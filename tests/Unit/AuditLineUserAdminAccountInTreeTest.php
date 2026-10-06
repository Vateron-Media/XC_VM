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
 * reseller neither deletes it nor switches it off or on, and its credits stay
 * on its balance. The sub-resellers in the tree the reseller manages as ever.
 *
 * Both entry points are held to it: the reseller REST API and the panel's
 * reg_user action. The panel action ends the request itself, so it runs in a
 * child PHP over this test's schema, on the production database class.
 */
final class AuditLineUserAdminAccountInTreeTest extends TestCase {
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

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch('reg_user', $rUserInfo, $rPermissions);
PHP;

	private TestDb $rDb;

	private string $rChild;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_credits_logs', 'users_logs', 'tickets', 'tickets_replies', 'lines'] as $rTable) {
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

		$this->rChild = sys_get_temp_dir() . '/xcvm-admin-in-tree-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
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

	/** The panel's reg_user action of the reseller on $rUserID, in a child PHP: its JSON answer. */
	private function panel(int $rUserID, string $rSub): mixed {
		$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => $GLOBALS['rPermissions'], 'request' => ['user_id' => (string) $rUserID, 'sub' => $rSub]];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return json_decode($rOut, true);
	}

	/** @return array<string, mixed> the account, [] when it is gone */
	private function user(int $rUserID): array {
		return UserRepository::getRegisteredUserById($rUserID) ?? [];
	}

	private function switchOff(int $rUserID): void {
		$this->rDb->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?', $rUserID);
	}

	private function logged(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	// ── an administrator's account ──────────────────────────────────

	public function testThePanelDoesNotDeleteAnAdministratorsAccountNorTakeItsCredits(): void {
		$this->assertSame(['result' => false], $this->panel(self::STAFF, 'delete'));

		$this->assertEquals(50, $this->user(self::STAFF)['credits'] ?? null, 'the account is there, with its balance');
		$this->assertEquals(100, $this->user(self::RESELLER)['credits']);
		$this->assertSame(0, $this->logged('users_credits_logs'));
		$this->assertSame(0, $this->logged('users_logs'), 'nothing was deleted, nothing is logged as deleted');
	}

	/** @return array<string, array{0: string, 1: int}> the action, and the status the account has before and after it */
	public static function switches(): array {
		return ['off' => ['disable', 1], 'on' => ['enable', 0]];
	}

	#[DataProvider('switches')]
	public function testThePanelDoesNotSwitchAnAdministratorsAccount(string $rSub, int $rStatus): void {
		$rStatus || $this->switchOff(self::STAFF);

		$this->assertSame(['result' => false], $this->panel(self::STAFF, $rSub));

		$this->assertEquals($rStatus, $this->user(self::STAFF)['status']);
		$this->assertSame(0, $this->logged('users_logs'));
	}

	public function testTheRestApiDoesNotSwitchAnAdministratorsAccount(): void {
		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::disableUser(self::STAFF));
		$this->assertEquals(1, $this->user(self::STAFF)['status']);

		$this->switchOff(self::STAFF);

		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::enableUser(self::STAFF));
		$this->assertEquals(0, $this->user(self::STAFF)['status']);
	}

	public function testTheRestApiDoesNotDeleteAnAdministratorsAccount(): void {
		$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::deleteUser(self::STAFF));

		$this->assertEquals(50, $this->user(self::STAFF)['credits'] ?? null);
	}

	// ── a sub-reseller ──────────────────────────────────────────────

	public function testThePanelSwitchesASubResellerOffAndOn(): void {
		$this->assertSame(['result' => true], $this->panel(self::SUB, 'disable'));
		$this->assertEquals(0, $this->user(self::SUB)['status']);

		$this->assertSame(['result' => true], $this->panel(self::SUB, 'enable'));
		$this->assertEquals(1, $this->user(self::SUB)['status']);
	}

	public function testThePanelDeletesASubResellerAndItsCreditsReturn(): void {
		$this->assertSame(['result' => true], $this->panel(self::SUB, 'delete'));

		$this->assertSame([], $this->user(self::SUB));
		$this->assertEquals(110, $this->user(self::RESELLER)['credits']);
		$this->assertEquals(50, $this->user(self::STAFF)['credits']);
	}

	public function testTheRestApiSwitchesASubResellerOffAndOn(): void {
		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::disableUser(self::SUB));
		$this->assertEquals(0, $this->user(self::SUB)['status']);

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableUser(self::SUB));
		$this->assertEquals(1, $this->user(self::SUB)['status']);
	}

	/** A group can be an administrator group and a reseller group at once: with no permission list its members are full administrators. */
	public function testAFullAdministratorThatIsAResellerManagesAnAdministratorsAccount(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `is_admin` = 1 WHERE `group_id` = 2');

		$this->assertSame(['result' => true], $this->panel(self::STAFF, 'disable'));
		$this->assertEquals(0, $this->user(self::STAFF)['status']);

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableUser(self::STAFF));
		$this->assertEquals(1, $this->user(self::STAFF)['status']);
	}
}
