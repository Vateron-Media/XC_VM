<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Backup\BackupService;

/**
 * getLocal() scans the backups directory, which holds '.', '..' and may hold
 * sub-directories and extension-less files. Reaching pathinfo()['extension'] on
 * those raised an "Undefined array key extension" E_WARNING on PHP 8 every time
 * AlertsCronJob read the newest backup (once a minute). These pin the quiet,
 * SQL-only behaviour.
 */
final class BackupServiceTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm-backup-' . uniqid() . '/';
		mkdir($this->dir);
		file_put_contents($this->dir . 'backup_1.sql', 'x');
		file_put_contents($this->dir . 'dump.sql', 'x');
		file_put_contents($this->dir . 'notes', 'x');        // no extension at all
		file_put_contents($this->dir . 'archive.tar', 'x');  // wrong extension
		mkdir($this->dir . 'subdir');                        // a directory, no extension
	}

	protected function tearDown(): void {
		foreach (['backup_1.sql', 'dump.sql', 'notes', 'archive.tar'] as $rFile) {
			@unlink($this->dir . $rFile);
		}
		@rmdir($this->dir . 'subdir');
		@rmdir($this->dir);
	}

	public function testOnlySqlFilesAreListedAndNoWarningIsRaised(): void {
		// Any E_WARNING (the undefined-key one in particular) becomes a throwable
		// so the test fails loudly instead of letting the warning slip by.
		set_error_handler(static function (int $rNo, string $rStr): bool {
			throw new \ErrorException($rStr, 0, $rNo);
		});
		try {
			$rResult = BackupService::getLocal($this->dir);
		} finally {
			restore_error_handler();
		}

		$rNames = array_column($rResult, 'filename');
		sort($rNames);
		$this->assertSame(['backup_1.sql', 'dump.sql'], $rNames);
	}

	public function testMissingDirectoryReturnsEmpty(): void {
		$this->assertSame([], BackupService::getLocal($this->dir . 'does-not-exist/'));
	}
}
