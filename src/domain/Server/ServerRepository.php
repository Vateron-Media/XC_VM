<?php

class ServerRepository {
	public static function getAll($rForce = false) {
		global $db, $rSettings;
		if (!$rForce) {
			$rCache = FileCache::getCache('servers', 10);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		if (empty($_SERVER['REQUEST_SCHEME'])) {
			$_SERVER['REQUEST_SCHEME'] = 'http';
		}

		$db->query('SELECT * FROM `servers`');
		$rServers = array();
		$rOnlineStatus = array(1);

		foreach ($db->get_rows() as $rRow) {
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
			$rRow['api_url_ip'] = 'http://' . escapeshellcmd($rRow['server_ip']) . ':' . intval($rRow['http_broadcast_port']) . '/api?password=' . urlencode($rSettings['live_streaming_pass']);
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

		FileCache::setCache('servers', $rServers);

		return $rServers;
	}

	public static function getAllSimple() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `servers` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rRow['server_online'] = in_array($rRow['status'], array(1, 3)) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				$rReturn[$rRow['id']] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getStreamingSimple($rPermissions, $type = 'online') {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `servers` WHERE `server_type` = 0 ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				if (isset($rPermissions['is_reseller']) && $rPermissions['is_reseller']) {
					$rRow['server_name'] = 'Server #' . ($rRow['id'] ?? 'unknown');
				}

				$rRow['server_online'] = in_array($rRow['status'], array(1, 3)) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				if (!isset($rRow['order'])) {
					$rRow['order'] = 0;
				}
				if ($rRow['server_online'] || $type == 'all') {
					$rReturn[$rRow['id']] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	public static function getProxySimple($rPermissions, $rOnline = false) {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `servers` WHERE `server_type` = 1 ORDER BY `id` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				if (isset($rPermissions['is_reseller']) && $rPermissions['is_reseller']) {
					$rRow['server_name'] = 'Proxy #' . $rRow['id'];
				}

				$rRow['server_online'] = in_array($rRow['status'], array(1, 3)) && time() - $rRow['last_check_ago'] <= 90 || $rRow['is_main'];
				if (!($rRow['server_online'] == 0 && $rOnline)) {
					$rReturn[$rRow['id']] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	public static function getFreeSpace($rServerID) {
		$rReturn = array();
		$rLines = json_decode(systemapirequest($rServerID, array('action' => 'get_free_space')), true);

		if (!empty($rLines)) {
			array_shift($rLines);
		}

		foreach ($rLines as $rLine) {
			$rSplit = explode(' ', preg_replace('!\s+!', ' ', trim($rLine)));
			if (0 < strlen($rSplit[0]) && strpos($rSplit[5], 'xc_vm') !== false || $rSplit[5] == '/') {
				$rReturn[] = array('filesystem' => $rSplit[0], 'size' => $rSplit[1], 'used' => $rSplit[2], 'avail' => $rSplit[3], 'percentage' => $rSplit[4], 'mount' => implode(' ', array_slice($rSplit, 5, count($rSplit) - 5)));
			}
		}

		return $rReturn;
	}

	public static function getStreamsRamdisk($rServerID) {
		$response = systemapirequest($rServerID, array('action' => 'streams_ramdisk'));
		$rReturn = json_decode($response, true);

		if (!is_array($rReturn)) {
			return array();
		}

		if (empty($rReturn['result'])) {
			return array();
		}

		return ($rReturn['streams'] ?? array());
	}

	public static function killPID($rServerID, $rPID) {
		systemapirequest($rServerID, array('action' => 'kill_pid', 'pid' => $rPID));
	}

	public static function getRTMPStats($rServerID) {
		return json_decode(systemapirequest($rServerID, array('action' => 'rtmp_stats')), true);
	}

	public static function checkSource($rServers, $rFFProbe, $rServerID, $rFilename) {
		$rAPI = $rServers[intval($rServerID)]['api_url_ip'] . '&action=getFile&filename=' . urlencode($rFilename);
		$rCommand = 'timeout 10 ' . $rFFProbe . ' -user_agent "Mozilla/5.0" -show_streams -v quiet "' . $rAPI . '" -of json';
		return json_decode(shell_exec($rCommand), true);
	}

	public static function getSSLLog($rServerID) {
		global $rServers;
		$rAPI = $rServers[intval($rServerID)]['api_url_ip'] . '&action=getFile&filename=' . urlencode(BIN_PATH . 'certbot/logs/xc_vm.log');
		return json_decode(file_get_contents($rAPI), true);
	}

	public static function freeTemp($rServerID) {
		systemapirequest($rServerID, array('action' => 'free_temp'));
	}

	public static function freeStreams($rServerID) {
		systemapirequest($rServerID, array('action' => 'free_streams'));
	}

	public static function probeSource($rServerID, $rURL, $rUserAgent = null, $rProxy = null, $rCookies = null, $rHeaders = null) {
		return json_decode(systemapirequest($rServerID, array('action' => 'probe', 'url' => $rURL, 'user_agent' => $rUserAgent, 'http_proxy' => $rProxy, 'cookies' => $rCookies, 'headers' => $rHeaders), 30), true);
	}

	public static function deleteById($rID, $rReplaceWith = null) {
		global $db, $rSettings;
		$rServer = getStreamingServersByID($rID);

		if (!$rServer || $rServer['is_main']) {
			return false;
		}

		if ($rReplaceWith) {
			$db->query('UPDATE `streams_servers` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
			if (!$rSettings['redis_handler']) {
				$db->query('UPDATE `lines_live` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
			}
			$db->query('UPDATE `lines_activity` SET `server_id` = ? WHERE `server_id` = ?;', $rReplaceWith, $rID);
		} else {
			$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ?;', $rID);
			if (!$rSettings['redis_handler']) {
				$db->query('DELETE FROM `lines_live` WHERE `server_id` = ?;', $rID);
			}
			$db->query('UPDATE `lines_activity` SET `server_id` = 0 WHERE `server_id` = ?;', $rID);
		}

		$db->query('UPDATE `servers` SET `parent_id` = NULL, `enabled` = 0 WHERE `server_type` = 1 AND `parent_id` = ?;', $rID);
		$db->query('DELETE FROM `servers_stats` WHERE `server_id` = ?;', $rID);
		$db->query('DELETE FROM `servers` WHERE `id` = ?;', $rID);

		if ($rServer['server_type'] == 0) {
			BackupService::revokePrivileges($rServer['server_ip'], DatabaseFactory::get(), ConfigReader::getAll());
		}

		return true;
	}

	public static function getAllowedDomains($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('allowed_domains', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}
		$rDomains = array('127.0.0.1', 'localhost');
		$db->query('SELECT `server_ip`, `private_ip`, `domain_name` FROM `servers` WHERE `enabled` = 1;');
		foreach ($db->get_rows() as $rRow) {
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
		$db->query('SELECT `reseller_dns` FROM `users` WHERE `status` = 1;');
		foreach ($db->get_rows() as $rRow) {
			if (!empty($rRow['reseller_dns'])) {
				$rDomains[] = $rRow['reseller_dns'];
			}
		}
		$rDomains = array_filter(array_unique($rDomains));
		FileCache::setCache('allowed_domains', $rDomains);
		return $rDomains;
	}

	public static function getAllowedIPs($rForce = false) {
		global $rServers, $rSettings;
		if ($rForce) {
		} else {
			$rCache = FileCache::getCache('allowed_ips', 60);
			if ($rCache === false) {
			} else {
				return $rCache;
			}
		}
		$rIPs = array('127.0.0.1');
		$rServerAddr = ($_SERVER['SERVER_ADDR'] ?? null);
		if (!empty($rServerAddr)) {
			$rIPs[] = $rServerAddr;
		} elseif (isset($rServers[SERVER_ID]['server_ip']) && !empty($rServers[SERVER_ID]['server_ip'])) {
			$rIPs[] = $rServers[SERVER_ID]['server_ip'];
		}
		foreach ($rServers as $rServerID => $rServerInfo) {
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
		if (empty($rSettings['allowed_ips_admin'])) {
		} else {
			$rIPs = array_merge($rIPs, explode(',', $rSettings['allowed_ips_admin']));
		}
		FileCache::setCache('allowed_ips', $rIPs);
		return array_unique($rIPs);
	}

	public static function getLocalRTMPStats() {
		global $rServers;
		$rURL = $rServers[SERVER_ID]['rtmp_mport_url'] . 'stat';
		$rContext = stream_context_create(array('http' => array('timeout' => 1)));
		$rXML = file_get_contents($rURL, false, $rContext);
		return json_decode(json_encode(simplexml_load_string($rXML, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
	}

	public static function getPublicURL($rServerID = null, $rForceProtocol = null) {
		global $rSettings, $rServers;
		$rOriginatorID = null;
		if (isset($rServerID)) {
		} else {
			$rServerID = SERVER_ID;
		}
		if ($rForceProtocol) {
			$rProtocol = $rForceProtocol;
		} else {
			if (isset($_SERVER['SERVER_PORT']) && $rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = $rServers[$rServerID]['server_protocol'];
			}
		}
		if (!$rServers[$rServerID]) {
		} else {
			if (!$rServers[$rServerID]['enable_proxy']) {
			} else {
				$rProxyIDs = array_keys(ConnectionTracker::getProxies($rServerID));
				if (count($rProxyIDs) != 0) {
				} else {
					$rProxyIDs = array_keys(ConnectionTracker::getProxies($rServerID, false));
				}
				if (count($rProxyIDs) != 0) {
					$rOriginatorID = $rServerID;
					$rServerID = $rProxyIDs[array_rand($rProxyIDs)];
				} else {
					return '';
				}
			}
			$rHost = (defined('host') ? HOST : null);
			if ($rHost && in_array(strtolower($rHost), array_map('strtolower', $rServers[$rServerID]['domains']['urls']))) {
				$rDomain = $rHost;
			} else {
				$rDomain = (empty($rServers[$rServerID]['domain_name']) ? $rServers[$rServerID]['server_ip'] : explode(',', $rServers[$rServerID]['domain_name'])[0]);
			}
			$rServerURL = $rProtocol . '://' . $rDomain . ':' . $rServers[$rServerID][$rProtocol . '_broadcast_port'] . '/';
			if (!($rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && $rServers[$rOriginatorID]['is_main'] == 0)) {
			} else {
				$rServerURL .= md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA) . '/';
			}
			return $rServerURL;
		}
	}
}
