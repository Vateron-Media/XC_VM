<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrateCommand;
use XcVm\Core\Events\Migration\LegacyTableMigrationEvent;

/**
 * Backup tables the core does not own (MigrateCommand::moduleTables(), no list
 * of modules): the migration saves each to a file of its own, from which its
 * module copies it, at the migration or when it is installed later.
 * The file is SQL: the table's own definition (as `legacy_<table>`) and its
 * rows as INSERTs, loaded into that staging table for a later module.
 */
final class MigrateModuleTablesTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-module-tables-' . bin2hex(random_bytes(4)) . '/';
		LegacyTableMigrationEvent::useDir($this->rDir);
	}

	protected function tearDown(): void {
		LegacyTableMigrationEvent::useDir(null);
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			unlink($rFile);
		}
		@rmdir($this->rDir);
	}

	public function testTablesTheCoreDoesNotOwnAreSavedForModulesWhateverTheirNames(): void {
		$rBackup = ['streams', 'lines', 'lines_activity', 'user_activity', 'reg_users', 'watch_folders', 'watch_logs', 'acme_addon_items'];

		$rSaved = MigrateCommand::moduleTables($rBackup, MigrateCommand::coreSchemaTables());

		$this->assertSame(['watch_folders', 'watch_logs', 'acme_addon_items'], $rSaved, 'migrated (streams, reg_users), core schema (lines_activity) and junk (user_activity) stay out');
	}

	public function testTheCoreSchemaIsReadFromDatabaseSql(): void {
		$rTables = MigrateCommand::coreSchemaTables();

		$this->assertContains('streams', $rTables);
		$this->assertContains('lines_activity', $rTables);
		$this->assertNotContains('watch_folders', $rTables, 'a module table is not the core\'s');
	}

	public function testATableIsSavedAsSqlAndLoadedBackForAModuleInstalledLater(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `watch_folders` (`id` int PRIMARY KEY, `directory` varchar(255), `bouquets` varchar(255) NULL);');
		$rDb->exec("INSERT INTO `watch_folders` VALUES (1, 'it''s a \"dir\"\nwith ? and \\\\ in it', NULL);");
		$rValues = [];
		foreach (range(2, 2500) as $i) {
			$rValues[] = '(' . $i . ", '/media/Фильмы " . $i . "', '[\"" . $i . "\"]')";
		}
		$rDb->exec('INSERT INTO `watch_folders` VALUES ' . implode(',', $rValues) . ';');

		$rNow = LegacyTableMigrationEvent::dump($rDb, 'watch_folders', 'xc');
		$this->assertCount(2500, iterator_to_array($rNow->rows(), false), 'at the migration: read from the backup table, in chunks');
		$this->assertFileExists(LegacyTableMigrationEvent::sqlFile('watch_folders'));

		$rDb->exec('DROP TABLE `watch_folders`;'); // the migration drops it from the backup
		$rLater = LegacyTableMigrationEvent::fromBackup('watch_folders', $rDb);
		$this->assertSame('xc', $rLater->format);
		$rRows = iterator_to_array($rLater->rows(), false);
		$this->assertCount(2500, $rRows);
		$this->assertEquals(['id' => 1, 'directory' => "it's a \"dir\"\nwith ? and \\ in it", 'bouquets' => null], $rRows[0]);
		$this->assertEquals(['id' => 2500, 'directory' => '/media/Фильмы 2500', 'bouquets' => '["2500"]'], $rRows[2499]);

		$rLater->discard();
		$this->assertFileDoesNotExist(LegacyTableMigrationEvent::sqlFile('watch_folders'));
		$rDb->query("SHOW TABLES LIKE 'legacy_watch_folders';");
		$this->assertSame(0, $rDb->num_rows(), 'the staging table goes too');
		$this->assertNull(LegacyTableMigrationEvent::fromBackup('watch_folders', $rDb));
	}

	public function testAnEmptyOrAbsentTableSavesNothing(): void {
		$rDb = new TestDb();
		$this->assertNull(LegacyTableMigrationEvent::dump($rDb, 'watch_folders', 'xui'));
		$rDb->exec('CREATE TABLE `watch_folders` (`id` int PRIMARY KEY);');
		$this->assertNull(LegacyTableMigrationEvent::dump($rDb, 'watch_folders', 'xui'));
		$this->assertNull(LegacyTableMigrationEvent::fromBackup('watch_folders', $rDb));
	}

	public function testATableThatCannotBeSavedSaysSo(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `watch_folders` (`id` int PRIMARY KEY);');
		$rDb->query('INSERT INTO `watch_folders` VALUES (1);');
		touch(rtrim($this->rDir, '/')); // a file where the directory should be
		LegacyTableMigrationEvent::useDir($this->rDir . 'sub/');

		$this->expectException(RuntimeException::class);
		try {
			LegacyTableMigrationEvent::dump($rDb, 'watch_folders', 'xui');
		} finally {
			unlink(rtrim($this->rDir, '/'));
		}
	}
}
