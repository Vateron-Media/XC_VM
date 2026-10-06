<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\MultiAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\UserAjaxController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * An administrator's account is switched off or on by a full administrator
 * (GroupService::reservedGroups): a member of the first group, or of an
 * administrator group that has no permission list. An administrator whose
 * group holds it to a list switches the other users, one at a time or
 * several together.
 *
 * Three entry points are held to it: the users list's row action, its bulk
 * action and the admin API. The panel actions end the request themselves, so
 * each is driven through a subclass whose json() throws the answer instead.
 */
final class AuditAdminUsersAccountSwitchTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const MANAGER = 3;
	private const RESELLER = 5;

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '[\"mng_regusers\",\"edit_reguser\"]', 1, '[]'), (4, 'Managers', 1, 0, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`) VALUES"
			. " (1, 'admin', 1, 0, 0, 1), (2, 'support', 3, 0, 0, 1), (3, 'manager', 4, 0, 0, 1), (5, 'reseller', 2, 0, 0, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		AdminAPIWrapper::$db = $this->rDb;
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$this->actAs(self::SUPPORT);
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		AdminAPIWrapper::$db = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** The administrator whose request this is, as the panel loads it. */
	private function actAs(int $rUserID): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		$GLOBALS['rPermissions'] = AuthRepository::getPermissions((int) $GLOBALS['rUserInfo']['member_group_id']);
		$GLOBALS['rPermissions']['advanced'] = json_decode($GLOBALS['rPermissions']['allowed_pages'], true);
	}

	private function statusOf(int $rUserID): int {
		return (int) UserRepository::getRegisteredUserById($rUserID)['status'];
	}

	private function setStatus(int $rUserID, int $rStatus): void {
		$this->rDb->query('UPDATE `users` SET `status` = ? WHERE `id` = ?', $rStatus, $rUserID);
	}

	/**
	 * Switches the users off or on through an entry point: whether it says it did.
	 *
	 * @param list<int> $rUserIDs one user for the row action and the API
	 */
	private function switch(string $rEntryPoint, string $rSub, array $rUserIDs): bool {
		if ($rEntryPoint == 'api') {
			$rAnswer = ($rSub == 'disable' ? AdminAPIWrapper::disableUser($rUserIDs[0]) : AdminAPIWrapper::enableUser($rUserIDs[0]));
			return $rAnswer === ['status' => 'STATUS_SUCCESS'];
		}

		try {
			if ($rEntryPoint == 'bulk') {
				RequestManager::set(['type' => 'user', 'sub' => $rSub, 'ids' => json_encode($rUserIDs)]);
				(new AuditAdminUsersSwitchBulk())->multi();
			} else {
				RequestManager::set(['sub' => $rSub, 'user_id' => (string) $rUserIDs[0]]);
				(new AuditAdminUsersSwitchRow())->regUser();
			}
		} catch (AuditAdminUsersSwitchAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	/** @return array<string, array{0: string, 1: string, 2: int, 3: int}> the entry point, the action, the account, and the status it has before and after */
	public static function administratorsAccounts(): array {
		$rCases = [];
		foreach (['the row action' => 'row', 'the bulk action' => 'bulk', 'the admin API' => 'api'] as $rName => $rEntryPoint) {
			foreach (['the first administrator' => self::ADMIN, 'a full administrator' => self::MANAGER, 'its own account' => self::SUPPORT] as $rWho => $rUserID) {
				$rCases[$rName . ' switches off ' . $rWho] = [$rEntryPoint, 'disable', $rUserID, 1];
				$rCases[$rName . ' switches on ' . $rWho] = [$rEntryPoint, 'enable', $rUserID, 0];
			}
		}
		return $rCases;
	}

	#[DataProvider('administratorsAccounts')]
	public function testAnAdministratorHeldToAListDoesNotSwitchAnAdministratorsAccount(string $rEntryPoint, string $rSub, int $rUserID, int $rStatus): void {
		$this->setStatus($rUserID, $rStatus);

		$rSaid = $this->switch($rEntryPoint, $rSub, [$rUserID]);

		$this->assertSame($rStatus, $this->statusOf($rUserID));
		// The bulk action answers for the selection, not for each account in it.
		$rEntryPoint == 'bulk' || $this->assertFalse($rSaid);
	}

	/** @return array<string, array{0: string}> */
	public static function entryPoints(): array {
		return ['the row action' => ['row'], 'the bulk action' => ['bulk'], 'the admin API' => ['api']];
	}

	#[DataProvider('entryPoints')]
	public function testItSwitchesTheOtherUsers(string $rEntryPoint): void {
		$this->assertTrue($this->switch($rEntryPoint, 'disable', [self::RESELLER]));
		$this->assertSame(0, $this->statusOf(self::RESELLER));

		$this->assertTrue($this->switch($rEntryPoint, 'enable', [self::RESELLER]));
		$this->assertSame(1, $this->statusOf(self::RESELLER));
	}

	public function testTheBulkActionLeavesTheAdministratorsInASelection(): void {
		$this->assertTrue($this->switch('bulk', 'disable', [self::ADMIN, self::MANAGER, self::RESELLER]));

		$this->assertSame([1, 1, 0], [$this->statusOf(self::ADMIN), $this->statusOf(self::MANAGER), $this->statusOf(self::RESELLER)]);
	}

	/** @return array<string, array{0: int, 1: string}> */
	public static function fullAdministrators(): array {
		$rCases = [];
		foreach (['a member of the first group' => self::ADMIN, 'a member of an administrator group without a list' => self::MANAGER] as $rName => $rActor) {
			foreach (self::entryPoints() as $rVia => [$rEntryPoint]) {
				$rCases[$rName . ', by ' . $rVia] = [$rActor, $rEntryPoint];
			}
		}
		return $rCases;
	}

	#[DataProvider('fullAdministrators')]
	public function testAFullAdministratorSwitchesAnAdministratorsAccount(int $rActor, string $rEntryPoint): void {
		$this->actAs($rActor);

		$this->assertTrue($this->switch($rEntryPoint, 'disable', [self::SUPPORT]));
		$this->assertSame(0, $this->statusOf(self::SUPPORT));

		$this->assertTrue($this->switch($rEntryPoint, 'enable', [self::SUPPORT]));
		$this->assertSame(1, $this->statusOf(self::SUPPORT));
	}
}

/** The answer an action gave, thrown in place of ending the request. */
final class AuditAdminUsersSwitchAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

trait AuditAdminUsersSwitchAnswers {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminUsersSwitchAnswer($rData);
	}
}

final class AuditAdminUsersSwitchRow extends UserAjaxController {
	use AuditAdminUsersSwitchAnswers;
}

final class AuditAdminUsersSwitchBulk extends MultiAjaxController {
	use AuditAdminUsersSwitchAnswers;
}
