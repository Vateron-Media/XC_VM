<?php

/**
 * ScannerCommand — scanner command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../DaemonTrait.php';

class ScannerCommand implements CommandInterface {
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

		global $db;

		$this->setProcessTitle('XC_VM[Scanner]');
		$this->killStaleProcesses('console.php scanner');
		$this->initDaemonMD5();

		if (!SettingsManager::getAll()['on_demand_checker']) {
			echo "On-Demand - Source Scanner is disabled.\n";
			return 0;
		}

		$this->rRefreshInterval = 60;

		while (true && $db && $db->ping()) {
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

			$this->scanOnDemandStreams($db);
			sleep(60);
			break;
		}

		if (is_object($db)) {
			$db->close_mysql();
		}

		$this->restartDaemon('scanner');
		return 0;
	}

	private function scanOnDemandStreams(Database $db): void {
		$rScanTime = SettingsManager::getAll()['on_demand_scan_time'] ?: 3600;

		if (!$db->query('SELECT `streams`.* FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams_servers`.`pid` IS NULL AND `streams_servers`.`on_demand` = 1 AND `streams_servers`.`parent_id` IS NULL AND `streams`.`type` = 1 AND `streams`.`direct_source` = 0 AND `streams_servers`.`server_id` = ? AND (UNIX_TIMESTAMP() - (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) > ? OR (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) IS NULL);', SERVER_ID, $rScanTime)) {
			return;
		}

		if ($db->num_rows() <= 0) {
			return;
		}

		foreach ($db->get_rows() as $rRow) {
			echo '[' . $rRow['id'] . '] - ' . $rRow['stream_display_name'] . "\n";
			$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rRow['id']);
			$rStreamArguments = $db->get_rows();
			$rProbesize = (intval($rRow['probesize_ondemand']) ?: 512000);
			$rAnalyseDuration = '10000000';
			$rTimeout = intval($rAnalyseDuration / 1000000) + SettingsManager::getAll()['probe_extra_wait'];

			if (SettingsManager::getAll()['on_demand_max_probe'] < $rTimeout && 0 < SettingsManager::getAll()['on_demand_max_probe']) {
				$rTimeout = intval(SettingsManager::getAll()['on_demand_max_probe']);
			}

			$rFFProbee = 'timeout ' . $rTimeout . ' ' . FfmpegPaths::probe() . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -i {STREAM_SOURCE} -loglevel error -print_format json -show_streams -show_format 2>' . STREAMS_TMP_PATH . $rRow['id'] . '._errors';
			$rSources = json_decode($rRow['stream_source'], true);
			$rSourceID = 0;
			$rErrors = null;
			$rFFProbeOutput = null;
			$rTimeTaken = null;

			foreach ($rSources as $rSource) {
				$rProcessed = false;
				$rRealSource = $rSource;
				$rStreamSource = StreamUtils::parseStreamURL($rSource);
				echo 'Checking source: ' . $rSource . "\n";
				$rURLInfo = parse_url($rStreamSource);
				$rIsXC_VM = StreamUtils::detectXC_VM($rStreamSource);

				if ($rIsXC_VM && SettingsManager::getAll()['send_xc_vm_header']) {
					foreach (array_keys($rStreamArguments) as $rID) {
						if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
							continue;
						}
						$rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Detect:1';
						$rProcessed = true;
					}
					if (!$rProcessed) {
						$rStreamArguments[] = array('value' => 'X-XC_VM-Detect:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
					}
				}

				if ($rIsXC_VM && SettingsManager::getAll()['request_prebuffer'] == 1) {
					$rProcessed = false;
					foreach (array_keys($rStreamArguments) as $rID) {
						if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
							continue;
						}
						$rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
						$rProcessed = true;
					}
					if (!$rProcessed) {
						$rStreamArguments[] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
					}
				}

				$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
				$rFetchOptions = implode(' ', StreamUtils::getArguments($rStreamArguments, $rProtocol, 'fetch'));

				if ($rIsXC_VM && SettingsManager::getAll()['api_probe']) {
					$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ':' . $rURLInfo['port'] . '/probe/' . base64_encode($rURLInfo['path']);
					$rTime = round(microtime(true) * 1000);
					$rFFProbeOutput = json_decode(CurlClient::getURL($rProbeURL), true);
					$rTimeTaken = round(microtime(true) * 1000) - $rTime;
					if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
						echo "Got stream information via API\n";
						break;
					}
				}

				$rTime = round(microtime(true) * 1000);
				$rFFProbeOutput = json_decode(shell_exec(str_replace(array('{FETCH_OPTIONS}', '{STREAM_SOURCE}'), array($rFetchOptions, escapeshellarg($rStreamSource)), $rFFProbee)), true);
				$rTimeTaken = round(microtime(true) * 1000) - $rTime;

				if (file_exists(STREAMS_TMP_PATH . $rRow['id'] . '._errors') && 0 < filesize(STREAMS_TMP_PATH . $rRow['id'] . '._errors')) {
					if (!$rErrors && $rSourceID == 0) {
						$rErrors = file_get_contents(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
					}
					unlink(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
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
				$rAudioCodec = ($rFFProbeOutput['codecs']['audio']['codec_name'] ?: null);
				$rVideoCodec = ($rFFProbeOutput['codecs']['video']['codec_name'] ?: null);
				$rResolution = ($rFFProbeOutput['codecs']['video']['height'] ?: null);
				$rVideoBitrate = ($rFFProbeOutput['codecs']['video']['bit_rate'] ?: 0);
				$rAudioBitrate = ($rFFProbeOutput['codecs']['audio']['bit_rate'] ?: 0);
				$rFPS = (intval(explode('/', $rFFProbeOutput['codecs']['video']['r_frame_rate'])[0]) ?: 0);
				if ($rFPS == 0) {
					$rFPS = (intval(explode('/', $rFFProbeOutput['codecs']['video']['avg_frame_rate'])[0]) ?: 0);
				}
				if ($rFPS >= 1000) {
					$rFPS = intval($rFPS / 1000);
				}
				if ($rResolution) {
					$rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
				}
				$rStatus = 1;
			} else {
				echo "Source down!\n";
				$rFPS = $rAudioCodec = $rVideoCodec = $rResolution = $rTimeTaken = null;
				$rSourceID = $rStatus = 0;
			}

			$rSource = $rSources[$rSourceID];
			$db->query('INSERT INTO `ondemand_check`(`stream_id`, `server_id`, `status`, `source_id`, `source_url`, `fps`, `video_codec`, `audio_codec`, `resolution`, `response`, `errors`, `date`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rRow['id'], SERVER_ID, $rStatus, $rSourceID, $rSource, $rFPS, $rVideoCodec, $rAudioCodec, $rResolution, $rTimeTaken, $rErrors, time());
			$db->query('UPDATE `streams_servers` SET `ondemand_check` = ? WHERE `stream_id` = ? AND `server_id` = ?;', $db->last_insert_id(), $rRow['id'], SERVER_ID);
			echo "\n";
		}
	}
}
