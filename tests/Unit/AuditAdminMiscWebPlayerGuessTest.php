<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Player\PlayerLoginController;
use XcVm\Public\Controllers\PlayerV2\PlayerLoginController as PlayerV2LoginController;

/**
 * The web players hand the username of a refused sign-in to the flood guard
 * as the client APIs do, and the guard alone reads the limit on them
 * (`bruteforce_username_attempts`) from the settings it resolves: the
 * username is counted where a limit is set, and nothing is said or written
 * where the settings hold none.
 *
 * FLOOD_TMP_PATH is a constant, so the sign-in runs in a child PHP with its
 * own, at a panel whose settings are the legacy global and that has no line.
 */
final class AuditAdminMiscWebPlayerGuessTest extends TestCase {
	private const IP = '203.0.113.9';

	private const SETTINGS = [
		'flood_limit' => 40, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_frequency' => 300,
		'enable_cache' => false, 'county_override_1st' => 0, 'show_isps' => 0, 'allow_countries' => ['ALL'],
	];

	private const CHILD = <<<'PHP'
<?php
require %BOOTSTRAP%;

/** A panel without a line: every query answers no row. */
final class AuditAdminMiscNoLineDb extends \XcVm\Core\Database\DatabaseHandler {
	public function __construct() {
		$this->dbh = true;
	}

	public function query(string $query, mixed $buffered = false) {
		return true;
	}

	public function num_rows() {
		return 0;
	}

	public function get_row() {
		return [];
	}

	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		return [];
	}
}

$rIn = json_decode($argv[1], true);

define('CLIENT_INVALID', 0);
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'CONS_TMP_PATH' => 'cons'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER['REMOTE_ADDR'] = $rIn['ip'];
$GLOBALS['rSettings'] = $rIn['settings'];
$GLOBALS['db'] = new AuditAdminMiscNoLineDb();
\XcVm\Infrastructure\Database\DatabaseFactory::set($GLOBALS['db']);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json'); // none: this server is no cluster node

$rController = new $rIn['controller']();
if ($rIn['controller'] === \XcVm\Public\Controllers\Player\PlayerLoginController::class) {
	// The first player reads the form itself.
	\XcVm\Core\Http\RequestManager::set(['username' => 'guess1', 'password' => 'x']);
	$rSignIn = new ReflectionMethod($rController, 'processLogin');
	$rArguments = [];
} else {
	$rSignIn = new ReflectionMethod($rController, 'processCredentialLogin');
	$rArguments = ['guess1', 'x', [0 => 'Invalid username or password.']];
}
$rSignIn->setAccessible(true);
$rAnswer = $rSignIn->invoke($rController, ...$rArguments);
echo json_encode(is_array($rAnswer) ? $rAnswer['status'] : $rAnswer);
PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-web-guess-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'cons'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * A sign-in from the address IP that the player refuses, without a word besides.
	 *
	 * @param class-string $rController
	 * @param array<string, mixed> $rSettings
	 */
	private function refusedSignIn(string $rController, array $rSettings): void {
		$rIn = ['dir' => $this->rDir, 'ip' => self::IP, 'settings' => $rSettings, 'controller' => $rController];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		$this->assertSame('0', $rOut, 'refused as a wrong username or password');
	}

	/** @return list<string> the files the guard keeps for the different usernames */
	private function counted(): array {
		return array_map('basename', glob($this->rDir . 'flood/*_user') ?: []);
	}

	/** @return array<string, array{0: class-string}> */
	public static function players(): array {
		return ['the first player' => [PlayerLoginController::class], 'the web player' => [PlayerV2LoginController::class]];
	}

	#[DataProvider('players')]
	public function testARefusedUsernameIsCountedWhereTheGuardFindsALimit(string $rController): void {
		$this->refusedSignIn($rController, ['bruteforce_username_attempts' => 3] + self::SETTINGS);

		$this->assertSame([self::IP . '_user'], $this->counted());
	}

	#[DataProvider('players')]
	public function testNothingIsCountedWhereTheSettingsHoldNoLimit(string $rController): void {
		$this->refusedSignIn($rController, self::SETTINGS);

		$this->assertSame([], $this->counted());
	}
}
