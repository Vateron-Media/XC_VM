<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Core\Logging\FileLogger;
use XcVm\Tests\Support\InstallSchema;

/** The panel's Database over a connection the test opened (the panel's comes from the xcvm_core extension). */
final class AuditDecisionMigrationsUniqueNamesDb extends Database {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * No two panel accounts share a username. Migration 068 makes the name
 * unique on an updated panel as an install has it, comparing names as the
 * column does: without case and trailing spaces. A panel with names used more
 * than once keeps them: the step fails, changes nothing, names every such
 * name in the update's output and runs again on the next update, which
 * applies it once they are renamed.
 */
final class AuditDecisionMigrationsUniqueNamesTest extends TestCase {
	private const MIGRATION = '068_unique_panel_account_names.sql';

	private TestDb $rDb;

	private Database $rPanelDb;

	private string $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->pdo->exec('SET NAMES utf8mb4');
		// The table as panels before 068 have it: a plain index on the name.
		$rTable = str_replace('UNIQUE KEY `username`', 'KEY `username`', InstallSchema::table('users'), $rReplaced);
		$this->assertSame(1, $rReplaced, 'the install schema has the unique name');
		$this->rDb->exec($rTable);
		$this->rDb->exec('CREATE TABLE `migrations` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `migration` VARCHAR(255) NOT NULL UNIQUE, `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
		foreach (array_diff(array_map('basename', glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: []), [self::MIGRATION]) as $rName) {
			$this->rDb->query('INSERT INTO `migrations` (`migration`) VALUES (?);', $rName);
		}
		$this->rPanelDb = new AuditDecisionMigrationsUniqueNamesDb($this->rDb->pdo);
		$this->rLog = sys_get_temp_dir() . '/xcvm-uniquenames-' . bin2hex(random_bytes(4)) . '.log';
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
	}

	private function account(int $rId, ?string $rName): void {
		$this->rDb->query('INSERT INTO `users` (`id`, `username`, `member_group_id`) VALUES (?, ?, 1);', $rId, $rName);
	}

	/** Pending steps, applied as an update applies them: what it prints. */
	private function update(): string {
		ob_start();
		MigrationRunner::run($this->rPanelDb);
		return (string) ob_get_clean();
	}

	private function unique(): bool {
		$this->rDb->query("SHOW INDEX FROM `users` WHERE `Key_name` = 'username';");
		return $this->rDb->get_row()['Non_unique'] === 0 || $this->rDb->get_row()['Non_unique'] === '0';
	}

	public function testAPanelWithoutSharedNamesGetsTheUniqueName(): void {
		$this->account(1, 'admin');
		$this->account(2, 'reseller');
		$this->account(3, null);
		$this->account(4, null);

		$rOutput = $this->update();

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $rOutput);
		$this->assertStringNotContainsString('[ERR]', $rOutput);
		$this->assertTrue($this->unique());
		foreach (['admin', 'ADMIN', 'admin '] as $rTaken) {
			try {
				$this->account(5, $rTaken);
				$this->fail(json_encode($rTaken) . ' was taken as a second name');
			} catch (\PDOException $e) {
				$this->assertSame('23000', $e->getCode());
			}
		}
		$this->account(5, null);
	}

	public function testSharedNamesAreNamedAndTheStepWaitsForThem(): void {
		$rAccounts = [1 => 'admin', 2 => 'Admin', 3 => 'bob', 4 => 'bob ', 5 => 'carl', 6 => '', 7 => '', 8 => null, 9 => null];
		foreach ($rAccounts as $rId => $rName) {
			$this->account($rId, $rName);
		}
		$this->rDb->query('SELECT `id`, `username` FROM `users` ORDER BY `id`;');
		$rBefore = $this->rDb->get_rows();

		$rOutput = $this->update();
		$this->assertStringContainsString('[FAIL] ' . self::MIGRATION . ' (not recorded — will retry on next run)', $rOutput);

		// Nothing changed: the accounts and the plain index are as they were.
		$this->assertFalse($this->unique());
		$this->rDb->query('SELECT `id`, `username` FROM `users` ORDER BY `id`;');
		$this->assertSame($rBefore, $this->rDb->get_rows());

		// The update's output names every name used more than once.
		preg_match('/^  \\[ERR\\]  .*Panel account names used more than once.*$/m', $rOutput, $rMessage);
		$this->assertNotEmpty($rMessage, $rOutput);
		$this->assertMatchesRegularExpression("/rename all but one and update again: '', '(admin|Admin)', '(bob|bob )'$/", $rMessage[0]);
		$this->assertStringNotContainsString('carl', $rMessage[0]);

		// The operator renames them; the next update applies the step.
		$this->rDb->query("UPDATE `users` SET `username` = CONCAT(`username`, '-', `id`) WHERE `id` IN (2, 4, 7);");
		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->update());
		$this->assertTrue($this->unique());
	}

	/** An install and an update end with the same index. */
	public function testAnInstallAndAnUpdateHaveTheSameIndex(): void {
		$this->update();
		$this->rDb->query("SHOW INDEX FROM `users` WHERE `Key_name` = 'username';");
		$rUpdated = array_diff_key($this->rDb->get_row(), ['Table' => 0, 'Cardinality' => 0]);

		$this->rDb->exec('DROP TABLE `users`');
		$this->rDb->exec(InstallSchema::table('users'));
		$this->rDb->query("SHOW INDEX FROM `users` WHERE `Key_name` = 'username';");

		$this->assertSame($rUpdated, array_diff_key($this->rDb->get_row(), ['Table' => 0, 'Cardinality' => 0]));
	}
}
