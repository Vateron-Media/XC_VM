<?php

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

		global $db;

		echo "Start watchdog\n";
		$this->setProcessTitle('XC_VM[Watchdog]');
		$this->killStaleProcesses('console.php watchdog');
		$this->initDaemonMD5();
		$this->initRedisIfEnabled();

		$this->rRefreshInterval = (intval(SettingsManager::getAll()['online_capacity_interval']) ?: 10);
		$rLastRequests = $rLastRequestsTime = $rPrevStat = null;
		$this->rLastCheck = null;

		$rServers = ServerRepository::getAll();
		$rWatchdog = json_decode($rServers[SERVER_ID]['watchdog_data'] ?? '{}', true) ?: [];
		$rCPUAverage = ($rWatchdog['cpu_average_array'] ?? []);

		while (true && $db && $db->ping()) {
			if (!$this->checkRedisHealth()) {
				break;
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
			$rRequestsPerSecond = ($rLastRequests ? intval(floatval($rRequests - $rLastRequests) / (time() - $rLastRequestsTime)) : 0);
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
			$rDiff['user'] = $rInfoB[0] - $rInfoA[0];
			$rDiff['nice'] = $rInfoB[1] - $rInfoA[1];
			$rDiff['sys'] = $rInfoB[2] - $rInfoA[2];
			$rDiff['idle'] = $rInfoB[3] - $rInfoA[3];
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
			exec("ps -u xc_vm | grep php-fpm | awk {'print \$1'}", $rPHPPIDs);

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
					} else {
						$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
						$rTotalUsers = $db->num_rows();
						$db->query('UPDATE `settings` SET `total_users` = ?;', $rTotalUsers);
					}
				}
				echo "Stats updated\n";
				sleep(2);
			} else {
				echo "Failed, break.\n";
			}
			break;
		}

		$this->restartDaemon('watchdog');
		return 0;
	}
}
