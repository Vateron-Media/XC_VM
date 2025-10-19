<?php

if (!($_GET['addr'] == '127.0.0.1' && $_GET['call'] == 'publish')) {
	register_shutdown_function('shutdown');
	set_time_limit(0);
	require_once 'init.php';
	error_reporting(0);
	ini_set('display_errors', 0);
	$rAllowed = StreamingUtilities::getAllowedRTMP();
	$rDeny = true;

	if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
	} else {
		generate404();
	}

	$rIP = StreamingUtilities::$rRequest['addr'];
	$rStreamID = intval(StreamingUtilities::$rRequest['name']);
	$rRestreamDetect = false;

	foreach (getallheaders() as $rKey => $rValue) {
		if (strtoupper($rKey) != 'X-XC_VM-DETECT') {
		} else {
			$rRestreamDetect = true;
		}
	}

	if (StreamingUtilities::$rRequest['call'] != 'publish') {
		if (StreamingUtilities::$rRequest['call'] != 'play_done') {
			if (!(StreamingUtilities::$rRequest['password'] == StreamingUtilities::$rSettings['live_streaming_pass'] || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['pull'] && ($rAllowed[$rIP]['password'] == StreamingUtilities::$rRequest['password'] || !$rAllowed[$rIP]['password']))) {
				if (isset(StreamingUtilities::$rRequest['tcurl']) && isset(StreamingUtilities::$rRequest['app'])) {
					if (isset(StreamingUtilities::$rRequest['token'])) {
						if (!ctype_xdigit(StreamingUtilities::$rRequest['token'])) {
							$rTokenData = explode('/', StreamingUtilities::decryptData(StreamingUtilities::$rRequest['token'], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));
							list($rUsername, $rPassword) = $rTokenData;
							$rUserInfo = StreamingUtilities::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
						} else {
							$rAccessToken = StreamingUtilities::$rRequest['token'];
							$rUserInfo = StreamingUtilities::getUserInfo(null, $rAccessToken, null, true, false, $rIP);
						}
					} else {
						$rUsername = StreamingUtilities::$rRequest['username'];
						$rPassword = StreamingUtilities::$rRequest['password'];
						$rUserInfo = StreamingUtilities::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
					}

					$rExtension = 'rtmp';
					$rExternalDevice = '';

					if ($rUserInfo) {
						$rDeny = false;

						if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {
							if ($rUserInfo['admin_enabled'] != 0) {
								if ($rUserInfo['enabled'] != 0) {
									if (empty($rUserInfo['allowed_ips']) || in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
										$rCountryCode = StreamingUtilities::getIPInfo($rIP)['country']['iso_code'];

										if (empty($rCountryCode)) {
										} else {
											$rForceCountry = !empty($rUserInfo['forced_country']);

											if (!($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country'])) {
												if ($rForceCountry || in_array('ALL', StreamingUtilities::$rSettings['allow_countries']) || in_array($rCountryCode, StreamingUtilities::$rSettings['allow_countries'])) {
												} else {
													StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
													http_response_code(404);

													exit();
												}
											} else {
												StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'COUNTRY_DISALLOW', $rIP);
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
																if (!($rChannelInfo = StreamingUtilities::redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserInfo['con_isp_name'], 'live'))) {
																} else {
																	if (!$rChannelInfo['redirect_id'] || $rChannelInfo['redirect_id'] == SERVER_ID) {
																		if (StreamingUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
																		} else {
																			if ($rChannelInfo['on_demand'] == 1) {
																				if (StreamingUtilities::isMonitorRunning($rChannelInfo['monitor_pid'], $rStreamID)) {
																				} else {
																					StreamingUtilities::startMonitor($rStreamID);
																					sleep(5);
																				}
																			} else {
																				http_response_code(404);

																				exit();
																			}
																		}

																		if (StreamingUtilities::$rSettings['redis_handler']) {
																			StreamingUtilities::connectRedis();
																			$rConnectionData = array('user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => SERVER_ID, 'proxy_id' => 0, 'user_agent' => '', 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => StreamingUtilities::$rRequest['clientid'], 'date_start' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']), 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => $rExternalDevice, 'hls_end' => 0, 'hls_last_read' => time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']), 'on_demand' => $rChannelInfo['on_demand'], 'identity' => $rUserInfo['id'], 'uuid' => md5(StreamingUtilities::$rRequest['clientid']));
																			$rResult = StreamingUtilities::createConnection($rConnectionData);
																		} else {
																			$rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rUserInfo['id'], $rStreamID, SERVER_ID, 0, '', $rIP, $rExtension, StreamingUtilities::$rRequest['clientid'], md5(StreamingUtilities::$rRequest['clientid']), time(), $rCountryCode, $rUserInfo['con_isp_name'], $rExternalDevice);
																		}

																		if ($rResult) {
																			StreamingUtilities::validateConnections($rUserInfo, false, '', $rIP, null);
																			http_response_code(200);

																			exit();
																		}

																		StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP);
																		http_response_code(404);

																		exit();
																	}

																	http_response_code(404);

																	exit();
																}
															} else {
																if (!StreamingUtilities::$rSettings['detect_restream_block_user']) {
																} else {
																	$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserInfo['id']);
																}

																StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'RESTREAM_DETECT', $rIP);
																http_response_code(404);

																exit();
															}
														} else {
															StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'BLOCKED_ASN', $rIP, json_encode(array('user_agent' => '', 'isp' => $rUserInfo['con_isp_name'], 'asn' => $rUserInfo['isp_asn'])), true);
															http_response_code(404);

															exit();
														}
													} else {
														StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'ISP_LOCK_FAILED', $rIP, json_encode(array('old' => $rUserInfo['isp_desc'], 'new' => $rUserInfo['con_isp_name'])));
														http_response_code(404);

														exit();
													}
												} else {
													StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'NOT_IN_BOUQUET', $rIP);
													http_response_code(404);

													exit();
												}
											} else {
												StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISALLOW_EXT', $rIP);
												http_response_code(404);

												exit();
											}
										} else {
											StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_ALREADY_CONNECTED', $rIP);
											http_response_code(404);

											exit();
										}
									} else {
										StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'IP_BAN', $rIP);
										http_response_code(404);

										exit();
									}
								} else {
									StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_DISABLED', $rIP);
									http_response_code(404);

									exit();
								}
							} else {
								StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_BAN', $rIP);
								http_response_code(404);

								exit();
							}
						} else {
							StreamingUtilities::clientLog($rStreamID, $rUserInfo['id'], 'USER_EXPIRED', $rIP);
							http_response_code(404);

							exit();
						}
					} else {
						if (!isset($rUsername)) {
						} else {
							StreamingUtilities::checkBruteforce($rIP, null, $rUsername);
						}

						StreamingUtilities::clientLog($rStreamID, 0, 'AUTH_FAILED', $rIP);
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
				if (StreamingUtilities::isStreamRunning($rChannelInfo['pid'], $rStreamID)) {
				} else {
					if ($rChannelInfo['on_demand'] == 1) {
						if (StreamingUtilities::isMonitorRunning($rChannelInfo['monitor_pid'], $rStreamID)) {
						} else {
							StreamingUtilities::startMonitor($rStreamID);
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

		if (StreamingUtilities::$rSettings['redis_handler']) {
			StreamingUtilities::closeConnection(md5(StreamingUtilities::$rRequest['clientid']));
		} else {
			StreamingUtilities::closeRTMP(StreamingUtilities::$rRequest['clientid']);
		}

		http_response_code(200);

		exit();
	}

	if (StreamingUtilities::$rRequest['password'] == StreamingUtilities::$rSettings['live_streaming_pass'] || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['push'] && ($rAllowed[$rIP]['password'] == StreamingUtilities::$rRequest['password'] || !$rAllowed[$rIP]['password'])) {
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
		StreamingUtilities::checkFlood($rIP);
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
