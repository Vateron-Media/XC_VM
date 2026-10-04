<?php

use XcVm\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner::rollback() — reversing migrations on a version downgrade.
 *
 * Runs the real diff/down-file/tracking-row logic against a throwaway
 * migrations directory and a TestDb handle (mirrors
 * ModuleMigratorTest's approach for the module-side equivalent).
 */
final class MigrationRunnerRollbackTest extends TestCase {

	private string $root;
	private TestDb $db;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/xcvm_rollback_' . uniqid('', true);
		mkdir($this->root, 0755, true);
		$this->db = new TestDb();
	}

	protected function tearDown(): void {
		$this->rrmdir($this->root);
	}

	private function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	private function writeDown(string $migrationName, string $sql): void {
		if (!is_dir($this->root . '/down')) {
			mkdir($this->root . '/down', 0755, true);
		}
		file_put_contents($this->root . '/down/' . $migrationName, $sql);
	}

	/** Mark $names as already-applied in the migrations tracking table. */
	private function markApplied(array $names): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `migrations` (
			`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
			`migration` VARCHAR(255) NOT NULL UNIQUE,
			`applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		);");
		foreach ($names as $name) {
			$this->db->query('INSERT INTO `migrations` (`migration`) VALUES (?);', $name);
		}
	}

	private function appliedNames(): array {
		$this->db->query('SELECT `migration` FROM `migrations`;');
		return array_column($this->db->get_rows(), 'migration');
	}

	public function testRollbackReversesMigrationsMissingFromTargetInDescendingOrder(): void {
		$this->markApplied(['001_a.sql', '002_b.sql', '003_c.sql']);
		$this->db->query('CREATE TABLE probe (id INTEGER PRIMARY KEY AUTO_INCREMENT, tag TEXT);');
		$this->writeDown('002_b.sql', "INSERT INTO probe (tag) VALUES ('down-002');");
		$this->writeDown('003_c.sql', "INSERT INTO probe (tag) VALUES ('down-003');");

		$result = MigrationRunner::rollback($this->db, ['001_a.sql'], $this->root . '/');

		$this->assertSame(['003_c.sql', '002_b.sql'], $result['reversed'], 'descending order');
		$this->assertSame([], $result['skipped']);

		$this->db->query('SELECT tag FROM probe ORDER BY id;');
		$this->assertSame(['down-003', 'down-002'], $this->db->get_column(), 'later down ran before earlier one');
		$this->assertSame(['001_a.sql'], $this->appliedNames(), 'reversed rows removed from tracking');
	}

	public function testRollbackSkipsMigrationsWithoutADownFile(): void {
		$this->markApplied(['001_a.sql', '002_b.sql']);
		// No .down.sql written for 002_b.sql.

		$result = MigrationRunner::rollback($this->db, ['001_a.sql'], $this->root . '/');

		$this->assertSame([], $result['reversed']);
		$this->assertSame(['002_b.sql'], $result['skipped']);
		$this->assertSame(['001_a.sql', '002_b.sql'], $this->appliedNames(), 'skipped row stays applied');
	}

	public function testRollbackReturnsEmptyWhenNothingNeedsReversing(): void {
		$this->markApplied(['001_a.sql']);

		$result = MigrationRunner::rollback($this->db, ['001_a.sql'], $this->root . '/');

		$this->assertSame(['reversed' => [], 'skipped' => []], $result);
	}

	public function testRollbackHandlesMissingMigrationsTable(): void {
		// Fresh DB — ensureTable() must create the table without erroring.
		$result = MigrationRunner::rollback($this->db, [], $this->root . '/');

		$this->assertSame(['reversed' => [], 'skipped' => []], $result);
	}

	public function testRollbackThrowsWhenADownFileFails(): void {
		$this->markApplied(['001_a.sql']);
		$this->writeDown('001_a.sql', 'THIS IS NOT VALID SQL;');

		$this->expectException(\RuntimeException::class);
		MigrationRunner::rollback($this->db, [], $this->root . '/');
	}
}
