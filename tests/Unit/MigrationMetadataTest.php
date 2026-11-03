<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrationGuard;
use XcVm\Core\Database\QueryHelper;
use XcVm\Tests\Support\QueryLogDb;

final class MigrationMetadataTest extends TestCase {
	public function testThousandsOfRowsReadTheirTargetColumnsOnlyOnce(): void {
		$inner = new TestDb();
		$inner->exec("CREATE TABLE `import_rows` (`id` int NOT NULL, `amount` decimal(16,4) NOT NULL DEFAULT 1.2500, `optional` int NULL DEFAULT NULL, `label` varchar(80) NOT NULL DEFAULT 'default', `forced_int` int NOT NULL, `forced_text` varchar(80) NOT NULL)");
		$db = new QueryLogDb($inner);
		$guard = new MigrationGuard();
		for ($id = 1; $id <= 2000; $id++) {
			$this->assertSame(['id' => $id, 'amount' => '1.2500', 'optional' => null, 'label' => 'default', 'forced_int' => 0, 'forced_text' => ''], $guard->prepareRow($db, 'import_rows', ['id' => $id, 'amount' => '', 'optional' => '', 'unknown' => 'discard']));
		}
		$this->assertCount(1, $db->rQueries, 'one metadata query for 2,000 rows, rather than one for each row');
	}

	public function testTwoTargetDatabasesNeverShareTheirColumnDefaults(): void {
		$first = new TestDb();
		$second = new TestDb();
		$first->exec("CREATE TABLE `import_rows` (`label` varchar(80) DEFAULT 'first')");
		$second->exec("CREATE TABLE `import_rows` (`label` varchar(80) DEFAULT 'second')");
		$guard = new MigrationGuard();
		$this->assertSame(['label' => 'first'], $guard->prepareRow($first, 'import_rows', []));
		$this->assertSame(['label' => 'second'], $guard->prepareRow($second, 'import_rows', []));
	}

	public function testANewImportReadsTheCurrentSchema(): void {
		$db = new TestDb();
		$db->exec('CREATE TABLE `import_rows` (`id` int NOT NULL)');
		$this->assertSame(['id' => 7], (new MigrationGuard())->prepareRow($db, 'import_rows', ['id' => 7]));
		$db->exec("ALTER TABLE `import_rows` ADD `added` varchar(80) DEFAULT 'new'");
		$this->assertSame(['id' => 7, 'added' => 'new'], (new MigrationGuard())->prepareRow($db, 'import_rows', ['id' => 7]));
	}

	public function testAMetadataReadFailureMarksTheImportFailed(): void {
		$db = new QueryLogDb(new TestDb());
		$db->rRefuse = '/information_schema/';
		$guard = new MigrationGuard();
		try {
			$guard->prepareRow($db, 'import_rows', ['id' => 7]);
			$this->fail('A failed metadata read must not become an empty inserted row.');
		} catch (RuntimeException $error) {
			$this->assertTrue($guard->hasFailed());
		}
	}

	public function testAnAbsentTargetTableMarksTheImportFailed(): void {
		$guard = new MigrationGuard();
		try {
			$guard->prepareRow(new TestDb(), 'missing', ['id' => 7]);
			$this->fail('Missing columns must not become an empty inserted row.');
		} catch (RuntimeException $error) {
			$this->assertTrue($guard->hasFailed());
		}
	}

	public function testOrdinaryFormsStillReadFreshColumnsAndKeepOnlyExistingFields(): void {
		$db = new TestDb();
		$db->exec("CREATE TABLE `import_rows` (`id` int NOT NULL, `label` varchar(80) DEFAULT 'old')");
		$before = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $db;
		try {
			$this->assertSame(['id' => 7], QueryHelper::verifyPostTable('import_rows', ['id' => 7], true));
			$db->exec("ALTER TABLE `import_rows` ADD `added` varchar(80) DEFAULT 'new'");
			$this->assertSame(['id' => 7, 'label' => 'old', 'added' => 'new'], QueryHelper::verifyPostTable('import_rows', ['id' => 7]));
		} finally {
			$GLOBALS['db'] = $before;
		}
	}
}
