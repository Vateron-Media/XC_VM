<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\UserAjaxController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * An administrator adjusts a user's credits by an amount: the balance changes
 * by that amount on the row as it is stored when it changes, not on the copy
 * the request read before. What the user spent in the meantime stays spent, a
 * balance keeps its fraction and is not taken below zero, and the log line
 * carries the amount.
 *
 * Both entry points are held to it: the panel's adjust_credits action and the
 * admin API's. The panel action ends the request itself, so it is driven
 * through a subclass whose json() throws the answer instead.
 */
final class AuditAdminViewsCreditsTest extends TestCase {
	private const ADMIN = 1;
	private const USER = 6;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_credits_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (1, 'admin', 1, 0, 0), (6, 'reseller', 2, 100, 0)");

		$this->rLog = new QueryLogDb($this->rDb);
		$GLOBALS['db'] = $this->rLog;
		DatabaseFactory::set($this->rLog);
		$GLOBALS['rUserInfo'] = ['id' => self::ADMIN, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** @return array<string, array{0: string}> */
	public static function entryPoints(): array {
		return ['the panel' => ['panel'], 'the admin API' => ['api']];
	}

	/** Adjusts the user's credits by an amount through an entry point: whether it says it did. */
	private function adjust(string $rEntryPoint, string $rAmount): bool {
		if ($rEntryPoint == 'api') {
			return AdminAPIWrapper::adjustCredits(self::USER, $rAmount, 'test') === ['status' => 'STATUS_SUCCESS'];
		}

		RequestManager::set(['id' => (string) self::USER, 'credits' => $rAmount, 'reason' => 'test']);
		try {
			(new AuditAdminViewsCreditsPanel())->adjustCredits();
		} catch (AuditAdminViewsCreditsAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	private function setBalance(float $rCredits): void {
		$this->rDb->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?', $rCredits, self::USER);
	}

	private function balance(): float {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::USER);
		return floatval($this->rDb->get_col());
	}

	/** @return list<array<string, mixed>> the log lines: whose credits, who changed them, by how much, and why */
	private function logged(): array {
		$this->rDb->query('SELECT `target_id`, `admin_id`, `amount`, `reason` FROM `users_credits_logs` ORDER BY `id`');
		return $this->rDb->get_rows();
	}

	/** The user spends $rSpent credits, in a request of its own, once this one has read the account. */
	private function spendsMeanwhile(int $rSpent): void {
		$rOther = TestDb::connect($this->rDb->schema());
		$this->rLog->rBefore = function (string $rQuery) use ($rOther, $rSpent): void {
			if (!str_starts_with(ltrim($rQuery), 'SELECT * FROM `users`')) {
				$this->rLog->rBefore = null;
				$rOther->exec('UPDATE `users` SET `credits` = `credits` - ' . $rSpent . ' WHERE `id` = ' . self::USER);
			}
		};
	}

	#[DataProvider('entryPoints')]
	public function testAnAmountIsAddedToTheBalanceAsStored(string $rEntryPoint): void {
		$this->spendsMeanwhile(30);

		$this->assertTrue($this->adjust($rEntryPoint, '50'));
		$this->assertSame(120.0, $this->balance());
	}

	#[DataProvider('entryPoints')]
	public function testCreditsSpentMeanwhileAreNotTakenAgain(string $rEntryPoint): void {
		$this->spendsMeanwhile(95);

		$this->assertFalse($this->adjust($rEntryPoint, '-10'));
		$this->assertSame(5.0, $this->balance());
		$this->assertSame([], $this->logged());
	}

	#[DataProvider('entryPoints')]
	public function testABalanceKeepsItsFraction(string $rEntryPoint): void {
		$this->setBalance(10.5);

		$this->assertTrue($this->adjust($rEntryPoint, '5'));
		$this->assertSame(15.5, $this->balance());

		$this->assertTrue($this->adjust($rEntryPoint, '-15'));
		$this->assertSame(0.5, $this->balance());
	}

	#[DataProvider('entryPoints')]
	public function testABalanceIsNotTakenBelowZero(string $rEntryPoint): void {
		$this->setBalance(10.5);

		$this->assertFalse($this->adjust($rEntryPoint, '-11'));
		$this->assertSame(10.5, $this->balance());
		$this->assertSame([], $this->logged());
	}

	#[DataProvider('entryPoints')]
	public function testTheLogCarriesTheAmount(string $rEntryPoint): void {
		$this->assertTrue($this->adjust($rEntryPoint, '25'));
		$this->assertTrue($this->adjust($rEntryPoint, '-5'));

		$this->assertSame(120.0, $this->balance());
		$this->assertEquals([
			['target_id' => self::USER, 'admin_id' => self::ADMIN, 'amount' => 25, 'reason' => 'test'],
			['target_id' => self::USER, 'admin_id' => self::ADMIN, 'amount' => -5, 'reason' => 'test'],
		], $this->logged());
	}
}

/** The answer the panel action gave, thrown in place of ending the request. */
final class AuditAdminViewsCreditsAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditAdminViewsCreditsPanel extends UserAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminViewsCreditsAnswer($rData);
	}
}
