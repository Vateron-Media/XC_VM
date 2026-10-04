<?php

use XcVm\Core\Module\ModuleMigrator;
use PHPUnit\Framework\TestCase;

/**
 * ModuleMigrator — file-based module schema (install/update/uninstall).
 *
 * Runs the real statement splitter and version selection against a throwaway
 * module directory and a TestDb handle: master schema on install,
 * the delta-only fallback, forward (from, to] ranges on update, teardown, the
 * has()/discover semver rules, comment stripping, and failure propagation.
 */
final class ModuleMigratorTest extends TestCase {

	private string $root;
	private TestDb $db;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/xcvm_mig_' . uniqid('', true);
		mkdir($this->root . '/migrations', 0755, true);
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

	private function writeMigration(string $version, string $sql): void {
		file_put_contents($this->root . '/migrations/' . $version . '.sql', $sql);
	}

	public function testInstallRunsMasterSchemaWhenPresent(): void {
		file_put_contents(
			$this->root . '/database.sql',
			"CREATE TABLE mod_items (id INTEGER PRIMARY KEY AUTO_INCREMENT, name TEXT);\nINSERT INTO mod_items (id, name) VALUES (1, 'seed');"
		);

		$ran = ModuleMigrator::install($this->root, $this->db, '2.0.0');

		$this->assertSame(['database.sql'], $ran);
		$this->db->query('SELECT name FROM mod_items WHERE id = 1;');
		$this->assertSame('seed', $this->db->get_col(), 'master schema applied');
	}

	public function testInstallFallsBackToReplayingDeltasWhenNoMaster(): void {
		$this->writeMigration('1.0.0', 'CREATE TABLE mod_a (id INTEGER PRIMARY KEY AUTO_INCREMENT);');
		$this->writeMigration('1.5.0', 'CREATE TABLE mod_b (id INTEGER PRIMARY KEY AUTO_INCREMENT);');
		$this->writeMigration('3.0.0', 'CREATE TABLE mod_too_new (id INTEGER PRIMARY KEY AUTO_INCREMENT);');

		$ran = ModuleMigrator::install($this->root, $this->db, '2.0.0');

		$this->assertSame(['1.0.0', '1.5.0'], $ran, 'only deltas <= target, ascending');
	}

	public function testUpAppliesOnlyDeltasInRange(): void {
		$this->writeMigration('1.0.0', 'CREATE TABLE mod_a (id INTEGER PRIMARY KEY AUTO_INCREMENT);');
		$this->writeMigration('1.1.0', 'CREATE TABLE mod_b (id INTEGER PRIMARY KEY AUTO_INCREMENT);');
		$this->writeMigration('2.0.0', 'CREATE TABLE mod_c (id INTEGER PRIMARY KEY AUTO_INCREMENT);');

		$ran = ModuleMigrator::up($this->root, $this->db, '1.0.0', '2.0.0');

		$this->assertSame(['1.1.0', '2.0.0'], $ran, 'exclusive of $from, inclusive of $to');
	}

	public function testUninstallRunsDropFileOrNoOps(): void {
		$this->assertSame([], ModuleMigrator::uninstall($this->root, $this->db), 'no teardown file');

		file_put_contents($this->root . '/database_drop.sql', 'DROP TABLE IF EXISTS whatever;');
		$this->assertSame(['database_drop.sql'], ModuleMigrator::uninstall($this->root, $this->db));
	}

	public function testHasReportsAnySchemaPresence(): void {
		$this->assertFalse(ModuleMigrator::has($this->root), 'empty migrations dir, no master/drop');
		$this->writeMigration('1.0.0', 'CREATE TABLE x (id INTEGER);');
		$this->assertTrue(ModuleMigrator::has($this->root));
	}

	public function testDiscoverIgnoresNonSemverAndStripsComments(): void {
		// Non-semver file must be ignored (else it would run and fail here).
		file_put_contents($this->root . '/migrations/notes.sql', 'this is not valid sql at all');
		$this->writeMigration('1.0.0', "-- create the table\nCREATE TABLE mod_c (id INTEGER PRIMARY KEY AUTO_INCREMENT);\n-- trailing comment");

		$ran = ModuleMigrator::install($this->root, $this->db, '1.0.0');

		$this->assertSame(['1.0.0'], $ran);
		$this->db->query('SELECT COUNT(*) FROM mod_c;');
		$this->assertSame('0', (string) $this->db->get_col(), 'commented statement ran cleanly');
	}

	public function testRunFilePropagatesFailure(): void {
		$this->writeMigration('1.0.0', 'THIS IS NOT VALID SQL;');
		$this->expectException(\Exception::class);
		ModuleMigrator::up($this->root, $this->db, null, '1.0.0');
	}
}
