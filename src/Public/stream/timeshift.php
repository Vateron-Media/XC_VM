<?php

use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Server\ProxyRoute;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Cache\CacheReader;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\AsyncFileOperations;
use XcVm\Streaming\Auth\StreamAuth;
use XcVm\Streaming\Auth\StreamAuthMiddleware;
use XcVm\Streaming\Delivery\HttpRange;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Streaming\Fanout\FanoutMode;
use XcVm\Streaming\Lifecycle\ShutdownHandler;

/**
 * Timeshift stream endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

set_time_limit(0);
register_shutdown_function([ShutdownHandler::class, 'handle'], 'timeshift');
unset($rSettings['watchdog_data'], $rSettings['server_hardware']);

StreamAuthMiddleware::sendStreamHeaders($rSettings, $rServers);

$rCreateExpiration = 60;
$rProxyID = null;
$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rStartTime = time();

// A link is one plain value: a request that sends a list of them has none.
if (isset($rRequest['token']) && !is_array($rRequest['token'])) {
	$rTokenData = StreamAuthMiddleware::decryptToken($rRequest['token'], $rSettings, $rServers, $rIP);

	$rUsername = $rTokenData['username'];
	$rPassword = $rTokenData['password'];
	$rStreamID = $rTokenData['stream'];
	$rExtension = $rTokenData['extension'];
	$rStartDate = $rTokenData['start'];
	$rDuration = intval($rTokenData['duration']);
	$rRedirectID = $rTokenData['redirect_id'];
	$rOriginatorID = ($rTokenData['originator_id'] ?: null);
	$rUserInfo = $rTokenData['user_info'];
	$rActivityStart = $rTokenData['activity_start'];
	$rCountryCode = $rTokenData['country_code'];

	if (empty($rTokenData['http_range']) || isset($_SERVER['HTTP_RANGE'])) {
	} else {
		$_SERVER['HTTP_RANGE'] = $rTokenData['http_range'];
	}
} else {
	generateError('NO_TOKEN_SPECIFIED');
}

if ($rSettings['use_buffer'] == 0) {
	header('X-Accel-Buffering: no');
}

// A start is a Unix time, YYYYMMDD-HH or YYYY-MM-DD:HH-MM, with or without
// seconds (its minute is what is read): nothing else is read as one.
if (!(is_string($rStartDate) || is_int($rStartDate)) || !preg_match('/^(\d+|\d{8}-\d{1,2}|\d{4}-\d{1,2}-\d{1,2}:\d{1,2}-\d{1,2}(?:[:-]\d{1,2})?)\z/', (string) $rStartDate)) {
	generateError('NO_TIMESTAMP');
}

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
	$rTimestamp = intval($rStartDate);
}

$rFile = ARCHIVE_PATH . $rStreamID . '/' . gmdate('Y-m-d:H-i', $rTimestamp) . '.ts';

if (!(empty($rStreamID) || empty($rTimestamp) || empty($rDuration))) {
} else {
	generateError('NO_TIMESTAMP');
}

if (file_exists($rFile) && is_readable($rFile)) {
} else {
	generateError('ARCHIVE_DOESNT_EXIST');
}

$rQueue = [];

// No minute after the present one has been recorded: the scan ends there,
// however long a duration was asked for.
$rScan = min($rDuration, intdiv(time(), 60) - intdiv($rTimestamp, 60) + 1);

// Batch check files using async operations
for ($i = 0; $i < $rScan; $i++) {
	$rFile = ARCHIVE_PATH . $rStreamID . '/' . gmdate('Y-m-d:H-i', $rTimestamp + $i * 60) . '.ts';

	if (@stat($rFile) !== false) {
		$fileSize = AsyncFileOperations::getFileSize($rFile);
		if ($fileSize !== false) {
			$rQueue[] = ['filename' => $rFile, 'filesize' => $fileSize];
		}
	}
}

if (count($rQueue) != 0) {
} else {
	generateError('ARCHIVE_DOESNT_EXIST');
}

if ($rUserInfo) {
	// Use async file operations for offset file check
	$offsetFile = $rQueue[0]['filename'] . '.offset';
	$offsetContent = AsyncFileOperations::readFile($offsetFile);
	$rOffset = $offsetContent ? intval($offsetContent) : 0;

	if ($rOriginatorID) {
		$rServerID = $rOriginatorID;
		$rProxyID = $rRedirectID;
	} else {
		$rProxyID = null;
		$rServerID = ($rRedirectID ?: SERVER_ID);
	}

	ConnectionTracker::openStore($rSettings);

	$rConnection = null;

	switch ($rExtension) {
		case 'm3u8':
			$rConnection = ConnectionTracker::findByUuid($rSettings, $rTokenData['uuid'], '`activity_id`, `pid`, `user_ip`');

			if (!$rConnection) {
				if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || $rActivityStart + $rCreateExpiration >= StreamAuthMiddleware::mainNow($rServers)) {
				} else {
					generateError('TOKEN_EXPIRED');
				}

				$rLastRead = time() - intval($rServers[SERVER_ID]['time_offset']);
				$rConnectionData = ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'hls', 'pid' => null, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => $rLastRead, 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']];
				$rResult = ConnectionTracker::openRecord($rSettings, $rConnectionData, ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => 'hls', 'pid' => null, 'uuid' => $rTokenData['uuid'], 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_last_read' => $rLastRead], $rTokenData, intval($rServers[SERVER_ID]['time_offset']));
			} else {
				$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

				if ($rIPMatch || !$rSettings['restrict_same_ip']) {
				} else {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
					generateError('IP_MISMATCH');
				}

				$rResult = ConnectionTracker::updateLive($rSettings, $rConnection, ['hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset'])]);
			}

			if ($rResult) {
			} else {
				StreamAuth::refuseAdmission($rStreamID, $rUserInfo, $rIP, $rExtension, $rCountryCode, $rServerID, $rProxyID);
				DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP, $rSettings['redis_handler'] ? 'redis unavailable: connection tracking write failed' : $db->error());
				generateError('LINE_CREATE_FAIL');
			}

			StreamAuth::validateConnections($rUserInfo, null, null, $rIP, $rUserAgent, $rTokenData['uuid']);

			if ($rSettings['redis_handler']) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			// The connection id is 32 hex characters: decryptToken() refuses any other.
			// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			$rOutput = "#EXTM3U\n";
			$rOutput .= "#EXT-X-VERSION:3\n";
			$rOutput .= "#EXT-X-TARGETDURATION:60\n";
			$rOutput .= "#EXT-X-MEDIA-SEQUENCE:0\n";
			$rOutput .= "#EXT-X-PLAYLIST-TYPE:VOD\n";

			for ($i = 0; $i < count($rQueue); $i++) {
				$rOutput .= "#EXTINF:60.0,\n";
				// segment.php reads the link's fields by position: the start is written
				// as the time it was read as, the line's names encoded.
				$rOutput .= (($rProxyID ? '/' . ProxyRoute::segment((int) $rProxyID, (int) $rServerID, $rServers) : '')) . '/hls/' . ViewerKey::mintOwn('TS/' . rawurlencode((string) $rUsername) . '/' . rawurlencode((string) $rPassword) . '/' . $rIP . '/' . $rDuration . '/' . $rTimestamp . '/' . $rStreamID . '_' . basename($rQueue[$i]['filename']) . '_' . (($i == 0 ? $rOffset : 0)) . '/' . $rTokenData['uuid'] . '/' . $rServerID, $rSettings) . "\n";
			}
			$rOutput .= '#EXT-X-ENDLIST';
			// The connection id is 32 hex characters: decryptToken() refuses any other.
			// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			ob_end_clean();
			header('Content-Type: application/x-mpegurl');
			header('Content-Length: ' . strlen($rOutput));
			echo $rOutput;

			exit();
		default:
			// The minute files' bytes go to the xc_fanout daemon when it serves
			// files (FanoutClient::handOverFile): this worker is freed at once, and
			// the connection is the daemon's, recorded with pid 0 as a live viewer's.
			$rFileDaemon = !FanoutMode::legacyDelivery($rSettings) && LicenseGate::fanoutUsable() && FanoutClient::supportsFiles();
			$rConnPID = $rFileDaemon ? 0 : $rPID;
			// By its uuid alone: a Range request is not matched to another connection
			// of the line (the one it was matched to was a playlist viewer's).
			$rConnection = ConnectionTracker::findByUuid($rSettings, $rTokenData['uuid'], '`server_id`, `activity_id`, `pid`, `user_ip`, `hls_end`');

			if (!$rConnection) {
				// The connection id is 32 hex characters: decryptToken() refuses any other.
				// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
				if (file_exists(CONS_TMP_PATH . $rTokenData['uuid']) || $rActivityStart + $rCreateExpiration >= StreamAuthMiddleware::mainNow($rServers)) {
				} else {
					generateError('TOKEN_EXPIRED');
				}

				$rLastRead = time() - intval($rServers[SERVER_ID]['time_offset']);
				$rConnectionData = ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => $rConnPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => $rLastRead, 'on_demand' => 0, 'identity' => $rUserInfo['id'], 'uuid' => $rTokenData['uuid']];
				$rResult = ConnectionTracker::openRecord($rSettings, $rConnectionData, ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => $rServerID, 'proxy_id' => $rProxyID, 'user_agent' => $rUserAgent, 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => $rConnPID, 'uuid' => $rTokenData['uuid'], 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => '', 'hls_last_read' => $rLastRead], $rTokenData, intval($rServers[SERVER_ID]['time_offset']));
			} else {
				$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rConnection['user_ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rConnection['user_ip'] == $rIP);

				if ($rIPMatch || !$rSettings['restrict_same_ip']) {
				} else {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'IP_MISMATCH', $rIP);
					generateError('IP_MISMATCH');
				}

				// An ended connection's worker serves someone else now (ConnectionTracker::ended): never kill it.
				if (!(!ConnectionTracker::ended($rConnection) && ProcessManager::isRunning($rConnection['pid'], 'php-fpm') && $rPID != $rConnection['pid'] && is_numeric($rConnection['pid']) && 0 < $rConnection['pid'])) {
				} else {
					if ($rConnection['server_id'] == SERVER_ID) {
						posix_kill(intval($rConnection['pid']), 9);
					} else {
						SignalDispatcher::kill(intval($rConnection['server_id']), intval($rConnection['pid']), false, $db);
					}
				}

				$rResult = ConnectionTracker::updateLive($rSettings, $rConnection, ['pid' => $rConnPID, 'hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset'])]);
			}

			if ($rResult) {
			} else {
				StreamAuth::refuseAdmission($rStreamID, $rUserInfo, $rIP, $rExtension, $rCountryCode, $rServerID, $rProxyID);
				DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP, $rSettings['redis_handler'] ? 'redis unavailable: connection tracking write failed' : $db->error());
				generateError('LINE_CREATE_FAIL');
			}

			StreamAuth::validateConnections($rUserInfo, null, null, $rIP, $rUserAgent, $rTokenData['uuid']);

			if ($rSettings['redis_handler']) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			$rCloseCon = true;

			if (!$rSettings['monitor_connection_status']) {
			} else {
				ob_implicit_flush(true);

				while (ob_get_level()) {
					ob_end_clean();
				}
			}

			// The connection id is 32 hex characters: decryptToken() refuses any other.
			// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
			touch(CONS_TMP_PATH . $rTokenData['uuid']);
			header('Content-Type: video/mp2t');
			$rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData['uuid'];
			// The response is the queued minute files back to back, the first one
			// from its .offset (a partial first minute). Its bitrate is over the
			// minutes it holds: a link may ask for more than were recorded.
			$rSize = getLength($rQueue) - $rOffset;
			$rBitrate = ($rSize * 0.008) / (count($rQueue) * 60);
			$rDownloadBytes = $rBitrate * 125;
			$rDownloadBytes += $rDownloadBytes * $rSettings['vod_bitrate_plus'] * 0.01;
			if ($rFileDaemon) {
				$rParts = [];
				foreach (array_values($rQueue) as $rIndex => $rItem) {
					$rFrom = ($rIndex === 0 ? $rOffset : 0);
					if ($rItem['filesize'] > $rFrom) {
						$rParts[] = ['path' => $rItem['filename'], 'offset' => $rFrom, 'length' => $rItem['filesize'] - $rFrom];
					}
				}
				// Throttled as below: past vod_limit_perc, at the recording's bitrate, never for a restreamer.
				$rRate = (0 < $rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) ? (int) $rDownloadBytes : 0;
				if ($rParts === [] || !FanoutClient::handOverFile($rStreamID, (string) $rTokenData['uuid'], $rParts, 'video/mp2t', (int) $rSettings['vod_limit_perc'], $rRate)) {
					generateError('ARCHIVE_DOESNT_EXIST');
				}
				exit();
			}
			$rServe = HttpRange::sendHeaders(HttpRange::parse($_SERVER['HTTP_RANGE'] ?? null, $rSize), $rSize);
			if ($rServe === null) {
				exit(); // 416 already sent
			}
			[$rStart, $rEnd] = $rServe;
			$rLength = $rEnd - $rStart + 1;
			$rRemaining = $rLength;
			$rLastCheck = $rTimeChecked = $rTimeStart = time();
			$rBytesRead = 0;
			$rBuffer = $rSettings['read_buffer_size'];
			$i = 0;
			$o = 0;

			if (0 < $rSettings['vod_limit_perc'] && !$rUserInfo['is_restreamer']) {
				$rLimitAt = intval($rLength * floatval($rSettings['vod_limit_perc'] / 100));
			} else {
				$rLimitAt = $rLength;
			}

			$rApplyLimit = false;

			// Map the served byte range onto the files: skip every file that ends
			// before $rStart, seek into the one it falls in, stop after $rEnd. (A
			// seek used to estimate the start file from the average file size, never
			// skip the files before it, and seek to a negative offset — so every
			// catch-up seek streamed from the archive's first byte.)
			$rPosition = 0; // response offset of the current file's first servable byte
			foreach (array_values($rQueue) as $rIndex => $rItem) {
				if ($rRemaining <= 0) {
					break;
				}
				$rFileStart = ($rIndex === 0 ? $rOffset : 0);
				$rFileBytes = $rItem['filesize'] - $rFileStart;
				if ($rFileBytes <= 0) {
					continue;
				}
				if ($rPosition + $rFileBytes <= $rStart) {
					$rPosition += $rFileBytes; // wholly before the range
					continue;
				}

				$rFP = fopen($rItem['filename'], 'rb');
				if (!$rFP) {
					break;
				}
				fseek($rFP, $rFileStart + max(0, $rStart - $rPosition));
				$rPosition += $rFileBytes;

				while (!feof($rFP) && 0 < $rRemaining) {
					$rResponse = stream_get_line($rFP, (int) min($rBuffer, $rRemaining));
					if ($rResponse === false || $rResponse === '') {
						break;
					}
					echo $rResponse;
					$rRemaining -= strlen($rResponse);
					$rBytesRead += strlen($rResponse);
					$i++;

					if (!$rApplyLimit && $rLimitAt <= $o * $rBuffer) {
						$rApplyLimit = true;
					} else {
						$o++;
					}

					if (0 < $rDownloadBytes && $rApplyLimit && ceil($rDownloadBytes / $rBuffer) <= $i) {
						// Throttled to the recording's bitrate: one second's worth of
						// chunks, then a second's pause. The count restarts after each
						// pause — without the reset every later chunk paused a second.
						AsyncFileOperations::efficientSleep(1000000);
						$i = 0;
					}
					if (30 > time() - $rTimeStart) {
					} else {
						// Named by the connection id, 32 hex characters (decryptToken() refuses any other).
						// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
						file_put_contents($rConSpeedFile, intval($rBytesRead / 1024 / 30));
						$rTimeStart = time();
						$rBytesRead = 0;
					}

					if (!($rSettings['monitor_connection_status'] && 5 <= time() - $rTimeChecked)) {
					} else {
						if (connection_status() == CONNECTION_NORMAL) {
							$rTimeChecked = time();
						} else {
							exit();
						}
					}

					if (300 > time() - $rLastCheck) {
					} else {
						$rLastCheck = time();
						$rConnection = null;
						$rSettings = CacheReader::get('settings');

						$rConnection = ConnectionTracker::heartbeat($rSettings, $rTokenData['uuid'], time() - intval($rServers[SERVER_ID]['time_offset']));

						if (!(!is_array($rConnection) || $rConnection['hls_end'] != 0 || $rConnection['pid'] != $rPID)) {
						} else {
							exit();
						}
					}
				}

				if (is_resource($rFP)) {
					fclose($rFP);
				}
			}
	}
} else {
	generateError('TOKEN_ERROR');
}

function getLength($rQueue) {
	$rLength = 0;

	foreach ($rQueue as $rItem) {
		$rLength += $rItem['filesize'];
	}

	return $rLength;
}
