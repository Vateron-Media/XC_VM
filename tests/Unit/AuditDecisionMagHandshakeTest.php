<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;
use XcVm\Tests\Support\InstallSchema;

/**
 * The MAG portal: a handshake hands out a token and changes nothing. The
 * device keeps the token it has, and the box that holds it keeps being served,
 * until get_profile has verified a box that came with a handshake's token:
 * from then on that token is the device's and the one before it stops.
 *
 * portal.php is a procedural entry script, so every request runs in a child
 * PHP. The child takes the script's own token check, dispatch and helper
 * functions as they are written, the real PortalHandler and the test database,
 * and stand-ins for the line lookup, the caches, the line-cache signal and the
 * guard. PortalHandler reads the clock through a function the child can set
 * back, which is how a token made some time ago is had.
 */
final class AuditDecisionMagHandshakeTest extends TestCase {
	private const MAC = '00:1A:79:00:00:07';
	private const OTHER_MAC = '00:1A:79:00:00:08';
	private const PASS = 'portal-test-pass';
	private const IP = '203.0.113.9';

	/** What a box posts with get_profile. */
	private const BOX = ['sn' => 'SN7', 'stb_type' => 'MAG250', 'ver' => 'ImageVersion: 218', 'image_version' => '218', 'device_id' => 'DEV7', 'device_id2' => 'DEV7B', 'hw_version' => '1.7-BD-00'];

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
			$GLOBALS['rSignals'][] = $rArgs[1];
		}
	}
	class BlocklistChanges {
		public static function set(...$rArgs) {
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
namespace XcVm\Ministra {
	/** PortalHandler's clock: the real one, $rAgo seconds back. */
	function time() {
		return \time() - $GLOBALS['rAgo'];
	}
}
namespace {
	%USES%

	require %BOOTSTRAP%;

	/** Database's query()/num_rows()/get_row(). A query that contains $rDown fails as Database's does: false, and no rows. */
	final class PortalDb {
		private ?\PDOStatement $rResult = null;

		public function __construct(private \PDO $rPdo, private ?string $rDown) {
		}

		public function query($rQuery, ...$rArgs) {
			$this->rResult = null;
			if ($this->rDown !== null && str_contains($rQuery, $this->rDown)) {
				return false;
			}
			$this->rResult = $this->rPdo->prepare($rQuery);
			return $this->rResult->execute($rArgs);
		}

		public function num_rows() {
			return $this->rResult ? $this->rResult->rowCount() : 0;
		}

		public function get_row() {
			if (!$this->rResult) {
				return false;
			}
			$rRow = $this->rResult->fetch(\PDO::FETCH_ASSOC) ?: [];
			$this->rResult = null;
			return array_map(static fn($rValue) => $rValue ? (string) $rValue : $rValue, $rRow);
		}
	}

	$rIn = json_decode($argv[1], true);
	define('MINISTRA_TMP_PATH', $rIn['dir'] . '/ministra/');
	define('FLOOD_TMP_PATH', $rIn['dir'] . '/flood/');
	define('SERVER_ID', 1);

	$db = new PortalDb(TestDb::connect($rIn['schema']), $rIn['down']);
	$rSettings = ['live_streaming_pass' => $rIn['pass'], 'secure_stream_tokens' => $rIn['sealed'], 'allowed_stb_types' => [], 'stalker_lock_images' => [], 'default_timezone' => 'UTC', 'mag_disable_ssl' => 0, 'mag_keep_extension' => 0, 'enable_debug_stalker' => 0];
	$rServers = [1 => ['site_url' => 'https://panel.test/', 'http_url' => 'http://panel.test/', 'server_protocol' => 'http']];
	$rCached = false;
	$rRequest = $rIn['request'];
	$rReqType = $rIn['type'];
	$rReqAction = $rIn['action'];
	$rMAC = $rIn['mac'];
	$rIP = $rIn['ip'];
	$rAuthToken = $rIn['token'];
	$rAgo = $rIn['ago'];
	$rDebug = $rSettings['enable_debug_stalker'];
	$rDevice = [];
	$rGuard = [];
	$rSignals = [];

	register_shutdown_function(static function () {
		echo "\n#" . json_encode(['authenticated' => $GLOBALS['rAuthenticated'] ?? null, 'guard' => $GLOBALS['rGuard'], 'signals' => $GLOBALS['rSignals'], 'status' => http_response_code()]);
	});

%FUNCTIONS%

	if ($rReqType == 'stb' && $rReqAction == 'handshake') {
		PortalHandler::handleHandshake($rMAC);
	}

%TOKEN_CHECK%

	$ctx = [
		'device' => &$rDevice,
		'profile' => ['id' => $rMagID],
		'language' => ['en_GB.utf8' => []],
		'timezone' => 'UTC',
		'theme' => 'default',
		'player' => '',
		'mac' => $rMAC,
		'ip' => $rIP,
		'authenticated' => $rAuthenticated,
		'gMode' => null,
		'debug' => $rDebug,
	];

%DISPATCH%
}
PHP;

	private TestDb $rDb;
	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('mag_devices'));
		$this->rDb->query('INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (7, 900, ?), (8, 901, ?)', self::MAC, self::OTHER_MAC);

		$this->rDir = dirname(__DIR__) . '/.tmp/ministra_handshake_' . getmypid() . '_' . $this->rDb->schema();
		foreach (['/ministra', '/flood'] as $rSub) {
			mkdir($this->rDir . $rSub, 0775, true);
		}

		$rPortal = (string) file_get_contents(MAIN_HOME . 'Ministra/portal.php');
		preg_match_all('/^use [^;]+;$/m', $rPortal, $rUses);
		$rFunctions = '';
		foreach (['getDevice', 'updateCache'] as $rName) {
			$this->assertSame(1, preg_match('/^function ' . $rName . '\(.*?^}$/ms', $rPortal, $rMatch), $rName . '() is not in portal.php');
			$rFunctions .= $rMatch[0] . "\n";
		}
		file_put_contents($this->rDir . '/portal.php', strtr(self::HARNESS, [
			'%BOOTSTRAP%' => var_export(dirname(__DIR__) . '/bootstrap.php', true),
			'%USES%' => implode("\n\t", $rUses[0]),
			'%FUNCTIONS%' => $rFunctions,
			'%TOKEN_CHECK%' => $this->section($rPortal, "\t\tif (!\$rAuthToken) {\n", "\t\t\$rMagData = [];\n"),
			'%DISPATCH%' => $this->section($rPortal, "\t\tif (\$rReqType == \"stb\") {\n\t\t\tPortalHandler::handleStbPublic", "\t} else {\n\t\t// Phase 7"),
		]));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The part of portal.php from $rFrom up to $rTo. */
	private function section(string $rSource, string $rFrom, string $rTo): string {
		$rStart = strpos($rSource, $rFrom);
		$rEnd = $rStart === false ? false : strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, 'portal.php no longer has the section that starts with ' . trim($rFrom));

		return substr($rSource, $rStart, $rEnd - $rStart);
	}

	/**
	 * One portal request.
	 *
	 * @param array<string, mixed> $rRequest
	 * @param array{ago?: int, down?: string, sealed?: int} $rWith how long ago it is made, the queries the database does not answer, and the secure_stream_tokens setting
	 * @return array{body: string, authenticated: ?bool, guard: list<array{0: string, 1: list<mixed>}>, signals: list<array<string, mixed>>, status: int|false}
	 */
	private function portal(string $rType, string $rAction, ?string $rToken = null, array $rRequest = [], array $rWith = []): array {
		return $this->answer(...$this->start($rType, $rAction, $rToken, $rRequest, $rWith));
	}

	/**
	 * A portal request started, not waited for (portal()).
	 *
	 * @param array<string, mixed> $rRequest
	 * @param array{ago?: int, down?: string, sealed?: int} $rWith
	 * @return array{0: resource, 1: array<int, resource>}
	 */
	private function start(string $rType, string $rAction, ?string $rToken = null, array $rRequest = [], array $rWith = []): array {
		$rIn = ['dir' => $this->rDir, 'schema' => $this->rDb->schema(), 'pass' => self::PASS, 'ip' => self::IP, 'type' => $rType, 'action' => $rAction, 'token' => $rToken, 'request' => $rRequest] + $rWith + ['mac' => self::MAC, 'ago' => 0, 'down' => null, 'sealed' => 1];
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $this->rDir . '/portal.php', json_encode($rIn)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);

		return [$rProc, $rPipes];
	}

	/**
	 * What the request start() started answers.
	 *
	 * @param resource $rProc
	 * @param array<int, resource> $rPipes
	 * @return array{body: string, authenticated: ?bool, guard: list<array{0: string, 1: list<mixed>}>, signals: list<array<string, mixed>>, status: int|false}
	 */
	private function answer($rProc, array $rPipes): array {
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rCut = strrpos($rOut, "\n#");
		$this->assertNotFalse($rCut, $rOut . $rErr);

		return ['body' => substr($rOut, 0, $rCut)] + json_decode(substr($rOut, $rCut + 2), true);
	}

	/**
	 * The token a handshake hands out.
	 *
	 * @param array{ago?: int, sealed?: int} $rWith
	 */
	private function handshake(array $rWith = []): string {
		return json_decode($this->portal('stb', 'handshake', null, [], $rWith)['body'], true)['js']['token'];
	}

	/** A box that handshakes and passes get_profile: the token it is served with. */
	private function verifiedBox(): string {
		$rToken = $this->handshake();
		$this->assertTrue($this->portal('stb', 'get_profile', $rToken, self::BOX)['authenticated']);

		return $rToken;
	}

	/** True when the portal answers a request only a verified device gets an answer to. */
	private function served(string $rToken): bool {
		$rReply = $this->portal('radio', 'get_fav_ids', $rToken);
		// Not served: Stalker's plain-text cue to handshake again (handleUnauthenticated).
		$this->assertContains($rReply['body'], ['Authorization failed.', '{"js":[]}']);
		$this->assertSame($rReply['body'] !== 'Authorization failed.', $rReply['authenticated']);

		return $rReply['authenticated'];
	}

	/** The token inside the sealed one a handshake hands out. */
	private function plain(string $rToken): string {
		return igbinary_unserialize(Encryption::readToken($rToken, self::PASS, OPENSSL_EXTRA, false))['token'];
	}

	/** The token the panel has for a device: the one its stream links are checked against. */
	private function deviceToken(int $rMagID = 7): ?string {
		return $this->rDb->pdo->query('SELECT `token` FROM `mag_devices` WHERE `mag_id` = ' . $rMagID)->fetchColumn();
	}

	/** The device's cache entry, as stored. */
	private function entry(): string {
		return (string) file_get_contents($this->rDir . '/ministra/ministra_7');
	}

	/** Makes the cache entry older than the 600 s after which getDevice() reads the device again. */
	private function expireCache(): void {
		$rEntry = igbinary_unserialize($this->entry());
		$rEntry['generated'] = time() - 601;
		file_put_contents($this->rDir . '/ministra/ministra_7', igbinary_serialize($rEntry));
	}

	public function testAHandshakeLeavesTheDeviceItsTokenAndItsEntry(): void {
		$rBox = $this->verifiedBox();
		$rEntry = $this->entry();

		$rHandshake = $this->portal('stb', 'handshake');
		$rOther = json_decode($rHandshake['body'], true)['js']['token'];

		$this->assertSame($this->plain($rBox), $this->deviceToken(), 'a handshake replaced the token of a device before any box was verified with the new one');
		$this->assertSame([], $rHandshake['signals'], 'a handshake told the line cache of a token no box was verified with');
		$this->assertTrue($rEntry === $this->entry(), 'a handshake wrote the entry of a verified device');
		$this->assertTrue($this->served($rBox), 'a handshake logged the verified box out');
		$this->assertFalse($this->served($rOther), 'a handshake alone opened the portal');
		$this->assertTrue($this->served($rBox));
	}

	public function testABoxRefusedByGetProfileDoesNotEndTheSessionOfTheVerifiedOne(): void {
		$rBox = $this->verifiedBox();
		$this->rDb->query('UPDATE `mag_devices` SET `lock_device` = 1 WHERE `mag_id` = 7');

		$rOther = $this->handshake();
		$rProfile = $this->portal('stb', 'get_profile', $rOther, ['device_id' => 'ANOTHER-BOX'] + self::BOX);

		$this->assertFalse($rProfile['authenticated']);
		$this->assertSame(1, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertSame([['bruteforce', [self::IP, hash('sha256', self::MAC)]], ['flood', []]], $rProfile['guard'], 'a get_profile that was refused was not reported');
		$this->assertSame([], $rProfile['signals']);
		$this->assertSame($this->plain($rBox), $this->deviceToken(), 'a box get_profile refused changed the token of the device');
		$this->assertFalse($this->served($rOther), 'a box get_profile refused was served');
		$this->assertTrue($this->served($rBox), 'a box get_profile refused ended the session of the verified one');
	}

	public function testGetProfileGivesTheDeviceTheNewTokenAndTheOldOneStops(): void {
		$rOld = $this->verifiedBox();

		// The box starts again: it has a new token, and has not been to get_profile with it.
		$rNew = $this->handshake();
		$this->assertTrue($this->served($rOld), 'the token of the device stopped before get_profile verified the new one');
		$this->assertFalse($this->served($rNew));

		$rProfile = $this->portal('stb', 'get_profile', $rNew, self::BOX);

		$this->assertTrue($rProfile['authenticated']);
		$this->assertSame(0, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertSame([], $rProfile['guard']);
		$this->assertSame($this->plain($rNew), $this->deviceToken(), 'the token get_profile verified is not the one stream links are checked against');
		$this->assertSame([['type' => 'update_line', 'id' => '900']], $rProfile['signals'], 'the line cache was not told of the new token');
		$this->assertTrue($this->served($rNew));
		$this->assertFalse($this->served($rOld), 'the token the device had before stayed good');
		$this->assertTrue($this->served($rNew));

		// With the token that is the device's by now, get_profile changes no token.
		$rAgain = $this->portal('stb', 'get_profile', $rNew, self::BOX);
		$this->assertTrue($rAgain['authenticated']);
		$this->assertSame([], $rAgain['signals']);
		$this->assertSame($this->plain($rNew), $this->deviceToken());
	}

	public function testABoxThatHandshakesTwiceIsVerifiedWithEitherToken(): void {
		$rFirst = $this->handshake();
		$rSecond = $this->handshake();
		$this->assertNotSame($this->plain($rFirst), $this->plain($rSecond));
		// The form it had, and the length the device table keeps.
		$this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $this->plain($rFirst));

		$this->assertTrue($this->portal('stb', 'get_profile', $rFirst, self::BOX)['authenticated'], 'a second handshake ended the first before get_profile');
		$this->assertTrue($this->served($rFirst));
		$this->assertFalse($this->served($rSecond));

		$this->assertTrue($this->portal('stb', 'get_profile', $rSecond, self::BOX)['authenticated']);
		$this->assertTrue($this->served($rSecond));
		$this->assertFalse($this->served($rFirst));
	}

	public function testAnEntryRebuiltAfterAHandshakeKeepsTheVerifiedBoxAndOnlyThatOne(): void {
		$rBox = $this->verifiedBox();
		$rOther = $this->handshake();
		$this->expireCache();

		$this->assertTrue($this->served($rBox), 'the verified box lost its session when the entry was rebuilt after a handshake');
		$this->assertFalse($this->served($rOther), 'the rebuild verified a token that has not passed get_profile');

		$this->expireCache();
		$this->assertTrue($this->portal('stb', 'get_profile', $rOther, self::BOX)['authenticated']);
		$this->assertTrue($this->served($rOther));
		$this->assertFalse($this->served($rBox));
	}

	public function testAHandshakesTokenIsPutToGetProfileOnlyForAWhile(): void {
		$rBox = $this->verifiedBox();

		// Ten minutes at least, twenty at most: the period it was made in and the next.
		$rOld = $this->handshake(['ago' => 1300]);
		$rProfile = $this->portal('stb', 'get_profile', $rOld, self::BOX);

		$this->assertFalse($rProfile['authenticated'], 'a handshake\'s token was taken long after it was made');
		$this->assertSame(1, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertSame($this->plain($rBox), $this->deviceToken());
		$this->assertTrue($this->served($rBox));

		$this->assertTrue($this->portal('stb', 'get_profile', $this->handshake(['ago' => 300]), self::BOX)['authenticated'], 'a box that took five minutes to come to get_profile was refused');
	}

	public function testOnlyATokenAHandshakeMadeForTheDeviceIsPutToGetProfile(): void {
		$rToken = $this->handshake();

		// A token of the same form that no handshake made, and this device's token under the number of another.
		$rForms = [
			'made up' => ['id' => '7', 'token' => strtoupper(bin2hex(random_bytes(16)))],
			'of another device' => ['id' => '8', 'token' => $this->plain($rToken)],
			'not a string' => ['id' => '7', 'token' => 7],
		];
		foreach ($rForms as $rName => $rPlain) {
			$rProfile = $this->portal('stb', 'get_profile', Encryption::mintToken(igbinary_serialize($rPlain), self::PASS, OPENSSL_EXTRA, true), self::BOX);

			$this->assertFalse($rProfile['authenticated'], 'get_profile verified a box with a token ' . $rName);
			$this->assertSame([['bruteforce', [self::IP, hash('sha256', self::MAC)]], ['flood', []]], $rProfile['guard'], $rName);
		}
		$this->assertNull($this->deviceToken(7));
		$this->assertNull($this->deviceToken(8));
		$this->assertSame(['sn' => null, 'device_id' => null], $this->rDb->pdo->query('SELECT `sn`, `device_id` FROM `mag_devices` WHERE `mag_id` = 8')->fetch(PDO::FETCH_ASSOC), 'a box was recorded on a device its token was not made for');

		$this->assertTrue($this->portal('stb', 'get_profile', $rToken, self::BOX)['authenticated']);
	}

	/** A box that starts again is verified against the device as the panel has it at that moment, as it was when the handshake wrote the entry. */
	public function testGetProfileAfterAHandshakeReadsTheDeviceAgain(): void {
		$rBox = $this->verifiedBox();
		$this->rDb->query('UPDATE `mag_devices` SET `volume` = 33 WHERE `mag_id` = 7');

		$rProfile = $this->portal('stb', 'get_profile', $this->handshake(), self::BOX);

		$this->assertTrue($rProfile['authenticated']);
		$this->assertSame('33', json_decode($rProfile['body'], true)['js']['volume'], 'the box got the device as it was cached before it started again');
		$this->assertFalse($this->served($rBox));
	}

	/** The handshake wrote the entry get_profile read; the device has to be had without it as well. */
	public function testGetProfileAfterAHandshakeDoesNotNeedTheEntry(): void {
		$rToken = $this->handshake();
		unlink($this->rDir . '/ministra/ministra_7');

		$this->assertTrue($this->portal('stb', 'get_profile', $rToken, self::BOX)['authenticated']);
		$this->assertTrue($this->served($rToken));
	}

	/**
	 * As in the handshake: a lookup the database did not answer says nothing about
	 * the box. Nor is a token the device's that the database did not store: its
	 * stream links would be checked against the one before it.
	 */
	public function testAGetProfileTheDatabaseDidNotAnswerIsNotReportedAndChangesNoToken(): void {
		$rBox = $this->verifiedBox();
		$rToken = $this->handshake();

		foreach (['the lookup of the device' => 'SELECT `mac`', 'the read of the device' => 'SELECT * FROM `mag_devices` WHERE `mac`', 'the new token' => 'SET `token`'] as $rName => $rDown) {
			$rProfile = $this->portal('stb', 'get_profile', $rToken, self::BOX, ['down' => $rDown]);

			$this->assertSame(503, $rProfile['status'], $rName);
			$this->assertSame('', $rProfile['body'], $rName);
			$this->assertSame([], $rProfile['guard'], 'a box was reported while the database did not answer ' . $rName);
			$this->assertSame([], $rProfile['signals'], $rName);
			$this->assertSame($this->plain($rBox), $this->deviceToken(), $rName);
			$this->assertFalse($this->served($rToken), $rName);
			$this->assertTrue($this->served($rBox), 'the verified box lost its session while the database did not answer ' . $rName);
		}

		$this->assertTrue($this->portal('stb', 'get_profile', $rToken, self::BOX)['authenticated'], 'the box could not come back once the database answered');
		$this->assertSame($this->plain($rToken), $this->deviceToken());
	}

	/**
	 * One verified get_profile of a device at a time stores its token and
	 * writes its entry: another waits for it, so the entry and the database
	 * never hold two different tokens.
	 */
	public function testAGetProfileWaitsForTheOneInProgressOfTheSameDevice(): void {
		$rBox = $this->verifiedBox();
		$rToken = $this->handshake();
		$rLock = fopen($this->rDir . '/ministra/ministra_7.lock', 'c');
		$this->assertTrue(flock($rLock, LOCK_EX), 'the lock the other get_profile holds');

		[$rProc, $rPipes] = $this->start('stb', 'get_profile', $rToken, self::BOX);
		usleep(800000);
		$this->assertTrue(proc_get_status($rProc)['running'], 'get_profile did not wait for the one in progress');
		$this->assertSame($this->plain($rBox), $this->deviceToken());

		flock($rLock, LOCK_UN);
		$this->assertTrue($this->answer($rProc, $rPipes)['authenticated']);
		$this->assertSame($this->plain($rToken), $this->deviceToken());
		$this->assertTrue($this->served($rToken));
	}

	/** With secure_stream_tokens off the token goes out in the format anyone can alter: what tells a handshake's token is in the token, not in how it is wrapped. */
	public function testTheTokenFormatOfAnOlderVersionIsHandledAlike(): void {
		$rWith = ['sealed' => 0];
		$rBox = $this->handshake($rWith);
		$this->assertFalse(Encryption::open($rBox, self::PASS, OPENSSL_EXTRA), 'the handshake sealed its token although the setting is off');
		$this->assertTrue($this->portal('stb', 'get_profile', $rBox, self::BOX, $rWith)['authenticated']);

		$rOther = $this->handshake($rWith);
		$rPlain = igbinary_unserialize(Encryption::readToken($rOther, self::PASS, OPENSSL_EXTRA, true));
		$rMadeUp = Encryption::mintToken(igbinary_serialize(['token' => strtoupper(bin2hex(random_bytes(16)))] + $rPlain), self::PASS, OPENSSL_EXTRA, false);

		$this->assertFalse($this->portal('stb', 'get_profile', $rMadeUp, self::BOX, $rWith)['authenticated'], 'get_profile verified a box with a token no handshake made');
		$this->assertTrue($this->portal('radio', 'get_fav_ids', $rBox, [], $rWith)['authenticated'], 'a handshake logged the verified box out');
		$this->assertTrue($this->portal('stb', 'get_profile', $rOther, self::BOX, $rWith)['authenticated']);
		$this->assertFalse($this->portal('radio', 'get_fav_ids', $rBox, [], $rWith)['authenticated']);
	}
}
