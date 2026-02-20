<?php

class ConnectionTracker {
	public static function getCapacity($rSettings, $rServers, $rRedis, $db, $rProxy = false) {
		$rFile = ($rProxy ? 'proxy_capacity' : 'servers_capacity');
		if ($rSettings['redis_handler'] && $rProxy && $rSettings['split_by'] == 'maxclients') {
			$rSettings['split_by'] == 'guar_band';
		}

		if ($rSettings['redis_handler']) {
			$rRows = array();
			$rMulti = $rRedis->multi();
			foreach (array_keys($rServers) as $rServerID) {
				if ($rServers[$rServerID]['server_online']) {
					$rMulti->zCard((($rProxy ? 'PROXY#' : 'SERVER#')) . $rServerID);
				}
			}
			$rResults = $rMulti->exec();
			$i = 0;
			foreach (array_keys($rServers) as $rServerID) {
				if ($rServers[$rServerID]['server_online']) {
					$rRows[$rServerID] = array('online_clients' => ($rResults[$i] ?: 0));
					$i++;
				}
			}
		} else {
			if ($rProxy) {
				$db->query('SELECT `proxy_id`, COUNT(*) AS `online_clients` FROM `lines_live` WHERE `proxy_id` <> 0 AND `hls_end` = 0 GROUP BY `proxy_id`;');
				$rRows = $db->get_rows(true, 'proxy_id');
			} else {
				$db->query('SELECT `server_id`, COUNT(*) AS `online_clients` FROM `lines_live` WHERE `server_id` <> 0 AND `hls_end` = 0 GROUP BY `server_id`;');
				$rRows = $db->get_rows(true, 'server_id');
			}
		}

		if ($rSettings['split_by'] == 'band') {
			$rServerSpeed = array();
			foreach (array_keys($rServers) as $rServerID) {
				$rServerHardware = json_decode($rServers[$rServerID]['server_hardware'], true);
				if (!empty($rServerHardware['network_speed'])) {
					$rServerSpeed[$rServerID] = (float) $rServerHardware['network_speed'];
				} else {
					if (0 < $rServers[$rServerID]['network_guaranteed_speed']) {
						$rServerSpeed[$rServerID] = $rServers[$rServerID]['network_guaranteed_speed'];
					} else {
						$rServerSpeed[$rServerID] = 1000;
					}
				}
			}
			foreach ($rRows as $rServerID => $rRow) {
				$rCurrentOutput = intval($rServers[$rServerID]['watchdog']['bytes_sent'] / 125000);
				$rRows[$rServerID]['capacity'] = (float) ($rCurrentOutput / (($rServerSpeed[$rServerID] ?: 1000)));
			}
		} else {
			if ($rSettings['split_by'] == 'maxclients') {
				foreach ($rRows as $rServerID => $rRow) {
					$rRows[$rServerID]['capacity'] = (float) ($rRow['online_clients'] / (($rServers[$rServerID]['total_clients'] ?: 1)));
				}
			} else {
				if ($rSettings['split_by'] == 'guar_band') {
					foreach ($rRows as $rServerID => $rRow) {
						$rCurrentOutput = intval($rServers[$rServerID]['watchdog']['bytes_sent'] / 125000);
						$rRows[$rServerID]['capacity'] = (float) ($rCurrentOutput / (($rServers[$rServerID]['network_guaranteed_speed'] ?: 1)));
					}
				} else {
					foreach ($rRows as $rServerID => $rRow) {
						$rRows[$rServerID]['capacity'] = $rRow['online_clients'];
					}
				}
			}
		}

		file_put_contents(CACHE_TMP_PATH . $rFile, json_encode($rRows), LOCK_EX);
		return $rRows;
	}

