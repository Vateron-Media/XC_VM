<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\Commands\UpdateCommand;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Cluster\RelaySeal;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\AtomicFile;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Domain\Server\ServerRepository;

/**
 * ServersCronJob — servers cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServersCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:servers';
	}

	public function getDescription(): string {
		return 'Cron: monitor server, launch daemons, update statistics';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Servers]');
		$this->loadCron();

		return 0;
	}

	private function pingServer(string $rIP, $rPort): int {
		$rStartTime = microtime(true);
		$rSocket = @fsockopen($rIP, $rPort, $rErrNo, $rErrStr, 3);
		$rStopTime = microtime(true);
		if (!$rSocket) {
			return -1;
		}
		fclose($rSocket);
		return (int) floor(($rStopTime - $rStartTime) * 1000);
	}

	private function loadCron(): void {
		global $db;

		SettingsManager::set(SettingsRepository::getAll(true));

		if (!ProcessManager::isNginxRunning()) {
			echo 'XC_VM not running...' . "\n";
			return;
		}

		$rServers = ServerRepository::getAll(true);

		// The current server must be present in the map; if it isn't (row not
		// yet inserted, or a transient load failure) every $rServers[SERVER_ID]
		// access below would raise offset-on-null warnings and do nothing useful.
		if (!isset($rServers[SERVER_ID])) {
			echo 'Server ' . SERVER_ID . ' not found in servers list...' . "\n";
			return;
		}

		// The shared Redis only: the cluster bus runs the same binary on a unix
		// socket ("redis-server unixsocket:…"), and must not pass for it.
		if ($rServers[SERVER_ID]['is_main'] && SettingsManager::get('redis_handler')) {
			if (!ProcessManager::isAnyProcessRunning(['redis-server *:', ':6379', 'bin/redis/redis.conf'])) {
				echo 'Restarting Redis!' . "\n";
				shell_exec(MAIN_HOME . 'bin/redis/redis-server ' . MAIN_HOME . '/bin/redis/redis.conf > /dev/null 2>/dev/null &');
			}
		}
		if ($rServers[SERVER_ID]['is_main'] && class_exists(ClusterBus::class) && !ClusterBus::running()) {
			echo 'Starting the cluster bus' . "\n";
			ClusterBus::ensureRunning();
		}
		// The cluster API's FPM pools: revived, and resized as servers come and go.
		if ($rServers[SERVER_ID]['is_main'] && class_exists(ClusterPool::class)) {
			ClusterPool::ensure(3.0);
		}
		// The load balancers MAIN's last update could not tell (offline then):
		// each is told once, when it is back and still on an older release.
		if ($rServers[SERVER_ID]['is_main'] && is_file(UpdateCommand::PENDING)) {
			$rPending = (array) json_decode((string) @file_get_contents(UpdateCommand::PENDING), true);
			$rDue = SettingsManager::get('auto_update_lbs') ? UpdateCommand::lbsToTell($rServers, $rPending, time()) : ['tell' => [], 'wait' => []];
			foreach ($rDue['tell'] as $rID) {
				NodeActions::update($rID, $db);
			}
			if ($rDue['wait'] === []) {
				@unlink(UpdateCommand::PENDING);
			} elseif ($rDue['wait'] !== $rPending) {
				AtomicFile::write(UpdateCommand::PENDING, (string) json_encode($rDue['wait']));
			}
		}

		// Daemon liveness checks read /proc via ProcessManager: the old
		// "ps | grep <word>" pipelines matched unrelated processes — e.g.
		// ffmpeg's -thread_queue_size satisfied the "queue" check, so the
		// encode queue daemon was never revived while any stream was up.
		if (!ProcessManager::isAnyProcessRunning(['XC_VM[Signals]', 'console.php signals'])) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php signals > /dev/null 2>/dev/null &');
		}

		if ($rServers[SERVER_ID]['is_main']) {
			$rCachePIDs = ProcessManager::findProcessPIDs(['XC_VM[CacheHandler]', 'console.php cache_handler']);
			if (SettingsManager::get('enable_cache') && count($rCachePIDs) == 0) {
				shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cache_handler > /dev/null 2>/dev/null &');
			} elseif (!SettingsManager::get('enable_cache') && count($rCachePIDs) > 0) {
				echo 'Killing Cache Handler' . "\n";
				foreach ($rCachePIDs as $rPID) {
					shell_exec('kill -9 ' . intval($rPID));
				}
			}
		}

		// With the TELEMETRY flow the node's agent samples the network (and the
		// rest); network.py and this cron's stats row stand down.
		$rAgentTelemetry = NodeFlows::on(NodeFlows::TELEMETRY);
		if ($rAgentTelemetry) {
			foreach (ProcessManager::findProcessPIDs([BIN_PATH . 'network']) as $rPID) {
				ProcessManager::kill($rPID);
			}
		} elseif (!ProcessManager::isAnyProcessRunning([BIN_PATH . 'network'])) {
			shell_exec(BIN_PATH . 'network > /dev/null 2>/dev/null &');
		}

		// A watchdog generation lives only a few seconds. If one is still
		// present but far older it is wedged — typically blocked in poll()
		// on a half-open MariaDB socket (CLOSE_WAIT) inside its DB ping — and
		// will never refresh last_check_ago, so the panel marks the node
		// offline while nginx/php/redis/streams are all fine. Kill the stale
		// process and start a fresh generation; a plain presence check alone
		// would keep trusting the wedged one forever.
		$rWatchdogAlive = false;
		foreach (ProcessManager::findProcessPIDs(['XC_VM[Watchdog]', 'console.php watchdog']) as $rWatchdogPID) {
			if (ProcessManager::getProcessAge($rWatchdogPID) > 90) {
				ProcessManager::kill($rWatchdogPID);
				continue;
			}
			$rWatchdogAlive = true;
		}
		if (!$rWatchdogAlive) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php watchdog > /dev/null 2>/dev/null &');
		}

		if (!ProcessManager::isAnyProcessRunning(['XC_VM[Queue]', 'console.php queue'])) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php queue > /dev/null 2>/dev/null &');
		}

		$rOnDemandPIDs = ProcessManager::findProcessPIDs(['XC_VM[Ondemand]', 'console.php ondemand']);
		if (SettingsManager::get('on_demand_instant_off') && count($rOnDemandPIDs) == 0) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php ondemand > /dev/null 2>/dev/null &');
		} elseif (!SettingsManager::get('on_demand_instant_off') && count($rOnDemandPIDs) > 0) {
			echo 'Killing On-Demand Instant-Off' . "\n";
			foreach ($rOnDemandPIDs as $rPID) {
				shell_exec('kill -9 ' . intval($rPID));
			}
		}

		$rScannerPIDs = ProcessManager::findProcessPIDs(['XC_VM[Scanner]', 'console.php scanner']);
		if (SettingsManager::get('on_demand_checker') && count($rScannerPIDs) == 0) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php scanner > /dev/null 2>/dev/null &');
		} elseif (!SettingsManager::get('on_demand_checker') && count($rScannerPIDs) > 0) {
			echo 'Killing On-Demand Scanner' . "\n";
			foreach ($rScannerPIDs as $rPID) {
				shell_exec('kill -9 ' . intval($rPID));
			}
		}

		$rStats = SystemInfo::getStats();
		$rWatchdog = json_decode($rServers[SERVER_ID]['watchdog_data'] ?? '', true);
		$rCPUAverage = ($rWatchdog['cpu_average_array'] ?? []) ?: [];
		if (count($rCPUAverage) > 0) {
			$rStats['cpu'] = round(array_sum($rCPUAverage) / count($rCPUAverage), 2);
		}

		$rHardware = ['total_ram' => $rStats['total_mem'], 'total_used' => $rStats['total_mem_used'], 'cores' => $rStats['cpu_cores'], 'threads' => $rStats['cpu_cores'], 'kernel' => $rStats['kernel'], 'total_running_streams' => $rStats['total_running_streams'], 'cpu_name' => $rStats['cpu_name'], 'cpu_usage' => $rStats['cpu'], 'network_speed' => $rStats['network_speed'], 'bytes_sent' => $rStats['bytes_sent'], 'bytes_received' => $rStats['bytes_received']];
		// Lets server:diagnose tell whether this node reads MAIN's tokens (never the value itself).
		$rHardware = OpensslExtra::publish($rHardware);

		if (@fsockopen($rServers[SERVER_ID]['server_ip'], $rServers[SERVER_ID]['http_broadcast_port'], $rErrNo, $rErrStr, 3) || @fsockopen($rServers[SERVER_ID]['server_ip'], $rServers[SERVER_ID]['https_broadcast_port'], $rErrNo, $rErrStr, 3)) {
			$rRemoteStatus = true;
		} else {
			$rRemoteStatus = false;
		}

		// The counts feed the servers_stats row alone, which the agent's
		// telemetry replaces: with TELEMETRY on they are not taken (a node in
		// mode 2 has no MAIN database to count them in, and the inventory
		// below would never go).
		$rConnections = $rUsers = $rAllUsers = $rStreams = 0;
		if (!$rAgentTelemetry && SettingsManager::get('redis_handler')) {
			$rConnections = $rServers[SERVER_ID]['connections'];
			$rUsers = $rServers[SERVER_ID]['users'];
			$rAllUsers = 0;
			foreach (array_keys($rServers) as $rServerID) {
				if ($rServers[$rServerID]['server_online']) {
					$rAllUsers += $rServers[$rServerID]['users'];
				}
			}
		} elseif (!$rAgentTelemetry) {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', SERVER_ID);
			$rConnections = intval($db->get_row()['count']);
			$db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', SERVER_ID);
			$rUsers = intval($db->num_rows());
			$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
			$rAllUsers = intval($db->num_rows());
		}
		if (!$rAgentTelemetry) {
			$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', SERVER_ID);
			$rStreams = intval($db->get_row()['count']);
		}

		$rPing = 0;
		if (!$rServers[SERVER_ID]['is_main']) {
			$rMainID = null;
			foreach ($rServers as $rServerID => $rServerArray) {
				if ($rServerArray['is_main']) {
					$rMainID = $rServerID;
					break;
				}
			}
			if ($rMainID) {
				$rPing = $this->pingServer($rServers[$rMainID]['server_ip'], $rServers[$rMainID]['http_broadcast_port']);
			}
		}

		$rSysCtl = file_get_contents('/etc/sysctl.conf');
		$rGovernors = [];
		if (shell_exec('which cpufreq-info')) {
			$rGovernors = array_filter(explode(' ', trim(shell_exec('cpufreq-info -g') ?? '')));
		}

		$rAddresses = array_values(array_unique(array_map('trim', explode("\n", shell_exec("ip -4 addr | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'")))));

		if (!$rAgentTelemetry) {
			$db->query('INSERT INTO `servers_stats`(`server_id`, `connections`, `total_users`, `users`, `streams`, `cpu`, `cpu_cores`, `cpu_avg`, `total_mem`, `total_mem_free`, `total_mem_used`, `total_mem_used_percent`, `total_disk_space`, `uptime`, `total_running_streams`, `bytes_sent`, `bytes_received`, `bytes_sent_total`, `bytes_received_total`, `cpu_load_average`, `gpu_info`, `iostat_info`, `time`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP());', SERVER_ID, $rConnections, $rAllUsers, $rUsers, $rStreams, $rStats['cpu'], $rStats['cpu_cores'], $rStats['cpu_avg'], $rStats['total_mem'], $rStats['total_mem_free'], $rStats['total_mem_used'], $rStats['total_mem_used_percent'], $rStats['total_disk_space'], $rStats['uptime'], $rStats['total_running_streams'], $rStats['bytes_sent'], $rStats['bytes_received'], $rStats['bytes_sent_total'], $rStats['bytes_received_total'], $rStats['cpu_load_average'], json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE), json_encode($rStats['iostat_info'], JSON_UNESCAPED_UNICODE));
		}

		// `whitelist_ips` grants the /api allowed IPs (ServerRepository), so a
		// node's own `ip -4 addr` is not evidence for it: a node that declared an
		// address would be granting it. MAIN keeps publishing its own — it is the
		// panel, already trusted, and a multi-homed MAIN calls each node from
		// whichever address the route picks. Elsewhere the column is the admin's,
		// as it already is for proxies (ProxyIdentity) and for the API path, which
		// never carried it.
		$rWhitelist = $rServers[SERVER_ID]['is_main']
			? json_encode($rAddresses, JSON_UNESCAPED_UNICODE)
			: ($rServers[SERVER_ID]['whitelist_ips'] ?? null);

		// With TELEMETRY on, the inventory goes to MAIN as an event; the node's
		// addresses do not (whitelist_ips feeds the allowed IPs, MAIN's to set).
		// relay_seal: this server opens a relay's session key, so its children pull from it sealed (RelaySeal, D11).
		$rRelaySeal = RelaySeal::supported() ? 1 : 0;
		$rInventory = ['relay_seal' => $rRelaySeal, 'remote_status' => $rRemoteStatus ? 1 : 0, 'xc_vm_version' => XC_VM_VERSION, 'server_hardware' => json_encode($rHardware, JSON_UNESCAPED_UNICODE), 'governors' => json_encode($rGovernors, JSON_UNESCAPED_UNICODE), 'sysctl' => $rSysCtl, 'video_devices' => json_encode($rStats['video_devices'], JSON_UNESCAPED_UNICODE), 'audio_devices' => json_encode($rStats['audio_devices'], JSON_UNESCAPED_UNICODE), 'gpu_info' => json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE), 'interfaces' => json_encode($rStats['interfaces'], JSON_UNESCAPED_UNICODE), 'ping' => $rPing];
		// A node in mode 2 never writes MAIN's row: an inventory the spool did not take goes next minute.
		if (!NodeStateSink::inventory($rInventory) && !NodeRole::refusesConnects()) {
			$db->query('UPDATE `servers` SET `remote_status` = ?, `xc_vm_version` = ?, `server_hardware` = ?,`whitelist_ips` = ?, `governors` = ?, `sysctl` = ?, `video_devices` = ?, `audio_devices` = ?, `gpu_info` = ?, `interfaces` = ?, `time_offset` = ' . intval(time()) . ' - UNIX_TIMESTAMP(), `ping` = ?, `relay_seal` = ? WHERE `id` = ?', $rRemoteStatus, XC_VM_VERSION, json_encode($rHardware, JSON_UNESCAPED_UNICODE), $rWhitelist, json_encode($rGovernors, JSON_UNESCAPED_UNICODE), $rSysCtl, json_encode($rStats['video_devices'], JSON_UNESCAPED_UNICODE), json_encode($rStats['audio_devices'], JSON_UNESCAPED_UNICODE), json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE), json_encode($rStats['interfaces'], JSON_UNESCAPED_UNICODE), $rPing, $rRelaySeal, SERVER_ID);
		}

		if ($rServers[SERVER_ID]['is_main']) {
			foreach ($rServers as $rServerID => $rServerArray) {
				if ($rServerArray['server_online'] != $rServerArray['last_status']) {
					$db->query('UPDATE `servers` SET `last_status` = ? WHERE `id` = ?;', $rServerArray['server_online'], $rServerID);
				}
			}
			$db->query('DELETE FROM `signals` WHERE `time` <= ?;', time() - 86400);
		}
	}
}
