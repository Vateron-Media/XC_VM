<?php
class CoreUtilities {
	public static $db = null;
	public static $redis = null;
	public static $rRequest = array();
	public static $rConfig = array();
	public static $rSettings = array();
	public static $rBouquets = array();
	public static $rServers = array();
	public static $rSegmentSettings = array();
	public static $rBlockedUA = array();
	public static $rBlockedISP = array();
	public static $rBlockedIPs = array();
	public static $rBlockedServers = array();
	public static $rAllowedIPs = array();
	public static $rProxies = array();
	public static $rAllowedDomains = array();
	public static $rCategories = array();
	public static $rFFMPEG_CPU = null;
	public static $rFFMPEG_GPU = null;
	public static $rFFPROBE = null;
	public static $rCached = null;

	public static function init($rUseCache = false) {
		LegacyInitializer::initCore($rUseCache);
	}

	public static function getDiffTimezone($rTimezone) {
		$rServerTZ = new DateTime('UTC', new DateTimeZone(date_default_timezone_get()));
		$rUserTZ = new DateTime('UTC', new DateTimeZone($rTimezone));
		return $rUserTZ->getTimestamp() - $rServerTZ->getTimestamp();
	}

	public static function getAllowedDomains($rForce = false) {
		if (!$rForce) {
			$rCache = self::getCache('allowed_domains', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}
		$rDomains = array('127.0.0.1', 'localhost');
		self::$db->query('SELECT `server_ip`, `private_ip`, `domain_name` FROM `servers` WHERE `enabled` = 1;');
		foreach (self::$db->get_rows() as $rRow) {
			foreach (explode(',', $rRow['domain_name']) as $rDomain) {
				$rDomains[] = $rDomain;
			}
			if (!empty($rRow['server_ip'])) {
				$rDomains[] = $rRow['server_ip'];
			}
			if (!empty($rRow['private_ip'])) {
				$rDomains[] = $rRow['private_ip'];
			}
		}
		self::$db->query('SELECT `reseller_dns` FROM `users` WHERE `status` = 1;');
		foreach (self::$db->get_rows() as $rRow) {
			if (!empty($rRow['reseller_dns'])) {
				$rDomains[] = $rRow['reseller_dns'];
			}
		}
		$rDomains = array_filter(array_unique($rDomains));
		self::setCache('allowed_domains', $rDomains);
		return $rDomains;
	}

	public static function getProxyIPs($rForce = false) {
		return BlocklistRepository::getProxyIPs(self::$rServers, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function isProxy($rIP) {
		if (isset(self::$rProxies[$rIP])) {
			return self::$rProxies[$rIP];
		}
	}

	public static function getBlockedUA($rForce = false) {
		return BlocklistRepository::getBlockedUA(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getBlockedIPs($rForce = false) {
		return BlocklistRepository::getBlockedIPs(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getBlockedISP($rForce = false) {
		return BlocklistRepository::getBlockedISP(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getBlockedServers($rForce = false) {
		return BlocklistRepository::getBlockedServers(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getBouquets($rForce = false) {
		return BouquetRepository::getAll(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getSettings($rForce = false) {
		return SettingsRepository::getAll(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function setCache($rCache, $rData) {
		file_put_contents(CACHE_TMP_PATH . $rCache, igbinary_serialize($rData), LOCK_EX);
	}

	public static function getCache($rCache, $rSeconds = null) {
		if (file_exists(CACHE_TMP_PATH . $rCache)) {
			if (!$rSeconds || time() - filemtime(CACHE_TMP_PATH . $rCache) < $rSeconds) {
				return igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . $rCache));
			}
		}
		return false;
	}

	public static function getServers($rForce = false) {
		return ServerRepository::getAll(self::$db, self::$rSettings, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rForce);
	}

	public static function getMultiCURL($rURLs, $callback = null, $rTimeout = 5) {
		return CurlClient::getMultiCURL(self::$rServers, $rURLs, $callback, $rTimeout);
	}

	public static function cleanGlobals(&$rData, $rIteration = 0) {
		if ($rIteration >= 10) {
			return;
		}
		foreach ($rData as $rKey => $rValue) {
			if (is_array($rValue)) {
				self::cleanGlobals($rData[$rKey], $rIteration + 1);
			} else {
				$rValue = str_replace(chr(0), '', $rValue);
				$rValue = str_replace('../', '&#46;&#46;/', $rValue);
				$rValue = str_replace('&#8238;', '', $rValue);
				$rData[$rKey] = $rValue;
			}
		}
	}

	public static function parseIncomingRecursively(&$rData, $rInput = array(), $rIteration = 0) {
		if ($rIteration >= 20 || !is_array($rData)) {
			return $rInput;
		}
		foreach ($rData as $rKey => $rValue) {
			if (is_array($rValue)) {
				$rInput[$rKey] = self::parseIncomingRecursively($rData[$rKey], array(), $rIteration + 1);
			} else {
				$rInput[self::parseCleanKey($rKey)] = self::parseCleanValue($rValue);
			}
		}
		return $rInput;
	}

	public static function parseCleanKey($rKey) {
		if ($rKey === '') {
			return '';
		}
		$rKey = htmlspecialchars(urldecode($rKey));
		$rKey = str_replace('..', '', $rKey);
		$rKey = preg_replace('/\_\_(.+?)\_\_/', '', $rKey);
		return preg_replace('/^([\w\.\-\_]+)$/', '$1', $rKey);
	}

	public static function parseCleanValue($rValue) {
		if ($rValue == '') {
			return '';
		}
		$rValue = str_replace('&#032;', ' ', stripslashes($rValue));
		$rValue = str_replace(array("\r\n", "\n\r", "\r"), "\n", $rValue);
		$rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
		$rValue = str_replace('-->', '--&#62;', $rValue);
		$rValue = str_ireplace('<script', '&#60;script', $rValue);
		$rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
		$rValue = preg_replace('/&#(\d+?)([^\d;])/i', '&#\\1;\\2', $rValue);
		return trim($rValue);
	}

	public static function generateString($rLength = 10) {
		$rCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789qwertyuiopasdfghjklzxcvbnm';
		$rString = '';
		$rMax = strlen($rCharacters) - 1;
		for ($index = 0; $index < $rLength; $index++) {
			$rString .= $rCharacters[rand(0, $rMax)];
		}
		return $rString;
	}

	public static function mergeRecursive($rArray) {
		if (!is_array($rArray)) {
			return $rArray;
		}
		$rArrayValues = array();
		foreach ($rArray as $rValue) {
			if (is_scalar($rValue) || is_resource($rValue)) {
				$rArrayValues[] = $rValue;
			} else if (is_array($rValue)) {
				$rArrayValues = array_merge($rArrayValues, self::mergeRecursive($rValue));
			}
		}
		return $rArrayValues;
	}

	public static function searchEPG($rArray, $rKey, $rValue) {
		return EpgRepository::search($rArray, $rKey, $rValue);
	}

	public static function searchRecursive($rArray, $rKey, $rValue, &$rResults) {
		foreach (EpgRepository::search($rArray, $rKey, $rValue) as $rMatch) {
			$rResults[] = $rMatch;
		}
	}

	public static function checkCron($rFilename, $rTime = 1800) {
		if (file_exists($rFilename)) {
			$rPID = trim(file_get_contents($rFilename));
			if (file_exists('/proc/' . $rPID) && time() - filemtime($rFilename) < $rTime) {
				exit('Running...');
			}
			if (time() - filemtime($rFilename) >= $rTime && is_numeric($rPID) && 0 < $rPID) {
				posix_kill($rPID, 9);
			}
		}
		file_put_contents($rFilename, getmypid());
	}

	public static function checkFlood($rIP = null) {
		return BruteforceGuard::checkFlood($rIP);
	}

	public static function checkBruteforce($rIP = null, $rMAC = null, $rUsername = null) {
		return BruteforceGuard::checkBruteforce($rIP, $rMAC, $rUsername);
	}

	public static function truncateAttempts($rAttempts, $rFrequency, $rList = false) {
		return BruteforceGuard::truncateAttempts($rAttempts, $rFrequency, $rList);
	}

	public static function getCategories($rType = null, $rForce = false) {
		return CategoryRepository::getFromDatabase(self::$db, array('CoreUtilities', 'getCache'), array('CoreUtilities', 'setCache'), $rType, $rForce);
	}

	public static function generateUniqueCode() {
		return substr(md5(self::$rSettings['live_streaming_pass']), 0, 15);
	}

	public static function unserialize_php($rSessionData) {
		$rReturn = array();
		$rOffset = 0;
		while ($rOffset < strlen($rSessionData)) {
			if (!strstr(substr($rSessionData, $rOffset), '|')) {
				return array();
			}
			$rPos = strpos($rSessionData, '|', $rOffset);
			$rVarName = substr($rSessionData, $rOffset, $rPos - $rOffset);
			$rOffset += ($rPos - $rOffset) + 1;
			$rData = igbinary_unserialize(substr($rSessionData, $rOffset));
			$rReturn[$rVarName] = $rData;
			$rOffset += strlen(igbinary_serialize($rData));
		}
		return $rReturn;
	}

	public static function generatePlaylist($rUserInfo, $rDeviceKey, $rOutputKey = 'ts', $rTypeKey = null, $rNoCache = false, $rProxy = false) {
		return PlaylistGenerator::generate(self::$db, self::$rSettings, self::$rServers, self::$rCategories, self::$rCached, $rUserInfo, $rDeviceKey, $rOutputKey, $rTypeKey, $rNoCache, $rProxy);
	}

	public static function generateCron() {
		return CronGenerator::generate(self::$db);
	}

	/*
													$rEncData .= $rChannelInfo['id'] . '/' . $rChannelInfo['target_container'];
												} else {
													if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
														$rEncData .= $rChannelInfo['id'];
													} else {
														$rEncData .= $rChannelInfo['id'] . '/' . $rOutputExt;
													}
												}
												$rToken = CoreUtilities::encryptData($rEncData, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
												$rURL = $rDomainName . 'play/' . $rToken;
												if ($rChannelInfo['live'] != 0) {
												} else {
													$rURL .= '#.' . $rChannelInfo['target_container'];
												}
											} else {
												$rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
												if ($rChannelInfo['live'] == 0) {
													$rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
												} else {
													if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
														$rURL .= $rChannelInfo['id'];
													} else {
														$rURL .= $rChannelInfo['id'] . '.' . $rOutputExt;
													}
												}
											}
										}
										if ($rChannelInfo['live'] == 0) {
											if (empty($rProperties['movie_image'])) {
											} else {
												$rIcon = $rProperties['movie_image'];
											}
										} else {
											$rIcon = $rChannelInfo['stream_icon'];
										}
										$rChannel = array();
										$rChannel['name'] = $rChannelInfo['stream_display_name'];
										$rChannel['icon'] = self::validateImage($rIcon);
										$rChannel['stream_url'] = $rURL;
										$rChannel['stream_type'] = 0;
										$rOutput['iptvstreams_list']['group']['channel'][] = $rChannel;
									}
								}
								unset($rRows);
							}
							$rData = json_encode((object) $rOutput);
						} else {
							if (!empty($rDeviceInfo['device_header'])) {
								$epgUrl = $rDomainName . 'epg/' . $rUserInfo['username'] . '/' . $rUserInfo['password'];
								$isM3UFormat = (strpos($rDeviceInfo['device_header'], '#EXTM3U') !== false);

								// If M3U format and no existing x-tvg-url, add it
								if ($isM3UFormat && strpos($rDeviceInfo['device_header'], 'x-tvg-url') === false) {
									$rDeviceInfo['device_header'] = str_replace('#EXTM3U', '#EXTM3U x-tvg-url="' . $epgUrl . '"', $rDeviceInfo['device_header']);
								}

								$rAppend = ($isM3UFormat ? "\n" . '#EXT-X-SESSION-DATA:DATA-ID="com.xc_vm.' . str_replace('.', '_', XC_VM_VERSION) . '"' : '');
								$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array('{BOUQUET_NAME}', '{USERNAME}', '{PASSWORD}', '{SERVER_URL}', '{OUTPUT_KEY}'), array(self::$rSettings['server_name'], $rUserInfo['username'], $rUserInfo['password'], $rDomainName, $rOutputKey), $rDeviceInfo['device_header'] . $rAppend)) . "\n";
								if ($rOutputFile) {
									fwrite($rOutputFile, $rData);
								}
								echo $rData;
								unset($rData);
							}
							if (!empty($rDeviceInfo['device_conf'])) {
								if (preg_match('/\\{URL\\#(.*?)\\}/', $rDeviceInfo['device_conf'], $rMatches)) {
									$rCharts = str_split($rMatches[1]);
									$rPattern = $rMatches[0];
								} else {
									$rCharts = array();
									$rPattern = '{URL}';
								}
								foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
									if (self::$rSettings['playlist_from_mysql'] || !self::$rCached) {
										$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
										self::$db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live,t1.tv_archive_duration,t1.tv_archive_server_id FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
										$rRows = self::$db->get_rows();
									} else {
										$rRows = array();
										foreach ($rBlockIDs as $rID) {
											$rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
										}
									}
									foreach ($rRows as $rChannel) {
										if (!$rTypeKey || in_array($rChannel['type_output'], $rTypeKey)) {
											if (!$rChannel['target_container']) {
												$rChannel['target_container'] = 'mp4';
											}

											$rConfig = $rDeviceInfo['device_conf'];
											if ($rDeviceInfo['device_key'] == 'm3u_plus') {
												if (!$rChannel['live']) {
													$rConfig = str_replace('tvg-id="{CHANNEL_ID}" ', '', $rConfig);
												}
												if (!$rEncryptPlaylist) {
													$rConfig = str_replace('xc_vm-id="{XC_VM_ID}" ', '', $rConfig);
												}
												if (0 < $rChannel['tv_archive_server_id'] && 0 < $rChannel['tv_archive_duration']) {
													$rConfig = str_replace('#EXTINF:-1 ', '#EXTINF:-1 timeshift="' . intval($rChannel['tv_archive_duration']) . '" ', $rConfig);
												}
											}
											$rProperties = (!is_array($rChannel['movie_properties']) ? json_decode($rChannel['movie_properties'], true) : $rChannel['movie_properties']);
											if ($rChannel['type_key'] == 'series') {
												$rSeriesID = $rSeriesAllocation[$rChannel['id']];
												$rChannel['live'] = 0;
												$rChannel['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][1]);
												$rChannel['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
												$rChannel['type_output'] = 'series';
												$rChannel['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
											} else {
												$rChannel['stream_display_name'] = self::formatTitle($rChannel['stream_display_name'], $rChannel['year']);
											}

											if ($rChannel['live'] == 0) {
												if (strlen($rUserInfo['access_token']) == 32) {
													$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
												} else {
													if ($rEncryptPlaylist) {
														$rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '/' . $rChannel['target_container'];
														$rToken = CoreUtilities::encryptData($rEncData, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
														$rURL = $rDomainName . 'play/' . $rToken . '#.' . $rChannel['target_container'];
													} else {
														$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
													}
												}
												if (!empty($rProperties['movie_image'])) {
													$rIcon = $rProperties['movie_image'];
												}
											} else {
												if ($rOutputKey != 'rtmp' || !array_key_exists($rChannel['id'], $rRTMPRows)) {
													if (strlen($rUserInfo['access_token']) == 32) {
														if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
															$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'];
														} else {
															$rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
														}
													} else {
														if ($rEncryptPlaylist) {
															$rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
															$rToken = CoreUtilities::encryptData($rEncData, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
															if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
																$rURL = $rDomainName . 'play/' . $rToken;
															} else {
																$rURL = $rDomainName . 'play/' . $rToken . '/' . $rOutputExt;
															}
														} else {
															if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
																$rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
															} else {
																$rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
															}
														}
													}
												} else {
													$rAvailableServers = array_values(array_keys($rRTMPRows[$rChannel['id']]));
													if (in_array($rUserInfo['force_server_id'], $rAvailableServers)) {
														$rServerID = $rUserInfo['force_server_id'];
													} else {
														if (self::$rSettings['rtmp_random'] == 1) {
															$rServerID = $rAvailableServers[array_rand($rAvailableServers, 1)];
														} else {
															$rServerID = $rAvailableServers[0];
														}
													}
													if (strlen($rUserInfo['access_token']) == 32) {
														$rURL = self::$rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rUserInfo['access_token'];
													} else {
														if ($rEncryptPlaylist) {
															$rEncData = $rUserInfo['username'] . '/' . $rUserInfo['password'];
															$rToken = CoreUtilities::encryptData($rEncData, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
															$rURL = self::$rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rToken;
														} else {
															$rURL = self::$rServers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?username=' . $rUserInfo['username'] . '&password=' . $rUserInfo['password'];
														}
													}
												}
												$rIcon = $rChannel['stream_icon'];
											}
											$rESRID = ($rChannel['live'] == 1 ? 1 : 4097);
											$rSID = (!empty($rChannel['custom_sid']) ? $rChannel['custom_sid'] : ':0:1:0:0:0:0:0:0:0:');
											$rCategoryIDs = json_decode($rChannel['category_id'], true);

											// If there are no categories, set the category to 0
											if (empty($rCategoryIDs)) {
												$rCategoryIDs = [0];
											}

											foreach ($rCategoryIDs as $rCategoryID) {
												if (isset(self::$rCategories[$rCategoryID])) {
													$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CATEGORY}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], self::$rCategories[$rCategoryID]['category_name'], self::validateImage($rIcon)), $rConfig)) . "\r\n";
												} else {
													$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], $rIcon), $rConfig)) . "\r\n";
													$rData = str_replace(' group-title="{CATEGORY}"', "", $rData);
												}
												if ($rOutputFile) {
													fwrite($rOutputFile, $rData);
												}
												echo $rData;
												unset($rData);

												// Break the loop if the playlist does not support categories
												if (stripos($rDeviceInfo['device_conf'], '{CATEGORY}') === false) {
													break;
												}
											}
										}
									}
									unset($rRows);
								}
								$rData = trim(str_replace(array('&lt;', '&gt;'), array('<', '>'), $rDeviceInfo['device_footer']));
								if ($rOutputFile) {
									fwrite($rOutputFile, $rData);
								}
								echo $rData;
								unset($rData);
							}
						}
						if ($rOutputFile) {
							fclose($rOutputFile);
							rename(PLAYLIST_PATH . md5($rCacheName) . '.write', PLAYLIST_PATH . md5($rCacheName));
						}
						exit();
					} else {
						header('Content-Description: File Transfer');
						header('Content-Type: audio/mpegurl');
						header('Expires: 0');
						header('Cache-Control: must-revalidate');
						header('Pragma: public');
						header('Content-Disposition: attachment; filename="' . $rFilename . '"');
						header('Content-Length: ' . filesize(PLAYLIST_PATH . md5($rCacheName)));
						readfile(PLAYLIST_PATH . md5($rCacheName));
						exit();
					}
				} else {
					exit();
				}
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	public static function generateCron() {
		if (!file_exists(TMP_PATH . 'crontab')) {
			$rJobs = array();
			self::$db->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
			foreach (self::$db->get_rows() as $rRow) {
				$rFullPath = CRON_PATH . $rRow['filename'];
				if (pathinfo($rFullPath, PATHINFO_EXTENSION) == 'php' && file_exists($rFullPath)) {
					$rJobs[] = $rRow['time'] . ' ' . PHP_BIN . ' ' . $rFullPath . ' # XC_VM';
				}
			}
			shell_exec('crontab -r');
			$rTempName = tempnam('/tmp', 'crontab');
			$rHandle = fopen($rTempName, 'w');
			fwrite($rHandle, implode("\n", $rJobs) . "\n");
			fclose($rHandle);
			shell_exec('crontab -u xc_vm ' . $rTempName);
			@unlink($rTempName);
			file_put_contents(TMP_PATH . 'crontab', 1);
			return true;
		} else {
			return false;
		}
	}
	*/
	public static function secondsToTime($rInputSeconds, $rInclSecs = true) {
		$rSecondsInAMinute = 60;
		$rSecondsInAnHour = 60 * $rSecondsInAMinute;
		$rSecondsInADay = 24 * $rSecondsInAnHour;
		$rDays = (int) floor($rInputSeconds / (($rSecondsInADay ?: 1)));
		$rHourSeconds = $rInputSeconds % $rSecondsInADay;
		$rHours = (int) floor($rHourSeconds / (($rSecondsInAnHour ?: 1)));
		$rMinuteSeconds = $rHourSeconds % $rSecondsInAnHour;
		$rMinutes = (int) floor($rMinuteSeconds / (($rSecondsInAMinute ?: 1)));
		$rRemaining = $rMinuteSeconds % $rSecondsInAMinute;
		$rSeconds = (int) ceil($rRemaining);
		$rOutput = '';
		if ($rDays == 0) {
		} else {
			$rOutput .= $rDays . 'd ';
		}
		if ($rHours == 0) {
		} else {
			$rOutput .= $rHours . 'h ';
		}
		if ($rMinutes == 0) {
		} else {
			$rOutput .= $rMinutes . 'm ';
		}
		if (!$rInclSecs) {
		} else {
			$rOutput .= $rSeconds . 's';
		}
		return $rOutput;
	}
	public static function isPIDsRunning($rServerIDS, $rPIDs, $rEXE) {
		if (is_array($rServerIDS)) {
		} else {
			$rServerIDS = array(intval($rServerIDS));
		}
		$rPIDs = array_map('intval', $rPIDs);
		$rOutput = array();
		foreach ($rServerIDS as $rServerID) {
			if (is_array(self::$rServers) && array_key_exists($rServerID, self::$rServers)) {
				$rResponse = self::serverRequest($rServerID, self::$rServers[$rServerID]['api_url_ip'] . '&action=pidsAreRunning', array('program' => $rEXE, 'pids' => $rPIDs));
				if ($rResponse) {
					$rDecoded = json_decode($rResponse, true);
					if (is_array($rDecoded)) {
						$rOutput[$rServerID] = array_map('trim', $rDecoded);
					} else {
						$rOutput[$rServerID] = false;
					}
				} else {
					$rOutput[$rServerID] = false;
				}
			}
		}
		return $rOutput;
	}
	public static function isPIDRunning($rServerID, $rPID, $rEXE) {
		if (!is_null($rPID) && is_numeric($rPID) && is_array(self::$rServers) && array_key_exists($rServerID, self::$rServers)) {
			if (!($rOutput = self::isPIDsRunning($rServerID, array($rPID), $rEXE))) {
				return false;
			}
			return $rOutput[$rServerID][$rPID];
		}
		return false;
	}
	public static function serverRequest($rServerID, $rURL, $rPostData = array()) {
		return CurlClient::serverRequest(self::$rServers, $rServerID, $rURL, $rPostData);
	}
	public static function deleteCache($rSources) {
		return StreamProcess::deleteCache($rSources);
	}
	public static function queueChannel($rStreamID, $rServerID = null) {
		StreamProcess::queueChannel(self::$db, $rStreamID, $rServerID);
	}
	public static function createChannel($rStreamID) {
		return StreamProcess::createChannel($rStreamID);
	}
	public static function createChannelItem($rStreamID, $rSource) {
		return StreamProcess::createChannelItem(self::$db, self::$rSettings, self::$rServers, self::$rFFMPEG_CPU, self::$rFFMPEG_GPU, $rStreamID, $rSource);
	}
	public static function extractSubtitle($rStreamID, $rSourceURL, $rIndex) {
		$rTimeout = 10;
		$rCommand = 'timeout ' . $rTimeout . ' ' . self::$rFFMPEG_CPU . ' -y -nostdin -hide_banner -loglevel ' . ((self::$rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err -i "' . $rSourceURL . '" -map 0:s:' . intval($rIndex) . ' ' . VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt';
		exec($rCommand, $rOutput);
		if (file_exists(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt')) {
			if (filesize(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt') != 0) {
				return true;
			}
			unlink(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt');
			return false;
		}
		return false;
	}
	public static function probeStream($rSourceURL, $rFetchArguments = array(), $rPrepend = '', $rParse = true) {
		$rAnalyseDuration = abs(intval(self::$rSettings['stream_max_analyze']));
		$rProbesize = abs(intval(self::$rSettings['probesize']));
		$rTimeout = intval($rAnalyseDuration / 1000000) + self::$rSettings['probe_extra_wait'];
		if (!is_array($rFetchArguments)) {
			$rFetchArguments = !empty($rFetchArguments) ? [$rFetchArguments] : [];
		}
		$rCommand = $rPrepend . 'timeout ' . $rTimeout . ' ' . self::$rFFPROBE . ' -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' ' . implode(' ', $rFetchArguments) . ' -i "' . $rSourceURL . '" -v quiet -print_format json -show_streams -show_format';
		exec($rCommand, $rReturn);
		$result = implode("\n", $rReturn);
		if ($rParse) {
			return self::parseFFProbe(json_decode($result, true));
		}
		return json_decode($result, true);
	}
	public static function parseFFProbe($rCodecs) {
		if (empty($rCodecs)) {
			return false;
		}
		if (empty($rCodecs['codecs'])) {
			$rOutput = array();
			$rOutput['codecs']['video'] = '';
			$rOutput['codecs']['audio'] = '';
			$rOutput['container'] = $rCodecs['format']['format_name'];
			$rOutput['filename'] = $rCodecs['format']['filename'];
			$rOutput['bitrate'] = (!empty($rCodecs['format']['bit_rate']) ? $rCodecs['format']['bit_rate'] : null);
			$rOutput['of_duration'] = (!empty($rCodecs['format']['duration']) ? $rCodecs['format']['duration'] : 'N/A');
			$rOutput['duration'] = (!empty($rCodecs['format']['duration']) ? gmdate('H:i:s', intval($rCodecs['format']['duration'])) : 'N/A');
			foreach ($rCodecs['streams'] as $rCodec) {
				if (isset($rCodec['codec_type']) && !($rCodec['codec_type'] != 'audio' && $rCodec['codec_type'] != 'video' && $rCodec['codec_type'] != 'subtitle')) {
					if ($rCodec['codec_type'] == 'audio' || $rCodec['codec_type'] == 'video') {
						if (!empty($rOutput['codecs'][$rCodec['codec_type']])) {
						} else {
							$rOutput['codecs'][$rCodec['codec_type']] = $rCodec;
						}
					} else {
						if ($rCodec['codec_type'] != 'subtitle') {
						} else {
							if (isset($rOutput['codecs'][$rCodec['codec_type']])) {
							} else {
								$rOutput['codecs'][$rCodec['codec_type']] = array();
							}
							$rOutput['codecs'][$rCodec['codec_type']][] = $rCodec;
						}
					}
				}
			}
			return $rOutput;
		} else {
			return $rCodecs;
		}
	}
	public static function stopStream($rStreamID, $rStop = false) {
		StreamProcess::stopStream(self::$db, $rStreamID, $rStop);
	}
	public static function checkPID($rPID, $rSearch) {
		if (is_array($rSearch)) {
		} else {
			$rSearch = array($rSearch);
		}
		if (!file_exists('/proc/' . $rPID)) {
		} else {
			$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
			foreach ($rSearch as $rTerm) {
				if (!stristr($rCommand, $rTerm)) {
				} else {
					return true;
				}
			}
		}
		return false;
	}
	public static function startMonitor($rStreamID, $rRestart = 0) {
		return StreamProcess::startMonitor($rStreamID, $rRestart);
	}
	public static function startProxy($rStreamID) {
		return StreamProcess::startProxy($rStreamID);
	}
	public static function startThumbnail($rStreamID) {
		return StreamProcess::startThumbnail($rStreamID);
	}
	public static function stopMovie($rStreamID, $rForce = false) {
		StreamProcess::stopMovie(self::$db, $rStreamID, $rForce);
	}
	public static function queueMovie($rStreamID, $rServerID = null) {
		StreamProcess::queueMovie(self::$db, $rStreamID, $rServerID);
	}
	public static function queueMovies($rStreamIDs, $rServerID = null) {
		StreamProcess::queueMovies(self::$db, $rStreamIDs, $rServerID);
	}
	public static function refreshMovies($rIDs, $rType = 1) {
		StreamProcess::refreshMovies(self::$db, $rIDs, $rType);
	}
	public static function startMovie($rStreamID) {
		return StreamProcess::startMovie(self::$db, self::$rSettings, self::$rServers, self::$rFFMPEG_CPU, self::$rFFMPEG_GPU, $rStreamID);
	}
	public static function fixCookie($rCookie) {
		$rPath = false;
		$rDomain = false;
		$rSplit = explode(';', $rCookie);
		foreach ($rSplit as $rPiece) {
			list($rKey, $rValue) = explode('=', $rPiece, 1);
			if (strtolower($rKey) == 'path') {
				$rPath = true;
			} else {
				if (strtolower($rKey) != 'domain') {
				} else {
					$rDomain = true;
				}
			}
		}
		if (!substr($rCookie, -1) != ';') {
		} else {
			$rCookie .= ';';
		}
		if ($rPath) {
		} else {
			$rCookie .= 'path=/;';
		}
		if ($rDomain) {
		} else {
			$rCookie .= 'domain=;';
		}
		return $rCookie;
	}
	public static function startLLOD($rStreamID, $rStreamInfo, $rStreamArguments, $rForceSource = null) {
		return StreamProcess::startLLOD(self::$db, $rStreamID, $rStreamInfo, $rStreamArguments, $rForceSource);
	}
	public static function startLoopback($rStreamID) {
		return StreamProcess::startLoopback(self::$db, self::$rSettings, self::$rServers, $rStreamID);
	}

	/**
	 * Starts a live stream processing pipeline
	 * 
	 * This method handles the complete setup and initialization of a live stream, including:
	 * - Source validation and selection
	 * - Stream probing and analysis
	 * - Transcoding configuration
	 * - Output format generation (HLS segments, RTMP, external pushes)
	 * - Delay buffer management
	 * - GPU acceleration setup
	 * - Process monitoring and database updates
	 *
	 * @param int $rStreamID The unique identifier of the stream to start
	 * @param bool $rFromCache Whether to use cached stream probe data (default: false)
	 * @param string|null $rForceSource Force use of a specific stream source URL (default: null)
	 * @param bool $rLLOD Enable low-latency on-demand streaming (default: false)
	 * @param int $rStartPos Starting position for stream playback in seconds (default: 0)
	 *
	 * @return array|false|int Returns array with stream details on success, false on failure, or 0 when stream is empty/invalid
	 */
	public static function startStream($rStreamID, $rFromCache = false, $rForceSource = null, $rLLOD = false, $rStartPos = 0) {
		return StreamProcess::startStream(self::$db, self::$rSettings, self::$rServers, self::$rSegmentSettings, self::$rFFMPEG_CPU, self::$rFFMPEG_GPU, self::$rFFPROBE, $rStreamID, $rFromCache, $rForceSource, $rLLOD, $rStartPos);
	}

	public static function getArguments($rArguments, $rProtocol, $rType) {
		$rReturn = array();
		if (!empty($rArguments)) {
			foreach ($rArguments as $rArgument_id => $rArgument) {
				if ($rArgument['argument_cat'] == $rType && (is_null($rArgument['argument_wprotocol']) || stristr($rProtocol, $rArgument['argument_wprotocol']) || is_null($rProtocol))) {
					if ($rArgument['argument_key'] == 'cookie') {
						$rArgument['value'] = self::fixCookie($rArgument['value']);
					}
					if ($rArgument['argument_type'] == 'text') {
						$rReturn[] = sprintf($rArgument['argument_cmd'], $rArgument['value']);
					} else {
						$rReturn[] = $rArgument['argument_cmd'];
					}
				}
			}
		}
		return $rReturn;
	}
	public static function parseTranscode($rArgs) {
		$rFitlerComplex = array();
		foreach ($rArgs as $rKey => $rArgument) {
			if (!($rKey == 'gpu' || $rKey == 'software_decoding' || $rKey == '16')) {
				if (isset($rArgument['cmd'])) {
					$rArgs[$rKey] = $rArgument = $rArgument['cmd'];
				}
				if (preg_match('/-filter_complex "(.*?)"/', $rArgument, $rMatches)) {
					$rArgs[$rKey] = trim(str_replace($rMatches[0], '', $rArgs[$rKey]));
					$rFitlerComplex[] = $rMatches[1];
				}
			}
		}
		if (!empty($rFitlerComplex)) {
			$rArgs[] = '-filter_complex "' . implode(',', $rFitlerComplex) . '"';
		}
		$rNewArgs = array();
		foreach ($rArgs as $rKey => $rArg) {
			if ($rKey != 'gpu' && $rKey != 'software_decoding') {
				if (is_numeric($rKey)) {
					$rNewArgs[] = $rArg;
				} else {
					$rNewArgs[] = $rKey . ' ' . $rArg;
				}
			}
		}
		$rNewArgs = array_filter($rNewArgs);
		uasort($rNewArgs, array('CoreUtilities', 'customOrder'));
		return array_map('trim', array_values(array_filter($rNewArgs)));
	}
	public static function customOrder($a, $b) {
		if (substr($a, 0, 3) == '-i ') {
			return -1;
		}
		return 1;
	}
	public static function parseStreamURL($rURL) {
		$rProtocol = strtolower(substr($rURL, 0, 4));
		if ($rProtocol == 'rtmp') {
			if (stristr($rURL, '$OPT')) {
				$rPattern = 'rtmp://$OPT:rtmp-raw=';
				$rURL = trim(substr($rURL, stripos($rURL, $rPattern) + strlen($rPattern)));
			}
			$rURL .= ' live=1 timeout=10';
		} else {
			if ($rProtocol == 'http') {
				$rPlatforms = array('livestream.com', 'ustream.tv', 'twitch.tv', 'vimeo.com', 'facebook.com', 'dailymotion.com', 'cnn.com', 'edition.cnn.com', 'youtube.com', 'youtu.be');
				$rHost = str_ireplace('www.', '', parse_url($rURL, PHP_URL_HOST));
				if (in_array($rHost, $rPlatforms)) {
					$rURLs = trim(shell_exec(YOUTUBE_BIN . ' ' . escapeshellarg($rURL) . ' -q --get-url --skip-download -f best'));
					list($rURL) = explode("\n", $rURLs);
				}
			}
		}
		return $rURL;
	}
	public static function detectXC_VM($rURL) {
		$rPath = parse_url($rURL)['path'];
		$rPathSize = count(explode('/', $rPath));
		$rRegex = array('/\\/auth\\/(.*)$/m' => 3, '/\\/play\\/(.*)$/m' => 3, '/\\/play\\/(.*)\\/(.*)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 5, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m' => 5);
		foreach ($rRegex as $rQuery => $rCount) {
			if ($rPathSize != $rCount) {
			} else {
				preg_match($rQuery, $rPath, $rMatches);
				if (0 >= count($rMatches)) {
				} else {
					return true;
				}
			}
		}
		return false;
	}
	public static function getAllowedIPs($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('allowed_ips', 60);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		$rIPs = array('127.0.0.1');
		$rServerAddr = ($_SERVER['SERVER_ADDR'] ?? null);
		if (!empty($rServerAddr)) {
			$rIPs[] = $rServerAddr;
		} elseif (isset(self::$rServers[SERVER_ID]['server_ip']) && !empty(self::$rServers[SERVER_ID]['server_ip'])) {
			$rIPs[] = self::$rServers[SERVER_ID]['server_ip'];
		}
		foreach (self::$rServers as $rServerID => $rServerInfo) {
			if (!empty($rServerInfo['whitelist_ips'])) {
				$rIPs = array_merge($rIPs, json_decode($rServerInfo['whitelist_ips'], true));
			}
			$rIPs[] = $rServerInfo['server_ip'];
			if (!$rServerInfo['private_ip']) {
			} else {
				$rIPs[] = $rServerInfo['private_ip'];
			}
			foreach (explode(',', $rServerInfo['domain_name']) as $rIP) {
				if (!filter_var($rIP, FILTER_VALIDATE_IP)) {
				} else {
					$rIPs[] = $rIP;
				}
			}
		}
		if (empty(self::$rSettings['allowed_ips_admin'])) {
		} else {
			$rIPs = array_merge($rIPs, explode(',', self::$rSettings['allowed_ips_admin']));
		}
		self::setCache('allowed_ips', $rIPs);
		return array_unique($rIPs);
	}
	public static function getUserInfo($rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '') {
		$rUserInfo = null;
		if (self::$rCached) {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if (self::$rSettings['case_sensitive_line']) {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . $rUsername));
				} else {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . strtolower($rUsername)));
				}
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					if (self::$rSettings['case_sensitive_line']) {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . $rUsername . '_' . $rPassword));
					} else {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . strtolower($rUsername) . '_' . strtolower($rPassword)));
					}
				} else {
					if (!empty($rUserID)) {
					} else {
						return false;
					}
				}
			}
			if (!$rUserID) {
			} else {
				$rUserInfo = igbinary_unserialize(file_get_contents(LINES_TMP_PATH . 'line_i_' . $rUserID));
			}
		} else {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				self::$db->query('SELECT * FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `access_token` = ? AND LENGTH(`access_token`) = 32', $rUsername);
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					self::$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `username` = ? AND `password` = ? LIMIT 1', $rUsername, $rPassword);
				} else {
					if (!empty($rUserID)) {
						self::$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `id` = ?', $rUserID);
					} else {
						return false;
					}
				}
			}
			if (0 >= self::$db->num_rows()) {
			} else {
				$rUserInfo = self::$db->get_row();
			}
		}
		if (!$rUserInfo) {
			return false;
		}
		if (!self::$rCached) {
		} else {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if ($rUsername == $rUserInfo['access_token']) {
				} else {
					return false;
				}
			} else {
				if (empty($rUsername) || empty($rPassword)) {
				} else {
					if (!($rUsername != $rUserInfo['username'] || $rPassword != $rUserInfo['password'])) {
					} else {
						return false;
					}
				}
			}
		}
		if (!(self::$rSettings['county_override_1st'] == 1 && empty($rUserInfo['forced_country']) && !empty($rIP) && $rUserInfo['max_connections'] == 1)) {
		} else {
			$rUserInfo['forced_country'] = self::getIPInfo($rIP)['registered_country']['iso_code'];
			if (self::$rCached) {
				self::setSignal('forced_country/' . $rUserInfo['id'], $rUserInfo['forced_country']);
			} else {
				self::$db->query('UPDATE `lines` SET `forced_country` = ? WHERE `id` = ?', $rUserInfo['forced_country'], $rUserInfo['id']);
			}
		}

		$allowedIPS = json_decode($rUserInfo['allowed_ips'], true);
		$allowedUa = json_decode($rUserInfo['allowed_ua'], true);
		$rUserInfo['bouquet'] = json_decode($rUserInfo['bouquet'], true);
		$rUserInfo['allowed_ips'] = array_filter(array_map('trim', is_array($allowedIPS) ? $allowedIPS : []));
		$rUserInfo['allowed_ua'] = array_filter(array_map('trim', is_array($allowedUa) ? $allowedUa : []));
		$rUserInfo['allowed_outputs'] = array_map('intval', json_decode($rUserInfo['allowed_outputs'], true));
		$rUserInfo['output_formats'] = array();
		if (self::$rCached) {
			foreach (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'output_formats')) as $rRow) {
				if (!in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
				} else {
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		} else {
			self::$db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');
			foreach (self::$db->get_rows() as $rRow) {
				if (!in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
				} else {
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		}
		$rUserInfo['con_isp_name'] = null;
		$rUserInfo['isp_violate'] = 0;
		$rUserInfo['isp_is_server'] = 0;
		if (self::$rSettings['show_isps'] != 1 || empty($rIP)) {
		} else {
			$rISPLock = self::getISP($rIP);
			if (!is_array($rISPLock)) {
			} else {
				if (empty($rISPLock['isp'])) {
				} else {
					$rUserInfo['con_isp_name'] = $rISPLock['isp'];
					$rUserInfo['isp_asn'] = $rISPLock['autonomous_system_number'];
					$rUserInfo['isp_violate'] = self::checkISP($rUserInfo['con_isp_name']);
					if (self::$rSettings['block_svp'] != 1) {
					} else {
						$rUserInfo['isp_is_server'] = intval(self::checkServer($rUserInfo['isp_asn']));
					}
				}
			}
			if (!(!empty($rUserInfo['con_isp_name']) && self::$rSettings['enable_isp_lock'] == 1 && $rUserInfo['is_stalker'] == 0 && $rUserInfo['is_isplock'] == 1 && !empty($rUserInfo['isp_desc']) && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc']))) {
			} else {
				$rUserInfo['isp_violate'] = 1;
			}
			if (!($rUserInfo['isp_violate'] == 0 && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc']))) {
			} else {
				if (self::$rCached) {
					self::setSignal('isp/' . $rUserInfo['id'], json_encode(array($rUserInfo['con_isp_name'], $rUserInfo['isp_asn'])));
				} else {
					self::$db->query('UPDATE `lines` SET `isp_desc` = ?, `as_number` = ? WHERE `id` = ?', $rUserInfo['con_isp_name'], $rUserInfo['isp_asn'], $rUserInfo['id']);
				}
			}
		}
		if (!$rGetChannelIDs) {
		} else {
			$rLiveIDs = $rVODIDs = $rRadioIDs = $rCategoryIDs = $rChannelIDs = $rSeriesIDs = array();
			foreach ($rUserInfo['bouquet'] as $rID) {
				if (!isset(self::$rBouquets[$rID]['streams'])) {
				} else {
					$rChannelIDs = array_merge($rChannelIDs, self::$rBouquets[$rID]['streams']);
				}
				if (!isset(self::$rBouquets[$rID]['series'])) {
				} else {
					$rSeriesIDs = array_merge($rSeriesIDs, self::$rBouquets[$rID]['series']);
				}
				if (!isset(self::$rBouquets[$rID]['channels'])) {
				} else {
					$rLiveIDs = array_merge($rLiveIDs, self::$rBouquets[$rID]['channels']);
				}
				if (!isset(self::$rBouquets[$rID]['movies'])) {
				} else {
					$rVODIDs = array_merge($rVODIDs, self::$rBouquets[$rID]['movies']);
				}
				if (!isset(self::$rBouquets[$rID]['radios'])) {
				} else {
					$rRadioIDs = array_merge($rRadioIDs, self::$rBouquets[$rID]['radios']);
				}
			}
			$rUserInfo['channel_ids'] = array_map('intval', array_unique($rChannelIDs));
			$rUserInfo['series_ids'] = array_map('intval', array_unique($rSeriesIDs));
			$rUserInfo['vod_ids'] = array_map('intval', array_unique($rVODIDs));
			$rUserInfo['live_ids'] = array_map('intval', array_unique($rLiveIDs));
			$rUserInfo['radio_ids'] = array_map('intval', array_unique($rRadioIDs));
		}
		$rAllowedCategories = array();
		$rCategoryMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'category_map'));
		foreach ($rUserInfo['bouquet'] as $rID) {
			$rAllowedCategories = array_merge($rAllowedCategories, ($rCategoryMap[$rID] ?: array()));
		}
		$rUserInfo['category_ids'] = array_values(array_unique($rAllowedCategories));
		return $rUserInfo;
	}
	public static function getAdultCategories() {
		$rReturn = array();
		foreach (self::$rCategories as $rCategory) {
			if (!$rCategory['is_adult']) {
			} else {
				$rReturn[] = intval($rCategory['id']);
			}
		}
		return $rReturn;
	}
	public static function getMAGInfo($rMAGID = null, $rMAC = null, $rGetChannelIDs = false, $rGetBouquetInfo = false, $rGetConnections = false) {
		if (empty($rMAGID)) {
			self::$db->query('SELECT * FROM `mag_devices` WHERE `mac` = ?', base64_encode($rMAC));
		} else {
			self::$db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?', $rMAGID);
		}
		if (0 >= self::$db->num_rows()) {
			return false;
		}
		$rMagInfo = array();
		$rMagInfo['mag_device'] = self::$db->get_row();
		$rMagInfo['mag_device']['mac'] = base64_decode($rMagInfo['mag_device']['mac']);
		$rMagInfo['user_info'] = array();
		if (!($rUserInfo = self::getUserInfo($rMagInfo['mag_device']['user_id'], null, null, $rGetChannelIDs, $rGetConnections))) {
		} else {
			$rMagInfo['user_info'] = $rUserInfo;
		}
		$rMagInfo['pair_line_info'] = array();
		if (empty($rMagInfo['user_info'])) {
		} else {
			$rMagInfo['pair_line_info'] = array();
			if (is_null($rMagInfo['user_info']['pair_id'])) {
			} else {
				if (!($rUserInfo = self::getUserInfo($rMagInfo['user_info']['pair_id'], null, null, $rGetChannelIDs, $rGetConnections))) {
				} else {
					$rMagInfo['pair_line_info'] = $rUserInfo;
				}
			}
		}
		return $rMagInfo;
	}
	public static function getE2Info($rDevice, $rGetChannelIDs = false, $rGetBouquetInfo = false, $rGetConnections = false) {
		if (empty($rDevice['device_id'])) {
			self::$db->query('SELECT * FROM `enigma2_devices` WHERE `mac` = ?', $rDevice['mac']);
		} else {
			self::$db->query('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?', $rDevice['device_id']);
		}
		if (0 >= self::$db->num_rows()) {
			return false;
		}
		$rReturn = array();
		$rReturn['enigma2'] = self::$db->get_row();
		$rReturn['user_info'] = array();
		if (!($rUserInfo = self::getUserInfo($rReturn['enigma2']['user_id'], null, null, $rGetChannelIDs, $rGetConnections))) {
		} else {
			$rReturn['user_info'] = $rUserInfo;
		}
		$rReturn['pair_line_info'] = array();
		if (empty($rReturn['user_info'])) {
		} else {
			$rReturn['pair_line_info'] = array();
			if (is_null($rReturn['user_info']['pair_id'])) {
			} else {
				if (!($rUserInfo = self::getUserInfo($rReturn['user_info']['pair_id'], null, null, $rGetChannelIDs, $rGetConnections))) {
				} else {
					$rReturn['pair_line_info'] = $rUserInfo;
				}
			}
		}
		return $rReturn;
	}
	public static function getRTMPStats() {
		$rURL = self::$rServers[SERVER_ID]['rtmp_mport_url'] . 'stat';
		$rContext = stream_context_create(array('http' => array('timeout' => 1)));
		$rXML = file_get_contents($rURL, false, $rContext);
		return json_decode(json_encode(simplexml_load_string($rXML, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
	}
	public static function closeConnection($rActivityInfo, $rRemove = true, $rEnd = true) {
		if (!empty($rActivityInfo)) {
			if (!self::$rSettings['redis_handler'] || is_object(self::$redis)) {
			} else {
				self::connectRedis();
			}
			if (is_array($rActivityInfo)) {
			} else {
				if (!self::$rSettings['redis_handler']) {
					if (strlen(strval($rActivityInfo)) == 32) {
						self::$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ?', $rActivityInfo);
					} else {
						self::$db->query('SELECT * FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo);
					}
					$rActivityInfo = self::$db->get_row();
				} else {
					$rActivityInfo = igbinary_unserialize(self::$redis->get($rActivityInfo));
				}
			}
			if (is_array($rActivityInfo)) {
				if ($rActivityInfo['container'] == 'rtmp') {
					if ($rActivityInfo['server_id'] == SERVER_ID) {
						shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . self::$rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rActivityInfo['pid']) . '" >/dev/null 2>/dev/null &');
					} else {
						if (self::$rSettings['redis_handler']) {
							self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
						} else {
							self::$db->query('INSERT INTO `signals` (`pid`,`server_id`,`rtmp`,`time`) VALUES(?,?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
						}
					}
				} else {
					if ($rActivityInfo['container'] == 'hls') {
						if (!(!$rRemove && $rEnd && $rActivityInfo['hls_end'] == 0)) {
						} else {
							if (self::$rSettings['redis_handler']) {
								self::updateConnection($rActivityInfo, array(), 'close');
							} else {
								self::$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
							}
							@unlink(CONS_TMP_PATH . $rActivityInfo['stream_id'] . '/' . $rActivityInfo['uuid']);
						}
					} else {
						if ($rActivityInfo['server_id'] == SERVER_ID) {
							if (!($rActivityInfo['pid'] != getmypid() && is_numeric($rActivityInfo['pid']) && 0 < $rActivityInfo['pid'])) {
							} else {
								posix_kill(intval($rActivityInfo['pid']), 9);
							}
						} else {
							if (self::$rSettings['redis_handler']) {
								self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 0);
							} else {
								self::$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id']);
							}
						}
					}
				}
				if ($rActivityInfo['server_id'] != SERVER_ID) {
				} else {
					@unlink(CONS_TMP_PATH . $rActivityInfo['uuid']);
				}
				if (!$rRemove) {
				} else {
					if ($rActivityInfo['server_id'] != SERVER_ID) {
					} else {
						@unlink(CONS_TMP_PATH . $rActivityInfo['stream_id'] . '/' . $rActivityInfo['uuid']);
					}
					if (self::$rSettings['redis_handler']) {
						$rRedis = self::$redis->multi();
						$rRedis->zRem('LINE#' . $rActivityInfo['identity'], $rActivityInfo['uuid']);
						$rRedis->zRem('LINE_ALL#' . $rActivityInfo['identity'], $rActivityInfo['uuid']);
						$rRedis->zRem('STREAM#' . $rActivityInfo['stream_id'], $rActivityInfo['uuid']);
						$rRedis->zRem('SERVER#' . $rActivityInfo['server_id'], $rActivityInfo['uuid']);
						if (!$rActivityInfo['user_id']) {
						} else {
							$rRedis->zRem('SERVER_LINES#' . $rActivityInfo['server_id'], $rActivityInfo['uuid']);
						}
						if (!$rActivityInfo['proxy_id']) {
						} else {
							$rRedis->zRem('PROXY#' . $rActivityInfo['proxy_id'], $rActivityInfo['uuid']);
						}
						$rRedis->del($rActivityInfo['uuid']);
						$rRedis->zRem('CONNECTIONS', $rActivityInfo['uuid']);
						$rRedis->zRem('LIVE', $rActivityInfo['uuid']);
						$rRedis->sRem('ENDED', $rActivityInfo['uuid']);
						$rRedis->exec();
					} else {
						self::$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
					}
				}
				self::writeOfflineActivity($rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'], $rActivityInfo['divergence'], $rActivityInfo['hmac_id'], $rActivityInfo['hmac_identifier']);
				return true;
			}
			return false;
		}
		return false;
	}
	public static function writeOfflineActivity($rServerID, $rProxyID, $rUserID, $rStreamID, $rStart, $rUserAgent, $rIP, $rExtension, $rGeoIP, $rISP, $rExternalDevice = '', $rDivergence = 0, $rIsHMAC = null, $rIdentifier = '') {
		if (self::$rSettings['save_closed_connection'] != 0) {
			if (!($rServerID && $rUserID && $rStreamID)) {
			} else {
				$rActivityInfo = array('user_id' => intval($rUserID), 'stream_id' => intval($rStreamID), 'server_id' => intval($rServerID), 'proxy_id' => intval($rProxyID), 'date_start' => intval($rStart), 'user_agent' => $rUserAgent, 'user_ip' => htmlentities($rIP), 'date_end' => time(), 'container' => $rExtension, 'geoip_country_code' => $rGeoIP, 'isp' => $rISP, 'external_device' => htmlentities($rExternalDevice), 'divergence' => intval($rDivergence), 'hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier);
				file_put_contents(LOGS_TMP_PATH . 'activity', base64_encode(json_encode($rActivityInfo)) . "\n", FILE_APPEND | LOCK_EX);
			}
		} else {
			return null;
		}
	}
	public static function streamLog($rStreamID, $rServerID, $rAction, $rSource = '') {
		if (self::$rSettings['save_restart_logs'] != 0) {
			$rData = array('server_id' => $rServerID, 'stream_id' => $rStreamID, 'action' => $rAction, 'source' => $rSource, 'time' => time());
			file_put_contents(LOGS_TMP_PATH . 'stream_log.log', base64_encode(json_encode($rData)) . "\n", FILE_APPEND);
		} else {
			return null;
		}
	}
	public static function getPlaylistSegments($rPlaylist, $rPrebuffer = 0, $rSegmentDuration = 10) {
		if (!file_exists($rPlaylist)) {
		} else {
			$rSource = file_get_contents($rPlaylist);
			if (!preg_match_all('/(.*?).ts/', $rSource, $rMatches)) {
			} else {
				if (0 < $rPrebuffer) {
					$rTotalSegments = intval($rPrebuffer / (($rSegmentDuration ?: 1)));
					return array_slice($rMatches[0], -1 * $rTotalSegments);
				}
				if ($rPrebuffer == -1) {
					return $rMatches[0];
				}
				preg_match('/_(.*)\\./', array_pop($rMatches[0]), $rCurrentSegment);
				return $rCurrentSegment[1];
			}
		}
	}
	public static function generateAdminHLS($rM3U8, $rPassword, $rStreamID, $rUIToken) {
		if (!file_exists($rM3U8)) {
		} else {
			$rSource = file_get_contents($rM3U8);
			if (!preg_match_all('/(.*?)\\.ts/', $rSource, $rMatches)) {
			} else {
				foreach ($rMatches[0] as $rMatch) {
					if ($rUIToken) {
						$rSource = str_replace($rMatch, '/admin/live?extension=m3u8&segment=' . $rMatch . '&uitoken=' . $rUIToken, $rSource);
					} else {
						$rSource = str_replace($rMatch, '/admin/live?password=' . $rPassword . '&extension=m3u8&segment=' . $rMatch . '&stream=' . $rStreamID, $rSource);
					}
				}
				return $rSource;
			}
		}
		return false;
	}
	public static function checkBlockedUAs($rUserAgent, $rReturn = false) {
		$rUserAgent = strtolower($rUserAgent);
		$rFoundID = false;
		foreach (self::$rBlockedUA as $rKey => $rBlocked) {
			if ($rBlocked['exact_match'] == 1) {
				if ($rBlocked['blocked_ua'] != $rUserAgent) {
				} else {
					$rFoundID = $rKey;
					break;
				}
			} else {
				if (!stristr($rUserAgent, $rBlocked['blocked_ua'])) {
				} else {
					$rFoundID = $rKey;
					break;
				}
			}
		}
		if (0 >= $rFoundID) {
		} else {
			self::$db->query('UPDATE `blocked_uas` SET `attempts_blocked` = `attempts_blocked`+1 WHERE `id` = ?', $rFoundID);
			if ($rReturn) {
				return true;
			}
			exit();
		}
	}
	public static function isMonitorRunning($rPID, $rStreamID, $rEXE = PHP_BIN) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
			} else {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if (!($rCommand == 'XC_VM[' . $rStreamID . ']' || $rCommand == 'XC_VMProxy[' . $rStreamID . ']')) {
				} else {
					return true;
				}
			}
			return false;
		}
		return false;
	}
	public static function isThumbnailRunning($rPID, $rStreamID, $rEXE = PHP_BIN) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
			} else {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand != 'Thumbnail[' . $rStreamID . ']') {
				} else {
					return true;
				}
			}
			return false;
		}
		return false;
	}
	public static function isArchiveRunning($rPID, $rStreamID, $rEXE = PHP_BIN) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
			} else {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand != 'TVArchive[' . $rStreamID . ']') {
				} else {
					return true;
				}
			}
			return false;
		}
		return false;
	}
	public static function isDelayRunning($rPID, $rStreamID) {
		if (!empty($rPID)) {
			static $procCache = [];
			$now = microtime(true);
			$key = (int)$rPID;
			if (isset($procCache[$key]) && $now - $procCache[$key]['time'] < 1.0) {
				$procExists = $procCache[$key]['exists'];
			} else {
				$procExists = file_exists('/proc/' . $rPID);
				$procCache[$key] = ['exists' => $procExists, 'time' => $now];
			}

			if ($procExists && is_readable('/proc/' . $rPID . '/exe')) {
				$rCommand = trim(@file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand == 'XC_VMDelay[' . $rStreamID . ']') {
					return true;
				}
			}
			return false;
		}
		return false;
	}
	public static function isStreamRunning($rPID, $rStreamID) {
		if (!empty($rPID)) {
			static $procCache = [];
			$now = microtime(true);
			$key = (int)$rPID;
			if (isset($procCache[$key]) && $now - $procCache[$key]['time'] < 1.0) {
				$procExists = $procCache[$key]['exists'];
			} else {
				$procExists = file_exists('/proc/' . $rPID);
				$procCache[$key] = ['exists' => $procExists, 'time' => $now];
			}

			if ($procExists && is_readable('/proc/' . $rPID . '/exe')) {
				$exe = @basename(@readlink('/proc/' . $rPID . '/exe'));
				if (strpos($exe, 'ffmpeg') === 0) {
					$rCommand = trim(@file_get_contents('/proc/' . $rPID . '/cmdline'));
					if (stristr($rCommand, '/' . $rStreamID . '_.m3u8') || stristr($rCommand, '/' . $rStreamID . '_%d.ts')) {
						return true;
					}
				} else {
					if (strpos($exe, 'php') === 0) {
						return true;
					}
				}
			}
			return false;
		}
		return false;
	}
	public static function isProcessRunning($rPID, $rEXE = null) {
		if (!empty($rPID)) {
			static $procCache = [];
			$now = microtime(true);
			$key = (int)$rPID;
			if (isset($procCache[$key]) && $now - $procCache[$key]['time'] < 1.0) {
				$procExists = $procCache[$key]['exists'];
			} else {
				$procExists = file_exists('/proc/' . $rPID);
				$procCache[$key] = ['exists' => $procExists, 'time' => $now];
			}

			if ($rEXE && !($procExists && is_readable('/proc/' . $rPID . '/exe') && strpos(@basename(@readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
				return false;
			}
			return true;
		}
		return false;
	}
	public static function isValidStream($rPlaylist, $rPID) {
		return (self::isProcessRunning($rPID, 'ffmpeg') || self::isProcessRunning($rPID, 'php')) && file_exists($rPlaylist);
	}
	public static function findKeyframe($rSegment) {
		$rPacketSize = 188;
		$rKeyframe = $rPosition = 0;
		$rFoundStart = false;
		if (file_exists($rSegment)) {
			$rFP = fopen($rSegment, 'rb');
			if ($rFP) {
				while (!feof($rFP)) {
					if (!$rFoundStart) {
						$rFirstPacket = fread($rFP, $rPacketSize);
						$rSecondPacket = fread($rFP, $rPacketSize);
						$i = 0;
						while ($i < strlen($rFirstPacket)) {
							list(, $rFirstHeader) = unpack('N', substr($rFirstPacket, $i, 4));
							list(, $rSecondHeader) = unpack('N', substr($rSecondPacket, $i, 4));
							$rSync = ($rFirstHeader >> 24 & 255) == 71 && ($rSecondHeader >> 24 & 255) == 71;
							if (!$rSync) {
								$i++;
							} else {
								$rFoundStart = true;
								$rPosition = $i;
								fseek($rFP, $i);
							}
						}
					}
					$rBuffer .= fread($rFP, $rPacketSize * 64 - strlen($rBuffer));
					if (!empty($rBuffer)) {
						foreach (str_split($rBuffer, $rPacketSize) as $rPacket) {
							list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
							$rSync = $rHeader >> 24 & 255;
							if ($rSync == 71) {
								if (substr($rPacket, 6, 4) == '?' . '' . "\r" . '' . '' . '' . "\x01") {
									$rKeyframe = $rPosition;
								} else {
									$rAdaptationField = $rHeader >> 4 & 3;
									if (($rAdaptationField & 2) === 2) {
										if (0 < $rKeyframe && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == "\x07" . 'P') {
											break;
										}
									}
								}
							}
							$rPosition += strlen($rPacket);
						}
					}
					$rBuffer = '';
				}
				fclose($rFP);
			}
		}
		return $rKeyframe;
	}
	public static function getUserIP() {
		return $_SERVER['REMOTE_ADDR'];
	}
	public static function getStreamBitrate($rType, $rPath, $rForceDuration = null) {
		clearstatcache();
		if (file_exists($rPath)) {
			switch ($rType) {
				case 'movie':
					if (!is_null($rForceDuration)) {
						sscanf($rForceDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
						$rTime = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
						$rBitrate = round((filesize($rPath) * 0.008) / (($rTime ?: 1)));
					}
					break;
				case 'live':
					$rFP = fopen($rPath, 'r');
					$rBitrates = array();
					while (!feof($rFP)) {
						$rLine = trim(fgets($rFP));
						if (stristr($rLine, 'EXTINF')) {
							list($rTrash, $rSeconds) = explode(':', $rLine);
							$rSeconds = rtrim($rSeconds, ',');
							if ($rSeconds > 0) {
								$rSegmentFile = trim(fgets($rFP));
								if (file_exists(dirname($rPath) . '/' . $rSegmentFile)) {
									$rSize = filesize(dirname($rPath) . '/' . $rSegmentFile) * 0.008;
									$rBitrates[] = $rSize / (($rSeconds ?: 1));
								} else {
									fclose($rFP);
									return false;
								}
							}
						}
					}
					fclose($rFP);
					$rBitrate = (0 < count($rBitrates) ? round(array_sum($rBitrates) / count($rBitrates)) : 0);
					break;
			}
			return (0 < $rBitrate ? $rBitrate : false);
		}
		return false;
	}
	public static function getISP($rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_isp')) {
				$rGeoIP = new MaxMind\Db\Reader(GEOISP_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if ($rResponse) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true);
		}
		return false;
	}
	public static function checkISP($rConISP) {
		foreach (self::$rBlockedISP as $rISP) {
			if (strtolower($rConISP) == strtolower($rISP['isp'])) {
				return intval($rISP['blocked']);
			}
		}
		return 0;
	}
	public static function checkServer($rASN) {
		return in_array($rASN, self::$rBlockedServers);
	}
	public static function getIPInfo($rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
				$rGeoIP = new MaxMind\Db\Reader(GEOLITE2_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if ($rResponse) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
		}
		return false;
	}
	public static function isRunning() {
		$rNginx = 0;
		exec('ps -fp $(pgrep -u xc_vm)', $rOutput, $rReturnVar);
		foreach ($rOutput as $rProcess) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
			if ($rSplit[8] == 'nginx:' && $rSplit[9] == 'master') {
				$rNginx++;
			}
		}
		return 0 < $rNginx;
	}
	public static function getCertificateInfo($rCertificate = null) {
		$rReturn = array('serial' => null, 'expiration' => null, 'subject' => null, 'path' => null);
		if (!$rCertificate) {
			$rConfig = explode("\n", file_get_contents(BIN_PATH . 'nginx/conf/ssl.conf'));
			foreach ($rConfig as $rLine) {
				if (stripos($rLine, 'ssl_certificate ') !== false) {
					$rCertificate = rtrim(trim(explode('ssl_certificate ', $rLine)[1]), ';');
					break;
				}
			}
		}
		if ($rCertificate) {
			$rReturn['path'] = pathinfo($rCertificate)['dirname'];
			exec('openssl x509 -serial -enddate -subject -noout -in ' . escapeshellarg($rCertificate), $rOutput, $rReturnVar);
			foreach ($rOutput as $rLine) {
				if (stripos($rLine, 'serial=') !== false) {
					$rReturn['serial'] = trim(explode('serial=', $rLine)[1]);
				} elseif (stripos($rLine, 'subject=') !== false) {
					$rReturn['subject'] = trim(explode('subject=', $rLine)[1]);
				} elseif (stripos($rLine, 'notAfter=') !== false) {
					$rReturn['expiration'] = strtotime(trim(explode('notAfter=', $rLine)[1]));
				}
			}
		}
		return $rReturn;
	}
	public static function downloadImage($rImage, $rType = null) {
		if (0 < strlen($rImage) && substr(strtolower($rImage), 0, 4) == 'http') {
			$rPathInfo = pathinfo($rImage);
			$rExt = $rPathInfo['extension'];
			if (!$rExt) {
				$rImageInfo = getimagesize($rImage);
				if ($rImageInfo['mime']) {
					list(, $rExt) = explode('/', $rImageInfo['mime']);
				}
			}
			if (in_array(strtolower($rExt), array('jpg', 'jpeg', 'png'))) {
				$rFilename = CoreUtilities::encryptData($rImage, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rPrevPath = IMAGES_PATH . $rFilename . '.' . $rExt;
				if (file_exists($rPrevPath)) {
					return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
				}
				$rCurl = curl_init();
				curl_setopt($rCurl, CURLOPT_URL, $rImage);
				curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($rCurl, CURLOPT_TIMEOUT, 5);
				$rData = curl_exec($rCurl);
				if (strlen($rData) > 0) {
					$rPath = IMAGES_PATH . $rFilename . '.' . $rExt;
					file_put_contents($rPath, $rData);
					if (file_exists($rPath)) {
						return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
					}
				}
			}
		}
		return $rImage;
	}
	public static function getImageSizeKeepAspectRatio($origWidth, $origHeight, $maxWidth, $maxHeight) {
		if ($maxWidth == 0) {
			$maxWidth = $origWidth;
		}
		if ($maxHeight == 0) {
			$maxHeight = $origHeight;
		}
		$widthRatio = $maxWidth / (($origWidth ?: 1));
		$heightRatio = $maxHeight / (($origHeight ?: 1));
		$ratio = min($widthRatio, $heightRatio);
		if ($ratio < 1) {
			$newWidth = (int) $origWidth * $ratio;
			$newHeight = (int) $origHeight * $ratio;
		} else {
			$newHeight = $origHeight;
			$newWidth = $origWidth;
		}
		return array('height' => round($newHeight, 0), 'width' => round($newWidth, 0));
	}
	public static function isAbsoluteUrl($rURL) {
		$rPattern = "/^(?:ftp|https?|feed)?:?\\/\\/(?:(?:(?:[\\w\\.\\-\\+!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*" . "\n" . "        (?:[\\w\\.\\-\\+%!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@)?(?:" . "\n" . '        (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+|(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\]))(?::[0-9]+)?(?:[\\/|\\?]' . "\n" . "        (?:[\\w#!:\\.\\?\\+\\|=&@\$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})*)?\$/xi";
		return (bool) preg_match($rPattern, $rURL);
	}
	public static function generateThumbnail($rImage, $rType) {
		return ImageUtils::generateThumbnail($rImage, $rType);
	}
	public static function validateImage($rURL, $rForceProtocol = null) {
		return ImageUtils::validateURL($rURL, $rForceProtocol, array('CoreUtilities', 'getPublicURL'));
	}
	public static function getPublicURL($rServerID = null, $rForceProtocol = null) {
		$rOriginatorID = null;
		if (isset($rServerID)) {
		} else {
			$rServerID = SERVER_ID;
		}
		if ($rForceProtocol) {
			$rProtocol = $rForceProtocol;
		} else {
			if (isset($_SERVER['SERVER_PORT']) && self::$rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = self::$rServers[$rServerID]['server_protocol'];
			}
		}
		if (!self::$rServers[$rServerID]) {
		} else {
			if (!self::$rServers[$rServerID]['enable_proxy']) {
			} else {
				$rProxyIDs = array_keys(self::getProxies($rServerID));
				if (count($rProxyIDs) != 0) {
				} else {
					$rProxyIDs = array_keys(self::getProxies($rServerID, false));
				}
				if (count($rProxyIDs) != 0) {
					$rOriginatorID = $rServerID;
					$rServerID = $rProxyIDs[array_rand($rProxyIDs)];
				} else {
					return '';
				}
			}
			$rHost = (defined('host') ? HOST : null);
			if ($rHost && in_array(strtolower($rHost), array_map('strtolower', self::$rServers[$rServerID]['domains']['urls']))) {
				$rDomain = $rHost;
			} else {
				$rDomain = (empty(self::$rServers[$rServerID]['domain_name']) ? self::$rServers[$rServerID]['server_ip'] : explode(',', self::$rServers[$rServerID]['domain_name'])[0]);
			}
			$rServerURL = $rProtocol . '://' . $rDomain . ':' . self::$rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';
			if (!(self::$rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && self::$rServers[$rOriginatorID]['is_main'] == 0)) {
			} else {
				$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
			}
			return $rServerURL;
		}
	}
	public static function getURL($rURL, $rWait = true) {
		return CurlClient::getURL($rURL, $rWait);
	}
	public static function startDownload($rType, $rUser, $rDownloadPID) {
		$rFloodLimit = intval(self::$rSettings['max_simultaneous_downloads']);
		if ($rFloodLimit != 0) {
			if (!$rUser['is_restreamer']) {
				$rFile = FLOOD_TMP_PATH . $rUser['id'] . '_downloads';
				if (file_exists($rFile) && time() - filemtime($rFile) < 10) {
					$rFloodRow[$rType] = array();
					foreach (json_decode(file_get_contents($rFile), true)[$rType] as $rPID) {
						if (!(self::isProcessRunning($rPID, 'php-fpm') && $rPID != $rDownloadPID)) {
						} else {
							$rFloodRow[$rType][] = $rPID;
						}
					}
				} else {
					$rFloodRow = array('epg' => array(), 'playlist' => array());
				}
				$rAllow = false;
				if (count($rFloodRow[$rType]) >= $rFloodLimit) {
				} else {
					$rFloodRow[$rType][] = $rDownloadPID;
					$rAllow = true;
				}
				file_put_contents($rFile, json_encode($rFloodRow), LOCK_EX);
				return $rAllow;
			} else {
				return true;
			}
		} else {
			return true;
		}
	}
	public static function stopDownload($rType, $rUser, $rDownloadPID) {
		if (intval(self::$rSettings['max_simultaneous_downloads']) != 0) {
			if (!$rUser['is_restreamer']) {
				$rFile = FLOOD_TMP_PATH . $rUser['id'] . '_downloads';
				if (file_exists($rFile)) {
					$rFloodRow[$rType] = array();
					foreach (json_decode(file_get_contents($rFile), true)[$rType] as $rPID) {
						if (!(self::isProcessRunning($rPID, 'php-fpm') && $rPID != $rDownloadPID)) {
						} else {
							$rFloodRow[$rType][] = $rPID;
						}
					}
				} else {
					$rFloodRow = array('epg' => array(), 'playlist' => array());
				}
				file_put_contents($rFile, json_encode($rFloodRow), LOCK_EX);
			} else {
				return null;
			}
		} else {
			return null;
		}
	}
	/** @deprecated Use BruteforceGuard::checkAuthFlood() */
	public static function checkAuthFlood($rUser, $rIP = null) {
		return BruteforceGuard::checkAuthFlood($rUser, $rIP);
	}
	public static function getCapacity($rProxy = false) {
		if (self::$rSettings['redis_handler'] && !is_object(self::$redis)) {
			self::connectRedis();
		}
		return ConnectionTracker::getCapacity(self::$rSettings, self::$rServers, self::$redis, self::$db, $rProxy);
	}

	/**
	 * Retrieve active connection data either from Redis or MySQL fallback.
	 *
	 * This method returns an array with two elements:
	 *   [0] => array of UUID keys
	 *   [1] => array of connection data arrays (unserialized Redis objects or MySQL rows)
	 *
	 * Behavior:
	 * - If Redis handler is enabled, connections are fetched from sorted sets:
	 *     SERVER#{server_id}, LINE#{user_id}, STREAM#{stream_id}, or LIVE
	 * - If no keys exist in Redis, it returns [[] , []] to avoid null results.
	 * - If Redis handler is disabled, a MySQL query is executed to obtain live connections.
	 *
	 * @param int|null $rServerID Optional server ID to filter connections (Redis ZSET: SERVER#{id})
	 * @param int|null $rUserID   Optional user/line ID to filter connections (Redis ZSET: LINE#{id})
	 * @param int|null $rStreamID Optional stream ID to filter connections (Redis ZSET: STREAM#{id})
	 *
	 * @return array{
	 *     0: array, // List of UUID keys
	 *     1: array  // List of connection data arrays
	 * }
	 *
	 */

	public static function getConnections($rServerID = null, $rUserID = null, $rStreamID = null) {
		if (self::$rSettings['redis_handler'] && !is_object(self::$redis)) {
			self::connectRedis();
		}
		return ConnectionTracker::getConnections(self::$rSettings, self::$redis, self::$db, $rServerID, $rUserID, $rStreamID);
	}

	public static function getEnded() {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rKeys = self::$redis->sMembers('ENDED');
		if (0 >= count($rKeys)) {
		} else {
			return array_map('igbinary_unserialize', self::$redis->mGet($rKeys));
		}
	}
	public static function getBouquetMap($rStreamID) {
		return BouquetMapper::getMapEntry($rStreamID);
	}
	public static function updateStream($rStreamID, $rForce = false) {
		return StreamProcess::updateStream(self::$db, self::$rCached, self::getMainID(), $rStreamID, $rForce);
	}
	public static function updateStreams($rStreamIDs) {
		return StreamProcess::updateStreams(self::$db, self::$rCached, self::getMainID(), $rStreamIDs);
	}
	public static function deleteLine($rUserID, $rForce = false) {
		LineService::deleteLineSignal(self::$db, self::$rCached, self::getMainID(), $rUserID, $rForce);
	}
	public static function deleteLines($rUserIDs, $rForce = false) {
		LineService::deleteLinesSignal(self::$db, self::$rCached, self::getMainID(), $rUserIDs, $rForce);
	}
	public static function updateLine($rUserID, $rForce = false) {
		return LineService::updateLineSignal(self::$db, self::$rCached, self::getMainID(), $rUserID, $rForce);
	}
	public static function updateLines($rUserIDs) {
		return LineService::updateLinesSignal(self::$db, self::$rCached, self::getMainID(), $rUserIDs);
	}
	public static function getMainID() {
		return ConnectionTracker::getMainID(self::$rServers);
	}
	public static function addToQueue($rStreamID, $rAddPID) {
		ConnectionTracker::addToQueue($rStreamID, $rAddPID, array('CoreUtilities', 'isProcessRunning'));
	}
	public static function removeFromQueue($rStreamID, $rPID) {
		ConnectionTracker::removeFromQueue($rStreamID, $rPID, array('CoreUtilities', 'isProcessRunning'));
	}
	public static function getProxyFor($rServerID) {
		return (array_rand(array_keys(self::getProxies($rServerID, false))) ?: null);
	}
	public static function formatTitle($rTitle, $rYear) {
		return StreamSorter::formatTitle(self::$rSettings, $rTitle, $rYear);
	}
	public static function sortChannels($rChannels) {
		return StreamSorter::sortChannels(self::$rSettings, $rChannels);
	}
	public static function sortSeries($rSeries) {
		return StreamSorter::sortSeries($rSeries);
	}
	public static function setSignal($rKey, $rData) {
		RedisManager::setSignal($rKey, $rData);
	}
	public static function connectRedis() {
		self::$redis = RedisManager::connect(self::$redis, self::$rConfig, self::$rSettings);
		return is_object(self::$redis);
	}
	public static function updateConnection($rData, $rChanges = array(), $rOption = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::updateConnection(self::$redis, $rData, $rChanges, $rOption);
	}
	public static function redisSignal($rPID, $rServerID, $rRTMP, $rCustomData = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::redisSignal(self::$redis, $rPID, $rServerID, $rRTMP, $rCustomData);
	}
	public static function getUserConnections($rUserIDs, $rCount = false, $rKeysOnly = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getUserConnections(self::$redis, $rUserIDs, $rCount, $rKeysOnly);
	}
	public static function getServerConnections($rServerIDs, $rProxy = false, $rCount = false, $rKeysOnly = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getServerConnections(self::$redis, $rServerIDs, $rProxy, $rCount, $rKeysOnly);
	}
	public static function getFirstConnection($rUserIDs) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getFirstConnection(self::$redis, $rUserIDs);
	}
	public static function getStreamConnections($rStreamIDs, $rGroup = true, $rCount = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getStreamConnections(self::$redis, $rStreamIDs, $rGroup, $rCount);
	}
	public static function getRedisConnections($rUserID = null, $rServerID = null, $rStreamID = null, $rOpenOnly = false, $rCountOnly = false, $rGroup = true, $rHLSOnly = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getRedisConnections(self::$redis, $rUserID, $rServerID, $rStreamID, $rOpenOnly, $rCountOnly, $rGroup, $rHLSOnly);
	}
	public static function getDomainName($rForceSSL = false) {
		return DomainResolver::resolve(self::$rServers, self::$rSettings, SERVER_ID, $rForceSSL, array('CoreUtilities', 'getProxies'), array('CoreUtilities', 'getCache'));
	}
	public static function checkCompatibility($rData) {
		if (!is_array($rData)) {
			$rData = json_decode($rData, true);
		}

		if (!is_array($rData) || !isset($rData['codecs']) || !is_array($rData['codecs'])) {
			return false;
		}

		$audioCodec = $rData['codecs']['audio']['codec_name'] ?? null;
		$videoCodec = $rData['codecs']['video']['codec_name'] ?? null;

		$rAudioCodecs = ['aac', 'libfdk_aac', 'opus', 'vorbis', 'pcm_s16le', 'mp2', 'mp3', 'flac'];
		$rVideoCodecs = ['h264', 'vp8', 'vp9', 'ogg', 'av1'];

		if (self::$rSettings['player_allow_hevc']) {
			$rVideoCodecs[] = 'hevc';
			$rVideoCodecs[] = 'h265';
			$rAudioCodecs[] = 'ac3';
		}

		if (!$videoCodec) {
			return false;
		}

		if (!in_array(strtolower($videoCodec), $rVideoCodecs, true)) {
			return false;
		}

		if ($audioCodec && !in_array(strtolower($audioCodec), $rAudioCodecs, true)) {
			return false;
		}

		return true;
	}

	public static function getNearest($arr, $search) {
		return StreamSorter::getNearest($arr, $search);
	}
	/**
	 * Downloads panel logs from database, formats them and clears the logs table
	 * 
	 * Fetches error logs from database (excluding EPG type), formats them into a structured array,
	 * converts timestamps to human-readable format, and truncates the logs table after successful processing.
	 * Includes error handling and security measures.
	 * 
	 * @static
	 * @return array Structured array containing error logs and system version
	 * @throws Exception If database query fails or date conversion fails
	 */
	public static function downloadPanelLogs(): array {
		// Increase socket timeout for large log files
		ini_set('default_socket_timeout', 60);

		// Initialize empty errors array as fallback
		$errors = [];

		try {
			// Use prepared statement to prevent SQL injection
			$query = "SELECT `type`, `log_message`, `log_extra`, `line`, `date` 
                  FROM `panel_logs` 
                  WHERE `type` <> 'epg' 
                --   GROUP BY `type`, `log_message`, `log_extra` 
                  ORDER BY `date` DESC 
                  LIMIT 1000";

			// Execute query with error handling
			$result = self::$db->query($query);
			if (!$result) {
				throw new Exception('Failed to execute database query');
			}

			// Fetch all rows with type checking
			$allErrors = self::$db->get_rows() ?: [];

			// Process each error record
			foreach ($allErrors as $error) {
				// Validate and sanitize error data
				$errorData = [
					'type' => isset($error['type']) ? htmlspecialchars($error['type'], ENT_QUOTES, 'UTF-8') : 'unknown',
					'message' => isset($error['log_message']) ? htmlspecialchars($error['log_message'], ENT_QUOTES, 'UTF-8') : '',
					'file' => isset($error['log_extra']) ? htmlspecialchars($error['log_extra'], ENT_QUOTES, 'UTF-8') : '',
					'line' => isset($error['line']) ? (int)$error['line'] : 0,
					'date' => isset($error['date']) ? (int)$error['date'] : 0,
				];

				// Convert timestamp to human-readable format with error handling
				try {
					if ($errorData['date'] > 0) {
						$dt = new DateTime('@' . $errorData['date']);
						$dt->setTimezone(new DateTimeZone('UTC'));
						$errorData['human_date'] = $dt->format('Y-m-d H:i:s');
					} else {
						$errorData['human_date'] = 'invalid_timestamp';
					}
				} catch (Exception $e) {
					$errorData['human_date'] = 'conversion_error';
				}

				$errors[] = $errorData;
			}

			// Clear logs only if we successfully processed them
			if (!empty($errors)) {
				$truncateResult = self::$db->query('TRUNCATE `panel_logs`;');
				if (!$truncateResult) {
					throw new Exception('Failed to truncate panel logs table');
				}
			}
		} catch (Exception $e) {
			// Re-throw with generic message for client
			throw new Exception('Failed to process panel logs');
		}

		// Return structured data with version info
		return [
			'errors' => $errors,
			'version' => defined('XC_VM_VERSION') ? XC_VM_VERSION : 'unknown'
		];
	}

	public static function submitPanelLogs() {
		// Increase default socket timeout
		ini_set('default_socket_timeout', 60);
		// Get API IP address
		$apiIP = self::getApiIP();

		if ($apiIP === false) {
			print("[ERR] Failed to get API IP\n");
			return false;
		}

		// Fetch logs from DB
		self::$db->query("SELECT `type`, `log_message`, `log_extra`, `line`, `date` FROM `panel_logs` WHERE `type` <> 'epg' GROUP BY CONCAT(`type`, `log_message`, `log_extra`) ORDER BY `date` DESC LIMIT 1000;");

		// Prepare API endpoint and payload
		$rAPI = 'http://' . $apiIP . '/api/v1/report';
		print("[1] API endpoint: $rAPI\n");

		$rData = [
			'errors'  => self::$db->get_rows(),
			'version' => XC_VM_VERSION
		];

		$payload = json_encode($rData, JSON_UNESCAPED_UNICODE);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rAPI);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);

		// JSON headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($payload)
		]);

		print("[2] Sending request...\n");

		$response = curl_exec($ch);

		// Catch curl errors
		if ($response === false) {
			$err = curl_error($ch);
			print("[ERR] cURL error: $err\n");
		}

		print("[3] Raw response: " . var_export($response, true) . "\n");

		curl_close($ch);
		// Processing JSON response
		if ($response !== false) {
			$responseData = json_decode($response, true);

			// Clear table on success
			if (isset($responseData['status']) && $responseData['status'] === 'success') {
				self::$db->query('TRUNCATE `panel_logs`;');
			}
		}

		return $response;
	}

	public static function getApiIP() {
		$url = 'https://raw.githubusercontent.com/Vateron-Media/XC_VM_Update/refs/heads/main/api_server.json';

		// Get the JSON content from the URL
		$json = file_get_contents($url);
		if ($json === false) {
			return false;
		}

		// Decode the JSON into an associative array
		$data = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE || empty($data['ip'])) {
			return false;
		}
		return $data['ip'];
	}

	public static function confirmIDs($rIDs) {
		$rReturn = array();
		foreach ($rIDs as $rID) {
			if (0 >= intval($rID)) {
			} else {
				$rReturn[] = $rID;
			}
		}
		return $rReturn;
	}
	public static function getTSInfo($rFilename) {
		return json_decode(shell_exec(BIN_PATH . 'tsinfo ' . escapeshellarg($rFilename)), true);
	}
	public static function getEPG($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false) {
		return EpgRepository::getStreamEpg($rStreamID, $rStartDate, $rFinishDate, $rByID);
	}
	public static function getEPGs($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
		return EpgRepository::getStreamsEpg($rStreamIDs, $rStartDate, $rFinishDate);
	}
	public static function getProgramme($rStreamID, $rProgrammeID) {
		return EpgRepository::getProgramme($rStreamID, $rProgrammeID);
	}
	public static function getProxies($rServerID, $rOnline = true) {
		return ConnectionTracker::getProxies(self::$rServers, $rServerID, $rOnline);
	}

	/**
	 * Encodes the input data using base64url encoding.
	 *
	 * This function takes the input data and encodes it using base64 encoding. It then replaces the characters '+' and '/' with '-' and '_', respectively, to make the encoding URL-safe. Finally, it removes any padding '=' characters at the end of the encoded string.
	 *
	 * @param string $rData The input data to be encoded.
	 * @return string The base64url encoded string.
	 */
	public static function base64url_encode($rData) {
		return rtrim(strtr(base64_encode($rData), '+/', '-_'), '=');
	}

	/**
	 * Decodes the input data encoded using base64url encoding.
	 *
	 * This function takes the input data encoded using base64url encoding and decodes it. It first replaces the characters '-' and '_' back to '+' and '/' respectively, to revert the URL-safe encoding. Then, it decodes the base64 encoded string to retrieve the original data.
	 *
	 * @param string $rData The base64url encoded data to be decoded.
	 * @return string|false The decoded original data, or false if decoding fails.
	 */
	public static function base64url_decode($rData) {
		return base64_decode(strtr($rData, '-_', '+/'));
	}

	/**
	 * Encrypts the provided data using AES-256-CBC encryption with a given decryption key and device ID.
	 *
	 * @param string $rData The data to be encrypted.
	 * @param string $decryptionKey The decryption key used to encrypt the data.
	 * @param string $rDeviceID The device ID used in the encryption process.
	 * @return string The encrypted data in base64url encoding.
	 */
	public static function encryptData($rData, $decryptionKey, $rDeviceID) {
		return self::base64url_encode(openssl_encrypt($rData, 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16)));
	}

	/**
	 * Decrypts the provided data using AES-256-CBC decryption with a given decryption key and device ID.
	 *
	 * @param string $rData The data to be decrypted.
	 * @param string $decryptionKey The decryption key used to decrypt the data.
	 * @param string $rDeviceID The device ID used in the decryption process.
	 * @return string The decrypted data.
	 */
	public static function decryptData($rData, $decryptionKey, $rDeviceID) {
		return openssl_decrypt(self::base64url_decode($rData), 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16));
	}

	public static function createBackup($Filename) {
		shell_exec("mysqldump -h 127.0.0.1 -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " --no-data " . self::$rConfig['database'] . " > \"" . $Filename . "\"");
		shell_exec("mysqldump -h 127.0.0.1 -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " --no-create-info --ignore-table xc_vm.detect_restream_logs --ignore-table xc_vm.epg_data --ignore-table xc_vm.lines_activity --ignore-table xc_vm.lines_live --ignore-table xc_vm.lines_logs --ignore-table xc_vm.login_logs --ignore-table xc_vm.mag_claims --ignore-table xc_vm.mag_logs --ignore-table xc_vm.mysql_syslog --ignore-table xc_vm.panel_logs --ignore-table xc_vm.panel_stats --ignore-table xc_vm.servers_stats --ignore-table xc_vm.signals --ignore-table xc_vm.streams_errors --ignore-table xc_vm.streams_logs --ignore-table xc_vm.streams_stats --ignore-table xc_vm.syskill_log --ignore-table xc_vm.users_credits_logs --ignore-table xc_vm.users_logs --ignore-table xc_vm.watch_logs " . self::$rConfig['database'] . " >> \"" . $Filename . "\"");
	}

	public static function restoreBackup($Filename) {
		shell_exec("mysql -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " " . self::$rConfig['database'] . " -e \"DROP DATABASE IF EXISTS xc_vm; CREATE DATABASE IF NOT EXISTS xc_vm;\"");
		shell_exec("mysql -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " " . self::$rConfig['database'] . " < \"" . $Filename . "\" > /dev/null 2>/dev/null &");
		shell_exec("mysqldump -h 127.0.0.1 -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " --no-data " . self::$rConfig['database'] . " > \"" . $Filename . "\"");
		shell_exec("mysqldump -h 127.0.0.1 -u " . self::$rConfig['username'] . " -p" . self::$rConfig['password'] . " -P " . self::$rConfig['port'] . " --no-create-info --ignore-table xc_vm.detect_restream_logs --ignore-table xc_vm.epg_data --ignore-table xc_vm.lines_activity --ignore-table xc_vm.lines_live --ignore-table xc_vm.lines_logs --ignore-table xc_vm.login_logs --ignore-table xc_vm.mag_claims --ignore-table xc_vm.mag_logs --ignore-table xc_vm.mysql_syslog --ignore-table xc_vm.panel_logs --ignore-table xc_vm.panel_stats --ignore-table xc_vm.servers_stats --ignore-table xc_vm.signals --ignore-table xc_vm.streams_errors --ignore-table xc_vm.streams_logs --ignore-table xc_vm.streams_stats --ignore-table xc_vm.syskill_log --ignore-table xc_vm.users_credits_logs --ignore-table xc_vm.users_logs --ignore-table xc_vm.watch_logs " . self::$rConfig['database'] . " >> \"" . $Filename . "\"");
	}

	public static function grantPrivileges($Host) {
		self::$db->query("GRANT SELECT, INSERT, UPDATE, DELETE, DROP, ALTER ON `" . self::$rConfig['database'] . "`.* TO '" . self::$rConfig['username'] . "'@'" . $Host . "' IDENTIFIED BY '" . self::$rConfig['password'] . "';");
	}

	public static function revokePrivileges($Host) {
		self::$db->query("REVOKE ALL PRIVILEGES ON `" . self::$rConfig['database'] . "`.* FROM '" . self::$rConfig['username'] . "'@'" . $Host . "';");
	}


	/**
	 * Retrieves a valid Plex authentication token for a given server.
	 * The method follows a multi-step approach:
	 *  1. Try to get a cached token from the filesystem.
	 *  2. Validate the cached token against the Plex server.
	 *  3. If no valid token is found, authenticate via plex.tv and cache the new token.
	 *
	 * @param string|null $plexIP       The IP address of the Plex Media Server (e.g. 192.168.1.100)
	 * @param int|null    $plexPort     The port of the Plex Media Server (usually 32400)
	 * @param string|null $plexUsername Plex account username (email or username)
	 * @param string|null $plexPassword Plex account password
	 *
	 * @return string|false The valid Plex token or false on failure
	 */
	public static function getPlexToken($plexIP = null, $plexPort = null, $plexUsername = null, $plexPassword = null) {
		// Generate a unique cache key based on connection details and credentials
		$serverKey = self::getPlexServerCacheKey($plexIP, $plexPort, $plexUsername, $plexPassword);

		// 1. Try to retrieve token from file cache
		$rToken = self::getCachedPlexToken($serverKey);
		if ($rToken) {
			// Even if cached, verify that the token is still valid on the server
			$rToken = self::checkPlexToken($plexIP, $plexPort, $rToken);
		}

		// 2. If no valid token yet – perform a fresh login via plex.tv
		if (!$rToken) {
			echo "Plex token not found in cache or invalid, logging in for server {$plexIP}:{$plexPort}...\n";

			$rData = self::getPlexLogin($plexUsername, $plexPassword);

			if (isset($rData['user']['authToken'])) {
				// Validate the freshly obtained token against the local server
				$rToken = self::checkPlexToken($plexIP, $plexPort, $rData['user']['authToken']);

				if ($rToken) {
					// Cache the working token for future use
					self::cachePlexToken($serverKey, $rToken);
					echo "New Plex token successfully cached for key: $serverKey\n";
				}
			} else {
				echo "Failed to login to Plex (wrong credentials or network issue)!\n";
				$rToken = false;
			}
		}

		return $rToken;
	}

	/**
	 * Generates a unique cache key for a Plex server + credentials combination.
	 *
	 * @param string      $ip        Server IP address
	 * @param int         $port      Server port
	 * @param string|null $username  Plex username (optional)
	 * @param string|null $password  Plex password (optional)
	 *
	 * @return string MD5 hash used as cache filename
	 */
	public static function getPlexServerCacheKey($ip, $port, $username = null, $password = null) {
		// Include credentials in the hash when provided – allows multiple accounts on same server
		if ($username && $password) {
			return md5($ip . ':' . $port . ':' . $username . ':' . $password);
		}

		return md5($ip . ':' . $port);
	}

	/**
	 * Loads a cached Plex token from the filesystem.
	 *
	 * @param string $serverKey Unique key generated by getPlexServerCacheKey()
	 *
	 * @return string|null Token string if valid and not near expiry, otherwise null
	 */
	public static function getCachedPlexToken($serverKey) {
		$cacheFile = CONFIG_PATH . 'plex/plex_token_' . $serverKey . '.json';

		if (!file_exists($cacheFile)) {
			return null;
		}

		$data = json_decode(file_get_contents($cacheFile), true);

		// Validate cache structure
		if (!$data || !isset($data['token']) || !isset($data['expires'])) {
			return null;
		}

		// If token will expire within the next 24 hours – treat it as expired and refresh
		if ($data['expires'] < time() + 86400) {
			@unlink($cacheFile); // Clean up almost-expired cache file
			return null;
		}

		return $data['token'];
	}

	/**
	 * Authenticates against plex.tv to obtain a global Plex authentication token.
	 *
	 * @param string $rUsername Plex account username/email
	 * @param string $rPassword Plex account password
	 *
	 *
	 * @return array Response array from plex.tv (decoded JSON)
	 */
	public static function getPlexLogin($rUsername, $rPassword) {
		$headers = [
			'Content-Type: application/xml; charset=utf-8',
			'X-Plex-Client-Identifier: 526e163c-8dbd-11eb-8dcd-0242ac130003',
			'X-Plex-Product: XC_VM',
			'X-Plex-Version: v' . XC_VM_VERSION
		];

		$ch = curl_init('https://plex.tv/users/sign_in.json');
		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_HEADER         => false,
			CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
			CURLOPT_USERPWD        => $rUsername . ':' . $rPassword,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => false, // Note: consider enabling in production
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
		]);

		$response = curl_exec($ch);
		curl_close($ch);

		return json_decode($response, true);
	}

	/**
	 * Verifies whether a given token is accepted by the local Plex Media Server.
	 *
	 * @param string $rIP    Server IP
	 * @param int    $rPort  Server port
	 * @param string $rToken Candidate Plex token
	 *
	 * @return string The same token if valid, empty string otherwise
	 */
	public static function checkPlexToken($rIP, $rPort, $rToken) {
		$checkURL = 'http://' . $rIP . ':' . $rPort . '/myplex/account?X-Plex-Token=' . $rToken;

		$ch = curl_init($checkURL);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => false, // Consider enabling with proper certs
		]);

		$data = curl_exec($ch);
		curl_close($ch);

		// Plex returns XML – convert to array for easy attribute access
		$xml = simplexml_load_string($data);
		if ($xml === false) {
			return '';
		}

		$json = json_decode(json_encode($xml), true);

		return (isset($json['@attributes']['signInState']) && $json['@attributes']['signInState'] === 'ok')
			? $rToken
			: '';
	}

	/**
	 * Stores a valid Plex token in the filesystem cache.
	 *
	 * @param string $serverKey Unique cache key
	 * @param string $token     Valid Plex authentication token
	 *
	 * @return void
	 */
	public static function cachePlexToken($serverKey, $token) {
		$cacheFile = CONFIG_PATH . 'plex/plex_token_' . $serverKey . '.json';

		$data = [
			'token'     => $token,
			'cached_at' => time(),
			// Plex tokens are generally valid for months; 30 days is a safe conservative value
			'expires'   => time() + 30 * 86400
		];

		// Ensure the directory exists
		if (!is_dir(dirname($cacheFile))) {
			mkdir(dirname($cacheFile), 0755, true);
		}

		file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
		@chmod($cacheFile, 0600); // Restrict permissions – contains sensitive token
	}
}
