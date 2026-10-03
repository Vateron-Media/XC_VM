<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\DivergenceSink;
use XcVm\Core\Cluster\HlsReaping;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * UsersCronJob — users cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UsersCronJob implements CommandInterface {
	use CronTrait;

	private $rPHPPIDs = [];

	private $rServers = [];

	public function getName(): string {
		return 'cron:users';
	}

	public function getDescription(): string {
		return 'Cron: manage user connections, Redis sync, divergence';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		set_time_limit(0);
		ini_set('memory_limit', -1);

		$this->setProcessTitle('XC_VM[Users]');
		$this->acquireCronLock();

		global $db;

		$rSync = null;
		$this->rServers = ServerRepository::getAll();

		if (!empty($rArgs[0]) && $this->rServers[SERVER_ID]['is_main']) {
			// Just enabled (the Cache page): the watchdog starts Redis within seconds.
			for ($i = 0; $i < 30 && !RedisManager::ensureConnected(); $i++) {
				sleep(1);
			}

			if (RedisManager::isConnected()) {
				$rSync = intval($rArgs[0]);

				if ($rSync == 1) {
					$rDeSync = $rRedisUsers = $rRedisUpdate = $rRedisSet = [];
					$db->query('SELECT * FROM `lines_live` WHERE `hls_end` = 0;');
					$rRows = $db->get_rows();

					if (count($rRows) > 0) {
						$rStreamIDs = [];

						foreach ($rRows as $rRow) {
							$streamId = (int) $rRow['stream_id'];
							if ($streamId > 0 && !in_array($streamId, $rStreamIDs)) {
								$rStreamIDs[] = $streamId;
							}
						}

						$rOnDemand = [];
						if (count($rStreamIDs) > 0) {
							$db->query('SELECT `stream_id`, `server_id`, `on_demand` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');
							foreach ($db->get_rows() as $rRow) {
								$rOnDemand[$rRow['stream_id']][$rRow['server_id']] = intval($rRow['on_demand']);
							}
						}

						$rRedis = RedisManager::instance()->multi();

						foreach ($rRows as $rRow) {
							echo 'Resynchronising UUID: ' . $rRow['uuid'] . "\n";

							$rRow['identity'] = StoredConnections::identity($rRow);

							$rRow['on_demand'] = ($rOnDemand[$rRow['stream_id']][$rRow['server_id']]);
							$rRedis->zAdd('LINE#' . $rRow['identity'], $rRow['date_start'], $rRow['uuid']);
							$rRedis->zAdd('LINE_ALL#' . $rRow['identity'], $rRow['date_start'], $rRow['uuid']);
							$rRedis->zAdd('STREAM#' . $rRow['stream_id'], $rRow['date_start'], $rRow['uuid']);
							$rRedis->zAdd('SERVER#' . $rRow['server_id'], $rRow['date_start'], $rRow['uuid']);

							if ($rRow['user_id']) {
								$rRedis->zAdd('SERVER_LINES#' . $rRow['server_id'], $rRow['user_id'], $rRow['uuid']);
							}

							if ($rRow['proxy_id']) {
								$rRedis->zAdd('PROXY#' . $rRow['proxy_id'], $rRow['date_start'], $rRow['uuid']);
							}

							$rRedis->zAdd('CONNECTIONS', $rRow['date_start'], $rRow['uuid']);
							$rRedis->zAdd('LIVE', $rRow['date_start'], $rRow['uuid']);
							$rRedis->set($rRow['uuid'], igbinary_serialize($rRow));
							$rDeSync[] = $rRow['uuid'];
						}
						$rRedis->exec();

						if (count($rDeSync) > 0) {
							$db->query("DELETE FROM `lines_live` WHERE `uuid` IN ('" . implode("','", $rDeSync) . "');");
						}
					}
				}
			} else {
				echo "Couldn't connect to Redis.\n";
				return 1;
			}
		}

		if (SettingsManager::getBool('redis_handler') && $this->rServers[SERVER_ID]['is_main']) {
			$this->rServers = ServerRepository::getAll(true);
			$this->rPHPPIDs = $this->loadPHPPIDs();
		}

		ConnectionTracker::holdActivity();
		try {
			$this->loadCron();
			ConnectionTracker::releaseActivity();
		} finally {
			ConnectionTracker::holdActivity(false); // what a failed sweep still holds was not removed: dropped
		}

		return 0;
	}

	/**
	 * Every node's live PHP-FPM worker pids, as its watchdog last published
	 * them. Read here because ServerRepository::getAll() leaves php_pids out.
	 *
	 * @return array<int, int[]|null> Worker pids keyed by server id ([] = none
	 *                                running, null = unknown).
	 */
	private function loadPHPPIDs(): array {
		global $db;
		$rPHPPIDs = [];

		$db->query('SELECT `id`, `php_pids` FROM `servers`;');
		foreach ($db->get_rows() ?: [] as $rRow) {
			$rDecodedPids = json_decode($rRow['php_pids'] ?? '', true);
			$rPHPPIDs[intval($rRow['id'])] = is_array($rDecodedPids) ? array_map('intval', $rDecodedPids) : null;
		}

		return $rPHPPIDs;
	}

	/**
	 * Redis mode on MAIN: whether a remote node's PHP-served connection still
	 * has its FPM worker, judged from the worker pids the node's watchdog
	 * published just before its last heartbeat. A pid assigned after that
	 * snapshot cannot be in it, so it counts as running. A reused connection
	 * keeps its original date_start while every pid write also stamps
	 * hls_last_read (MAIN's clock, like last_check_ago), so the later of the
	 * two is when the current pid was assigned.
	 *
	 * @param array      $rConnection Connection blob.
	 * @param array|null $rServer     The node's servers row (null = unknown).
	 * @param int[]|null $rPIDs       The node's worker pids (null = unknown).
	 * @return bool False only when the worker is known to be gone.
	 */
	private function isRemoteWorkerRunning(array $rConnection, ?array $rServer, ?array $rPIDs): bool {
		$rAssigned = max(intval($rConnection['date_start']), intval($rConnection['hls_last_read'] ?? 0));

		if (!is_array($rPIDs) || !isset($rServer['last_check_ago']) || $rServer['last_check_ago'] - 1 < $rAssigned) {
			return true;
		}

		return in_array(intval($rConnection['pid']), $rPIDs);
	}

	/**
	 * Has the connection's line expired? A lines_live row carries its line's
	 * exp_date (the join); a Redis record does not, so the line's, read by the
	 * cron, stands in. No date: it never expires.
	 */
	private static function lineExpired(array $rConnection, mixed $rLineExp, int $rNow): bool {
		$rExp = $rConnection['exp_date'] ?? $rLineExp;
		return $rExp !== null && $rExp !== '' && (int) $rExp < $rNow;
	}

	/**
	 * When a viewer closed for silence was last known watching (MAIN's clock):
	 * an ended one's end (its worker stamps hls_last_read on the way out), an
	 * HLS viewer's last read, a worker's last check-in plus one period (it
	 * would have checked in again). RTMP and daemon viewers never check in:
	 * now.
	 *
	 * @param array<string, mixed> $rConnection
	 */
	private static function lastHeard(array $rConnection, int $rNow): int {
		$rLast = intval($rConnection['hls_last_read'] ?? 0);
		if ($rLast <= 0 || $rLast >= $rNow) {
			return $rNow;
		}
		if (($rConnection['hls_end'] ?? 0) == 1 || ($rConnection['container'] ?? '') == 'hls') {
			return $rLast;
		}
		if (($rConnection['container'] ?? '') == 'rtmp' || intval($rConnection['pid'] ?? 0) === 0) {
			return $rNow;
		}
		return min($rNow, $rLast + 300);
	}

	/** A viewer silent this long is one its server no longer sweeps (MySQL mode, MAIN only). */
	public const ORPHAN_AFTER = 1800;

	/**
	 * Is this another server's viewer that nobody sweeps: its server deleted,
	 * or, for one that checks in (HLS, or a TS/VOD PHP worker), silent for
	 * ORPHAN_AFTER (a server that sweeps its own would have closed it long
	 * before)? RTMP and daemon-served viewers never check in: only their
	 * server tells, so only a deleted server orphans them.
	 *
	 * @param array<string, mixed> $rConnection
	 * @param array<int, array<string, mixed>> $rServers
	 */
	private static function orphaned(array $rConnection, array $rServers, int $rNow): bool {
		if (!isset($rServers[(int) ($rConnection['server_id'] ?? 0)])) {
			return true;
		}
		$rChecksIn = ($rConnection['container'] ?? '') == 'hls' || (($rConnection['container'] ?? '') != 'rtmp' && intval($rConnection['pid'] ?? 0) !== 0);
		return $rChecksIn && $rNow - intval($rConnection['hls_last_read'] ?? 0) >= self::ORPHAN_AFTER;
	}

	/** Three missed check-ins: a TS, VOD or timeshift worker checks its viewer in every 300 s. */
	public const SILENT_WORKER = 900;

	/**
	 * Is this worker-served viewer (TS, VOD, timeshift TS) over? Ended five
	 * minutes ago, its worker gone, or no check-in for SILENT_WORKER: a worker
	 * that stopped serving it without closing it (its end-of-request write
	 * failed, Redis down) leaves a pid PHP-FPM gives the next request, which
	 * reads as running for as long as that worker lives.
	 *
	 * @param array<string, mixed> $rConnection
	 */
	private static function workerGone(array $rConnection, bool $rIsRunning, int $rNow): bool {
		$rSilent = $rNow - intval($rConnection['hls_last_read'] ?? 0);
		return !$rIsRunning || ($rConnection['hls_end'] == 1 ? $rSilent >= 300 : $rSilent >= self::SILENT_WORKER);
	}

	/**
	 * Is this HLS viewer over? The node said so (hls_end), or it made no
	 * playlist request for 30 s and its node does not reap for itself
	 * (HlsReaping: an agent with hls_reaper, unless orphaned).
	 */
	private function hlsEnded(array $rConnection, int $rNow): bool {
		if ($rConnection['hls_end'] == 1) {
			return true;
		}
		return HlsReaping::STALE_AFTER <= $rNow - intval($rConnection['hls_last_read']) && !HlsReaping::nodeReaps((int) $rConnection['server_id']);
	}

	/**
	 * On a node whose CONNECTIONS flow is on, the viewers' speed files (KiB/s,
	 * named by uuid) go to MAIN as a conn.divergence event, and the files read
	 * are removed. False when they were not sent: this cron then writes
	 * MAIN's tables from them, as before.
	 *
	 * @param list<string> $rFiles DIVERGENCE_TMP_PATH files
	 */
	private function spoolDivergence(array $rFiles): bool {
		if (!NodeFlows::on(NodeFlows::CONNECTIONS)) {
			return false;
		}
		$rRates = [];
		foreach ($rFiles as $rFile) {
			$rRates[basename($rFile)] = intval(@file_get_contents($rFile));
		}
		if (!DivergenceSink::spool($rRates, 'cron')) {
			return false;
		}
		foreach ($rFiles as $rFile) {
			@unlink($rFile);
		}
		return true;
	}

	/**
	 * This node's viewers' divergence, from the speed files (KiB/s, named by
	 * uuid) of its PHP-served viewers: a CONNECTIONS node sends the rates to
	 * MAIN, which writes the divergence (conn.divergence); otherwise it is
	 * written into MAIN's tables here, as before.
	 *
	 * @param string|null $rDir The speed files (tests); DIVERGENCE_TMP_PATH by default.
	 */
	private function writeDivergence(bool $rRedis, ?string $rDir = null): void {
		global $db;

		$rDir ??= DIVERGENCE_TMP_PATH;
		$rConnectionSpeeds = glob($rDir . '*') ?: [];

		// A CONNECTIONS node sends the rates to MAIN, which writes the
		// divergence (conn.divergence); it writes MAIN's tables only without.
		if (count($rConnectionSpeeds) > 0 && $this->spoolDivergence($rConnectionSpeeds)) {
			$rConnectionSpeeds = [];
		}

		if (count($rConnectionSpeeds) > 0) {
			$rBitrates = [];

			if ($rRedis) {
				$rStreamMap = [];

				$db->query('SELECT `stream_id`, `bitrate` FROM `streams_servers` WHERE `server_id` = ? AND `bitrate` IS NOT NULL;', SERVER_ID);
				foreach ($db->get_rows() as $rRow) {
					$bitrate = intval($rRow['bitrate']);
					if ($bitrate > 0) {
						$rStreamMap[intval($rRow['stream_id'])] = DivergenceSink::expected($bitrate);
					}
				}

				$rUUIDs = [];
				foreach ($rConnectionSpeeds as $rConnectionSpeed) {
					if (!empty($rConnectionSpeed)) {
						$rUUIDs[] = basename($rConnectionSpeed);
					}
				}

				if (count($rUUIDs) > 0) {
					$rRedisInstance = RedisManager::instance();
					if (!$rRedisInstance instanceof \Redis) {
						$rConnections = [];
					} else {
						$rConnections = array_map(
							static fn($v) => ($v !== false) ? igbinary_unserialize($v) : null,
							$rRedisInstance->mGet($rUUIDs)
						);
					}

					foreach ($rConnections as $rConnection) {
						if (!is_array($rConnection)) {
							continue;
						}

						$uuid = $rConnection['uuid'];
						$streamId = intval($rConnection['stream_id']);

						if (!isset($rStreamMap[$streamId])) {
							continue;
						}

						$rBitrates[$uuid] = $rStreamMap[$streamId];
					}
				}

				unset($rStreamMap);
			} else {
				$db->query('SELECT `lines_live`.`uuid`, `streams_servers`.`bitrate` FROM `lines_live` LEFT JOIN `streams_servers` ON `lines_live`.`stream_id` = `streams_servers`.`stream_id` AND `lines_live`.`server_id` = `streams_servers`.`server_id` WHERE `lines_live`.`server_id` = ?;', SERVER_ID);

				foreach ($db->get_rows() as $rRow) {
					$bitrate = intval($rRow['bitrate']);
					if ($bitrate > 0) {
						$rBitrates[$rRow['uuid']] = DivergenceSink::expected($bitrate);
					}
				}
			}

			if (!$rRedis) {
				$rUUIDMap = [];
				$db->query('SELECT `uuid`, `activity_id` FROM `lines_live`;');
				foreach ($db->get_rows() as $rRow) {
					$rUUIDMap[$rRow['uuid']] = $rRow['activity_id'];
				}
			}

			$rLiveQuery = $rDivergenceUpdate = [];

			foreach ($rConnectionSpeeds as $rConnectionSpeed) {
				if (empty($rConnectionSpeed)) {
					continue;
				}

				$rUUID = basename($rConnectionSpeed);
				$rAverageSpeed = intval(file_get_contents($rConnectionSpeed));

				if (!isset($rBitrates[$rUUID]) || $rBitrates[$rUUID] <= 0) {
					$rDivergenceUpdate[] = "('" . $rUUID . "', 0)";

					if (!$rRedis && isset($rUUIDMap[$rUUID])) {
						$rLiveQuery[] = '(' . $rUUIDMap[$rUUID] . ', 0)';
					}

					continue;
				}

				$rDivergence = DivergenceSink::of($rAverageSpeed, $rBitrates[$rUUID]);
				$rDivergenceUpdate[] = "('" . $rUUID . "', " . $rDivergence . ')';

				if (!$rRedis && isset($rUUIDMap[$rUUID])) {
					$rLiveQuery[] = '(' . $rUUIDMap[$rUUID] . ', ' . $rDivergence . ')';
				}
			}

			if (count($rDivergenceUpdate) > 0) {
				$rUpdateQuery = implode(',', $rDivergenceUpdate);
				$db->query('INSERT INTO `lines_divergence`(`uuid`,`divergence`) VALUES ' . $rUpdateQuery . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
			}

			if (!$rRedis && count($rLiveQuery) > 0) {
				$rLiveQueryStr = implode(',', $rLiveQuery);
				$db->query('INSERT INTO `lines_live`(`activity_id`,`divergence`) VALUES ' . $rLiveQueryStr . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
			}

			shell_exec('rm -f ' . escapeshellarg($rDir) . '*');
		}
	}

	private function processDeletions($rDelete, $rDelStream = []) {
		$rRedis = SettingsManager::getBool('redis_handler');
		global $db;
		$rTime = time();
		$rRemoved = true;

		if ($rRedis) {
			// Redis can die mid-run — postpone cleanup instead of crashing the
			// cron; the entries stay in LIVE/ENDED and are re-detected next run.
			if ($rDelete['count'] > 0 && ($rRedisInstance = RedisManager::instance())) {
				$rRedis = $rRedisInstance->multi();

				foreach ($rDelete['line'] as $rUserID => $rUUIDs) {
					$rRedis->zRem('LINE#' . $rUserID, ...$rUUIDs);
					$rRedis->zRem('LINE_ALL#' . $rUserID, ...$rUUIDs);
				}

				foreach ($rDelete['stream'] as $rStreamID => $rUUIDs) {
					$rRedis->zRem('STREAM#' . $rStreamID, ...$rUUIDs);
				}

				foreach ($rDelete['server'] as $rServerID => $rUUIDs) {
					$rRedis->zRem('SERVER#' . $rServerID, ...$rUUIDs);
					$rRedis->zRem('SERVER_LINES#' . $rServerID, ...$rUUIDs);
				}

				foreach ($rDelete['proxy'] as $rProxyID => $rUUIDs) {
					$rRedis->zRem('PROXY#' . $rProxyID, ...$rUUIDs);
				}

				if (count($rDelete['uuid']) > 0) {
					$rRedis->zRem('CONNECTIONS', ...$rDelete['uuid']);
					$rRedis->zRem('LIVE', ...$rDelete['uuid']);
					$rRedis->sRem('ENDED', ...$rDelete['uuid']);
					$rRedis->del(...$rDelete['uuid']);
				}

				$rRemoved = is_array($rRedis->exec());
			} elseif ($rDelete['count'] > 0) {
				echo "Redis unavailable, connection cleanup postponed until next run\n";
				$rRemoved = false;
			}
		} else {
			foreach ($rDelete as $rConnections) {
				if (count($rConnections) > 0) {
					// ponytail: one failed DELETE drops the batch's rows, the other servers' removed too; a DB that fails one fails all.
					$rRemoved = $db->query("DELETE FROM `lines_live` WHERE `uuid` IN ('" . implode("','", $rConnections) . "')") !== false && $rRemoved;
				}
			}
		}
		// The rows of the connections closed so far: written once their records
		// are gone; the ones kept are closed, and logged, by the next sweep.
		ConnectionTracker::releaseActivity($rRemoved);

		foreach (($rRedis ? $rDelete['server'] : $rDelete) as $rServerID => $rConnections) {
			if ($rServerID != SERVER_ID) {
				$rPayloads = [];
				foreach ($rConnections as $rConnection) {
					$rPayloads[] = ['type' => 'delete_con', 'uuid' => $rConnection];
				}
				SignalDispatcher::cacheBatch(intval($rServerID), $rPayloads, intval($rTime), $db);
			}
		}

		foreach ($rDelStream as $rStreamID => $rConnections) {
			foreach ($rConnections as $rConnection) {
				@unlink(CONS_TMP_PATH . $rStreamID . '/' . $rConnection);
			}
		}

		if ($rRedis) {
			return ['line' => [], 'server' => [], 'server_lines' => [], 'proxy' => [], 'stream' => [], 'uuid' => [], 'count' => 0];
		}

		return [];
	}

	/**
	 * Blob 'identity' may be absent (written by an external component or a
	 * pre-identity build) — derive it the same way the sync path does
	 * (StoredConnections::identity()).
	 *
	 * @param array $rConnection Deserialized connection blob.
	 * @return int|string
	 */
	private function connectionIdentity(array $rConnection) {
		if (isset($rConnection['identity'])) {
			return $rConnection['identity'];
		}
		return StoredConnections::identity($rConnection);
	}

	private function loadCron(): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		global $db;

		$rServers = $this->rServers;
		$rPHPPIDs = $this->rPHPPIDs;

		if ($rRedis) {
			RedisManager::ensureConnected();
		}

		// MAIN's clock, as hls_last_read and date_start are: a node's own runs
		// ahead or behind by its time_offset, and HLS goes stale in 30 s.
		$rStartTime = time() - intval($rServers[SERVER_ID]['time_offset'] ?? 0);
		$rLiveKeys = [];
		if ($rServers[SERVER_ID]['is_main']) {
			// Nodes whose agent ends its own idle HLS viewers, and the orphan
			// purge: a silent CONNECTIONS node's rows leave MAIN's store.
			HlsReaping::begin($rStartTime, ClusterSettings::int('cluster_orphan_conn_ttl_sec', SettingsManager::get('cluster_orphan_conn_ttl_sec')));
			if (class_exists(ConnectionIngest::class)) {
				foreach (HlsReaping::orphaned() as $rOrphan) {
					$rPurged = ConnectionIngest::purgeNode($rOrphan);
					if ($rPurged > 0) {
						echo 'Orphan purge: ' . $rPurged . ' connection(s) of silent node ' . $rOrphan . "\n";
						ClusterAudit::log('conn.orphan_purge', $rOrphan, ['connections' => $rPurged], 'liveness');
					}
				}
			}
		} else {
			// Its own rows (MySQL mode): its agent's reaper, with the same
			// grace as on MAIN when it stops.
			HlsReaping::beginLocal($rStartTime);
		}

		if (!$rRedis || $rServers[SERVER_ID]['is_main']) {
			$rAutoKick = SettingsManager::getInt('user_auto_kick_hours') * 3600;
			$rLiveKeys = $rDelete = $rDeleteStream = $rRetired = [];
			$rRedisDelete = ['line' => [], 'server' => [], 'server_lines' => [], 'proxy' => [], 'stream' => [], 'uuid' => [], 'count' => 0];

			if ($rRedis) {
				$rUsers = [];
				$rResult = ConnectionTracker::getConnections();
				$rKeys = $rResult[0] ?? [];
				$rConnections = $rResult[1] ?? [];
				$i = 0;

				for ($rSize = count($rConnections); $i < $rSize; $i++) {
					$rConnection = $rConnections[$i];

					if (is_array($rConnection)) {
						$rConnection['identity'] = $this->connectionIdentity($rConnection);
						$rUsers[$rConnection['identity']][] = $rConnection;
						$rLiveKeys[] = $rConnection['uuid'];
					} else {
						$rRedisDelete['count']++;
						$rRedisDelete['uuid'][] = $rKeys[$i];
					}
				}
				unset($rConnections);
			} else {
				$rUsers = ConnectionTracker::getConnections(($rServers[SERVER_ID]['is_main'] ? null : SERVER_ID));
			}

			$rRestreamerArray = $rMaxConnectionsArray = $rExpDateArray = [];
			$rUserIDs = InputValidator::confirmIDs(array_keys($rUsers));

			if (count($rUserIDs) > 0) {
				$db->query('SELECT `id`, `max_connections`, `is_restreamer`, `exp_date` FROM `lines` WHERE `id` IN (' . implode(',', $rUserIDs) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rMaxConnectionsArray[$rRow['id']] = $rRow['max_connections'];
					$rRestreamerArray[$rRow['id']] = $rRow['is_restreamer'];
					$rExpDateArray[$rRow['id']] = $rRow['exp_date'];
				}
			}

			if ($rRedis && $rServers[SERVER_ID]['is_main']) {
				foreach (ConnectionTracker::getEnded() as $rConnection) {
					if (is_array($rConnection)) {
						$rConnection['identity'] = $this->connectionIdentity($rConnection);
						if (!in_array($rConnection['container'], ['ts', 'hls', 'rtmp']) && time() - $rConnection['hls_last_read'] < 300) {
							$rClose = false;
						} else {
							$rClose = true;
						}

						if ($rClose) {
							echo 'Close connection: ' . $rConnection['uuid'] . "\n";
							ConnectionTracker::closeConnection(['date_end' => self::lastHeard($rConnection, $rStartTime)] + $rConnection, false, false);
							$rRedisDelete['count']++;
							$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
							$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
							$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
							$rRedisDelete['uuid'][] = $rConnection['uuid'];

							if ($rConnection['proxy_id']) {
								$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
							}
						}
					}
				}

				if ($rRedisDelete['count'] >= 1000) {
					$rRedisDelete = $this->processDeletions($rRedisDelete, $rRedisDelete['stream']);
				}
			}

			foreach ($rUsers as $rUserID => $rConnections) {
				$rActiveCount = 0;
				// HMAC identities and deleted lines have no row in `lines` —
				// 0 disables the max-connections kick below.
				$rMaxConnections = $rMaxConnectionsArray[$rUserID] ?? 0;
				$rIsRestreamer = !empty($rRestreamerArray[$rUserID]);

				foreach ($rConnections as $rConnection) {
					if ($rConnection['server_id'] == SERVER_ID || $rRedis) {
						if (!self::lineExpired($rConnection, $rExpDateArray[$rUserID] ?? null, $rStartTime)) {
							$rTotalTime = $rStartTime - $rConnection['date_start'];

							if ($rAutoKick == 0 || $rAutoKick > $rTotalTime || $rIsRestreamer) {
								if ($rConnection['container'] == 'hls') {
									if ($this->hlsEnded($rConnection, $rStartTime)) {
										echo 'Close connection: ' . $rConnection['uuid'] . "\n";
										ConnectionTracker::closeConnection(['date_end' => self::lastHeard($rConnection, $rStartTime)] + $rConnection, false, false);

										if ($rRedis) {
											$rRedisDelete['count']++;
											$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
											$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
											$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
											$rRedisDelete['uuid'][] = $rConnection['uuid'];

											if ($rConnection['user_id']) {
												$rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
											}

											if ($rConnection['proxy_id']) {
												$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
											}
										} else {
											$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
											$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
										}
									}
								} else {
									// Daemon-served TS (pid=0, ADR 0003 Phase C) has no PHP
									// worker to probe — the fanout_sync daemon closes it by
									// reconciling against the daemon's live-connection set.
									// Skip it here so this reaper neither leaks it (a live
									// reused worker pid reads as "running") nor closes it
									// early (when that worker recycles).
									if ($rConnection['container'] != 'rtmp' && intval($rConnection['pid']) !== 0) {
										if ($rConnection['server_id'] == SERVER_ID) {
											$rIsRunning = ProcessManager::isRunning($rConnection['pid'], 'php-fpm');
										} else {
											$rIsRunning = $this->isRemoteWorkerRunning($rConnection, $rServers[$rConnection['server_id']] ?? null, $rPHPPIDs[$rConnection['server_id']] ?? null);
										}

										if (self::workerGone($rConnection, $rIsRunning, $rStartTime)) {
											echo 'Close connection: ' . $rConnection['uuid'] . "\n";
											ConnectionTracker::closeConnection(['date_end' => self::lastHeard($rConnection, $rStartTime)] + $rConnection, false, false);

											if ($rRedis) {
												$rRedisDelete['count']++;
												$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
												$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
												$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
												$rRedisDelete['uuid'][] = $rConnection['uuid'];

												if ($rConnection['user_id']) {
													$rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
												}

												if ($rConnection['proxy_id']) {
													$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
												}
											} else {
												$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
												$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
											}
										}
									}
								}
							} else {
								echo 'Close connection: ' . $rConnection['uuid'] . "\n";
								ConnectionTracker::closeConnection($rConnection, false, false);

								if ($rRedis) {
									$rRedisDelete['count']++;
									$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
									$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
									$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
									$rRedisDelete['uuid'][] = $rConnection['uuid'];

									if ($rConnection['user_id']) {
										$rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
									}

									if ($rConnection['proxy_id']) {
										$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
									}
								} else {
									$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
									$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
								}
							}
						} else {
							echo 'Close connection: ' . $rConnection['uuid'] . "\n";
							ConnectionTracker::closeConnection($rConnection, false, false);

							if ($rRedis) {
								$rRedisDelete['count']++;
								$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
								$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
								$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
								$rRedisDelete['uuid'][] = $rConnection['uuid'];

								if ($rConnection['user_id']) {
									$rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
								}

								if ($rConnection['proxy_id']) {
									$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
								}
							} else {
								$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
								$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
							}
						}
					} elseif ($rServers[SERVER_ID]['is_main'] && $rConnection['container'] == 'hls' && $rConnection['hls_end'] == 1 && HlsReaping::sweptByMain((int) $rConnection['server_id']) && class_exists(ConnectionIngest::class)) {
						// MySQL mode: each server sweeps its own rows, and a node in mode 2
						// has no database to sweep them in. MAIN closes what such a node's
						// agent said has ended, as it does for every server in Redis mode:
						// left there, the row refused the same player's next record from
						// another node until it counted as orphaned.
						if (ConnectionIngest::retireEnded($rConnection, self::lastHeard($rConnection, $rStartTime), false)) {
							echo 'Close connection: ' . $rConnection['uuid'] . "\n";
							// Not $rDelete: its DELETE goes by uuid, and would take the row another node may open under it.
							$rRetired[(int) $rConnection['server_id']][] = ['type' => 'delete_con', 'uuid' => (string) $rConnection['uuid']];
						}
						continue;
					} elseif ($rServers[SERVER_ID]['is_main'] && self::orphaned($rConnection, $rServers, $rStartTime)) {
						// MySQL mode: each server sweeps its own rows, so a deleted one's,
						// or a crashed one's, stayed open for good. MAIN closes them, as
						// ended: nothing is left to kill.
						echo 'Close orphaned connection: ' . $rConnection['uuid'] . "\n";
						ConnectionTracker::closeConnection(['hls_end' => 1, 'date_end' => self::lastHeard($rConnection, $rStartTime)] + $rConnection, false, false);
						$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
						$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
						continue;
					}

					if (!$rConnection['hls_end']) {
						$rActiveCount++;
					}
				}

				if ($rServers[SERVER_ID]['is_main'] && 0 < $rMaxConnections && $rMaxConnections < $rActiveCount) {
					foreach ($rConnections as $rConnection) {
						if (!$rConnection['hls_end']) {
							echo 'Close connection: ' . $rConnection['uuid'] . "\n";
							ConnectionTracker::closeConnection($rConnection, false, false);

							if ($rRedis) {
								$rRedisDelete['count']++;
								$rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
								$rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
								$rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
								$rRedisDelete['uuid'][] = $rConnection['uuid'];

								if ($rConnection['user_id']) {
									$rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
								}

								if ($rConnection['proxy_id']) {
									$rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
								}
							} else {
								$rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
								$rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
							}

							$rActiveCount--;
						}

						if ($rActiveCount >= $rMaxConnections) {
							break;
						}
					}
				}

				if ($rRedis && 1000 <= $rRedisDelete['count']) {
					$rRedisDelete = $this->processDeletions($rRedisDelete, $rRedisDelete['stream']);
				} else {
					if (!$rRedis && count($rDelete) >= 1000) {
						$rDelete = $this->processDeletions($rDelete, $rDeleteStream);
					}
				}
			}

			if ($rRedis && 0 < $rRedisDelete['count']) {
				$this->processDeletions($rRedisDelete, $rRedisDelete['stream']);
			} else {
				if (!$rRedis && count($rDelete) > 0) {
					$this->processDeletions($rDelete, $rDeleteStream);
				}
			}
			// The rows MAIN closed for a node in mode 2: each node drops their markers, told once.
			foreach ($rRetired as $rServerID => $rPayloads) {
				try {
					SignalDispatcher::cacheBatch($rServerID, $rPayloads, time(), $db);
				} catch (\Throwable) {
					// The rows are closed either way; the markers are the node's to drop.
				}
			}
		}

		$this->writeDivergence($rRedis);

		if ($rServers[SERVER_ID]['is_main']) {
			if ($rRedis) {
				$rDeleteQuery = "DELETE FROM `lines_divergence` WHERE `uuid` NOT IN ('" . implode("','", $rLiveKeys) . "');";
				for ($rRetry = 0; $rRetry < 3; $rRetry++) {
					if ($db->query($rDeleteQuery)) {
						break;
					}
					usleep(200000);
				}
			} else {
				$db->query('DELETE FROM `lines_divergence` WHERE `uuid` NOT IN (SELECT `uuid` FROM `lines_live`);');
			}
			$db->query('DELETE FROM `lines_live` WHERE `uuid` IS NULL;');
		}
	}
}
