<?php

/**
 * ConnectionTracker — live streaming connection management.
 *
 * Tracks connection lifecycle (create, update, close), stores state in Redis
 * sorted sets (LIVE, LINE#, STREAM#, SERVER#, PROXY#) with MySQL fallback.
 * Provides server load calculation, batch connection queries by user/server/stream,
 * and closed connection activity logging.
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ConnectionTracker {
	/**
	 * Calculate server/proxy load capacity.
	 *
	 * Counts active connections via Redis zCard or MySQL COUNT, then computes
	 * load ratio using the configured strategy: band, maxclients, guar_band, or client count.
	 * Result is cached to a file.
	 *
	 * @param bool $rProxy If true — calculate for proxy servers, otherwise for main servers.
	 * @return array<int, array{online_clients: int, capacity?: float}> Map of serverID => load data.
	 */
	public static function getCapacity(bool $rProxy = false): array {
		global $rSettings, $rServers, $db;
		$rRedis = RedisManager::instance();
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
			if (!is_array($rResults)) {
				$rResults = [];
			}
			$i = 0;
			foreach (array_keys($rServers) as $rServerID) {
				if ($rServers[$rServerID]['server_online']) {
					$rRows[$rServerID] = array('online_clients' => ($rResults[$i] ?? 0));
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

		if (defined('CACHE_TMP_PATH') && is_dir(CACHE_TMP_PATH) && is_writable(CACHE_TMP_PATH)) {
			file_put_contents(CACHE_TMP_PATH . $rFile, json_encode($rRows), LOCK_EX);
		}
		return $rRows;
	}

	/**
	 * Get connections filtered by server, user, or stream.
	 *
	 * In Redis mode returns [keys[], deserialized data[]].
	 * In MySQL mode performs a JOIN query with lines, streams, streams_servers tables.
	 *
	 * @param int|null $rServerID Server ID to filter by.
	 * @param int|null $rUserID   User ID to filter by.
	 * @param int|null $rStreamID Stream ID to filter by.
	 * @return array Connections: [keys[], data[]] for Redis or rows for MySQL.
	 */
	public static function getConnections(?int $rServerID = null, ?int $rUserID = null, ?int $rStreamID = null): array {
		global $rSettings, $db;
		$rRedis = RedisManager::instance();
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

	/**
	 * Get the main server ID.
	 *
	 * @return int|null Server ID with is_main flag, or null if not found.
	 */
	public static function getMainID(): ?int {
		global $rServers;
		foreach ($rServers as $rServerID => $rServer) {
			if ($rServer['is_main']) {
				return $rServerID;
			}
		}
		return null;
	}

	/**
	 * Add a process PID to the stream processing queue.
	 *
	 * Queue is stored on disk (igbinary). Dead PIDs are automatically
	 * filtered out on each call.
	 *
	 * @param int $rStreamID Stream ID.
	 * @param int $rAddPID   Process PID to add.
	 * @return void
	 */
	public static function addToQueue(int $rStreamID, int $rAddPID): void {
		$rActivePIDs = $rPIDs = array();
		if (file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
			$rPIDs = igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID)));
		}
		foreach ($rPIDs as $rPID) {
			if (ProcessManager::isRunning($rPID, 'php-fpm')) {
				$rActivePIDs[] = $rPID;
			}
		}
		if (!in_array($rAddPID, $rActivePIDs, true)) {
			$rActivePIDs[] = $rAddPID;
		}
		file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs), LOCK_EX);
	}

	/**
	 * Remove a process PID from the stream processing queue.
	 *
	 * If the queue becomes empty, the file is deleted.
	 *
	 * @param int $rStreamID Stream ID.
	 * @param int $rPID      Process PID to remove.
	 * @return void
	 */
	public static function removeFromQueue(int $rStreamID, int $rPID): void {
		$rActivePIDs = array();
		foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) ?: array()) as $rActivePID) {
			if (ProcessManager::isRunning($rActivePID, 'php-fpm') && $rPID != $rActivePID) {
				$rActivePIDs[] = $rActivePID;
			}
		}
		if (0 < count($rActivePIDs)) {
			file_put_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID), igbinary_serialize($rActivePIDs), LOCK_EX);
		} else {
			@unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}
	}

	/**
	 * Update a connection in Redis with applied changes.
	 *
	 * With $rOption='open' — adds UUID to all sorted sets (LIVE, LINE#, STREAM#, SERVER#, etc.).
	 * With $rOption='close' — removes UUID from sorted sets and marks as ENDED.
	 * Data is igbinary-serialized and saved atomically via MULTI/EXEC.
	 *
	 * @param array       $rData    Current connection data.
	 * @param array       $rChanges Fields to update (key => value).
	 * @param string|null $rOption  Action: 'open', 'close', or null (data update only).
	 * @return array|null Updated connection data, or null on exec failure.
	 */
	public static function updateConnection(array $rData, array $rChanges = [], ?string $rOption = null): ?array {
		$rRedis = RedisManager::instance();
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

	/**
	 * Send a signal to a server via Redis.
	 *
	 * Creates an entry in SIGNALS#{serverID} set and stores signal data.
	 * Used for remote termination of RTMP/HLS streams on other servers.
	 *
	 * @param int        $rPID        Process PID to terminate.
	 * @param int        $rServerID   Target server ID.
	 * @param int        $rRTMP       1 — RTMP, 0 — regular process.
	 * @param mixed|null $rCustomData Additional signal data.
	 * @return array|false MULTI/EXEC result.
	 */
	public static function redisSignal(int $rPID, int $rServerID, int $rRTMP, $rCustomData = null) {
		$rRedis = RedisManager::instance();
		$rKey = 'SIGNAL#' . md5($rServerID . '#' . $rPID . '#' . $rRTMP);
		$rData = array('pid' => $rPID, 'server_id' => $rServerID, 'rtmp' => $rRTMP, 'time' => time(), 'custom_data' => $rCustomData, 'key' => $rKey);
		return $rRedis->multi()->sAdd('SIGNALS#' . $rServerID, $rKey)->set($rKey, igbinary_serialize($rData))->exec();
	}

	/**
	 * Get connections for multiple users (batch).
	 *
	 * Uses MULTI pipeline for parallel LINE# sorted set queries.
	 *
	 * @param int[] $rUserIDs  Array of user IDs.
	 * @param bool  $rCount    If true — return only connection count per user.
	 * @param bool  $rKeysOnly If true — return only Redis keys (UUIDs) without deserialization.
	 * @return array Map of userID => connections[] (or count, or keys).
	 */
	public static function getUserConnections(array $rUserIDs, bool $rCount = false, bool $rKeysOnly = false): array {
		$rRedis = RedisManager::instance();
		$rMulti = $rRedis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rMulti->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		if (!is_array($rGroups)) {
			return ($rKeysOnly ? $rRedisKeys : $rConnectionMap);
		}
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
			if (!$rCount && !empty($rRedisKeys)) {
				foreach ($rRedis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['user_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}

	/**
	 * Get connections for multiple servers/proxies (batch).
	 *
	 * Uses MULTI pipeline for parallel SERVER#/PROXY# sorted set queries.
	 *
	 * @param int[] $rServerIDs Array of server IDs.
	 * @param bool  $rProxy     If true — query PROXY# instead of SERVER#.
	 * @param bool  $rCount     If true — return only count.
	 * @param bool  $rKeysOnly  If true — return only UUID keys.
	 * @return array Map of serverID => connections[] (or count, or keys).
	 */
	public static function getServerConnections(array $rServerIDs, bool $rProxy = false, bool $rCount = false, bool $rKeysOnly = false): array {
		$rRedis = RedisManager::instance();
		$rMulti = $rRedis->multi();
		foreach ($rServerIDs as $rServerID) {
			$rMulti->zRevRangeByScore(($rProxy ? 'PROXY#' . $rServerID : 'SERVER#' . $rServerID), '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		if (!is_array($rGroups)) {
			return ($rKeysOnly ? $rRedisKeys : $rConnectionMap);
		}
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
			if (!$rCount && !empty($rRedisKeys)) {
				foreach ($rRedis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow['server_id']][] = $rRow;
				}
			}
			return $rConnectionMap;
		}
		return $rRedisKeys;
	}

	/**
	 * Get the most recent connection for each user.
	 *
	 * Queries LINE# with LIMIT 0,1 via MULTI pipeline and deserializes results.
	 *
	 * @param int[] $rUserIDs Array of user IDs.
	 * @return array<int, array> Map of userID => connection data.
	 */
	public static function getFirstConnection(array $rUserIDs): array {
		$rRedis = RedisManager::instance();
		$rMulti = $rRedis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rMulti->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf', array('limit' => array(0, 1)));
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		if (!is_array($rGroups)) {
			return $rConnectionMap;
		}
		foreach ($rGroups as $rKeys) {
			if (0 < count($rKeys)) {
				$rRedisKeys[] = $rKeys[0];
			}
		}
		if (empty($rRedisKeys)) {
			return $rConnectionMap;
		}
		foreach ($rRedis->mGet(array_unique($rRedisKeys)) as $rRow) {
			$rRow = igbinary_unserialize($rRow);
			$rConnectionMap[$rRow['user_id']] = $rRow;
		}
		return $rConnectionMap;
	}

	/**
	 * Get connections for multiple streams (batch).
	 *
	 * Uses MULTI pipeline for parallel STREAM# sorted set queries.
	 *
	 * @param int[] $rStreamIDs Array of stream IDs.
	 * @param bool  $rGroup     If true — group by stream_id, otherwise by stream_id + server_id.
	 * @param bool  $rCount     If true — return only connection count per stream.
	 * @return array Map of streamID => connections[] (or count).
	 */
	public static function getStreamConnections(array $rStreamIDs, bool $rGroup = true, bool $rCount = false): array {
		$rRedis = RedisManager::instance();
		$rMulti = $rRedis->multi();
		foreach ($rStreamIDs as $rStreamID) {
			$rMulti->zRevRangeByScore('STREAM#' . $rStreamID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = array();
		if (!is_array($rGroups)) {
			return $rConnectionMap;
		}
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rStreamIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 < count($rKeys)) {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		if (!$rCount && !empty($rRedisKeys)) {
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

	/**
	 * Universal Redis connection query with multiple filters.
	 *
	 * Reads from LIVE/LINE#/STREAM#/SERVER# depending on provided filters.
	 * Supports counting, grouping by user identity, and HLS filtering.
	 *
	 * @param int|null $rUserID    Filter by user.
	 * @param int|null $rServerID  Filter by server.
	 * @param int|null $rStreamID  Filter by stream.
	 * @param bool     $rOpenOnly  Only open connections (hls_end=0).
	 * @param bool     $rCountOnly Return [total, unique_users] instead of data.
	 * @param bool     $rGroup     Group by user/identity.
	 * @param bool     $rHLSOnly   Exclude HLS connections.
	 * @return array Connections grouped by identity, or [count, unique].
	 */
	public static function getRedisConnections(?int $rUserID = null, ?int $rServerID = null, ?int $rStreamID = null, bool $rOpenOnly = false, bool $rCountOnly = false, bool $rGroup = true, bool $rHLSOnly = false): array {
		$rRedis = RedisManager::instance();
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

	/**
	 * Get a single connection by UUID.
	 *
	 * @param string $rUUID Connection UUID (Redis key).
	 * @return array|null Deserialized connection data, or null if not found.
	 */
	public static function getConnection(string $rUUID): ?array {
		$rRedis = RedisManager::instance();
		if (!$rRedis) {
			return null;
		}
		$raw = $rRedis->get($rUUID);
		return ($raw !== false) ? igbinary_unserialize($raw) : null;
	}

	/**
	 * Create a new connection in Redis.
	 *
	 * Atomically (MULTI/EXEC) adds UUID to all sorted sets:
	 * LINE#, LINE_ALL#, STREAM#, SERVER#, SERVER_LINES#, PROXY#,
	 * CONNECTIONS, LIVE, and stores igbinary-serialized data.
	 *
	 * @param array $rData Connection data (uuid, identity, stream_id, server_id, etc.).
	 * @return array|false MULTI/EXEC result.
	 */
	public static function createConnection(array $rData) {
		$rRedis = RedisManager::instance();
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

	/**
	 * Get connections for a specific user/line.
	 *
	 * @param int  $rUserID User ID.
	 * @param bool $rActive If true — only active (LINE#), otherwise all (LINE_ALL#).
	 * @param bool $rKeys   Unused (overwritten internally).
	 * @return array UUID keys or deserialized connection data.
	 */
	public static function getLineConnections(int $rUserID, bool $rActive = false, bool $rKeys = false): array {
		$rRedis = RedisManager::instance();
		$rKeys = $rRedis->zRangeByScore((($rActive ? 'LINE#' : 'LINE_ALL#')) . $rUserID, '-inf', '+inf');
		if ($rKeys) {
			return $rKeys;
		}
		if (0 >= count($rKeys)) {
			return array();
		}
		return array_map('igbinary_unserialize', $rRedis->mGet($rKeys));
	}

	/**
	 * Get all ended (ENDED) connections.
	 *
	 * Reads members from ENDED set and deserializes data via mGet.
	 *
	 * @return array Array of deserialized ended connection data.
	 */
	public static function getEnded(): array {
		$rRedis = RedisManager::instance();
		$rKeys = $rRedis->sMembers('ENDED');
		if (0 >= count($rKeys)) {
			return array();
		}
		return array_map('igbinary_unserialize', $rRedis->mGet($rKeys));
	}

	/**
	 * Get proxy servers attached to the specified server.
	 *
	 * @param int  $rServerID Parent server ID.
	 * @param bool $rOnline   If true — only online proxies.
	 * @return array<int, array> Map of proxyID => server data.
	 */
	public static function getProxies(int $rServerID, bool $rOnline = true): array {
		global $rServers;
		$rReturn = array();
		foreach ($rServers as $rProxyID => $rServerInfo) {
			if ($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline)) {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}
		return $rReturn;
	}

	/**
	 * Close an active connection.
	 *
	 * Performs the full close cycle: kills the process (RTMP drop client,
	 * posix_kill, or Redis signal), removes from Redis sorted sets,
	 * cleans tmp files, and writes to the activity log.
	 *
	 * @param array|string $rActivityInfo Connection data or UUID/activity_id.
	 * @param bool         $rRemove       Remove connection from Redis/MySQL.
	 * @param bool         $rEnd          Mark HLS connection as ended.
	 * @return bool True on successful close, false otherwise.
	 */
	public static function closeConnection($rActivityInfo, bool $rRemove = true, bool $rEnd = true): bool {
		if (!empty($rActivityInfo)) {
			global $rSettings, $rServers, $db;
			if (!$rSettings['redis_handler'] || is_object(RedisManager::instance())) {
			} else {
				RedisManager::ensureConnected();
			}
			$rRedisObj = RedisManager::instance();
			if (is_array($rActivityInfo)) {
			} else {
				if (!$rSettings['redis_handler']) {
					if (strlen(strval($rActivityInfo)) == 32) {
						$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ?', $rActivityInfo);
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo);
					}
					$rActivityInfo = $db->get_row();
				} else {
					$raw = $rRedisObj->get($rActivityInfo);
					$rActivityInfo = ($raw !== false) ? igbinary_unserialize($raw) : null;
				}
			}
			if (is_array($rActivityInfo)) {
				if ($rActivityInfo['container'] == 'rtmp') {
					if ($rActivityInfo['server_id'] == SERVER_ID) {
						shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rActivityInfo['pid']) . '" >/dev/null 2>/dev/null &');
					} else {
						if ($rSettings['redis_handler']) {
							self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
						} else {
							$db->query('INSERT INTO `signals` (`pid`,`server_id`,`rtmp`,`time`) VALUES(?,?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
						}
					}
				} else {
					if ($rActivityInfo['container'] == 'hls') {
						if (!(!$rRemove && $rEnd && $rActivityInfo['hls_end'] == 0)) {
						} else {
							if ($rSettings['redis_handler']) {
								self::updateConnection($rActivityInfo, array(), 'close');
							} else {
								$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
							}
							@unlink(CONS_TMP_PATH . $rActivityInfo['stream_id'] . '/' . $rActivityInfo['uuid']);
						}
					} else {
						if ($rActivityInfo['server_id'] == SERVER_ID) {
							if (!($rActivityInfo['pid'] != getmypid() && is_numeric($rActivityInfo['pid']) && 0 < $rActivityInfo['pid'])) {
							} else {
								posix_kill(intval($rActivityInfo['pid']), 9);
							}
						} else {
							if ($rSettings['redis_handler']) {
								self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 0);
							} else {
								$db->query('INSERT INTO `signals` (`pid`,`server_id`,`time`) VALUES(?,?,UNIX_TIMESTAMP())', $rActivityInfo['pid'], $rActivityInfo['server_id']);
							}
						}
					}
				}
				if ($rActivityInfo['server_id'] == SERVER_ID) {
					@unlink(CONS_TMP_PATH . $rActivityInfo['uuid']);
				}
				if ($rRemove) {
					if ($rActivityInfo['server_id'] == SERVER_ID) {
						@unlink(CONS_TMP_PATH . $rActivityInfo['stream_id'] . '/' . $rActivityInfo['uuid']);
					}
					if ($rSettings['redis_handler']) {
						$rRedis = $rRedisObj->multi();
						$rRedis->zRem('LINE#' . $rActivityInfo['identity'], $rActivityInfo['uuid']);
						$rRedis->zRem('LINE_ALL#' . $rActivityInfo['identity'], $rActivityInfo['uuid']);
						$rRedis->zRem('STREAM#' . $rActivityInfo['stream_id'], $rActivityInfo['uuid']);
						$rRedis->zRem('SERVER#' . $rActivityInfo['server_id'], $rActivityInfo['uuid']);
						if ($rActivityInfo['user_id']) {
							$rRedis->zRem('SERVER_LINES#' . $rActivityInfo['server_id'], $rActivityInfo['uuid']);
						}
						if ($rActivityInfo['proxy_id']) {
							$rRedis->zRem('PROXY#' . $rActivityInfo['proxy_id'], $rActivityInfo['uuid']);
						}
						$rRedis->del($rActivityInfo['uuid']);
						$rRedis->zRem('CONNECTIONS', $rActivityInfo['uuid']);
						$rRedis->zRem('LIVE', $rActivityInfo['uuid']);
						$rRedis->sRem('ENDED', $rActivityInfo['uuid']);
						$rRedis->exec();
					} else {
						$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
					}
				}
				self::writeOfflineActivity($rSettings, $rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'] ?? '', $rActivityInfo['divergence'] ?? 0, $rActivityInfo['hmac_id'] ?? null, $rActivityInfo['hmac_identifier'] ?? '');
				return true;
			}
			return false;
		}
		return false;
	}

	/**
	 * Write closed connection data to the activity log file.
	 *
	 * Log is written as base64(json) per line to LOGS_TMP_PATH/activity.
	 * Only writes if save_closed_connection setting is enabled.
	 *
	 * @param array       $rSettings       Global settings.
	 * @param int         $rServerID       Server ID.
	 * @param int         $rProxyID        Proxy ID (0 if no proxy).
	 * @param int         $rUserID         User ID.
	 * @param int         $rStreamID       Stream ID.
	 * @param int         $rStart          Connection start Unix timestamp.
	 * @param string      $rUserAgent      Client User-Agent.
	 * @param string      $rIP             Client IP address.
	 * @param string      $rExtension      Container type (rtmp, hls, etc.).
	 * @param string      $rGeoIP          GeoIP country code.
	 * @param string      $rISP            ISP name.
	 * @param string      $rExternalDevice External device identifier.
	 * @param int         $rDivergence     Divergence value.
	 * @param int|null    $rIsHMAC         HMAC ID.
	 * @param string      $rIdentifier     HMAC identifier.
	 * @return void
	 */
	public static function writeOfflineActivity(array $rSettings, int $rServerID, int $rProxyID, int $rUserID, int $rStreamID, int $rStart, string $rUserAgent, string $rIP, string $rExtension, string $rGeoIP, string $rISP, string $rExternalDevice = '', int $rDivergence = 0, ?int $rIsHMAC = null, string $rIdentifier = ''): void {
		if ($rSettings['save_closed_connection'] != 0) {
			if (!($rServerID && $rUserID && $rStreamID)) {
			} else {
				$rActivityInfo = array('user_id' => intval($rUserID), 'stream_id' => intval($rStreamID), 'server_id' => intval($rServerID), 'proxy_id' => intval($rProxyID), 'date_start' => intval($rStart), 'user_agent' => $rUserAgent, 'user_ip' => htmlentities($rIP), 'date_end' => time(), 'container' => $rExtension, 'geoip_country_code' => $rGeoIP, 'isp' => $rISP, 'external_device' => htmlentities($rExternalDevice), 'divergence' => intval($rDivergence), 'hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier);
				file_put_contents(LOGS_TMP_PATH . 'activity', base64_encode(json_encode($rActivityInfo)) . "\n", FILE_APPEND | LOCK_EX);
			}
		} else {
			return;
		}
	}

	/**
	 * Count active (live) connections on a server or proxy.
	 *
	 * In Redis mode counts via getRedisConnections.
	 * In MySQL mode performs COUNT(*) on lines_live.
	 *
	 * @param int  $rServerID Server or proxy ID.
	 * @param bool $rProxy    If true — count for proxy.
	 * @return int Number of active connections.
	 */
	public static function getLiveConnections(int $rServerID, bool $rProxy = false): int {
		global $db;

		if (SettingsManager::getAll()['redis_handler']) {
			$rCount = 0;

			if ($rProxy) {
				$rParentIDs = ServerRepository::getAll()[$rServerID]['parent_id'];

				foreach ($rParentIDs as $rParentID) {
					foreach (self::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
						if ($rConnection['proxy_id'] != $rServerID) {
						} else {
							$rCount++;
						}
					}
				}
			} else {
				list($rCount) = self::getRedisConnections(null, $rServerID, null, true, true, false);
			}

			return $rCount;
		} else {
			if ($rProxy) {
				$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `proxy_id` = ? AND `hls_end` = 0;', $rServerID);
			} else {
				$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
			}

			return $db->get_row()['count'];
		}
	}
}
