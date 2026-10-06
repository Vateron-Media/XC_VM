<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Mass Delete removes the users it was given, as the users list's bulk
 * delete does: their sub-users and lines stay, without an owner, and an
 * administrator's account is left to a full administrator. A group form
 * saved for a group that does not exist says so and stores nothing.
 */
final class AuditAdminUsersMassDeleteTest extends TestCase {
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
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '[\"mass_delete\",\"edit_group\"]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `owner_id`, `status`) VALUES"
			. " (1, 'admin', 1, 0, 1), (2, 'support', 3, 0, 1), (5, 'reseller', 2, 0, 1), (6, 'another', 2, 0, 1), (7, 'sub', 2, 5, 1)");
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `member_id`) VALUES (9, 'line', 'secret', 5)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->actAs(1);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	private function actAs(int $rUserID): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
	}

	/** @return list<int> the users the panel holds */
	private function users(): array {
		$this->rDb->query('SELECT `id` FROM `users` ORDER BY `id`');
		return array_map('intval', $this->rDb->get_column());
	}

	public function testMassDeleteRemovesTheUsersItWasGiven(): void {
		$rResult = UserService::massDelete(['users' => json_encode([5, 6])]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame([1, 2, 7], $this->users());

		$this->assertNull(UserRepository::getRegisteredUserById(7)['owner_id'], 'a sub-user stays, without an owner');
		$this->rDb->query('SELECT `member_id` FROM `lines` WHERE `id` = 9');
		$this->assertNull($this->rDb->get_col(), 'a line stays, without an owner');
	}

	public function testMassDeleteLeavesAnAdministratorsAccountToAFullAdministrator(): void {
		$this->actAs(2);

		UserService::massDelete(['users' => json_encode([1, 2, 6])]);

		$this->assertSame([1, 2, 5, 7], $this->users());
	}

	public function testAGroupFormForAGroupThatDoesNotExistStoresNothing(): void {
		$rResult = GroupService::process(['edit' => '99', 'group_name' => 'Ghosts', 'permissions_selected' => '[]', 'groups_selected' => '[]', 'packages_selected' => '[]', 'notice_html' => '']);

		$this->assertSame(STATUS_INVALID_GROUP, $rResult['status']);
		$this->assertSame([1, 2, 3], array_keys(GroupService::getAll()));
	}

	/** The group a form edits is the one its `edit` holds the number of: a built-in group is saved with the flags it has. */
	public function testABuiltInGroupIsSavedWithItsFlagsUnderTheNumberTheFormHolds(): void {
		$rResult = GroupService::process(['edit' => '2abc', 'group_name' => 'Sellers', 'permissions_selected' => '[]', 'groups_selected' => '[]', 'packages_selected' => '[]', 'notice_html' => '']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$rGroup = GroupService::getById(2);
		$this->assertSame(['Sellers', 0, 1], [$rGroup['group_name'], (int) $rGroup['is_admin'], (int) $rGroup['is_reseller']]);
		$this->assertSame([1, 2, 3], array_keys(GroupService::getAll()));
	}
}