	public static function getConnections($rSettings, $rRedis, $db, $rServerID = null, $rUserID = null, $rStreamID = null) {
		if ($rSettings['redis_handler']) {
			if ($rServerID) {
				$rKeys = $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
			} elseif ($rUserID) {
				$rKeys = $rRedis->zRangeByScore('LINE#' . $rUserID, '-inf', '+inf');
			} elseif ($rStreamID) {
				$rKeys = $rRedis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
			} else {
				$rKeys = $rRedis->zRangeByScore('LIVE', '-inf', '+inf');
			}

			if (count($rKeys) > 0) {
				return array($rKeys, array_map('igbinary_unserialize', $rRedis->mGet($rKeys)));
			}
			return array([], []);
		}

		$rWhere = array();
		if (!empty($rServerID)) {
			$rWhere[] = 't1.server_id = ' . intval($rServerID);
		}
		if (!empty($rUserID)) {
			$rWhere[] = 't1.user_id = ' . intval($rUserID);
		}
		$rExtra = count($rWhere) ? 'WHERE ' . implode(' AND ', $rWhere) : '';
		$rQuery = 'SELECT t2.*,t3.*,t5.bitrate,t1.*,t1.uuid AS `uuid` 
               FROM `lines_live` t1 
               LEFT JOIN `lines` t2 ON t2.id = t1.user_id 
               LEFT JOIN `streams` t3 ON t3.id = t1.stream_id 
               LEFT JOIN `streams_servers` t5 ON t5.stream_id = t1.stream_id AND t5.server_id = t1.server_id 
               ' . $rExtra . ' 
               ORDER BY t1.activity_id ASC';
		$db->query($rQuery);
		return $db->get_rows(true, 'user_id', false);
	}

	public static function getMainID($rServers) {
		foreach ($rServers as $rServerID => $rServer) {
			if ($rServer['is_main']) {
				return $rServerID;
			}
		}
	}

