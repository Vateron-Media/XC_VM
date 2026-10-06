<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\PackageAjaxController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The group action sets a group's administrator or reseller flag under the
 * rules of the group form: a group that cannot be deleted keeps its flags,
 * and an administrator group is changed, or a group made one, by a full
 * administrator (GroupService::reservedGroups).
 *
 * The action ends the request itself, so it is driven through a subclass
 * whose json() throws the answer instead.
 */
final class AuditAdminUsersGroupFlagTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;

	private const ADMINS = 1;
	private const RESELLERS = 2;
	private const MANAGERS = 4;
	private const AGENTS = 7;

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '[\"mng_groups\",\"edit_group\"]', 1, '[]'),"
			. " (4, 'Managers', 1, 0, '[]', 1, '[]'), (7, 'Agents', 0, 1, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`) VALUES (1, 'admin', 1, 1), (2, 'support', 3, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$this->actAs(self::ADMIN);
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** The administrator whose request this is, as the panel loads it. */
	private function actAs(int $rUserID): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		$GLOBALS['rPermissions'] = AuthRepository::getPermissions((int) $GLOBALS['rUserInfo']['member_group_id']);
		$GLOBALS['rPermissions']['advanced'] = json_decode($GLOBALS['rPermissions']['allowed_pages'], true);
	}

	/** Sets a flag of a group through the action: whether it says it did. */
	private function set(int $rGroupID, string $rFlag, string $rValue): bool {
		RequestManager::set(['sub' => $rFlag, 'value' => $rValue, 'group_id' => (string) $rGroupID]);
		try {
			(new AuditAdminUsersGroupFlagPanel())->group();
		} catch (AuditAdminUsersGroupFlagAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	/** @return array{0: int, 1: int} the group's administrator and reseller flags */
	private function flags(int $rGroupID): array {
		$rGroup = GroupService::getById($rGroupID);
		return [(int) $rGroup['is_admin'], (int) $rGroup['is_reseller']];
	}

	public function testAFullAdministratorSetsTheFlagsOfAGroup(): void {
		$this->assertTrue($this->set(self::AGENTS, 'is_reseller', '0'));
		$this->assertSame([0, 0], $this->flags(self::AGENTS));

		$this->assertTrue($this->set(self::AGENTS, 'is_admin', '1'));
		$this->assertSame([1, 0], $this->flags(self::AGENTS));

		$this->assertTrue($this->set(self::MANAGERS, 'is_admin', '0'));
		$this->assertSame([0, 0], $this->flags(self::MANAGERS));
	}

	/** @return array<string, array{0: int, 1: string, 2: string}> */
	public static function groupsThatCannotBeDeleted(): array {
		return [
			'the first group is no longer an administrator group' => [self::ADMINS, 'is_admin', '0'],
			'the first group becomes a reseller group' => [self::ADMINS, 'is_reseller', '1'],
			'the resellers become administrators' => [self::RESELLERS, 'is_admin', '1'],
			'the resellers are no longer resellers' => [self::RESELLERS, 'is_reseller', '0'],
		];
	}

	#[DataProvider('groupsThatCannotBeDeleted')]
	public function testAGroupThatCannotBeDeletedKeepsItsFlags(int $rGroupID, string $rFlag, string $rValue): void {
		$rBefore = $this->flags($rGroupID);

		$this->assertFalse($this->set($rGroupID, $rFlag, $rValue));
		$this->assertSame($rBefore, $this->flags($rGroupID));
	}

	/** @return array<string, array{0: int, 1: string, 2: string}> */
	public static function reservedChanges(): array {
		return [
			'a group becomes an administrator group' => [self::AGENTS, 'is_admin', '1'],
			'an administrator group is no longer one' => [self::MANAGERS, 'is_admin', '0'],
			'an administrator group becomes a reseller group' => [self::MANAGERS, 'is_reseller', '1'],
			'its own group becomes a reseller group' => [3, 'is_reseller', '1'],
		];
	}

	#[DataProvider('reservedChanges')]
	public function testAnAdministratorHeldToAListLeavesAdministratorGroupsAsTheyAre(int $rGroupID, string $rFlag, string $rValue): void {
		$this->actAs(self::SUPPORT);
		$rBefore = $this->flags($rGroupID);

		$this->assertFalse($this->set($rGroupID, $rFlag, $rValue));
		$this->assertSame($rBefore, $this->flags($rGroupID));
	}

	public function testItSetsTheResellerFlagOfTheOtherGroups(): void {
		$this->actAs(self::SUPPORT);

		$this->assertTrue($this->set(self::AGENTS, 'is_reseller', '0'));
		$this->assertSame([0, 0], $this->flags(self::AGENTS));
	}

	public function testAGroupThatDoesNotExistIsRefused(): void {
		$this->assertFalse($this->set(99, 'is_reseller', '1'));
		$this->assertFalse(GroupService::getById(99));
	}
}

/** The answer the action gave, thrown in place of ending the request. */
final class AuditAdminUsersGroupFlagAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditAdminUsersGroupFlagPanel extends PackageAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminUsersGroupFlagAnswer($rData);
	}
}
