<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Administrator groups and administrator accounts are a full administrator's
 * to manage (GroupService::reservedGroups), also where a reseller's panel is
 * the way in. A reseller gives the users it makes one of its sub-reseller
 * groups that is not an administrator group, and does not change an
 * administrator's account that sits in its tree. An administrator held to a
 * permission list does not put an administrator group among the sub-reseller
 * groups of a group it saves.
 */
final class AuditLineUserSubResellerGroupTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const RESELLER = 5;
	private const SUB = 6;
	private const STAFF = 8;

	private const ADMINS = 1;
	private const RESELLERS = 2;
	private const SUPPORT_GROUP = 3;
	private const MANAGERS = 4;
	private const AGENTS = 7;

	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$rPages = json_encode(['mng_groups', 'add_group', 'edit_group']);
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`, `create_sub_resellers`, `create_sub_resellers_price`, `allow_change_username`, `allow_change_password`, `minimum_username_length`, `minimum_password_length`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]', 0, 0, 1, 1, 4, 4), (2, 'Resellers', 0, 1, '[]', 0, '[1,4,2,7]', 1, 10, 1, 1, 4, 4),"
			. " (3, 'Support', 1, 0, '" . $rPages . "', 1, '[]', 0, 0, 1, 1, 4, 4), (4, 'Managers', 1, 0, '[]', 1, '[]', 0, 0, 1, 1, 4, 4), (7, 'Agents', 0, 1, '[]', 1, '[]', 0, 0, 1, 1, 4, 4)");
		// The reseller's tree holds a sub-reseller and an administrator's account.
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `password`, `member_group_id`, `credits`, `owner_id`, `email`, `override_packages`, `api_key`, `notes`, `reseller_dns`, `status`) VALUES"
			. " (1, 'admin', 'admin-hash', 1, 0, 0, '', '[]', '', '', '', 1), (2, 'support', 'support-hash', 3, 0, 0, '', '[]', '', '', '', 1), (5, 'reseller', 'reseller-hash', 2, 100, 0, '', '[]', '', '', '', 1),"
			. " (6, 'subreseller', 'sub-hash', 2, 0, 5, '', '[]', '', '', '', 1), (8, 'staff', 'staff-hash', 4, 0, 5, '', '[]', '', '', '', 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->actAsReseller();
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The reseller's request begins, as its panel and its API load it. */
	private function actAsReseller(): void {
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = array_merge(AuthRepository::getPermissions(self::RESELLERS), ['all_reports' => [self::SUB, self::STAFF]]);
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
		unset($GLOBALS['rAdminUserInfo']);
	}

	/** An administrator's request begins, as the admin panel loads it. */
	private function actAsAdministrator(int $rUserID): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
		$GLOBALS['rPermissions'] = AuthRepository::getPermissions((int) $GLOBALS['rUserInfo']['member_group_id']);
		$GLOBALS['rPermissions']['advanced'] = json_decode($GLOBALS['rPermissions']['allowed_pages'], true);
	}

	/** The sub-reseller groups the Resellers group lists. */
	private function list(string $rGroups): void {
		$this->rDb->query('UPDATE `users_groups` SET `subresellers` = ? WHERE `group_id` = ?', $rGroups, self::RESELLERS);
		$this->actAsReseller();
	}

	/**
	 * The reseller's form for a new user, with $rChange made.
	 *
	 * @param array<string, mixed> $rChange
	 * @return array<string, mixed>
	 */
	private function newUser(array $rChange = []): array {
		return $rChange + ['username' => 'another', 'password' => 'known-password', 'email' => '', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0'];
	}

	/** @return array<string, mixed> */
	private function user(int $rUserID): array {
		return UserRepository::getRegisteredUserById($rUserID) ?? [];
	}

	/** @return array<string, mixed> the user named $rUsername, [] when there is none */
	private function named(string $rUsername): array {
		$this->rDb->query('SELECT `id`, `member_group_id` FROM `users` WHERE `username` = ?', $rUsername);
		return $this->rDb->get_row();
	}

	// ── the users a reseller makes ──────────────────────────────────

	/** @return array<string, array{0: int}> */
	public static function administratorGroups(): array {
		return ['the first group' => [self::ADMINS], 'another administrator group' => [self::MANAGERS]];
	}

	#[DataProvider('administratorGroups')]
	public function testAResellerDoesNotGiveAUserAnAdministratorGroup(int $rGroup): void {
		$rResult = ResellerAPI::processUser($this->newUser(['member_group_id' => (string) $rGroup]));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::RESELLERS, $this->named('another')['member_group_id'], 'the first of its sub-reseller groups that is not an administrator group');
	}

	public function testTheGroupAUserGetsUnaskedIsNotAnAdministratorGroup(): void {
		$rResult = ResellerAPI::processUser($this->newUser());

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::RESELLERS, $this->named('another')['member_group_id']);
	}

	public function testNoUserIsMadeWhenEverySubResellerGroupIsAnAdministratorGroup(): void {
		$this->list('[1,4]');

		$rResult = ResellerAPI::processUser($this->newUser(['member_group_id' => (string) self::ADMINS]));

		$this->assertSame(STATUS_INVALID_SUBRESELLER, $rResult['status']);
		$this->assertSame([], $this->named('another'));
		$this->assertEquals(100, $this->user(self::RESELLER)['credits'], 'nothing was sold');
	}

	public function testAResellerDoesNotChangeAnAdministratorsAccount(): void {
		$rBefore = $this->user(self::STAFF);

		$rResult = ResellerAPI::processUser(['edit' => (string) self::STAFF, 'username' => 'staff', 'password' => 'a-new-password', 'email' => 'new@example.com', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0']);

		$this->assertFalse($rResult);
		$this->assertSame($rBefore, $this->user(self::STAFF));
	}

	/** A group can be an administrator group and a reseller group at once: held to a list, its members do not hand it on. */
	public function testAResellerInAnAdministratorGroupHeldToAListDoesNotGiveAUserItsOwnGroup(): void {
		$this->rDb->exec("UPDATE `users_groups` SET `is_admin` = 1, `allowed_pages` = '[\"mng_regusers\"]', `subresellers` = '[2]' WHERE `group_id` = 2");
		$this->actAsReseller();

		$rResult = ResellerAPI::processUser($this->newUser(['member_group_id' => (string) self::RESELLERS]));

		$this->assertSame(STATUS_INVALID_SUBRESELLER, $rResult['status']);
		$this->assertSame([], $this->named('another'));
		$this->assertFalse(ResellerAPI::processUser(['edit' => (string) self::SUB, 'username' => 'subreseller', 'password' => 'a-new-password', 'email' => '', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0']));
		$this->assertSame('sub-hash', $this->user(self::SUB)['password']);
	}

	public function testAResellerManagesItsSubResellers(): void {
		$rResult = ResellerAPI::processUser($this->newUser(['member_group_id' => (string) self::AGENTS]));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::AGENTS, $this->named('another')['member_group_id']);
		$this->assertEquals(90, $this->user(self::RESELLER)['credits']);

		$rResult = ResellerAPI::processUser(['edit' => (string) self::SUB, 'username' => 'subreseller', 'password' => '', 'member_group_id' => (string) self::AGENTS, 'email' => 'sub@example.com', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('sub@example.com', $this->user(self::SUB)['email']);
		$this->assertEquals(self::AGENTS, $this->user(self::SUB)['member_group_id']);
	}

	// ── the sub-reseller groups of a group ──────────────────────────

	/**
	 * The Resellers group as the group form posts it, with $rGroups ticked as its sub-reseller groups.
	 *
	 * @return array<string, mixed>
	 */
	private function groupForm(string $rGroups): array {
		return ['edit' => (string) self::RESELLERS, 'group_name' => 'Resellers', 'is_reseller' => 'on', 'create_sub_resellers' => 'on', 'permissions_selected' => '[]', 'groups_selected' => $rGroups, 'packages_selected' => '[]', 'notice_html' => ''];
	}

	public function testAnAdministratorHeldToAListDoesNotListAnAdministratorGroupForSubResellers(): void {
		$this->actAsAdministrator(self::SUPPORT);

		$rResult = GroupService::process($this->groupForm('[1,3,4,7]'));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('[7]', GroupService::getById(self::RESELLERS)['subresellers']);
	}

	/** The two together: what such an administrator saves leads no reseller to an administrator group. */
	public function testAGroupSavedByAnAdministratorHeldToAListGivesItsResellersNoAdministratorGroup(): void {
		$this->actAsAdministrator(self::SUPPORT);
		GroupService::process($this->groupForm('[1]'));
		$this->actAsReseller();

		ResellerAPI::processUser($this->newUser(['member_group_id' => (string) self::ADMINS]));

		$this->assertSame([], $this->named('another'));
		$this->assertEquals(100, $this->user(self::RESELLER)['credits']);
	}

	public function testAFullAdministratorListsTheGroupsItTicks(): void {
		$this->actAsAdministrator(self::ADMIN);

		$rResult = GroupService::process($this->groupForm('[4,7]'));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('[4,7]', GroupService::getById(self::RESELLERS)['subresellers']);
	}
}
