<?php

register_shutdown_function('shutdown');
set_time_limit(0);
require_once 'init.php';
unset(CoreUtilities::$rSettings['watchdog_data'], CoreUtilities::$rSettings['server_hardware']);

header('Access-Control-Allow-Origin: *');

if (empty(CoreUtilities::$rSettings['send_server_header'])) {
} else {
	header('Server: ' . CoreUtilities::$rSettings['send_server_header']);
}

if (!CoreUtilities::$rSettings['send_protection_headers']) {
} else {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if (!CoreUtilities::$rSettings['send_altsvc_header']) {
} else {
	header('Alt-Svc: h3-29=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty(CoreUtilities::$rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	CoreUtilities::$rSettings['send_unique_header_domain'] = '.' . HOST;
}

if (empty(CoreUtilities::$rSettings['send_unique_header'])) {
} else {
	$rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
	header('Set-Cookie: ' . CoreUtilities::$rSettings['send_unique_header'] . '=' . CoreUtilities::generateString(11) . '; Domain=' . CoreUtilities::$rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
}

$rCreateExpiration = 60;
$rProxyID = null;
$rIP = CoreUtilities::getUserIP();
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rIsMag = false;

if (isset(CoreUtilities::$rRequest['token'])) {
	$rTokenData = json_decode(CoreUtilities::decryptData(CoreUtilities::$rRequest['token'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);

	if (is_array($rTokenData)) {
	} else {
		CoreUtilities::clientLog(0, 0, 'LB_TOKEN_INVALID', $rIP);
		generateError('LB_TOKEN_INVALID');
	}

	if (!(isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']))) {
	} else {
		generateError('TOKEN_EXPIRED');
	}

	if (isset($rTokenData['hmac_id'])) {
		$rIsHMAC = $rTokenData['hmac_id'];
		$rIdentifier = $rTokenData['identifier'];
	} else {
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

if (CoreUtilities::$rSettings['use_buffer'] != 0) {
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

	if (CoreUtilities::$rSettings['redis_handler']) {
		CoreUtilities::connectRedis();
	} else {
		CoreUtilities::connectDatabase();
	}

	if (CoreUtilities::$rSettings['redis_handler']) {
		$rConnection = CoreUtilities::getConnection($rTokenData['uuid']);
	} else {
		CoreUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ?;', $rTokenData['uuid']);

		if (0 < CoreUtilities::$db->num_rows()) {
			$rConnection = CoreUtilities::$db->get_row();
		} else {
			if (empty($_SERVER['HTTP_RANGE'])) {
			} else {
				if (!$rIsHMAC) {
					CoreUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `container` = ? AND `user_agent` = ? AND `stream_id` = ?;', $rUserInfo['id'], 'VOD', $rUserAgent, $rStreamID);
				} else {
					CoreUtilities::$db->query('SELECT `server_id`, `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `hmac_id` = ? AND `hmac_identifier` = ? AND `container` = ? AND `user_agent` = ? AND `stream_id` = ?;', $rIsHMAC, $rIdentifier, 'VOD', $rUserAgent, $rStreamID);
				}

				if (0 >= CoreUtilities::$db->num_rows()) {
				} else {
					$rConnection = CoreUtilities::$db->get_row();
				}
			}
		}
	}

	if (!$rConnection) {
		if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || ($rActivityStart + $rCreateExpiration) - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']) >= time()) {
		} else {
			generateError('TOKEN_EXPIRED');
		}

		if (!$rIsHMAC) {
			if (CoreUtilities::$rSettings['redis_handler']) {
				$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']);
				$rResult = CoreUtilities::createConnection($rConnectionData);
			} else {
				$rResult = CoreUtilities::$db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?);', $rUserInfo['id'], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'VOD', $rPID, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name']);
			}
		} else {
			if (CoreUtilities::$rSettings['redis_handler']) {
				$rConnectionData = array('hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier, 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'VOD', 'pid' => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => 0, 'identity' => $rIsHMAC . '_' . $rIdentifier, 'uuid' => $rTokenData['uuid']);
				$rResult = CoreUtilities::createConnection($rConnectionData);
			} else {
				$rResult = CoreUtilities::$db->query('INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'VOD', $rPID, $rTokenData['uuid'], $rActivityStart, $rCountryCode, $rUserInfo['con_isp_name']);
			}
		}
	} else {
		$rIPMatch = (CoreUtilities::$rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

		if ($rIPMatch || !CoreUtilities::$rSettings['restrict_same_ip']) {
		} else {
			CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
			generateError('IP_MISMATCH');
		}

		if (!(CoreUtilities::isProcessRunning($rConnection['pid'], 'php-fpm') && $rPID != $rConnection['pid'] && is_numeric($rConnection['pid']) && 0 < $rConnection['pid'])) {
		} else {
			if ($rConnection['server_id'] == SERVER_ID) {
				posix_kill(intval($rConnection['pid']), 9);
			} else {
				CoreUtilities::$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rConnection['pid'], $rConnection['server_id']);
			}
		}

		if (CoreUtilities::$rSettings['redis_handler']) {
			$rChanges = array('pid' => $rPID, 'hls_last_read' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']));

			if ($rConnection = CoreUtilities::updateConnection($rConnection, $rChanges, 'open')) {
				$rResult = true;
			} else {
				$rResult = false;
			}
		} else {
			$rResult = CoreUtilities::$db->query('UPDATE `lines_live` SET `hls_end` = 0, `pid` = ? WHERE `activity_id` = ?;', $rPID, $rConnection['activity_id']);
		}
	}

	if ($rResult) {
	} else {
		CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
		generateError('LINE_CREATE_FAIL');
	}

	CoreUtilities::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);

	if (CoreUtilities::$rSettings['redis_handler']) {
		CoreUtilities::closeRedis();
	} else {
		CoreUtilities::closeDatabase();
	}

	$rCloseCon = true;

	if (!CoreUtilities::$rSettings['monitor_connection_status']) {
	} else {
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
		$rDownloadBytes += $rDownloadBytes * CoreUtilities::$rSettings['vod_bitrate_plus'] * 0.01;
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
			$rBuffer = CoreUtilities::$rSettings['read_buffer_size'];
			$i = 0;
			$o = 0;

			if (0 < CoreUtilities::$rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) {
				$rLimitAt = intval($rLength * floatval(CoreUtilities::$rSettings['vod_limit_perc'] / 100));
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

				if (!(0 < $rDownloadBytes && $rApplyLimit && ceil($rDownloadBytes / $rBuffer) <= $i)) {
				} else {
					sleep(1);
					$i = 0;
				}

				if (!(CoreUtilities::$rSettings['monitor_connection_status'] && 5 <= time() - $rTimeChecked)) {
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
					CoreUtilities::$rSettings = CoreUtilities::getCache('settings');

					if (CoreUtilities::$rSettings['redis_handler']) {
						CoreUtilities::connectRedis();
						$rConnection = CoreUtilities::getConnection($rTokenData['uuid']);
						CoreUtilities::closeRedis();
					} else {
						CoreUtilities::connectDatabase();
						CoreUtilities::$db->query('SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rTokenData['uuid']);

						if (CoreUtilities::$db->num_rows() != 1) {
						} else {
							$rConnection = CoreUtilities::$db->get_row();
						}

						CoreUtilities::closeDatabase();
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
		$rHeaders = get_headers($rDirectProxy, 1);
		$rContentType = (is_array($rHeaders['Content-Type']) ? $rHeaders['Content-Type'][count($rHeaders['Content-Type']) - 1] : $rHeaders['Content-Type']);
		$rSize = $rLength = $rHeaders['Content-Length'];

		if (0 < $rLength && in_array($rContentType, array('video/mp4', 'video/x-matroska', 'video/x-msvideo', 'video/3gpp', 'video/x-flv', 'video/x-ms-wmv', 'video/quicktime', 'video/mp2t', 'video/mpeg', 'application/octet-stream'))) {
			if (!$rHeaders['Location']) {
			} else {
				$rDirectProxy = $rHeaders['Location'];
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
	CoreUtilities::$rSettings = CoreUtilities::getCache('settings');

	if (!$rCloseCon) {
	} else {
		if (CoreUtilities::$rSettings['redis_handler']) {
			if (is_object(CoreUtilities::$redis)) {
			} else {
				CoreUtilities::connectRedis();
			}

			$rConnection = CoreUtilities::getConnection($rTokenData['uuid']);

			if (!($rConnection && $rConnection['pid'] == $rPID)) {
			} else {
				$rChanges = array('hls_last_read' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']));
				CoreUtilities::updateConnection($rConnection, $rChanges, 'close');
			}
		} else {
			if (is_object(CoreUtilities::$db)) {
			} else {
				CoreUtilities::connectDatabase();
			}

			CoreUtilities::$db->query('UPDATE `lines_live` SET `hls_end` = 1, `hls_last_read` = ? WHERE `uuid` = ? AND `pid` = ?;', time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']), $rTokenData['uuid'], $rPID);
		}
	}

	if (!CoreUtilities::$rSettings['redis_handler'] && is_object(CoreUtilities::$db)) {
		CoreUtilities::closeDatabase();
	} else {
		if (!(CoreUtilities::$rSettings['redis_handler'] && is_object(CoreUtilities::$redis))) {
		} else {
			CoreUtilities::closeRedis();
		}
	}
}
