<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Cluster\ClusterRoute;

/**
 * Signal Dispatcher
 *
	 * The one way code tells a node to do something: kill a viewer process or
	 * refresh cached data. Callers used to write `signals` rows
 * directly (47 INSERTs); they now name the intent and the dispatcher writes it
 * through a SignalSink. Today the only sink is LegacySqlSignalSink, which
 * writes exactly the rows the old INSERTs wrote, so nothing changes for the
 * consumers (the signals daemon, cron:root_signals, the cache handler). The
 * cluster API plan (Phase 4) adds a sink that turns these into signed
 * commands for nodes in API mode.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class SignalDispatcher {
	private static ?SignalSink $rSink = null;

	/**
	 * Kill a viewer process (a connection's `pid`) on its node. Written with
	 * the database's clock, as the stream endpoints always did. A pid that is
	 * not positive names no process (the signals daemon would skip it, or send
	 * an RTMP drop for client 0), so nothing is written.
	 *
	 * @param object|null $rDb The caller's DatabaseHandler, if it holds its own.
	 */
	public static function kill(int $rServerID, int $rPID, bool $rRTMP = false, ?object $rDb = null): bool {
		if ($rPID <= 0) {
			return false;
		}
		// A node with the COMMANDS flow gets a signed conn.kill_worker (MAIN only).
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::kill($rServerID, $rPID, $rRTMP);
			if ($rRouted) {
				return $rQueued;
			}
		}
		$rRow = ['pid' => $rPID, 'server_id' => $rServerID];
		if ($rRTMP) {
			$rRow['rtmp'] = 1;
		}
		$rRow['time'] = null;
		return self::sink($rDb)->insert([$rRow]);
	}

	/**
	 * A cache job (update_line, update_streams, delete_vod, drop_con, …;
	 * CacheJobs). For a node in mode 2, which reads no `signals` row, MAIN
	 * sends it as a `node.cache` command (ClusterRoute::cache), and the
	 * node runs its own at once.
	 *
	 * @param array<string, mixed> $rPayload
	 * @param bool $rOnce   Skip when an identical cache signal is already pending.
	 * @param bool $rDbTime Stamp with the database's clock instead of PHP's.
	 */
	public static function cache(int $rServerID, array $rPayload, bool $rOnce = false, bool $rDbTime = false, ?object $rDb = null): bool {
		// A node in mode 2 reads no row: its own jobs run here, and MAIN
		// sends it the others as a signed node.cache.
		if (self::runsHere($rServerID)) {
			CacheJobs::run(CacheJobs::onNode([$rPayload]));
			return true;
		}
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::cache($rServerID, [$rPayload]);
			if ($rRouted) {
				return $rQueued;
			}
		}
		$rJson = json_encode($rPayload);
		$rSink = self::sink($rDb);
		if ($rOnce && $rSink->pending($rServerID, $rJson)) {
			return true;
		}
		return $rSink->insert([['server_id' => $rServerID, 'cache' => 1, 'time' => $rDbTime ? null : time(), 'custom_data' => $rJson]]);
	}

	/**
	 * Several cache jobs for one node in one write.
	 *
	 * @param list<array<string, mixed>> $rPayloads
	 */
	public static function cacheBatch(int $rServerID, array $rPayloads, ?int $rTime = null, ?object $rDb = null): bool {
		if ($rPayloads === []) {
			return true;
		}
		if (self::runsHere($rServerID)) {
			CacheJobs::run(CacheJobs::onNode($rPayloads));
			return true;
		}
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::cache($rServerID, $rPayloads);
			if ($rRouted) {
				return $rQueued;
			}
		}
		$rTime ??= time();
		$rRows = [];
		foreach ($rPayloads as $rPayload) {
			$rRows[] = ['server_id' => $rServerID, 'cache' => 1, 'time' => $rTime, 'custom_data' => json_encode($rPayload)];
		}
		return self::sink($rDb)->insert($rRows);
	}

	/**
	 * Does a node run its own cache jobs where they are queued? On a node
	 * in mode 2 (NodeRole::refusesConnects), a row would be refused, and
	 * its signals daemon reads none: the jobs that act on the node run at
	 * once (CacheJobs::onNode), as the daemon would have run them a moment
	 * later.
	 */
	private static function runsHere(int $rServerID): bool {
		return defined('SERVER_ID') && $rServerID === (int) SERVER_ID && NodeRole::refusesConnects();
	}

	/** Replace the sink (tests; later the cluster API). Null restores the legacy SQL sink. */
	public static function useSink(?SignalSink $rSink): void {
		self::$rSink = $rSink;
	}

	private static function sink(?object $rDb): SignalSink {
		return self::$rSink ?? new LegacySqlSignalSink($rDb);
	}
}
