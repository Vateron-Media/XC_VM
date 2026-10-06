<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Reference\PermissionReference;

/**
 * An administrator whose group lists advanced permissions is held to them by
 * the pages that do their own work: the Modules page takes the permission of
 * the settings page (its links ask for the same), the EPG source form the EPG
 * permissions, the activation-code pages those of their menu entries, and the
 * details of an activation code the permission that lists the lines. The
 * tables behind a page take the page's permission, and a page keeps its rule
 * under every spelling of its name the router accepts. Group 1 and a group
 * that lists none keep them all.
 *
 * Each page ends the request itself, so it runs in a child process that says
 * what it answered with: 302 is the way home a refused page is sent.
 */
final class AuditAdminAuthzPagesTest extends TestCase {
	/** The Modules page asked to do something, from its own form. */
	private const MODULES = '$_SERVER["REQUEST_METHOD"] = "POST"; define("PAGE_NAME", "modules");'
		. ' \XcVm\Core\Http\RequestManager::set(["module_action" => "none", "module_name" => "x"]);'
		. ' (new \XcVm\Public\Controllers\Admin\ModulesController())->index();';

	/** The form of an EPG source that does not exist: an allowed request ends there, unanswered. */
	private const EPG = 'define("PAGE_NAME", "%s"); $db->exec("CREATE TABLE `epg` (`id` int PRIMARY KEY)");'
		. ' \XcVm\Core\Http\RequestManager::set(["id" => 999]);'
		. ' (new \XcVm\Public\Controllers\Admin\EpgController())->index();';

	/** The details of activation code 7, whose line is "codeline" / "codesecret". */
	private const DETAILS = '$db->exec("CREATE TABLE `activation_codes` (`id` int PRIMARY KEY, `subscriber_id` int, `package_id` int)");'
		. ' $db->exec("CREATE TABLE `lines` (`id` int PRIMARY KEY, `username` varchar(64), `password` varchar(64), `exp_date` int, `max_connections` int)");'
		. ' $db->exec("INSERT INTO `lines` VALUES (3, \'codeline\', \'codesecret\', NULL, 1)"); $db->exec("INSERT INTO `activation_codes` VALUES (7, 3, 1)");'
		. ' \XcVm\Core\Http\RequestManager::set(["id" => %d]);'
		. ' (new \XcVm\Public\Controllers\Admin\ActiveCodeDetailsController())->index();';

	/** The activation-code pages, each with the permission its menu entry asks for. */
	private const CODE_PAGES = [
		'active_code' => 'add_user',
		'active_codes' => 'users',
		'active_codes_batch' => 'users',
		'active_codes_mass' => 'mass_edit_lines',
	];

	/** A page asked for under the name %s: what its rule answers, or nothing when the router knows no such page. */
	private const ADMIN_RULE = 'define("PAGE_NAME", "%s"); $rRouter = \XcVm\Core\Http\Router::getInstance();'
		. ' $rRouter->get("line_mass", static function () { var_export(\XcVm\Core\Auth\PageAuthorization::checkPermissions()); });'
		. ' $rRouter->dispatch(PAGE_NAME, "GET");';

	/** The same for a reseller page, the reseller holding the page's permission (%d) or not. */
	private const RESELLER_RULE = 'define("PAGE_NAME", "%s"); $rPermissions = ["is_reseller" => 1, "reseller_client_connection_logs" => %d];'
		. ' $rRouter = \XcVm\Core\Http\Router::getInstance();'
		. ' $rRouter->get("live_connections", static function () { var_export(\XcVm\Core\Auth\PageAuthorization::checkResellerPermissions()); });'
		. ' $rRouter->dispatch(PAGE_NAME, "GET");';

	/** One table of ./table (%s names its handler), asked directly: it prints its rows, or nothing when it refuses. */
	private const TABLE = ' $rHandler = new ReflectionMethod(\XcVm\Public\Controllers\Admin\TableController::class, "%s"); $rHandler->setAccessible(true);'
		. ' $rHandler->invoke(new \XcVm\Public\Controllers\Admin\TableController(), ["draw" => 1, "recordsTotal" => 0, "recordsFiltered" => 0, "data" => []], 0, 1000);';

