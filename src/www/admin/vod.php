<?php

register_shutdown_function('shutdown');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);
require '../init.php';
$rIP = CoreUtilities::getUserIP();

if (!empty(CoreUtilities::$rRequest['uitoken'])) {
	$rTokenData = json_decode(CoreUtilities::decryptData(CoreUtilities::$rRequest['uitoken'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);
	CoreUtilities::$rRequest['stream'] = $rTokenData['stream_id'] . '.' . $rTokenData['container'];
	$rIPMatch = (CoreUtilities::$rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenData['ip']), 0, -1)) == implode('.', array_slice(explode('.', CoreUtilities::getUserIP()), 0, -1)) : $rTokenData['ip'] == CoreUtilities::getUserIP());

	if ($rTokenData['expires'] >= time() && $rIPMatch) {
	} else {
		generate404();
	}
} else {
	if (!in_array($rIP, CoreUtilities::getAllowedIPs())) {
		generate404();
	} else {
		if (!(empty(CoreUtilities::$rRequest['password']) || CoreUtilities::$rSettings['live_streaming_pass'] != CoreUtilities::$rRequest['password'])) {
		} else {



			generate404();
		}
	}
}

if (!empty(CoreUtilities::$rRequest['stream'])) {
} else {
	generate404();
}

$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
CoreUtilities::$db = &$db;
$rStream = pathinfo(CoreUtilities::$rRequest['stream']);
$rStreamID = intval($rStream['filename']);
$rExtension = $rStream['extension'];
$db->query("SELECT t1.* FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.pid IS NOT NULL AND t2.server_id = ? INNER JOIN `streams_types` t3 ON t3.type_id = t1.type AND t3.type_key IN ('movie', 'series') WHERE t1.`id` = ?", SERVER_ID, $rStreamID);

if (CoreUtilities::$rSettings['use_buffer'] != 0) {
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
