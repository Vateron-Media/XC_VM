<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\Commands\ClusterMaintainStatsCommand;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Streaming\Auth\RtmpOffline;
use XcVm\Streaming\Codec\FFmpegCommand;
use XcVm\Streaming\Codec\FFprobeRunner;

/**
 * CleanupCronJob — cleanup cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CleanupCronJob implements CommandInterface {
	use CronTrait;

	/** Rows one retention DELETE takes, and the longest the prune runs per pass (s). */
	private const PRUNE_BATCH = 10000;
	private const PRUNE_SEC = 20;

	/** Seconds of deleting per log table per run: a backlog goes over several hourly runs. */
	private const PRUNE_LOG_SEC = 240;

	/**
	 * The directories of resized images under IMAGES_PATH, the days a file
	 * stays in one, and the bytes and the files one holds before its oldest
	 * files go.
	 */
	private const IMAGE_CACHES = ['admin/', 'player/'];
	private const IMAGE_CACHE_DAYS = 30;
	private const IMAGE_CACHE_BYTES = 4 * 1024 * 1024 * 1024;
	private const IMAGE_CACHE_FILES = 250000;

	public function getName(): string {
		return 'cron:cleanup';
	}

	public function getDescription(): string {
		return 'Cron: cleanup streams, archives, VOD, rotate tables';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Cleanup]');

		$rTimeout = 3600;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);

		$this->loadCron();

		return 0;
	}

	/**
	 * Can this node check its stream, archive and VOD files against its
	 * streams? Where the replica owns its streams and its own store keeps
	 * their runtime state (StreamSource::local(), mode 1 or 2 with STREAMS
	 * on), from those (NodeStreams); elsewhere but in mode 2 from MAIN's
	 * database, as before. A node in mode 2 without them may not read MAIN's
	 * database (its connect is refused), so the checks are skipped there:
	 * files of streams deleted on MAIN stay, TV archive segments are kept
	 * past their retention, and neither the VOD analysis nor the
	 * created-channel checks run. Never against an empty or partial list,
	 * which would delete every file: a check whose list the replica cannot
	 * give whole, or MAIN's database did not answer, is skipped.
	 */
	protected function streamChecks(): bool {
		return !NodeRole::refusesConnects() || StreamSource::local();
	}

	private function loadCron(): void {
		global $db;

		// First, and without a database: everything after streamChecks()
		// reads MAIN's database, which a node in mode 2 skips.
		// This node's connect audit: its report covers seven days.
		ConnectAudit::prune(8);
		// Its settings misses: the days that left the report's window drop out
		// of the audit.json its agent sends.
		SettingsAudit::prune(8);
		SettingsAudit::publish();
		// The RTMP viewers MAIN said yes to, past the hour they stand in for it.
		RtmpOffline::prune();

		// Everything below reads MAIN's database; the MAIN-only part never runs on a node.
		if (!$this->streamChecks()) {
			return;
		}

		if (intval(SettingsManager::get('cleanup')) == 1) {
			$rStreams = NodeStreams::fileStreams($db);
			foreach ($rStreams === null ? [] : glob(STREAMS_PATH . '*') as $rFilename) {
				$rID = intval(rtrim(explode('.', basename($rFilename))[0], '_')) . "\n";
				if (0 < $rID && !in_array($rID, $rStreams)) {
					echo 'Deleting: ' . $rFilename . "\n";
					unlink($rFilename);
				}
			}
			$rArchive = NodeStreams::archives($db);
			date_default_timezone_set('UTC');
			foreach ($rArchive === null ? [] : glob(ARCHIVE_PATH . '*') as $rStreamID) {
				$rID = intval(basename($rStreamID));
				if (0 < $rID && is_dir(ARCHIVE_PATH . $rID)) {
					if (!isset($rArchive[$rID])) {
						echo 'Deleting: ' . $rStreamID . "\n";
						exec('rm -rf ' . $rStreamID);
					} else {
						$rDuration = $rArchive[$rID];
						$rDeleteBefore = time() - $rDuration * 86400 + 3600;
						foreach (glob(ARCHIVE_PATH . $rID . '/*') as $rArchiveFile) {
							list($rDate, $rTime) = explode(':', explode('.', basename($rArchiveFile))[0]);
							list($rHour, $rMinute) = explode('-', $rTime);
							$rFileTime = strtotime($rDate . ' ' . $rHour . ':' . $rMinute . ':00');
							if ($rFileTime < $rDeleteBefore) {
								echo 'Deleting: ' . $rArchiveFile . "\n";
								unlink($rArchiveFile);
							}
						}
					}
				}
			}
			$rCreated = NodeStreams::createdIDs($db);
			foreach ($rCreated === null ? [] : glob(CREATED_PATH . '*') as $rFilename) {
				$rID = intval(rtrim(explode('.', basename($rFilename))[0], '_')) . "\n";
				if (0 < $rID && !in_array($rID, $rCreated)) {
					echo 'Deleting: ' . $rFilename . "\n";
					unlink($rFilename);
				}
			}
		}

		if (intval(SettingsManager::get('check_vod')) == 1) {
			$rRows = NodeStreams::vodChecks($db) ?? [];
			if (count($rRows) > 0) {
				foreach ($rRows as $rRow) {
					$rMoviePath = VOD_PATH . $rRow['id'] . '.' . $rRow['target_container'];
					if ($rRow['stream_status'] == 0) {
						if (!file_exists($rMoviePath)) {
							echo 'BAD MOVIE' . "\n";
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['stream_status' => 1], $db);
							StreamProcess::updateStream($rRow['id']);
						}
					} elseif ($rRow['stream_status'] == 1) {
						if (file_exists($rMoviePath) && ($rFFProbee = FFprobeRunner::probeStream($rMoviePath))) {
							$rDuration = (isset($rFFProbee['duration']) ? $rFFProbee['duration'] : 0);
							sscanf($rDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
							$rSeconds = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
							$rSize = filesize($rMoviePath);
							$rBitrate = round(($rSize * 0.008) / $rSeconds);
							$rMovieProperties = json_decode($rRow['movie_properties'], true);
							if (!is_array($rMovieProperties)) {
								$rMovieProperties = [];
							}
							if (!isset($rMovieProperties['duration_secs']) || $rSeconds != $rMovieProperties['duration_secs']) {
								$rMovieProperties['duration_secs'] = $rSeconds;
								$rMovieProperties['duration'] = $rDuration;
							}
							if (!isset($rMovieProperties['video']) || $rFFProbee['codecs']['video']['codec_name'] != $rMovieProperties['video']) {
								$rMovieProperties['video'] = $rFFProbee['codecs']['video'];
							}
							if (!isset($rMovieProperties['audio']) || $rFFProbee['codecs']['audio']['codec_name'] != $rMovieProperties['audio']) {
								$rMovieProperties['audio'] = $rFFProbee['codecs']['audio'];
							}
							if (SettingsManager::get('extract_subtitles')) {
								if (!isset($rMovieProperties['subtitle']) || $rFFProbee['codecs']['subtitle']['codec_name'] != $rMovieProperties['subtitle']) {
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
							$rAudioCodec = ($rFFProbee['codecs']['audio']['codec_name'] ?: null);
							$rVideoCodec = ($rFFProbee['codecs']['video']['codec_name'] ?: null);
							$rResolution = ($rFFProbee['codecs']['video']['height'] ?: null);
							if ($rResolution) {
								$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
							}
							if (!ContentSink::movieProperties((int) $rRow['id'], $rMovieProperties, $db) && NodeRole::refusesConnects()) {
								// Mode 2 and the agent took no event: checked again once it is back.
								continue;
							}
							StreamStateWriter::updateRow(intval($rRow['server_stream_id']), ['bitrate' => $rBitrate, 'to_analyze' => 0, 'stream_status' => 0, 'stream_info' => json_encode($rFFProbee, JSON_UNESCAPED_UNICODE), 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution, 'compatible' => $rCompatible], $db);
							StreamProcess::updateStream($rRow['id']);
							echo 'VALID MOVIE' . "\n";
						}
					}
				}
			}
			$rStreams = NodeStreams::builtChannels($db) ?? [];
			if (count($rStreams) > 0) {
				foreach ($rStreams as $rStream) {
					echo "\n\n" . '[*] Checking Channel ' . $rStream['stream_display_name'] . "\n";
					if (file_exists(CREATED_PATH . $rStream['id'] . '_.list')) {
						$rList = explode("\n", file_get_contents(CREATED_PATH . $rStream['id'] . '_.list'));
						$rExisting = glob(CREATED_PATH . $rStream['id'] . '*.*');
						$rFailure = false;
						$rActualFiles = [];
						foreach ($rList as $rItem) {
							$rFilename = trim(explode("'", explode("'", $rItem)[1])[0]);
							if ($rFilename !== '') {
								if (in_array($rFilename, $rExisting)) {
									$rActualFiles[] = $rFilename;
								} else {
									$rFailure = true;
								}
							}
						}
						if ($rFailure) {
							echo 'BAD CHANNEL' . "\n";
							StreamStateWriter::updateRow(intval($rStream['server_stream_id']), ['cchannel_rsources' => json_encode($rActualFiles, JSON_UNESCAPED_UNICODE)], $db);
							StreamProcess::updateStream($rStream['id']);
						}
					} else {
						echo 'BAD CHANNEL' . "\n";
						StreamStateWriter::updateRow(intval($rStream['server_stream_id']), ['cchannel_rsources' => '[]'], $db);
						StreamProcess::updateStream($rStream['id']);
					}
				}
			}
		}

		// The node's own store keeps only the streams and recordings it holds.
		if (StreamSource::local() && ($rHeld = NodeStreams::held()) !== null) {
			StreamRuntime::prune(...$rHeld);
		}

		// Retention of cluster-wide log tables: MAIN's job. Every LB used to
		// run the same DELETEs against MAIN's database each minute.
		if (!NodeRole::isMain()) {
			return;
		}
		// SSH passwords saved by installs before they moved to one-shot cred files.
		InstallCredentials::scrubLegacyMetadata();
		self::pruneLogs($db, SettingsManager::getAll(), time());

		// The cluster settings' own retention, in days (ClusterSettings::INTS,
		// which also holds each one's bounds and default): the dashboard's
		// server graphs and the cluster audit log. Both were settings with a
		// form field and no reader, so neither table was ever pruned.
		$rUntil = microtime(true) + self::PRUNE_SEC;
		foreach (['servers_stats' => 'servers_stats_retention_days', 'cluster_audit' => 'cluster_audit_retention_days'] as $rTable => $rSetting) {
			// lb-settings: servers_stats_retention_days, cluster_audit_retention_days
			$rDays = ClusterSettings::int($rSetting, SettingsManager::getAll()[$rSetting] ?? null);
			self::prune($db, $rTable, time() - $rDays * 86400, $rUntil);
		}
		// The indexes that prune, the server graphs and the log pages read by,
		// built online and apart: on a year of rows an ALTER runs for minutes.
		if (class_exists(ClusterMaintainStatsCommand::class) && ClusterMaintainStatsCommand::pending($db) !== []) {
			ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'cluster:maintain-stats']);
		}
		// The resized images the panels cache: nothing else removes them.
		if (defined('IMAGES_PATH')) {
			self::pruneImageCaches(IMAGES_PATH);
		}
	}

	/**
	 * Prune the directories ImageResizeService caches resized images in
	 * (IMAGE_CACHES under $rImages), where every distinct URL and size leaves
	 * a file. One last written more than IMAGE_CACHE_DAYS ago goes: it is
	 * built again when asked for, and browsers are told to keep theirs for 7
	 * days. A directory still over $rMaxBytes or $rMaxFiles then loses its
	 * oldest files first: the bytes alone would let any number of small files
	 * stay.
	 *
	 * Only the names the resizer writes are removed ('<md5>_<width>_<height>.png'
	 * and '<md5>.webp'), and only in those directories, never below them:
	 * $rImages itself and its other sub-directories hold the icons and logos
	 * the panel keeps. No more than twice $rMaxFiles names are held while a
	 * directory is read, however many files it has (some 70 MB at
	 * IMAGE_CACHE_FILES).
	 *
	 * @return int the files deleted
	 */
	public static function pruneImageCaches(string $rImages, ?int $rNow = null, int $rMaxBytes = self::IMAGE_CACHE_BYTES, int $rMaxFiles = self::IMAGE_CACHE_FILES): int {
		$rBefore = ($rNow ?? time()) - self::IMAGE_CACHE_DAYS * 86400;
		$rDeleted = 0;
		foreach (self::IMAGE_CACHES as $rCache) {
			$rDir = $rImages . $rCache;
			$rHandle = is_dir($rDir) ? @opendir($rDir) : false;
			if ($rHandle === false) {
				continue;
			}
			$rKept = [];
			$rBytes = 0;
			while (($rName = readdir($rHandle)) !== false) {
				if (!preg_match('/^[0-9a-f]{32}(_\d+_\d+\.png|\.webp)\z/', $rName) || !is_file($rDir . $rName)) {
					continue;
				}
				$rTime = @filemtime($rDir . $rName);
				if ($rTime === false) {
					continue;
				}
				if ($rTime < $rBefore) {
					$rDeleted += (int) @unlink($rDir . $rName);
					continue;
				}
				$rKept[$rName] = $rTime;
				$rBytes += (int) @filesize($rDir . $rName);
				if (count($rKept) >= 2 * $rMaxFiles) {
					$rDeleted += self::trimImageCache($rDir, $rKept, $rBytes, $rMaxBytes, $rMaxFiles);
				}
			}
			closedir($rHandle);
			$rDeleted += self::trimImageCache($rDir, $rKept, $rBytes, $rMaxBytes, $rMaxFiles);
		}
		return $rDeleted;
	}

	/**
	 * Delete the oldest of $rKept (file name => when it was last written, in
	 * $rDir, $rBytes in all) until no more than $rMaxFiles of them and
	 * $rMaxBytes stay. $rKept and $rBytes are left as what stays.
	 *
	 * @param array<string, int> $rKept
	 * @return int the files deleted
	 */
	private static function trimImageCache(string $rDir, array &$rKept, int &$rBytes, int $rMaxBytes, int $rMaxFiles): int {
		$rOver = count($rKept) - $rMaxFiles;
		if ($rOver <= 0 && $rBytes <= $rMaxBytes) {
			return 0;
		}
		asort($rKept);
		$rGone = 0;
		$rDeleted = 0;
		foreach ($rKept as $rName => $rTime) {
			if ($rGone >= $rOver && $rBytes <= $rMaxBytes) {
				break;
			}
			$rSize = (int) @filesize($rDir . $rName);
			if (@unlink($rDir . $rName)) {
				$rBytes -= $rSize;
				$rDeleted++;
			}
			$rGone++;
		}
		$rKept = array_slice($rKept, $rGone, null, true);
		return $rDeleted;
	}

	/**
	 * The retention of the connection and log tables (Settings, Logs): each table
	 * whose keep period is set loses its rows older than that, PRUNE_BATCH at a
	 * time and for at most $rSeconds, so no single statement holds the table.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array<string, int> table => rows deleted
	 */
	public static function pruneLogs(object $db, array $rSettings, int $rNow, float $rSeconds = self::PRUNE_LOG_SEC): array {
		$rTables = ['lines_activity' => ['keep_activity', 'date_end'], 'lines_logs' => ['keep_client', 'date'], 'login_logs' => ['keep_login', 'date'], 'streams_errors' => ['keep_errors', 'date'], 'streams_logs' => ['keep_restarts', 'date'], 'ondemand_check' => ['on_demand_scan_keep', 'date'], 'mysql_syslog' => ['keep_syslog', 'date']];
		$rDeleted = [];
		foreach ($rTables as $rTable => [$rKey, $rColumn]) {
			$rKeep = intval($rSettings[$rKey] ?? 0); // lb-settings: keep_activity, keep_client, keep_login, keep_errors, keep_restarts, on_demand_scan_keep, keep_syslog
			if (0 < $rKeep) {
				$rDeleted[$rTable] = self::prune($db, $rTable, $rNow - $rKeep, microtime(true) + $rSeconds, $rColumn);
			}
		}
		return $rDeleted;
	}

	/**
	 * Delete $rTable's rows older than $rBefore, PRUNE_BATCH at a time, until
	 * none are left or $rUntil (microtime) passes; the next run goes on. One
	 * DELETE of a year of rows held the table and its undo log for as long
	 * as it ran (plan, section 8).
	 *
	 * @return int the rows deleted
	 */
	public static function prune(object $db, string $rTable, int $rBefore, float $rUntil, string $rColumn = 'time'): int {
		$rDeleted = 0;
		do {
			if (!$db->query('DELETE FROM `' . $rTable . '` WHERE `' . $rColumn . '` < ? LIMIT ' . self::PRUNE_BATCH . ';', $rBefore)) {
				break;
			}
			$rRows = $db->num_rows();
			$rDeleted += $rRows;
		} while ($rRows >= self::PRUNE_BATCH && microtime(true) < $rUntil);
		return $rDeleted;
	}
}
