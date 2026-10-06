<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The node's half of the line a node names (ADR 0004, design 1, B): the
 * proof of the mint MAIN put in the viewer's token (`prf`) is kept in the
 * registry record as `mint` (`<token uuid>.<iat>.<p>`), which the agent
 * mirrors to MAIN with the record, and goes into the admission header, from
 * which an agent that knows it copies it into conn_admit. A token without
 * `prf` adds nothing. The agent is a stand-in on a real unix socket.
 */
final class AuditCluster1NodeMintTest extends TestCase {
	private string $rDir;

	/** @var resource|null */
	private $rAgent = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-nodemint-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 2, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		AgentClient::useSocket($this->rDir . '/agent.sock');
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `user_id` int, `stream_id` int, `server_id` int, `user_ip` text, `hls_end` int DEFAULT 0)');
		DatabaseFactory::set($rDb);
	}

	protected function tearDown(): void {
		if ($this->rAgent !== null) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
		}
		NodeFlows::usePath(null);
		AgentClient::useSocket(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A stand-in agent answering $rCount requests with 200 {}, logging each. */
	private function agent(int $rCount): void {
		file_put_contents($this->rDir . '/agent.php', <<<'PHP'
<?php
[, $rSock, $rLog, $rCount] = $argv;
$rServer = stream_socket_server('unix://' . $rSock);
for ($i = 0; $i < (int) $rCount && ($rConn = @stream_socket_accept($rServer, 10)) !== false; $i++) {
	$rRaw = '';
	while (!str_contains($rRaw, "\r\n\r\n") && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rRaw .= $rChunk;
	}
	[$rHead, $rBody] = array_pad(explode("\r\n\r\n", $rRaw, 2), 2, '');
	$rLen = preg_match('/^Content-Length: (\d+)/mi', $rHead, $rM) ? (int) $rM[1] : 0;
	while (strlen($rBody) < $rLen && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rBody .= $rChunk;
	}
	$rAdm = preg_match('/^X-XCVM-Admission: (.*)$/mi', $rHead, $rH) ? json_decode(trim($rH[1]), true) : null;
	file_put_contents($rLog, json_encode(['body' => json_decode($rBody, true), 'adm' => $rAdm]) . "\n", FILE_APPEND);
	fwrite($rConn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
	fclose($rConn);
}
PHP);
		$rNull = ['file', '/dev/null', 'w'];
		$this->rAgent = proc_open([PHP_BINARY, $this->rDir . '/agent.php', $this->rDir . '/agent.sock', $this->rDir . '/requests.log', (string) $rCount], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists($this->rDir . '/agent.sock'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . '/agent.sock');
	}

	/** @return list<array{body: array<string, mixed>, adm: ?array<string, mixed>}> */
	private function requests(): array {
		return array_map(static fn(string $rLine): array => json_decode($rLine, true), file($this->rDir . '/requests.log', FILE_IGNORE_NEW_LINES) ?: []);
	}

	private function open(string $rUUID, array $rToken, string $rContainer = 'ts'): mixed {
		$rRecord = ['stream_id' => 100, 'server_id' => 5, 'proxy_id' => null, 'user_agent' => 'VLC', 'user_ip' => '203.0.113.9', 'container' => $rContainer, 'pid' => 1, 'date_start' => time(), 'hls_end' => 0, 'hls_last_read' => time(), 'on_demand' => 0, 'uuid' => $rUUID, 'user_id' => 42, 'identity' => 42];
		return ConnectionTracker::openRecord(['redis_handler' => 0], $rRecord, ['uuid' => $rUUID], $rToken);
	}

	private function token(string $rUUID, array $rExtra = []): array {
		return $rExtra + ['stream_id' => 100, 'uuid' => $rUUID, 'user_info' => ['id' => 42, 'max_connections' => 2, 'pair_id' => null]];
	}

	public function testTheRecordAndTheAdmissionHeaderCarryTheProofOfTheMint(): void {
		$this->agent(4);
		$rPrf = ['iat' => 1800000000, 'p' => str_repeat('ab', 16)];
		$this->assertTrue($this->open('t1', $this->token('t1', ['prf' => $rPrf])));
		// HLS: recorded under the playlist key, the proof names the token's uuid.
		$this->assertTrue($this->open('hlskey', $this->token('hlskey', ['prf' => $rPrf, 'adm_uuid' => 't2']), 'hls'));
		// Unlimited: no admission header, the record still carries it.
		$this->assertTrue($this->open('t3', $this->token('t3', ['prf' => $rPrf, 'user_info' => ['id' => 42, 'max_connections' => 0]])));
		// No prf (an older MAIN), or a malformed one: nothing added.
		$this->assertTrue($this->open('t4', $this->token('t4', ['prf' => ['iat' => '1', 'p' => 'zz']])));
		[$rTs, $rHls, $rUnlimited, $rNone] = $this->requests();
		$this->assertSame('t1.1800000000.' . str_repeat('ab', 16), $rTs['body']['mint'] ?? null);
		$this->assertSame($rTs['body']['mint'], $rTs['adm']['mint'] ?? null, 'and the header, for conn_admit');
		$this->assertSame('t2.1800000000.' . str_repeat('ab', 16), $rHls['body']['mint'] ?? null);
		$this->assertSame('t3.1800000000.' . str_repeat('ab', 16), $rUnlimited['body']['mint'] ?? null);
		$this->assertNull($rUnlimited['adm']);
		$this->assertArrayNotHasKey('mint', $rNone['body']);
		$this->assertArrayNotHasKey('mint', $rNone['adm']);
	}

	public function testARecordWrittenToMainsStoreWhenTheAgentDoesNotAnswerCarriesNoProof(): void {
		// No agent: MAIN's table, with the columns the caller gave, as before.
		$this->assertNotFalse($this->open('t5', $this->token('t5', ['prf' => ['iat' => 1800000000, 'p' => str_repeat('ab', 16)]])));
		$rDb = DatabaseFactory::get();
		$rDb->query('SELECT `uuid` FROM `lines_live`');
		$this->assertSame([['uuid' => 't5']], $rDb->get_rows());
	}

	public function testTheEndpointsPassTheirTokenToTheRecord(): void {
		// live.php names the token's uuid as adm_uuid before it records an HLS viewer under its playlist key.
		$rLive = (string) file_get_contents(MAIN_HOME . 'Public/stream/live.php');
		$this->assertLessThan(strpos($rLive, '$rTokenData["uuid"] = ConnectionTracker::hlsConnectionKey('), strpos($rLive, '$rTokenData["adm_uuid"] = $rTokenData["uuid"] ?? null;'));
		$this->assertStringContainsString('"token" => $rTokenData,', $rLive);
		foreach (['vod.php', 'timeshift.php'] as $rFile) {
			$this->assertStringContainsString('$rTokenData, intval($rServers[SERVER_ID][\'time_offset\']));', (string) file_get_contents(MAIN_HOME . 'Public/stream/' . $rFile));
		}
	}
}
