<?php

use PHPUnit\Framework\TestCase;

/**
 * The MAG portal's handshake reports a MAC to the brute-force guard, and
 * answers it with a token that names no device, once the database has said
 * the panel does not have it. A lookup the database did not answer says
 * nothing about the box: nothing is reported and no token goes out.
 *
 * The handshake answers and exits, so each one runs in a child PHP: the real
 * PortalHandler, portal.php's own getDevice() and updateCache(), the test
 * database, and stand-ins for the line lookup, the caches and the guard.
 */
final class AuditReviewPlayersHandshakeTest extends TestCase {
	private const MAC = '00:1A:79:00:00:07';
	private const UNKNOWN_MAC = '00:1A:79:FF:FF:FF';
	private const IP = '203.0.113.9';

	private const HARNESS = <<<'PHP'
<?php
namespace XcVm\Infrastructure\Cache {
	class CacheReader {
		public static function get($rKey) {
			return [];
		}
	}
}
namespace XcVm\Domain\User {
	class UserRepository {
		public static function getStreamingUserInfo(...$rArgs) {
			return ['id' => 900, 'username' => 'line', 'password' => 'secret', 'bouquet' => [], 'allowed_ips' => '[]'];
		}
	}
}
namespace XcVm\Core\Cluster {
	class SignalDispatcher {
		public static function cache(...$rArgs) {
		}
	}
}
namespace XcVm\Core\Auth {
	class BruteforceGuard {
		public static function checkBruteforce(...$rArgs) {
			$GLOBALS['rGuard'][] = ['bruteforce', $rArgs];
		}
		public static function checkFlood(...$rArgs) {
			$GLOBALS['rGuard'][] = ['flood', $rArgs];
		}
	}
}
namespace {
	%USES%

	require %BOOTSTRAP%;

	/** The test database, except that it does not answer the first $rFail lookups of a device by its MAC. */
	final class HandshakeDb {
		private bool $rFailed = false;

		public function __construct(private TestDb $rDb, private int $rFail) {
		}

		public function query($rQuery, ...$rArgs) {
			$this->rFailed = 0 < $this->rFail && str_contains($rQuery, 'WHERE `mac` = ?');
			if ($this->rFailed) {
				$this->rFail--;
				return false;
			}
			return $this->rDb->query($rQuery, ...$rArgs);
		}

		public function num_rows() {
			return $this->rFailed ? 0 : $this->rDb->num_rows();
		}

		public function get_row() {
			return $this->rFailed ? [] : $this->rDb->get_row();
		}
	}

	$rIn = json_decode($argv[1], true);
	define('MINISTRA_TMP_PATH', $rIn['dir']);
	define('SERVER_ID', 1);

	$rPanel = new TestDb();
	$rPanel->exec(\XcVm\Tests\Support\InstallSchema::table('mag_devices'));
	$rPanel->query('INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (7, 900, ?)', $rIn['registered']);

	$db = new HandshakeDb($rPanel, $rIn['fail']);
	$rSettings = ['live_streaming_pass' => 'portal-test-pass', 'secure_stream_tokens' => 1];
	$rCached = false;
	$rIP = $rIn['ip'];
	$rDevice = [];
	$rGuard = [];

	register_shutdown_function(static function () {
		echo "\n#" . json_encode(['guard' => $GLOBALS['rGuard'], 'status' => http_response_code()]);
	});

%FUNCTIONS%

	PortalHandler::handleHandshake($rIn['mac']);
}
PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-portal-handshake-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);

		$rPortal = (string) file_get_contents(MAIN_HOME . 'Ministra/portal.php');
		preg_match_all('/^use [^;]+;$/m', $rPortal, $rUses);
		$rFunctions = '';
		foreach (['getDevice', 'updateCache'] as $rName) {
			$this->assertSame(1, preg_match('/^function ' . $rName . '\(.*?^}$/ms', $rPortal, $rMatch), $rName . '() is not in portal.php');
			$rFunctions .= $rMatch[0] . "\n";
		}
		file_put_contents($this->rDir . 'handshake.php', strtr(self::HARNESS, [
			'%BOOTSTRAP%' => var_export(dirname(__DIR__) . '/bootstrap.php', true),
			'%USES%' => implode("\n\t", $rUses[0]),
			'%FUNCTIONS%' => $rFunctions,
		]));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * One handshake of $rMAC with a database that does not answer its first $rFail lookups by MAC.
	 *
	 * @return array{token: mixed, body: string, status: int|false, guard: list<array{0: string, 1: list<mixed>}>}
	 */
	private function handshake(string $rMAC, int $rFail): array {
		$rIn = ['dir' => $this->rDir, 'registered' => self::MAC, 'mac' => $rMAC, 'ip' => self::IP, 'fail' => $rFail];
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $this->rDir . 'handshake.php', json_encode($rIn)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rCut = strrpos($rOut, "\n#");
		$this->assertNotFalse($rCut, $rOut . $rErr);
		$rBody = substr($rOut, 0, $rCut);

		return ['token' => json_decode($rBody, true)['js']['token'] ?? null, 'body' => $rBody] + json_decode(substr($rOut, $rCut + 2), true);
	}

	public function testAHandshakeWhoseLookupFailedIsNotReported(): void {
		foreach ([self::MAC, self::UNKNOWN_MAC] as $rMAC) {
			$rReply = $this->handshake($rMAC, PHP_INT_MAX);

			$this->assertSame([], $rReply['guard'], 'a box was reported while the database could not look it up');
			$this->assertSame('', $rReply['body'], 'the box was handed a token its get_profile is refused and reported with');
			$this->assertSame(503, $rReply['status']);
		}
	}

	/** The database answers again a moment later and has the device: it was never an unknown one. */
	public function testADeviceALookupMissedIsNotReported(): void {
		$rReply = $this->handshake(self::MAC, 1);

		$this->assertSame([], $rReply['guard'], 'a registered device was reported after a lookup that failed');
		$this->assertSame('', $rReply['body']);
		$this->assertSame(503, $rReply['status']);
	}

	public function testAMacTheDatabaseDoesNotHaveIsReported(): void {
		foreach ([0, 1] as $rFail) {
			$rReply = $this->handshake(self::UNKNOWN_MAC, $rFail);

			$this->assertSame([['bruteforce', [self::IP, hash('sha256', self::UNKNOWN_MAC)]], ['flood', []]], $rReply['guard']);
			$this->assertIsString($rReply['token'], 'the answer tells an unknown MAC from a registered one');
			$this->assertNotSame(503, $rReply['status']);
		}
	}

	public function testARegisteredDeviceGetsItsToken(): void {
		$rReply = $this->handshake(self::MAC, 0);

		$this->assertSame([], $rReply['guard']);
		$this->assertIsString($rReply['token']);
		$this->assertNotSame(503, $rReply['status']);
	}
}
