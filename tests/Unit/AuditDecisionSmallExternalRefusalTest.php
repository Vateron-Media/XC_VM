<?php

use PHPUnit\Framework\TestCase;

/**
 * The second web player signs a visitor in with a line of this panel or with
 * an account on another Xtream server. A sign-in the other server refused
 * ends the request, for the page's form as for its script: the page is shown
 * again with that server's answer, and the name and password, which are that
 * server's, are not tried as a line of this panel.
 *
 * A sign-in that is accepted ends in exit() and FLOOD_TMP_PATH is a constant,
 * so each request runs in a child PHP, against a database of its own that
 * knows one line.
 */
final class AuditDecisionSmallExternalRefusalTest extends TestCase {
	private const IP = '203.0.113.9';

	/** This machine itself, which the sign-in refuses before it connects anywhere. */
	private const SERVER = 'http://127.0.0.1:9';
	private const REFUSAL = 'Failed to connect to server (' . self::SERVER . ')';
	private const NO_SUCH_LINE = 'Invalid username or password.';

	/** Answers the form post given as JSON in its one argument, then says who is signed in and what the database was asked. */
	private const CHILD = <<<'PHP'
<?php
[$rBootstrap, $rDir, $rIP, $rRequest] = json_decode($argv[1], true);
require $rBootstrap;
require MAIN_HOME . 'bootstrap.php';

// The panel is up: the controller's own boot has nothing left to do.
$rState = new \XcVm\Core\Bootstrap\BootState(\XcVm\Core\Enum\BootContext::Admin, [], \XcVm\Core\Container\ServiceContainer::getInstance());
$rState->booted = true;
$rBoot = new ReflectionProperty(XC_Bootstrap::class, 'state');
$rBoot->setAccessible(true);
$rBoot->setValue(null, $rState);

// One line, `viewer` with the password `secret`.
$rLines = new TestDb();
foreach (['lines', 'mag_devices', 'activation_codes', 'output_formats', 'bouquets', 'blocked_ips', 'cluster_changes'] as $rTable) {
	$rLines->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
}
$rLines->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[]', '[]', '[]', '[]');
$rLines->query('INSERT INTO `lines` (`id`, `username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`) VALUES (7, ?, ?, ?, ?, ?, ?)', 'viewer', 'secret', '[1]', '[1,2]', '[]', '[]');
$db = new \XcVm\Tests\Support\QueryLogDb($rLines);
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);

define('SERVER_ID', 1);
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'CONS_TMP_PATH' => 'cons'] as $rName => $rSub) {
	define($rName, $rDir . $rSub . '/');
}
$rSettings = [
	'flood_limit' => 40, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 10, 'bruteforce_mac_attempts' => 10,
	'bruteforce_frequency' => 300, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1, 'county_override_1st' => 0,
	'show_isps' => 0, 'allow_countries' => ['ALL'], 'server_name' => 'Panel',
];
$rCached = false;
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Cluster\NodeFlows::usePath($rDir . 'flows.json'); // none: this server is no cluster node

// A form's own post: no script's header, nothing that asks for JSON.
$_SERVER['REMOTE_ADDR'] = $rIP;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $rRequest;
\XcVm\Core\Http\RequestManager::set($rRequest);

register_shutdown_function(static function () use ($db) {
	echo "\n" . json_encode(['line' => $_SESSION['phash'] ?? null, 'queries' => $db->rQueries]);
});
(new \XcVm\Public\Controllers\PlayerV2\PlayerLoginController())->index();
PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-external-refusal-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'cons', 'sessions'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', self::CHILD);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * One form post from the address IP, the first of that address.
	 *
	 * @param array<string, string> $rRequest
	 * @return array{page: string, line: mixed, lookups: int, counted: int} The page it answered with, the line it signed in, how many
	 *                                                                      times it looked for a line, and how many times it counted
	 *                                                                      against the address.
	 */
	private function post(array $rRequest): array {
		array_map('unlink', glob($this->rDir . 'flood/*') ?: []);

		$rArgument = (string) json_encode([dirname(__DIR__) . '/bootstrap.php', $this->rDir, self::IP, $rRequest]);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'session.save_path=' . $this->rDir . 'sessions', $this->rDir . 'child.php', $rArgument], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);

		$rCut = strrpos($rOut, "\n");
		$this->assertNotFalse($rCut, $rOut);
		$rState = json_decode(substr($rOut, $rCut + 1), true);
		$this->assertIsArray($rState, $rOut);

		// The flood guard's file starts at 0 with the first request it counts.
		$rFile = $this->rDir . 'flood/' . self::IP;

		return [
			'page' => substr($rOut, 0, $rCut),
			'line' => $rState['line'],
			'lookups' => count(preg_grep('/\bFROM `lines`/', $rState['queries'])),
			'counted' => is_file($rFile) ? json_decode((string) file_get_contents($rFile), true)['requests'] + 1 : 0,
		];
	}

	public function testASignInAnotherServerRefusedIsNotTriedAsALineOfThisPanel(): void {
		$rForm = ['server' => self::SERVER, 'username' => 'viewer'];

		foreach (['a password of no line' => 'guess', 'the password of a line of this panel' => 'secret'] as $rCase => $rPassword) {
			$rAnswer = $this->post($rForm + ['password' => $rPassword]);

			$this->assertNull($rAnswer['line'], $rCase);
			$this->assertSame(0, $rAnswer['lookups'], $rCase);
			$this->assertSame(1, $rAnswer['counted'], $rCase);
			$this->assertStringContainsString(self::REFUSAL, $rAnswer['page'], $rCase);
			$this->assertStringNotContainsString(self::NO_SUCH_LINE, $rAnswer['page'], $rCase);
		}
	}

	public function testAPlaylistAddressAnotherServerRefusedEndsTheRequest(): void {
		// Every field the page has, in one post: the playlist address is the one that is read.
		$rAnswer = $this->post([
			'action' => 'login_playlist_url', 'playlist_url' => self::SERVER . '/get.php?username=someone&password=else',
			'server' => self::SERVER, 'username' => 'viewer', 'password' => 'secret',
		]);

		$this->assertNull($rAnswer['line']);
		$this->assertSame(0, $rAnswer['lookups']);
		$this->assertSame(1, $rAnswer['counted']);
		$this->assertStringContainsString(self::REFUSAL, $rAnswer['page']);
	}

	public function testASignInThatNamesNoServerIsALineOfThisPanel(): void {
		$rAnswer = $this->post(['username' => 'viewer', 'password' => 'secret']);
		$this->assertSame(7, $rAnswer['line']);
		$this->assertSame(0, $rAnswer['counted']);

		$rAnswer = $this->post(['username' => 'viewer', 'password' => 'guess']);
		$this->assertNull($rAnswer['line']);
		$this->assertGreaterThan(0, $rAnswer['lookups']);
		$this->assertSame(1, $rAnswer['counted']);
		$this->assertStringContainsString(self::NO_SUCH_LINE, $rAnswer['page']);
	}
}
