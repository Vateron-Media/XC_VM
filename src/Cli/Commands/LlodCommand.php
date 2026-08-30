<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Streaming\Fanout\FanoutClient;

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
		if (!defined('CACHE_TMP_PATH')) define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
		if (!defined('CONS_TMP_PATH')) define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
		if (!defined('FFMPEG')) define('FFMPEG', \XcVm\Streaming\Codec\FfmpegPaths::cpu() ?: FFMPEG_BIN_40);
		if (!defined('FFPROBE')) define('FFPROBE', \XcVm\Streaming\Codec\FfmpegPaths::probe() ?: FFPROBE_BIN_40);
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
		// Real target segment duration (seconds) — same knob the ffmpeg path uses.
		// Falls back to the compiled default when the setting is absent.
		$rSegTime = intval($rSettings['seg_time'] ?? 0) ?: SEGMENT_DURATION;

		echo "Starting LLOD processing...\n\n";

		$this->startLlod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold, $rSegTime, $rSegmentStatus, $rFP, $rSegmentFile);

		return 0;
	}

	private function startLlod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold, $rSegTime, &$rSegmentStatus, &$rFP, &$rSegmentFile): void {
		// Keyframe-aligned segmentation. The previous version cut segments on a
		// fixed wall-clock timer (SEGMENT_DURATION) at an arbitrary TS-packet
		// boundary, so a segment frequently started mid-GOP (no IDR keyframe) or
		// without PAT/PMT — the player could not decode it until the next
		// keyframe, producing the "plays a few seconds, stalls, recovers" cycle.
		//
		// Now segments are cut at a random-access point on the video PID (a
		// keyframe) once at least seg_time has elapsed, each segment is prefixed
		// with the last-seen PAT + PMT so it is self-contained, and playback only
		// starts on the first keyframe. A hard ceiling (2×seg_time) falls back to
		// a time-based cut so sources that never flag random access still advance.
		$segTime    = $rSegTime > 0 ? $rSegTime : SEGMENT_DURATION;
		$maxSegTime = $segTime * 2;

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

		// LLOD v3 daemon feed (ADR 0003). LLOD reads MPEG-TS itself (no ffmpeg),
		// so mirror the raw bytes into the xc_fanout daemon's push-fed ingest
		// socket — the same stream then fans out via /live/<id> and in-RAM /hls,
		// and live.php's isStreamFed() routes viewers to the daemon. The write is
		// non-blocking + best-effort so a daemon stall never slows LLOD's own
		// segmenting (its primary job); the daemon resyncs on PAT/PMT after any
		// dropped bytes. Null / failed connect ⇒ legacy-only, no behaviour change.
		$rDaemonSock = FanoutClient::registerIngest($rStreamID);
		$rDaemonConn = null;
		if ($rDaemonSock !== null) {
			$rDaemonConn = @stream_socket_client('unix://' . $rDaemonSock, $rDErrno, $rDErrstr, 2);
			if ($rDaemonConn) {
				stream_set_blocking($rDaemonConn, false);
			}
		}

		shell_exec('rm -f ' . STREAMS_PATH . escapeshellarg($rStreamID) . '_*.ts');

		// ── MPEG-TS demux state ──────────────────────────────────────────
		$patPacket = null;   // last-seen raw PAT packet (PID 0)
		$pmtPacket = null;   // last-seen raw PMT packet
		$pmtPid    = null;   // PID carrying the PMT (parsed from the PAT)
		$videoPid  = null;   // elementary PID of the first video stream (from PMT)

		$segment           = 0;
		$segmentOpen       = false;
		$segmentStart      = microtime(true);
		$rSegmentDurations = array();

		$lastData    = time();
		$firstDataAt = microtime(true);
		$buffer      = '';

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
			$buffer  .= $data;

			// Mirror to the daemon (best-effort, non-blocking). On a write error
			// (daemon gone) stop mirroring; live.php then falls back to legacy.
			if ($rDaemonConn) {
				if (@fwrite($rDaemonConn, $data) === false) {
					@fclose($rDaemonConn);
					$rDaemonConn = null;
				}
			}

			$len = strlen($buffer);
			$off = 0;

			// Process only whole 188-byte TS packets; keep any remainder buffered.
			while ($len - $off >= PACKET_SIZE) {
				// Re-sync to the TS sync byte (0x47) if alignment was lost.
				if ($buffer[$off] !== "\x47") {
					$sync = strpos($buffer, "\x47", $off);
					if ($sync === false) {
						$off = $len; // no sync byte in buffer — drop it
						break;
					}
					if ($len - $sync < PACKET_SIZE) {
						$off = $sync; // partial packet — keep it for the next read
						break;
					}
					$off = $sync;
					continue;
				}

				$pkt = substr($buffer, $off, PACKET_SIZE);
				$off += PACKET_SIZE;

				$hdr = $this->parseTsHeader($pkt);
				$pid = $hdr['pid'];

				// Capture PSI so each new segment can be made self-contained.
				if ($pid === 0) {
					$patPacket = $pkt;
					$newPmtPid = $this->parsePat($pkt, $hdr['payload_offset']);
					if ($newPmtPid !== null) {
						$pmtPid = $newPmtPid;
					}
				} elseif ($pmtPid !== null && $pid === $pmtPid) {
					$pmtPacket = $pkt;
					$newVideoPid = $this->parsePmt($pkt, $hdr['payload_offset']);
					if ($newVideoPid !== null) {
						$videoPid = $newVideoPid;
					}
				}

				// A random-access point on the video PID = a safe segment start.
				$isRAP   = ($videoPid !== null && $pid === $videoPid && $hdr['pusi'] && $hdr['random_access']);
				$elapsed = microtime(true) - $segmentStart;

				if (!$segmentOpen) {
					// Hold segment #0 until the first keyframe so playback never
					// begins mid-GOP. Start anyway if no RAP is seen in time.
					$forceStart = (microtime(true) - $firstDataAt) >= $maxSegTime;
					if (!$isRAP && !$forceStart) {
						continue; // still waiting for a clean start point
					}
					$rSegmentFile = $this->openSegment($rStreamID, $segment, $patPacket, $pmtPacket);
					if (!$rSegmentFile) {
						$this->writeError($rStreamID, '[LLOD] Failed to create initial segment file');
						fclose($rFP);
						return;
					}
					$rSegmentStatus[$segment] = true;
					$segmentOpen  = true;
					$segmentStart = microtime(true);
					echo "Segment #{$segment} opened\n";
				} elseif (($isRAP && $elapsed >= $segTime) || $elapsed >= $maxSegTime) {
					// Cut on a keyframe once seg_time has elapsed; the hard ceiling
					// guarantees progress on sources that never flag random access.
					fclose($rSegmentFile);
					$rSegmentDurations[$segment] = $elapsed;
					echo "Segment #{$segment} closed (" . round($elapsed, 3) . "s)\n";

					$segment++;
					$rSegmentFile = $this->openSegment($rStreamID, $segment, $patPacket, $pmtPacket);
					if (!$rSegmentFile) {
						$this->writeError($rStreamID, '[LLOD] Failed to create segment file #' . $segment);
						fclose($rFP);
						return;
					}
					$rSegmentStatus[$segment] = true;
					$segmentStart = microtime(true);
					echo "Segment #{$segment} opened\n";

					$remain = $this->deleteOldSegments($rStreamID, $rSegListSize, $rSegDeleteThreshold, $rSegmentStatus, $rSegmentDurations);
					$this->updateSegments($rStreamID, $remain, $rSegmentDurations, $segTime);
				}

				fwrite($rSegmentFile, $pkt);
			}

			// Retain the partial trailing packet for the next read.
			$buffer = ($off >= $len) ? '' : substr($buffer, $off);
		}

		if (is_resource($rSegmentFile)) {
			fclose($rSegmentFile);
		}
		if (is_resource($rFP)) {
			fclose($rFP);
		}
		if (is_resource($rDaemonConn)) {
			fclose($rDaemonConn);
		}
		// Drop the daemon ingest on a clean exit; stopStream() is the backstop
		// when LLOD is killed mid-loop.
		FanoutClient::unregister($rStreamID);
	}

	/**
	 * Open a new segment file and prefix it with the last-seen PAT + PMT so the
	 * segment is self-contained (a player can start decoding it from scratch).
	 *
	 * @param int|string  $rStreamID Stream id.
	 * @param int         $segment   Segment index.
	 * @param string|null $patPacket Raw 188-byte PAT packet, or null if not seen yet.
	 * @param string|null $pmtPacket Raw 188-byte PMT packet, or null if not seen yet.
	 * @return resource|false The open file handle, or false on failure.
	 */
	private function openSegment($rStreamID, $segment, $patPacket, $pmtPacket) {
		$file = fopen(STREAMS_PATH . $rStreamID . "_{$segment}.ts", 'wb');
		if (!$file) {
			return false;
		}
		stream_set_write_buffer($file, 8192);
		if ($patPacket !== null) {
			fwrite($file, $patPacket);
		}
		if ($pmtPacket !== null) {
			fwrite($file, $pmtPacket);
		}
		return $file;
	}

	/**
	 * Parse the 4-byte TS header (and adaptation-field flags) of a packet.
	 *
	 * @param string $pkt A 188-byte TS packet.
	 * @return array{pid:int,pusi:bool,random_access:bool,payload_offset:int}
	 */
	private function parseTsHeader($pkt) {
		$b1 = ord($pkt[1]);
		$b2 = ord($pkt[2]);
		$b3 = ord($pkt[3]);

		$pusi = ($b1 & 0x40) !== 0;
		$pid  = (($b1 & 0x1F) << 8) | $b2;
		$afc  = ($b3 & 0x30) >> 4; // 1=payload only, 2=adaptation only, 3=both

		$randomAccess  = false;
		$payloadOffset = 4;

		if ($afc === 2 || $afc === 3) {
			$afLen = ord($pkt[4]);
			if ($afLen > 0) {
				$flags        = ord($pkt[5]);
				$randomAccess = ($flags & 0x40) !== 0; // random_access_indicator
			}
			$payloadOffset = 5 + $afLen;
		}
		// No payload (adaptation-only or reserved) — mark payload as absent.
		if ($afc === 0 || $afc === 2 || $payloadOffset >= PACKET_SIZE) {
			$payloadOffset = PACKET_SIZE;
		}

		return [
			'pid'            => $pid,
			'pusi'           => $pusi,
			'random_access'  => $randomAccess,
			'payload_offset' => $payloadOffset,
		];
	}

	/**
	 * Parse a PAT packet and return the PID of the first program's PMT.
	 *
	 * @param string $pkt           A 188-byte TS packet on PID 0.
	 * @param int    $payloadOffset Offset of the payload within the packet.
	 * @return int|null program_map_PID, or null if not resolvable in this packet.
	 */
	private function parsePat($pkt, $payloadOffset) {
		if ($payloadOffset >= PACKET_SIZE) {
			return null;
		}
		$ptr = ord($pkt[$payloadOffset]);          // pointer_field
		$p   = $payloadOffset + 1 + $ptr;          // start of the PAT section
		if ($p + 8 > PACKET_SIZE || ord($pkt[$p]) !== 0x00) {
			return null;                           // not a PAT section start
		}
		$sectionLength = ((ord($pkt[$p + 1]) & 0x0F) << 8) | ord($pkt[$p + 2]);
		$progStart     = $p + 8;                   // after the fixed section header
		$progEnd       = min($p + 3 + $sectionLength - 4, PACKET_SIZE); // exclude CRC32

		for ($i = $progStart; $i + 4 <= $progEnd; $i += 4) {
			$programNumber = (ord($pkt[$i]) << 8) | ord($pkt[$i + 1]);
			$pid           = ((ord($pkt[$i + 2]) & 0x1F) << 8) | ord($pkt[$i + 3]);
			if ($programNumber !== 0) {            // skip the network PID (prog 0)
				return $pid;
			}
		}
		return null;
	}

	/**
	 * Parse a PMT packet and return the PID of the first video elementary stream
	 * (falling back to the PCR PID when no known video stream type is present).
	 *
	 * @param string $pkt           A 188-byte TS packet on the PMT PID.
	 * @param int    $payloadOffset Offset of the payload within the packet.
	 * @return int|null Video/PCR PID, or null if not resolvable in this packet.
	 */
	private function parsePmt($pkt, $payloadOffset) {
		if ($payloadOffset >= PACKET_SIZE) {
			return null;
		}
		$ptr = ord($pkt[$payloadOffset]);
		$p   = $payloadOffset + 1 + $ptr;
		if ($p + 12 > PACKET_SIZE || ord($pkt[$p]) !== 0x02) {
			return null;                           // not a PMT section start
		}
		$sectionLength     = ((ord($pkt[$p + 1]) & 0x0F) << 8) | ord($pkt[$p + 2]);
		$sectionEnd        = min($p + 3 + $sectionLength - 4, PACKET_SIZE); // exclude CRC32
		$pcrPid            = ((ord($pkt[$p + 8]) & 0x1F) << 8) | ord($pkt[$p + 9]);
		$programInfoLength = ((ord($pkt[$p + 10]) & 0x0F) << 8) | ord($pkt[$p + 11]);

		// Known video stream_types: MPEG-1/2, H.264, HEVC, VC-1.
		$videoTypes = [0x01, 0x02, 0x1B, 0x24, 0xEA];
		$i = $p + 12 + $programInfoLength;         // first ES loop entry
		while ($i + 5 <= $sectionEnd) {
			$streamType   = ord($pkt[$i]);
			$elemPid      = ((ord($pkt[$i + 1]) & 0x1F) << 8) | ord($pkt[$i + 2]);
			$esInfoLength = ((ord($pkt[$i + 3]) & 0x0F) << 8) | ord($pkt[$i + 4]);
			if (in_array($streamType, $videoTypes, true)) {
				return $elemPid;
			}
			$i += 5 + $esInfoLength;
		}
		// No recognised video stream — align cuts to the PCR clock instead.
		return $pcrPid !== 0x1FFF ? $pcrPid : null;
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

				if (!empty($http_response_header)) {
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

	private function deleteOldSegments($rStreamID, $rKeep, $rThreshold, &$rSegmentStatus, &$rSegmentDurations = array()): array {
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
					unset($rSegmentDurations[$rSegmentID]);
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

	private function updateSegments($rStreamID, $segments, $rSegmentDurations = array(), $rSegTime = SEGMENT_DURATION): void {
		if (empty($segments)) {
			return;
		}

		// EXT-X-TARGETDURATION must be >= the longest segment (rounded up), or
		// players reject the playlist. Derive it from the real durations.
		$target = max(1, intval($rSegTime));
		foreach ($segments as $seg) {
			$d = isset($rSegmentDurations[$seg]) ? (int) ceil($rSegmentDurations[$seg]) : intval($rSegTime);
			if ($d > $target) {
				$target = $d;
			}
		}

		$m3u8  = "#EXTM3U\n";
		$m3u8 .= "#EXT-X-VERSION:3\n";
		$m3u8 .= "#EXT-X-TARGETDURATION:{$target}\n";
		$m3u8 .= "#EXT-X-MEDIA-SEQUENCE:" . reset($segments) . "\n";

		foreach ($segments as $seg) {
			// Real measured duration keeps the player's clock from drifting
			// (the old hardcoded 4.000000 caused periodic re-buffering).
			$dur = isset($rSegmentDurations[$seg]) ? (float) $rSegmentDurations[$seg] : (float) $rSegTime;
			$m3u8 .= '#EXTINF:' . number_format($dur, 6, '.', '') . ",\n";
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
					if ($rCommand === $expectedCommand && 0 < $rPID) {
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
