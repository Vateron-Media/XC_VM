<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\DivergenceSink;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * MAIN's side of a node's connection registry (cluster plan, Phase 6): the
 * node's agent mirrors every viewer it records as P0 events, and they land in
 * MAIN's store (Redis or `lines_live`, as redis_handler says) exactly as the
 * node's PHP used to write them, so the reaper, the limits and the admin read
 * what they always read.
 *
 * ```text
 * conn.upsert {record}   create, or update and re-open (hls_end 0) / end (hls_end 1)
 * conn.remove {uuid}     drop it from the store
 * conn.close {uuid}      the viewer left the node's fanout: its activity row,
 *                        then drop it from the store
 * conn.divergence {rows} (P1) each viewer's measured rate, turned into its
 *                        divergence in `lines_divergence`
 * conn.touch {uuid, hls_last_read} (P2) the viewer's last playlist request:
 *                        the cluster bus, or the store's hls_last_read
 * ```
 *
 * A node writes only its own connections: `server_id` is always the sender,
 * the line identity is recomputed from the record's owner, and a uuid another
 * node holds is refused.
 *
 * In an events batch a store that fails a read or a write throws rather
 * than answer false: false is an event refused (dropped and counted, and the
 * cursor moves on), while a failed statement may have taken the batch's
 * whole transaction with it (a deadlock). The batch is not applied (503 DB)
 * and the node sends it again. conn.divergence's read of the store is the
 * one exception (below). A snapshot (ConnectionSnapshot) calls upsert() and
 * remove() without $rFailBatch: a record MAIN cannot write is dropped there,
 * and the digest check asks for another snapshot.
 */
final class ConnectionIngest {
	use DatabaseAware;

	/** The record keys a node may set. */
	public const KEYS = ['user_id', 'hmac_id', 'hmac_identifier', 'stream_id', 'proxy_id', 'user_agent', 'user_ip', 'container', 'pid', 'uuid', 'date_start', 'geoip_country_code', 'isp', 'external_device', 'hls_last_read', 'hls_end', 'on_demand'];

	/** The `lines_live` columns among them. */
	private const COLUMNS = ['user_id', 'hmac_id', 'hmac_identifier', 'stream_id', 'server_id', 'proxy_id', 'user_agent', 'user_ip', 'container', 'pid', 'uuid', 'date_start', 'geoip_country_code', 'isp', 'external_device', 'hls_last_read', 'hls_end'];

	/**
	 * Apply a node's conn.upsert. A connection that opened on the node is no
	 * longer reserved (ConnectionAdmission): it is counted as open from here.
	 * An HLS viewer is recorded under its playlist key; its record names the
	 * token's uuid MAIN reserved at mint as `adm_uuid`, released too.
	 *
	 * @param array<string, mixed> $rRecord
	 * @param bool $rFailBatch A store that fails throws (an events batch), rather than answer false.
	 */
	public static function upsert(int $rServerID, array $rRecord, bool $rFailBatch = false): bool {
		$rOk = self::write($rServerID, $rRecord, $rFailBatch);
		if ($rOk) {
			$rUUID = (string) ($rRecord['uuid'] ?? '');
			$rIdentity = StoredConnections::identity($rRecord);
			$rRedisMode = (bool) SettingsManager::get('redis_handler');
			ConnectionAdmission::release($rRedisMode, $rIdentity, $rUUID);
			$rReserved = $rRecord['adm_uuid'] ?? null;
			if (is_string($rReserved) && $rReserved !== $rUUID && preg_match(AgentConnections::CONN_UUID, $rReserved)) {
				ConnectionAdmission::release($rRedisMode, $rIdentity, $rReserved);
			}
		}
		return $rOk;
	}

