<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\DivergenceSink;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Streaming\Fanout\FanoutConfig;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * FanoutSyncCommand — reconcile xc_fanout live-TS connections (ADR 0003, Phase C).
 *
 * Under X-Accel the auth PHP-FPM worker returns immediately, so the reaper's
 * isRunning(pid) check can neither track nor time out a daemon-served TS viewer
 * (those rows are recorded pid=0). This daemon closes them by reconciling every
 * pid=0 live-TS row against the set of connection uuids the xc_fanout daemon
 * still has open (control GET /connections): a row whose uuid is no longer
 * connected — and which is past a short grace so a just-connecting viewer isn't
 * killed — is closed, freeing the line's connection slot.
 *
 * If the daemon is unreachable the reconcile is skipped (an empty set would
 * wrongly close every daemon connection).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutSyncCommand implements CommandInterface {
	use DaemonTrait;

	/** Seconds a pid=0 row must exist before it can be reconciled (connect race). */
	private const GRACE = 20;

	/** Reconcile interval, seconds. */
	private const INTERVAL = 10;

	/**
	 * Seconds between two divergence reports sent to MAIN (conn.divergence,
	 * P1), as often as the users cron sends its own: at every pass they would
	 * take much of the lane the node's logs share.
	 */
	private const DIVERGENCE_EVERY = 60;

	/** When this node's divergence last went to MAIN (conn.divergence). */
	private int $rDivergenceAt = 0;

	/** @var array<string,int> Daemon viewer uuid => when it was first seen without a row. */
	private array $rOrphanSince = [];

	public function getName(): string {
		return 'fanout_sync';
	}

	public function getDescription(): string {
		return 'Daemon: reconcile xc_fanout TS connections against the daemon';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!$this->acquireDaemonLock('fanout_sync')) {
			return 0;
		}

		$this->setProcessTitle('XC_VM[FanoutSync]');
		$this->killStaleProcesses('console.php fanout_sync');
		$this->killStaleProcesses('XC_VM\\[FanoutSync\\]');
		$this->initDaemonMD5();
		// A node in mode 2 may not open MAIN's Redis: with the Redis handler on,
		// a generation started there died at this line, and only `service`
		// starts this daemon again.
		if (!NodeRole::refusesConnects()) {
			$this->initRedisIfEnabled();
		}

		while (true) {
			// Refresh settings periodically and exit (to be respawned) if nginx
			// stopped or the code changed — the standard daemon self-restart.
			$rLastRefresh = $this->rLastCheck;
			if (!$this->refreshOrBreak()) {
				break;
			}

			// Keep the daemon's tuning config.json in step with panel settings even
			// when nobody re-saved them: if the daemon wrote a default/stale config,
			// the panel corrects it here. Runs in the xc_vm context that owns the
			// file; a cheap no-op unless the panel-owned keys actually diverge (the
			// internal diff guard skips the write), and the daemon mtime-polls it.
			//
			// Only with settings just re-read from the database: this loop runs
			// every INTERVAL but refreshes settings every rRefreshInterval, and a
			// sync from the older snapshot put the previous values back over an
			// admin's save (SettingsService::edit writes the file at once) for up
			// to a minute.
			$rEnabled = FanoutMode::enabled();
			if ($rEnabled && $this->rLastCheck !== $rLastRefresh) {
				FanoutConfig::sync(SettingsManager::getAll());
			}

			// Fanout switched off: the daemon is stopped, so it serves nobody. An
			// empty active set lets reconcile() close the daemon-served rows it
			// left behind (pid 0); the PHP-served rows of the legacy path carry
			// their php-fpm pid and are never touched here.
			$rActive = $rEnabled ? FanoutClient::activeConnections() : [];
			if ($rActive !== null && AgentConnections::enabled()) {
				// The agent owns this node's registry and reconciles it against the
				// fanout itself, so there are no rows here to close or drop: the
				// daemon only reports the rates it measures.
				$this->spoolRates($rActive);
			} elseif ($rActive !== null) {
				$rConns = $this->daemonConnections();
				if ($rConns !== null) {
					$this->reconcile(array_flip($rActive), $rConns);
					if ($rEnabled) {
						$this->dropOrphans($rActive, $rConns);
						$this->writeDivergence($rConns);
					}
				}
			}

			sleep(self::INTERVAL);
		}

		// Self-respawn like every other daemon (watchdog/signals): refreshOrBreak
		// exits the loop on an nginx restart or a code change so a FRESH process
		// picks up the new code — but that only happens if we spawn the successor.
		// Without this the reconciler dies on the first deploy / nginx reload and
		// never comes back (it is launched just once by the `service` script), so
		// daemon-served pid=0 connections stop being reconciled and ghost viewers
		// pile up in Redis/lines_live on streams nobody is watching.
		$this->restartDaemon('fanout_sync');

		return 0;
	}

	/**
	 * Close daemon-served TS rows whose uuid is no longer connected to the daemon.
	 *
	 * @param array<string,int>              $rActiveSet Currently-connected uuids (flipped).
	 * @param array<int,array<string,mixed>> $rConns     Candidate pid=0 rows (shared).
	 * @return void
	 */
	private function reconcile(array $rActiveSet, array $rConns): void {
		global $rServers;
		$rOffset = intval($rServers[SERVER_ID]['time_offset'] ?? 0);
		$rNow = time() - $rOffset;

		foreach ($rConns as $rConn) {
			if (!is_array($rConn) || empty($rConn['uuid'])) {
				continue;
			}
			if (($rConn['container'] ?? '') === 'hls' || !empty($rConn['hls_end'])) {
				continue;
			}
			if (intval($rConn['pid'] ?? -1) !== 0) {
				continue;
			}
			if (($rNow - intval($rConn['hls_last_read'] ?? 0)) < self::GRACE) {
				continue; // still within the connect grace
			}
			if (!isset($rActiveSet[$rConn['uuid']])) {
				ConnectionTracker::closeConnection($rConn);
			}
		}
	}

	/**
	 * The other direction of reconcile(): end daemon viewers that have no open row
	 * any more. Every path that closes a connection by deleting its row — the
	 * reaper, an expired or banned line, a limit eviction on a node that could not
	 * reach this daemon — would otherwise leave the viewer streaming, untracked
	 * and uncounted. A uuid must stay orphaned for GRACE before it is dropped (the
	 * row is written before the X-Accel hand-off, so a live viewer always has one;
	 * the grace covers a read racing that write).
	 *
	 * @param string[]                       $rActive Uuids connected to the daemon.
	 * @param array<int,array<string,mixed>> $rConns  Open rows on this server.
	 * @param int|null                       $rNow    Current time (tests), default now.
	 * @param callable|null                  $rDrop   fn(string $uuid) (tests), default the daemon call.
	 * @return string[] The uuids dropped on this pass.
	 */
	public function dropOrphans(array $rActive, array $rConns, ?int $rNow = null, ?callable $rDrop = null): array {
		$rKnown = [];
		foreach ($rConns as $rConn) {
			if (is_array($rConn) && !empty($rConn['uuid']) && empty($rConn['hls_end'])) {
				$rKnown[$rConn['uuid']] = true;
			}
		}

		$rNow = $rNow ?? time();
		$rDrop = $rDrop ?? static function (string $rUUID): void {
			FanoutClient::dropConnection($rUUID);
		};
		$rSeen = [];
		$rDropped = [];
		foreach ($rActive as $rUUID) {
			$rUUID = (string) $rUUID;
			if ($rUUID === '' || isset($rKnown[$rUUID])) {
				continue;
			}
			$rSince = $this->rOrphanSince[$rUUID] ?? $rNow;
			if ($rNow - $rSince >= self::GRACE) {
				echo 'Dropping daemon viewer without a connection row: ' . $rUUID . "\n";
				$rDrop($rUUID);
				$rDropped[] = $rUUID;
				continue;
			}
			$rSeen[$rUUID] = $rSince;
		}
		$this->rOrphanSince = $rSeen;
		return $rDropped;
	}

	/**
	 * Record per-viewer transfer telemetry (`divergence`) for daemon-served rows
	 * (ADR 0003, P4). Under X-Accel the byte path left PHP, so live.php can no
	 * longer measure each viewer's rate the way the legacy chase-read loop did
	 * (it wrote KB/s to DIVERGENCE_TMP_PATH, which UsersCronJob turned into a
	 * bitrate divergence). Instead the daemon accounts bytes per connection and
	 * exposes the average KB/s at control GET /rates; here we compare that to the
	 * stream's expected bitrate — the identical `bitrate/8*0.92` math UsersCronJob
	 * uses — and write the divergence for the pid=0 rows. Legacy (non-daemon)
	 * viewers keep their tmpfs-driven path in UsersCronJob; the two never overlap
	 * (a daemon viewer has no speed file, a legacy viewer isn't pid=0), so there
	 * is no double write.
	 *
	 * @param array<int,array<string,mixed>> $rConns Candidate pid=0 rows (shared).
	 * @param array<string,mixed>|null      $rRates uuid => KB/s (tests); by default the daemon's.
	 * @return void
	 */
	private function writeDivergence(array $rConns, ?array $rRates = null): void {
		if (count($rConns) === 0) {
			return;
		}

		$rRates ??= FanoutClient::connectionRates();
		if (!is_array($rRates) || count($rRates) === 0) {
			return; // daemon unreachable or no active viewers — nothing to record
		}
		if ($this->spoolDivergence($rConns, $rRates)) {
			return; // a CONNECTIONS node: MAIN writes it (conn.divergence)
		}

		global $rSettings;
		$rRedisMode = !empty($rSettings['redis_handler']);

		DatabaseFactory::connect();
		global $db;

		// Expected delivery rate per stream: bitrate (kbps) / 8 = KB/s, minus a
		// small headroom (0.92) — the same expectation UsersCronJob compares to.
		$rExpected = [];
		$db->query('SELECT `stream_id`, `bitrate` FROM `streams_servers` WHERE `server_id` = ? AND `bitrate` IS NOT NULL;', SERVER_ID);
		foreach ($db->get_rows() as $rRow) {
			$rBitrate = intval($rRow['bitrate']);
			if ($rBitrate > 0) {
				$rExpected[intval($rRow['stream_id'])] = DivergenceSink::expected($rBitrate);
			}
		}
		if (count($rExpected) === 0) {
			return;
		}

		$rDivergenceRows = $rLiveRows = [];
		foreach ($rConns as $rConn) {
			if (!is_array($rConn) || empty($rConn['uuid'])) {
				continue;
			}
			$rUUID = $rConn['uuid'];
			if (!isset($rRates[$rUUID])) {
				continue; // not currently connected to the daemon (or no rate yet)
			}
			$rStreamID = intval($rConn['stream_id'] ?? 0);
			$rExpectedKBs = $rExpected[$rStreamID] ?? 0;
			if ($rExpectedKBs <= 0) {
				continue;
			}

			// divergence = how many % BELOW the expected bitrate the viewer runs
			// (a viewer receiving faster than realtime, e.g. the prebuffer burst,
			// clamps to 0), stored as a positive shortfall — matches legacy.
			$rDivergence = DivergenceSink::of(intval($rRates[$rUUID]), $rExpectedKBs);

			$rDivergenceRows[] = "('" . $rUUID . "', " . $rDivergence . ')';
			if (!$rRedisMode && !empty($rConn['activity_id'])) {
				$rLiveRows[] = '(' . intval($rConn['activity_id']) . ', ' . $rDivergence . ')';
			}
		}

		if (count($rDivergenceRows) > 0) {
			$db->query('INSERT INTO `lines_divergence`(`uuid`,`divergence`) VALUES ' . implode(',', $rDivergenceRows) . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
		}
		if (!$rRedisMode && count($rLiveRows) > 0) {
			$db->query('INSERT INTO `lines_live`(`activity_id`,`divergence`) VALUES ' . implode(',', $rLiveRows) . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
		}
	}

	/**
	 * A CONNECTIONS node's delivery rates, as a conn.divergence event: every
	 * viewer the fanout reports is this node's own, so the rates need no row of
	 * MAIN's to be filtered against.
	 *
	 * @param list<string> $rActive The fanout's open connection uuids.
	 */
	private function spoolRates(array $rActive): void {
		$rRates = FanoutClient::connectionRates();
		if (is_array($rRates) && $rRates !== []) {
			$this->spoolDivergence(array_map(static fn($rUUID): array => ['uuid' => (string) $rUUID], $rActive), $rRates);
		}
	}

	/**
	 * On a node whose CONNECTIONS flow is on, the daemon's rates for this
	 * node's daemon-served rows go to MAIN as a conn.divergence event, at most
	 * every DIVERGENCE_EVERY, and MAIN works out the divergence from the
	 * stream's bitrate. False when they were not sent: writeDivergence()
	 * writes MAIN's tables, as before.
	 *
	 * @param array<int,array<string,mixed>> $rConns Candidate pid=0 rows.
	 * @param array<string,mixed>            $rRates uuid => KB/s from the daemon.
	 */
	private function spoolDivergence(array $rConns, array $rRates): bool {
		if (!NodeFlows::on(NodeFlows::CONNECTIONS)) {
			return false;
		}
		if (time() - $this->rDivergenceAt < self::DIVERGENCE_EVERY) {
			return true; // reported less than DIVERGENCE_EVERY ago
		}
		$rOwn = [];
		foreach ($rConns as $rConn) {
			$rUUID = is_array($rConn) ? (string) ($rConn['uuid'] ?? '') : '';
			if ($rUUID !== '' && isset($rRates[$rUUID])) {
				$rOwn[$rUUID] = intval($rRates[$rUUID]);
			}
		}
		if (!DivergenceSink::spool($rOwn, 'fanout')) {
			return false;
		}
		$this->rDivergenceAt = time();
		return true;
	}

	/**
	 * Candidate daemon-served rows (pid=0, open) on this server, from Redis or DB.
	 * Null when they could not be read — distinct from "none", which would have
	 * dropOrphans() disconnect every daemon viewer on a Redis or database blip.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function daemonConnections(): ?array {
		global $rSettings;

		// With CONNECTIONS on, this node's viewers live in its agent's registry,
		// which the agent reconciles against the fanout itself; in mode 2 there is
		// neither MAIN's Redis nor its `lines_live` to read. Null is the answer the
		// loop already has for "could not be read": it skips the pass.
		// A node in mode 2 has neither MAIN's Redis nor its `lines_live` to read.
		// Null is the answer the loop already has for "could not be read".
		if (NodeRole::refusesConnects()) {
			return null;
		}

		if (!empty($rSettings['redis_handler'])) {
			RedisManager::ensureConnected();
			$rRedis = RedisManager::instance();
			if (!$rRedis) {
				return null;
			}
			try {
				$rKeys = $rRedis->zRangeByScore('SERVER#' . SERVER_ID, '-inf', '+inf');
			} catch (\Throwable $rError) {
				return null;
			}
			if (!is_array($rKeys)) {
				return null;
			}
			$rOut = [];
			foreach ($rKeys as $rUUID) {
				$rConn = ConnectionTracker::getConnection($rUUID);
				if (is_array($rConn)) {
					$rOut[] = $rConn;
				}
			}
			return $rOut;
		}

		DatabaseFactory::connect();
		global $db;
		if (!$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ? AND `pid` = 0 AND `hls_end` = 0', SERVER_ID)) {
			return null;
		}
		return $db->get_rows();
	}
}
