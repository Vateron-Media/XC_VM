<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Admin API entry point
 *
 * @package XC_VM_Web_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

register_shutdown_function('shutdown');
set_time_limit(0);
$rIP = NetworkUtils::getUserIP();

if (in_array($rIP, ServerRepository::getAllowedIPs()) || in_array($rIP, SettingsManager::get('api_ips'))) {
} else {
	generate404();
}

if (empty(SettingsManager::get('api_pass')) || RequestManager::get('api_pass') == SettingsManager::get('api_pass')) {
} else {
	generate404();
}

$db = new DatabaseHandler();
DatabaseFactory::set($db);
$rAction = (!empty(RequestManager::get('action')) ? RequestManager::get('action') : '');
$rSubAction = (!empty(RequestManager::get('sub')) ? RequestManager::get('sub') : '');
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

	// Movies and episodes are VOD content and start/stop through the node's
	// `action=vod` endpoint. The admin UI sends action=movie / action=episode with
	// a singular stream_id/server_id; other callers use action=vod with
	// stream_ids[]/servers[]. Accept every combination here (movie/episode used to
	// fall through to `default` and return an empty 200 — the streams never started).
	case 'movie':
	case 'episode':
	case 'vod':
		switch ($rSubAction) {
			case 'start':
			case 'stop':
				$rReq = RequestManager::getAll();
				$rStreamIDs = !empty($rReq['stream_ids'])
					? array_map('intval', $rReq['stream_ids'])
					: array(intval($rReq['stream_id'] ?? 0));
				$rServerID = intval($rReq['server_id'] ?? -1);
				$rServerIDs = !empty($rReq['servers'])
					? array_map('intval', $rReq['servers'])
					: ($rServerID > 0 ? array($rServerID) : array_keys($rAllServers));
				$rForce = ($rReq['force'] ?? false);
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rPostData = array('function' => $rSubAction, 'stream_ids' => $rStreamIDs);
					if ($rSubAction === 'start') {
						$rPostData['force'] = $rForce;
					}
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=vod', 'postdata' => $rPostData);
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();
		}

		break;

	case 'stream':
		switch ($rSubAction) {
			case 'start':
				$rStreamIDs = array_map('intval', RequestManager::get('stream_ids') ?? array());
				$rServerIDs = (empty(RequestManager::get('servers')) ? array_keys($rAllServers) : array_map('intval', RequestManager::get('servers')));
				$rURLs = array();

				foreach ($rServerIDs as $rServerID) {
					$rURLs[$rServerID] = array('url' => $rAllServers[$rServerID]['api_url_ip'] . '&action=stream', 'postdata' => array('function' => $rSubAction, 'stream_ids' => $rStreamIDs));
				}
				CurlClient::getMultiCURL($rURLs);
				echo json_encode(array('result' => true));

				exit();

			case 'stop':
				$rStreamIDs = array_map('intval', RequestManager::get('stream_ids') ?? array());
				$rServerIDs = (empty(RequestManager::get('servers')) ? array_keys($rAllServers) : array_map('intval', RequestManager::get('servers')));
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
				if (!empty(RequestManager::get('username')) && !empty(RequestManager::get('password'))) {
					$rUsername = RequestManager::get('username');
					$rPassword = RequestManager::get('password');
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
