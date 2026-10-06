<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ActiveCodeApiController;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The active-code API runs the active-code actions of the Admin API for an
 * admin key, under the same rule: an action runs only with a permission of the
 * key's group (AdminApiController::ACTION_PERMISSIONS). A refused action
 * answers STATUS_NO_PERMISSIONS and does nothing.
 */
final class AuditAdminApiGateActiveCodeTest extends TestCase {
	private const FIRST_GROUP = '11111111111111111111111111111111';
	private const WITHOUT_A_LIST = '44444444444444444444444444444444';
	private const WITH_A_LIST = '33333333333333333333333333333333';

	private const REFUSED = ['status' => 'STATUS_NO_PERMISSIONS'];

	/** The names the active-code API gives each action of the Admin API. */
	private const NAMES = [
		'get_active_codes' => ['get_active_codes', 'list', 'get_codes'],
		'get_active_code' => ['get_active_code', 'get', 'details'],
		'generate_active_codes' => ['generate_active_codes', 'generate'],
		'create_active_code' => ['create_active_code', 'create'],
		'edit_active_code' => ['edit_active_code', 'edit', 'update'],
		'delete_active_code' => ['delete_active_code', 'delete'],
		'enable_active_code' => ['enable_active_code', 'enable'],
		'disable_active_code' => ['disable_active_code', 'disable'],
		'reset_active_code_device' => ['reset_active_code_device', 'reset_device'],
		'mass_active_codes' => ['mass_active_codes', 'mass'],
		'get_active_codes_batches' => ['get_active_codes_batches', 'batches'],
		'export_active_code_batch' => ['export_active_code_batch', 'export'],
	];

	private const CODE = ['activation_code' => 'ABCDEF1234', 'status' => 1, 'mac' => '00:1A:79:00:00:01', 'device_id' => 'box-1'];

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'users_packages', 'activation_codes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[\"ticket\"]', 0, '[]'), (3, 'Support', 1, 0, '[\"ticket\"]', 1, '[]'), (4, 'Managers', 1, 0, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES"
			. " (1, 'admin', 1, 1, '', '" . self::FIRST_GROUP . "'), (2, 'support', 3, 1, '', '" . self::WITH_A_LIST . "'), (3, 'manager', 4, 1, '', '" . self::WITHOUT_A_LIST . "')");
		$this->rDb->exec("INSERT INTO `activation_codes` (`id`, `activation_code`, `batch_name`, `status`, `mac`, `device_id`) VALUES (1, 'ABCDEF1234', 'B1', 1, '00:1A:79:00:00:01', 'box-1')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The permissions the group of the restricted key lists. */
	private function grant(string ...$rPermissions): void {
		$this->rDb->query('UPDATE `users_groups` SET `allowed_pages` = ? WHERE `group_id` = 3;', json_encode($rPermissions));
	}

	/** Ask the active-code API for an action with an admin key, as its index() does: its answer. */
	private function ask(string $rKey, string $rName, array $rData = []): mixed {
		AdminAPIWrapper::$db = $this->rDb;
		AdminAPIWrapper::$rKey = $rKey;
		$this->assertTrue(AdminAPIWrapper::createSession());

		$rApi = new class extends ActiveCodeApiController {
			public function admin(string $rName, array $rData): void {
				$this->handleAdminAction($rName, $rData, 0, 50, null, null);
			}
		};
		ob_start();
		try {
			$rApi->admin($rName, $rData);
		} finally {
			$rBody = (string) ob_get_clean();
		}
		return json_decode($rBody, true);
	}

	/** @return array<string, mixed> The one code, as stored. */
	private function code(): array {
		$this->rDb->query('SELECT `activation_code`, `status`, `mac`, `device_id` FROM `activation_codes`;');
		$rRows = $this->rDb->get_rows();
		$this->assertCount(1, $rRows);
		return array_merge($rRows[0], ['status' => (int) $rRows[0]['status']]);
	}

	/** A name the list leaves out is not held to a permission by the tests below. */
	public function testEveryNameOfTheActiveCodeApiIsListed(): void {
		preg_match('/function handleAdminAction\b.*?function handleResellerAction\b/s', (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Api/ActiveCodeApiController.php'), $rSwitch);
		preg_match_all('/\bcase\s+[\'"](\w+)[\'"]\s*:/', $rSwitch[0] ?? '', $rCases);
		$rNames = $rCases[1];
		$rListed = array_merge(...array_values(self::NAMES));
		sort($rNames);
		sort($rListed);

		$this->assertGreaterThan(20, count($rNames));
		$this->assertSame($rNames, $rListed);
		$this->assertSame([], array_diff(array_keys(self::NAMES), array_keys(AdminApiController::ACTION_PERMISSIONS)));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function names(): array {
		$rNames = [];
		foreach (self::NAMES as $rAction => $rItsNames) {
			foreach ($rItsNames as $rName) {
				$rNames[$rName] = [$rName, $rAction];
			}
		}
		return $rNames;
	}

	/** Every permission but those of the action is not enough for any of its names. */
	#[DataProvider('names')]
	public function testANameIsRefusedWithoutAPermissionOfItsAction(string $rName, string $rAction): void {
		$this->grant(...array_values(array_diff(PermissionReference::keys(), AdminApiController::ACTION_PERMISSIONS[$rAction])));

		$rAnswer = $this->ask(self::WITH_A_LIST, $rName, ['id' => 1, 'code' => 'ABCDEF1234', 'ids' => '1', 'sub_action' => 'reset_device', 'batch_name' => 'B1', 'qty' => 1]);

		$this->assertSame(self::REFUSED, $rAnswer);
		$this->assertSame(self::CODE, $this->code());
		$this->rDb->query('SELECT COUNT(*) FROM `lines`;');
		$this->assertSame(0, (int) $this->rDb->get_col());
	}

	public function testAKeyRunsTheActiveCodeActionsItsGroupListsThePermissionOf(): void {
		$this->grant('users');
		$this->assertSame('ABCDEF1234', $this->ask(self::WITH_A_LIST, 'list')['data'][0]['activation_code']);
		$this->assertSame(self::REFUSED, $this->ask(self::WITH_A_LIST, 'reset_device', ['id' => 1]));
		$this->assertSame(self::CODE, $this->code());

		$this->grant('edit_user');
		$this->assertSame(self::REFUSED, $this->ask(self::WITH_A_LIST, 'list'));
		$this->assertSame('STATUS_SUCCESS', $this->ask(self::WITH_A_LIST, 'reset_device', ['id' => 1])['status']);
		$this->assertSame(array_merge(self::CODE, ['mac' => null, 'device_id' => null]), $this->code());
	}

	/** @return array<string, array{0: string}> */
	public static function fullAdministrators(): array {
		return ['the first group' => [self::FIRST_GROUP], 'an administrator group without a list' => [self::WITHOUT_A_LIST]];
	}

	#[DataProvider('fullAdministrators')]
	public function testAKeyOfAFullAdministratorRunsTheActiveCodeActions(string $rKey): void {
		$this->assertSame('ABCDEF1234', $this->ask($rKey, 'list')['data'][0]['activation_code']);
		$this->assertSame('STATUS_SUCCESS', $this->ask($rKey, 'mass', ['sub_action' => 'reset_device', 'ids' => '1'])['status']);
		$this->assertSame(array_merge(self::CODE, ['mac' => null, 'device_id' => null]), $this->code());
	}
}
