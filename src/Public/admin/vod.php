<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\AdminStreamToken;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Admin VOD handler
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

if (!empty(RequestManager::get('uitoken'))) {
	$rToken = AdminStreamToken::decode(RequestManager::get('uitoken'), SettingsManager::get('live_streaming_pass'));

	if ($rToken === null || !$rToken->isValid((bool) SettingsManager::get('ip_subnet_match'), $rIP)) {
		generate404();
	}

	RequestManager::update('stream', $rToken->streamId . '.' . $rToken->container);
} elseif (!in_array($rIP, ServerRepository::getAllowedIPs())) {
	generate404();
} elseif (empty(RequestManager::get('password')) || SettingsManager::get('live_streaming_pass') != RequestManager::get('password')) {
	generate404();
}

if (empty(RequestManager::get('stream'))) {
	generate404();
}

$db = new DatabaseHandler();
DatabaseFactory::set($db);
$rStream = pathinfo(RequestManager::get('stream'));
$rStreamID = intval($rStream['filename']);
$rExtension = $rStream['extension'];
$db->query("SELECT t1.* FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.pid IS NOT NULL AND t2.server_id = ? INNER JOIN `streams_types` t3 ON t3.type_id = t1.type AND t3.type_key IN ('movie', 'series') WHERE t1.`id` = ?", SERVER_ID, $rStreamID);

if (SettingsManager::get('use_buffer') == 0) {
	header('X-Accel-Buffering: no');
}

if (0 < $db->num_rows()) {
	$rInfo = $db->get_row();
	$db->close_mysql();
	$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;

	if (file_exists($rRequest)) {
		header('Content-Type: ' . StreamUtils::containerMimeType($rInfo['target_container']));
		$rFile = @fopen($rRequest, 'rb');
		$rSize = filesize($rRequest);
		$rLength = $rSize;
		$rStart = 0;
		$rEnd = $rSize - 1;
		header('Accept-Ranges: 0-' . $rLength);

		if (isset($_SERVER['HTTP_RANGE'])) {
			$rRangeStart = $rStart;
			$rRangeEnd = $rEnd;
			list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);

			if (strpos($range, ',') === false) {
				if ($range == '-') {
					$rRangeStart = $rSize - substr($range, 1);
				} else {
					$range = explode('-', $range);
					$rRangeStart = $range[0];
					$rRangeEnd = (isset($range[1]) && is_numeric($range[1]) ? $range[1] : $rSize);
				}

				$rRangeEnd = ($rEnd < $rRangeEnd ? $rEnd : $rRangeEnd);

				if (!($rRangeEnd < $rRangeStart || $rSize - 1 < $rRangeStart || $rSize <= $rRangeEnd)) {
					$rStart = $rRangeStart;
					$rEnd = $rRangeEnd;
					$rLength = $rEnd - $rStart + 1;
					fseek($rFile, $rStart);
					header('HTTP/1.1 206 Partial Content');
				} else {
					header('HTTP/1.1 416 Requested Range Not Satisfiable');
					header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

					exit();
				}
			} else {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);

				exit();
			}
		}

		header('Content-Range: bytes ' . $rStart . '-' . $rEnd . '/' . $rSize);
		header('Content-Length: ' . $rLength);
		$rBuffer = 8192;

		while (!feof($rFile) && ($p = ftell($rFile)) <= $rEnd) {
			$rResponse = stream_get_line($rFile, $rBuffer);
			echo $rResponse;
		}
		fclose($rFile);

		exit();
	}
}

function shutdown() {
	global $db;

	if (is_object($db)) {
		$db->close_mysql();
	}
}