	public static function addToQueue($rStreamID, $rAddPID, $rProcessCheckCallback) {
		$rActivePIDs = $rPIDs = array();
		if (file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
			$rPIDs = igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID)));
		}
		foreach ($rPIDs as $rPID) {
			if (call_user_func($rProcessCheckCallback, $rPID, 'php-fpm')) {
				$rActivePIDs[] = $rPID;
			}
		}
		if (!in_array($rAddPID, $rActivePIDs, true)) {
			$rActivePIDs[] = $rAddPID;
		}
		file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs), LOCK_EX);
	}

	public static function removeFromQueue($rStreamID, $rPID, $rProcessCheckCallback) {
		$rActivePIDs = array();
		foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) ?: array()) as $rActivePID) {
			if (call_user_func($rProcessCheckCallback, $rActivePID, 'php-fpm') && $rPID != $rActivePID) {
				$rActivePIDs[] = $rActivePID;
			}
		}
		if (0 < count($rActivePIDs)) {
			file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs), LOCK_EX);
		} else {
			@unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}
	}

	public static function updateConnection($rRedis, $rData, $rChanges = array(), $rOption = null) {
		$rOrigData = $rData;
		foreach ($rChanges as $rKey => $rValue) {
			$rData[$rKey] = $rValue;
		}
		$rMulti = $rRedis->multi();
		if ($rOption == 'open') {
			$rMulti->sRem('ENDED', $rData['uuid']);
			$rMulti->zAdd('LIVE', $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('LINE#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('STREAM#' . $rData['stream_id'], $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('SERVER#' . $rData['server_id'], $rData['date_start'], $rData['uuid']);
			if ($rData['proxy_id']) {
				$rMulti->zAdd('PROXY#' . $rData['proxy_id'], $rData['date_start'], $rData['uuid']);
			}
			if ($rData['hls_end'] == 1) {
				$rData['hls_end'] = 0;
				if ($rData['user_id']) {
					$rMulti->zAdd('SERVER_LINES#' . $rData['server_id'], $rData['user_id'], $rData['uuid']);
				}
			}
		} else {
			if ($rOption == 'close') {
				$rMulti->sAdd('ENDED', $rData['uuid']);
				$rMulti->zRem('LIVE', $rData['uuid']);
				$rMulti->zRem('LINE#' . $rOrigData['identity'], $rData['uuid']);
				$rMulti->zRem('STREAM#' . $rOrigData['stream_id'], $rData['uuid']);
				$rMulti->zRem('SERVER#' . $rOrigData['server_id'], $rData['uuid']);
				if ($rData['proxy_id']) {
					$rMulti->zRem('PROXY#' . $rOrigData['proxy_id'], $rData['uuid']);
				}
				if ($rData['hls_end'] == 0) {
					$rData['hls_end'] = 1;
					if ($rData['user_id']) {
						$rMulti->zRem('SERVER_LINES#' . $rOrigData['server_id'], $rData['uuid']);
					}
				}
			}
		}
		$rMulti->set($rData['uuid'], igbinary_serialize($rData));
		if ($rMulti->exec()) {
			return $rData;
		}
		return null;
	}

	public static function redisSignal($rRedis, $rPID, $rServerID, $rRTMP, $rCustomData = null) {
		$rKey = 'SIGNAL#' . md5($rServerID . '#' . $rPID . '#' . $rRTMP);
		$rData = array('pid' => $rPID, 'server_id' => $rServerID, 'rtmp' => $rRTMP, 'time' => time(), 'custom_data' => $rCustomData, 'key' => $rKey);
		return $rRedis->multi()->sAdd('SIGNALS#' . $rServerID, $rKey)->set($rKey, igbinary_serialize($rData))->exec();
	}

	public static function getUserConnections($rRedis, $rUserIDs, $rCount = false, $rKeysOnly = false) {
		$rMulti = $rRedis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rMulti->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rUserIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 < count($rKeys)) {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		$rRedisKeys = array_unique($rRedisKeys);
		if (!$rKeysOnly) {
			if (!$rCount) {
				foreach ($rRedis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['user_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}

	public static function getServerConnections($rRedis, $rServerIDs, $rProxy = false, $rCount = false, $rKeysOnly = false) {
		$rMulti = $rRedis->multi();
		foreach ($rServerIDs as $rServerID) {
			$rMulti->zRevRangeByScore(($rProxy ? 'PROXY#' . $rServerID : 'SERVER#' . $rServerID), '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rServerIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 < count($rKeys)) {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		$rRedisKeys = array_unique($rRedisKeys);
		if (!$rKeysOnly) {
			if (!$rCount) {
				foreach ($rRedis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['server_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}

	public static function getFirstConnection($rRedis, $rUserIDs) {
		$rMulti = $rRedis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rMulti->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf', array('limit' => array(0, 1)));
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rKeys) {
			if (0 < count($rKeys)) {
				$rRedisKeys[] = $rKeys[0];
			}
		}
		foreach ($rRedis->mGet(array_unique($rRedisKeys)) as $rRow) {
			$rRow = igbinary_unserialize($rRow);
			$rConnectionMap[$rRow['user_id']] = $rRow;
		}
		return $rConnectionMap;
	}

	public static function getStreamConnections($rRedis, $rStreamIDs, $rGroup = true, $rCount = false) {
		$rMulti = $rRedis->multi();
		foreach ($rStreamIDs as $rStreamID) {
			$rMulti->zRevRangeByScore('STREAM#' . $rStreamID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rStreamIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 < count($rKeys)) {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		if (!$rCount) {
			foreach ($rRedis->mGet(array_unique($rRedisKeys)) as $rRow) {
				$rRow = igbinary_unserialize($rRow);
				if ($rGroup) {
					$rConnectionMap[$rRow['stream_id']][] = $rRow;
				} else {
					$rConnectionMap[$rRow['stream_id']][$rRow['server_id']][] = $rRow;
				}
			}
		}
		return $rConnectionMap;
	}

	public static function getRedisConnections($rRedis, $rUserID = null, $rServerID = null, $rStreamID = null, $rOpenOnly = false, $rCountOnly = false, $rGroup = true, $rHLSOnly = false) {
		$rReturn = ($rCountOnly ? array(0, 0) : array());
		$rUniqueUsers = array();
		$rUserID = (0 < intval($rUserID) ? intval($rUserID) : null);
		$rServerID = (0 < intval($rServerID) ? intval($rServerID) : null);
		$rStreamID = (0 < intval($rStreamID) ? intval($rStreamID) : null);

		if ($rUserID) {
			$rKeys = $rRedis->zRangeByScore('LINE#' . $rUserID, '-inf', '+inf');
		} else {
			if ($rStreamID) {
				$rKeys = $rRedis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
			} else {
				if ($rServerID) {
					$rKeys = $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
				} else {
					$rKeys = $rRedis->zRangeByScore('LIVE', '-inf', '+inf');
				}
			}
		}

		if (0 < count($rKeys)) {
			foreach ($rRedis->mGet(array_unique($rKeys)) as $rRow) {
				$rRow = igbinary_unserialize($rRow);
				if (!($rServerID && $rServerID != $rRow['server_id']) && !($rStreamID && $rStreamID != $rRow['stream_id']) && !($rUserID && $rUserID != $rRow['user_id']) && !($rHLSOnly && $rRow['container'] == 'hls')) {
					$rUUID = ($rRow['user_id'] ?: $rRow['hmac_id'] . '_' . $rRow['hmac_identifier']);
					if ($rCountOnly) {
						$rReturn[0]++;
						$rUniqueUsers[] = $rUUID;
					} else {
						if ($rGroup) {
							if (!isset($rReturn[$rUUID])) {
								$rReturn[$rUUID] = array();
							}
							$rReturn[$rUUID][] = $rRow;
						} else {
							$rReturn[] = $rRow;
						}
					}
				}
			}
		}

		if ($rCountOnly) {
			$rReturn[1] = count(array_unique($rUniqueUsers));
		}
		return $rReturn;
	}

	public static function getConnection($rRedis, $rUUID) {
		return igbinary_unserialize($rRedis->get($rUUID));
	}

	public static function createConnection($rRedis, $rData) {
		$rMulti = $rRedis->multi();
		$rMulti->zAdd('LINE#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
		$rMulti->zAdd('LINE_ALL#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
		$rMulti->zAdd('STREAM#' . $rData['stream_id'], $rData['date_start'], $rData['uuid']);
		$rMulti->zAdd('SERVER#' . $rData['server_id'], $rData['date_start'], $rData['uuid']);
		if ($rData['user_id']) {
			$rMulti->zAdd('SERVER_LINES#' . $rData['server_id'], $rData['user_id'], $rData['uuid']);
		}
		if ($rData['proxy_id']) {
			$rMulti->zAdd('PROXY#' . $rData['proxy_id'], $rData['date_start'], $rData['uuid']);
		}
		$rMulti->zAdd('CONNECTIONS', $rData['date_start'], $rData['uuid']);
		$rMulti->zAdd('LIVE', $rData['date_start'], $rData['uuid']);
		$rMulti->set($rData['uuid'], igbinary_serialize($rData));
		return $rMulti->exec();
	}

	public static function getLineConnections($rRedis, $rUserID, $rActive = false, $rKeys = false) {
		$rKeys = $rRedis->zRangeByScore((($rActive ? 'LINE#' : 'LINE_ALL#')) . $rUserID, '-inf', '+inf');
		if ($rKeys) {
			return $rKeys;
		}
		if (0 >= count($rKeys)) {
			return array();
		}
		return array_map('igbinary_unserialize', $rRedis->mGet($rKeys));
	}

	public static function getProxies($rServers, $rServerID, $rOnline = true) {
		$rReturn = array();
		foreach ($rServers as $rProxyID => $rServerInfo) {
			if ($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline)) {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}
		return $rReturn;
	}
}
