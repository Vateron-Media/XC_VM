<?php

use XcVm\Core\Auth\AuthService;
use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\User\RtmpViewerAuth;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Auth\StreamAuth;
use XcVm\Streaming\Protection\ConnectionLimiter;

/**
 * RTMP stream handler
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

// nginx-rtmp says who the client is (addr, clientid), what it asks for (call)
// and which stream (name). The stream's own arguments share the query string,
// so these four are read from it here, and each has one value.
$rNotify = StreamAuth::notifyArguments($_SERVER['QUERY_STRING'] ?? '');
if ($rNotify === null) {
	http_response_code(404);

	exit();
}

if (!($rNotify['addr'] == '127.0.0.1' && $rNotify['call'] == 'publish')) {
	register_shutdown_function('shutdown');
	set_time_limit(0);
	error_reporting(0);
	ini_set('display_errors', 0);
	$rAllowed = BlocklistService::getAllowedRTMP();
	$rDeny = true;

	if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
	} else {
		generate404();
	}

	$rIP = $rNotify['addr'];
	$rStreamID = intval($rNotify['name']);
	$rRestreamDetect = false;

	foreach (getallheaders() as $rKey => $rValue) {
		if (strtoupper($rKey) != 'X-XC_VM-DETECT') {
		} else {
			$rRestreamDetect = true;
		}
	}

	if ($rNotify['call'] != 'publish') {
		if ($rNotify['call'] != 'play_done') {
			// A viewer's line is checked on MAIN: by MAIN's own check, or on a load
			// balancer, which is not shipped the line lookup (Domain/User), by MAIN's
			// through this node's agent (`rtmp_auth`, ADR 0004). A relay with the
			// stream password, or from an allowed address, is let through as before.
			$rUserInfo = null;
			if (!(ViewerKey::passMatches($rSettings['live_streaming_pass'] ?? null, $rRequest['password'] ?? null) || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['pull'] && (!$rAllowed[$rIP]['password'] || AuthService::secretMatches($rAllowed[$rIP]['password'], $rRequest['password'] ?? null)))) {
				if (!isset($rRequest['tcurl']) || !isset($rRequest['app'])) {
					http_response_code(404);

					exit();
				}

				// The link's credentials. A line's name and password, or its token, are
				// plain values: one sent as a list is no line's.
				$rCreds = isset($rRequest['token']) ? ['token' => $rRequest['token']] : ['username' => $rRequest['username'] ?? '', 'password' => $rRequest['password'] ?? ''];
				if (count(array_filter($rCreds, 'is_string')) !== count($rCreds)) {
					http_response_code(404);

					exit();
				}

				if (class_exists(RtmpViewerAuth::class)) {
					$rAuth = RtmpViewerAuth::check($rSettings, (bool) $rCached, $rBouquets ?: [], $rServers, $rStreamID, $rIP, $rCreds, $rRestreamDetect, (int) SERVER_ID);
				} else {
					// No answer (no agent, an agent without the op, MAIN unreachable) refuses the viewer.
					$rAuth = AgentClient::main('rtmp_auth', ['stream_id' => $rStreamID, 'ip' => $rIP, 'restream' => $rRestreamDetect, 'uuid' => ConnectionTracker::rtmpUuid($rNotify['clientid'])] + $rCreds, 6.0) ?? ['ok' => false, 'reason' => 'NO_ANSWER'];
				}

				if (($rAuth['ok'] ?? false) !== true || !is_array($rAuth['user'] ?? null)) {
					// Only credentials that name no line count against the address.
					$rDeny = ($rAuth['reason'] ?? '') === 'AUTH_FAILED';
					http_response_code(404);

					exit();
				}

				$rUserInfo = $rAuth['user'];
				$rCountryCode = (string) ($rAuth['country_code'] ?? '');
			}

			$rDeny = false;
			// The stream and this node's row: MAIN's database, or its replica and its own store (StreamSource::local).
			if (StreamSource::local()) {
				$rChannelInfo = StreamSource::joined(intval($rStreamID));
			} else {
				$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.`id` = ?', SERVER_ID, $rStreamID);
				$rChannelInfo = $db->get_row();
			}

			if (!$rChannelInfo) {
				// A viewer of a stream this server does not hold is refused; a relay is let through, as before.
				http_response_code($rUserInfo === null ? 200 : 404);

				exit();
			}

			if (!ProcessManager::isStreamAlive($rChannelInfo['pid'], $rStreamID)) {
				if ($rChannelInfo['on_demand'] != 1) {
					http_response_code(404);

					exit();
				}

				if (!StreamProcess::isWatched($rStreamID, $rChannelInfo['monitor_pid'])) {
					StreamProcess::startMonitor($rStreamID);
					sleep(5);
				}
			}

			if ($rUserInfo !== null) {
				$rExtension = 'rtmp';
				$rExternalDevice = '';
				if ($rSettings['redis_handler']) {
					RedisManager::ensureConnected();
				}
				$rLastRead = time() - intval($rServers[SERVER_ID]['time_offset']);
				$rConnectionData = ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => SERVER_ID, 'proxy_id' => 0, 'user_agent' => '', 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => $rNotify['clientid'], 'date_start' => $rLastRead, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => $rExternalDevice, 'hls_end' => 0, 'hls_last_read' => $rLastRead, 'on_demand' => $rChannelInfo['on_demand'], 'identity' => $rUserInfo['id'], 'uuid' => ConnectionTracker::rtmpUuid($rNotify['clientid'])];
				// The table path keeps its own date_start (the node's clock), as it always did.
				// No stream token: MAIN's mint, when it sent one, carries the proof and the claim;
				// without one a limited line is admitted by the agent asking MAIN (conn_admit).
				$rResult = ConnectionTracker::openRecord($rSettings, $rConnectionData, ['user_id' => $rUserInfo['id'], 'stream_id' => $rStreamID, 'server_id' => SERVER_ID, 'proxy_id' => 0, 'user_agent' => '', 'user_ip' => $rIP, 'container' => $rExtension, 'pid' => $rNotify['clientid'], 'uuid' => ConnectionTracker::rtmpUuid($rNotify['clientid']), 'date_start' => time(), 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo['con_isp_name'], 'external_device' => $rExternalDevice, 'hls_last_read' => $rLastRead], ConnectionTracker::rtmpToken($rAuth), intval($rServers[SERVER_ID]['time_offset']));

				if ($rResult) {
					StreamAuth::validateConnections($rUserInfo, false, '', $rIP, null, ConnectionTracker::rtmpUuid($rNotify['clientid']));
					http_response_code(200);

					exit();
				}

				$rRefused = ConnectionTracker::refusedAdmission();
				if ($rRefused !== null) {
					// Logged as the other endpoints log it; RTMP has no video to show.
					DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], StreamAuth::admissionRefusal($rRefused)[0], $rIP, 'admission: ' . $rRefused);
					http_response_code(404);

					exit();
				}

				DatabaseLogger::clientLog($rStreamID, $rUserInfo['id'], 'LINE_CREATE_FAIL', $rIP, $rSettings['redis_handler'] ? 'redis unavailable: connection tracking write failed' : $db->error());
				http_response_code(404);

				exit();
			}

			http_response_code(200);

			exit();
		}

		$rDeny = false;

		// Both stores: the record is removed and its activity written.
		ConnectionLimiter::closeRTMP($rNotify['clientid']);

		http_response_code(200);

		exit();
	}

	if (ViewerKey::passMatches($rSettings['live_streaming_pass'] ?? null, $rRequest['password'] ?? null) || isset($rAllowed[$rIP]) && $rAllowed[$rIP]['push'] && (!$rAllowed[$rIP]['password'] || AuthService::secretMatches($rAllowed[$rIP]['password'], $rRequest['password'] ?? null))) {
		$rDeny = false;
		http_response_code(200);

		exit();
	}

	http_response_code(404);

	exit();
} else {
	http_response_code(200);

	exit();
}

function shutdown() {
	global $rDeny;
	global $rIP;

	if (!$rDeny) {
	} else {
		BruteforceGuard::checkFlood($rIP);
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
