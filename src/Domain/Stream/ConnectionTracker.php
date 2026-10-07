<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;

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
	use DatabaseAware;

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
		global $rSettings, $rServers;
		$db = self::db();
		$rRedis = RedisManager::instance();
		$rFile = ($rProxy ? 'proxy_capacity' : 'servers_capacity');
		if ($rSettings['redis_handler'] && $rProxy && $rSettings['split_by'] == 'maxclients') {
			$rSettings['split_by'] = 'guar_band';
		}

		if ($rSettings['redis_handler'] && $rRedis) {
			$rRows = [];
			$rResults = null;
			// One reconnect+retry: phpredis may silently reconnect a broken
			// socket without replaying AUTH, so a healthy-looking connection
			// can suddenly throw NOAUTH (see RedisManager::reconnect()).
			for ($rAttempt = 0; $rAttempt < 2 && $rRedis; $rAttempt++) {
				try {
					$rMulti = $rRedis->multi();
					// multi() returns false (not the pipeline) on a broken socket;
					// calling zCard() on that bool would fatal outside the RedisException
					// catch. Turn it into a RedisException so we reconnect and retry.
					if (!$rMulti instanceof \Redis) {
						throw new \RedisException('Redis multi() did not return a pipeline');
					}
					foreach (array_keys($rServers) as $rServerID) {
						if ($rServers[$rServerID]['server_online']) {
							$rMulti->zCard((($rProxy ? 'PROXY#' : 'SERVER#')) . $rServerID);
						}
					}
					$rResults = $rMulti->exec();
					break;
				} catch (\RedisException $e) {
					$rRedis = RedisManager::reconnect();
				}
			}
			if (!is_array($rResults)) {
				$rResults = [];
			}
			$i = 0;
			foreach (array_keys($rServers) as $rServerID) {
				if ($rServers[$rServerID]['server_online']) {
					$rRows[$rServerID] = ['online_clients' => ($rResults[$i] ?? 0)];
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
			$rServerSpeed = [];
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

		// A node MAIN's liveness loop finds silent for over 10 s (suspect) weighs
		// double, so the balancer sends it fewer new viewers.
		if (!$rProxy) {
			foreach ($rRows as $rServerID => $rRow) {
				if (isset($rRow['capacity'])) {
					$rRows[$rServerID]['capacity'] = $rRow['capacity'] * ClusterHealth::weight((int) $rServerID);
				}
			}
		}

		if (defined('CACHE_TMP_PATH') && is_dir(CACHE_TMP_PATH) && is_writable(CACHE_TMP_PATH)) {
			FileCache::writeAtomic(CACHE_TMP_PATH . $rFile, (string) json_encode($rRows));
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
		global $rSettings;
		$db = self::db();
		$rRedis = RedisManager::instance();
		if ($rSettings['redis_handler'] && $rRedis) {
			if ($rServerID) {
				$rKeys = $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf');
			} elseif ($rUserID) {
				$rKeys = $rRedis->zRangeByScore('LINE#' . $rUserID, '-inf', '+inf');
			} elseif ($rStreamID) {
				$rKeys = $rRedis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
			} else {
				$rKeys = $rRedis->zRangeByScore('LIVE', '-inf', '+inf');
			}

			// zRangeByScore/mGet return false on a failed connection (e.g. an
			// unauthenticated socket during a Redis restart) — degrade to empty.
			if (is_array($rKeys) && count($rKeys) > 0) {
				$rData = self::records($rRedis, $rKeys);
				if ($rData !== null) {
					return [$rKeys, $rData];
				}
			}
			return [[], []];
		}

		$rWhere = [];
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
	 */
	public static function addToQueue(int $rStreamID, int $rAddPID): void {
		$rActivePIDs = $rPIDs = [];
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
	 */
	public static function removeFromQueue(int $rStreamID, int $rPID): void {
		$rQueueFile = SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID);
		if (!file_exists($rQueueFile)) {
			return;
		}
		$rActivePIDs = [];
		foreach ((igbinary_unserialize(file_get_contents($rQueueFile)) ?: []) as $rActivePID) {
			if (ProcessManager::isRunning($rActivePID, 'php-fpm') && $rPID != $rActivePID) {
				$rActivePIDs[] = $rActivePID;
			}
		}
		if (0 < count($rActivePIDs)) {
			file_put_contents($rQueueFile, igbinary_serialize($rActivePIDs), LOCK_EX);
		} else {
			@unlink($rQueueFile);
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
		if (!$rRedis instanceof \Redis) {
			return null;
		}
		$rOrigData = $rData;
		foreach ($rChanges as $rKey => $rValue) {
			$rData[$rKey] = $rValue;
		}
		$rMulti = $rRedis->multi();
		if ($rOption == 'open') {
			// A viewer that moved (an HLS refresh served by another server or
			// proxy) leaves the old sets, or they count it there forever.
			foreach (['LINE#' => 'identity', 'STREAM#' => 'stream_id', 'SERVER#' => 'server_id', 'PROXY#' => 'proxy_id'] as $rSet => $rField) {
				if (!empty($rOrigData[$rField]) && $rOrigData[$rField] != $rData[$rField]) {
					$rMulti->zRem($rSet . $rOrigData[$rField], $rData['uuid']);
				}
			}
			$rMoved = $rOrigData['server_id'] != $rData['server_id'];
			if ($rMoved && $rOrigData['user_id']) {
				$rMulti->zRem('SERVER_LINES#' . $rOrigData['server_id'], $rData['uuid']);
			}
			$rMulti->sRem('ENDED', $rData['uuid']);
			$rMulti->zAdd('LIVE', $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('LINE#' . $rData['identity'], $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('STREAM#' . $rData['stream_id'], $rData['date_start'], $rData['uuid']);
			$rMulti->zAdd('SERVER#' . $rData['server_id'], $rData['date_start'], $rData['uuid']);
			if ($rData['proxy_id']) {
				$rMulti->zAdd('PROXY#' . $rData['proxy_id'], $rData['date_start'], $rData['uuid']);
			}
			if ($rData['hls_end'] == 1 || $rMoved) {
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

	/** How long a Redis-mode signal waits for its server (seconds). */
	public const SIGNAL_TTL = 300;

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
	public static function redisSignal(int $rPID, int $rServerID, int $rRTMP, mixed $rCustomData = null) {
		// A node with the COMMANDS flow gets a signed command instead (MAIN only):
		// conn.kill_worker for a worker pid, conn.drop for a daemon viewer.
		if (class_exists(ClusterRoute::class)) {
			$rRouted = $rQueued = false;
			if ($rCustomData === null && $rPID > 0) {
				[$rRouted, $rQueued] = ClusterRoute::kill($rServerID, $rPID, $rRTMP === 1);
			} elseif (is_array($rCustomData) && ($rCustomData['type'] ?? '') === 'drop_con') {
				[$rRouted, $rQueued] = ClusterRoute::drop($rServerID, (string) ($rCustomData['uuid'] ?? ''));
			}
			if ($rRouted) {
				return $rQueued ? [true, true] : false;
			}
		}
		$rRedis = RedisManager::instance();
		if (!$rRedis instanceof \Redis) {
			return false;
		}
		// The payload is part of the key when there is one: pid-less signals
		// (a daemon viewer's drop_con) would otherwise all share one key per server
		// and overwrite each other before the target's signals daemon read them.
		$rKey = 'SIGNAL#' . md5($rServerID . '#' . $rPID . '#' . $rRTMP . (is_null($rCustomData) ? '' : '#' . json_encode($rCustomData)));
		$rData = ['pid' => $rPID, 'server_id' => $rServerID, 'rtmp' => $rRTMP, 'time' => time(), 'custom_data' => $rCustomData, 'key' => $rKey];
		// Short-lived: a server that has not read it by then is down, and its
		// worker gone with it; hours later the pid may be someone else's.
		return $rRedis->multi()->sAdd('SIGNALS#' . $rServerID, $rKey)->expire('SIGNALS#' . $rServerID, self::SIGNAL_TTL)->set($rKey, igbinary_serialize($rData), ['ex' => self::SIGNAL_TTL])->exec();
	}

	/**
	 * Read the raw connection payloads behind a batch of Redis keys.
	 *
	 * `mGet` answers false once the connection has dropped, and the manager
	 * hands back null when Redis is unreachable at all — iterating either is a
	 * warning or, for null, a fatal. An empty batch never reaches Redis.
	 *
	 * @param \Redis|null $rRedis Active connection, or null when unreachable.
	 * @param string[]    $rKeys  Connection keys.
	 * @return array<int, mixed> Raw igbinary payloads.
	 */
	public static function readConnections(?\Redis $rRedis, array $rKeys): array {
		if ($rKeys === [] || !$rRedis instanceof \Redis) {
			return [];
		}
		// mGet answers false on a dropped connection and the client itself while
		// pipelining, so anything but an array counts as "no connections".
		$rRows = $rRedis->mGet($rKeys);
		return is_array($rRows) ? array_values($rRows) : [];
	}

	/**
	 * Read a line's connection rows from its LINE# (or LINE_ALL#) sorted set.
	 *
	 * getLineConnections() answers the set's members — connection keys, not
	 * the connections — so a caller that needs the rows (IP, start time) reads
	 * them here. An unreachable Redis, a failed call and an unreadable payload
	 * all read as "no connection".
	 *
	 * @param \Redis|null $rRedis  Active connection, or null when unreachable.
	 * @param int         $rLineID Line ID.
	 * @param bool        $rActive If true — only active (LINE#), otherwise all (LINE_ALL#).
	 * @return array<int, array> Unserialized connection rows.
	 */
	public static function getLineConnectionRows(?\Redis $rRedis, int $rLineID, bool $rActive = true): array {
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		// zRangeByScore returns false on a failed connection — degrade to empty.
		$rKeys = $rRedis->zRangeByScore(($rActive ? 'LINE#' : 'LINE_ALL#') . $rLineID, '-inf', '+inf');
		if (!is_array($rKeys) || count($rKeys) === 0) {
			return [];
		}
		$rConnections = [];
		foreach (self::readConnections($rRedis, $rKeys) as $rRow) {
			$rRow = is_string($rRow) ? igbinary_unserialize($rRow) : false;
			if (is_array($rRow)) {
				$rConnections[] = $rRow;
			}
		}
		return $rConnections;
	}

	/**
	 * Pick the IP of the oldest connection — the one disallow_2nd_ip_con accepts.
	 *
	 * Rows that are not arrays or carry no user_ip are skipped; a row without
	 * date_start sorts after every dated one, and on a tie the first row wins.
	 *
	 * @param array $rRows Connection rows (see getLineConnectionRows()).
	 * @return string|null The oldest connection's IP, or null when there is none.
	 */
	public static function oldestConnectionIP(array $rRows): ?string {
		$rAcceptIP = null;
		$rOldestStart = PHP_INT_MAX;
		foreach ($rRows as $rRow) {
			if (!is_array($rRow) || empty($rRow['user_ip'])) {
				continue;
			}
			$rStart = isset($rRow['date_start']) ? intval($rRow['date_start']) : PHP_INT_MAX;
			if (is_null($rAcceptIP) || $rStart < $rOldestStart) {
				$rAcceptIP = (string) $rRow['user_ip'];
				$rOldestStart = $rStart;
			}
		}
		return $rAcceptIP;
	}

	/**
	 * The IP a line's first open connection came from, the one a second IP is
	 * refused against (disallow_2nd_ip_con), from whichever store is in use.
	 * HMAC identities have no line id, so neither path matches them.
	 *
	 * @param array<string, mixed> $rSettings Settings (reads redis_handler).
	 */
	public static function acceptedIP(array $rSettings, mixed $rLineID): ?string {
		if (AgentConnections::enabled() && !empty($rLineID)) {
			$rOldest = AgentConnections::oldest($rLineID);
			if ($rOldest !== null) {
				return $rOldest ? (string) $rOldest['user_ip'] : null;
			}
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			self::refuseUnchecked();
		}
		if ($rSettings['redis_handler']) {
			// The LINE# set holds connection keys; the oldest connection's IP is
			// read from the rows behind them.
			return self::acceptedLineIP(RedisManager::instance(), $rLineID);
		}
		// The FIRST connection's IP is the accepted one, as the Redis path picks
		// it (oldest date_start).
		$db = self::store();
		$db->query('SELECT `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0 ORDER BY `activity_id` ASC LIMIT 1;', $rLineID);
		return $db->num_rows() == 1 ? $db->get_row()['user_ip'] : null;
	}

	/**
	 * The IP disallow_2nd_ip_con accepts for a line in Redis mode: its oldest
	 * active connection's.
	 *
	 * An HMAC token has no line id (null) and is never checked — the MySQL
	 * branch's `user_id = NULL` matches nothing either — so Redis is not asked.
	 *
	 * @param \Redis|null $rRedis  Active connection, or null when unreachable.
	 * @param mixed       $rLineID Line ID; empty for an HMAC identity.
	 * @return string|null The accepted IP, or null when there is none.
	 */
	public static function acceptedLineIP(?\Redis $rRedis, mixed $rLineID): ?string {
		if (empty($rLineID)) {
			return null;
		}
		return self::oldestConnectionIP(self::getLineConnectionRows($rRedis, intval($rLineID), true));
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
		return self::connectionsBySet($rUserIDs, 'LINE#', 'user_id', $rCount, $rKeysOnly);
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
		return self::connectionsBySet($rServerIDs, $rProxy ? 'PROXY#' : 'SERVER#', 'server_id', $rCount, $rKeysOnly);
	}

	/**
	 * getUserConnections() and getServerConnections(): the sorted sets
	 * `<$rPrefix><id>` for each id, read in one MULTI pipeline. With $rCount,
	 * id => how many uuids its set holds; with $rKeysOnly, the uuids of all
	 * the sets, deduplicated; else their records, grouped by $rGroupField.
	 *
	 * @param array<int, int|string> $rIDs
	 * @return array
	 */
	private static function connectionsBySet(array $rIDs, string $rPrefix, string $rGroupField, bool $rCount, bool $rKeysOnly): array {
		$rRedis = RedisManager::instance();
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		$rMulti = $rRedis->multi();
		foreach ($rIDs as $rID) {
			$rMulti->zRevRangeByScore($rPrefix . $rID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = [];
		if (!is_array($rGroups)) {
			return ($rKeysOnly ? $rRedisKeys : $rConnectionMap);
		}
		foreach ($rGroups as $rGroupID => $rKeys) {
			if ($rCount) {
				$rConnectionMap[$rIDs[$rGroupID]] = count($rKeys);
			} else {
				if (0 < count($rKeys)) {
					$rRedisKeys = array_merge($rRedisKeys, $rKeys);
				}
			}
		}
		$rRedisKeys = array_unique($rRedisKeys);
		if (!$rKeysOnly) {
			if (!$rCount && $rRedisKeys !== []) {
				foreach ($rRedis->mGet($rRedisKeys) as $rRow) {
					$rRow = igbinary_unserialize($rRow);
					$rConnectionMap[$rRow[$rGroupField]][] = $rRow;
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
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		$rMulti = $rRedis->multi();
		foreach ($rUserIDs as $rUserID) {
			$rMulti->zRevRangeByScore('LINE#' . $rUserID, '+inf', '-inf', ['limit' => [0, 1]]);
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = [];
		if (!is_array($rGroups)) {
			return $rConnectionMap;
		}
		foreach ($rGroups as $rKeys) {
			if (0 < count($rKeys)) {
				$rRedisKeys[] = $rKeys[0];
			}
		}
		if ($rRedisKeys === []) {
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
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		$rMulti = $rRedis->multi();
		foreach ($rStreamIDs as $rStreamID) {
			$rMulti->zRevRangeByScore('STREAM#' . $rStreamID, '+inf', '-inf');
		}
		$rGroups = $rMulti->exec();
		$rConnectionMap = $rRedisKeys = [];
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
		if (!$rCount && $rRedisKeys !== []) {
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
	 * Stream IDs this server is actively serving on-demand — an on_demand row
	 * with a running feed (pid set). Used by the on-demand killer to know which
	 * streams to check for idleness.
	 *
	 * @param int $rServerID This server's id.
	 * @return array<int,int> List of stream ids.
	 */
	public static function activeOnDemandStreamIDs(int $rServerID): array {
		$db = self::db();
		$db->query("SELECT stream_id FROM streams_servers WHERE server_id = ? AND on_demand = 1 AND pid IS NOT NULL AND pid > 0", $rServerID);
		return $db->get_column();
	}

	/**
	 * For each given stream, how many child servers are actively restreaming it
	 * from this server (a live parent_id row with both a running feed and
	 * monitor). A stream with attached restreamers must not be killed.
	 *
	 * @param array<int,int> $rStreamIDs Streams to count for.
	 * @param int            $rServerID  This server's id (the parent).
	 * @return array<int,int> stream_id => restreamer count.
	 */
	public static function attachedRestreamCounts(array $rStreamIDs, int $rServerID): array {
		return self::countByStream('SELECT stream_id, COUNT(*) AS cnt FROM streams_servers WHERE parent_id = ? AND pid > 0 AND monitor_pid > 0', $rServerID, $rStreamIDs);
	}

	/**
	 * DB fallback for the live viewer count per stream on this server (used when
	 * the Redis connection store is off — otherwise getStreamConnections covers
	 * it). Counts open live lines (hls_end = 0).
	 *
	 * @param array<int,int> $rStreamIDs Streams to count for.
	 * @param int            $rServerID  This server's id.
	 * @return array<int,int> stream_id => viewer count.
	 */
	public static function onlineClientCounts(array $rStreamIDs, int $rServerID): array {
		return self::countByStream('SELECT stream_id, COUNT(*) AS cnt FROM lines_live WHERE server_id = ? AND hls_end = 0', $rServerID, $rStreamIDs);
	}

	/**
	 * attachedRestreamCounts() and onlineClientCounts(): $rSelect (a
	 * `COUNT(*) AS cnt` over one table, its one placeholder the server) for
	 * $rStreamIDs, grouped by stream: stream_id => count.
	 *
	 * @param array<int,int> $rStreamIDs
	 * @return array<int,int>
	 */
	private static function countByStream(string $rSelect, int $rServerID, array $rStreamIDs): array {
		if ($rStreamIDs === []) {
			return [];
		}
		$db = self::db();
		$rPlaceholders = str_repeat('?,', count($rStreamIDs) - 1) . '?';
		$db->query($rSelect . " AND stream_id IN ($rPlaceholders) GROUP BY stream_id", $rServerID, ...$rStreamIDs);
		$rCounts = [];
		foreach ($db->get_rows(true, 'stream_id') as $rID => $rRow) {
			$rCounts[$rID] = (int) $rRow['cnt'];
		}
		return $rCounts;
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
		if (!$rRedis instanceof \Redis) {
			return ($rCountOnly ? [0, 0] : []);
		}
		$rReturn = ($rCountOnly ? [0, 0] : []);
		$rUniqueUsers = [];
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
				if ((!$rServerID || $rServerID == $rRow['server_id']) && (!$rStreamID || $rStreamID == $rRow['stream_id']) && (!$rUserID || $rUserID == $rRow['user_id']) && (!$rHLSOnly || $rRow['container'] != 'hls')) {
					$rUUID = StoredConnections::identity(is_array($rRow) ? $rRow : []); // a record gone since the zRange reads as false
					if ($rCountOnly) {
						$rReturn[0]++;
						$rUniqueUsers[] = $rUUID;
					} else {
						if ($rGroup) {
							if (!isset($rReturn[$rUUID])) {
								$rReturn[$rUUID] = [];
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
		if (!$rRedis instanceof \Redis) {
			return null;
		}
		$raw = $rRedis->get($rUUID);
		return ($raw !== false) ? igbinary_unserialize($raw) : null;
	}

	/**
	 * Deterministic connection id for an HLS viewer.
	 *
	 * HLS is stateless: the player re-fetches the playlist and re-selects
	 * channels, and each request through auth.php previously minted a fresh
	 * random uuid — so every (re)select created a NEW tracked connection that the
	 * 30s/60s reaper only cleared slowly, ballooning the live-connection count on
	 * channel switch. Deriving the id from line identity + stream + IP +
	 * user-agent makes the same player reuse ONE connection instead.
	 *
	 * Deliberately per (identity, stream, IP, user-agent): different streams, IPs
	 * or players (user-agents) stay distinct connections — preserving multiple
	 * streams from one IP and multi-device counting — while the same player
	 * re-requesting the same channel from the same IP collapses to one.
	 *
	 * @param int|null    $rIsHMAC     HMAC id, or null for a regular line.
	 * @param string|null $rIdentifier HMAC identifier (null/'' for a regular line).
	 * @param int|string  $rUserId     Line id (used when not HMAC).
	 * @param int         $rStreamId   Stream id being watched.
	 * @param string      $rIp         Client IP.
	 * @param string      $rUserAgent  Client user-agent ('' when absent).
	 * @return string 32-char hex connection id.
	 */
	public static function hlsConnectionKey(?int $rIsHMAC, ?string $rIdentifier, int|string $rUserId, int $rStreamId, string $rIp, string $rUserAgent): string {
		$rIdentity = is_null($rIsHMAC) ? ('u' . intval($rUserId)) : ('h' . $rIsHMAC . '_' . ($rIdentifier ?? ''));

		return md5('hls#' . $rIdentity . '#' . intval($rStreamId) . '#' . $rIp . '#' . $rUserAgent);
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
		if (!$rRedis instanceof \Redis) {
			return false;
		}
		$rMulti = $rRedis->multi();
		// An HLS uuid is derived from the player (hlsConnectionKey), so a re-auth
		// after a close reuses it: leave ENDED, or the main server's reaper would
		// close and delete this fresh connection as the old ended one.
		$rMulti->sRem('ENDED', $rData['uuid']);
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
	 * The database handle the stream endpoints use now: the one their global
	 * `$db` holds, which connectLazy() and close() replace between a request's
	 * steps. An instance injected at boot (setDb) may be the one they closed.
	 */
	private static function store(): object {
		return DatabaseFactory::get() ?? self::db();
	}

	/**
	 * Refuse the request on a node in mode 2 whose agent did not answer a read
	 * a check depends on (this viewer's record, the line's other address): the
	 * agent is its only store, and an unchecked request is not let through.
	 * The endpoint's answer to a failed write.
	 */
	private static function refuseUnchecked(): never {
		generateError('LINE_CREATE_FAIL');
		exit();
	}

	/** Why the node's agent refused the viewer of the last openRecord(), or null. */
	private static ?string $rRefused = null;

	/**
	 * The reason the node's agent refused the last viewer openRecord() was
	 * asked to record (a refusal reason such as `LIMIT`), or null when it did
	 * not refuse it.
	 */
	public static function refusedAdmission(): ?string {
		return self::$rRefused;
	}

	/**
	 * Store a new connection: the one place a stream endpoint records a viewer
	 * (the connection store seam, cluster plan Phase 6). $rRecord is the Redis
	 * record (with `identity`); $rDbRow the `lines_live` columns this caller
	 * writes on the table path, exactly as it wrote them before.
	 *
	 * On a node whose agent holds its viewers, a viewer with a stream token is
	 * also admitted by the agent (AgentConnections::admission()): with MAIN's
	 * `adm` claim from the token, or by asking MAIN (conn_admit), or by the
	 * offline policy. A viewer the agent refuses is recorded nowhere: this
	 * returns false and refusedAdmission() says why, and the endpoint refuses
	 * it (StreamAuth::refuseAdmission).
	 *
	 * @param array<string, mixed> $rSettings Settings (reads redis_handler).
	 * @param array<string, mixed> $rRecord   Redis connection record.
	 * @param array<string, mixed> $rDbRow    Column => value for `lines_live` (code-controlled keys).
	 * @param array<string, mixed>|null $rToken The viewer's decrypted stream token, when it has one.
	 * @param int $rTimeOffset This node's servers.time_offset (its clock less MAIN's).
	 * @return mixed Truthy on success (Redis MULTI result or DB write result).
	 */
	public static function openRecord(array $rSettings, array $rRecord, array $rDbRow, ?array $rToken = null, int $rTimeOffset = 0) {
		self::$rRefused = null;
		if (AgentConnections::enabled()) {
			// An HLS viewer is recorded under its playlist key, not the token's
			// uuid MAIN reserved at mint: name the reservation, so MAIN releases
			// it with the viewer (ConnectionIngest).
			$rReserved = (string) ($rToken['adm_uuid'] ?? $rToken['uuid'] ?? '');
			if (is_array($rToken['adm'] ?? null) && $rReserved !== '' && $rReserved !== (string) $rRecord['uuid']) {
				$rRecord['adm_uuid'] = $rReserved;
			}
			// MAIN's proof that it minted the token for this viewer (`prf`), kept
			// in the record as `mint` for the record's life: the agent mirrors it
			// to MAIN, which stores a new record only with it under
			// cluster_conn_binding = enforce. It names the token's uuid.
			$rProof = $rToken['prf'] ?? null;
			if (is_array($rProof) && is_int($rProof['iat'] ?? null) && $rProof['iat'] > 0 && is_string($rProof['p'] ?? null) && preg_match('/^[0-9a-f]{32}\z/', $rProof['p']) && preg_match(AgentConnections::CONN_UUID, $rReserved)) {
				$rRecord['mint'] = $rReserved . '.' . $rProof['iat'] . '.' . $rProof['p'];
			}
			$rAdmission = $rToken === null ? null : AgentConnections::admission($rToken, $rRecord, time() - $rTimeOffset);
			$rOut = AgentConnections::register((string) $rRecord['uuid'], $rRecord, $rAdmission);
			if ($rOut === true) {
				return true; // the node's agent holds it and tells MAIN
			}
			if (is_string($rOut)) {
				self::$rRefused = $rOut;
				return false;
			}
			unset($rRecord['adm_uuid'], $rRecord['mint']);
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			return false;
		}
		if ($rSettings['redis_handler']) {
			return self::createConnection($rRecord);
		}
		$rColumns = array_keys($rDbRow);
		return self::store()->query('INSERT INTO `lines_live` (`' . implode('`,`', $rColumns) . '`) VALUES(' . implode(',', array_fill(0, count($rColumns), '?')) . ');', ...array_values($rDbRow));
	}

	/**
	 * A viewer's connection by its uuid (Redis or `lines_live`). On the table
	 * path, a request that finds none may be matched on other columns instead
	 * (a player's HTTP Range request carries no uuid of its own).
	 *
	 * @param array<string, mixed> $rSettings  Settings (reads redis_handler).
	 * @param string               $rColumns   Columns to select on the table path (code-controlled).
	 * @param array<string, mixed> $rFallback  Column => value to match when the uuid finds nothing (table path only).
	 * @return array<string, mixed>|null
	 */
	public static function findByUuid(array $rSettings, string $rUUID, string $rColumns, array $rFallback = []): ?array {
		if (AgentConnections::enabled()) {
			$rFound = AgentConnections::get($rUUID);
			if ($rFound === false && $rFallback !== [] && !$rSettings['redis_handler']) {
				$rFound = AgentConnections::find($rFallback);
			}
			if ($rFound !== null) {
				return $rFound ?: null;
			}
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			self::refuseUnchecked();
		}
		if ($rSettings['redis_handler']) {
			$rConnection = self::getConnection($rUUID);
			return is_array($rConnection) ? $rConnection : null;
		}
		$db = self::store();
		$db->query('SELECT ' . $rColumns . ' FROM `lines_live` WHERE `uuid` = ?;', $rUUID);
		if ($db->num_rows() > 0) {
			return $db->get_row();
		}
		if ($rFallback === []) {
			return null;
		}
		$db->query('SELECT ' . $rColumns . ' FROM `lines_live` WHERE ' . implode(' AND ', array_map(static fn($rColumn) => '`' . $rColumn . '` = ?', array_keys($rFallback))) . ';', ...array_values($rFallback));
		return $db->num_rows() > 0 ? $db->get_row() : null;
	}

	/**
	 * A long-running viewer's periodic check-in: refresh `hls_last_read` and
	 * read back what the store now says (null when the connection is gone).
	 * A connection that has ended (closed by the limiter, an admin or the
	 * sweep) is read back as it is, never opened again: the caller then stops.
	 * Opens and closes its own Redis / database connection, as the stream
	 * endpoints' loops do between check-ins.
	 *
	 * @param array<string, mixed> $rSettings Settings (reads redis_handler).
	 * @return array<string, mixed>|null The Redis record, or `pid` and `hls_end` on the table path.
	 */
	public static function heartbeat(array $rSettings, string $rUUID, int $rLastRead): ?array {
		if (AgentConnections::enabled()) {
			$rTouched = AgentConnections::touch($rUUID, $rLastRead);
			if ($rTouched !== null) {
				return $rTouched ?: null;
			}
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			return null;
		}
		$rConnection = null;
		if ($rSettings['redis_handler']) {
			RedisManager::ensureConnected();
			$rExisting = self::getConnection($rUUID);
			if ($rExisting) {
				$rConnection = self::ended($rExisting) ? $rExisting : self::updateConnection($rExisting, ['hls_last_read' => $rLastRead]);
			}
			RedisManager::closeInstance();
			return $rConnection ?: null;
		}
		DatabaseFactory::connectLazy();
		$db = self::store();
		$db->query('UPDATE `lines_live` SET `hls_last_read` = ? WHERE `uuid` = ?', $rLastRead, $rUUID);
		$db->query('SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rUUID);
		if ($db->num_rows() == 1) {
			$rConnection = $db->get_row();
		}
		DatabaseFactory::close();
		return $rConnection;
	}

	/**
	 * Create a live connection record for the current request, transparently
	 * targeting Redis or the `lines_live` table depending on redis_handler. The
	 * HLS and TS delivery arms share this; they differ only in the container and
	 * the pid recorded.
	 *
	 * @param array    $rSettings  Settings (reads redis_handler).
	 * @param array    $rCtx       Request-scoped fields: is_hmac, identifier,
	 *                             user_id, stream_id, server_id, proxy_id,
	 *                             user_agent, user_ip, date_start,
	 *                             geoip_country_code, isp, external_device,
	 *                             on_demand, uuid, time_offset, and the
	 *                             viewer's stream token (`token`, for
	 *                             admission by the node's agent).
	 * @param string   $rContainer Container: `hls` or the TS extension.
	 * @param int|null $rPid       Owning pid (NULL for HLS).
	 * @return mixed Truthy on success (Redis MULTI result or DB write result).
	 */
	public static function createLive(array $rSettings, array $rCtx, string $rContainer, ?int $rPid) {
		$rConn = [
			"stream_id" => $rCtx["stream_id"],
			"server_id" => $rCtx["server_id"],
			"proxy_id" => $rCtx["proxy_id"],
			"user_agent" => $rCtx["user_agent"],
			"user_ip" => $rCtx["user_ip"],
			"container" => $rContainer,
			"pid" => $rPid,
			"date_start" => $rCtx["date_start"],
			"geoip_country_code" => $rCtx["geoip_country_code"],
			"isp" => $rCtx["isp"],
			"external_device" => $rCtx["external_device"],
			"hls_end" => 0,
			"hls_last_read" => time() - $rCtx["time_offset"],
			"on_demand" => $rCtx["on_demand"],
			"uuid" => $rCtx["uuid"],
		];

		if (is_null($rCtx["is_hmac"])) {
			$rConn["user_id"] = $rCtx["user_id"];
			$rConn["identity"] = $rCtx["user_id"];
		} else {
			$rConn["hmac_id"] = $rCtx["is_hmac"];
			$rConn["hmac_identifier"] = $rCtx["identifier"];
			$rConn["identity"] = $rCtx["is_hmac"] . "_" . $rCtx["identifier"];
		}

		if (!$rSettings["redis_handler"] && $rContainer === 'hls' && !NodeRole::refusesConnects()) {
			// A re-auth after a close reuses the player's HLS uuid. Drop the closed row
			// (its activity was logged when it was closed) — the reaper deletes by uuid
			// and would otherwise take this new row down with the old one. Not on a
			// node in mode 2: it is refused every query, its record is its agent's,
			// and MAIN's row follows that one (ConnectionIngest).
			self::store()->query('DELETE FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 1;', $rConn["uuid"]);
		}

		$rOwner = is_null($rCtx["is_hmac"]) ? ["user_id" => $rConn["user_id"]] : ["hmac_id" => $rConn["hmac_id"], "hmac_identifier" => $rConn["hmac_identifier"]];
		return self::openRecord($rSettings, $rConn, $rOwner + [
			"stream_id" => $rConn["stream_id"], "server_id" => $rConn["server_id"], "proxy_id" => $rConn["proxy_id"], "user_agent" => $rConn["user_agent"],
			"user_ip" => $rConn["user_ip"], "container" => $rConn["container"], "pid" => $rConn["pid"], "uuid" => $rConn["uuid"], "date_start" => $rConn["date_start"],
			"geoip_country_code" => $rConn["geoip_country_code"], "isp" => $rConn["isp"], "external_device" => $rConn["external_device"], "hls_last_read" => $rConn["hls_last_read"],
		], $rCtx["token"] ?? null, (int) $rCtx["time_offset"]);
	}

	/**
	 * Find the current live connection for this request (Redis or lines_live).
	 * The HLS and TS arms differ in the columns they need and their filters,
	 * expressed here as explicit flags rather than hidden branches. The dynamic
	 * pieces are built from those booleans only — never from request input.
	 *
	 * @param array  $rSettings      Settings (reads redis_handler).
	 * @param array  $rCtx           Request-scoped fields (uuid, is_hmac,
	 *                               identifier, user_id, server_id, stream_id,
	 *                               adaptive).
	 * @param string $rContainer     Container: `hls` or the TS extension.
	 * @param bool   $rWithPid       Also select the `pid` column (TS).
	 * @param bool   $rOpenOnly      Restrict to open rows (`hls_end = 0`) (HLS).
	 * @param bool   $rAllowAdaptive Honour an adaptive token (HLS only).
	 * @return array|null The connection row, or null when none is open.
	 */
	public static function lookupLive(array $rSettings, array $rCtx, string $rContainer, bool $rWithPid, bool $rOpenOnly, bool $rAllowAdaptive): ?array {
		if (AgentConnections::enabled()) {
			$rFound = AgentConnections::get((string) $rCtx["uuid"]);
			if ($rFound !== null) {
				return $rFound !== false && self::liveMatches($rFound, $rCtx, $rContainer, $rOpenOnly, $rAllowAdaptive) ? $rFound : null;
			}
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			self::refuseUnchecked();
		}
		if ($rSettings["redis_handler"]) {
			$rConnection = self::getConnection($rCtx["uuid"]);
			// Same meaning as `hls_end = 0` on the table path: a connection that was
			// closed — kicked for the line's limit, or by an admin — is not resumed
			// by the player's next playlist request (which would quietly undo the
			// kick); the request is treated as a new connection and its token's
			// expiry and the line's limits apply again.
			if ($rOpenOnly && is_array($rConnection) && !empty($rConnection['hls_end'])) {
				return null;
			}
			return $rConnection;
		}

		$db = self::db();
		$rCols = $rWithPid ? "`activity_id`, `pid`, `user_ip`, `hls_end`" : "`activity_id`, `user_ip`";
		$rOpen = $rOpenOnly ? " AND `hls_end` = 0" : "";

		if ($rAllowAdaptive && !empty($rCtx["adaptive"])) {
			$db->query("SELECT $rCols FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `container` = ?" . $rOpen, $rCtx["uuid"], $rCtx["user_id"], $rContainer);
		} elseif (is_null($rCtx["is_hmac"])) {
			$db->query("SELECT $rCols FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?" . $rOpen, $rCtx["uuid"], $rCtx["user_id"], $rCtx["server_id"], $rContainer, $rCtx["stream_id"]);
		} else {
			$db->query("SELECT $rCols FROM `lines_live` WHERE `uuid` = ? AND `hmac_id` = ? AND `hmac_identifier` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?" . $rOpen, $rCtx["uuid"], $rCtx["is_hmac"], $rCtx["identifier"], $rCtx["server_id"], $rContainer, $rCtx["stream_id"]);
		}

		return $db->num_rows() > 0 ? $db->get_row() : null;
	}

	/**
	 * Does a record from the node's agent answer lookupLive() as the table
	 * path's query would: same owner, and (unless an adaptive token names only
	 * the line) same server, container and stream; open when asked.
	 *
	 * @param array<string, mixed> $rRecord
	 * @param array<string, mixed> $rCtx
	 */
	private static function liveMatches(array $rRecord, array $rCtx, string $rContainer, bool $rOpenOnly, bool $rAllowAdaptive): bool {
		if ($rOpenOnly && !empty($rRecord["hls_end"])) {
			return false;
		}
		if (($rRecord["container"] ?? null) != $rContainer) {
			return false;
		}
		if ($rAllowAdaptive && !empty($rCtx["adaptive"])) {
			return (string) ($rRecord["user_id"] ?? "") === (string) $rCtx["user_id"];
		}
		$rOwner = is_null($rCtx["is_hmac"])
			? (string) ($rRecord["user_id"] ?? "") === (string) $rCtx["user_id"]
			: (string) ($rRecord["hmac_id"] ?? "") === (string) $rCtx["is_hmac"] && (string) ($rRecord["hmac_identifier"] ?? "") === (string) $rCtx["identifier"];
		return $rOwner && (string) ($rRecord["server_id"] ?? "") === (string) $rCtx["server_id"] && (string) ($rRecord["stream_id"] ?? "") === (string) $rCtx["stream_id"];
	}

	/**
	 * Refresh an existing live connection (Redis or lines_live), applying the
	 * given column changes and re-opening the row (`hls_end = 0`). On the Redis
	 * path $rConnection is updated in place with the stored record.
	 *
	 * @param array $rSettings  Settings (reads redis_handler).
	 * @param array $rConnection Connection row (needs activity_id for the DB path).
	 * @param array $rChanges    Column => value pairs to write (code-controlled keys).
	 * @return bool True on a successful write.
	 */
	public static function updateLive(array $rSettings, array &$rConnection, array $rChanges): bool {
		// A record the node's agent holds (it has the Redis record's shape,
		// identity included): refresh it there, re-opened.
		if (AgentConnections::enabled() && isset($rConnection['identity'], $rConnection['uuid'])) {
			$rUpdated = array_merge($rConnection, $rChanges, ['hls_end' => 0]);
			if (AgentConnections::put((string) $rConnection['uuid'], $rUpdated)) {
				$rConnection = $rUpdated;
				return true;
			}
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			return false;
		}
		if ($rSettings["redis_handler"]) {
			$rUpdated = self::updateConnection($rConnection, $rChanges, "open");
			if ($rUpdated) {
				$rConnection = $rUpdated;
				return true;
			}
			return false;
		}

		$db = self::db();
		$rSet = [];
		$rParams = [];
		foreach ($rChanges as $rColumn => $rValue) {
			$rSet[] = "`" . $rColumn . "` = ?";
			$rParams[] = $rValue;
		}
		$rParams[] = $rConnection["activity_id"];

		return (bool) $db->query('UPDATE `lines_live` SET ' . implode(", ", $rSet) . ", `hls_end` = 0 WHERE `activity_id` = ?", ...$rParams);
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
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		// zRangeByScore returns false on a failed connection — degrade to empty.
		$rKeys = $rRedis->zRangeByScore((($rActive ? 'LINE#' : 'LINE_ALL#')) . $rUserID, '-inf', '+inf');
		if (is_array($rKeys) && count($rKeys) > 0) {
			return $rKeys;
		}
		return [];
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
		if (!$rRedis instanceof \Redis) {
			return [];
		}
		// sMembers/mGet return false on a failed connection — degrade to empty.
		$rKeys = $rRedis->sMembers('ENDED');
		if (!is_array($rKeys) || 0 >= count($rKeys)) {
			return [];
		}
		return self::records($rRedis, $rKeys) ?? [];
	}

	/** Keys a record read asks Redis for at once. */
	public const READ_CHUNK = 5000;

	/**
	 * The records of $rKeys (false for one gone), READ_CHUNK keys a command:
	 * one mGet of every viewer held Redis, for every client, while it built a
	 * reply of tens of MB. Null when a read fails.
	 *
	 * @param list<string> $rKeys
	 * @return list<array<string, mixed>|false>|null
	 */
	private static function records(\Redis $rRedis, array $rKeys): ?array {
		$rData = [];
		foreach (array_chunk($rKeys, self::READ_CHUNK) as $rChunk) {
			$rPart = $rRedis->mGet($rChunk);
			if (!is_array($rPart)) {
				return null;
			}
			foreach ($rPart as $rItem) {
				$rData[] = is_string($rItem) ? igbinary_unserialize($rItem) : false;
			}
		}
		return $rData;
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
		$rReturn = [];
		foreach ($rServers as $rProxyID => $rServerInfo) {
			if ($rServerInfo['server_type'] == 1 && in_array($rServerID, $rServerInfo['parent_id']) && ($rServerInfo['server_online'] || !$rOnline)) {
				$rReturn[$rProxyID] = $rServerInfo;
			}
		}
		return $rReturn;
	}

	/**
	 * End a daemon-served live-TS viewer — a `pid = 0` row (ADR 0003). The worker
	 * that admitted it returned at the X-Accel hand-off, so there is no process to
	 * kill: the xc_fanout daemon serving it has to drop the uuid. On this node that
	 * is one control call; for a viewer on another node it is a `drop_con` signal,
	 * which that node's signals daemon turns into the same call.
	 *
	 * @param array $rConnection Connection row (needs uuid and server_id).
	 */
	public static function dropDaemonViewer(array $rConnection): void {
		global $rSettings;
		$rUUID = (string) ($rConnection['uuid'] ?? '');
		if ($rUUID === '') {
			return;
		}
		$rServerID = intval($rConnection['server_id'] ?? 0);
		if ($rServerID <= 0 || $rServerID == SERVER_ID) {
			FanoutClient::dropConnection($rUUID);
			return;
		}
		$rSignal = ['type' => 'drop_con', 'uuid' => $rUUID];
		if (class_exists(ClusterRoute::class) && ClusterRoute::drop($rServerID, $rUUID)[0]) {
			return; // a command node: a signed conn.drop
		}
		if (!empty($rSettings['redis_handler'])) {
			self::redisSignal(0, $rServerID, 0, $rSignal);
		} else {
			SignalDispatcher::cache($rServerID, $rSignal, false, true, self::db());
		}
	}

	/**
	 * Remove a connection's Redis record and every set that names it.
	 *
	 * @param array<string, mixed> $rConnection The stored record (identity, stream, server, proxy, user).
	 */
	public static function removeRecord(\Redis $rRedisObj, array $rConnection): bool {
		$rUUID = $rConnection['uuid'];
		$rRedis = $rRedisObj->multi();
		$rRedis->zRem('LINE#' . $rConnection['identity'], $rUUID);
		$rRedis->zRem('LINE_ALL#' . $rConnection['identity'], $rUUID);
		$rRedis->zRem('STREAM#' . $rConnection['stream_id'], $rUUID);
		$rRedis->zRem('SERVER#' . $rConnection['server_id'], $rUUID);
		if (!empty($rConnection['user_id'])) {
			$rRedis->zRem('SERVER_LINES#' . $rConnection['server_id'], $rUUID);
		}
		if (!empty($rConnection['proxy_id'])) {
			$rRedis->zRem('PROXY#' . $rConnection['proxy_id'], $rUUID);
		}
		$rRedis->del($rUUID);
		$rRedis->zRem('CONNECTIONS', $rUUID);
		$rRedis->zRem('LIVE', $rUUID);
		$rRedis->sRem('ENDED', $rUUID);
		return (bool) $rRedis->exec();
	}

	/**
	 * An RTMP viewer's uuid, from nginx-rtmp's client id (which play_done
	 * closes it by). nginx numbers its clients from 1 in each process, so
	 * the id alone named two servers' viewers alike, and in Redis mode the
	 * second's record replaced the first's: the server is part of it.
	 */
	public static function rtmpUuid(int|string $rClientID, ?int $rServerID = null): string {
		// md5() only names the connection; it protects nothing.
		// nosemgrep: php.lang.security.weak-crypto.weak-crypto
		return md5(($rServerID ?? SERVER_ID) . '/' . $rClientID);
	}

	/**
	 * Has the connection ended (`hls_end` = 1)? For a connection a PHP worker
	 * served, the worker then ran its shutdown handler and PHP-FPM gave it to
	 * another request (pm = ondemand, max_requests 40000), or it exited and its
	 * pid went to another process: that pid must never be killed. A record
	 * without the field has not ended.
	 *
	 * @param array<string, mixed> $rConnection
	 */
	public static function ended(array $rConnection): bool {
		return (int) ($rConnection['hls_end'] ?? 0) === 1;
	}

	/**
	 * Close an active connection.
	 *
	 * Performs the full close cycle: kills the process (RTMP drop client,
	 * posix_kill, or Redis signal) unless the connection has already ended
	 * (self::ended: its worker or client is gone), removes from Redis sorted
	 * sets, cleans tmp files, and writes to the activity log.
	 *
	 * @param array|string $rActivityInfo Connection data or UUID/activity_id.
	 * @param bool         $rRemove       Remove connection from Redis/MySQL.
	 * @param bool         $rEnd          Mark HLS connection as ended.
	 * @return bool True on successful close, false otherwise.
	 */
	public static function closeConnection(array|string $rActivityInfo, bool $rRemove = true, bool $rEnd = true): bool {
		if (!empty($rActivityInfo)) {
			global $rSettings, $rServers;
			$db = self::db();
			if ($rSettings['redis_handler'] && !is_object(RedisManager::instance())) {
				RedisManager::ensureConnected();
			}
			$rRedisObj = RedisManager::instance();
			if (!$rRedisObj && $rSettings['redis_handler']) {
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
					$raw = $rRedisObj->get($rActivityInfo);
					$rActivityInfo = ($raw !== false) ? igbinary_unserialize($raw) : null;
				}
			}
			if (is_array($rActivityInfo)) {
				// Before the defaults below, which read a missing hls_end as ended.
				$rEnded = self::ended($rActivityInfo);
				$rActivityInfo += ['server_id' => 0, 'pid' => 0, 'activity_id' => null, 'stream_id' => 0, 'uuid' => '', 'hls_end' => 1];
				if (($rActivityInfo['container'] ?? '') == 'rtmp') {
					// An ended one's client is gone (play_done): nginx may have given its id to another.
					if (!$rEnded && $rActivityInfo['server_id'] == SERVER_ID) {
						shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rActivityInfo['pid']) . '" >/dev/null 2>/dev/null &');
					} elseif (!$rEnded) {
						if ($rSettings['redis_handler']) {
							self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
						} else {
							SignalDispatcher::kill(intval($rActivityInfo['server_id']), intval($rActivityInfo['pid']), true, $db);
						}
					}
				} else {
					if (($rActivityInfo['container'] ?? '') == 'hls') {
						// The server that serves the viewer keeps its marker
						// (CONS_TMP_PATH/<uuid>), which its segment.php checks for
						// every segment: without this a killed HLS viewer on another
						// server kept getting the segments of the playlist it held.
						$rServerID = intval($rActivityInfo['server_id']);
						if (!$rEnded && ($rRemove || $rEnd) && $rServerID > 0 && $rServerID != SERVER_ID) {
							SignalDispatcher::cache($rServerID, ['type' => 'delete_con', 'uuid' => (string) $rActivityInfo['uuid']], false, false, $db);
						}
						if (!$rRemove && $rEnd && $rActivityInfo['hls_end'] == 0) {
							if ($rSettings['redis_handler']) {
								self::updateConnection($rActivityInfo, [], 'close');
							} else {
								$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
							}
							@unlink(CONS_TMP_PATH . $rActivityInfo['stream_id'] . '/' . $rActivityInfo['uuid']);
						}
					} else {
						if (intval($rActivityInfo['pid']) === 0) {
							self::dropDaemonViewer($rActivityInfo);
						} elseif (!$rEnded && $rActivityInfo['server_id'] == SERVER_ID) {
							// An ended connection's worker ended with it and now serves someone else (self::ended).
							if ($rActivityInfo['pid'] != getmypid() && is_numeric($rActivityInfo['pid']) && 0 < $rActivityInfo['pid']) {
								posix_kill(intval($rActivityInfo['pid']), 9);
							}
						} elseif (!$rEnded) {
							if ($rSettings['redis_handler']) {
								self::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 0);
							} else {
								SignalDispatcher::kill(intval($rActivityInfo['server_id']), intval($rActivityInfo['pid']), false, $db);
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
						self::removeRecord($rRedisObj, $rActivityInfo);
					} else {
						$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
					}
				}
				if ($rRemove || ($rEnd && ($rActivityInfo['container'] ?? '') == 'hls')) {
					if ($rActivityInfo['server_id'] == SERVER_ID) {
						// This node's own connection: its agent's registry follows the
						// close just made in MAIN's store.
						if (AgentConnections::enabled()) {
							AgentConnections::closed((string) $rActivityInfo['uuid'], $rRemove);
						}
					} elseif (class_exists(ClusterRoute::class)) {
						// Another node's (MAIN): a node whose registry holds it hears of it.
						ClusterRoute::closeConnection(intval($rActivityInfo['server_id']), (string) $rActivityInfo['uuid'], $rRemove);
					}
				}
				// The end in MAIN's clock, as date_start and hls_last_read are. A
				// record never carries date_end: a close for silence sets it to
				// when the viewer was last heard, not when it was noticed.
				$rEndAt = isset($rActivityInfo['date_end']) ? intval($rActivityInfo['date_end']) : time() - intval($rServers[SERVER_ID]['time_offset'] ?? 0);
				// A Redis record has no divergence: the sweep keeps it in lines_divergence.
				if ($rSettings['redis_handler'] && !isset($rActivityInfo['divergence']) && !empty($rSettings['save_closed_connection']) && !empty($rServers[SERVER_ID]['is_main'])) {
					try {
						$db->query('SELECT `divergence` FROM `lines_divergence` WHERE `uuid` = ?;', $rActivityInfo['uuid']);
						$rActivityInfo['divergence'] = (int) round((float) ($db->get_row()['divergence'] ?? 0));
					} catch (\Throwable) {
						// logged as 0, as before
					}
				}
				self::writeOfflineActivity($rSettings, $rActivityInfo['server_id'] ?? 0, intval($rActivityInfo['proxy_id'] ?? 0), $rActivityInfo['user_id'] ?? 0, $rActivityInfo['stream_id'] ?? 0, $rActivityInfo['date_start'] ?? 0, $rActivityInfo['user_agent'] ?? '', $rActivityInfo['user_ip'] ?? '', $rActivityInfo['container'] ?? '', $rActivityInfo['geoip_country_code'] ?? '', strval($rActivityInfo['isp'] ?? ''), $rActivityInfo['external_device'] ?? '', $rActivityInfo['divergence'] ?? 0, $rActivityInfo['hmac_id'] ?? null, $rActivityInfo['hmac_identifier'] ?? '', $rEndAt);
				return true;
			}
			return false;
		}
		return false;
	}

	/** @var list<string>|null The sweep's activity lines, held until their records are gone; null: written at once. */
	private static ?array $rHeldActivity = null;

	/**
	 * Hold activity lines back (the sweep), until releaseActivity(): written
	 * at once, a sweep killed before it removed the records it closed, or
	 * that could not remove them, logged them again when it next closed them.
	 */
	public static function holdActivity(bool $rHold = true): void {
		self::$rHeldActivity = $rHold ? (self::$rHeldActivity ?? []) : null;
	}

	/** Write the held lines, their records removed; or drop them, the records kept (they are closed, and logged, again). */
	public static function releaseActivity(bool $rWrite = true): void {
		if ($rWrite && !empty(self::$rHeldActivity)) {
			file_put_contents(LOGS_TMP_PATH . 'activity', implode('', self::$rHeldActivity), FILE_APPEND | LOCK_EX);
		}
		if (self::$rHeldActivity !== null) {
			self::$rHeldActivity = [];
		}
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
	 * @param int|null    $rEnd            When it ended (MAIN's clock); null: now.
	 * @param bool        $rHold           False: its record is gone already, so the line is written at once even in a sweep.
	 */
	public static function writeOfflineActivity(array $rSettings, int $rServerID, int $rProxyID, int $rUserID, int $rStreamID, int $rStart, string $rUserAgent, string $rIP, string $rExtension, string $rGeoIP, string $rISP, string $rExternalDevice = '', int $rDivergence = 0, ?int $rIsHMAC = null, string $rIdentifier = '', ?int $rEnd = null, bool $rHold = true): void {
		if ($rSettings['save_closed_connection'] != 0) {
			if ($rServerID && ($rUserID || $rIsHMAC) && $rStreamID) { // a line's viewer, or an HMAC identity's
				$rActivityInfo = ['user_id' => intval($rUserID), 'stream_id' => intval($rStreamID), 'server_id' => intval($rServerID), 'proxy_id' => intval($rProxyID), 'date_start' => intval($rStart), 'user_agent' => $rUserAgent, 'user_ip' => htmlentities($rIP), 'date_end' => $rEnd ?? time(), 'container' => $rExtension, 'geoip_country_code' => $rGeoIP, 'isp' => $rISP, 'external_device' => htmlentities($rExternalDevice), 'divergence' => intval($rDivergence), 'hmac_id' => $rIsHMAC, 'hmac_identifier' => $rIdentifier];
				$rLine = base64_encode(json_encode($rActivityInfo)) . "\n";
				if ($rHold && self::$rHeldActivity !== null) {
					self::$rHeldActivity[] = $rLine;
				} else {
					file_put_contents(LOGS_TMP_PATH . 'activity', $rLine, FILE_APPEND | LOCK_EX);
				}
			}
		} else {
			return;
		}
	}

	/**
	 * Count a line's live connections — the active_cons player_api reports.
	 *
	 * In Redis mode counts the line's LINE# set (getLineConnections() degrades a
	 * failed Redis call to none); in MySQL mode its open lines_live rows.
	 *
	 * @param int $rUserID Line ID.
	 * @return int Number of live connections.
	 */
	public static function countLineConnections(int $rUserID): int {
		if ($rUserID <= 0) {
			return 0;
		}
		if (SettingsManager::get('redis_handler')) {
			return count(self::getLineConnections($rUserID, true));
		}
		$db = self::db();
		$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0;', $rUserID);
		return (int) ($db->get_row()['count'] ?? 0);
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
		$db = self::db();

		if (SettingsManager::get('redis_handler')) {
			$rCount = 0;

			if ($rProxy) {
				$rParentIDs = ServerRepository::getAll()[$rServerID]['parent_id'];

				foreach ($rParentIDs as $rParentID) {
					foreach (self::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
						if ($rConnection['proxy_id'] == $rServerID) {
							$rCount++;
						}
					}
				}
			} else {
				list($rCount) = self::getRedisConnections(null, $rServerID, null, true, true, false);
			}

			return $rCount;
		}
		if ($rProxy) {
				$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `proxy_id` = ? AND `hls_end` = 0;', $rServerID);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
		}
		return $db->get_row()['count'];
	}
}
