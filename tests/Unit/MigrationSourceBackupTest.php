<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrationGuard;
use XcVm\Cli\Commands\MigrationSourceBackup;
use XcVm\Tests\Support\QueryLogDb;

final class MigrationSourceBackupTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm-source-backup-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
	}

	protected function tearDown(): void {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->dir);
	}

	public function testRecoveryPreservesRowsEmptyTablesForeignKeysAndOriginalNames(): void {
		$db = new TestDb();
		$db->exec('CREATE TABLE `ParentRows` (`id` int PRIMARY KEY) ENGINE=InnoDB');
		$db->exec('CREATE TABLE `ChildRows` (`id` int PRIMARY KEY, `parent_id` int, `text` text, CONSTRAINT `backup_fk` FOREIGN KEY (`parent_id`) REFERENCES `ParentRows` (`id`)) ENGINE=InnoDB');
		$db->exec('CREATE TABLE `EmptyRows` (`id` int PRIMARY KEY) ENGINE=InnoDB');
		$db->query('INSERT INTO `ParentRows` VALUES (7)');
		$text = "A quoted 'value', a newline\nБосна and `legacy_ChildRows`";
		$db->query('INSERT INTO `ChildRows` VALUES (1, 7, ?)', $text);
		$guard = new MigrationGuard();
		$backup = MigrationSourceBackup::create($db, $guard, $this->dir);
		$this->assertSame(0700, fileperms($backup->directory) & 0777);
		$this->assertSame(0600, fileperms($backup->directory . '/ChildRows.sql') & 0777);
		$db->exec('SET FOREIGN_KEY_CHECKS = 0; DROP TABLE `ChildRows`, `ParentRows`, `EmptyRows`; SET FOREIGN_KEY_CHECKS = 1;');
		$backup->restoreMissing($db, $guard);
		$db->query('SELECT `text` FROM `ChildRows`');
		$this->assertSame($text, $db->get_col());
		$db->query('SELECT COUNT(*) FROM `EmptyRows`');
		$this->assertSame(0, (int) $db->get_col());
		$db->query('SHOW CREATE TABLE `ChildRows`');
		$this->assertStringContainsString('FOREIGN KEY', array_values($db->get_row())[1]);
		$backup->discard();
		$this->assertDirectoryDoesNotExist($backup->directory);
	}

	public function testAFailedRecoveryCopyNeverDiscardsSourceRowsOrLeavesAnIncompleteArchive(): void {
		$inner = new TestDb();
		$inner->exec('CREATE TABLE `source_rows` (`id` int PRIMARY KEY)');
		$inner->exec('INSERT INTO `source_rows` VALUES ' . implode(',', array_map(static fn(int $id): string => '(' . $id . ')', range(1, 1500))));
		$db = new QueryLogDb($inner);
		$db->rRefuse = '/OFFSET 1000/';
		try {
			MigrationSourceBackup::create($db, new MigrationGuard(), $this->dir);
			$this->fail('A failed source read must not authorize cleanup.');
		} catch (RuntimeException $error) {
			$inner->query('SELECT COUNT(*) FROM `source_rows`');
			$this->assertSame(1500, (int) $inner->get_col());
			$this->assertSame([], glob($this->dir . '/*'));
		}
	}

	public function testCorruptRecoveryFilesAreRejectedBeforeRestoringAnyTable(): void {
		$db = new TestDb();
		$db->exec('CREATE TABLE `source_rows` (`id` int PRIMARY KEY)');
		$db->query('INSERT INTO `source_rows` VALUES (7)');
		$backup = MigrationSourceBackup::create($db, new MigrationGuard(), $this->dir);
		file_put_contents($backup->directory . '/source_rows.sql', 'corrupt');
		$this->expectException(RuntimeException::class);
		$backup->restoreMissing($db, new MigrationGuard());
	}
}
