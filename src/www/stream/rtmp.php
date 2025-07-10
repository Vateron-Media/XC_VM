<?php

if (!($_GET['addr'] == '127.0.0.1' && $_GET['call'] == 'publish')) {
	register_shutdown_function('shutdown');
	set_time_limit(0);
	require_once 'init.php';
	error_reporting(0);
	ini_set('display_errors', 0);
	$rAllowed = CoreUtilities::getAllowedRTMP();
	$rDeny = true;

	if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
	} else {
		generate404();
	}

	$rIP = CoreUtilities::$rRequest['addr'];
	$rStreamID = intval(CoreUtilities::$rRequest['name']);
	$rRestreamDetect = false;

	foreach (getallheaders() as $rKey => $rValue) {
		if (strtoupper($rKey) != 'X-XC_VM-DETECT') {
		} else {
			$rRestreamDetect = true;
		}
	}

	if (CoreUtilities::$rRequest['call'] != 'publish') {
		if (CoreUtilities::$rRequest['call'] != 'play_done') {
			if (!(CoreUtilities::$rRequest['password'] == CoreUtilities::$rSettings['live_streaming_pass'] || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['pull'] && ($rAllowed[$rIP]['password'] == CoreUtilities::$rRequest['password'] || !$rAllowed[$rIP]['password']))) {
				if (isset(CoreUtilities::$rRequest['tcurl']) && isset(CoreUtilities::$rRequest['app'])) {
					if (isset(CoreUtilities::$rRequest['token'])) {
						if (!ctype_xdigit(CoreUtilities::$rRequest['token'])) {
							$rTokenData = explode('/', CoreUtilities::decryptData(CoreUtilities::$rRequest['token'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));
							list($rUsername, $rPassword) = $rTokenData;
							$rUserInfo = CoreUtilities::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
						} else {
							$rAccessToken = CoreUtilities::$rRequest['token'];
							$rUserInfo = CoreUtilities::getUserInfo(null, $rAccessToken, null, true, false, $rIP);
						}
					} else {
						$rUsername = CoreUtilities::$rRequest['username'];
						$rPassword = CoreUtilities::$rRequest['password'];
						$rUserInfo = CoreUtilities::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
					}

					$rExtension = 'rtmp';
					$rExternalDevice = '';

					if ($rUserInfo) {
						$rDeny = false;

						if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {
							if ($rUserInfo['admin_enabled'] != 0) {
								if ($rUserInfo['enabled'] != 0) {
									if (empty($rUserInfo['allowed_ips']) || in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
										$rCountryCode = CoreUtilities::getIPInfo($rIP)['country']['iso_code'];

										if (empty($rCountryCode)) {
										} else {
											$rForceCountry = !empty($rUserInfo['forced_country']);

											if (!($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country'])) {
												if ($rForceCountry || in_array('ALL', CoreUtilities::$rSettings['allow_countries']) || in_array($rCountryCode, CoreUtilities::$rSettings['allow_countries'])) {
												} else {
													CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
													http_response_code(404);

													exit();
												}
											} else {
												CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
												http_response_code(404);

												exit();
											}
										}

										if (!isset($rUserInfo['ip_limit_reached'])) {
											if (in_array($rExtension, $rUserInfo['output_formats'])) {
												if (in_array($rStreamID, $rUserInfo['channel_ids'])) {
													if ($rUserInfo['isp_violate'] != 1) {
														if ($rUserInfo['isp_is_server'] != 1 || $rUserInfo['is_restreamer']) {
															if (!$rRestreamDetect || $rUserInfo['is_restreamer']) {
																if (!($rChannelInfo = CoreUtilities::redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'live'))) {
																} else {
																	if (!$rChannelInfo['redirect_id'] || $rChannelInfo['redirect_id'] == SERVER_ID) {
																		if (CoreUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
																		} else {
																			if ($rChannelInfo['on_demand'] == 1) {
																				if (CoreUtilities::isMonitorRunning($rChannelInfo['monitor_pid'], $rStreamID)) {
																				} else {
																					CoreUtilities::startMonitor($rStreamID);
																					sleep(5);
																				}
																			} else {
																				http_response_code(404);

																				exit();
																			}
																		}

																		if (CoreUtilities::$rSettings['redis_handler']) {
																			CoreUtilities::connectRedis();
																			$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => SERVER_ID, 'proxy_id' => 0, 'user_agent' => '', 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => CoreUtilities::$rRequest['clientid'], 'date_start' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']), 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => $rExternalDevice, 'hls_end' => 0, 'hls_last_read' => time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => $rChannelInfo['on_demand'], 'identity' => $rUserInfo['id'], 'uuid' => md5(CoreUtilities::$rRequest['clientid']));
																			$rResult = CoreUtilities::createConnection($rConnectionData);
																		} else {
																			$rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rUserInfo['id'], $rStreamID, SERVER_ID, 0, '', $rIP, $rExtension, CoreUtilities::$rRequest['clientid'], md5(CoreUtilities::$rRequest['clientid']), time(), $rCountryCode, $rUserInfo['con_isp_name'], $rExternalDevice);
																		}

																		if ($rResult) {
																			CoreUtilities::validateConnections($rUserInfo, false, '', $rIP, null);
																			http_response_code(200);

																			exit();
																		}

																		CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
																		http_response_code(404);

																		exit();
																	}

																	http_response_code(404);

																	exit();
																}
															} else {
																if (!CoreUtilities::$rSettings['detect_restream_block_user']) {
																} else {
																	$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserInfo['id']);
																}

																CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'RESTREAM_DETECT', $rIP);
																http_response_code(404);

																exit();
															}
														} else {
															CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'BLOCKED_ASN', $rIP, json_encode(array('user_agent' => '', 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
															http_response_code(404);

															exit();
														}
													} else {
														CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'ISP_LOCK_FAILED', $rIP, json_encode(array('old' => $rUserInfo['isp_desc'], 'new' => $rUserInfo['con_isp_name'])));
														http_response_code(404);

														exit();
													}
												} else {
													CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'NOT_IN_BOUQUET', $rIP);
													http_response_code(404);

													exit();
												}
											} else {
												CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISALLOW_EXT', $rIP);
												http_response_code(404);

												exit();
											}
										} else {
											CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_ALREADY_CONNECTED', $rIP);
											http_response_code(404);

											exit();
										}
									} else {
										CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'IP_BAN', $rIP);
										http_response_code(404);

										exit();
									}
								} else {
									CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISABLED', $rIP);
									http_response_code(404);

									exit();
								}
							} else {
								CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_BAN', $rIP);
								http_response_code(404);

								exit();
							}
						} else {
							CoreUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_EXPIRED', $rIP);
							http_response_code(404);

							exit();
						}
					} else {
						if (!isset($rUsername)) {
						} else {
							CoreUtilities::checkBruteforce($rIP, null, $rUsername);
						}

						CoreUtilities::clientLog($rStreamID, 0, 'AUTH_FAILED', $rIP);
					}

					http_response_code(404);

					exit();
				}

				http_response_code(404);

				exit();
			}

			$rDeny = false;
			$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.`id` = ?', SERVER_ID, $rStreamID);
			$rChannelInfo = $db->get_row();

			if ($rChannelInfo) {
				if (CoreUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
				} else {
					if ($rChannelInfo['on_demand'] == 1) {
						if (CoreUtilities::isMonitorRunning($rChannelInfo['monitor_pid'], $rStreamID)) {
						} else {
							CoreUtilities::startMonitor($rStreamID);
							sleep(5);
						}
					} else {
						http_response_code(404);

						exit();
					}
				}

				http_response_code(200);

				exit();
			}

			http_response_code(200);

			exit();
		}

		$rDeny = false;

		if (CoreUtilities::$rSettings['redis_handler']) {
			CoreUtilities::closeConnection(md5(CoreUtilities::$rRequest['clientid']));
		} else {
			CoreUtilities::closeRTMP(CoreUtilities::$rRequest['clientid']);
		}

		http_response_code(200);

		exit();
	}

	if (CoreUtilities::$rRequest['password'] == CoreUtilities::$rSettings['live_streaming_pass'] || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['push'] && ($rAllowed[$rIP]['password'] == CoreUtilities::$rRequest['password'] || !$rAllowed[$rIP]['password'])) {
		$rDeny = false;
		http_response_code(200);

		exit();
	}

	http_response_code(404);

	exit();
} else {
	http_response_code(200);

	exit();
}

function shutdown() {
	global $rDeny;
	global $rIP;

	if (!$rDeny) {
	} else {
		CoreUtilities::checkFlood($rIP);
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
