<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\UserAjaxController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The credits of an administrator's account are adjusted by a full
 * administrator (GroupService::reservedGroups): a member of the first group,
 * or of an administrator group that has no permission list. An administrator
 * whose group holds it to a list adjusts the credits of the other users.
 *
 * Both entry points are held to it: the panel's adjust_credits action and the
 * admin API's. The panel action ends the request itself, so it is driven
 * through a subclass whose json() throws the answer instead.
 */
final class AuditAdminMiscAdjustCreditsTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const MANAGER = 3;
	private const RESELLER = 5;

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_credits_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '[\"mng_regusers\",\"edit_reguser\"]', 1, '[]'), (4, 'Managers', 1, 0, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`) VALUES"
			. " (1, 'admin', 1, 100, 0, 1), (2, 'support', 3, 100, 0, 1), (3, 'manager', 4, 100, 0, 1), (5, 'reseller', 2, 100, 0, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$this->actAs(self::SUPPORT);
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

	/** Adjusts a user's credits by an amount through an entry point: whether it says it did. */
	private function adjust(string $rEntryPoint, int $rUserID, string $rAmount): bool {
		if ($rEntryPoint == 'api') {
			return AdminAPIWrapper::adjustCredits($rUserID, $rAmount, 'test') === ['status' => 'STATUS_SUCCESS'];
		}

		RequestManager::set(['id' => (string) $rUserID, 'credits' => $rAmount, 'reason' => 'test']);
		try {
			(new AuditAdminMiscAdjustCreditsPanel())->adjustCredits();
		} catch (AuditAdminMiscAdjustCreditsAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	private function balance(int $rUserID): float {
		return floatval(UserRepository::getRegisteredUserById($rUserID)['credits']);
	}

	private function logged(): int {
		$this->rDb->query('SELECT COUNT(*) FROM `users_credits_logs`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string}> */
	public static function entryPoints(): array {
		return ['the panel' => ['panel'], 'the admin API' => ['api']];
	}

	/** @return array<string, array{0: string, 1: int, 2: string}> the entry point, the account and the amount */
	public static function administratorsAccounts(): array {
		$rCases = [];
		foreach (self::entryPoints() as $rName => [$rEntryPoint]) {
			foreach (['the first administrator' => self::ADMIN, 'a full administrator' => self::MANAGER, 'its own account' => self::SUPPORT] as $rWho => $rUserID) {
				$rCases[$rName . ' adds to ' . $rWho] = [$rEntryPoint, $rUserID, '50'];
				$rCases[$rName . ' takes from ' . $rWho] = [$rEntryPoint, $rUserID, '-50'];
			}
		}
		return $rCases;
	}

	#[DataProvider('administratorsAccounts')]
	public function testAnAdministratorHeldToAListDoesNotAdjustAnAdministratorsCredits(string $rEntryPoint, int $rUserID, string $rAmount): void {
		$this->assertFalse($this->adjust($rEntryPoint, $rUserID, $rAmount));

		$this->assertSame(100.0, $this->balance($rUserID));
		$this->assertSame(0, $this->logged());
	}

	#[DataProvider('entryPoints')]
	public function testItAdjustsTheCreditsOfTheOtherUsers(string $rEntryPoint): void {
		$this->assertTrue($this->adjust($rEntryPoint, self::RESELLER, '50'));
		$this->assertTrue($this->adjust($rEntryPoint, self::RESELLER, '-30'));

		$this->assertSame(120.0, $this->balance(self::RESELLER));
		$this->assertSame(2, $this->logged());
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
	public function testAFullAdministratorAdjustsAnAdministratorsCredits(int $rActor, string $rEntryPoint): void {
		$this->actAs($rActor);

		$this->assertTrue($this->adjust($rEntryPoint, self::SUPPORT, '50'));
		$this->assertSame(150.0, $this->balance(self::SUPPORT));
	}
}

/** The answer the panel action gave, thrown in place of ending the request. */
final class AuditAdminMiscAdjustCreditsAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditAdminMiscAdjustCreditsPanel extends UserAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminMiscAdjustCreditsAnswer($rData);
	}
}
