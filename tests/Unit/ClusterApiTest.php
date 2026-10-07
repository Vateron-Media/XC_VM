<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Domain\Cluster\ClusterSemaphore;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\HeartbeatService;
use XcVm\Domain\Cluster\NodeAudit;
use XcVm\Domain\Cluster\NodeAuthCache;
use XcVm\Domain\Cluster\NodeCertbot;
use XcVm\Domain\Cluster\NodeHealth;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\QueryLogDb;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * MAIN's cluster API end to end, against the real migrations' schema: SSH
 * enrolment issues epoch 1, and a test agent (doing what the Go agent does:
 * open the sealed token, derive the session keys, sign, BOX, MAC) walks the
 * node through enrol_complete, hello, heartbeat, token_refresh and revocation.
 */
final class ClusterApiTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	/** Fresh per test, as per install: the real extension's revocation floor outlives the test database. */
	private string $rUuid;

	private ClusterCrypto $rCrypto;

	private ?string $rDir = null;

	private array $rSettings;

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0];

	private string $rNodeSk;

	private string $rNodeBoxSk = '';

	/** @var array{0: string, 1: string} current [epoch => eph sk] */
	private array $rEph = [];

	/** The cluster bus, started by the first test that asks for it (bus()). */
	private static ?BusServer $rBus = null;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit', '063_widen_cluster_reservation_identity'] as $rName) {
			$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql'));
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `audit` text DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `arch` varchar(8) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 0)');
		$this->rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		DatabaseFactory::set($this->rDb);
		$this->rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];
		SettingsManager::set($this->rSettings);
		$rHex = bin2hex(random_bytes(16));
		$this->rUuid = sprintf('%s-%s-4%s-a%s-%s', substr($rHex, 0, 8), substr($rHex, 8, 4), substr($rHex, 13, 3), substr($rHex, 17, 3), substr($rHex, 20, 12));
		if (getenv('XCVM_CLUSTER_API_REAL')) {
			$this->rCrypto = $this->realExtension();
			$this->rT0 = (int) floor(microtime(true) * 1000);
		} else {
			$this->rCrypto = new FakeClusterCrypto();
		}
		ClusterClock::fix($this->rT0);
		// No bus unless a test starts one (bus()): never one at the checkout's
		// default socket, and in a directory of its own, so that the marks
		// beside it are never a shared path.
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
	}

	/**
	 * Opt-in: the same flows against a real test-hooks xcvm_core, with a fake
	 * licence in a throwaway XCVM_CONFIG_DIR (as ClusterExtensionIntegrationTest):
	 *
	 *   XCVM_CLUSTER_API_REAL=1 XCVM_CONFIG_DIR=$(mktemp -d) php -d extension=…/xcvm_core.so \
	 *     tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterApiTest
	 */
	private function realExtension(): ClusterCrypto {
		$rDir = (string) getenv('XCVM_CONFIG_DIR');
		if (!class_exists('XC_VM', false) || !method_exists('XC_VM', 'cluster_session')) {
			$this->markTestSkipped('xcvm_core with the cluster API is not loaded');
		}
		if ($rDir === '' || !str_starts_with(realpath($rDir) ?: '', realpath(sys_get_temp_dir()) ?: '/tmp') || file_exists($rDir . '/config.enc')) {
			$this->markTestSkipped('XCVM_CONFIG_DIR must be a throwaway directory under the temp dir');
		}
		$this->rDir = $rDir;
		$rPair = sodium_crypto_sign_keypair();
		putenv('XCVM_TEST_VERDICT_PK_HEX=' . bin2hex(sodium_crypto_sign_publickey($rPair)));
		putenv('XCVM_TEST_CLUSTER_LIC_TTL=0');
		$rPayload = json_encode(['v' => 1, 'jti' => bin2hex(random_bytes(16)), 'hwid' => \XC_VM::install_id(), 'exp' => null, 'kid' => 1, 'iat' => time()], JSON_UNESCAPED_SLASHES);
		$rB64 = static fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
		file_put_contents($rDir . '/activation_key', 'XCVM1.' . $rB64($rPayload) . '.' . $rB64(sodium_crypto_sign_detached($rPayload, sodium_crypto_sign_secretkey($rPair))));
		if (!\XC_VM::license_valid()) {
			$this->markTestSkipped('not a test-hooks build (the fake licence was not accepted)');
		}
		$rCrypto = ClusterCryptoFactory::create();
		$rCrypto->init();
		return $rCrypto;
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ClusterBus::useSocket(null);
		EventDispatcher::resetInstance();
		DatabaseFactory::reset();
		SettingsManager::set([]);
		OpensslExtra::usePrevFile(null);
		if ($this->rDir !== null) {
			putenv('XCVM_TEST_VERDICT_PK_HEX');
			putenv('XCVM_TEST_CLUSTER_LIC_TTL');
			@unlink($this->rDir . '/activation_key');
		}
	}

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
	}


	// ── The test agent ───────────────────────────────────────────────────

	private function enrol(): array {
		$rPair = sodium_crypto_sign_keypair();
		$this->rNodeSk = sodium_crypto_sign_secretkey($rPair);
		$this->rNodeBoxSk = random_bytes(32);
		$rEphSk = random_bytes(32);
		$rFirst = EnrolmentService::issueFirst(
			$this->rCrypto,
			self::SID,
			$this->rUuid,
			sodium_crypto_sign_publickey($rPair),
			sodium_crypto_scalarmult_base($this->rNodeBoxSk),
			sodium_crypto_scalarmult_base($rEphSk),
			$this->rSettings,
			$this->rMain
		);
		$this->rEph = [1 => $rEphSk];
		return $rFirst;
	}

	/** Open a sealed token as the agent does; @return array{doc: array, keys: array} */
	private function openToken(string $rSealed, string $rEphSk): array {
		$rBody = Seal::open($rEphSk, 'token', $this->rUuid, $rSealed);
		$this->assertNotNull($rBody, 'the token opens with the per-epoch key');
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rDoc = substr($rBody, 4, $rLen);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'tok', $rDoc, substr($rBody, 4 + $rLen)));
		$rJson = json_decode($rDoc, true);
		return ['doc' => $rJson, 'keys' => ClusterReference::sessionKeys((string) hex2bin($rJson['token']))];
	}

	/**
	 * Build a request as the agent does.
	 *
	 * @param array<string, callable> $rTamper
	 * @return array{req: array, ctx: string}
	 */
	private function request(string $rOp, array $rPayload, int $rEpoch, array $rKeys, array $rTamper = []): array {
		$rNonce = $rTamper['nonce'] ?? random_bytes(16);
		$rTs = $rTamper['ts'] ?? ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => $rEpoch, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rBody = Box::box($rKeys['enc_up'], $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => (string) $rEpoch, 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rKeys['mac_up'], $rCtx, $rBody)),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		if (isset($rTamper['body'])) {
			$rBody = $rTamper['body']($rBody);
		}
		if (isset($rTamper['headers'])) {
			$rHeaders = $rTamper['headers']($rHeaders);
		}
		return ['req' => ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'], 'ctx' => $rCtx];
	}

	private function call(string $rOp, array $rPayload, int $rEpoch, array $rKeys, array $rTamper = []): array {
		$r = $this->request($rOp, $rPayload, $rEpoch, $rKeys, $rTamper);
		return [ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), $r['ctx'], $r['req']];
	}

	/** Verify and open a boxed reply as the agent does. */
	private function reply(array $rRes, string $rCtx, array $rKeys): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, $rRes['status'], $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		$this->assertTrue(Canonical::verifyMac($rKeys['mac_down'], $rResCtx, $rRes['body'], (string) hex2bin($rH['X-XCVM-Sig'])), 'reply MAC');
		$rPlain = Box::open($rKeys['enc_down'], $rResCtx, $rRes['body']);
		$this->assertNotNull($rPlain, 'reply opens');
		return json_decode($rPlain, true);
	}

	/** Verify a denial as the agent does: panel-signed, and about this request. */
	private function denial(array $rRes, int $rStatus, string $rReason, ?array $rReq = null): array {
		$this->assertSame($rStatus, $rRes['status'], $rRes['body']);
		$rSig = Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) $rSig), 'denial signature');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($rReason, $rDoc['reason']);
		if ($rReq !== null) {
			$this->assertSame($this->rUuid, $rDoc['node']);
			$this->assertSame($rReq['headers']['X-XCVM-Nonce'], $rDoc['req_nonce'], 'bound to the request nonce');
		}
		return $rDoc;
	}

	private function active(): array {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'boot_id' => 'boot-1', 'agent_version' => '0.1.0'], 1, $rTok['keys']);
		$this->reply($rRes, $rCtx, $rTok['keys']);
		return $rTok['keys'];
	}

	/** GET challenge?cn= as the agent does: the signed document's challenge. */
	private function challenge(): string {
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => []], $this->rSettings, $this->rMain);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		return (string) base64_decode(json_decode($rRes['body'], true)['challenge']);
	}

	/**
	 * POST token_rekey as the agent does: epoch 0, no MAC, the body SEALed to
	 * the panel box key under the request context, node-signed.
	 *
	 * @param array<string, mixed> $rExtra Payload fields to add or override.
	 * @param array<string, callable> $rTamper
	 * @return array{0: array, 1: array} response, request
	 */
	private function rekey(string $rChallenge, string $rEphSk, array $rExtra = [], array $rTamper = []): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . 'token_rekey';
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 0, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rPayload = $rExtra + [
			'challenge' => base64_encode($rChallenge), 'eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEphSk)),
			'instance_id' => 'inst-a', 'boot_id' => 'boot-9', 'agent_version' => '0.1.2',
		];
		$rBody = Seal::seal($this->rCrypto->info()['panel_box_pub'], 'rekey', $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => '0', 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		if (isset($rTamper['headers'])) {
			$rHeaders = $rTamper['headers']($rHeaders);
		}
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->rSettings, $this->rMain), $rReq];
	}

	/** Verify a re-key reply as the agent does: panel-signed `pre`, about this request; @return array{doc: array, keys: array} the new epoch. */
	private function rekeyed(array $rRes, array $rReq, string $rEphSk): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rSig = Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'pre', $rRes['body'], (string) $rSig), 're-key reply signature');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame('xcvm-rekey', $rDoc['typ']);
		$this->assertSame($this->rUuid, $rDoc['node']);
		$this->assertSame($rReq['headers']['X-XCVM-Nonce'], $rDoc['req_nonce']);
		$rTok = $this->openToken((string) base64_decode($rDoc['token_sealed']), $rEphSk);
		$this->assertSame($rDoc['epoch'], $rTok['doc']['epoch']);
		return $rTok;
	}

	/** An active node whose tokens are all gone (expiry without moving the clock, so it holds for the real extension too). */
	private function expired(): array {
		$rKeys = $this->active();
		$this->rDb->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = 5');
		// A write that bypasses MAIN's writers: the bus must not hold the epoch either.
		NodeAuthCache::forget(self::SID);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq);
		return $rKeys;
	}

	/** Another enrolled, active node taking commands, whose agent says $rFeatures at hello. */
	private function peer(int $rServerID, ?string $rFeatures): void {
		$this->rDb->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`, `features`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$rServerID,
			sprintf('00000000-0000-4000-a000-%012d', $rServerID),
			'active',
			1,
			NodeRegistry::FLOW_COMMANDS,
			1,
			random_bytes(32),
			sodium_crypto_scalarmult_base(random_bytes(32)),
			1,
			1800000000,
			1800000000,
			$rFeatures
		);
	}

	/** @return list<int> the nodes a config.changed is queued for */
	private function announced(): array {
		$this->rDb->query("SELECT `server_id` FROM `cluster_commands` WHERE `type` = 'config.changed' ORDER BY `server_id`");
		return array_map('intval', array_column($this->rDb->get_rows() ?: [], 'server_id'));
	}

	// ── Tests ────────────────────────────────────────────────────────────

	public function testEnrolmentIssuesEpochOneAndClusterJson(): void {
		$rFirst = $this->enrol();
		$this->assertSame(1, $rFirst['epoch']);
		$this->assertSame(1, $rFirst['gen']);
		$this->assertSame($this->rUuid, $rFirst['cluster']['node_uuid']);
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'], $rFirst['cluster']['policy']['main_urls']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('enrolling', $rNode['state']);
		$this->assertSame(1, (int) $rNode['mode'], 'hybrid while lb_new_node_mode is legacy');
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		$this->assertSame(1, $rTok['doc']['epoch']);
		$this->assertSame(self::SID, $rTok['doc']['server_id']);
	}

	public function testEnrolCompleteActivates(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'boot_id' => 'boot-1', 'agent_version' => '0.1.0'], 1, $rTok['keys']);
		$rOut = $this->reply($rRes, $rCtx, $rTok['keys']);
		$this->assertSame('active', $rOut['state']);
		$this->assertSame($this->rT0, $rOut['main_time_ms']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('active', $rNode['state']);
		$this->assertSame('inst-a', $rNode['instance_id']);
		$this->assertNull($rNode['enrol_deadline']);
		$this->assertSame(1, (int) $rNode['epoch']);
	}

	public function testEnrolCompleteAnnouncesTheNodeToThePeersThatTakeIt(): void {
		$this->peer(6, ReplicaBuilder::FEATURE_CONFIG_CHANGED);
		$this->peer(7, 'hls_reaper'); // today's agent: its next poll fetches the change
		$rFirst = $this->enrol();
		// The node would take the command too: it is never told about itself.
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS, 'features' => ReplicaBuilder::FEATURE_CONFIG_CHANGED]);
		$this->assertSame([], $this->announced(), 'not before it is active');
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys']);
		$this->assertSame('active', $this->reply($rRes, $rCtx, $rTok['keys'])['state']);
		$this->assertSame([6], $this->announced());
	}

	public function testEnrolCompleteAfterDeadlineIsRefused(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		ClusterClock::fix($this->rT0 + 1801000);
		[$rRes, , $rReq] = $this->call('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys']);
		$this->denial($rRes, 409, 'ENROL_EXPIRED', $rReq);
		$this->assertSame('enrolling', NodeRegistry::byServer(self::SID)['state']);
	}

	public function testHeartbeatBeforeEnrolCompleteIsNotActive(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rTok['keys']);
		$this->assertSame('enrolling', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
	}

	public function testHelloAndHeartbeat(): void {
		$rKeys = $this->active();
		ClusterClock::fix($this->rT0 + 5000);
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a', 'boot_id' => 'boot-2', 'agent_version' => '0.1.1', 'features' => ['hls_reaper', 'Bad Name!', 'hls_reaper', 7]], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame('active', $rOut['state']);
		$this->assertSame(['min' => 1, 'max' => 1], $rOut['proto']);
		$this->assertSame('boot-2', NodeRegistry::byServer(self::SID)['boot_id']);
		$this->assertSame('hls_reaper', NodeRegistry::byServer(self::SID)['features'], 'only well-formed names, once');
		$this->assertNull(ClusterApi::features(null), 'an older agent says nothing: MAIN keeps doing it all');
		$this->assertSame('a,b', ClusterApi::features(['b', 'a']));

		[$rRes, $rCtx] = $this->call('heartbeat', ['telemetry' => ['cpu' => 3]], 1, $rKeys, ['ts' => $this->rT0 + 5250]);
		$rBeat = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(0, $rBeat['pending']);
		$this->assertSame(1, $rBeat['policy_ver'], 'the agent compares it with its own to refetch the policy');
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame($this->rT0 + 5000, (int) $rNode['last_seen_at']);
		$this->assertSame(250, (int) $rNode['clock_offset_ms']);
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['status'], 'first authenticated heartbeat marks the server up');

		// An agent that stamps its requests with MAIN's time says its own
		// clock (ten minutes ahead) in `local_ms`: that is the offset.
		[$rRes, $rCtx] = $this->call('heartbeat', ['local_ms' => $this->rT0 + 5000 + 600000], 1, $rKeys, ['ts' => $this->rT0 + 5000]);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(600000, (int) NodeRegistry::byServer(self::SID)['clock_offset_ms']);
	}

	/**
	 * The hello and the heartbeat say which policy the agent dials
	 * (`policy_ver`), and nginx which MAIN port took them (`$server_port`).
	 * Both are recorded when they change, and only then, so a heartbeat that
	 * changes neither writes nothing more.
	 */
	public function testHelloAndHeartbeatRecordThePolicyAndPortTheNodeUses(): void {
		$rKeys = $this->active();
		$rSend = function (string $rOp, array $rPayload, int $rPort) use ($rKeys): void {
			ClusterClock::fix(ClusterClock::nowMs() + 2000);
			$r = $this->request($rOp, $rPayload, 1, $rKeys);
			$this->reply(ClusterApi::handle($this->rCrypto, ['port' => $rPort] + $r['req'], $this->rSettings, $this->rMain), $r['ctx'], $rKeys);
		};
		$rNode = static fn(): array => NodeRegistry::byServer(self::SID);

		// Before migration 046 there is no main_port column: the version alone.
		$rSend('hello', ['instance_id' => 'inst-a', 'policy_ver' => 1], 25461);
		$this->assertSame(1, (int) $rNode()['policy_ver']);
		$this->assertArrayNotHasKey('main_port', $rNode());

		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `main_port` int DEFAULT NULL');
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$rRecorded = static fn(): array => array_values(array_filter($rLog->writes(), static fn(string $rQuery): bool => str_contains($rQuery, '`main_port`') || str_contains($rQuery, '`policy_ver`')));
		$rSend('heartbeat', ['policy_ver' => 1], 25461);
		$this->assertSame([1, 25461], [(int) $rNode()['policy_ver'], (int) $rNode()['main_port']]);
		$this->assertCount(1, $rRecorded());
		$rSend('heartbeat', ['policy_ver' => 1], 25461);
		$this->assertCount(1, $rRecorded(), 'nothing changed: nothing written');

		$rSend('heartbeat', ['policy_ver' => 2, 'telemetry' => ['cpu' => 3]], 8080);
		$this->assertSame([2, 8080], [(int) $rNode()['policy_ver'], (int) $rNode()['main_port']]);
		$rSend('hello', ['instance_id' => 'inst-a', 'policy_ver' => 3], 8443);
		$this->assertSame([3, 8443], [(int) $rNode()['policy_ver'], (int) $rNode()['main_port']]);
		$this->assertSame('active', $rNode()['state']);

		// An agent that says nothing (an older one, after a downgrade) is
		// unknown again; a request nginx gave no port changes no port.
		$rSend('heartbeat', [], 0);
		$this->assertSame([0, 8443], [(int) $rNode()['policy_ver'], (int) $rNode()['main_port']]);
		$this->assertCount(4, $rRecorded());
	}

	public function testAHeartbeatKeepsTheNodesAuditWhenItChanges(): void {
		$rKeys = $this->active();
		$rAudit = ['settings_misses' => ['rare_key' => 1, 'hot_key' => 40, 'Bad Key' => 3, 'neg_key' => -1, 'float_key' => 1.5, '*' => 2]];
		[$rRes, $rCtx] = $this->call('heartbeat', ['audit' => $rAudit], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame('{"settings_misses":{"hot_key":40,"rare_key":1,"*":2}}', NodeRegistry::byServer(self::SID)['audit'], 'only names and counts, most missed first');
		$this->assertSame([self::SID => ['hot_key' => 40, 'rare_key' => 1, '*' => 2]], NodeAudit::settingsMisses());
		$this->assertSame(['hot_key' => 40, 'rare_key' => 1, '*' => 2], ClusterAdmin::nodes([], 30)[0]['settings_misses'], 'on the Cluster Nodes page');

		// The same report again, today's agent (no audit) and a malformed one write nothing.
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		foreach ([['audit' => $rAudit], [], ['audit' => 'misses'], ['audit' => ['settings_misses' => 'x']], ['audit' => ['settings_misses' => ['k' => 1], 'pad' => str_repeat('x', NodeAudit::MAX_BYTES)]]] as $rPayload) {
			[$rRes, $rCtx] = $this->call('heartbeat', $rPayload, 1, $rKeys);
			$this->reply($rRes, $rCtx, $rKeys);
		}
		$this->assertSame([], array_values(array_filter($rLog->writes(), static fn(string $rSql): bool => str_contains($rSql, '`audit`'))), 'the audit is not written again');
		$this->assertSame(['hot_key' => 40, 'rare_key' => 1, '*' => 2], NodeAudit::settingsMisses()[self::SID]);

		// Nothing missed any more: an empty report clears the list.
		[$rRes, $rCtx] = $this->call('heartbeat', ['audit' => ['settings_misses' => new \stdClass()]], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame('{"settings_misses":{}}', NodeRegistry::byServer(self::SID)['audit']);
		$this->assertSame([], ClusterAdmin::nodes([], 30)[0]['settings_misses']);
		$this->assertNull(ClusterAdmin::nodes([], 30)[0]['connects'], 'no connect counters reported');
	}

	public function testAHeartbeatKeepsTheNodesConnectCounters(): void {
		$rKeys = $this->active();
		$rSites = ['sql Public/stream/live.php:61' => 10, 'sql Core/Bootstrap/Stage/DatabaseStage.php:27' => 2, 'redis Streaming/X.php:1' => 0, 'mysql Streaming/X.php:1' => 3, "sql \x01.php:1" => 1, 'redis ' . str_repeat('a', ConnectAudit::MAX_SITE_LEN) => 1, '*' => 1];
		$rAudit = ['settings_misses' => [], 'sql_connects' => 12, 'redis_connects' => 0, 'sites' => $rSites, 'connects_since' => 1800000000];
		[$rRes, $rCtx] = $this->call('heartbeat', ['audit' => $rAudit], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame('{"settings_misses":{},"sql_connects":12,"redis_connects":0,"sites":{"sql Public/stream/live.php:61":10,"sql Core/Bootstrap/Stage/DatabaseStage.php:27":2,"*":1},"connects_since":1800000000}', NodeRegistry::byServer(self::SID)['audit'], 'only sites a node writes, most first');
		$rNode = ClusterAdmin::nodes([], 30)[0];
		$this->assertSame(['sql_connects' => 12, 'redis_connects' => 0, 'sites' => ['sql Public/stream/live.php:61' => 10, 'sql Core/Bootstrap/Stage/DatabaseStage.php:27' => 2, '*' => 1], 'connects_since' => 1800000000], $rNode['connects'], 'on the Cluster Nodes page');
		$this->assertSame([], $rNode['settings_misses']);

		// Counters that are not counts: the settings misses are kept, the connect report is not.
		foreach ([['sql_connects' => -1], ['redis_connects' => '0'], ['sites' => 'x'], ['connects_since' => 'yesterday'], ['connects_since' => 0]] as $rBad) {
			$rDoc = NodeAudit::normalise(array_merge($rAudit, $rBad));
			$this->assertSame(isset($rBad['connects_since']) ? ['settings_misses', 'sql_connects', 'redis_connects', 'sites'] : ['settings_misses'], array_keys((array) $rDoc), json_encode($rBad));
		}
		// Past MAX_SITES sites, the least fold into "*".
		$rMany = [];
		for ($i = 0; $i < ConnectAudit::MAX_SITES + 3; $i++) {
			$rMany['sql S' . $i . '.php:1'] = $i + 1;
		}
		$rDoc = NodeAudit::normalise(['settings_misses' => [], 'sql_connects' => 999, 'redis_connects' => 0, 'sites' => $rMany]);
		$this->assertCount(ConnectAudit::MAX_SITES + 1, $rDoc['sites']);
		$this->assertSame(1 + 2 + 3, $rDoc['sites']['*']);
		$this->assertArrayNotHasKey('connects_since', $rDoc, 'an agent\'s report without it: none');
	}

	public function testAnAuditOverTheCapKeepsTheMostMissed(): void {
		$rMisses = [];
		for ($i = 0; $i < SettingsAudit::MAX_KEYS + 3; $i++) {
			$rMisses[sprintf('key_%03d', $i)] = $i + 1;
		}
		$rDoc = NodeAudit::normalise(['settings_misses' => $rMisses + ['*' => 5], 'sql_connects' => 9]);
		$this->assertCount(SettingsAudit::MAX_KEYS + 1, $rDoc['settings_misses']);
		$this->assertSame(sprintf('key_%03d', SettingsAudit::MAX_KEYS + 2), array_key_first($rDoc['settings_misses']));
		$this->assertSame(5 + 1 + 2 + 3, $rDoc['settings_misses']['*'], 'the three least missed, with the node\'s own rest');
		$this->assertSame(['settings_misses'], array_keys($rDoc), 'a connect report without its other counters, and members MAIN does not know, are not kept');
		$this->assertNull(NodeAudit::normalise(null));
		$this->assertNull(NodeAudit::normalise(['sql_connects' => 1]));
		// The bound is on the shortest encoding, as the agent measures audit.json: the "path:line" sites fit although escaped slashes would not.
		$rAudit = ['settings_misses' => ['k' => 1], 'sites' => []];
		for ($i = 0; strlen((string) json_encode($rAudit, JSON_UNESCAPED_SLASHES)) < NodeAudit::MAX_BYTES - 64; $i++) {
			$rAudit['sites']['sql src/Domain/Stream/StreamService.php:' . $i] = $i + 1;
		}
		$this->assertGreaterThan(NodeAudit::MAX_BYTES, strlen((string) json_encode($rAudit)));
		$this->assertSame(['settings_misses' => ['k' => 1]], NodeAudit::normalise($rAudit));
		$this->assertNull(NodeAudit::normalise($rAudit + ['pad' => str_repeat('x', 100)]), 'over the bound: nothing kept');
		// Before migration 045 the row has no `audit`: nothing to write, nothing to show.
		NodeAudit::record(['server_id' => self::SID], ['settings_misses' => ['k' => 1]]);
		$this->rDb->exec('CREATE TABLE `bare` (`x` int)');
		$this->assertSame([], NodeAudit::settingsMisses());
	}

	public function testHelloFromAnotherInstanceQuarantines(): void {
		$rKeys = $this->active();
		$this->peer(6, ReplicaBuilder::FEATURE_CONFIG_CHANGED);
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a'], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame([], $this->announced(), 'the same instance: nothing changed');
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-CLONE'], 1, $rKeys);
		$this->assertSame('quarantined', $this->reply($rRes, $rCtx, $rKeys)['state']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('quarantined', $rNode['state']);
		$this->assertSame('inst-a', $rNode['instance_id'], 'the enrolled instance is kept');
		$this->assertSame([6], $this->announced(), 'its peers stop trusting it at once');
	}

	public function testReplayIsRefused(): void {
		$rKeys = $this->active();
		$r = $this->request('heartbeat', [], 1, $rKeys);
		$this->assertSame(200, ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain)['status']);
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 401, 'REPLAY', $r['req']);
	}

	public function testBadMacBurnsNoNonce(): void {
		$rKeys = $this->active();
		$rNonce = random_bytes(16);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys, ['nonce' => $rNonce, 'body' => static fn($b) => $b . 'x']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		// The genuine request with that nonce still goes through.
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys, ['nonce' => $rNonce]);
		$this->assertSame(200, $rRes['status']);
	}

	public function testRefreshNeedsTheNodeSignature(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		[$rRes, , $rReq] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys, [
			'headers' => static function ($h) {
				$h['X-XCVM-Node-Sig'] = str_repeat('00', 64);
				return $h;
			},
		]);
		$this->denial($rRes, 401, 'BAD_NODE_SIG', $rReq);
	}

	public function testClockSkewAndProto(): void {
		$rKeys = $this->active();
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys, ['ts' => $this->rT0 - 91000]);
		$this->denial($rRes, 401, 'CLOCK_SKEW', $rReq);
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys, ['headers' => static function ($h) {
			$h['X-XCVM-Proto'] = '9';
			return $h;
		}
		]);
		$rDoc = $this->denial($rRes, 426, 'PROTO');
		$this->assertSame([1, 1], [$rDoc['min'], $rDoc['max']]);
	}

	public function testTokenRefreshIsIdempotentAndRotates(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		$rPayload = ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))];
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rFirst = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(2, $rFirst['epoch']);

		// The reply was lost: the retry with the same key gets the same token.
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rAgain = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame($rFirst['token_sealed'], $rAgain['token_sealed']);

		// A retry with a new key replaces the unused epoch 2 rather than adding 3.
		$rEph = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys);
		$rNew = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(2, $rNew['epoch']);
		$this->assertNotSame($rFirst['token_sealed'], $rNew['token_sealed']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(2, (int) $this->rDb->get_row()['n'], 'at most two epochs');

		// The node switches to epoch 2; the next refresh mints 3.
		$rTok2 = $this->openToken((string) base64_decode($rNew['token_sealed']), $rEph);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok2['keys']);
		$this->reply($rRes, $rCtx, $rTok2['keys']);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['epoch']);
		$rEph3 = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph3))], 2, $rTok2['keys']);
		$this->assertSame(3, $this->reply($rRes, $rCtx, $rTok2['keys'])['epoch']);
	}

	/**
	 * The lease as the node reads it: the panel signature over the exact bytes
	 * MAIN sent (ed25519 is deterministic, so re-signing proves them), and the
	 * document those bytes hold.
	 *
	 * @return array<string, mixed>
	 */
	private function leaseDoc(string $rPayload, string $rSig, int $rExp): array {
		$this->assertSame(ClusterReference::panelSign($this->rCrypto->rSeed, 'lea', $rPayload), $rSig, 'signed under tag lea, over these bytes');
		$rDoc = json_decode($rPayload, true);
		$this->assertIsArray($rDoc);
		$this->assertSame('xcvm-lease', $rDoc['typ']);
		$this->assertSame($this->rUuid, $rDoc['node_uuid']);
		$this->assertSame(self::SID, $rDoc['server_id']);
		$this->assertSame($rExp, $rDoc['exp'], 'the exp announced beside the lease is the one inside it');
		return $rDoc;
	}

	/**
	 * Phase 9: a token is handed over with the lease that says how long its node
	 * may keep serving viewers once it can no longer reach MAIN. The first token
	 * (install data over SSH) and both refresh replies — a fresh epoch and a
	 * token sent a second time — carry one.
	 */
	public function testTheFirstTokenAndEveryRefreshCarryTheNodesLease(): void {
		SettingsManager::set(['lb_partition_tolerance_h' => 2] + $this->rSettings);
		$rFirst = $this->enrol();
		$rNow = ClusterClock::now();
		$this->assertNotNull($rFirst['lease'], 'the token the installer writes leaves with one');
		$rDoc = $this->leaseDoc((string) $rFirst['lease']['payload'], (string) $rFirst['lease']['sig'], (int) $rFirst['lease']['exp']);
		$this->assertSame(min((int) $rFirst['exp'] + 2 * 3600, $rNow + 26 * 3600), $rDoc['exp'], "the token's exp plus the tolerance, under the 26 h cap");
		$this->assertSame(1, $rDoc['gen'], "the node's generation: a revoked one gets no lease");

		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'agent_version' => '0.1.0'], 1, $rTok['keys']);
		$this->reply($rRes, $rCtx, $rTok['keys']);
		$rKeys = $rTok['keys'];

		$rEph = random_bytes(32);
		$rPayload = ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))];
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rReply = $this->reply($rRes, $rCtx, $rKeys);
		$rLease = $this->leaseDoc((string) base64_decode($rReply['lease']['payload'], true), (string) base64_decode($rReply['lease']['sig'], true), (int) $rReply['lease']['exp']);
		$this->assertSame((int) $rReply['exp'] + 2 * 3600, $rLease['exp'], 'the epoch it accompanies');

		// A reply that was lost: the same token again, and a lease again — minted
		// now, not kept beside the token, so nothing stale is re-sent.
		ClusterClock::fix($this->rT0 + 30000);
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rAgain = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame($rReply['token_sealed'], $rAgain['token_sealed'], 'the same epoch');
		$rReLease = $this->leaseDoc((string) base64_decode($rAgain['lease']['payload'], true), (string) base64_decode($rAgain['lease']['sig'], true), (int) $rAgain['lease']['exp']);
		$this->assertSame(ClusterClock::now(), $rReLease['iat'], 'issued at this moment');
		$this->assertSame($rLease['exp'], $rReLease['exp'], 'the same window: it follows the token, not the request');
	}

	/**
	 * A rekey carries one too, and a panel the extension will not sign a lease
	 * for sends the token without one: the node keeps the lease it holds until
	 * its own `exp`, which is what a lease is for.
	 */
	public function testARekeyCarriesALeaseAndARefusedOneIsAuditedNotFatal(): void {
		SettingsManager::set(['lb_partition_tolerance_h' => 1] + $this->rSettings);
		$this->expired();
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$rTok = $this->rekeyed($rRes, $rReq, $rEph);
		// The lease rides in the panel-signed reply, beside the sealed token.
		$rDoc = json_decode((string) $rRes['body'], true);
		$rLease = $this->leaseDoc((string) base64_decode($rDoc['lease']['payload'], true), (string) base64_decode($rDoc['lease']['sig'], true), (int) $rDoc['lease']['exp']);
		$this->assertSame((int) $rDoc['exp'] + 3600, $rLease['exp']);

		$this->rCrypto->rRefuseLease = 'LICENCE';
		ClusterClock::fix($this->rT0 + 61000);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base(random_bytes(32)))], 2, $rTok['keys']);
		$rReply = $this->reply($rRes, $rCtx, $rTok['keys']);
		$this->assertArrayNotHasKey('lease', $rReply, 'no lease');
		$this->assertNotEmpty($rReply['token_sealed'], 'and still a token: a node without a lease is not a node without a session');
		$this->rDb->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'node.lease_refused'");
		$this->assertSame('LICENCE', json_decode((string) $this->rDb->get_row()['detail'], true)['reason']);
	}

	public function testAnEpochBecomesCurrentOnlyOnceMarkedUsedAndIsNotMarkedAgain(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys);
		$rTok2 = $this->openToken((string) base64_decode($this->reply($rRes, $rCtx, $rKeys)['token_sealed']), $rEph);
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);

		// MySQL refuses to mark epoch 2 used: it does not become current either.
		$rLog->rRefuse = '/^UPDATE `cluster_node_epochs`/';
		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok2['keys']);
		$this->reply($rRes, $rCtx, $rTok2['keys']);
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['epoch']);

		// The next request marks it, and the ones after write nothing for it.
		$rLog->rRefuse = null;
		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok2['keys']);
		$this->reply($rRes, $rCtx, $rTok2['keys']);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['epoch']);
		$this->rDb->query('SELECT `used` FROM `cluster_node_epochs` WHERE `server_id` = 5 AND `epoch` = 2');
		$this->assertSame(1, (int) $this->rDb->get_row()['used']);
		$rLog->rQueries = [];
		foreach ([2 => $rTok2['keys'], 1 => $rKeys] as $rEpoch => $rEpochKeys) {
			[$rRes, $rCtx] = $this->call('heartbeat', [], $rEpoch, $rEpochKeys);
			$this->reply($rRes, $rCtx, $rEpochKeys);
		}
		$this->assertSame([], array_values(array_filter($rLog->writes(), static fn(string $rQ): bool => str_contains($rQ, 'cluster_node_epochs'))), 'the current epoch, and the one before it, are not marked again');
	}

	public function testExpiredEpochIsRefused(): void {
		$rKeys = $this->active();
		ClusterClock::fix($this->rT0 + (75 * 60 + 1) * 1000);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq);
		TokenService::prune();
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'z is erased with the row');
	}

	/** A deleted server's node is revoked: an agent still running there is refused, never handed tokens, leases or commands. */
	public function testADeletedServersNodeIsRevoked(): void {
		$rKeys = $this->active();
		$this->assertTrue(NodeRegistry::serverDeleted(self::SID, $this->rCrypto));
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(2, $this->denial($rRes, 403, 'NODE_REVOKED', $rReq)['revoked_gen']);
		$this->assertFalse(NodeRegistry::serverDeleted(self::SID, $this->rCrypto), 'already revoked: nothing to do');
		$this->assertFalse(NodeRegistry::serverDeleted(99, $this->rCrypto), 'a server without a node');
		$this->assertStringContainsString("NodeRegistry::serverDeleted(\$rID);", (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerRepository.php'), 'deleteById revokes it');
	}

	public function testRevokedNodeIsRefusedEvenWithItsRowRestored(): void {
		$rKeys = $this->active();
		$this->rDb->query('SELECT * FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$rEpochRow = $this->rDb->get_row();
		$rNodeRow = NodeRegistry::byServer(self::SID);
		$this->assertTrue(NodeRegistry::revoke(self::SID, $this->rCrypto));
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(2, $this->denial($rRes, 403, 'NODE_REVOKED', $rReq)['revoked_gen']);

		// Restore both rows from a "backup": the extension's floor still refuses.
		$this->rDb->query('UPDATE `cluster_nodes` SET `state` = ?, `gen` = ? WHERE `server_id` = 5', 'active', (int) $rNodeRow['gen']);
		$this->rDb->query(
			'INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `token_sealed`, `nbf`, `exp`, `refresh_at`, `used`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			5,
			1,
			$rEpochRow['record'],
			$rEpochRow['token_sealed'],
			$rEpochRow['nbf'],
			$rEpochRow['exp'],
			$rEpochRow['refresh_at'],
			1,
			$rEpochRow['created_at']
		);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 403, 'NODE_REVOKED', $rReq);
	}

	public function testUnknownNodeAndDisabledApi(): void {
		$rKeys = ClusterReference::sessionKeys(random_bytes(32));
		$this->rNodeSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'UNKNOWN_NODE', $rReq);

		$this->rSettings['cluster_api_enabled'] = 0;
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 503, 'DISABLED');
	}

	public function testHealthAndChallengeArePanelSigned(): void {
		$rPub = $this->rCrypto->info()['panel_sign_pub'];
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/health', 'headers' => []], ['cluster_api_enabled' => 0], $this->rMain);
		$this->assertSame(200, $rRes['status']);
		$this->assertTrue(PanelSig::verify($rPub, 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$this->assertSame(base64_encode($rPub), json_decode($rRes['body'], true)['panel_sign_pub']);

		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => []], $this->rSettings, $this->rMain);
		$this->assertSame(200, $rRes['status']);
		$this->assertTrue(PanelSig::verify($rPub, 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($this->rUuid, $rDoc['cn']);
		$this->assertSame(32, strlen((string) base64_decode($rDoc['challenge'])));

		$this->denial(ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/nope', 'headers' => []], $this->rSettings, $this->rMain), 404, 'UNKNOWN_OP');
		$this->denial(ClusterApi::handle($this->rCrypto, ['method' => 'POST', 'path' => '/cluster/v1/health', 'headers' => []], $this->rSettings, $this->rMain), 405, 'BAD_REQUEST');
	}

	public function testReEnrolmentRaisesTheGeneration(): void {
		$rOld = $this->active();
		$rSecond = $this->enrol();
		$this->assertSame(2, $rSecond['gen']);
		[$rRes] = $this->call('heartbeat', [], 1, $rOld);
		$this->assertSame(401, $rRes['status'], 'the old generation\'s keys no longer authenticate');
	}

	public function testNodeHealth(): void {
		$this->assertSame('unknown', NodeHealth::state(null, 0, 100000, 30));
		$this->assertSame('ok', NodeHealth::state(90000, 0, 100000, 30));
		$this->assertSame('suspect', NodeHealth::state(80000, 0, 100000, 30));
		$this->assertSame('offline', NodeHealth::state(60000, 0, 100000, 30));
		$this->assertSame('ok', NodeHealth::state(1000, 95000, 100000, 30), 'MAIN downtime does not count');
	}

	public function testPolicy(): void {
		$rMain = $this->rMain + ['domain_name' => 'panel.example.com', 'https_broadcast_port' => 443];
		$rMain['enable_https'] = 1;
		$this->assertSame(
			['https://panel.example.com:443/cluster/v1/', 'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current(['cluster_transport' => 'https_preferred'], $rMain)['main_urls']
		);
		$this->assertSame(['https://panel.example.com:443/cluster/v1/'], ClusterPolicy::current(['cluster_transport' => 'https_required'], $rMain)['main_urls']);
		$this->assertCount(2, ClusterPolicy::current(['cluster_transport' => 'auto'], $rMain, false)['main_urls']);
		$this->assertSame('http://10.0.0.1:31200/cluster/v1/', ClusterPolicy::current(['cluster_api_port' => 31200], ['server_ip' => '10.0.0.1'])['main_urls'][0]);
	}

	public function testSas(): void {
		$rSas = EnrolmentService::sas($this->rUuid, str_repeat("\x01", 32), str_repeat("\x02", 32));
		$this->assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){5}$/', $rSas);
		$this->assertNotSame($rSas, EnrolmentService::sas($this->rUuid, str_repeat("\x01", 32), str_repeat("\x03", 32)));
	}

	public function testInitRecordsThePanelKeysAndReadiness(): void {
		$rFirst = ClusterMeta::init($this->rCrypto);
		$rFp = bin2hex((string) $this->rCrypto->info()['panel_fp']);
		$this->assertSame($rFp, $rFirst['panel_fp']);
		$this->assertSame($rFp, ClusterMeta::get('panel_fp'));
		$this->assertSame(base64_encode((string) $this->rCrypto->info()['panel_sign_pub']), ClusterMeta::get('panel_sign_pub'));
		$this->assertSame($this->rT0, ClusterMeta::readyAtMs());
		$this->assertSame((string) intdiv($this->rT0, 1000), ClusterMeta::get(ClusterMeta::ROOT_AT), 'a new root: when it changed');

		ClusterClock::fix($this->rT0 + 60000);
		$this->assertFalse(ClusterMeta::init($this->rCrypto)['created'], 'idempotent');
		$this->assertSame($this->rT0 + 60000, ClusterMeta::readyAtMs(), 'a restart restarts the silence clock');
		$this->assertSame((string) intdiv($this->rT0, 1000), ClusterMeta::get(ClusterMeta::ROOT_AT), 'the same root: unchanged');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_meta` WHERE `name` = 'panel_fp'");
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
	}

	public function testRekeyAfterExpiryIssuesAFreshEpoch(): void {
		$this->expired();
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$rTok = $this->rekeyed($rRes, $rReq, $rEph);
		$this->assertSame(2, $rTok['doc']['epoch'], 'after the current epoch');
		$this->assertSame(self::SID, $rTok['doc']['server_id']);

		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok['keys']);
		$this->reply($rRes, $rCtx, $rTok['keys']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame(2, (int) $rNode['epoch']);
		$this->assertSame('boot-9', $rNode['boot_id']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
	}

	public function testRekeyReplacesAnEpochWhoseReplyWasLost(): void {
		$this->expired();
		$rLost = random_bytes(32);
		[$rRes] = $this->rekey($this->challenge(), $rLost);
		$this->assertSame(200, $rRes['status']);
		ClusterClock::fix($this->rT0 + 61000);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$this->assertSame(3, $this->rekeyed($rRes, $rReq, $rEph)['doc']['epoch']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'the unused epoch 2 is gone');
	}

	public function testRekeyChallengeIsSingleUseAndIssued(): void {
		$this->expired();
		[$rRes, $rReq] = $this->rekey(random_bytes(32), random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);

		$rChallenge = $this->challenge();
		ClusterClock::fix($this->rT0 + 61000);
		[$rRes] = $this->rekey($rChallenge, random_bytes(32));
		$this->assertSame(200, $rRes['status']);
		ClusterClock::fix($this->rT0 + 122000);
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);

		// A challenge lives 180 s.
		$rOld = $this->challenge();
		ClusterClock::fix($this->rT0 + 122000 + 181000);
		[$rRes, $rReq] = $this->rekey($rOld, random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);
	}

	public function testRekeyIsLimitedToOncePerMinute(): void {
		$this->expired();
		[$rRes] = $this->rekey($this->challenge(), random_bytes(32));
		$this->assertSame(200, $rRes['status']);
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->assertGreaterThan(0, $this->denial($rRes, 429, 'RATE_LIMITED', $rReq)['retry_after_ms']);
	}

	public function testRekeyNeedsTheNodeSignatureAndChargesNothingWithout(): void {
		$this->expired();
		$rChallenge = $this->challenge();
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32), [], ['headers' => static function ($h) {
			$h['X-XCVM-Node-Sig'] = str_repeat('00', 64);
			return $h;
		}
		]);
		$this->denial($rRes, 401, 'BAD_NODE_SIG', $rReq);
		// Neither the minute nor the challenge was spent.
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($rChallenge, $rEph);
		$this->rekeyed($rRes, $rReq, $rEph);

		// A MAC'd session header has no place in a re-key.
		[$rRes] = $this->rekey($this->challenge(), random_bytes(32), [], ['headers' => static function ($h) {
			$h['X-XCVM-Sig'] = str_repeat('00', 32);
			return $h;
		}
		]);
		$this->denial($rRes, 400, 'BAD_REQUEST');
	}

	public function testRekeyRefusedWithoutLicenceKeepsTheNode(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence cannot be withdrawn from the real extension mid-test');
		}
		$this->expired();
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 403, 'LICENCE_INVALID', $rReq);
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);

		// Re-licensed: the next minute's re-key goes through.
		$this->rCrypto->rRefuseIssue = null;
		ClusterClock::fix($this->rT0 + 61000);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$this->rekeyed($rRes, $rReq, $rEph);
	}

	public function testRekeyFromAnotherInstanceQuarantines(): void {
		$this->expired();
		$this->peer(6, ReplicaBuilder::FEATURE_CONFIG_CHANGED);
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32), ['instance_id' => 'inst-CLONE']);
		$this->assertSame('quarantined', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
		$this->assertSame('quarantined', NodeRegistry::byServer(self::SID)['state']);
		$this->assertSame([6], $this->announced(), 'its peers stop trusting it at once');

		// A quarantined node waits for the admin.
		ClusterClock::fix($this->rT0 + 61000);
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
	}

	public function testRekeyOfARevokedNodeIsRefused(): void {
		$this->expired();
		$this->assertTrue(NodeRegistry::revoke(self::SID, $this->rCrypto));
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 403, 'NODE_REVOKED', $rReq);
	}

	public function testCommandsAreDeliveredSignedAndAcked(): void {
		$rKeys = $this->active();
		$rCmd = \XcVm\Domain\Cluster\CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertCount(1, $rOut['commands']);
		$rOne = $rOut['commands'][0];
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'cmd', $rOne['doc'], (string) Enc::b64urlDecode($rOne['sig'])), 'panel-signed, tag cmd');
		$rDoc = json_decode($rOne['doc'], true);
		$this->assertSame(['node.rpc', 'get_pids', 1, $this->rUuid, 1, $rCmd, []], [$rDoc['type'], $rDoc['action'], $rDoc['seq'], $rDoc['node_uuid'], $rDoc['gen'], $rDoc['cmd_id'], $rDoc['args']], 'the action is the envelope\'s, as the extension classes it');

		// Nothing after the high-water.
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 1, 'wait_ms' => 0], 1, $rKeys);
		$this->assertSame([], $this->reply($rRes, $rCtx, $rKeys)['commands']);

		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rCmd, 'ok' => true, 'result' => '[1,2]'], 1, $rKeys);
		$this->assertTrue($this->reply($rRes, $rCtx, $rKeys)['ok']);
		$this->assertSame([true, '[1,2]'], \XcVm\Domain\Cluster\CommandBus::result($rCmd));
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'the high-water rises with the ack');
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys);
		$this->assertSame([], $this->reply($rRes, $rCtx, $rKeys)['commands'], 'an acked command is not delivered again');

		// An ack for a command that is not this node's is refused.
		[$rRes, , $rReq] = $this->call('ack', ['cmd_id' => str_repeat('ab', 16), 'ok' => true], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
	}

	/**
	 * A quarantine freezes what grants (plan, section 4): a quarantined node
	 * is handed its restrictive commands only — its node.quarantine, kills,
	 * stops, a fence — and the rest waits, queued, until an admin trusts it
	 * again. Its replica stays refused.
	 */
	public function testQuarantineFreezesGrantingCommands(): void {
		$rKeys = $this->active();
		$rBus = \XcVm\Domain\Cluster\CommandBus::class;
		$rGrant = $rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$rStop = $rBus::enqueue($this->rCrypto, self::SID, 'stream.stop', ['stream_id' => 7]);
		NodeRegistry::update(self::SID, ['state' => 'quarantined']);
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys);
		$rDocs = array_map(static fn(array $rC): array => json_decode($rC['doc'], true), $this->reply($rRes, $rCtx, $rKeys)['commands']);
		$this->assertSame([$rStop], array_column($rDocs, 'cmd_id'), 'the stop only');
		[$rRes, , $rReq] = $this->call('config', [], 1, $rKeys);
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);

		NodeRegistry::update(self::SID, ['state' => 'active']);
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys);
		$this->assertContains($rGrant, array_map(static fn(array $rC): string => json_decode($rC['doc'], true)['cmd_id'], $this->reply($rRes, $rCtx, $rKeys)['commands']), 'trusted again, the granting command is handed out');
	}

	public function testCommandsExpireAndDedupe(): void {
		$this->active();
		$rBus = \XcVm\Domain\Cluster\CommandBus::class;
		$rFirst = $rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'stream', 'function' => 'start', 'stream_ids' => [1]], 'stream:1');
		$rSecond = $rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'stream', 'function' => 'stop', 'stream_ids' => [1]], 'stream:1');
		$rPending = $rBus::pending(self::SID, 0);
		$this->assertCount(1, $rPending, 'a newer desired state replaces the unacked one');
		$this->assertSame($rSecond, json_decode($rPending[0]['doc'], true)['cmd_id']);
		$this->assertNotSame($rFirst, $rSecond);
		ClusterClock::fix($this->rT0 + 601000);
		$this->assertSame([], $rBus::pending(self::SID, 0), 'expired');
		$rBus::prune();
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_commands`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
	}

	public function testRoutingFollowsTheCommandsFlow(): void {
		$this->active();
		SettingsManager::set($this->rSettings);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			$this->assertSame([false, false], \XcVm\Domain\Cluster\ClusterRoute::kill(self::SID, 123, false), 'flow off: legacy');
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS]);
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::kill(self::SID, 123, false));
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::send(self::SID, ['action' => 'free_temp']));
			$rStart = microtime(true);
			$this->assertSame([true, null], \XcVm\Domain\Cluster\ClusterRoute::rpc(self::SID, ['action' => 'get_pids'], 1), 'no ack: a timeout, as an offline node');
			$this->assertLessThan(3, microtime(true) - $rStart);
			$rTypes = array_map(static fn($rC) => json_decode($rC['doc'], true)['type'], \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0));
			$this->assertSame(['conn.kill_worker', 'node.rpc', 'node.rpc'], $rTypes);
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	public function testRemoteKillsAndViewerDropsBecomeCommands(): void {
		$this->active();
		SettingsManager::set($this->rSettings);
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS]);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			// ConnectionTracker's Redis-mode kills: a worker pid, an RTMP client, a daemon viewer.
			$this->assertNotFalse(\XcVm\Domain\Stream\ConnectionTracker::redisSignal(55, self::SID, 0));
			$this->assertNotFalse(\XcVm\Domain\Stream\ConnectionTracker::redisSignal(9, self::SID, 1));
			$this->assertNotFalse(\XcVm\Domain\Stream\ConnectionTracker::redisSignal(0, self::SID, 0, ['type' => 'drop_con', 'uuid' => 'abc123']));
			\XcVm\Domain\Cluster\ClusterRoute::drop(self::SID, 'abc123'); // the same viewer again: superseded, not doubled
			$this->assertSame([false, false], \XcVm\Domain\Cluster\ClusterRoute::drop(self::SID, 'bad uuid;rm'));
			$rDocs = array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0));
			$this->assertSame(['conn.kill_worker', 'conn.kill_worker', 'conn.drop'], array_column($rDocs, 'type'));
			$this->assertSame([['pid' => 55, 'rtmp' => false], ['pid' => 9, 'rtmp' => true], ['uuid' => 'abc123']], array_column($rDocs, 'args'));
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	/**
	 * A node in mode 2 reads no `signals` row (its connects are refused), so
	 * the cache jobs MAIN queues for it (a movie's files deleted, a closed
	 * viewer's connection file, a daemon viewer's drop) go as signed
	 * `node.cache` commands, none naming more than CacheJobs::MAX targets (a
	 * list of ids split across them), only in the form the node runs. Nodes
	 * in mode 0 or 1, one without COMMANDS and MAIN itself (even with a node
	 * row of its own) keep the rows their signals daemon reads. Run alone:
	 * MAIN's SERVER_ID is 1 here, whatever another test defined.
	 */
	// skip-on-panel: PHPUnit feeds an isolated test to its child PHP on stdin, and the
	// panel's PHP (ionCube Loader + opcache.enable_cli) segfaults on a script read from stdin.
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testCacheJobsForAModeTwoNodeBecomeCommands(): void {
		define('SERVER_ID', 1); // MAIN
		$this->active();
		SettingsManager::set($this->rSettings);
		$rRows = [];
		SignalDispatcher::useSink(new class ($rRows) implements \XcVm\Core\Cluster\SignalSink {
			public function __construct(private array &$rRows) {
			}

			public function insert(array $rRows): bool {
				array_push($this->rRows, ...$rRows);
				return true;
			}

			public function pending(int $rServerID, string $rCustomData): bool {
				return false;
			}
		});
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		$rJobs = static fn(int $rServerID = self::SID): array => array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending($rServerID, 0, 200));
		$rMax = \XcVm\Core\Cluster\CacheJobs::MAX;
		try {
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS, 'mode' => 1]);
			$this->assertTrue(SignalDispatcher::cache(self::SID, ['type' => 'delete_vod', 'id' => 7]));
			$this->assertSame([], $rJobs(), 'mode 1: the row its daemon reads');
			$this->assertCount(1, $rRows);

			NodeRegistry::update(self::SID, ['mode' => 2]);
			$this->assertTrue(SignalDispatcher::cache(self::SID, ['type' => 'delete_vods', 'id' => ['8', 9, 'x', 0]]));
			$this->assertTrue(SignalDispatcher::cache(self::SID, ['type' => 'drop_con', 'uuid' => 'abc123'], true, true));
			$rUUIDs = array_map(static fn(int $i): string => sprintf('%032x', $i), range(1, $rMax + 20));
			$this->assertTrue(SignalDispatcher::cacheBatch(self::SID, array_merge(array_map(static fn(string $rUUID): array => ['type' => 'delete_con', 'uuid' => $rUUID], $rUUIDs), [['type' => 'delete_con', 'uuid' => '../x']])));
			$this->assertTrue(SignalDispatcher::cacheBatch(self::SID, [['type' => 'delete_vod', 'id' => 1], ['type' => 'delete_vods', 'id' => range(1, $rMax + 10)], ['type' => 'drop_con', 'uuid' => 'z']]));
			$this->assertFalse(SignalDispatcher::cache(self::SID, ['type' => 'delete_con', 'uuid' => 'a b']), 'nothing well-formed: nothing sent');
			$this->assertCount(1, $rRows, 'no row for a node in mode 2');
			$rDocs = $rJobs();
			$this->assertSame(array_fill(0, 6, 'node.purge'), array_column($rDocs, 'type'), 'every job here removes something');
			$this->assertSame([['type' => 'delete_vods', 'id' => [8, 9]]], $rDocs[0]['args']['jobs'], 'ids as integers, the rest dropped');
			$this->assertSame([['type' => 'drop_con', 'uuid' => 'abc123']], $rDocs[1]['args']['jobs']);
			$this->assertCount($rMax, $rDocs[2]['args']['jobs']);
			$this->assertSame($rUUIDs, array_merge(array_column($rDocs[2]['args']['jobs'], 'uuid'), array_column($rDocs[3]['args']['jobs'], 'uuid')), 'in order, the malformed one left out');
			// A list of ids longer than a command's room goes on in the next one.
			$this->assertSame([['type' => 'delete_vod', 'id' => 1], ['type' => 'delete_vods', 'id' => range(1, $rMax - 1)]], $rDocs[4]['args']['jobs']);
			$this->assertSame([['type' => 'delete_vods', 'id' => range($rMax, $rMax + 10)], ['type' => 'drop_con', 'uuid' => 'z']], $rDocs[5]['args']['jobs']);
			foreach ($rDocs as $rDoc) {
				$this->assertLessThanOrEqual($rMax, \XcVm\Core\Cluster\CacheJobs::targets($rDoc['args']['jobs']));
			}
			$this->assertSame(86400, $rDocs[0]['exp'] - $rDocs[0]['iat'], 'kept for a day, as MAIN keeps a signals row');
			$this->rDb->query('SELECT DISTINCT `class` FROM `cluster_commands`');
			$this->assertSame('R', $this->rDb->get_row()['class'], 'restrictive: removals only (node.purge)');

			// With the cluster API off, and for a node without COMMANDS, rows.
			SettingsManager::set(['cluster_api_enabled' => 0] + $this->rSettings);
			$this->assertTrue(SignalDispatcher::cache(self::SID, ['type' => 'delete_con', 'uuid' => 'off']));
			SettingsManager::set($this->rSettings);
			NodeRegistry::update(self::SID, ['flows' => 0]);
			$this->assertTrue(SignalDispatcher::cacheBatch(self::SID, [['type' => 'delete_con', 'uuid' => 'abc']]));
			$this->assertCount(6, $rJobs());

			// So do MAIN's own jobs, even were MAIN's server a node in mode 2 taking commands.
			$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `created_at`, `updated_at`) VALUES (1, ?, 'active', 2, ?, 0, 0);", '00000000-0000-4000-a000-000000000001', NodeRegistry::FLOW_COMMANDS);
			$this->assertTrue(SignalDispatcher::cache(1, ['type' => 'delete_vod', 'id' => 3]));
			$this->assertSame([], $rJobs(1), 'no node.cache for MAIN itself');
			$this->assertSame([self::SID, self::SID, 1], array_column(array_slice($rRows, 1), 'server_id'));
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
			SignalDispatcher::useSink(null);
		}
	}

	/**
	 * A node in mode 2 cannot queue its own certificate renewal (its root
	 * reads no `signals` row), so MAIN's daily cron:certbot sends it the
	 * `node.root certbot_generate` on the node's own rule: HTTPS on, and the
	 * certificate it last reported due within a week; for its names, not
	 * its addresses. Nodes in mode 0 or 1 queue their own; a node root
	 * does not take commands from gets nothing, not even a row.
	 */
	public function testMainSendsTheCertificateRenewalsOfModeTwoNodes(): void {
		$this->active();
		SettingsManager::set($this->rSettings);
		foreach (['`enable_https` tinyint(4) NOT NULL DEFAULT 0', '`domain_name` text', '`certbot_ssl` mediumtext'] as $rColumn) {
			$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN ' . $rColumn);
		}
		$rNow = intdiv($this->rT0, 1000);
		$rDue = (string) json_encode(['serial' => '0A', 'expiration' => $rNow + 3 * 86400, 'subject' => 'CN = node.example', 'path' => '/home/xc_vm/bin/certbot/config/live/node.example']);
		$this->rDb->query('UPDATE `servers` SET `enable_https` = 1, `domain_name` = ?, `certbot_ssl` = ? WHERE `id` = ?;', 'node.example, 192.0.2.5 ,www.node.example', $rDue, self::SID);
		$rRows = [];
		SignalDispatcher::useSink(new class ($rRows) implements \XcVm\Core\Cluster\SignalSink {
			public function __construct(private array &$rRows) {
			}

			public function insert(array $rRows): bool {
				array_push($this->rRows, ...$rRows);
				return true;
			}

			public function pending(int $rServerID, string $rCustomData): bool {
				return false;
			}
		});
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		$rRoot = static fn(): array => array_values(array_filter(array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0, 200)), static fn(array $rDoc): bool => $rDoc['type'] === 'node.root'));
		try {
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS, 'mode' => 1, 'root_ready' => 1]);
			$this->assertSame([], NodeCertbot::renewDue(), 'mode 1 queues its own');
			NodeRegistry::update(self::SID, ['mode' => 2, 'root_ready' => 0]);
			$this->assertSame([], NodeCertbot::renewDue(), 'root takes no command yet');
			NodeRegistry::update(self::SID, ['root_ready' => 1]);
			$this->assertSame([self::SID], NodeCertbot::renewDue());
			$rSent = $rRoot();
			$this->assertCount(1, $rSent);
			$this->assertSame(['certbot_generate', ['domain' => ['node.example', 'www.node.example']]], [$rSent[0]['action'], $rSent[0]['args']], 'names trimmed, the address left out');

			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = ? WHERE `id` = ?;', json_encode(['serial' => '0B', 'expiration' => $rNow + 30 * 86400]), self::SID);
			$this->assertSame([], NodeCertbot::renewDue(), 'not due');
			// The node's own rule: due when less than a week is left.
			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = ? WHERE `id` = ?;', json_encode(['serial' => '0B', 'expiration' => $rNow + NodeCertbot::DUE]), self::SID);
			$this->assertSame([], NodeCertbot::renewDue(), 'a week left: not due yet');
			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = ? WHERE `id` = ?;', json_encode(['serial' => '0B', 'expiration' => $rNow + NodeCertbot::DUE - 1]), self::SID);
			$this->assertSame([self::SID], NodeCertbot::renewDue(), 'a second less: due');
			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = ?, `enable_https` = 0 WHERE `id` = ?;', $rDue, self::SID);
			$this->assertSame([], NodeCertbot::renewDue(), 'HTTPS off');
			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = NULL, `enable_https` = 1 WHERE `id` = ?;', self::SID);
			$this->assertSame([], NodeCertbot::renewDue(), 'nothing reported');
			$this->rDb->query('UPDATE `servers` SET `certbot_ssl` = ?, `domain_name` = ? WHERE `id` = ?;', $rDue, '192.0.2.5', self::SID);
			$this->assertSame([], NodeCertbot::renewDue(), 'no name to certify');
			$this->assertCount(2, $rRoot());
			$this->assertSame([], $rRows, 'never a signals row');
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
			SignalDispatcher::useSink(null);
		}
	}

	/**
	 * MAIN's daily cron:certbot sends those renewals once it saw to its own
	 * certificate; the check the certbot command starts (`cron:certbot 1`)
	 * sends none, nor does a run where the cluster API is off or on a node.
	 * Run alone: MAIN's SERVER_ID is 1 here.
	 */
	// skip-on-panel: PHPUnit feeds an isolated test to its child PHP on stdin, and the
	// panel's PHP (ionCube Loader + opcache.enable_cli) segfaults on a script read from stdin.
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testMainsDailyCronCertbotSendsTheRenewals(): void {
		define('SERVER_ID', 1); // MAIN
		$this->active();
		SettingsManager::set($this->rSettings);
		foreach (['`enable_https` tinyint(4) NOT NULL DEFAULT 0', '`domain_name` text', '`certbot_ssl` mediumtext'] as $rColumn) {
			$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN ' . $rColumn);
		}
		$rDue = (string) json_encode(['serial' => '0A', 'expiration' => intdiv($this->rT0, 1000) + 3 * 86400]);
		$this->rDb->query('UPDATE `servers` SET `enable_https` = 1, `domain_name` = ?, `certbot_ssl` = ? WHERE `id` = ?;', 'node.example', $rDue, self::SID);
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS, 'mode' => 2, 'root_ready' => 1]);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		$rMain = true;
		\XcVm\Core\Cluster\NodeRole::useServers(static function () use (&$rMain): array {
			return [1 => ['id' => 1, 'is_main' => $rMain ? 1 : 0], self::SID => ['id' => self::SID, 'is_main' => 0]];
		});
		// Its own certificate is not this test's: only whether it was seen to, and how.
		$rCron = new class extends \XcVm\Cli\CronJobs\CertbotCronJob {
			/** @var list<bool> */
			public array $rChecks = [];

			protected function checkCertificate(bool $rCheck): void {
				$this->rChecks[] = $rCheck;
			}
		};
		$rRun = static function (bool $rCheck) use ($rCron): string {
			ob_start();
			try {
				(new \ReflectionMethod(\XcVm\Cli\CronJobs\CertbotCronJob::class, 'loadCron'))->invoke($rCron, $rCheck);
			} finally {
				$rOut = (string) ob_get_clean();
			}
			return $rOut;
		};
		$rRoot = static fn(): array => array_values(array_filter(array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0, 200)), static fn(array $rDoc): bool => $rDoc['type'] === 'node.root'));
		try {
			$this->assertSame('', $rRun(true), 'the certbot command\'s check');
			SettingsManager::set(['cluster_api_enabled' => 0] + $this->rSettings);
			$this->assertSame('', $rRun(false), 'the cluster API off');
			SettingsManager::set($this->rSettings);
			$rMain = false;
			$this->assertSame('', $rRun(false), 'not MAIN');
			$this->assertSame([], $rRoot());

			$rMain = true;
			$this->assertSame('Certificate renewal sent to server ' . self::SID . '.' . "\n", $rRun(false));
			$rSent = $rRoot();
			$this->assertCount(1, $rSent);
			$this->assertSame(['certbot_generate', ['domain' => ['node.example']]], [$rSent[0]['action'], $rSent[0]['args']]);
			$this->assertSame([true, false, false, false], $rCron->rChecks, 'its own certificate first, each run');
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
			\XcVm\Core\Cluster\NodeRole::useServers(null);
		}
	}

	public function testMainsClosesReachANodeThatHoldsItsViewers(): void {
		$this->active();
		SettingsManager::set($this->rSettings);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS]);
			$this->assertSame([false, false], \XcVm\Domain\Cluster\ClusterRoute::closeConnection(self::SID, 'abc', true), 'no registry on the node: nothing to tell');
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::closeConnection(self::SID, 'abc', false));
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::closeConnection(self::SID, 'abc', true)); // supersedes the first
			$rDocs = array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0));
			$this->assertSame([['conn.close', ['uuid' => 'abc', 'remove' => true]]], array_map(static fn($rD) => [$rD['type'], $rD['args']], $rDocs));
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	// skip-on-panel: PHPUnit feeds an isolated test to its child PHP on stdin, and the
	// panel's PHP (ionCube Loader + opcache.enable_cli) segfaults on a script read from stdin.
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testTheLimitersCloseReachesTheNodeThatHoldsTheViewer(): void {
		define('SERVER_ID', 1); // MAIN; the viewer is on node 5
		if (!class_exists('XC_VM', false)) {
			eval('final class XC_VM { public static function redis_connect() { return null; } }'); // no Redis here; the limiter asks for it up front
		}
		$this->active();
		SettingsManager::set($this->rSettings);
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `hls_end` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `lines_live` (`activity_id`) VALUES (7)');
		$GLOBALS['db'] = $this->rDb;
		$GLOBALS['rSettings'] = ['redis_handler' => 0, 'save_closed_connection' => 0];
		$rViewer = ['activity_id' => 7, 'server_id' => self::SID, 'proxy_id' => 0, 'user_id' => 3, 'stream_id' => 100, 'date_start' => 1, 'user_agent' => 'ua', 'user_ip' => '10.0.0.1', 'geoip_country_code' => '', 'isp' => '', 'pid' => 0];
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			$this->assertTrue(\XcVm\Streaming\Protection\ConnectionLimiter::closeConnection($rViewer + ['uuid' => 'hlsviewer', 'container' => 'hls']));
			$this->rDb->query('SELECT `hls_end` FROM `lines_live` WHERE `activity_id` = 7');
			$this->assertSame(1, (int) $this->rDb->get_row()['hls_end']);
			$rDocs = array_map(static fn($rC) => json_decode($rC['doc'], true), \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0));
			$this->assertSame([['conn.close', ['uuid' => 'hlsviewer', 'remove' => false]]], array_map(static fn($rD) => [$rD['type'], $rD['args']], $rDocs), 'an ended HLS viewer stays ended on the node');
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	public function testADriftedNodeIsAskedForItsSnapshotAndItIsApplied(): void {
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_id` int, `stream_id` int, `server_id` int, `proxy_id` int, `user_agent` text, `user_ip` text, `container` text, `pid` int, `date_start` int, `geoip_country_code` text, `isp` text, `external_device` text, `hls_last_read` int, `hls_end` int DEFAULT 0, `hmac_id` int, `hmac_identifier` text, `uuid` text)');
		$this->rDb->query("INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`) VALUES ('ghost', 5, 7)");
		$rDir = sys_get_temp_dir() . '/xcvm-api-snap-' . bin2hex(random_bytes(4));
		\XcVm\Domain\Cluster\ConnectionDigest::useState($rDir . '/d/', 0);
		\XcVm\Domain\Cluster\ConnectionSnapshot::useDir($rDir . '/s/');
		try {
			$rKeys = $this->active();
			$rDigest = ['count' => 0, 'users' => 0, 'xor64' => '0000000000000000'];
			$rSnap = ['snap_id' => 'ab12ab12ab12ab12', 'seq' => 0, 'last' => true, 'records' => []];

			// Without CONNECTIONS the digest is not looked at, and a snapshot is refused.
			[$rRes, $rCtx] = $this->call('heartbeat', ['conn_digest' => $rDigest], 1, $rKeys);
			$this->assertArrayNotHasKey('want_conn_snapshot', $this->reply($rRes, $rCtx, $rKeys));
			[$rRes, , $rReq] = $this->call('conn_snapshot', $rSnap, 1, $rKeys);
			$this->denial($rRes, 409, 'FLOW_OFF', $rReq);

			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
			[$rRes, $rCtx] = $this->call('heartbeat', ['conn_digest' => $rDigest], 1, $rKeys);
			$this->assertArrayNotHasKey('want_conn_snapshot', $this->reply($rRes, $rCtx, $rKeys), 'one miss');
			[$rRes, $rCtx] = $this->call('heartbeat', ['conn_digest' => $rDigest], 1, $rKeys);
			$this->assertTrue($this->reply($rRes, $rCtx, $rKeys)['want_conn_snapshot'] ?? false, 'the drift lasted');

			[$rRes, , $rReq] = $this->call('conn_snapshot', ['seq' => 1] + $rSnap, 1, $rKeys);
			$this->assertSame(0, $this->denial($rRes, 409, 'SNAP_GAP', $rReq)['expected_seq']);
			[$rRes, $rCtx] = $this->call('conn_snapshot', $rSnap, 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame([true, 0, 1, 0], [$rOut['done'], $rOut['applied'], $rOut['removed'], $rOut['dropped']]);
			$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live`');
			$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'the node holds none: the ghost is gone');
		} finally {
			\XcVm\Domain\Cluster\ConnectionDigest::useState(null);
			\XcVm\Domain\Cluster\ConnectionSnapshot::useDir(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testEventsAreAppliedInOrderAndHelloReturnsTheCursors(): void {
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `pid` int)');
		$this->rDb->exec('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, 5, 0)');
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS]);
		$rState = ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => self::SID, 'fields' => ['pid' => 42]]];

		[$rRes, $rCtx] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState]], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame([1, 1, 0], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->rDb->query('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11');
		$this->assertSame(42, (int) $this->rDb->get_row()['pid']);

		// A gap on P0 is refused with the number MAIN expects.
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 5, 'events' => [$rState]], 1, $rKeys);
		$this->assertSame(2, $this->denial($rRes, 409, 'USEQ_GAP', $rReq)['expected_useq']);

		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p9', 'first_useq' => 2, 'events' => []], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);

		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a'], 1, $rKeys);
		$this->assertSame(['p0' => 1, 'p1' => 0], $this->reply($rRes, $rCtx, $rKeys)['cursors']);
	}

	public function testAP0BatchThatGoesBackQuarantines(): void {
		$rPrevious = EventIngest::useLockDir(sys_get_temp_dir() . '/xcvm-ingest-' . bin2hex(random_bytes(4)) . '/');
		try {
			$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `pid` int)');
			$this->rDb->exec('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, 5, 0)');
			$rKeys = $this->active();
			$this->peer(6, ReplicaBuilder::FEATURE_CONFIG_CHANGED);
			NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS]);
			$rState = static fn(int $rPid): array => ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => self::SID, 'fields' => ['pid' => $rPid]]];

			$this->assertSame(1, $this->served('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState(42)]], 1, $rKeys)['useq']);
			$this->assertSame(0, $this->served('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState(42)]], 1, $rKeys)['applied'], 'its reply was lost: a repeat');
			$this->assertSame([], $this->announced());

			// Other events under a number MAIN applied: another install.
			[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState(7)]], 1, $rKeys);
			$this->assertSame('quarantined', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
			$rNode = NodeRegistry::byServer(self::SID);
			$this->assertSame(['quarantined', 'P0 sequence went backwards'], [$rNode['state'], $rNode['quarantine_reason']]);
			$this->assertSame([6], $this->announced(), 'its peers stop trusting it at once');
			$this->rDb->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'node.quarantine';");
			$this->assertSame(['reason' => 'p0 backwards', 'first_useq' => 1, 'count' => 1, 'cursor' => 1], json_decode($this->rDb->get_row()['detail'], true));
			$this->rDb->query('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11');
			$this->assertSame(42, (int) $this->rDb->get_row()['pid'], 'nothing of it applied');
		} finally {
			exec('rm -rf ' . escapeshellarg((string) EventIngest::useLockDir($rPrevious)));
		}
	}

	public function testP2TakesTouchesWithoutANumberAndHelloAndHeartbeatSaySo(): void {
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` varchar(32), `server_id` int, `hls_last_read` int, `hls_end` int NOT NULL DEFAULT 0)');
		$this->rDb->exec("INSERT INTO `lines_live` (`uuid`, `server_id`, `hls_last_read`) VALUES ('aaaa', 5, 100)");
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
		$rTouch = ['type' => 'conn.touch', 't' => $this->rT0, 'd' => ['uuid' => 'aaaa', 'hls_last_read' => 150]];

		[$rRes, $rCtx] = $this->call('events', ['lane' => 'p2', 'events' => [$rTouch]], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame([0, 1, 0], [$rOut['useq'], $rOut['applied'], $rOut['dropped']], 'no number, no cursor');
		$this->rDb->query('SELECT `hls_last_read` FROM `lines_live` WHERE `uuid` = ?', 'aaaa');
		$this->assertSame(150, (int) $this->rDb->get_row()['hls_last_read'], 'no bus here: MAIN\'s store');
		[$rRes, $rCtx] = $this->call('events', ['lane' => 'p2', 'first_useq' => 'x', 'events' => [$rTouch]], 1, $rKeys);
		$this->assertSame(1, $this->reply($rRes, $rCtx, $rKeys)['applied'], 'first_useq is not read on P2');

		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a'], 1, $rKeys);
		$rHello = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(['conn.touch'], $rHello['p2_types']);
		$this->assertSame(['p0' => 0, 'p1' => 0], $rHello['cursors']);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(['conn.touch'], $this->reply($rRes, $rCtx, $rKeys)['p2_types'], 'in every heartbeat too, so a rollback is noticed');
	}

	public function testP2FailsWith503WhenTheStoreIsDownAndIsClosedToAQuarantinedNode(): void {
		if (!class_exists(\Redis::class)) {
			$this->markTestSkipped('phpredis not available');
		}
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
		$rTouch = ['type' => 'conn.touch', 't' => $this->rT0, 'd' => ['uuid' => 'aaaa', 'hls_last_read' => 150]];

		// A store that cannot be written: 503 DB, and the node keeps its touches for a retry.
		SettingsManager::set($this->rSettings + ['redis_handler' => 1]);
		$rInstance = new \ReflectionProperty(\XcVm\Infrastructure\Redis\RedisManager::class, 'instance');
		$rInstance->setValue(null, new \Redis()); // not connected
		(new \ReflectionProperty(\XcVm\Infrastructure\Redis\RedisManager::class, 'lastPingCheck'))->setValue(null, time());
		try {
			[$rRes, , $rReq] = $this->call('events', ['lane' => 'p2', 'events' => [$rTouch]], 1, $rKeys);
			$this->denial($rRes, 503, 'DB', $rReq);
		} finally {
			$rInstance->setValue(null, null);
			SettingsManager::set($this->rSettings);
		}

		// Events come only from an active node, on P2 too, while heartbeat
		// (open to a quarantined node) still lists what P2 takes.
		NodeRegistry::update(self::SID, ['state' => 'quarantined']);
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p2', 'events' => [$rTouch]], 1, $rKeys);
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(['conn.touch'], $this->reply($rRes, $rCtx, $rKeys)['p2_types']);
	}

	public function testRecordingCompleteCreatesTheVodOnceForTheNodesRecording(): void {
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int, `stream_display_name` text, `stream_source` text, `target_container` text, `year` text, `movie_properties` text, `rating` int, `read_native` int, `movie_symlink` int, `remove_subtitles` int, `transcode_profile_id` int, `order` int, `added` int, `category_id` text)');
		$this->rDb->exec('CREATE TABLE `recordings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `created_id` int, `category_id` text, `bouquets` text, `title` text, `description` text, `start` int, `end` int, `source_id` int, `status` int)');
		$this->rDb->exec("INSERT INTO `recordings` VALUES (1, NULL, '[]', '[]', 'Match', '', 1800000000, 1800003600, 5, 1), (2, NULL, '[]', '[]', 'Theirs', '', 1800000000, 1800003600, 6, 1)");
		$rKeys = $this->active();

		[$rRes, , $rReq] = $this->call('recording_complete', ['recording_id' => 1, 'stream_icon' => null], 1, $rKeys);
		$this->assertSame('content', $this->denial($rRes, 409, 'FLOW_OFF', $rReq)['flow']);

		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_CONTENT]);
		[$rRes, $rCtx] = $this->call('recording_complete', ['recording_id' => 1, 'stream_icon' => null], 1, $rKeys);
		$rID = $this->reply($rRes, $rCtx, $rKeys)['stream_id'];
		[$rRes, $rCtx] = $this->call('recording_complete', ['recording_id' => 1], 1, $rKeys);
		$this->assertSame($rID, $this->reply($rRes, $rCtx, $rKeys)['stream_id'], 'a retry gets the same VOD');

		[$rRes, , $rReq] = $this->call('recording_complete', ['recording_id' => 2], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
	}

	public function testRootCommandsNeedTheNodesRootPin(): void {
		$rKeys = $this->active();
		SettingsManager::set($this->rSettings);
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS]);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			$this->assertSame([false, false], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reload_nginx']), 'no pin reported: the signals table');
			// The agent reports its root pin in a heartbeat.
			[$rRes, $rCtx] = $this->call('heartbeat', ['root_ready' => true], 1, $rKeys);
			$this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['root_ready']);
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reload_nginx']));
			$rDoc = json_decode(\XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0)[0]['doc'], true);
			$this->assertSame(['node.root', 'reload_nginx', []], [$rDoc['type'], $rDoc['action'], $rDoc['args']]);
			$this->assertSame(86400, $rDoc['exp'] - $rDoc['iat'], 'root commands live a day');
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	/** A node in mode 2 reads no signals row: a root action it cannot take is not queued at all. */
	public function testARootActionANodeInModeTwoCannotTakeIsNotQueued(): void {
		$this->active();
		SettingsManager::set($this->rSettings);
		\XcVm\Domain\Cluster\ClusterRoute::useCrypto(fn() => $this->rCrypto);
		try {
			NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS, 'root_ready' => 0]);
			$this->assertSame([false, false], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reboot']), 'mode 1 still reads the signals table');

			NodeRegistry::update(self::SID, ['mode' => 2]);
			$this->assertSame([true, false], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reboot']), 'mode 2 without the pin');

			NodeRegistry::update(self::SID, ['root_ready' => 1, 'state' => 'quarantined']);
			$this->assertSame([true, false], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reboot']), 'mode 2, quarantined');
			$this->assertSame([], \XcVm\Domain\Cluster\CommandBus::pending(self::SID, 0), 'nothing queued');
			$this->assertFalse(\XcVm\Core\Cluster\NodeActions::reboot(self::SID), 'the caller hears it was not sent');

			NodeRegistry::update(self::SID, ['state' => 'active']);
			$this->assertSame([true, true], \XcVm\Domain\Cluster\ClusterRoute::root(self::SID, ['action' => 'reboot']));
		} finally {
			\XcVm\Domain\Cluster\ClusterRoute::useCrypto(null);
		}
	}

	// ── config: the node replica ─────────────────────────────────────────

	/** Open a replica record as the agent does: sealed to its box key, panel-signed. */
	private function openRecord(string $rB64, string $rTag): array {
		$rBody = Seal::open($this->rNodeBoxSk, 'replica', $this->rUuid, (string) base64_decode($rB64));
		$this->assertNotNull($rBody, 'sealed to this node');
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rPayload = substr($rBody, 4, $rLen);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], $rTag, $rPayload, substr($rBody, 4 + $rLen)), $rTag . ' signature');
		return json_decode($rPayload, true);
	}

	private function blocklistTables(): void {
		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/034_create_cluster_changes.sql'));
		$this->rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39), `notes` text, `date` int)');
		$this->rDb->exec('CREATE TABLE `blocked_uas` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_agent` varchar(255), `exact_match` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `blocked_isps` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `isp` text, `blocked` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `blocked_asns` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `asn` int, `blocked` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `rtmp_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(255), `password` varchar(128), `push` int, `pull` int)');
	}

	private function block(string $rIP, bool $rOn = true): void {
		$this->rDb->query($rOn ? 'INSERT INTO `blocked_ips` (`ip`) VALUES (?)' : 'DELETE FROM `blocked_ips` WHERE `ip` = ?', $rIP);
		$rOn ? BlocklistChanges::set('ip', [$rIP]) : BlocklistChanges::del('ip', [$rIP]);
	}

	public function testConfigServesTheBlocklistAsASectionThenAsDeltas(): void {
		$this->blocklistTables();
		$rKeys = $this->active();
		$this->block('203.0.113.1');

		// A new node: the whole section, for it alone.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
		$rDoc = $this->openRecord($rOut['section']['sealed'], 'rep');
		$this->assertSame(['blocklist', $this->rUuid, 1, $rOut['section']['etag'], $rOut['seq']], [$rDoc['section'], $rDoc['node'], $rDoc['gen'], $rDoc['etag'], $rDoc['seq']]);
		$this->assertSame(['203.0.113.1'], $rDoc['data']['ip']);
		$this->assertSame($rOut['section']['etag'], ReplicaBuilder::etag($rDoc['data']));

		// Then only what changed, as a blk delta.
		$this->block('203.0.113.2');
		$this->block('203.0.113.1', false);
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rOut['seq']], 1, $rKeys);
		$rNext = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
		$this->assertArrayNotHasKey('section', $rNext);
		$this->assertSame(['v' => 1, 'seq' => $rNext['seq'], 'iat' => $rDoc['iat'], 'add' => ['203.0.113.2'], 'remove' => ['203.0.113.1']], $this->openRecord($rNext['delta'], 'blk'));

		// Nothing new: nothing sent.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rNext['seq']], 1, $rKeys);
		$this->assertSame(['seq' => $rNext['seq'], 'more' => false], $this->reply($rRes, $rCtx, $rKeys)['blocklist']);

		// A hand edit of another kind: the section again, unless the node holds it.
		$this->rDb->exec("INSERT INTO `blocked_uas` (`user_agent`) VALUES ('curl')");
		BlocklistChanges::set('ua', [1]);
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rNext['seq']], 1, $rKeys);
		$rSec = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
		$this->assertSame('curl', $this->openRecord($rSec['section']['sealed'], 'rep')['data']['ua'][0]['user_agent']);
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rNext['seq'], 'have' => ['blocklist' => $rSec['section']['etag']], 'blocklist_parts' => true], 1, $rKeys);
		$this->assertSame(['seq' => $rSec['seq'], 'more' => false, 'unchanged' => true], $this->reply($rRes, $rCtx, $rKeys)['blocklist']);
		// An older agent keeps the deltas it stored over a section it is told is unchanged: it gets the section again.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rNext['seq'], 'have' => ['blocklist' => $rSec['section']['etag']]], 1, $rKeys);
		$this->assertSame($rSec['section']['etag'], $this->reply($rRes, $rCtx, $rKeys)['blocklist']['section']['etag']);

		[$rRes] = $this->call('config', ['blocklist_since' => -1], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST');
	}

	public function testWithoutALicenceBansStillReachTheNodeButNothingElse(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence is switched off in the fake only');
		}
		$this->blocklistTables();
		$rKeys = $this->active();
		$this->block('203.0.113.1');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$rSeq = $this->reply($rRes, $rCtx, $rKeys)['blocklist']['seq'];
		$this->rCrypto->rLicensed = false;

		$this->block('203.0.113.2');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rSeq], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
		$this->assertSame(['203.0.113.2'], $this->openRecord($rOut['delta'], 'blk')['add'], 'a ban only restricts');

		$this->block('203.0.113.2', false);
		[$rRes] = $this->call('config', ['blocklist_since' => $rOut['seq']], 1, $rKeys);
		$this->denial($rRes, 403, 'LICENCE_INVALID');
	}

	public function testWithoutALicenceWholeSectionsAreLeftOutAndBansStillArrive(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence is switched off in the fake only');
		}
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `seg_time` int)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 6)");
		$rKeys = $this->active();
		$this->block('203.0.113.1');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->rCrypto->rLicensed = false;

		// A whole section grants, so it cannot be signed: the node keeps what it
		// holds, and the ban in the same call still reaches it.
		$this->rDb->exec('UPDATE `settings` SET `seg_time` = 8');
		$this->block('203.0.113.2');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rOut['blocklist']['seq'], 'have' => ['settings' => $rOut['settings']['etag'], 'servers' => '']], 1, $rKeys);
		$rNext = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(['203.0.113.2'], $this->openRecord($rNext['blocklist']['delta'], 'blk')['add']);
		$this->assertArrayNotHasKey('settings', $rNext, 'left out: not a malformed section today\'s agent would stop on');
		$this->assertArrayNotHasKey('servers', $rNext);
	}

	public function testAWholeSectionRefusedForAnyReasonButTheLicenceDeniesTheCall(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the fake alone refuses to sign on demand');
		}
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `seg_time` int)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 6)");
		$rKeys = $this->active();
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$rBlocklist = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
		$rAsk = ['blocklist_since' => $rBlocklist['seq'], 'have' => ['blocklist' => $rBlocklist['section']['etag'], 'settings' => '']];

		// The blocklist is held (nothing to sign): only the settings section is refused.
		foreach (['CLOCK' => [503, 'CLOCK'], 'REVOKED' => [403, 'NODE_REVOKED']] as $rReason => [$rStatus, $rDenial]) {
			$this->rCrypto->rRefuseSign = $rReason;
			[$rRes, , $rReq] = $this->call('config', $rAsk, 1, $rKeys);
			$this->denial($rRes, $rStatus, $rDenial, $rReq);
		}
		$this->rCrypto->rRefuseSign = 'LICENCE';
		[$rRes, $rCtx] = $this->call('config', $rAsk, 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertTrue($rOut['blocklist']['unchanged']);
		$this->assertArrayNotHasKey('settings', $rOut, 'only a licence refusal leaves the section out');
	}

	public function testConnAdmitAdmitsForTheAuthenticatedNodeFromMainsOwnLine(): void {
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `max_connections` int, `pair_id` int, `enabled` int, `admin_enabled` int, `exp_date` int)');
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `server_id` int, `user_id` int, `hmac_id` int, `hmac_identifier` text, `hls_end` int DEFAULT 0)');
		$this->rDb->query('INSERT INTO `lines` VALUES (42, 1, NULL, 1, 1, NULL), (50, 1, NULL, 1, 0, NULL)');
		$rDir = sys_get_temp_dir() . '/xcvm-api-admit-' . bin2hex(random_bytes(4)) . '/';
		\XcVm\Domain\Cluster\ConnectionLimits::useQueue($rDir);
		$rCuts = [];
		\XcVm\Domain\Cluster\ConnectionAdmission::useEnforcer(static function (?int $rLine, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP, ?string $rUA, ?string $rUUID) use (&$rCuts): void {
			$rCuts[] = [$rLine, $rRoom, $rIP, $rUUID];
		});
		SettingsManager::set($this->rSettings + ['redis_handler' => 0]);
		try {
			$rKeys = $this->active();
			$rUUID = str_repeat('a', 32);
			$rAsk = ['uuid' => $rUUID, 'line_id' => 42, 'stream_id' => 100, 'ip' => '203.0.113.9', 'ua' => 'VLC', 'max_connections' => 50];

			// Only a node that holds its viewers asks.
			[$rRes, , $rReq] = $this->call('conn_admit', $rAsk, 1, $rKeys);
			$this->assertSame('connections', $this->denial($rRes, 409, 'FLOW_OFF', $rReq)['flow']);
			NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);

			// Nothing happens without a good MAC.
			[$rRes, , $rReq] = $this->call('conn_admit', $rAsk, 1, $rKeys, ['body' => static fn($b) => substr($b, 0, -1) . chr(ord(substr($b, -1)) ^ 1)]);
			$this->denial($rRes, 401, 'BAD_MAC', $rReq);
			$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations`');
			$this->assertSame(0, (int) $this->rDb->get_row()['n']);

			[$rRes, $rCtx] = $this->call('conn_admit', $rAsk, 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame([true, intdiv($this->rT0, 1000) + 5 + 10], [$rOut['admit'], $rOut['exp']], 'admitted until the reservation expires');
			$this->assertArrayNotHasKey('reason', $rOut);

			// A retry (the same viewer again) gets the same answer and keeps one reservation.
			[$rRes, $rCtx] = $this->call('conn_admit', $rAsk, 1, $rKeys);
			$this->assertSame($rOut['exp'], $this->reply($rRes, $rCtx, $rKeys)['exp']);
			$this->rDb->query('SELECT `server_id`, `identity`, `stream_id` FROM `cluster_reservations` WHERE `id` = ?', $rUUID);
			$rRows = $this->rDb->get_rows();
			$this->assertCount(1, $rRows);
			$this->assertSame([self::SID, '42', 100], [(int) $rRows[0]['server_id'], (string) $rRows[0]['identity'], (int) $rRows[0]['stream_id']], 'the reservation is the authenticated node\'s');

			// The cut goes by `lines` (limit 1), never by the node's 50, and spares the viewer.
			$this->assertSame(2, \XcVm\Domain\Cluster\ConnectionLimits::drain());
			$this->assertSame([[42, 0, '203.0.113.9', $rUUID], [42, 0, '203.0.113.9', $rUUID]], $rCuts);

			// A line auth.php would refuse is refused; a malformed request is a bad request.
			[$rRes, $rCtx] = $this->call('conn_admit', ['uuid' => str_repeat('b', 32), 'line_id' => 50] + $rAsk, 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame([false, 0, 'BANNED'], [$rOut['admit'], $rOut['exp'], $rOut['reason']]);
			[$rRes, , $rReq] = $this->call('conn_admit', ['line_id' => '42'] + $rAsk, 1, $rKeys);
			$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);

			// A line MAIN cannot read is not a refusal: the agent's offline policy decides.
			$this->rDb->exec('ALTER TABLE `lines` RENAME TO `lines_gone`');
			[$rRes, , $rReq] = $this->call('conn_admit', ['uuid' => str_repeat('c', 32)] + $rAsk, 1, $rKeys);
			$this->denial($rRes, 503, 'DB', $rReq);
			$this->rDb->exec('ALTER TABLE `lines_gone` RENAME TO `lines`');

			// Only an active node.
			NodeRegistry::update(self::SID, ['state' => 'quarantined']);
			[$rRes, , $rReq] = $this->call('conn_admit', $rAsk, 1, $rKeys);
			$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
		} finally {
			\XcVm\Domain\Cluster\ConnectionLimits::useQueue(null);
			\XcVm\Domain\Cluster\ConnectionAdmission::useEnforcer(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testRtmpAuthChecksAViewersLineOnMain(): void {
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `username` varchar(64), `password` varchar(64), `access_token` varchar(32), `is_mag` int DEFAULT 0, `is_e2` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `mag_devices` (`user_id` int, `token` text)');
		$rLog = sys_get_temp_dir() . '/xcvm-api-rtmp-' . bin2hex(random_bytes(4)) . '.log';
		$rCounter = (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_rtmp/' . self::SID;
		@unlink($rCounter);
		$rWas = DatabaseLogger::getLogFile();
		DatabaseLogger::setLogFile($rLog);
		try {
			$rKeys = $this->active();
			$rAsk = ['stream_id' => 100, 'ip' => '203.0.113.9', 'restream' => false, 'username' => 'nobody', 'password' => 'guess'];

			// Credentials that name no line: refused, and logged on MAIN as rtmp.php logs them there.
			[$rRes, $rCtx] = $this->call('rtmp_auth', $rAsk, 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame([false, 'AUTH_FAILED'], [$rOut['ok'], $rOut['reason']]);
			$rLogged = json_decode((string) base64_decode(trim((string) file_get_contents($rLog))), true);
			$this->assertSame([100, 0, 'AUTH_FAILED', '203.0.113.9'], [$rLogged['stream_id'], $rLogged['user_id'], $rLogged['action'], $rLogged['user_ip']]);

			// What the node says is checked before anything runs.
			$rNoCredentials = $rAsk;
			unset($rNoCredentials['username'], $rNoCredentials['password']);
			foreach ([['stream_id' => '100'] + $rAsk, ['stream_id' => 0] + $rAsk, ['ip' => 'nowhere'] + $rAsk, ['restream' => 1] + $rAsk, ['username' => ['nobody']] + $rAsk, ['token' => str_repeat('a', 513)] + $rAsk, ['uuid' => 'not a uuid'] + $rAsk, ['uuid' => 42] + $rAsk, $rNoCredentials] as $rBad) {
				[$rRes, , $rReq] = $this->call('rtmp_auth', $rBad, 1, $rKeys);
				$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
			}

			// A node's failed checks have a budget: past it, MAIN checks none for the node this minute.
			file_put_contents($rCounter, intdiv(ClusterClock::nowMs(), 60000) . ' ' . (ClusterApi::RTMP_FAIL_BUDGET - 1));
			[$rRes, $rCtx] = $this->call('rtmp_auth', $rAsk, 1, $rKeys);
			$this->assertSame('AUTH_FAILED', $this->reply($rRes, $rCtx, $rKeys)['reason'], 'the budget\'s last check');
			$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = 'rtmp.auth_budget'");
			$this->assertSame(1, (int) $this->rDb->get_row()['n']);
			$rLogged = count(file($rLog));
			[$rRes, $rCtx] = $this->call('rtmp_auth', $rAsk, 1, $rKeys);
			$this->assertSame([false, 'REFUSED'], [$this->reply($rRes, $rCtx, $rKeys)['ok'], $this->reply($rRes, $rCtx, $rKeys)['reason']]);
			$this->assertCount($rLogged, file($rLog), 'not checked: nothing logged');
			unlink($rCounter);

			// A line MAIN cannot read is no answer: the node refuses the viewer.
			$this->rDb->exec('ALTER TABLE `lines` RENAME TO `lines_gone`');
			[$rRes, , $rReq] = $this->call('rtmp_auth', $rAsk, 1, $rKeys);
			$this->denial($rRes, 503, 'DB', $rReq);
			$this->rDb->exec('ALTER TABLE `lines_gone` RENAME TO `lines`');

			// Only an active node.
			NodeRegistry::update(self::SID, ['state' => 'quarantined']);
			[$rRes, , $rReq] = $this->call('rtmp_auth', $rAsk, 1, $rKeys);
			$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
		} finally {
			DatabaseLogger::setLogFile($rWas);
			@unlink($rLog);
			@unlink($rCounter);
		}
	}

	public function testHelloAndHeartbeatCarryTheOfflineAdmissionPolicy(): void {
		$rKeys = $this->active();
		[$rRes, $rCtx] = $this->call('hello', [], 1, $rKeys);
		$this->assertSame('local', $this->reply($rRes, $rCtx, $rKeys)['offline_admission'], 'the default');

		$this->rSettings['lb_offline_admission'] = 'deny';
		[$rRes, $rCtx] = $this->call('hello', [], 1, $rKeys);
		$this->assertSame('deny', $this->reply($rRes, $rCtx, $rKeys)['offline_admission']);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame('deny', $this->reply($rRes, $rCtx, $rKeys)['offline_admission'], 'a change reaches the node within a heartbeat');

		$this->rSettings['lb_offline_admission'] = 'maybe';
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame('local', $this->reply($rRes, $rCtx, $rKeys)['offline_admission']);
	}

	public function testConfigServesTheSettingsSectionToAnAgentThatAsks(): void {
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `api_pass` text, `seg_time` int)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'secret', 6)");
		$rKeys = $this->active();

		// An agent that predates it is not sent the section.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$this->assertArrayNotHasKey('settings', $this->reply($rRes, $rCtx, $rKeys));

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys)['settings'];
		$rDoc = $this->openRecord($rOut['sealed'], 'rep');
		$this->assertSame(['settings', $this->rUuid, $rOut['etag']], [$rDoc['section'], $rDoc['node'], $rDoc['etag']]);
		$this->assertSame(['id' => '1', 'seg_time' => '6', 'server_name' => 'XC'], $rDoc['data'], 'never the secret');

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => $rOut['etag']]], 1, $rKeys);
		$this->assertSame(['unchanged' => true], $this->reply($rRes, $rCtx, $rKeys)['settings']);

		[$rRes] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => 'nope']], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST');
	}

	public function testConfigServesTheServersNodeCrontabAndClusterSectionsByEtag(): void {
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `api_pass` text, `cloudflare` int, `mag_legacy_redirect` int)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'secret', 1, 0)");
		$this->rDb->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `filename` varchar(255), `time` varchar(128), `enabled` int, `role` varchar(8))');
		$this->rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('streams', '* * * * *', 1, 'all'), ('tmdb', '0 * * * *', 1, 'main')");
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `server_ip` varchar(255)');
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `http_broadcast_port` int');
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `watchdog_data` text');
		$this->rDb->exec("UPDATE `servers` SET `server_ip` = '10.0.0.5', `http_broadcast_port` = 8080, `watchdog_data` = '{\"cpu\":1}'");
		$rKeys = $this->active();
		$rNew = ['servers', 'node', 'crontab', 'cluster'];

		// Today's agent names only the blocklist and settings: none of the new sections.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['blocklist' => '', 'settings' => '']], 1, $rKeys);
		$this->assertSame([], array_values(array_intersect($rNew, array_keys($this->reply($rRes, $rCtx, $rKeys)))));

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => array_fill_keys($rNew, '')], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$rHave = [];
		$rData = [];
		foreach ($rNew as $rSection) {
			$rDoc = $this->openRecord($rOut[$rSection]['sealed'], 'rep');
			$this->assertSame([$rSection, $this->rUuid, 1, $rOut[$rSection]['etag']], [$rDoc['section'], $rDoc['node'], $rDoc['gen'], $rDoc['etag']], $rSection);
			$this->assertSame($rOut[$rSection]['etag'], ReplicaBuilder::etag($rDoc['data']), $rSection);
			$this->assertStringNotContainsString('secret', (string) json_encode($rDoc['data']), $rSection);
			$this->assertStringNotContainsString('watchdog', (string) json_encode($rDoc['data']), $rSection);
			$rHave[$rSection] = $rOut[$rSection]['etag'];
			$rData[$rSection] = $rDoc['data'];
		}
		$this->assertSame([5, '10.0.0.5', 8080], [$rData['servers']['servers'][0]['id'], $rData['servers']['servers'][0]['server_ip'], $rData['servers']['servers'][0]['http_broadcast_port']]);
		$this->assertSame([['box_pub' => base64_encode((string) NodeRegistry::byServer(self::SID)['node_box_pub']), 'dataplane' => false, 'ed_pub' => base64_encode((string) NodeRegistry::byServer(self::SID)['node_sign_pub']), 'gen' => 1, 'sid' => 5, 'state' => 'active']], $rData['servers']['nodes'], 'keys sorted, as every level of a section');
		$this->assertSame([5, 8080, 1], [$rData['node']['id'], $rData['node']['http_broadcast_port'], $rData['node']['cloudflare']]);
		$this->assertSame([['filename' => 'streams', 'time' => '* * * * *']], $rData['crontab']['jobs'], 'the node\'s mode (1): never a main row');
		$this->assertSame(ClusterPolicy::current($this->rSettings, $this->rMain)['main_urls'], $rData['cluster']['main_urls']);
		$this->assertSame(base64_encode($this->rCrypto->info()['panel_sign_pub']), $rData['cluster']['panel_sign_pub']);

		// Held: unchanged, each on its own ETag.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => $rHave], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		foreach ($rNew as $rSection) {
			$this->assertSame(['unchanged' => true], $rOut[$rSection], $rSection);
		}
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['node' => $rHave['servers']]], 1, $rKeys);
		$this->assertArrayHasKey('sealed', $this->reply($rRes, $rCtx, $rKeys)['node'], 'another section\'s ETag is not this one\'s');
	}

	public function testConfigServesTheBouquetsAndCategoriesByEtagWithinWhatTheAgentReads(): void {
		$this->blocklistTables();
		$this->rDb->exec(InstallSchema::table('bouquets'));
		$this->rDb->exec(InstallSchema::table('streams_categories'));
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`, `bouquet_order`) VALUES (1, 'Sports', '[1,2]', '[]', '[]', '[]', 0), (2, 'News', '[3]', '[]', '[]', '[9]', 1)");
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES (4, 'live', 'News', 0, 1, 0)");
		$rKeys = $this->active();
		$rNew = [ReplicaSections::BOUQUETS, ReplicaSections::CATEGORIES];

		// Today's agent names neither.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['blocklist' => '']], 1, $rKeys);
		$this->assertSame([], array_values(array_intersect($rNew, array_keys($this->reply($rRes, $rCtx, $rKeys)))));

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => array_fill_keys($rNew, '')], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$rHave = [];
		$rData = [];
		foreach ($rNew as $rSection) {
			$rDoc = $this->openRecord($rOut[$rSection]['sealed'], 'rep');
			$this->assertSame([$rSection, $this->rUuid, 1, $rOut[$rSection]['etag']], [$rDoc['section'], $rDoc['node'], $rDoc['gen'], $rDoc['etag']], $rSection);
			$this->assertSame($rOut[$rSection]['etag'], ReplicaBuilder::etag($rDoc['data']), $rSection);
			$rHave[$rSection] = $rOut[$rSection]['etag'];
			$rData[$rSection] = $rDoc['data'];
		}
		$this->assertSame([2, 1], array_column($rData['bouquets']['bouquets'], 'id'), 'in BouquetService\'s order: bouquet_order, 0 last');
		$this->assertSame(['bouquet_channels' => '[3]', 'bouquet_movies' => '[]', 'bouquet_name' => 'News', 'bouquet_order' => 1, 'bouquet_radios' => '[]', 'bouquet_series' => '[9]', 'id' => 2], $rData['bouquets']['bouquets'][0], 'every column, typed, keys sorted');
		$this->assertSame([['cat_order' => 1, 'category_name' => 'News', 'category_type' => 'live', 'id' => 4, 'is_adult' => 0, 'parent_id' => 0]], $rData['categories']['categories']);

		// Held: unchanged.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => $rHave], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		foreach ($rNew as $rSection) {
			$this->assertSame(['unchanged' => true], $rOut[$rSection], $rSection);
		}

		// Bouquets too large for the agent's reply (8 MiB, with everything else):
		// `too_large` with their ETag, so the node drops the bouquets it holds and
		// its readers take MAIN's database again; audited once; the rest of the
		// reply still arrives.
		$this->rDb->query("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_order`) VALUES (3, 'Everything', ?, 2)", '[' . implode(',', range(100000, 700000)) . ']');
		$rLarge = '';
		foreach ([1, 2] as $rTry) {
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => $rHave], 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$this->assertSame(['too_large', 'etag'], array_keys($rOut[ReplicaSections::BOUQUETS]), 'try ' . $rTry . ': no record');
			$this->assertTrue($rOut[ReplicaSections::BOUQUETS]['too_large']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rOut[ReplicaSections::BOUQUETS]['etag']);
			$this->assertNotSame($rHave[ReplicaSections::BOUQUETS], $rOut[ReplicaSections::BOUQUETS]['etag'], 'the section as it is now');
			$this->assertSame(['unchanged' => true], $rOut[ReplicaSections::CATEGORIES]);
			$this->assertArrayHasKey('blocklist', $rOut);
			$rLarge = $rOut[ReplicaSections::BOUQUETS]['etag'];
		}
		$this->rDb->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'replica.section_too_large'");
		$rRows = $this->rDb->get_rows();
		$this->assertCount(1, $rRows, 'once per ETag');
		$this->assertSame(['section' => 'bouquets', 'max' => ReplicaBuilder::MAX_WHOLE_BYTES], array_intersect_key(json_decode($rRows[0]['detail'], true), ['section' => 0, 'max' => 0]));
		$this->assertGreaterThan(ReplicaBuilder::MAX_WHOLE_BYTES, json_decode($rRows[0]['detail'], true)['bytes']);
		// The agent keeps that ETag, with no files: nothing is sealed again until the bouquets change.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => [ReplicaSections::BOUQUETS => $rLarge] + $rHave], 1, $rKeys);
		$this->assertSame(['unchanged' => true], $this->reply($rRes, $rCtx, $rKeys)[ReplicaSections::BOUQUETS]);
		// Smaller again: sent.
		$this->rDb->exec('DELETE FROM `bouquets` WHERE `id` = 3');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => [ReplicaSections::BOUQUETS => $rLarge] + $rHave], 1, $rKeys);
		$this->assertSame($rHave[ReplicaSections::BOUQUETS], $this->reply($rRes, $rCtx, $rKeys)[ReplicaSections::BOUQUETS]['etag']);

		// An agent that names neither catalogue section predates `too_large`: a
		// section past the bound is left out for it, as before.
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` mediumtext)');
		$this->rDb->query('INSERT INTO `settings` VALUES (1, ?)', str_repeat('x', 3500000));
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertArrayNotHasKey('settings', $rOut, 'today\'s agent would take a reply without a record as a malformed section');
		$this->assertArrayHasKey('blocklist', $rOut);
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '', ReplicaSections::CATEGORIES => '']], 1, $rKeys);
		$this->assertTrue($this->reply($rRes, $rCtx, $rKeys)['settings']['too_large']);
	}

	/**
	 * A section too large for one reply, to an agent that says `parts`: MAIN
	 * stages the sealed record once and serves it in 4 MiB parts, which join
	 * into the record the node opens as any section sent whole.
	 */
	/** Block $rCount IPv6 addresses written out in full (39 characters), past the first $rFrom: large sections from few rows. */
	private function manyBlocked(int $rFrom, int $rCount): void {
		foreach (array_chunk(range($rFrom, $rFrom + $rCount - 1), 10000) as $rChunk) {
			$this->rDb->exec('INSERT INTO `blocked_ips` (`ip`) VALUES ' . implode(', ', array_map(static fn(int $i): string => sprintf("('2001:0db8:0000:0000:0000:0000:%04x:%04x')", ($i >> 16) & 0xffff, $i & 0xffff), $rChunk)));
		}
	}

	/** A blocklist too large for one reply: in parts to an agent that says blocklist_parts, whole and then held to an older one. */
	public function testABlocklistTooLargeForOneReplyIsFetchedInParts(): void {
		$this->blocklistTables();
		$rKeys = $this->active();
		$this->manyBlocked(0, 80000);
		$rDir = sys_get_temp_dir() . '/xcvm-xfer-' . bin2hex(random_bytes(4)) . '/';
		ReplicaBuilder::useXferDir($rDir);
		$rPart = function (int $rN, string $rEtag) use ($rKeys): array {
			[$rRes, $rCtx] = $this->call('config', ['part' => ['section' => 'blocklist', 'etag' => $rEtag, 'n' => $rN]], 1, $rKeys);
			return $this->reply($rRes, $rCtx, $rKeys)['part'];
		};
		try {
			// An older agent: whole, while one reply carries it; nothing staged.
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true], 1, $rKeys);
			$rOld = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
			$this->assertGreaterThan(ReplicaBuilder::MAX_WHOLE_BYTES, strlen($rOld['section']['sealed']));
			$this->assertSame([], glob($rDir . '*') ?: []);

			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true, 'blocklist_parts' => true], 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys)['blocklist'];
			$this->assertSame(['too_large' => true, 'etag' => $rOld['section']['etag'], 'parts' => 2], $rOut['section']);
			$rFirst = $rPart(0, $rOut['section']['etag']);
			$this->assertSame(['section' => 'blocklist', 'etag' => $rOut['section']['etag'], 'n' => 0, 'parts' => 2], array_diff_key($rFirst, ['data' => 0]));
			$rDoc = $this->openRecord($rFirst['data'] . $rPart(1, $rOut['section']['etag'])['data'], 'rep');
			$this->assertSame(['blocklist', $rOut['section']['etag'], $rOut['seq']], [$rDoc['section'], $rDoc['etag'], $rDoc['seq']], 'the record names the seq the reply does');
			$this->assertCount(80000, $rDoc['data']['ip']);
			$this->assertSame([], glob($rDir . '*') ?: [], 'the last part served removes the stage');

			// Past what one reply carries, an older agent is held: no section, its seq unchanged.
			$this->manyBlocked(80000, 80000);
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true], 1, $rKeys);
			$this->assertSame(['seq' => 0, 'more' => false], $this->reply($rRes, $rCtx, $rKeys)['blocklist']);
			$rAudits = "SELECT COUNT(*) FROM `cluster_audit` WHERE `event` = 'replica.section_too_large' AND `detail` LIKE '%\"blocklist\"%'";
			$this->assertSame(2, (int) $this->rDb->pdo->query($rAudits)->fetchColumn(), 'one per ETag: the one sent in parts, and this one');
			$this->call('config', ['blocklist_since' => 0, 'parts' => true], 1, $rKeys);
			$this->assertSame(2, (int) $this->rDb->pdo->query($rAudits)->fetchColumn(), 'not again for the same ETag');
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true, 'blocklist_parts' => true], 1, $rKeys);
			$this->assertSame(3, $this->reply($rRes, $rCtx, $rKeys)['blocklist']['section']['parts']);
		} finally {
			ReplicaBuilder::useXferDir(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testASectionTooLargeForOneReplyIsFetchedInParts(): void {
		$this->blocklistTables();
		$this->rDb->exec(InstallSchema::table('bouquets'));
		$this->rDb->query("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_order`) VALUES (3, 'Everything', ?, 2)", '[' . implode(',', range(100000, 700000)) . ']');
		$rKeys = $this->active();
		$rDir = sys_get_temp_dir() . '/xcvm-xfer-' . bin2hex(random_bytes(4)) . '/';
		ReplicaBuilder::useXferDir($rDir);
		$rPart = function (array $rAsk) use ($rKeys): array {
			[$rRes, $rCtx] = $this->call('config', ['part' => $rAsk], 1, $rKeys);
			return $this->reply($rRes, $rCtx, $rKeys)['part'];
		};
		try {
			// An older agent: too_large alone, and nothing staged for it.
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => [ReplicaSections::BOUQUETS => '']], 1, $rKeys);
			$this->assertSame(['too_large', 'etag'], array_keys($this->reply($rRes, $rCtx, $rKeys)[ReplicaSections::BOUQUETS]));
			$this->assertSame([], glob($rDir . '*') ?: []);

			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true, 'have' => [ReplicaSections::BOUQUETS => '']], 1, $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys)[ReplicaSections::BOUQUETS];
			$this->assertTrue($rOut['too_large']);
			$this->assertSame(2, $rOut['parts']);
			$rEtag = $rOut['etag'];
			$rFirst = $rPart(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => 0]);
			$this->assertSame(ReplicaBuilder::PART_BYTES, strlen($rFirst['data']));
			$this->assertSame(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => 0, 'parts' => 2], array_diff_key($rFirst, ['data' => 0]));

			// The next poll finds it staged: not sealed again, so the parts still fit together.
			[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'parts' => true, 'have' => [ReplicaSections::BOUQUETS => '']], 1, $rKeys);
			$this->assertSame(['too_large' => true, 'etag' => $rEtag, 'parts' => 2], $this->reply($rRes, $rCtx, $rKeys)[ReplicaSections::BOUQUETS]);
			$this->assertSame($rFirst['data'], $rPart(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => 0])['data']);

			$rLast = $rPart(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => 1]);
			$rDoc = $this->openRecord($rFirst['data'] . $rLast['data'], 'rep');
			$this->assertSame([ReplicaSections::BOUQUETS, $rEtag], [$rDoc['section'], $rDoc['etag']], 'the parts join into the section\'s record');
			$this->assertSame([], glob($rDir . '*') ?: [], 'the last part served removes the stage');
			$this->assertSame(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'gone' => true], $rPart(['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => 0]));

			foreach ([
				['section' => 'streams', 'etag' => $rEtag, 'n' => 0],
				['section' => ReplicaSections::BOUQUETS, 'etag' => 'nope', 'n' => 0],
				['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => -1],
				['section' => ReplicaSections::BOUQUETS, 'etag' => $rEtag, 'n' => '0'],
				'bouquets',
			] as $rBad) {
				[$rRes] = $this->call('config', ['part' => $rBad], 1, $rKeys);
				$this->denial($rRes, 400, 'BAD_REQUEST');
			}
		} finally {
			ReplicaBuilder::useXferDir(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testConfigKeepsTheWholeReplyWithinWhatTheAgentReads(): void {
		$this->blocklistTables();
		$this->rDb->exec(InstallSchema::table('bouquets'));
		$this->rDb->exec(InstallSchema::table('streams_categories'));
		// Each section under the bound, together past the agent's 8 MiB, with a blocklist section to send too.
		$this->rDb->query("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (1, ?, 1)", str_repeat('b', 2900000));
		// Category names and user agents are varchar(255) in production: a section this big is many rows.
		$rFill = function (string $rTable, string $rColumns, callable $rRow, int $rBytes): void {
			for ($i = 1; ; $i += 500) {
				$this->rDb->query('SELECT * FROM `' . $rTable . '`');
				if (strlen((string) json_encode($this->rDb->get_rows())) >= $rBytes) {
					return;
				}
				$this->rDb->exec('INSERT INTO `' . $rTable . '` (' . $rColumns . ') VALUES ' . implode(', ', array_map($rRow, range($i, $i + 499))));
			}
		};
		$rFill('streams_categories', '`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`', static fn(int $n): string => "({$n}, 'live', '" . str_repeat('c', 250) . "', 0, {$n}, 0)", 2900000);
		$rFill('blocked_uas', '`user_agent`', static fn(int $n): string => "('" . str_pad((string) $n, 250, 'u') . "')", 700000);
		BlocklistChanges::set('ua', [1]);
		$rKeys = $this->active();
		$rNew = [ReplicaSections::BOUQUETS => '', ReplicaSections::CATEGORIES => ''];

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => $rNew], 1, $rKeys);
		$this->assertLessThanOrEqual(8 << 20, strlen($rRes['body']), 'the agent reads it');
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertArrayHasKey('section', $rOut['blocklist']);
		$this->assertLessThan(ReplicaBuilder::MAX_WHOLE_BYTES, strlen($rOut[ReplicaSections::BOUQUETS]['sealed']));
		$this->assertArrayNotHasKey(ReplicaSections::CATEGORIES, $rOut, 'no room left: the next poll has it');
		$this->assertLessThanOrEqual(ReplicaBuilder::MAX_REPLY, strlen((string) json_encode($rOut, JSON_UNESCAPED_SLASHES)));

		// The next poll: what the last one carried is unchanged, and the categories fit.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rOut['blocklist']['seq'], 'have' => ['blocklist' => $rOut['blocklist']['section']['etag'], ReplicaSections::BOUQUETS => $rOut[ReplicaSections::BOUQUETS]['etag'], ReplicaSections::CATEGORIES => '']], 1, $rKeys);
		$rNext = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(['unchanged' => true], $rNext[ReplicaSections::BOUQUETS]);
		$this->assertSame(ReplicaSections::CATEGORIES, $this->openRecord($rNext[ReplicaSections::CATEGORIES]['sealed'], 'rep')['section']);
		$this->assertLessThan(ReplicaBuilder::MAX_WHOLE_BYTES, strlen($rNext[ReplicaSections::CATEGORIES]['sealed']));
		$this->assertGreaterThan(8 << 20, strlen($rOut['blocklist']['section']['sealed']) + strlen($rOut[ReplicaSections::BOUQUETS]['sealed']) + strlen($rNext[ReplicaSections::CATEGORIES]['sealed']), 'all three in one reply: past what the agent reads');
		$this->assertSame([], array_values(array_diff(array_merge(ReplicaBuilder::WHOLE, [ReplicaSections::SECRETS]), ReplicaBuilder::REPLY_ORDER)), 'every section has its place in a reply');
		$this->assertSame([ReplicaSections::BOUQUETS, ReplicaSections::CATEGORIES], array_slice(ReplicaBuilder::REPLY_ORDER, -2), 'the catalogue last');
	}

	/** MAIN's previous OPENSSL_EXTRA: none, never the deploy root's file. */
	private function noPreviousExtra(): void {
		OpensslExtra::usePrevFile(sys_get_temp_dir() . '/xcvm-no-prev-' . bin2hex(random_bytes(4)));
	}

	public function testConfigServesTheSecretsSectionOnlyToANodeInModeOneOrTwo(): void {
		$this->noPreviousExtra();
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `api_pass` text, `live_streaming_pass` text)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'secret-api', 'secret-live')");
		$rKeys = $this->active();

		// Today's agent does not name it: never sent.
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['blocklist' => '', 'settings' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertArrayNotHasKey('secrets', $rOut);
		$this->assertStringNotContainsString('secret-live', (string) json_encode($this->openRecord($rOut['settings']['sealed'], 'rep')));

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys)['secrets'];
		$rDoc = $this->openRecord($rOut['sealed'], 'rep');
		$this->assertSame(['secrets', $this->rUuid, 1, $rOut['etag']], [$rDoc['section'], $rDoc['node'], $rDoc['gen'], $rDoc['etag']]);
		$this->assertSame($rOut['etag'], ReplicaBuilder::etag($rDoc['data']));
		$this->assertSame(['live_streaming_pass', 'openssl_extra', ViewerKey::NAME], array_keys($rDoc['data']));
		$this->assertSame(['secret-live', OPENSSL_EXTRA], [$rDoc['data']['live_streaming_pass']['current'], $rDoc['data']['openssl_extra']['current']]);
		$this->assertSame(ViewerKey::entry('secret-live', self::SID, null), $rDoc['data'][ViewerKey::NAME], 'the node\'s own viewer key');
		$this->assertStringNotContainsString('secret-api', (string) json_encode($rDoc));

		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => $rOut['etag']]], 1, $rKeys);
		$this->assertSame(['unchanged' => true], $this->reply($rRes, $rCtx, $rKeys)['secrets']);

		// A legacy node (mode 0) keeps MAIN's database: left out, the rest still served.
		$this->rDb->query('UPDATE `cluster_nodes` SET `mode` = 0 WHERE `server_id` = ?', self::SID);
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => '', 'settings' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertArrayNotHasKey('secrets', $rOut);
		$this->assertArrayHasKey('sealed', $rOut['settings']);

		[$rRes] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => 'nope']], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST');
	}

	public function testWithoutALicenceTheSecretsAreLeftOut(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence is switched off in the fake only');
		}
		$this->noPreviousExtra();
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `live_streaming_pass` text)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'secret-live')");
		$rKeys = $this->active();
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => '']], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->rCrypto->rLicensed = false;

		// The section grants (it opens MAIN-minted viewer tokens): a changed one is not signed.
		$this->rDb->exec("UPDATE `settings` SET `live_streaming_pass` = 'secret-new'");
		$rHave = ['blocklist' => $rOut['blocklist']['section']['etag'], 'secrets' => $rOut['secrets']['etag']];
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => $rOut['blocklist']['seq'], 'have' => $rHave], 1, $rKeys);
		$rNext = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertTrue($rNext['blocklist']['unchanged']);
		$this->assertArrayNotHasKey('secrets', $rNext);
	}

	/**
	 * The entry point opens MySQL at an op's first query: down there, the
	 * agent still gets a signed 503 DB, and MySQL is tried once.
	 */
	public function testADatabaseDownAtTheFirstQueryIsASigned503Db(): void {
		$rKeys = $this->active();
		$r = $this->request('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$rDown = new class (true) extends LazyDatabaseHandler {
			public int $rOpens = 0;

			public function db_connect(bool $migrate = false, ?bool $graceful = null) {
				$this->rOpens++;
				return false;
			}
		};
		NodeAuthCache::forget(self::SID);
		DatabaseFactory::set($rDown);
		try {
			$this->denial(ClusterApi::serve($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 503, 'DB');
		} finally {
			DatabaseFactory::set($this->rDb);
		}
		$this->assertSame(1, $rDown->rOpens);
		$rIndex = (string) file_get_contents(MAIN_HOME . 'Public/cluster/index.php');
		$this->assertStringContainsString('$db = new LazyDatabaseHandler(true);', $rIndex, 'the entry point opens nothing an op does not query');
	}

	public function testConfigAnswers503ForASectionMainCannotRead(): void {
		$this->noPreviousExtra();
		$this->blocklistTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `live_streaming_pass` text)');
		$rKeys = $this->active();

		// No settings row: never an empty section the node would take as its settings.
		[$rRes] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$this->denial($rRes, 503, 'DB');
		[$rRes] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => '']], 1, $rKeys);
		$this->denial($rRes, 503, 'DB');

		// An unset stream secret: never an entry with an empty `current`; the settings still go.
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', '')");
		[$rRes] = $this->call('config', ['blocklist_since' => 0, 'have' => ['secrets' => '']], 1, $rKeys);
		$this->denial($rRes, 503, 'DB');
		[$rRes, $rCtx] = $this->call('config', ['blocklist_since' => 0, 'have' => ['settings' => '']], 1, $rKeys);
		$this->assertSame('XC', $this->openRecord($this->reply($rRes, $rCtx, $rKeys)['settings']['sealed'], 'rep')['data']['server_name']);
	}

	// ── streams: the R2 section ──────────────────────────────────────────

	/**
	 * The stream tables, as an install has them, and a catalogue: node 5
	 * holds 10 (assigned, relayed on by 6), 12 (records its TV archive) and
	 * 13 (a recording of it scheduled there); 11 runs on 6 alone.
	 */
	private function streamsTables(): void {
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_types', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/034_create_cluster_changes.sql'));
		$this->rDb->exec("INSERT INTO `streams_types` VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0)");
		$this->rDb->exec("INSERT INTO `profiles` VALUES (7, 'hd', '{\"3\":{\"cmd\":\"-b:v 4M\"}}')");
		$this->rDb->exec("INSERT INTO `streams_arguments` VALUES (1, 'fetch', 'User Agent', 'shown in the form', 'http', 'user_agent', '-user_agent \"%s\"', 'text', 'VLC')");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `notes`, `transcode_profile_id`, `enable_transcode`, `tv_archive_server_id`, `tv_archive_duration`, `vframes_server_id`, `tv_archive_pid`, `order`) VALUES
			(10, 1, 'News', '[\"http://src.example/a\"]', 'secret-notes', 7, 1, 0, 0, 0, 0, 3),
			(11, 1, 'Other', '[\"http://src.example/b\"]', NULL, 0, 0, 0, 0, 0, 0, 4),
			(12, 1, 'Archive', '[\"http://src.example/c\"]', NULL, 0, 0, 5, 24, 0, 0, 5),
			(13, 1, 'Recorded', '[\"http://src.example/d\"]', NULL, 0, 0, 0, 0, 0, 0, 6)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`, `pid`) VALUES (1, 10, 5, NULL, 1, 0), (2, 10, 6, 5, 0, 0), (3, 11, 6, NULL, 0, 0), (4, 13, 6, NULL, 0, 0)');
		$this->rDb->exec("INSERT INTO `streams_options` (`id`, `stream_id`, `argument_id`, `value`) VALUES (1, 10, 1, 'curl/8')");
		$this->rDb->exec("INSERT INTO `recordings` (`id`, `stream_id`, `source_id`, `title`, `start`, `end`, `archive`, `status`) VALUES (1, 13, 5, 'Match', 100, 200, 0, 0), (2, 13, 6, 'Match on 6', 100, 200, 0, 0), (3, 10, 6, 'News on 6', 300, 400, 0, 0)");
		// Every holder's row at version 0, as migration 047 seeds an install's.
		foreach (['SELECT `server_id`, `stream_id`, 0, 0 FROM `streams_servers`', 'SELECT `tv_archive_server_id`, `id`, 0, 0 FROM `streams` WHERE `tv_archive_server_id` > 0', 'SELECT `source_id`, `stream_id`, 0, 0 FROM `recordings`'] as $rSelect) {
			$this->rDb->exec('REPLACE INTO `cluster_stream_ver` (`server_id`, `stream_id`, `ver`, `updated_at`) ' . $rSelect);
		}
		EventDispatcher::resetInstance();
		EventDispatcher::subscribe(StreamVersions::class);
		(new \ReflectionProperty(StreamRepository::class, 'db'))->setValue(null, null);
	}

	/** An active node whose agent keeps the streams section: the STREAMS flow on, and `streams` said at hello. */
	private function streamsNode(): array {
		$rKeys = $this->active();
		$this->rDb->query('UPDATE `cluster_nodes` SET `flows` = ? WHERE `server_id` = ?', NodeRegistry::FLOW_STREAMS, self::SID);
		$this->served('hello', ['instance_id' => 'inst-a', 'features' => ['hls_reaper', StreamReplica::FEATURE]], 1, $rKeys);
		return $rKeys;
	}

	/**
	 * Open a stream record as the agent does: sealed to this node, `rep`-signed,
	 * naming this node, its generation, the stream and the announced ETag.
	 *
	 * @param array{id: int, ver: int, etag: string, sealed: string} $rEntry
	 */
	private function openStream(array $rEntry): array {
		$rDoc = $this->openRecord($rEntry['sealed'], 'rep');
		$this->assertSame(['stream', $this->rUuid, 1, $rEntry['id'], $rEntry['ver'], $rEntry['etag']], [$rDoc['section'], $rDoc['node'], $rDoc['gen'], $rDoc['stream_id'], $rDoc['ver'], $rDoc['etag']]);
		$this->assertSame($rEntry['etag'], ReplicaBuilder::etag($rDoc['data']), 'the ETag is the data\'s hash');
		$this->assertSame(['children', 'options', 'profile', 'recordings', 'server', 'stream', 'tickets', 'type'], array_keys($rDoc['data']));
		return $rDoc;
	}

	/** @return array<int, string> the ETags of a reply's records, by stream */
	private function etags(array $rOut): array {
		return array_column($rOut['streams'], 'etag', 'id');
	}

	private const EVERY_STREAM = ['from' => 0, 'to' => 2147483647];

	/**
	 * Phase 8: with the node's DATAPLANE flow on, a record carries the relay
	 * and file tickets of the moment in its `tickets` slot, and a delta that
	 * asks carries the next epoch's. Neither moves the record's ETag (taken
	 * with the slot empty) or its version, so a refresh resends no record, and
	 * the node's stream cache entry (what its encoder starts from) does not
	 * change: no restart.
	 */
	public function testTicketsRideTheRecordAndTheDeltaWithoutMovingItsEtagOrVersion(): void {
		$this->streamsTables();
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `is_main` int NOT NULL DEFAULT 0');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`, `is_main`) VALUES (1, 0, 1)');
		// Node 5 relays stream 10 from MAIN, and reads 12's movie file from MAIN and its subtitles from server 8 (a legacy LB).
		$this->rDb->exec('UPDATE `streams_servers` SET `parent_id` = 1 WHERE `stream_id` = 10 AND `server_id` = 5');
		$this->rDb->exec("UPDATE `streams` SET `stream_source` = '[\"s:1:/media/movies/film.mkv\"]', `movie_subtitles` = '{\"location\":8,\"files\":[\"/subs/a.srt\"]}' WHERE `id` = 12");
		$rKeys = $this->streamsNode();
		$this->rDb->query('UPDATE `cluster_nodes` SET `flows` = ? WHERE `server_id` = ?', NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT | NodeRegistry::FLOW_DATAPLANE, self::SID);
		\XcVm\Domain\Cluster\TicketService::forget();
		$rPanel = $this->rCrypto->info()['panel_sign_pub'];
		$rEpoch = \XcVm\Core\Cluster\DataPlane::epoch(intdiv($this->rT0, 1000));
		$rOpen = function (array $rEntry): array {
			$rDoc = $this->openRecord($rEntry['sealed'], 'rep');
			$this->assertSame($rEntry['etag'], $rDoc['etag']);
			$this->assertSame($rEntry['etag'], ReplicaBuilder::etag(['tickets' => null] + $rDoc['data']), 'the ETag is taken with the slot empty');
			return $rDoc['data'];
		};

		$rOut = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => new \stdClass()]], 1, $rKeys);
		$rData = array_combine(array_column($rOut['streams'], 'id'), array_map($rOpen, $rOut['streams']));
		$rRelay = \XcVm\Core\Cluster\Crypto\Ticket::verify($rPanel, 'rly', (string) $rData[10]['tickets']['relay'], intdiv($this->rT0, 1000));
		$this->assertSame(['child_gen' => 1, 'child_sid' => 5, 'parent_sid' => 1, 'stream_id' => 10, 'tid' => \XcVm\Core\Cluster\DataPlane::relayTid($rEpoch, 5, 10)], array_intersect_key((array) $rRelay, array_flip(['child_gen', 'child_sid', 'parent_sid', 'stream_id', 'tid'])));
		$this->assertNull($rData[10]['tickets']['files']);
		$rRef = \XcVm\Core\Cluster\DataPlane::ref(1, '/media/movies/film.mkv');
		$this->assertSame([$rRef], array_keys((array) $rData[12]['tickets']['files']), 'MAIN\'s file only: server 8 checks no ticket');
		$rFile = \XcVm\Core\Cluster\Crypto\Ticket::verify($rPanel, 'fil', $rData[12]['tickets']['files'][$rRef], intdiv($this->rT0, 1000));
		$this->assertSame([5, 1, 1, $rRef], [$rFile['fetcher_sid'], $rFile['fetcher_gen'], $rFile['owner_sid'], $rFile['ref']]);
		$this->assertStringNotContainsString('film', (string) json_encode($rFile), 'no path on the wire');
		$this->assertSame('/media/movies/film.mkv', $this->rCrypto->openLocal('xfile', (string) Enc::b64urlDecode(substr($rFile['file'], 2)), $rRef), 'sealed by MAIN to itself');
		$this->assertNull($rData[13]['tickets'], 'a stream that pulls nothing from another server');
		$rEtags = $this->etags($rOut);
		$this->rDb->query('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		$rVersions = $this->rDb->get_rows();

		// A node holding the previous epoch's asks on its delta: the current
		// epoch's come, with no record and no version moved.
		$rDelta = $this->served('streams', ['since' => 1, 'tickets' => ['epoch' => $rEpoch - 1, 'from' => 0]], 1, $rKeys);
		$this->assertSame([1, [], [], false], [$rDelta['ver'], $rDelta['streams'], $rDelta['removed'], $rDelta['more']], 'no version moved');
		$this->assertSame([$rEpoch, null], [$rDelta['tickets']['epoch'], $rDelta['tickets']['next']]);
		$this->assertSame(['10', '12'], array_map('strval', array_keys($rDelta['tickets']['streams'])));
		$this->assertSame($rData[10]['tickets']['relay'], $rDelta['tickets']['streams'][10]['relay'], 'the epoch\'s ticket, as the record has it');
		$this->rDb->query('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`');
		$this->assertSame($rVersions, $this->rDb->get_rows(), 'no version stamped');
		$this->assertArrayNotHasKey('tickets', $this->served('streams', ['since' => 1, 'tickets' => ['epoch' => $rEpoch, 'from' => 0]], 1, $rKeys), 'this epoch\'s held: nothing');
		// Paged: a node part-way through a refresh goes on from where it was.
		$rPage = $this->served('streams', ['since' => 1, 'tickets' => ['epoch' => $rEpoch, 'from' => 11]], 1, $rKeys)['tickets'];
		$this->assertSame(['12'], array_map('strval', array_keys($rPage['streams'])));

		// The next epoch's tickets differ, and change neither the ETag nor the
		// cache entry the node's encoder starts from.
		$rNode = NodeRegistry::byServer(self::SID);
		$rLater = \XcVm\Domain\Cluster\TicketService::forRecord($this->rCrypto, $rNode, ['tickets' => null] + $rData[10], intdiv($this->rT0, 1000) + \XcVm\Core\Cluster\DataPlane::EPOCH);
		$this->assertNotSame($rData[10]['tickets']['relay'], $rLater['relay']);
		$this->assertSame(\XcVm\Core\Cluster\DataPlane::relayTid($rEpoch + 1, 5, 10), \XcVm\Core\Cluster\Crypto\Ticket::verify($rPanel, 'rly', (string) $rLater['relay'], intdiv($this->rT0, 1000) + \XcVm\Core\Cluster\DataPlane::EPOCH)['tid']);
		$rTen = ['tickets' => $rLater] + $rData[10];
		$this->assertSame($rEtags[10], ReplicaBuilder::etag(['tickets' => null] + $rTen));
		$this->assertSame(
			\XcVm\Core\Cluster\ReplicaStreamCache::entry(10, $rData[10], 5, $rEtags[10], 0),
			\XcVm\Core\Cluster\ReplicaStreamCache::entry(10, $rTen, 5, $rEtags[10], 0),
			'the stream cache entry the encoder starts from is unchanged'
		);
		$this->assertSame([], $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => $rEtags]], 1, $rKeys)['streams'], 'no record resent for a ticket');

		// Without a licence nothing is minted: the refresh keeps the node's epoch.
		$this->rCrypto->rLicensed = false;
		$rRefused = $this->served('streams', ['since' => 1, 'tickets' => ['epoch' => $rEpoch - 1, 'from' => 0]], 1, $rKeys)['tickets'];
		$this->assertSame([$rEpoch - 1, true, null], [$rRefused['epoch'], $rRefused['withheld'], $rRefused['next']]);
		$this->rCrypto->rLicensed = true;

		// A malformed ask is refused; the flow off, no tickets at all.
		[$rRes, , $rReq] = $this->call('streams', ['since' => 1, 'tickets' => ['epoch' => -1]], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
		$this->rDb->query('UPDATE `cluster_nodes` SET `flows` = ? WHERE `server_id` = ?', NodeRegistry::FLOW_STREAMS, self::SID);
		$this->assertArrayNotHasKey('tickets', $this->served('streams', ['since' => 1, 'tickets' => ['epoch' => 0, 'from' => 0]], 1, $rKeys));
		$rOff = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => new \stdClass()]], 1, $rKeys);
		$this->assertNull($rOpen($rOff['streams'][0])['tickets']);
		$this->assertSame($rEtags, $this->etags($rOff));
	}

	public function testStreamsGoOnlyToANodeWithTheFlowWhoseAgentSaysItKeepsThem(): void {
		$this->streamsTables();
		$rKeys = $this->active();
		[$rRes, , $rReq] = $this->call('streams', ['since' => 0], 1, $rKeys);
		$this->assertSame(['flow' => 'streams'], array_intersect_key($this->denial($rRes, 409, 'FLOW_OFF', $rReq), ['flow' => 0, 'feature' => 0]));

		// The flow alone: today's agent never says it keeps them.
		$this->rDb->query('UPDATE `cluster_nodes` SET `flows` = ? WHERE `server_id` = ?', NodeRegistry::FLOW_STREAMS, self::SID);
		[$rRes, , $rReq] = $this->call('streams', ['since' => 0], 1, $rKeys);
		$this->assertSame(['flow' => 'streams', 'feature' => 'streams'], array_intersect_key($this->denial($rRes, 409, 'FLOW_OFF', $rReq), ['flow' => 0, 'feature' => 0]));

		$this->served('hello', ['instance_id' => 'inst-a', 'features' => [StreamReplica::FEATURE]], 1, $rKeys);
		$this->assertTrue($this->served('streams', ['since' => 0], 1, $rKeys)['full']);
		$this->assertFalse(ClusterApi::readsMain(Canonical::PATH_PREFIX . 'streams'));
		$this->assertSame('cluster_ingest', ClusterPool::poolFor('streams'), 'the ingest pool, on the bulk lane');
		$this->assertSame(ClusterSemaphore::LANE_BULK, ClusterSemaphore::ingestLane('streams', ['since' => 0]));
	}

	public function testANewNodeTakesEveryStreamItHoldsThenOnlyWhatChanges(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();

		// Nothing held yet: every stream is checked, by range.
		$this->assertSame(['ver' => 0, 'head' => 1, 'more' => false, 'full' => true, 'streams' => [], 'removed' => []], array_diff_key($this->served('streams', ['since' => 0], 1, $rKeys), ['main_time_ms' => 0]));
		$rOut = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => new \stdClass()]], 1, $rKeys);
		$this->assertSame([10, 12, 13], array_column($rOut['streams'], 'id'), 'the node holds these three, never 11');
		$this->assertSame([0, 1, null, []], [$rOut['ver'], $rOut['head'], $rOut['next'], $rOut['removed']]);
		[$rTen, $rTwelve, $rThirteen] = array_map(fn(array $rEntry): array => $this->openStream($rEntry)['data'], $rOut['streams']);

		$this->assertSame(['enable_transcode' => 1, 'id' => 10, 'stream_display_name' => 'News', 'stream_source' => '["http://src.example/a"]', 'transcode_profile_id' => 7, 'type' => 1], array_intersect_key($rTen['stream'], array_flip(['id', 'type', 'stream_display_name', 'stream_source', 'transcode_profile_id', 'enable_transcode'])), 'typed, keys sorted');
		$this->assertEqualsCanonicalizing(array_keys(ReplicaSections::STREAM_FIELDS), array_keys($rTen['stream']), 'the carried columns, nothing else');
		$this->assertSame(['live' => 1, 'type_id' => 1, 'type_key' => 'live', 'type_name' => 'Live Streams', 'type_output' => 'live'], $rTen['type']);
		$this->assertSame(['profile_id' => 7, 'profile_name' => 'hd', 'profile_options' => '{"3":{"cmd":"-b:v 4M"}}'], $rTen['profile']);
		$this->assertSame([['argument_cat' => 'fetch', 'argument_cmd' => '-user_agent "%s"', 'argument_default_value' => 'VLC', 'argument_id' => 1, 'argument_key' => 'user_agent', 'argument_name' => 'User Agent', 'argument_type' => 'text', 'argument_wprotocol' => 'http', 'value' => 'curl/8']], $rTen['options']);
		$this->assertSame(['on_demand' => 1, 'parent_id' => null, 'server_id' => 5, 'server_stream_id' => 1, 'stream_id' => 10], $rTen['server']);
		$this->assertSame([6], $rTen['children'], 'server 6 relays it from this node');
		$this->assertSame([], $rTen['recordings'], 'another node\'s recording of it is not this node\'s');
		$this->assertNull($rTen['tickets'], 'Phase 8\'s relay and file tickets');
		$this->assertNull($rTwelve['server'], 'held for its archive, not run here');
		$this->assertSame(5, $rTwelve['stream']['tv_archive_server_id']);
		$this->assertSame([1], array_column($rThirteen['recordings'], 'id'));
		$this->assertSame('Match', $rThirteen['recordings'][0]['title']);

		// Held: nothing comes again, and a stream the node holds that it should not goes.
		$rHave = $this->etags($rOut) + [99 => str_repeat('a', 64)];
		$this->assertSame(['ver' => 0, 'head' => 1, 'next' => null, 'streams' => [], 'removed' => [99]], array_diff_key($this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => $rHave]], 1, $rKeys), ['main_time_ms' => 0]));
		// The pass done, the node takes the head it saw first as its cursor.
		$this->assertSame(['ver' => 1, 'head' => 1, 'more' => false, 'streams' => [], 'removed' => []], array_diff_key($this->served('streams', ['since' => 1], 1, $rKeys), ['main_time_ms' => 0]));

		// An edit: that stream alone, at its new version.
		$this->rDb->exec("UPDATE `streams` SET `stream_source` = '[\"http://src.example/a2\"]' WHERE `id` = 10");
		EventDispatcher::dispatch(new StreamsChangedEvent([10]));
		$rOut = $this->served('streams', ['since' => 1], 1, $rKeys);
		$this->assertSame([[10, 2]], array_map(static fn(array $rEntry): array => [$rEntry['id'], $rEntry['ver']], $rOut['streams']));
		$this->assertSame([2, false, []], [$rOut['ver'], $rOut['more'], $rOut['removed']]);
		$this->assertSame('["http://src.example/a2"]', $this->openStream($rOut['streams'][0])['data']['stream']['stream_source']);
		$rTenEtag = $rOut['streams'][0]['etag'];

		// What the node writes back moves no version and no ETag.
		StreamRowMerge::mergeNode(5, 10, ['pid' => 4242, 'stream_status' => 1, 'bitrate' => 3000]);
		ContentSink::workerPid(12, 'tv_archive', 77);
		$this->assertSame([], $this->served('streams', ['since' => 2], 1, $rKeys)['streams']);
		$rHave[10] = $rTenEtag;
		unset($rHave[99]);
		$this->assertSame([], $this->served('streams', ['since' => 2, 'resync' => self::EVERY_STREAM + ['hashes' => $rHave]], 1, $rKeys)['streams']);

		// Taken off this node: a removal.
		StreamRepository::deleteStreamsByServer([10], 5);
		$rOut = $this->served('streams', ['since' => 2], 1, $rKeys);
		$this->assertSame([[], [10], 3], [$rOut['streams'], $rOut['removed'], $rOut['ver']]);
	}

	public function testAHashResyncSendsOnlyTheStreamsThatDifferInPages(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		$rHave = $this->etags($this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => []]], 1, $rKeys));

		// A write that bumps nothing (catalogue metadata): the section hashes catch it.
		$this->rDb->exec("UPDATE `streams` SET `stream_display_name` = 'News at ten' WHERE `id` = 10");
		$rHave[12] = str_repeat('0', 64);
		$rOut = $this->served('streams', ['since' => 1, 'resync' => ['from' => 10, 'to' => 12, 'hashes' => array_intersect_key($rHave, [10 => 0, 12 => 0]) + [11 => str_repeat('b', 64)]]], 1, $rKeys);
		$this->assertSame([10, 12], array_column($rOut['streams'], 'id'), 'only those that differ, within the range');
		$this->assertSame([[11], null, 1], [$rOut['removed'], $rOut['next'], $rOut['ver']], 'the cursor stays the node\'s');
		$this->assertSame('News at ten', $this->openStream($rOut['streams'][0])['data']['stream']['stream_display_name']);

		// More than a reply holds: in pages, each naming where to go on from.
		$rValues = [];
		for ($i = 0; $i < StreamReplica::MAX_RECORDS + 5; $i++) {
			$rValues[] = '(' . (1000 + $i) . ", 2, '[]')";
		}
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES ' . implode(', ', $rValues));
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) SELECT `id`, 5, 0 FROM `streams` WHERE `id` >= 1000');
		$rOut = $this->served('streams', ['since' => 1, 'resync' => ['from' => 100, 'to' => 2147483647, 'hashes' => []]], 1, $rKeys);
		$this->assertCount(StreamReplica::MAX_RECORDS, $rOut['streams']);
		$this->assertSame(1000 + StreamReplica::MAX_RECORDS, $rOut['next']);
		$rOut = $this->served('streams', ['since' => 1, 'resync' => ['from' => $rOut['next'], 'to' => 2147483647, 'hashes' => []]], 1, $rKeys);
		$this->assertSame(range(1000 + StreamReplica::MAX_RECORDS, 1004 + StreamReplica::MAX_RECORDS), array_column($rOut['streams'], 'id'));
		$this->assertNull($rOut['next']);

		// And a delta past a reply's worth: `more`, with the cursor where it stopped.
		EventDispatcher::dispatch(new StreamsChangedEvent(range(1000, 1004 + StreamReplica::MAX_RECORDS)));
		$rOut = $this->served('streams', ['since' => 1], 1, $rKeys);
		$this->assertCount(StreamReplica::MAX_RECORDS, $rOut['streams']);
		$this->assertTrue($rOut['more']);
		$this->assertSame(end($rOut['streams'])['ver'], $rOut['ver']);
		$rNext = $this->served('streams', ['since' => $rOut['ver']], 1, $rKeys);
		$this->assertSame(range(1000 + StreamReplica::MAX_RECORDS, 1004 + StreamReplica::MAX_RECORDS), array_column($rNext['streams'], 'id'));
		$this->assertFalse($rNext['more']);
	}

	public function testANodeGetsOnlyItsOwnStreamsSealedToItNeverAnotherNodesOrASecret(): void {
		$this->streamsTables();
		$this->rDb->exec('CREATE TABLE `settings` (`id` int, `live_streaming_pass` text, `api_pass` text)');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'secret-live', 'secret-api')");
		$this->peer(6, 'streams');
		$rKeys = $this->streamsNode();
		$rOut = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => []]], 1, $rKeys);
		$this->assertNotContains(11, array_column($rOut['streams'], 'id'));
		foreach ($rOut['streams'] as $rEntry) {
			$rDoc = $this->openStream($rEntry);
			$rJson = (string) json_encode($rDoc);
			foreach (['secret-live', 'secret-api', 'secret-notes', 'on 6', '"order"', 'tv_archive_pid'] as $rNever) {
				$this->assertStringNotContainsString($rNever, $rJson, $rEntry['id'] . ': ' . $rNever);
			}
			$this->assertNull(Seal::open(random_bytes(32), 'replica', $this->rUuid, (string) base64_decode($rEntry['sealed'])), 'opens with this node\'s key alone');
			$this->assertNull(Seal::open($this->rNodeBoxSk, 'replica', sprintf('00000000-0000-4000-a000-%012d', 6), (string) base64_decode($rEntry['sealed'])), 'and for this node alone');
			if ($rDoc['data']['server'] !== null) {
				$this->assertSame(5, $rDoc['data']['server']['server_id']);
			}
			foreach ($rDoc['data']['recordings'] as $rRecording) {
				$this->assertSame(5, $rRecording['source_id']);
			}
		}
		// A change to another node's stream reaches this node as nothing at all.
		EventDispatcher::dispatch(new StreamsChangedEvent([11]));
		$this->assertSame(['streams' => [], 'removed' => []], array_intersect_key($this->served('streams', ['since' => 1], 1, $rKeys), ['streams' => 0, 'removed' => 0]));
	}

	public function testWithoutALicenceChangedStreamsAreLeftOutAndRemovalsStillArrive(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence is switched off in the fake only');
		}
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		$rHave = $this->etags($this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => []]], 1, $rKeys));
		$this->rCrypto->rLicensed = false;

		// First a removal (13's recording here is gone), then an edit of 10.
		$this->rDb->exec('DELETE FROM `recordings` WHERE `id` = 1');
		EventDispatcher::dispatch(new StreamsChangedEvent([13]));
		$this->rDb->exec("UPDATE `streams` SET `stream_source` = '[]' WHERE `id` = 10");
		EventDispatcher::dispatch(new StreamsChangedEvent([10]));
		$rOut = $this->served('streams', ['since' => 1], 1, $rKeys);
		$this->assertSame([[], [13], 1, 2, false], [$rOut['streams'], $rOut['removed'], $rOut['withheld'], $rOut['ver'], $rOut['more']], 'the cursor stops before what could not be signed');
		$rOut = $this->served('streams', ['since' => 1, 'resync' => self::EVERY_STREAM + ['hashes' => $rHave]], 1, $rKeys);
		$this->assertSame([[], [13], 1], [$rOut['streams'], $rOut['removed'], $rOut['withheld']]);

		// Licensed again: the edit arrives from where the node stopped.
		$this->rCrypto->rLicensed = true;
		$rOut = $this->served('streams', ['since' => 2], 1, $rKeys);
		$this->assertSame([10], array_column($rOut['streams'], 'id'));
		$this->assertArrayNotHasKey('withheld', $rOut);

		// Any other refusal to sign denies the call.
		$this->rCrypto->rRefuseSign = 'CLOCK';
		[$rRes, , $rReq] = $this->call('streams', ['since' => 1], 1, $rKeys);
		$this->denial($rRes, 503, 'CLOCK', $rReq);
	}

	public function testAStreamsReadThatFailsAnswers503DbNeverAPartialSection(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		EventDispatcher::dispatch(new StreamsChangedEvent([10, 12, 13]));
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		foreach (['cluster_meta', 'cluster_stream_ver', 'FROM `streams_servers`', 'FROM `streams` ', 'streams_types', 'profiles', 'streams_options', 'recordings'] as $rTable) {
			$rLog->rRefuse = '/' . preg_quote($rTable, '/') . '/';
			foreach ([['since' => 1], ['since' => 1, 'resync' => self::EVERY_STREAM + ['hashes' => []]]] as $rAsk) {
				[$rRes, , $rReq] = $this->call('streams', $rAsk, 1, $rKeys);
				$this->denial($rRes, 503, 'DB', $rReq);
			}
		}
		$rLog->rRefuse = null;
		$this->assertCount(3, $this->served('streams', ['since' => 1], 1, $rKeys)['streams']);
	}

	public function testAMalformedStreamsRequestIsRefused(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		$rHash = str_repeat('c', 64);
		foreach ([
			[], ['since' => -1], ['since' => '1'], ['since' => 1.5],
			['since' => 0, 'resync' => []], ['since' => 0, 'resync' => ['from' => 5, 'to' => 4, 'hashes' => []]],
			['since' => 0, 'resync' => ['from' => -1, 'to' => 4, 'hashes' => []]], ['since' => 0, 'resync' => ['from' => 0, 'to' => 2147483648, 'hashes' => []]],
			['since' => 0, 'resync' => ['from' => 0, 'to' => 9, 'hashes' => [10 => $rHash]]], ['since' => 0, 'resync' => ['from' => 0, 'to' => 9, 'hashes' => [5 => 'nope']]],
			['since' => 0, 'resync' => ['from' => 0, 'to' => 9, 'hashes' => ['x' => $rHash]]], ['since' => 0, 'resync' => ['from' => 0, 'to' => 9, 'hashes' => 'all']],
			['since' => 0, 'resync' => ['from' => 0, 'to' => 9, 'hashes' => [$rHash]]], ['since' => 0, 'resync' => 'all'],
			['since' => 0, 'resync' => ['from' => 0, 'to' => 2147483647, 'hashes' => array_fill_keys(range(1, StreamReplica::MAX_HASHES + 1), $rHash)]],
		] as $rAsk) {
			[$rRes, , $rReq] = $this->call('streams', $rAsk, 1, $rKeys);
			$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
		}
	}

	public function testANodeBelowItsFloorChecksEveryStreamAgain(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		EventDispatcher::dispatch(new StreamsChangedEvent([10]));
		$this->assertArrayNotHasKey('full', $this->served('streams', ['since' => 1], 1, $rKeys));
		EventDispatcher::dispatch(StreamsChangedEvent::all());
		$this->assertTrue($this->served('streams', ['since' => 2], 1, $rKeys)['full'], 'every stream changed at once');
		$this->assertArrayNotHasKey('full', $this->served('streams', ['since' => 3], 1, $rKeys));
		$this->assertTrue($this->served('streams', ['since' => 4], 1, $rKeys)['full'], 'a cursor past the head: MAIN\'s versions went back (a restore)');
		StreamVersions::raiseFloor(9, self::SID);
		$this->assertTrue($this->served('streams', ['since' => 3], 1, $rKeys)['full'], 'this node\'s own floor');
	}

	/** Streams held by this node alone (assigned, not on demand), from $rFirst on. */
	private function heldStreams(int $rFirst, int $rCount): array {
		$rIDs = range($rFirst, $rFirst + $rCount - 1);
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`, `stream_source`) VALUES ' . implode(', ', array_map(static fn(int $rID): string => '(' . $rID . ", 2, '[]')", $rIDs)));
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) SELECT `id`, ' . self::SID . ', 0 FROM `streams` WHERE `id` >= ' . $rFirst);
		return $rIDs;
	}

	public function testAResyncPastWhatOneCallExaminesGoesOnWhereItStopped(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		$rIDs = $this->heldStreams(1000, StreamReplica::MAX_EXAMINE + 5);
		// The node holds every one as MAIN signs it, and names them all.
		$rHave = array_map(static fn(array $rData): string => ReplicaBuilder::etag($rData), StreamReplica::data(self::SID, $rIDs));
		$this->assertSame($rIDs, array_keys($rHave));

		// MAIN reads MAX_EXAMINE of them: the ids named past those are the next call's, never removals.
		$rOut = $this->served('streams', ['since' => 1, 'resync' => ['from' => 1000, 'to' => 2147483647, 'hashes' => $rHave]], 1, $rKeys);
		$this->assertSame([[], [], 1000 + StreamReplica::MAX_EXAMINE], [$rOut['streams'], $rOut['removed'], $rOut['next']]);
		$rOut = $this->served('streams', ['since' => 1, 'resync' => ['from' => $rOut['next'], 'to' => 2147483647, 'hashes' => array_slice($rHave, StreamReplica::MAX_EXAMINE, null, true)]], 1, $rKeys);
		$this->assertSame([[], [], null], [$rOut['streams'], $rOut['removed'], $rOut['next']]);
	}

	public function testADeltaPastWhatOneCallReadsSaysMore(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		$rIDs = $this->heldStreams(1000, StreamReplica::MAX_ROWS + 1);
		EventDispatcher::dispatch(new StreamsChangedEvent($rIDs));
		$rHead = StreamVersions::head();
		// Taken off this node at once: more removals than a delta reads.
		StreamRepository::deleteStreamsByServer($rIDs, self::SID);

		$rOut = $this->served('streams', ['since' => $rHead], 1, $rKeys);
		$this->assertSame([[], array_slice($rIDs, 0, StreamReplica::MAX_ROWS), $rHead + StreamReplica::MAX_ROWS, true], [$rOut['streams'], $rOut['removed'], $rOut['ver'], $rOut['more']]);
		$rOut = $this->served('streams', ['since' => $rOut['ver']], 1, $rKeys);
		$this->assertSame([[], [1000 + StreamReplica::MAX_ROWS], $rHead + StreamReplica::MAX_ROWS + 1, false], [$rOut['streams'], $rOut['removed'], $rOut['ver'], $rOut['more']]);
	}

	public function testANullResyncIsADeltaAndNullHashesNameNone(): void {
		$this->streamsTables();
		$rKeys = $this->streamsNode();
		EventDispatcher::dispatch(new StreamsChangedEvent([10]));
		$rOut = $this->served('streams', ['since' => 1, 'resync' => null], 1, $rKeys);
		$this->assertSame([[10], 2, false], [array_column($rOut['streams'], 'id'), $rOut['ver'], $rOut['more']], 'a delta');
		$this->assertArrayNotHasKey('next', $rOut);
		foreach ([['hashes' => null], []] as $rHashes) {
			$rOut = $this->served('streams', ['since' => 1, 'resync' => self::EVERY_STREAM + $rHashes], 1, $rKeys);
			$this->assertSame([[10, 12, 13], null], [array_column($rOut['streams'], 'id'), $rOut['next']], 'a resync that names none');
		}
	}

	/**
	 * MAIN's cluster entry point subscribes StreamVersions before it serves an
	 * op: `recording_complete` makes a VOD a node holds (RecordingFinalizer),
	 * and that entry point never runs ContainerPopulateStage.
	 */
	public function testTheClusterEntryPointStampsTheVersionsOfWhatItsOpsChange(): void {
		$rIndex = (string) file_get_contents(MAIN_HOME . 'Public/cluster/index.php');
		$rSubscribe = strpos($rIndex, "\n\tEventDispatcher::subscribe(StreamVersions::class);\n");
		$rHandle = strpos($rIndex, '$rEmit(ClusterApi::serve($rCrypto, $rReq, $rSettings, $rMain));');
		$this->assertNotFalse($rSubscribe);
		$this->assertNotFalse($rHandle);
		$this->assertLessThan($rHandle, $rSubscribe);
	}

	// ── The cluster bus: nonces and per-op semaphores ────────────────────

	/**
	 * The cluster bus (a real redis-server on a unix socket), emptied: by
	 * default one that has been taking claims for an hour, else fresh.
	 */
	private function bus(bool $rSettled = true): \Redis {
		self::$rBus ??= BusServer::start('api-bus');
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		foreach ([NonceStore::BUS_MARK, NonceStore::SQL_MARK, NodeAuthCache::STALE_MARK] as $rMark) {
			@unlink(self::$rBus->rDir . '/' . $rMark);
		}
		ClusterBus::useSocket(self::$rBus->socket());
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		if ($rSettled) {
			$rRedis->set('nonces_since', (string) ($this->rT0 - 3600000));
		}
		return $rRedis;
	}

	public function testOnTheBusNoncesLeaveMySqlAlone(): void {
		$this->bus();
		$rKeys = $this->active();
		$r = $this->request('heartbeat', [], 1, $rKeys);
		$this->assertSame(200, ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain)['status']);
		$rDoc = $this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 401, 'REPLAY', $r['req']);
		$this->assertArrayNotHasKey('retry_after_ms', $rDoc, 'a replay: no wait would let it pass');
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_nonces`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'no row per request');
	}

	public function testOnTheBusAHeartbeatWritesNothingToMySql(): void {
		$this->bus();
		$rKeys = $this->active();
		HeartbeatService::flush(); // MAIN's flusher is running
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		ClusterClock::fix($this->rT0 + 2000);
		[$rRes, $rCtx] = $this->call('heartbeat', ['root_ready' => true, 'telemetry' => ['cpu' => 3]], 1, $rKeys, ['ts' => $this->rT0 + 2250]);
		$this->assertSame('active', $this->reply($rRes, $rCtx, $rKeys)['state']);
		$this->assertSame([], $rLog->writes(), 'no MySQL write: the node and its epoch are only read');
		$this->assertCount(2, $rLog->rQueries);
		$this->assertSame($this->rT0 + 2000, HeartbeatService::lastSeen()[self::SID]);

		HeartbeatService::flush();
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([$this->rT0 + 2000, 250, 1], [(int) $rNode['last_seen_at'], (int) $rNode['clock_offset_ms'], (int) $rNode['root_ready']]);
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['status'], 'the flush marks the server up');
	}

	public function testAnOpWithoutAFreePermitIsRefusedWithASigned503(): void {
		$rRedis = $this->bus();
		$rKeys = $this->active();
		$rHeld = [];
		for ($i = 0; $i < ClusterSemaphore::PERMITS; $i++) {
			$rHeld[] = (string) ClusterSemaphore::acquire('hello');
		}
		[$rRes, , $rReq] = $this->call('hello', ['instance_id' => 'inst-a', 'boot_id' => 'boot-7'], 1, $rKeys);
		$rDoc = $this->denial($rRes, 503, 'RATE_LIMITED', $rReq);
		$this->assertSame('hello', $rDoc['op']);
		$this->assertGreaterThanOrEqual(ClusterSemaphore::RETRY_MIN_MS, $rDoc['retry_after_ms']);
		$this->assertNotSame('boot-7', NodeRegistry::byServer(self::SID)['boot_id'], 'the handler did not run');

		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);

		ClusterSemaphore::release('hello', $rHeld[0]);
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a', 'boot_id' => 'boot-7'], 1, $rKeys);
		$this->assertSame('active', $this->reply($rRes, $rCtx, $rKeys)['state']);
		$this->assertSame(ClusterSemaphore::PERMITS - 1, $rRedis->zCard('sem:hello'), 'the served hello gave its permit back');

		// A node that is not active is told so, not that MAIN is busy.
		for ($i = 0; $i < ClusterSemaphore::PERMITS; $i++) {
			ClusterSemaphore::acquire('config');
		}
		NodeRegistry::update(self::SID, ['state' => 'quarantined']);
		[$rRes, , $rReq] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
	}

	public function testABusyRekeySpendsNeitherTheMinuteNorTheChallenge(): void {
		$this->bus();
		$this->expired();
		$rChallenge = $this->challenge();
		$rHeld = [];
		for ($i = 0; $i < ClusterSemaphore::PERMITS; $i++) {
			$rHeld[] = (string) ClusterSemaphore::acquire('token_rekey');
		}
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32));
		$this->assertSame('token_rekey', $this->denial($rRes, 503, 'RATE_LIMITED', $rReq)['op']);

		ClusterSemaphore::release('token_rekey', $rHeld[0]);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($rChallenge, $rEph);
		$this->assertSame(2, $this->rekeyed($rRes, $rReq, $rEph)['doc']['epoch'], 'the same challenge, the same minute');
	}

	public function testAFreshBusRefusesASessionRequestStampedAtItsFloorWithAWait(): void {
		$rKeys = $this->active();
		$this->bus(false);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$rDoc = $this->denial($rRes, 401, 'REPLAY', $rReq);
		$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, $rDoc['retry_after_ms'], 'MAIN cannot vouch for it yet: stamped anew that long after main_time_ms, it passes');
		$this->assertSame($this->rT0, $rDoc['main_time_ms']);

		ClusterClock::fix($this->rT0 + $rDoc['retry_after_ms']);
		$rNonce = random_bytes(16);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys, ['nonce' => $rNonce]);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_nonces` WHERE `node` = ? AND `nonce` = ?', $this->rUuid, $rNonce);
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'a young bus claims in MySQL too');
	}

	public function testAFreshBusRefusesARekeyStampedAtItsFloorAndSpendsNothing(): void {
		$this->expired();
		$rChallenge = $this->challenge();
		$this->bus(false);
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32));
		$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, $this->denial($rRes, 401, 'REPLAY', $rReq)['retry_after_ms']);

		ClusterClock::fix($this->rT0 + 1000);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($rChallenge, $rEph);
		$this->assertSame(2, $this->rekeyed($rRes, $rReq, $rEph)['doc']['epoch'], 'the same challenge, the same minute');
	}

	public function testAuthenticationAndTheNonceComeBeforeAnyPermit(): void {
		$rRedis = $this->bus();
		$rKeys = $this->active();
		for ($i = 0; $i < ClusterSemaphore::PERMITS; $i++) {
			ClusterSemaphore::acquire('hello');
		}
		[$rRes, , $rReq] = $this->call('hello', ['instance_id' => 'inst-a'], 1, $rKeys, ['body' => static fn($b) => $b . 'x']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		$r = $this->request('hello', ['instance_id' => 'inst-a'], 1, $rKeys);
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 503, 'RATE_LIMITED', $r['req']);
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 401, 'REPLAY', $r['req']);
		$this->assertSame(ClusterSemaphore::PERMITS, $rRedis->zCard('sem:hello'), 'no refused request took a permit');
	}

	public function testConfigAndConnSnapshotHoldAPermitToo(): void {
		$this->bus();
		$rKeys = $this->active();
		foreach (['config' => ['blocklist_since' => 0], 'conn_snapshot' => ['snap_id' => 'snap-1', 'seq' => 0, 'last' => true, 'conns' => []], 'streams' => ['since' => 0]] as $rOp => $rPayload) {
			for ($i = 0; $i < ClusterSemaphore::PERMITS; $i++) {
				ClusterSemaphore::acquire($rOp);
			}
			[$rRes, , $rReq] = $this->call($rOp, $rPayload, 1, $rKeys);
			$this->assertSame($rOp, $this->denial($rRes, 503, 'RATE_LIMITED', $rReq)['op']);
		}
		[$rRes, $rCtx] = $this->call('heartbeat', [], 1, $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
	}

	// ── The cluster bus: ingest permits ──────────────────────────────────

	public function testP0EventsKeepTheirReserveWhileBulkHoldsTheRest(): void {
		$rRedis = $this->bus();
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `pid` int)');
		$this->rDb->exec('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, 5, 0)');
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_LOGS | NodeRegistry::FLOW_CONTENT | NodeRegistry::FLOW_CONNECTIONS]);
		$rPermits = ClusterSemaphore::ingestPermits(null);
		$this->assertSame(['p0' => 3, 'bulk' => 3, 'total' => 6], $rPermits, 'cluster_ingest_concurrency unset: its default, 6');
		for ($i = 0; $i < $rPermits['bulk']; $i++) {
			$this->assertIsString(ClusterSemaphore::acquireIngest('bulk', 6));
		}
		$rState = ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => self::SID, 'fields' => ['pid' => 42]]];

		// Every bulk op is refused before its handler runs: MAIN is busy, not failing.
		$rBulk = [
			'events' => ['lane' => 'p1', 'first_useq' => 1, 'events' => [['type' => 'skip', 'd' => ['lane' => 'p1', 'count' => 3]]]],
			'config' => ['blocklist_since' => 0],
			'conn_snapshot' => ['snap_id' => 'snap-1', 'seq' => 0, 'last' => true, 'conns' => []],
			'recording_complete' => ['recording_id' => 1],
		];
		foreach ($rBulk as $rOp => $rPayload) {
			[$rRes, , $rReq] = $this->call($rOp, $rPayload, 1, $rKeys);
			$rDoc = $this->denial($rRes, 503, 'RATE_LIMITED', $rReq);
			$this->assertSame([$rOp, 'bulk'], [$rDoc['op'], $rDoc['lane']], 'names the op and its lane');
			$this->assertGreaterThanOrEqual(ClusterSemaphore::RETRY_MIN_MS, $rDoc['retry_after_ms']);
			$this->assertLessThanOrEqual(ClusterSemaphore::RETRY_MAX_MS, $rDoc['retry_after_ms']);
		}
		$this->assertSame([0, 0], [$rRedis->zCard('sem:config'), $rRedis->zCard('sem:conn_snapshot')], 'their per-op permits were given back');

		// A P0 batch is served from the reserve, and gives its permit back.
		[$rRes, $rCtx] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState]], 1, $rKeys);
		$this->assertSame(1, $this->reply($rRes, $rCtx, $rKeys)['applied']);
		$this->assertSame(0, $rRedis->zCard('sem:ingest:p0'));
		// Control ops take no ingest permit.
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a'], 1, $rKeys);
		$this->assertSame(['p0' => 1, 'p1' => 0], $this->reply($rRes, $rCtx, $rKeys)['cursors'], 'the refused P1 batch was not applied');

		// P0 is refused only once it holds its reserve and every permit is held.
		for ($i = 0; $i < $rPermits['total'] - $rPermits['bulk']; $i++) {
			$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6));
		}
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 2, 'events' => [$rState]], 1, $rKeys);
		$rDoc = $this->denial($rRes, 503, 'RATE_LIMITED', $rReq);
		$this->assertSame(['events', 'p0'], [$rDoc['op'], $rDoc['lane']]);
		$this->assertGreaterThanOrEqual(ClusterSemaphore::P0_RETRY_MIN_MS, $rDoc['retry_after_ms']);
		$this->assertLessThanOrEqual(ClusterSemaphore::P0_RETRY_MAX_MS, $rDoc['retry_after_ms'], 'P0 is asked back sooner');

		// Once a permit is free, the refused batch is resent and applied once.
		$rRedis->del('sem:ingest:bulk');
		[$rRes, $rCtx] = $this->call('events', $rBulk['events'], 1, $rKeys);
		$this->assertSame(1, $this->reply($rRes, $rCtx, $rKeys)['useq']);
		[$rRes, $rCtx] = $this->call('events', $rBulk['events'], 1, $rKeys);
		$this->assertSame([1, 0], [$this->reply($rRes, $rCtx, $rKeys)['useq'], $rRedis->zCard('sem:ingest:bulk')], 'a repeat, and the permit given back');
	}

	public function testTheConcurrencySettingSizesTheIngestPermits(): void {
		$this->rSettings['cluster_ingest_concurrency'] = 2;
		$this->bus();
		$rKeys = $this->active();
		ClusterSemaphore::acquireIngest('bulk', 2);
		[$rRes, , $rReq] = $this->call('config', ['blocklist_since' => 0], 1, $rKeys);
		$this->assertSame('bulk', $this->denial($rRes, 503, 'RATE_LIMITED', $rReq)['lane'], '1 of 2 for bulk');
		ClusterSemaphore::acquireIngest('p0', 2);
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 1, $rKeys);
		$this->assertSame('p0', $this->denial($rRes, 503, 'RATE_LIMITED', $rReq)['lane'], '1 of 2 kept for P0, and held');
	}

	public function testNothingBeforeTheBoxTakesAnIngestPermitAndARefusalWritesNothing(): void {
		$rRedis = $this->bus();
		$rKeys = $this->active();
		for ($i = 0; $i < 6 - ClusterSemaphore::BULK_RESERVE; $i++) {
			ClusterSemaphore::acquireIngest('p0', 6); // every permit P0 may take
		}
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 1, $rKeys, ['body' => static fn($b) => $b . 'x']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$r = $this->request('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 1, $rKeys);
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 503, 'RATE_LIMITED', $r['req']);
		$this->assertSame([], $rLog->writes(), 'a request refused a permit writes nothing to MySQL');
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 401, 'REPLAY', $r['req']);
		DatabaseFactory::set($this->rDb);
		NodeRegistry::update(self::SID, ['state' => 'quarantined']);
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 1, $rKeys);
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
		$this->assertSame([5, 0], [$rRedis->zCard('sem:ingest:p0'), $rRedis->zCard('sem:ingest:bulk')], 'no refused request took a permit');
	}

	public function testANewEpochIsMarkedUsedOnlyOnceAnIngestPermitIsTaken(): void {
		$rRedis = $this->bus();
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys);
		$rTok2 = $this->openToken((string) base64_decode($this->reply($rRes, $rCtx, $rKeys)['token_sealed']), $rEph);
		for ($i = 0; $i < 6 - ClusterSemaphore::BULK_RESERVE; $i++) {
			$this->assertIsString(ClusterSemaphore::acquireIngest('p0', 6));
		}
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);

		// The node's first request at epoch 2 is refused a permit: epoch 2 stays unused, and epoch 1 current.
		[$rRes, , $rReq] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 2, $rTok2['keys']);
		$this->assertSame('p0', $this->denial($rRes, 503, 'RATE_LIMITED', $rReq)['lane']);
		$this->assertSame([], $rLog->writes(), 'nothing written, not even the epoch');
		DatabaseFactory::set($this->rDb);
		$this->rDb->query('SELECT `used` FROM `cluster_node_epochs` WHERE `server_id` = 5 AND `epoch` = 2');
		$this->assertSame(0, (int) $this->rDb->get_row()['used']);
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['epoch']);

		// Served once a permit is free: then epoch 2 is marked used.
		$rRedis->del('sem:ingest:p0');
		[$rRes, $rCtx] = $this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => []], 2, $rTok2['keys']);
		$this->reply($rRes, $rCtx, $rTok2['keys']);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['epoch']);
	}

	public function testWithoutTheBusIngestTakesNoPermit(): void {
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_LOGS]);
		$this->rSettings['cluster_ingest_concurrency'] = 1;
		for ($i = 1; $i <= 3; $i++) {
			[$rRes, $rCtx] = $this->call('events', ['lane' => 'p1', 'first_useq' => $i, 'events' => [['type' => 'skip', 'd' => ['lane' => 'p1', 'count' => 1]]]], 1, $rKeys);
			$this->assertSame($i, $this->reply($rRes, $rCtx, $rKeys)['useq'], 'as before the bus');
		}
	}

	// ── The cluster bus: authentication ──────────────────────────────────

	/** A request the agent sends, served: its reply. */
	private function served(string $rOp, array $rPayload, int $rEpoch, array $rKeys): array {
		[$rRes, $rCtx] = $this->call($rOp, $rPayload, $rEpoch, $rKeys);
		return $this->reply($rRes, $rCtx, $rKeys);
	}

	/** The servers ClusterAdmin acts on: MAIN and the node, a load balancer. */
	private function servers(): array {
		return [1 => ['server_name' => 'main', 'is_main' => 1, 'server_type' => 0] + $this->rMain, self::SID => ['server_name' => 'lb', 'is_main' => 0, 'server_type' => 0]];
	}

	public function testOnTheBusAHeldHeartbeatAsksMySqlNothingAndALongPollOnlyForItsCommands(): void {
		$this->bus();
		$rKeys = $this->active();
		HeartbeatService::flush(); // MAIN's flusher is running
		$this->served('heartbeat', [], 1, $rKeys); // the first request since enrol_complete reads MySQL
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		foreach ([1, 2, 3] as $i) {
			ClusterClock::fix($this->rT0 + 2000 * $i);
			$this->assertSame('active', $this->served('heartbeat', ['root_ready' => true, 'telemetry' => ['cpu' => 3]], 1, $rKeys)['state']);
		}
		$this->assertSame([], $rLog->rQueries, 'a heartbeat on the bus sends MySQL no query of its own, its authentication included (the connection\'s setup is the entry point\'s)');

		$this->assertSame([], $this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys)['commands']);
		$this->assertNotSame([], $rLog->rQueries);
		foreach ($rLog->rQueries as $rQuery) {
			$this->assertStringContainsString('`cluster_commands`', $rQuery, 'the long-poll reads its commands, and nothing to authenticate');
		}
	}

	/**
	 * A heartbeat that reports a new policy version and MAIN port writes them
	 * through the registry, which drops the bus's copy of the row: the next
	 * heartbeat reads it again once, and the one after asks MySQL nothing.
	 * Columns written without that drop would be written again by every
	 * heartbeat while the copy lives.
	 */
	public function testOnTheBusAHeartbeatWritesANewPolicyVersionAndPortOnce(): void {
		$this->bus();
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `main_port` int DEFAULT NULL');
		$rKeys = $this->active();
		HeartbeatService::flush(); // MAIN's flusher is running
		$this->served('heartbeat', [], 1, $rKeys); // held on the bus: policy_ver 0, no port
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$rBeat = function (int $i) use ($rKeys, $rLog): array {
			$rLog->rQueries = [];
			ClusterClock::fix($this->rT0 + 2000 * $i);
			$r = $this->request('heartbeat', ['policy_ver' => 3], 1, $rKeys);
			$this->assertSame('active', $this->reply(ClusterApi::handle($this->rCrypto, ['port' => 8443] + $r['req'], $this->rSettings, $this->rMain), $r['ctx'], $rKeys)['state']);
			return $rLog->rQueries;
		};
		$rBeat(1);
		$this->assertCount(1, $rLog->writes(), 'the new version and port, written once');
		$rQueries = $rBeat(2);
		$this->assertCount(2, $rQueries, 'the row read again once, and nothing written');
		$this->assertSame([], $rLog->writes());
		$this->assertSame([], $rBeat(3), 'then held with them: MySQL asked nothing');
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([3, 8443], [(int) $rNode['policy_ver'], (int) $rNode['main_port']]);
	}

	public function testOnTheBusEnrolCompleteIsSeenAtTheNextRequest(): void {
		$this->bus();
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rTok['keys']);
		$this->assertSame('enrolling', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state'], 'the enrolling row, now held on the bus');
		$this->assertSame('active', $this->served('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys'])['state']);
		$this->assertSame('active', $this->served('heartbeat', [], 1, $rTok['keys'])['state']);
	}

	public function testOnTheBusARevokedNodeIsRefusedAtItsNextRequest(): void {
		$this->bus();
		$rKeys = $this->active();
		$this->served('heartbeat', [], 1, $rKeys);
		$this->served('heartbeat', [], 1, $rKeys); // held on the bus
		$this->assertSame('cluster_node_revoked', ClusterAdmin::act($this->rCrypto, ['cluster_action' => 'revoke', 'server_id' => self::SID], $this->servers(), 1, $this->rSettings, 1)['message']);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(2, $this->denial($rRes, 403, 'NODE_REVOKED', $rReq)['revoked_gen'], 'the revoked row, not the one the bus held');
	}

	public function testOnTheBusAQuarantineIsSeenAtTheNextRequest(): void {
		$this->bus();
		$rKeys = $this->active();
		$this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys); // held on the bus
		$this->assertSame('quarantined', $this->served('hello', ['instance_id' => 'inst-CLONE'], 1, $rKeys)['state']);
		[$rRes, , $rReq] = $this->call('config', [], 1, $rKeys);
		$this->assertSame('quarantined', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
		$this->assertSame('quarantined', $this->served('heartbeat', [], 1, $rKeys)['state']);
	}

	public function testOnTheBusARekeysQuarantineIsSeenAtTheNextRequest(): void {
		$this->bus();
		$rKeys = $this->active();
		$this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys); // held on the bus
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32), ['instance_id' => 'inst-CLONE']);
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
		[$rRes, , $rReq] = $this->call('config', [], 1, $rKeys);
		$this->assertSame('quarantined', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
	}

	public function testOnTheBusHelloReturnsTheCursorsAsMySqlHasThem(): void {
		$this->bus();
		$rKeys = $this->active();
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_LOGS]);
		$this->served('heartbeat', [], 1, $rKeys); // held on the bus, its cursors at 0
		$this->assertSame(1, $this->served('events', ['lane' => 'p1', 'first_useq' => 1, 'events' => [['type' => 'skip', 'd' => ['lane' => 'p1', 'count' => 1]]]], 1, $rKeys)['useq']);
		$this->assertSame(['p0' => 0, 'p1' => 1], $this->served('hello', ['instance_id' => 'inst-a'], 1, $rKeys)['cursors'], 'the batch just applied, not the held row\'s cursor');
	}

	public function testOnTheBusAnAdminsFlowSwitchIsSeenAtTheNextRequest(): void {
		$this->bus();
		$rKeys = $this->active();
		$rFlows = NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS;
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => $rFlows]);
		$this->assertSame($rFlows, $this->served('heartbeat', [], 1, $rKeys)['flows']);
		$this->assertSame($rFlows, $this->served('heartbeat', [], 1, $rKeys)['flows']); // held on the bus
		$this->assertSame('success', ClusterAdmin::act($this->rCrypto, ['cluster_action' => 'connections_off', 'server_id' => self::SID], $this->servers(), 1, $this->rSettings, 1)['type']);
		$this->assertSame($rFlows & ~NodeRegistry::FLOW_CONNECTIONS, $this->served('heartbeat', [], 1, $rKeys)['flows']);
		[$rRes, , $rReq] = $this->call('conn_admit', ['uuid' => 'viewer-1'], 1, $rKeys);
		$this->denial($rRes, 409, 'FLOW_OFF', $rReq);
	}

	public function testOnTheBusAnEpochMintedAgainUnderItsNumberIsReadAgain(): void {
		$this->bus();
		$rKeys = $this->active();
		$rEphA = random_bytes(32);
		$rTokA = $this->openToken((string) base64_decode($this->served('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEphA))], 1, $rKeys)['token_sealed']), $rEphA);
		// Epoch 2 is named once, so the bus holds its record, and not used.
		[$rRes, , $rReq] = $this->call('heartbeat', [], 2, $rTokA['keys'], ['body' => static fn($b) => $b . 'x']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		// The refresh reply was lost: asked again with another key, epoch 2 is minted anew.
		$rEphB = random_bytes(32);
		$rTokB = $this->openToken((string) base64_decode($this->served('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEphB))], 1, $rKeys)['token_sealed']), $rEphB);
		$this->assertSame(2, $rTokB['doc']['epoch']);
		$this->assertSame('active', $this->served('heartbeat', [], 2, $rTokB['keys'])['state'], 'the new record, not the one the bus held');
		[$rRes, , $rReq] = $this->call('heartbeat', [], 2, $rTokA['keys']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
	}

	public function testOnTheBusARekeyDropsTheEpochsItReplaces(): void {
		$this->bus();
		$rKeys = $this->active();
		$this->served('heartbeat', [], 1, $rKeys); // epoch 1 held on the bus
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$rTok = $this->rekeyed($rRes, $rReq, $rEph);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq);
		$this->assertSame('active', $this->served('heartbeat', [], $rTok['doc']['epoch'], $rTok['keys'])['state']);
	}

	public function testOnTheBusAReEnrolmentIsSeenAtTheNextRequest(): void {
		$this->bus();
		$rOld = $this->active();
		$this->served('heartbeat', [], 1, $rOld); // the old enrolment's epoch 1 held on the bus
		$rSecond = $this->enrol();
		$rTok = $this->openToken($rSecond['token_sealed'], $this->rEph[1]);
		$this->assertSame(2, $this->served('enrol_complete', ['instance_id' => 'inst-b'], 1, $rTok['keys'])['gen'], 'the new epoch 1, not the one the bus held');
		[$rRes] = $this->call('heartbeat', [], 1, $rOld);
		$this->assertSame(401, $rRes['status']);
	}

	public function testOnTheBusANewEpochIsMarkedOnceThenAsksMySqlNothing(): void {
		$this->bus();
		$rKeys = $this->active();
		HeartbeatService::flush();
		$rEph = random_bytes(32);
		$rTok2 = $this->openToken((string) base64_decode($this->served('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys)['token_sealed']), $rEph);
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$this->served('heartbeat', [], 2, $rTok2['keys']);
		$this->assertNotSame([], $rLog->writes(), 'epoch 2 becomes current');
		$rLog->rQueries = [];
		$this->served('heartbeat', [], 2, $rTok2['keys']);
		$this->assertSame([], $rLog->writes(), 'read again once, with epoch 2 current: not marked again');
		$rLog->rQueries = [];
		$this->served('heartbeat', [], 2, $rTok2['keys']);
		$this->assertSame([], $rLog->rQueries);
	}

	public function testOnlyTheOpsThatReadMainsRowAreGivenIt(): void {
		foreach (['challenge', 'enrol_complete', 'hello', 'config', 'an_op_to_come'] as $rOp) {
			$this->assertTrue(ClusterApi::readsMain(Canonical::PATH_PREFIX . $rOp), $rOp);
		}
		foreach (['heartbeat', 'commands', 'ack', 'events', 'conn_admit', 'rtmp_auth', 'conn_snapshot', 'recording_complete', 'streams', 'token_refresh', 'token_rekey', 'enrol_code', 'enrol_code_status'] as $rOp) {
			$this->assertFalse(ClusterApi::readsMain(Canonical::PATH_PREFIX . $rOp), $rOp);
		}
		// No handler of those is given MAIN's row.
		$rSource = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/ClusterApi.php');
		preg_match_all("/^\\s*'(\\w+)' => self::\\w+\\((.*)\\),$/m", $rSource, $rArms, PREG_SET_ORDER);
		$this->assertNotEmpty($rArms);
		foreach ($rArms as [, $rOp, $rArgs]) {
			if (!ClusterApi::readsMain(Canonical::PATH_PREFIX . $rOp)) {
				$this->assertStringNotContainsString('$rMain', $rArgs, $rOp . ' is served without MAIN\'s row');
			}
		}
		$rKeys = $this->active();
		$this->rMain = [];
		$this->assertSame('active', $this->served('heartbeat', [], 1, $rKeys)['state']);
		$this->assertSame([], $this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys)['commands']);
	}

	// ── Regressions: seq floor, enrol race, session record, failures, anchors ─

	/**
	 * An ack lost for longer than its command lived: the row is pruned, yet
	 * the node ran the command and raised its high-water. The next command
	 * must take a seq above it, or the long-poll (seq > after_seq) never
	 * hands it out.
	 */
	public function testACommandWhoseAckWasLostPastItsLifeNeverLendsItsSeqAgain(): void {
		$rKeys = $this->active();
		$rBus = \XcVm\Domain\Cluster\CommandBus::class;
		$rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$this->assertSame([1], array_column($this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], 1, $rKeys)['commands'], 'seq'));
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'handed out: the floor is past it before any ack');
		// The node runs it (its high-water is now 1); the ack never arrives.
		ClusterClock::fix($this->rT0 + 601000);
		$rBus::prune();
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_commands`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'pruned');
		$rCmd = $rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$rOut = $this->served('commands', ['after_seq' => 1, 'wait_ms' => 0], 1, $rKeys)['commands'];
		$this->assertCount(1, $rOut, 'delivered above the node\'s high-water');
		$this->assertSame([2, $rCmd], [$rOut[0]['seq'], json_decode($rOut[0]['doc'], true)['cmd_id']]);
	}

	/**
	 * The high-water the node reports on each poll is recorded too (a
	 * command handed out before MAIN kept the floor at delivery), but never
	 * past the highest seq MAIN issued it.
	 */
	public function testTheHighWaterANodeReportsIsKeptUpToTheHighestSeqIssued(): void {
		$rKeys = $this->active();
		$rBus = \XcVm\Domain\Cluster\CommandBus::class;
		$rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_free_space']);
		// Delivered by a MAIN that did not keep the floor: the node holds 2.
		$rBus::pending(self::SID, 0);
		$this->assertSame(0, (int) NodeRegistry::byServer(self::SID)['cmd_seq']);
		$this->assertSame([], $this->served('commands', ['after_seq' => 2, 'wait_ms' => 0], 1, $rKeys)['commands']);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'its high-water, from the poll');
		$this->served('commands', ['after_seq' => PHP_INT_MAX, 'wait_ms' => 0], 1, $rKeys);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'no further than MAIN issued');
		$this->served('commands', ['after_seq' => 1, 'wait_ms' => 0], 1, $rKeys);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'never lowered');
		$rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$this->served('commands', ['after_seq' => PHP_INT_MAX, 'wait_ms' => 0], 1, $rKeys);
		$this->assertSame(3, (int) NodeRegistry::byServer(self::SID)['cmd_seq'], 'a larger high-water raises it to the cap');
		ClusterClock::fix($this->rT0 + 601000);
		$rBus::prune();
		$rBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$this->assertSame([4], array_column($rBus::pending(self::SID, 3), 'seq'));
	}

	/** A revocation that lands while enrol_complete runs keeps its row: the node is refused, not activated and announced. */
	public function testARevocationDuringEnrolCompleteIsNotOverwritten(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		$rLog = new QueryLogDb($this->rDb);
		$rRevoked = false;
		$rLog->rBefore = function (string $rQuery, array $rArgs) use (&$rRevoked): void {
			if (!$rRevoked && str_starts_with($rQuery, 'UPDATE `cluster_nodes` SET `state` = ?') && ($rArgs[0] ?? null) === 'active') {
				// The admin revokes the node after its state was read.
				$rRevoked = true;
				$this->assertTrue(NodeRegistry::revoke(self::SID, $this->rCrypto));
			}
		};
		DatabaseFactory::set($rLog);
		[$rRes, , $rReq] = $this->call('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys']);
		$this->assertTrue($rRevoked);
		$this->assertSame(2, $this->denial($rRes, 403, 'NODE_REVOKED', $rReq)['revoked_gen']);
		$this->assertSame('revoked', NodeRegistry::byServer(self::SID)['state']);
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = 'node.enrol_complete'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'not recorded as enrolled');
	}

	/** A re-enrolment's new row (next gen) that lands meanwhile is not activated by the old agent. */
	public function testAReEnrolmentDuringEnrolCompleteKeepsTheNewRow(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		$rOld = NodeRegistry::byServer(self::SID);
		$rLog = new QueryLogDb($this->rDb);
		$rReEnrolled = false;
		$rLog->rBefore = function (string $rQuery, array $rArgs) use (&$rReEnrolled, $rOld): void {
			if (!$rReEnrolled && str_starts_with($rQuery, 'UPDATE `cluster_nodes` SET `state` = ?') && ($rArgs[0] ?? null) === 'active') {
				// The admin re-enrols the server after the old row was read.
				$rReEnrolled = true;
				$rPair = sodium_crypto_sign_keypair();
				NodeRegistry::startEnrolment(self::SID, $this->rUuid, sodium_crypto_sign_publickey($rPair), sodium_crypto_scalarmult_base(random_bytes(32)), (int) $rOld['mode'], $this->rCrypto);
			}
		};
		DatabaseFactory::set($rLog);
		[$rRes, , $rReq] = $this->call('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys']);
		$this->assertTrue($rReEnrolled);
		$this->assertSame('enrolling', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
		$rNew = NodeRegistry::byServer(self::SID);
		$this->assertSame('enrolling', $rNew['state'], 'the new generation still waits for its own agent');
		$this->assertSame((int) $rOld['gen'] + 1, (int) $rNew['gen']);
		$this->assertNull($rNew['instance_id']);
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = 'node.enrol_complete'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'not recorded as enrolled');
	}

	/** The server and epoch the extension-sealed record names must be the row's and the request's. */
	public function testASessionRecordForAnotherServerOrEpochOpensNoSession(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys);
		$rTok2 = $this->openToken((string) base64_decode($this->reply($rRes, $rCtx, $rKeys)['token_sealed']), $rEph);

		// Epoch 2's record under epoch 1's number: its keys would sign for epoch 1.
		$this->rDb->query('SELECT `record` FROM `cluster_node_epochs` WHERE `server_id` = 5 AND `epoch` = 2');
		$rRecord2 = $this->rDb->get_row()['record'];
		$this->rDb->query('SELECT `record` FROM `cluster_node_epochs` WHERE `server_id` = 5 AND `epoch` = 1');
		$rRecord1 = $this->rDb->get_row()['record'];
		$this->rDb->query('UPDATE `cluster_node_epochs` SET `record` = ? WHERE `server_id` = 5 AND `epoch` = 1', $rRecord2);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rTok2['keys']);
		$this->assertSame('RECORD', $this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq)['detail']);
		$this->rDb->query('UPDATE `cluster_node_epochs` SET `record` = ? WHERE `server_id` = 5 AND `epoch` = 1', $rRecord1);
		$this->assertSame('active', $this->served('heartbeat', [], 1, $rKeys)['state'], 'its own record opens');

		// The node's rows moved to another server.
		$this->rDb->query('UPDATE `cluster_nodes` SET `server_id` = 6 WHERE `server_id` = 5');
		$this->rDb->query('UPDATE `cluster_node_epochs` SET `server_id` = 6 WHERE `server_id` = 5');
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame('RECORD', $this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq)['detail']);
	}

	/**
	 * What handle() throws (here the extension refusing to sign even the
	 * denial: its clock) is logged, and answered with an unsigned 503 that
	 * names nothing of it: the agent takes it as a transport error.
	 */
	public function testAThrowingRequestIsLoggedAndAnsweredWithAnUnsigned503(): void {
		$rLogFile = sys_get_temp_dir() . '/cluster-api-log-' . bin2hex(random_bytes(4)) . '.log';
		\XcVm\Core\Logging\FileLogger::setLogFile($rLogFile);
		try {
			$rCrypto = new class extends FakeClusterCrypto {
				public function sign(string $rTag, string $rPayload): string {
					throw new \XcVm\Core\Cluster\Crypto\ClusterRefusedException('CLOCK', 'cluster_sign');
				}
			};
			$rRes = ClusterApi::serve($rCrypto, ['method' => 'POST', 'path' => '/cluster/v1/heartbeat', 'headers' => []], $this->rSettings, $this->rMain);
			$this->assertSame(503, $rRes['status']);
			$this->assertArrayNotHasKey('X-XCVM-Panel-Sig', $rRes['headers']);
			$this->assertSame(['v' => 1, 'reason' => 'ERROR'], json_decode($rRes['body'], true), 'nothing of the throwable');
			$rLine = json_decode((string) base64_decode(trim((string) file_get_contents($rLogFile))), true);
			$this->assertSame('cluster', $rLine['type']);
			$this->assertStringContainsString('/cluster/v1/heartbeat failed (refused: CLOCK)', $rLine['message']);

			// A request that goes through is served as handle() serves it.
			$this->assertSame(200, ClusterApi::serve($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/health', 'headers' => []], [], [])['status']);
		} finally {
			\XcVm\Core\Logging\FileLogger::setLogFile(null);
			@unlink($rLogFile);
		}
		$rIndex = (string) file_get_contents(MAIN_HOME . 'Public/cluster/index.php');
		$this->assertStringNotContainsString('ClusterApi::handle(', $rIndex, 'the entry point serves every op through serve()');
		$this->assertStringContainsString('$rEmit(ClusterApi::failed($rReq[\'path\'], $rE));', $rIndex, 'and answers what throws around it by failed()');
	}

	/** A hex or uuid field with a trailing newline is not that field (`$` would match before it). */
	public function testATrailingNewlineIsNotAValidIdOrHash(): void {
		$rKeys = $this->active();
		$rCmd = \XcVm\Domain\Cluster\CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		[$rRes, , $rReq] = $this->call('ack', ['cmd_id' => $rCmd . "\n", 'ok' => true], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
		[$rRes, , $rReq] = $this->call('config', ['have' => ['settings' => str_repeat('ab', 32) . "\n"]], 1, $rKeys);
		$this->denial($rRes, 400, 'BAD_REQUEST', $rReq);
		$this->assertFalse(EnrolmentService::validUuid($this->rUuid . "\n"));
		$this->assertFalse(Canonical::validNode($this->rUuid . "\n"));
		$this->assertTrue(EnrolmentService::validUuid($this->rUuid));
	}
}
