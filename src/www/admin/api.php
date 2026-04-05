<?php

register_shutdown_function('shutdown');
set_time_limit(0);
require '../init.php';
$rIP = NetworkUtils::getUserIP();

if (in_array($rIP, ServerRepository::getAllowedIPs()) || in_array($rIP, SettingsManager::getAll()['api_ips'])) {
} else {
	generate404();
}

if (empty(SettingsManager::getAll()['api_pass']) || RequestManager::getAll()['api_pass'] == SettingsManager::getAll()['api_pass']) {
} else {
	generate404();
}

$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
DatabaseFactory::set($db);
$rAction = (!empty(RequestManager::getAll()['action']) ? RequestManager::getAll()['action'] : '');
$rSubAction = (!empty(RequestManager::getAll()['sub']) ? RequestManager::getAll()['sub'] : '');
$rAllServers = ServerRepository::getAll();

switch ($rAction) {
	case 'server':
		switch ($rSubAction) {
			case 'list':
				$rOutput = array();

				foreach ($rAllServers as $rServerID => $rServerInfo) {
					$rOutput[] = array('id' => $rServerID, 'server_name' => $rServerInfo['server_name'], 'online' => $rServerInfo['server_online'], 'info' => json_decode($rServerInfo['server_hardware'], true));
				}
				echo json_encode($rOutput);

				break;
		}

		break;

	case 'vod':
		switch ($rSubAction) {
			case 'start':
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids'] ?? array());
				$rForce = (RequestManager::getAll()['force'] ?: false);
				$rServerIDs = (empty(RequestManager::getAll()['servers']) ? array_keys($rAllServers) : array_map('intval', RequestManager::getAll()['servers']));
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=vod', 'postdata' => array('function' => $rSubAction, 'stream_ids' => $rStreamIDs, 'force' => $rForce));
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();

			case 'stop':
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids'] ?? array());
				$rServerIDs = (empty(RequestManager::getAll()['servers']) ? array_keys($rAllServers) : array_map('intval', RequestManager::getAll()['servers']));
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=vod', 'postdata' => array('function' => $rSubAction, 'stream_ids' => $rStreamIDs));
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();
		}

		break;

	case 'stream':
		switch ($rSubAction) {
			case 'start':
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids'] ?? array());
				$rServerIDs = (empty(RequestManager::getAll()['servers']) ? array_keys($rAllServers) : array_map('intval', RequestManager::getAll()['servers']));
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=stream', 'postdata' => array('function' => $rSubAction, 'stream_ids' => $rStreamIDs));
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();

			case 'stop':
				$rStreamIDs = array_map('intval', RequestManager::getAll()['stream_ids'] ?? array());
				$rServerIDs = (empty(RequestManager::getAll()['servers']) ? array_keys($rAllServers) : array_map('intval', RequestManager::getAll()['servers']));
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=stream', 'postdata' => array('function' => $rSubAction, 'stream_ids' => $rStreamIDs));
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();

			case 'list':
				$rOutput = array();
				$db->query('SELECT id,stream_display_name FROM `streams` WHERE type <> 2');

				foreach ($db->get_rows() as $rRow) {
					$rOutput[] = array('id' => $rRow['id'], 'stream_name' => $rRow['stream_display_name']);
				}
				echo json_encode($rOutput);

				break;

			case 'offline':
				$db->query('SELECT t1.stream_status,t1.server_id,t1.stream_id  FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.type <> 2 WHERE t1.stream_status <> 0');
				$rStreams = $db->get_rows(true, 'stream_id', false, 'server_id');
				$rOutput = array();

				foreach ($rStreams as $rStreamID => $rStreamServers) {
					$rOutput[$rStreamID] = array_keys($rStreamServers);
				}
				echo json_encode($rOutput);

				break;

			case 'online':
				$db->query('SELECT t1.stream_status,t1.server_id,t1.stream_id FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.type <> 2 WHERE t1.pid > 0 AND t1.stream_status = 0');
				$rStreams = $db->get_rows(true, 'stream_id', false, 'server_id');
				$rOutput = array();

				foreach ($rStreams as $rStreamID => $rStreamServers) {
					$rOutput[$rStreamID] = array_keys($rStreamServers);
				}
				echo json_encode($rOutput);

				break;
		}

		break;

	case 'line':
		switch ($rSubAction) {
			case 'info':
				if (!empty(RequestManager::getAll()['username']) && !empty(RequestManager::getAll()['password'])) {
					$rUsername = RequestManager::getAll()['username'];
					$rPassword = RequestManager::getAll()['password'];
					$rUserInfo = UserRepository::getUserInfo(false, $rUsername, $rPassword, true, true);

					if (!empty($rUserInfo)) {
						echo json_encode(array('result' => true, 'user_info' => $rUserInfo));
					} else {
						echo json_encode(array('result' => false, 'error' => 'NOT EXISTS'));
					}
				} else {
					echo json_encode(array('result' => false, 'error' => 'PARAMETER ERROR (user/pass)'));
				}

				break;
		}

		break;

	case 'reg_user':
		switch ($rSubAction) {
			case 'list':
				$db->query('SELECT id,username,credits,group_id,group_name,last_login,date_registered,email,ip,status FROM `users` t1 INNER JOIN `users_groups` t2 ON t1.member_group_id = t2.group_id');
				$rResults = $db->get_rows();
				echo json_encode($rResults);

				break;
		}

		break;

	default:
		break;
}
function shutdown() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
