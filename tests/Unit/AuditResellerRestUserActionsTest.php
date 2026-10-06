<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The reseller REST API manages a reseller's sub-resellers as the reseller
 * panel does (ResellerApiDispatcher::handleRegUser, handleAdjustCredits): with
 * the same group permissions (create_sub_resellers, and delete_users to
 * delete), never on the reseller's own account, and a deleted sub-reseller's
 * credits return to the reseller while its lines and its own sub-resellers
 * become the reseller's.
 *
 * A sub-reseller whose credits could not be moved is not deleted, by the API
 * or by the panel, and what it holds is held from the read of it to the
 * delete: credits another request gives it meanwhile are not deleted with it.
 * The panel refuses the reseller's own account as the API does. A panel action
 * ends the request itself, so it runs in a child PHP over this test's schema,
 * on the production database class.
 */
final class AuditResellerRestUserActionsTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;
	private const BELOW = 9;

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

// The production database class, on which the balance of one account cannot be
// written. Another request ($rMeanwhile, its statements) comes on its own
// connection between the action's read of what the sub-reseller holds and its
// move of it.
$db = new class extends \XcVm\Core\Database\DatabaseHandler {
	public ?int $rUnwritable = null;

	public array $rMeanwhile = [];

	private int $rReads = 0;

	public function query(string $query, mixed $buffered = false) {
		if ($this->rUnwritable !== null && str_starts_with($query, 'UPDATE `users` SET `credits`') && func_get_arg(2) == $this->rUnwritable) {
			return false;
		}
		if ($this->rMeanwhile && str_starts_with($query, 'SELECT ROUND(COALESCE(`credits`') && func_get_arg(1) == 6 && ++$this->rReads == 2) {
			$rOther = TestDb::connect($GLOBALS['rIn']['schema']);
			try {
				foreach ($this->rMeanwhile as $rStatement) {
					$rOther->query($rStatement);
				}
			} catch (PDOException) {
			}
		}
		return parent::query(...func_get_args());
	}
};
$db->rUnwritable = $rIn['unwritable'];
$db->rMeanwhile = $rIn['meanwhile'];
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
\XcVm\Core\Http\RequestManager::set($rIn['request']);

