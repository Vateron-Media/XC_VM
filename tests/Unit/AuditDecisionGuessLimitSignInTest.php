<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Api\Enigma2ApiController;
use XcVm\Public\Controllers\Api\EpgApiController;
use XcVm\Public\Controllers\Api\PlayerApiController;
use XcVm\Public\Controllers\Api\PlaylistApiController;
use XcVm\Public\Controllers\Player\PlayerLoginController;
use XcVm\Public\Controllers\PlayerV2\PlayerLoginController as PlayerV2LoginController;
use XcVm\Tests\Support\InstallSchema;

/**
 * A sign-in with a username and password that is refused hands both to the
 * flood guard: on the client APIs (player_api, playlist, EPG, Enigma2) and in
 * the web players. An address may try fewer than
 * `bruteforce_username_attempts` different passwords for one username in
 * `bruteforce_frequency` seconds; the same one again is the same guess, and a
 * sign-in that is accepted is not counted. A token carries no password.
 *
 * A request ends in exit() and FLOOD_TMP_PATH is a constant, so each one runs
 * in a child PHP, over the test's schema and a throwaway set of cache
 * directories.
 */
final class AuditDecisionGuessLimitSignInTest extends TestCase {
	private const IP = '203.0.113.9';

	/** The third different password for a username blocks the address; the flood limit is out of the way. */
	private const SETTINGS = [
		'flood_limit' => 40, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 3, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0, 'force_epg_timezone' => 0, 'default_timezone' => 'UTC', 'disable_player_api' => 0,
		'legacy_panel_api' => 0, 'disable_enigma2' => 0, 'keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'message_of_day' => '',
		'server_name' => 'Panel', 'vod_sort_newest' => 0, 'channel_number_type' => 'bouquet', 'live_streaming_pass' => 'guess-limit-test-pass',
		'secure_stream_tokens' => 0, 'debug_show_errors' => 1,
	];

	/** A client API's controller, as Public/index.php runs it. */
	private const API = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

// What the endpoint's bootstrap leaves the controller.
define('SERVER_ID', 1);
defined('XC_VM_VERSION') || define('XC_VM_VERSION', 'test');
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'LINES_TMP_PATH' => 'lines', 'STREAMS_TMP_PATH' => 'streams', 'EPG_PATH' => 'epg'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = ['REMOTE_ADDR' => $rIn['ip'], 'HTTP_HOST' => 'panel.test', 'REQUEST_URI' => $rIn['uri'], 'SERVER_PROTOCOL' => 'HTTP/1.1'] + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1]];
$rCached = false;
$rRequest = $rIn['request'];
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Http\RequestManager::set($rRequest);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json'); // none: this server is no cluster node
\XcVm\Infrastructure\Database\DatabaseFactory::connect();

$rController = new $rIn['controller']();
register_shutdown_function([$rController, 'shutdown']);
$rController->index();
PHP;

	/** A web player's sign-in with the form's username and password. */
	private const PLAYER = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

define('SERVER_ID', 1);
define('CLIENT_INVALID', 0);
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'CONS_TMP_PATH' => 'cons'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER['REMOTE_ADDR'] = $rIn['ip'];
$rSettings = $rIn['settings'];
$rCached = false;
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json'); // none: this server is no cluster node
\XcVm\Infrastructure\Database\DatabaseFactory::connect();

