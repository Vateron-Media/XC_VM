<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StatusCommand;
use XcVm\Cli\Commands\UpdateCommand;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Updates\ReleaseArchiveInspector;
use XcVm\Public\Controllers\Admin\DashboardController;
use XcVm\Tests\Support\InstallSchema;

/** The panel's Database over a connection the test opened: a refused statement answers false, as in production. */
final class UpdateSafetyDb extends Database {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * What an update leaves an operator to go on: a migration that fails is
 * reported (update.log, the panel log, the dashboard's schema row,
 * db:migrate's status), MAIN dumps the database before the migrations run,
 * and a rollback reverses the schema only for a target whose runner keeps it
 * reversed.
 */
final class UpdateSafetyTest extends TestCase {
	private string $rDir;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-update-safety-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		$this->rDb = new TestDb();
		FileLogger::setLogFile($this->rDir . 'panel.log');
		class_exists(\XcVm\Core\Config\ConstantsInitializer::class); // defines XC_VM_VERSION
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @param array<string,string> $rFiles name => SQL */
	private function migrations(array $rFiles): string {
		mkdir($this->rDir . 'database/up', 0777, true);
		foreach ($rFiles as $rName => $rSql) {
			file_put_contents($this->rDir . 'database/up/' . $rName, $rSql);
		}
		return $this->rDir . 'database/';
	}

	/** @return array{0: string[], 1: string} what run() returned, and what it printed */
	private function migrate(string $rDatabaseDir): array {
		ob_start();
		$rFailed = MigrationRunner::run(new UpdateSafetyDb($this->rDb->pdo), $rDatabaseDir);
		return [$rFailed, (string) ob_get_clean()];
	}

	private function recorded(): array {
		$this->rDb->query('SELECT `migration` FROM `migrations` ORDER BY `migration`;');
		return array_column($this->rDb->get_rows(), 'migration');
	}

	public function testRunReportsNoFailureWhenEveryFileApplies(): void {
		$rDir = $this->migrations(['001_a.sql' => 'CREATE TABLE `probe` (`id` INT);', '002_b.sql' => 'INSERT INTO `probe` VALUES (1);']);

		[$rFailed] = $this->migrate($rDir);

		$this->assertSame([], $rFailed);
		$this->assertSame(['001_a.sql', '002_b.sql'], $this->recorded());
		$this->assertFileDoesNotExist($this->rDir . 'panel.log');
	}

	public function testRunReportsTheFileThatFailedWithItsCauseAndDoesNotRecordIt(): void {
		$rDir = $this->migrations(['001_a.sql' => 'CREATE TABLE `probe` (`id` INT);', '002_b.sql' => 'SELECT * FROM `no_such_table`;']);

		[$rFailed, $rOutput] = $this->migrate($rDir);

		$this->assertCount(1, $rFailed);
		$this->assertStringStartsWith('002_b.sql: ', $rFailed[0]);
		$this->assertStringContainsString('no_such_table', $rFailed[0], 'the driver message');
		$this->assertSame(['001_a.sql'], $this->recorded());
		$this->assertStringContainsString('[FAIL] 002_b.sql', $rOutput);
		// The panel log names the file, without the driver message.
		$rLogged = array_values(array_filter(array_map(static fn(string $rLine): ?array => json_decode((string) base64_decode($rLine), true), file($this->rDir . 'panel.log', FILE_IGNORE_NEW_LINES)), static fn(?array $rRow): bool => ($rRow['type'] ?? '') === 'migration'));
		$this->assertCount(1, $rLogged);
		$rLogged = $rLogged[0];
		$this->assertStringContainsString('002_b.sql', $rLogged['message']);
		$this->assertStringNotContainsString('no_such_table', $rLogged['message']);
	}

	public function testTheSchemaRowShowsTheLastRun(): void {
		$this->rDb->exec(InstallSchema::table('settings'));
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1);');
		$rDb = new UpdateSafetyDb($this->rDb->pdo);
		$rState = function (): string {
			$this->rDb->query('SELECT `status_uuid` FROM `settings`;');
			return DashboardController::schemaCheck((string) $this->rDb->get_col(), XC_VM_VERSION, [])['state'];
		};

		StatusCommand::markSchema($rDb, true);
		$this->assertSame('ok', $rState());
		StatusCommand::markSchema($rDb, false);
		$this->assertSame('warn', $rState(), 'a mark left from a clean run is cleared');
		StatusCommand::markSchema($rDb, true);
		$this->assertSame('ok', $rState());
	}

	/**
	 * A release archive as `make main` builds it (`tar -C <dir> .`, members named
	 * './<path>'), or with plain member names.
	 *
	 * @param list<string> $rFiles paths inside the archive
	 */
	private function archive(array $rFiles, bool $rDotted = true): string {
		$rStage = $this->rDir . 'stage-' . bin2hex(random_bytes(3)) . '/';
		foreach ($rFiles as $rFile) {
			@mkdir(dirname($rStage . $rFile), 0777, true);
			file_put_contents($rStage . $rFile, '-- ' . $rFile);
		}
		$rArchive = $this->rDir . bin2hex(random_bytes(3)) . '.tar.gz';
		exec(implode(' ', array_map('escapeshellarg', array_merge(['tar', '-czf', $rArchive, '-C', $rStage], $rDotted ? ['.'] : array_values(array_unique(array_map(static fn(string $rFile): string => explode('/', $rFile)[0], $rFiles)))))), $rOut, $rCode);
		$this->assertSame(0, $rCode);
		return $rArchive;
	}

	public function testAReleaseArchiveListsItsMigrationsWhateverItsMemberNames(): void {
		foreach ([true, false] as $rDotted) {
			$rFiles = ReleaseArchiveInspector::listSubpathFiles($this->archive(['migrations/database/up/001_a.sql', 'migrations/database/up/002_b.sql', 'migrations/database/down/002_b.sql'], $rDotted), 'migrations/database/up');
			sort($rFiles);
			$this->assertSame(['001_a.sql', '002_b.sql'], $rFiles, $rDotted ? './ members' : 'plain members');
		}
	}

	public function testARollbackReversesTheSchemaOnlyForATargetUpTo253(): void {
		// 2.6.0 on: its runner applies up/ again, which the updater leaves in place.
		$this->assertNull(UpdateCommand::rollbackMigrations($this->archive(['migrations/database/up/001_a.sql', 'migrations/deleted_files.txt'])));
		// Up to 2.5.3: its runner reads migrations/*.sql.
		$this->assertSame(['001_a.sql'], UpdateCommand::rollbackMigrations($this->archive(['migrations/001_a.sql', 'migrations/deleted_files.txt'])));
		// No list: never "reverse everything".
		$this->assertNull(UpdateCommand::rollbackMigrations($this->archive(['update', 'migrations/deleted_files.txt'])));
	}

	public function testThePreUpdateDumpIsSizedFromTheDumpedTablesAndFitsTwiceOver(): void {
		$this->rDb->exec('CREATE TABLE `lines` (`id` INT, `pad` CHAR(200)) ENGINE=InnoDB;');
		$this->rDb->exec('CREATE TABLE `lines_activity` (`id` INT, `pad` CHAR(200)) ENGINE=InnoDB;');
		$this->rDb->exec("INSERT INTO `lines_activity` SELECT `seq`, 'x' FROM `seq_1_to_20000`;");
		$this->rDb->pdo->query('ANALYZE TABLE `lines`, `lines_activity`;')->fetchAll();
		$this->rDb->query("SELECT `data_length` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND `table_name` = 'lines';");
		$rLines = (int) $this->rDb->get_col();

		$this->assertSame($rLines, BackupService::estimateSize(new UpdateSafetyDb($this->rDb->pdo)), 'lines_activity is not dumped');

		$rSlack = 512 * 1024 * 1024;
		$this->assertFalse(BackupService::roomForDump(100, 200 + $rSlack - 1));
		$this->assertTrue(BackupService::roomForDump(100, 200 + $rSlack));
	}

	public function testOnePreUpdateDumpIsKept(): void {
		foreach (['pre_update_a.sql', 'pre_update_b.sql', 'backup_x.sql', 'pre_rollback_y.sql', 'pre_restore_z.sql'] as $rName) {
			touch($this->rDir . $rName);
		}

		BackupService::keepOnly($this->rDir, 'pre_update_', $this->rDir . 'pre_update_b.sql');

		$rLeft = array_values(array_filter(scandir($this->rDir), static fn(string $rName): bool => str_ends_with($rName, '.sql')));
		$this->assertSame(['backup_x.sql', 'pre_restore_z.sql', 'pre_rollback_y.sql', 'pre_update_b.sql'], $rLeft);
	}
}
