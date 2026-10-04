<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\ClusterDiagnosis;
use XcVm\Core\Cluster\NodeFlows;

/**
 * `server:diagnose` reports a node's cluster state (ADR 0004, Phase 10): on
 * the node from its agent's `GET /v1/status`, on MAIN from the node's row and
 * command queue. ClusterDiagnosis judges both; these pin what it calls a
 * problem and what it leaves alone, and that AgentClient::status() reads the
 * agent's document (a stand-in agent on a real unix socket, as
 * AgentClientRetryTest).
 */
final class ClusterDiagnosisTest extends TestCase {
	private const NOW_MS = 1_800_000_000_000;

	private ?string $rDir = null;

	/** @var resource|null */
	private $rAgent = null;

	protected function tearDown(): void {
		if ($this->rAgent !== null) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
			$this->rAgent = null;
		}
		AgentClient::useSocket(null);
		if ($this->rDir !== null) {
			exec('rm -rf ' . escapeshellarg($this->rDir));
		}
	}

	/** A healthy agent's document, as XC_VM_Fanout's status.go writes it. */
	private function agentDoc(array $rOver = []): array {
		$rNow = intdiv(self::NOW_MS, 1000);
		return array_replace([
			'v' => 1, 'version' => '1.4.0', 'server_id' => 3, 'now_ms' => self::NOW_MS,
			'state' => 'active', 'mode' => 1, 'flows' => NodeFlows::TELEMETRY | NodeFlows::COMMANDS, 'fenced' => false,
			'heartbeat_sec' => 2, 'last_heartbeat_ms' => self::NOW_MS - 1500,
			'main_skew_ms' => -200, 'main_time_seen_ms' => self::NOW_MS - 1500, 'busy_refusals' => 0,
			'token' => ['epoch' => 9, 'gen' => 2, 'nbf' => $rNow - 120, 'exp' => $rNow + 3600, 'refresh_at' => $rNow + 1800],
			'lease' => ['gen' => 2, 'iat' => $rNow - 60, 'exp' => $rNow + 3600 + 12 * 3600], 'lease_refused' => '',
			'lanes' => [
				['name' => 'p0', 'files' => 0, 'bytes' => 0, 'oldest_ms' => 0, 'inflight' => 0, 'cursor' => 41],
				['name' => 'p1', 'files' => 2, 'bytes' => 2048, 'oldest_ms' => self::NOW_MS - 4000, 'inflight' => 3, 'cursor' => 7],
			],
		], $rOver);
	}

	/** @return array<string, array{label: string, value: string, ok: bool, problem: ?string}> by label */
	private function byLabel(array $rChecks): array {
		$rOut = [];
		foreach ($rChecks as $rCheck) {
			$this->assertSame($rCheck['ok'], $rCheck['problem'] === null, $rCheck['label'] . ': a problem exactly when not ok');
			$rOut[$rCheck['label']] = $rCheck;
		}
		return $rOut;
	}

	public function testAHealthyAgentHasNoProblem(): void {
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(), self::NOW_MS, []));
		foreach ($rChecks as $rLabel => $rCheck) {
			$this->assertTrue($rCheck['ok'], $rLabel . ': ' . $rCheck['value']);
		}
		$this->assertStringContainsString('mode 1 · flows telemetry,commands', $rChecks['Cluster state']['value']);
		$this->assertStringContainsString('epoch 9 (gen 2)', $rChecks['Token']['value']);
		$this->assertStringContainsString('fence off', $rChecks['Lease']['value'], 'lb_lease_fence is off by default');
		$this->assertStringContainsString('2 file(s), 2.0 KiB, oldest 4s, 3 event(s) in flight · MAIN at #7', $rChecks['Outbox p1']['value']);
		$this->assertStringContainsString('-0.2s', $rChecks['Clock vs MAIN']['value']);
	}

	public function testAnAgentThatDoesNotAnswerIsTheProblem(): void {
		$rChecks = ClusterDiagnosis::agent(null, self::NOW_MS, []);
		$this->assertCount(1, $rChecks);
		$this->assertFalse($rChecks[0]['ok']);
		$this->assertStringContainsString('does not answer', (string) $rChecks[0]['problem']);
	}

	public function testEachFailureIsNamed(): void {
		$rNow = intdiv(self::NOW_MS, 1000);
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc([
			'state' => 'quarantined',
			'fenced' => true,
			'last_heartbeat_ms' => self::NOW_MS - 45000,
			'main_skew_ms' => 40000,
			'token' => null,
			'lease' => ['gen' => 2, 'iat' => $rNow - 90000, 'exp' => $rNow - 100],
			'lanes' => [['name' => 'p0', 'files' => 5, 'bytes' => 900, 'oldest_ms' => self::NOW_MS - 300000, 'inflight' => 0, 'cursor' => -1]],
		]), self::NOW_MS, ['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]));
		$this->assertFalse($rChecks['Cluster state']['ok']);
		$this->assertStringContainsString('quarantined', (string) $rChecks['Cluster state']['problem']);
		$this->assertFalse($rChecks['Licence fence']['ok']);
		$this->assertFalse($rChecks['Last heartbeat']['ok']);
		$this->assertStringContainsString('45s', (string) $rChecks['Last heartbeat']['problem']);
		$this->assertFalse($rChecks['Clock vs MAIN']['ok'], '40 s is past the warning, well before the 90 s refusal');
		$this->assertFalse($rChecks['Token']['ok']);
		$this->assertFalse($rChecks['Lease']['ok']);
		$this->assertStringContainsString('no new viewer starts here', (string) $rChecks['Lease']['problem'], 'with the fence on');
		$this->assertStringContainsString('fence on', $rChecks['Lease']['value']);
		$this->assertFalse($rChecks['Outbox p0']['ok']);
		$this->assertStringContainsString('MAIN\'s cursor not known yet', $rChecks['Outbox p0']['value']);
	}

	public function testAnExpiredLeaseWithTheFenceOffOnlyWarns(): void {
		$rNow = intdiv(self::NOW_MS, 1000);
		$rLease = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(['lease' => ['gen' => 2, 'iat' => $rNow - 90000, 'exp' => $rNow - 100]]), self::NOW_MS, []))['Lease'];
		$this->assertFalse($rLease['ok']);
		$this->assertStringContainsString('keeps serving', (string) $rLease['problem']);
	}

	public function testMainsTimeNotSeenIsNoSkew(): void {
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(['main_time_seen_ms' => 0, 'main_skew_ms' => 0, 'last_heartbeat_ms' => 0, 'state' => '']), self::NOW_MS, []));
		$this->assertTrue($rChecks['Clock vs MAIN']['ok']);
		$this->assertSame('MAIN\'s time not observed yet', $rChecks['Clock vs MAIN']['value']);
		$this->assertFalse($rChecks['Last heartbeat']['ok']);
		$this->assertFalse($rChecks['Cluster state']['ok']);
	}

	public function testALeaseRefusedIsReported(): void {
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(['lease' => null, 'lease_refused' => 'another node\'s lease']), self::NOW_MS, []));
		$this->assertFalse($rChecks['Lease']['ok']);
		$this->assertStringContainsString('another node\'s lease', (string) $rChecks['Lease']['problem']);
	}

	public function testTheFenceWindowFollowsTheTokenAndTheSettings(): void {
		$this->assertSame(['lease_until' => 1000 + 12 * 3600, 'drain_until' => 1000 + 12 * 3600 + 600, 'tolerance_h' => 12, 'drain_min' => 10, 'fence_on' => false], ClusterDiagnosis::fenceWindow(1000, []), 'the defaults');
		$rWindow = ClusterDiagnosis::fenceWindow(1000, ['lb_partition_tolerance_h' => 99, 'lb_fence_drain_min' => 0, 'lb_lease_fence' => 1]);
		$this->assertSame(1000 + 24 * 3600, $rWindow['lease_until'], 'the tolerance clamped to its 24 h');
		$this->assertSame($rWindow['lease_until'], $rWindow['drain_until']);
		$this->assertTrue($rWindow['fence_on']);
		$this->assertNull(ClusterDiagnosis::fenceWindow(null, [])['lease_until'], 'no token, no window');
	}

	public function testMainsViewOfANode(): void {
		$rNow = intdiv(self::NOW_MS, 1000);
		$rNode = ['server_id' => 3, 'state' => 'active', 'health' => 'ok', 'mode' => 2, 'flows' => 127, 'gen' => 2, 'epoch' => 9,
			'token_exp' => $rNow + 600, 'last_seen_at' => self::NOW_MS - 2000, 'clock_offset_ms' => 120, 'useq_p0' => 41, 'useq_p1' => 7
		];
		$rChecks = $this->byLabel(ClusterDiagnosis::node($rNode, ['count' => 0, 'oldest' => null], self::NOW_MS, []));
		foreach ($rChecks as $rLabel => $rCheck) {
			$this->assertTrue($rCheck['ok'], $rLabel . ': ' . $rCheck['value']);
		}
		$this->assertStringContainsString('lb_lease_fence off', $rChecks['Fence window']['value']);
		$this->assertStringContainsString(gmdate('Y-m-d H:i', $rNow + 600 + 12 * 3600), $rChecks['Fence window']['value']);
		$this->assertSame('p0 #41 · p1 #7', $rChecks['Event cursors']['value']);

		$rChecks = $this->byLabel(ClusterDiagnosis::node(['health' => 'offline', 'token_exp' => $rNow - 5, 'last_seen_at' => self::NOW_MS - 120000, 'clock_offset_ms' => -95000] + $rNode, ['count' => 3, 'oldest' => $rNow - 600], self::NOW_MS, []));
		$this->assertFalse($rChecks['Cluster node']['ok']);
		$this->assertFalse($rChecks['Agent heard']['ok']);
		$this->assertFalse($rChecks['Token']['ok']);
		$this->assertFalse($rChecks['Clock offset']['ok']);
		$this->assertFalse($rChecks['Command queue']['ok']);
		$this->assertStringContainsString('3 not acked, oldest 600s', $rChecks['Command queue']['value']);

		$rRevoked = $this->byLabel(ClusterDiagnosis::node(['state' => 'revoked', 'health' => 'revoked', 'token_exp' => $rNow - 5, 'last_seen_at' => null] + $rNode, ['count' => 0, 'oldest' => null], self::NOW_MS, []));
		$this->assertStringContainsString('Re-enrol', (string) $rRevoked['Cluster node']['problem']);
		$this->assertTrue($rRevoked['Token']['ok'], 'a revoked node\'s token expiring is no separate problem');
	}

	/** The relay proxy's port, from the agent's status (on the node) and the node's row (on MAIN). */
	public function testARelayPortThatCannotBeBoundIsAProblem(): void {
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(), self::NOW_MS, []));
		$this->assertArrayNotHasKey('Relay proxy', $rChecks, 'no relay report: no line');
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(['relay' => ['bound' => true]]), self::NOW_MS, []));
		$this->assertTrue($rChecks['Relay proxy']['ok']);
		$rChecks = $this->byLabel(ClusterDiagnosis::agent($this->agentDoc(['relay' => ['bound' => false, 'since_ms' => self::NOW_MS - 90000, 'failures' => 12, 'error' => "bind: address already in use\n"]]), self::NOW_MS, []));
		$this->assertFalse($rChecks['Relay proxy']['ok']);
		$this->assertSame('cannot bind 127.0.0.1:31290 for 1m (12 attempts): bind: address already in use', $rChecks['Relay proxy']['value']);

		$rNode = ['server_id' => 3, 'state' => 'active', 'health' => 'ok', 'mode' => 1, 'flows' => 3, 'token_exp' => intdiv(self::NOW_MS, 1000) + 600, 'last_seen_at' => self::NOW_MS - 2000];
		$this->assertArrayNotHasKey('Relay proxy', $this->byLabel(ClusterDiagnosis::node($rNode, ['count' => 0, 'oldest' => null], self::NOW_MS, [])), 'an agent without the proxy');
		$this->assertTrue($this->byLabel(ClusterDiagnosis::node(['relay' => true, 'relay_down_since' => null] + $rNode, ['count' => 0, 'oldest' => null], self::NOW_MS, []))['Relay proxy']['ok']);
		$rDown = $this->byLabel(ClusterDiagnosis::node(['relay' => true, 'relay_down_since' => 1_799_999_000, 'relay_error' => 'bind: address already in use'] + $rNode, ['count' => 0, 'oldest' => null], self::NOW_MS, []))['Relay proxy'];
		$this->assertFalse($rDown['ok']);
		$this->assertStringContainsString('since ' . gmdate('Y-m-d H:i', 1_799_999_000) . ' UTC: bind: address already in use', $rDown['value']);
	}

	public function testStatusReadsTheAgentsDocument(): void {
		$this->agent([[200, (string) json_encode($this->agentDoc())]]);
		$rStatus = AgentClient::status();
		$this->assertIsArray($rStatus);
		$this->assertSame(3, $rStatus['server_id']);
		$this->assertSame('GET /v1/status HTTP/1.0', trim((string) file_get_contents($this->rDir . '/requests.log')));
	}

	public function testStatusIsNullForAnAgentWithoutTheEndpoint(): void {
		// Today's agent answers an unknown path with 403 "not allowed".
		$this->agent([[403, 'not allowed']]);
		$this->assertNull(AgentClient::status());
	}

	public function testStatusIsNullWithNoAgent(): void {
		AgentClient::useSocket(sys_get_temp_dir() . '/no-such-agent-' . bin2hex(random_bytes(4)) . '.sock');
		$this->assertNull(AgentClient::status(0.5));
	}

	public function testTheCommandReportsTheClusterFromBothSides(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerDiagnoseCommand.php');
		$this->assertStringContainsString('ClusterDiagnosis::agent(AgentClient::status()', $rSrc, 'the node asks its agent');
		$this->assertStringContainsString('ClusterDiagnosis::node($rNode, $this->commandQueue($rServerID)', $rSrc, 'MAIN reads the node\'s row and queue');
		$this->assertStringContainsString('NodeLease::verdict(', $rSrc, 'the node shows the verdict its PHP acts on');
	}

	/**
	 * A stand-in agent answering each request in turn with the next [status, body].
	 *
	 * @param list<array{0: int, 1: string}> $rReplies
	 */
	private function agent(array $rReplies): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-diag-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		AgentClient::useSocket($this->rDir . '/agent.sock');
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
	file_put_contents($rLog, strtok($rRaw, "\r\n") . "\n", FILE_APPEND);
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
}
