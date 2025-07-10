<?php

register_shutdown_function('shutdown');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);
require '../init.php';
$rIP = CoreUtilities::getUserIP();
$rPID = getmypid();

if (CoreUtilities::$rSettings['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if (!empty(CoreUtilities::$rRequest['uitoken'])) {
	$rTokenData = json_decode(CoreUtilities::decryptData(CoreUtilities::$rRequest['uitoken'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);
	CoreUtilities::$rRequest['stream'] = $rTokenData['stream_id'];
	CoreUtilities::$rRequest['extension'] = 'm3u8';
	$rIPMatch = (CoreUtilities::$rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenData['ip']), 0, -1)) == implode('.', array_slice(explode('.', CoreUtilities::getUserIP()), 0, -1)) : $rTokenData['ip'] == CoreUtilities::getUserIP());

	if ($rTokenData['expires'] >= time() && $rIPMatch) {
	} else {
		generate404();
	}

	$rPrebuffer = CoreUtilities::$rSegmentSettings['seg_time'];
} else {
	if (empty(CoreUtilities::$rRequest['password']) || CoreUtilities::$rSettings['live_streaming_pass'] != CoreUtilities::$rRequest['password']) {
		generate404();
	} else {
		if (!in_array($rIP, CoreUtilities::getAllowedIPs())) {
			generate404();
		} else {
			$rPrebuffer = (isset(CoreUtilities::$rRequest['prebuffer']) ? CoreUtilities::$rSegmentSettings['seg_time'] : 0);

			foreach (getallheaders() as $rKey => $rValue) {
				if (strtoupper($rKey) != 'X-XC_VM-PREBUFFER') {
				} else {
					$rPrebuffer = CoreUtilities::$rSegmentSettings['seg_time'];
				}
			}
		}
	}
}

$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
CoreUtilities::$db = &$db;
$rPassword = CoreUtilities::$rSettings['live_streaming_pass'];
$rStreamID = intval(CoreUtilities::$rRequest['stream']);
$rExtension = CoreUtilities::$rRequest['extension'];
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

	if (!(CoreUtilities::$rSettings['on_demand_instant_off'] && $rChannelInfo['on_demand'] == 1)) {
	} else {
		CoreUtilities::addToQueue($rStreamID, $rPID);
	}

	if (CoreUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
	} else {
		$rChannelInfo['pid'] = null;

		if ($rChannelInfo['on_demand'] == 1) {
			if (CoreUtilities::isMonitorRunning($rChannelInfo['monitor_pid'], $rStreamID)) {
			} else {
				CoreUtilities::startMonitor($rStreamID);

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
		if (isset(CoreUtilities::$rRequest['odstart'])) {
			echo '0';

			exit();
		}

		generate404();
	} else {
		if (!isset(CoreUtilities::$rRequest['odstart'])) {
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
			if (!CoreUtilities::isValidStream($rPlaylist, $rChannelInfo['pid'])) {
			} else {
				if (empty(CoreUtilities::$rRequest['segment'])) {
					if (!($rSource = CoreUtilities::generateAdminHLS($rPlaylist, $rPassword, $rStreamID, CoreUtilities::$rRequest['uitoken']))) {
					} else {
						header('Content-Type: application/vnd.apple.mpegurl');
						header('Content-Length: ' . strlen($rSource));
						ob_end_flush();
						echo $rSource;

						exit();
					}
				} else {
					$rSegment = STREAMS_PATH . str_replace(array('\\', '/'), '', urldecode(CoreUtilities::$rRequest['segment']));

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

					if (CoreUtilities::$rSegmentSettings['seg_time'] >= $rDuration) {
					} else {
						CoreUtilities::$rSegmentSettings['seg_time'] = $rDuration;
					}
				}

				$rSegments = CoreUtilities::getPlaylistSegments($rPlaylist, $rPrebuffer, CoreUtilities::$rSegmentSettings['seg_time']);
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
							exit();
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
			$rTotalFails = CoreUtilities::$rSegmentSettings['seg_time'] * 2;

			if (!(($rTotalFails < intval(CoreUtilities::$rSettings['segment_wait_time']) ?: 20))) {
			} else {
				$rTotalFails = (intval(CoreUtilities::$rSettings['segment_wait_time']) ?: 20);
			}

			if (true) {
				$rSegmentFile = sprintf('%d_%d.ts', $rStreamID, $rCurrent + 1);
				$rNextSegment = sprintf('%d_%d.ts', $rStreamID, $rCurrent + 2);
				$rChecks = 0;

				while (!file_exists(STREAMS_PATH . $rSegmentFile) && $rChecks <= $rTotalFails * 10) {
					usleep(100000);
					$rChecks++;
				}

				if (file_exists(STREAMS_PATH . $rSegmentFile)) {
					if (!(empty($rChannelInfo['pid']) && file_exists(STREAMS_PATH . $rStreamID . '_.pid'))) {
					} else {
						$rChannelInfo['pid'] = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
					}

					$rFails = 0;
					$rTimeStart = time();
					$rFP = fopen(STREAMS_PATH . $rSegmentFile, 'r');

					while ($rFails <= $rTotalFails && !file_exists(STREAMS_PATH . $rNextSegment)) {
						$rData = stream_get_line($rFP, CoreUtilities::$rSettings['read_buffer_size']);

						if (!empty($rData)) {
							echo $rData;
							$rData = '';
							$rFails = 0;

							break;
						}

						if (CoreUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
							sleep(1);
							$rFails++;
						}
					}

					if (CoreUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID) && $rFails <= $rTotalFails && file_exists(STREAMS_PATH . $rSegmentFile) && is_resource($rFP)) {
						$rSegmentSize = filesize(STREAMS_PATH . $rSegmentFile);
					} else {
						exit();
					}
				} else {
					exit();
				}
			}
	}
	$rRestSize = $rSegmentSize - ftell($rFP);

	if (0 >= $rRestSize) {
	} else {
		echo stream_get_line($rFP, $rRestSize);
	}

	fclose($rFP);
	$rFails = 0;
	$rCurrent++;
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

	if (!(CoreUtilities::$rSettings['on_demand_instant_off'] && $rChannelInfo['on_demand'] == 1)) {
	} else {
		CoreUtilities::removeFromQueue($rStreamID, $rPID);
	}
}
