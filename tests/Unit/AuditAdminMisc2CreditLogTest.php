<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\UserAjaxController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * A credit adjustment moves whole credits, and its log row holds the amount
 * that was moved: the balance and the log say the same, whatever was typed.
 * The panel's adjust_credits action and the admin API's are both held to it.
 *
 * The panel action ends the request itself, so it is driven through a
 * subclass whose json() throws the answer instead.
 */
final class AuditAdminMisc2CreditLogTest extends TestCase {
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
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`) VALUES (1, 'admin', 1, 0, 0, 1), (5, 'reseller', 2, 100, 0, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(1);
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** Adjusts the reseller's credits by what was typed, through an entry point: whether it says it did. */
	private function adjust(string $rEntryPoint, string $rTyped): bool {
		if ($rEntryPoint == 'api') {
			return AdminAPIWrapper::adjustCredits(self::RESELLER, $rTyped, 'test') === ['status' => 'STATUS_SUCCESS'];
		}

		RequestManager::set(['id' => (string) self::RESELLER, 'credits' => $rTyped, 'reason' => 'test']);
		try {
			(new AuditAdminMisc2CreditLogPanel())->adjustCredits();
		} catch (AuditAdminMisc2CreditLogAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	/** @return array<string, array{0: string, 1: string, 2: float}> the entry point, what was typed and the amount it moves */
	public static function amounts(): array {
		$rCases = [];
		foreach (['the panel' => 'panel', 'the admin API' => 'api'] as $rName => $rEntryPoint) {
			$rCases[$rName . ' adds a whole amount'] = [$rEntryPoint, '25', 25.0];
			$rCases[$rName . ' adds an amount with a fraction'] = [$rEntryPoint, '10.9', 10.0];
			$rCases[$rName . ' takes an amount with a fraction'] = [$rEntryPoint, '-10.9', -10.0];
		}
		return $rCases;
	}

	#[DataProvider('amounts')]
	public function testTheLogHoldsTheAmountTheBalanceMovedBy(string $rEntryPoint, string $rTyped, float $rMoved): void {
		$this->assertTrue($this->adjust($rEntryPoint, $rTyped));

		$this->assertSame(100.0 + $rMoved, floatval(UserRepository::getRegisteredUserById(self::RESELLER)['credits']));
		$this->rDb->query('SELECT `target_id`, `admin_id`, `amount`, `reason` FROM `users_credits_logs`');
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => 1, 'amount' => $rMoved, 'reason' => 'test']], $this->rDb->get_raw_rows());
	}
}

/** The answer the panel action gave, thrown in place of ending the request. */
final class AuditAdminMisc2CreditLogAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditAdminMisc2CreditLogPanel extends UserAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminMisc2CreditLogAnswer($rData);
	}
}
