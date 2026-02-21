<?php

class ServerService {
	public static function process($rData, $db) {
		if (!hasPermissions('adv', 'edit_server')) {
			exit();
		}

		$rServer = getStreamingServersByID($rData['edit']);
		if (!$rServer) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}

		$rArray = verifyPostTable('servers', $rData, true);
		$rPorts = array('http' => array(), 'https' => array());

		foreach ($rData['http_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && $rPort != $rData['rtmp_port']) {
				$rPorts['http'][] = $rPort;
			}
		}
		$rPorts['http'] = array_unique($rPorts['http']);
		unset($rData['http_broadcast_ports']);

		foreach ($rData['https_broadcast_ports'] as $rPort) {
			if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535 && !in_array($rPort, ($rPorts['http'] ?: array())) && !in_array($rPort, ($rPorts['https'] ?: array())) && $rPort != $rData['rtmp_port']) {
				$rPorts['https'][] = $rPort;
			}
		}
		$rPorts['https'] = array_unique($rPorts['https']);
		unset($rData['https_broadcast_ports']);
		$rArray['http_broadcast_port'] = null;
		$rArray['http_ports_add'] = null;

		if (count($rPorts['http']) > 0) {
			$rArray['http_broadcast_port'] = $rPorts['http'][0];
			if (1 < count($rPorts['http'])) {
				$rArray['http_ports_add'] = implode(',', array_slice($rPorts['http'], 1, count($rPorts['http']) - 1));
			}
		}

		$rArray['https_broadcast_port'] = null;
		$rArray['https_ports_add'] = null;
		if (count($rPorts['https']) > 0) {
			$rArray['https_broadcast_port'] = $rPorts['https'][0];
			if (1 < count($rPorts['https'])) {
				$rArray['https_ports_add'] = implode(',', array_slice($rPorts['https'], 1, count($rPorts['https']) - 1));
			}
		}

		foreach (array('enable_gzip', 'timeshift_only', 'enable_https', 'random_ip', 'enable_geoip', 'enable_isp', 'enabled', 'enable_proxy') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		if ($rServer['is_main']) {
			$rArray['enabled'] = 1;
		}

		if (isset($rData['geoip_countries'])) {
			$rArray['geoip_countries'] = array();
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = array();
		}

		if (isset($rData['isp_names'])) {
			$rArray['isp_names'] = array();
			foreach ($rData['isp_names'] as $rISP) {
				$rArray['isp_names'][] = strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rISP)));
			}
		} else {
			$rArray['isp_names'] = array();
		}

		if (isset($rData['domain_name'])) {
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}
		if (0 < strlen($rData['private_ip']) && !filter_var($rData['private_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		$rArray['total_services'] = $rData['total_services'];
		$rPrepare = prepareArray($rArray);
		$rPrepare['data'][] = $rData['edit'];
		$rQuery = 'UPDATE `servers` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rInsertID = $rData['edit'];
		$rPorts = array('http' => array(), 'https' => array());
		foreach (array_merge(array(intval($rArray['http_broadcast_port'])), explode(',', $rArray['http_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['http'][] = intval($rPort);
			}
		}
		foreach (array_merge(array(intval($rArray['https_broadcast_port'])), explode(',', $rArray['https_ports_add'])) as $rPort) {
			if (is_numeric($rPort) && 0 < $rPort && $rPort <= 65535) {
				$rPorts['https'][] = intval($rPort);
			}
		}
		changePort($rInsertID, 0, $rPorts['http'], false);
		changePort($rInsertID, 1, $rPorts['https'], false);
		changePort($rInsertID, 2, array($rArray['rtmp_port']), false);
		setServices($rInsertID, intval($rArray['total_services']), true);

		if (!empty($rArray['governor'])) {
			setGovernor($rInsertID, $rArray['governor']);
		}
		if (!empty($rArray['sysctl'])) {
			setSysctl($rInsertID, $rArray['sysctl']);
		}
		if (file_exists(CACHE_TMP_PATH . 'servers')) {
			unlink(CACHE_TMP_PATH . 'servers');
		}

		$rFS = getFreeSpace($rInsertID);
		$rMounted = false;
		foreach ($rFS as $rMount) {
			if ($rMount['mount'] == rtrim(STREAMS_PATH, '/')) {
				$rMounted = true;
				break;
			}
		}

		if ($rData['disable_ramdisk'] && $rMounted) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'disable_ramdisk')));
		} else if (!$rData['disable_ramdisk'] && !$rMounted) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rInsertID, time(), json_encode(array('action' => 'enable_ramdisk')));
		}

		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
	}

	public static function processProxy($rData, $db) {
		if (!hasPermissions('adv', 'edit_server')) {
			exit();
		}

		$rArray = overwriteData(getStreamingServersByID($rData['edit']), $rData);
		foreach (array('enable_https', 'random_ip', 'enable_geoip', 'enabled') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]);
		}

		if (isset($rData['geoip_countries'])) {
			$rArray['geoip_countries'] = array();
			foreach ($rData['geoip_countries'] as $rCountry) {
				$rArray['geoip_countries'][] = $rCountry;
			}
		} else {
			$rArray['geoip_countries'] = array();
		}

		if (isset($rData['domain_name'])) {
			$rArray['domain_name'] = implode(',', $rData['domain_name']);
		} else {
			$rArray['domain_name'] = '';
		}

		if (strlen($rData['server_ip']) == 0 || !filter_var($rData['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}
		if (checkExists('servers', 'server_ip', $rData['server_ip'], 'id', $rArray['id'])) {
			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		}

		$rArray['server_type'] = 1;
		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			if (file_exists(CACHE_TMP_PATH . 'servers')) {
				unlink(CACHE_TMP_PATH . 'servers');
			}
			if (file_exists(CACHE_TMP_PATH . 'proxy_servers')) {
				unlink(CACHE_TMP_PATH . 'proxy_servers');
			}
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function install($rData, $db, $rServers, $rProxyServers) {
		if (!hasPermissions('adv', 'add_server')) {
			exit();
		}

		$rParentIDs = array();
		$rUpdateSysctl = isset($rData['update_sysctl']) ? 1 : 0;
		$rPrivateIP = isset($rData['use_private_ip']) ? 1 : 0;

		if ($rData['type'] == 1) {
			foreach (json_decode($rData['parent_id'], true) as $rServerID) {
				if ($rServers[$rServerID]['server_type'] == 0) {
					$rParentIDs[] = intval($rServerID);
				}
			}
		}

		if (isset($rData['edit'])) {
			if ($rData['type'] == 1) {
				$rServer = $rProxyServers[$rData['edit']];
			} else {
				$rServer = $rServers[$rData['edit']];
			}
			if (!$rServer) {
				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			$db->query('UPDATE `servers` SET `status` = 3, `parent_id` = ? WHERE `id` = ?;', '[' . implode(',', $rParentIDs) . ']', $rServer['id']);
			if ($rData['type'] == 1) {
				$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
			} else {
				$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rServer['id']) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rServer['id']) . '.install" 2>/dev/null &';
			}
			shell_exec($rCommand);
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rServer['id']));
		}

		$rArray = verifyPostTable('servers', $rData);
		$rArray['status'] = 3;
		unset($rArray['id']);

		if (strlen($rArray['server_ip']) == 0 || !filter_var($rArray['server_ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		if ($rData['type'] == 1) {
			$rArray['server_type'] = 1;
			$rArray['parent_id'] = '[' . implode(',', $rParentIDs) . ']';
		} else {
			$rArray['server_type'] = 0;
		}

		$rArray['network_interface'] = 'auto';
		$rPrepare = prepareArray($rArray);
		$rQuery = 'INSERT INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rInsertID = $db->last_insert_id();
		if ($rArray['server_type'] == 0) {
			CoreUtilities::grantPrivileges($rArray['server_ip']);
		}

		if ($rData['type'] == 1) {
			$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' ' . intval($rData['http_broadcast_port']) . ' ' . intval($rData['https_broadcast_port']) . ' ' . intval($rUpdateSysctl) . ' ' . intval($rPrivateIP) . ' "' . json_encode($rParentIDs) . '" > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
		} else {
			$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . intval($rData['type']) . ' ' . intval($rInsertID) . ' ' . intval($rData['ssh_port']) . ' ' . escapeshellarg($rData['root_username']) . ' ' . escapeshellarg($rData['root_password']) . ' 80 443 ' . intval($rUpdateSysctl) . ' > "' . BIN_PATH . 'install/' . intval($rInsertID) . '.install" 2>/dev/null &';
		}

		shell_exec($rCommand);
		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
	}

	public static function reorder($rData, $db) {
		$rPostServers = json_decode($rData['server_order'], true);
		if (count($rPostServers) > 0) {
			foreach ($rPostServers as $rOrder => $rPostServer) {
				$db->query('UPDATE `servers` SET `order` = ? WHERE `id` = ?;', intval($rOrder) + 1, $rPostServer['id']);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}
}
