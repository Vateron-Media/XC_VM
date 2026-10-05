<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What a node's heartbeat reports of its own audit (`audit`), kept with the
 * node in `cluster_nodes.audit` and shown on the Cluster Nodes page: the
 * settings reads outside the replica's allowlist (Core\Cluster\SettingsAudit;
 * plan, section 9, R1 `settings`) and the connects to MAIN's MySQL and Redis
 * (Core\Cluster\ConnectAudit; plan, section 10, step 1), each over the
 * node's last seven days.
 *
 * ```text
 * audit            {"settings_misses": {…}, "sql_connects": n, "redis_connects": n,
 *                  "sites": {…}, "connects_since": t}, at most MAX_BYTES in its
 *                  shortest encoding
 * settings_misses  key: [a-z0-9_]{1,64} or "*" (the rest), count: an integer >= 1;
 *                  at most SettingsAudit::MAX_KEYS keys and "*"
 * sql_connects,    integers >= 0: connect attempts, refused ones included; kept
 * redis_connects,  only together, and only with settings_misses
 * sites            "sql|redis path:line" (printable ASCII, at most
 *                  ConnectAudit::MAX_SITE_LEN bytes) or "*": an integer >= 1;
 *                  at most ConnectAudit::MAX_SITES sites and "*"
 * connects_since   optional, unix seconds >= 1: when the node's connect audit began
 * ```
 *
 * A heartbeat without `audit` (today's agent), or with one whose
 * `settings_misses` is not an object, changes nothing. A new report is
 * written only when it differs from the stored one: the row is read for
 * every request already, so an unchanged report costs no query, on the
 * cluster bus or not.
 */
final class NodeAudit {
	use DatabaseAware;

	/**
	 * Largest `audit` taken, measured in its shortest JSON encoding (slashes
	 * and non-ASCII unescaped). The agent sends an audit.json of at most as
	 * many bytes, re-encoded by Go, which escapes no slash: measured this way
	 * whatever it sends fits, `sites`' "path:line" strings included.
	 */
	public const MAX_BYTES = 16384;

	/**
	 * The heartbeat's `audit` as MAIN keeps it, or null when it is not one
	 * (the stored report stays). Entries that are not a settings name (or a
	 * site) and a count are dropped; past SettingsAudit::MAX_KEYS names (or
	 * ConnectAudit::MAX_SITES sites), the least count under "*". The connect
	 * counters are kept only when all three are well formed, and
	 * `streams_local` (does the node read its streams on itself:
	 * SettingsAudit::publish) only as the boolean it is.
	 *
	 * @return array{settings_misses: array<string, int>, sql_connects?: int, redis_connects?: int, sites?: array<string, int>, connects_since?: int, streams_local?: bool}|null
	 */
	public static function normalise(mixed $rAudit): ?array {
		if (!is_array($rAudit) || !is_array($rAudit['settings_misses'] ?? null) || strlen((string) json_encode($rAudit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) > self::MAX_BYTES) {
			return null;
		}
		$rMisses = [];
		foreach ($rAudit['settings_misses'] as $rKey => $rCount) {
			$rKey = (string) $rKey;
			if (is_int($rCount) && $rCount > 0 && ($rKey === SettingsAudit::OTHER || SettingsAudit::name($rKey) === $rKey)) {
				$rMisses[$rKey] = $rCount;
			}
		}
		return ['settings_misses' => SettingsAudit::top($rMisses)] + self::connects($rAudit)
			+ (is_bool($rAudit['streams_local'] ?? null) ? ['streams_local' => $rAudit['streams_local']] : []);
	}

	/**
	 * The stored form: `{"settings_misses": {…}}`, and the connect members
	 * when reported; the maps objects even when empty.
	 */
	public static function encode(array $rAudit): string {
		$rDoc = ['settings_misses' => (object) $rAudit['settings_misses']];
		if (isset($rAudit['sql_connects'], $rAudit['redis_connects'], $rAudit['sites'])) {
			$rDoc += ['sql_connects' => $rAudit['sql_connects'], 'redis_connects' => $rAudit['redis_connects'], 'sites' => (object) $rAudit['sites']];
			if (isset($rAudit['connects_since'])) {
				$rDoc['connects_since'] = $rAudit['connects_since'];
			}
		}
		if (isset($rAudit['streams_local'])) {
			$rDoc['streams_local'] = (bool) $rAudit['streams_local'];
		}
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Does the node read its streams on itself (its replica owns their
	 * definitions and its own store of their state is whole), by its last
	 * report? Null when the report does not say: none yet, or a node whose
	 * release predates the member. The move to mode 2 asks it
	 * (ClusterAdmin::modeGate).
	 *
	 * @param array<string, mixed>|null $rReport one of reports()
	 */
	public static function streamsLocal(?array $rReport): ?bool {
		return is_bool($rReport['streams_local'] ?? null) ? $rReport['streams_local'] : null;
	}

	/**
	 * Drop what a node said of its streams: it said it in the mode it has just
	 * left (ClusterAdmin::act()), and says it again in its next report. A
	 * report it has not rewritten since comes back with its next heartbeat,
	 * as true as it was: the node rewrites it whenever the answer changes
	 * (StreamRuntime::lapse, ReplicaApply) and removes it in mode 0.
	 */
	public static function forgetStreamsLocal(int $rServerID): void {
		$rReport = self::reports()[$rServerID] ?? null;
		if ($rReport === null || !array_key_exists('streams_local', $rReport)) {
			return;
		}
		unset($rReport['streams_local']);
		try {
			NodeRegistry::update($rServerID, ['audit' => self::encode($rReport)]);
		} catch (\Throwable) {
			// The gate still asks that the node was heard a moment ago.
		}
	}

	/**
	 * The connect counters of a heartbeat's `audit`, or [] when they are not
	 * all there as counts.
	 *
	 * @param array<mixed> $rAudit
	 * @return array{sql_connects?: int, redis_connects?: int, sites?: array<string, int>, connects_since?: int}
	 */
	private static function connects(array $rAudit): array {
		$rSql = $rAudit['sql_connects'] ?? null;
		$rRedis = $rAudit['redis_connects'] ?? null;
		if (!is_int($rSql) || $rSql < 0 || !is_int($rRedis) || $rRedis < 0 || !is_array($rAudit['sites'] ?? null)) {
			return [];
		}
		$rSites = [];
		foreach ($rAudit['sites'] as $rKey => $rCount) {
			$rKey = (string) $rKey;
			if (is_int($rCount) && $rCount > 0 && ($rKey === ConnectAudit::OTHER || ConnectAudit::isSite($rKey))) {
				$rSites[$rKey] = $rCount;
			}
		}
		$rOut = ['sql_connects' => $rSql, 'redis_connects' => $rRedis, 'sites' => SettingsAudit::top($rSites, ConnectAudit::MAX_SITES)];
		$rSince = $rAudit['connects_since'] ?? null;
		return is_int($rSince) && $rSince > 0 ? $rOut + ['connects_since' => $rSince] : $rOut;
	}

	/**
	 * Keep a heartbeat's `audit` with its node when it is one and differs
	 * from what the row holds. Before migration 045 the row has no `audit`,
	 * and nothing is written. A failed write is tried again at the next
	 * heartbeat, which still differs.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row, as the request read it
	 */
	public static function record(array $rNode, mixed $rAudit): void {
		$rDoc = self::normalise($rAudit);
		if ($rDoc === null || !array_key_exists('audit', $rNode)) {
			return;
		}
		$rJson = self::encode($rDoc);
		if ($rJson === $rNode['audit']) {
			return;
		}
		try {
			NodeRegistry::update((int) $rNode['server_id'], ['audit' => $rJson]);
		} catch (\Throwable) {
			// The next heartbeat tries again.
		}
	}

	/**
	 * Each node's last reported audit, as normalise() keeps it (server id =>
	 * report); a node that reported none is absent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function reports(): array {
		try {
			$rRows = self::db()->query('SELECT `server_id`, `audit` FROM `cluster_nodes` WHERE `audit` IS NOT NULL;') ? self::db()->get_rows() : [];
		} catch (\Throwable) {
			$rRows = []; // before migration 045
		}
		$rOut = [];
		foreach ($rRows ?: [] as $rRow) {
			$rDoc = json_decode((string) $rRow['audit'], true);
			if (is_array($rDoc) && is_array($rDoc['settings_misses'] ?? null)) {
				$rOut[(int) $rRow['server_id']] = $rDoc;
			}
		}
		return $rOut;
	}

	/**
	 * Each node's last reported settings misses (server id => {key: count});
	 * a node that reported none is absent.
	 *
	 * @return array<int, array<string, int>>
	 */
	public static function settingsMisses(): array {
		return array_map(static fn(array $rDoc): array => $rDoc['settings_misses'], self::reports());
	}

	/**
	 * A report's connect counters for the Cluster Nodes page, or null when
	 * the node reported none (today's agent, or a node before this audit).
	 *
	 * @param array<string, mixed>|null $rReport one of reports()
	 * @return array{sql_connects: int, redis_connects: int, sites: array<string, int>, connects_since: int|null}|null
	 */
	public static function connectsOf(?array $rReport): ?array {
		if (!isset($rReport['sql_connects'], $rReport['redis_connects']) || !is_array($rReport['sites'] ?? null)) {
			return null;
		}
		return ['sql_connects' => (int) $rReport['sql_connects'], 'redis_connects' => (int) $rReport['redis_connects'], 'sites' => $rReport['sites'], 'connects_since' => isset($rReport['connects_since']) ? (int) $rReport['connects_since'] : null];
	}
}
