<?php

register_shutdown_function('shutdown');
set_time_limit(0);
require_once 'init.php';
unset(StreamingUtilities::$rSettings['watchdog_data'], StreamingUtilities::$rSettings['server_hardware']);

header('Access-Control-Allow-Origin: *');

if (empty(StreamingUtilities::$rSettings['send_server_header'])) {
} else {
	header('Server: ' . StreamingUtilities::$rSettings['send_server_header']);
}

if (!StreamingUtilities::$rSettings['send_protection_headers']) {
} else {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if (!StreamingUtilities::$rSettings['send_altsvc_header']) {
} else {
	header('Alt-Svc: h3-29=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty(StreamingUtilities::$rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	StreamingUtilities::$rSettings['send_unique_header_domain'] = '.' . HOST;
}

if (empty(StreamingUtilities::$rSettings['send_unique_header'])) {
} else {
	$rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
	header('Set-Cookie: ' . StreamingUtilities::$rSettings['send_unique_header'] . '=' . StreamingUtilities::generateString(11) . '; Domain=' . StreamingUtilities::$rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
}

$rCreateExpiration = 60;
$rProxyID = null;
$rIP = StreamingUtilities::getUserIP();
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rIsMag = false;

if (isset(StreamingUtilities::$rRequest['token'])) {
	$rTokenData = json_decode(StreamingUtilities::decryptData(StreamingUtilities::$rRequest['token'], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);

	if (is_array($rTokenData)) {
	} else {
		StreamingUtilities::clientLog(0, 0, 'LB_TOKEN_INVALID', $rIP);
		generateError('LB_TOKEN_INVALID');
	}

	if (!(isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']))) {
	} else {
		generateError('TOKEN_EXPIRED');
	}

	if (isset($rTokenData['hmac_id'])) {
		$rIsHMAC = $rTokenData['hmac_id'];
		$rIdentifier = $rTokenData['identifier'];
	} else {
		$rIsHMAC = null;
		$rIdentifier = null;
		$rUsername = $rTokenData['username'];
		$rPassword = $rTokenData['password'];
	}

	$rStreamID = intval($rTokenData['stream_id']);
	$rExtension = $rTokenData['extension'];
	$rType = $rTokenData['type'];
	$rChannelInfo = $rTokenData['channel_info'];
	$rUserInfo = $rTokenData['user_info'];
	$rActivityStart = $rTokenData['activity_start'];
	$rCountryCode = $rTokenData['country_code'];
	$rIsMag = $rTokenData['is_mag'];
	$rDirectProxy = ($rChannelInfo['proxy'] ?: null);

	if (empty($rTokenData['http_range']) || isset($_SERVER['HTTP_RANGE'])) {
	} else {
		$_SERVER['HTTP_RANGE'] = $rTokenData['http_range'];
	}
} else {
	generateError('NO_TOKEN_SPECIFIED');
}

$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;

if (file_exists($rRequest) || $rDirectProxy) {
} else {
	generateError('VOD_DOESNT_EXIST');
}

if (StreamingUtilities::$rSettings['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if ($rChannelInfo) {
	if ($rChannelInfo['originator_id']) {
		$rServerID = $rChannelInfo['originator_id'];
		$rProxyID = $rChannelInfo['redirect_id'];
	} else {
		$rServerID = ($rChannelInfo['redirect_id'] ?: SERVER_ID);
		$rProxyID = null;
	}

	if (StreamingUtilities::$rSettings['redis_handler']) {
		StreamingUtilities::connectRedis();
	} else {
		StreamingUtilities::connectDatabase();
	}

	if (StreamingUtilities::$rSettings['redis_handler']) {
		$rConnection = StreamingUtilities::getConnection($rTokenData['uuid']);
	} else {
		StreamingUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ?;', $rTokenData['uuid']);

		if (0 < StreamingUtilities::$db->num_rows()) {
			$rConnection = StreamingUtilities::$db->get_row();
		} else {
			if (!empty($_SERVER['HTTP_RANGE'])) {
				if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
					StreamingUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `container` = ? AND `user_agent` = ? AND `stream_id` = ?;', $rUserInfo['id'], 'VOD', $rUserAgent, $rStreamID);
				} else {
					StreamingUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `hmac_id` = ? AND `hmac_identifier` = ? AND `container` = ? AND `user_agent` = ? AND `stream_id` = ?;', $rIsHMAC, $rIdentifier, 'VOD', $rUserAgent, $rStreamID);
				}

				if (StreamingUtilities::$db->num_rows() > 0) {
					$rConnection = StreamingUtilities::$db->get_row();
				}
			}
		}
	}

	if (!isset($rConnection)) {
		if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || ($rActivityStart + $rCreateExpiration) - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']) >= time()) {
		} else {
			generateError('TOKEN_EXPIRED');
		}

		if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
			if (StreamingUtilities::$rSettings['redis_handler']) {
				$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']);
				$rResult = StreamingUtilities::createConnection($rConnectionData);
			} else {
				$rResult = StreamingUtilities::$db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?);', $rUserInfo['id'], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'VOD', $rPID, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name']);
			}
		} else {
			if (StreamingUtilities::$rSettings['redis_handler']) {
				$rConnectionData = array('hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier, 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rIsHMAC . '_' . $rIdentifier, 'uuid' => $rTokenData['uuid']);
				$rResult = StreamingUtilities::createConnection($rConnectionData);
			} else {
				$rResult = StreamingUtilities::$db->query('INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'VOD', $rPID, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name']);
			}
		}
	} else {
		$rIPMatch = (StreamingUtilities::$rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

		if ($rIPMatch || !StreamingUtilities::$rSettings['restrict_same_ip']) {
		} else {
			StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
			generateError('IP_MISMATCH');
		}

		if (StreamingUtilities::isProcessRunning($rConnection['pid'], 'php-fpm') && $rPID != $rConnection['pid'] && is_numeric($rConnection['pid']) && 0 < $rConnection['pid']) {
			if ($rConnection['server_id'] == SERVER_ID) {
				posix_kill(intval($rConnection['pid']), 9);
			} else {
				StreamingUtilities::$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rConnection['pid'], $rConnection['server_id']);
			}
		}

		if (StreamingUtilities::$rSettings['redis_handler']) {
			$rChanges = array('pid' => $rPID, 'hls_last_read' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']));

			if ($rConnection = StreamingUtilities::updateConnection($rConnection, $rChanges, 'open')) {
				$rResult = true;
			} else {
				$rResult = false;
			}
		} else {
			$rResult = StreamingUtilities::$db->query('UPDATE `lines_live` SET `hls_end` = 0, `pid` = ? WHERE `activity_id` = ?;', $rPID, $rConnection['activity_id']);
		}
	}

	if (!$rResult) {
		StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
		generateError('LINE_CREATE_FAIL');
	}

	StreamingUtilities::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);

	if (StreamingUtilities::$rSettings['redis_handler']) {
		StreamingUtilities::closeRedis();
	} else {
		StreamingUtilities::closeDatabase();
	}

	$rCloseCon = true;

	if (StreamingUtilities::$rSettings['monitor_connection_status']) {
		ob_implicit_flush(true);

		while (ob_get_level()) {
			ob_end_clean();
		}
	}

	touch(CONS_TMP_PATH . $rTokenData['uuid']);

	if (!$rDirectProxy) {
		$rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData['uuid'];

		switch ($rChannelInfo['target_container']) {
			case 'mp4':
			case 'm4v':
				header('Content-type: video/mp4');

				break;

			case 'mkv':
				header('Content-type: video/x-matroska');

				break;

			case 'avi':
				header('Content-type: video/x-msvideo');

				break;

			case '3gp':
				header('Content-type: video/3gpp');

				break;

			case 'flv':
				header('Content-type: video/x-flv');

				break;

			case 'wmv':
				header('Content-type: video/x-ms-wmv');

				break;

			case 'mov':
				header('Content-type: video/quicktime');

				break;

			case 'ts':
				header('Content-type: video/mp2t');

				break;

			case 'mpg':
			case 'mpeg':
				header('Content-Type: video/mpeg');

				break;

			default:
				header('Content-Type: application/octet-stream');
		}
		$rDownloadBytes = (!empty($rChannelInfo['bitrate']) ? $rChannelInfo['bitrate'] * 125 : 0);
		$rDownloadBytes += $rDownloadBytes * StreamingUtilities::$rSettings['vod_bitrate_plus'] * 0.01;
		$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;

		if (!file_exists($rRequest)) {
		} else {
			$rFP = @fopen($rRequest, 'rb');
			$rSize = filesize($rRequest);
			$rLength = $rSize;
			$rStart = 0;
			$rEnd = $rSize - 1;
			header('Accept-Ranges: 0-' . $rLength);

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
						fseek($rFP, $rStart);
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
			$rLastCheck = $rTimeStart = $rTimeChecked = time();
			$rBytesRead = 0;
			$rBuffer = StreamingUtilities::$rSettings['read_buffer_size'];
			$i = 0;
			$o = 0;

			if (0 < StreamingUtilities::$rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) {
				$rLimitAt = intval($rLength * floatval(StreamingUtilities::$rSettings['vod_limit_perc'] / 100));
			} else {
				$rLimitAt = $rLength;
			}

			$rApplyLimit = false;

			while (!feof($rFP) && ($p = ftell($rFP)) <= $rEnd) {
				$rResponse = stream_get_line($rFP, $rBuffer);
				$i++;

				if (!$rApplyLimit && $rLimitAt <= $o * $rBuffer) {
					$rApplyLimit = true;
				} else {
					$o++;
				}

				echo $rResponse;
				$rBytesRead += strlen($rResponse);

				if (30 > time() - $rTimeStart) {
				} else {
					file_put_contents($rConSpeedFile, intval($rBytesRead / 1024 / 30));
					$rTimeStart = time();
					$rBytesRead = 0;
				}

				if (0 < $rDownloadBytes && $rApplyLimit && ceil($rDownloadBytes / $rBuffer) <= $i) {
					// Use efficient sleep instead of blocking sleep
					AsyncFileOperations::efficientSleep(1000000); // 1 second with better CPU usage
					$i = 0;
				}

				if (!(StreamingUtilities::$rSettings['monitor_connection_status'] && 5 <= time() - $rTimeChecked)) {
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
					StreamingUtilities::$rSettings = StreamingUtilities::getCache('settings');

					if (StreamingUtilities::$rSettings['redis_handler']) {
						StreamingUtilities::connectRedis();
						$rConnection = StreamingUtilities::getConnection($rTokenData['uuid']);
						StreamingUtilities::closeRedis();
					} else {
						StreamingUtilities::connectDatabase();
						StreamingUtilities::$db->query('SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rTokenData['uuid']);

						if (StreamingUtilities::$db->num_rows() != 1) {
						} else {
							$rConnection = StreamingUtilities::$db->get_row();
						}

						StreamingUtilities::closeDatabase();
					}

					if (!(!is_array($rConnection) || $rConnection['hls_end'] != 0 || $rConnection['pid'] != $rPID)) {
					} else {
						exit();
					}
				}
			}
			fclose($rFP);

			exit();
		}
	} else {

		$opts = array(
			'http'=> array(
			'max_redirects' => '20',
			'method'=>   "GET",
			'timeout' => 122,
			'user_agent'=> 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.16; rv:101.0) Gecko/20100101 Firefox/101.0'
			)
		);
		$context = stream_context_create($opts);
		$rHeaders = get_headers($rDirectProxy, 1, $context);
		$rContentType = (is_array($rHeaders['Content-Type']) ? $rHeaders['Content-Type'][count($rHeaders['Content-Type']) - 1] : $rHeaders['Content-Type']);
		$rSize = $rLength = $rHeaders['Content-Length'];

		if (0 < $rLength && in_array($rContentType, array('video/mp4', 'video/x-matroska', 'video/x-msvideo', 'video/3gpp', 'video/x-flv', 'video/x-ms-wmv', 'video/quicktime', 'video/mp2t', 'video/mpeg', 'application/octet-stream'))) {
			if (!$rHeaders['Location']) {
			} else {
			if(is_array($rHeaders['Location'])){
					$tmp = array_reverse($rHeaders['Location']);
					$rDirectProxy = $tmp[0];
				}
				else {
					$rDirectProxy = $rHeaders['Location'];
				}
				unset($rHeaders['Location']);
			}

			header('Content-Type: ' . $rContentType);
			header('Accept-Ranges: bytes');
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
			$ch = curl_init();

			if (!isset($_SERVER['HTTP_RANGE'])) {
			} else {
				preg_match('/bytes=(\\d+)-(\\d+)?/', $_SERVER['HTTP_RANGE'], $rMatches);
				$rOffset = intval($rMatches[1]);
				$rLength = $rSize - $rOffset - 1;
				$rHeaders = array('Range: bytes=' . $rOffset . '-' . ($rOffset + $rLength));
				curl_setopt($ch, CURLOPT_HTTPHEADER, $rHeaders);
			}

			if (512 * 1024 * 1024 >= $rSize) {
			} else {
				$rMaxRate = (!empty($rChannelInfo['bitrate']) ? ($rSize * 0.008) / $rChannelInfo['bitrate'] * 125 * 3 : 20 * 1024 * 1024);

				if ($rMaxRate >= 1 * 1024 * 1024) {
				} else {
					$rMaxRate = 1 * 1024 * 1024;
				}

				curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, intval($rMaxRate));
			}

			curl_setopt($ch, CURLOPT_BUFFERSIZE, 10 * 1024 * 1024);
			curl_setopt($ch, CURLOPT_VERBOSE, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
			curl_setopt($ch, CURLOPT_URL, $rDirectProxy);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_NOBODY, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_exec($ch);

			exit();
		}

		generateError('VOD_DOESNT_EXIST');
	}
} else {
	generateError('TOKEN_ERROR');
}