	/** @param array<string, mixed> $rRecord */
	private static function write(int $rServerID, array $rRecord, bool $rFailBatch): bool {
		$rRecord = array_filter(array_intersect_key($rRecord, array_flip(self::KEYS)), static fn($rValue) => is_scalar($rValue) || $rValue === null);
		$rUUID = (string) ($rRecord['uuid'] ?? '');
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID) || (empty($rRecord['user_id']) && empty($rRecord['hmac_id']))) {
			return false;
		}
		$rRecord += ['user_id' => null, 'proxy_id' => null]; // the store reads both
		$rRecord['server_id'] = $rServerID;
		$rRecord['hls_end'] = empty($rRecord['hls_end']) ? 0 : 1;
		$rRecord['identity'] = StoredConnections::identity($rRecord);

		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				throw new \RuntimeException('redis unavailable'); // the batch is not applied; the node resends it
			}
			$rExisting = ConnectionTracker::getConnection($rUUID);
			if (is_array($rExisting)) {
				if ((int) ($rExisting['server_id'] ?? 0) !== $rServerID) {
					return false; // another node's connection
				}
				$rWritten = ConnectionTracker::updateConnection($rExisting, $rRecord, $rRecord['hls_end'] ? 'close' : 'open') !== null;
			} else {
				$rWritten = (bool) ConnectionTracker::createConnection($rRecord);
			}
			return $rWritten || self::failed($rFailBatch); // its EXEC failed
		}

		$rDb = self::db();
		if (!$rDb->query('SELECT `activity_id`, `server_id` FROM `lines_live` WHERE `uuid` = ?;', $rUUID)) {
			return self::failed($rFailBatch); // unread, it would be inserted a second time
		}
		$rRow = $rDb->num_rows() > 0 ? $rDb->get_row() : null;
		$rColumns = array_intersect_key($rRecord, array_flip(self::COLUMNS));
		if ($rRow !== null) {
			if ((int) $rRow['server_id'] !== $rServerID) {
				return false;
			}
			unset($rColumns['uuid']);
			$rWritten = $rDb->query('UPDATE `lines_live` SET ' . implode(', ', array_map(static fn($rColumn) => '`' . $rColumn . '` = ?', array_keys($rColumns))) . ' WHERE `activity_id` = ?;', ...array_values($rColumns), ...[(int) $rRow['activity_id']]);
		} else {
			$rWritten = $rDb->query('INSERT INTO `lines_live` (`' . implode('`,`', array_keys($rColumns)) . '`) VALUES(' . implode(',', array_fill(0, count($rColumns), '?')) . ');', ...array_values($rColumns));
		}
		return $rWritten || self::failed($rFailBatch);
	}

	/** @param bool $rFailBatch A store that fails throws (an events batch), rather than answer false. */
	public static function remove(int $rServerID, string $rUUID, bool $rFailBatch = false): bool {
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID)) {
			return false;
		}
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				throw new \RuntimeException('redis unavailable');
			}
			$rExisting = ConnectionTracker::getConnection($rUUID);
			if (!is_array($rExisting)) {
				return true; // already gone
			}
			if ((int) ($rExisting['server_id'] ?? 0) !== $rServerID) {
				return false;
			}
			return ConnectionTracker::removeRecord($rRedis, $rExisting) || self::failed($rFailBatch); // its EXEC failed
		}
		return self::db()->query('DELETE FROM `lines_live` WHERE `uuid` = ? AND `server_id` = ?;', $rUUID, $rServerID) || self::failed($rFailBatch);
	}

	/**
	 * A store that failed: in an events batch it fails the batch, elsewhere
	 * (a snapshot's record) the record is not applied.
	 *
	 * @return false
	 */
	private static function failed(bool $rFailBatch): bool {
		if ($rFailBatch) {
			throw new \RuntimeException('store unavailable');
		}
		return false;
	}

	/**
	 * A daemon-served viewer the node's fanout reports gone (conn.close, the
	 * fanout's conn_close): closed as fanout_sync's reconcile closes it, with
	 * its activity row, but without the kill and the conn.close command back
	 * that ConnectionTracker::closeConnection sends: the viewer is already
	 * gone and the node's registry already dropped it.
	 */
	public static function close(int $rServerID, string $rUUID): bool {
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID)) {
			return false;
		}
		if (SettingsManager::get('redis_handler')) {
			$rRow = ConnectionTracker::getConnection($rUUID);
		} else {
			// Unread, the viewer would count as already closed.
			if (!self::db()->query('SELECT * FROM `lines_live` WHERE `uuid` = ?;', $rUUID)) {
				throw new \RuntimeException('store unavailable');
			}
			$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		}
		if (!is_array($rRow)) {
			return true; // already closed (fanout_sync, a kick)
		}
		if ((int) ($rRow['server_id'] ?? 0) !== $rServerID) {
			return false;
		}
		ConnectionTracker::writeOfflineActivity(
			SettingsManager::getAll() + ['save_closed_connection' => 0],
			$rServerID,
			(int) ($rRow['proxy_id'] ?? 0),
			(int) ($rRow['user_id'] ?? 0),
			(int) ($rRow['stream_id'] ?? 0),
			(int) ($rRow['date_start'] ?? 0),
			(string) ($rRow['user_agent'] ?? ''),
			(string) ($rRow['user_ip'] ?? ''),
			(string) ($rRow['container'] ?? ''),
			(string) ($rRow['geoip_country_code'] ?? ''),
			(string) ($rRow['isp'] ?? ''),
			(string) ($rRow['external_device'] ?? ''),
			(int) ($rRow['divergence'] ?? 0),
			isset($rRow['hmac_id']) ? (int) $rRow['hmac_id'] : null,
			(string) ($rRow['hmac_identifier'] ?? '')
		);
		return self::remove($rServerID, $rUUID, true);
	}

	/**
	 * A node's conn.divergence (P1): each viewer's measured rate (KiB/s)
	 * becomes its divergence in `lines_divergence`, against the stream's
	 * bitrate on that node, with the formula the node's cron used
	 * (DivergenceSink). With `lines_live` as the store, the row's `divergence`
	 * is set too, as the cron did. Only connections MAIN's store holds for the
	 * node are written, one statement per table for the whole event.
	 *
	 * A store that cannot be read drops the event rather than failing the
	 * batch: the next report comes within a minute, and the lane's logs are
	 * not held up for it. Any other failed statement fails the batch, as
	 * every statement of it does: the node's bitrates (unread, every viewer
	 * would be written as 0 % divergent) and both writes.
	 *
	 * @param array<string, mixed> $rData {rows: [{uuid, rate}, …]}
	 */
	public static function divergence(int $rServerID, array $rData): bool {
		$rRows = $rData['rows'] ?? null;
		if (!is_array($rRows) || $rRows === [] || !array_is_list($rRows) || count($rRows) > DivergenceSink::CHUNK) {
			return false;
		}
		$rRates = [];
		foreach ($rRows as $rRow) {
			$rUUID = is_array($rRow) ? ($rRow['uuid'] ?? null) : null;
			$rRate = is_array($rRow) ? ($rRow['rate'] ?? null) : null;
			if (is_string($rUUID) && preg_match(DivergenceSink::UUID, $rUUID) && is_int($rRate) && $rRate >= 0) {
				$rRates[$rUUID] = $rRate;
			}
		}
		$rOwn = $rRates === [] ? null : self::owned($rServerID, array_map('strval', array_keys($rRates)));
		if ($rOwn === null || $rOwn === []) {
			return false;
		}
		$rDb = self::db();
		$rStreamIDs = array_values(array_unique(array_column($rOwn, 0)));
		$rExpected = [];
		if (!$rDb->query('SELECT `stream_id`, `bitrate` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', array_fill(0, count($rStreamIDs), '?')) . ');', $rServerID, ...$rStreamIDs)) {
			throw new \RuntimeException('store unavailable'); // unread, every viewer would read as 0 % divergent
		}
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rExpected[(int) $rRow['stream_id']] = DivergenceSink::expected((int) $rRow['bitrate']);
		}
		$rParams = $rLive = [];
		foreach ($rOwn as $rUUID => [$rStreamID, $rActivityID]) {
			$rDivergence = DivergenceSink::of($rRates[$rUUID], $rExpected[$rStreamID] ?? 0);
			array_push($rParams, (string) $rUUID, $rDivergence);
			if ($rActivityID !== null) {
				$rLive[$rActivityID] = $rDivergence;
			}
		}
		if (!$rDb->query('REPLACE INTO `lines_divergence` (`uuid`, `divergence`) VALUES ' . implode(',', array_fill(0, count($rOwn), '(?, ?)')) . ';', ...$rParams)) {
			throw new \RuntimeException('store unavailable');
		}
		if ($rLive !== []) {
			$rCase = [];
			foreach ($rLive as $rActivityID => $rDivergence) {
				array_push($rCase, $rActivityID, $rDivergence);
			}
			if (!$rDb->query('UPDATE `lines_live` SET `divergence` = CASE `activity_id`' . str_repeat(' WHEN ? THEN ?', count($rLive)) . ' ELSE `divergence` END WHERE `server_id` = ? AND `activity_id` IN (' . implode(',', array_fill(0, count($rLive), '?')) . ');', ...[...$rCase, $rServerID, ...array_keys($rLive)])) {
				throw new \RuntimeException('store unavailable');
			}
		}
		return true;
	}

	/**
	 * A node's conn.touch events (P2), folded to the latest per viewer by the
	 * event's time: when each viewer last asked for its playlist.
	 *
	 * For a node whose agent ends its own idle HLS viewers ($rReaps: the
	 * `hls_reaper` feature), nothing on MAIN decides by that time any more
	 * (HlsReaping), so it goes to the cluster bus only (ClusterBus::touch),
	 * and MAIN's store is spared a write per viewer. Only the viewers the
	 * store holds for the node get there, read once for the batch, so a node
	 * can neither fill the bus with made-up uuids nor touch another node's.
	 * Without the bus, when it is too full, or for a node that does not reap
	 * (MAIN's 30 s rule reads it), it goes into the store as the P0 upsert put
	 * it there: the node's own connections only, never re-opening, creating
	 * or moving one, and never back to an earlier read.
	 *
	 * A store that cannot be read or written throws: the batch is not
	 * applied (503 DB), and the node sends its newer values again.
	 *
	 * @param array<string, array{0: int, 1: int}> $rTouches uuid => [t (ms), hls_last_read]
	 */
	public static function touch(int $rServerID, bool $rReaps, array $rTouches): void {
		if ($rTouches === []) {
			return;
		}
		if ($rReaps && ClusterBus::client() !== null) {
			$rOwn = self::owned($rServerID, array_map('strval', array_keys($rTouches)));
			if ($rOwn === null) {
				throw new \RuntimeException('store unavailable');
			}
			if (ClusterBus::touch($rServerID, array_intersect_key($rTouches, $rOwn))) {
				return;
			}
		}
		$rReads = [];
		foreach ($rTouches as $rUUID => [, $rRead]) {
			$rReads[(string) $rUUID] = $rRead;
		}
		if (SettingsManager::get('redis_handler')) {
			self::touchRedis($rServerID, $rReads);
			return;
		}
		foreach (array_chunk($rReads, 1000, true) as $rChunk) {
			$rCase = [];
			foreach ($rChunk as $rUUID => $rRead) {
				array_push($rCase, (string) $rUUID, $rRead);
			}
			$rWhen = 'CASE `uuid`' . str_repeat(' WHEN ? THEN ?', count($rChunk)) . ' ELSE `hls_last_read` END';
			$rIn = implode(',', array_fill(0, count($rChunk), '?'));
			if (!self::db()->query('UPDATE `lines_live` SET `hls_last_read` = ' . $rWhen . ' WHERE `server_id` = ? AND `uuid` IN (' . $rIn . ') AND (`hls_last_read` IS NULL OR `hls_last_read` < ' . $rWhen . ');', ...[...$rCase, $rServerID, ...array_map('strval', array_keys($rChunk)), ...$rCase])) {
				throw new \RuntimeException('store unavailable');
			}
		}
	}

	/**
	 * touch() on Redis: each of the node's records gets the later read. A
	 * record written meanwhile (an upsert, a close) is left as that write
	 * made it (WATCH), so a touch never undoes one.
	 *
	 * @param array<string, int> $rReads uuid => hls_last_read
	 */
	private static function touchRedis(int $rServerID, array $rReads): void {
		$rRedis = RedisManager::instance();
		if (!$rRedis instanceof \Redis) {
			throw new \RuntimeException('redis unavailable'); // the batch is not applied; the node resends newer values
		}
		foreach ($rReads as $rUUID => $rRead) {
			$rUUID = (string) $rUUID;
			// An upsert or a close landing between this read and the write
			// fails the EXEC, and its record stands.
			$rRedis->watch($rUUID);
			$rRaw = $rRedis->get($rUUID);
			$rRecord = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
			if (!is_array($rRecord) || (int) ($rRecord['server_id'] ?? 0) !== $rServerID || (int) ($rRecord['hls_last_read'] ?? 0) >= $rRead) {
				$rRedis->unwatch();
				continue;
			}
			$rRecord['hls_last_read'] = $rRead;
			$rRedis->multi()->set($rUUID, igbinary_serialize($rRecord))->exec();
		}
	}

	/**
	 * The node's own connections among these uuids, as MAIN's store holds
	 * them: uuid => [stream_id, activity_id], the activity id null in Redis.
	 * Null when the store cannot be read.
	 *
	 * @param list<string> $rUUIDs
	 * @return array<string, array{0: int, 1: int|null}>|null
	 */
	private static function owned(int $rServerID, array $rUUIDs): ?array {
		$rOut = [];
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			try {
				$rData = $rRedis instanceof \Redis ? $rRedis->mGet($rUUIDs) : null;
			} catch (\Throwable) {
				$rData = null;
			}
			if (!is_array($rData)) {
				return null;
			}
			foreach (array_values($rData) as $i => $rRaw) {
				$rRecord = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
				if (is_array($rRecord) && (int) ($rRecord['server_id'] ?? 0) === $rServerID) {
					$rOut[$rUUIDs[$i]] = [(int) ($rRecord['stream_id'] ?? 0), null];
				}
			}
			return $rOut;
		}
		$rDb = self::db();
		if (!$rDb->query('SELECT `uuid`, `activity_id`, `stream_id` FROM `lines_live` WHERE `server_id` = ? AND `uuid` IN (' . implode(',', array_fill(0, count($rUUIDs), '?')) . ');', $rServerID, ...$rUUIDs)) {
			return null;
		}
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rOut[(string) $rRow['uuid']] = [(int) $rRow['stream_id'], (int) $rRow['activity_id']];
		}
		return $rOut;
	}

	/**
	 * The orphan purge (plan, "Liveness"): every connection MAIN's store holds
	 * for a node silent past cluster_orphan_conn_ttl_sec is dropped, so its
	 * viewers stop counting toward their lines' limits. Store only: no kill or
	 * command goes to the node, whose own registry still holds them; if it
	 * comes back, its digest disagrees and a snapshot puts them back.
	 *
	 * @return int How many were dropped.
	 */
	public static function purgeNode(int $rServerID): int {
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				return 0;
			}
			$rUUIDs = $rRedis->zRange('SERVER#' . $rServerID, 0, -1);
			$rCount = 0;
			foreach (is_array($rUUIDs) ? $rUUIDs : [] as $rUUID) {
				$rConnection = ConnectionTracker::getConnection((string) $rUUID);
				if (is_array($rConnection) && (int) ($rConnection['server_id'] ?? 0) === $rServerID && ConnectionTracker::removeRecord($rRedis, $rConnection)) {
					$rCount++;
				} elseif (!is_array($rConnection)) {
					$rRedis->zRem('SERVER#' . $rServerID, $rUUID); // a dangling index entry
				}
			}
			return $rCount;
		}
		$rDb = self::db();
		$rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
		$rCount = (int) ($rDb->get_row()['n'] ?? 0);
		if ($rCount > 0) {
			$rDb->query('DELETE FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
		}
		return $rCount;
	}
}
