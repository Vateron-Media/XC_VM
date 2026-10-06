<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Authorization;

/**
 * An administrator group whose permission list is stored as nothing (NULL or
 * empty text, as an import or a hand-made row can leave it) is a group that
 * lists no permissions: its members keep every power, as the members of a
 * group that stores an empty list do, and as the admin API and the reserved
 * groups already read such a row. A group that stores a list is held to it.
 *
 * A panel request reads the list in two places, each after a framework boot:
 * the statement is run here as it stands there.
 */
final class AuditAdminMisc2PermissionListTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** Sign in a member of a group that stores $rStored, its list read as the file reads it. */
	private function signIn(string $rFile, ?string $rStored): void {
		$this->assertSame(1, preg_match('/^\t+(\$rPermissions\[[\'"]advanced[\'"]\] = .+;)$/m', (string) file_get_contents(MAIN_HOME . $rFile), $rStatement), $rFile . ' reads the list once');

		$rPermissions = ['is_admin' => 1, 'allowed_pages' => $rStored];
		// The statement is this repository's own source line, matched above: no input reaches it.
		eval($rStatement[1]);
		$GLOBALS['rPermissions'] = $rPermissions;
	}

	/** @return array<string, array{0: string}> */
	public static function readers(): array {
		return [
			'a page' => ['Infrastructure/Bootstrap/AdminScopeBootstrap.php'],
			'a table' => ['Public/Controllers/Admin/TableController.php'],
		];
	}

	#[DataProvider('readers')]
	public function testAGroupThatStoresNoListKeepsEveryPower(string $rFile): void {
		foreach ([null, '', '[]'] as $rStored) {
			$this->signIn($rFile, $rStored);

			$this->assertTrue(Authorization::check('adv', 'settings'), var_export($rStored, true));
			$this->assertSame([], $GLOBALS['rPermissions']['advanced'], var_export($rStored, true));
		}
	}

	#[DataProvider('readers')]
	public function testAGroupThatStoresAListIsHeldToIt(string $rFile): void {
		$this->signIn($rFile, '["users","edit_user"]');

		$this->assertTrue(Authorization::check('adv', 'users'));
		$this->assertFalse(Authorization::check('adv', 'settings'));
	}
}
