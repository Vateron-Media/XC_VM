<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Core\Module\StreamFormRegistry;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * An admin API key acts as the administrator that holds it: with the
 * permissions its group lists, as that administrator has them in the panel
 * and as the API's own tables already apply them. A key of the first group,
 * or of an administrator group that lists none, keeps every permission.
 */
final class AuditAdminUsersApiKeyTest extends TestCase {
	private const SUPPORT_PAGES = ['users', 'edit_user', 'folder_watch'];

	private const KEYS = [
		'the first group' => '11111111111111111111111111111111',
		'an administrator group without a list' => '44444444444444444444444444444444',
		'an administrator group with a list' => '33333333333333333333333333333333',
	];

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		// The first group keeps every permission whatever its row lists.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[\"ticket\"]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'),"
			. " (3, 'Support', 1, 0, '" . json_encode(self::SUPPORT_PAGES) . "', 1, '[]'), (4, 'Managers', 1, 0, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES"
			. " (1, 'admin', 1, 1, '', '" . self::KEYS['the first group'] . "'), (2, 'support', 3, 1, '', '" . self::KEYS['an administrator group with a list'] . "'),"
			. " (3, 'manager', 4, 1, '', '" . self::KEYS['an administrator group without a list'] . "'), (5, 'reseller', 2, 1, '', '55555555555555555555555555555555')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		AdminAPIWrapper::$db = $this->rDb;
		AdminApiRegistry::reset();
		StreamFormRegistry::reset();
		$GLOBALS['_ERRORS'] = [0 => 'STATUS_FAILURE', 1 => 'STATUS_SUCCESS'];
	}

	protected function tearDown(): void {
		AdminApiRegistry::reset();
		StreamFormRegistry::reset();
		DatabaseFactory::reset();
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['_ERRORS']);
	}

	private function signIn(string $rKey): bool {
		AdminAPIWrapper::$rKey = $rKey;
		return AdminAPIWrapper::createSession();
	}

	public function testAKeyIsHeldToThePermissionsItsGroupLists(): void {
		$this->assertTrue($this->signIn(self::KEYS['an administrator group with a list']));

		$rHeld = array_values(array_filter(PermissionReference::keys(), static fn(string $rKey): bool => Authorization::check('adv', $rKey)));
		sort($rHeld);
		$rListed = array_values(array_intersect(self::SUPPORT_PAGES, PermissionReference::keys()));
		sort($rListed);

		$this->assertSame($rListed, $rHeld);
		$this->assertSame(self::SUPPORT_PAGES, AdminAPIWrapper::getUserInfo()['permissions']['advanced']);
	}

	/** @return array<string, array{0: string}> */
	public static function fullAdministrators(): array {
		return ['the first group' => [self::KEYS['the first group']], 'an administrator group without a list' => [self::KEYS['an administrator group without a list']]];
	}

	#[DataProvider('fullAdministrators')]
	public function testAKeyOfAFullAdministratorKeepsEveryPermission(string $rKey): void {
		$this->assertTrue($this->signIn($rKey));

		$this->assertSame([], array_values(array_filter(PermissionReference::keys(), static fn(string $rName): bool => !Authorization::check('adv', $rName))));
		$this->assertTrue(Authorization::check('adv', 'a_key_a_module_registers'));
	}

	/** A group stored without a list is one that lists none. */
	public function testAGroupStoredWithoutAListKeepsEveryPermission(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `allowed_pages` = NULL WHERE `group_id` = 4');

		$this->assertTrue($this->signIn(self::KEYS['an administrator group without a list']));
		$this->assertTrue(Authorization::check('adv', 'add_server'));
	}

	/** A module's action runs for every key, and asks for its own permission as the module's pages do. */
	public function testAModuleActionRunsWithThePermissionsOfTheKey(): void {
		AdminApiRegistry::add('get_things', static fn(array $rData): array => ['status' => 1, 'data' => ['listed' => Authorization::check('adv', 'folder_watch'), 'other' => Authorization::check('adv', 'folder_watch_add')]]);

		$this->assertTrue($this->signIn(self::KEYS['an administrator group with a list']));
		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['listed' => true, 'other' => false]], AdminAPIWrapper::moduleAction(AdminApiRegistry::get('get_things'), [], null, null));

		$this->assertTrue($this->signIn(self::KEYS['the first group']));
		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['listed' => true, 'other' => true]], AdminAPIWrapper::moduleAction(AdminApiRegistry::get('get_things'), [], null, null));
	}

	/** A stream saved with a key takes a module's fields as the stream form does: those of the tabs its group may see. */
	public function testAStreamSaveTakesTheModuleFieldsOfTheTabsTheKeyMaySee(): void {
		StreamFormRegistry::add('watch', 'Watch', static fn(): string => '', 'folder_watch');
		StreamFormRegistry::add('other', 'Other', static fn(): string => '', 'folder_watch_add');
		$rPosted = ['module' => ['watch' => ['a' => '1'], 'other' => ['b' => '2']]];

		$this->assertTrue($this->signIn(self::KEYS['an administrator group with a list']));
		$this->assertSame(['watch' => ['a' => '1']], StreamFormRegistry::posted($rPosted));

		$this->assertTrue($this->signIn(self::KEYS['the first group']));
		$this->assertSame($rPosted['module'], StreamFormRegistry::posted($rPosted));
	}

	public function testAKeyThatIsNotAnEnabledAdministratorsOpensNoSession(): void {
		$this->assertFalse($this->signIn('55555555555555555555555555555555'), 'a reseller');
		$this->assertFalse($this->signIn('00000000000000000000000000000000'), 'no user');

		$this->rDb->exec('UPDATE `users` SET `status` = 0 WHERE `id` = 2');
		$this->assertFalse($this->signIn(self::KEYS['an administrator group with a list']), 'a disabled administrator');
	}
}
