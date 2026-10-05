<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * OndemandCommand — ondemand command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class OndemandCommand implements CommandInterface {
	public function getName(): string {
		return 'ondemand';
	}

	public function getDescription(): string {
		return 'On-Demand Killer — kill streams with no viewers';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		set_time_limit(0);

		// Distinctive process title so singleton checks can actually find this
		// daemon. Without it the process runs under the generic 'XC_VM[Console]'
		// title (bootstrap.php), so both the self-dedupe below and
		// ServersCronJob's liveness check miss it and spawn a new
		// connection-holding daemon on every tick — exhausting max_connections.
		cli_set_process_title('XC_VM[Ondemand]');

		global $db;

		// Kill any OTHER running ondemand instance (dedupe). findProcessPIDs()
		// skips our own PID and matches both the retitled process and the raw
		// command line (covers the window before a sibling sets its title).
		foreach (ProcessManager::findProcessPIDs(['XC_VM[Ondemand]', 'console.php ondemand']) as $rOtherPID) {
			@posix_kill($rOtherPID, 9);
		}

		if (!SettingsManager::get('on_demand_instant_off')) {
			echo 'On-Demand - Instant Off setting is disabled.' . "\n";
			return 0;
		}

		// This node's streams from its replica and its own store (mode 1 or 2
		// with STREAMS on, NodeStreams); their viewers then from the agent's
		// registry with CONNECTIONS on, not MAIN's Redis.
		$rLocal = StreamSource::local();
		$rAgentViewers = $rLocal && AgentConnections::enabled();
		if (SettingsManager::get('redis_handler') && !$rAgentViewers) {
			RedisManager::ensureConnected();
		}

		$rMainID = ConnectionTracker::getMainID();
		$rLastCheck = null;
		$rInterval = 60;
		$rMD5 = md5_file(__FILE__);
		// Left when the node moves into or out of mode 2: this loop pings the
		// connection it opened, which would keep it open on MAIN for good.
		$rRefuses = NodeRole::refusesConnects();

		while (true) {
			if ($rRefuses !== NodeRole::refusesConnects() || !$db || !$db->ping() || (SettingsManager::get('redis_handler') && !$rAgentViewers && RedisManager::instance() && !RedisManager::instance()->ping())) {
				break;
			}

			$rCurentMD5Hash = md5_file(__FILE__);
			if (!$rLastCheck || time() - $rLastCheck > $rInterval || $rCurentMD5Hash !== $rMD5) {
				SettingsManager::set(SettingsRepository::getAll(true));
				// The kill below asks whether a pid is this stream's producer: a driver
				// module installed since this daemon started names an engine it must know.
				SourceDriverRegistry::reset();
				$rLastCheck = time();
				$rMD5 = $rCurentMD5Hash;
				$rLocal = StreamSource::local();
				$rAgentViewers = $rLocal && AgentConnections::enabled();
			}

			$rStreamIDs = NodeStreams::activeOnDemand($rLocal);
			if ($rStreamIDs === []) {
				usleep(800000);
				continue;
			}

			$rAttached = NodeStreams::attached($rStreamIDs, $rLocal);

			// Viewer counts come from Redis when enabled (per-server slice of the
			// stream's connection set), else from the lines_live table.
			if ($rAgentViewers) {
				$rOnline = NodeStreams::viewers(array_map('intval', $rStreamIDs));
			} elseif (SettingsManager::get('redis_handler') && RedisManager::instance()) {
				$rConnections = ConnectionTracker::getStreamConnections($rStreamIDs, false, false);
				$rOnline = [];
				foreach ($rStreamIDs as $rStreamID) {
					$rOnline[$rStreamID] = count($rConnections[$rStreamID][SERVER_ID] ?? []);
				}
			} else {
				$rOnline = ConnectionTracker::onlineClientCounts($rStreamIDs, SERVER_ID);
			}

			$rRows = [];
			foreach ($rStreamIDs as $rStreamID) {
				$rRows[] = [
					'stream_id' => $rStreamID,
					'online_clients' => $rOnline[$rStreamID] ?? 0,
					'attached' => $rAttached[$rStreamID] ?? 0
				];
			}

			foreach ($rRows as $rRow) {
				if ($rRow['online_clients'] > 0 || $rRow['attached'] > 0) {
					continue;
				}

				$rStreamID = $rRow['stream_id'];
				$pidFile = STREAMS_PATH . $rStreamID . '_.pid';
				$monitorFile = STREAMS_PATH . $rStreamID . '_.monitor';

				if (!file_exists($pidFile)) {
					continue;
				}

				$rPID = (int) @file_get_contents($pidFile);
				$rMonitorPID = file_exists($monitorFile) ? (int) @file_get_contents($monitorFile) : 0;

				$rQueue = 0;
				$queueFile = SIGNALS_TMP_PATH . 'queue_' . $rStreamID;
				if (file_exists($queueFile)) {
					$queue = @igbinary_unserialize(@file_get_contents($queueFile)) ?: [];
					foreach ($queue as $pid) {
						if (ProcessManager::isRunning($pid, 'php-fpm')) {
							$rQueue++;
						}
					}
				}

				$rAdminQueue = (file_exists(SIGNALS_TMP_PATH . 'admin_' . $rStreamID) && time() - @filemtime(SIGNALS_TMP_PATH . 'admin_' . $rStreamID) <= 30) ? 1 : 0;

				$rPidMtime = @filemtime($pidFile);
				$rStreamAge = ($rPidMtime === false) ? 0 : time() - $rPidMtime;

				if ($rQueue > 0 || $rAdminQueue > 0 || $rStreamAge < 30) {
					continue;
				}

				echo "Killing a stream without viewers: ID $rStreamID\n";

				// Release a supervised stream before touching its producer: killing
				// the producer first is what the fanout supervisor restarts.
				FanoutClient::release($rStreamID);
				FanoutClient::unregister($rStreamID);

				// A pid file outlives its process, and the number is handed to
				// another one: each is killed only while it still is this stream's.
				if (ProcessManager::isMonitorAlive($rMonitorPID, $rStreamID)) {
					@posix_kill($rMonitorPID, 9);
				}
				if (ProcessManager::isStreamRunning($rPID, (int) $rStreamID)) {
					@posix_kill($rPID, 9);
				}

				@shell_exec('rm -f ' . STREAMS_PATH . $rStreamID . '_*');
				@unlink($queueFile);
				@unlink(SIGNALS_TMP_PATH . 'admin_' . $rStreamID);

				StreamStateWriter::update(intval($rStreamID), intval(SERVER_ID), ['bitrate' => null, 'current_source' => null, 'to_analyze' => 0, 'pid' => null, 'stream_started' => null, 'stream_info' => null, 'audio_codec' => null, 'video_codec' => null, 'resolution' => null, 'compatible' => 0, 'stream_status' => 0, 'monitor_pid' => null], $db);

				// MAIN refreshes its cache from the node's events once its store keeps the streams.
				if (!$rLocal) {
					SignalDispatcher::cache(intval($rMainID), ['type' => 'update_stream', 'id' => $rStreamID], false, false, $db);
				}

				StreamProcess::updateStream($rStreamID);
			}

			usleep(800000);
		}

		if (is_object($db)) {
			$db->close_mysql();
		}
		shell_exec('(sleep 2; ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ondemand) > /dev/null 2>&1 &');

		return 0;
	}
}
