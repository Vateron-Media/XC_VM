<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Epg\CreatedChannelEpg;
use XcVm\Domain\Epg\EpgService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The reserved created-channel EPG source ('Created Channels') is identified by
 * name. EpgService::process must not let the admin create a colliding source or
 * rename another one onto it — including case/whitespace variants, since MySQL
 * matches the name case- and trailing-space-insensitively.
 */
final class EpgReservedSourceGuardTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `epg` (`id` INT AUTO_INCREMENT PRIMARY KEY, `epg_name` VARCHAR(255) COLLATE utf8_unicode_ci, `epg_file` VARCHAR(300), `days_keep` INT)');
		// id 1 = the reserved sentinel, id 2 = an ordinary source.
		$this->rDb->query("INSERT INTO `epg` (`epg_name`, `epg_file`) VALUES ('Created Channels', ''), ('Normal', 'n.xml')");
		DatabaseFactory::set($this->rDb);
		(new ReflectionProperty(EpgService::class, 'db'))->setValue(null, null);
		$GLOBALS['db'] = $this->rDb;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
	}

	protected function tearDown(): void {
		(new ReflectionProperty(EpgService::class, 'db'))->setValue(null, null);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	private function rowCount(): int {
		$this->rDb->query('SELECT COUNT(*) `c` FROM `epg`;');
		return (int) $this->rDb->get_row()['c'];
	}

	private function nameOf(int $rId): ?string {
		$this->rDb->query('SELECT `epg_name` FROM `epg` WHERE `id` = ?;', $rId);
		return $this->rDb->get_row()['epg_name'] ?? null;
	}

	public function testCreateWithReservedNameIsRefused(): void {
		$rBefore = $this->rowCount();
		$rResult = EpgService::process(['epg_name' => 'Created Channels', 'epg_file' => 'x.xml']);
		$this->assertSame(STATUS_FAILURE, $rResult['status']);
		$this->assertSame($rBefore, $this->rowCount());
	}

	public function testCreateWithReservedNameDifferentCaseIsRefused(): void {
		$rBefore = $this->rowCount();
		$rResult = EpgService::process(['epg_name' => 'created CHANNELS', 'epg_file' => 'x.xml']);
		$this->assertSame(STATUS_FAILURE, $rResult['status']);
		$this->assertSame($rBefore, $this->rowCount());
	}

	public function testCreateWithReservedNameTrailingSpaceIsRefused(): void {
		$rBefore = $this->rowCount();
		$rResult = EpgService::process(['epg_name' => '  Created Channels  ', 'epg_file' => 'x.xml']);
		$this->assertSame(STATUS_FAILURE, $rResult['status']);
		$this->assertSame($rBefore, $this->rowCount());
	}

	public function testEditRenamingAnotherSourceToReservedNameIsRefused(): void {
		$rResult = EpgService::process(['edit' => 2, 'epg_name' => 'Created Channels']);
		$this->assertSame(STATUS_FAILURE, $rResult['status']);
		$this->assertSame('Normal', $this->nameOf(2));
	}

	public function testEditingTheReservedRowIsRefused(): void {
		$rResult = EpgService::process(['edit' => CreatedChannelEpg::reservedSourceId(), 'epg_name' => 'Hijacked']);
		$this->assertSame(STATUS_FAILURE, $rResult['status']);
		$this->assertSame('Created Channels', $this->nameOf(1));
	}

	public function testAnOrdinaryCreateStillSucceeds(): void {
		$rBefore = $this->rowCount();
		$rResult = EpgService::process(['epg_name' => 'My Guide', 'epg_file' => 'http://example/epg.xml']);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame($rBefore + 1, $this->rowCount());
	}
}
