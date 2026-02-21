<?php
class StreamingUtilities {
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
	public static $rCategories = array();
	public static $rProxies = array();
	public static $rFFMPEG_CPU = null;
	public static $rFFMPEG_GPU = null;
	public static $rCached = null;
	public static $rAccess = null;
	public static function init() {
		LegacyInitializer::initStreaming();
	}
	public static function isCacheEnabledAndComplete() {
		if (!self::$rSettings['enable_cache']) {
			return false;
		}
		return file_exists(CACHE_TMP_PATH . 'cache_complete');
	}
	public static function connectDatabase() {
		$_INFO = array();

		if (file_exists(MAIN_HOME . 'config')) {
			$_INFO = parse_ini_file(CONFIG_PATH . 'config.ini');
		} else {
			die('no config found');
		}

		self::$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	}
	public static function closeDatabase() {
		if (self::$db) {
			self::$db->close_mysql();
			self::$db = null;
		}
	}
	public static function getCache($rCache) {
		$rData = (file_get_contents(CACHE_TMP_PATH . $rCache) ?: null);
		return igbinary_unserialize($rData);
	}
	public static function mc_decrypt($rData, $rKey) {
		$rData = explode('|', $rData . '|');
		$rDecoded = base64_decode($rData[0]);
		$rIV = base64_decode($rData[1]);
		if (strlen($rIV) === mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC)) {
			$rKey = pack('H*', $rKey);
			$rDecrypted = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $rKey, $rDecoded, MCRYPT_MODE_CBC, $rIV));
			$rMAC = substr($rDecrypted, -64);
			$rDecrypted = substr($rDecrypted, 0, -64);
			$rCalcHMAC = hash_hmac('sha256', $rDecrypted, substr(bin2hex($rKey), -32));
			if ($rCalcHMAC === $rMAC) {
				$rDecrypted = unserialize($rDecrypted);
				return $rDecrypted;
			}
			return false;
		}
		return false;
	}
	public static function cleanGlobals(&$rData, $rIteration = 0) {
		if (10 > $rIteration) {
			foreach ($rData as $rKey => $rValue) {
				if (is_array($rValue)) {
					self::cleanGlobals($rData[$rKey], ++$rIteration);
				} else {
					$rValue = str_replace(chr('0'), '', $rValue);
					$rValue = str_replace("\x0", '', $rValue);
					$rValue = str_replace("\x0", '', $rValue);
					$rValue = str_replace('../', '&#46;&#46;/', $rValue);
					$rValue = str_replace('&#8238;', '', $rValue);
					$rData[$rKey] = $rValue;
				}
			}
		} else {
			return null;
		}
	}
	public static function parseIncomingRecursively(&$rData, $rInput = array(), $rIteration = 0) {
		if (20 > $rIteration) {
			if (is_array($rData)) {
				foreach ($rData as $rKey => $rValue) {
					if (is_array($rValue)) {
						$rInput[$rKey] = self::parseIncomingRecursively($rData[$rKey], array(), $rIteration + 1);
					} else {
						$rKey = self::parseCleanKey($rKey);
						$rValue = self::parseCleanValue($rValue);
						$rInput[$rKey] = $rValue;
					}
				}
				return $rInput;
			} else {
				return $rInput;
			}
		} else {
			return $rInput;
		}
	}
	public static function parseCleanKey($rKey) {
		if ($rKey !== '') {
			$rKey = htmlspecialchars(urldecode($rKey));
			$rKey = str_replace('..', '', $rKey);
			$rKey = preg_replace('/\\_\\_(.+?)\\_\\_/', '', $rKey);
			$rKey = preg_replace('/^([\\w\\.\\-\\_]+)$/', '$1', $rKey);
			return $rKey;
		}
		return '';
	}
	public static function parseCleanValue($rValue) {
		if ($rValue != '') {
			$rValue = str_replace(array("\r\n", "\n\r", "\r"), "\n", $rValue);
			$rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
			$rValue = str_replace('-->', '--&#62;', $rValue);
			$rValue = str_ireplace('<script', '&#60;script', $rValue);
			$rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
			$rValue = preg_replace('/&#(\\d+?)([^\\d;])/i', '&#\\1;\\2', $rValue);
			return trim($rValue);
		}
		return '';
	}
	/** @deprecated Use BruteforceGuard::checkFlood() */
	public static function checkFlood($rIP = null) {
		return BruteforceGuard::checkFlood($rIP, !empty(self::$rCached));
	}
	/** @deprecated Use BruteforceGuard::checkBruteforce() */
	public static function checkBruteforce($rIP = null, $rMAC = null, $rUsername = null) {
		return BruteforceGuard::checkBruteforce($rIP, $rMAC, $rUsername, !empty(self::$rCached));
	}
	/** @deprecated Use BruteforceGuard::checkAuthFlood() */
	public static function checkAuthFlood($rUser, $rIP = null) {
		return BruteforceGuard::checkAuthFlood($rUser, $rIP);
	}
	public static function isProxied($rServerID) {
		return self::$rServers[$rServerID]['enable_proxy'];
	}
	public static function isProxy($rIP) {
		if (!isset(self::$rProxies[$rIP])) {
		} else {
			return self::$rProxies[$rIP];
		}
	}
	/** @deprecated Use BruteforceGuard::truncateAttempts() */
	public static function truncateAttempts($rAttempts, $rFrequency, $rList = false) {
		return BruteforceGuard::truncateAttempts($rAttempts, $rFrequency, $rList);
	}
	public static function getCapacity($rProxy = false) {
		if (!self::$rCached && self::$rSettings['redis_handler'] && !is_object(self::$redis)) {
			self::connectRedis();
		}
		if (self::$rCached) {
			return json_decode(file_get_contents(CACHE_TMP_PATH . (($rProxy ? 'proxy_capacity' : 'servers_capacity'))), true);
		}
		return ConnectionTracker::getCapacity(self::$rSettings, self::$rServers, self::$redis, self::$db, $rProxy);
	}
	public static function redirectStream($rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserISP = '', $rType = '') {
		if (self::$rCached) {
			$rStream = (igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamID)) ?: null);
			$rStream['bouquets'] = self::getBouquetMap($rStreamID);
		} else {
			$rStream = self::getStreamData($rStreamID);
		}
		if ($rStream) {
			$rStream['info']['bouquets'] = $rStream['bouquets'];
			$rAvailableServers = array();
			if ($rType == 'archive') {
				if (!(0 < $rStream['info']['tv_archive_duration'] && 0 < $rStream['info']['tv_archive_server_id'] && array_key_exists($rStream['info']['tv_archive_server_id'], self::$rServers))) {
				} else {
					$rAvailableServers = array($rStream['info']['tv_archive_server_id']);
				}
			} else {
				if (!($rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 0)) {
					foreach (self::$rServers as $rServerID => $rServerInfo) {
						if (!(!array_key_exists($rServerID, $rStream['servers']) || !$rServerInfo['server_online'] || $rServerInfo['server_type'] != 0)) {
							if (!isset($rStream['servers'][$rServerID])) {
							} else {
								if ($rType == 'movie') {
									if (!((!empty($rStream['servers'][$rServerID]['pid']) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && $rStream['servers'][$rServerID]['stream_status'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1) && ($rStream['info']['target_container'] == $rExtension || $rExtension == 'srt' || $rExtension == 'm3u8' || $rExtension == 'ts') && $rServerInfo['timeshift_only'] == 0)) {
									} else {
										$rAvailableServers[] = $rServerID;
									}
								} else {
									if (!(($rStream['servers'][$rServerID]['on_demand'] == 1 && $rStream['servers'][$rServerID]['stream_status'] != 1 || 0 < $rStream['servers'][$rServerID]['pid'] && $rStream['servers'][$rServerID]['stream_status'] == 0) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && (int) $rStream['servers'][$rServerID]['delay_available_at'] <= time() && $rServerInfo['timeshift_only'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1)) {
									} else {
										$rAvailableServers[] = $rServerID;
									}
								}
							}
						}
					}
				} else {
					header('Location: ' . str_replace(' ', '%20', json_decode($rStream['info']['stream_source'], true)[0]));
					exit();
				}
			}
			if (!empty($rAvailableServers)) {
				shuffle($rAvailableServers);
				$rServerCapacity = self::getCapacity();
				$rAcceptServers = array();
				foreach ($rAvailableServers as $rServerID) {
					$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
					if ($rOnlineClients != 0) {
					} else {
						$rServerCapacity[$rServerID]['capacity'] = 0;
					}
					$rAcceptServers[$rServerID] = (0 < self::$rServers[$rServerID]['total_clients'] && $rOnlineClients < self::$rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
				}
				$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
				if (empty($rAcceptServers)) {
					if ($rType == 'archive') {
						return null;
					}
					return array();
				}
				$rKeys = array_keys($rAcceptServers);
				$rValues = array_values($rAcceptServers);
				array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
				$rAcceptServers = array_combine($rKeys, $rValues);
				if ($rExtension == 'rtmp' && array_key_exists(SERVER_ID, $rAcceptServers)) {
					$rRedirectID = SERVER_ID;
				} else {
					if (isset($rUserInfo) && $rUserInfo['force_server_id'] != 0 && array_key_exists($rUserInfo['force_server_id'], $rAcceptServers)) {
						$rRedirectID = $rUserInfo['force_server_id'];
					} else {
						$rPriorityServers = array();
						foreach (array_keys($rAcceptServers) as $rServerID) {
							if (self::$rServers[$rServerID]['enable_geoip'] == 1) {
								if (in_array($rCountryCode, self::$rServers[$rServerID]['geoip_countries'])) {
									$rRedirectID = $rServerID;
									break;
								}
								if (self::$rServers[$rServerID]['geoip_type'] == 'strict') {
									unset($rAcceptServers[$rServerID]);
								} else {
									if (isset($rStream) && !self::$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
										$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['geoip_type'] == 'low_priority' ? 3 : 2);
									} else {
										$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['geoip_type'] == 'low_priority' ? 2 : 1);
									}
								}
							} else {
								if (self::$rServers[$rServerID]['enable_isp'] == 1) {
									if (in_array(strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rUserISP))), self::$rServers[$rServerID]['isp_names'])) {
										$rRedirectID = $rServerID;
										break;
									}
									if (self::$rServers[$rServerID]['isp_type'] == 'strict') {
										unset($rAcceptServers[$rServerID]);
									} else {
										if (isset($rStream) && !self::$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
											$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['isp_type'] == 'low_priority' ? 3 : 2);
										} else {
											$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['isp_type'] == 'low_priority' ? 2 : 1);
										}
									}
								} else {
									if (isset($rStream) && !self::$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
										$rPriorityServers[$rServerID] = 2;
									} else {
										$rPriorityServers[$rServerID] = 1;
									}
								}
							}
						}
						if (!(empty($rPriorityServers) && empty($rRedirectID))) {
							$rRedirectID = (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
						} else {
							return false;
						}
					}
				}
				if ($rType == 'archive') {
					return $rRedirectID;
				}
				$rStream['info']['redirect_id'] = $rRedirectID;
				$fc4c58c5d1cd68d1 = $rRedirectID;
				return array_merge($rStream['info'], $rStream['servers'][$fc4c58c5d1cd68d1]);
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	public static function getOffAirVideo($Fd50c63671da34f8) {
		if (!(isset(self::$rSettings[$Fd50c63671da34f8]) && 0 < strlen(self::$rSettings[$Fd50c63671da34f8]))) {
			switch ($Fd50c63671da34f8) {
				case 'connected_video_path':
					if (!file_exists(VIDEO_PATH . 'connected.ts')) {
						break;
					}
					return VIDEO_PATH . 'connected.ts';
				case 'expired_video_path':
					if (!file_exists(VIDEO_PATH . 'expired.ts')) {
						break;
					}
					return VIDEO_PATH . 'expired.ts';
				case 'banned_video_path':
					if (!file_exists(VIDEO_PATH . 'banned.ts')) {
						break;
					}
					return VIDEO_PATH . 'banned.ts';
				case 'not_on_air_video_path':
					if (!file_exists(VIDEO_PATH . 'offline.ts')) {
						break;
					}
					return VIDEO_PATH . 'offline.ts';
				case 'expiring_video_path':
					if (!file_exists(VIDEO_PATH . 'expiring.ts')) {
						break;
					}
					return VIDEO_PATH . 'expiring.ts';
			}
		} else {
			return self::$rSettings[$Fd50c63671da34f8];
		}
	}
	public static function showVideoServer($Fca476d6a870416e, $Fd50c63671da34f8, $rExtension, $rUserInfo, $rIP, $rCountryCode, $rISP, $rServerID = null, $rProxyID = null) {
		$Fd50c63671da34f8 = self::getOffAirVideo($Fd50c63671da34f8);
		if (!(!$rUserInfo['is_restreamer'] && self::$rSettings[$Fca476d6a870416e] && 0 < strlen($Fd50c63671da34f8))) {
			switch ($Fca476d6a870416e) {
				case 'show_expired_video':
					generateError('EXPIRED');
					break;
				case 'show_banned_video':
					generateError('BANNED');
					break;
				case 'show_not_on_air_video':
					generateError('STREAM_OFFLINE');
					break;
				default:
					generate404();
					break;
			}
		} else {
			if (!$rServerID) {
				$rServerID = self::F4221e28760b623E($rUserInfo, $rIP, $rCountryCode, $rISP);
			}
			if (!$rServerID) {
				$rServerID = SERVER_ID;
			}
			$rOriginatorID = null;
			if (self::isProxied($rServerID) && (!$rUserInfo['is_restreamer'] || !self::$rSettings['restreamer_bypass_proxy'])) {
				$rProxies = self::getProxies($rServerID);
				$rProxyID = self::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);
				if (!$rProxyID) {
					generate404();
				}
				$rOriginatorID = $rServerID;
				$rServerID = $rProxyID;
			}
			if (self::$rServers[$rServerID]['random_ip'] && 0 < count(self::$rServers[$rServerID]['domains']['urls'])) {
				$rURL = self::$rServers[$rServerID]['domains']['protocol'] . '://' . self::$rServers[$rServerID]['domains']['urls'][array_rand(self::$rServers[$rServerID]['domains']['urls'])] . ':' . self::$rServers[$rServerID]['domains']['port'];
			} else {
				$rURL = rtrim(self::$rServers[$rServerID]['site_url'], '/');
			}
			if (!$rOriginatorID || self::$rServers[$rOriginatorID]['is_main']) {
			} else {
				$rURL .= '/' . md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA);
			}
			$rTokenData = array('expires' => time() + 10, 'video_path' => $Fd50c63671da34f8);
			$rToken = StreamingUtilities::encryptData(json_encode($rTokenData), self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			if ($rExtension == 'm3u8') {
				$segmentDuration = 10;
				$sequence = intval(time() / $segmentDuration);
				$rM3U8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:{$sequence}\n#EXT-X-ALLOW-CACHE:NO\n#EXT-X-TARGETDURATION:{$segmentDuration}\n#EXT-X-PLAYLIST-TYPE:EVENT\n";

				// Create 3 segments
				for ($i = 0; $i < 3; $i++) {
					$rM3U8 .= "#EXTINF:{$segmentDuration}.0,\n" . $rURL . '/auth/' . $rToken  . "\n";
				}

				header('Content-Type: application/x-mpegurl');
				header('Content-Length: ' . strlen($rM3U8));
				echo $rM3U8;
				exit();
			}
			header('Location: ' . $rURL . '/auth/' . $rToken);
			exit();
		}
	}
	public static function F4221e28760B623E($rUserInfo, $rUserIP, $rCountryCode, $rUserISP = '') {
		$rAvailableServers = array();
		foreach (self::$rServers as $rServerID => $rServerInfo) {
			if ($rServerInfo['server_online'] && $rServerInfo['server_type'] == 0) {
				$rAvailableServers[] = $rServerID;
			}
		}
		if (!empty($rAvailableServers)) {
			shuffle($rAvailableServers);
			$rServerCapacity = self::getCapacity();
			$rAcceptServers = array();
			foreach ($rAvailableServers as $rServerID) {
				$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
				if ($rOnlineClients != 0) {
				} else {
					$rServerCapacity[$rServerID]['capacity'] = 0;
				}
				$rAcceptServers[$rServerID] = (0 < self::$rServers[$rServerID]['total_clients'] && $rOnlineClients < self::$rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
			}
			$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
			if (empty($rAcceptServers)) {
				return false;
			}
			$rKeys = array_keys($rAcceptServers);
			$rValues = array_values($rAcceptServers);
			array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
			$rAcceptServers = array_combine($rKeys, $rValues);
			if ($rUserInfo['force_server_id'] != 0 && array_key_exists($rUserInfo['force_server_id'], $rAcceptServers)) {
				$rRedirectID = $rUserInfo['force_server_id'];
			} else {
				$rPriorityServers = array();
				foreach (array_keys($rAcceptServers) as $rServerID) {
					if (self::$rServers[$rServerID]['enable_geoip'] == 1) {
						if (in_array($rCountryCode, self::$rServers[$rServerID]['geoip_countries'])) {
							$rRedirectID = $rServerID;
							break;
						}
						if (self::$rServers[$rServerID]['geoip_type'] == 'strict') {
							unset($rAcceptServers[$rServerID]);
						} else {
							$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['geoip_type'] == 'low_priority' ? 1 : 2);
						}
					} else {
						if (self::$rServers[$rServerID]['enable_isp'] == 1) {
							if (in_array($rUserISP, self::$rServers[$rServerID]['isp_names'])) {
								$rRedirectID = $rServerID;
								break;
							}
							if (self::$rServers[$rServerID]['isp_type'] == 'strict') {
								unset($rAcceptServers[$rServerID]);
							} else {
								$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['isp_type'] == 'low_priority' ? 1 : 2);
							}
						} else {
							$rPriorityServers[$rServerID] = 1;
						}
					}
				}
				if (!(empty($rPriorityServers) && empty($rRedirectID))) {
					$rRedirectID = (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
				} else {
					return false;
				}
			}
			return $rRedirectID;
		} else {
			return false;
		}
	}
	public static function availableProxy($rProxies, $rCountryCode, $rUserISP = '') {
		if (!empty($rProxies)) {
			$rServerCapacity = self::getCapacity(true);
			$rAcceptServers = array();
			foreach ($rProxies as $rServerID) {
				$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
				if ($rOnlineClients != 0) {
				} else {
					$rServerCapacity[$rServerID]['capacity'] = 0;
				}
				$rAcceptServers[$rServerID] = (0 < self::$rServers[$rServerID]['total_clients'] && $rOnlineClients < self::$rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
			}
			$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
			if (empty($rAcceptServers)) {
				return null;
			}
			$rKeys = array_keys($rAcceptServers);
			$rValues = array_values($rAcceptServers);
			array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
			$rAcceptServers = array_combine($rKeys, $rValues);
			$rPriorityServers = array();
			foreach (array_keys($rAcceptServers) as $rServerID) {
				if (self::$rServers[$rServerID]['enable_geoip'] == 1) {
					if (in_array($rCountryCode, self::$rServers[$rServerID]['geoip_countries'])) {
						$rRedirectID = $rServerID;
						break;
					}
					if (self::$rServers[$rServerID]['geoip_type'] == 'strict') {
						unset($rAcceptServers[$rServerID]);
					} else {
						$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['geoip_type'] == 'low_priority' ? 1 : 2);
					}
				} else {
					if (self::$rServers[$rServerID]['enable_isp'] == 1) {
						if (in_array($rUserISP, self::$rServers[$rServerID]['isp_names'])) {
							$rRedirectID = $rServerID;
							break;
						}
						if (self::$rServers[$rServerID]['isp_type'] == 'strict') {
							unset($rAcceptServers[$rServerID]);
						} else {
							$rPriorityServers[$rServerID] = (self::$rServers[$rServerID]['isp_type'] == 'low_priority' ? 1 : 2);
						}
					} else {
						$rPriorityServers[$rServerID] = 1;
					}
				}
			}
			if (!(empty($rPriorityServers) && empty($rRedirectID))) {
				$rRedirectID = (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
				return $rRedirectID;
			}
			return null;
		} else {
			return null;
		}
	}
	public static function closeConnections($rUserID, $rMaxConnections, $rIsHMAC = null, $rIdentifier = '', $rIP = null, $rUserAgent = null) {
		if (self::$rSettings['redis_handler']) {
			$rConnections = array();
			$rKeys = self::getConnections($rUserID, true, true);
			$rToKill = count($rKeys) - $rMaxConnections;
			if ($rToKill > 0) {
				foreach (array_map('igbinary_unserialize', self::$redis->mGet($rKeys)) as $rConnection) {
					if (!is_array($rConnection)) {
					} else {
						$rConnections[] = $rConnection;
					}
				}
				unset($rKeys);
				$rDate = array_column($rConnections, 'date_start');
				array_multisort($rDate, SORT_ASC, $rConnections);
			} else {
				return null;
			}
		} else {
			if ($rIsHMAC) {
				self::$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`hmac_id` = ? AND `lines_live`.`hls_end` = 0 AND `lines_live`.`hmac_identifier` = ? ORDER BY `lines_live`.`activity_id` ASC', $rIsHMAC, $rIdentifier);
			} else {
				self::$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`user_id` = ? AND `lines_live`.`hls_end` = 0 ORDER BY `lines_live`.`activity_id` ASC', $rUserID);
			}
			$rConnectionCount = self::$db->num_rows();
			$rToKill = $rConnectionCount - $rMaxConnections;
			if ($rToKill > 0) {
				$rConnections = self::$db->get_rows();
			} else {
				return null;
			}
		}
		$rIP = self::getUserIP();
		$rKilled = 0;
		$rDelSID = $rDelUUID = $rIDs = array();
		if ($rIP && $rUserAgent) {
			$rKillTypes = array(2, 1, 0);
		} else {
			if ($rIP) {
				$rKillTypes = array(1, 0);
			} else {
				$rKillTypes = array(0);
			}
		}
		foreach ($rKillTypes as $rKillOwnIP) {
			$i = 0;
			while ($i < count($rConnections) && $rKilled < $rToKill) {
				if ($rKilled != $rToKill) {
					if ($rConnections[$i]['pid'] != getmypid()) {
						if (!($rConnections[$i]['user_ip'] == $rIP && $rConnections[$i]['user_agent'] == $rUserAgent && $rKillOwnIP == 2 || $rConnections[$i]['user_ip'] == $rIP && $rKillOwnIP == 1 || $rKillOwnIP == 0)) {
						} else {
							if (!self::closeConnection($rConnections[$i])) {
							} else {
								$rKilled++;
								if ($rConnections[$i]['container'] == 'hls') {
								} else {
									if (self::$rSettings['redis_handler']) {
										$rIDs[] = $rConnections[$i];
									} else {
										$rIDs[] = intval($rConnections[$i]['activity_id']);
									}
									$rDelUUID[] = $rConnections[$i]['uuid'];
									$rDelSID[$rConnections[$i]['stream_id']][] = $rDelUUID;
								}
								if (!($rConnections[$i]['on_demand'] && $rConnections[$i]['server_id'] == SERVER_ID && self::$rSettings['on_demand_instant_off'])) {
								} else {
									self::removeFromQueue($rConnections[$i]['stream_id'], $rConnections[$i]['pid']);
								}
							}
						}
					}
					$i++;
				} else {
					break;
				}
			}
		}
		if (empty($rIDs)) {
		} else {
			if (self::$rSettings['redis_handler']) {
				$rUUIDs = array();
				$rRedis = self::$redis->multi();
				foreach ($rIDs as $rConnection) {
					$rRedis->zRem('LINE#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('LINE_ALL#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('STREAM#' . $rConnection['stream_id'], $rConnection['uuid']);
					$rRedis->zRem('SERVER#' . $rConnection['server_id'], $rConnection['uuid']);
					if (!$rConnection['user_id']) {
					} else {
						$rRedis->zRem('SERVER_LINES#' . $rConnection['server_id'], $rConnection['uuid']);
					}
					if (!$rConnection['proxy_id']) {
					} else {
						$rRedis->zRem('PROXY#' . $rConnection['proxy_id'], $rConnection['uuid']);
					}
					$rRedis->del($rConnection['uuid']);
					$rUUIDs[] = $rConnection['uuid'];
				}
				$rRedis->zRem('CONNECTIONS', ...$rUUIDs);
				$rRedis->zRem('LIVE', ...$rUUIDs);
				$rRedis->sRem('ENDED', ...$rUUIDs);
				$rRedis->exec();
			} else {
				self::$db->query('DELETE FROM `lines_live` WHERE `activity_id` IN (' . implode(',', array_map('intval', $rIDs)) . ')');
			}
			foreach ($rDelUUID as $rUUID) {
				@unlink(CONS_TMP_PATH . $rUUID);
			}
			foreach ($rDelSID as $rStreamID => $rUUIDs) {
				foreach ($rUUIDs as $rUUID) {
					@unlink(CONS_TMP_PATH . $rStreamID . '/' . $rUUID);
				}
			}
		}
		return $rKilled;
	}
	public static function closeConnection($rActivityInfo) {
		if (!empty($rActivityInfo)) {
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
					if ($rActivityInfo['container'] == 'hls' || $rActivityInfo['container'] == 'm3u8') {
						if (self::$rSettings['redis_handler']) {
							self::updateConnection($rActivityInfo, array(), 'close');
						} else {
							self::$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
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
				self::writeOfflineActivity($rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'], $rActivityInfo['divergence'], $rActivityInfo['hmac_id'], $rActivityInfo['hmac_identifier']);
				return true;
			}
			return false;
		}
		return false;
	}
	public static function closeRTMP($rPID) {
		if (!empty($rPID)) {
			self::$db->query("SELECT * FROM `lines_live` WHERE `container` = 'rtmp' AND `pid` = ? AND `server_id` = ?", $rPID, SERVER_ID);
			if (0 >= self::$db->num_rows()) {
				return false;
			}
			$rActivityInfo = self::$db->get_row();
			self::$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
			self::writeOfflineActivity($rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'], $rActivityInfo['divergence'], $rActivityInfo['hmac_id'], $rActivityInfo['hmac_identifier']);
			return true;
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
	public static function getAllowedRTMP() {
		$rReturn = array();
		self::$db->query('SELECT `ip`, `password`, `push`, `pull` FROM `rtmp_ips`');
		foreach (self::$db->get_rows() as $rRow) {
			$rReturn[gethostbyname($rRow['ip'])] = array('password' => $rRow['password'], 'push' => boolval($rRow['push']), 'pull' => boolval($rRow['pull']));
		}
		return $rReturn;
	}
	public static function canWatch($rStreamID, $rIDs = array(), $rType = 'movie') {
		if ($rType == 'movie') {
			return in_array($rStreamID, $rIDs);
		}
		if ($rType != 'series') {
		} else {
			if (self::$rCached) {
				$rSeries = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_map'));
				return in_array($rSeries[$rStreamID], $rIDs);
			}
			self::$db->query('SELECT series_id FROM `streams_episodes` WHERE `stream_id` = ? LIMIT 1', $rStreamID);
			if (0 >= self::$db->num_rows()) {
			} else {
				return in_array(self::$db->get_col(), $rIDs);
			}
		}
		return false;
	}
	public static function getUserInfo($rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '') {
		return UserRepository::getStreamingUserInfo(self::$db, self::$rSettings, self::$rCached, self::$rBouquets, $rUserID, $rUsername, $rPassword, $rGetChannelIDs, $rGetConnections, $rIP, array('getIPInfo' => array(self::class, 'getIPInfo'), 'setSignal' => array(self::class, 'setSignal'), 'getISP' => array(self::class, 'getISP'), 'checkISP' => array(self::class, 'checkISP'), 'checkServer' => array(self::class, 'checkServer')));
	}
	public static function setSignal($rKey, $rData) {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
	}
	public static function validateHMAC($rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0) {
		return HMACValidator::validate(self::$db, self::$rSettings, self::$rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP, $rMACIP, $rIdentifier, $rMaxConnections, array('StreamingUtilities', 'decryptData'));
	}
	public static function checkBlockedUAs($rUserAgent, $rReturn = false) {
		return BlocklistService::checkBlockedUAs(self::$rBlockedUA, $rUserAgent, $rReturn);
	}
	public static function isMonitorRunning($rPID, $rStreamID, $rEXE = PHP_BIN) {
		if (!empty($rPID)) {
			static $procCache = [];
			$now = microtime(true);
			$cacheKey = (int)$rPID;
			if (isset($procCache[$cacheKey]) && $now - $procCache[$cacheKey]['time'] < 1.0) {
				$procExists = $procCache[$cacheKey]['exists'];
			} else {
				$procExists = file_exists('/proc/' . $rPID);
				$procCache[$cacheKey] = ['exists' => $procExists, 'time' => $now];
			}

			if ($procExists && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(@readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0) {
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
	public static function isStreamRunning($pid, $streamID) {
		if ($pid <= 1 || !is_int($pid)) {
			return false;
		}

		$procDir = "/proc/$pid";
		static $procCache = [];
		$now = microtime(true);
		$cacheKey = (int)$pid;
		if (isset($procCache[$cacheKey]) && $now - $procCache[$cacheKey]['time'] < 1.0) {
			if (!$procCache[$cacheKey]['exists']) {
				return false;
			}
		} else {
			$exists = file_exists($procDir);
			$procCache[$cacheKey] = ['exists' => $exists, 'time' => $now];
			if (!$exists) {
				return false;
			}
		}

		// Optional check: verify that the exe link exists
		$exeLink = $procDir . '/exe';
		if (!is_link($exeLink)) {
			return false;
		}

		static $cache = [];
		$cacheKey = $pid . '|' . $streamID;
		if (isset($cache[$cacheKey]) && $cache[$cacheKey]['time'] > time() - 4) {
			return $cache[$cacheKey]['alive'];
		}

		$cmd = @file_get_contents("/proc/$pid/cmdline");
		if ($cmd === false) {
			$alive = false;
		} else {
			$cmd = str_replace("\0", ' ', $cmd);
			$alive = stripos($cmd, $streamID) !== false;
		}

		$cache[$cacheKey] = ['alive' => $alive, 'time' => time()];

		return $alive;
	}
	public static function isProcessRunning($rPID, $rEXE) {
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

			if (!($procExists && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(@readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
				return false;
			}
			return true;
		}
		return false;
	}
	public static function startMonitor($rStreamID, $rRestart = 0) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'monitor.php ' . intval($rStreamID) . ' ' . intval($rRestart) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function startProxy($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'proxy.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function sendSignal($rSignalData, $rSegmentFile, $rCodec = 'h264', $rReturn = false) {
		if (empty($rSignalData['xy_offset'])) {
			$x = rand(150, 380);
			$y = rand(110, 250);
		} else {
			list($x, $y) = explode('x', $rSignalData['xy_offset']);
		}
		if ($rReturn) {
			$rOutput = SIGNALS_TMP_PATH . $rSignalData['activity_id'] . '_' . $rSegmentFile;
			shell_exec(self::$rFFMPEG_CPU . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -i ' . escapeshellarg(STREAMS_PATH . $rSegmentFile) . ' -filter_complex "drawtext=fontfile=' . FFMPEG_FONT . ":text='" . escapeshellcmd($rSignalData['message']) . "':fontsize=" . escapeshellcmd($rSignalData['font_size']) . ':x=' . intval($x) . ':y=' . intval($y) . ':fontcolor=' . escapeshellcmd($rSignalData['font_color']) . '" -map 0 -vcodec ' . $rCodec . ' -preset ultrafast -acodec copy -scodec copy -mpegts_flags +initial_discontinuity -mpegts_copyts 1 -f mpegts ' . escapeshellarg($rOutput));
			$rData = file_get_contents($rOutput);
			unlink($rOutput);
			return $rData;
		}
		passthru(self::$rFFMPEG_CPU . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -i ' . escapeshellarg(STREAMS_PATH . $rSegmentFile) . ' -filter_complex "drawtext=fontfile=' . FFMPEG_FONT . ":text='" . escapeshellcmd($rSignalData['message']) . "':fontsize=" . escapeshellcmd($rSignalData['font_size']) . ':x=' . intval($x) . ':y=' . intval($y) . ':fontcolor=' . escapeshellcmd($rSignalData['font_color']) . '" -map 0 -vcodec ' . $rCodec . ' -preset ultrafast -acodec copy -scodec copy -mpegts_flags +initial_discontinuity -mpegts_copyts 1 -f mpegts -');
		return true;
	}
	public static function getUserIP() {
		return $_SERVER['REMOTE_ADDR'];
	}
	public static function getISP($rIP) {
		if (!empty($rIP)) {
			$rResponse = (file_exists(CONS_TMP_PATH . md5($rIP) . '_isp') ? json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true) : null);
			if (is_array($rResponse)) {
			} else {
				$rGeoIP = new MaxMind\Db\Reader(GEOISP_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if (!is_array($rResponse)) {
				} else {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
				}
			}
			return $rResponse;
		}
		return false;
	}
	public static function checkISP($rConISP) {
		return BlocklistService::checkISP(self::$rBlockedISP, $rConISP);
	}
	public static function checkServer($rASN) {
		return BlocklistService::checkServer(self::$rBlockedServers, $rASN);
	}
	public static function getIPInfo($rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
				$rGeoIP = new MaxMind\Db\Reader(GEOLITE2_BIN);
				$rResponse = $rGeoIP->get($rIP);
				$rGeoIP->close();
				if (!$rResponse) {
				} else {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
		}
		return false;
	}
	public static function validateImage($rURL, $rForceProtocol = null) {
		return ImageUtils::validateURL($rURL, $rForceProtocol, array('StreamingUtilities', 'getPublicURL'));
	}
	public static function isRunning() {
		$rNginx = 0;
		exec('ps -fp $(pgrep -u xc_vm)', $rOutput, $rReturnVar);
		foreach ($rOutput as $rProcess) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
			if (!($rSplit[8] == 'nginx:' && $rSplit[9] == 'master')) {
			} else {
				$rNginx++;
			}
		}
		return 0 < $rNginx;
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
		if (self::$rServers[$rServerID]) {
			if (self::$rServers[$rServerID]['enable_proxy']) {
				$rProxyIDs = array_keys(self::getProxies($rServerID));
				if (count($rProxyIDs) == 0) {
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
			if (self::$rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && self::$rServers[$rOriginatorID]['is_main'] == 0) {
				$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
			}
			return $rServerURL;
		}
	}
	public static function getCategories($rType = null) {
		return CategoryRepository::filterLoaded(self::$rCategories, $rType);
	}
	public static function matchCIDR($rASN, $rIP) {
		if (!file_exists(CIDR_TMP_PATH . $rASN)) {
		} else {
			$rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);
			foreach ($rCIDRs as $rCIDR => $rData) {
				if (!(ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2]))) {
				} else {
					return $rData;
				}
			}
		}
	}
	public static function getLLODSegments($rStreamID, $rPlaylist, $rPrebuffer = 1) {
		$rPrebuffer++;
		$rSegments = $rKeySegments = array();
		if (!file_exists($rPlaylist)) {
		} else {
			$rSource = file_get_contents($rPlaylist);
			if (!preg_match_all('/(.*?).ts((#\\w+)+|#?)/', $rSource, $rMatches)) {
			} else {
				if (0 >= count($rMatches[1])) {
				} else {
					$rLastKey = null;
					for ($i = 0; $i < count($rMatches[1]); $i++) {
						$rFilename = $rMatches[1][$i];
						list($rSID, $rSegmentID) = explode('_', $rFilename);
						if (empty($rMatches[2][$i])) {
						} else {
							$rKeySegments[$rSegmentID] = array();
							$rLastKey = $rSegmentID;
						}
						if (!$rLastKey) {
						} else {
							$rKeySegments[$rLastKey][] = $rSegmentID;
						}
					}
				}
			}
			$rKeySegments = array_slice($rKeySegments, count($rKeySegments) - $rPrebuffer, $rPrebuffer, true);
			foreach ($rKeySegments as $rKeySegment => $rSubSegments) {
				foreach ($rSubSegments as $rSegmentID) {
					$rSegments[] = $rStreamID . '_' . $rSegmentID . '.ts';
				}
			}
		}
		return (!empty($rSegments) ? $rSegments : null);
	}
	public static function getPlaylistSegments($rPlaylist, $rPrebuffer = 0, $rSegmentDuration = 10) {
		if (file_exists($rPlaylist)) {
			$rSource = file_get_contents($rPlaylist);
			$rSource = str_replace(array("\r\n", "\r"), "\n", $rSource);

			// Handle fMP4 initialization segment
			if (preg_match('/#EXT-X-MAP:URI="(.*?)"/', $rSource, $rInitMatch)) {
				$rInitSegment = $rInitMatch[1];  // e.g., "1_init.mp4"

				// The original instruction snippet for getPlaylistSegments had token generation logic
				// that was more appropriate for generateHLS.
				// For getPlaylistSegments, we only need to extract the segment names.
				// The tokenization for fMP4 init segments is already handled in generateHLS.
				// This part of the instruction seems to be a copy-paste error from generateHLS.
				// Therefore, I'm only adding the str_replace for newlines as it's a common cleanup.
				// The fMP4 init segment handling for tokenization is already in generateHLS.
				// If the intent was to return the init segment itself, it would be different.
				// Given the context of getPlaylistSegments returning segment names,
				// and generateHLS handling tokenization, I will not add the tokenization logic here.
				// The instruction's snippet for getPlaylistSegments was incomplete and seemed to mix concerns.
				// I will ensure the file remains syntactically correct and functional.
			}

			if (preg_match_all('/(.*?)\.(ts|m4s)/', $rSource, $rMatches)) {
				if (0 < $rPrebuffer) {
					$rTotalSegments = intval($rPrebuffer / $rSegmentDuration);
					if (!$rTotalSegments) {
						$rTotalSegments = 1;
					}
					return array_slice($rMatches[0], 0 - $rTotalSegments);
				}
				if ($rPrebuffer == -1) {
					return $rMatches[0];
				}
				preg_match('/_(.*)\\./', array_pop($rMatches[0]), $rCurrentSegment);
				return $rCurrentSegment[1];
			}
		}
	}

	public static function generateHLS($rM3U8, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC = null, $rIdentifier = '', $rVideoCodec = 'h264', $rOnDemand = 0, $rServerID = null, $rProxyID = null) {
		if (file_exists($rM3U8)) {
			$rSource = file_get_contents($rM3U8);
			if (self::$rSettings['encrypt_hls']) {
				$rKeyToken = StreamingUtilities::encryptData($rIP . '/' . $rStreamID, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rSource = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"" . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/key/' . $rKeyToken . '",IV=0x' . bin2hex(file_get_contents(STREAMS_PATH . $rStreamID . '_.iv')) . "\n" . substr($rSource, 8, strlen($rSource) - 8);
			}

			// Handle fMP4 init segment if present
			if (preg_match('/#EXT-X-MAP:URI="(.*?)"/', $rSource, $rInitMatch)) {
				$rInitSegment = $rInitMatch[1];  // e.g., "1_init.mp4"

				if ($rIsHMAC) {
					$rInitToken = StreamingUtilities::encryptData('HMAC#' . $rIsHMAC . '/' . $rIdentifier . '/' . $rIP . '/' . $rStreamID . '/' . $rInitSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				} else {
					$rInitToken = StreamingUtilities::encryptData($rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rStreamID . '/' . $rInitSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				}

				if (self::$rSettings['allow_cdn_access']) {
					$rSource = str_replace('URI="' . $rInitSegment . '"', 'URI="' . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rInitSegment . '?token=' . $rInitToken . '"', $rSource);
				} else {
					$rSource = str_replace('URI="' . $rInitSegment . '"', 'URI="' . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rInitToken . '"', $rSource);
				}
			}

			if (preg_match_all('/(.*?)\.(ts|m4s)/', $rSource, $rMatches)) {
				foreach ($rMatches[0] as $rMatch) {
					if ($rIsHMAC) {
						$rToken = StreamingUtilities::encryptData('HMAC#' . $rIsHMAC . '/' . $rIdentifier . '/' . $rIP . '/' . $rStreamID . '/' . $rMatch . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
					} else {
						$rToken = StreamingUtilities::encryptData($rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rStreamID . '/' . $rMatch . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
					}
					if (self::$rSettings['allow_cdn_access']) {
						$rSource = str_replace($rMatch, (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rMatch . '?token=' . $rToken, $rSource);
					} else {
						$rSource = str_replace($rMatch, (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rToken, $rSource);
					}
				}

				return $rSource;
			}
		}

		return false;
	}
	public static function validateConnections($rUserInfo, $rIsHMAC = false, $rIdentifier = '', $rIP = null, $rUserAgent = null) {
		if ($rUserInfo['max_connections'] != 0) {
			if (!$rIsHMAC) {
				if (!empty($rUserInfo['pair_id'])) {
					self::closeConnections($rUserInfo['pair_id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent);
				}
				self::closeConnections($rUserInfo['id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent);
			} else {
				self::closeConnections(null, $rUserInfo['max_connections'], $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);
			}
		}
	}
	public static function getBouquetMap($rStreamID) {
		return BouquetMapper::getMapEntry($rStreamID);
	}
	public static function getStreamData($rStreamID) {
		$rOutput = array();
		self::$db->query('SELECT * FROM `streams` t1 LEFT JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE t1.`id` = ?', $rStreamID);
		if (0 >= self::$db->num_rows()) {
		} else {
			$rStreamInfo = self::$db->get_row();
			$rServers = array();
			if (!($rStreamInfo['direct_source'] == 0 || $rStreamInfo['direct_proxy'] == 1)) {
			} else {
				self::$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` = ?', $rStreamID);
				if (0 >= self::$db->num_rows()) {
				} else {
					$rServers = self::$db->get_rows(true, 'server_id');
				}
			}
			$rOutput['bouquets'] = self::getBouquetMap($rStreamID);
			$rOutput['info'] = $rStreamInfo;
			$rOutput['servers'] = $rServers;
		}
		return (!empty($rOutput) ? $rOutput : false);
	}
	public static function getMainID() {
		return ConnectionTracker::getMainID(self::$rServers);
	}
	public static function addToQueue($rStreamID, $rAddPID) {
		ConnectionTracker::addToQueue($rStreamID, $rAddPID, array('StreamingUtilities', 'isProcessRunning'));
	}
	public static function removeFromQueue($rStreamID, $rPID) {
		ConnectionTracker::removeFromQueue($rStreamID, $rPID, array('StreamingUtilities', 'isProcessRunning'));
	}
	public static function generateString($rLength = 10) {
		$rCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789qwertyuiopasdfghjklzxcvbnm';
		$rString = '';
		$rMax = strlen($rCharacters) - 1;
		$i = 0;
		while ($i < $rLength) {
			$rString .= $rCharacters[rand(0, $rMax)];
			$i++;
		}
		return $rString;
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
	public static function getDiffTimezone($rTimezone) {
		$rServerTZ = new DateTime('UTC', new DateTimeZone(date_default_timezone_get()));
		$rUserTZ = new DateTime('UTC', new DateTimeZone($rTimezone));
		return $rUserTZ->getTimestamp() - $rServerTZ->getTimestamp();
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
	public static function connectRedis() {
		self::$redis = RedisManager::connect(self::$redis, self::$rConfig, self::$rSettings);
		return is_object(self::$redis);
	}
	public static function closeRedis() {
		self::$redis = RedisManager::close(self::$redis);
		return true;
	}
	public static function getConnection($rUUID) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getConnection(self::$redis, $rUUID);
	}
	public static function createConnection($rData) {
		if (!is_object(self::$redis)) {
			self::connectRedis();
		}
		return ConnectionTracker::createConnection(self::$redis, $rData);
	}
	public static function updateConnection($rData, $rChanges = array(), $rOption = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::updateConnection(self::$redis, $rData, $rChanges, $rOption);
	}
	public static function getConnections($rUserID, $rActive = false, $rKeys = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::getLineConnections(self::$redis, $rUserID, $rActive, $rKeys);
	}
	public static function redisSignal($rPID, $rServerID, $rRTMP, $rCustomData = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return ConnectionTracker::redisSignal(self::$redis, $rPID, $rServerID, $rRTMP, $rCustomData);
	}
	public static function getNearest($rSearch, $rArray) {
		return StreamSorter::getNearest($rArray, $rSearch);
	}
	public static function getDomainName($rForceSSL = false) {
		return DomainResolver::resolve(self::$rServers, self::$rSettings, SERVER_ID, $rForceSSL, array('StreamingUtilities', 'getProxies'), array('StreamingUtilities', 'getCache'));
	}
	public static function getProxies($rServerID, $rOnline = true) {
		return ConnectionTracker::getProxies(self::$rServers, $rServerID, $rOnline);
	}
	public static function getStreamingURL($rServerID = null, $rOriginatorID = null, $rForceHTTP = false) {
		if (isset($rServerID)) {
		} else {
			$rServerID = SERVER_ID;
		}
		if ($rForceHTTP) {
			$rProtocol = 'http';
		} else {
			if (self::$rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = self::$rServers[$rServerID]['server_protocol'];
			}
		}
		$rDomain = null;
		if (0 < strlen(HOST) && in_array(strtolower(HOST), array_map('strtolower', self::$rServers[$rServerID]['domains']['urls']))) {
			$rDomain = HOST;
		} else {
			if (!(self::$rServers[$rServerID]['random_ip'] && 0 < count(self::$rServers[$rServerID]['domains']['urls']))) {
			} else {
				$rDomain = self::$rServers[$rServerID]['domains']['urls'][array_rand(self::$rServers[$rServerID]['domains']['urls'])];
			}
		}
		if ($rDomain) {
			$rURL = $rProtocol . '://' . $rDomain . ':' . self::$rServers[$rServerID][$rProtocol . '_broadcast_port'];
		} else {
			$rURL = rtrim(self::$rServers[$rServerID][$rProtocol . '_url'], '/');
		}
		if (!(self::$rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && self::$rServers[$rOriginatorID]['is_main'] == 0)) {
		} else {
			$rURL .= '/' . md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA);
		}
		return $rURL;
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
}
