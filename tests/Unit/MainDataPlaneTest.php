<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterMainDataplaneCommand;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\MainAgentFiles;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\MainDataPlane;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Cluster\TicketService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * MAIN's data-plane client (ADR 0004, Phase 9's eighth increment): a key of
 * MAIN's own, listed in the signed node list while it is on, tickets MAIN
 * mints for itself (as the fetcher or child) into the file its agent reads,
 * and URLs that go through the agent instead of the legacy `getFile` and
 * password. The agent's side is XC_VM_Fanout's `mainrole_test.go`.
 */
final class MainDataPlaneTest extends TestCase {
	private const MAIN = 1;
	// Not an id any test defines SERVER_ID as (1 or 5): the node is never this process, whichever test ran first.
	private const NODE = 7;
	private const LEGACY = 6;
	private const UUID = '7f8fad5b-d9cb-469f-a165-70867728950e';
	private const NOW = 1_800_000_000;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rNodeBoxSk;

	/** @var list<array{0: string, 1: string}> keygen calls: [state path, uuid] */
	private array $rKeygens = [];

	private string $rSignPub;

	public static function setUpBeforeClass(): void {
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', sys_get_temp_dir() . '/xcvm-test-config/');
		}
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql');
		foreach (array_filter(array_map('trim', explode(';', (string) $rSql))) as $rStatement) {
			$this->rDb->exec($rStatement);
		}
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `is_main` int DEFAULT 0, `server_type` int DEFAULT 0, `server_name` varchar(64), `server_ip` varchar(64), `private_ip` varchar(64), `http_broadcast_port` int DEFAULT 80, `enabled` int DEFAULT 1)');
		$this->rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `is_main`, `server_name`, `server_ip`) VALUES (1, 1, 'main', '203.0.113.1'), (5, 0, 'node', '203.0.113.5'), (6, 0, 'legacy', '203.0.113.6')");
		foreach (['streams_servers' => '`stream_id` int, `server_id` int', 'streams' => '`id` int, `tv_archive_server_id` int, `vframes_server_id` int', 'recordings' => '`stream_id` int, `source_id` int'] as $rTable => $rCols) {
			$this->rDb->exec('CREATE TABLE `' . $rTable . '` (' . $rCols . ')');
		}
		$this->rNodeBoxSk = random_bytes(32);
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`) VALUES (?, '0f8fad5b-d9cb-469f-a165-70867728950e', 'active', 1, ?, 2, ?, ?, 1, 0, 0)",
			self::NODE, NodeRegistry::FLOW_DATAPLANE, random_bytes(32), sodium_crypto_scalarmult_base($this->rNodeBoxSk));
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(self::NOW * 1000);
		$this->rCrypto = new FakeClusterCrypto();
		MainDataPlane::useSeams(fn() => $this->rCrypto, self::NOW);
		TicketService::forget();
		$this->rSignPub = random_bytes(32);
		$this->clean();
	}

	protected function tearDown(): void {
		MainDataPlane::useSeams(null);
		MainAgentFiles::usePath(null);
		TicketService::forget();
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		$this->clean();
	}

	private function clean(): void {
		$rDir = AgentPaths::file(MainAgentFiles::REPLICA);
		foreach (['servers.json', 'tickets.json', '.tickets.lock', 'settings.json'] as $rFile) {
			@unlink($rDir . $rFile);
		}
		@unlink(AgentPaths::file(MainAgentFiles::DIGEST_N1));
		@unlink(AgentPaths::file(MainAgentFiles::IDENTITY));
		MainAgentFiles::usePath(null);
	}

	private function keygen(): \Closure {
		return function (string $rState, string $rUuid): array {
			$this->rKeygens[] = [$rState, $rUuid];
			return ['node_uuid' => $rUuid, 'sign_pub' => bin2hex($this->rSignPub), 'box_pub' => bin2hex(str_repeat("\x02", 32))];
		};
	}

	/** @return array<string, mixed> */
	private function store(): array {
		return (array) json_decode((string) @file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'tickets.json'), true);
	}

	/** @return array<string, mixed>|null */
	private function verified(string $rTag, string $rWire): ?array {
		return Ticket::verify($this->rCrypto->info()['panel_sign_pub'], $rTag, $rWire, self::NOW);
	}

	private function events(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['detail'], $this->rDb->get_rows());
	}

	public function testOffByDefaultNothingChanges(): void {
		$this->assertNull(MainDataPlane::identity());
		$this->assertNull(MainDataPlane::node());
		$this->assertFalse(MainDataPlane::ensureFile(self::NODE, '/movies/a.mkv'));
		$this->assertSame([self::NODE], array_column(ReplicaBuilder::serversData()['nodes'], 'sid'), 'no entry for MAIN');
		$this->assertFileDoesNotExist(AgentPaths::file(MainAgentFiles::IDENTITY));
	}

	/** On: a key, main.json for the agent, MAIN's entry in the node list, and the agent's files. */
	public function testOnListsMainWithItsOwnKey(): void {
		$this->assertNull(MainDataPlane::enable($this->keygen()));
		$this->assertSame([[AgentPaths::file(MainAgentFiles::STATE), $this->rKeygens[0][1]]], $this->rKeygens);
		$rID = MainDataPlane::identity();
		$this->assertSame([1, true, base64_encode($this->rSignPub)], [$rID['gen'], $rID['on'], $rID['sign_pub']]);

		$rFile = MainAgentFiles::identity();
		$this->assertSame(['v' => 1, 'server_id' => self::MAIN, 'node_uuid' => $rID['node_uuid'], 'gen' => 1, 'panel_sign_pub' => base64_encode($this->rCrypto->info()['panel_sign_pub']), 'dataplane' => true], $rFile);
		$this->assertTrue(MainAgentFiles::on());

		$rEntry = ReplicaBuilder::serversData()['nodes'][1];
		$this->assertSame(['sid' => self::MAIN, 'gen' => 1, 'state' => 'active', 'ed_pub' => base64_encode($this->rSignPub), 'dataplane' => true], $rEntry);
		$rServers = json_decode((string) file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'servers.json'), true);
		$this->assertSame($rEntry, $rServers['data']['nodes'][1], 'the agent reads the same list the nodes get');
		$this->assertSame(DataPlane::epoch(self::NOW), $this->store()['epoch']);
		$this->assertStringStartsWith('cluster.main_dataplane {"on":true,"gen":1,"new_key":true}', $this->events()[0]);
		if (DataPlaneTrust::main()) {
			$this->assertTrue(DataPlane::on(), 'MAIN pulls through its agent');
		}

		// The same key again keeps the generation; a re-key raises it.
		$this->assertNull(MainDataPlane::enable($this->keygen()));
		$this->assertSame(1, MainDataPlane::identity()['gen']);
		$this->assertSame($this->rKeygens[0][1], $this->rKeygens[1][1], 'the same uuid');
		$this->rSignPub = random_bytes(32);
		$this->assertNull(MainDataPlane::enable($this->keygen(), true));
		$this->assertSame(2, MainDataPlane::identity()['gen']);
		$this->assertNotSame($this->rKeygens[0][1], $this->rKeygens[2][1], 'a re-key is a new uuid');

		// Off: MAIN leaves the list; the key stays.
		$this->assertNull(MainDataPlane::disable());
		$this->assertSame([self::NODE], array_column(ReplicaBuilder::serversData()['nodes'], 'sid'));
		$this->assertFalse(MainAgentFiles::identity()['dataplane']);
		$this->assertNull(MainDataPlane::node());
		$this->assertSame(2, MainDataPlane::identity()['gen']);
		$this->assertSame('MAIN\'s data plane is not on', MainDataPlane::disable());
	}

	public function testAKeygenThatFailsChangesNothing(): void {
		$this->assertSame('xc_agent keygen failed', MainDataPlane::enable(static fn(): ?array => null));
		$this->assertSame('xc_agent keygen printed no keys', MainDataPlane::enable(static fn(string $rS, string $rU): array => ['node_uuid' => $rU, 'sign_pub' => 'zz', 'box_pub' => 'zz']));
		$this->assertNull(MainDataPlane::identity());
		$this->assertFileDoesNotExist(AgentPaths::file(MainAgentFiles::IDENTITY));
	}

	/** A file MAIN reads from a node: a ticket naming MAIN as the fetcher at its generation, the path sealed to the owner. */
	public function testMainMintsItsOwnFileAndRelayTickets(): void {
		MainDataPlane::enable($this->keygen());
		$this->assertTrue(MainDataPlane::ensureFile(self::NODE, '/movies/a.mkv'));
		$rRef = DataPlane::ref(self::NODE, '/movies/a.mkv');
		$rWire = $this->store()['streams'][MainDataPlane::ADHOC]['files'][$rRef];
		$rDoc = $this->verified('fil', $rWire);
		$this->assertSame([self::MAIN, 1, self::NODE, $rRef], [$rDoc['fetcher_sid'], $rDoc['fetcher_gen'], $rDoc['owner_sid'], $rDoc['ref']]);
		$this->assertSame('/movies/a.mkv', Seal::open($this->rNodeBoxSk, DataPlane::SEAL_PURPOSE, $rRef, (string) base64_decode(strtr(substr($rDoc['file'], 2), '-_', '+/'), true)), 'only the owner opens the path');

		// A fresh ticket is kept, not minted again.
		$rBefore = file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'tickets.json');
		$this->assertTrue(MainDataPlane::ensureFile(self::NODE, '/movies/a.mkv'));
		$this->assertSame($rBefore, file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'tickets.json'));

		$this->assertFalse(MainDataPlane::ensureFile(self::LEGACY, '/movies/b.mkv'), 'a legacy server cannot check a ticket');
		$this->assertFalse(MainDataPlane::ensureFile(self::MAIN, '/movies/c.mkv'), 'MAIN reads its own files itself');

		$this->assertTrue(MainDataPlane::ensureRelay(self::NODE, 77));
		$rDoc = $this->verified('rly', $this->store()['streams']['77']['relay']);
		$this->assertSame([self::MAIN, 1, self::NODE, 77], [$rDoc['child_sid'], $rDoc['child_gen'], $rDoc['parent_sid'], $rDoc['stream_id']]);
		$this->assertFalse(MainDataPlane::ensureRelay(self::LEGACY, 78));
	}

	/** At each new epoch the cron mints anew what MAIN read on demand within a day; a re-key's tickets name the new generation. */
	public function testTheCronRefreshesAtEachEpoch(): void {
		MainDataPlane::enable($this->keygen());
		MainDataPlane::ensureFile(self::NODE, '/movies/a.mkv');
		$rRef = DataPlane::ref(self::NODE, '/movies/a.mkv');
		$rFirst = $this->store()['streams'][MainDataPlane::ADHOC]['files'][$rRef];

		MainDataPlane::refresh();
		$this->assertSame($rFirst, $this->store()['streams'][MainDataPlane::ADHOC]['files'][$rRef], 'the same epoch: nothing minted');

		$rNext = self::NOW + DataPlane::EPOCH;
		MainDataPlane::useSeams(fn() => $this->rCrypto, $rNext);
		ClusterClock::fix($rNext * 1000);
		MainDataPlane::refresh();
		$rStore = $this->store();
		$this->assertSame(DataPlane::epoch($rNext), $rStore['epoch']);
		$rDoc = Ticket::verify($this->rCrypto->info()['panel_sign_pub'], 'fil', $rStore['streams'][MainDataPlane::ADHOC]['files'][$rRef], $rNext);
		$this->assertSame(DataPlane::fileTid(DataPlane::epoch($rNext), self::MAIN, $rRef), $rDoc['tid']);

		// A day without asking: the file is no longer kept.
		$rLater = self::NOW + MainDataPlane::FILE_KEEP + DataPlane::EPOCH;
		MainDataPlane::useSeams(fn() => $this->rCrypto, $rLater);
		ClusterClock::fix($rLater * 1000);
		MainDataPlane::refresh();
		$this->assertArrayNotHasKey(MainDataPlane::ADHOC, $this->store()['streams']);
	}

	/**
	 * MAIN's agent is given lb_digest_nonce_required in its replica's settings,
	 * as a node is; and the owners it reports taking a chunk digest without a
	 * nonce from reach the Cluster Nodes page while its report is fresh.
	 */
	public function testMainsAgentGetsTheDigestSwitchAndItsReportIsRead(): void {
		MainDataPlane::enable($this->keygen());
		$rBefore = SettingsManager::getAll();
		SettingsManager::set(['lb_digest_nonce_required' => '1'] + $rBefore);
		try {
			MainDataPlane::refresh();
		} finally {
			SettingsManager::set($rBefore);
		}
		$rSettings = json_decode((string) file_get_contents(AgentPaths::file(MainAgentFiles::REPLICA) . 'settings.json'), true);
		$this->assertSame(['lb_digest_nonce_required' => 1], $rSettings['data']);

		$rNowMs = self::NOW * 1000;
		$this->assertNull(MainDataPlane::digestN1($rNowMs), 'no report');
		file_put_contents(AgentPaths::file(MainAgentFiles::DIGEST_N1), json_encode(['owners' => [9, 5], 'at_ms' => $rNowMs - 60000]));
		$this->assertSame([5, 9], MainDataPlane::digestN1($rNowMs));
		$this->assertNull(MainDataPlane::digestN1($rNowMs + MainDataPlane::DIGEST_N1_STALE * 1000), 'a stopped agent\'s report');
		file_put_contents(AgentPaths::file(MainAgentFiles::DIGEST_N1), json_encode(['owners' => [9], 'at_ms' => (int) round(microtime(true) * 1000)]));
		$this->assertSame(['owners' => [7, 9], 'silent' => 0], ClusterOverview::digestN1([['state' => 'active', 'digest_n1' => '[7]']]));
	}

	/** Where MAIN reads another server's file: its agent while on, getFile with the server's IP otherwise. */
	public function testTheSourceProbeAndTheCertbotLogUseTheAgent(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerRepository.php');
		$this->assertStringContainsString('$rAPI = self::fileUrl($rServers, $rServerID, $rFilename);', $rSrc);
		$this->assertStringContainsString("\$rAPI = self::fileUrl(\$rServers, intval(\$rServerID), BIN_PATH . 'certbot/logs/xc_vm.log');", $rSrc);
		$this->assertStringNotContainsString("['api_url_ip'] . '&action=getFile", str_replace("(\$rServers[\$rServerID]['api_url_ip'] ?? '') . '&action=getFile", '', $rSrc), 'no other getFile URL');

		$rDp = (string) file_get_contents(MAIN_HOME . 'Core/Cluster/DataPlane.php');
		$this->assertStringContainsString('return NodeFlows::on(NodeFlows::DATAPLANE) || (DataPlaneTrust::main() && MainAgentFiles::on());', $rDp);
		$this->assertStringContainsString('MainDataPlane::ensureFile($rOwnerID, (string) $rPath)', $rDp);
		$this->assertStringContainsString('MainDataPlane::ensureRelay((int) $rParentID, (int) $rStreamID)', $rDp);
	}

	/** On MAIN, the URL builders: through MAIN's agent while on, with MAIN's ticket minted then; the legacy URLs otherwise. */
	public function testMainsUrlsGoThroughItsAgentWhileOn(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-mdp-' . bin2hex(random_bytes(4));
		mkdir($rDir);
		file_put_contents($rDir . '/relay.key', "kLoopbackKey0123456789abcdef\n");
		$rUid = (int) fileowner($rDir . '/relay.key');
		file_put_contents($rDir . '/tcp', "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
			. sprintf("   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 1000 1 0000000000000000 100 0 0 10 0\n", DataPlane::PORT, $rUid));
		file_put_contents($rDir . '/tcp6', '');
		DataPlane::useKeyFile($rDir . '/relay.key', [$rDir . '/tcp', $rDir . '/tcp6']);
		NodeFlows::usePath($rDir . '/no-flows.json');
		DataPlaneTrust::useSources(static fn(int $rSid): ?array => $rSid === self::NODE ? ['sid' => $rSid, 'gen' => 2, 'state' => 'active', 'ed_pub' => str_repeat('x', 32), 'dataplane' => true] : null, null, null, null, true);
		$rServers = [
			self::MAIN => ['is_main' => 1, 'api_url' => 'http://main/api?password=s', 'private_url_ip' => null, 'public_url_ip' => 'http://203.0.113.1/'],
			self::NODE => ['is_main' => 0, 'api_url' => 'http://node/api?password=s', 'private_url_ip' => null, 'public_url_ip' => 'http://203.0.113.5/'],
			self::LEGACY => ['is_main' => 0, 'api_url' => 'http://legacy/api?password=s', 'private_url_ip' => null, 'public_url_ip' => 'http://203.0.113.6/'],
		];
		try {
			$this->assertFalse(DataPlane::on(), 'off: MAIN has no flows');
			$this->assertSame('http://node/api?password=s&action=getFile&filename=%2Fmovies%2Fa.mkv', DataPlane::fileUrl($rServers, self::NODE, '/movies/a.mkv'));

			MainDataPlane::enable($this->keygen());
			$this->assertTrue(DataPlane::on());
			$rRef = DataPlane::ref(self::NODE, '/movies/a.mkv');
			$this->assertSame('http://127.0.0.1:31290/xfile/kLoopbackKey0123456789abcdef/' . $rRef . '.mkv', DataPlane::fileUrl($rServers, self::NODE, '/movies/a.mkv'));
			$this->assertArrayHasKey($rRef, $this->store()['streams'][MainDataPlane::ADHOC]['files'], 'minted as the URL was built');
			$this->assertSame('http://127.0.0.1:31290/relay/kLoopbackKey0123456789abcdef/77.ts', DataPlane::relayUrl($rServers, self::NODE, 77, 's'));
			$this->assertArrayHasKey('relay', $this->store()['streams']['77']);

			$this->assertStringContainsString('action=getFile', DataPlane::fileUrl($rServers, self::LEGACY, '/movies/b.mkv'), 'a legacy owner keeps getFile');
			$this->assertStringContainsString('password=s', DataPlane::relayUrl($rServers, self::LEGACY, 78, 's'));
			$this->assertStringContainsString('action=getFile', DataPlane::fileUrl($rServers, self::MAIN, '/movies/c.mkv'), 'MAIN\'s own file is not pulled');

			// No licence: nothing minted, the legacy URL.
			$this->rCrypto->rRefuseSign = 'LICENCE';
			$this->assertStringContainsString('action=getFile', DataPlane::fileUrl($rServers, self::NODE, '/movies/d.mkv'));
		} finally {
			DataPlane::useKeyFile(null);
			NodeFlows::usePath(null);
			DataPlaneTrust::useSources(null, null, null);
			array_map('unlink', glob($rDir . '/*') ?: []);
			rmdir($rDir);
		}
	}

	/** The command is MAIN's only; the files it meets the agent in are the agent's. */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheCommandAndTheSupervisor(): void {
		$rRoot = dirname(__DIR__, 2);
		$this->assertStringContainsString('Cli/Commands/ClusterMainDataplaneCommand.php', (string) file_get_contents($rRoot . '/Makefile'));
		$this->assertStringContainsString('Cli/Commands/ClusterMainDataplaneCommand.php', (string) file_get_contents($rRoot . '/tools/ci/verify-lb-archive.sh'));
		$this->assertSame('cluster:main-dataplane', (new ClusterMainDataplaneCommand())->getName());
		$rRun = (string) file_get_contents($rRoot . '/src/bin/xc_agent/run.sh');
		$this->assertStringContainsString('"$AGENT_DIR/xc_agent" run -role main -state "$MAIN_STATE"', $rRun);
		$this->assertStringContainsString('MAIN_ID="$SCRIPT/config/cluster/main.json"', $rRun);
		$this->assertStringContainsString("is_file('/home/xc_vm/config/cluster/main.json')", (string) file_get_contents($rRoot . '/src/Cli/CronJobs/RootSignalsCronJob.php'));
		$this->assertSame('cluster/main_agent.json', MainAgentFiles::STATE);
		$this->assertSame('cluster/main.json', MainAgentFiles::IDENTITY);
		$this->assertSame('cluster/replica/', MainAgentFiles::REPLICA);
		$this->assertSame(NodeFlows::DATAPLANE, NodeRegistry::FLOW_DATAPLANE);
	}
}
