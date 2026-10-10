<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * An administrator's account is a full administrator's to manage
 * (GroupService::reservedGroups), also when it sits in a reseller's tree: the
 * reseller moves no credits to it and takes none from it. Between the
 * reseller and the sub-resellers in its tree credits move as ever.
 *
 * Both entry points are held to it: the reseller REST API and the panel's
 * adjust_credits action. The panel action ends the request itself, so it runs
 * in a child PHP over this test's schema, on the production database class.
 */
final class AuditResellerFollowAdjustCreditsTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;
	private const STAFF = 8;

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
$db = new \XcVm\Core\Database\DatabaseHandler();
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
\XcVm\Core\Http\RequestManager::set($rIn['request']);

// What the reseller bootstrap leaves the action.
$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['reseller']);
$rPermissions = $rIn['permissions'];
$rUserInfo['reports'] = array_merge([$rUserInfo['id']], $rPermissions['all_reports']);

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch('adjust_credits', $rUserInfo, $rPermissions);
PHP;

	private TestDb $rDb;

	private string $rChild;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_credits_logs', 'users_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`, `create_sub_resellers`, `delete_users`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]', 0, 0), (2, 'Resellers', 0, 1, '[]', 0, '[2]', 1, 1), (4, 'Managers', 1, 0, '[]', 1, '[]', 0, 0)");
		// The reseller's tree holds a sub-reseller and an administrator's account, each with a balance.
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`) VALUES"
			. " (1, 'admin', 1, 0, 0, 1), (5, 'reseller', 2, 100, 0, 1), (6, 'sub', 2, 10, 5, 1), (8, 'staff', 4, 50, 5, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		ResellerAPIWrapper::$db = $this->rDb;
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = array_merge(AuthRepository::getPermissions(2), ['all_reports' => [self::SUB, self::STAFF]]);
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;

		$this->rChild = sys_get_temp_dir() . '/xcvm-adjust-credits-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
		DatabaseFactory::reset();
		ResellerAPIWrapper::$db = null;
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The panel's adjust_credits action of the reseller on $rUserID, in a child PHP: its JSON answer. */
	private function panel(int $rUserID, int|string $rCredits): mixed {
		$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => $GLOBALS['rPermissions'], 'request' => ['id' => (string) $rUserID, 'credits' => (string) $rCredits, 'reason' => 'test']];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return json_decode($rOut, true);
	}

	/** @return array<string, float> the balance of the reseller, of the sub-reseller and of the administrator's account */
	private function balances(): array {
		$this->rDb->query('SELECT `username`, `credits` FROM `users` WHERE `id` IN (?, ?, ?) ORDER BY `id`', self::RESELLER, self::SUB, self::STAFF);
		return array_map('floatval', array_column($this->rDb->get_rows(), 'credits', 'username'));
	}

	private function logged(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: int}> the credits asked to be moved to the account */
	public static function ways(): array {
		return ['to the account' => [30], 'from the account' => [-50]];
	}

	// ── an administrator's account ──────────────────────────────────

	#[DataProvider('ways')]
	public function testTheRestApiMovesNoCreditsOfAnAdministratorsAccount(int $rCredits): void {
		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::adjustCredits(self::STAFF, $rCredits, ''));

		$this->assertSame(['reseller' => 100.0, 'sub' => 10.0, 'staff' => 50.0], $this->balances());
		$this->assertSame(0, $this->logged('users_credits_logs'));
		$this->assertSame(0, $this->logged('users_logs'));
	}

	#[DataProvider('ways')]
	public function testThePanelMovesNoCreditsOfAnAdministratorsAccount(int $rCredits): void {
		$this->assertSame(['result' => false], $this->panel(self::STAFF, $rCredits));

		$this->assertSame(['reseller' => 100.0, 'sub' => 10.0, 'staff' => 50.0], $this->balances());
		$this->assertSame(0, $this->logged('users_credits_logs'));
		$this->assertSame(0, $this->logged('users_logs'));
	}

	// ── a sub-reseller ──────────────────────────────────────────────

	/** Whole credits move, and the credit log says what moved: "2.9" moved 2 and was logged as 2.9. */
	public function testTheCreditLogSaysWhatMoved(): void {
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, '2.9', ''));
		$this->assertSame(['result' => true], $this->panel(self::SUB, '2.9'));

		$this->assertSame(['reseller' => 96.0, 'sub' => 14.0, 'staff' => 50.0], $this->balances());
		$this->rDb->query('SELECT `amount` FROM `users_credits_logs` ORDER BY `id`');
		$this->assertSame([2.0, 2.0], array_map('floatval', array_column($this->rDb->get_rows(), 'amount')));
	}

	public function testTheRestApiMovesCreditsOfASubResellerBothWays(): void {
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, 30, ''));
		$this->assertSame(['reseller' => 70.0, 'sub' => 40.0, 'staff' => 50.0], $this->balances());

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::SUB, -40, ''));
		$this->assertSame(['reseller' => 110.0, 'sub' => 0.0, 'staff' => 50.0], $this->balances());
	}

	public function testThePanelMovesCreditsOfASubResellerBothWays(): void {
		$this->assertSame(['result' => true], $this->panel(self::SUB, 30));
		$this->assertSame(['reseller' => 70.0, 'sub' => 40.0, 'staff' => 50.0], $this->balances());

		$this->assertSame(['result' => true], $this->panel(self::SUB, -40));
		$this->assertSame(['reseller' => 110.0, 'sub' => 0.0, 'staff' => 50.0], $this->balances());
	}

	/** A group can be an administrator group and a reseller group at once: with no permission list its members are full administrators. */
	public function testAFullAdministratorThatIsAResellerMovesCreditsOfAnAdministratorsAccount(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `is_admin` = 1 WHERE `group_id` = 2');

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::adjustCredits(self::STAFF, 30, ''));
		$this->assertSame(['result' => true], $this->panel(self::STAFF, -50));

		$this->assertSame(['reseller' => 120.0, 'sub' => 10.0, 'staff' => 30.0], $this->balances());
	}
}
