<?php

header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'init.php';
require_once INCLUDES_PATH . 'streaming.php';

if (($rSettings['enable_cache'] && !file_exists(CACHE_TMP_PATH . 'cache_complete') || empty($rSettings['live_streaming_pass']))) {
	generateError('CACHE_INCOMPLETE');
}

$rIsMag = false;
$rMagToken = null;

if (isset($_GET['token']) && !ctype_xdigit($_GET['token'])) {
	$rData = explode('/', CoreUtilities::decryptData($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));
	$_GET['type'] = $rData[0];
	$rTypeSplit = explode('::', $_GET['type']);

	if (count($rTypeSplit) == 2) {
		$_GET['type'] = $rTypeSplit[1];
		$rIsMag = true;
	}

	if ($_GET['type'] == 'timeshift') {
		list(, $_GET['username'], $_GET['password'], $_GET['duration'], $_GET['start'], $_GET['stream']) = $rData;

		if ($rIsMag) {
			$rMagToken = $rData[6];
		}

		$_GET['extension'] = 'ts';
	} else {
		list(, $_GET['username'], $_GET['password'], $_GET['stream']) = $rData;

		if (5 <= count($rData)) {
			$_GET['extension'] = $rData[4];
		}

		if (count($rData) == 6) {
			if ($rIsMag) {
				$rMagToken = $rData[5];
			} else {
				$rExpiry = $rData[5];
			}
		}

		if (!isset($_GET['extension'])) {
			$_GET['extension'] = 'ts';
		}
	}

	unset($_GET['token'], $rData);
}

if (isset($_GET['utc'])) {
	$_GET['type'] = 'timeshift';
	$_GET['start'] = $_GET['utc'];
	$_GET['duration'] = 3600 * 6;
	unset($_GET['utc']);
}

$rType = (isset($_GET['type']) ? $_GET['type'] : 'live');
$rStreamID = intval($_GET['stream']);
$rExtension = (isset($_GET['extension']) ? strtolower(preg_replace('/[^A-Za-z0-9 ]/', '', trim($_GET['extension']))) : null);

if (!$rExtension && in_array($rType, array('movie', 'series', 'subtitle'))) {
	$rStream = pathinfo($_GET['stream']);
	$rStreamID = intval($rStream['filename']);
	$rExtension = strtolower(preg_replace('/[^A-Za-z0-9 ]/', '', trim($rStream['extension'])));
}

