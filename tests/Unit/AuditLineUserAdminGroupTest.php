<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Administrator accounts and administrator groups are managed by a full
 * administrator: a member of the first group, or of an administrator group
 * that has no permission list. An administrator whose group holds it to a
 * list manages the other users and the other groups: it does not change or
 * delete an administrator's account, give a user an administrator group,
 * change an administrator group or make a group one. The group as it is
 * stored decides who is a full administrator.
 */
final class AuditLineUserAdminGroupTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const MANAGER = 3;
	private const RESELLER = 5;

	private const ADMINS = 1;
	private const RESELLERS = 2;
	private const SUPPORT_GROUP = 3;
	private const MANAGERS = 4;
	private const AGENTS = 7;

	private const SUPPORT_PAGES = ['mng_regusers', 'add_reguser', 'edit_reguser', 'mass_edit_users', 'mng_groups', 'add_group', 'edit_group'];

	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_2fa', 'api_tokens', 'users_groups', 'users_packages', 'users_credits_logs', 'users_logs', 'tickets', 'tickets_replies', 'lines'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '" . json_encode(self::SUPPORT_PAGES) . "', 1, '[]'),"
			. " (4, 'Managers', 1, 0, '[]', 1, '[]'), (7, 'Agents', 0, 1, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `password`, `member_group_id`, `credits`, `owner_id`, `email`, `override_packages`, `api_key`, `notes`, `reseller_dns`, `status`) VALUES"
			. " (1, 'admin', 'admin-hash', 1, 0, 0, '', '[]', '', '', '', 1), (2, 'support', 'support-hash', 3, 0, 0, '', '[]', '', '', '', 1),"
			. " (3, 'manager', 'manager-hash', 4, 0, 0, '', '[]', '', '', '', 1), (5, 'reseller', 'reseller-hash', 2, 0, 0, '', '[]', '', '', '', 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->actAs(self::SUPPORT);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The administrator whose request this is, as the panel loads it. */
	private function actAs(int $rUserID): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
		$GLOBALS['rPermissions'] = AuthRepository::getPermissions((int) $GLOBALS['rUserInfo']['member_group_id']);
		$GLOBALS['rPermissions']['advanced'] = json_decode($GLOBALS['rPermissions']['allowed_pages'], true);
	}

	/** @return array<string, mixed> */
	private function user(int $rUserID): array {
		return UserRepository::getRegisteredUserById($rUserID) ?? [];
	}

	/** @return array<string, mixed> */
	private function group(int $rGroupID): array {
		return GroupService::getById($rGroupID) ?: [];
	}

	/**
	 * The user form of $rUserID as the panel shows it, with $rChange made.
	 *
	 * @param array<string, mixed> $rChange
	 * @return array<string, mixed>
	 */
	private function userForm(int $rUserID, array $rChange): array {
		$rUser = $this->user($rUserID);
		return $rChange + ['edit' => (string) $rUser['id'], 'username' => $rUser['username'], 'password' => '', 'member_group_id' => (string) $rUser['member_group_id'], 'email' => '', 'owner_id' => '0', 'reseller_dns' => '', 'notes' => ''];
	}

	/**
	 * The group form of $rGroupID as the panel shows it, with $rChange made.
	 *
	 * @param array<string, mixed> $rChange
	 * @return array<string, mixed>
	 */
	private function groupForm(int $rGroupID, array $rChange): array {
		$rGroup = $this->group($rGroupID);
		$rForm = ['edit' => (string) $rGroupID, 'group_name' => $rGroup['group_name'], 'permissions_selected' => $rGroup['allowed_pages'], 'groups_selected' => '[]', 'packages_selected' => '[]', 'notice_html' => ''];
		foreach (['is_admin', 'is_reseller'] as $rFlag) {
			if ($rGroup[$rFlag]) {
				$rForm[$rFlag] = 'on';
			}
		}
		return $rChange + $rForm;
	}

	// ── users ───────────────────────────────────────────────────────

	/** @return array<string, array{0: int}> */
	public static function administratorGroups(): array {
		return ['the first group' => [self::ADMINS], 'a group without a permission list' => [self::MANAGERS], 'its own group' => [self::SUPPORT_GROUP]];
	}

	#[DataProvider('administratorGroups')]
	public function testAnAdministratorHeldToAListDoesNotGiveAUserAnAdministratorGroup(int $rGroup): void {
		$rResult = UserService::process($this->userForm(self::RESELLER, ['member_group_id' => (string) $rGroup]));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertEquals(self::RESELLERS, $this->user(self::RESELLER)['member_group_id']);
	}

	public function testItDoesNotMoveItsOwnAccountToTheFirstGroup(): void {
		$rResult = UserService::process($this->userForm(self::SUPPORT, ['member_group_id' => (string) self::ADMINS]));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertEquals(self::SUPPORT_GROUP, $this->user(self::SUPPORT)['member_group_id']);
	}

	public function testItDoesNotChangeAnAdministratorsAccount(): void {
		$rResult = UserService::process($this->userForm(self::ADMIN, ['password' => 'a-new-password', 'email' => 'new@example.com']));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertSame('admin-hash', $this->user(self::ADMIN)['password']);
		$this->assertSame('', $this->user(self::ADMIN)['email']);
	}

	public function testItDoesNotAddAnAdministrator(): void {
		$rResult = UserService::process(['username' => 'another', 'password' => 'secret-secret', 'member_group_id' => (string) self::ADMINS, 'email' => '', 'owner_id' => '0', 'reseller_dns' => '', 'notes' => '', 'api_key' => str_repeat('0a', 16)]);

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->rDb->query("SELECT COUNT(*) FROM `users` WHERE `username` = 'another'");
		$this->assertSame(0, (int) $this->rDb->get_col());
	}

	public function testTheGroupThatIsStoredIsTheGroupThatWasChecked(): void {
		UserService::process($this->userForm(self::RESELLER, ['member_group_id' => '0.6']));

		$this->assertNotEquals(self::ADMINS, $this->user(self::RESELLER)['member_group_id']);
	}

	public function testItManagesTheOtherUsers(): void {
		$rResult = UserService::process($this->userForm(self::RESELLER, ['notes' => 'called', 'member_group_id' => (string) self::AGENTS]));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('called', $this->user(self::RESELLER)['notes']);
		$this->assertEquals(self::AGENTS, $this->user(self::RESELLER)['member_group_id']);

		$rResult = UserService::process(['username' => 'another', 'password' => 'secret-secret', 'member_group_id' => (string) self::RESELLERS, 'email' => '', 'owner_id' => '0', 'reseller_dns' => '', 'notes' => '', 'api_key' => str_repeat('0a', 16)]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::RESELLERS, $this->user((int) $rResult['data']['insert_id'])['member_group_id']);
	}

	public function testAMassEditDoesNotGiveOutAnAdministratorGroup(): void {
		$rResult = UserService::massEdit(['users_selected' => json_encode([self::RESELLER]), 'c_member_group_id' => 'on', 'member_group_id' => (string) self::ADMINS]);

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertEquals(self::RESELLERS, $this->user(self::RESELLER)['member_group_id']);
	}

	public function testAMassEditLeavesAdministratorsAsTheyAre(): void {
		$rResult = UserService::massEdit(['users_selected' => json_encode([self::ADMIN, self::MANAGER, self::RESELLER]), 'c_status' => 'on', 'c_member_group_id' => 'on', 'member_group_id' => (string) self::AGENTS]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals([1, self::ADMINS], [$this->user(self::ADMIN)['status'], $this->user(self::ADMIN)['member_group_id']]);
		$this->assertEquals([1, self::MANAGERS], [$this->user(self::MANAGER)['status'], $this->user(self::MANAGER)['member_group_id']]);
		$this->assertEquals([0, self::AGENTS], [$this->user(self::RESELLER)['status'], $this->user(self::RESELLER)['member_group_id']]);
	}

	public function testItDoesNotDeleteAnAdministrator(): void {
		$this->assertFalse(UserService::deleteRegisteredUser(self::ADMIN));
		$this->assertNotSame([], $this->user(self::ADMIN));

		$this->assertTrue(UserService::deleteRegisteredUser(self::RESELLER));
		$this->assertSame([], $this->user(self::RESELLER));
	}

	public function testDeletingSeveralUsersLeavesTheAdministrators(): void {
		UserService::deleteRegisteredUsers([self::ADMIN, self::MANAGER, self::RESELLER]);

		$this->assertNotSame([], $this->user(self::ADMIN));
		$this->assertNotSame([], $this->user(self::MANAGER));
		$this->assertSame([], $this->user(self::RESELLER));
	}

	/** A caller that hands the request an empty permission list of its own changes nothing. */
	public function testTheStoredGroupDecidesWhoIsAFullAdministrator(): void {
		$GLOBALS['rPermissions']['advanced'] = [];

		$rResult = UserService::process($this->userForm(self::SUPPORT, ['member_group_id' => (string) self::ADMINS]));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertEquals(self::SUPPORT_GROUP, $this->user(self::SUPPORT)['member_group_id']);
	}

	/** @return array<string, array{0: int}> */
	public static function fullAdministrators(): array {
		return ['a member of the first group' => [self::ADMIN], 'a member of an administrator group without a list' => [self::MANAGER]];
	}

	#[DataProvider('fullAdministrators')]
	public function testAFullAdministratorManagesAdministrators(int $rActor): void {
		$this->actAs($rActor);

		$rResult = UserService::process($this->userForm(self::SUPPORT, ['member_group_id' => (string) self::ADMINS, 'email' => 'support@example.com']));
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::ADMINS, $this->user(self::SUPPORT)['member_group_id']);
		$this->assertSame('support@example.com', $this->user(self::SUPPORT)['email']);

		$rResult = UserService::massEdit(['users_selected' => json_encode([self::SUPPORT, self::RESELLER]), 'c_member_group_id' => 'on', 'member_group_id' => (string) self::MANAGERS]);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::MANAGERS, $this->user(self::SUPPORT)['member_group_id']);
		$this->assertEquals(self::MANAGERS, $this->user(self::RESELLER)['member_group_id']);

		$rResult = GroupService::process($this->groupForm(self::SUPPORT_GROUP, ['permissions_selected' => json_encode(['mng_regusers'])]));
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(['mng_regusers'], json_decode($this->group(self::SUPPORT_GROUP)['allowed_pages'], true));

		$this->assertTrue(UserService::deleteRegisteredUser(self::SUPPORT));
		$this->assertSame([], $this->user(self::SUPPORT));
	}

	// ── groups ──────────────────────────────────────────────────────

	public function testItDoesNotChangeItsOwnGroup(): void {
		$rResult = GroupService::process($this->groupForm(self::SUPPORT_GROUP, ['permissions_selected' => '[]']));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertSame(self::SUPPORT_PAGES, json_decode($this->group(self::SUPPORT_GROUP)['allowed_pages'], true));
	}

	public function testItDoesNotMakeAGroupAnAdministratorGroup(): void {
		$rResult = GroupService::process($this->groupForm(self::AGENTS, ['is_admin' => 'on']));

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertEquals(0, $this->group(self::AGENTS)['is_admin']);

		$rForm = $this->groupForm(self::AGENTS, ['group_name' => 'Staff', 'is_admin' => 'on']);
		unset($rForm['edit']);
		$rResult = GroupService::process($rForm);

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->rDb->query("SELECT COUNT(*) FROM `users_groups` WHERE `group_name` = 'Staff'");
		$this->assertSame(0, (int) $this->rDb->get_col());
	}

	/** @return array<string, array{0: string, 1: int}> the key a request names for the group it saves, and the administrator group that key is, or rounds to */
	public static function namedKeys(): array {
		return [
			'the first group' => ['1', self::ADMINS],
			'a fraction next to the first group' => ['0.6', self::ADMINS],
			'its own group' => ['3', self::SUPPORT_GROUP],
			'a fraction next to its own group' => ['2.6', self::SUPPORT_GROUP],
		];
	}

	/** A group is saved under its own key: the one it has, or a new one. The request does not name it. */
	#[DataProvider('namedKeys')]
	public function testASavedGroupDoesNotTakeThePlaceOfAnAdministratorGroup(string $rKey, int $rGroup): void {
		$rBefore = $this->group($rGroup);

		GroupService::process($this->groupForm(self::AGENTS, ['group_id' => $rKey, 'group_name' => 'Edited']));

		$this->assertSame($rBefore, $this->group($rGroup));
		$this->assertSame('Edited', $this->group(self::AGENTS)['group_name'], 'the group that was opened is the one saved');

		$rForm = $this->groupForm(self::AGENTS, ['group_id' => $rKey, 'group_name' => 'Added']);
		unset($rForm['edit']);
		$rResult = GroupService::process($rForm);

		$this->assertSame($rBefore, $this->group($rGroup));
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertGreaterThan(self::AGENTS, (int) $rResult['data']['insert_id'], 'a new group gets a key of its own');
		$this->assertSame('Added', $this->group((int) $rResult['data']['insert_id'])['group_name']);
	}

	/** The same for a full administrator: a group it saves does not replace another one. */
	public function testAGroupSavedByAFullAdministratorKeepsItsOwnKeyToo(): void {
		$this->actAs(self::ADMIN);

		GroupService::process($this->groupForm(self::AGENTS, ['group_id' => (string) self::RESELLERS, 'group_name' => 'Edited']));

		$this->assertSame('Resellers', $this->group(self::RESELLERS)['group_name']);
		$this->assertSame('Edited', $this->group(self::AGENTS)['group_name']);
	}

	public function testItDoesNotDeleteAnAdministratorGroup(): void {
		$this->assertFalse(GroupService::deleteById(self::MANAGERS));

		$this->assertNotSame([], $this->group(self::MANAGERS));
		$this->assertEquals(self::MANAGERS, $this->user(self::MANAGER)['member_group_id']);

		$this->assertTrue(GroupService::deleteById(self::AGENTS));
		$this->assertSame([], $this->group(self::AGENTS));

		$this->actAs(self::ADMIN);
		$this->assertTrue(GroupService::deleteById(self::MANAGERS));
		$this->assertSame([], $this->group(self::MANAGERS));
	}

	public function testItManagesTheOtherGroups(): void {
		$rResult = GroupService::process($this->groupForm(self::AGENTS, ['group_name' => 'Agents of the north']));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('Agents of the north', $this->group(self::AGENTS)['group_name']);

		$rResult = GroupService::process($this->groupForm(self::RESELLERS, ['group_name' => 'Resellers of the south']));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('Resellers of the south', $this->group(self::RESELLERS)['group_name']);
	}
}
