<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Player\PlayerLoginController;
use XcVm\Public\Controllers\PlayerV2\PlayerLoginController as PlayerV2LoginController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The web players sign a visitor in with a line's username and password. A
 * username or password they refuse is a guess, as on the client APIs: an
 * address may try fewer than `bruteforce_username_attempts` different
 * usernames in `bruteforce_frequency` seconds. The same one again is the same
 * guess.
 *
 * FLOOD_TMP_PATH is a constant, so the sign-ins run in a child PHP with its
 * own, over the test's schema.
 */
final class AuditMiscWebPlayerSignInTest extends TestCase {
	private const IP = '203.0.113.9';

	/** Three different usernames from one address block it. */
	private const SETTINGS = [
		'flood_limit' => 40, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 3, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0,
	];

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
$rAnswers = [];
foreach ($rIn['tries'] as [$rUsername, $rPassword]) {
	if ($rIn['controller'] === \XcVm\Public\Controllers\Player\PlayerLoginController::class) {
		// The first player reads the form itself.
		\XcVm\Core\Http\RequestManager::set(['username' => $rUsername, 'password' => $rPassword]);
		$rSignIn = new ReflectionMethod($rController, 'processLogin');
		$rArguments = [];
	} else {
		$rSignIn = new ReflectionMethod($rController, 'processCredentialLogin');
		$rArguments = [$rUsername, $rPassword, [0 => 'Invalid username or password.']];
	}
	$rSignIn->setAccessible(true);
	$rAnswer = $rSignIn->invoke($rController, ...$rArguments);
	$rAnswers[] = is_array($rAnswer) ? $rAnswer['status'] : $rAnswer;
}
echo json_encode($rAnswers);
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'bouquets', 'blocked_ips', 'cluster_changes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->query('INSERT INTO `lines` (`username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`) VALUES (?, ?, ?, ?, ?, ?)', 'viewer', 'secret', '[]', '[]', '[]', '[]');

		$this->rDir = sys_get_temp_dir() . '/xcvm-web-sign-in-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'cons'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Sign-ins from the address IP, one after the other.
	 *
	 * @param class-string $rController
	 * @param list<array{0: string, 1: string}> $rTries username and password
	 */
	private function signIn(string $rController, array $rTries): void {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'ip' => self::IP, 'settings' => self::SETTINGS, 'controller' => $rController, 'tries' => $rTries];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		$this->assertSame(array_fill(0, count($rTries), 0), json_decode($rOut, true), 'every one is refused as a wrong username or password');
	}

	/** @return list<string> the usernames counted against the address */
	private function tried(): array {
		$rFile = $this->rDir . 'flood/' . self::IP . '_user';
		return is_file($rFile) ? array_map('strval', array_keys(json_decode((string) file_get_contents($rFile), true)['attempts'])) : [];
	}

	/** @return list<string> the addresses blocked so far, each with its reason */
	private function blocked(): array {
		$this->rDb->query('SELECT CONCAT(`ip`, \' \', `notes`) FROM `blocked_ips`');
		return $this->rDb->get_column();
	}

	/** @return array<string, array{0: class-string}> */
	public static function players(): array {
		return ['the first player' => [PlayerLoginController::class], 'the web player' => [PlayerV2LoginController::class]];
	}

	#[DataProvider('players')]
	public function testDifferentRefusedUsernamesCountAgainstTheAddress(string $rController): void {
		// An unknown username twice, then a line's own with a wrong password.
		$this->signIn($rController, [['guess1', 'x'], ['guess1', 'y'], ['viewer', 'wrong']]);
		$this->assertSame(['guess1', 'viewer'], $this->tried());
		$this->assertSame([], $this->blocked(), 'two different usernames');

		$this->signIn($rController, [['guess2', 'x']]);
		$this->assertSame([self::IP . ' BRUTEFORCE USER ATTACK'], $this->blocked(), 'the third different username');
		$this->assertFileExists($this->rDir . 'flood/block_' . self::IP);
	}
}
