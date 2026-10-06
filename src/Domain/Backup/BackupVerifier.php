<?php

namespace XcVm\Domain\Backup;

use XcVm\Cli\Commands\ToolsCommand;
use XcVm\Core\Backup\BackupService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The weekly restore test (cron:backup_verify): the newest backup is restored
 * into the scratch database xc_vm_migrate, which every install has, and must
 * come back with its tables and its settings row; the scratch database is
 * emptied again after. A scratch database that holds tables already (a
 * migration from another panel in progress) is left alone: the test is
 * skipped. The result is kept in `settings.backup_verify`, shown on the
 * Backups page; a failure turns the dashboard's Backups row yellow.
 *
 * @package XC_VM_Domain_Backup
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class BackupVerifier {
	use DatabaseAware;

	public const SCRATCH = 'xc_vm_migrate';

	/** @return array{time: int, state: string, file: string, tables: int, lines: int, error: string} state: ok, failed or skipped */
	public static function run(int $rNow): array {
		$rResult = ['time' => $rNow, 'state' => 'skipped', 'file' => '', 'tables' => 0, 'lines' => 0, 'error' => ''];
		$rNewest = BackupService::newestBackup();
		if ($rNewest === null) {
			$rResult['error'] = 'no backup yet';
		} elseif (self::tables() !== []) {
			$rResult['error'] = self::SCRATCH . ' holds tables (a migration in progress?)';
		} else {
			$rResult['file'] = $rNewest['filename'];
			$rResult = array_merge($rResult, self::restore(MAIN_HOME . 'backups/' . $rNewest['filename']));
			self::empty();
		}
		self::db()->query('UPDATE `settings` SET `backup_verify` = ?;', json_encode($rResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		return $rResult;
	}

	/** @return array{state: string, tables: int, lines: int, error: string} */
	private static function restore(string $rFile): array {
		$rCopy = (string) tempnam(sys_get_temp_dir(), 'xc_verify_');
		if (ToolsCommand::stripDatabaseSwitches($rFile, $rCopy) === null) {
			@unlink($rCopy);
			return ['state' => 'failed', 'tables' => 0, 'lines' => 0, 'error' => 'cannot copy the backup (is the temp directory full?)'];
		}
		$rRestored = (bool) \XC_VM::db_restore($rCopy, self::SCRATCH);
		@unlink($rCopy);
		$rTables = count(self::tables());
		$db = self::db();
		$rSettings = $rTables > 0 && $db->query('SELECT COUNT(*) FROM `' . self::SCRATCH . '`.`settings`;') ? (int) $db->get_col() : 0;
		$rLines = $rTables > 0 && $db->query('SELECT COUNT(*) FROM `' . self::SCRATCH . '`.`lines`;') ? (int) $db->get_col() : 0;
		$rError = match (true) {
			!$rRestored => 'the restore failed',
			$rTables === 0 => 'nothing was restored',
			$rSettings !== 1 => 'the settings row is missing',
			default => '',
		};
		return ['state' => $rError === '' ? 'ok' : 'failed', 'tables' => $rTables, 'lines' => $rLines, 'error' => $rError];
	}

	/** @return list<string> the scratch database's tables */
	private static function tables(): array {
		$db = self::db();
		$db->query('SELECT `table_name` AS `name` FROM `information_schema`.`tables` WHERE `table_schema` = ?;', self::SCRATCH);
		return array_column($db->get_raw_rows(), 'name');
	}

	private static function empty(): void {
		$db = self::db();
		$db->query('SET FOREIGN_KEY_CHECKS = 0;');
		foreach (self::tables() as $rTable) {
			$db->query('DROP TABLE IF EXISTS `' . self::SCRATCH . '`.`' . str_replace('`', '', $rTable) . '`;');
		}
		$db->query('SET FOREIGN_KEY_CHECKS = 1;');
	}

	/** @return array{time: int, state: string, file: string, tables: int, lines: int, error: string}|null the last result */
	public static function last(?string $rSetting): ?array {
		$rResult = json_decode((string) $rSetting, true);
		return is_array($rResult) && isset($rResult['state'], $rResult['time']) ? $rResult : null;
	}
}
