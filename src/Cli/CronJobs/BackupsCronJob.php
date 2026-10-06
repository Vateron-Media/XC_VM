<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Backup\BackupTargets;
use XcVm\Domain\Backup\RecoveryBundle;
use XcVm\Domain\Server\ServerRepository;

/**
 * BackupsCronJob — backups cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BackupsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:backups';
	}

	public function getDescription(): string {
		return 'Cron: automatic database backup (local + Dropbox)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(32757);

		if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
			echo 'Please run on main server.' . "\n";
			return 1;
		}

		$this->setProcessTitle('XC_VM[Backups]');
		$this->acquireCronLock();

		global $db;

		$rForce = false;
		if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
			$rForce = true;
		}

		$rBackups = SettingsManager::get('automatic_backups');
		$rLastBackup = intval(SettingsManager::get('last_backup'));
		$rPeriod = BackupService::PERIODS;

		if (!$rForce) {
			$rPID = getmypid();
			if (file_exists('/proc/' . SettingsManager::get('backups_pid')) && (string) SettingsManager::get('backups_pid') !== '') {
				return 0;
			}
			$db->query('UPDATE `settings` SET `backups_pid` = ?;', $rPID);
		}

		$rCreated = null;
		if (isset($rBackups) && $rBackups != 'off' || $rForce) {
			if ($rLastBackup + $rPeriod[$rBackups] <= time() || $rForce) {
				if (!$rForce) {
					$db->query('UPDATE `settings` SET `last_backup` = ?;', time());
				}
				$db->close_mysql();
				$rFilename = MAIN_HOME . 'backups/backup_' . date('Y-m-d_H:i:s') . '.sql';

				if (BackupService::create($rFilename)) {
					$rCreated = basename($rFilename);
					if (SettingsManager::get('dropbox_remote')) {
						file_put_contents($rFilename . '.uploading', time());
						$rResponse = BackupService::uploadRemote(basename($rFilename), $rFilename);
						if (!isset($rResponse->error)) {
							$rResponse = json_decode(json_encode($rResponse, JSON_UNESCAPED_UNICODE), true);
							if (!isset($rResponse['size']) || intval($rResponse['size']) != filesize($rFilename)) {
								$rError = 'Failed to upload';
								file_put_contents($rFilename . '.error', $rError);
							}
						} else {
							try {
								$rError = json_decode(explode(', in apiCall', $rResponse->error->getMessage())[0], true)['error_summary'];
							} catch (\exception $e) {
								$rError = 'Unknown error';
							}
							file_put_contents($rFilename . '.error', $rError);
						}
						unlink($rFilename . '.uploading');
					}
				}
			}
		}

		// A restore's dump of the live database is its way back: neither counted nor removed here.
		$rBackups = array_values(array_filter(BackupService::getLocal(), static fn(array $rItem): bool => !str_starts_with($rItem['filename'], BackupService::PRE_RESTORE)));
		if (intval(SettingsManager::get('backups_to_keep')) < count($rBackups) && 0 < intval(SettingsManager::get('backups_to_keep'))) {
			$rDelete = array_slice($rBackups, 0, count($rBackups) - intval(SettingsManager::get('backups_to_keep')));
			foreach ($rDelete as $rItem) {
				// Never the backup made in this run, wherever its time puts it in the list.
				if ($rItem['filename'] === $rCreated) {
					continue;
				}
				if (file_exists(MAIN_HOME . 'backups/' . $rItem['filename'])) {
					unlink(MAIN_HOME . 'backups/' . $rItem['filename']);
				}
			}
		}

		// Dropbox is asked for its list only after a backup was made, not every minute.
		if ($rCreated !== null && SettingsManager::get('dropbox_remote')) {
			$rRemoteBackups = BackupService::getRemote();
			if (intval(SettingsManager::get('dropbox_keep')) < count($rRemoteBackups) && 0 < intval(SettingsManager::get('dropbox_keep'))) {
				$rDelete = array_slice($rRemoteBackups, 0, count($rRemoteBackups) - intval(SettingsManager::get('dropbox_keep')));
				foreach ($rDelete as $rItem) {
					try {
						BackupService::deleteRemote($rItem['path']);
					} catch (\exception $e) {
					}
				}
			}
		}

		// The backup targets (Backups page) get the new dump, and once a day the recovery bundle.
		if ($rCreated !== null) {
			self::offsite(MAIN_HOME . 'backups/' . $rCreated, time());
		}

		@unlink($this->rIdentifier);

		return 0;
	}

	/**
	 * Copy a new dump to every enabled backup target, failures written beside
	 * it in its .error file (the dashboard's Backups row reads it); make the
	 * day's recovery bundle when a passphrase is set and copy it too; then
	 * keep each target's newest copies.
	 */
	private static function offsite(string $rDump, int $rNow): void {
		if (!class_exists(BackupTargets::class)) {
			return;
		}
		$rFiles = [$rDump];
		if (RecoveryBundle::passphrase() !== null && $rNow - RecoveryBundle::newest() >= 23 * 3600 && ($rBundle = RecoveryBundle::make($rNow)) !== null) {
			$rFiles[] = $rBundle;
			// A week of bundles here; the targets keep as many as they are set to.
			$rLocal = glob(MAIN_HOME . 'backups/recovery_*.bundle') ?: [];
			sort($rLocal);
			foreach (array_slice($rLocal, 0, max(0, count($rLocal) - 7)) as $rOld) {
				@unlink($rOld);
			}
		}
		$rErrors = [];
		foreach (BackupTargets::enabled() as $rTarget) {
			foreach ($rFiles as $rFile) {
				$rError = BackupTargets::upload($rTarget, $rFile, basename($rFile));
				if ($rError !== null) {
					$rErrors[] = $rTarget['name'] . ': ' . $rError;
				}
			}
			BackupTargets::prune($rTarget);
		}
		if ($rErrors !== []) {
			file_put_contents($rDump . '.error', implode("\n", $rErrors) . "\n", FILE_APPEND);
		}
	}
}
