<?php

class ProxySelector {
	public static function availableProxy($rServers, $rProxies, $rCountryCode, $rUserISP = '', $rGetCapacityCallback = null) {
		if (empty($rProxies)) {
			return null;
		}
		$rServerCapacity = call_user_func($rGetCapacityCallback, true);
		$rAcceptServers = array();
		foreach ($rProxies as $rServerID) {
			$rOnlineClients = (isset($rServerCapacity[$rServerID]['online_clients']) ? $rServerCapacity[$rServerID]['online_clients'] : 0);
			if ($rOnlineClients == 0) {
				$rServerCapacity[$rServerID]['capacity'] = 0;
			}
			$rAcceptServers[$rServerID] = (0 < $rServers[$rServerID]['total_clients'] && $rOnlineClients < $rServers[$rServerID]['total_clients'] ? $rServerCapacity[$rServerID]['capacity'] : false);
		}
		$rAcceptServers = array_filter($rAcceptServers, 'is_numeric');
		if (empty($rAcceptServers)) {
			return null;
		}
		$rKeys = array_keys($rAcceptServers);
		$rValues = array_values($rAcceptServers);
		array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
		$rAcceptServers = array_combine($rKeys, $rValues);
		$rPriorityServers = array();
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
		if (!(empty($rPriorityServers) && empty($rRedirectID))) {
			$rRedirectID = (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
			return $rRedirectID;
		}
		return null;
	}
}
