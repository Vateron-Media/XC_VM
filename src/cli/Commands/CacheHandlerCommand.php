<?php

/**
 * CacheHandlerCommand — cache handler command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../DaemonTrait.php';

class CacheHandlerCommand implements CommandInterface {
	use DaemonTrait;

	public function getName(): string {
		return 'cache_handler';
	}

	public function getDescription(): string {
		return 'Daemon: handle cache signals (ISP, flood, bruteforce)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		global $db;

		$this->setProcessTitle('XC_VM[CacheHandler]');
		$this->killStaleProcesses('console.php cache_handler');
		$this->initDaemonMD5();

		SettingsManager::set(SettingsRepository::getAll(true));

		if (!SettingsManager::get('enable_cache')) {
			echo "Cache disabled.\n";
			return 0;
		}

		while (true) {
			if (!$db->ping()) {
				break;
			}

			if ($this->shouldRefreshSettings()) {
				SettingsManager::set(SettingsRepository::getAll(true));
				ServerRepository::getAll(true);
				if (!SettingsManager::get('enable_cache')) {
					echo "Cache disabled! Break.\n";
					break;
				}
				if ($this->hasFileChanged()) {
					echo "File changed! Break.\n";
					break;
				}
				$this->rLastCheck = time();
			}

			try {
				$rUpdatedLines = array();
				foreach (glob(SIGNALS_TMP_PATH . 'cache_*') as $rFileMD5) {
					list($rKey, $rData) = json_decode(file_get_contents($rFileMD5), true);
					list($rHeader) = explode('/', $rKey);
					switch ($rHeader) {
						case 'restream_block_user':
							list($rBlank, $rUserID, $rStreamID, $rIP) = explode('/', $rKey);
							$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserID);
							$db->query('INSERT INTO `detect_restream_logs`(`user_id`, `stream_id`, `ip`, `time`) VALUES(?, ?, ?, ?);', $rUserID, $rStreamID, $rIP, time());
							$rUpdatedLines[] = $rUserID;
							break;
						case 'forced_country':
							$rUserID = intval(explode('/', $rKey)[1]);
							$db->query('UPDATE `lines` SET `forced_country` = ? WHERE `id` = ?', $rData, $rUserID);
							$rUpdatedLines[] = $rUserID;
							break;
						case 'isp':
							$rUserID = intval(explode('/', $rKey)[1]);
							$rISPInfo = json_decode($rData, true);
							$db->query('UPDATE `lines` SET `isp_desc` = ?, `as_number` = ? WHERE `id` = ?', $rISPInfo[0], $rISPInfo[1], $rUserID);
							$rUpdatedLines[] = $rUserID;
							break;
						case 'expiring':
							$rUserID = intval(explode('/', $rKey)[1]);
							$db->query('UPDATE `lines` SET `last_expiration_video` = ? WHERE `id` = ?;', time(), $rUserID);
							$rUpdatedLines[] = $rUserID;
							break;
						case 'flood_attack':
							list($rBlank, $rIP) = explode('/', $rKey);
							$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'FLOOD ATTACK', time());
							touch(FLOOD_TMP_PATH . 'block_' . $rIP);
							break;
						case 'bruteforce_attack':
							list($rBlank, $rIP) = explode('/', $rKey);
							$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'BRUTEFORCE ATTACK', time());
							touch(FLOOD_TMP_PATH . 'block_' . $rIP);
							break;
					}
					unlink($rFileMD5);
				}
				$rUpdatedLines = array_unique($rUpdatedLines);
				foreach ($rUpdatedLines as $rUserID) {
					LineService::updateLineSignal($rUserID);
				}
				sleep(1);
			} catch (Exception $e) {
				echo "Error!\n";
			}
		}

		$this->restartDaemon('cache_handler');
		return 0;
	}
}
