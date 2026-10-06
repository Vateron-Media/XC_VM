<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ConnectionLimits;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * SignalsCommand — signals command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SignalsCommand implements CommandInterface {
	use DaemonTrait;

	/** Seconds between syncs of supervised streams' rows from the fanout daemon. */
	public const RECONCILE_INTERVAL = 5;

	/** The loop's pace: four passes a second, as the re-execing one had. */
	private const PASS_USEC = 250000;

	public function getName(): string {
		return 'signals';
	}

	public function getDescription(): string {
		return 'Daemon: process kill signals and cache signals from DB/Redis';
	}

	/**
	 * Does the daemon read MAIN's database and Redis: its `signals` rows,
	 * the Redis signals, and the pings that keep it running? Not on a node
	 * in mode 2 (plan, section 10), whose connects are refused
	 * (NodeRole::refusesConnects): MAIN sends it kills as `conn.kill_worker`
	 * and `conn.drop` and cache jobs as `node.cache`, signed commands which
	 * its agent and cluster:exec run (CacheJobs). MAIN, mode 0 and mode 1
	 * read them as before.
	 */
	public static function readsMainDatabase(): bool {
		return !NodeRole::refusesConnects();
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!$this->acquireDaemonLock('signals')) {
			return 0;
		}

		global $db;

		$this->setProcessTitle('XC_VM[Signals]');
		$this->killStaleProcesses('console.php signals');
		$this->killStaleProcesses('XC_VM\\[Signals\\]');
		$this->initDaemonMD5();
		// A node in mode 2 has neither MAIN's database nor its Redis: its
		// kills and cache jobs come as commands, and a pass pings nothing.
		$rApi = !self::readsMainDatabase();
		if (!$rApi) {
			$this->initRedisIfEnabled();
		}

		$rServers = ServerRepository::getAll();
		$rLastReconcile = 0;
		$rLastLiveness = 0;
		$rIsMain = NodeRole::isMain();

		while ($rApi || ($db && $db->ping())) {
			if (!$this->refreshOrBreak()) {
				break;
			}
			// Was every pass (four times a second): a `SELECT * FROM servers`
			// against MAIN from every node, all day.
			if ($this->serversRefreshDue()) {
				$rServers = $this->refreshServers();
			}

			// Stop if Redis required but dead. checkRedisHealth() catches
			// RedisException (NOAUTH/timeouts during a restart window) — a raw
			// ping here used to kill the daemon with an uncaught exception.
			if (!$rApi && !$this->checkRedisHealth()) {
				break;
			}

			// Keep supervised streams' rows current: the fanout daemon runs their
			// producers but cannot write the database, and the minute cron alone
			// would leave a start showing "starting" (or a failure showing "up")
			// for up to a minute. One control-socket call when nothing changed.
			if (time() - $rLastReconcile >= self::RECONCILE_INTERVAL) {
				$rLastReconcile = time();
				StreamProcess::reconcileSupervised();
			}

			// MAIN's liveness loop for nodes whose agent reports telemetry:
			// once a second, and each transition rewrites the servers cache.
			if ($rIsMain && time() !== $rLastLiveness && SettingsManager::get('cluster_api_enabled')) {
				$rLastLiveness = time();
				try {
					if (LivenessService::tick(ClusterSettings::int('cluster_offline_after_sec', SettingsManager::get('cluster_offline_after_sec'))) !== []) {
						$rServers = $this->refreshServers();
					}
				} catch (\Throwable $rE) {
					echo 'Liveness: ' . $rE->getMessage() . "\n";
				}
				// max_connections for CONNECTIONS nodes' viewers, as they ask.
				try {
					ConnectionLimits::drain();
				} catch (\Throwable $rE) {
					echo 'Connection limits: ' . $rE->getMessage() . "\n";
				}
			}

			// Mode 2: no `signals` row and no Redis signal to read; its kills
			// arrive as commands (the loop above).
			if ($rApi) {
				usleep(self::PASS_USEC);
				continue;
			}

			// ── Kill-сигналы из БД ──────────────────────────────
			if ($db->query('SELECT `signal_id`, `pid`, `rtmp` FROM `signals` WHERE `server_id` = ? AND `pid` IS NOT NULL ORDER BY `signal_id` ASC LIMIT 100', SERVER_ID)) {
				if ($db->num_rows() > 0) {
					$rIDs = [];
					foreach ($db->get_rows() as $rRow) {
						$rIDs[] = $rRow['signal_id'];
						$rPID = $rRow['pid'];
						if ($rRow['rtmp'] == 0) {
							if (!empty($rPID) && file_exists('/proc/' . $rPID) && is_numeric($rPID) && 0 < $rPID) {
								shell_exec('kill -9 ' . intval($rPID));
							}
						} else {
							shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rPID) . '" >/dev/null 2>/dev/null &');
						}
					}
					if (count($rIDs) > 0) {
						$db->query('DELETE FROM `signals` WHERE `signal_id` IN (' . implode(',', $rIDs) . ')');
					}
				}

				// ── Cache-сигналы из БД ─────────────────────────
				if ($db->query('SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 ORDER BY `signal_id` ASC LIMIT 1000;', SERVER_ID)) {
					if ($db->num_rows() > 0) {
						$rJobs = $rIDs = [];
						foreach ($db->get_rows() as $rRow) {
							$rJobs[] = json_decode($rRow['custom_data'], true);
							$rIDs[] = $rRow['signal_id'];
						}
						CacheJobs::run($rJobs);
						if (count($rIDs) > 0) {
							$db->query('DELETE FROM `signals` WHERE `signal_id` IN (' . implode(',', $rIDs) . ')');
						}
					}

					// ── Redis kill-сигналы ──────────────────────
					if (SettingsManager::get('redis_handler')) {
						// A read can drop mid-pass (a node reaches MAIN's Redis over the
						// WAN): restart as a failed health check does, not die uncaught.
						// Unremoved signals stay in the set for the next generation.
						try {
							$rSignals = [];
							foreach (RedisManager::instance()->sMembers('SIGNALS#' . SERVER_ID) as $rKey) {
								$rSignals[] = $rKey;
							}
							if (count($rSignals) > 0) {
								$rSignalData = RedisManager::instance()->mGet($rSignals);
								$rIDs = [];
								foreach ($rSignalData as $rData) {
									if (!is_string($rData)) {
										continue; // expired unread (ConnectionTracker::SIGNAL_TTL)
									}
									$rRow = igbinary_unserialize($rData);
									$rIDs[] = $rRow['key'];
									$rPID = $rRow['pid'];
									if (is_array($rRow['custom_data'] ?? null) && ($rRow['custom_data']['type'] ?? '') === 'drop_con') {
										FanoutClient::dropConnection((string) ($rRow['custom_data']['uuid'] ?? ''));
									} elseif ($rRow['rtmp'] == 0) {
										if (!empty($rPID) && file_exists('/proc/' . $rPID) && is_numeric($rPID) && 0 < $rPID) {
											shell_exec('kill -9 ' . intval($rPID));
										}
									} else {
										shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rPID) . '" >/dev/null 2>/dev/null &');
									}
								}
								RedisManager::instance()->multi()->del($rIDs)->sRem('SIGNALS#' . SERVER_ID, ...$rSignals)->exec();
							}
						} catch (\RedisException $e) {
							echo 'Redis signals: ' . $e->getMessage() . "\n";
							break;
						}
					}
				}
			}

			usleep(self::PASS_USEC);
		}

		$this->restartDaemon('signals');
		return 0;
	}
}
