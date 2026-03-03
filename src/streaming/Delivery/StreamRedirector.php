<?php

class StreamRedirector {
	public static function redirectStream($rCached, $rSettings, $rServers, $rStreamID, $rExtension, $rUserInfo, $rCountryCode, $rUserISP = '', $rType = '') {
		if ($rCached) {
			$rStream = (igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamID)) ?: null);
			$rStream['bouquets'] = BouquetService::getMapEntry($rStreamID);
		} else {
			$rStream = self::getStreamData($rStreamID);
		}
		if (!$rStream) {
			return false;
		}

		$rStream['info']['bouquets'] = $rStream['bouquets'];
		$rAvailableServers = array();
		if ($rType == 'archive') {
			if (0 < $rStream['info']['tv_archive_duration'] && 0 < $rStream['info']['tv_archive_server_id'] && array_key_exists($rStream['info']['tv_archive_server_id'], $rServers)) {
				$rAvailableServers = array($rStream['info']['tv_archive_server_id']);
			}
		} else {
			if (!($rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 0)) {
				foreach ($rServers as $rServerID => $rServerInfo) {
					if (!array_key_exists($rServerID, $rStream['servers']) || !$rServerInfo['server_online'] || $rServerInfo['server_type'] != 0) {
						continue;
					}
					if (!isset($rStream['servers'][$rServerID])) {
						continue;
					}
					if ($rType == 'movie') {
						if ((!empty($rStream['servers'][$rServerID]['pid']) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && $rStream['servers'][$rServerID]['stream_status'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1) && ($rStream['info']['target_container'] == $rExtension || $rExtension == 'srt' || $rExtension == 'm3u8' || $rExtension == 'ts') && $rServerInfo['timeshift_only'] == 0) {
							$rAvailableServers[] = $rServerID;
						}
					} else {
						if (($rStream['servers'][$rServerID]['on_demand'] == 1 && $rStream['servers'][$rServerID]['stream_status'] != 1 || 0 < $rStream['servers'][$rServerID]['pid'] && $rStream['servers'][$rServerID]['stream_status'] == 0) && $rStream['servers'][$rServerID]['to_analyze'] == 0 && (int) $rStream['servers'][$rServerID]['delay_available_at'] <= time() && $rServerInfo['timeshift_only'] == 0 || $rStream['info']['direct_source'] == 1 && $rStream['info']['direct_proxy'] == 1) {
							$rAvailableServers[] = $rServerID;
						}
					}
				}
			} else {
				header('Location: ' . str_replace(' ', '%20', json_decode($rStream['info']['stream_source'], true)[0]));
				exit();
			}
		}

		if (empty($rAvailableServers)) {
			return false;
		}

		shuffle($rAvailableServers);
		$rServerCapacity = ConnectionTracker::getCapacity($rSettings, $rServers, RedisManager::instance());
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
			if ($rType == 'archive') {
				return null;
			}
			return array();
		}
		$rKeys = array_keys($rAcceptServers);
		$rValues = array_values($rAcceptServers);
		array_multisort($rValues, SORT_ASC, $rKeys, SORT_ASC);
		$rAcceptServers = array_combine($rKeys, $rValues);
		if ($rExtension == 'rtmp' && array_key_exists(SERVER_ID, $rAcceptServers)) {
			$rRedirectID = SERVER_ID;
		} else {
			if (isset($rUserInfo) && $rUserInfo['force_server_id'] != 0 && array_key_exists($rUserInfo['force_server_id'], $rAcceptServers)) {
				$rRedirectID = $rUserInfo['force_server_id'];
			} else {
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
							if (isset($rStream) && !$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
								$rPriorityServers[$rServerID] = ($rServers[$rServerID]['geoip_type'] == 'low_priority' ? 3 : 2);
							} else {
								$rPriorityServers[$rServerID] = ($rServers[$rServerID]['geoip_type'] == 'low_priority' ? 2 : 1);
							}
						}
					} else {
						if ($rServers[$rServerID]['enable_isp'] == 1) {
							if (in_array(strtolower(trim(preg_replace('/[^A-Za-z0-9 ]/', '', $rUserISP))), $rServers[$rServerID]['isp_names'])) {
								$rRedirectID = $rServerID;
								break;
							}
							if ($rServers[$rServerID]['isp_type'] == 'strict') {
								unset($rAcceptServers[$rServerID]);
							} else {
								if (isset($rStream) && !$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
									$rPriorityServers[$rServerID] = ($rServers[$rServerID]['isp_type'] == 'low_priority' ? 3 : 2);
								} else {
									$rPriorityServers[$rServerID] = ($rServers[$rServerID]['isp_type'] == 'low_priority' ? 2 : 1);
								}
							}
						} else {
							if (isset($rStream) && !$rSettings['ondemand_balance_equal'] && $rStream['servers'][$rServerID]['on_demand']) {
								$rPriorityServers[$rServerID] = 2;
							} else {
								$rPriorityServers[$rServerID] = 1;
							}
						}
					}
				}
				if (empty($rPriorityServers) && empty($rRedirectID)) {
					return false;
				}
				$rRedirectID = (empty($rRedirectID) ? array_search(min($rPriorityServers), $rPriorityServers) : $rRedirectID);
			}
		}
		if ($rType == 'archive') {
			return $rRedirectID;
		}
		$rStream['info']['redirect_id'] = $rRedirectID;
		$fc4c58c5d1cd68d1 = $rRedirectID;
		return array_merge($rStream['info'], $rStream['servers'][$fc4c58c5d1cd68d1]);
	}

	private static function getStreamData($rStreamID) {
		global $db;
		$rOutput = array();
		$db->query('SELECT * FROM `streams` t1 LEFT JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE t1.`id` = ?', $rStreamID);
		if (0 >= $db->num_rows()) {
		} else {
			$rStreamInfo = $db->get_row();
			$rServersData = array();
			if (!($rStreamInfo['direct_source'] == 0 || $rStreamInfo['direct_proxy'] == 1)) {
			} else {
				$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` = ?', $rStreamID);
				if (0 >= $db->num_rows()) {
				} else {
					$rServersData = $db->get_rows(true, 'server_id');
				}
			}
			$rOutput['bouquets'] = BouquetService::getMapEntry($rStreamID);
			$rOutput['info'] = $rStreamInfo;
			$rOutput['servers'] = $rServersData;
		}
		return (!empty($rOutput) ? $rOutput : false);
	}

	public static function getStreamingURL($rSettings, $rServers, $rServerID = null, $rOriginatorID = null, $rForceHTTP = false) {
		if (!isset($rServerID)) {
			$rServerID = SERVER_ID;
		}
		if ($rForceHTTP) {
			$rProtocol = 'http';
		} else {
			if ($rSettings['keep_protocol']) {
				$rProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http');
			} else {
				$rProtocol = $rServers[$rServerID]['server_protocol'];
			}
		}
		$rDomain = null;
		if (0 < strlen(HOST) && in_array(strtolower(HOST), array_map('strtolower', $rServers[$rServerID]['domains']['urls']))) {
			$rDomain = HOST;
		} else {
			if ($rServers[$rServerID]['random_ip'] && 0 < count($rServers[$rServerID]['domains']['urls'])) {
				$rDomain = $rServers[$rServerID]['domains']['urls'][array_rand($rServers[$rServerID]['domains']['urls'])];
			}
		}
		if ($rDomain) {
			$rURL = $rProtocol . '://' . $rDomain . ':' . $rServers[$rServerID][$rProtocol . '_broadcast_port'];
		} else {
			$rURL = rtrim($rServers[$rServerID][$rProtocol . '_url'], '/');
		}
		if ($rServers[$rServerID]['server_type'] == 1 && $rOriginatorID && $rServers[$rOriginatorID]['is_main'] == 0) {
			$rURL .= '/' . md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA);
		}
		return $rURL;
	}
}
