<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Util\AtomicFile;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Who ends an idle HLS viewer (plan, Phase 6: "Connection close (HLS) —
 * agent reaper, 30 s after the last read").
 *
 * An HLS viewer has no worker to watch, only its playlist requests. The
 * legacy reaper (UsersCronJob) ends one 30 s after its `hls_last_read`. On a
 * node whose CONNECTIONS flow is on, that time only reaches MAIN in the
 * agent's throttled upserts, so a slow or cut link would end viewers who are
 * still watching. An agent that says `hls_reaper` at hello ends them itself,
 * by the node's own clock (a P0 `conn.upsert` with `hls_end` 1), and the
 * reaper here then closes only what the node ended.
 *
 * The exception is a node that has gone silent (orphaned): its viewers would
 * otherwise count against their lines forever. A CONNECTIONS node is orphaned
 * once it has been silent for `cluster_orphan_conn_ttl_sec` and this reaper
 * has itself watched it stay silent that long, so MAIN's own downtime never
 * orphans anyone: silence counts from max(last_seen_at, cluster_ready_at), and
 * while the fleet silence guard is up (ClusterHealth) the nodes it holds are
 * not watched, for at most GUARD_HOLD_TTLS. Its rows are then purged from
 * MAIN's store (orphaned(), ConnectionIngest::purgeNode), HLS and TS alike.
 *
 * A node that stops reaping for itself (CONNECTIONS off, mode 0, revoked,
 * an agent without `hls_reaper`) keeps counting as reaping for LEAVE_GRACE:
 * its touches reached only the cluster bus (conn.touch), so the store's
 * hls_last_read can be minutes old until the node's PHP, or an older agent's
 * P0 upserts, write fresh reads there again.
 *
 * In Core: UsersCronJob ships to LBs, which reap their own rows in MySQL
 * mode and ask their own agent (NodeFlows) instead of cluster_nodes.
 */
final class HlsReaping {
	use DatabaseAware;

	public const FEATURE = 'hls_reaper';

	/** Seconds without a playlist request before an HLS viewer ends. */
	public const STALE_AFTER = 30;

	/** Silence (s) from which the reaper starts watching a node. */
	private const WATCH_AFTER = 10;

	/** A gap between two passes longer than this restarts the watch (MAIN was down). */
	private const MAX_PASS_GAP = 180;

	/**
	 * Seconds a node still counts as reaping after a pass first finds it no
	 * longer does: two reaper passes, for the node to hear of the change at
	 * its next heartbeat and its viewers' next playlist requests to refresh
	 * the store.
	 */
	public const LEAVE_GRACE = 120;

	/**
	 * Longest the fleet silence guard holds the watch, in orphan TTLs from the
	 * pass that first saw it up. The guard lasts while most nodes are silent,
	 * which a lasting loss of most of the fleet never ends; after this, a node
	 * that stays silent is purged one TTL later. A purge during a longer cut
	 * of MAIN's own network is undone when the nodes come back: their digests
	 * disagree, and their snapshots restore the rows.
	 */
	private const GUARD_HOLD_TTLS = 4;

	/** @var array<int, bool> server id => the node ends its own idle HLS viewers */
	private static array $rReaps = [];

	/** @var list<int> CONNECTIONS nodes silent past the orphan TTL, watched by MAIN */
	private static array $rOrphaned = [];

	/** @var array<int, bool> server id => a node in mode 2: it has no database, so its users cron sweeps nothing of MAIN's */
	private static array $rNoSweep = [];

	/** On an LB, this pass's answer for its own rows (beginLocal()); null: ask the agent now. */
	private static ?bool $rLocal = null;

	private static ?string $rPath = null;

	/** Does this node end its own idle HLS viewers, so the 30 s rule must not? */
	public static function nodeReaps(int $rServerID): bool {
		if (defined('SERVER_ID') && $rServerID === (int) SERVER_ID) {
			return self::$rLocal ?? self::localReaps();
		}
		return self::$rReaps[$rServerID] ?? false;
	}

	/**
	 * MAIN, `lines_live` as the store: must MAIN close what this node's agent
	 * said has ended? There each server's users cron sweeps its own rows,
	 * and a node in mode 2 runs none (it has no database): nobody else would,
	 * until the row counted as orphaned. As of the last begin().
	 */
	public static function sweptByMain(int $rServerID): bool {
		return self::$rNoSweep[$rServerID] ?? false;
	}

	/** On an LB: does this node's own agent end its idle HLS viewers? */
	public static function localReaps(): bool {
		return NodeFlows::on(NodeFlows::CONNECTIONS) && NodeFlows::agentHas(self::FEATURE);
	}

	/**
	 * Does this node's agent end its idle HLS viewers, by its cluster_nodes
	 * row (mode ≥ 1, CONNECTIONS on, `hls_reaper` said at hello)? Its touches
	 * then stay on MAIN's cluster bus (conn.touch).
	 *
	 * @param array<string, mixed> $rRow
	 */
	public static function capable(array $rRow): bool {
		return (int) ($rRow['mode'] ?? 0) >= 1 && ((int) ($rRow['flows'] ?? 0) & NodeFlows::CONNECTIONS) !== 0
			&& in_array(self::FEATURE, explode(',', (string) ($rRow['features'] ?? '')), true);
	}

	/**
	 * MAIN, once per reaper pass: which nodes reap for themselves, and the
	 * silence watch that finds orphaned ones. A node that reaped in an
	 * earlier pass and no longer does (its row changed or left the active
	 * ones) still counts for LEAVE_GRACE from the pass that first found it
	 * so, unless it is orphaned. When cluster_nodes cannot be read, the
	 * reapers of the last pass that read it stand (those leaving, within
	 * their grace) and nothing is orphaned: a reaping node's touches reach
	 * only the cluster bus, so MAIN's store holds no fresh read for the 30 s
	 * rule to judge by. With no such pass, every node falls back to the 30 s
	 * rule.
	 */
	public static function begin(int $rNowSec, int $rOrphanTtlSec): void {
		self::$rReaps = [];
		self::$rOrphaned = [];
		self::$rNoSweep = [];
		$db = self::db();
		try {
			$rRead = $db->query("SELECT `server_id`, `mode`, `flows`, `features`, `last_seen_at` FROM `cluster_nodes` WHERE `state` = 'active';");
		} catch (\Throwable) {
			$rRead = false;
		}
		$rState = self::load();
		if (!$rRead) {
			foreach ($rState['reaps'] as $rID) {
				self::$rReaps[$rID] = true;
			}
			foreach ($rState['leaving'] as $rID => $rSince) {
				self::$rReaps[$rID] = self::$rReaps[$rID] ?? ($rNowSec - $rSince < self::LEAVE_GRACE);
			}
			return;
		}
		$rRows = $db->get_rows();
		$rReady = self::readyAtSec();
		$rFresh = $rNowSec - $rState['run'] <= self::MAX_PASS_GAP;
		$rSince = $rFresh ? $rState['since'] : [];
		// The fleet silence guard: MAIN suspects its own fault, so the silence
		// of the nodes it holds does not count, and their watch starts over.
		// It does not hold a node that was offline before it came up (the
		// guard leaves such a node offline), nor anyone past GUARD_HOLD_TTLS.
		$rGuardAt = !ClusterHealth::read()['guard'] ? 0 : ($rFresh && $rState['guard'] > 0 ? $rState['guard'] : $rNowSec);
		$rHolding = $rGuardAt > 0 && $rNowSec - $rGuardAt < self::GUARD_HOLD_TTLS * $rOrphanTtlSec;
		$rKeep = [];
		foreach ($rRows as $rRow) {
			$rID = (int) $rRow['server_id'];
			// Every CONNECTIONS node is watched (the orphan purge); only those
			// whose agent says hls_reaper end their own idle HLS viewers.
			if ((int) $rRow['mode'] < 1 || ((int) $rRow['flows'] & NodeFlows::CONNECTIONS) === 0) {
				continue;
			}
			if ((int) $rRow['mode'] >= 2) {
				self::$rNoSweep[$rID] = true;
			}
			$rCapable = self::capable($rRow);
			// Silence counts from max(last_seen_at, cluster_ready_at): the time
			// MAIN's API was not serving is not the node's silence.
			$rHeard = $rRow['last_seen_at'] === null ? null : intdiv((int) $rRow['last_seen_at'], 1000);
			$rFrom = $rHeard === null ? ($rReady > 0 ? $rReady : null) : max($rHeard, $rReady);
			$rSilent = $rFrom === null ? PHP_INT_MAX : $rNowSec - $rFrom;
			if ($rSilent >= self::WATCH_AFTER && !($rHolding && ClusterHealth::state($rID) !== 'offline')) {
				$rKeep[$rID] = $rSince[$rID] ?? $rNowSec;
			}
			$rOrphaned = isset($rKeep[$rID]) && $rSilent >= $rOrphanTtlSec && $rNowSec - $rKeep[$rID] >= $rOrphanTtlSec;
			self::$rReaps[$rID] = $rCapable && !$rOrphaned;
			if ($rOrphaned) {
				self::$rOrphaned[] = $rID;
			}
		}
		$rReapers = array_keys(array_filter(self::$rReaps));
		$rLeaving = [];
		foreach (array_unique([...$rState['reaps'], ...array_keys($rState['leaving'])]) as $rID) {
			if (!empty(self::$rReaps[$rID]) || in_array($rID, self::$rOrphaned, true)) {
				continue; // reaps again, or its rows are purged
			}
			$rLeft = $rState['leaving'][$rID] ?? $rNowSec;
			if ($rNowSec - $rLeft < self::LEAVE_GRACE) {
				self::$rReaps[$rID] = true;
				$rLeaving[$rID] = $rLeft;
			}
		}
		self::save(['run' => $rNowSec, 'since' => $rKeep, 'guard' => $rGuardAt, 'reaps' => $rReapers, 'leaving' => $rLeaving] + $rState);
	}

	/**
	 * An LB, once per reaper pass (in MySQL mode its users cron judges its
	 * own rows in `lines_live`): does its agent end its idle HLS viewers? One
	 * that stops (CONNECTIONS off, the reaper gone) still counts for
	 * LEAVE_GRACE from the pass that first found it so, as on MAIN.
	 */
	public static function beginLocal(int $rNowSec): void {
		$rState = self::load();
		$rReaps = self::localReaps();
		$rLeft = null;
		if (!$rReaps && ($rState['local'] || $rState['local_left'] !== null)) {
			$rLeft = $rState['local_left'] ?? $rNowSec;
			if ($rNowSec - $rLeft >= self::LEAVE_GRACE) {
				$rLeft = null;
			}
		}
		self::$rLocal = $rReaps || $rLeft !== null;
		self::save(['local' => $rReaps, 'local_left' => $rLeft] + $rState);
	}

	/**
	 * CONNECTIONS nodes found orphaned by the last begin(): their connections
	 * are purged from MAIN's store (ConnectionIngest::purgeNode).
	 *
	 * @return list<int>
	 */
	public static function orphaned(): array {
		return self::$rOrphaned;
	}

	/**
	 * When MAIN's cluster API last started serving (`cluster_meta.ready_at`,
	 * written by ClusterMeta::markReady), in seconds; 0 when unknown.
	 */
	private static function readyAtSec(): int {
		try {
			$db = self::db();
			if (!$db->query("SELECT `value` FROM `cluster_meta` WHERE `name` = 'ready_at';") || $db->num_rows() === 0) {
				return 0;
			}
			return intdiv((int) ($db->get_row()['value'] ?? 0), 1000);
		} catch (\Throwable) {
			return 0; // no cluster tables: silence counts from last_seen_at alone
		}
	}

	/** Tests: keep the watch elsewhere, and forget the last pass. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rReaps = [];
		self::$rOrphaned = [];
		self::$rLocal = null;
	}

	private static function path(): ?string {
		return self::$rPath ?? (defined('TMP_PATH') ? TMP_PATH . 'cluster_orphans.json' : null);
	}

	/**
	 * The last pass: MAIN's (run, since, guard, reaps, leaving) and an LB's
	 * own (local, local_left).
	 *
	 * @return array{run: int, since: array<int, int>, guard: int, reaps: list<int>, leaving: array<int, int>, local: bool, local_left: int|null}
	 */
	private static function load(): array {
		$rPath = self::path();
		$rDoc = $rPath === null ? null : json_decode((string) @file_get_contents($rPath), true);
		$rDoc = is_array($rDoc) ? $rDoc : [];
		$rReaps = array_values(array_map('intval', array_filter(is_array($rDoc['reaps'] ?? null) ? $rDoc['reaps'] : [], 'is_int')));
		return [
			'run' => (int) ($rDoc['run'] ?? 0), 'since' => self::times($rDoc['since'] ?? null), 'guard' => (int) ($rDoc['guard'] ?? 0),
			'reaps' => $rReaps, 'leaving' => self::times($rDoc['leaving'] ?? null),
			'local' => ($rDoc['local'] ?? false) === true, 'local_left' => is_int($rDoc['local_left'] ?? null) ? $rDoc['local_left'] : null,
		];
	}

	/** @return array<int, int> server id => a time, from the saved doc */
	private static function times(mixed $rMap): array {
		$rOut = [];
		foreach (is_array($rMap) ? $rMap : [] as $rID => $rAt) {
			$rOut[(int) $rID] = (int) $rAt;
		}
		return $rOut;
	}

	/** @param array{run: int, since: array<int, int>, guard: int, reaps: list<int>, leaving: array<int, int>, local: bool, local_left: int|null} $rState */
	private static function save(array $rState): void {
		$rPath = self::path();
		if ($rPath === null) {
			return;
		}
		AtomicFile::write($rPath, (string) json_encode($rState));
	}
}
