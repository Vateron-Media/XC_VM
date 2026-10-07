<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Streaming\Auth\RtmpOffline;

/**
 * An RTMP viewer on a load balancer: MAIN's answer through the agent, and
 * while the agent cannot reach MAIN (its 502), MAIN's last yes for the same
 * credentials, stream, address and restream flag, for TTL seconds. Never when
 * MAIN refused (409) or the agent did not answer; never under deny, on a node
 * that is not active or whose lease refuses new sessions, or past the line's
 * expiry. The agent is a stand-in on a real unix socket.
 */
final class RtmpOfflineTest extends TestCase {
	private const NOW = 1800000000;

	private const CREDS = ['username' => 'viewer', 'password' => 'secret-pass'];

	private const USER = ['id' => 42, 'max_connections' => 2, 'pair_id' => null, 'con_isp_name' => 'ISP', 'is_restreamer' => 0, 'exp_date' => null];

	private const NO = ['ok' => false, 'reason' => 'NO_ANSWER'];

	private string $rDir;

	/** @var resource|null */
	private $rAgent = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-rtmp-offline-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cache', 0700, true);
		RtmpOffline::useDir($this->rDir . 'cache/');
		$this->flows('active');
		AgentClient::useSocket($this->rDir . 'agent.sock');
		NodeLease::useExtension(false);
		NodeLease::usePath($this->rDir . 'no-lease.json');
	}

	protected function tearDown(): void {
		if (is_resource($this->rAgent)) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
		}
		RtmpOffline::useDir(null);
		NodeFlows::usePath(null);
		AgentClient::useSocket(null);
		NodeLease::usePath(null);
		NodeLease::useExtension(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(string $rState): void {
		file_put_contents($this->rDir . 'flows.json', (string) json_encode(['mode' => 2, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => $rState]));
		NodeFlows::usePath($this->rDir . 'flows.json');
	}

	/**
	 * The stand-in agent: it answers each request in turn with the next [status, body].
	 *
	 * @param list<array{0: int, 1: string}> $rReplies
	 */
	private function agent(array $rReplies): void {
		if (is_resource($this->rAgent)) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
		}
		@unlink($this->rDir . 'agent.sock');
		file_put_contents($this->rDir . 'agent.php', <<<'PHP'
<?php
[, $rSock, $rReplies] = $argv;
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
	fwrite($rConn, 'HTTP/1.1 ' . $rReply[0] . " X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rReply[1]) . "\r\nConnection: close\r\n\r\n" . $rReply[1]);
	fclose($rConn);
}
PHP);
		file_put_contents($this->rDir . 'replies.json', json_encode($rReplies));
		$rNull = ['file', '/dev/null', 'w'];
		$this->rAgent = proc_open([PHP_BINARY, $this->rDir . 'agent.php', $this->rDir . 'agent.sock', $this->rDir . 'replies.json'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists($this->rDir . 'agent.sock'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . 'agent.sock', 'the stand-in agent listens');
	}

	/** @param array<string, mixed> $rSettings */
	private function ask(array $rSettings = [], int $rStream = 100, string $rIP = '203.0.113.9', array $rCreds = self::CREDS, bool $rRestream = false, int $rNow = self::NOW): array {
		return RtmpOffline::ask($rSettings, $rStream, $rIP, $rCreds, $rRestream, str_repeat('a', 32), $rNow);
	}

	private static function yes(array $rUser = self::USER): string {
		return (string) json_encode(['ok' => true, 'user' => $rUser, 'country_code' => 'FR', 'token' => ['uuid' => str_repeat('a', 32), 'prf' => ['iat' => 1, 'p' => str_repeat('c', 32)]]]);
	}

	public function testMainsYesStandsInOnlyWhileTheAgentCannotReachMain(): void {
		$rStandIn = ['ok' => true, 'user' => self::USER, 'country_code' => 'FR'];
		$this->agent([[200, self::yes()], [502, 'MAIN unreachable'], [409, 'denied: NOT_ACTIVE'], [502, 'MAIN unreachable']]);

		$this->assertSame(json_decode(self::yes(), true), $this->ask(), 'MAIN\'s answer, as it gave it');
		$this->assertSame($rStandIn, $this->ask(rNow: self::NOW + 60), 'MAIN not reached: its yes, without its mint');
		$this->assertSame(self::NO, $this->ask(rNow: self::NOW + 61), 'MAIN refused this node: refused');
		$this->assertSame(self::NO, $this->ask(rNow: self::NOW + 62), 'and forgotten');
	}

	public function testNoAgentAnswerRefusesAndForgets(): void {
		$this->agent([[200, self::yes()]]);
		$this->ask();
		proc_terminate($this->rAgent);
		proc_close($this->rAgent);
		$this->rAgent = null;
		@unlink($this->rDir . 'agent.sock');
		$this->assertSame(self::NO, $this->ask(), 'the agent did not answer: MAIN was not asked');
		$this->agent([[502, 'MAIN unreachable']]);
		$this->assertSame(self::NO, $this->ask(), 'forgotten');
	}

	public function testItStandsInForTheSameViewerAndWithinTheWindowOnly(): void {
		$rReplies = [[200, self::yes()]];
		foreach (range(1, 9) as $i) {
			$rReplies[] = [502, 'MAIN unreachable'];
		}
		$this->agent($rReplies);
		$this->ask();
		$this->assertTrue($this->ask(['lb_offline_admission' => 'allow'], rNow: self::NOW + RtmpOffline::TTL)['ok'], 'its last second');
		$this->assertSame(self::NO, $this->ask(rNow: self::NOW + RtmpOffline::TTL + 1), 'past the window');
		$this->assertSame(self::NO, $this->ask(['lb_offline_admission' => 'deny']), 'the offline policy says deny');
		$this->assertSame(self::NO, $this->ask(rStream: 101), 'another stream');
		$this->assertSame(self::NO, $this->ask(rIP: '203.0.113.10'), 'another address: MAIN checked this one');
		$this->assertSame(self::NO, $this->ask(rRestream: true), 'a restreamer\'s request: MAIN checks those apart');
		$this->assertSame(self::NO, $this->ask(rCreds: ['username' => 'viewer', 'password' => 'other']), 'other credentials');
		$this->assertSame(self::NO, $this->ask(rCreds: ['token' => 'secret-pass']), 'a token is not a password');
		$this->assertTrue($this->ask()['ok'], 'the same viewer still');
	}

	/** Bytes that are not UTF-8 name a viewer as any others do: such credentials share no key. */
	public function testCredentialsThatAreNotUtf8ShareNoKey(): void {
		$rLatin = ['username' => "caf\xe9", 'password' => "p\xe4ss"];
		$this->agent([[200, self::yes()], [502, ''], [502, ''], [502, '']]);
		$this->ask(rCreds: $rLatin);
		$this->assertSame(self::NO, $this->ask(rCreds: ['username' => "caf\xe8", 'password' => "p\xe4ss"]), 'another name');
		$this->assertSame(self::NO, $this->ask(rStream: 101, rCreds: $rLatin), 'another stream');
		$this->assertTrue($this->ask(rCreds: $rLatin)['ok'], 'the same bytes');
	}

	public function testANodeThatMayNotTakeNewViewersOrALineThatExpiredIsRefused(): void {
		$this->agent([[200, self::yes()], [502, ''], [502, ''], [502, ''], [200, self::yes(['exp_date' => self::NOW + 30] + self::USER)], [502, ''], [502, '']]);
		$this->ask();

		$this->flows('quarantined');
		$this->assertSame(self::NO, $this->ask(), 'a quarantined node');
		$this->flows('active');

		// The lease ran out and the node drains: no new viewer starts (NodeLease).
		$rNowMs = (int) round(microtime(true) * 1000);
		file_put_contents($this->rDir . 'lease.json', (string) json_encode(['exp' => intdiv($rNowMs, 1000) - 5, 'iat' => intdiv($rNowMs, 1000) - 3600, 'gen' => 4, 'server_id' => 7, 'anchor_ms' => $rNowMs, 'wrote_at_ms' => $rNowMs]));
		NodeLease::usePath($this->rDir . 'lease.json');
		$this->assertSame(self::NO, $this->ask(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]), 'a draining node');
		NodeLease::usePath($this->rDir . 'no-lease.json');
		$this->assertTrue($this->ask()['ok'], 'serving again');

		$this->ask();
		$this->assertTrue($this->ask(rNow: self::NOW + 29)['ok']);
		$this->assertSame(self::NO, $this->ask(rNow: self::NOW + 30), 'the line expired');
	}

	public function testNoCredentialIsWrittenAndWhatIsPastTheWindowIsPruned(): void {
		$this->agent([[200, self::yes()], [200, self::yes()]]);
		$this->ask();
		$this->ask(rStream: 101);
		$rCache = $this->rDir . 'cache/';
		foreach (array_merge(glob($rCache . '*') ?: [], [$rCache . '.key']) as $rFile) {
			$this->assertStringNotContainsString('secret-pass', (string) file_get_contents($rFile), basename($rFile));
			$this->assertStringNotContainsString('viewer', basename($rFile));
		}
		$this->assertSame(32, strlen((string) file_get_contents($rCache . '.key')));
		$this->assertSame('0600', substr(sprintf('%o', fileperms($rCache . '.key')), -4));

		$rFiles = glob($rCache . '*.json') ?: [];
		$this->assertCount(2, $rFiles);
		touch($rFiles[0], time() - RtmpOffline::TTL - 5);
		$this->assertSame(1, RtmpOffline::prune());
		$this->assertCount(1, glob($rCache . '*.json') ?: []);
	}

	public function testRtmpPhpAsksThroughIt(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/stream/rtmp.php');
		$this->assertStringContainsString("\$rAuth = RtmpOffline::ask(\$rSettings, \$rStreamID, \$rIP, \$rCreds, \$rRestreamDetect, ConnectionTracker::rtmpUuid(\$rNotify['clientid']));", $rSource);
		$this->assertStringNotContainsString('AgentClient::', $rSource, 'no other way to MAIN');
	}
}
