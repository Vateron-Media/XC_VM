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
			define('SERVER_ID', intval(self::$rConfig['server_id']));
		}
		if ($rUseCache) {
			self::$rSettings = self::getCache('settings');
		} else {
			self::$rSettings = self::getSettings();
		}
		if (empty(self::$rSettings['default_timezone'])) {
		} else {
			date_default_timezone_set(self::$rSettings['default_timezone']);
		}
		if (self::$rSettings['on_demand_wait_time'] != 0) {
		} else {
			self::$rSettings['on_demand_wait_time'] = 15;
		}
		self::$rSegmentSettings = array('seg_time' => intval(self::$rSettings['seg_time']), 'seg_list_size' => intval(self::$rSettings['seg_list_size']), 'seg_delete_threshold' => intval(self::$rSettings['seg_delete_threshold']));
		switch (self::$rSettings['ffmpeg_cpu']) {
			case '8.0':
				self::$rFFMPEG_CPU = FFMPEG_BIN_80;
				self::$rFFPROBE = FFPROBE_BIN_80;
				self::$rFFMPEG_GPU = FFMPEG_BIN_80;
				break;
			case '7.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_71;
				self::$rFFPROBE = FFPROBE_BIN_71;
				self::$rFFMPEG_GPU = FFMPEG_BIN_71;
				break;
			case '5.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_51;
				self::$rFFPROBE = FFPROBE_BIN_51;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.4':
				self::$rFFMPEG_CPU = FFMPEG_BIN_44;
				self::$rFFPROBE = FFPROBE_BIN_44;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.3':
				self::$rFFMPEG_CPU = FFMPEG_BIN_43;
				self::$rFFPROBE = FFPROBE_BIN_43;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			default:
				self::$rFFMPEG_CPU = FFMPEG_BIN_40;
				self::$rFFPROBE = FFPROBE_BIN_40;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
		}

		self::$rCached = self::$rSettings['enable_cache'];
		if ($rUseCache) {
			self::$rServers = self::getCache('servers');
			self::$rBouquets = self::getCache('bouquets');
			self::$rBlockedUA = self::getCache('blocked_ua');
			self::$rBlockedISP = self::getCache('blocked_isp');
			self::$rBlockedIPs = self::getCache('blocked_ips');
			self::$rProxies = self::getCache('proxy_servers');
			self::$rBlockedServers = self::getCache('blocked_servers');
			self::$rAllowedDomains = self::getCache('allowed_domains');
			self::$rAllowedIPs = self::getCache('allowed_ips');
			self::$rCategories = self::getCache('categories');
		} else {
			self::$rServers = self::getServers();
			self::$rBouquets = self::getBouquets();
			self::$rBlockedUA = self::getBlockedUA();
			self::$rBlockedISP = self::getBlockedISP();
			self::$rBlockedIPs = self::getBlockedIPs();
			self::$rProxies = self::getProxyIPs();
			self::$rBlockedServers = self::getBlockedServers();
			self::$rAllowedDomains = self::getAllowedDomains();
			self::$rAllowedIPs = self::getAllowedIPs();
			self::$rCategories = self::getCategories();
			self::generateCron();
		}
	}
	public static function getDiffTimezone($rTimezone) {
		$rServerTZ = new DateTime('UTC', new DateTimeZone(date_default_timezone_get()));
		$rUserTZ = new DateTime('UTC', new DateTimeZone($rTimezone));
		return $rUserTZ->getTimestamp() - $rServerTZ->getTimestamp();
	}
	public static function getAllowedDomains($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('allowed_domains', 20);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		$rDomains = array('127.0.0.1', 'localhost');
		self::$db->query('SELECT `server_ip`, `private_ip`, `domain_name` FROM `servers` WHERE `enabled` = 1;');
		foreach (self::$db->get_rows() as $rRow) {
			foreach (explode(',', $rRow['domain_name']) as $rDomain) {
				$rDomains[] = $rDomain;
			}
			if (!$rRow['server_ip']) {
			} else {
				$rDomains[] = $rRow['server_ip'];
			}
			if (!$rRow['private_ip']) {
			} else {
				$rDomains[] = $rRow['private_ip'];
			}
		}
		self::$db->query('SELECT `reseller_dns` FROM `users` WHERE `status` = 1;');
		foreach (self::$db->get_rows() as $rRow) {
			if (!$rRow['reseller_dns']) {
			} else {
				$rDomains[] = $rRow['reseller_dns'];
			}
		}
		$rDomains = array_filter(array_unique($rDomains));
		self::setCache('allowed_domains', $rDomains);
		return $rDomains;
	}
	public static function getProxyIPs($rForce = false) {
		if (!$rForce) {
			$rCache = self::getCache('proxy_servers', 20);
			if ($rCache === true) {
				return $rCache;
			}
		}
		$rOutput = array();
		foreach (self::$rServers as $rServer) {
			if ($rServer['server_type'] == 1) {
				$rOutput[$rServer['server_ip']] = $rServer;
				if ($rServer['private_ip']) {
					$rOutput[$rServer['private_ip']] = $rServer;
				}
			}
		}
		self::setCache('proxy_servers', $rOutput);
		return $rOutput;
	}
	public static function isProxy($rIP) {
		if (!isset(self::$rProxies[$rIP])) {
		} else {
			return self::$rProxies[$rIP];
		}
	}
	public static function getBlockedUA($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('blocked_ua', 20);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		self::$db->query('SELECT id,exact_match,LOWER(user_agent) as blocked_ua FROM `blocked_uas`');
		$rOutput = self::$db->get_rows(true, 'id');
		self::setCache('blocked_ua', $rOutput);
		return $rOutput;
	}
	public static function getBlockedIPs($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('blocked_ips', 20);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		$rOutput = array();
		self::$db->query('SELECT `ip` FROM `blocked_ips`');
		foreach (self::$db->get_rows() as $rRow) {
			$rOutput[] = $rRow['ip'];
		}
		self::setCache('blocked_ips', $rOutput);
		return $rOutput;
	}
	public static function getBlockedISP($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('blocked_isp', 20);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		self::$db->query('SELECT id,isp,blocked FROM `blocked_isps`');
		$rOutput = self::$db->get_rows();
		self::setCache('blocked_isp', $rOutput);
		return $rOutput;
	}
	public static function getBlockedServers($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('blocked_servers', 20);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		$rOutput = array();
		self::$db->query('SELECT `asn` FROM `blocked_asns` WHERE `blocked` = 1;');
		foreach (self::$db->get_rows() as $rRow) {
			$rOutput[] = $rRow['asn'];
		}
		self::setCache('blocked_servers', $rOutput);
		return $rOutput;
	}
	public static function getBouquets($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('bouquets', 60);
			if (empty($rCache)) {
			} else {
				return $rCache;
			}
		}
		$rOutput = array();
		self::$db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');
		foreach (self::$db->get_rows(true, 'id') as $rID => $rChannels) {
			$rOutput[$rID]['streams'] = array_merge(json_decode($rChannels['bouquet_channels'], true), json_decode($rChannels['bouquet_movies'], true), json_decode($rChannels['bouquet_radios'], true));
			$rOutput[$rID]['series'] = json_decode($rChannels['bouquet_series'], true);
			$rOutput[$rID]['channels'] = json_decode($rChannels['bouquet_channels'], true);
			$rOutput[$rID]['movies'] = json_decode($rChannels['bouquet_movies'], true);
			$rOutput[$rID]['radios'] = json_decode($rChannels['bouquet_radios'], true);
		}
		self::setCache('bouquets', $rOutput);
		return $rOutput;
	}
	public static function getSettings($rForce = false) {
		if ($rForce) {
		} else {
			$rCache = self::getCache('settings', 20);
			if (empty($rCache)) {
			} else {
				return $rCache;
			}
		}
		$rOutput = array();
		self::$db->query('SELECT * FROM `settings`');
		$rRows = self::$db->get_row();
		foreach ($rRows as $rKey => $rValue) {
			$rOutput[$rKey] = $rValue;
		}
		$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'], true);
		$rOutput['allowed_stb_types'] = array_map('strtolower', json_decode($rOutput['allowed_stb_types'], true));
		$rOutput['stalker_lock_images'] = json_decode($rOutput['stalker_lock_images'], true);
		if (!array_key_exists('bouquet_name', $rOutput)) {
		} else {
			$rOutput['bouquet_name'] = str_replace(' ', '_', $rOutput['bouquet_name']);
		}
		$rOutput['api_ips'] = explode(',', $rOutput['api_ips']);
		self::setCache('settings', $rOutput);
		return $rOutput;
	}
	public static function setCache($rCache, $rData) {
		$rData = igbinary_serialize($rData);
		file_put_contents(CACHE_TMP_PATH . $rCache, $rData, LOCK_EX);
	}
	public static function getCache($rCache, $rSeconds = null) {
		if (file_exists(CACHE_TMP_PATH . $rCache)) {
			if ($rSeconds && time() - filemtime(CACHE_TMP_PATH . $rCache) >= $rSeconds) {
			} else {
				$rData = file_get_contents(CACHE_TMP_PATH . $rCache);
				return igbinary_unserialize($rData);
			}
		}
		return false;
	}
	public static function getServers($rForce = false) {
		if (!$rForce) {
			$rCache = self::getCache('servers', 10);
			if (!empty($rCache)) {
				return $rCache;
			}
		}
		if (empty($_SERVER['REQUEST_SCHEME'])) {
			$_SERVER['REQUEST_SCHEME'] = 'http';
		}
		self::$db->query('SELECT * FROM `servers`');
		$rServers = array();
		$rOnlineStatus = array(1);
		foreach (self::$db->get_rows() as $rRow) {
			if (empty($rRow['domain_name'])) {
				$rURL = escapeshellcmd($rRow['server_ip']);
			} else {
				$rURL = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', $rRow['domain_name'])[0]));
			}
			if ($rRow['enable_https'] == 1) {
				$rProtocol = 'https';
			} else {
				$rProtocol = 'http';
			}
			$rPort = ($rProtocol == 'http' ? intval($rRow['http_broadcast_port']) : intval($rRow['https_broadcast_port']));
			$rRow['server_protocol'] = $rProtocol;
			$rRow['request_port'] = $rPort;
			$rRow['site_url'] = $rProtocol . '://' . $rURL . ':' . $rPort . '/';
			$rRow['http_url'] = 'http://' . $rURL . ':' . intval($rRow['http_broadcast_port']) . '/';
			$rRow['https_url'] = 'https://' . $rURL . ':' . intval($rRow['https_broadcast_port']) . '/';
			$rRow['rtmp_server'] = 'rtmp://' . $rURL . ':' . intval($rRow['rtmp_port']) . '/live/';
			$rRow['domains'] = array('protocol' => $rProtocol, 'port' => $rPort, 'urls' => array_filter(array_map('escapeshellcmd', explode(',', $rRow['domain_name']))));
			$rRow['rtmp_mport_url'] = 'http://127.0.0.1:31210/';
			$rRow['api_url_ip'] = 'http://' . escapeshellcmd($rRow['server_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/api?password=' . urlencode(self::$rSettings['live_streaming_pass']);
			$rRow['api_url'] = $rRow['api_url_ip'];
			$rRow['site_url_ip'] = $rProtocol . '://' . escapeshellcmd($rRow['server_ip']) . ':' . $rPort . '/';
			$rRow['private_url_ip'] = (!empty($rRow['private_ip']) ? 'http://' . escapeshellcmd($rRow['private_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/' : null);
			$rRow['public_url_ip'] = 'http://' . escapeshellcmd($rRow['server_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/';
			$rRow['geoip_countries'] = (empty($rRow['geoip_countries']) ? array() : json_decode($rRow['geoip_countries'], true));
			$rRow['isp_names'] = (empty($rRow['isp_names']) ? array() : json_decode($rRow['isp_names'], true));
			if (is_numeric($rRow['parent_id'])) {
				$rRow['parent_id'] = array(intval($rRow['parent_id']));
			} else {
				$decoded = json_decode($rRow['parent_id'] ?? '', true);
				$rRow['parent_id'] = is_array($decoded) ? array_map('intval', $decoded) : [];
			}

			if ($rRow['enable_https'] == 2) {
				$rRow['allow_http'] = false;
			} else {
				$rRow['allow_http'] = true;
			}
			if ($rRow['server_type'] == 1) {
				$rLastCheckTime = 180;
			} else {
				$rLastCheckTime = 90;
			}
			$rRow['watchdog'] = json_decode($rRow['watchdog_data'], true);
			$rRow['server_online'] = $rRow['enabled'] && in_array($rRow['status'], $rOnlineStatus) && time() - $rRow['last_check_ago'] <= $rLastCheckTime || SERVER_ID == $rRow['id'];
			if (!isset($rRow['order'])) {
				$rRow['order'] = 0;
			}
			$rServers[intval($rRow['id'])] = $rRow;
		}
		self::setCache('servers', $rServers);
		return $rServers;
	}
	public static function getMultiCURL($rURLs, $callback = null, $rTimeout = 5) {
		if (!empty($rURLs)) {
			$rOffline = array();
			$rCurl = array();
			$rResults = array();
			$rMulti = curl_multi_init();
			foreach ($rURLs as $rKey => $rValue) {
				if (self::$rServers[$rKey]['server_online']) {
					$rCurl[$rKey] = curl_init();
					curl_setopt($rCurl[$rKey], CURLOPT_URL, $rValue['url']);
					curl_setopt($rCurl[$rKey], CURLOPT_RETURNTRANSFER, true);
					curl_setopt($rCurl[$rKey], CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($rCurl[$rKey], CURLOPT_CONNECTTIMEOUT, 5);
					curl_setopt($rCurl[$rKey], CURLOPT_TIMEOUT, $rTimeout);
					curl_setopt($rCurl[$rKey], CURLOPT_SSL_VERIFYHOST, 0);
					curl_setopt($rCurl[$rKey], CURLOPT_SSL_VERIFYPEER, 0);
					if ($rValue['postdata'] == null) {
					} else {
						curl_setopt($rCurl[$rKey], CURLOPT_POST, true);
						curl_setopt($rCurl[$rKey], CURLOPT_POSTFIELDS, http_build_query($rValue['postdata']));
					}
					curl_multi_add_handle($rMulti, $rCurl[$rKey]);
				} else {
					$rOffline[] = $rKey;
				}
			}
			$rActive = null;
			do {
				$rMultiExec = curl_multi_exec($rMulti, $rActive);
			} while ($rMultiExec == CURLM_CALL_MULTI_PERFORM);
			while ($rActive && $rMultiExec == CURLM_OK) {
				if (curl_multi_select($rMulti) != -1) {
				} else {
					usleep(50000);
				}
				do {
					$rMultiExec = curl_multi_exec($rMulti, $rActive);
				} while ($rMultiExec == CURLM_CALL_MULTI_PERFORM);
			}
			foreach ($rCurl as $rKey => $rValue) {
				$rResults[$rKey] = curl_multi_getcontent($rValue);
				if ($callback == null) {
				} else {
					$rResults[$rKey] = call_user_func($callback, $rResults[$rKey], true);
				}
				curl_multi_remove_handle($rMulti, $rValue);
			}
			foreach ($rOffline as $rKey) {
				$rResults[$rKey] = false;
			}
			curl_multi_close($rMulti);
			return $rResults;
		} else {
			return array();
		}
	}
	public static function cleanGlobals(&$rData, $rIteration = 0) {
		if (10 > $rIteration) {
			foreach ($rData as $rKey => $rValue) {
				if (is_array($rValue)) {
					self::cleanGlobals($rData[$rKey], ++$rIteration);
				} else {
					$rValue = str_replace(chr('0'), '', $rValue);
					$rValue = str_replace('', '', $rValue);
					$rValue = str_replace('', '', $rValue);
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
			return preg_replace('/^([\\w\\.\\-\\_]+)$/', '$1', $rKey);
		}
		return '';
	}
	public static function parseCleanValue($rValue) {
		if ($rValue != '') {
			$rValue = str_replace('&#032;', ' ', stripslashes($rValue));
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
	public static function saveLog($rType, $rMessage, $rExtra = '', $rLine = 0) {
		if (stripos($rExtra, 'panel_logs') === false && stripos($rMessage, 'timeout exceeded') === false && stripos($rMessage, 'lock wait timeout') === false && stripos($rMessage, 'duplicate entry') === false) {
			panelLog($rType, $rMessage, $rExtra, $rLine);
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
	public static function mergeRecursive($rArray) {
		if (is_array($rArray)) {
			$rArrayValues = array();
			foreach ($rArray as $rValue) {
				if (is_scalar($rValue) || is_resource($rValue)) {
					$rArrayValues[] = $rValue;
				} else {
					if (!is_array($rValue)) {
					} else {
						$rArrayValues = array_merge($rArrayValues, self::mergeRecursive($rValue));
					}
				}
			}
			return $rArrayValues;
		} else {
			return $rArray;
		}
	}
	public static function getTotalTmpfs() {
		$rTotal = 0;
		exec('df | grep tmpfs', $rOutput);
		foreach ($rOutput as $rLine) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', $rLine));
			if ($rSplit[0] != 'tmpfs') {
			} else {
				$rTotal += intval($rSplit[2]);
			}
		}
		return $rTotal;
	}
	public static function getStats() {
		$rJSON = array();
		$rJSON['cpu'] = round(self::getTotalCPU(), 2);
		$rJSON['cpu_cores'] = intval(shell_exec('cat /proc/cpuinfo | grep "^processor" | wc -l'));
		$rJSON['cpu_avg'] = round((sys_getloadavg()[0] * 100) / (($rJSON['cpu_cores'] ?: 1)), 2);
		$rJSON['cpu_name'] = trim(shell_exec("cat /proc/cpuinfo | grep 'model name' | uniq | awk -F: '{print \$2}'"));
		if ($rJSON['cpu_avg'] > 100) {
			$rJSON['cpu_avg'] = 100;
		}
		$rMemInfo = self::getMemory();
		$rJSON['total_mem'] = $rMemInfo['total'];
		$rJSON['total_mem_free'] = $rMemInfo['free'];
		$rJSON['total_mem_used'] = $rMemInfo['used'] + self::getTotalTmpfs();
		$rJSON['total_mem_used_percent'] = round($rJSON['total_mem_used'] / $rJSON['total_mem'] * 100, 2);
		$rJSON['total_disk_space'] = disk_total_space(MAIN_HOME);
		$rJSON['free_disk_space'] = disk_free_space(MAIN_HOME);
		$rJSON['kernel'] = trim(shell_exec('uname -r'));
		$rJSON['uptime'] = self::getUptime();
		$rJSON['total_running_streams'] = (int) trim(shell_exec('ps ax | grep -v grep | grep -c ffmpeg'));
		$rJSON['bytes_sent'] = 0;
		$rJSON['bytes_sent_total'] = 0;
		$rJSON['bytes_received'] = 0;
		$rJSON['bytes_received_total'] = 0;
		$rJSON['network_speed'] = 0;
		$rJSON['interfaces'] = self::getNetworkInterfaces();
		$rJSON['network_speed'] = 0;
		if ($rJSON['cpu'] > 100) {
			$rJSON['cpu'] = 100;
		}
		if ($rJSON['total_mem'] < $rJSON['total_mem_used']) {
			$rJSON['total_mem_used'] = $rJSON['total_mem'];
		}
		if ($rJSON['total_mem_used_percent'] > 100) {
			$rJSON['total_mem_used_percent'] = 100;
		}
		$rJSON['network_info'] = CoreUtilities::getNetwork((self::$rServers[SERVER_ID]['network_interface'] == 'auto' ? null : self::$rServers[SERVER_ID]['network_interface']));
		foreach ($rJSON['network_info'] as $rInterface => $rData) {
			if (file_exists('/sys/class/net/' . $rInterface . '/speed')) {
				$NetSpeed = intval(file_get_contents('/sys/class/net/' . $rInterface . '/speed'));
				if (0 < $NetSpeed && $rJSON['network_speed'] == 0) {
					$rJSON['network_speed'] = $NetSpeed;
				}
			}
			$rJSON['bytes_sent_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
			$rJSON['bytes_received_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
			$rJSON['bytes_sent'] += $rData['out_bytes'];
			$rJSON['bytes_received'] += $rData['in_bytes'];
		}
		$rJSON['audio_devices'] = array();
		$rJSON['video_devices'] = $rJSON['audio_devices'];
		$rJSON['gpu_info'] = $rJSON['video_devices'];
		$rJSON['iostat_info'] = $rJSON['gpu_info'];
		if (shell_exec('which iostat')) {
			$rJSON['iostat_info'] = self::getIO();
		}
		if (shell_exec('which nvidia-smi')) {
			$rJSON['gpu_info'] = self::getGPUInfo();
		}
		if (shell_exec('which v4l2-ctl')) {
			$rJSON['video_devices'] = self::getVideoDevices();
		}
		if (shell_exec('which arecord')) {
			$rJSON['audio_devices'] = self::getAudioDevices();
		}
		list($rJSON['cpu_load_average']) = sys_getloadavg();
		return $rJSON;
	}
	public static function getNetworkInterfaces() {
		$rReturn = array();
		exec('ls /sys/class/net/', $rOutput, $rReturnVar);
		foreach ($rOutput as $rInterface) {
			$rInterface = trim(rtrim($rInterface, ':'));
			if (!($rInterface != 'lo' && substr($rInterface, 0, 4) != 'bond')) {
			} else {
				$rReturn[] = $rInterface;
			}
		}
		return $rReturn;
	}
	public static function getVideoDevices() {
		$rReturn = array();
		$rID = 0;
		try {
			$rDevices = array_values(array_filter(explode("\n", shell_exec('v4l2-ctl --list-devices'))));
			if (is_array($rDevices)) {
				foreach ($rDevices as $rKey => $rValue) {
					if ($rKey % 2 == 0) {
						$rReturn[$rID]['name'] = $rValue;
						list(, $rReturn[$rID]['video_device']) = explode('/dev/', $rDevices[$rKey + 1]);
						$rID++;
					}
				}
			}
		} catch (Exception $e) {
		}
		return $rReturn;
	}
	public static function getAudioDevices() {
		try {
			return array_filter(explode("\n", shell_exec('arecord -L | grep "hw:CARD="')));
		} catch (Exception $e) {
			return array();
		}
	}
	public static function getIO() {
		exec('iostat -o JSON -m', $rOutput, $rReturnVar);
		$rOutput = implode('', $rOutput);
		$rJSON = json_decode($rOutput, true);
		if (isset($rJSON['sysstat'])) {
			return $rJSON['sysstat']['hosts'][0]['statistics'][0];
		}
		return array();
	}
	public static function getGPUInfo() {
		exec('nvidia-smi -x -q', $rOutput, $rReturnVar);
		$rOutput = implode('', $rOutput);
		if (stripos($rOutput, '<?xml') === false) {
		} else {
			$rJSON = json_decode(json_encode(simplexml_load_string($rOutput)), true);
			if (!isset($rJSON['driver_version'])) {
			} else {
				$rGPU = array('attached_gpus' => $rJSON['attached_gpus'], 'driver_version' => $rJSON['driver_version'], 'cuda_version' => $rJSON['cuda_version'], 'gpus' => array());
				if (!isset($rJSON['gpu']['board_id'])) {
				} else {
					$rJSON['gpu'] = array($rJSON['gpu']);
				}
				foreach ($rJSON['gpu'] as $rInstance) {
					$rArray = array('name' => $rInstance['product_name'], 'power_readings' => $rInstance['power_readings'], 'utilisation' => $rInstance['utilization'], 'memory_usage' => $rInstance['fb_memory_usage'], 'fan_speed' => $rInstance['fan_speed'], 'temperature' => $rInstance['temperature'], 'clocks' => $rInstance['clocks'], 'uuid' => $rInstance['uuid'], 'id' => intval($rInstance['pci']['pci_device']), 'processes' => array());
					foreach ($rInstance['processes']['process_info'] as $rProcess) {
						$rArray['processes'][] = array('pid' => intval($rProcess['pid']), 'memory' => $rProcess['used_memory']);
					}
					$rGPU['gpus'][] = $rArray;
				}
				return $rGPU;
			}
		}
		return array();
	}
	public static function searchEPG($rArray, $rKey, $rValue) {
		$rResults = array();
		self::searchRecursive($rArray, $rKey, $rValue, $rResults);
		return $rResults;
	}
	public static function searchRecursive($rArray, $rKey, $rValue, &$rResults) {
		if (is_array($rArray)) {
			if (!(isset($rArray[$rKey]) && $rArray[$rKey] == $rValue)) {
			} else {
				$rResults[] = $rArray;
			}
			foreach ($rArray as $subarray) {
				self::searchRecursive($subarray, $rKey, $rValue, $rResults);
			}
		} else {
			return null;
		}
	}
	public static function checkCron($rFilename, $rTime = 1800) {
		if (!file_exists($rFilename)) {
		} else {
			$rPID = trim(file_get_contents($rFilename));
			if (!file_exists('/proc/' . $rPID)) {
			} else {
				if (time() - filemtime($rFilename) >= $rTime) {
					if (!(is_numeric($rPID) && 0 < $rPID)) {
					} else {
						posix_kill($rPID, 9);
					}
				} else {
					exit('Running...');
				}
			}
		}
		file_put_contents($rFilename, getmypid());
		return false;
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
									self::$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'FLOOD ATTACK', time());
									self::$rBlockedIPs = self::getBlockedIPs();
								}
								touch(FLOOD_TMP_PATH . 'block_' . $rIP);
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
										self::$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'BRUTEFORCE ' . strtoupper($rFloodType) . ' ATTACK', time());
										touch(FLOOD_TMP_PATH . 'block_' . $rIP);
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
	public static function getTotalCPU() {
		$rTotalLoad = 0;
		exec('ps -Ao pid,pcpu', $processes);
		foreach ($processes as $process) {
			$cols = explode(' ', preg_replace('!\\s+!', ' ', trim($process)));
			if (count($cols) >= 2 && is_numeric($cols[1])) {
				$rTotalLoad += floatval($cols[1]);
			}
		}

		// Get CPU core count with fallback
		$cpuCores = 1; // Default fallback

		// Method 1: Try /proc/cpuinfo
		$coreCount = intval(shell_exec("grep -P '^processor' /proc/cpuinfo|wc -l"));
		if ($coreCount > 0) {
			$cpuCores = $coreCount;
		}

		// Avoid division by zero
		if ($cpuCores <= 0) {
			$cpuCores = 1;
		}

		return $rTotalLoad / $cpuCores;
	}
	public static function getCategories($rType = null, $rForce = false) {
		if (is_string($rType)) {
			self::$db->query('SELECT t1.* FROM `streams_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			return (0 < self::$db->num_rows() ? self::$db->get_rows(true, 'id') : array());
		}
		if ($rForce) {
		} else {
			$rCache = self::getCache('categories', 20);
			if (empty($rCache)) {
			} else {
				return $rCache;
			}
		}
		self::$db->query('SELECT t1.* FROM `streams_categories` t1 ORDER BY t1.cat_order ASC');
		$rCategories = (0 < self::$db->num_rows() ? self::$db->get_rows(true, 'id') : array());
		self::setCache('categories', $rCategories);
		return $rCategories;
	}
	public static function generateUniqueCode() {
		return substr(md5(self::$rSettings['live_streaming_pass']), 0, 15);
	}
	public static function unserialize_php($rSessionData) {
		$rReturn = array();
		$rOffset = 0;
		while ($rOffset < strlen($rSessionData)) {
			if (strstr(substr($rSessionData, $rOffset), '|')) {
				$rPos = strpos($rSessionData, '|', $rOffset);
				$rNum = $rPos - $rOffset;
				$rVarName = substr($rSessionData, $rOffset, $rNum);
				$rOffset += $rNum + 1;
				$rData = igbinary_unserialize(substr($rSessionData, $rOffset));
				$rReturn[$rVarName] = $rData;
				$rOffset += strlen(igbinary_serialize($rData));
			} else {
				return array();
			}
		}
		return $rReturn;
	}
	public static function generatePlaylist($rUserInfo, $rDeviceKey, $rOutputKey = 'ts', $rTypeKey = null, $rNoCache = false, $rProxy = false) {
		if (!empty($rDeviceKey)) {
			if ($rOutputKey == 'mpegts') {
				$rOutputKey = 'ts';
			}
			if ($rOutputKey == 'hls') {
				$rOutputKey = 'm3u8';
			}
			if (empty($rOutputKey)) {
				self::$db->query('SELECT t1.output_ext FROM `output_formats` t1 INNER JOIN `output_devices` t2 ON t2.default_output = t1.access_output_id AND `device_key` = ?', $rDeviceKey);
			} else {
				self::$db->query('SELECT t1.output_ext FROM `output_formats` t1 WHERE `output_key` = ?', $rOutputKey);
			}
			if (self::$db->num_rows() > 0) {
				$rCacheName = $rUserInfo['id'] . '_' . $rDeviceKey . '_' . $rOutputKey . '_' . implode('_', ($rTypeKey ?: array()));
				$rOutputExt = self::$db->get_col();
				$rEncryptPlaylist = ($rUserInfo['is_restreamer'] ? self::$rSettings['encrypt_playlist_restreamer'] : self::$rSettings['encrypt_playlist']);
				if ($rUserInfo['is_stalker']) {
					$rEncryptPlaylist = false;
				}
				$rDomainName = self::getDomainName();
				if ($rDomainName) {
					if (!$rProxy) {
						$rRTMPRows = array();
						if ($rOutputKey == 'rtmp') {
							self::$db->query('SELECT t1.id,t2.server_id FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id WHERE t1.rtmp_output = 1');
							$rRTMPRows = self::$db->get_rows(true, 'id', false, 'server_id');
						}
					} else {
						if ($rOutputKey == 'rtmp') {
							$rOutputKey = 'ts';
						}
					}
					if (empty($rOutputExt)) {
						$rOutputExt = 'ts';
					}
					self::$db->query('SELECT t1.*,t2.* FROM `output_devices` t1 LEFT JOIN `output_formats` t2 ON t2.access_output_id = t1.default_output WHERE t1.device_key = ? LIMIT 1', $rDeviceKey);
					if (0 >= self::$db->num_rows()) {
						return false;
					}
					$rDeviceInfo = self::$db->get_row();
					if (strlen($rUserInfo['access_token']) == 32) {
						$rFilename = str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']);
					} else {
						$rFilename = str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']);
					}
					if (!(0 < self::$rSettings['cache_playlists'] && !$rNoCache && file_exists(PLAYLIST_PATH . md5($rCacheName)))) {
						$rData = '';
						$rSeriesAllocation = $rSeriesEpisodes = $rSeriesInfo = array();
						$rUserInfo['episode_ids'] = array();
						if (0 >= count($rUserInfo['series_ids'])) {
						} else {
							if (self::$rCached) {
								foreach ($rUserInfo['series_ids'] as $rSeriesID) {
									$rSeriesInfo[$rSeriesID] = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_' . intval($rSeriesID)));
									$rSeriesData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'episodes_' . intval($rSeriesID)));
									foreach ($rSeriesData as $rSeasonID => $rEpisodes) {
										foreach ($rEpisodes as $rEpisode) {
											$rSeriesEpisodes[$rEpisode['stream_id']] = array($rSeasonID, $rEpisode['episode_num']);
											$rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
											$rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
										}
									}
								}
							} else {
								self::$db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', $rUserInfo['series_ids']) . ')');
								$rSeriesInfo = self::$db->get_rows(true, 'id');
								if (0 >= count($rUserInfo['series_ids'])) {
								} else {
									self::$db->query('SELECT stream_id, series_id, season_num, episode_num FROM `streams_episodes` WHERE series_id IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(series_id,' . implode(',', $rUserInfo['series_ids']) . '), season_num ASC, episode_num ASC');
									foreach (self::$db->get_rows(true, 'series_id', false) as $rSeriesID => $rEpisodes) {
										foreach ($rEpisodes as $rEpisode) {
											$rSeriesEpisodes[$rEpisode['stream_id']] = array($rEpisode['season_num'], $rEpisode['episode_num']);
											$rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
											$rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
										}
									}
								}
							}
						}
						if (0 >= count($rUserInfo['episode_ids'])) {
						} else {
							$rUserInfo['channel_ids'] = array_merge($rUserInfo['channel_ids'], $rUserInfo['episode_ids']);
						}
						$rChannelIDs = array();
						$rAdded = false;
						if ($rTypeKey) {
							foreach ($rTypeKey as $rType) {
								switch ($rType) {
									case 'live':
									case 'created_live':
										if (!$rAdded) {
											$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['live_ids']);
											$rAdded = true;
											break;
										}
										break;
									case 'movie':
										$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['vod_ids']);
										break;
									case 'radio_streams':
										$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['radio_ids']);
										break;
									case 'series':
										$rChannelIDs = array_merge($rChannelIDs, $rUserInfo['episode_ids']);
										break;
								}
							}
						} else {
							$rChannelIDs = $rUserInfo['channel_ids'];
						}
						if (in_array(self::$rSettings['channel_number_type'], array('bouquet_new', 'manual'))) {
							$rChannelIDs = self::sortChannels($rChannelIDs);
						}
						unset($rUserInfo['live_ids'], $rUserInfo['vod_ids'], $rUserInfo['radio_ids'], $rUserInfo['episode_ids'], $rUserInfo['channel_ids']);
						$rOutputFile = null;
						header('Content-Description: File Transfer');
						header('Content-Type: application/octet-stream');
						header('Expires: 0');
						header('Cache-Control: must-revalidate');
						header('Pragma: public');
						if (strlen($rUserInfo['access_token']) == 32) {
							header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']) . '"');
						} else {
							header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']) . '"');
						}
						if (self::$rSettings['cache_playlists'] == 1) {
							$rOutputPath = PLAYLIST_PATH . md5($rCacheName) . '.write';
							$rOutputFile = fopen($rOutputPath, 'w');
						}
						if ($rDeviceKey == 'starlivev5') {
							$rOutput = array();
							$rOutput['iptvstreams_list'] = array();
							$rOutput['iptvstreams_list']['@version'] = 1;
							$rOutput['iptvstreams_list']['group'] = array();
							$rOutput['iptvstreams_list']['group']['name'] = 'IPTV';
							$rOutput['iptvstreams_list']['group']['channel'] = array();
							foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
								if (self::$rSettings['playlist_from_mysql'] || !self::$rCached) {
									$rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
									self::$db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
									$rRows = self::$db->get_rows();
								} else {
									$rRows = array();
									foreach ($rBlockIDs as $rID) {
										$rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
									}
								}
								foreach ($rRows as $rChannelInfo) {
									if (!$rTypeKey || in_array($rChannelInfo['type_output'], $rTypeKey)) {
										if ($rChannelInfo['target_container']) {
										} else {
											$rChannelInfo['target_container'] = 'mp4';
										}
										$rProperties = (!is_array($rChannelInfo['movie_properties']) ? json_decode($rChannelInfo['movie_properties'], true) : $rChannelInfo['movie_properties']);
										if ($rChannelInfo['type_key'] == 'series') {
											$rSeriesID = $rSeriesAllocation[$rChannelInfo['id']];
											$rChannelInfo['live'] = 0;
											$rChannelInfo['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][1]);
											$rChannelInfo['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
											$rChannelInfo['type_output'] = 'series';
											$rChannelInfo['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
										} else {
											$rChannelInfo['stream_display_name'] = self::formatTitle($rChannelInfo['stream_display_name'], $rChannelInfo['year']);
										}
										if (strlen($rUserInfo['access_token']) == 32) {
											$rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['access_token'] . '/';
											if ($rChannelInfo['live'] == 0) {
												$rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
											} else {
												if (self::$rSettings['cloudflare'] && $rOutputExt == 'ts') {
													$rURL .= $rChannelInfo['id'];
												} else {
													$rURL .= $rChannelInfo['id'] . '.' . $rOutputExt;
												}
											}
										} else {
											if ($rEncryptPlaylist) {
												$rEncData = $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
												if ($rChannelInfo['live'] == 0) {
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
								$rAppend = ($rDeviceInfo['device_header'] == '#EXTM3U' ? "\n" . '#EXT-X-SESSION-DATA:DATA-ID="com.xc_vm.' . str_replace('.', '_', XC_VM_VERSION) . '"' : '');
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
											if (count($rCategoryIDs) > 0) {
												foreach ($rCategoryIDs as $rCategoryID) {
													if (isset(self::$rCategories[$rCategoryID])) {
														$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CATEGORY}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], self::$rCategories[$rCategoryID]['category_name'], self::validateImage($rIcon)), $rConfig)) . "\r\n";
														if ($rOutputFile) {
															fwrite($rOutputFile, $rData);
														}
														echo $rData;
														unset($rData);
														if (stripos($rDeviceInfo['device_conf'], '{CATEGORY}') !== true) {
															break;
														}
													}
												}
											} else {
												$rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XC_VM_ID}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], $rIcon), $rConfig)) . "\r\n";
												$rData = str_replace(' group-title="{CATEGORY}"', "", $rData);
												if ($rOutputFile) {
													fwrite($rOutputFile, $rData);
												}
												echo $rData;
												unset($rData);
											}
										}
									}
									unset($rRows);
								}
								$rData = trim(str_replace(array('&lt;', '&gt;'), array('<', '>'), $rDeviceInfo['device_footer']));
								if (!$rOutputFile) {
								} else {
									fwrite($rOutputFile, $rData);
								}
								echo $rData;
								unset($rData);
							}
						}
						if (!$rOutputFile) {
						} else {
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
				if (!(pathinfo($rFullPath, PATHINFO_EXTENSION) == 'php' && file_exists($rFullPath))) {
				} else {
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
	public static function getUptime() {
		if (!(file_exists('/proc/uptime') && is_readable('/proc/uptime'))) {
			return '';
		}
		$tmp = explode(' ', file_get_contents('/proc/uptime'));
		return self::secondsToTime(intval($tmp[0]));
	}
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
		if (is_array(self::$rServers) && isset(self::$rServers[$rServerID]) && self::$rServers[$rServerID]['server_online']) {
			$rOutput = false;
			$i = 1;
			while ($i <= 2) {
				$rCurl = curl_init();
				curl_setopt($rCurl, CURLOPT_URL, $rURL);
				curl_setopt($rCurl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:9.0) Gecko/20100101 Firefox/9.0');
				curl_setopt($rCurl, CURLOPT_HEADER, 0);
				curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
				curl_setopt($rCurl, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($rCurl, CURLOPT_FRESH_CONNECT, true);
				curl_setopt($rCurl, CURLOPT_FORBID_REUSE, true);
				curl_setopt($rCurl, CURLOPT_SSL_VERIFYHOST, 0);
				curl_setopt($rCurl, CURLOPT_SSL_VERIFYPEER, 0);
				if (empty($rPostData)) {
				} else {
					curl_setopt($rCurl, CURLOPT_POST, true);
					curl_setopt($rCurl, CURLOPT_POSTFIELDS, http_build_query($rPostData));
				}
				$rOutput = curl_exec($rCurl);
				$rResponseCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
				$rError = curl_errno($rCurl);
				@curl_close($rCurl);
				if ($rError != 0 || $rResponseCode != 200) {
					$i++;
					break;
				}
			}
			return $rOutput;
		}
		return false;
	}
	public static function deleteCache($rSources) {
		if (!empty($rSources)) {
			foreach ($rSources as $rSource) {
				if (!file_exists(CACHE_TMP_PATH . md5($rSource))) {
				} else {
					unlink(CACHE_TMP_PATH . md5($rSource));
				}
			}
		} else {
			return null;
		}
	}
	public static function queueChannel($rStreamID, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		self::$db->query('SELECT `id` FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		if (self::$db->num_rows() != 0) {
		} else {
			self::$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('channel', ?, ?, ?);", $rStreamID, $rServerID, time());
		}
	}
	public static function createChannel($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'created.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function createChannelItem($rStreamID, $rSource) {
		$rStream = array();
		self::$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t1.type = 3 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if (self::$db->num_rows() > 0) {
			$rStream['stream_info'] = self::$db->get_row();
			self::$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if (self::$db->num_rows() > 0) {
				$rStream['server_info'] = self::$db->get_row();
				$rMD5 = md5($rSource);
				if (substr($rSource, 0, 2) == 's:') {
					$rSplit = explode(':', $rSource, 3);
					$rServerID = intval($rSplit[1]);
					$rSourcePath = $rSplit[2]; // File path
					if ($rServerID != SERVER_ID) {
						// File on another server - needs to be retrieved via API.
						if (is_array(self::$rServers) && isset(self::$rServers[$rServerID])) {
							$rSourcePath = self::$rServers[$rServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rSplit[2]);
						} else {
							// Server not found, use local path.
							$rSourcePath = $rSplit[2];
						}
					}
				} else {
					$rServerID = SERVER_ID;
					$rSourcePath = $rSource;
				}
				if ($rServerID == SERVER_ID && intval($rStream['stream_info']['movie_symlink']) == 1) {
					$rExtension = pathinfo($rSource)['extension'];
					if (strlen($rExtension) == 0) {
						$rExtension = 'mp4';
					}
					// Create symlink
					$rCommand = 'ln -sfn ' . escapeshellarg($rSourcePath) . ' "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.' . escapeshellcmd($rExtension) . '" >/dev/null 2>/dev/null & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
				} else {
					$rStream['stream_info']['transcode_attributes'] = json_decode($rStream['stream_info']['profile_options'], true);
					if (!is_array($rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes'] = array();
					}
					$rLogoOptions = (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback ? $rStream['stream_info']['transcode_attributes'][16]['cmd'] : '');
					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = '';
					if (empty($rGPUOptions)) {
					} else {
						$rFFProbeOutput = self::probeStream($rSourcePath);
						if (!in_array($rFFProbeOutput['codecs']['video']['codec_name'], array('h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'))) {
						} else {
							$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
						}
					}
					$rCommand = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? self::$rFFMPEG_GPU : self::$rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . ((self::$rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err {GPU} -fflags +genpts -async 1 -i {STREAM_SOURCE} {LOGO} ';

					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}
					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}
					if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
						$rCommand .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
					}
					$rCommand .= implode(' ', self::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rCommand .= '-strict -2 -mpegts_flags +initial_discontinuity -f mpegts "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.ts"';
					$rCommand .= ' >/dev/null 2>"' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors" & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
					$rCommand = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{STREAM_SOURCE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, escapeshellarg($rSourcePath)), $rCommand);
				}
				shell_exec($rCommand);
				return intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));
			}
			return false;
		}
		return false;
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
		if (file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
			$rMonitor = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
		} else {
			self::$db->query('SELECT `monitor_pid` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ? LIMIT 1;', SERVER_ID, $rStreamID);
			$rMonitor = intval(self::$db->get_row()['monitor_pid']);
		}
		if (0 >= $rMonitor) {
		} else {
			if (!(self::checkPID($rMonitor, array('XC_VM[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']')) && is_numeric($rMonitor) && 0 < $rMonitor)) {
			} else {
				posix_kill($rMonitor, 9);
			}
		}
		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
		} else {
			self::$db->query('SELECT `pid` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ? LIMIT 1;', SERVER_ID, $rStreamID);
			$rPID = intval(self::$db->get_row()['pid']);
		}
		if (0 >= $rPID) {
		} else {
			if (!(self::checkPID($rPID, array($rStreamID . '_.m3u8', $rStreamID . '_%d.ts', 'LLOD[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']', 'Loopback[' . $rStreamID . ']')) && is_numeric($rPID) && 0 < $rPID)) {
			} else {
				posix_kill($rPID, 9);
			}
		}
		if (!file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
		} else {
			unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}
		self::streamLog($rStreamID, SERVER_ID, 'STREAM_STOP');
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
		if (!$rStop) {
		} else {
			shell_exec('rm -f ' . DELAY_PATH . intval($rStreamID) . '_*');
			self::$db->query('UPDATE `streams_servers` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`audio_codec` = NULL,`video_codec` = NULL,`resolution` = NULL,`compatible` = 0,`stream_status` = 0,`monitor_pid` = NULL WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			self::updateStream($rStreamID);
		}
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
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'monitor.php ' . intval($rStreamID) . ' ' . intval($rRestart) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function startProxy($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'proxy.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function startThumbnail($rStreamID) {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'thumbnail.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}
	public static function stopMovie($rStreamID, $rForce = false) {
		shell_exec("kill -9 `ps -ef | grep '/" . intval($rStreamID) . ".' | grep -v grep | awk '{print \$2}'`;");
		if ($rForce) {
			exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rStreamID) . '.*');
		} else {
			self::$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', SERVER_ID, time(), json_encode(array('type' => 'delete_vod', 'id' => $rStreamID)));
		}
		self::$db->query('UPDATE `streams_servers` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`audio_codec` = NULL,`video_codec` = NULL,`resolution` = NULL,`compatible` = 0,`stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
		self::updateStream($rStreamID);
	}
	public static function queueMovie($rStreamID, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		self::$db->query('DELETE FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		self::$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('movie', ?, ?, ?);", $rStreamID, $rServerID, time());
	}
	public static function queueMovies($rStreamIDs, $rServerID = null) {
		if ($rServerID) {
		} else {
			$rServerID = SERVER_ID;
		}
		if (0 >= count($rStreamIDs)) {
		} else {
			self::$db->query('DELETE FROM `queue` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ') AND `server_id` = ?;', $rServerID);
			$rQuery = '';
			foreach ($rStreamIDs as $rStreamID) {
				if (0 >= $rStreamID) {
				} else {
					$rQuery .= "('movie', " . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . time() . '),';
				}
			}
			if (empty($rQuery)) {
			} else {
				$rQuery = rtrim($rQuery, ',');
				self::$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rQuery . ';');
			}
		}
	}
	public static function refreshMovies($rIDs, $rType = 1) {
		if (0 >= count($rIDs)) {
		} else {
			self::$db->query('DELETE FROM `watch_refresh` WHERE `type` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rIDs)) . ');', $rType);
			$rQuery = '';
			foreach ($rIDs as $rID) {
				if (0 >= $rID) {
				} else {
					$rQuery .= '(' . intval($rType) . ', ' . intval($rID) . ', 0),';
				}
			}
			if (empty($rQuery)) {
			} else {
				$rQuery = rtrim($rQuery, ',');
				self::$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES ' . $rQuery . ';');
			}
		}
	}
	public static function startMovie($rStreamID) {
		$rStream = array();
		self::$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 0 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if (self::$db->num_rows() > 0) {
			$rStream['stream_info'] = self::$db->get_row();
			self::$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if (self::$db->num_rows() > 0) {
				$rStream['server_info'] = self::$db->get_row();
				self::$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = self::$db->get_rows();

				list($rStreamSource) = json_decode($rStream['stream_info']['stream_source'], true);
				if (substr($rStreamSource, 0, 2) == 's:') {
					$rMovieSource = explode(':', $rStreamSource, 3);
					$rMovieServerID = $rMovieSource[1];
					if ($rMovieServerID != SERVER_ID) {
						$rMoviePath = self::$rServers[$rMovieServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rMovieSource[2]);
					} else {
						$rMoviePath = $rMovieSource[2];
					}
					$rProtocol = null;
				} else {
					if (substr($rStreamSource, 0, 1) == '/') {
						$rMovieServerID = SERVER_ID;
						$rMoviePath = $rStreamSource;
						$rProtocol = null;
					} else {
						$rProtocol = substr($rStreamSource, 0, strpos($rStreamSource, '://'));
						$rMoviePath = str_replace(' ', '%20', $rStreamSource);
						$rFetchOptions = implode(' ', self::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));
					}
				}

				// If symlink movie
				if ((isset($rMovieServerID) && $rMovieServerID == SERVER_ID || file_exists($rMoviePath)) && $rStream['stream_info']['movie_symlink'] == 1) {
					$rFFMPEG = 'ln -sfn ' . escapeshellarg($rMoviePath) . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd(pathinfo($rMoviePath)['extension']) . ' >/dev/null 2>/dev/null & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
				} else {
					// subtitle import + metadata (คงไว้)
					$rSubtitles = json_decode($rStream['stream_info']['movie_subtitles'], true);
					$rSubtitlesImport = '';
					$rSubtitlesMetadata = '';
					if (!empty($rSubtitles) && !empty($rSubtitles['files']) && is_array($rSubtitles['files'])) {
						for ($i = 0; $i < count($rSubtitles['files']); $i++) {
							$rSubtitleFile = escapeshellarg($rSubtitles['files'][$i]);
							$rInputCharset = escapeshellarg($rSubtitles['charset'][$i]);
							if ($rSubtitles['location'] == SERVER_ID) {
								$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i ' . $rSubtitleFile . ' ';
							} else {
								$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i "' . self::$rServers[$rSubtitles['location']]['api_url'] . '&action=getFile&filename=' . urlencode($rSubtitleFile) . '" ';
							}
							for ($i = 0; $i < count($rSubtitles['files']); $i++) {
								$rSubtitlesMetadata .= '-map ' . ($i + 1) . ' -metadata:s:s:' . $i . ' title=' . escapeshellcmd($rSubtitles['names'][$i]) . ' -metadata:s:s:' . $i . ' language=' . escapeshellcmd($rSubtitles['names'][$i]) . ' ';
							}
						}
					}

					$rReadNative = ($rStream['stream_info']['read_native'] == 1 ? '-re' : '');
					if ($rStream['stream_info']['enable_transcode'] == 1) {
						if ($rStream['stream_info']['transcode_profile_id'] == -1) {
							$rDecoded = json_decode($rStream['stream_info']['transcode_attributes'], true);
							$rStream['stream_info']['transcode_attributes'] = array_merge(self::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), (is_array($rDecoded) ? $rDecoded : array()));
						} else {
							$rDecoded = json_decode($rStream['stream_info']['profile_options'], true);
							$rStream['stream_info']['transcode_attributes'] = (is_array($rDecoded) ? $rDecoded : array());
						}
					} else {
						$rStream['stream_info']['transcode_attributes'] = array();
					}
					$rLogoOptions = (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback ? $rStream['stream_info']['transcode_attributes'][16]['cmd'] : '');
					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = '';
					if (!empty($rGPUOptions)) {
						$rFFProbeOutput = self::probeStream($rMoviePath);
						if (in_array($rFFProbeOutput['codecs']['video']['codec_name'], array('h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'))) {
							$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
						}
					}
					$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? self::$rFFMPEG_GPU : self::$rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . ((self::$rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err {GPU} {FETCH_OPTIONS} -fflags +genpts -async 1 {READ_NATIVE} -i {STREAM_SOURCE} {LOGO} ' . $rSubtitlesImport;
					$rMap = '-map 0 -copy_unknown ';
					if (!empty($rStream['stream_info']['custom_map'])) {
						$rMap = escapeshellcmd($rStream['stream_info']['custom_map']) . ' -copy_unknown ';
					} else {
						if ($rStream['stream_info']['remove_subtitles'] == 1) {
							$rMap = '-map 0:a -map 0:v';
						}
					}
					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}
					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}
					if ($rStream['stream_info']['target_container'] == 'mp4') {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'mov_text';
					} elseif ($rStream['stream_info']['target_container'] == 'mkv') {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'srt';
					} else {
						$rStream['stream_info']['transcode_attributes']['-scodec'] = 'copy';
					}
					$rOutputs = array();
					$rOutputs[$rStream['stream_info']['target_container']] = '-movflags +faststart -dn ' . $rMap . ' -ignore_unknown ' . $rSubtitlesMetadata . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd($rStream['stream_info']['target_container']);
					foreach ($rOutputs as $rOutputCommand) {
						$rFFMPEG .= implode(' ', self::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
						$rFFMPEG .= $rOutputCommand;
					}
					$rFFMPEG .= ' >/dev/null 2>' . VOD_PATH . intval($rStreamID) . '.errors & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
					$rFFMPEG = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{FETCH_OPTIONS}', '{STREAM_SOURCE}', '{READ_NATIVE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, (empty($rFetchOptions) ? '' : $rFetchOptions), escapeshellarg($rMoviePath), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : '')), $rFFMPEG);
				}
				shell_exec($rFFMPEG);
				file_put_contents(VOD_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);
				$rPID = intval(file_get_contents(VOD_PATH . $rStreamID . '_.pid'));
				self::$db->query('UPDATE `streams_servers` SET `to_analyze` = 1,`stream_started` = ?,`stream_status` = 0,`pid` = ? WHERE `stream_id` = ? AND `server_id` = ?', time(), $rPID, $rStreamID, SERVER_ID);
				self::updateStream($rStreamID);
				return $rPID;
			}
			return false;
		}
		return false;
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
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
		} else {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}
		$rSources = ($rForceSource ? array($rForceSource) : json_decode($rStreamInfo['stream_source'], true));
		$rArgumentMap = array();
		foreach ($rStreamArguments as $rStreamArgument) {
			$rArgumentMap[$rStreamArgument['argument_key']] = array('value' => $rStreamArgument['value'], 'argument_default_value' => $rStreamArgument['argument_default_value']);
		}
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'llod.php ' . intval($rStreamID) . ' "' . base64_encode(json_encode($rSources)) . '" "' . base64_encode(json_encode($rArgumentMap)) . '" >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
		$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
		$rKey = openssl_random_pseudo_bytes(16);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
		$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
		$rIV = openssl_random_pseudo_bytes($rIVSize);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
		self::$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rSources[0], $rStreamID, SERVER_ID);
		self::updateStream($rStreamID);
		return array('main_pid' => $rPID, 'stream_source' => $rSources[0], 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
	}
	public static function startLoopback($rStreamID) {
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
		} else {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}
		$rStream = array();
		self::$db->query('SELECT * FROM `streams` WHERE direct_source = 0 AND id = ?', $rStreamID);
		if (self::$db->num_rows() > 0) {
			$rStream['stream_info'] = self::$db->get_row();
			self::$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if (self::$db->num_rows() > 0) {
				$rStream['server_info'] = self::$db->get_row();
				if ($rStream['server_info']['parent_id'] != 0) {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'loopback.php ' . intval($rStreamID) . ' ' . intval($rStream['server_info']['parent_id']) . ' >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
					$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
					$rKey = openssl_random_pseudo_bytes(16);
					file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
					$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
					$rIV = openssl_random_pseudo_bytes($rIVSize);
					file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
					self::$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rSources[0], $rStreamID, SERVER_ID);
					self::updateStream($rStreamID);
					$rLoopURL = (!is_null(self::$rServers[SERVER_ID]['private_url_ip']) && !is_null(self::$rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? self::$rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : self::$rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					return array('main_pid' => $rPID, 'stream_source' => $rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode(self::$rSettings['live_streaming_pass']) . '&extension=ts', 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
				}
				return 0;
			}
			return false;
		}
		return false;
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
		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}

		$rStream = array();
		self::$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);

		if (self::$db->num_rows() > 0) {
			$rStream['stream_info'] = self::$db->get_row();
			self::$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);

			if (self::$db->num_rows() > 0) {
				$rStream['server_info'] = self::$db->get_row();
				self::$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = self::$db->get_rows();

				if ($rStream['server_info']['on_demand'] == 1) {
					$rProbesize = intval($rStream['stream_info']['probesize_ondemand']);
					$rAnalyseDuration = '10000000';
				} else {
					$rAnalyseDuration = abs(intval(self::$rSettings['stream_max_analyze']));
					$rProbesize = abs(intval(self::$rSettings['probesize']));
				}

				$rTimeout = intval($rAnalyseDuration / 1000000) + self::$rSettings['probe_extra_wait'];
				$rFFProbee = 'timeout ' . $rTimeout . ' ' . self::$rFFPROBE . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' {CONCAT} -i {STREAM_SOURCE} -v quiet -print_format json -show_streams -show_format';
				$rFetchOptions = array();
				$rLoopback = false;
				$rOffset = 0;

				if (!$rStream['server_info']['parent_id']) {
					if ($rStream['stream_info']['type_key'] == 'created_live') {
						$rSources = array(CREATED_PATH . $rStreamID . '_.list');

						if ($rStartPos > 0) {
							$rCCOutput = array();
							$rCCDuration = array();
							$rCCInfo = json_decode($rStream['server_info']['cc_info'], true);

							foreach ($rCCInfo as $rItem) {
								$rCCDuration[$rItem['path']] = intval(explode('.', $rItem['seconds'])[0]);
							}
							$rTimer = 0;
							$rValid = true;

							foreach (explode("\n", file_get_contents(CREATED_PATH . $rStreamID . '_.list')) as $rItem) {
								list($rPath) = explode("'", explode("file '", $rItem)[1]);

								if ($rPath) {
									if ($rCCDuration[$rPath]) {
										$rDuration = $rCCDuration[$rPath];

										if ($rTimer <= $rStartPos && $rStartPos < $rTimer + $rDuration) {
											$rOffset = $rTimer;
											$rCCOutput[] = $rPath;
										} else {
											if ($rStartPos < $rTimer + $rDuration) {
												$rCCOutput[] = $rPath;
											}
										}

										$rTimer += $rDuration;
									} else {
										$rValid = false;
									}
								}
							}

							if ($rValid) {
								$rSources = array(CREATED_PATH . $rStreamID . '_.tlist');
								$rTList = '';

								foreach ($rCCOutput as $rItem) {
									$rTList .= "file '" . $rItem . "'" . "\n";
								}
								file_put_contents(CREATED_PATH . $rStreamID . '_.tlist', $rTList);
							}
						}
					} else {
						$rSources = json_decode($rStream['stream_info']['stream_source'], true);
					}

					if (count($rSources) > 0) {
						if (!empty($rForceSource)) {
							$rSources = array($rForceSource);
						} else {
							if (self::$rSettings['priority_backup'] != 1) {
								if (!empty($rStream['server_info']['current_source'])) {
									$k = array_search($rStream['server_info']['current_source'], $rSources);

									if ($k !== false) {
										$i = 0;

										while ($i <= $k) {
											$rTemp = $rSources[$i];
											unset($rSources[$i]);
											array_push($rSources, $rTemp);
											$i++;
										}
										$rSources = array_values($rSources);
									}
								}
							}
						}
					}
				} else {
					$rLoopback = true;

					if ($rStream['server_info']['on_demand']) {
						$rLLOD = true;
					}

					$rLoopURL = (!is_null(self::$rServers[SERVER_ID]['private_url_ip']) && !is_null(self::$rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? self::$rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : self::$rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					$rSources = array($rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode(self::$rSettings['live_streaming_pass']) . '&extension=ts');
				}

				if ($rStream['stream_info']['type_key'] == 'created_live' && file_exists(CREATED_PATH . $rStreamID . '_.info')) {
					self::$db->query('UPDATE `streams_servers` SET `cc_info` = ? WHERE `server_id` = ? AND `stream_id` = ?;', file_get_contents(CREATED_PATH . $rStreamID . '_.info'), SERVER_ID, $rStreamID);
				}

				if (!$rFromCache) {
					self::deleteCache($rSources);
				}

				foreach ($rSources as $rSource) {
					$rProcessed = false;
					$rRealSource = $rSource;
					$rStreamSource = self::parseStreamURL($rSource);
					echo 'Checking source: ' . $rSource . "\n";
					$rURLInfo = parse_url($rStreamSource);
					$rIsXC_VM = ($rLoopback ? true : self::detectXC_VM($rStreamSource));

					if ($rIsXC_VM && !$rLoopback && self::$rSettings['send_xc_vm_header']) {
						foreach (array_keys($rStream['stream_arguments']) as $rID) {
							if ($rStream['stream_arguments'][$rID]['argument_key'] == 'headers') {
								$rStream['stream_arguments'][$rID]['value'] .= "\r\n" . 'X-XC_VM-Detect:1';
								$rProcessed = true;
							}
						}

						if (!$rProcessed) {
							$rStream['stream_arguments'][] = array('value' => 'X-XC_VM-Detect:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
						}
					}

					$rProbeArguments = $rStream['stream_arguments'];

					if ($rIsXC_VM && $rStream['server_info']['on_demand'] == 1 && self::$rSettings['request_prebuffer'] == 1) {
						foreach (array_keys($rStream['stream_arguments']) as $rID) {
							if ($rStream['stream_arguments'][$rID]['argument_key'] == 'headers') {
								$rStream['stream_arguments'][$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
								$rProcessed = true;
							}
						}

						if (!$rProcessed) {
							$rStream['stream_arguments'][] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
						}
					}

					foreach (array_keys($rProbeArguments) as $rID) {
						if ($rProbeArguments[$rID]['argument_key'] == 'headers') {
							$rProbeArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
							$rProcessed = true;
						}
					}

					if (!$rProcessed) {
						$rProbeArguments[] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
					}

					$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
					$rProbeOptions = implode(' ', self::getArguments($rProbeArguments, $rProtocol, 'fetch'));
					$rFetchOptions = implode(' ', self::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));

					// === SKIP FFPROBE FEATURE ===
					// Feature for streams with corrupted PMT where ffprobe reports incorrect codecs
					// (e.g., AC3 with sample_rate=0, channels=0 when actual audio is AAC ADTS)
					$rSkipFFProbe = false;
					foreach ($rStream['stream_arguments'] as $rArg) {
						if ($rArg['argument_key'] == 'skip_ffprobe' && $rArg['value'] == 1) {
							$rSkipFFProbe = true;
							break;
						}
					}

					if ($rSkipFFProbe) {
						$rFFProbeOutput = array(
							'codecs' => array(
								'video' => array('codec_name' => 'h264', 'codec_type' => 'video', 'height' => 1080),
								'audio' => array('codec_name' => 'aac', 'codec_type' => 'audio')
							),
							'container' => 'mpegts'
						);
						error_log('[XC_VM] Stream ' . $rStreamID . ': FFProbe skipped');
						echo 'Got stream information via skip_ffprobe (assumed h264/aac)' . "\n";

						// Ensure $rSource is defined for cache after the loop
						if (empty($rSource)) {
							$rSource = is_array($rSources) && count($rSources) > 0 ? $rSources[0] : $rStreamSource;
						}
						break;
					}
					// === END SKIP FFPROBE FEATURE ===

					if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource)) && time() - filemtime(CACHE_TMP_PATH . md5($rSource)) <= 300) {
						$rFFProbeOutput = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . md5($rStreamSource)));

						if ($rFFProbeOutput && (isset($rFFProbeOutput['streams']) || isset($rFFProbeOutput['codecs']))) {
							echo 'Got stream information via cache' . "\n";

							break;
						}
					} else {
						if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource))) {
							$rFromCache = false;
						}
					}

					if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
						if ($rIsXC_VM && self::$rSettings['api_probe']) {
							$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ':' . $rURLInfo['port'] . '/probe/' . base64_encode($rURLInfo['path']);
							$rFFProbeOutput = json_decode(self::getURL($rProbeURL), true);

							if ($rFFProbeOutput && isset($rFFProbeOutput['codecs'])) {
								echo 'Got stream information via API' . "\n";

								break;
							}
						}

						$rProbeCmd = str_replace(array('{FETCH_OPTIONS}', '{CONCAT}', '{STREAM_SOURCE}'), array($rProbeOptions, ($rStream['stream_info']['type_key'] == 'created_live' && !$rStream['server_info']['parent_id'] ? '-safe 0 -f concat' : ''), escapeshellarg($rStreamSource)), $rFFProbee);
						$rFFProbeOutput = json_decode(shell_exec($rProbeCmd), true);

						if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
							echo 'Got stream information via ffprobe' . "\n";

							break;
						}
					}
				}
				if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
					if (!isset($rFFProbeOutput['codecs'])) {
						$rFFProbeOutput = self::parseFFProbe($rFFProbeOutput);
					}

					if (empty($rFFProbeOutput)) {
						self::$db->query("UPDATE `streams_servers` SET `progress_info` = '',`to_analyze` = 0,`pid` = -1,`stream_status` = 1 WHERE `server_id` = ? AND `stream_id` = ?", SERVER_ID, $rStreamID);

						return 0;
					}

					if (!$rFromCache) {
						file_put_contents(CACHE_TMP_PATH . md5($rSource), igbinary_serialize($rFFProbeOutput));
					}
				}

				$rExternalPush = json_decode($rStream['stream_info']['external_push'], true);
				$rProgressURL = 'http://127.0.0.1:' . intval(self::$rServers[SERVER_ID]['http_broadcast_port']) . '/progress?stream_id=' . intval($rStreamID);

				if (empty($rStream['stream_info']['custom_ffmpeg'])) {
					if ($rLoopback) {
						$rOptions = '{FETCH_OPTIONS}';
					} else {
						$rOptions = '{GPU} {FETCH_OPTIONS}';
					}

					if ($rStream['stream_info']['stream_all'] == 1) {
						$rMap = '-map 0 -copy_unknown ';
					} else {
						if (!empty($rStream['stream_info']['custom_map'])) {
							$rMap = escapeshellcmd($rStream['stream_info']['custom_map']) . ' -copy_unknown ';
						} else {
							if ($rStream['stream_info']['type_key'] == 'radio_streams') {
								$rMap = '-map 0:a? ';
							} else {
								$rMap = '';
							}
						}
					}

					if (($rStream['stream_info']['gen_timestamps'] == 1 || empty($rProtocol)) && $rStream['stream_info']['type_key'] != 'created_live') {
						$rGenPTS = '-fflags +genpts -async 1';
					} else {
						if (in_array($rFFProbeOutput['codecs']['audio']['codec_name'], array('ac3', 'eac3')) && self::$rSettings['dts_legacy_ffmpeg']) {
							self::$rFFMPEG_CPU = FFMPEG_BIN_40;
							self::$rFFPROBE = FFPROBE_BIN_40;
						}

						// Use -nofix_dts only for FFmpeg 4.0, newer versions don't support it
						$rNoFix = (self::$rFFMPEG_CPU == FFMPEG_BIN_40 ? '-nofix_dts' : '');
						// For newer FFmpeg versions, use equivalent timestamp handling
						$rGenPTS = $rNoFix . ' -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0';
					}

					if (!$rStream['server_info']['parent_id'] && ($rStream['stream_info']['read_native'] == 1 || stristr($rFFProbeOutput['container'], 'hls') && self::$rSettings['read_native_hls'] || empty($rProtocol) || stristr($rFFProbeOutput['container'], 'mp4') || stristr($rFFProbeOutput['container'], 'matroska'))) {
						$rReadNative = '-re';
					} else {
						$rReadNative = '';
					}

					if (!$rStream['server_info']['parent_id'] && $rStream['stream_info']['enable_transcode'] == 1 && $rStream['stream_info']['type_key'] != 'created_live') {
						if ($rStream['stream_info']['transcode_profile_id'] == -1) {
							$rStream['stream_info']['transcode_attributes'] = array_merge(self::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), json_decode($rStream['stream_info']['transcode_attributes'], true));
						} else {
							$rStream['stream_info']['transcode_attributes'] = json_decode($rStream['stream_info']['profile_options'], true);
						}
					} else {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? self::$rFFMPEG_GPU : self::$rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . ((self::$rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err ' . $rOptions . ' {GEN_PTS} {READ_NATIVE} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -progress "' . $rProgressURL . '" {CONCAT} -i {STREAM_SOURCE} {LOGO} ';

					if (!array_key_exists('-acodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-acodec'] = 'copy';
					}

					if (!array_key_exists('-vcodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-vcodec'] = 'copy';
					}

					if (!array_key_exists('-scodec', $rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes']['-sn'] = '';
					}
				} else {
					$rStream['stream_info']['transcode_attributes'] = array();
					$rFFMPEG = ((stripos($rStream['stream_info']['custom_ffmpeg'], 'nvenc') !== false ? self::$rFFMPEG_GPU : self::$rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . ((self::$rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -progress "' . $rProgressURL . '" ' . $rStream['stream_info']['custom_ffmpeg'];
				}

				$rLLODOptions = ($rLLOD && !$rLoopback ? '-fflags nobuffer -flags low_delay -strict experimental' : '');
				$rOutputs = array();

				if ($rLoopback) {
					$rOptions = '{MAP}';
					$rFLVOptions = '{MAP}';
					$rMap = '-map 0 -copy_unknown ';
				} else {
					$rOptions = '{MAP} {LLOD}';
					$rFLVOptions = '{MAP} {AAC_FILTER}';
				}

				$rKeyFrames = (self::$rSettings['ignore_keyframes'] ? '+split_by_time' : '');
				$rOutputs['mpegts'][] = $rOptions . ' -individual_header_trailer 0 -f hls -hls_time ' . intval(self::$rSegmentSettings['seg_time']) . ' -hls_list_size ' . intval(self::$rSegmentSettings['seg_list_size']) . ' -hls_delete_threshold ' . intval(self::$rSegmentSettings['seg_delete_threshold']) . ' -hls_flags delete_segments+discont_start+omit_endlist' . $rKeyFrames . ' -hls_segment_type mpegts -hls_segment_filename "' . STREAMS_PATH . intval($rStreamID) . '_%d.ts" "' . STREAMS_PATH . intval($rStreamID) . '_.m3u8" ';

				if ($rStream['stream_info']['rtmp_output'] == 1) {
					$rOutputs['flv'][] = $rFLVOptions . ' -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:' . intval(self::$rServers[$rStream['server_info']['server_id']]['rtmp_port']) . '/live/' . intval($rStreamID) . '?password=' . urlencode(self::$rSettings['live_streaming_pass']) . ' ';
				}

				if (!empty($rExternalPush[SERVER_ID])) {
					foreach ($rExternalPush[SERVER_ID] as $rPushURL) {
						$rOutputs['flv'][] = $rFLVOptions . ' -f flv -flvflags no_duration_filesize ' . escapeshellarg($rPushURL) . ' ';
					}
				}

				$rLogoOptions = (isset($rStream['stream_info']['transcode_attributes'][16]) && !$rLoopback ? $rStream['stream_info']['transcode_attributes'][16]['cmd'] : '');
				$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
				$rInputCodec = '';

				$supportedCodecs = ['h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'];
				$videoCodec = $rFFProbeOutput['codecs']['video']['codec_name'];

				if (!empty($rGPUOptions) && in_array($videoCodec, $supportedCodecs)) {
					$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
				}

				if (0 >= $rStream['stream_info']['delay_minutes'] || $rStream['server_info']['parent_id']) {
					foreach ($rOutputs as $rOutputCommands) {
						foreach ($rOutputCommands as $rOutputCommand) {
							if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
								$rFFMPEG .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
							}

							$rFFMPEG .= implode(' ', self::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
							$rFFMPEG .= $rOutputCommand;
						}
					}
				} else {
					$rSegmentStart = 0;
					$m3u8File = DELAY_PATH . $rStreamID . '_.m3u8';
					$oldM3u8File = DELAY_PATH . intval($rStreamID) . '_.m3u8_old';


					if (file_exists($m3u8File)) {
						$rFile = file($m3u8File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

						if (!is_array($rFile) || count($rFile) < 2) {
							// The file is empty or unreadable — safe exit
							return;
						}

						$lastLine = $rFile[count($rFile) - 1];
						$prevLine = $rFile[count($rFile) - 2];

						if (stristr($lastLine, $rStreamID . '_')) {
							if (preg_match('/_(.*?)\.ts/', $lastLine, $rMatches)) {
								$rSegmentStart = intval($rMatches[1]) + 1;
							}
						} else {
							if (preg_match('/_(.*?)\.ts/', $prevLine, $rMatches)) {
								$rSegmentStart = intval($rMatches[1]) + 1;
							}
						}


						if (file_exists($oldM3u8File)) {
							file_put_contents($oldM3u8File, file_get_contents($oldM3u8File) . file_get_contents($m3u8File));
							shell_exec("sed -i '/EXTINF\\|.ts/!d' " . escapeshellarg($oldM3u8File));
						} else {
							copy($m3u8File, $oldM3u8File);
						}
					}

					$rFFMPEG .= implode(' ', self::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rFFMPEG .= '{MAP} -individual_header_trailer 0 -f hls -hls_time ' . intval(self::$rSegmentSettings['seg_time']) . ' -hls_list_size ' . intval($rStream['stream_info']['delay_minutes']) * 6 . ' -hls_delete_threshold 4 -start_number ' . $rSegmentStart . ' -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "' . DELAY_PATH . intval($rStreamID) . '_%d.ts" "' . DELAY_PATH . intval($rStreamID) . '_.m3u8" ';

					$rSleepTime = $rStream['stream_info']['delay_minutes'] * 60;

					if ($rSegmentStart > 0) {
						$rSleepTime -= ($rSegmentStart - 1) * 10;

						if ($rSleepTime > 0) {
						} else {
							$rSleepTime = 0;
						}
					}
				}

				$rFFMPEG .= ' >/dev/null 2>>' . STREAMS_PATH . intval($rStreamID) . '.errors & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid';
				$rFFMPEG = str_replace(array('{FETCH_OPTIONS}', '{GEN_PTS}', '{STREAM_SOURCE}', '{MAP}', '{READ_NATIVE}', '{CONCAT}', '{AAC_FILTER}', '{GPU}', '{INPUT_CODEC}', '{LOGO}', '{LLOD}'), array((empty($rStream['stream_info']['custom_ffmpeg']) ? $rFetchOptions : ''), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rGenPTS : ''), escapeshellarg($rStreamSource), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rMap : ''), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : ''), ($rStream['stream_info']['type_key'] == 'created_live' && !$rStream['server_info']['parent_id'] ? '-safe 0 -f concat' : ''), (!stristr($rFFProbeOutput['container'], 'flv') && $rFFProbeOutput['codecs']['audio']['codec_name'] == 'aac' && $rStream['stream_info']['transcode_attributes']['-acodec'] == 'copy' ? '-bsf:a aac_adtstoasc' : ''), $rGPUOptions, $rInputCodec, $rLogoOptions, $rLLODOptions), $rFFMPEG);
				shell_exec($rFFMPEG);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);
				$rKey = openssl_random_pseudo_bytes(16);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
				$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
				$rIV = openssl_random_pseudo_bytes($rIVSize);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
				$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));

				if ($rStream['stream_info']['tv_archive_server_id'] == SERVER_ID) {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'archive.php ' . intval($rStreamID) . ' >/dev/null 2>/dev/null & echo $!');
				}

				if ($rStream['stream_info']['vframes_server_id'] == SERVER_ID) {
					self::startThumbnail($rStreamID);
				}

				$rDelayEnabled = 0 < $rStream['stream_info']['delay_minutes'] && !$rStream['server_info']['parent_id'];
				$rDelayStartAt = ($rDelayEnabled ? time() + $rSleepTime : 0);

				if ($rStream['stream_info']['enable_transcode']) {
					$rFFProbeOutput = array();
				}

				$rCompatible = 0;
				$rAudioCodec = $rVideoCodec = $rResolution = null;

				if ($rFFProbeOutput) {
					$rCompatible = intval(self::checkCompatibility($rFFProbeOutput));
					$rAudioCodec = ($rFFProbeOutput['codecs']['audio']['codec_name'] ?: null);
					$rVideoCodec = ($rFFProbeOutput['codecs']['video']['codec_name'] ?: null);
					$rResolution = ($rFFProbeOutput['codecs']['video']['height'] ?: null);

					if ($rResolution) {
						$rResolution = self::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
					}
				}

				self::$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`audio_codec` = ?, `video_codec` = ?, `resolution` = ?,`compatible` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', $rDelayStartAt, time(), json_encode($rFFProbeOutput), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rPID, json_encode(array()), $rSource, $rStreamID, SERVER_ID);
				self::updateStream($rStreamID);
				$rPlaylist = (!$rDelayEnabled ? STREAMS_PATH . $rStreamID . '_.m3u8' : DELAY_PATH . $rStreamID . '_.m3u8');

				return array('main_pid' => $rPID, 'stream_source' => $rRealSource, 'delay_enabled' => $rDelayEnabled, 'parent_id' => $rStream['server_info']['parent_id'], 'delay_start_at' => $rDelayStartAt, 'playlist' => $rPlaylist, 'transcode' => $rStream['stream_info']['enable_transcode'], 'offset' => $rOffset);
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	public static function getArguments($rArguments, $rProtocol, $rType) {
		$rReturn = array();
		if (empty($rArguments)) {
		} else {
			foreach ($rArguments as $rArgument_id => $rArgument) {
				if ($rArgument['argument_cat'] == $rType && (is_null($rArgument['argument_wprotocol']) || stristr($rProtocol, $rArgument['argument_wprotocol']) || is_null($rProtocol))) {
					if ($rArgument['argument_key'] != 'cookie') {
					} else {
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
		$rIPs = array('127.0.0.1', $_SERVER['SERVER_ADDR']);
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
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe'))) {
			} else {
				$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
				if ($rCommand != 'XC_VMDelay[' . $rStreamID . ']') {
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
	public static function isProcessRunning($rPID, $rEXE = null) {
		if (!empty($rPID)) {
			clearstatcache(true);
			if (!(file_exists('/proc/' . $rPID) && is_readable('/proc/' . $rPID . '/exe') && strpos(basename(readlink('/proc/' . $rPID . '/exe')), basename($rEXE)) === 0) && $rEXE) {
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
		if ($rType == 1 || $rType == 5 || $rType == 4) {
			$rMaxW = 96;
			$rMaxH = 32;
		} else {
			if ($rType == 2) {
				$rMaxW = 58;
				$rMaxH = 32;
			} else {
				if ($rType == 5) {
					$rMaxW = 32;
					$rMaxH = 64;
				} else {
					return false;
				}
			}
		}
		list($rExtension) = explode('.', strtolower(pathinfo($rImage)['extension']));
		if (!in_array($rExtension, array('png', 'jpg', 'jpeg'))) {
		} else {
			$rImagePath = IMAGES_PATH . 'admin/' . md5($rImage) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;
			if (file_exists($rImagePath)) {
			} else {
				if (self::isAbsoluteUrl($rImage)) {
					$rActURL = $rImage;
				} else {
					$rActURL = IMAGES_PATH . basename($rImage);
				}
				list($rWidth, $rHeight) = getimagesize($rActURL);
				$rImageSize = self::getImageSizeKeepAspectRatio($rWidth, $rHeight, $rMaxW, $rMaxH);
				if (!($rImageSize['width'] && $rImageSize['height'])) {
				} else {
					$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
					if ($rExtension == 'png') {
						$rImage = imagecreatefrompng($rActURL);
					} else {
						$rImage = imagecreatefromjpeg($rActURL);
					}
					imagealphablending($rImageP, false);
					imagesavealpha($rImageP, true);
					imagecopyresampled($rImageP, $rImage, 0, 0, 0, 0, $rImageSize['width'], $rImageSize['height'], $rWidth, $rHeight);
					imagepng($rImageP, $rImagePath);
				}
			}
			if (!file_exists($rImagePath)) {
			} else {
				return true;
			}
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
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		curl_setopt($ch, CURLOPT_URL, $rURL);
		curl_setopt($ch, CURLOPT_USERAGENT, 'XC_VM/' . XC_VM_VERSION);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, $rWait);
		$rReturn = curl_exec($ch);
		curl_close($ch);
		return $rReturn;
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
	public static function getCapacity($rProxy = false) {
		$rFile = ($rProxy ? 'proxy_capacity' : 'servers_capacity');
		if (self::$rSettings['redis_handler'] && $rProxy && self::$rSettings['split_by'] == 'maxclients') {
			self::$rSettings['split_by'] == 'guar_band';
		}
		if (self::$rSettings['redis_handler']) {
			$rRows = array();
			$rMulti = self::$redis->multi();
			foreach (array_keys(self::$rServers) as $rServerID) {
				if (self::$rServers[$rServerID]['server_online']) {
					$rMulti->zCard((($rProxy ? 'PROXY#' : 'SERVER#')) . $rServerID);
				}
			}
			$rResults = $rMulti->exec();
			$i = 0;
			foreach (array_keys(self::$rServers) as $rServerID) {
				if (self::$rServers[$rServerID]['server_online']) {
					$rRows[$rServerID] = array('online_clients' => ($rResults[$i] ?: 0));
					$i++;
				}
			}
		} else {
			if ($rProxy) {
				self::$db->query('SELECT `proxy_id`, COUNT(*) AS `online_clients` FROM `lines_live` WHERE `proxy_id` <> 0 AND `hls_end` = 0 GROUP BY `proxy_id`;');
				$rRows = self::$db->get_rows(true, 'proxy_id');
			} else {
				self::$db->query('SELECT `server_id`, COUNT(*) AS `online_clients` FROM `lines_live` WHERE `server_id` <> 0 AND `hls_end` = 0 GROUP BY `server_id`;');
				$rRows = self::$db->get_rows(true, 'server_id');
			}
		}
		if (self::$rSettings['split_by'] == 'band') {
			$rServerSpeed = array();
			foreach (array_keys(self::$rServers) as $rServerID) {
				$rServerHardware = json_decode(self::$rServers[$rServerID]['server_hardware'], true);
				if (!empty($rServerHardware['network_speed'])) {
					$rServerSpeed[$rServerID] = (float) $rServerHardware['network_speed'];
				} else {
					if (0 < self::$rServers[$rServerID]['network_guaranteed_speed']) {
						$rServerSpeed[$rServerID] = self::$rServers[$rServerID]['network_guaranteed_speed'];
					} else {
						$rServerSpeed[$rServerID] = 1000;
					}
				}
			}
			foreach ($rRows as $rServerID => $rRow) {
				$rCurrentOutput = intval(self::$rServers[$rServerID]['watchdog']['bytes_sent'] / 125000);
				$rRows[$rServerID]['capacity'] = (float) ($rCurrentOutput / (($rServerSpeed[$rServerID] ?: 1000)));
			}
		} else {
			if (self::$rSettings['split_by'] == 'maxclients') {
				foreach ($rRows as $rServerID => $rRow) {
					$rRows[$rServerID]['capacity'] = (float) ($rRow['online_clients'] / ((self::$rServers[$rServerID]['total_clients'] ?: 1)));
				}
			} else {
				if (self::$rSettings['split_by'] == 'guar_band') {
					foreach ($rRows as $rServerID => $rRow) {
						$rCurrentOutput = intval(self::$rServers[$rServerID]['watchdog']['bytes_sent'] / 125000);
						$rRows[$rServerID]['capacity'] = (float) ($rCurrentOutput / ((self::$rServers[$rServerID]['network_guaranteed_speed'] ?: 1)));
					}
				} else {
					foreach ($rRows as $rServerID => $rRow) {
						$rRows[$rServerID]['capacity'] = $rRow['online_clients'];
					}
				}
			}
		}
		file_put_contents(CACHE_TMP_PATH . $rFile, json_encode($rRows), LOCK_EX);
		return $rRows;
	}
	public static function getConnections($rServerID = null, $rUserID = null, $rStreamID = null) {
		if (!self::$rSettings['redis_handler'] || is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		if (self::$rSettings['redis_handler']) {
			if ($rServerID) {
				$rKeys = self::$redis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
			} else {
				if ($rUserID) {
					$rKeys = self::$redis->zRangeByScore('LINE#' . $rUserID, '-inf', '+inf');
				} else {
					if ($rStreamID) {
						$rKeys = self::$redis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
					} else {
						$rKeys = self::$redis->zRangeByScore('LIVE', '-inf', '+inf');
					}
				}
			}
			if (count($rKeys) > 0) {
				return array($rKeys, array_map('igbinary_unserialize', self::$redis->mGet($rKeys)));
			}
		} else {
			$rWhere = array();
			if (empty($rServerID)) {
			} else {
				$rWhere[] = 't1.server_id = ' . intval($rServerID);
			}
			if (empty($rUserID)) {
			} else {
				$rWhere[] = 't1.user_id = ' . intval($rUserID);
			}
			$rExtra = '';
			if (0 >= count($rWhere)) {
			} else {
				$rExtra = 'WHERE ' . implode(' AND ', $rWhere);
			}
			$rQuery = 'SELECT t2.*,t3.*,t5.bitrate,t1.*,t1.uuid AS `uuid` FROM `lines_live` t1 LEFT JOIN `lines` t2 ON t2.id = t1.user_id LEFT JOIN `streams` t3 ON t3.id = t1.stream_id LEFT JOIN `streams_servers` t5 ON t5.stream_id = t1.stream_id AND t5.server_id = t1.server_id ' . $rExtra . ' ORDER BY t1.activity_id ASC';
			self::$db->query($rQuery);
			return self::$db->get_rows(true, 'user_id', false);
		}
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
		$rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
		$rReturn = ($rBouquetMap[$rStreamID] ?: array());
		unset($rBouquetMap);
		return $rReturn;
	}
	public static function updateStream($rStreamID, $rForce = false) {
		if (self::$rCached) {
			self::$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', self::getMainID(), json_encode(array('type' => 'update_stream', 'id' => $rStreamID)));
			if (self::$db->get_row()['count'] != 0) {
			} else {
				self::$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', self::getMainID(), time(), json_encode(array('type' => 'update_stream', 'id' => $rStreamID)));
			}
			return true;
		}
		return false;
	}
	public static function updateStreams($rStreamIDs) {
		if (self::$rCached) {
			self::$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', self::getMainID(), json_encode(array('type' => 'update_streams', 'id' => $rStreamIDs)));
			if (self::$db->get_row()['count'] != 0) {
			} else {
				self::$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', self::getMainID(), time(), json_encode(array('type' => 'update_streams', 'id' => $rStreamIDs)));
			}
			return true;
		}
		return false;
	}
	public static function deleteLine($rUserID, $rForce = false) {
		self::updateLine($rUserID, $rForce);
	}
	public static function deleteLines($rUserIDs, $rForce = false) {
		self::updateLines($rUserIDs);
	}
	public static function updateLine($rUserID, $rForce = false) {
		if (self::$rCached) {
			self::$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', self::getMainID(), json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			if (self::$db->get_row()['count'] != 0) {
			} else {
				self::$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', self::getMainID(), time(), json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			}
			return true;
		}
		return false;
	}
	public static function updateLines($rUserIDs) {
		if (self::$rCached) {
			self::$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', self::getMainID(), json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			if (self::$db->get_row()['count'] != 0) {
			} else {
				self::$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', self::getMainID(), time(), json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			}
			return true;
		}
		return false;
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
	public static function getProxyFor($rServerID) {
		return (array_rand(array_keys(self::getProxies($rServerID, false))) ?: null);
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
	public static function setSignal($rKey, $rData) {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
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
		return null;
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
	public static function getUserConnections($rUserIDs, $rCount = false, $rKeysOnly = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rRedis = self::$redis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rRedis->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf');
		}
		$rGroups = $rRedis->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rUserIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 >= count($rKeys)) {
				} else {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		$rRedisKeys = array_unique($rRedisKeys);
		if (!$rKeysOnly) {
			if ($rCount) {
			} else {
				foreach (self::$redis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['user_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}
	public static function getServerConnections($rServerIDs, $rProxy = false, $rCount = false, $rKeysOnly = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rRedis = self::$redis->multi();
		foreach ($rServerIDs as $rServerID) {
			$rRedis->zRevRangeByScore(($rProxy ? 'PROXY#' . $rServerID : 'SERVER#' . $rServerID), '+inf', '-inf');
		}
		$rGroups = $rRedis->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rServerIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 >= count($rKeys)) {
				} else {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		$rRedisKeys = array_unique($rRedisKeys);
		if (!$rKeysOnly) {
			if ($rCount) {
			} else {
				foreach (self::$redis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['server_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}
	public static function getFirstConnection($rUserIDs) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rRedis = self::$redis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rRedis->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf', array('limit' => array(0, 1)));
		}
		$rGroups = $rRedis->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if (0 >= count($rKeys)) {
			} else {
				$rRedisKeys[] = $rKeys[0];
			}
		}
		foreach (self::$redis->mGet(array_unique($rRedisKeys)) as $rRow) {
			$rRow = igbinary_unserialize($rRow);
			$rConnectionMap[$rRow['user_id']] = $rRow;
		}
		return $rConnectionMap;
	}
	public static function getStreamConnections($rStreamIDs, $rGroup = true, $rCount = false) {
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rRedis = self::$redis->multi();
		foreach ($rStreamIDs as $rStreamID) {
			$rRedis->zRevRangeByScore('STREAM#' . $rStreamID, '+inf', '-inf');
		}
		$rGroups = $rRedis->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rStreamIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 >= count($rKeys)) {
				} else {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		if ($rCount) {
		} else {
			foreach (self::$redis->mGet(array_unique($rRedisKeys)) as $rRow) {
				$rRow = igbinary_unserialize($rRow);
				if ($rGroup) {
					$rConnectionMap[$rRow['stream_id']][] = $rRow;
				} else {
					$rConnectionMap[$rRow['stream_id']][$rRow['server_id']][] = $rRow;
				}
			}
		}
		return $rConnectionMap;
	}
	public static function getRedisConnections($rUserID = null, $rServerID = null, $rStreamID = null, $rOpenOnly = false, $rCountOnly = false, $rGroup = true, $rHLSOnly = false) {
		$rReturn = ($rCountOnly ? array(0, 0) : array());
		if (is_object(self::$redis)) {
		} else {
			self::connectRedis();
		}
		$rUniqueUsers = array();
		$rUserID = (0 < intval($rUserID) ? intval($rUserID) : null);
		$rServerID = (0 < intval($rServerID) ? intval($rServerID) : null);
		$rStreamID = (0 < intval($rStreamID) ? intval($rStreamID) : null);
		if ($rUserID) {
			$rKeys = self::$redis->zRangeByScore('LINE#' . $rUserID, '-inf', '+inf');
		} else {
			if ($rStreamID) {
				$rKeys = self::$redis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
			} else {
				if ($rServerID) {
					$rKeys = self::$redis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
				} else {
					$rKeys = self::$redis->zRangeByScore('LIVE', '-inf', '+inf');
				}
			}
		}
		if (0 >= count($rKeys)) {
		} else {
			foreach (self::$redis->mGet(array_unique($rKeys)) as $rRow) {
				$rRow = igbinary_unserialize($rRow);
				if (!($rServerID && $rServerID != $rRow['server_id']) && !($rStreamID && $rStreamID != $rRow['stream_id']) && !($rUserID && $rUserID != $rRow['user_id']) && !($rHLSOnly && $rRow['container'] == 'hls')) {
					$rUUID = ($rRow['user_id'] ?: $rRow['hmac_id'] . '_' . $rRow['hmac_identifier']);
					if ($rCountOnly) {
						$rReturn[0]++;
						$rUniqueUsers[] = $rUUID;
					} else {
						if ($rGroup) {
							if (isset($rReturn[$rUUID])) {
							} else {
								$rReturn[$rUUID] = array();
							}
							$rReturn[$rUUID][] = $rRow;
						} else {
							$rReturn[] = $rRow;
						}
					}
				}
			}
		}
		if (!$rCountOnly) {
		} else {
			$rReturn[1] = count(array_unique($rUniqueUsers));
		}
		return $rReturn;
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
		if ($rProxied) {
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
	public static function checkCompatibility($rData) {
		if (is_array($rData)) {
		} else {
			$rData = json_decode($rData, true);
		}
		$rAudioCodecs = array('aac', 'libfdk_aac', 'opus', 'vorbis', 'pcm_s16le', 'mp2', 'mp3', 'flac', null);
		$rVideoCodecs = array('h264', 'vp8', 'vp9', 'ogg', 'av1', null);
		if (!self::$rSettings['player_allow_hevc']) {
		} else {
			$rVideoCodecs[] = 'hevc';
			$rVideoCodecs[] = 'h265';
			$rAudioCodecs[] = 'ac3';
		}
		return ($rData['codecs']['audio']['codec_name'] || $rData['codecs']['video']['codec_name']) && in_array(strtolower($rData['codecs']['audio']['codec_name']), $rAudioCodecs) && in_array(strtolower($rData['codecs']['video']['codec_name']), $rVideoCodecs);
	}
	public static function getNearest($arr, $search) {
		$closest = null;
		foreach ($arr as $item) {
			if ($closest === null || abs($item - $search) < abs($search - $closest)) {
				$closest = $item;
			}
		}
		return $closest;
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
		$rReturn = array();
		$rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : array());
		foreach ($rData as $rItem) {
			if ($rStartDate && !($rStartDate < $rItem['end'] && $rItem['start'] < $rFinishDate)) {
			} else {
				if ($rByID) {
					$rReturn[$rItem['id']] = $rItem;
				} else {
					$rReturn[] = $rItem;
				}
			}
		}
		return $rReturn;
	}
	public static function getEPGs($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
		$rReturn = array();
		foreach ($rStreamIDs as $rStreamID) {
			$rReturn[$rStreamID] = self::getEPG($rStreamID, $rStartDate, $rFinishDate);
		}
		return $rReturn;
	}
	public static function getProgramme($rStreamID, $rProgrammeID) {
		$rData = self::getEPG($rStreamID, null, null, true);
		if (!isset($rData[$rProgrammeID])) {
		} else {
			return $rData[$rProgrammeID];
		}
	}
	public static function getNetwork($rInterface = null) {
		$rReturn = array();
		if (file_exists(LOGS_TMP_PATH . 'network')) {
			$rNetwork = json_decode(file_get_contents(LOGS_TMP_PATH . 'network'), true);
			foreach ($rNetwork as $rLine) {
				if (!($rInterface && $rLine[0] != $rInterface) && !($rLine[0] == 'lo' || !$rInterface && substr($rLine[0], 0, 4) == 'bond')) {
					$rReturn[$rLine[0]] = array('in_bytes' => intval($rLine[1] / 2), 'in_packets' => $rLine[2], 'in_errors' => $rLine[3], 'out_bytes' => intval($rLine[4] / 2), 'out_packets' => $rLine[5], 'out_errors' => $rLine[6]);
				}
			}
		}
		return $rReturn;
	}
	public static function getProxies($rServerID, $rOnline = true) {
		$rReturn = array();
		foreach (self::$rServers as $rProxyID => $rServerInfo) {
			if ($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline)) {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}
		return $rReturn;
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

	public static function getMemory() {
		try {
			$rFree = explode("\n", file_get_contents('/proc/meminfo'));
			$rMemory = array();

			foreach ($rFree as $rLine) {
				if (empty($rLine)) continue;

				// PHP 8 fix: Better string parsing
				$rParts = preg_split('/\s+/', trim($rLine));
				if (count($rParts) >= 2) {
					$rKey = rtrim($rParts[0], ':');
					$rValue = intval($rParts[1]);
					$rMemory[$rKey] = $rValue;
				}
			}

			if (isset($rMemory['MemTotal'], $rMemory['MemAvailable'])) {
				return array(
					'total' => $rMemory['MemTotal'],
					'free' => $rMemory['MemAvailable'],
					'used' => $rMemory['MemTotal'] - $rMemory['MemAvailable']
				);
			}

			return array('total' => 0, 'free' => 0, 'used' => 0);
		} catch (Exception $e) {
			return array('total' => 0, 'free' => 0, 'used' => 0);
		}
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