if ($rExtension) {
	if (!($rStreamID && (!$rSettings['enable_cache'] || file_exists(STREAMS_TMP_PATH . 'stream_' . $rStreamID)))) {
		generateError('INVALID_STREAM_ID');
	}

	if (($rSettings['ignore_invalid_users'] && $rSettings['enable_cache'])) {
		if (isset($_GET['token'])) {
			if (!file_exists(LINES_TMP_PATH . 'line_t_' . $_GET['token'])) {
				generateError('INVALID_CREDENTIALS');
			}
		} else {
			if ((isset($_GET['username']) && isset($_GET['password']))) {
				if ($rSettings['case_sensitive_line']) {
					$rPath = LINES_TMP_PATH . 'line_c_' . $_GET['username'] . '_' . $_GET['password'];
				} else {
					$rPath = LINES_TMP_PATH . 'line_c_' . strtolower($_GET['username']) . '_' . strtolower($_GET['password']);
				}

				if (!file_exists($rPath)) {
					generateError('INVALID_CREDENTIALS');
				}
			}
		}
	}

	if (($rSettings['enable_cache'] && !$rSettings['show_not_on_air_video'] && file_exists(CACHE_TMP_PATH . 'servers'))) {
		$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
		$rStream = (igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamID)) ?: null);
		$rAvailableServers = array();

		if ($rType == 'archive') {
			if ((0 < $rStream['info']['tv_archive_duration'] && 0 < $rStream['info']['tv_archive_server_id'] && array_key_exists($rStream['info']['tv_archive_server_id'], $rServers) && $rServers[$rStream['info']['tv_archive_server_id']]['server_online'])) {
				$rAvailableServers[] = array($rStream['info']['tv_archive_server_id']);
			}
		} else {
			if (($rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 0)) {
				$rAvailableServers[] = $rServerID;
			}

			foreach ($rServers as $rServerID => $rServerInfo) {
				if (!(!array_key_exists($rServerID, $rStream['servers']) || !$rServerInfo['server_online'] || $rServerInfo['server_type'] != 0)) {
					if (isset($rStream['servers'][$rServerID])) {
						if ($rType == 'movie') {
							if (((!empty($rStream['servers'][$rServerID]['pid']) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && $rStream['servers'][$rServerID]['stream_status'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1) && ($rStream['info']['target_container'] == $rExtension || ($rExtension = 'srt')) && $rServerInfo['timeshift_only'] == 0)) {
								$rAvailableServers[] = $rServerID;
							}
						} else {
							if ((($rStream['servers'][$rServerID]['on_demand'] == 1 && $rStream['servers'][$rServerID]['stream_status'] != 1 || 0 < $rStream['servers'][$rServerID]['pid'] && $rStream['servers'][$rServerID]['stream_status'] == 0) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && (int) $rStream['servers'][$rServerID]['delay_available_at'] <= time() && $rServerInfo['timeshift_only'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1)) {
								$rAvailableServers[] = $rServerID;
							}
						}
					}
				}
			}
		}

		if (count($rAvailableServers) == 0) {
			CoreUtilities::showVideoServer('show_not_on_air_video', 'not_on_air_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
		}
	}

	CoreUtilities::$rAccess = 'auth';
	CoreUtilities::$rSettings = $rSettings;
	CoreUtilities::init(false);

	if (!CoreUtilities::$rCached) {
		CoreUtilities::connectDatabase();
		$db = &CoreUtilities::$db;
	}

	header('Access-Control-Allow-Origin: *');
	register_shutdown_function('shutdown');
	$rRestreamDetect = false;
	$rPrebuffer = isset(CoreUtilities::$rRequest['prebuffer']);

	foreach (getallheaders() as $rKey => $rValue) {
		if (strtoupper($rKey) == 'X-XC_VM-DETECT') {
			$rRestreamDetect = true;
		} else {
			if (strtoupper($rKey) == 'X-XC_VM-PREBUFFER') {
				$rPrebuffer = true;
			}
		}
	}
	$rIsEnigma = false;
	$rUserInfo = null;
	$rIsHMAC = null;
	$rIdentifier = '';
	$rPID = getmypid();
	$rUUID = md5(uniqid());
	$rIP = CoreUtilities::getUserIP();
	$rCountryCode = CoreUtilities::getIPInfo($rIP)['country']['iso_code'];
	$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
	$rDeny = true;
	$rExternalDevice = null;
	$rActivityStart = time();

	if (!isset($rExpiry)) {
		$rExpiry = null;
	}

	if (isset(CoreUtilities::$rRequest['token'])) {
		$rAccessToken = CoreUtilities::$rRequest['token'];
		$rUserInfo = CoreUtilities::getUserInfo(null, $rAccessToken, null, false, false, $rIP);
	} else {
		if (isset(CoreUtilities::$rRequest['hmac'])) {
			if (!in_array($rType, array('live', 'movie', 'series'))) {
				$rDeny = false;
				generateError('INVALID_TYPE_TOKEN');
			}

			$rIdentifier = (empty(CoreUtilities::$rRequest['identifier']) ? '' : CoreUtilities::$rRequest['identifier']);
			$rHMACIP = (empty(CoreUtilities::$rRequest['ip']) ? '' : CoreUtilities::$rRequest['ip']);
			$rMaxConnections = (isset(CoreUtilities::$rRequest['max']) ? intval(CoreUtilities::$rRequest['max']) : 0);
			$rExpiry = (isset(CoreUtilities::$rRequest['expiry']) ? CoreUtilities::$rRequest['expiry'] : null);

			if (($rExpiry && $rExpiry < time())) {
				$rDeny = false;
				generateError('TOKEN_EXPIRED');
			}

			$rIsHMAC = CoreUtilities::validateHMAC(CoreUtilities::$rRequest['hmac'], $rExpiry, $rStreamID, $rExtension, $rIP, $rHMACIP, $rIdentifier, $rMaxConnections);

			if ($rIsHMAC) {
				$rUserInfo = array('id' => null, 'is_restreamer' => 0, 'force_server_id' => 0, 'con_isp_name' => null, 'max_connections' => $rMaxConnections);

				if (CoreUtilities::$rSettings['show_isps']) {
					$rISPLock = CoreUtilities::getISP($rIP);

					if (is_array($rISPLock)) {
						$rUserInfo['con_isp_name'] = $rISPLock['isp'];
					}
				}
			}
		} else {
			$rUsername = CoreUtilities::$rRequest['username'];
			$rPassword = CoreUtilities::$rRequest['password'];
			$rUserInfo = CoreUtilities::getUserInfo(null, $rUsername, $rPassword, false, false, $rIP);
		}
	}

	if ($rUserInfo || $rIsHMAC) {
		$rDeny = false;
		CoreUtilities::checkAuthFlood($rUserInfo, $rIP);

		if ((CoreUtilities::$rServers[SERVER_ID]['enable_proxy'] && !CoreUtilities::isProxy($_SERVER['HTTP_X_IP']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
			generateError('PROXY_ACCESS_DENIED');
		}

		if ($rUserInfo['is_e2']) {
			$rIsEnigma = true;
		}

		if (isset($rAccessToken)) {
			$rUsername = $rUserInfo['username'];
			$rPassword = $rUserInfo['password'];
		}

		if (!$rIsHMAC) {
			if (!(is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time())) {
				CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_EXPIRED', $rIP);

				if (in_array($rType, array('live', 'timeshift'))) {
					CoreUtilities::showVideoServer('show_expired_video', 'expired_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
				} else {
					if (in_array($rType, array('movie', 'series'))) {
						CoreUtilities::showVideoServer('show_expired_video', 'expired_video_path', 'ts', $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
					} else {
						generateError('EXPIRED');
					}
				}
			}

			if ($rUserInfo['admin_enabled'] == 0) {
				CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_BAN', $rIP);

				if (in_array($rType, array('live', 'timeshift'))) {
					CoreUtilities::showVideoServer('show_banned_video', 'banned_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
				} else {
					if (in_array($rType, array('movie', 'series'))) {
						CoreUtilities::showVideoServer('show_banned_video', 'banned_video_path', 'ts', $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
					} else {
						generateError('BANNED');
					}
				}
			}

			if ($rUserInfo['enabled'] == 0) {
				CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISABLED', $rIP);

				if (in_array($rType, array('live', 'timeshift'))) {
					CoreUtilities::showVideoServer('show_banned_video', 'banned_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
				} else {
					if (in_array($rType, array('movie', 'series'))) {
						CoreUtilities::showVideoServer('show_banned_video', 'banned_video_path', 'ts', $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
					} else {
						generateError('DISABLED');
					}
				}
			}

			if ($rType != 'subtitle') {
				if ($rUserInfo['bypass_ua'] == 0) {
					if (CoreUtilities::checkBlockedUAs($rUserAgent)) {
						generateError('BLOCKED_USER_AGENT');
					}
				}

				if ((empty($rUserAgent) && CoreUtilities::$rSettings['disallow_empty_user_agents'])) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'EMPTY_UA', $rIP);
					generateError('EMPTY_USER_AGENT');
				}

				if (!(empty($rUserInfo['allowed_ips']) || in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips'])))) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'IP_BAN', $rIP);
					generateError('NOT_IN_ALLOWED_IPS');
				}

				if (!empty($rCountryCode)) {
					$rForceCountry = !empty($rUserInfo['forced_country']);

					if (($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country'])) {
						CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
						generateError('FORCED_COUNTRY_INVALID');
					}

					if (!($rForceCountry || in_array('ALL', CoreUtilities::$rSettings['allow_countries']) || in_array($rCountryCode, CoreUtilities::$rSettings['allow_countries']))) {
						CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
						generateError('NOT_IN_ALLOWED_COUNTRY');
					}
				}

				if (!(empty($rUserInfo['allowed_ua']) || in_array($rUserAgent, $rUserInfo['allowed_ua']))) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_AGENT_BAN', $rIP);
					generateError('NOT_IN_ALLOWED_UAS');
				}

				if ($rUserInfo['isp_violate']) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'ISP_LOCK_FAILED', $rIP, json_encode(array('old' => $rUserInfo['isp_desc'], 'new' => $rUserInfo['con_isp_name'])));
					generateError('ISP_BLOCKED');
				}

				if ($rUserInfo['isp_is_server'] && !$rUserInfo['is_restreamer']) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'BLOCKED_ASN', $rIP, json_encode(array('user_agent' => $rUserAgent, 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
					generateError('ASN_BLOCKED');
				}

				if ($rUserInfo['is_mag'] && !$rIsMag) {
					generateError('DEVICE_NOT_ALLOWED');
				} else {
					if ($rIsMag && !CoreUtilities::$rSettings['disable_mag_token'] && (!$rMagToken || $rMagToken != $rUserInfo['mag_token'])) {
						generateError('TOKEN_EXPIRED');
					} else {
						if (($rExpiry && $rExpiry < time())) {
							CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'TOKEN_EXPIRED', $rIP);
							generateError('TOKEN_EXPIRED');
						}
					}
				}
			}

			// if (($rUserInfo['is_stalker'] && in_array($rType, array('live', 'movie', 'series', 'timeshift')))) {
			// 	if ((empty(CoreUtilities::$rRequest['stalker_key']) || $rExtension != 'ts')) {
			// 		generateError('STALKER_INVALID_KEY');
			// 	}

			// 	$rStalkerKey = base64_decode(urldecode(CoreUtilities::$rRequest['stalker_key']));

			// 	if ($rDecryptKey = CoreUtilities::mc_decrypt($rStalkerKey, md5(CoreUtilities::$rSettings['live_streaming_pass']))) {
			// 		$rStalkerData = explode('=', $rDecryptKey);

			// 		if ($rStalkerData[2] != $rStreamID) {
			// 			CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'STALKER_CHANNEL_MISMATCH', $rIP);
			// 			generateError('STALKER_CHANNEL_MISMATCH');
			// 		}

			// 		$rIPMatch = (CoreUtilities::$rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rStalkerData[1]), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rStalkerData[1] == $rIP);

			// 		if (!$rIPMatch && CoreUtilities::$rSettings['restrict_same_ip']) {
			// 			CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'STALKER_IP_MISMATCH', $rIP);
			// 			generateError('STALKER_IP_MISMATCH');
			// 		}

			// 		$rCreateExpiration = (CoreUtilities::$rSettings['create_expiration'] ?: 5);

			// 		if ($rStalkerData[3] < time() - $rCreateExpiration) {
			// 			CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'STALKER_KEY_EXPIRED', $rIP);
			// 			generateError('STALKER_KEY_EXPIRED');
			// 		}

			// 		$rExternalDevice = $rStalkerData[0];
			// 	} else {
			// 		CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'STALKER_DECRYPT_FAILED', $rIP);
			// 		generateError('STALKER_DECRYPT_FAILED');
			// 	}
			// }

			if (!in_array($rType, array('thumb', 'subtitle'))) {
				if (!($rUserInfo['is_restreamer'] || in_array($rIP, CoreUtilities::$rAllowedIPs))) {
					if ((CoreUtilities::$rSettings['block_streaming_servers'] || CoreUtilities::$rSettings['block_proxies'])) {
						$rCIDR = CoreUtilities::matchCIDR($rUserInfo['isp_asn'], $rIP);

						if ($rCIDR) {
							if ((CoreUtilities::$rSettings['block_streaming_servers'] && $rCIDR[3]) && !$rCIDR[4]) {
								CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'HOSTING_DETECT', $rIP, json_encode(array('user_agent' => $rUserAgent, 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
								generateError('HOSTING_DETECT');
							}

							if ((CoreUtilities::$rSettings['block_proxies'] && $rCIDR[4])) {
								CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'PROXY_DETECT', $rIP, json_encode(array('user_agent' => $rUserAgent, 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
								generateError('PROXY_DETECT');
							}
						}
					}

					if ($rRestreamDetect) {
						if (CoreUtilities::$rSettings['detect_restream_block_user']) {
							if (CoreUtilities::$rCached) {
								CoreUtilities::setSignal('restream_block_user/' . $rUserInfo['id'] . '/' . $rStreamID . '/' . $rIP, 1);
							} else {
								$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserInfo['id']);
							}
						}

						if ((CoreUtilities::$rSettings['restream_deny_unauthorised'] || CoreUtilities::$rSettings['detect_restream_block_user'])) {
							CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'RESTREAM_DETECT', $rIP, json_encode(array('user_agent' => $rUserAgent, 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
							generateError('RESTREAM_DETECT');
						}
					}
				}
			}

			if ($rType == 'live') {
				if (!in_array($rExtension, $rUserInfo['output_formats'])) {
					CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISALLOW_EXT', $rIP);
					generateError('USER_DISALLOW_EXT');
				}
			}

			if (($rType == 'live' && CoreUtilities::$rSettings['show_expiring_video'] && !$rUserInfo['is_trial'] && !is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] - 86400 * 7 <= time() && (86400 <= time() - $rUserInfo['last_expiration_video'] || !$rUserInfo['last_expiration_video']))) {
				if (CoreUtilities::$rCached) {
					CoreUtilities::setSignal('expiring/' . $rUserInfo['id'], time());
				} else {
					$db->query('UPDATE `lines` SET `last_expiration_video` = ? WHERE `id` = ?;', time(), $rUserInfo['id']);
				}

				CoreUtilities::showVideoServer('show_expiring_video', 'expiring_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
			}
		}
	} else {
		CoreUtilities::checkBruteforce($rIP, null, $rUsername);
		CoreUtilities::clientLog($rStreamID, 0, 'AUTH_FAILED', $rIP);
		generateError('INVALID_CREDENTIALS');
	}

	if ($rIsMag) {
		$rForceHTTP = CoreUtilities::$rSettings['mag_disable_ssl'];
	} else {
		if ($rIsEnigma) {
			$rForceHTTP = true;
		} else {
			$rForceHTTP = false;
		}
	}

	switch ($rType) {
		case 'live':
			$rChannelInfo = CoreUtilities::redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'live');

			if (is_array($rChannelInfo)) {
				if (count(array_keys($rChannelInfo)) == 0) {
					generateError('NO_SERVERS_AVAILABLE');
				}

				if (!array_intersect($rUserInfo['bouquet'], $rChannelInfo['bouquets'])) {
					generateError('NOT_IN_BOUQUET');
				}

				if ((CoreUtilities::isProxied($rChannelInfo['redirect_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
					$rProxies = CoreUtilities::getProxies($rChannelInfo['redirect_id']);
					$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

					if (!$rProxyID) {
						generateError('NO_SERVERS_AVAILABLE');
					}

					$rChannelInfo['originator_id'] = $rChannelInfo['redirect_id'];
					$rChannelInfo['redirect_id'] = $rProxyID;
				}

				$rURL = CoreUtilities::getStreamingURL($rChannelInfo['redirect_id'], ($rChannelInfo['originator_id'] ?: null), $rForceHTTP);
				$rStreamInfo = json_decode($rChannelInfo['stream_info'], true);
				$rVideoCodec = ($rStreamInfo['codecs']['video']['codec_name'] ?: 'h264');

				switch ($rExtension) {
					case 'm3u8':
						if ((CoreUtilities::$rSettings['disable_hls'] && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['disable_hls_allow_restream']))) {
							generateError('HLS_DISABLED');
						}

						if ($rChannelInfo['direct_proxy']) {
							generateError('HLS_DISABLED');
						}

						$rAdaptive = json_decode($rChannelInfo['adaptive_link'], true);

						if (!$rIsHMAC && is_array($rAdaptive) && 0 < count($rAdaptive)) {
							$rParts = array();

							foreach (array_merge(array($rStreamID), $rAdaptive) as $rAdaptiveID) {
								if ($rAdaptiveID != $rStreamID) {
									$rAdaptiveInfo = CoreUtilities::redirectStream($rAdaptiveID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'live');

									if ((CoreUtilities::isProxied($rAdaptiveInfo['redirect_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
										$rProxies = CoreUtilities::getProxies($rAdaptiveInfo['redirect_id']);
										$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

										if (!$rProxyID) {
											generateError('NO_SERVERS_AVAILABLE');
										}

										$rAdaptiveInfo['originator_id'] = $rAdaptiveInfo['redirect_id'];
										$rAdaptiveInfo['redirect_id'] = $rProxyID;
									}

									$rURL = CoreUtilities::getStreamingURL($rAdaptiveInfo['redirect_id'], ($rAdaptiveInfo['originator_id'] ?: null), $rForceHTTP);
								} else {
									$rAdaptiveInfo = $rChannelInfo;
								}

								$rStreamInfo = json_decode($rAdaptiveInfo['stream_info'], true);
								$rBitrate = ($rStreamInfo['bitrate'] ?: 0);
								$rWidth = ($rStreamInfo['codecs']['video']['width'] ?: 0);
								$rHeight = ($rStreamInfo['codecs']['video']['height'] ?: 0);

								if ((0 < $rBitrate && 0 < $rHeight && 0 < $rWidth)) {
									$rTokenData = array('stream_id' => $rAdaptiveID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'pid' => $rPID, 'channel_info' => array('redirect_id' => $rAdaptiveInfo['redirect_id'], 'originator_id' => ($rAdaptiveInfo['originator_id'] ?: null), 'pid' => $rAdaptiveInfo['pid'], 'on_demand' => $rAdaptiveInfo['on_demand'], 'monitor_pid' => $rAdaptiveInfo['monitor_pid']), 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_id' => $rUserInfo['pair_id'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'external_device' => $rExternalDevice, 'activity_start' => $rActivityStart, 'country_code' => $rCountryCode, 'video_codec' => ($rStreamInfo['codecs']['video']['codec_name'] ?: 'h264'), 'uuid' => $rUUID, 'adaptive' => array($rChannelInfo['redirect_id'], $rStreamID));
									$rStreamURL = (string) $rURL . '/auth/' . CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
									$rParts[$rBitrate] = '#EXT-X-STREAM-INF:BANDWIDTH=' . $rBitrate . ',RESOLUTION=' . $rWidth . 'x' . $rHeight . "\n" . $rStreamURL;
								}
							}

							if (0 < count($rParts)) {
								krsort($rParts);
								$rM3U8 = "#EXTM3U\n" . implode("\n", array_values($rParts));
								ob_end_clean();
								header('Content-Type: application/x-mpegurl');
								header('Content-Length: ' . strlen($rM3U8));
								echo $rM3U8;

								exit();
							}

							CoreUtilities::showVideoServer('show_not_on_air_video', 'not_on_air_video_path', 'ts', $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], ($rChannelInfo['originator_id'] ?: $rChannelInfo['redirect_id']), ($rChannelInfo['originator_id'] ? $rChannelInfo['redirect_id'] : null));

							exit();
						} else {
							if (!$rIsHMAC) {
								$rTokenData = array('stream_id' => $rStreamID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'pid' => $rPID, 'channel_info' => array('redirect_id' => $rChannelInfo['redirect_id'], 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'on_demand' => $rChannelInfo['on_demand'], 'llod' => $rChannelInfo['llod'], 'monitor_pid' => $rChannelInfo['monitor_pid']), 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_id' => $rUserInfo['pair_id'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'external_device' => $rExternalDevice, 'activity_start' => $rActivityStart, 'country_code' => $rCountryCode, 'video_codec' => $rVideoCodec, 'uuid' => $rUUID);
							} else {
								$rTokenData = array('stream_id' => $rStreamID, 'hmac_hash' => CoreUtilities::$rRequest['hmac'], 'hmac_id' => $rIsHMAC, 'identifier' => $rIdentifier, 'extension' => $rExtension, 'channel_info' => array('redirect_id' => $rChannelInfo['redirect_id'], 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'on_demand' => $rChannelInfo['on_demand'], 'llod' => $rChannelInfo['llod'], 'monitor_pid' => $rChannelInfo['monitor_pid']), 'user_info' => $rUserInfo, 'pid' => $rPID, 'external_device' => $rExternalDevice, 'activity_start' => $rActivityStart, 'country_code' => $rCountryCode, 'video_codec' => $rVideoCodec, 'uuid' => $rUUID);
							}

							$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

							if (CoreUtilities::$rSettings['allow_cdn_access']) {
								header('Location: ' . $rURL . '/auth/' . $rStreamID . '.m3u8?token=' . $rToken);
								exit();
							}

							header('Location: ' . $rURL . '/auth/' . $rToken);
							exit();
						}

						// no break
					case 'ts':
						if ((CoreUtilities::$rSettings['disable_ts'] && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['disable_ts_allow_restream']))) {
							generateError('TS_DISABLED');
						}

						if (!$rIsHMAC) {
							$rTokenData = array('stream_id' => $rStreamID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'channel_info' => array('stream_id' => $rChannelInfo['stream_id'], 'redirect_id' => ($rChannelInfo['redirect_id'] ?: null), 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'on_demand' => $rChannelInfo['on_demand'], 'llod' => $rChannelInfo['llod'], 'monitor_pid' => $rChannelInfo['monitor_pid'], 'proxy' => $rChannelInfo['direct_proxy']), 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_id' => $rUserInfo['pair_id'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'pid' => $rPID, 'prebuffer' => $rPrebuffer, 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'external_device' => $rExternalDevice, 'video_codec' => $rVideoCodec, 'uuid' => $rUUID);
						} else {
							$rTokenData = array('stream_id' => $rStreamID, 'hmac_hash' => CoreUtilities::$rRequest['hmac'], 'hmac_id' => $rIsHMAC, 'identifier' => $rIdentifier, 'extension' => $rExtension, 'channel_info' => array('stream_id' => $rChannelInfo['stream_id'], 'redirect_id' => ($rChannelInfo['redirect_id'] ?: null), 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'on_demand' => $rChannelInfo['on_demand'], 'llod' => $rChannelInfo['llod'], 'monitor_pid' => $rChannelInfo['monitor_pid'], 'proxy' => $rChannelInfo['direct_proxy']), 'user_info' => $rUserInfo, 'pid' => $rPID, 'prebuffer' => $rPrebuffer, 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'external_device' => $rExternalDevice, 'video_codec' => $rVideoCodec, 'uuid' => $rUUID);
						}

						$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

						if (CoreUtilities::$rSettings['allow_cdn_access']) {
							header('Location: ' . $rURL . '/auth/' . $rStreamID . '.ts?token=' . $rToken);

							exit();
						}

						header('Location: ' . $rURL . '/auth/' . $rToken);

						exit();
				}
			} else {
				CoreUtilities::showVideoServer('show_not_on_air_video', 'not_on_air_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);
			}

			break;

		case 'movie':
		case 'series':
			$rChannelInfo = CoreUtilities::redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'movie');

			if ($rChannelInfo) {
				if ((CoreUtilities::isProxied($rChannelInfo['redirect_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
					$rProxies = CoreUtilities::getProxies($rChannelInfo['redirect_id']);
					$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

					if (!$rProxyID) {
						generateError('NO_SERVERS_AVAILABLE');
					}

					$rChannelInfo['originator_id'] = $rChannelInfo['redirect_id'];
					$rChannelInfo['redirect_id'] = $rProxyID;
				}

				$rURL = CoreUtilities::getStreamingURL($rChannelInfo['redirect_id'], ($rChannelInfo['originator_id'] ?: null), $rForceHTTP);

				if ($rChannelInfo['direct_proxy']) {
					$rChannelInfo['bitrate'] = (json_decode($rChannelInfo['movie_properties'], true)['duration_secs'] ?: 0);
				}

				if (!$rIsHMAC) {
					$rTokenData = array('stream_id' => $rStreamID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'type' => $rType, 'pid' => $rPID, 'channel_info' => array('stream_id' => $rChannelInfo['stream_id'], 'bitrate' => $rChannelInfo['bitrate'], 'target_container' => $rChannelInfo['target_container'], 'redirect_id' => $rChannelInfo['redirect_id'], 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'proxy' => ($rChannelInfo['direct_proxy'] ? json_decode($rChannelInfo['stream_source'], true)[0] : null)), 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_id' => $rUserInfo['pair_id'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'is_mag' => $rIsMag, 'uuid' => $rUUID, 'http_range' => (isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null));
				} else {
					$rTokenData = array('stream_id' => $rStreamID, 'hmac_hash' => CoreUtilities::$rRequest['hmac'], 'hmac_id' => $rIsHMAC, 'identifier' => $rIdentifier, 'extension' => $rExtension, 'type' => $rType, 'pid' => $rPID, 'channel_info' => array('stream_id' => $rChannelInfo['stream_id'], 'bitrate' => $rChannelInfo['bitrate'], 'target_container' => $rChannelInfo['target_container'], 'redirect_id' => $rChannelInfo['redirect_id'], 'originator_id' => ($rChannelInfo['originator_id'] ?: null), 'pid' => $rChannelInfo['pid'], 'proxy_source' => ($rChannelInfo['direct_proxy'] ? json_decode($rChannelInfo['stream_source'], true)[0] : null)), 'user_info' => $rUserInfo, 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'is_mag' => $rIsMag, 'uuid' => $rUUID, 'http_range' => (isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null));
				}

				$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

				if (CoreUtilities::$rSettings['allow_cdn_access']) {
					header('Location: ' . $rURL . '/vauth/' . $rStreamID . '.' . $rExtension . '?token=' . $rToken);

					exit();
				}

				header('Location: ' . $rURL . '/vauth/' . $rToken);

				exit();
			}

			CoreUtilities::showVideoServer('show_not_on_air_video', 'not_on_air_video_path', 'ts', $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);

			break;

		case 'timeshift':
			$rOriginatorID = null;
			$rRedirectID = CoreUtilities::redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'archive');

			if (!$rRedirectID) {
				CoreUtilities::showVideoServer('show_not_on_air_video', 'not_on_air_video_path', $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo['con_isp_name'], SERVER_ID);

				break;
			}

			if ((CoreUtilities::isProxied($rChannelInfo['redirect_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
				$rProxies = CoreUtilities::getProxies($rChannelInfo['redirect_id']);
				$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

				if (!$rProxyID) {
					generateError('NO_SERVERS_AVAILABLE');
				}

				$rOriginatorID = $rChannelInfo['redirect_id'];
				$rRedirectID = $rProxyID;
			}

			$rURL = CoreUtilities::getStreamingURL($rRedirectID, ($rOriginatorID ?: null), $rForceHTTP);
			$rStartDate = CoreUtilities::$rRequest['start'];
			$rDuration = intval(CoreUtilities::$rRequest['duration']);

			switch ($rExtension) {
				case 'm3u8':
					if ((CoreUtilities::$rSettings['disable_hls'] && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['disable_hls_allow_restream']))) {
						generateError('HLS_DISABLED');
					}

					$rTokenData = array('stream' => $rStreamID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'pid' => $rPID, 'start' => $rStartDate, 'duration' => $rDuration, 'redirect_id' => $rRedirectID, 'originator_id' => $rOriginatorID, 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_line_info' => $rUserInfo['pair_line_info'], 'pair_id' => $rUserInfo['pair_id'], 'active_cons' => $rUserInfo['active_cons'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'uuid' => $rUUID, 'http_range' => (isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null));
					$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

					if (CoreUtilities::$rSettings['allow_cdn_access']) {
						header('Location: ' . $rURL . '/tsauth/' . $rStreamID . '_' . $rStartDate . '_' . $rDuration . '.m3u8?token=' . $rToken);

						exit();
					}

					header('Location: ' . $rURL . '/tsauth/' . $rToken);

					exit();

				default:
					if ((CoreUtilities::$rSettings['disable_ts'] && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['disable_ts_allow_restream']))) {
						generateError('TS_DISABLED');
					}

					$rActivityStart = time();
					$rTokenData = array('stream' => $rStreamID, 'username' => $rUserInfo['username'], 'password' => $rUserInfo['password'], 'extension' => $rExtension, 'pid' => $rPID, 'start' => $rStartDate, 'duration' => $rDuration, 'redirect_id' => $rRedirectID, 'originator_id' => $rOriginatorID, 'user_info' => array('id' => $rUserInfo['id'], 'max_connections' => $rUserInfo['max_connections'], 'pair_line_info' => $rUserInfo['pair_line_info'], 'pair_id' => $rUserInfo['pair_id'], 'active_cons' => $rUserInfo['active_cons'], 'con_isp_name' => $rUserInfo['con_isp_name'], 'is_restreamer' => $rUserInfo['is_restreamer']), 'country_code' => $rCountryCode, 'activity_start' => $rActivityStart, 'uuid' => $rUUID, 'http_range' => (isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null));
					$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

					if (CoreUtilities::$rSettings['allow_cdn_access']) {
						header('Location: ' . $rURL . '/tsauth/' . $rStreamID . '_' . $rStartDate . '_' . $rDuration . '.ts?token=' . $rToken);

						exit();
					}

					header('Location: ' . $rURL . '/tsauth/' . $rToken);

					exit();
			}
			// no break
		case 'thumb':
			$rStreamInfo = null;

			if (CoreUtilities::$rCached) {
				$rStreamInfo = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamID));
			} else {
				$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);

				if (0 < $db->num_rows()) {
					$rStreamInfo = array('info' => $db->get_row());
				}
			}

			if (!$rStreamInfo) {
				generateError('INVALID_STREAM_ID');
			}

			if ($rStreamInfo['info']['vframes_server_id'] == 0) {
				generateError('THUMBNAILS_NOT_ENABLED');
			}

			$rTokenData = array('stream' => $rStreamID, 'expires' => time() + 5);
			$rOriginatorID = null;

			if ((CoreUtilities::isProxied($rStreamInfo['info']['vframes_server_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
				$rProxies = CoreUtilities::getProxies($rStreamInfo['info']['vframes_server_id']);
				$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

				if (!$rProxyID) {
					generateError('THUMBNAILS_NOT_ENABLED');
				}

				$rOriginatorID = $rStreamInfo['info']['vframes_server_id'];
				$rStreamInfo['info']['vframes_server_id'] = $rProxyID;
			}

			$rURL = CoreUtilities::getStreamingURL($rStreamInfo['info']['vframes_server_id'], $rOriginatorID, $rForceHTTP);
			$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			header('Location: ' . $rURL . '/thauth/' . $rToken);

			exit();

		case 'subtitle':
			$rChannelInfo = CoreUtilities::redirectStream($rStreamID, 'srt', $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'movie');

			if ($rChannelInfo) {
				if ((CoreUtilities::isProxied($rChannelInfo['redirect_id']) && (!$rUserInfo['is_restreamer'] || !CoreUtilities::$rSettings['restreamer_bypass_proxy']))) {
					$rProxies = CoreUtilities::getProxies($rChannelInfo['redirect_id']);
					$rProxyID = CoreUtilities::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);

					if (!$rProxyID) {
						generateError('NO_SERVERS_AVAILABLE');
					}

					$rChannelInfo['originator_id'] = $rChannelInfo['redirect_id'];
					$rChannelInfo['redirect_id'] = $rProxyID;
				}

				$rURL = CoreUtilities::getStreamingURL($rChannelInfo['redirect_id'], ($rChannelInfo['originator_id'] ?: null), $rForceHTTP);
				$rTokenData = array('stream_id' => $rStreamID, 'sub_id' => (intval(CoreUtilities::$rRequest['sid']) ?: 0), 'webvtt' => (intval(CoreUtilities::$rRequest['webvtt']) ?: 0), 'expires' => time() + 5);
				$rToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				header('Location: ' . $rURL . '/subauth/' . $rToken);

				exit();
			}

			generateError('INVALID_STREAM_ID');

			break;
	}
} else {
	switch ($rType) {
		case 'timeshift':
		case 'live':
			$rExtension = 'ts';

			break;

		case 'series':
		case 'movie':
			$rExtension = 'mp4';

			break;
	}
}

function shutdown() {
	global $rDeny;
	global $db;

	if ($rDeny) {
		CoreUtilities::checkFlood();
	}

	if (is_object($db)) {
		$db->close_mysql();
	}
}
