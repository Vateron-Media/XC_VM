<?php

use XcVm\Core\Auth\Authorization;
use PHPUnit\Framework\TestCase;

/**
 * Authorization — per-entity access checks for the current user/reseller.
 *
 * Reads the current identity from the $rUserInfo / $rPermissions / $db globals,
 * so each test seeds those and tearDown clears them. The 'user' and 'line'
 * checks scope by the report tree via a real query; the 'adv' check is
 * pure permission logic; and everything short-circuits to false when the
 * identity globals are missing.
 */
final class AuthorizationTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE users (id INTEGER PRIMARY KEY AUTO_INCREMENT, owner_id INTEGER);
			 CREATE TABLE `lines` (id INTEGER PRIMARY KEY AUTO_INCREMENT, member_id INTEGER);
			 INSERT INTO users (id, owner_id) VALUES (100, 10), (200, 20), (300, 99);
			 INSERT INTO `lines` (id, member_id) VALUES (5, 10), (6, 99);'
		);
		$GLOBALS['db'] = $this->db;
		$GLOBALS['rUserInfo'] = ['id' => 10, 'member_group_id' => 2];
		$GLOBALS['rPermissions'] = [
			'all_reports' => [20],
			'is_admin'    => 1,
			'advanced'    => ['export', 'mass_edit'],
		];
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	// ── hasResellerPermissions ───────────────────────────────────────

	public function testHasResellerPermissionsReadsTheFlagMap(): void {
		$GLOBALS['rPermissions'] = ['manage_streams' => 1, 'delete' => 0];
		$this->assertTrue(Authorization::hasResellerPermissions('manage_streams'));
		$this->assertFalse(Authorization::hasResellerPermissions('delete'), 'falsy flag');
		$this->assertFalse(Authorization::hasResellerPermissions('missing'));
	}

	public function testHasResellerPermissionsFalseWhenMapMissing(): void {
		unset($GLOBALS['rPermissions']);
		$this->assertFalse(Authorization::hasResellerPermissions('anything'));
	}

	// ── check(): guards ──────────────────────────────────────────────

	public function testCheckFalseWhenIdentityGlobalsMissing(): void {
		unset($GLOBALS['rUserInfo']);
		$this->assertFalse(Authorization::check('adv', 1));
	}

	// ── check(): 'user' / 'line' scope by report tree ────────────────

	public function testCheckUserMatchesWithinOwnerReportTree(): void {
		// user 100 is owned by 10 (self); reports = [self=10, 20].
		$this->assertTrue(Authorization::check('user', 100));
		// user 200 owned by 20, which is in the report tree.
		$this->assertTrue(Authorization::check('user', 200));
		// user 300 owned by 99 (outside the tree) and not self.
		$this->assertFalse(Authorization::check('user', 300));
	}

	public function testCheckLineMatchesWithinReportTree(): void {
		$this->assertTrue(Authorization::check('line', 5), 'line owned by self (10)');
		$this->assertFalse(Authorization::check('line', 6), 'line owned by 99, outside tree');
	}

	// ── check(): 'adv' permission logic ──────────────────────────────

	public function testCheckAdvRequiresAdmin(): void {
		$GLOBALS['rPermissions']['is_admin'] = 0;
		$this->assertFalse(Authorization::check('adv', 'export'));
	}

	public function testCheckAdvSuperAdminBypassesAdvancedList(): void {
		$GLOBALS['rUserInfo']['member_group_id'] = 1; // super admin group
		$this->assertTrue(Authorization::check('adv', 'anything_not_in_list'));
	}

	public function testCheckAdvNonSuperAdminIsGatedByAdvancedList(): void {
		// member_group_id 2, advanced = ['export', 'mass_edit']
		$this->assertTrue(Authorization::check('adv', 'export'));
		$this->assertFalse(Authorization::check('adv', 'delete_all'));
	}
}
