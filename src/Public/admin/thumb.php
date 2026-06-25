<?php

use XcVm\Domain\Server\ServerRepository;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Config\SettingsManager;
/**
 * Thumbnail generator endpoint
 *
 * @package XC_VM_Web_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

register_shutdown_function('shutdown');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);
$rIP = NetworkUtils::getUserIP();

if (!empty(RequestManager::getAll()['uitoken'])) {
	$rTokenData = json_decode(Encryption::decrypt(RequestManager::getAll()['uitoken'], SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA), true);
	RequestManager::update('stream', $rTokenData['stream_id']);
	$rIPMatch = (SettingsManager::getAll()['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenData['ip']), 0, -1)) == implode('.', array_slice(explode('.', NetworkUtils::getUserIP()), 0, -1)) : $rTokenData['ip'] == NetworkUtils::getUserIP());

	if ($rTokenData['expires'] >= time() && $rIPMatch) {
	} else {
		generate404();
	}
} else {
	generate404();
}

$db = new DatabaseHandler();
DatabaseFactory::set($db);
$rStreamID = intval(RequestManager::getAll()['stream']);
$rStream = array();
$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);

if ($db->num_rows() > 0) {
} else {
	generate404();
}

$rStream = $db->get_row();

if (SERVER_ID == $rStream['vframes_server_id']) {
	if (file_exists(STREAMS_PATH . $rStreamID . '_.jpg') && time() - filemtime(STREAMS_PATH . $rStreamID . '_.jpg') < 60) {
		header('Age: ' . intval(time() - filemtime(STREAMS_PATH . $rStreamID . '_.jpg')));
		header('Content-type: image/jpg');
		echo file_get_contents(STREAMS_PATH . $rStreamID . '_.jpg');

		exit();
	}

	generate404();
} else {
	$rURL = ServerRepository::getAll()[$rStream['vframes_server_id']]['site_url'];
	header('Location: ' . $rURL . 'admin/thumb?stream=' . $rStreamID . '&aid=' . intval(RequestManager::getAll()['aid']) . '&uitoken=' . urlencode(RequestManager::getAll()['uitoken']) . '&expires=' . intval(RequestManager::getAll()['expires']));

	exit();
}

function shutdown() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
