<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;

require_once __DIR__ . '/../DaemonTrait.php';

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
		$this->initRedisIfEnabled();

		while (true) {
			// Refresh settings periodically and exit (to be respawned) if nginx
			// stopped or the code changed — the standard daemon self-restart.
			if (!$this->refreshOrBreak()) {
				break;
			}

			$rActive = FanoutClient::activeConnections();
			if ($rActive !== null) {
				$rConns = $this->daemonConnections();
				$this->reconcile(array_flip($rActive), $rConns);
				$this->writeDivergence($rConns);
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
	 * @return void
	 */
	private function writeDivergence(array $rConns): void {
		if (count($rConns) === 0) {
			return;
		}

		$rRates = FanoutClient::connectionRates();
		if (!is_array($rRates) || count($rRates) === 0) {
			return; // daemon unreachable or no active viewers — nothing to record
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
				$rExpected[intval($rRow['stream_id'])] = intval($rBitrate / 8 * 0.92);
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
			// clamps to 0). abs() to store a positive shortfall — matches legacy.
			$rDivergence = intval((intval($rRates[$rUUID]) - $rExpectedKBs) / $rExpectedKBs * 100);
			if ($rDivergence > 0) {
				$rDivergence = 0;
			}
			$rDivergence = abs($rDivergence);

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
	 * Candidate daemon-served rows (pid=0, open) on this server, from Redis or DB.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function daemonConnections(): array {
		global $rSettings;

		if (!empty($rSettings['redis_handler'])) {
			RedisManager::ensureConnected();
			$rRedis = RedisManager::instance();
			if (!$rRedis) {
				return array();
			}
			$rKeys = $rRedis->zRangeByScore('SERVER#' . SERVER_ID, '-inf', '+inf');
			if (!is_array($rKeys)) {
				return array();
			}
			$rOut = array();
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
		$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ? AND `pid` = 0 AND `hls_end` = 0', SERVER_ID);
		return $db->get_rows();
	}
}
