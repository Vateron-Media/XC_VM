<?php

set_time_limit(0);
require_once 'init.php';
register_shutdown_function([ShutdownHandler::class, 'handle'], 'timeshift');
unset($rSettings['watchdog_data'], $rSettings['server_hardware']);

StreamAuthMiddleware::sendStreamHeaders($rSettings, $rServers);

$rCreateExpiration = 60;
$rProxyID = null;
$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rStartTime = time();

if (isset($rRequest['token'])) {
	$rTokenData = StreamAuthMiddleware::decryptToken($rRequest['token'], $rSettings, $rServers, $rIP);

	$rUsername = $rTokenData['username'];
	$rPassword = $rTokenData['password'];
	$rStreamID = $rTokenData['stream'];
	$rExtension = $rTokenData['extension'];
	$rStartDate = $rTokenData['start'];
	$rDuration = $rTokenData['duration'];
	$rRedirectID = $rTokenData['redirect_id'];
	$rOriginatorID = ($rTokenData['originator_id'] ?: null);
	$rUserInfo = $rTokenData['user_info'];
	$rActivityStart = $rTokenData['activity_start'];
	$rCountryCode = $rTokenData['country_code'];

	if (empty($rTokenData['http_range']) || isset($_SERVER['HTTP_RANGE'])) {
	} else {
		$_SERVER['HTTP_RANGE'] = $rTokenData['http_range'];
	}
} else {
	generateError('NO_TOKEN_SPECIFIED');
}

if ($rSettings['use_buffer'] == 0) {
	header('X-Accel-Buffering: no');
}

if (!is_numeric($rStartDate)) {
	if (substr_count($rStartDate, '-') == 1) {
		list($rDate, $rTime) = explode('-', $rStartDate);
		$rYear = substr($rDate, 0, 4);
		$rMonth = substr($rDate, 4, 2);
		$rDay = substr($rDate, 6, 2);
		$rMinutes = 0;
		$rHour = $rTime;
	} else {
		list($rDate, $rTime) = explode(':', $rStartDate);
		list($rYear, $rMonth, $rDay) = explode('-', $rDate);
		list($rHour, $rMinutes) = explode('-', $rTime);
	}

	$rTimestamp = mktime($rHour, $rMinutes, 0, $rMonth, $rDay, $rYear);
} else {
	$rTimestamp = $rStartDate;
}

$rFile = ARCHIVE_PATH . $rStreamID . '/' . gmdate('Y-m-d:H-i', $rTimestamp) . '.ts';

if (!(empty($rStreamID) || empty($rTimestamp) || empty($rDuration))) {
} else {
	generateError('NO_TIMESTAMP');
}

if (file_exists($rFile) && is_readable($rFile)) {
} else {
	generateError('ARCHIVE_DOESNT_EXIST');
}

$rQueue = array();

// Batch check files using async operations
for ($i = 0; $i < $rDuration; $i++) {
	$rFile = ARCHIVE_PATH . $rStreamID . '/' . gmdate('Y-m-d:H-i', $rTimestamp + $i * 60) . '.ts';

	if (@stat($rFile) !== false) {
		$fileSize = AsyncFileOperations::getFileSize($rFile);
		if ($fileSize !== false) {
			$rQueue[] = array('filename' => $rFile, 'filesize' => $fileSize);
		}
	}
}

if (count($rQueue) != 0) {
} else {
	generateError('ARCHIVE_DOESNT_EXIST');
}

