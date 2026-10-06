<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A reseller moves credits between its own balance and a sub-reseller's:
 * what one side gains the other side gives, so the two balances together
 * hold what they held before. The reseller's own account is no sub-reseller
 * of itself, an amount moves only when the giving side holds it now (not
 * when the request started), and a balance keeps its fraction.
 *
 * Both entry points are held to it: the reseller REST API and the panel's
 * adjust_credits action. The panel action ends the request itself, so it runs
 * in a child PHP over this test's schema, on the production database class.
 */
final class AuditCreditsTransferTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;

	private const CHILD = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

// The production database class. What another request does `meanwhile` is done
// once this one has read what it reads, as it goes to hold a balance.
$db = new class extends \XcVm\Core\Database\DatabaseHandler {
	public ?string $rMeanwhile = null;

	public function query(string $query, mixed $buffered = false) {
		if ($this->rMeanwhile !== null && str_contains($query, 'FOR UPDATE')) {
			[$rSql, $this->rMeanwhile] = [$this->rMeanwhile, null];
			TestDb::connect($GLOBALS['rIn']['schema'])->exec($rSql);
		}
		return parent::query(...func_get_args());
	}
};
$db->rMeanwhile = $rIn['meanwhile'] ?? null;
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
\XcVm\Core\Http\RequestManager::set($rIn['request']);

