<?php

require_once __DIR__ . '/../DaemonTrait.php';

class QueueCommand implements CommandInterface {
	use DaemonTrait;

	public function getName(): string {
		return 'queue';
	}

	public function getDescription(): string {
		return 'Daemon: encoding queue management (movie/channel)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		global $db;

		$this->setProcessTitle('XC_VM[Queue]');
		$this->killStaleProcesses('console.php queue');
		$this->initDaemonMD5();

		while (true && $db->ping()) {
			if ($this->shouldRefreshSettings()) {
				if ($this->hasFileChanged()) {
					echo "File changed! Break.\n";
					break;
				}
				SettingsManager::set(SettingsRepository::getAll(true));
				$this->rLastCheck = time();
			}

			// ── Movie queue ──────────────────────────────────────
			if ($db->query("SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = 'movie' ORDER BY `added` ASC;", SERVER_ID)) {
				$rDelete = $rInProgress = array();
				if ($db->num_rows() > 0) {
					foreach ($db->get_rows() as $rRow) {
						if ($rRow['pid'] && (ProcessManager::isRunning($rRow['pid'], 'ffmpeg') || ProcessManager::isRunning($rRow['pid'], PHP_BIN))) {
							$rInProgress[] = $rRow['pid'];
						} else {
							$rDelete[] = $rRow['id'];
						}
					}
				}
				$rFreeSlots = (0 < SettingsManager::getAll()['max_encode_movies'] ? intval(SettingsManager::getAll()['max_encode_movies']) - count($rInProgress) : 50);
				if ($rFreeSlots > 0) {
					if ($db->query("SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = 'movie' ORDER BY `added` ASC LIMIT " . $rFreeSlots . ';', SERVER_ID)) {
						if ($db->num_rows() > 0) {
							foreach ($db->get_rows() as $rRow) {
								$rPID = StreamProcess::startMovie($rRow['stream_id']);
								if ($rPID) {
									$db->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ?;', $rPID, $rRow['id']);
								} else {
									$rDelete[] = $rRow['id'];
								}
							}
						}
					}
				}

				// ── Channel queue ────────────────────────────────
				if ($db->query("SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = 'channel' ORDER BY `added` ASC;", SERVER_ID)) {
					$rInProgress = array();
					if ($db->num_rows() > 0) {
						foreach ($db->get_rows() as $rRow) {
							if ($rRow['pid'] && ProcessManager::isRunning($rRow['pid'], PHP_BIN)) {
								$rInProgress[] = $rRow['pid'];
							} else {
								$rDelete[] = $rRow['id'];
							}
						}
					}
					$rFreeSlots = (0 < SettingsManager::getAll()['max_encode_cc'] ? intval(SettingsManager::getAll()['max_encode_cc']) - count($rInProgress) : 1);
					if ($rFreeSlots > 0) {
						if ($db->query("SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = 'channel' ORDER BY `added` ASC LIMIT " . $rFreeSlots . ';', SERVER_ID)) {
							if ($db->num_rows() > 0) {
								foreach ($db->get_rows() as $rRow) {
									if (file_exists(CREATED_PATH . $rRow['stream_id'] . '_.create')) {
										unlink(CREATED_PATH . $rRow['stream_id'] . '_.create');
									}
									shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php created ' . intval($rRow['stream_id']) . ' >/dev/null 2>/dev/null &');
									$rPID = null;
									foreach (range(1, 3) as $i) {
										if (!file_exists(CREATED_PATH . $rRow['stream_id'] . '_.create')) {
											usleep(100000);
										} else {
											$rPID = intval(file_get_contents(CREATED_PATH . $rRow['stream_id'] . '_.create'));
											break;
										}
									}
									if ($rPID) {
										$db->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ?;', $rPID, $rRow['id']);
									} else {
										$rDelete[] = $rRow['id'];
									}
								}
							}
						}
					}
					if (count($rDelete) > 0) {
						$db->query('DELETE FROM `queue` WHERE `id` IN (' . implode(',', $rDelete) . ');');
					}
					sleep((0 < SettingsManager::getAll()['queue_loop'] ? intval(SettingsManager::getAll()['queue_loop']) : 5));
				}
				break;
			}
		}

		$this->restartDaemon('queue');
		return 0;
	}
}
