<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\LocalTelemetry;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\CounterRateSampler;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * WatchdogCommand — watchdog command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchdogCommand implements CommandInterface {
	use DatabaseAware;
	use DaemonTrait;

	public function getName(): string {
		return 'watchdog';
	}

	public function getDescription(): string {
		return 'Daemon: system monitoring, CPU, connections, update servers';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!$this->acquireDaemonLock('watchdog')) {
			return 0;
		}

		$db = self::db();
		// A node in mode 2 has neither MAIN's database nor its Redis (plan,
		// section 10): nothing to ping or wait for, and its agent reports.
		$rApi = NodeRole::refusesConnects();

		echo "Start watchdog\n";
		$this->setProcessTitle('XC_VM[Watchdog]');
		$this->killStaleProcesses('console.php watchdog');
		$this->killStaleProcesses('XC_VM\\[Watchdog\\]');
		$this->initDaemonMD5();
		if (!$rApi) {
			$this->initRedisIfEnabled();
		}

		$this->rRefreshInterval = (intval(SettingsManager::get('online_capacity_interval')) ?: 10);
		$rPrevStat = null;
		$this->rLastCheck = null;

		$rServers = ServerRepository::getAll();
		$rWatchdog = json_decode($rServers[SERVER_ID]['watchdog_data'] ?? '{}', true) ?: [];
		$rCPUAverage = ($rWatchdog['cpu_average_array'] ?? []);

		while (true) {
			// Mode 2 is decided at the start: a generation that began on the
			// other side of the move ends here, and the next one starts right.
			if ($rApi !== NodeRole::refusesConnects()) {
				echo "Mode changed! Break.\n";
				break;
			}

			// Survive a MariaDB outage on the main: WAIT for the DB instead of
			// exiting. A respawned process dies in bootstrap while the DB is
			// down, which used to break the heartbeat chain on every node
			// simultaneously until cron:servers revived it a minute later.
			if (!$rApi && !$db->ping()) {
				$this->waitForDatabase();
				break; // respawn with a fresh process now that the DB is back
			}

			// The heartbeat (last_check_ago) is pure DB — a dead Redis must not
			// stop it, or every node goes "offline" in the panel whenever the
			// shared Redis blips. Degrade: skip Redis-dependent stats below.
			$rRedisAlive = $rApi || $this->checkRedisHealth();
			if (!$rRedisAlive) {
				$this->attemptRedisRestart();
			}

			if ($this->serversRefreshDue()) {
				$rServers = $this->refreshServers();
			}

			if ($this->shouldRefreshSettings()) {
				if (!ProcessManager::isNginxRunning()) {
					echo "Not running! Break.\n";
					break;
				}
				if ($this->hasFileChanged()) {
					echo "File changed! Break.\n";
					break;
				}
				SettingsManager::set(SettingsRepository::getAll(true));
				// The capacities count MAIN's connections (its Redis or database).
				if (!$rApi) {
					ConnectionTracker::getCapacity(true);
					ConnectionTracker::getCapacity(false);
				}
				$this->rLastCheck = time();
				echo "Set new time LastCheck\n";
			}

			// ── Nginx stats ──────────────────────────────────────
			// The count is kept in a state file rather than a variable, so a
			// rate is still right across a restart (a code change, an nginx
			// reload) as well as across a pass.
			$rRequests = SystemInfo::nginxRequestCount((string) @file_get_contents('http://127.0.0.1:' . $rServers[SERVER_ID]['http_broadcast_port'] . '/nginx_status'));
			$rRequestsPerSecond = ($rRequests === null ? 0 : (new CounterRateSampler(TMP_PATH . 'watchdog_nginx_requests.json'))->sample($rRequests, time()));

			// ── TELEMETRY flow: the node's agent reports to MAIN ──
			// MAIN writes this server's row from the agent's heartbeats; here
			// only what PHP alone knows is sampled, for the agent to forward.
			// Mode 2 never writes the row itself, whatever its flows.
			if (NodeFlows::on(NodeFlows::TELEMETRY) || $rApi) {
				self::writeLocalTelemetry($rRequestsPerSecond);
				sleep(2);
				// The next pass, in this process: a `break` here ended the
				// generation after every pass, and restartDaemon() booted a new
				// PHP process every 2 s on every node with TELEMETRY.
				continue;
			}

			// ── CPU stats ────────────────────────────────────────
			$rStats = SystemInfo::getStats();
			if (!$rPrevStat) {
				$rPrevStat = file('/proc/stat');
				sleep(2);
			}
			$rStat = file('/proc/stat');
			$rInfoA = explode(' ', preg_replace('!cpu +!', '', $rPrevStat[0]));
			$rInfoB = explode(' ', preg_replace('!cpu +!', '', $rStat[0]));
			$rPrevStat = $rStat;
			$rDiff = [];
			$rDiff['user'] = intval($rInfoB[0]) - intval($rInfoA[0]);
			$rDiff['nice'] = intval($rInfoB[1]) - intval($rInfoA[1]);
			$rDiff['sys'] = intval($rInfoB[2]) - intval($rInfoA[2]);
			$rDiff['idle'] = intval($rInfoB[3]) - intval($rInfoA[3]);
			$rTotal = array_sum($rDiff);
			$rCPU = [];
			foreach ($rDiff as $x => $y) {
				$rCPU[$x] = round($y / $rTotal * 100, 2);
			}
			$rStats['cpu'] = $rCPU['user'] + $rCPU['sys'];
			$rCPUAverage[] = $rStats['cpu'];
			if (30 < count($rCPUAverage)) {
				$rCPUAverage = array_slice($rCPUAverage, count($rCPUAverage) - 30, 30);
			}
			$rStats['cpu_average_array'] = $rCPUAverage;

			// ── xc_fanout daemon health (admin "Service Status" panel) ──
			$rStats['fanout'] = FanoutClient::status();

			// ── PHP PIDs ─────────────────────────────────────────
			// FPM worker pids, which connection rows record; the pool pid
			// files hold only the masters. null (unknown) is stored as "null".
			$rPHPPIDs = ProcessManager::phpFpmWorkerPIDs();

			// ── Update servers table ─────────────────────────────
			$rConnections = $rUsers = 0;
			if (!SettingsManager::get('redis_handler')) {
				$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ?;', SERVER_ID);
				$rConnections = $db->get_row()['count'];
				$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ? GROUP BY `user_id`;', SERVER_ID);
				$rUsers = $db->num_rows();
				$rResult = $db->query('UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = UNIX_TIMESTAMP(), `requests_per_second` = ?, `php_pids` = ?, `connections` = ?, `users` = ? WHERE `id` = ?;', json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rRequestsPerSecond, json_encode($rPHPPIDs), $rConnections, $rUsers, SERVER_ID);
			} else {
				$rResult = $db->query('UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = UNIX_TIMESTAMP(), `requests_per_second` = ?, `php_pids` = ? WHERE `id` = ?;', json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rRequestsPerSecond, json_encode($rPHPPIDs), SERVER_ID);
			}

			if ($rResult) {
				if ($rServers[SERVER_ID]['is_main']) {
					if (SettingsManager::get('redis_handler')) {
						// Redis down: keep the last known connections/users totals
						// for this round instead of overwriting them with garbage.
						if ($rRedisAlive) {
							try {
								$rMulti = RedisManager::instance()->multi();
								foreach (array_keys($rServers) as $rServerID) {
									if ($rServers[$rServerID]['server_online']) {
										$rMulti->zCard('SERVER#' . $rServerID);
										$rMulti->zRangeByScore('SERVER_LINES#' . $rServerID, '-inf', '+inf', ['withscores' => true]);
									}
								}
								$rResults = $rMulti->exec();
								$rTotalUsers = [];
								$i = 0;
								foreach (array_keys($rServers) as $rServerID) {
									if ($rServers[$rServerID]['server_online']) {
										$db->query('UPDATE `servers` SET `connections` = ?, `users` = ? WHERE `id` = ?;', $rResults[$i * 2], count(array_unique(array_values($rResults[$i * 2 + 1]))), $rServerID);
										$rTotalUsers = array_merge(array_values($rResults[$i * 2 + 1]), $rTotalUsers);
										$i++;
									}
								}
								$db->query('UPDATE `settings` SET `total_users` = ?;', count(array_unique($rTotalUsers)));
							} catch (\RedisException $e) {
								echo 'Redis connection lost: ' . $e->getMessage() . "\n";
							}
						}
					} else {
						$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
						$rTotalUsers = $db->num_rows();
						$db->query('UPDATE `settings` SET `total_users` = ?;', $rTotalUsers);
					}
				}
				echo "Stats updated\n";
				sleep(2);
			} else {
				echo "DB write failed - waiting for database...\n";
				$this->waitForDatabase();
			}
		}

		$this->restartDaemon('watchdog');
		return 0;
	}

	/**
	 * Block until the panel database accepts connections again.
	 *
	 * db_connect(false, true) reconnects to the MAIN panel DB and returns false
	 * instead of exit()ing on failure, so the watchdog can sit out a MariaDB
	 * restart/outage on the main and resume the heartbeat the moment the DB is
	 * back, instead of dying and leaving the node "offline" until cron:servers
	 * revives it. (migrate=true would probe `xc_vm_migrate` and, worse, leave the
	 * global $db pointing at it.)
	 */
	private function waitForDatabase(): void {
		$db = self::db();
		echo "Database unavailable - waiting for it to come back...\n";
		$rAttempt = 0;
		while (!$db->db_connect(false, true)) {
			$rAttempt++;
			if ($rAttempt % 12 === 0) {
				echo 'Still waiting for the database (' . ($rAttempt * 5) . "s)...\n";
			}
			sleep(5);
		}
		echo "Database is back - resuming.\n";
	}

	/**
	 * Restart the local Redis (KeyDB) when the health check fails and the
	 * process is actually dead. In Redis mode every playback request dies
	 * with LINE_CREATE_FAIL until Redis is back, so waiting for a human is
	 * not an option.
	 *
	 * No-op on LB nodes (bin/redis is stripped from the LB build — Redis
	 * lives on MAIN). A live-but-unreachable process (auth/config drift)
	 * is left alone: a restart would not fix it. Attempts are rate-limited
	 * to one per 60 seconds across watchdog respawns via a stamp file.
	 */
	private function attemptRedisRestart(): void {
		$rBinary = MAIN_HOME . 'bin/redis/redis-server';
		if (!file_exists($rBinary)) {
			return;
		}

		$rPid = intval(trim(@file_get_contents(MAIN_HOME . 'bin/redis/redis-server.pid') ?: ''));
		if (0 < $rPid && ProcessManager::isRunning($rPid)) {
			echo "Redis process is alive but unreachable, not restarting\n";
			return;
		}

		$rStamp = TMP_PATH . 'redis_restart_attempt';
		if (file_exists($rStamp) && 60 > time() - intval(@file_get_contents($rStamp) ?: '0')) {
			return;
		}
		file_put_contents($rStamp, time());

		echo "Redis process is dead, restarting\n";
		exec(escapeshellarg($rBinary) . ' ' . escapeshellarg(MAIN_HOME . 'bin/redis/redis.conf') . ' >/dev/null 2>/dev/null');
		sleep(1);

		if (RedisManager::reconnect() instanceof \Redis) {
			echo "Redis restarted successfully\n";
		} else {
			echo "Redis restart attempted, connection still unavailable\n";
		}
	}

	/**
	 * `config/cluster/local.json`, beside the agent's state: what the agent
	 * cannot sample itself. It forwards the file while it is under 10 s old
	 * and at most 64 KiB (Core\Cluster\LocalTelemetry). The devices, GPUs and
	 * disk I/O are probed as getStats() probes them, at most every 30 s, each
	 * tool for at most 5 s, and a due probe runs after the file is written.
	 */
	public static function writeLocalTelemetry(int|float $rRequestsPerSecond): void {
		$rDir = CONFIG_PATH . 'cluster/';
		if (!is_dir($rDir)) {
			return;
		}
		LocalTelemetry::refresh($rDir, TMP_PATH . 'watchdog_devices.json', time(), ['requests_per_second' => (int) $rRequestsPerSecond, 'fanout' => FanoutClient::status()], static fn(): array => SystemInfo::getDevices(LocalTelemetry::PROBE_TIMEOUT));
	}
}
