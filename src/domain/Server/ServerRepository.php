<?php

class ServerRepository {
	public static function getAll($db, $rSettings, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'servers', 10);
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

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'servers', $rServers);
		}

		return $rServers;
	}
}
