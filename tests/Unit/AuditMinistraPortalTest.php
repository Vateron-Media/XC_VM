<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;
use XcVm\Tests\Support\InstallSchema;

/**
 * The MAG portal: a device is served only once get_profile has verified it,
 * a verified device stays verified for as long as its token is the device's
 * token, failed attempts reach the brute-force guard, and the catch-up
 * "next part" link is the programme after the one on screen.
 *
 * portal.php is a procedural entry script, so every request runs in a child
 * PHP. The child takes the script's own token check, dispatch and helper
 * functions as they are written, the real PortalHandler and the test database,
 * and stand-ins for the line lookup, the caches and the guard.
 */
final class AuditMinistraPortalTest extends TestCase {
	private const MAC = '00:1A:79:00:00:07';
	private const UNKNOWN_MAC = '00:1A:79:FF:FF:FF';
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
namespace {
	%USES%

	require %BOOTSTRAP%;

	/** Database's query()/num_rows()/get_row(): a result is read once, and get_row() is false when none is pending. */
	final class PortalDb {
		private ?\PDOStatement $rResult = null;

		public function __construct(private \PDO $rPdo) {
		}

		public function query($rQuery, ...$rArgs) {
			$GLOBALS['rQueries']++;
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
	define('EPG_PATH', $rIn['dir'] . '/epg/');
	define('SERVER_ID', 1);

	$db = new PortalDb(TestDb::connect($rIn['schema']));
	$rSettings = ['live_streaming_pass' => $rIn['pass'], 'secure_stream_tokens' => 1, 'allowed_stb_types' => [], 'stalker_lock_images' => [], 'default_timezone' => 'UTC', 'mag_disable_ssl' => 0, 'mag_keep_extension' => 0, 'enable_debug_stalker' => 0];
	$rServers = [1 => ['site_url' => 'https://panel.test/', 'http_url' => 'http://panel.test/', 'server_protocol' => 'http']];
	$rCached = false;
	$rRequest = $rIn['request'];
	$rReqType = $rIn['type'];
	$rReqAction = $rIn['action'];
	$rMAC = $rIn['mac'];
	$rIP = $rIn['ip'];
	$rAuthToken = $rIn['token'];
	$rDebug = $rSettings['enable_debug_stalker'];
	$rDevice = [];
	$rGuard = [];
	$rQueries = 0;

	register_shutdown_function(static function () {
		echo "\n#" . json_encode(['authenticated' => $GLOBALS['rAuthenticated'] ?? null, 'guard' => $GLOBALS['rGuard'], 'queries' => $GLOBALS['rQueries']]);
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
		$this->rDb->query('INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (7, 900, ?)', self::MAC);

		$this->rDir = dirname(__DIR__) . '/.tmp/ministra_portal_' . getmypid() . '_' . $this->rDb->schema();
		foreach (['/ministra', '/flood', '/epg'] as $rSub) {
			mkdir($this->rDir . $rSub, 0775, true);
		}

		$rPortal = (string) file_get_contents(MAIN_HOME . 'Ministra/portal.php');
		preg_match_all('/^use [^;]+;$/m', $rPortal, $rUses);
		$rFunctions = '';
		foreach (['getDevice', 'updateCache', 'getEPG'] as $rName) {
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
	 * @return array{body: string, authenticated: ?bool, guard: list<array{0: string, 1: list<mixed>}>, queries: int}
	 */
	private function portal(string $rType, string $rAction, ?string $rToken = null, array $rRequest = [], string $rMAC = self::MAC): array {
		$rIn = ['dir' => $this->rDir, 'schema' => $this->rDb->schema(), 'pass' => self::PASS, 'ip' => self::IP, 'type' => $rType, 'action' => $rAction, 'mac' => $rMAC, 'token' => $rToken, 'request' => $rRequest];
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $this->rDir . '/portal.php', json_encode($rIn)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rCut = strrpos($rOut, "\n#");
		$this->assertNotFalse($rCut, $rOut . $rErr);

		return ['body' => substr($rOut, 0, $rCut)] + json_decode(substr($rOut, $rCut + 2), true);
	}

	/** The token a handshake hands out. */
	private function handshake(string $rMAC = self::MAC): ?string {
		return json_decode($this->portal('stb', 'handshake', null, [], $rMAC)['body'], true)['js']['token'];
	}

	/** True when the portal answers a request only a verified device gets an answer to. */
	private function served(?string $rToken): bool {
		$rReply = $this->portal('radio', 'get_fav_ids', $rToken);
		$this->assertContains($rReply['body'], ['', '{"js":[]}']);
		$this->assertSame($rReply['body'] !== '', $rReply['authenticated']);

		return $rReply['authenticated'];
	}

	/** @return array<string, mixed> The device's cache entry. */
	private function cached(int $rMagID = 7): array {
		return igbinary_unserialize((string) file_get_contents($this->rDir . '/ministra/ministra_' . $rMagID));
	}

	/** Makes the cache entry older than the 600 s after which getDevice() reads the device again. */
	private function expireCache(int $rMagID = 7): void {
		$rEntry = $this->cached($rMagID);
		$rEntry['generated'] = time() - 601;
		file_put_contents($this->rDir . '/ministra/ministra_' . $rMagID, igbinary_serialize($rEntry));
	}

	public function testAHandshakeAloneDoesNotOpenThePortal(): void {
		$rToken = $this->handshake();
		$rEntry = file_get_contents($this->rDir . '/ministra/ministra_7');

		$this->assertFalse($this->served($rToken), 'a device that has not passed get_profile was served');
		$this->assertFalse($this->served($rToken), 'the unverified device was served on its second request');
		// get_profile stores the verified entry: a request that only carries the token must not write over it.
		$this->assertTrue($rEntry === file_get_contents($this->rDir . '/ministra/ministra_7'), 'an unverified request wrote the device entry');
	}

	public function testADeviceThatPassesGetProfileIsServed(): void {
		$rToken = $this->handshake();
		$rProfile = $this->portal('stb', 'get_profile', $rToken, self::BOX);

		$this->assertTrue($rProfile['authenticated']);
		$this->assertSame(0, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertSame([], $rProfile['guard'], 'a verified device was reported to the brute-force guard');
		$this->assertSame(['sn' => 'SN7', 'device_id' => 'DEV7'], $this->rDb->pdo->query('SELECT `sn`, `device_id` FROM `mag_devices`')->fetch(PDO::FETCH_ASSOC), 'get_profile did not record the box');
		$this->assertTrue($this->served($rToken));
		$this->assertTrue($this->served($rToken));
	}

	public function testAVerifiedDeviceStaysVerifiedWhenItsCacheEntryIsRebuilt(): void {
		$rToken = $this->handshake();
		$this->portal('stb', 'get_profile', $rToken, self::BOX);
		$this->expireCache();

		$this->assertTrue($this->served($rToken), 'the device lost its session when the cache entry was rebuilt');
		$this->assertGreaterThan(time() - 60, $this->cached()['generated'], 'the rebuilt entry was not stored');
		$this->assertTrue($this->served($rToken), 'the device lost its session on the request after the rebuild');
	}

	public function testARebuiltEntryIsNotVerifiedForAnotherToken(): void {
		$this->portal('stb', 'get_profile', $this->handshake(), self::BOX);
		$this->expireCache();

		// The device's token changed outside this cache (another handshake): its holder has not passed get_profile.
		$rOther = strtoupper(md5('another handshake'));
		$this->rDb->query('UPDATE `mag_devices` SET `token` = ? WHERE `mag_id` = 7', $rOther);
		$rToken = Encryption::mintToken(igbinary_serialize(['id' => '7', 'token' => $rOther]), self::PASS, OPENSSL_EXTRA, true);

		$this->assertFalse($this->served($rToken));
	}

	public function testADeviceRefusedByGetProfileIsNotServed(): void {
		$this->rDb->query('UPDATE `mag_devices` SET `lock_device` = 1, `sn` = ?, `device_id` = ? WHERE `mag_id` = 7', 'SN7', 'ANOTHER-BOX');
		$rToken = $this->handshake();
		$rProfile = $this->portal('stb', 'get_profile', $rToken, self::BOX);

		$this->assertFalse($rProfile['authenticated']);
		$this->assertSame(1, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertFalse($this->served($rToken), 'a device that failed the lock was served');
	}

	public function testADeviceRefusedAfterItWasVerifiedIsNotServed(): void {
		$this->rDb->query('UPDATE `mag_devices` SET `lock_device` = 1 WHERE `mag_id` = 7');
		$rToken = $this->handshake();
		$this->portal('stb', 'get_profile', $rToken, self::BOX);
		$this->assertTrue($this->served($rToken));
		// Entries are named by device number: this one belongs to the device whose number is this device's line id.
		file_put_contents($this->rDir . '/ministra/ministra_900', 'another device');

		$rProfile = $this->portal('stb', 'get_profile', $rToken, ['device_id' => 'ANOTHER-BOX'] + self::BOX);

		$this->assertSame(1, json_decode($rProfile['body'], true)['js']['status']);
		$this->assertFileExists($this->rDir . '/ministra/ministra_900', 'refusing a device removed the entry of another device');
		$this->assertFalse($this->served($rToken), 'a device get_profile refused kept its session');
	}

	public function testAHandshakeForAnUnknownMacIsReportedAndAnsweredLikeAnyOther(): void {
		$rKnown = $this->portal('stb', 'handshake');
		$this->assertSame([], $rKnown['guard'], 'a registered device was reported to the brute-force guard');
		$rKnownToken = json_decode($rKnown['body'], true)['js']['token'];

		$rUnknown = $this->portal('stb', 'handshake', null, [], self::UNKNOWN_MAC);
		$this->assertSame([['bruteforce', [self::IP, hash('sha256', self::UNKNOWN_MAC)]], ['flood', []]], $rUnknown['guard']);

		$rToken = json_decode($rUnknown['body'], true)['js']['token'];
		$this->assertIsString($rToken, 'the answer tells an unknown MAC from a registered one');
		$this->assertSame(strlen($rKnownToken), strlen($rToken));
		$rPlain = igbinary_unserialize(Encryption::readToken($rToken, self::PASS, OPENSSL_EXTRA, false));
		$this->assertSame(['id', 'token'], array_keys($rPlain));
		$this->assertSame('0', $rPlain['id'], 'the number is as long as a device\'s and is no device\'s');
		$this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $rPlain['token']);
		$this->assertSame(strlen($rToken), strlen($this->handshake(self::UNKNOWN_MAC)), 'the same MAC got tokens of two lengths');

		$this->assertFalse($this->served($rToken));
		$this->assertSame(1, (int) $this->rDb->pdo->query('SELECT COUNT(*) FROM `mag_devices`')->fetchColumn());
	}

	public function testATokenForAnUnknownMacNamesNoDevice(): void {
		// One device, numbered 1: whatever number within the devices' range a token carries, it is that device's.
		$this->rDb->query('UPDATE `mag_devices` SET `mag_id` = 1');
		$this->portal('stb', 'get_profile', $this->handshake(), self::BOX);
		$this->expireCache(1);

		$rReply = $this->portal('radio', 'get_fav_ids', $this->handshake(self::UNKNOWN_MAC));

		$this->assertFalse($rReply['authenticated']);
		$this->assertSame(0, $rReply['queries'], 'a token handed to an unknown MAC made the portal read a device');
	}

	public function testAGetProfileThatIsNotVerifiedIsReported(): void {
		$rReply = $this->portal('stb', 'get_profile', null, self::BOX, self::UNKNOWN_MAC);

		$this->assertSame(1, json_decode($rReply['body'], true)['js']['status']);
		$this->assertSame([['bruteforce', [self::IP, hash('sha256', self::UNKNOWN_MAC)]], ['flood', []]], $rReply['guard']);
	}

	public function testTheNextPartIsTheProgrammeAfterTheOneOnScreen(): void {
		// As the EPG cron writes a channel's file: rows of epg_data in start order, their values strings.
		$rStart = strtotime('2026-10-04 10:00:00 UTC');
		$rTitles = ['Morning', 'Law & Order: 100% +1', 'Afternoon'];
		$rProgrammes = [];
		foreach ($rTitles as $rIndex => $rTitle) {
			$rProgrammes[] = ['id' => (string) (100 + $rIndex), 'start' => (string) ($rStart + $rIndex * 3600), 'end' => (string) ($rStart + ($rIndex + 1) * 3600 - ($rIndex == 2 ? 1800 : 0)), 'title' => $rTitle, 'description' => ''];
		}
		file_put_contents($this->rDir . '/epg/stream_55', igbinary_serialize($rProgrammes));

		$rToken = $this->handshake();
		$this->portal('stb', 'get_profile', $rToken, self::BOX);

		// The box is on "Morning"; an EPG item's real_id is "<stream>_<start>". At the end of
		// each part it asks with the id it holds, which it takes from the link of that part.
		$rRealID = '55_' . $rStart;
		foreach ([1 => 60, 2 => 30] as $rIndex => $rMinutes) {
			$rLink = json_decode($this->portal('tv_archive', 'get_next_part_url', $rToken, ['id' => $rRealID])['body'], true)['js'];
			$this->assertSame(1, preg_match('#^ffmpeg https://panel\.test/play/([\w-]+)\?\S*$#', (string) $rLink, $rPlay), var_export($rLink, true));
			$this->assertSame(
				'ministra::timeshift/line/secret/' . $rMinutes . '/' . ($rStart + $rIndex * 3600) . '/55/' . $this->cached()['token'],
				Encryption::readToken($rPlay[1], self::PASS, OPENSSL_EXTRA, false),
			);

			// player.js: decodeURIComponent(title.replace(/\+/g, "%20")), which stops on a "%" that starts no escape.
			$this->assertSame(1, preg_match('/osd_title=([^&]*)/', $rLink, $rTitle), $rLink);
			$rTitle = str_replace('+', '%20', $rTitle[1]);
			$this->assertMatchesRegularExpression('/^(?:[^%]|%[0-9A-Fa-f]{2})*$/', $rTitle, 'the box cannot decode the title');
			$this->assertSame($rTitles[$rIndex], rawurldecode($rTitle));

			$this->assertSame(1, preg_match('/real_id=([^&]*)/', $rLink, $rMatch), 'the link does not say which programme it is: ' . $rLink);
			$rRealID = $rMatch[1];
			$this->assertSame('55_' . ($rStart + $rIndex * 3600), $rRealID);
		}

		// Nothing follows the last programme.
		$this->assertSame('{"js":false}', $this->portal('tv_archive', 'get_next_part_url', $rToken, ['id' => $rRealID])['body']);
	}
}
