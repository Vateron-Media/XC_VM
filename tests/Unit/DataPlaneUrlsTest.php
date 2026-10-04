<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Domain\Stream\StreamProcess;

/**
 * The URLs a node pulls with (ADR 0004, Phase 8): with its DATAPLANE flow on,
 * a relay and a file on another server go through the agent's loopback proxy
 * and no URL carries the stream secret (acceptance: `live_streaming_pass` in
 * no /proc/<pid>/cmdline and no `current_source`). A parent or owner that
 * cannot check a ticket (a legacy server) keeps the legacy URL, as does
 * every URL while the flow is off.
 */
final class DataPlaneUrlsTest extends TestCase {
	private const SECRET = 'the-stream-secret';
	private const KEY = 'kLoopbackKey0123456789abcdef';

	private string $rDir;

	/** @var array<int, array<string, mixed>> */
	private array $rServers;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rDir = sys_get_temp_dir() . '/xcvm-dp-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/relay.key', self::KEY . "\n");
		// The agent's listener, owned by whoever owns relay.key.
		$this->table('tcp', [['0100007F', DataPlane::PORT, (int) fileowner($this->rDir . '/relay.key')]]);
		$this->table('tcp6', []);
		DataPlane::useKeyFile($this->rDir . '/relay.key', [$this->rDir . '/tcp', $this->rDir . '/tcp6']);
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT | NodeFlows::DATAPLANE);
		// 20: MAIN; 21: an active node; 22: a legacy server no ticket can name.
		$rNode = static fn(int $rSid): array => ['sid' => $rSid, 'gen' => 1, 'state' => 'active', 'ed_pub' => str_repeat('x', 32), 'dataplane' => false];
		DataPlaneTrust::useSources(static fn(int $rSid): ?array => $rSid === 21 ? $rNode(21) : null, null, null, null, false);
		$rRow = static fn(int $rID, int $rMain = 0): array => [
			'is_main' => $rMain, 'private_url_ip' => null, 'public_url_ip' => 'http://10.0.0.' . $rID . ':8080/',
			'api_url' => 'http://10.0.0.' . $rID . ':8080/api?password=' . self::SECRET,
		];
		$this->rServers = [(int) SERVER_ID => $rRow((int) SERVER_ID), 20 => $rRow(20, 1), 21 => $rRow(21), 22 => $rRow(22)];
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		DataPlane::useKeyFile(null);
		DataPlaneTrust::useSources(null, null, null);
		foreach (glob($this->rDir . '/*') ?: [] as $rFile) {
			unlink($rFile);
		}
		rmdir($this->rDir);
	}

	/**
	 * A /proc/net/tcp-format table of listening sockets, one per [address, port, uid].
	 *
	 * @param list<array{0: string, 1: int, 2: int}> $rRows
	 */
	private function table(string $rName, array $rRows, string $rState = '0A'): void {
		$rOut = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n";
		foreach ($rRows as $i => [$rAddr, $rPort, $rUid]) {
			$rRem = str_repeat('0', strlen($rAddr)) . ':0000';
			$rOut .= sprintf("%4d: %s:%04X %s %s 00000000:00000000 00:00000000 00000000 %5d        0 %d 1 0000000000000000 100 0 0 10 0\n", $i, $rAddr, $rPort, $rRem, $rState, $rUid, 1000 + $i);
		}
		file_put_contents($this->rDir . '/' . $rName, $rOut);
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	public function testWithTheDataPlaneOnNoUrlCarriesTheSecret(): void {
		foreach ([20, 21] as $rParent) {
			$rURL = DataPlane::relayUrl($this->rServers, $rParent, 77, self::SECRET);
			$this->assertSame('http://127.0.0.1:31290/relay/' . self::KEY . '/77.ts', $rURL, 'parent ' . $rParent);
			$this->assertSame('http://127.0.0.1:31290/relay/' . self::KEY . '/77.ts?prebuffer=1', DataPlane::relayUrl($this->rServers, $rParent, 77, self::SECRET, true));
			$rFile = DataPlane::fileUrl($this->rServers, $rParent, '/media/movies/a film.mkv');
			$this->assertSame('http://127.0.0.1:31290/xfile/' . self::KEY . '/' . DataPlane::ref($rParent, '/media/movies/a film.mkv') . '.mkv', $rFile);
			$this->assertStringNotContainsString(self::SECRET, $rURL . $rFile);
			$this->assertStringNotContainsString('movies', $rFile, 'no path on the loopback either');
		}
		// The ref is stable: the URL an encoder holds outlives the ticket.
		$this->assertSame(DataPlane::ref(21, '/a.mkv'), DataPlane::ref(21, '/a.mkv'));
		$this->assertNotSame(DataPlane::ref(21, '/a.mkv'), DataPlane::ref(20, '/a.mkv'));
	}

	public function testTheStreamBuildersUseThem(): void {
		$rSubs = (new ReflectionMethod(StreamProcess::class, 'buildSubtitleImport'))->invoke(null, json_encode(['files' => ['/media/subs/a.srt'], 'names' => ['en'], 'charset' => ['UTF-8'], 'location' => 21]), $this->rServers);
		$this->assertStringContainsString('127.0.0.1:31290/xfile/', $rSubs[0]);
		$this->assertStringNotContainsString(self::SECRET, $rSubs[0]);
		[$rOwner, $rSource] = (new ReflectionMethod(StreamProcess::class, 'resolveChannelSource'))->invoke(null, 's:21:/media/does-not-exist-here.mkv', $this->rServers);
		$this->assertSame(21, $rOwner);
		$this->assertStringStartsWith('http://127.0.0.1:31290/xfile/', $rSource);

		// No builder writes the relay or getFile URL itself any more.
		$rRoot = MAIN_HOME;
		foreach (['Domain/Stream/StreamProcess.php', 'Cli/Commands/LoopbackCommand.php', 'Cli/Commands/MonitorCommand.php'] as $rFile) {
			$rCode = (string) file_get_contents($rRoot . $rFile);
			$this->assertStringNotContainsString("'admin/live?stream='", $rCode, $rFile);
			$this->assertStringNotContainsString("'&action=getFile", $rCode, $rFile);
		}
	}

	public function testALegacyServerOrTheFlowOffKeepsTheLegacyUrl(): void {
		$this->assertStringContainsString('password=' . self::SECRET, DataPlane::relayUrl($this->rServers, 22, 77, self::SECRET), 'a parent that checks no ticket');
		$this->assertStringContainsString('action=getFile', DataPlane::fileUrl($this->rServers, 22, '/a.mkv'));
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
		$this->assertSame('http://10.0.0.21:8080/admin/live?stream=77&password=' . self::SECRET . '&extension=ts', DataPlane::relayUrl($this->rServers, 21, 77, self::SECRET));
		$this->assertSame('http://10.0.0.21:8080/api?password=' . self::SECRET . '&action=getFile&filename=%2Fa.mkv', DataPlane::fileUrl($this->rServers, 21, '/a.mkv'));
	}

	/**
	 * The port is unprivileged: whoever holds it while the agent does not
	 * would read `k` from the URLs and feed the encoders its own bytes. A
	 * loopback URL goes out only while every listener on it is the uid that
	 * owns relay.key; otherwise the URL is UNAVAILABLE (a privileged port,
	 * refused at once), never the legacy URL with the secret.
	 */
	public function testTheLoopbackIsUsedOnlyWhileTheAgentHoldsThePort(): void {
		$rUid = (int) fileowner($this->rDir . '/relay.key');
		$rFixtures = [$this->rDir . '/tcp', $this->rDir . '/tcp6'];
		$rCases = [
			'the agent on 127.0.0.1' => [[['0100007F', DataPlane::PORT, $rUid]], [], self::KEY],
			'the agent on every address' => [[['00000000', DataPlane::PORT, $rUid]], [], self::KEY],
			'nobody listens (the agent is down)' => [[], [], null],
			'another user holds the port' => [[['0100007F', DataPlane::PORT, $rUid + 1]], [], null],
			'another user beside the agent' => [[['0100007F', DataPlane::PORT, $rUid], ['00000000', DataPlane::PORT, $rUid + 1]], [], null],
			'another user on v6 beside the agent' => [[['0100007F', DataPlane::PORT, $rUid]], [['00000000000000000000000000000000', DataPlane::PORT, $rUid + 1]], null],
			'the agent on v6 only' => [[], [['00000000000000000000000001000000', DataPlane::PORT, $rUid]], null],
			'the agent on another port only' => [[['0100007F', DataPlane::PORT + 1, $rUid]], [], null],
			'the agent on a public address only' => [[['0A00000A', DataPlane::PORT, $rUid]], [], null],
		];
		foreach ($rCases as $rWhat => [$rV4, $rV6, $rWant]) {
			$this->table('tcp', $rV4);
			$this->table('tcp6', $rV6);
			$this->assertSame($rWant !== null, DataPlane::listenerOwnedBy($rUid, $rFixtures), $rWhat);
			DataPlane::useKeyFile($this->rDir . '/relay.key', $rFixtures);
			$this->assertSame($rWant, DataPlane::loopback(), $rWhat);
			$rURL = DataPlane::relayUrl($this->rServers, 21, 77, self::SECRET);
			$this->assertSame($rWant === null ? DataPlane::UNAVAILABLE : 'http://127.0.0.1:31290/relay/' . self::KEY . '/77.ts', $rURL, $rWhat);
			$this->assertSame($rWant === null ? DataPlane::UNAVAILABLE : 'http://127.0.0.1:31290/xfile/' . self::KEY . '/' . DataPlane::ref(21, '/a.mkv') . '.mkv', DataPlane::fileUrl($this->rServers, 21, '/a.mkv'), $rWhat);
			$this->assertStringNotContainsString(self::SECRET, $rURL, $rWhat);
		}

		// A socket that is not listening (an established connection) is no holder.
		$this->table('tcp', [['0100007F', DataPlane::PORT, $rUid + 1]], '01');
		$this->table('tcp6', []);
		$this->assertFalse(DataPlane::listenerOwnedBy($rUid, $rFixtures), 'nobody listens');

		// No relay.key (the agent removed it when it let go of the port), or tables that do not read.
		$this->table('tcp', [['0100007F', DataPlane::PORT, $rUid]]);
		unlink($this->rDir . '/relay.key');
		DataPlane::useKeyFile($this->rDir . '/relay.key', $rFixtures);
		$this->assertNull(DataPlane::loopback(), 'no key');
		$this->assertSame(DataPlane::UNAVAILABLE, DataPlane::relayUrl($this->rServers, 21, 77, self::SECRET));
		$this->assertFalse(DataPlane::listenerOwnedBy($rUid, [$this->rDir . '/missing']), 'no table');
	}

	/** The verdict is kept a few seconds: the builders ask per URL. */
	public function testTheVerdictIsKeptBriefly(): void {
		$this->assertSame(self::KEY, DataPlane::loopback());
		$this->table('tcp', []);
		$this->assertSame(self::KEY, DataPlane::loopback(), 'within the check TTL');
		DataPlane::useKeyFile($this->rDir . '/relay.key', [$this->rDir . '/tcp', $this->rDir . '/tcp6']);
		$this->assertNull(DataPlane::loopback(), 'checked again');
	}

	/**
	 * api_legacy.conf's 0 (the node's legacy `/api` answers 404) only once
	 * nothing reads the node's files with `getFile`: its own flow on, and
	 * every server of the cluster, MAIN included, an active node with its
	 * DATAPLANE flow on. MAIN is no node, so today this is never.
	 */
	public function testTheLegacyApiRetiresOnlyWhenNothingReadsWithGetFile(): void {
		$rNodes = [];
		DataPlaneTrust::useSources(static function (int $rSid) use (&$rNodes): ?array {
			return $rNodes[$rSid] ?? null;
		}, null, null, null, false);
		$rNode = static fn(int $rSid, bool $rOn = true, string $rState = 'active'): array => ['sid' => $rSid, 'gen' => 1, 'state' => $rState, 'ed_pub' => str_repeat('x', 32), 'dataplane' => $rOn];
		try {
			DataPlane::useServers(fn(): array => $this->rServers);
			$rNodes = [SERVER_ID => $rNode((int) SERVER_ID), 21 => $rNode(21), 22 => $rNode(22)];
			$this->assertFalse(DataPlane::legacyApiRetired(), 'MAIN (20) is no node: it still reads with getFile');

			$rNodes[20] = $rNode(20);
			$this->assertTrue(DataPlane::legacyApiRetired(), 'every server reads through /xfile');

			$rNodes[22] = $rNode(22, false);
			$this->assertFalse(DataPlane::legacyApiRetired(), 'a server whose flow is off');
			$rNodes[22] = $rNode(22, true, 'revoked');
			$this->assertFalse(DataPlane::legacyApiRetired(), 'a server that is no active node');
			$rNodes[22] = $rNode(22);

			$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
			$this->assertFalse(DataPlane::legacyApiRetired(), 'this node\'s own flow off');
			$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT | NodeFlows::DATAPLANE);
			$this->assertTrue(DataPlane::legacyApiRetired());

			DataPlane::useServers(static fn(): array => []);
			$this->assertFalse(DataPlane::legacyApiRetired(), 'no servers list: nothing is known');
			DataPlane::useServers(static function (): array {
				throw new RuntimeException('no database');
			});
			$this->assertFalse(DataPlane::legacyApiRetired(), 'a list that does not read');
		} finally {
			DataPlane::useServers(null);
		}
	}
}
