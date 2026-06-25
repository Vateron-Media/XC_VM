<?php

use XcVm\Domain\Stream\StreamProcess;
use XcVm\Core\Config\ConfigReader;
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
		// PAT_HEADER restored to the real PAT header bytes (0xB0 0x0D) derived from the stream.
		// The value was corrupted (byte 0xB0 → space/U+FFFD) by a non-binary-safe editor.
		if (!defined('PAT_HEADER')) define('PAT_HEADER', "\xB0\x0D");
		if (!defined('KEYFRAME_HEADER')) define('KEYFRAME_HEADER', "\x07P");
		if (!defined('PACKET_SIZE')) define('PACKET_SIZE', 188);
		if (!defined('BUFFER_SIZE')) define('BUFFER_SIZE', 12032);
		if (!defined('PAT_PERIOD')) define('PAT_PERIOD', 2);
		// Minimum VIDEO duration per segment (90 kHz ticks). ~1.5s guarantees one cut per 2s GOP
		// and prevents sub-GOP cuts that were producing 8 KB .ts files. Measured in PTS (video time),
		// so it stays correct even when the source bursts at 400+ Mbps.
		if (!defined('MIN_SEG_PTS')) define('MIN_SEG_PTS', 135000);
		// Segment size safety cap. If the source stops delivering keyframes with an advancing PCR
		// (e.g. re-serving the same chunk to a consumer), we still rotate on the next keyframe to
		// avoid writing a giant .ts that fills the disk. ~16 MB ≈ ~20s, well above a normal segment (~800 KB).
		if (!defined('MAX_SEG_BYTES')) define('MAX_SEG_BYTES', 16777216);
		if (!defined('TIMEOUT')) define('TIMEOUT', 20);
		if (!defined('TIMEOUT_READ')) define('TIMEOUT_READ', 1);

		if (!file_exists(CACHE_TMP_PATH . 'settings')) {
			echo "Settings not cached!\n";
			return 0;
		}
		if (!file_exists(CACHE_TMP_PATH . 'servers')) {
			echo "Servers not cached!\n";
			return 0;
		}

		if (!defined('SERVER_ID')) define('SERVER_ID', intval(ConfigReader::get('server_id')));
		$this->checkRunning($rStreamID);

		// Single-instance lock per stream. The monitor/watchdog (StreamProcess::startLoopback) can
		// relaunch loopback while another instance is still running → two processes writing the same
		// N_*.ts files, corrupting segmentation (one giant segment / uncontrolled segment numbers).
		// flock is released automatically on process death, so it handles crashes and watchdog races.
		$rLock = @fopen(STREAMS_PATH . $rStreamID . '_.loopback.lock', 'c');
		if (!$rLock || !flock($rLock, LOCK_EX | LOCK_NB)) {
			echo 'Another loopback for stream ' . $rStreamID . " is already running. Exiting.\n";
			return 0;
		}

		$rFP = null;
		$rSegmentFile = null;
		$rSegmentDuration = array();
		$rSegmentStatus = array();
		$rLastPTS = null;
		$rCurPTS = null;
		$rSegStartPTS = null;

		register_shutdown_function(function () use (&$rFP, &$rSegmentFile, &$rLock) {
			if (is_resource($rSegmentFile)) {
				@fclose($rSegmentFile);
			}
			if (is_resource($rFP)) {
				@fclose($rFP);
			}
			if (is_resource($rLock)) {
				@flock($rLock, LOCK_UN);
				@fclose($rLock);
			}
		});

		set_time_limit(0);
		cli_set_process_title('Loopback[' . $rStreamID . ']');
		require MAIN_HOME . 'Streaming/TimeshiftClient.php';

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
		$rResyncCount = 0;
		$rLastResyncLog = 0;
		$rLastSegment = round(microtime(true) * 1000);
		$rSegment = 0;
		$rSegmentFile = fopen(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts', 'wb');
		$rSegmentStatus[$rSegment] = true;
		echo 'PID: ' . getmypid() . "\n";

		while (!feof($rFP)) {
			stream_set_timeout($rFP, TIMEOUT_READ);
			$rRead = fread($rFP, BUFFER_SIZE - strlen($rBuffer . $rExcessBuffer));
			// Connection is considered alive whenever raw bytes arrive (even pure 0xFF padding).
			// Previously the timeout clock only advanced on clean packets, so a period of only padding
			// fired a false "No data" timeout. The timer must reflect a silent socket, not missing packets.
			if ($rRead !== false && $rRead !== '') {
				$rLastPacket = time();
			}
			$rBuffer = $rBuffer . $rExcessBuffer . $rRead;
			$rExcessBuffer = '';
			// The source (admin/live) interleaves unaligned 0xFF padding between real packets, breaking
			// sync roughly once per buffer. Sanitize here: keep only valid packets (0x47 + 188 bytes),
			// skipping junk — without discarding good packets or reading from the socket again.
			// Any partial packet at the end goes into $rExcessBuffer and is completed on the next read.
			$rClean = '';
			$rLen = strlen($rBuffer);
			$rI = 0;
			$rSkipped = false;
			while ($rI < $rLen) {
				if ($rBuffer[$rI] === "\x47") {
					if ($rI + PACKET_SIZE > $rLen) {
						break; // incomplete packet at end → carry over to excess
					}
					$rClean .= substr($rBuffer, $rI, PACKET_SIZE);
					$rI += PACKET_SIZE;
				} else {
					$rNext = strpos($rBuffer, "\x47", $rI + 1);
					if ($rNext === false) {
						$rI = $rLen; // nothing but junk until end of buffer → discard
						break;
					}
					$rSkipped = true;
					$rI = $rNext;
				}
			}
			$rExcessBuffer = substr($rBuffer, $rI);
			$rBuffer = $rClean;
			if ($rSkipped) {
				// These gaps are routine for this source; log at most once per 60s to avoid flooding.
				$rResyncCount++;
				if (time() - $rLastResyncLog >= 60) {
					$this->writeError($rStreamID, '[Loopback] Realigned ' . $rResyncCount . ' junk gap(s) in source stream (padding).');
					$rLastResyncLog = time();
					$rResyncCount = 0;
				}
			}
			$rPacketNum = floor(strlen($rBuffer) / PACKET_SIZE);
			if (0 < $rPacketNum) {
				foreach (str_split($rBuffer, PACKET_SIZE) as $rPacket) {
					list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
					$rSync = $rHeader >> 24 & 255;
					if ($rSync == 71) {
						if (substr($rPacket, 6, 2) == PAT_HEADER) {
							$rPAT = true;
							$rPATHeaders = array();
						} else {
							$rAdaptationField = $rHeader >> 4 & 3;
							if (($rAdaptationField & 2) === 2) {
								if (0 < count($rPATHeaders) && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == KEYFRAME_HEADER) {
									// Extract PCR directly from the adaptation field. TS::parsePacket() is
									// unsuitable here — its getBits() only advances on zero bits and returns
									// garbage PTS, causing the gate to never fire (one giant segment).
									// Keyframe flags are 0x50, so PCR_flag (0x10) is set: PCR base in the
									// 5 bytes starting at offset 6.
									$rKfPTS = null;
									if ((ord($rPacket[5]) & 0x10) && strlen($rPacket) >= 12) {
										$rPcrB = array_values(unpack('C6', substr($rPacket, 6, 6)));
										$rKfPTS = ($rPcrB[0] << 25) | ($rPcrB[1] << 17) | ($rPcrB[2] << 9) | ($rPcrB[3] << 1) | ($rPcrB[4] >> 7);
									}
									// Open a new segment only when >= MIN_SEG_PTS of VIDEO (PCR) has elapsed
									// since the start of the current segment. A PCR discontinuity (rollback) forces a cut.
									$rDoRotate = false;
									if ($rFirstWrite || is_null($rSegStartPTS) || is_null($rKfPTS)) {
										$rDoRotate = true;
									} else {
										$rDelta = $rKfPTS - $rSegStartPTS;
										if ($rDelta < 0 || MIN_SEG_PTS <= $rDelta) {
											$rDoRotate = true;
										} elseif (is_resource($rSegmentFile) && MAX_SEG_BYTES <= ftell($rSegmentFile)) {
											$rDoRotate = true; // safety cap: PCR not advancing but segment is already huge
										}
									}
									if ($rDoRotate) {
										$rPrebuffer = implode('', $rPATHeaders);
										$rNewSegment = true;
										$rPAT = false;
										$rPATHeaders = array();
										$rLastPTS = $rSegStartPTS;
										$rCurPTS = $rKfPTS;
										$rSegStartPTS = $rKfPTS;
									} else {
										// Still within the current segment duration: consume the PAT/keyframe pair without rotating.
										$rPAT = false;
										$rPATHeaders = array();
									}
								}
							}
						}
						if ($rPAT && count($rPATHeaders) < 10) {
							$rPATHeaders[] = $rPacket;
						}
						if ($rNewSegment) {
							$rPrebuffer .= $rPacket;
						}
					} else {
						// Defensive: the buffer is sanitized on input (only 0x47+188 packets),
						// so a packet without a sync byte should not occur here; skip as a precaution.
						continue;
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
			// The condition was previously inverted (TIMEOUT > elapsed) — since elapsed is ~0 right after
			// a packet, TIMEOUT(20) > 0 was always true and the loop broke on the first iteration.
			// We should only exit when no data has arrived for >= TIMEOUT seconds.
			if (time() - $rLastPacket >= TIMEOUT) {
				echo 'No data, timeout reached' . "\n";
				$this->writeError($rStreamID, '[Loopback] No data received for ' . TIMEOUT . ' seconds, closing source.');
				break;
			}
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