function shutdown() {
	global $rCloseCon;
	global $rTokenData;
	global $rPID;
	StreamingUtilities::$rSettings = StreamingUtilities::getCache('settings');

	if (!$rCloseCon) {
	} else {
		if (StreamingUtilities::$rSettings['redis_handler']) {
			if (is_object(StreamingUtilities::$redis)) {
			} else {
				StreamingUtilities::connectRedis();
			}

			$rConnection = StreamingUtilities::getConnection($rTokenData['uuid']);

			if (!($rConnection && $rConnection['pid'] == $rPID)) {
			} else {
				$rChanges = array('hls_last_read' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']));
				StreamingUtilities::updateConnection($rConnection, $rChanges, 'close');
			}
		} else {
			if (is_object(StreamingUtilities::$db)) {
			} else {
				StreamingUtilities::connectDatabase();
			}

			StreamingUtilities::$db->query('UPDATE `lines_live` SET `hls_end` = 1, `hls_last_read` = ? WHERE `uuid` = ? AND `pid` = ?;', time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']), $rTokenData['uuid'], $rPID);
		}
	}

	if (!StreamingUtilities::$rSettings['redis_handler'] && is_object(StreamingUtilities::$db)) {
		StreamingUtilities::closeDatabase();
	} else {
		if (!(StreamingUtilities::$rSettings['redis_handler'] && is_object(StreamingUtilities::$redis))) {
		} else {
			StreamingUtilities::closeRedis();
		}
	}
}
