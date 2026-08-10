<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * WatchdogCommand — watchdog command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../DaemonTrait.php';

class WatchdogCommand implements CommandInterface {
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

		global $db;

		echo "Start watchdog\n";
		$this->setProcessTitle('XC_VM[Watchdog]');
		$this->killStaleProcesses('console.php watchdog');
		$this->killStaleProcesses('XC_VM\\[Watchdog\\]');
		$this->initDaemonMD5();
		$this->initRedisIfEnabled();

		$this->rRefreshInterval = (intval(SettingsManager::getAll()['online_capacity_interval']) ?: 10);
		$rLastRequests = $rLastRequestsTime = $rPrevStat = null;
		$this->rLastCheck = null;

		$rServers = ServerRepository::getAll();
		$rWatchdog = json_decode($rServers[SERVER_ID]['watchdog_data'] ?? '{}', true) ?: [];
		$rCPUAverage = ($rWatchdog['cpu_average_array'] ?? []);

		while (true) {
			// Survive a MariaDB outage on the main: WAIT for the DB instead of
			// exiting. A respawned process dies in bootstrap while the DB is
			// down, which used to break the heartbeat chain on every node
			// simultaneously until cron:servers revived it a minute later.
			if (!$db || !$db->ping()) {
				$this->waitForDatabase($db);
				break; // respawn with a fresh process now that the DB is back
			}

			// The heartbeat (last_check_ago) is pure DB — a dead Redis must not
			// stop it, or every node goes "offline" in the panel whenever the
			// shared Redis blips. Degrade: skip Redis-dependent stats below.
			$rRedisAlive = $this->checkRedisHealth();
			if (!$rRedisAlive) {
				$this->attemptRedisRestart();
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
				$rServers = ServerRepository::getAll(true);
				SettingsManager::set(SettingsRepository::getAll(true));
				ConnectionTracker::getCapacity(true);
				ConnectionTracker::getCapacity(false);
				$this->rLastCheck = time();
				echo "Set new time LastCheck\n";
			}

			// ── Nginx stats ──────────────────────────────────────
			$rNginx = explode("\n", file_get_contents('http://127.0.0.1:' . $rServers[SERVER_ID]['http_broadcast_port'] . '/nginx_status'));
			list($rAccepted, $rHandled, $rRequests) = explode(' ', trim($rNginx[2]));
			$rRequestsPerSecond = ($rLastRequests ? intval((floatval($rRequests) - floatval($rLastRequests)) / (time() - $rLastRequestsTime)) : 0);
			$rLastRequests = $rRequests;
			$rLastRequestsTime = time();

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
			$rDiff = array();
			$rDiff['user'] = intval($rInfoB[0]) - intval($rInfoA[0]);
			$rDiff['nice'] = intval($rInfoB[1]) - intval($rInfoA[1]);
			$rDiff['sys'] = intval($rInfoB[2]) - intval($rInfoA[2]);
			$rDiff['idle'] = intval($rInfoB[3]) - intval($rInfoA[3]);
			$rTotal = array_sum($rDiff);
			$rCPU = array();
			foreach ($rDiff as $x => $y) {
				$rCPU[$x] = round($y / $rTotal * 100, 2);
			}
			$rStats['cpu'] = $rCPU['user'] + $rCPU['sys'];
			$rCPUAverage[] = $rStats['cpu'];
			if (30 < count($rCPUAverage)) {
				$rCPUAverage = array_slice($rCPUAverage, count($rCPUAverage) - 30, 30);
			}
			$rStats['cpu_average_array'] = $rCPUAverage;

			// ── PHP PIDs ─────────────────────────────────────────
			$rPHPPIDs = array();
			foreach (glob(MAIN_HOME . 'bin/php/sockets/*.pid') ?: array() as $rPidFile) {
				$rPid = trim(@file_get_contents($rPidFile) ?: '');
				if (is_numeric($rPid) && 0 < intval($rPid)) {
					$rPHPPIDs[] = intval($rPid);
				}
			}

			// ── Update servers table ─────────────────────────────
			$rConnections = $rUsers = 0;
			if (!SettingsManager::getAll()['redis_handler']) {
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
					if (SettingsManager::getAll()['redis_handler']) {
						// Redis down: keep the last known connections/users totals
						// for this round instead of overwriting them with garbage.
						if ($rRedisAlive) {
							try {
								$rMulti = RedisManager::instance()->multi();
								foreach (array_keys($rServers) as $rServerID) {
									if ($rServers[$rServerID]['server_online']) {
										$rMulti->zCard('SERVER#' . $rServerID);
										$rMulti->zRangeByScore('SERVER_LINES#' . $rServerID, '-inf', '+inf', array('withscores' => true));
									}
								}
								$rResults = $rMulti->exec();
								$rTotalUsers = array();
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
				$this->waitForDatabase($db);
			}
			break;
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
	 *
	 * @param object|null $db The global Database wrapper.
	 */
	private function waitForDatabase($db): void {
		if (!is_object($db)) {
			return;
		}
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

		if (RedisManager::reconnect()) {
			echo "Redis restarted successfully\n";
		} else {
			echo "Redis restart attempted, connection still unavailable\n";
		}
	}
}
