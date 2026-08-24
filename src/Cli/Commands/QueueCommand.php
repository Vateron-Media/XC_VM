<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Health\ProcessChecker;

/**
 * QueueCommand — queue command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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
		if (!$this->acquireDaemonLock('queue')) {
			return 0;
		}

		global $db;

		$this->setProcessTitle('XC_VM[Queue]');
		$this->killStaleProcesses('console.php queue');
		$this->killStaleProcesses('XC_VM\\[Queue\\]');
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
				$rFreeSlots = (0 < SettingsManager::get('max_encode_movies') ? intval(SettingsManager::get('max_encode_movies')) - count($rInProgress) : 50);
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
					$rFreeSlots = (0 < SettingsManager::get('max_encode_cc') ? intval(SettingsManager::get('max_encode_cc')) - count($rInProgress) : 1);
					if ($rFreeSlots > 0) {
						if ($db->query("SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = 'channel' ORDER BY `added` ASC LIMIT " . $rFreeSlots . ';', SERVER_ID)) {
							if ($db->num_rows() > 0) {
								foreach ($db->get_rows() as $rRow) {
									$rCreateFile = CREATED_PATH . $rRow['stream_id'] . '_.create';
									// A build for this stream may already be running (e.g. the
									// queue row was re-added while the previous launch is still
									// encoding). Killing it and starting over would reset the
									// build to 0% every time — adopt the live PID instead.
									$rExistingPID = (file_exists($rCreateFile) ? intval(file_get_contents($rCreateFile)) : 0);
									if ($rExistingPID && ProcessChecker::checkPID($rExistingPID, 'XC_VMCreate[' . intval($rRow['stream_id']) . ']')) {
										$db->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ?;', $rExistingPID, $rRow['id']);
										continue;
									}
									if (file_exists($rCreateFile)) {
										unlink($rCreateFile);
									}
									shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php created ' . intval($rRow['stream_id']) . ' >/dev/null 2>/dev/null &');
									$rPID = null;
									// console.php bootstrap takes well over the old 300ms window;
									// give the spawned process up to 5s to write its PID file.
									foreach (range(1, 20) as $i) {
										if (!file_exists($rCreateFile)) {
											usleep(250000);
										} else {
											$rPID = intval(file_get_contents($rCreateFile));
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
					sleep((0 < SettingsManager::get('queue_loop') ? intval(SettingsManager::get('queue_loop')) : 5));
				}
				break;
			}
		}

		$this->restartDaemon('queue');
		return 0;
	}
}