if ($rUserInfo) {
	// Use async file operations for offset file check
	$offsetFile = $rQueue[0]['filename'] . '.offset';
	$offsetContent = AsyncFileOperations::readFile($offsetFile);
	$rOffset = $offsetContent ? intval($offsetContent) : 0;

	if ($rOriginatorID) {
		$rServerID = $rOriginatorID;
		$rProxyID = $rRedirectID;
	} else {
		$rProxyID = null;
		$rServerID = ($rRedirectID ?: SERVER_ID);
	}

	if ($rSettings['redis_handler']) {
		RedisManager::ensureConnected();
	} else {
		DatabaseFactory::connect();
	}

	switch ($rExtension) {
		case 'm3u8':
			if ($rSettings['redis_handler']) {
				$rConnection = ConnectionTracker::getConnection($rTokenData['uuid']);
			} else {
				$db->query('SELECT `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ?;', $rTokenData['uuid']);

				if (0 >= $db->num_rows()) {
				} else {
					$rConnection = $db->get_row();
				}
			}

			if (!$rConnection) {
				if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || ($rActivityStart + $rCreateExpiration) - intval($rServers[SERVER_ID]['time_offset']) >= time()) {
				} else {
					generateError('TOKEN_EXPIRED');
				}

				if ($rSettings['redis_handler']) {
					$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'hls', 'pid' => null, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']);
					$rResult = ConnectionTracker::createConnection($rConnectionData);
				} else {
					$rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`,`hls_last_read`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?);', $rUserInfo['id'], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'hls', null, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name'], '', time() - intval($rServers[SERVER_ID]['time_offset']));
				}
			} else {
				$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

				if ($rIPMatch || !$rSettings['restrict_same_ip']) {
				} else {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
					generateError('IP_MISMATCH');
				}

				if ($rSettings['redis_handler']) {
					$rChanges = array('hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset']));

					if ($rConnection = ConnectionTracker::updateConnection($rConnection, $rChanges, 'open')) {
						$rResult = true;
					} else {
						$rResult = false;
					}
				} else {
					$rResult = $db->query('UPDATE `lines_live` SET `hls_last_read` = ?, `hls_end` = 0 WHERE `activity_id` = ?', time() - intval($rServers[SERVER_ID]['time_offset']), $rConnection['activity_id']);
				}
			}

			if ($rResult) {
			} else {
				DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
				generateError('LINE_CREATE_FAIL');
			}

			StreamAuth::validateConnections($rUserInfo, null, null, $rIP, $rUserAgent);

			if ($rSettings['redis_handler']) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			$rOutput = "#EXTM3U\n";
			$rOutput .= "#EXT-X-VERSION:3\n";
			$rOutput .= "#EXT-X-TARGETDURATION:60\n";
			$rOutput .= "#EXT-X-MEDIA-SEQUENCE:0\n";
			$rOutput .= "#EXT-X-PLAYLIST-TYPE:VOD\n";

			for ($i = 0; $i < count($rQueue); $i++) {
				$rOutput .= "#EXTINF:60.0,\n";
				$rOutput .= (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . Encryption::encrypt('TS/' . $rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rDuration . '/' . $rStartDate . '/' . $rStreamID . '_' . basename($rQueue[$i]['filename']) . '_' . (($i == 0 ? $rOffset : 0)) . '/' . $rTokenData['uuid'] . '/' . $rServerID, $rSettings['live_streaming_pass'], OPENSSL_EXTRA) . "\n";
			}
			$rOutput .= '#EXT-X-ENDLIST';
			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			ob_end_clean();
			header('Content-Type: application/x-mpegurl');
			header('Content-Length: ' . strlen($rOutput));
			echo $rOutput;

			exit();
		default:
			if ($rSettings['redis_handler']) {
				$rConnection = ConnectionTracker::getConnection($rTokenData['uuid']);
			} else {
				$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ?;', $rTokenData['uuid']);

				if (0 < $db->num_rows()) {
					$rConnection = $db->get_row();
				} else {
					if (empty($_SERVER['HTTP_RANGE'])) {
					} else {
						$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `hmac_id` = ? AND `hmac_identifier` = ? AND `container` = ? AND `user_agent` = ? AND `stream_id` = ?;', $rIsHMAC, $rIdentifier, 'hls', $rUserAgent, $rStreamID);

						if (0 >= $db->num_rows()) {
						} else {
							$rConnection = $db->get_row();
						}
					}
				}
			}

			if (!$rConnection) {
				if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || ($rActivityStart + $rCreateExpiration) - intval($rServers[SERVER_ID]['time_offset']) >= time()) {
				} else {
					generateError('TOKEN_EXPIRED');
				}

				if ($rSettings['redis_handler']) {
					$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']);
					$rResult = ConnectionTracker::createConnection($rConnectionData);
				} else {
					$rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rUserInfo['id'], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, $rExtension, $rPID, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name'], '');
				}
			} else {
				$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

				if ($rIPMatch || !$rSettings['restrict_same_ip']) {
				} else {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
					generateError('IP_MISMATCH');
				}

				if (!(ProcessManager::isRunning($rConnection['pid'], 'php-fpm') && $rPID != $rConnection['pid'] && is_numeric($rConnection['pid']) && 0 < $rConnection['pid'])) {
				} else {
					if ($rConnection['server_id'] == SERVER_ID) {
						posix_kill(intval($rConnection['pid']), 9);
					} else {
						$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rConnection['pid'], $rConnection['server_id']);
					}
				}

				if ($rSettings['redis_handler']) {
					$rChanges = array('pid' => $rPID, 'hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset']));

					if ($rConnection = ConnectionTracker::updateConnection($rConnection, $rChanges, 'open')) {
						$rResult = true;
					} else {
						$rResult = false;
					}
				} else {
					$rResult = $db->query('UPDATE `lines_live` SET `hls_end` = 0, `pid` = ? WHERE `activity_id` = ?;', $rPID, $rConnection['activity_id']);
				}
			}

			if ($rResult) {
			} else {
				DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
				generateError('LINE_CREATE_FAIL');
			}

			StreamAuth::validateConnections($rUserInfo, null, null, $rIP, $rUserAgent);

			if ($rSettings['redis_handler']) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			$rCloseCon = true;

			if (!$rSettings['monitor_connection_status']) {
			} else {
				ob_implicit_flush(true);

				while (ob_get_level()) {
					ob_end_clean();
				}
			}

			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			header('Content-Type: video/mp2t');
			$rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData['uuid'];
			$rLength = $rSize = getLength($rQueue) - $rOffset;
			$rBitrate = ($rSize * 0.008) / ($rDuration * 60);
			header('Accept-Ranges: 0-' . $rLength);
			$rStart = 0;
			$rEnd = $rSize - 1;

			if (empty($_SERVER['HTTP_RANGE'])) {
			} else {
				$rRangeStart = $rStart;
				$rRangeEnd = $rEnd;
				list(, $rRange) = explode('=', $_SERVER['HTTP_RANGE'], 2);

				if (strpos($rRange, ',') === false) {




					if ($rRange == '-') {
						$rRangeStart = $rSize - substr($rRange, 1);
					} else {
						$rRange = explode('-', $rRange);
						$rRangeStart = $rRange[0];
						$rRangeEnd = (isset($rRange[1]) && is_numeric($rRange[1]) ? $rRange[1] : $rSize);
					}

					$rRangeEnd = ($rEnd < $rRangeEnd ? $rEnd : $rRangeEnd);

					if (!($rRangeEnd < $rRangeStart || $rSize - 1 < $rRangeStart || $rSize <= $rRangeEnd)) {
						$rStart = $rRangeStart;
						$rEnd = $rRangeEnd;
						$rLength = $rEnd - $rStart + 1;
						header('HTTP/1.1 206 Partial Content');
					} else {
						header('HTTP/1.1 416 Requested Range Not Satisfiable');
						header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

						exit();
					}
				} else {
					header('HTTP/1.1 416 Requested Range Not Satisfiable');
					header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

					exit();
				}
			}

			header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);
			header('Content-Length: ' . $rLength);
			$rStartFrom = 0;

			if (0 >= $rStart) {
			} else {
				$rStartFrom = floor($rStart / ($rSize / count($rQueue)));
			}

			$rFirstFile = false;
			$rSeekTo = 0;
			$rSizeToDate = 0;
			$rDownloadBytes = $rBitrate * 125;
			$rDownloadBytes += $rDownloadBytes * $rSettings['vod_bitrate_plus'] * 0.01;
			$rLastCheck = $rTimeChecked = $rTimeStart = time();
			$rBytesRead = 0;
			$rBuffer = $rSettings['read_buffer_size'];
			$i = 0;
			$o = 0;

			if (0 < $rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) {
				$rLimitAt = intval($rLength * floatval($rSettings['vod_limit_perc'] / 100));
			} else {
				$rLimitAt = $rLength;
			}

			$rApplyLimit = false;

			foreach ($rQueue as $rKey => $rItem) {
				$rSizeToDate += $rItem['filesize'];

				if ($rFirstFile || 0 >= $rStartFrom) {
				} else {
					if ($rKey < $rStartFrom) {
					} else {
						$rFirstFile = true;
						$rSeekTo = $rStart - $rSizeToDate;
					}
				}

				$rFP = fopen($rItem['filename'], 'rb');
				fseek($rFP, $rSeekTo + $rOffset);
				$rOffset = 0;

				while (!feof($rFP)) {
					$rPosition = ftell($rFP);
					$rResponse = stream_get_line($rFP, $rBuffer);
					echo $rResponse;
					$rBytesRead += strlen($rResponse);
					$i++;

					if (!$rApplyLimit && $rLimitAt <= $o * $rBuffer) {
						$rApplyLimit = true;
					} else {
						$o++;
					}

					if (0 < $rDownloadBytes && $rApplyLimit && ceil($rDownloadBytes / $rBuffer) <= $i) {
						// Use efficient sleep instead of blocking sleep
						AsyncFileOperations::efficientSleep(1000000); // 1 second with better CPU usage
					}
					if (30 > time() - $rTimeStart) {
					} else {
						file_put_contents($rConSpeedFile, intval($rBytesRead / 1024 / 30));
						$rTimeStart = time();
						$rBytesRead = 0;
					}

					if (!($rSettings['monitor_connection_status'] && 5 <= time() - $rTimeChecked)) {
					} else {
						if (connection_status() == CONNECTION_NORMAL) {


							$rTimeChecked = time();
						} else {
							exit();
						}
					}

					if (300 > time() - $rLastCheck) {
					} else {
						$rLastCheck = time();
						$rConnection = null;
						$rSettings = CacheReader::get('settings');

						if ($rSettings['redis_handler']) {
							RedisManager::ensureConnected();
							$rConnection = ConnectionTracker::getConnection($rTokenData['uuid']);
							RedisManager::closeInstance();
						} else {
							DatabaseFactory::connect();
							$db->query('SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rTokenData['uuid']);

							if ($db->num_rows() != 1) {
							} else {
								$rConnection = $db->get_row();
							}

							DatabaseFactory::close();
						}

						if (!(!is_array($rConnection) || $rConnection['hls_end'] != 0 || $rConnection['pid'] != $rPID)) {
						} else {
							exit();
						}
					}
				}

				if (!is_resource($rFP)) {
				} else {
					fclose($rFP);
				}

				$rSeekTo = 0;
			}
	}
} else {
	generateError('TOKEN_ERROR');
}

function getLength($rQueue) {
	$rLength = 0;

	foreach ($rQueue as $rItem) {
		$rLength += $rItem['filesize'];
	}

	return $rLength;
}
