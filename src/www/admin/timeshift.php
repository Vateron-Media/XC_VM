<?php

register_shutdown_function('shutdown');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);
require '../init.php';
$rIP = CoreUtilities::getUserIP();

if (CoreUtilities::$rSettings['use_buffer'] != 0) {
} else {
	header('X-Accel-Buffering: no');
}

if (!empty(CoreUtilities::$rRequest['uitoken'])) {
	$rTokenData = json_decode(CoreUtilities::decryptData(CoreUtilities::$rRequest['uitoken'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);
	CoreUtilities::$rRequest['stream'] = $rTokenData['stream_id'];
	CoreUtilities::$rRequest['extension'] = 'm3u8';

	if (!isset($rTokenData['start'])) {
	} else {
		CoreUtilities::$rRequest['start'] = $rTokenData['start'];
	}

	if (!isset($rTokenData['duration'])) {
	} else {
		CoreUtilities::$rRequest['duration'] = $rTokenData['duration'];
	}

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

$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
CoreUtilities::$db = &$db;
$rPassword = CoreUtilities::$rSettings['live_streaming_pass'];
$rStreamID = intval(CoreUtilities::$rRequest['stream']);
$rExtension = CoreUtilities::$rRequest['extension'];

if (!empty(CoreUtilities::$rRequest['segment'])) {
} else {
	$rStartDate = CoreUtilities::$rRequest['start'];
	$rDuration = CoreUtilities::$rRequest['duration'];

	if (!is_numeric($rStartDate)) {
		if (substr_count($rStartDate, '-') == 1) {
			list($rDate, $rTime) = explode('-', $rStartDate);
			$rYear = substr($rDate, 0, 4);
			$rMonth = substr($rDate, 4, 2);
			$rDay = substr($rDate, 6, 2);
			$rMinutes = 0;
			$rHour = $rTime;
		} else {
			list($rDate, $rTime) = explode(':', $rStartDate);
			list($rYear, $rMonth, $rDay) = explode('-', $rDate);
			list($rHour, $rMinutes) = explode('-', $rTime);
		}

		$rTimestamp = mktime($rHour, $rMinutes, 0, $rMonth, $rDay, $rYear);
	} else {
		$rTimestamp = $rStartDate;
	}
}

$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.`id` = ?', SERVER_ID, $rStreamID);

if (0 < $db->num_rows()) {
	$rChannelInfo = $db->get_row();
	$db->close_mysql();

	if (!empty(CoreUtilities::$rRequest['segment'])) {
	} else {
		$rQueue = array();
		$rFile = ARCHIVE_PATH . $rStreamID . '/' . date('Y-m-d:H-i', $rTimestamp) . '.ts';

		if (!(empty($rStreamID) || empty($rTimestamp) || empty($rDuration))) {
		} else {
			generate404();
		}

		if (file_exists($rFile) && is_readable($rFile)) {
		} else {
			generate404();
		}

		$rQueue = array();
		$i = 0;

		while ($i < $rDuration) {
			$rFile = ARCHIVE_PATH . $rStreamID . '/' . date('Y-m-d:H-i', $rTimestamp + $i * 60) . '.ts';

			if (!file_exists($rFile)) {
			} else {
				$rQueue[] = array('filename' => $rFile, 'filesize' => filesize($rFile));
			}

			$i++;
		}

		if (count($rQueue) != 0) {
		} else {
			generate404();
		}
	}

	switch ($rExtension) {
		case 'm3u8':
			if (empty(CoreUtilities::$rRequest['segment'])) {
				$rOutput = '#EXTM3U' . "\n";
				$rOutput .= '#EXT-X-VERSION:3' . "\n";
				$rOutput .= '#EXT-X-TARGETDURATION:60' . "\n";
				$rOutput .= '#EXT-X-MEDIA-SEQUENCE:0' . "\n";
				$rOutput .= '#EXT-X-PLAYLIST-TYPE:VOD' . "\n";

				foreach ($rQueue as $rKey => $rItem) {
					$rOutput .= '#EXTINF:60.0,' . "\n";

					if (!empty(CoreUtilities::$rRequest['uitoken'])) {
						$rOutput .= '/admin/timeshift?extension=m3u8&segment=' . basename($rItem['filename']) . '&uitoken=' . CoreUtilities::$rRequest['uitoken'] . "\n";
					} else {
						$rOutput .= '/admin/timeshift?extension=m3u8&stream=' . $rStreamID . '&segment=' . basename($rItem['filename']) . '&password=' . $rPassword . "\n";
					}
				}
				$rOutput .= '#EXT-X-ENDLIST';
				ob_end_clean();
				header('Content-Type: application/x-mpegurl');
				header('Content-Length: ' . strlen($rOutput));
				echo $rOutput;

				exit();
			} else {
				$rSegment = ARCHIVE_PATH . $rStreamID . '/' . str_replace(array('\\', '/'), '', urldecode(CoreUtilities::$rRequest['segment']));

				if (file_exists($rSegment)) {
					$rBytes = filesize($rSegment);
					header('Content-Length: ' . $rBytes);
					header('Content-Type: video/mp2t');
					readfile($rSegment);
				} else {
					generate404();
				}
			}

			break;

		case 'ts':
			header('Content-Type: video/mp2t');
			$rLength = $rSize = getlength($rQueue);
			header('Accept-Ranges: 0-' . $rLength);
			$rStart = 0;
			$rEnd = $rSize - 1;

			if (!isset($_SERVER['HTTP_RANGE'])) {
			} else {
				$rRangeStart = $rStart;
				$rRangeEnd = $rEnd;
				list(, $rRange) = explode('=', $_SERVER['HTTP_RANGE'], 2);

				if (strpos($rRange, ',') === false) {
					if ($rRange == '-') {
						$rRangeStart = $rSize - substr($rRange, 1);
					} else {
						$rRange = explode('-', $rRange);
						$rRangeStart = $rRange[0];
						$rRangeEnd = (isset($rRange[1]) && is_numeric($rRange[1]) ? $rRange[1] : $rSize);
					}

					$rRangeEnd = ($rEnd < $rRangeEnd ? $rEnd : $rRangeEnd);

					if (!($rRangeEnd < $rRangeStart || $rSize - 1 < $rRangeStart || $rSize <= $rRangeEnd)) {
						$rStart = $rRangeStart;
						$rEnd = $rRangeEnd;
						$rLength = $rEnd - $rStart + 1;
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
			$rStartFrom = 0;

			if (0 >= $rStart) {
			} else {
				$rStartFrom = floor($rStart / ($rSize / count($rQueue)));
			}

			$rFirstFile = false;
			$rSeekTo = 0;
			$rSizeToDate = 0;
			$rBuffer = CoreUtilities::$rSettings['read_buffer_size'];

			foreach ($rQueue as $rKey => $rItem) {
				$rSizeToDate += $rItem['filesize'];

				if ($rFirstFile || 0 >= $rStartFrom) {
				} else {
					if ($rKey < $rStartFrom) {
					} else {
						$rFirstFile = true;
						$rSeekTo = $rStart - $rSizeToDate;
					}
				}

				$rFP = fopen($rItem['filename'], 'rb');
				fseek($rFP, $rSeekTo);

				while (!feof($rFP)) {
					$rPosition = ftell($rFP);
					$rResponse = stream_get_line($rFP, $rBuffer);
					echo $rResponse;
				}

				if (!is_resource($rFP)) {
				} else {
					fclose($rFP);
				}

				$rSeekTo = 0;
			}

			break;
	}
} else {
	generate404();
}

function getLength($rQueue) {
	$rLength = 0;

	foreach ($rQueue as $item) {
		$rLength += $item['filesize'];
	}

	return $rLength;
}

function shutdown() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
