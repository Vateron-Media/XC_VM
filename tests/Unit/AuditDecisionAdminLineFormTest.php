<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\LineController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The admin line form shows the line as stored: it escapes what it prints
 * and posts it back, so a value read through the row cleaner ('&lt;' for
 * '<') would come back and be saved escaped.
 */
final class AuditDecisionAdminLineFormTest extends TestCase {
	private mixed $rDb;

	protected function setUp(): void {
		$this->rDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $rDb = new TestDb();
		$rDb->exec(InstallSchema::table('lines'));
		$rDb->query("INSERT INTO `lines` (`id`, `username`, `password`, `admin_notes`) VALUES (5, 'us<er>', 'pa<ss> & co', 'a <b>note</b>')");
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rDb;
	}

	public function testTheFormGetsTheLineAsStored(): void {
		$rLine = LineController::storedLine('5');

		$this->assertSame(['us<er>', 'pa<ss> & co', 'a <b>note</b>'], [$rLine['username'], $rLine['password'], $rLine['admin_notes']]);
		$this->assertNull(LineController::storedLine('6'));
		$this->assertNull(LineController::storedLine('x'));
	}
}
