<?php

class ConnectionLimiter {
	public static function closeConnections($db, $redis, $rSettings, $rServers, $rUserID, $rMaxConnections, $rIsHMAC = null, $rIdentifier = '', $rIP = null, $rUserAgent = null, $rGetConnectionsCallback = null, $rGetUserIPCallback = null, $rCloseConnectionCallback = null, $rRemoveFromQueueCallback = null) {
		if ($rSettings['redis_handler']) {
			$rConnections = array();
			$rKeys = call_user_func($rGetConnectionsCallback, $rUserID, true, true);
			$rToKill = count($rKeys) - $rMaxConnections;
			if ($rToKill > 0) {
				foreach (array_map('igbinary_unserialize', $redis->mGet($rKeys)) as $rConnection) {
					if (is_array($rConnection)) {
						$rConnections[] = $rConnection;
					}
				}
				unset($rKeys);
				$rDate = array_column($rConnections, 'date_start');
				array_multisort($rDate, SORT_ASC, $rConnections);
			} else {
				return null;
			}
		} else {
			if ($rIsHMAC) {
				$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`hmac_id` = ? AND `lines_live`.`hls_end` = 0 AND `lines_live`.`hmac_identifier` = ? ORDER BY `lines_live`.`activity_id` ASC', $rIsHMAC, $rIdentifier);
			} else {
				$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`user_id` = ? AND `lines_live`.`hls_end` = 0 ORDER BY `lines_live`.`activity_id` ASC', $rUserID);
			}
			$rConnectionCount = $db->num_rows();
			$rToKill = $rConnectionCount - $rMaxConnections;
			if ($rToKill > 0) {
				$rConnections = $db->get_rows();
			} else {
				return null;
			}
		}

		$rIP = call_user_func($rGetUserIPCallback);
		$rKilled = 0;
		$rDelSID = $rDelUUID = $rIDs = array();
		if ($rIP && $rUserAgent) {
			$rKillTypes = array(2, 1, 0);
		} else {
			if ($rIP) {
				$rKillTypes = array(1, 0);
			} else {
				$rKillTypes = array(0);
			}
		}

		foreach ($rKillTypes as $rKillOwnIP) {
			$i = 0;
			while ($i < count($rConnections) && $rKilled < $rToKill) {
				if ($rKilled != $rToKill) {
					if ($rConnections[$i]['pid'] != getmypid()) {
						if ($rConnections[$i]['user_ip'] == $rIP && $rConnections[$i]['user_agent'] == $rUserAgent && $rKillOwnIP == 2 || $rConnections[$i]['user_ip'] == $rIP && $rKillOwnIP == 1 || $rKillOwnIP == 0) {
							if (call_user_func($rCloseConnectionCallback, $rConnections[$i])) {
								$rKilled++;
								if ($rConnections[$i]['container'] != 'hls') {
									if ($rSettings['redis_handler']) {
										$rIDs[] = $rConnections[$i];
									} else {
										$rIDs[] = intval($rConnections[$i]['activity_id']);
									}
									$rDelUUID[] = $rConnections[$i]['uuid'];
									$rDelSID[$rConnections[$i]['stream_id']][] = $rDelUUID;
								}
								if ($rConnections[$i]['on_demand'] && $rConnections[$i]['server_id'] == SERVER_ID && $rSettings['on_demand_instant_off']) {
									call_user_func($rRemoveFromQueueCallback, $rConnections[$i]['stream_id'], $rConnections[$i]['pid']);
								}
							}
						}
					}
					$i++;
				} else {
					break;
				}
			}
		}

		if (!empty($rIDs)) {
			if ($rSettings['redis_handler']) {
				$rUUIDs = array();
				$rRedis = $redis->multi();
				foreach ($rIDs as $rConnection) {
					$rRedis->zRem('LINE#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('LINE_ALL#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('STREAM#' . $rConnection['stream_id'], $rConnection['uuid']);
					$rRedis->zRem('SERVER#' . $rConnection['server_id'], $rConnection['uuid']);
					if ($rConnection['user_id']) {
						$rRedis->zRem('SERVER_LINES#' . $rConnection['server_id'], $rConnection['uuid']);
					}
					if ($rConnection['proxy_id']) {
						$rRedis->zRem('PROXY#' . $rConnection['proxy_id'], $rConnection['uuid']);
					}
					$rRedis->del($rConnection['uuid']);
					$rUUIDs[] = $rConnection['uuid'];
				}
				$rRedis->zRem('CONNECTIONS', ...$rUUIDs);
				$rRedis->zRem('LIVE', ...$rUUIDs);
				$rRedis->sRem('ENDED', ...$rUUIDs);
				$rRedis->exec();
			} else {
				$db->query('DELETE FROM `lines_live` WHERE `activity_id` IN (' . implode(',', array_map('intval', $rIDs)) . ')');
			}

			foreach ($rDelUUID as $rUUID) {
				@unlink(CONS_TMP_PATH . $rUUID);
			}
			foreach ($rDelSID as $rStreamID => $rUUIDs) {
				foreach ($rUUIDs as $rUUID) {
					@unlink(CONS_TMP_PATH . $rStreamID . '/' . $rUUID);
				}
			}
		}

		return $rKilled;
	}

	public static function closeConnection($db, $redis, $rSettings, $rServers, $rActivityInfo, $rUpdateConnectionCallback = null, $rRedisSignalCallback = null, $rWriteOfflineActivityCallback = null) {
		if (empty($rActivityInfo)) {
			return false;
		}

		if (!is_array($rActivityInfo)) {
			if (!$rSettings['redis_handler']) {
				if (strlen(strval($rActivityInfo)) == 32) {
					$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ?', $rActivityInfo);
				} else {
					$db->query('SELECT * FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo);
				}
				$rActivityInfo = $db->get_row();
			} else {
				$rActivityInfo = igbinary_unserialize($redis->get($rActivityInfo));
			}
		}

		if (!is_array($rActivityInfo)) {
			return false;
		}

		if ($rActivityInfo['container'] == 'rtmp') {
			if ($rActivityInfo['server_id'] == SERVER_ID) {
				shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rActivityInfo['pid']) . '" >/dev/null 2>/dev/null &');
			} else {
				if ($rSettings['redis_handler']) {
					call_user_func($rRedisSignalCallback, $rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
				} else {
					$db->query('INSERT INTO `signals` (`pid`,`server_id`,`rtmp`,`time`) VALUES(?,?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
				}
			}
		} else {
			if ($rActivityInfo['container'] == 'hls' || $rActivityInfo['container'] == 'm3u8') {
				if ($rSettings['redis_handler']) {
					call_user_func($rUpdateConnectionCallback, $rActivityInfo, array(), 'close');
				} else {
					$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
				}
			} else {
				if ($rActivityInfo['server_id'] == SERVER_ID) {
					if ($rActivityInfo['pid'] != getmypid() && is_numeric($rActivityInfo['pid']) && 0 < $rActivityInfo['pid']) {
						posix_kill(intval($rActivityInfo['pid']), 9);
					}
				} else {
					if ($rSettings['redis_handler']) {
						call_user_func($rRedisSignalCallback, $rActivityInfo['pid'], $rActivityInfo['server_id'], 0);
					} else {
						$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id']);
					}
				}
			}
		}

		call_user_func($rWriteOfflineActivityCallback, $rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'], $rActivityInfo['divergence'], $rActivityInfo['hmac_id'], $rActivityInfo['hmac_identifier']);
		return true;
	}

	public static function closeRTMP($db, $rPID, $rWriteOfflineActivityCallback = null) {
		if (empty($rPID)) {
			return false;
		}

		$db->query("SELECT * FROM `lines_live` WHERE `container` = 'rtmp' AND `pid` = ? AND `server_id` = ?", $rPID, SERVER_ID);
		if (0 >= $db->num_rows()) {
			return false;
		}

		$rActivityInfo = $db->get_row();
		$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
		call_user_func($rWriteOfflineActivityCallback, $rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'], $rActivityInfo['divergence'], $rActivityInfo['hmac_id'], $rActivityInfo['hmac_identifier']);
		return true;
	}
}
