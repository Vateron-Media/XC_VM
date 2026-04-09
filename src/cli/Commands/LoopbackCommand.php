<?php

/**
 * LoopbackCommand — loopback command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LoopbackCommand implements CommandInterface {

	public function getName(): string {
		return 'loopback';
	}

	public function getDescription(): string {
		return 'Loopback — receive MPEG-TS stream from another server';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (count($rArgs) < 2) {
			echo "Loopback cannot be directly run!\n";
			return 0;
		}

		error_reporting(0);
		ini_set('display_errors', 0);
		$rStreamID = intval($rArgs[0]);
		$rServerID = intval($rArgs[1]);

		if (!defined('MAIN_HOME')) define('MAIN_HOME', '/home/xc_vm/');
		if (!defined('STREAMS_PATH')) define('STREAMS_PATH', MAIN_HOME . 'content/streams/');
		if (!defined('FFMPEG')) define('FFMPEG', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffmpeg');
		if (!defined('FFPROBE')) define('FFPROBE', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffprobe');
		if (!defined('CACHE_TMP_PATH')) define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
		if (!defined('CONFIG_PATH')) define('CONFIG_PATH', MAIN_HOME . 'config/');
		if (!defined('PAT_HEADER')) define('PAT_HEADER', "�\r");
		if (!defined('KEYFRAME_HEADER')) define('KEYFRAME_HEADER', "\x07P");
		if (!defined('PACKET_SIZE')) define('PACKET_SIZE', 188);
		if (!defined('BUFFER_SIZE')) define('BUFFER_SIZE', 12032);
		if (!defined('PAT_PERIOD')) define('PAT_PERIOD', 2);
		if (!defined('TIMEOUT')) define('TIMEOUT', 20);
		if (!defined('TIMEOUT_READ')) define('TIMEOUT_READ', 1);

		if (!file_exists(CONFIG_PATH . 'config.ini')) {
			echo "Config file missing!\n";
			return 0;
		}
		if (!file_exists(CACHE_TMP_PATH . 'settings')) {
			echo "Settings not cached!\n";
			return 0;
		}
		if (!file_exists(CACHE_TMP_PATH . 'servers')) {
			echo "Servers not cached!\n";
			return 0;
		}

		$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
		if (!defined('SERVER_ID')) define('SERVER_ID', intval($rConfig['server_id']));
		$this->checkRunning($rStreamID);

		$rFP = null;
		$rSegmentFile = null;
		$rSegmentDuration = array();
		$rSegmentStatus = array();
		$rLastPTS = null;
		$rCurPTS = null;

		register_shutdown_function(function () use (&$rFP, &$rSegmentFile) {
			if (is_resource($rSegmentFile)) {
				@fclose($rSegmentFile);
			}
			if (is_resource($rFP)) {
				@fclose($rFP);
			}
		});

		set_time_limit(0);
		cli_set_process_title('Loopback[' . $rStreamID . ']');
		require MAIN_HOME . 'streaming/TimeshiftClient.php';

		$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
		$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
		$rSegListSize = $rSettings['seg_list_size'];
		$rSegDeleteThreshold = $rSettings['seg_delete_threshold'];

		$rLoopURL = (!is_null($rServers[SERVER_ID]['private_url_ip']) && !is_null($rServers[$rServerID]['private_url_ip']) ? $rServers[$rServerID]['private_url_ip'] : $rServers[$rServerID]['public_url_ip']);
		$rFP = @fopen($rLoopURL . 'admin/live?stream=' . @intval($rStreamID) . '&password=' . @urlencode($rSettings['live_streaming_pass']) . '&extension=ts&prebuffer=1', 'rb');
		if (!$rFP) {
			return 0;
		}

		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		stream_set_blocking($rFP, true);
		$rExcessBuffer = $rPrebuffer = $rBuffer = $rPacket = '';
		$rPATHeaders = array();
		$rNewSegment = $rPAT = false;
		$rFirstWrite = true;
		$rLastPacket = time();
		$rLastSegment = round(microtime(true) * 1000);
		$rSegment = 0;
		$rSegmentFile = fopen(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts', 'wb');
		$rSegmentStatus[$rSegment] = true;
		echo 'PID: ' . getmypid() . "\n";

		while (!feof($rFP)) {
			stream_set_timeout($rFP, TIMEOUT_READ);
			$rBuffer = $rBuffer . $rExcessBuffer . fread($rFP, BUFFER_SIZE - strlen($rBuffer . $rExcessBuffer));
			$rExcessBuffer = '';
			$rPacketNum = floor(strlen($rBuffer) / PACKET_SIZE);
			if (0 < $rPacketNum) {
				$rLastPacket = time();
				if (strlen($rBuffer) != $rPacketNum * PACKET_SIZE) {
					$rExcessBuffer = substr($rBuffer, $rPacketNum * PACKET_SIZE, strlen($rBuffer) - $rPacketNum * PACKET_SIZE);
					$rBuffer = substr($rBuffer, 0, $rPacketNum * PACKET_SIZE);
				}
				$rPacketNo = 0;
				foreach (str_split($rBuffer, PACKET_SIZE) as $rPacket) {
					list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
					$rSync = $rHeader >> 24 & 255;
					if ($rSync == 71) {
						if (substr($rPacket, 6, 4) == PAT_HEADER) {
							$rPAT = true;
							$rPATHeaders = array();
						} else {
							$rAdaptationField = $rHeader >> 4 & 3;
							if (($rAdaptationField & 2) === 2) {
								if (0 < count($rPATHeaders) && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == KEYFRAME_HEADER) {
									$rPrebuffer = implode('', $rPATHeaders);
									$rNewSegment = true;
									$rPAT = false;
									$rPATHeaders = array();
									$rHandler = new TS();
									$rHandler->setPacket($rPacket);
									$rPacketInfo = $rHandler->parsePacket();
									if (isset($rPacketInfo['pts'])) {
										$rLastPTS = $rCurPTS;
										$rCurPTS = $rPacketInfo['pts'];
									}
									unset($rHandler);
								}
							}
						}
						if ($rPAT && count($rPATHeaders) < 10) {
							$rPATHeaders[] = $rPacket;
						}
						if ($rNewSegment) {
							$rPrebuffer .= $rPacket;
						}
						$rPacketNo++;
					} else {
						$this->writeError($rStreamID, '[Loopback] No sync byte detected! Stream is out of sync.');
						$i = 0;
						while ($i < strlen($rPacket)) {
							if (substr($rPacket, $i, 2) == 'G' . "\x01") {
								if (strlen(fread($rFP, $i)) == $i) {
									$this->writeError($rStreamID, '[Loopback] Resynchronised stream. Continuing...');
									$rLastPacket = time();
									break;
								}
							}
							$i++;
						}
						$this->writeError($rStreamID, "[Loopback] Couldn't rectify out-of-sync data. Exiting.");
						return 1;
					}
				}
				if ($rNewSegment) {
					$rLastSegment = round(microtime(true) * 1000);
					$rPosition = strpos($rBuffer, $rPrebuffer);
					if (0 < $rPosition) {
						$rLastBuffer = substr($rBuffer, 0, $rPosition);
						if (!$rFirstWrite) {
							fwrite($rSegmentFile, $rLastBuffer, strlen($rLastBuffer));
						}
					}
					if (!$rFirstWrite) {
						fclose($rSegmentFile);
						$rSegment++;
						$rSegmentFile = fopen(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts', 'wb');
						$rSegmentStatus[$rSegment] = true;
						$rSegmentsRemaining = $this->deleteOldSegments($rStreamID, $rSegListSize, $rSegDeleteThreshold, $rSegmentStatus);
						$this->updateSegments($rStreamID, $rSegmentsRemaining, $rSegmentDuration, $rLastPTS, $rCurPTS);
					}
					$rFirstWrite = false;
					fwrite($rSegmentFile, $rPrebuffer, strlen($rPrebuffer));
					$rPrebuffer = '';
					$rNewSegment = false;
				} else {
					fwrite($rSegmentFile, $rBuffer, strlen($rBuffer));
				}
				$rBuffer = '';
			}
			if (TIMEOUT > time() - $rLastPacket) {
				break;
			}
			echo 'No data, timeout reached' . "\n";
			$this->writeError($rStreamID, '[Loopback] No data received for ' . TIMEOUT . ' seconds, closing source.');
		}

		if (time() - $rLastPacket < TIMEOUT) {
			$this->writeError($rStreamID, '[Loopback] Connection to source closed unexpectedly.');
		}
		fclose($rSegmentFile);
		fclose($rFP);

		return 0;
	}

	private function checkRunning($rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
		}
		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'Loopback\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} else {
			if (file_exists('/proc/' . $rPID)) {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'Loopback[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID) {
					posix_kill($rPID, 9);
				}
			}
		}
	}

	private function deleteOldSegments($rStreamID, $rKeep, $rThreshold, &$rSegmentStatus): array {
		$rReturn = array();
		$rCurrentSegment = max(array_keys($rSegmentStatus));
		foreach ($rSegmentStatus as $rSegmentID => $rStatus) {
			if ($rStatus) {
				if ($rSegmentID < $rCurrentSegment - ($rKeep + $rThreshold) + 1) {
					$rSegmentStatus[$rSegmentID] = false;
					@unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.ts');
				} else {
					if ($rSegmentID != $rCurrentSegment) {
						$rReturn[] = $rSegmentID;
					}
				}
			}
		}
		if ($rKeep < count($rReturn)) {
			$rReturn = array_slice($rReturn, count($rReturn) - $rKeep, $rKeep);
		}
		return $rReturn;
	}

	private function updateSegments($rStreamID, $rSegmentsRemaining, &$rSegmentDuration, $rLastPTS, $rCurPTS): void {
		$rHLS = '#EXTM3U' . "\n" . '#EXT-X-VERSION:3' . "\n" . '#EXT-X-TARGETDURATION:4' . "\n" . '#EXT-X-MEDIA-SEQUENCE:';
		$rSequence = false;
		foreach ($rSegmentsRemaining as $rSegment) {
			if (file_exists(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts')) {
				if (!$rSequence) {
					$rHLS .= $rSegment . "\n";
					$rSequence = true;
				}
				if (!isset($rSegmentDuration[$rSegment]) && $rLastPTS) {
					$rSegmentDuration[$rSegment] = ($rCurPTS - $rLastPTS) / 90000;
				}
				$rHLS .= '#EXTINF:' . round((isset($rSegmentDuration[$rSegment]) ? $rSegmentDuration[$rSegment] : 10), 0) . '.000000,' . "\n" . $rStreamID . '_' . $rSegment . '.ts' . "\n";
			}
		}
		file_put_contents(STREAMS_PATH . $rStreamID . '_.m3u8', $rHLS);
	}

	private function writeError($rStreamID, $rError): void {
		echo $rError . "\n";
		file_put_contents(STREAMS_PATH . $rStreamID . '.errors', $rError . "\n", FILE_APPEND | LOCK_EX);
	}
}