	/** Activation code 7, in stock, for the table of codes. */
	private const CODES = '$db->exec("CREATE TABLE `activation_codes` (`id` int PRIMARY KEY, `activation_code` varchar(32), `batch_name` varchar(32), `package_id` int, `status` int, `created_by` int, `subscriber_id` int, `mac` varchar(32), `created_at` int, `is_trial` int)");'
		. ' $db->exec("CREATE TABLE `lines` (`id` int PRIMARY KEY, `username` varchar(64), `exp_date` int, `enabled` int)");'
		. ' $db->exec("CREATE TABLE `users` (`id` int PRIMARY KEY, `username` varchar(64))");'
		. ' $db->exec("CREATE TABLE `users_packages` (`id` int PRIMARY KEY, `package_name` varchar(64))");'
		. ' $db->exec("INSERT INTO `activation_codes` VALUES (7, \'CODE7\', \'B1\', 1, 1, 0, NULL, NULL, 0, 0)");';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-authz-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
	}

	/**
	 * Runs $rCode for an administrator whose group lists $rAdvanced, in a child
	 * PHP with the suite's bootstrap and a database of its own ($db).
	 *
	 * @param list<string> $rAdvanced
	 * @return array{0: string, 1: string} the status it answered with and what it printed
	 */
	private function request(string $rCode, array $rAdvanced, int $rGroup = 5): array {
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' $rUserInfo = ' . var_export(['id' => 9, 'member_group_id' => $rGroup], true) . ';'
			. ' $rPermissions = ' . var_export(['is_admin' => 1, 'advanced' => $rAdvanced], true) . ';'
			. ' $db = new TestDb(); \XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. ' $_SERVER["HTTP_X_REQUESTED_WITH"] = "XMLHttpRequest";'
			. ' register_shutdown_function(static function () { echo "\nstatus " . var_export(http_response_code(), true); });'
			. ' ' . $rCode);
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		proc_close($rProc);
		$this->assertSame(1, preg_match('/^(.*)\nstatus (\w+)$/s', $rOut, $rMatch), 'the child ran to its end: ' . $rOut);
		return [$rMatch[2], $rMatch[1]];
	}

	public function testTheModulesPageTakesThePermissionOfTheSettingsPage(): void {
		$rAllButSettings = array_values(array_diff(PermissionReference::keys(), ['settings']));
		$this->assertSame(['302', ''], $this->request(self::MODULES, $rAllButSettings), 'sent home with every permission but settings');

		$rHandled = ['false', '{"type":"info","message":"No action taken"}'];
		$this->assertSame($rHandled, $this->request(self::MODULES, ['settings']), 'with settings');
		$this->assertSame($rHandled, $this->request(self::MODULES, ['ticket'], 1), 'group 1');
		$this->assertSame($rHandled, $this->request(self::MODULES, []), 'a group that lists no permissions');
	}

	public function testTheEpgSourceFormTakesTheEpgPermissions(): void {
		$rAllButEpg = array_values(array_diff(PermissionReference::keys(), ['epg', 'epg_edit', 'add_epg']));
		foreach (['epg', 'epg.php'] as $rName) {
			$this->assertSame(['302', ''], $this->request(sprintf(self::EPG, $rName), $rAllButEpg), 'sent home with every permission but the EPG ones, asked for as ' . $rName);
		}

		foreach ([[['epg_edit'], 5], [['epg'], 5], [['ticket'], 1], [[], 5]] as [$rAdvanced, $rGroup]) {
			$this->assertSame(['false', ''], $this->request(sprintf(self::EPG, 'epg'), $rAdvanced, $rGroup), 'looked up for group ' . $rGroup . ' with ' . implode(',', $rAdvanced));
		}
	}

	/**
	 * The router takes `line/mass` for `line_mass`, and either with a trailing
	 * `.php`: whichever name the page was asked for under, its rule is the same.
	 */
	public function testAPageKeepsItsRuleUnderEverySpellingOfItsName(): void {
		$rAllButMassEdit = array_values(array_diff(PermissionReference::keys(), ['mass_edit_lines']));
		$rOpen = [];
		foreach (['line_mass', 'line/mass', 'line_mass.php', 'line/mass.php'] as $rName) {
			if ($this->request(sprintf(self::ADMIN_RULE, $rName), $rAllButMassEdit)[1] !== 'false') {
				$rOpen[] = $rName . ' with every permission but mass_edit_lines';
			}
			$this->assertSame('true', $this->request(sprintf(self::ADMIN_RULE, $rName), ['mass_edit_lines'])[1], $rName . ' with mass_edit_lines');
		}

		foreach (['live_connections', 'live/connections', 'live_connections.php'] as $rName) {
			if ($this->request(sprintf(self::RESELLER_RULE, $rName, 0), [])[1] !== 'false') {
				$rOpen[] = $rName . ' for a reseller without the permission';
			}
			$this->assertSame('true', $this->request(sprintf(self::RESELLER_RULE, $rName, 1), [])[1], $rName . ' for a reseller with it');
		}
		$this->assertSame([], $rOpen);
	}

	public function testTheActivationCodePagesTakeThePermissionsOfTheirMenuEntries(): void {
		$GLOBALS['db'] = new stdClass();
		$rSignIn = static function (array $rAdvanced, int $rGroup = 5): void {
			$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => array_values($rAdvanced)];
		};

		$rOpen = [];
		foreach (self::CODE_PAGES as $rPage => $rKey) {
			$this->assertContains($rKey, PermissionReference::keys());
			$rSignIn(array_diff(PermissionReference::keys(), [$rKey]));
			if (PageAuthorization::checkPermissions($rPage, false)) {
				$rOpen[] = $rPage . ' with every permission but ' . $rKey;
			}
			$rSignIn([$rKey]);
			$this->assertTrue(PageAuthorization::checkPermissions($rPage, false), $rPage . ' with ' . $rKey);
			$rSignIn(['ticket'], 1);
			$this->assertTrue(PageAuthorization::checkPermissions($rPage, false), $rPage . ' for group 1');
			$rSignIn([]);
			$this->assertTrue(PageAuthorization::checkPermissions($rPage, false), $rPage . ' for a group that lists no permissions');
		}
		$this->assertSame([], $rOpen);
	}

	/** Manage Active Codes (users) and Mass Edit Active Codes (mass_edit_lines) both read the table of codes. */
	public function testTheTableOfCodesAnswersTheTwoPagesThatReadIt(): void {
		$rTable = self::CODES . sprintf(self::TABLE, 'handleActiveCodes');
		$rNeither = array_values(array_diff(PermissionReference::keys(), ['users', 'mass_edit_lines']));
		$this->assertSame('', $this->request($rTable, $rNeither)[1], 'nothing with every permission but users and mass_edit_lines');

		foreach ([[['users'], 5], [['mass_edit_lines'], 5], [['ticket'], 1], [[], 5]] as [$rAdvanced, $rGroup]) {
			$rRows = json_decode($this->request($rTable, $rAdvanced, $rGroup)[1], true)['data'] ?? null;
			$this->assertSame([7], array_column((array) $rRows, 'id'), 'the codes for group ' . $rGroup . ' with ' . implode(',', $rAdvanced));
		}
	}

	public function testTheTableOfModulesTakesThePermissionOfTheModulesPage(): void {
		$rTable = sprintf(self::TABLE, 'handleModules');
		$rAllButSettings = array_values(array_diff(PermissionReference::keys(), ['settings']));
		$this->assertSame('', $this->request($rTable, $rAllButSettings)[1], 'nothing with every permission but settings');

		foreach ([[['settings'], 5], [['ticket'], 1], [[], 5]] as [$rAdvanced, $rGroup]) {
			$this->assertStringStartsWith('{"draw":1,', $this->request($rTable, $rAdvanced, $rGroup)[1], 'the modules for group ' . $rGroup . ' with ' . implode(',', $rAdvanced));
		}
	}

	public function testTheDetailsOfACodeTakeThePermissionThatListsTheLines(): void {
		$rAllButUsers = array_values(array_diff(PermissionReference::keys(), ['users']));
		$this->assertSame(['403', ''], $this->request(sprintf(self::DETAILS, 7), $rAllButUsers), 'refused with every permission but users');

		// A code that does not exist: an allowed request gets as far as looking it up.
		$this->assertSame(['403', ''], $this->request(sprintf(self::DETAILS, 999), $rAllButUsers));
		foreach ([[['users'], 5], [['ticket'], 1], [[], 5]] as [$rAdvanced, $rGroup]) {
			$this->assertSame(['404', ''], $this->request(sprintf(self::DETAILS, 999), $rAdvanced, $rGroup), 'looked up for group ' . $rGroup . ' with ' . implode(',', $rAdvanced));
		}
	}
}
