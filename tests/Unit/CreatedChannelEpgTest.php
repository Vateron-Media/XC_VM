<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Epg\CreatedChannelEpg;
use XcVm\Infrastructure\Database\DatabaseFactory;

final class CreatedChannelEpgTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `epg` (`id` INT AUTO_INCREMENT PRIMARY KEY, `epg_name` VARCHAR(255), `epg_file` VARCHAR(300), `days_keep` INT)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testReservedSourceIdFindsSentinelRow(): void {
		$this->rDb->query("INSERT INTO `epg` (`epg_name`, `epg_file`) VALUES ('Other', 'x.xml'), ('Created Channels', '')");
		$this->assertSame(2, CreatedChannelEpg::reservedSourceId());
	}

	public function testReservedSourceIdIsZeroWhenMissing(): void {
		$this->assertSame(0, CreatedChannelEpg::reservedSourceId());
	}

	public function testMigrationSeedIsIdempotent(): void {
		$sql = file_get_contents(__DIR__ . '/../../src/migrations/database/up/093_add_created_channels_epg_source.sql');
		$this->rDb->exec($sql);
		$this->rDb->exec($sql);
		$this->assertSame(1, CreatedChannelEpg::reservedSourceId());
		$this->rDb->query("SELECT COUNT(*) c FROM `epg`");
		$this->assertSame(1, (int) $this->rDb->get_row()['c']);
	}
}
