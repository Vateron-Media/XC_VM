<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Tests\Support\InstallSchema;

/**
 * A blocked user agent with nothing in it blocks nobody, and is not saved.
 * stristr($ua, '') answers the whole agent, so one empty row (the form only
 * asks the browser to require the field; the admin API asked nothing) matched
 * every client.
 */
final class BlockedUserAgentEmptyTest extends TestCase {
	public function testAnEmptyEntryMatchesNoAgent(): void {
		$rList = [['id' => 1, 'blocked_ua' => '', 'exact_match' => 0], ['id' => 2, 'blocked_ua' => '   ', 'exact_match' => 0], ['id' => 3, 'blocked_ua' => 'badbot', 'exact_match' => 0]];
		$this->assertFalse(BlocklistService::checkBlockedUAs($rList, 'VLC/3.0.18'));
		$this->assertFalse(BlocklistService::checkBlockedUAs([['id' => 1, 'blocked_ua' => '', 'exact_match' => 1]], ''), 'nor an exact one the empty agent (disallow_empty_user_agents is that setting)');
		$this->assertTrue(BlocklistService::checkBlockedUAs($rList, 'Mozilla BadBot/2'), 'the others still match');
	}

	public function testAnEmptyEntryIsNotSaved(): void {
		defined('STATUS_INVALID_NAME') || define('STATUS_INVALID_NAME', 11);
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('blocked_uas'));
		$rOwn = new ReflectionProperty(BlocklistService::class, 'db');
		$rOwnBefore = $rOwn->getValue();
		$rOwn->setValue(null, $rDb);
		$rBefore = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $rDb;
		try {
			foreach (['', '   '] as $rAgent) {
				$this->assertNotSame(STATUS_SUCCESS, BlocklistService::processUA(['blocked_ua' => $rAgent])['status'] ?? null);
			}
			$rDb->query('SELECT COUNT(*) AS `n` FROM `blocked_uas`;');
			$this->assertSame(0, (int) $rDb->get_row()['n']);
		} finally {
			$rOwn->setValue(null, $rOwnBefore);
			$GLOBALS['db'] = $rBefore;
		}
	}
}
