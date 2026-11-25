<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ModuleBackup;
use XcVm\Core\Module\ModuleMigrator;

final class ModuleBackupTest extends TestCase {
	private string $root;
	private string $module;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/xcvm_module_backup_' . bin2hex(random_bytes(6));
		$this->module = $this->root . '/Modules/demo_abcde';
		mkdir($this->module, 0755, true);
	}

	protected function tearDown(): void {
		$this->remove($this->root);
	}

	public function testCreateDumpsOnlyExistingOwnedTablesAndListsMetadata(): void {
		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE IF EXISTS `demo_rows`, `missing_rows`;');
		$db = new ModuleBackupDb([
			'demo_rows' => [['id' => '1', 'value' => "  <kept>\n\0  "]],
			'core_users' => [['id' => '9']],
		]);
		$backup = new ModuleBackup($db, $this->root . '/Modules');

		$entry = $backup->create($this->module, $this->metadata());

		$this->assertNotNull($entry);
		$this->assertSame(['demo_rows'], $entry['tables']);
		$this->assertSame(0600, fileperms($this->root . '/module_backups/' . $entry['id'] . '.sql') & 0777);
		$this->assertSame(0700, fileperms($this->root . '/module_backups') & 0777);
		$sql = file_get_contents($this->root . '/module_backups/' . $entry['id'] . '.sql');
		$this->assertStringContainsString('CREATE TABLE `demo_rows`', $sql);
		$this->assertStringContainsString("CHECK (`value` <> '<x>')", $sql, 'raw DDL is not HTML-escaped');
		$this->assertStringContainsString("  <kept>\\n\0  ", $sql, 'raw module values are not trimmed or HTML-escaped');
		$this->assertStringNotContainsString('core_users', $sql);
		$this->assertSame([true, ['demo_rows']], [$backup->list()[0]['verified'], $backup->list()[0]['tables']]);
	}

	public function testOwnedTablesComeOnlyFromTheTeardownContract(): void {
		file_put_contents($this->module . '/database_drop.sql', "-- owned\nDROP TABLE IF EXISTS `mod_a`, mod_b;\n");
		$this->assertSame(['mod_a', 'mod_b'], ModuleMigrator::ownedTables($this->module));

		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE mod_a; DELETE FROM core_table;');
		$this->expectException(\RuntimeException::class);
		ModuleMigrator::ownedTables($this->module);
	}

	public function testNoExistingOwnedTableCreatesNoEmptyBackup(): void {
		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE IF EXISTS `gone`;');
		$backup = new ModuleBackup(new ModuleBackupDb([]), $this->root . '/Modules');

		$this->assertNull($backup->create($this->module, $this->metadata()));
		$this->assertDirectoryDoesNotExist($this->root . '/module_backups');
	}

	public function testRestoreVerifiesChecksumTakesSafetyBackupAndTouchesOnlyRecordedTable(): void {
		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE IF EXISTS `demo_rows`;');
		$db = new ModuleBackupDb([
			'demo_rows' => [['id' => '1', 'value' => 'old']],
			'core_users' => [['id' => '9']],
		]);
		$backup = new ModuleBackup($db, $this->root . '/Modules');
		$entry = $backup->create($this->module, $this->metadata());
		$db->tables['demo_rows'] = [['id' => '2', 'value' => 'current']];
		$db->executed = [];

		$backup->restore($entry['id']);

		$this->assertCount(2, $backup->list(), 'the current table was kept as a pre-restore backup');
		$this->assertContains('pre_restore', array_column($backup->list(), 'kind'));
		$this->assertNotEmpty(array_filter($db->executed, static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM `demo_rows`')));
		$this->assertEmpty(array_filter($db->executed, static fn(string $sql): bool => str_contains($sql, 'core_users')));
		$lockAt = array_search('LOCK TABLES `demo_rows` WRITE;', $db->executed, true);
		$deleteAt = array_search('DELETE FROM `demo_rows`;', $db->executed, true);
		$this->assertIsInt($lockAt);
		$this->assertIsInt($deleteAt);
		$this->assertLessThan($deleteAt, $lockAt, 'write lock is held before current data is replaced');

		$db->tables['demo_rows'] = [['id' => '3', 'value' => 'new current']];
		$db->failOn = 'LOCK TABLES';
		$before = count($db->executed);
		try {
			$backup->restore($entry['id']);
			$this->fail('restore continued without a safety backup');
		} catch (\RuntimeException $error) {
			$this->assertStringContainsString('lock', strtolower($error->getMessage()));
		}
		$this->assertEmpty(array_filter(array_slice($db->executed, $before), static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM')));
		$db->failOn = '';

		file_put_contents($this->root . '/module_backups/' . $entry['id'] . '.sql', "DROP TABLE IF EXISTS `core_users`;\n", FILE_APPEND);
		$before = count($db->executed);
		try {
			$backup->restore($entry['id']);
			$this->fail('tampered backup restored');
		} catch (\RuntimeException $error) {
			$this->assertStringContainsString('checksum', $error->getMessage());
		}
		$this->assertCount($before, $db->executed, 'checksum is checked before any database write');
	}

	public function testRestoreRecreatesAMissingRecordedTableBeforeLockingAndLoadingIt(): void {
		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE IF EXISTS `demo_rows`;');
		$db = new ModuleBackupDb(['demo_rows' => [['id' => '1', 'value' => 'saved']]]);
		$backup = new ModuleBackup($db, $this->root . '/Modules');
		$entry = $backup->create($this->module, $this->metadata());
		unset($db->tables['demo_rows']);
		$db->executed = [];

		$backup->restore($entry['id']);

		$this->assertArrayHasKey('demo_rows', $db->tables);
		$createAt = array_search('CREATE TABLE `demo_rows` (`id` int, `value` text, CHECK (`value` <> \'<x>\'));', $db->executed, true);
		$lockAt = array_search('LOCK TABLES `demo_rows` WRITE;', $db->executed, true);
		$this->assertIsInt($createAt);
		$this->assertIsInt($lockAt);
		$this->assertLessThan($lockAt, $createAt);
	}

	public function testIdentifiersSymlinksDownloadAndDeleteAreBoundToTheBackupDirectory(): void {
		file_put_contents($this->module . '/database_drop.sql', 'DROP TABLE `demo_rows`;');
		$backup = new ModuleBackup(new ModuleBackupDb(['demo_rows' => []]), $this->root . '/Modules');
		$entry = $backup->create($this->module, $this->metadata());
		$this->assertSame($this->root . '/module_backups/' . $entry['id'] . '.sql', $backup->downloadPath($entry['id']));

		foreach (['../outside', 'not-an-id'] as $id) {
			try {
				$backup->downloadPath($id);
				$this->fail('invalid id accepted');
			} catch (\RuntimeException) {
			}
		}

		$sql = $this->root . '/module_backups/' . $entry['id'] . '.sql';
		$real = $sql . '.real';
		rename($sql, $real);
		symlink($real, $sql);
		try {
			$backup->downloadPath($entry['id']);
			$this->fail('symlink accepted');
		} catch (\RuntimeException) {
		}
		unlink($sql);
		rename($real, $sql);
		$backup->delete($entry['id']);
		$this->assertSame([], $backup->list());
	}

	public function testAdminActionsUseDatabasePermissionPostAndSameOriginProtection(): void {
		$controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/Controllers/Admin/Ajax/ModuleBackupAjaxController.php');
		$front = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/index.php');
		$lb = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Commands/ModuleDeleteCommand.php');
		$this->assertStringContainsString("gate('adv', 'database')", $controller);
		$this->assertStringContainsString("REQUEST_METHOD'] ?? 'GET') !== 'POST'", $controller);
		$this->assertStringContainsString('RequestSecurity::enforceSameOrigin()', $front);
		$this->assertStringContainsString('deleteModuleFilesOnly', $lb);
		$this->assertStringNotContainsString('ModuleBackup', $lb, 'LB file-only deletion never touches shared DB backups');
	}

	/** @return array{name:string,title:string,hash_id:string,version:string} */
	private function metadata(): array {
		return ['name' => 'demo', 'title' => 'Demo', 'hash_id' => str_repeat('a', 32), 'version' => '1.2.3'];
	}

	private function remove(string $path): void {
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (scandir($path) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->remove($path . '/' . $entry);
			}
		}
		@rmdir($path);
	}
}

final class ModuleBackupDb {
	/** @var array<string,list<array<string,mixed>>> */
	public array $tables;
	/** @var string[] */
	public array $executed = [];
	public string $failOn = '';
	private array $result = [];

	public function __construct(array $tables) {
		$this->tables = $tables;
	}

	public function query(string $sql, mixed ...$args): bool {
		$this->executed[] = $sql;
		if ($this->failOn !== '' && str_contains($sql, $this->failOn)) {
			return false;
		}
		if (str_contains($sql, 'information_schema')) {
			$table = (string) ($args[0] ?? '');
			$this->result = isset($this->tables[$table]) ? [['table_name' => $table]] : [];
			return true;
		}
		if (preg_match('/^SHOW CREATE TABLE `([^`]+)`/', $sql, $match)) {
			$this->result = isset($this->tables[$match[1]])
				? [['Table' => $match[1], 'Create Table' => 'CREATE TABLE `' . $match[1] . '` (`id` int, `value` text, CHECK (`value` <> \'<x>\'))']]
				: [];
			return $this->result !== [];
		}
		if (preg_match('/^SELECT \* FROM `([^`]+)` LIMIT (\d+) OFFSET (\d+)/', $sql, $match)) {
			$this->result = array_slice($this->tables[$match[1]] ?? [], (int) $match[3], (int) $match[2]);
			return true;
		}
		if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $match)) {
			unset($this->tables[$match[1]]);
			return true;
		}
		if (preg_match('/^CREATE TABLE `([^`]+)`/', $sql, $match)) {
			$this->tables[$match[1]] = [];
			return true;
		}
		return true;
	}

	public function get_row(): array|false {
		return $this->result[0] ?? false;
	}

	public function get_raw_row(): ?array {
		return $this->result[0] ?? null;
	}

	public function get_rows(): array {
		return $this->result;
	}

	public function get_raw_rows(): array {
		return $this->result;
	}

	public function num_rows(): int {
		return count($this->result);
	}

	public function escape(string $value): string {
		return "'" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], $value) . "'";
	}
}
