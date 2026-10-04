<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * A node's connections as MAIN's store holds them (cluster plan, Phase 6),
 * and the line identity a record is kept under. MAIN reads them for its
 * digest check and a snapshot's removals (ConnectionDigest,
 * ConnectionSnapshot); the node reads them into its agent before the
 * CONNECTIONS switch (cluster:seed-connections).
 *
 * Lives in Core: the seed runs on LBs, where Domain\Cluster does not.
 */
final class StoredConnections {
	/**
	 * The line identity a record is kept under (Redis `LINE#<identity>`, an
	 * admission's reservation): the line's id, or `<hmac_id>_<hmac_identifier>`
	 * for an HMAC identity, the identifier cut to 255 bytes as `lines_live`
	 * keeps it (`cluster_reservations.identity` holds the 267 characters). It
	 * is recomputed from the record's owner, as the digest reads it
	 * (ConnectionDigest::owner()): both ids as integers.
	 *
	 * @param array<string, mixed> $rRecord
	 */
	public static function identity(array $rRecord): string {
		if (!empty($rRecord['user_id'])) {
			return (string) (int) $rRecord['user_id'];
		}
		return (int) ($rRecord['hmac_id'] ?? 0) . '_' . substr((string) ($rRecord['hmac_identifier'] ?? ''), 0, 255);
	}

	/**
	 * The node's connections in MAIN's store, as registry records, in the
	 * store's order. A record carries the keys in $rKeys (by default the whole
	 * registry record, AgentConnections::RECORD_KEYS); they are column names,
	 * taken from code, never from a request.
	 *
	 * - Redis: the records `SERVER#<sid>` names, with the identity they were
	 *   stored under.
	 * - `lines_live`: the node's rows with a uuid, their identity computed
	 *   (identity()).
	 *
	 * With $rOpenOnly only the open ones (`hls_end` not set), as the digest
	 * counts them and a snapshot removes them; without it the ended ones too,
	 * as the registry holds them (the seed). In Redis a close takes the uuid
	 * out of `SERVER#`, but a record upserted as ended stays listed there.
	 *
	 * @param list<string> $rKeys
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException The store cannot be read.
	 */
	public static function ofServer(int $rServerID, bool $rOpenOnly, array $rKeys = AgentConnections::RECORD_KEYS): array {
		$rOut = [];
		$rKeep = array_flip($rKeys);
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			$rUUIDs = $rRedis instanceof \Redis ? $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf') : false;
			if (!is_array($rUUIDs)) {
				throw new \RuntimeException('redis unavailable');
			}
			foreach (array_chunk($rUUIDs, 1000) as $rChunk) {
				$rData = $rRedis->mGet($rChunk);
				foreach (is_array($rData) ? $rData : [] as $rRaw) {
					$rRecord = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
					if (is_array($rRecord) && isset($rRecord['uuid']) && (int) ($rRecord['server_id'] ?? 0) === $rServerID && !($rOpenOnly && !empty($rRecord['hls_end']))) {
						$rOut[] = array_intersect_key($rRecord, $rKeep);
					}
				}
			}
			return $rOut;
		}
		$rDb = DatabaseFactory::get();
		if ($rDb === null) {
			throw new \RuntimeException('no database');
		}
		$rIdentity = isset($rKeep['identity']);
		$rColumns = array_unique(array_merge(['uuid'], array_diff($rKeys, ['identity', 'on_demand']), $rIdentity ? ['user_id', 'hmac_id', 'hmac_identifier'] : []));
		$rDb->query('SELECT `' . implode('`, `', $rColumns) . '` FROM `lines_live` WHERE `server_id` = ? AND `uuid` IS NOT NULL' . ($rOpenOnly ? ' AND `hls_end` = 0' : '') . ';', $rServerID);
		foreach ($rDb->get_rows() as $rRow) {
			if ((string) $rRow['uuid'] === '') {
				continue;
			}
			if ($rIdentity) {
				$rRow['identity'] = self::identity($rRow);
			}
			$rOut[] = array_intersect_key($rRow, $rKeep);
		}
		return $rOut;
	}
}
