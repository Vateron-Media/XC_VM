<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrateCommand;
use XcVm\Core\Database\DatabaseHandler;

/**
 * The handle on the backup database (xc_vm_migrate) stays on it when its
 * connection drops. It used to reconnect to the panel's schema (its session
 * times out after 60 s idle), and the migration then read the panel's own
 * tables for the backup's and, at its end, dropped them as the backup's.
 */
final class MigrateHandleReconnectTest extends TestCase {
	/** @return DatabaseHandler&object{rConnects: list<bool>} */
	private function handle(bool $rMigrate): DatabaseHandler {
		return new class (migrate: $rMigrate) extends DatabaseHandler {
			/** @var list<bool> */
			public array $rConnects = [];

			public function db_connect(bool $migrate = false, ?bool $graceful = null) {
				$this->rConnects[] = $migrate;
				return true;
			}

			public function close_mysql() {
				return true;
			}
		};
	}

	public function testAReconnectStaysOnTheHandlesOwnSchema(): void {
		$rBackup = $this->handle(true);
		$this->assertTrue($rBackup->reconnect());
		$this->assertSame([true, true], $rBackup->rConnects, 'the backup database again, not the panel\'s');

		$rPanel = $this->handle(false);
		$this->assertTrue($rPanel->reconnect());
		$this->assertSame([false, false], $rPanel->rConnects);
	}

	/** Nothing of the backup is saved aside and dropped through a handle that is on the panel's schema. */
	public function testTheBackupIsOnlyEmptiedOnASchemaOfItsOwn(): void {
		$rPanel = new TestDb();
		$rBackup = new TestDb();
		$this->assertTrue(MigrateCommand::onItsOwnSchema($rBackup, $rPanel));
		$this->assertFalse(MigrateCommand::onItsOwnSchema($rPanel, $rPanel), 'the same schema: refused');
	}
}
