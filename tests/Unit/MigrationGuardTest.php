<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrationGuard;
use XcVm\Core\Database\DatabaseHandler;

final class MigrationGuardTest extends TestCase {
	public function testABooleanFailureCannotBeMistakenForAnImportedRow(): void {
		$db = new class () extends DatabaseHandler {
			public function __construct() {
			}

			public function query(string $query, mixed $buffered = false) {
				return false;
			}

			public function close_mysql() {
				return true;
			}
		};
		$guard = new MigrationGuard();
		try {
			$guard->query($db, 'INSERT INTO `lines` (`id`) VALUES (?)', 1);
			$this->fail('A failed write must interrupt this import chunk.');
		} catch (RuntimeException $error) {
			$this->assertTrue($guard->hasFailed());
		}
	}

	public function testSqlExceptionsStayFailedEvenIfTheImporterCatchesThem(): void {
		$db = new TestDb();
		$guard = new MigrationGuard();
		try {
			$guard->query($db, 'SELECT * FROM `missing_table`');
			$this->fail('The failing SQL must throw.');
		} catch (PDOException $error) {
			$this->assertTrue($guard->hasFailed());
		}
		$guard->query($db, 'SELECT 1');
		$this->assertTrue($guard->hasFailed(), 'a later successful statement does not authorize source cleanup');
	}
}
