<?php

namespace XcVm\Streaming\Auth;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Streaming\Delivery\OffAirHandler;
use XcVm\Streaming\Protection\ConnectionLimiter;

/**
 * StreamAuth — stream auth
 *
 * @package XC_VM_Streaming_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamAuth {
	public static function checkAccess($rUserInfo, $rUserIP, $rCountryCode, $rUserISP = '') {
		global $rServers;
		$rAvailableServers = [];
		foreach ($rServers as $rServerID => $rServerInfo) {
			if ($rServerInfo['server_online'] && $rServerInfo['server_type'] == 0) {
				$rAvailableServers[] = $rServerID;
			}
		}

		if ($rAvailableServers === []) {
			return false;
		}

		shuffle($rAvailableServers);
		$rServerCapacity = ConnectionTracker::capacity();
		$rAcceptServers = [];

		foreach ($rAvailableServers as $rServerID) {
			$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
			if ($rOnlineClients == 0) {
				$rServerCapacity[$rServerID]['capacity'] = 0;
			}
			$rAcceptServers[$rServerID] = (0 < $rServers[$rServerID]['total_clients'] && $rOnlineClients < $rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
		}

		$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
		if ($rAcceptServers === []) {
			return false;
		}

		$rKeys = array_keys($rAcceptServers);
		$rValues = array_values($rAcceptServers);
		array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
		$rAcceptServers = array_combine($rKeys, $rValues);

		if ($rUserInfo['force_server_id'] != 0 && array_key_exists($rUserInfo['force_server_id'], $rAcceptServers)) {
			return $rUserInfo['force_server_id'];
		}

		$rPriorityServers = [];
		$rRedirectID = null;
		foreach (array_keys($rAcceptServers) as $rServerID) {
			if ($rServers[$rServerID]['enable_geoip'] == 1) {
				if (in_array($rCountryCode, $rServers[$rServerID]['geoip_countries'])) {
					$rRedirectID = $rServerID;
					break;
				}
				if ($rServers[$rServerID]['geoip_type'] == 'strict') {
					unset($rAcceptServers[$rServerID]);
				} else {
					$rPriorityServers[$rServerID] = ($rServers[$rServerID]['geoip_type'] == 'low_priority' ? 1 : 2);
				}
			} else {
				if ($rServers[$rServerID]['enable_isp'] == 1) {
					if (in_array($rUserISP, $rServers[$rServerID]['isp_names'])) {
						$rRedirectID = $rServerID;
						break;
					}
					if ($rServers[$rServerID]['isp_type'] == 'strict') {
						unset($rAcceptServers[$rServerID]);
					} else {
						$rPriorityServers[$rServerID] = ($rServers[$rServerID]['isp_type'] == 'low_priority' ? 1 : 2);
					}
				} else {
					$rPriorityServers[$rServerID] = 1;
				}
			}
		}

		if ($rPriorityServers === [] && empty($rRedirectID)) {
			return false;
		}

		return (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
	}

	/**
	 * Enforce the line's (or HMAC identity's) connection limit after the current
	 * request's connection was recorded.
	 *
	 * @param array       $rUserInfo  Line info (id, pair_id, max_connections).
	 * @param mixed       $rIsHMAC    HMAC id, or falsy for a line.
	 * @param string|null $rIdentifier HMAC identifier.
	 * @param string|null $rIP        Requesting IP.
	 * @param string|null $rUserAgent Requesting user agent.
	 * @param string|null $rUUID      The current connection's uuid, which is never evicted.
	 * @return void
	 */
	public static function validateConnections(array $rUserInfo, mixed $rIsHMAC = false, ?string $rIdentifier = '', ?string $rIP = null, ?string $rUserAgent = null, ?string $rUUID = null) {
		if ($rUserInfo['max_connections'] != 0 && $rUUID !== null && AgentConnections::enabled()) {
			// A CONNECTIONS node's viewers live in its agent: MAIN enforces the
			// line's limit when this request reaches it (conn.limit), so the
			// request makes no WAN call. If the spool refuses, enforce here.
			// The viewer's address is the caller's to say: an RTMP viewer's
			// request comes from nginx-rtmp, on this server.
			$rLimit = ['uuid' => $rUUID, 'ip' => (string) ($rIP ?? $_SERVER['REMOTE_ADDR'] ?? ''), 'user_agent' => (string) $rUserAgent];
			$rLimit += $rIsHMAC ? ['hmac_id' => (int) $rIsHMAC, 'hmac_identifier' => (string) $rIdentifier, 'max_connections' => (int) $rUserInfo['max_connections']] : ['user_id' => (int) $rUserInfo['id']];
			if (EventSpool::append('p0', [['type' => 'conn.limit', 'd' => $rLimit]])) {
				return;
			}
		}
		if ($rUserInfo['max_connections'] != 0) {
			if (!$rIsHMAC) {
				if (!empty($rUserInfo['pair_id'])) {
					ConnectionLimiter::closeConnections($rUserInfo['pair_id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent, $rUUID);
				}
				ConnectionLimiter::closeConnections($rUserInfo['id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent, $rUUID);
			} else {
				ConnectionLimiter::closeConnections(null, $rUserInfo['max_connections'], $rIsHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			}
		}
	}

	/**
	 * Refuse a viewer the node's agent did not admit (the last
	 * ConnectionTracker::openRecord(); cluster plan, Phase 6), the way
	 * auth.php refuses the same condition (admissionRefusal()), with
	 * `admission: <reason>` as the client log's data. Returns, doing nothing,
	 * when the agent refused nothing; otherwise it does not return.
	 *
	 * @param array<string, mixed> $rUserInfo The token's user_info.
	 * @param mixed $rServerID The node that records the viewer (the token's, as the endpoint read it).
	 * @param mixed $rProxyID  The proxy in front of it, if any.
	 */
	public static function refuseAdmission(mixed $rStreamID, array $rUserInfo, string $rIP, string $rExtension, ?string $rCountryCode, mixed $rServerID, mixed $rProxyID): void {
		$rReason = ConnectionTracker::refusedAdmission();
		if ($rReason === null) {
			return;
		}
		[$rEvent, $rShow, $rPath, $rError] = self::admissionRefusal($rReason);
		DatabaseLogger::clientLog((int) $rStreamID, (int) ($rUserInfo['id'] ?? 0), $rEvent, $rIP, 'admission: ' . $rReason);
		if ($rShow === null || $rPath === null) {
			generateError((string) $rError);
		} else {
			OffAirHandler::showVideoServer($rShow, $rPath, $rExtension, $rUserInfo + ['is_restreamer' => 0, 'con_isp_name' => null], $rIP, (string) $rCountryCode, $rUserInfo['con_isp_name'] ?? null, $rServerID ? (int) $rServerID : null, $rProxyID ? (int) $rProxyID : null);
		}
		exit();
	}

	/**
	 * How an admission refusal is shown, by its reason, as auth.php shows the
	 * same condition at mint: [client log event, show-video setting, video
	 * path setting, error code when there is no video]. MAIN's line reasons
	 * get auth.php's own (USER_EXPIRED, USER_BAN, USER_DISABLED; an unknown
	 * line or HMAC key is AUTH_FAILED and INVALID_CREDENTIALS); the agent's
	 * LIMIT and OFFLINE, and any other reason, read as a line already
	 * connected elsewhere.
	 *
	 * @return array{0: string, 1: ?string, 2: ?string, 3: ?string}
	 */
	public static function admissionRefusal(string $rReason): array {
		return match ($rReason) {
			'EXPIRED' => ['USER_EXPIRED', 'show_expired_video', 'expired_video_path', null],
			'BANNED' => ['USER_BAN', 'show_banned_video', 'banned_video_path', null],
			'DISABLED' => ['USER_DISABLED', 'show_banned_video', 'banned_video_path', null],
			'UNKNOWN_LINE', 'UNKNOWN_HMAC' => ['AUTH_FAILED', null, null, 'INVALID_CREDENTIALS'],
			default => ['USER_ALREADY_CONNECTED', 'show_connected_video', 'connected_video_path', null],
		};
	}

	/**
	 * What nginx-rtmp itself says in a callback (on_play, on_publish,
	 * on_play_done): the client's address and id, the call and the stream
	 * name. The arguments the client put on its stream name (username,
	 * password, token) arrive in the same query string, so these four are
	 * read from it here, by their exact names, and each has one value: null
	 * for a query string that gives one of them two. One it does not name is
	 * null.
	 *
	 * @return array{addr: ?string, clientid: ?string, call: ?string, name: ?string}|null
	 */
	public static function notifyArguments(string $rQuery): ?array {
		$rOut = ['addr' => null, 'clientid' => null, 'call' => null, 'name' => null];
		foreach (explode('&', $rQuery) as $rPair) {
			$rPair = explode('=', $rPair, 2);
			$rKey = urldecode($rPair[0]);
			if (array_key_exists($rKey, $rOut)) {
				$rValue = urldecode($rPair[1] ?? '');
				if ($rOut[$rKey] !== null && $rOut[$rKey] !== $rValue) {
					return null;
				}
				$rOut[$rKey] = $rValue;
			}
		}
		return $rOut;
	}
}
