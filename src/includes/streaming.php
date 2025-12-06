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
		if (!empty($_GET)) {
			self::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			self::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			self::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			self::cleanGlobals($_COOKIE);
		}
		$rInput = @self::parseIncomingRecursively($_GET, array());
		self::$rRequest = @self::parseIncomingRecursively($_POST, $rInput);
		self::$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
		if (!defined('SERVER_ID')) {
			define('SERVER_ID',	intval(self::$rConfig['server_id']));
		}
		if (!self::$rSettings) {
			self::$rSettings = self::getCache('settings');
		}
		if (!empty(self::$rSettings['default_timezone'])) {
			date_default_timezone_set(
				self::$rSettings['default_timezone']
			);
		}
		if ((self::$rSettings['on_demand_wait_time'] == 0)) {
			self::$rSettings['on_demand_wait_time'] = 15;
		}
		switch (self::$rSettings['ffmpeg_cpu']) {
			case '8.0':
				self::$rFFMPEG_CPU = FFMPEG_BIN_80;
				self::$rFFMPEG_GPU = FFMPEG_BIN_80;
				break;
			case '7.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_71;
				self::$rFFMPEG_GPU = FFMPEG_BIN_71;
				break;
			case '5.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_51;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.4':
				self::$rFFMPEG_CPU = FFMPEG_BIN_44;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.3':
				self::$rFFMPEG_CPU = FFMPEG_BIN_43;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			default:
				self::$rFFMPEG_CPU = FFMPEG_BIN_40;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
		}
		self::$rCached = self::isCacheEnabledAndComplete();
		self::$rServers = self::getCache(
			'servers'
		);
		self::$rBlockedUA = self::getCache(
			'blocked_ua'
		);
		self::$rBlockedISP = self::getCache(
			'blocked_isp'
		);
		self::$rBlockedIPs = self::getCache(
			'blocked_ips'
		);
		self::$rBlockedServers = self::getCache(
			'blocked_servers'
		);
		self::$rAllowedIPs = self::getCache(
			'allowed_ips'
		);
		self::$rProxies = self::getCache(
			'proxy_servers'
		);
		self::$rSegmentSettings = array(
			'seg_time' => intval(
				self::$rSettings['seg_time']
			),
			'seg_list_size' => intval(
				self::$rSettings['seg_list_size']
			),
		);
		self::connectDatabase();
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

		self::$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
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
	public static function checkFlood($rIP = null) {
		if (self::$rSettings['flood_limit'] != 0) {
			if ($rIP) {
			} else {
				$rIP = self::getUserIP();
			}
			if (!(empty($rIP) || in_array($rIP, self::$rAllowedIPs))) {
				$rFloodExclude = array_filter(array_unique(explode(',', self::$rSettings['flood_ips_exclude'])));
				if (!in_array($rIP, $rFloodExclude)) {
					$rIPFile = FLOOD_TMP_PATH . $rIP;
					if (file_exists($rIPFile)) {
						$rFloodRow = json_decode(file_get_contents($rIPFile), true);
						$rFloodSeconds = self::$rSettings['flood_seconds'];
						$rFloodLimit = self::$rSettings['flood_limit'];
						if (time() - $rFloodRow['last_request'] <= $rFloodSeconds) {
							$rFloodRow['requests']++;
							if ($rFloodLimit > $rFloodRow['requests']) {
								$rFloodRow['last_request'] = time();
								file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
							} else {
								if (in_array($rIP, self::$rBlockedIPs)) {
								} else {
									if (self::$rCached) {
										self::setSignal('flood_attack/' . $rIP, 1);
									} else {
										self::$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'FLOOD ATTACK', time());
									}
									touch(FLOOD_TMP_PATH . 'block_' . $rIP);
								}
								unlink($rIPFile);
								return null;
							}
						} else {
							$rFloodRow['requests'] = 0;
							$rFloodRow['last_request'] = time();
							file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
						}
					} else {
						file_put_contents($rIPFile, json_encode(array('requests' => 0, 'last_request' => time())), LOCK_EX);
					}
				} else {
					return null;
				}
			} else {
				return null;
			}
		} else {
			return null;
		}
	}
	public static function checkBruteforce($rIP = null, $rMAC = null, $rUsername = null) {
		if ($rMAC || $rUsername) {
			if (!($rMAC && self::$rSettings['bruteforce_mac_attempts'] == 0)) {
				if (!($rUsername && self::$rSettings['bruteforce_username_attempts'] == 0)) {
					if ($rIP) {
					} else {
						$rIP = self::getUserIP();
					}
					if (!(empty($rIP) || in_array($rIP, self::$rAllowedIPs))) {
						$rFloodExclude = array_filter(array_unique(explode(',', self::$rSettings['flood_ips_exclude'])));
						if (!in_array($rIP, $rFloodExclude)) {
							$rFloodType = (!is_null($rMAC) ? 'mac' : 'user');
							$rTerm = (!is_null($rMAC) ? $rMAC : $rUsername);
							$rIPFile = FLOOD_TMP_PATH . $rIP . '_' . $rFloodType;
							if (file_exists($rIPFile)) {
								$rFloodRow = json_decode(file_get_contents($rIPFile), true);
								$rFloodSeconds = intval(self::$rSettings['bruteforce_frequency']);
								$rFloodLimit = intval(self::$rSettings[array('mac' => 'bruteforce_mac_attempts', 'user' => 'bruteforce_username_attempts')[$rFloodType]]);
								$rFloodRow['attempts'] = self::truncateAttempts($rFloodRow['attempts'], $rFloodSeconds);
								if (in_array($rTerm, array_keys($rFloodRow['attempts']))) {
								} else {
									$rFloodRow['attempts'][$rTerm] = time();
									if ($rFloodLimit > count($rFloodRow['attempts'])) {
										file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
									} else {
										if (in_array($rIP, self::$rBlockedIPs)) {
										} else {
											if (self::$rCached) {
												self::setSignal('bruteforce_attack/' . $rIP, 1);
											} else {
												self::$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'BRUTEFORCE ' . strtoupper($rFloodType) . ' ATTACK', time());
											}
											touch(FLOOD_TMP_PATH . 'block_' . $rIP);
										}
										unlink($rIPFile);
										return null;
									}
								}
							} else {
								$rFloodRow = array('attempts' => array($rTerm => time()));
								file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
							}
						} else {
							return null;
						}
					} else {
						return null;
					}
				} else {
					return null;
				}
			} else {
				return null;
			}
		} else {
			return null;
		}
	}
	public static function checkAuthFlood($rUser, $rIP = null) {
		if (self::$rSettings['auth_flood_limit'] != 0) {
			if (!$rUser['is_restreamer']) {
				if ($rIP) {
				} else {
					$rIP = self::getUserIP();
				}
				if (!(empty($rIP) || in_array($rIP, self::$rAllowedIPs))) {
					$rFloodExclude = array_filter(array_unique(explode(',', self::$rSettings['flood_ips_exclude'])));
					if (!in_array($rIP, $rFloodExclude)) {
						$rUserFile = FLOOD_TMP_PATH . intval($rUser['id']) . '_' . $rIP;
						if (file_exists($rUserFile)) {
							$rFloodRow = json_decode(file_get_contents($rUserFile), true);
							if (!(isset($rFloodRow['block_until']) && time() < $rFloodRow['block_until'])) {
							} else {
								sleep(intval(self::$rSettings['auth_flood_sleep']));
							}
							$rFloodSeconds = self::$rSettings['auth_flood_seconds'];
							$rFloodLimit = self::$rSettings['auth_flood_limit'];
							$rFloodRow['attempts'] = self::truncateAttempts($rFloodRow['attempts'], $rFloodSeconds, true);
							if ($rFloodLimit > count($rFloodRow['attempts'])) {
							} else {
								$rFloodRow['block_until'] = time() + intval(self::$rSettings['auth_flood_seconds']);
							}
							$rFloodRow['attempts'][] = time();
							file_put_contents($rUserFile, json_encode($rFloodRow), LOCK_EX);
						} else {
							file_put_contents($rUserFile, json_encode(array('attempts' => array(time()))), LOCK_EX);
						}
					} else {
						return null;
					}
				} else {
					return null;
				}
			} else {
				return null;
			}
		} else {
			return null;
		}
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
	public static function truncateAttempts($rAttempts, $rFrequency, $rList = false) {
		$rAllowedAttempts = array();
		$rTime = time();
		if ($rList) {
			foreach ($rAttempts as $rAttemptTime) {
				if ($rTime - $rAttemptTime > $rFrequency) {
				} else {
					$rAllowedAttempts[] = $rAttemptTime;
				}
			}
		} else {
			foreach ($rAttempts as $rAttempt => $rAttemptTime) {
				if ($rTime - $rAttemptTime > $rFrequency) {
				} else {
					$rAllowedAttempts[$rAttempt] = $rAttemptTime;
				}
			}
		}
		return $rAllowedAttempts;
	}
	public static function getCapacity($rProxy = false) {
		return json_decode(file_get_contents(CACHE_TMP_PATH . (($rProxy ? 'proxy_capacity' : 'servers_capacity'))), true);
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
									if (!((!empty($rStream['servers'][$rServerID]['pid']) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && $rStream['servers'][$rServerID]['stream_status'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1) && ($rStream['info']['target_container'] == $rExtension || ($rExtension = 'srt')) && $rServerInfo['timeshift_only'] == 0)) {
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
	public static function B97D7ACBCf7c7A5e($Fd50c63671da34f8) {
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
		$Fd50c63671da34f8 = self::B97D7AcBCF7C7A5e($Fd50c63671da34f8);
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
			if ($rServerID) {
			} else {
				$rServerID = self::F4221e28760b623E($rUserInfo, $rIP, $rCountryCode, $rISP);
			}
			if ($rServerID) {
			} else {
				$rServerID = SERVER_ID;
			}
			$rOriginatorID = null;
			if (!(self::isProxied($rServerID) && (!$rUserInfo['is_restreamer'] || !self::$rSettings['restreamer_bypass_proxy']))) {
			} else {
				$rProxies = self::getProxies($rServerID);
				$rProxyID = self::availableProxy(array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);
				if ($rProxyID) {
				} else {
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
				$rM3U8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-ALLOW-CACHE:YES\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\n" . $rURL . '/auth/' . $rToken . "\n#EXT-X-ENDLIST";
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
					if ($rActivityInfo['container'] == 'hls') {
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
		$rUserInfo['bouquet'] = json_decode($rUserInfo['bouquet'], true);
		$rUserInfo['allowed_ips'] = @array_filter(@array_map('trim', @json_decode($rUserInfo['allowed_ips'], true)));
		$rUserInfo['allowed_ua'] = @array_filter(@array_map('trim', @json_decode($rUserInfo['allowed_ua'], true)));
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
	public static function setSignal($rKey, $rData) {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
	}
	public static function validateHMAC($rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0) {
		if (0 < strlen($rIP) && 0 < strlen($rMACIP)) {
			if ($rIP != $rMACIP) {
				return null;
			}
		}
		$rKeyID = null;
		if (self::$rCached) {
			$rKeys = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'hmac_keys'));
		} else {
			$rKeys = array();
			self::$db->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `enabled` = 1;');
			foreach (self::$db->get_rows() as $rKey) {
				$rKeys[] = $rKey;
			}
		}
		foreach ($rKeys as $rKey) {
			$rResult = hash_hmac('sha256', (string) $rStreamID . '##' . $rExtension . '##' . $rExpiry . '##' . $rMACIP . '##' . $rIdentifier . '##' . $rMaxConnections, StreamingUtilities::decryptData($rKey['key'], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

			if (md5($rResult) == md5($rHMAC)) {
				$rKeyID = $rKey['id'];
				break;
			}
		}
		return $rKeyID;
	}
	public static function clientLog($rStreamID, $rUserID, $rAction, $rIP, $rData = '', $bypass = false) {
		if (self::$rSettings['client_logs_save'] != 0 || $bypass) {
			$rUserAgent = (!empty($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : '');
			$rData = array('user_id' => $rUserID, 'stream_id' => $rStreamID, 'action' => $rAction, 'query_string' => htmlentities($_SERVER['QUERY_STRING']), 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'time' => time(), 'extra_data' => $rData);
			file_put_contents(LOGS_TMP_PATH . 'client_request.log', base64_encode(json_encode($rData)) . "\n", FILE_APPEND);
		} else {
			return null;
		}
	}
	public static function checkBlockedUAs($rUserAgent, $rReturn = false) {
		$rUserAgent = strtolower($rUserAgent);
		foreach (self::$rBlockedUA as $rKey => $rBlocked) {
			if ($rBlocked['exact_match'] == 1) {
				if ($rBlocked['blocked_ua'] != $rUserAgent) {
				} else {
					return true;
				}
			} else {
				if (!stristr($rUserAgent, $rBlocked['blocked_ua'])) {
				} else {
					return true;
				}
			}
		}
		return false;
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
	public static function isStreamRunning($rPID, $rStreamID) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe'))) {
			} else {
				if (strpos(basename(readlink('/proc/' . $rPID . '/exe')), 'ffmpeg') === 0) {
					$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
					if (!(stristr($rCommand, '/' . $rStreamID . '_.m3u8') || stristr($rCommand, '/' . $rStreamID . '_%d.ts'))) {
					} else {
						return true;
					}
				} else {
					if (strpos(basename(readlink('/proc/' . $rPID . '/exe')), 'php') !== 0) {
					} else {
						return true;
					}
				}
			}
			return false;
		}
		return false;
	}
	public static function isProcessRunning($rPID, $rEXE) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0)) {
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
		foreach (self::$rBlockedISP as $rISP) {
			if (strtolower($rConISP) != strtolower($rISP['isp'])) {
			} else {
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
		if (substr($rURL, 0, 2) == 's:') {
			$rSplit = explode(':', $rURL, 3);
			$rServerURL = self::getPublicURL(intval($rSplit[1]), $rForceProtocol);
			if ($rServerURL) {
				return $rServerURL . 'images/' . basename($rURL);
			}
			return '';
		}
		return $rURL;
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
		$rReturn = array();
		foreach (self::$rCategories as $rCategory) {
			if ($rCategory['category_type'] != $rType && $rType) {
			} else {
				$rReturn[] = $rCategory;
			}
		}
		return $rReturn;
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
			if (preg_match_all('/(.*?).ts/', $rSource, $rMatches)) {
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

	// Checked
	public static function generateHLS($rM3U8, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC = null, $rIdentifier = '', $rVideoCodec = 'h264', $rOnDemand = 0, $rServerID = null, $rProxyID = null) {
		if (file_exists($rM3U8)) {
			$rSource = file_get_contents($rM3U8);

			if (self::$rSettings['encrypt_hls'] && $rOnDemand) {
				$rKeyToken = StreamingUtilities::encryptData($rIP . '/' . $rStreamID, self::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rSource = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"" . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/key/' . $rKeyToken . '",IV=0x' . bin2hex(file_get_contents(STREAMS_PATH . $rStreamID . '_.iv')) . "\n" . substr($rSource, 8, strlen($rSource) - 8);
			}

			if (preg_match_all('/(.*?)\\.ts/', $rSource, $rMatches)) {
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
		$rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
		$rReturn = ($rBouquetMap[$rStreamID] ?: array());
		unset($rBouquetMap);
		return $rReturn;
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
		foreach (self::$rServers as $rServerID => $rServer) {
			if (!$rServer['is_main']) {
			} else {
				return $rServerID;
			}
		}
	}
	public static function addToQueue($rStreamID, $rAddPID) {
		$rActivePIDs = $rPIDs = array();
		if (!file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
		} else {
			$rPIDs = igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID)));
		}
		foreach ($rPIDs as $rPID) {
			if (!self::isProcessRunning($rPID, 'php-fpm')) {
			} else {
				$rActivePIDs[] = $rPID;
			}
		}
		if (in_array($rActivePIDs, $rAddPID)) {
		} else {
			$rActivePIDs[] = $rAddPID;
		}
		file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs));
	}
	public static function removeFromQueue($rStreamID, $rPID) {
		$rActivePIDs = array();
		foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) ?: array()) as $rActivePID) {
			if (!(self::isProcessRunning($rActivePID, 'php-fpm') && $rPID != $rActivePID)) {
			} else {
				$rActivePIDs[] = $rActivePID;
			}
		}
		if (0 < count($rActivePIDs)) {
			file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs));
		} else {
			unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}
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
		if (!(is_numeric($rYear) && 1900 <= $rYear && $rYear <= intval(date('Y') + 1))) {
		} else {
			if (self::$rSettings['movie_year_append'] == 0) {
				return trim($rTitle) . ' (' . $rYear . ')';
			}
			if (self::$rSettings['movie_year_append'] != 0) {
			} else {
				return trim($rTitle) . ' - ' . $rYear;
			}
		}
		return $rTitle;
	}
	public static function sortChannels($rChannels) {
		if (!(0 < count($rChannels) && file_exists(CACHE_TMP_PATH . 'channel_order') && self::$rSettings['channel_number_type'] != 'bouquet')) {
		} else {
			$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order'));
			$rChannels = array_flip($rChannels);
			$rNewOrder = array();
			foreach ($rOrder as $rID) {
				if (!isset($rChannels[$rID])) {
				} else {
					$rNewOrder[] = $rID;
				}
			}
			if (0 >= count($rNewOrder)) {
			} else {
				return $rNewOrder;
			}
		}
		return $rChannels;
	}
	public static function sortSeries($rSeries) {
		if (!(0 < count($rSeries) && file_exists(CACHE_TMP_PATH . 'series_order'))) {
		} else {
			$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'series_order'));
			$rSeries = array_flip($rSeries);
			$rNewOrder = array();
			foreach ($rOrder as $rID) {
				if (!isset($rSeries[$rID])) {
				} else {
					$rNewOrder[] = $rID;
				}
			}
			if (0 >= count($rNewOrder)) {
			} else {
				return $rNewOrder;
			}
		}
		return $rSeries;
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
		if (is_object(self::$redis)) {
		} else {
			try {
				self::$redis = new Redis();
				self::$redis->connect(self::$rConfig['hostname'], 6379);
				self::$redis->auth(self::$rSettings['redis_password']);
			} catch (Exception $e) {
				self::$redis = null;
				return false;
			}
		}
		return true;
	}
	public static function closeRedis() {
		if (!is_object(self::$redis)) {
		} else {
			self::$redis->close();
			self::$redis = null;
		}
		return true;
	}
	public static function getConnection($rUUID) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		return igbinary_unserialize(self::$redis->get($rUUID));
	}
	public static function createConnection($rData) {
		if (!is_object(self::$redis)) {
			self::connectRedis();
		}
		$rRedis = self::$redis->multi();
		$rRedis->zAdd('LINE#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
		$rRedis->zAdd('LINE_ALL#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
		$rRedis->zAdd('STREAM#' . $rData['stream_id'], $rData['date_start'], $rData['uuid']);
		$rRedis->zAdd('SERVER#' . $rData['server_id'], $rData['date_start'], $rData['uuid']);
		if ($rData['user_id']) {
			$rRedis->zAdd('SERVER_LINES#' . $rData['server_id'], $rData['user_id'], $rData['uuid']);
		}
		if ($rData['proxy_id']) {
			$rRedis->zAdd('PROXY#' . $rData['proxy_id'], $rData['date_start'], $rData['uuid']);
		}
		$rRedis->zAdd('CONNECTIONS', $rData['date_start'], $rData['uuid']);
		$rRedis->zAdd('LIVE', $rData['date_start'], $rData['uuid']);
		$rRedis->set($rData['uuid'], igbinary_serialize($rData));
		return $rRedis->exec();
	}
	public static function updateConnection($rData, $rChanges = array(), $rOption = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rOrigData = $rData;
		foreach ($rChanges as $rKey => $rValue) {
			$rData[$rKey] = $rValue;
		}
		$rRedis = self::$redis->multi();
		if ($rOption == 'open') {
			$rRedis->sRem('ENDED', $rData['uuid']);
			$rRedis->zAdd('LIVE', $rData['date_start'], $rData['uuid']);
			$rRedis->zAdd('LINE#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
			$rRedis->zAdd('STREAM#' . $rData['stream_id'], $rData['date_start'], $rData['uuid']);
			$rRedis->zAdd('SERVER#' . $rData['server_id'], $rData['date_start'], $rData['uuid']);
			if (!$rData['proxy_id']) {
			} else {
				$rRedis->zAdd('PROXY#' . $rData['proxy_id'], $rData['date_start'], $rData['uuid']);
			}
			if ($rData['hls_end'] != 1) {
			} else {
				$rData['hls_end'] = 0;
				if (!$rData['user_id']) {
				} else {
					$rRedis->zAdd('SERVER_LINES#' . $rData['server_id'], $rData['user_id'], $rData['uuid']);
				}
			}
		} else {
			if ($rOption != 'close') {
			} else {
				$rRedis->sAdd('ENDED', $rData['uuid']);
				$rRedis->zRem('LIVE', $rData['uuid']);
				$rRedis->zRem('LINE#' . $rOrigData['identity'], $rData['uuid']);
				$rRedis->zRem('STREAM#' . $rOrigData['stream_id'], $rData['uuid']);
				$rRedis->zRem('SERVER#' . $rOrigData['server_id'], $rData['uuid']);
				if (!$rData['proxy_id']) {
				} else {
					$rRedis->zRem('PROXY#' . $rOrigData['proxy_id'], $rData['uuid']);
				}
				if ($rData['hls_end'] != 0) {
				} else {
					$rData['hls_end'] = 1;
					if (!$rData['user_id']) {
					} else {
						$rRedis->zRem('SERVER_LINES#' . $rOrigData['server_id'], $rData['uuid']);
					}
				}
			}
		}
		$rRedis->set($rData['uuid'], igbinary_serialize($rData));
		if ($rRedis->exec()) {
			return $rData;
		}
	}
	public static function getConnections($rUserID, $rActive = false, $rKeys = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rKeys = self::$redis->zRangeByScore((($rActive ? 'LINE#' : 'LINE_ALL#')) . $rUserID, '-inf', '+inf');
		if ($rKeys) {
			return $rKeys;
		}
		if (0 >= count($rKeys)) {
			return array();
		}
		return array_map('igbinary_unserialize', self::$redis->mGet($rKeys));
	}
	public static function redisSignal($rPID, $rServerID, $rRTMP, $rCustomData = null) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rKey = 'SIGNAL#' . md5($rServerID . '#' . $rPID . '#' . $rRTMP);
		$rData = array('pid' => $rPID, 'server_id' => $rServerID, 'rtmp' => $rRTMP, 'time' => time(), 'custom_data' => $rCustomData, 'key' => $rKey);
		return self::$redis->multi()->sAdd('SIGNALS#' . $rServerID, $rKey)->set($rKey, igbinary_serialize($rData))->exec();
	}
	public static function getNearest($rSearch, $rArray) {
		$rClosest = null;
		foreach ($rArray as $rItem) {
			if (!($rClosest === null || abs($rItem - $rSearch) < abs($rSearch - $rClosest))) {
			} else {
				$rClosest = $rItem;
			}
		}
		return $rClosest;
	}
	public static function getDomainName($rForceSSL = false) {
		$rOriginatorID = null;
		$rServerID = SERVER_ID;
		if ($rForceSSL) {
			$rProtocol = 'https';
		} else {
			if (isset($_SERVER['SERVER_PORT']) && self::$rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = self::$rServers[$rServerID]['server_protocol'];
			}
		}
		$rProxied = self::$rServers[$rServerID]['enable_proxy'];
		if (!$rProxied) {
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
		list($rDomain, $rAccessPort) = explode(':', $_SERVER['HTTP_HOST']);
		if (!($rProxied || self::$rSettings['use_mdomain_in_lists'] == 1)) {
		} else {
			if (in_array(strtolower($rDomain), (self::getCache('reseller_domains') ?: array()))) {
			} else {
				if (empty(self::$rServers[$rServerID]['domain_name'])) {
					$rDomain = escapeshellcmd(self::$rServers[$rServerID]['server_ip']);
				} else {
					$rDomain = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', self::$rServers[$rServerID]['domain_name'])[0]));
				}
			}
		}
		$rServerURL = $rProtocol . '://' . $rDomain . ':' . self::$rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';
		if (!(self::$rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && self::$rServers[$rOriginatorID]['is_main'] == 0)) {
		} else {
			$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
		}
		return $rServerURL;
	}
	public static function getProxies($rServerID, $rOnline = true) {
		$rReturn = array();
		foreach (self::$rServers as $rProxyID => $rServerInfo) {
			if (!($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline))) {
			} else {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}
		return $rReturn;
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
