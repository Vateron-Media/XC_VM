<?php

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
require '../init.php';
$rIP = NetworkUtils::getUserIP();

if (!empty(RequestManager::getAll()['uitoken'])) {
	$rTokenData = json_decode(Encryption::decrypt(RequestManager::getAll()['uitoken'], SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA), true);
	RequestManager::update('stream', $rTokenData['stream_id'] . '.' . $rTokenData['container']);
	$rIPMatch = (SettingsManager::getAll()['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenData['ip']), 0, -1)) == implode('.', array_slice(explode('.', NetworkUtils::getUserIP()), 0, -1)) : $rTokenData['ip'] == NetworkUtils::getUserIP());

	if ($rTokenData['expires'] >= time() && $rIPMatch) {
	} else {
		generate404();
	}
} else {
	if (!in_array($rIP, ServerRepository::getAllowedIPs())) {
		generate404();
	} else {
		if (!(empty(RequestManager::getAll()['password']) || SettingsManager::getAll()['live_streaming_pass'] != RequestManager::getAll()['password'])) {
		} else {



			generate404();
		}
	}
}

if (!empty(RequestManager::getAll()['stream'])) {
} else {
	generate404();
}

$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
DatabaseFactory::set($db);
$rStream = pathinfo(RequestManager::getAll()['stream']);
$rStreamID = intval($rStream['filename']);
$rExtension = $rStream['extension'];
$db->query("SELECT t1.* FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.pid IS NOT NULL AND t2.server_id = ? INNER JOIN `streams_types` t3 ON t3.type_id = t1.type AND t3.type_key IN ('movie', 'series') WHERE t1.`id` = ?", SERVER_ID, $rStreamID);

if (SettingsManager::getAll()['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if (0 >= $db->num_rows()) {
} else {
	$rInfo = $db->get_row();
	$db->close_mysql();
	$rRequest = VOD_PATH . $rStreamID . '.' . $rExtension;

	if (!file_exists($rRequest)) {
	} else {
		switch ($rInfo['target_container']) {
			case 'mp4':
				header('Content-type: video/mp4');

				break;

			case 'mkv':
				header('Content-type: video/x-matroska');

				break;

			case 'avi':
				header('Content-type: video/x-msvideo');

				break;

			case '3gp':
				header('Content-type: video/3gpp');

				break;

			case 'flv':
				header('Content-type: video/x-flv');

				break;

			case 'wmv':
				header('Content-type: video/x-ms-wmv');

				break;

			case 'mov':
				header('Content-type: video/quicktime');

				break;

			case 'ts':
				header('Content-type: video/mp2t');

				break;

			default:
				header('Content-Type: application/octet-stream');
		}
		$rFile = @fopen($rRequest, 'rb');
		$rSize = filesize($rRequest);
		$rLength = $rSize;
		$rStart = 0;
		$rEnd = $rSize - 1;
		header('Accept-Ranges: 0-' . $rLength);

		if (!isset($_SERVER['HTTP_RANGE'])) {
		} else {
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

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
