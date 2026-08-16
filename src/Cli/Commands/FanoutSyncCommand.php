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
				$this->reconcile(array_flip($rActive));
			}

			sleep(self::INTERVAL);
		}

		return 0;
	}

	/**
	 * Close daemon-served TS rows whose uuid is no longer connected to the daemon.
	 *
	 * @param array<string,int> $rActiveSet Currently-connected uuids (flipped).
	 * @return void
	 */
	private function reconcile(array $rActiveSet): void {
		global $rServers;
		$rOffset = intval($rServers[SERVER_ID]['time_offset'] ?? 0);
		$rNow = time() - $rOffset;

		foreach ($this->daemonConnections() as $rConn) {
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