// What the reseller bootstrap leaves the action.
$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['reseller']);
$rPermissions = $rIn['permissions'];
$rUserInfo['reports'] = array_merge([$rUserInfo['id']], $rPermissions['all_reports']);

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch('reg_user', $rUserInfo, $rPermissions);
PHP;

	/**
	 * Another request: it logs what it has to give, then moves 15 credits from
	 * the administrator to the sub-reseller, unless the sub-reseller's row is
	 * held (a row that is held is not waited for).
	 */
	private const MEANWHILE = [
		"INSERT INTO `users_credits_logs` (`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES (6, 1, 15, 0, 'meanwhile')",
		'SELECT `id` FROM `users` WHERE `id` = 6 FOR UPDATE NOWAIT',
		'UPDATE `users` SET `credits` = `credits` + IF(`id` = 6, 15, -15) WHERE `id` IN (1, 6)',
	];

	private TestDb $rDb;

	private string $rChild;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_2fa', 'api_tokens', 'users_groups', 'users_credits_logs', 'users_logs', 'tickets', 'tickets_replies', 'lines'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`, `create_sub_resellers`, `delete_users`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]', 0, 0), (2, 'Resellers', 0, 1, '[]', 0, '[2]', 1, 1)");
		// The reseller's tree: a sub-reseller with a balance, a line and a sub-reseller of its own.
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`) VALUES"
			. " (1, 'admin', 1, 0, 0, 1), (5, 'reseller', 2, 100, 0, 1), (6, 'sub', 2, 10, 5, 1), (9, 'below', 2, 3, 6, 1)");
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`) VALUES (1, 6, 'viewer', 'secret')");

		$this->serve($this->rDb);
		$this->allow(1, 1);

		$this->rChild = sys_get_temp_dir() . '/xcvm-rest-users-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
		DatabaseFactory::reset();
		ResellerAPIWrapper::$db = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The request's database. */
	private function serve(\XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
		ResellerAPIWrapper::$db = $rDb;
	}

	/** What the API key's session leaves the request, with the two permissions of the reseller's group. */
	private function allow(int $rSubResellers, int $rDeleteUsers): void {
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = ['create_sub_resellers' => $rSubResellers, 'delete_users' => $rDeleteUsers, 'all_reports' => [self::SUB, self::BELOW]];
	}

	/**
	 * The reseller's balance cannot be written: the second half of a move to it
	 * fails. With $rMeanwhile another request (MEANWHILE) comes on its own
	 * connection between the action's read of what the sub-reseller holds and
	 * its move of it.
	 */
	private function refuseTheResellersBalance(bool $rMeanwhile = false): void {
		$rLog = new QueryLogDb($this->rDb);
		$rReads = 0;
		$rLog->rBefore = function (string $rQuery, array $rArgs) use ($rLog, $rMeanwhile, &$rReads): void {
			$rLog->rRefuse = (str_starts_with($rQuery, 'UPDATE `users` SET `credits`') && $rArgs[1] == self::RESELLER) ? '/^UPDATE `users` SET `credits`/' : null;
			if ($rMeanwhile && str_starts_with($rQuery, 'SELECT ROUND(COALESCE(`credits`') && $rArgs[0] == self::SUB && ++$rReads == 2) {
				$rOther = TestDb::connect($this->rDb->schema());
				try {
					foreach (self::MEANWHILE as $rStatement) {
						$rOther->query($rStatement);
					}
				} catch (PDOException) {
				}
			}
		};
		$this->serve($rLog);
	}

	/** @return float what all accounts hold together */
	private function heldByAll(): float {
		$this->rDb->query('SELECT SUM(`credits`) FROM `users`');
		return (float) $this->rDb->get_col();
	}

	/** @return int the requests that came between the read and the move */
	private function cameMeanwhile(): int {
		$this->rDb->query("SELECT COUNT(*) FROM `users_credits_logs` WHERE `reason` = 'meanwhile'");
		return (int) $this->rDb->get_col();
	}

	/** The panel's reg_user action of the reseller on $rUserID, in a child PHP: its JSON answer. */
	private function panel(int $rUserID, string $rSub, ?int $rUnwritable = null, bool $rMeanwhile = false): mixed {
		$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => $GLOBALS['rPermissions'], 'unwritable' => $rUnwritable, 'meanwhile' => $rMeanwhile ? self::MEANWHILE : [], 'request' => ['user_id' => (string) $rUserID, 'sub' => $rSub]];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return json_decode($rOut, true);
	}

	/** @return array<string, array{credits: float, owner_id: int, status: int}> the accounts as stored, by name */
	private function accounts(): array {
		$this->rDb->query('SELECT `username`, `credits`, `owner_id`, `status` FROM `users` WHERE `id` > 1 ORDER BY `id`');
		$rOut = [];
		foreach ($this->rDb->get_rows() as $rRow) {
			$rOut[$rRow['username']] = ['credits' => (float) $rRow['credits'], 'owner_id' => (int) $rRow['owner_id'], 'status' => (int) $rRow['status']];
		}
		return $rOut;
	}

	private function lineOwner(): int {
		$this->rDb->query('SELECT `member_id` FROM `lines` WHERE `id` = 1');
		return (int) $this->rDb->get_col();
	}

	private function logged(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string, 1: list<mixed>}> the action and what follows the user's id */
	public static function actions(): array {
		return ['delete_user' => ['deleteUser', []], 'disable_user' => ['disableUser', []], 'enable_user' => ['enableUser', []], 'adjust_credits' => ['adjustCredits', [5, '']]];
	}

	// ── the group permissions ───────────────────────────────────────

	#[DataProvider('actions')]
	public function testAGroupThatCreatesNoSubResellersManagesNone(string $rAction, array $rMore): void {
		$this->allow(0, 1);
		$this->rDb->exec('UPDATE `users` SET `status` = ' . (int) ($rAction !== 'enableUser') . ' WHERE `id` = ' . self::SUB);
		$rBefore = $this->accounts();

		$this->assertSame(['status' => 'STATUS_NO_PERMISSIONS'], ResellerAPIWrapper::$rAction(self::SUB, ...$rMore));

		$this->assertSame($rBefore, $this->accounts());
		$this->assertSame(0, $this->logged('users_credits_logs'));
	}

	public function testAGroupThatDeletesNoUsersDeletesNoSubReseller(): void {
		$this->allow(1, 0);
		$rBefore = $this->accounts();

		$this->assertSame(['status' => 'STATUS_NO_PERMISSIONS'], ResellerAPIWrapper::deleteUser(self::SUB));
		$this->assertSame($rBefore, $this->accounts());

		// What it does not need the permission for, it still does.
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::disableUser(self::SUB));
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableUser(self::SUB));
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 5, ''));
		$this->assertSame(15.0, $this->accounts()['sub']['credits']);
	}

	// ── the reseller's own account ──────────────────────────────────

	#[DataProvider('actions')]
	public function testTheResellersOwnAccountIsNotASubResellerOfItself(string $rAction, array $rMore): void {
		// It holds nothing, so that no rule about credits answers for this one.
		$this->rDb->exec('UPDATE `users` SET `credits` = 0 WHERE `id` = ' . self::RESELLER);
		$rBefore = $this->accounts();

		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::$rAction(self::RESELLER, ...$rMore));

		$this->assertSame($rBefore, $this->accounts());
		$this->assertSame(6, $this->lineOwner());
	}

	/** @return array<string, array{0: string}> what the panel's reg_user action does */
	public static function panelActions(): array {
		return ['delete' => ['delete'], 'disable' => ['disable'], 'enable' => ['enable']];
	}

	#[DataProvider('panelActions')]
	public function testThePanelLeavesTheResellersOwnAccountAsItIs(string $rSub): void {
		$this->rDb->exec('UPDATE `users` SET `credits` = 0 WHERE `id` = ' . self::RESELLER);
		$rBefore = $this->accounts();

		$this->assertSame(['result' => false], $this->panel(self::RESELLER, $rSub));

		$this->assertSame($rBefore, $this->accounts());
		$this->assertSame(0, $this->logged('users_logs'));
	}

	// ── a sub-reseller that is deleted ──────────────────────────────

	public function testADeletedSubResellersCreditsLinesAndSubResellersBecomeTheResellers(): void {
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::deleteUser(self::SUB));

		$this->assertSame(['reseller' => ['credits' => 110.0, 'owner_id' => 0, 'status' => 1], 'below' => ['credits' => 3.0, 'owner_id' => self::RESELLER, 'status' => 1]], $this->accounts());
		$this->assertSame(self::RESELLER, $this->lineOwner());
		$this->rDb->query('SELECT `target_id`, `admin_id`, `amount`, `reason` FROM `users_credits_logs`');
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::RESELLER, 'amount' => 10, 'reason' => 'Deleted user: sub']], $this->rDb->get_rows());
		$this->rDb->query('SELECT `owner`, `type`, `action`, `log_id`, `cost`, `credits_after` FROM `users_logs`');
		$this->assertEquals([['owner' => self::RESELLER, 'type' => 'user', 'action' => 'delete', 'log_id' => self::SUB, 'cost' => 10, 'credits_after' => 110]], $this->rDb->get_rows());
	}

	/** An empty balance is nothing to move, not a move that failed; the sub-reseller here is one further down the tree. */
	public function testADeletedSubResellerWithNoCreditsIsDeleted(): void {
		$this->rDb->exec('UPDATE `users` SET `credits` = 0 WHERE `id` = ' . self::BELOW);

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::deleteUser(self::BELOW));

		$this->assertSame(['reseller', 'sub'], array_keys($this->accounts()));
		$this->assertSame(100.0, $this->accounts()['reseller']['credits']);
	}

	public function testTheRestApiKeepsASubResellerWhoseCreditsDidNotMove(): void {
		$this->refuseTheResellersBalance();
		$rBefore = $this->accounts();

		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::deleteUser(self::SUB));

		$this->assertSame($rBefore, $this->accounts(), 'the account is there, with all it held');
		$this->assertSame(self::SUB, $this->lineOwner());
		$this->assertSame(0, $this->logged('users_credits_logs'));
		$this->assertSame(0, $this->logged('users_logs'));
	}

	public function testThePanelKeepsASubResellerWhoseCreditsDidNotMove(): void {
		$rBefore = $this->accounts();

		$this->assertSame(['result' => false], $this->panel(self::SUB, 'delete', self::RESELLER));

		$this->assertSame($rBefore, $this->accounts(), 'the account is there, with all it held');
		$this->assertSame(self::SUB, $this->lineOwner());
		$this->assertSame(0, $this->logged('users_credits_logs'));
		$this->assertSame(0, $this->logged('users_logs'));
	}

	/** The read of what a sub-reseller holds failed: that is no empty balance. */
	public function testASubResellerWhoseBalanceCouldNotBeReadIsKept(): void {
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^SELECT ROUND\(COALESCE\(`credits`, 0\), 4\) FROM `users` WHERE `id` = \? FOR UPDATE/';
		$this->serve($rLog);
		$rBefore = $this->accounts();

		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::deleteUser(self::SUB));

		$this->assertSame($rBefore, $this->accounts(), 'the account is there, with all it held');
		$this->assertSame(self::SUB, $this->lineOwner());
	}

	/** The administrator has 15 credits to give, the sub-reseller holds none when the delete reads its balance. */
	public function testTheRestApiLosesNoCreditsGivenWhileASubResellerIsDeleted(): void {
		$this->rDb->exec('UPDATE `users` SET `credits` = IF(`id` = 1, 15, 0) WHERE `id` IN (1, ' . self::SUB . ')');
		$rHeld = $this->heldByAll();
		$this->refuseTheResellersBalance(true);

		ResellerAPIWrapper::deleteUser(self::SUB);

		$this->assertSame(1, $this->cameMeanwhile(), 'another request came in between');
		$this->assertSame($rHeld, $this->heldByAll(), 'no credits went with the deleted account');
	}

	public function testThePanelLosesNoCreditsGivenWhileASubResellerIsDeleted(): void {
		$this->rDb->exec('UPDATE `users` SET `credits` = IF(`id` = 1, 15, 0) WHERE `id` IN (1, ' . self::SUB . ')');
		$rHeld = $this->heldByAll();

		$this->panel(self::SUB, 'delete', self::RESELLER, true);

		$this->assertSame(1, $this->cameMeanwhile(), 'another request came in between');
		$this->assertSame($rHeld, $this->heldByAll(), 'no credits went with the deleted account');
	}

	/** The same database, with every balance writable: the panel deletes as ever. */
	public function testThePanelDeletesASubResellerWhoseCreditsMoved(): void {
		$this->assertSame(['result' => true], $this->panel(self::SUB, 'delete'));

		$this->assertSame(['reseller' => ['credits' => 110.0, 'owner_id' => 0, 'status' => 1], 'below' => ['credits' => 3.0, 'owner_id' => self::RESELLER, 'status' => 1]], $this->accounts());
		$this->assertSame(self::RESELLER, $this->lineOwner());
		$this->assertSame(1, $this->logged('users_credits_logs'));
	}
}
