<?php

require_once __DIR__ . '/../DaemonTrait.php';

class SignalsCommand implements CommandInterface {
	use DaemonTrait;

	public function getName(): string {
		return 'signals';
	}

	public function getDescription(): string {
		return 'Daemon: process kill signals and cache signals from DB/Redis';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		global $db;

		$this->setProcessTitle('XC_VM[Signals]');
		$this->killStaleProcesses('console.php signals');
		$this->initDaemonMD5();
		$this->initRedisIfEnabled();

		$rServers = ServerRepository::getAll();

		while (true && $db && $db->ping()) {
			if (!$this->refreshOrBreak()) {
				break;
			}
			if ($this->rLastCheck) {
				$rServers = ServerRepository::getAll(true);
			}

			// Skip iteration if Redis required but dead
			if ((SettingsManager::getAll()['redis_handler'] ?? false) && (!RedisManager::instance() || !RedisManager::instance()->ping())) {
				break;
			}

			// ── Kill-сигналы из БД ──────────────────────────────
			if ($db->query('SELECT `signal_id`, `pid`, `rtmp` FROM `signals` WHERE `server_id` = ? AND `pid` IS NOT NULL ORDER BY `signal_id` ASC LIMIT 100', SERVER_ID)) {
				if ($db->num_rows() > 0) {
					$rIDs = array();
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
						$rDeletedLines = $rUpdatedStreams = $rUpdatedLines = $rIDs = array();
						foreach ($db->get_rows() as $rRow) {
							$rCustomData = json_decode($rRow['custom_data'], true);
							$rIDs[] = $rRow['signal_id'];
							switch ($rCustomData['type']) {
								case 'update_stream':
									if (!in_array($rCustomData['id'], $rUpdatedStreams)) {
										$rUpdatedStreams[] = $rCustomData['id'];
									}
									break;
								case 'update_line':
									if (!in_array($rCustomData['id'], $rUpdatedLines)) {
										$rUpdatedLines[] = $rCustomData['id'];
									}
									break;
								case 'update_streams':
									foreach ($rCustomData['id'] as $rID) {
										if (!in_array($rID, $rUpdatedStreams)) {
											$rUpdatedStreams[] = $rID;
										}
									}
									break;
								case 'update_lines':
									foreach ($rCustomData['id'] as $rID) {
										if (!in_array($rID, $rUpdatedLines)) {
											$rUpdatedLines[] = $rID;
										}
									}
									break;
								case 'delete_con':
									unlink(CONS_TMP_PATH . $rCustomData['uuid']);
									break;
								case 'delete_vod':
									exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rCustomData['id']) . '.*');
									break;
								case 'delete_vods':
									foreach ($rCustomData['id'] as $rID) {
										exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rID) . '.*');
									}
									break;
							}
						}
						if (count($rUpdatedStreams) > 0) {
							shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "streams_update" "' . implode(',', $rUpdatedStreams) . '"');
						}
						if (count($rUpdatedLines) > 0) {
							shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines_update" "' . implode(',', $rUpdatedLines) . '"');
						}
						if (count($rIDs) > 0) {
							$db->query('DELETE FROM `signals` WHERE `signal_id` IN (' . implode(',', $rIDs) . ')');
						}
					}

					// ── Redis kill-сигналы ──────────────────────
					if (SettingsManager::getAll()['redis_handler']) {
						$rSignals = array();
						foreach (RedisManager::instance()->sMembers('SIGNALS#' . SERVER_ID) as $rKey) {
							$rSignals[] = $rKey;
						}
						if (count($rSignals) > 0) {
							$rSignalData = RedisManager::instance()->mGet($rSignals);
							$rIDs = array();
							foreach ($rSignalData as $rData) {
								$rRow = igbinary_unserialize($rData);
								$rIDs[] = $rRow['key'];
								$rPID = $rRow['pid'];
								if ($rRow['rtmp'] == 0) {
									if (!empty($rPID) && file_exists('/proc/' . $rPID) && is_numeric($rPID) && 0 < $rPID) {
										shell_exec('kill -9 ' . intval($rPID));
									}
								} else {
									shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rPID) . '" >/dev/null 2>/dev/null &');
								}
							}
							RedisManager::instance()->multi()->del($rIDs)->sRem('SIGNALS#' . SERVER_ID, ...$rSignals)->exec();
						}
					}

					usleep(250000);
				}
				break;
			}
		}

		$this->restartDaemon('signals');
		return 0;
	}
}
