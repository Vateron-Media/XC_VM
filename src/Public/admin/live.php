<?php

use XcVm\Core\Util\StreamUtils;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Config\SettingsManager;
/**
 * Admin live stream handler
 *
 * @package XC_VM_Web_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

register_shutdown_function('shutdown');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);
$rIP = NetworkUtils::getUserIP();
$rPID = getmypid();
$rSegmentSettings = array('seg_time' => intval(SettingsManager::getAll()['seg_time']), 'seg_list_size' => intval(SettingsManager::getAll()['seg_list_size']), 'seg_delete_threshold' => intval(SettingsManager::getAll()['seg_delete_threshold']));

if (SettingsManager::getAll()['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if (!empty(RequestManager::getAll()['uitoken'])) {
	$rTokenData = json_decode(Encryption::decrypt(RequestManager::getAll()['uitoken'], SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA), true);
	RequestManager::update('stream', $rTokenData['stream_id']);
	RequestManager::update('extension', 'm3u8');
	$rIPMatch = (SettingsManager::getAll()['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenData['ip']), 0, -1)) == implode('.', array_slice(explode('.', NetworkUtils::getUserIP()), 0, -1)) : $rTokenData['ip'] == NetworkUtils::getUserIP());

	if ($rTokenData['expires'] >= time() && $rIPMatch) {
	} else {
		generate404();
	}

	$rPrebuffer = $rSegmentSettings['seg_time'];
} else {
	if (empty(RequestManager::getAll()['password']) || SettingsManager::getAll()['live_streaming_pass'] != RequestManager::getAll()['password']) {
		generate404();
	} else {
		if (!in_array($rIP, ServerRepository::getAllowedIPs())) {
			generate404();
		} else {
			$rPrebuffer = (isset(RequestManager::getAll()['prebuffer']) ? $rSegmentSettings['seg_time'] : 0);

			foreach (getallheaders() as $rKey => $rValue) {
				if (strtoupper($rKey) != 'X-XC_VM-PREBUFFER') {
				} else {
					$rPrebuffer = $rSegmentSettings['seg_time'];
				}
			}
		}
	}
}

$db = new DatabaseHandler();
DatabaseFactory::set($db);
$rPassword = SettingsManager::getAll()['live_streaming_pass'];
$rStreamID = intval(RequestManager::getAll()['stream']);
$rExtension = RequestManager::getAll()['extension'];
$rWaitTime = 20;
$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.`id` = ?', SERVER_ID, $rStreamID);

if (0 < $db->num_rows()) {
	touch(SIGNALS_TMP_PATH . 'admin_' . intval($rStreamID));
	$rChannelInfo = $db->get_row();
	$db->close_mysql();

	if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
	} else {
		$rChannelInfo['pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
	}

	if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
	} else {
		$rChannelInfo['monitor_pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
	}

	if (!(SettingsManager::getAll()['on_demand_instant_off'] && $rChannelInfo['on_demand'] == 1)) {
	} else {
		ConnectionTracker::addToQueue($rStreamID, $rPID);
	}

	if (ProcessManager::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
	} else {
		$rChannelInfo['pid'] = null;

		if ($rChannelInfo['on_demand'] == 1) {
			if (ProcessManager::isMonitorAlive($rChannelInfo['monitor_pid'], $rStreamID)) {
			} else {
				StreamProcess::startMonitor($rStreamID);

				for ($rRetries = 0; !file_exists(STREAMS_PATH . intval($rStreamID) . '_.monitor') && $rRetries < 300; $rRetries++) {
					usleep(10000);
				}
				$rChannelInfo['monitor_pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
			}
		} else {
			generate404();
		}
	}

	$rRetries = 0;
	$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';

	if ($rExtension == 'ts') {
		if (file_exists($rPlaylist)) {
		} else {
			$rFirstTS = STREAMS_PATH . $rStreamID . '_0.ts';
			$rFP = null;

			while ($rRetries < intval($rWaitTime) * 100) {
				if (!file_exists($rFirstTS) || $rFP) {
				} else {
					$rFP = fopen($rFirstTS, 'r');
				}

				if (!($rFP && fread($rFP, 1))) {
					usleep(10000);
					$rRetries++;

					break;
				}
			}

			if (!$rFP) {
			} else {
				fclose($rFP);
			}
		}
	} else {
		$rFirstTS = STREAMS_PATH . $rStreamID . '_.m3u8';

		while (!file_exists($rPlaylist) && !file_exists($rFirstTS) && $rRetries < intval($rWaitTime) * 100) {
			usleep(10000);
			$rRetries++;
		}
	}

	if ($rRetries == intval($rWaitTime) * 10) {
		if (isset(RequestManager::getAll()['odstart'])) {
			echo '0';

			exit();
		}

		generate404();
	} else {
		if (!isset(RequestManager::getAll()['odstart'])) {
		} else {
			echo '1';

			exit();
		}
	}

	if ($rChannelInfo['pid']) {
	} else {
		$rChannelInfo['pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
	}

	switch ($rExtension) {
		case 'm3u8':
			if (!StreamUtils::isValidStream($rPlaylist, $rChannelInfo['pid'])) {
			} else {
				if (empty(RequestManager::getAll()['segment'])) {
					if (!($rSource = StreamUtils::generateAdminHLS($rPlaylist, $rPassword, $rStreamID, RequestManager::getAll()['uitoken']))) {
					} else {
						header('Content-Type: application/vnd.apple.mpegurl');
						header('Content-Length: ' . strlen($rSource));
						ob_end_flush();
						echo $rSource;

						exit();
					}
				} else {
					$rSegment = STREAMS_PATH . str_replace(array('\\', '/'), '', urldecode(RequestManager::getAll()['segment']));

					if (!file_exists($rSegment)) {
					} else {
						$rBytes = filesize($rSegment);
						header('Content-Length: ' . $rBytes);
						header('Content-Type: video/mp2t');
						readfile($rSegment);

						exit();
					}
				}
			}

			break;

		default:
			header('Content-Type: video/mp2t');

			if (file_exists($rPlaylist)) {
				if (!file_exists(STREAMS_PATH . $rStreamID . '_.dur')) {
				} else {
					$rDuration = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.dur'));

					if ($rSegmentSettings['seg_time'] >= $rDuration) {
					} else {
						$rSegmentSettings['seg_time'] = $rDuration;
					}
				}

				$rSegments = StreamUtils::getPlaylistSegments($rPlaylist, $rPrebuffer, $rSegmentSettings['seg_time']);
			} else {
				$rSegments = null;
			}

			if (!is_null($rSegments)) {
				if (is_array($rSegments)) {
					$rBytes = 0;
					$rStartTime = time();

					foreach ($rSegments as $rSegment) {
						if (file_exists(STREAMS_PATH . $rSegment)) {
							$rBytes += readfile(STREAMS_PATH . $rSegment);
						} else {
							// Prebuffer segment already rotated out (hls_delete_threshold) → skip instead of
							// exit(), otherwise the loopback connection drops and restarts in a loop.
							continue;
						}
					}
					preg_match('/_(.*)\\./', array_pop($rSegments), $rCurrentSegment);
					$rCurrent = $rCurrentSegment[1];
				} else {
					$rCurrent = $rSegments;
				}
			} else {
				if (!file_exists($rPlaylist)) {
					$rCurrent = -1;
				} else {
					exit();
				}
			}

			$rFails = 0;
			$rTotalFails = $rSegmentSettings['seg_time'] * 2;

			if (!(($rTotalFails < intval(SettingsManager::getAll()['segment_wait_time']) ?: 20))) {
			} else {
				$rTotalFails = (intval(SettingsManager::getAll()['segment_wait_time']) ?: 20);
			}

			while (true) {
				$rSegmentFile = sprintf('%d_%d.ts', $rStreamID, $rCurrent + 1);
				$rNextSegment = sprintf('%d_%d.ts', $rStreamID, $rCurrent + 2);
				$rChecks = 0;

				while (!file_exists(STREAMS_PATH . $rSegmentFile) && $rChecks <= $rTotalFails * 10) {
					usleep(100000);
					$rChecks++;
				}

				if (!file_exists(STREAMS_PATH . $rSegmentFile)) {
					exit();
				}

				if (!(empty($rChannelInfo['pid']) && file_exists(STREAMS_PATH . $rStreamID . '_.pid'))) {
				} else {
					$rChannelInfo['pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
				}

				$rFP = fopen(STREAMS_PATH . $rSegmentFile, 'r');
				if (!is_resource($rFP)) {
					exit();
				}
				$rFails = 0;

				// Stream the ENTIRE segment (following it as it grows), then advance $rCurrent.
				// The original always reopened $rCurrent+1 from offset 0 and had $rCurrent++ outside
				// while(true) (unreachable), so it re-served the same ~6s segment in a loop → PCR frozen on the consumer.
				while (true) {
					$rData = stream_get_line($rFP, SettingsManager::getAll()['read_buffer_size']);

					if ($rData !== '' && $rData !== false) {
						echo $rData;
						$rData = '';
						$rFails = 0;

						continue;
					}

					// no new data at this moment
					if (file_exists(STREAMS_PATH . $rNextSegment)) {
						// next segment already exists → current one is complete; drain the remainder and advance
						clearstatcache(true, STREAMS_PATH . $rSegmentFile);
						$rRestSize = filesize(STREAMS_PATH . $rSegmentFile) - ftell($rFP);

						if (0 < $rRestSize) {
							echo stream_get_line($rFP, $rRestSize);
						}

						break;
					}

					if (!ProcessManager::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
						fclose($rFP);

						exit();
					}

					usleep(100000);
					$rFails++;

					// Allow enough time (>= segment duration). With a 0.1s usleep the limit must be
					// $rTotalFails*10 (~20s), not $rTotalFails (~2s), otherwise we give up before the next
					// segment (hls_time=6s) appears → loopback connection drops and restarts every ~5s.
					if ($rTotalFails * 10 < $rFails) {
						fclose($rFP);

						exit();
					}
				}

				fclose($rFP);
				$rFails = 0;
				$rCurrent++;
			}
	}
} else {
	generate404();
}

function shutdown() {
	global $db;
	global $rChannelInfo;
	global $rPID;
	global $rStreamID;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}

	if (!(SettingsManager::getAll()['on_demand_instant_off'] && $rChannelInfo['on_demand'] == 1)) {
	} else {
		ConnectionTracker::removeFromQueue($rStreamID, $rPID);
	}
}
