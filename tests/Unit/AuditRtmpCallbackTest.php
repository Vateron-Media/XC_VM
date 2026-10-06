<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Auth\StreamAuth;

if (!defined('SERVER_ID')) {
	define('SERVER_ID', 1);
}
if (!defined('CONS_TMP_PATH')) {
	define('CONS_TMP_PATH', sys_get_temp_dir() . '/xcvm-no-cons/');
}

/**
 * nginx-rtmp asks Public/stream/rtmp.php whether a client may publish or play
 * (on_publish, on_play, on_play_done). It says who the client is (addr,
 * clientid), what it asks for (call) and which stream (name), and the
 * arguments the client put on its stream name (username, password, token)
 * arrive in the same query string. The script takes nginx-rtmp's four from
 * the query string, where each has one value, and refuses a callback that
 * gives one of them two.
 *
 * A refused callback counts against the client's address in the flood guard,
 * which keeps its counts for addresses only. A viewer the script admits on a
 * full line stays, and the line's oldest connection is the one that ends; a
 * node that leaves that to MAIN tells it the viewer's address.
 */
final class AuditRtmpCallbackTest extends TestCase {
	/** What nginx-rtmp sends for a play of stream 42 by 203.0.113.5, before the stream's own arguments. */
	private const PLAY = 'app=live&flashver=LNX%209%2C0&swfurl=&tcurl=rtmp%3A%2F%2Fpanel.example%3A8880%2Flive&pageurl=&addr=203.0.113.5&clientid=17&call=play&name=42&start=4294965296&duration=0&reset=0';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-rtmp-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Runs $rCode in a child PHP that has the suite's bootstrap; what it printed. */
	private function child(string $rCode, string $rBefore = ''): string {
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, '<?php ' . $rBefore . ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . '; ' . $rCode);
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		proc_close($rProc);
		return $rOut;
	}

	/** The status rtmp.php answers nginx-rtmp's callback with, for its query string. */
	private function answer(string $rQuery): string {
		return $this->child(
			'$_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["QUERY_STRING"] = ' . var_export($rQuery, true) . '; parse_str($_SERVER["QUERY_STRING"], $_GET);'
			. ' register_shutdown_function(static function () { echo "answered " . var_export(http_response_code(), true); });'
			. ' require MAIN_HOME . "Public/stream/rtmp.php";'
		);
	}

	public function testNginxRtmpsOwnArgumentsAreReadFromTheQueryString(): void {
		$this->assertSame(['addr' => '203.0.113.5', 'clientid' => '17', 'call' => 'play', 'name' => '42'], StreamAuth::notifyArguments(self::PLAY . '&username=line&password=secret'));
		$this->assertSame(['addr' => '127.0.0.1', 'clientid' => '3', 'call' => 'publish', 'name' => 'a b&c'], StreamAuth::notifyArguments('app=live&addr=127.0.0.1&clientid=3&call=publish&name=a%20b%26c&type=live'), 'values are decoded');
		$this->assertSame(['addr' => '203.0.113.5', 'clientid' => '17', 'call' => 'play_done', 'name' => null], StreamAuth::notifyArguments('app=live&addr=203.0.113.5&clientid=17&call=play_done'), 'one nginx-rtmp did not send is null');
		// Only the name itself: these are other arguments, whatever PHP's own parsing makes of them.
		$this->assertSame(['addr' => '203.0.113.5', 'clientid' => '17', 'call' => 'play', 'name' => '42'], StreamAuth::notifyArguments(self::PLAY . '&addr[]=127.0.0.1&+call=publish&clientid.=9&names=7'));
	}

	public function testACallbackThatGivesOneOfThemTwoValuesIsRefused(): void {
		foreach (['addr=127.0.0.1', 'clientid=9', 'call=publish', 'name=7', 'name=', 'addr', '%61ddr=127.0.0.1', 'c%61ll=play_done'] as $rArgument) {
			$this->assertNull(StreamAuth::notifyArguments(self::PLAY . '&token=abc&' . $rArgument), $rArgument . ' after nginx-rtmp\'s');
			$this->assertNull(StreamAuth::notifyArguments($rArgument . '&' . self::PLAY), $rArgument . ' before nginx-rtmp\'s');
		}
		// The same value again says nothing new.
		$this->assertSame(['addr' => '203.0.113.5', 'clientid' => '17', 'call' => 'play', 'name' => '42'], StreamAuth::notifyArguments(self::PLAY . '&addr=203.0.113.5&call=play&name=%34%32'));
	}

	public function testTheScriptRefusesSuchACallbackAndStillTakesTheLocalPublisher(): void {
		$this->assertSame('answered 404', $this->answer('app=live&addr=203.0.113.5&clientid=17&call=play&name=42&start=0&duration=0&reset=0&addr=127.0.0.1&call=publish'));
		// The panel's own ffmpeg publishes from 127.0.0.1 with no password (StreamProcess, rtmp_output).
		$this->assertSame('answered 200', $this->answer('app=live&flashver=FMLE%2F3.0&swfurl=&tcurl=rtmp%3A%2F%2F127.0.0.1%3A8880%2Flive&pageurl=&addr=127.0.0.1&clientid=3&call=publish&name=42&type=live'));
	}

	public function testTheScriptReadsThoseArgumentsNowhereElse(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/stream/rtmp.php');
		$this->assertStringContainsString("StreamAuth::notifyArguments(\$_SERVER['QUERY_STRING'] ?? '')", $rSource);
		$this->assertStringNotContainsString('$_GET', $rSource);
		$this->assertDoesNotMatchRegularExpression('/\$rRequest\[\'(addr|clientid|call|name)\'\]/', $rSource, 'the parsed request holds the stream\'s own arguments');
	}

	public function testTheFloodGuardKeepsCountsForAddressesOnly(): void {
		$this->child(
			'\XcVm\Core\Config\SettingsManager::set(["flood_limit" => 10, "flood_seconds" => 2, "bruteforce_username_attempts" => 10, "bruteforce_mac_attempts" => 10, "bruteforce_frequency" => 300, "auth_flood_limit" => 10, "auth_flood_seconds" => 10, "auth_flood_sleep" => 0]);'
			. ' foreach (["block_203.0.113.9", "../outside", "203.0.113.9", "2001:db8::7"] as $rAddress) {'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkFlood($rAddress);'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rAddress, null, "someone");'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkAuthFlood(["id" => 5], $rAddress);'
			. ' }',
			'define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
		);
		$rFiles = array_values(array_diff(scandir($this->rDir . 'flood'), ['.', '..']));
		sort($rFiles);
		$this->assertSame(['2001:db8::7', '2001:db8::7_user', '203.0.113.9', '203.0.113.9_user', '5_2001:db8::7', '5_203.0.113.9'], $rFiles, 'a count per address, and none for anything else');
		$this->assertSame([], glob($this->rDir . 'outside*') ?: [], 'nothing outside its directory');
	}

	public function testAViewerAdmittedOnAFullLineStaysAndTheOldestConnectionEnds(): void {
		// The call rtmp.php makes once it has recorded the viewer: that source line is run below, as it stands there.
		$this->assertSame(1, preg_match_all('/StreamAuth::validateConnections\([^;]+\);/', (string) file_get_contents(MAIN_HOME . 'Public/stream/rtmp.php'), $rCall));

		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` varchar(64), `user_id` int, `stream_id` int, `server_id` int, `proxy_id` int DEFAULT 0, `user_agent` text, `user_ip` varchar(64), `container` varchar(16), `pid` int, `date_start` int, `geoip_country_code` varchar(8), `isp` text, `hls_end` int DEFAULT 0, `hmac_id` int, `hmac_identifier` text)');
		$rDb->exec('CREATE TABLE `streams_servers` (`stream_id` int, `server_id` int, `on_demand` int DEFAULT 0)');
		$rClient = (string) (getmypid() + 1); // nginx-rtmp's client id: never this process's pid
		$rUUID = ConnectionTracker::rtmpUuid($rClient);
		// A line with room for one: watched from one address, then played over RTMP from another.
		$rDb->query('INSERT INTO `lines_live` (`uuid`, `user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `pid`, `date_start`) VALUES (?, 7, 11, ?, ?, ?, ?, 0, 1800000000), (?, 7, 11, ?, ?, ?, ?, ?, 1800000060)', 'watching', SERVER_ID, 'tv', '198.51.100.7', 'hls', $rUUID, SERVER_ID, '', '203.0.113.9', 'rtmp', $rClient);

		$rWas = [$GLOBALS['db'] ?? null, $GLOBALS['rSettings'] ?? null, $GLOBALS['rServers'] ?? null];
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rSettings'] = ['redis_handler' => 0, 'save_closed_connection' => 0, 'on_demand_instant_off' => 0];
		$GLOBALS['rServers'] = [SERVER_ID => ['rtmp_mport_url' => 'xcvm-test://']]; // no nginx-rtmp to ask here
		RedisManager::useConnector(static fn() => false);
		NodeFlows::usePath($this->rDir . 'flows.json'); // none: this server keeps its own viewers
		try {
			(static function (array $rUserInfo, string $rIP, array $rNotify) use ($rCall): void {
				eval('use XcVm\Domain\Stream\ConnectionTracker; use XcVm\Streaming\Auth\StreamAuth; ' . $rCall[0][0]);
			})(['id' => 7, 'max_connections' => 1, 'pair_id' => null], '203.0.113.9', ['clientid' => $rClient]);

			$rDb->query('SELECT `uuid`, `hls_end` FROM `lines_live` ORDER BY `activity_id`');
			$rLeft = array_map(static fn(array $rRow): array => [$rRow['uuid'], (int) $rRow['hls_end']], $rDb->get_rows());
			$this->assertSame([['watching', 1], [$rUUID, 0]], $rLeft, 'the new viewer is recorded and the older one ended');
		} finally {
			NodeFlows::usePath(null);
			RedisManager::useConnector(null);
			[$GLOBALS['db'], $GLOBALS['rSettings'], $GLOBALS['rServers']] = $rWas;
		}
	}

	public function testANodeThatLeavesTheLimitToMainNamesTheViewersAddress(): void {
		file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . 'flows.json');
		EventSpool::useDir($this->rDir . 'spool/');
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // nginx-rtmp calls the script from the server itself
		try {
			StreamAuth::validateConnections(['id' => 7, 'max_connections' => 1, 'pair_id' => null], false, '', '203.0.113.9', null, 'rtmpviewer');
			$rFiles = glob($this->rDir . 'spool/p0/*.ndjson') ?: [];
			$this->assertCount(1, $rFiles);
			$rEvent = json_decode(trim((string) file_get_contents($rFiles[0])), true);
			$this->assertSame(['conn.limit', ['uuid' => 'rtmpviewer', 'ip' => '203.0.113.9', 'user_agent' => '', 'user_id' => 7]], [$rEvent['type'], $rEvent['d']]);
		} finally {
			if ($rWas === null) {
				unset($_SERVER['REMOTE_ADDR']);
			} else {
				$_SERVER['REMOTE_ADDR'] = $rWas;
			}
			EventSpool::useDir(null);
			NodeFlows::usePath(null);
		}
	}
}
