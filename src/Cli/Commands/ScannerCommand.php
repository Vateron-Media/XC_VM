<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Codec\FfmpegPaths;
use XcVm\Streaming\Codec\FFprobeRunner;

/**
 * ScannerCommand — scanner command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ScannerCommand implements CommandInterface {
	use DatabaseAware;
	use DaemonTrait;

	public function getName(): string {
		return 'scanner';
	}

	public function getDescription(): string {
		return 'Daemon: scan on-demand sources';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$db = self::db();

		$this->setProcessTitle('XC_VM[Scanner]');
		$this->killStaleProcesses('console.php scanner');
		$this->initDaemonMD5();

		if (!SettingsManager::get('on_demand_checker')) {
			echo "On-Demand - Source Scanner is disabled.\n";
			return 0;
		}

		$this->rRefreshInterval = 60;

		// A node in mode 2 has no database of MAIN's: its checks go to MAIN as
		// log events and its due list comes from the replica.
		$rApi = NodeRole::refusesConnects();

		// Not past a move into or out of mode 2: its next generation decides again
		// whether it reads MAIN's database (DaemonTrait::modeChanged()).
		while (!$this->modeChanged() && ($rApi || $db->ping())) {
			if (!$this->shouldRefreshSettings()) {
				// skip
			} else {
				if ($this->hasFileChanged()) {
					echo "File changed! Break.\n";
					break;
				}
				SettingsManager::set(SettingsRepository::getAll(true));
				$this->rLastCheck = time();
			}

			$this->scanOnDemandStreams();
			sleep(60);
		}

		if (!$rApi) {
			$db->close_mysql();
		}

		$this->restartDaemon('scanner');
		return 0;
	}

	private function scanOnDemandStreams(): void {
		$db = self::db();
		$rScanTime = SettingsManager::get('on_demand_scan_time') ?: 3600;

		foreach (NodeStreams::onDemandDue(intval($rScanTime), $db) as $rRow) {
			echo '[' . $rRow['id'] . '] - ' . $rRow['stream_display_name'] . "\n";
			$rStreamArguments = StreamSource::arguments(intval($rRow['id']), false, $db);
			$rProbesize = (intval($rRow['probesize_ondemand']) ?: 512000);
			$rAnalyseDuration = '10000000';
			$rTimeout = intval($rAnalyseDuration / 1000000) + SettingsManager::get('probe_extra_wait');

			if (SettingsManager::get('on_demand_max_probe') < $rTimeout && 0 < SettingsManager::get('on_demand_max_probe')) {
				$rTimeout = intval(SettingsManager::get('on_demand_max_probe'));
			}

			$rFFProbee = 'timeout ' . $rTimeout . ' ' . FfmpegPaths::probe() . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -i {STREAM_SOURCE} -loglevel error -print_format json -show_streams -show_format 2>' . STREAMS_TMP_PATH . $rRow['id'] . '._errors';
			$rSources = json_decode($rRow['stream_source'], true);
			$rSourceID = 0;
			$rErrors = null;
			$rFFProbeOutput = null;
			$rTimeTaken = null;

			foreach ($rSources as $rSource) {
				$rProcessed = false;
				$rStreamSource = StreamUtils::parseStreamURL($rSource, (string) (array_column($rStreamArguments, 'value', 'argument_key')['proxy'] ?? ''));
				echo 'Checking source: ' . $rSource . "\n";
				$rURLInfo = parse_url($rStreamSource);
				$rIsXC_VM = StreamUtils::detectXC_VM($rStreamSource);

				if ($rIsXC_VM && SettingsManager::get('send_xc_vm_header')) {
					foreach (array_keys($rStreamArguments) as $rID) {
						if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
							continue;
						}
						$rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Detect:1';
						$rProcessed = true;
					}
					if (!$rProcessed) {
						$rStreamArguments[] = ['value' => 'X-XC_VM-Detect:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'"];
					}
				}

				if ($rIsXC_VM && SettingsManager::get('request_prebuffer') == 1) {
					$rProcessed = false;
					foreach (array_keys($rStreamArguments) as $rID) {
						if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
							continue;
						}
						$rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
						$rProcessed = true;
					}
					if (!$rProcessed) {
						$rStreamArguments[] = ['value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'"];
					}
				}

				$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
				$rFetchOptions = implode(' ', StreamUtils::getArguments($rStreamArguments, $rProtocol, 'fetch'));

				if ($rIsXC_VM && SettingsManager::get('api_probe')) {
					$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ':' . $rURLInfo['port'] . '/probe/' . base64_encode($rURLInfo['path']);
					$rTime = round(microtime(true) * 1000);
					$rFFProbeOutput = NetworkUtils::probeTargetAllowed($rProbeURL) ? json_decode(CurlClient::getURL($rProbeURL), true) : null;
					$rTimeTaken = round(microtime(true) * 1000) - $rTime;
					if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
						echo "Got stream information via API\n";
						break;
					}
				}

				$rTime = round(microtime(true) * 1000);
				$rFFProbeOutput = StreamProcess::driverProbe(intval($rRow['id']), (string) $rSource) ?? json_decode(shell_exec(str_replace(['{FETCH_OPTIONS}', '{STREAM_SOURCE}'], [$rFetchOptions, escapeshellarg($rStreamSource)], $rFFProbee)), true);
				$rTimeTaken = round(microtime(true) * 1000) - $rTime;

				if (file_exists(STREAMS_TMP_PATH . $rRow['id'] . '._errors') && 0 < filesize(STREAMS_TMP_PATH . $rRow['id'] . '._errors')) {
					if (!$rErrors && $rSourceID == 0) {
						$rErrors = file_get_contents(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
					}
					@unlink(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
				}

				if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
					echo "Got stream information via ffprobe\n";
					break;
				}

				if (!$rRow['llod']) {
					$rSourceID++;
				} else {
					break;
				}
			}

			if (!empty($rFFProbeOutput)) {
				echo "Source live!\n";
				$rFFProbeOutput = FFprobeRunner::parseFFProbe($rFFProbeOutput);
				// parseFFProbe() leaves codecs.video/audio as '' when the source has no
				// stream of that type, instead of an array.
				$rVideoInfo = is_array($rFFProbeOutput['codecs']['video'] ?? null) ? $rFFProbeOutput['codecs']['video'] : [];
				$rAudioInfo = is_array($rFFProbeOutput['codecs']['audio'] ?? null) ? $rFFProbeOutput['codecs']['audio'] : [];
				$rAudioCodec = ($rAudioInfo['codec_name'] ?? null);
				$rVideoCodec = ($rVideoInfo['codec_name'] ?? null);
				$rResolution = ($rVideoInfo['height'] ?? null);
				$rFPS = (intval(explode('/', $rVideoInfo['r_frame_rate'] ?? '')[0]));
				if ($rFPS == 0) {
					$rFPS = (intval(explode('/', $rVideoInfo['avg_frame_rate'] ?? '')[0]));
				}
				if ($rFPS >= 1000) {
					$rFPS = intval($rFPS / 1000);
				}
				if ($rResolution) {
					$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
				}
				$rStatus = 1;
			} else {
				echo "Source down!\n";
				$rFPS = $rAudioCodec = $rVideoCodec = $rResolution = $rTimeTaken = null;
				$rSourceID = $rStatus = 0;
			}

			$rSource = $rSources[$rSourceID];
			$rCheck = [
				'stream_id' => intval($rRow['id']), 'server_id' => intval(SERVER_ID), 'status' => $rStatus, 'source_id' => $rSourceID,
				'source_url' => $rSource, 'fps' => $rFPS, 'video_codec' => $rVideoCodec, 'audio_codec' => $rAudioCodec,
				'resolution' => $rResolution, 'response' => $rTimeTaken, 'errors' => $rErrors, 'date' => time(),
			];
			// With LOGS on the check is a `log.ondemand_check` event and MAIN
			// points the node's row at the row it inserts; here the node knows
			// the id itself.
			if (LogSink::write('ondemand_check', [$rCheck], $db) && !NodeFlows::on(NodeFlows::LOGS)) {
				StreamStateWriter::update(intval($rRow['id']), intval(SERVER_ID), ['ondemand_check' => $db->last_insert_id()], $db);
			}
			NodeStreams::scanned(intval($rRow['id']));
			echo "\n";
		}
	}
}
