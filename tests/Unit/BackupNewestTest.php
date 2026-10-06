<?php

use PHPUnit\Framework\TestCase;

/**
 * The dashboard's Backups row reads the newest backup a backup run made
 * (backup_*.sql, not a dump taken around an update, rollback or restore) and
 * whether its Dropbox upload failed.
 *
 * MAIN_HOME is fixed for the suite, so the list is read in a child PHP whose
 * MAIN_HOME is a throwaway directory.
 */
final class BackupNewestTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-newest-backup-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'backups', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function newest(): ?array {
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, '<?php define("MAIN_HOME", ' . var_export($this->rDir, true) . '); require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';'
			. ' echo json_encode(\XcVm\Core\Backup\BackupService::newestBackup());');
		exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $rScript])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return json_decode(implode('', $rOut), true);
	}

	public function testTheNewestBackupRunAndItsUpload(): void {
		touch($this->rDir . 'backups/backup_2027-01-01_04:00:00.sql', 1800000000 - 86400);
		touch($this->rDir . 'backups/backup_2027-01-02_04:00:00.sql', 1800000000);
		touch($this->rDir . 'backups/pre_rollback_2.6.1_to_2.6.0.sql', 1800000100);
		touch($this->rDir . 'backups/pre_restore_2027-01-02_05:00:00.sql', 1800000200);
		file_put_contents($this->rDir . 'backups/backup_2027-01-02_04:00:00.sql.error', 'too_many_write_operations');

		$this->assertSame(['timestamp' => 1800000000, 'upload_failed' => true], $this->newest());
	}

	public function testNoBackupRunIsNone(): void {
		touch($this->rDir . 'backups/pre_rollback_2.6.1_to_2.6.0.sql');
		$this->assertNull($this->newest());
		exec('rm -rf ' . escapeshellarg($this->rDir . 'backups'));
		$this->assertNull($this->newest(), 'no backups folder');
	}
}
