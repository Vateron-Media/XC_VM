<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\MediaAnalyzedEvent;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Streaming\Codec\FFmpegCommand;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * VodCronJob — vod cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class VodCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:vod';
	}

	public function getDescription(): string {
		return 'Cron: check VOD/channels, start recordings, analyze media';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[VOD]');
		$this->loadCron();

		return 0;
	}

	private function loadCron(): void {
		global $db;

		// This node's streams, from its replica and its own store where they
		// answer (NodeStreams), else from MAIN's database as before.
		$rStreams = NodeStreams::createdChannels($db);
		if (count($rStreams) > 0) {
			foreach ($rStreams as $rStream) {
				echo "\n\n" . '[*] Checking Stream ' . $rStream['stream_display_name'] . "\n";
				$rCreateFile = CREATED_PATH . $rStream['id'] . '_.create';
				$rPID = is_file($rCreateFile) ? intval(file_get_contents($rCreateFile)) : 0;
				if ($rPID && ProcessChecker::checkPID($rPID, 'XC_VMCreate[' . intval($rStream['id']) . ']')) {
					echo "\t" . 'Build Is Still Going!' . "\n";
				} else {
					$rSourcesLeft = array_diff(json_decode($rStream['stream_source'], true), json_decode($rStream['cchannel_rsources'], true));
					if (count($rSourcesLeft) > 0) {
						echo "\t" . 'Needs Updating!' . "\n";
						// The queue is MAIN's table: in mode 2 QueueSink asks MAIN.
						StreamProcess::queueChannel($rStream['id']);
					} else {
						if (file_exists(CREATED_PATH . $rStream['id'] . '_.info')) {
							$rCCInfo = file_get_contents(CREATED_PATH . $rStream['id'] . '_.info');
							StreamStateWriter::update(intval($rStream['id']), intval(SERVER_ID), ['cc_info' => $rCCInfo], $db);
							unlink(CREATED_PATH . $rStream['id'] . '_.info');
						}
						echo "\t" . 'Build Finished' . "\n";
					}
				}
			}
		}

		// Fenced: no recording starts (RecordCommand refuses too).
		$rRecordings = NodeLease::refusesEverything() ? [] : NodeStreams::recordingsDue($db);
		if (count($rRecordings) > 0) {
			foreach ($rRecordings as $rRow) {
				echo 'Start recording ID: ' . intval($rRow['id']) . "\n";
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php record ' . intval($rRow['id']) . ' > /dev/null 2>/dev/null &');
			}
		}

		exec("ps ax | grep 'ffmpeg' | awk '{print \$1}'", $rPIDs);

		$rCount = NodeStreams::analysisCount($db);

		if ($rCount > 0) {
			if ($rCount <= 1000) {
				$rSteps = [0, $rCount];
			} else {
				$rSteps = range(0, $rCount, 1000);
			}
			if ($rSteps === []) {
				$rSteps = [0];
			}

			foreach ($rSteps as $rStep) {
				$rRows = NodeStreams::analysis(intval($rStep), $db);
				if (count($rRows) <= 0) {
					continue;
				}

				foreach ($rRows as $rRow) {
					echo '[*] Checking Movie ' . $rRow['stream_display_name'] . ' ' . "\t\t" . '---> ';
					if (in_array($rRow['pid'], $rPIDs)) {
						echo 'ENCODING...' . "\n";
					} else {
						$rMoviePath = VOD_PATH . intval($rRow['stream_id']) . '.' . escapeshellcmd($rRow['target_container']);
						if ($rFFProbee = FFprobeRunner::probeStream($rMoviePath)) {
							if (!isset($rFFProbee['codecs']['video']) || !is_array($rFFProbee['codecs']['video'])) {
								// ffprobe opened the file but found no usable video stream
								// (e.g. a truncated/placeholder upload): parseFFProbe returns
								// '' for the missing codec, and the VALID branch below treats
								// it as an array ($rFFProbee['codecs']['video']['codec_name']),
								// which is a TypeError on PHP 8 that aborts the whole analyzer
								// run and leaves every remaining movie stuck in `to_analyze = 1`
								// (yellow) forever. Treat such a file as broken instead.
								StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['to_analyze' => 0, 'stream_status' => 1], $db);
								echo 'BROKEN (no video stream)' . "\n";
								StreamProcess::updateStream($rRow['stream_id']);
								continue;
							}
							// ffprobe (especially over network/rclone mounts) can still
							// return a partial result for an odd file — e.g. a video stream
							// but no audio, where parseFFProbe stores '' (a string) instead
							// of an array. The VALID branch dereferences these as arrays
							// ($rFFProbee['codecs']['audio']['codec_name']), a TypeError on
							// PHP 8 that aborts the whole run. Normalise to arrays.
							foreach (['video', 'audio'] as $rCodecKind) {
								if (!is_array($rFFProbee['codecs'][$rCodecKind] ?? null)) {
									$rFFProbee['codecs'][$rCodecKind] = [];
								}
							}
							$rDuration = (isset($rFFProbee['duration']) ? $rFFProbee['duration'] : 0);
							sscanf($rDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
							$rSeconds = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
							$rSize = filesize($rMoviePath);
							// Guard against a zero/unknown duration (ffprobe reports
							// 'N/A' for truncated or duration-less files): dividing by
							// it throws DivisionByZeroError on PHP 8, which aborts the
							// whole analyzer run and leaves every remaining movie stuck
							// in `to_analyze = 1` (yellow) forever.
							$rBitrate = ($rSeconds > 0 ? round(($rSize * 0.008) / $rSeconds) : 0);
							$rMovieProperties = json_decode($rRow['movie_properties'], true);
							if (!is_array($rMovieProperties)) {
								$rMovieProperties = [];
							}
							if (!isset($rMovieProperties['duration_secs']) || $rSeconds != $rMovieProperties['duration_secs']) {
								$rMovieProperties['duration_secs'] = $rSeconds;
								$rMovieProperties['duration'] = $rDuration;
							}
							if (!isset($rMovieProperties['video']) || ($rFFProbee['codecs']['video']['codec_name'] ?? null) != $rMovieProperties['video']) {
								$rMovieProperties['video'] = $rFFProbee['codecs']['video'];
							}
							if (!isset($rMovieProperties['audio']) || ($rFFProbee['codecs']['audio']['codec_name'] ?? null) != $rMovieProperties['audio']) {
								$rMovieProperties['audio'] = $rFFProbee['codecs']['audio'];
							}
							if (SettingsManager::get('extract_subtitles')) {
								if (!isset($rMovieProperties['subtitle']) || ($rFFProbee['codecs']['subtitle']['codec_name'] ?? null) != $rMovieProperties['subtitle']) {
									$rMovieProperties['subtitle'] = $rFFProbee['codecs']['subtitle'];
								}
							}
							if (!isset($rMovieProperties['bitrate']) || $rBitrate != $rMovieProperties['bitrate']) {
								if (0 < $rBitrate) {
									$rMovieProperties['bitrate'] = $rBitrate;
								} else {
									$rBitrate = $rMovieProperties['bitrate'];
								}
							}
							if (isset($rFFProbee['codecs']['subtitle']) && SettingsManager::get('extract_subtitles')) {
								$i = 0;
								foreach ($rFFProbee['codecs']['subtitle'] as $rSubtitle) {
									FFmpegCommand::extractSubtitle($rRow['stream_id'], $rMoviePath, $i);
									$i++;
								}
							}
							$rCompatible = intval(DiagnosticsService::checkCompatibility($rFFProbee, SettingsManager::get('player_allow_hevc')));
							$rAudioCodec = ($rFFProbee['codecs']['audio']['codec_name'] ?? null) ?: null; // a file without an audio track has none
							$rVideoCodec = ($rFFProbee['codecs']['video']['codec_name'] ?? null) ?: null;
							$rResolution = ($rFFProbee['codecs']['video']['height'] ?? null) ?: null;
							if ($rResolution) {
								$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
							}
							if (!ContentSink::movieProperties((int) $rRow['stream_id'], $rMovieProperties, $db) && NodeRole::refusesConnects()) {
								// Mode 2 and the agent took no event: still due, analysed again once it is back.
								echo 'DEFERRED' . "\n";
								continue;
							}
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['bitrate' => $rBitrate, 'to_analyze' => 0, 'stream_status' => 0, 'stream_info' => json_encode($rFFProbee, JSON_UNESCAPED_UNICODE), 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution, 'compatible' => $rCompatible], $db);
							echo 'VALID' . "\n";
							EventDispatcher::dispatch(new MediaAnalyzedEvent((int) $rRow['stream_id'], (int) $rRow['type']));
						} else {
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['to_analyze' => 0, 'stream_status' => 1], $db);
							echo 'BROKEN' . "\n";
						}
						StreamProcess::updateStream($rRow['stream_id']);
					}
				}
			}
		}
	}
}
