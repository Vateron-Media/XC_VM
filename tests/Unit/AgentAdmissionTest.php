<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Error\ErrorResponder;
use XcVm\Core\Error\ErrorResponseException;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Streaming\Auth\StreamAuth;

/**
 * The LB half of admission (cluster plan, Phase 6, steps 4-6): a new viewer's
 * register (`PUT /v1/conn/{uuid}` on the agent's socket) carries what the agent
 * needs to admit it, in the `X-XCVM-Admission` header, and the endpoint turns
 * the agent's refusal into the legacy connection-limit refusal. An agent that
 * predates it ignores the header and answers as before: admitted.
 *
 * The agent here is a stand-in on a real unix socket: it logs each request
 * and answers what the test tells it to.
 */
final class AgentAdmissionTest extends TestCase {
	private const MAIN_NOW = 1800000000;

	private string $rDir;

	/** @var resource|null */
	private $rAgent = null;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-admission-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		AgentClient::useSocket($this->rDir . '/agent.sock');
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `user_id` int, `stream_id` int, `server_id` int, `user_ip` text, `hls_end` int DEFAULT 0)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		if ($this->rAgent !== null) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
		}
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		AgentClient::useSocket(null);
		DatabaseFactory::reset();
		// The last refusal outlives the test otherwise, and refuseAdmission() exits on it.
		(new \ReflectionProperty(ConnectionTracker::class, 'rRefused'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Start the stand-in agent: it answers each request in turn with the next
	 * [status, body, delay ms].
	 *
	 * @param list<array{0: int, 1: string, 2?: int}> $rReplies
	 */
	private function agent(array $rReplies): void {
		$rScript = $this->rDir . '/agent.php';
		file_put_contents($rScript, <<<'PHP'
<?php
[, $rSock, $rLog, $rReplies] = $argv;
$rServer = stream_socket_server('unix://' . $rSock, $rErrNo, $rErr);
foreach (json_decode((string) file_get_contents($rReplies), true) as $rReply) {
	$rConn = @stream_socket_accept($rServer, 10);
	if ($rConn === false) {
		break;
	}
	$rRaw = '';
	while (!str_contains($rRaw, "\r\n\r\n") && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rRaw .= $rChunk;
	}
	[$rHead, $rBody] = array_pad(explode("\r\n\r\n", $rRaw, 2), 2, '');
	$rLen = preg_match('/^Content-Length: (\d+)/mi', $rHead, $rM) ? (int) $rM[1] : 0;
	while (strlen($rBody) < $rLen && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rBody .= $rChunk;
	}
	file_put_contents($rLog, json_encode(['head' => $rHead, 'body' => $rBody]) . "\n", FILE_APPEND);
	usleep(1000 * (int) ($rReply[2] ?? 0));
	fwrite($rConn, 'HTTP/1.1 ' . $rReply[0] . " X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rReply[1]) . "\r\nConnection: close\r\n\r\n" . $rReply[1]);
	fclose($rConn);
}
PHP);
		file_put_contents($this->rDir . '/replies.json', json_encode($rReplies));
		$rNull = ['file', '/dev/null', 'w'];
		$this->rAgent = proc_open([PHP_BINARY, $rScript, $this->rDir . '/agent.sock', $this->rDir . '/requests.log', $this->rDir . '/replies.json'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists($this->rDir . '/agent.sock'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . '/agent.sock', 'the stand-in agent listens');
	}

	/**
	 * What the agent was sent: the request line, headers and JSON body of each request.
	 *
	 * @return list<array{line: string, headers: array<string, string>, body: mixed}>
	 */
	private function requests(): array {
		$rOut = [];
		foreach (file($this->rDir . '/requests.log', FILE_IGNORE_NEW_LINES) ?: [] as $rLine) {
			$rReq = json_decode($rLine, true);
			$rHead = explode("\r\n", $rReq['head']);
			$rHeaders = [];
			foreach (array_slice($rHead, 1) as $rHeader) {
				[$rName, $rValue] = array_pad(explode(':', $rHeader, 2), 2, '');
				$rHeaders[strtolower($rName)] = trim($rValue);
			}
			$rOut[] = ['line' => $rHead[0], 'headers' => $rHeaders, 'body' => json_decode($rReq['body'], true)];
		}
		return $rOut;
	}

	/** @return array<string, mixed> the registry record live.php builds for a line's TS viewer */
	private function record(string $rUUID, array $rExtra = []): array {
		return $rExtra + ['stream_id' => 100, 'server_id' => 5, 'proxy_id' => null, 'user_agent' => 'VLC', 'user_ip' => '203.0.113.9', 'container' => 'ts', 'pid' => 4321, 'date_start' => self::MAIN_NOW, 'hls_end' => 0, 'hls_last_read' => self::MAIN_NOW, 'on_demand' => 0, 'uuid' => $rUUID, 'user_id' => 42, 'identity' => 42];
	}

	/** @return array<string, mixed> the stream token as live.php decrypted it */
	private function token(string $rUUID, array $rExtra = []): array {
		return $rExtra + ['stream_id' => 100, 'uuid' => $rUUID, 'channel_info' => ['redirect_id' => 5, 'originator_id' => null], 'user_info' => ['id' => 42, 'max_connections' => 2, 'pair_id' => null]];
	}

	/** @param array<string, mixed> $rDbRow */
	private function open(array $rRecord, ?array $rToken, array $rDbRow = [], int $rTimeOffset = 0): mixed {
		return ConnectionTracker::openRecord(['redis_handler' => 0], $rRecord, $rDbRow ?: ['user_id' => 42, 'stream_id' => 100, 'server_id' => 5, 'user_ip' => '203.0.113.9', 'uuid' => $rRecord['uuid']], $rToken, $rTimeOffset);
	}

	/** @return array<string, mixed>|null the admission header of the n-th request */
	private function header(int $rIndex): ?array {
		$rRaw = $this->requests()[$rIndex]['headers']['x-xcvm-admission'] ?? null;
		return $rRaw === null ? null : json_decode($rRaw, true);
	}

	public function testANewViewersRegisterCarriesItsAdmissionRequest(): void {
		$this->agent([[200, '{}'], [200, '{}'], [200, '{}'], [200, '{}']]);
		$rUUID = str_repeat('a', 32);
		$rAdm = ['exp' => time() + 15, 'sid' => 5];

		$this->assertTrue($this->open($this->record($rUUID), $this->token($rUUID, ['adm' => $rAdm])));
		// Expired, or made for another node: the agent must ask MAIN (conn_admit).
		$this->assertTrue($this->open($this->record('b1'), $this->token('b1', ['adm' => ['exp' => time() - 1, 'sid' => 5]])));
		$this->assertTrue($this->open($this->record('b2'), $this->token('b2', ['adm' => ['exp' => time() + 15, 'sid' => 6]])));
		// An HMAC identity.
		$rHmac = $this->record('b3', ['user_id' => null, 'hmac_id' => 3, 'hmac_identifier' => 'dev', 'identity' => '3_dev']);
		$this->assertTrue($this->open($rHmac, $this->token('b3', ['hmac_id' => 3, 'identifier' => 'dev', 'user_info' => ['id' => null, 'max_connections' => 1]])));

		$rReqs = $this->requests();
		$this->assertSame('PUT /v1/conn/' . $rUUID . ' HTTP/1.0', $rReqs[0]['line']);
		$this->assertSame(['adm' => $rAdm, 'line_id' => 42, 'stream_id' => 100, 'max_connections' => 2, 'ip' => '203.0.113.9', 'ua' => 'VLC'], json_decode($rReqs[0]['headers']['x-xcvm-admission'], true));
		$this->assertSame($this->record($rUUID), $rReqs[0]['body'], 'the record itself is unchanged: an older agent stores it as before');
		$this->assertArrayNotHasKey('adm', json_decode($rReqs[1]['headers']['x-xcvm-admission'], true), 'an expired claim is not passed on');
		$this->assertArrayNotHasKey('adm', json_decode($rReqs[2]['headers']['x-xcvm-admission'], true), 'nor another node\'s');
		$this->assertSame(['hmac_id' => 3, 'identifier' => 'dev', 'stream_id' => 100, 'max_connections' => 1, 'ip' => '203.0.113.9', 'ua' => 'VLC'], json_decode($rReqs[3]['headers']['x-xcvm-admission'], true));
		$this->assertSame(0, $this->rows(), 'the agent holds them, not MAIN\'s store');
	}

	/** rtmp.php has no stream token: its limited viewer is admitted without a claim (the agent asks MAIN), and a refusal is logged and refused. */
	public function testAnRtmpViewerIsAdmittedWithoutAClaim(): void {
		$this->agent([[200, '{}'], [403, '{"admit":false,"reason":"EXPIRED"}']]);
		$rUUID = md5('17');
		$rRecord = $this->record($rUUID, ['user_agent' => '', 'container' => 'rtmp', 'pid' => '17']);
		$this->assertTrue($this->open($rRecord, ['user_info' => ['max_connections' => 2]]));
		$this->assertSame(['line_id' => 42, 'stream_id' => 100, 'max_connections' => 2, 'ip' => '203.0.113.9', 'ua' => ''], $this->header(0));
		$this->assertFalse($this->open($this->record(md5('18'), ['user_agent' => '', 'container' => 'rtmp']), ['user_info' => ['max_connections' => 2]]));
		$this->assertSame('EXPIRED', ConnectionTracker::refusedAdmission());

		$rRtmp = (string) file_get_contents(MAIN_HOME . 'Public/stream/rtmp.php');
		$this->assertStringContainsString("['user_info' => ['max_connections' => (int) \$rUserInfo['max_connections']]] + \$rMinted, intval(\$rServers[SERVER_ID]['time_offset']));", $rRtmp, 'with MAIN\'s mint on a load balancer');
		$this->assertStringContainsString("StreamAuth::admissionRefusal(\$rRefused)[0], \$rIP, 'admission: ' . \$rRefused);", $rRtmp);
		$this->assertLessThan(strpos($rRtmp, "'LINE_CREATE_FAIL'"), strpos($rRtmp, 'ConnectionTracker::refusedAdmission()'), 'a refusal is told apart before a failed register');
	}

	public function testNoAdmissionForAnUnlimitedLineARefreshOrAnEndpointWithoutAToken(): void {
		$this->agent([[200, '{}'], [200, '{}'], [200, '{}']]);
		$this->assertTrue($this->open($this->record('c1'), $this->token('c1', ['user_info' => ['id' => 42, 'max_connections' => 0]])));
		$this->assertTrue($this->open($this->record('c2'), null));
		$rConnection = $this->record('c3');
		$this->assertTrue(ConnectionTracker::updateLive(['redis_handler' => 0], $rConnection, ['pid' => 5]));
		foreach ($this->requests() as $rReq) {
			$this->assertArrayNotHasKey('x-xcvm-admission', $rReq['headers']);
		}
	}

	public function testAnHlsViewerTellsMainWhichReservationItWas(): void {
		$this->agent([[200, '{}'], [200, '{}']]);
		// live.php records an HLS viewer under its playlist key; the reservation is the token's uuid.
		$rToken = $this->token('d1', ['adm' => ['exp' => time() + 15, 'sid' => 5], 'adm_uuid' => 'd1', 'uuid' => 'hlskey']);
		$this->assertTrue($this->open($this->record('hlskey', ['container' => 'hls']), $rToken));
		$this->assertTrue($this->open($this->record('d2'), $this->token('d2', ['adm' => ['exp' => time() + 15, 'sid' => 5]])));
		[$rHls, $rTs] = $this->requests();
		$this->assertSame('d1', $rHls['body']['adm_uuid'], 'released on ingest with the viewer');
		$this->assertArrayNotHasKey('adm_uuid', $rTs['body'], 'the same uuid: nothing to add');
	}

	public function testARefusalIsNotRecordedAnywhere(): void {
		$this->agent([[403, '{"admit":false,"reason":"LIMIT"}'], [200, '{}'], [403, '{"admit":false,"reason":"no such"}']]);
		$this->assertFalse($this->open($this->record('e1'), $this->token('e1')));
		$this->assertSame('LIMIT', ConnectionTracker::refusedAdmission());
		$this->assertSame(0, $this->rows(), 'not written to MAIN\'s store in the agent\'s place');
		// The next viewer is admitted: the refusal was the last one's only.
		$this->assertTrue($this->open($this->record('e2'), $this->token('e2')));
		$this->assertNull(ConnectionTracker::refusedAdmission());
		// A reason that is not one reads as REFUSED.
		$this->assertFalse($this->open($this->record('e3'), $this->token('e3')));
		$this->assertSame('REFUSED', ConnectionTracker::refusedAdmission());
	}

	public function testAdmClaimFreshnessGoesByMainsClock(): void {
		$rToken = $this->token('h1', ['adm' => ['exp' => self::MAIN_NOW, 'sid' => 5]]);
		$this->assertSame(['exp' => self::MAIN_NOW, 'sid' => 5], AgentConnections::admission($rToken, $this->record('h1'), self::MAIN_NOW)['adm'], 'not past at its own second');
		$this->assertArrayNotHasKey('adm', AgentConnections::admission($rToken, $this->record('h1'), self::MAIN_NOW + 1));
		// A malformed claim is not passed on; the rest of the request is.
		$rBad = AgentConnections::admission($this->token('h1', ['adm' => ['exp' => (string) self::MAIN_NOW, 'sid' => 5]]), $this->record('h1'), self::MAIN_NOW);
		$this->assertSame(['line_id' => 42, 'stream_id' => 100, 'max_connections' => 2, 'ip' => '203.0.113.9', 'ua' => 'VLC'], $rBad);

		// servers.time_offset is this node's clock less MAIN's.
		$this->agent([[200, '{}'], [200, '{}']]);
		// The node runs an hour ahead: a claim that looks half an hour old here is still good on MAIN.
		$this->assertTrue($this->open($this->record('h2'), $this->token('h2', ['adm' => ['exp' => time() - 1800, 'sid' => 5]]), [], 3600));
		// An hour behind: one that looks half an hour away here expired on MAIN.
		$this->assertTrue($this->open($this->record('h3'), $this->token('h3', ['adm' => ['exp' => time() + 1800, 'sid' => 5]]), [], -3600));
		$this->assertArrayHasKey('adm', (array) $this->header(0));
		$this->assertArrayNotHasKey('adm', (array) $this->header(1));
	}

	public function testLivesRegisterPassesTheTokenOn(): void {
		$this->agent([[200, '{}'], [200, '{}']]);
		$rCtx = ['is_hmac' => null, 'identifier' => null, 'user_id' => 42, 'stream_id' => 100, 'server_id' => 5, 'proxy_id' => 0, 'user_agent' => 'VLC', 'user_ip' => '203.0.113.9', 'date_start' => self::MAIN_NOW, 'geoip_country_code' => 'PT', 'isp' => 'ISP', 'external_device' => '', 'on_demand' => 0, 'time_offset' => 0];
		$rAdm = ['exp' => time() + 15, 'sid' => 5];
		$this->assertTrue(ConnectionTracker::createLive(['redis_handler' => 0], $rCtx + ['uuid' => 'i1', 'token' => $this->token('i1', ['adm' => $rAdm])], 'ts', 4321));
		// HLS: live.php keeps the token's uuid as adm_uuid before it takes the playlist key.
		// A node in mode 2 is refused any query on MAIN's table, and its viewer
		// with it: the closed row there is left to MAIN.
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 2, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		clearstatcache();
		NodeRole::useMainBuild(false);
		$this->rDb->exec("INSERT INTO `lines_live` (`uuid`, `hls_end`) VALUES ('hlskey', 1)");
		$this->assertTrue(ConnectionTracker::createLive(['redis_handler' => 0], $rCtx + ['uuid' => 'hlskey', 'token' => $this->token('hlskey', ['adm' => $rAdm, 'adm_uuid' => 'i2'])], 'hls', null));
		$this->rDb->query("SELECT COUNT(*) FROM `lines_live` WHERE `uuid` = 'hlskey'");
		$this->assertSame(1, (int) $this->rDb->get_col());
		$this->assertSame($rAdm, $this->header(0)['adm'] ?? null);
		$this->assertSame($rAdm, $this->header(1)['adm'] ?? null);
		$this->assertSame('i2', $this->requests()[1]['body']['adm_uuid'] ?? null);
	}

	public function testTheHeaderIsAsciiWhateverTheUserAgent(): void {
		$this->agent([[200, '{}']]);
		$this->assertTrue($this->open($this->record('j1', ['user_agent' => "Kodi/\u{e9}\x80"]), $this->token('j1')));
		$rRaw = $this->requests()[0]['headers']['x-xcvm-admission'];
		$this->assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $rRaw);
		$this->assertSame("Kodi/\u{e9}\u{fffd}", json_decode($rRaw, true)['ua']);
	}

	public function testAQuarantinedNodeRegistersWithoutAsking(): void {
		// MAIN mints it no claim and answers its conn_admit NOT_ACTIVE.
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'quarantined']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		$this->agent([[200, '{}']]);
		$this->assertTrue($this->open($this->record('k1'), $this->token('k1')));
		$this->assertNull($this->header(0));
		$this->assertSame(0, $this->rows(), 'its agent still holds its viewers');
	}

	public function testARefusalIsShownAsAuthShowsItsCause(): void {
		$this->assertSame(['USER_EXPIRED', 'show_expired_video', 'expired_video_path', null], StreamAuth::admissionRefusal('EXPIRED'));
		$this->assertSame(['USER_BAN', 'show_banned_video', 'banned_video_path', null], StreamAuth::admissionRefusal('BANNED'));
		$this->assertSame(['USER_DISABLED', 'show_banned_video', 'banned_video_path', null], StreamAuth::admissionRefusal('DISABLED'));
		$this->assertSame(['AUTH_FAILED', null, null, 'INVALID_CREDENTIALS'], StreamAuth::admissionRefusal('UNKNOWN_LINE'));
		$this->assertSame(['AUTH_FAILED', null, null, 'INVALID_CREDENTIALS'], StreamAuth::admissionRefusal('UNKNOWN_HMAC'));
		foreach (['LIMIT', 'OFFLINE', 'REFUSED', 'SOMETHING_NEW'] as $rReason) {
			$this->assertSame(['USER_ALREADY_CONNECTED', 'show_connected_video', 'connected_video_path', null], StreamAuth::admissionRefusal($rReason), $rReason);
		}
	}

	public function testTheStreamEndpointsAreWiredForAdmission(): void {
		$rRoot = MAIN_HOME . 'Public/stream/';
		$rLive = (string) file_get_contents($rRoot . 'live.php');
		$rKeep = strpos($rLive, '$rTokenData["adm_uuid"] = $rTokenData["uuid"]');
		$rKey = strpos($rLive, '$rTokenData["uuid"] = ConnectionTracker::hlsConnectionKey');
		$this->assertNotFalse($rKeep);
		$this->assertNotFalse($rKey);
		$this->assertLessThan($rKey, $rKeep, 'the reserved uuid is kept before the playlist key replaces it');
		$this->assertStringContainsString('"token" => $rTokenData,', $rLive);
		$rRefusals = ['live.php' => 2, 'timeshift.php' => 2, 'vod.php' => 1];
		foreach ($rRefusals as $rFile => $rCount) {
			$rSource = (string) file_get_contents($rRoot . $rFile);
			$this->assertSame($rCount, substr_count($rSource, 'StreamAuth::refuseAdmission('), $rFile);
			$this->assertSame($rCount, substr_count($rSource, 'generateError("LINE_CREATE_FAIL")') + substr_count($rSource, "generateError('LINE_CREATE_FAIL')"), $rFile . ': every failed register is refused first');
			if ($rFile !== 'live.php') {
				$this->assertSame($rCount, substr_count($rSource, '$rTokenData, intval($rServers[SERVER_ID][\'time_offset\']));'), $rFile . ': openRecord gets the token and the offset');
			}
		}
	}

	public function testAnOlderAgentAdmitsAndAnUnexpectedAnswerFallsBack(): void {
		// An agent that predates admission ignores the header and answers with the record.
		$this->agent([[200, (string) json_encode($this->record('f1'))], [403, '{"error":"no"}'], [503, 'spool full']]);
		$this->assertTrue($this->open($this->record('f1'), $this->token('f1')));
		$this->assertNull(ConnectionTracker::refusedAdmission());
		$this->assertSame(0, $this->rows());
		// No admission answer: not a refusal. MAIN's store takes the viewer, as when the agent is down.
		$this->assertTrue((bool) $this->open($this->record('f2'), $this->token('f2')));
		$this->assertNull(ConnectionTracker::refusedAdmission());
		$this->assertTrue((bool) $this->open($this->record('f3'), $this->token('f3')));
		$this->assertSame(2, $this->rows());
		// Nothing refused: the endpoint's own error path goes on (the refusal would exit).
		StreamAuth::refuseAdmission(100, ['id' => 42], '203.0.113.9', 'ts', 'PT', 5, null);
		$this->addToAssertionCount(1);
	}

	public function testTheRegisterWaitsForTheAgentsConnAdmit(): void {
		// conn_admit has 1.5 s: longer than the socket's usual 1 s.
		$this->agent([[200, '{}', 1300]]);
		$this->assertTrue($this->open($this->record('g1'), $this->token('g1')));
		$this->assertSame(0, $this->rows(), 'the agent\'s late answer is its answer');
		$this->assertGreaterThan(AgentConnections::TIMEOUT, AgentConnections::ADMIT_TIMEOUT);
	}

	/** This node in mode 2: its agent is the only store (NodeRole::refusesConnects()). */
	private function modeTwo(): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 2, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		clearstatcache();
		NodeRole::useMainBuild(false);
	}

	public function testInModeTwoALateAgentIsWaitedFor(): void {
		// A one-core load balancer's agent took up to 1 s while the minute's
		// crons started: past the hot path's 1 s, a node in mode 2 had no
		// store to turn to and its viewer got an HTTP 500.
		$this->agent([[200, (string) json_encode($this->record('h1')), 1300], [200, (string) json_encode($this->record('h1')), 1300]]);
		$this->assertNull(AgentConnections::get('h1'), 'mode 1: MAIN\'s store stands in after 1 s');
		$this->modeTwo();
		$this->assertSame('h1', AgentConnections::get('h1')['uuid'] ?? null);
	}

	public function testInModeTwoAnAgentThatDoesNotAnswerLeavesNoOtherStoreAsked(): void {
		// No agent listens, and there is no database: any other store would throw.
		$this->modeTwo();
		DatabaseFactory::reset();
		$rSettings = ['redis_handler' => 0];
		$rConnection = $this->record('k1');
		$rCtx = ['uuid' => 'k1', 'is_hmac' => null, 'identifier' => null, 'user_id' => 42, 'server_id' => 5, 'stream_id' => 100, 'adaptive' => false];
		// A write fails, and the endpoint answers LINE_CREATE_FAIL; a check-in ends the viewer.
		$this->assertFalse(ConnectionTracker::updateLive($rSettings, $rConnection, ['pid' => 1]));
		$this->assertFalse(ConnectionTracker::openRecord($rSettings, $rConnection, ['uuid' => 'k1']));
		$this->assertNull(ConnectionTracker::refusedAdmission(), 'not the agent\'s refusal');
		$this->assertNull(ConnectionTracker::heartbeat($rSettings, 'k1', self::MAIN_NOW));
		// A read a check depends on refuses the request: "none" would skip the
		// address checks (restrict_same_ip, disallow_2nd_ip_con).
		$rReads = [
			'lookupLive' => static fn() => ConnectionTracker::lookupLive($rSettings, $rCtx, 'ts', true, false, false),
			'findByUuid' => static fn() => ConnectionTracker::findByUuid($rSettings, 'k1', '`pid`'),
			'acceptedIP' => static fn() => ConnectionTracker::acceptedIP($rSettings, 42),
		];
		ErrorResponder::$throwInsteadOfExit = true;
		try {
			foreach ($rReads as $rName => $rRead) {
				ob_start();
				try {
					$rRead();
					$this->fail($rName . ' let the request through unchecked');
				} catch (ErrorResponseException $e) {
					$this->assertSame('LINE_CREATE_FAIL', $e->errorCode, $rName);
				} finally {
					ob_end_clean();
				}
			}
		} finally {
			ErrorResponder::$throwInsteadOfExit = false;
		}
	}

	private function rows(): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live`');
		return (int) $this->rDb->get_row()['n'];
	}
}
