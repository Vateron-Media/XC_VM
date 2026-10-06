<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * An admin API key runs an action only with a permission of its group: the
 * one the panel asks for the same operation (AdminApiController::ACTION_PERMISSIONS).
 * A key of the first group, or of an administrator group that lists none, runs
 * every action. A refused action answers STATUS_NO_PERMISSIONS and does nothing.
 */
final class AuditAdminApiGateTest extends TestCase {
	private const FIRST_GROUP = '11111111111111111111111111111111';
	private const WITHOUT_A_LIST = '44444444444444444444444444444444';
	private const WITH_A_LIST = '33333333333333333333333333333333';

	private const REFUSED = ['status' => 'STATUS_NO_PERMISSIONS'];

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'bouquets'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		// The first group keeps every permission whatever its row lists.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[\"ticket\"]', 0, '[]'), (3, 'Support', 1, 0, '[\"ticket\"]', 1, '[]'), (4, 'Managers', 1, 0, '[]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES"
			. " (1, 'admin', 1, 1, '', '" . self::FIRST_GROUP . "'), (2, 'support', 3, 1, '', '" . self::WITH_A_LIST . "'), (3, 'manager', 4, 1, '', '" . self::WITHOUT_A_LIST . "')");
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `enabled`, `admin_enabled`) VALUES (1, 'viewer', 'secret', 1, 1)");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`) VALUES (1, 'Sports')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		// A handle another test gave the bouquets is not this test's database.
		(new ReflectionProperty(BouquetService::class, 'db'))->setValue(null, null);
		AdminApiRegistry::reset();
		$this->rRequest = RequestManager::getAll();
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		AdminApiRegistry::reset();
		DatabaseFactory::reset();
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['_ERRORS']);
	}

	/** The permissions the group of the restricted key lists. */
	private function grant(string ...$rPermissions): void {
		$this->rDb->query('UPDATE `users_groups` SET `allowed_pages` = ? WHERE `group_id` = 3;', json_encode($rPermissions));
	}

	/** Ask the API for an action with a key: its answer. */
	private function ask(string $rKey, string $rAction, array $rData = []): mixed {
		RequestManager::set(['api_key' => $rKey, 'action' => $rAction] + $rData);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		return json_decode($rBody, true);
	}

	/** Open the session of a key, as the API does before it runs an action. */
	private function signIn(string $rKey): void {
		AdminAPIWrapper::$db = $this->rDb;
		AdminAPIWrapper::$rKey = $rKey;
		$this->assertTrue(AdminAPIWrapper::createSession());
	}

	private function lineIsEnabled(): bool {
		$this->rDb->query('SELECT `enabled` FROM `lines` WHERE `id` = 1;');
		return (bool) $this->rDb->get_col();
	}

	/** @return list<string> The actions the API's own switch runs. */
	private static function actions(): array {
		preg_match_all('/\bcase\s+[\'"](\w+)[\'"]\s*:/', (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Api/AdminApiController.php'), $rCases);
		return $rCases[1];
	}

	/** An action the table leaves out would run for every key. */
	public function testEveryActionOfTheApiHasItsPermissions(): void {
		$rActions = self::actions();
		$rListed = array_keys(AdminApiController::ACTION_PERMISSIONS);
		sort($rActions);
		sort($rListed);

		$this->assertGreaterThan(150, count($rActions));
		$this->assertSame($rActions, $rListed);
	}

	public function testAnActionAsksForPermissionsAGroupCanList(): void {
		$rUnknown = $rOpen = [];
		foreach (AdminApiController::ACTION_PERMISSIONS as $rAction => $rPermissions) {
			if (!$rPermissions) {
				$rOpen[] = $rAction;
			}
			foreach (array_diff($rPermissions, PermissionReference::keys()) as $rPermission) {
				$rUnknown[] = $rAction . ': ' . $rPermission;
			}
		}

		$this->assertSame([], $rUnknown);
		// A key's own account is the one thing every key reads.
		$this->assertSame(['user_info'], $rOpen);
	}

	/** @return array<string, array{0: string, 1: list<string>}> */
	public static function permissionsOfThePanel(): array {
		return [
			'a query on the database' => ['mysql_query', ['database']],
			'the settings, read' => ['get_settings', ['settings']],
			'the settings, saved' => ['edit_settings', ['settings']],
			'a server installed' => ['install_server', ['add_server']],
			'a server deleted' => ['delete_server', ['edit_server']],
			'nginx reloaded' => ['reload_nginx', ['edit_server']],
			'a process killed' => ['kill_pid', ['process_monitor']],
			'the cache rebuilt' => ['reload_cache', ['database']],
			'a line read' => ['get_line', ['edit_user']],
			'a line edited' => ['edit_line', ['edit_user']],
			'a line created' => ['create_line', ['add_user']],
			'a line deleted' => ['delete_line', ['edit_user']],
			'a user read' => ['get_user', ['edit_reguser']],
			'a user edited' => ['edit_user', ['edit_reguser']],
			'a user created' => ['create_user', ['add_reguser']],
			'credits adjusted' => ['adjust_credits', ['edit_reguser']],
			'a group edited' => ['edit_group', ['edit_group']],
			'the lines listed' => ['get_lines', ['users', 'mass_edit_lines']],
			'the users listed' => ['get_users', ['mng_regusers', 'mass_edit_users']],
			'the MAG devices listed' => ['get_mags', ['manage_mag', 'mass_edit_mags']],
			'the Enigma2 devices listed' => ['get_enigmas', ['manage_e2', 'mass_edit_enigmas']],
			'the streams of the providers listed' => ['get_provider_streams', ['streams', 'add_stream', 'edit_stream', 'add_movie', 'edit_movie']],
			'a movie read' => ['get_movie', ['edit_movie']],
			'a stream started' => ['start_stream', ['edit_stream']],
			'a created channel deleted' => ['delete_channel', ['edit_cchannel', 'edit_stream']],
			'an access code created' => ['create_access_code', ['add_code']],
			'a blocked address removed' => ['delete_blocked_ip', ['block_ips']],
			'a connection closed' => ['kill_connection', ['connection_logs']],
			'codes generated' => ['generate_active_codes', ['add_user']],
		];
	}

	/** @param list<string> $rPermissions */
	#[DataProvider('permissionsOfThePanel')]
	public function testAnActionAsksForThePermissionThePanelAsksForIt(string $rAction, array $rPermissions): void {
		$this->assertSame($rPermissions, AdminApiController::ACTION_PERMISSIONS[$rAction]);
	}

	/** @return array<string, array{0: string}> */
	public static function fullAdministrators(): array {
		return ['the first group' => [self::FIRST_GROUP], 'an administrator group without a list' => [self::WITHOUT_A_LIST]];
	}

	#[DataProvider('fullAdministrators')]
	public function testAKeyOfAFullAdministratorRunsEveryAction(string $rKey): void {
		$this->signIn($rKey);
		$this->assertSame([], array_values(array_filter(self::actions(), static fn(string $rAction): bool => !AdminApiController::permitted($rAction))));

		$this->assertSame('viewer', $this->ask($rKey, 'get_line', ['id' => 1])['data']['username']);
		$this->assertSame(['status' => 'STATUS_SUCCESS'], $this->ask($rKey, 'disable_line', ['id' => 1]));
		$this->assertFalse($this->lineIsEnabled());
		$this->assertSame('STATUS_SUCCESS', $this->ask($rKey, 'mysql_query', ['query' => 'UPDATE `lines` SET `enabled` = 1'])['status']);
		$this->assertTrue($this->lineIsEnabled());
	}

	public function testAKeyRunsTheActionsItsGroupListsThePermissionOf(): void {
		$this->grant('users', 'edit_user', 'database');

		$this->assertSame('viewer', $this->ask(self::WITH_A_LIST, 'get_line', ['id' => 1])['data']['username']);
		$this->assertSame(['status' => 'STATUS_SUCCESS'], $this->ask(self::WITH_A_LIST, 'disable_line', ['id' => 1]));
		$this->assertFalse($this->lineIsEnabled());
		$this->assertSame('STATUS_SUCCESS', $this->ask(self::WITH_A_LIST, 'mysql_query', ['query' => 'UPDATE `lines` SET `enabled` = 1'])['status']);
		$this->assertTrue($this->lineIsEnabled());
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}> */
	public static function actionsNotListed(): array {
		return [
			'a query, with every permission on lines' => ['mysql_query', ['query' => 'UPDATE `lines` SET `enabled` = 0'], ['users', 'add_user', 'edit_user', 'mass_edit_lines']],
			'a line switched off, with the permission to list lines' => ['disable_line', ['id' => 1], ['users']],
			'a line banned, with the permission to add one' => ['ban_line', ['id' => 1], ['add_user']],
			'a line read, with the permissions on users' => ['get_line', ['id' => 1], ['mng_regusers', 'edit_reguser']],
			// The table of lines answers with less of a line than its record holds.
			'a line read, with the permission to list lines' => ['get_line', ['id' => 1], ['users']],
		];
	}

	/**
	 * @param array<string, mixed> $rData
	 * @param list<string>         $rListed
	 */
	#[DataProvider('actionsNotListed')]
	public function testAKeyIsRefusedAnActionItsGroupDoesNotListThePermissionOf(string $rAction, array $rData, array $rListed): void {
		$this->grant(...$rListed);

		$this->assertSame(self::REFUSED, $this->ask(self::WITH_A_LIST, $rAction, $rData));

		$this->rDb->query('SELECT `enabled`, `admin_enabled` FROM `lines` WHERE `id` = 1;');
		$this->assertSame(['enabled' => 1, 'admin_enabled' => 1], array_map('intval', $this->rDb->get_row()));
	}

	/** One bouquet is read with the permission that lists the bouquets, or the one that edits them. */
	public function testAnActionThatNamesSeveralPermissionsTakesAnyOfThem(): void {
		$this->assertSame(['bouquets', 'edit_bouquet'], AdminApiController::ACTION_PERMISSIONS['get_bouquet']);

		foreach (['bouquets', 'edit_bouquet'] as $rPermission) {
			$this->grant($rPermission);
			$this->assertSame('Sports', $this->ask(self::WITH_A_LIST, 'get_bouquet', ['id' => 1])['data']['bouquet_name'], $rPermission);
		}

		$this->grant('add_bouquet');
		$this->assertSame(self::REFUSED, $this->ask(self::WITH_A_LIST, 'get_bouquet', ['id' => 1]));
	}

	public function testAKeyWhoseGroupListsNoneOfThemReadsItsOwnAccountOnly(): void {
		$this->grant('ticket');
		$this->signIn(self::WITH_A_LIST);

		$this->assertSame(['user_info'], array_values(array_filter(self::actions(), static fn(string $rAction): bool => AdminApiController::permitted($rAction))));

		$rAnswer = $this->ask(self::WITH_A_LIST, 'user_info');
		$this->assertSame('support', $rAnswer['data']['username']);
		$this->assertSame(['ticket'], $rAnswer['permissions']['advanced']);
	}

	/** A module's action is not in the table: it reaches the module, which asks for its own permission. */
	public function testAModuleActionReachesTheModuleForEveryKey(): void {
		AdminApiRegistry::add('get_things', static fn(array $rData): array => ['status' => 'STATUS_SUCCESS', 'data' => ['listed' => Authorization::check('adv', 'folder_watch')]]);
		$this->grant('folder_watch');
		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['listed' => true]], $this->ask(self::WITH_A_LIST, 'get_things'));

		$this->grant('ticket');
		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['listed' => false]], $this->ask(self::WITH_A_LIST, 'get_things'));
	}

	public function testAnUnknownActionAndAnUnknownKeyAnswerAsTheyDid(): void {
		$this->assertSame(['status' => 'STATUS_FAILURE', 'error' => 'Invalid action.'], $this->ask(self::WITH_A_LIST, 'no_such_action'));
		$this->assertSame(['status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.'], $this->ask('00000000000000000000000000000000', 'get_line', ['id' => 1]));
	}

	/**
	 * A service ends the request when the permission is missing, which left the
	 * API's answer empty: the API answers before the service is reached. Run in
	 * a child PHP, as the service would end this one.
	 */
	public function testAnActionItsServiceWouldEndAnswersNoPermissions(): void {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. '$db = new TestDb();'
			. 'foreach (["users", "users_groups", "bouquets"] as $rTable) {'
			. ' $db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));'
			. '}'
			. '$db->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES (3, \'Support\', 1, 0, \'[\\"bouquets\\",\\"edit_bouquet\\"]\', 1, \'[]\')");'
			. '$db->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (2, \'support\', 3, 1, \'\', \'' . self::WITH_A_LIST . '\')");'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. '\XcVm\Core\Http\RequestManager::set(["api_key" => "' . self::WITH_A_LIST . '", "action" => "create_bouquet", "bouquet_name" => "Sports"]);'
			. '(new \XcVm\Public\Controllers\Api\AdminApiController())->index();'
			. '$db->query("SELECT COUNT(*) FROM `bouquets`;");'
			. 'echo " bouquets: " . $db->get_col();';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$this->assertSame('{"status":"STATUS_NO_PERMISSIONS"} bouquets: 0', $rOut, $rErr);
	}
}
