<?php

/**
 * LlodCommand — llod command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LlodCommand implements CommandInterface {

	public function getName(): string {
		return 'llod';
	}

	public function getDescription(): string {
		return 'LLOD — Low-Latency On-Demand stream processor';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (count($rArgs) < 3) {
			echo "LLOD cannot be directly run!\n";
			echo "Arguments received: " . count($rArgs) . "\n";
			return 0;
		}

		$rStreamID = intval($rArgs[0]);
		$rStreamSources = json_decode(base64_decode($rArgs[1]), true);
		$rStreamArguments = json_decode(base64_decode($rArgs[2]), true);

		if (!is_array($rStreamSources) || !is_array($rStreamArguments)) {
			echo "Failed to decode stream parameters\n";
			return 1;
		}

		echo "=== LLOD STARTUP ===\n";
		echo "Stream ID: $rStreamID\n";
		echo "Stream sources count: " . count($rStreamSources) . "\n";
		echo "Stream arguments count: " . count($rStreamArguments) . "\n";
		echo "====================\n\n";

		if (!defined('MAIN_HOME')) define('MAIN_HOME', '/home/xc_vm/');
		if (!defined('STREAMS_PATH')) define('STREAMS_PATH', MAIN_HOME . 'content/streams/');
		if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', MAIN_HOME . 'includes/');
		if (!defined('CACHE_TMP_PATH')) define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
		if (!defined('CONS_TMP_PATH')) define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
		if (!defined('FFMPEG')) define('FFMPEG', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffmpeg');
		if (!defined('FFPROBE')) define('FFPROBE', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffprobe');
		if (!defined('PACKET_SIZE')) define('PACKET_SIZE', 188);
		if (!defined('BUFFER_SIZE')) define('BUFFER_SIZE', 12032);
		if (!defined('TIMEOUT')) define('TIMEOUT', 20);
		if (!defined('SEGMENT_DURATION')) define('SEGMENT_DURATION', 4);

		if (!file_exists(CACHE_TMP_PATH . 'settings')) {
			echo "Settings not cached!\n";
			return 0;
		}

		echo "Settings file found at: " . CACHE_TMP_PATH . "settings\n";

		$this->checkRunning($rStreamID);

		$rFP = null;
		$rSegmentFile = null;
		$rSegmentStatus = array();

		register_shutdown_function(function () use (&$rFP, &$rSegmentFile) {
			if (is_resource($rSegmentFile)) {
				echo "Closing segment file\n";
				@fclose($rSegmentFile);
			}
			if (is_resource($rFP)) {
				echo "Closing stream resource\n";
				@fclose($rFP);
			}
		});

		set_time_limit(0);
		error_reporting(E_WARNING | E_PARSE);
		cli_set_process_title('LLOD[' . $rStreamID . ']');
		require INCLUDES_PATH . 'ts.php';

		$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));

		if ($rSettings === false || !is_array($rSettings)) {
			echo "Failed to unserialize settings\n";
			return 1;
		}

		echo "Settings loaded successfully\n";
		echo "Segment list size: " . $rSettings['seg_list_size'] . "\n";
		echo "Segment delete threshold: " . $rSettings['seg_delete_threshold'] . "\n";
		echo "Request prebuffer: " . $rSettings['request_prebuffer'] . "\n";

		$rSegListSize = $rSettings['seg_list_size'];
		$rSegDeleteThreshold = $rSettings['seg_delete_threshold'];
		$rRequestPrebuffer = $rSettings['request_prebuffer'];

		echo "Starting LLOD processing...\n\n";

		$this->startLlod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold, $rSegmentStatus, $rFP, $rSegmentFile);

		return 0;
	}

	private function startLlod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold, &$rSegmentStatus, &$rFP, &$rSegmentFile): void {
		$segmentDuration = SEGMENT_DURATION;
		$segmentStart = microtime(true);

		if (!file_exists(CONS_TMP_PATH . $rStreamID)) {
			if (!@mkdir(CONS_TMP_PATH . $rStreamID, 0777, true)) {
				$this->writeError($rStreamID, '[LLOD] Failed to create connection directory');
				return;
			}
		}

		$ua = $rStreamArguments['user_agent']['value'] ?? 'Mozilla/5.0';

		$context = stream_context_create([
			'http' => [
				'timeout'    => TIMEOUT,
				'user_agent' => $ua,
			],
			'ssl' => [
				'verify_peer'      => false,
				'verify_peer_name' => false,
			]
		]);

		$rFP = $this->getActiveStream($rStreamID, $rStreamSources, $context);
		if (!$rFP) {
			echo "No active stream\n";
			return;
		}

		stream_set_blocking($rFP, true);

		shell_exec('rm -f ' . STREAMS_PATH . escapeshellarg($rStreamID) . '_*.ts');

		$segment = 0;
		$rSegmentFile = fopen(STREAMS_PATH . $rStreamID . "_{$segment}.ts", 'wb');

		if (!$rSegmentFile) {
			$this->writeError($rStreamID, '[LLOD] Failed to create initial segment file');
			fclose($rFP);
			return;
		}

		$rSegmentStatus[$segment] = true;

		echo "Segment #{$segment} opened\n";

		$buffer = '';
		$lastData = time();

		while (!feof($rFP)) {
			$data = fread($rFP, BUFFER_SIZE);

			if ($data === '' || $data === false) {
				if (time() - $lastData > TIMEOUT) {
					$this->writeError($rStreamID, '[LLOD] stream timeout');
					break;
				}
				usleep(10000);
				continue;
			}

			$lastData = time();
			$buffer .= $data;

			$packets = floor(strlen($buffer) / PACKET_SIZE);
			if ($packets > 0) {
				$writeSize = $packets * PACKET_SIZE;
				fwrite($rSegmentFile, substr($buffer, 0, $writeSize));
				$buffer = substr($buffer, $writeSize);
			}

			if ((microtime(true) - $segmentStart) >= $segmentDuration) {
				fclose($rSegmentFile);
				echo "Segment #{$segment} closed\n";

				$segment++;
				$segmentStart = microtime(true);

				$rSegmentFile = fopen(STREAMS_PATH . $rStreamID . "_{$segment}.ts", 'wb');

				if (!$rSegmentFile) {
					$this->writeError($rStreamID, '[LLOD] Failed to create segment file #' . $segment);
					fclose($rFP);
					return;
				}

				$rSegmentStatus[$segment] = true;

				echo "Segment #{$segment} opened\n";

				$remain = $this->deleteOldSegments($rStreamID, $rSegListSize, $rSegDeleteThreshold, $rSegmentStatus);
				$this->updateSegments($rStreamID, $remain);
			}
		}

		if (is_resource($rSegmentFile)) {
			fclose($rSegmentFile);
		}
		if (is_resource($rFP)) {
			fclose($rFP);
		}
	}

	private function getActiveStream($rStreamID, $rURLs, $rContext) {
		echo "Trying to get active stream from " . count($rURLs) . " URL(s)\n";

		foreach ($rURLs as $index => $rURL) {
			echo "\nAttempting source " . ($index + 1) . "/" . count($rURLs) . ": $rURL\n";

			$rFP = @fopen($rURL, 'rb', false, $rContext);

			if ($rFP) {
				echo "Connection successful\n";

				$rMetadata = stream_get_meta_data($rFP);
				echo "Stream metadata obtained\n";

				$rHeaders = array();

				if (!empty($rMetadata['wrapper_data']) && is_array($rMetadata['wrapper_data'])) {
					foreach ($rMetadata['wrapper_data'] as $rLine) {
						if (strpos($rLine, 'HTTP') !== 0) {
							$pos = strpos($rLine, ':');
							if ($pos !== false) {
								$rKey = substr($rLine, 0, $pos);
								$rValue = trim(substr($rLine, $pos + 1));
								$rHeaders[$rKey] = $rValue;
							}
						} else {
							$rHeaders[0] = $rLine;
						}
					}
				}

				echo "Response headers:\n";
				foreach ($rHeaders as $key => $value) {
					echo "  $key: $value\n";
				}

				$rContentType = $rHeaders['Content-Type'] ?? '';
				echo "Content-Type: $rContentType\n";

				if (stripos($rContentType, 'video/mp2t') !== false) {
					echo "Content-Type is valid MPEG-TS\n";
					echo "=== getActiveStream() successful ===\n\n";
					return $rFP;
				}

				$contentTypeInfo = $rHeaders['Content-Type'] ?? 'unknown';
				$this->writeError($rStreamID, "[LLOD] Source isn't MPEG-TS: " . $rURL . ' - ' . $contentTypeInfo);
				fclose($rFP);
			} else {
				$rError = null;

				if (isset($http_response_header)) {
					foreach ($http_response_header as $rKey => $rHeader) {
						if (preg_match('#HTTP/[0-9\\.]+\\s+([0-9]+)#', $rHeader, $rOutput)) {
							$rError = $rHeader;
						}
					}
				}

				$errorMsg = (!empty($rError) ? $rError : 'Invalid source');
				echo "Connection failed: $errorMsg\n";
				$this->writeError($rStreamID, '[LLOD] ' . $errorMsg . ': ' . $rURL);
			}
		}

		echo "=== failed - no valid sources found ===\n\n";
		return false;
	}

	private function deleteOldSegments($rStreamID, $rKeep, $rThreshold, &$rSegmentStatus): array {
		echo "Stream ID: $rStreamID\n";
		echo "Keep segments: $rKeep\n";
		echo "Delete threshold: $rThreshold\n";

		$rReturn = array();

		if (empty($rSegmentStatus)) {
			return $rReturn;
		}

		$rCurrentSegment = max(array_keys($rSegmentStatus));

		echo "Current segment: $rCurrentSegment\n";
		echo "Segment status array size: " . count($rSegmentStatus) . "\n";

		foreach ($rSegmentStatus as $rSegmentID => $rStatus) {
			if ($rStatus) {
				if ($rSegmentID < $rCurrentSegment - ($rKeep + $rThreshold) + 1) {
					echo "Marking segment $rSegmentID for deletion\n";
					$rSegmentStatus[$rSegmentID] = false;
					$deleted = @unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.ts');
					@unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.m4s');
					echo "Unlink result for segment $rSegmentID: " . ($deleted ? "success" : "failed") . "\n";
				} else {
					if ($rSegmentID !== $rCurrentSegment) {
						$rReturn[] = $rSegmentID;
					}
				}
			}
		}

		echo "Segments to keep (before slice): " . count($rReturn) . "\n";

		if ($rKeep < count($rReturn)) {
			$rReturn = array_slice($rReturn, count($rReturn) - $rKeep, $rKeep);
			echo "Segments to keep (after slice): " . count($rReturn) . "\n";
		} else {
			echo "Keep threshold larger than available segments, keeping all\n";
		}

		return $rReturn;
	}

	private function updateSegments($rStreamID, $segments): void {
		if (empty($segments)) {
			return;
		}

		$duration = SEGMENT_DURATION;

		$m3u8  = "#EXTM3U\n";
		$m3u8 .= "#EXT-X-VERSION:3\n";
		$m3u8 .= "#EXT-X-TARGETDURATION:{$duration}\n";
		$m3u8 .= "#EXT-X-MEDIA-SEQUENCE:" . reset($segments) . "\n";

		foreach ($segments as $seg) {
			$m3u8 .= "#EXTINF:{$duration}.000000,\n";
			$m3u8 .= "{$rStreamID}_{$seg}.ts\n";
		}

		if (@file_put_contents(STREAMS_PATH . $rStreamID . '_.m3u8', $m3u8, LOCK_EX) === false) {
			$this->writeError($rStreamID, '[LLOD] Failed to write playlist file');
			return;
		}

		echo "Playlist updated (" . count($segments) . " segments)\n";
	}

	private function writeError($rStreamID, $rError): void {
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "[$timestamp] $rError\n";
		echo $logMessage;
		@file_put_contents(STREAMS_PATH . $rStreamID . '.errors', $logMessage, FILE_APPEND | LOCK_EX);
	}

	private function checkRunning($rStreamID): void {
		echo "Checking for existing process for stream $rStreamID\n";
		clearstatcache(true);
		$monitorFile = STREAMS_PATH . $rStreamID . '_.monitor';
		$rPID = null;
		if (file_exists($monitorFile)) {
			$rPID = intval(file_get_contents($monitorFile));
			echo "Monitor file found, PID: $rPID\n";
		} else {
			echo "No monitor file found\n";
		}
		if (empty($rPID)) {
			$killCmd = "kill -9 `ps -ef | grep 'LLOD\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`";
			echo "No PID from monitor, executing kill command: $killCmd\n";
			shell_exec($killCmd);
		} else {
			if (file_exists('/proc/' . $rPID)) {
				echo "Process directory exists: /proc/$rPID\n";
				$cmdlineFile = '/proc/' . $rPID . '/cmdline';
				if (file_exists($cmdlineFile)) {
					$rCommand = trim(file_get_contents($cmdlineFile));
					echo "Process command line: $rCommand\n";
					$expectedCommand = 'LLOD[' . $rStreamID . ']';
					if ($rCommand === $expectedCommand && is_numeric($rPID) && 0 < $rPID) {
						echo "Killing existing process PID: $rPID\n";
						posix_kill($rPID, 9);
					} else {
						echo "Process command doesn't match expected: '$rCommand' != '$expectedCommand'\n";
					}
				} else {
					echo "Command line file not found\n";
				}
			} else {
				echo "Process directory doesn't exist, process not running\n";
			}
		}
	}
}