// What the reseller bootstrap leaves the action: the account as the request found it
// when it started (`read`: the balance it read then, when another request has changed it since).
$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['reseller']);
$rUserInfo['credits'] = $rIn['read'] ?? $rUserInfo['credits'];
$rPermissions = $rIn['permissions'];
$rUserInfo['reports'] = array_merge([$rUserInfo['id']], $rPermissions['all_reports']);

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch($rIn['action'], $rUserInfo, $rPermissions);
PHP;

	private TestDb $rDb;

	private string $rChild;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_credits_logs', 'users_logs', 'tickets', 'tickets_replies', 'lines'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (5, 'reseller', 2, 100, 0), (6, 'sub', 2, 10, 5)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		ResellerAPIWrapper::$db = $this->rDb;
		$this->startRequest();

		$this->rChild = sys_get_temp_dir() . '/xcvm-credits-transfer-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
		DatabaseFactory::reset();
		ResellerAPIWrapper::$db = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The reseller's request begins: it reads the account once, as every reseller request does. */
	private function startRequest(): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = ['create_sub_resellers' => 1, 'delete_users' => 1, 'all_reports' => [self::SUB]];
	}

	private function setBalance(int $rUserID, float $rCredits): void {
		$this->rDb->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?', $rCredits, $rUserID);
	}

	/** @return array{0: float, 1: float} the reseller's balance and the sub-reseller's */
	private function balances(): array {
		$this->rDb->query('SELECT `credits` FROM `users` ORDER BY `id`');
		return array_map('floatval', $this->rDb->get_column());
	}

	private function logged(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** The panel's adjust_credits action for the reseller: its JSON answer. */
	private function panel(int $rTargetID, string $rCredits): mixed {
		return $this->action('adjust_credits', ['id' => (string) $rTargetID, 'credits' => $rCredits, 'reason' => 'test']);
	}

	/**
	 * A panel action of the reseller, in a child PHP: its JSON answer.
	 *
	 * @param array<string, string> $rRequest
	 * @param float|null            $rRead      the balance the request read when it started, when it has changed since
	 * @param string|null           $rMeanwhile what another request does (SQL) after this one has read the accounts
	 */
	private function action(string $rAction, array $rRequest, ?float $rRead = null, ?string $rMeanwhile = null): mixed {
		$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => $GLOBALS['rPermissions'], 'action' => $rAction, 'request' => $rRequest, 'read' => $rRead, 'meanwhile' => $rMeanwhile];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return json_decode($rOut, true);
	}

	// ── the reseller's own account ──────────────────────────────────

	public function testTheRestApiMovesNoCreditsToTheResellersOwnAccount(): void {
		$rAnswer = ResellerAPIWrapper::adjustCredits(self::RESELLER, 100, '');

		$this->assertSame([100.0, 10.0], $this->balances());
		$this->assertSame(['status' => 'STATUS_FAILURE'], $rAnswer);
		$this->assertSame(0, $this->logged('users_credits_logs'));
	}

	public function testThePanelMovesNoCreditsToTheResellersOwnAccount(): void {
		$rAnswer = $this->panel(self::RESELLER, '100');

		$this->assertSame([100.0, 10.0], $this->balances());
		$this->assertSame(['result' => false], $rAnswer);
		$this->assertSame(0, $this->logged('users_credits_logs'));
	}

	// ── a sub-reseller ──────────────────────────────────────────────

	public function testTheRestApiMovesCreditsBothWays(): void {
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 30, 'top-up'));
		$this->assertSame([70.0, 40.0], $this->balances());

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, '-40', ''));
		$this->assertSame([110.0, 0.0], $this->balances());

		$this->assertSame(2, $this->logged('users_credits_logs'));
		$this->assertSame(2, $this->logged('users_logs'));
	}

	public function testThePanelMovesCreditsBothWays(): void {
		$this->assertSame(['result' => true], $this->panel(self::SUB, '30'));
		$this->assertSame([70.0, 40.0], $this->balances());

		$this->assertSame(['result' => true], $this->panel(self::SUB, '-40'));
		$this->assertSame([110.0, 0.0], $this->balances());

		$this->assertSame(2, $this->logged('users_credits_logs'));
		$this->assertSame(2, $this->logged('users_logs'));
	}

	public function testNeitherSideGivesMoreThanItHolds(): void {
		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::adjustCredits(self::SUB, 101, ''));
		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::adjustCredits(self::SUB, -11, ''));
		$this->assertSame(['result' => false], $this->panel(self::SUB, '101'));
		$this->assertSame(['result' => false], $this->panel(self::SUB, '-11'));

		$this->assertSame([100.0, 10.0], $this->balances());
		$this->assertSame(0, $this->logged('users_credits_logs'));
	}

	/**
	 * The request read 100 credits when it started; another request of the same
	 * reseller has spent 80 of them since. The balance decides, not the copy.
	 */
	public function testCreditsSpentSinceTheRequestStartedAreNotGivenAway(): void {
		$this->setBalance(self::RESELLER, 20);

		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::adjustCredits(self::SUB, 100, ''));
		$this->assertSame([20.0, 10.0], $this->balances());

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 15, ''));
		$this->assertSame([5.0, 25.0], $this->balances());
	}

	/**
	 * Another request gives the sub-reseller 50 credits after this one has read
	 * its account and before it changes a balance: they stay on the balance.
	 */
	public function testCreditsTheOtherSideReceivedMeanwhileAreKept(): void {
		$rLog = new QueryLogDb($this->rDb);
		$rOther = TestDb::connect($this->rDb->schema());
		$rLog->rBefore = static function (string $rQuery) use ($rLog, $rOther): void {
			if (str_starts_with(ltrim($rQuery), 'UPDATE `users`')) {
				$rLog->rBefore = null;
				$rOther->exec('UPDATE `users` SET `credits` = `credits` + 50 WHERE `id` = ' . self::SUB);
			}
		};
		$GLOBALS['db'] = $rLog;
		DatabaseFactory::set($rLog);
		ResellerAPIWrapper::$db = $rLog;

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 30, ''));
		$this->assertSame([70.0, 90.0], $this->balances());
	}

	// ── a sub-reseller that is deleted ──────────────────────────────

	/**
	 * A deleted sub-reseller's credits return to the reseller: added to the
	 * balance as it is stored, which another request has spent 60 of since
	 * this one started.
	 */
	public function testADeletedSubResellersCreditsAreAddedToTheBalanceAsStored(): void {
		$this->setBalance(self::RESELLER, 40);

		$this->assertSame(['result' => true], $this->action('reg_user', ['user_id' => (string) self::SUB, 'sub' => 'delete'], 100.0));

		$this->assertSame([50.0], $this->balances());
		$this->rDb->query('SELECT `target_id`, `amount` FROM `users_credits_logs`');
		$this->assertEquals([['target_id' => self::RESELLER, 'amount' => 10]], $this->rDb->get_rows());
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs`');
		$this->assertEquals([['cost' => 10, 'credits_after' => 50]], $this->rDb->get_rows());
	}

	/** All the deleted sub-reseller holds returns, the fraction of a credit with it. */
	public function testADeletedSubResellersWholeBalanceReturns(): void {
		$this->setBalance(self::SUB, 50.75);

		$this->assertSame(['result' => true], $this->action('reg_user', ['user_id' => (string) self::SUB, 'sub' => 'delete']));

		$this->assertSame([150.75], $this->balances());
		$this->rDb->query('SELECT `target_id`, `amount` FROM `users_credits_logs`');
		$this->assertEquals([['target_id' => self::RESELLER, 'amount' => 50.75]], $this->rDb->get_rows());
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs`');
		$this->assertEquals([['cost' => 50, 'credits_after' => 150]], $this->rDb->get_rows());
	}

	/**
	 * The sub-reseller spends 10 of its 50 credits after the reseller's request
	 * has read its account: the 40 it holds when it is deleted return.
	 */
	public function testADeletedSubResellerReturnsWhatItHoldsWhenItIsDeleted(): void {
		$this->setBalance(self::SUB, 50);

		$this->assertSame(['result' => true], $this->action('reg_user', ['user_id' => (string) self::SUB, 'sub' => 'delete'], null, 'UPDATE `users` SET `credits` = 40 WHERE `id` = ' . self::SUB));

		$this->assertSame([140.0], $this->balances());
		$this->rDb->query('SELECT `target_id`, `amount` FROM `users_credits_logs`');
		$this->assertEquals([['target_id' => self::RESELLER, 'amount' => 40]], $this->rDb->get_rows());
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs`');
		$this->assertEquals([['cost' => 40, 'credits_after' => 140]], $this->rDb->get_rows());
	}

	/**
	 * A move made inside a transaction the caller has opened belongs to it: the
	 * caller's to commit, and undone with everything else it rolls back.
	 */
	public function testAMoveInsideAnOpenTransactionIsTheCallersToUndo(): void {
		$this->rDb->beginTransaction();

		$this->assertTrue(UserCredits::transfer(self::RESELLER, self::SUB, 30));
		$this->assertTrue($this->rDb->isInTransaction());
		$this->assertFalse(UserCredits::transfer(self::RESELLER, self::SUB, 500));
		$this->assertTrue($this->rDb->isInTransaction());

		$this->rDb->rollback();
		$this->assertSame([100.0, 10.0], $this->balances());
	}

	public function testAMoveKeepsTheFractionOfABalance(): void {
		$this->setBalance(self::RESELLER, 100.5);
		$this->setBalance(self::SUB, 0.25);
		$this->startRequest();

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 10, ''));
		$this->assertSame([90.5, 10.25], $this->balances());

		$this->assertSame(['result' => true], $this->panel(self::SUB, '-10'));
		$this->assertSame([100.5, 0.25], $this->balances());
	}

	/** A price that adds up to the balance (0.1 * 3 is 0.30000000000000004) is covered by it. */
	public function testASumEqualToTheBalanceIsCovered(): void {
		$this->setBalance(self::RESELLER, 0.3);

		$this->assertFalse(UserCredits::debit(self::RESELLER, 0.31));
		$this->assertSame(0.3, UserCredits::balance(self::RESELLER));
		$this->assertTrue(UserCredits::debit(self::RESELLER, 0.1 * 3));
		$this->assertSame(0.0, UserCredits::balance(self::RESELLER));
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		$this->assertSame('0', (string) (0 + $this->rDb->get_col()), 'nothing is left, and nothing is owed');
	}
}