$rController = new $rIn['controller']();
if ($rIn['controller'] === \XcVm\Public\Controllers\Player\PlayerLoginController::class) {
	// The first player reads the form itself.
	\XcVm\Core\Http\RequestManager::set($rIn['request']);
	$rSignIn = new ReflectionMethod($rController, 'processLogin');
	$rArguments = [];
} else {
	$rSignIn = new ReflectionMethod($rController, 'processCredentialLogin');
	$rArguments = [$rIn['request']['username'], $rIn['request']['password'], [0 => 'Invalid username or password.']];
}
$rSignIn->setAccessible(true);
$rAnswer = $rSignIn->invoke($rController, ...$rArguments);
echo json_encode(is_array($rAnswer) ? $rAnswer['status'] : $rAnswer);
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'activation_codes', 'output_formats', 'bouquets', 'blocked_ips', 'cluster_changes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[]', '[]', '[]', '[]');
		// A line with the password `secret`.
		$this->rDb->query('INSERT INTO `lines` (`username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`, `access_token`) VALUES (?, ?, ?, ?, ?, ?, ?)', 'viewer', 'secret', '[1]', '[1,2]', '[]', '[]', md5('viewer'));

		$this->rDir = sys_get_temp_dir() . '/xcvm-guess-limit-sign-in-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'lines', 'streams', 'epg', 'cons'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		foreach (['api' => self::API, 'player' => self::PLAYER] as $rName => $rChild) {
			file_put_contents($this->rDir . $rName . '.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), $rChild));
		}
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * One sign-in from the address IP: what it answered.
	 *
	 * @param class-string $rController
	 * @param array<string, mixed> $rRequest
	 */
	private function signIn(string $rController, string $rUri, array $rRequest): string {
		$rChild = (in_array($rController, [PlayerLoginController::class, PlayerV2LoginController::class], true) ? 'player' : 'api');
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'ip' => self::IP, 'uri' => $rUri, 'settings' => self::SETTINGS, 'request' => $rRequest, 'controller' => $rController];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . $rChild . '.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return $rOut;
	}

	/** How many different passwords are counted against the address for a username. */
	private function counted(string $rUsername = 'viewer'): int {
		$rFile = $this->rDir . 'flood/' . self::IP . '_user';
		return is_file($rFile) ? count(json_decode((string) file_get_contents($rFile), true)['passwords'][$rUsername] ?? []) : 0;
	}

	/** @return list<string> the addresses blocked so far, each with its reason */
	private function blocked(): array {
		$this->rDb->query('SELECT CONCAT(`ip`, \' \', `notes`) FROM `blocked_ips`');
		return $this->rDb->get_column();
	}

	/** @return array<string, array{0: class-string, 1: string}> each entry point that takes a username and password, and its address */
	public static function entryPoints(): array {
		return [
			'player_api' => [PlayerApiController::class, '/player_api.php'],
			'playlist' => [PlaylistApiController::class, '/playlist'],
			'EPG' => [EpgApiController::class, '/epg'],
			'Enigma2' => [Enigma2ApiController::class, '/enigma2.php'],
			'the first player' => [PlayerLoginController::class, '/player/login'],
			'the web player' => [PlayerV2LoginController::class, '/player/login'],
		];
	}

	#[DataProvider('entryPoints')]
	public function testDifferentRefusedPasswordsForOneUsernameCountAgainstTheAddress(string $rController, string $rUri): void {
		// A device that keeps an old password, and one mistyped: two different ones.
		foreach (['old-password', 'old-password', 'old-password', 'mistyped', 'old-password'] as $rPassword) {
			$this->signIn($rController, $rUri, ['username' => 'viewer', 'password' => $rPassword]);
		}
		$this->assertSame(2, $this->counted());
		$this->assertSame([], $this->blocked(), 'two different passwords');
		$this->assertFileDoesNotExist($this->rDir . 'flood/block_' . self::IP);

		$this->signIn($rController, $rUri, ['username' => 'viewer', 'password' => 'another']);
		$this->assertSame([self::IP . ' BRUTEFORCE USER ATTACK'], $this->blocked(), 'the third different password');
		$this->assertFileExists($this->rDir . 'flood/block_' . self::IP);
	}

	public function testAnAcceptedSignInIsNotCounted(): void {
		foreach (['wrong1', 'wrong2'] as $rPassword) {
			$rAnswer = json_decode($this->signIn(PlayerApiController::class, '/player_api.php', ['username' => 'viewer', 'password' => $rPassword]), true);
			$this->assertSame(0, $rAnswer['user_info']['auth'] ?? null);
		}

		// The line's own password, after two that were not.
		for ($rTimes = 0; $rTimes < 3; $rTimes++) {
			$rAnswer = json_decode($this->signIn(PlayerApiController::class, '/player_api.php', ['username' => 'viewer', 'password' => 'secret']), true);
			$this->assertSame(1, $rAnswer['user_info']['auth'] ?? null);
		}
		$this->assertSame(2, $this->counted());
		$this->assertSame([], $this->blocked());
	}

	/** A token is a line's access token or an activation code: whatever else the request holds is no password for it. */
	public function testATokenCarriesNoPassword(): void {
		foreach (['one', 'two', 'three', 'four'] as $rPassword) {
			$rAnswer = json_decode($this->signIn(PlayerApiController::class, '/player_api.php', ['token' => 'GUESS0', 'password' => $rPassword]), true);
			$this->assertSame(0, $rAnswer['user_info']['auth'] ?? null);
		}
		$this->assertSame(0, $this->counted('GUESS0'));
		$this->assertSame([], $this->blocked());
	}
}
