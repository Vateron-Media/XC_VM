<?php

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
		$rAvailableServers = array();
		foreach ($rServers as $rServerID => $rServerInfo) {
			if ($rServerInfo['server_online'] && $rServerInfo['server_type'] == 0) {
				$rAvailableServers[] = $rServerID;
			}
		}

		if (empty($rAvailableServers)) {
			return false;
		}

		shuffle($rAvailableServers);
		$rServerCapacity = ConnectionTracker::getCapacity();
		$rAcceptServers = array();

		foreach ($rAvailableServers as $rServerID) {
			$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
			if ($rOnlineClients == 0) {
				$rServerCapacity[$rServerID]['capacity'] = 0;
			}
			$rAcceptServers[$rServerID] = (0 < $rServers[$rServerID]['total_clients'] && $rOnlineClients < $rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
		}

		$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
		if (empty($rAcceptServers)) {
			return false;
		}

		$rKeys = array_keys($rAcceptServers);
		$rValues = array_values($rAcceptServers);
		array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
		$rAcceptServers = array_combine($rKeys, $rValues);

		if ($rUserInfo['force_server_id'] != 0 && array_key_exists($rUserInfo['force_server_id'], $rAcceptServers)) {
			return $rUserInfo['force_server_id'];
		}

		$rPriorityServers = array();
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

		if (empty($rPriorityServers) && empty($rRedirectID)) {
			return false;
		}

		return (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
	}

	public static function validateConnections($rUserInfo, $rIsHMAC = false, $rIdentifier = '', $rIP = null, $rUserAgent = null) {
		if ($rUserInfo['max_connections'] != 0) {
			if (!$rIsHMAC) {
				if (!empty($rUserInfo['pair_id'])) {
					ConnectionLimiter::closeConnections($rUserInfo['pair_id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent);
				}
				ConnectionLimiter::closeConnections($rUserInfo['id'], $rUserInfo['max_connections'], null, '', $rIP, $rUserAgent);
			} else {
				ConnectionLimiter::closeConnections(null, $rUserInfo['max_connections'], $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);
			}
		}
	}
}
