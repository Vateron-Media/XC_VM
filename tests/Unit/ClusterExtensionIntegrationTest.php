<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\LeaseService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference as Ref;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The panel facade against a real xcvm_core: init, token issue, the agent's
 * side of the token, the session MAIN derives, and a BOX round trip in both
 * directions. The panel and the extension must agree on every key.
 *
 * Opt-in: it needs a test-hooks build (XCVM_TEST_VERDICT_PK_HEX, to fake a
 * licence) and XCVM_CONFIG_DIR pointing at a throwaway directory, so it never
 * touches a real install:
 *
 *   XCVM_CONFIG_DIR=$(mktemp -d) php -d extension=…/.build_ext/dev/xcvm_core.so \
 *     tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterExtensionIntegrationTest
 */
final class ClusterExtensionIntegrationTest extends TestCase {
	private string $rDir;
	private string $rVerdictSk;

	protected function setUp(): void {
		$rDir = (string) getenv('XCVM_CONFIG_DIR');
		if (!class_exists('XC_VM', false) || !method_exists('XC_VM', 'cluster_session')) {
			$this->markTestSkipped('xcvm_core with the cluster API is not loaded');
		}
		if ($rDir === '' || !str_starts_with(realpath($rDir) ?: '', realpath(sys_get_temp_dir()) ?: '/tmp') || file_exists($rDir . '/config.enc')) {
			$this->markTestSkipped('XCVM_CONFIG_DIR must be a throwaway directory under the temp dir');
		}
		$this->rDir = $rDir;
		$rPair = sodium_crypto_sign_keypair();
		$this->rVerdictSk = sodium_crypto_sign_secretkey($rPair);
		putenv('XCVM_TEST_VERDICT_PK_HEX=' . bin2hex(sodium_crypto_sign_publickey($rPair)));
		putenv('XCVM_TEST_CLUSTER_LIC_TTL=0');
		$rPayload = json_encode(['v' => 1, 'jti' => bin2hex(random_bytes(16)), 'hwid' => \XC_VM::install_id(), 'exp' => null, 'kid' => 1, 'iat' => time()], JSON_UNESCAPED_SLASHES);
		$rB64 = static fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
		file_put_contents($this->rDir . '/activation_key', 'XCVM1.' . $rB64($rPayload) . '.' . $rB64(sodium_crypto_sign_detached($rPayload, $this->rVerdictSk)));
		if (!\XC_VM::license_valid()) {
			$this->markTestSkipped('not a test-hooks build (the fake licence was not accepted)');
		}
	}

	protected function tearDown(): void {
		putenv('XCVM_TEST_VERDICT_PK_HEX');
		putenv('XCVM_TEST_CLUSTER_LIC_TTL');
		if (isset($this->rDir)) {
			@unlink($this->rDir . '/activation_key');
		}
	}

	public function testPanelAndExtensionAgreeEndToEnd(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rRoot = $rCrypto->init();
		$this->assertSame(32, strlen($rRoot['panel_sign_pub']));
		$this->assertSame(1, $rCrypto->info()['api']);

		// The agent: static node key, per-epoch ephemeral key.
		$rNodePair = sodium_crypto_sign_keypair();
		$rEphSk = random_bytes(32);
		$rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$rIssued = $rCrypto->tokenIssue([
			'node_uuid' => $rUuid, 'server_id' => 7, 'gen' => 1, 'epoch' => 1, 'rotation_min' => 60,
			'node_sign_pub' => sodium_crypto_sign_publickey($rNodePair), 'agent_eph_pub' => sodium_crypto_scalarmult_base($rEphSk),
		]);
		$this->assertSame(15, $rIssued['grace_min']);

		// Agent side: open with the ephemeral key, verify the panel signature, derive.
		$rBody = Seal::open($rEphSk, 'token', $rUuid, $rIssued['token_sealed']);
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rDoc = substr($rBody, 4, $rLen);
		$this->assertTrue(PanelSig::verify($rRoot['panel_sign_pub'], 'tok', $rDoc, substr($rBody, 4 + $rLen)));
		$rAgentKeys = Ref::sessionKeys(hex2bin(json_decode($rDoc, true)['token']));

		// MAIN side: the session of the stored epoch record.
		$rSession = $rCrypto->session($rIssued['epoch_record'], $rUuid);
		$this->assertSame($rAgentKeys['mac_up'], $rSession->rMacUp);
		$this->assertSame($rAgentKeys['enc_down'], $rSession->rEncDown);

		// A request up and a reply down, with the panel's canonical MAC.
		$rCtx = Canonical::request(['proto' => 1, 'agent' => 'test', 'method' => 'POST', 'path' => '/cluster/v1/heartbeat', 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $rUuid, 'epoch' => 1, 'ts_ms' => time() * 1000, 'nonce' => random_bytes(16)]);
		$rUp = Box::box($rAgentKeys['enc_up'], $rCtx, '{"hb":1}');
		$this->assertTrue(Canonical::verifyMac($rSession->rMacUp, $rCtx, $rUp, Canonical::mac($rAgentKeys['mac_up'], $rCtx, $rUp)));
		$this->assertSame('{"hb":1}', Box::open($rSession->rEncUp, $rCtx, $rUp));
		$rDown = Box::box($rSession->rEncDown, $rCtx, '{"ok":true}');
		$this->assertSame('{"ok":true}', Box::open($rAgentKeys['enc_down'], $rCtx, $rDown));

		// A record sealed for this node does not open as another node.
		$this->expectException(ClusterRefusedException::class);
		$rCrypto->session($rIssued['epoch_record'], '11111111-2222-4333-8444-555555555555');
	}

	/**
	 * The lease MAIN sends with a token, against the extension that signs it:
	 * the panel's own `lea` verification, the fields the node reads, and the
	 * caps the extension applies whatever MAIN asks for (ADR-002, "Lease").
	 */
	public function testALeaseTravelsWithATokenAndTheExtensionCapsIt(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rPub = $rCrypto->init()['panel_sign_pub'];
		$rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$rNode = ['node_uuid' => $rUuid, 'server_id' => 7, 'gen' => 1];
		$rTokenExp = time() + 4500;

		$rLease = LeaseService::issue($rCrypto, $rNode, $rTokenExp);
		$this->assertNotNull($rLease, 'a licensed panel gets one');
		$this->assertTrue(PanelSig::verify($rPub, 'lea', $rLease['payload'], $rLease['sig']), 'the tag a lease is signed under');
		$this->assertFalse(PanelSig::verify($rPub, 'tok', $rLease['payload'], $rLease['sig']), 'and not a token signature');
		$rDoc = json_decode($rLease['payload'], true);
		$this->assertSame(['xcvm-lease', $rUuid, 7, 1], [$rDoc['typ'], $rDoc['node_uuid'], $rDoc['server_id'], $rDoc['gen']]);
		$this->assertSame($rLease['exp'], $rDoc['exp']);
		$this->assertSame(min($rTokenExp + LeaseService::toleranceHours() * 3600, $rDoc['iat'] + 26 * 3600), $rDoc['exp']);

		// The extension, not the panel, holds the ceilings: a token expiry far out
		// and a tolerance beyond 24 h still leave a lease of at most 26 h.
		$rFar = $rCrypto->leaseIssue(['node_uuid' => $rUuid, 'server_id' => 7, 'gen' => 1, 'token_exp' => time() + 86000, 'tolerance_h' => 99]);
		$rFarDoc = json_decode((string) $rFar['payload'], true);
		$this->assertSame($rFarDoc['iat'] + 26 * 3600, (int) $rFar['exp'], 'the 26 h cap');
	}

	public function testSignedRecordsVerifyWithThePanelClass(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rPub = $rCrypto->init()['panel_sign_pub'];
		$rCmd = json_encode(['v' => 1, 'type' => 'conn.drop', 'exp' => time() + 300, 'iat' => time(), 'cmd_id' => str_repeat('a', 32), 'seq' => 1,
			'node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'gen' => 1, 'dedupe_key' => null, 'args' => ['uuid' => 'x']]);
		$this->assertSame('R', $rCrypto->recordClass('cmd', $rCmd));
		$this->assertTrue(PanelSig::verify($rPub, 'cmd', $rCmd, $rCrypto->sign('cmd', $rCmd)));
		$this->assertFalse(PanelSig::verify($rPub, 'blk', $rCmd, $rCrypto->sign('cmd', $rCmd)));
	}

	/**
	 * The registry the tests hold (tests/Support/cluster_commands.json) is the
	 * one this extension classes by: every type, with and without its
	 * action, with every argument a restrictive type takes and with one it
	 * does not.
	 */
	public function testTheExtensionClassesByTheSharedRegistry(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rCrypto->init();
		$rReg = FakeClusterCrypto::commandRegistry();
		foreach ($rReg['types'] as $rType => $rEntry) {
			$rDoc = ['type' => $rType, 'exp' => time() + 300] + (empty($rEntry['action']) ? [] : ['action' => 'x']);
			if (isset($rEntry['job_types'])) {
				// Restrictive only as a whole (node.purge): every job one of its types.
				$rJobs = array_map(static fn(string $rJob): array => ['type' => $rJob, 'id' => 1], $rEntry['job_types']);
				$this->assertSame('R', $rCrypto->recordClass('cmd', (string) json_encode($rDoc + ['args' => ['jobs' => $rJobs]])), $rType . ' with every job type');
				$this->assertRefused('RECORD:args', $rCrypto, (string) json_encode($rDoc + ['args' => ['jobs' => [['type' => 'update_stream', 'id' => 1]]]]), $rType . ' with a job of another type');
				$this->assertRefused('RECORD:args', $rCrypto, (string) json_encode($rDoc), $rType . ' without its jobs');
				continue;
			}
			$this->assertSame($rEntry['class'], $rCrypto->recordClass('cmd', (string) json_encode($rDoc)), $rType);
			if (isset($rEntry['args'])) {
				$this->assertSame('R', $rCrypto->recordClass('cmd', (string) json_encode($rDoc + ['args' => (object) array_fill_keys($rEntry['args'], 1)])), $rType . ' with every argument');
				$this->assertRefused('RECORD:args', $rCrypto, (string) json_encode($rDoc + ['args' => ['zz' => 1]]), $rType);
			}
			if (!empty($rEntry['action'])) {
				$this->assertRefused('RECORD:action', $rCrypto, (string) json_encode(['type' => $rType, 'exp' => time() + 300, 'args' => ['action' => 'x']]), $rType . ': the action among the arguments');
				foreach ($rEntry['restrictive_actions'] ?? [] as $rAction => $rArgs) {
					$this->assertSame('R', $rCrypto->recordClass('cmd', (string) json_encode(['action' => $rAction] + $rDoc)), $rType . ' ' . $rAction);
				}
			}
		}
		$this->assertRefused('RECORD:type', $rCrypto, (string) json_encode(['type' => 'rm -rf', 'exp' => time() + 300]), 'an unknown type');
	}

	/**
	 * Every command MAIN sends, built by CommandBus and ClusterRoute, against
	 * the real extension: signed, under the class the extension gives it (the
	 * `class` column is its answer). Before, `conn.close`, `node.cache`,
	 * `artefact.fetch`, `node.rpc`, `node.root` and `conn.kill_worker` (its
	 * `rtmp`) were refused with RECORD, and only `conn.drop` was ever tried.
	 */
	public function testEveryCommandMainSendsIsSigned(): void {
		$rCrypto = ClusterCryptoFactory::create();
		$rPub = $rCrypto->init()['panel_sign_pub'];
		$rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql'));
		}
		$rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		DatabaseFactory::set($rDb);
		SettingsManager::set(['cluster_api_enabled' => 1]);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		ClusterRoute::useCrypto(static fn() => $rCrypto);
		try {
			$rSid = 17;
			NodeRegistry::startEnrolment($rSid, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 2);
			NodeRegistry::update($rSid, ['state' => 'active', 'mode' => 2, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONNECTIONS, 'root_ready' => 1]);
			$this->assertSame([true, true], ClusterRoute::send($rSid, ['action' => 'free_temp']));
			$this->assertSame([true, true], ClusterRoute::root($rSid, ['action' => 'reboot']));
			$this->assertSame([true, true], ClusterRoute::cache($rSid, [['type' => 'delete_vod', 'id' => 7]]));
			$this->assertSame([true, true], ClusterRoute::kill($rSid, 4242, true));
			$this->assertSame([true, true], ClusterRoute::drop($rSid, 'viewer1'));
			$this->assertSame([true, true], ClusterRoute::closeConnection($rSid, 'viewer2', false));
			$this->assertSame([true, true], ClusterRoute::rotateNow($rSid));
			CommandBus::enqueue($rCrypto, $rSid, 'config.changed', ['sections' => ['servers']], 'config.changed');
			CommandBus::enqueue($rCrypto, $rSid, 'artefact.fetch', ['artefact' => ['id' => 'offair/banned', 'name' => 'banned.ts', 'size' => 3, 'sha256' => str_repeat('0', 64), 'mtime' => 1, 'ctime' => 1]]);
			$this->assertSame([true, true], ClusterRoute::cache($rSid, [['type' => 'update_stream', 'id' => 7]]), 'a rebuild goes as node.cache');
			$this->assertTrue(ClusterRoute::stop($rSid, 'stream.stop', [7])[0]);
			$this->assertTrue(ClusterRoute::stop($rSid, 'vod.stop', [8])[0]);
			$this->assertTrue(ClusterRoute::start($rSid, 'stream.start', [7])[0]);
			$this->assertTrue(ClusterRoute::start($rSid, 'vod.start', [8])[0]);
			$this->assertSame([true, true], ClusterRoute::fence($rSid, 'test', 1));
			$this->assertSame([true, true], ClusterRoute::resync($rSid));
			CommandBus::enqueue($rCrypto, $rSid, 'policy.update', [], 'policy.update'); // no producer: the policy rides the heartbeat
			CommandBus::enqueue($rCrypto, $rSid, 'node.unfence', []); // its route shares the fence's dedupe key
			CommandBus::enqueue($rCrypto, $rSid, 'stream.assign', ['stream_ids' => [7], 'set' => ['to_analyze' => 1]]);
			CommandBus::enqueue($rCrypto, $rSid, 'queue.poke', []);
			// Last: a quarantined node is handed restrictive commands only.
			$this->assertSame([true, true], ClusterRoute::quarantine($rSid, 'test'));
			$rDb->query('SELECT `type`, `class`, `payload`, `sig` FROM `cluster_commands` WHERE `server_id` = ? ORDER BY `seq`', $rSid);
			$rRows = $rDb->get_raw_rows(); // raw, as CommandBus reads them: the cleaning would rewrite the binary sig
			$this->assertEqualsCanonicalizing(CommandBus::TYPES, array_column($rRows, 'type'));
			foreach ($rRows as $rRow) {
				$this->assertTrue(PanelSig::verify($rPub, 'cmd', (string) $rRow['payload'], (string) $rRow['sig']), $rRow['type']);
				$this->assertSame($rCrypto->recordClass('cmd', (string) $rRow['payload']), $rRow['class'], $rRow['type']);
				$this->assertSame(FakeClusterCrypto::commandRegistry()['types'][$rRow['type']]['class'], $rRow['class'], $rRow['type']);
			}
		} finally {
			ClusterRoute::useCrypto(null);
			ClusterBus::useSocket(null);
			SettingsManager::set([]);
			DatabaseFactory::reset();
		}
	}

	private function assertRefused(string $rReason, \XcVm\Core\Cluster\Crypto\ClusterCrypto $rCrypto, string $rPayload, string $rWhy): void {
		try {
			$rCrypto->sign('cmd', $rPayload);
			$this->fail($rWhy . ': signed');
		} catch (ClusterRefusedException $rE) {
			$this->assertSame($rReason, $rE->reason(), $rWhy);
		}
	}

	/**
	 * Phase 9: the node's fence follows the extension's compiled verdict. The
	 * agent's lease (its state file, Go's base64) is handed to
	 * cluster_lease_store once, and license_valid() then holds on the node with
	 * no activation key at all. MAIN and the node are one install here, so
	 * MAIN packs its pin for itself.
	 */
	public function testTheNodesFenceFollowsTheCompiledLeaseVerdict(): void {
		if (!method_exists('XC_VM', 'cluster_lease_state')) {
			$this->markTestSkipped('xcvm_core without the lease verdict');
		}
		$rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$rCrypto = ClusterCryptoFactory::create();
		$rCrypto->init();
		$this->assertIsArray(\XC_VM::cluster_pin(\XC_VM::cluster_pack(\XC_VM::install_id())));
		$rLease = LeaseService::issue($rCrypto, ['node_uuid' => $rUuid, 'server_id' => 7, 'gen' => 1], time() + 4500);
		$rWork = $this->rDir . '/lease-it';
		@mkdir($rWork);
		file_put_contents($rWork . '/agent.json', (string) json_encode(['node_uuid' => $rUuid, 'lease' => [
			'payload' => base64_encode($rLease['payload']), 'sig' => base64_encode($rLease['sig']), 'iat' => json_decode($rLease['payload'], true)['iat'], 'exp' => $rLease['exp'],
		]]));
		file_put_contents($rWork . '/flows.json', (string) json_encode(['mode' => 1, 'flows' => \XcVm\Core\Cluster\NodeFlows::CONFIG, 'state' => 'active']));
		\XcVm\Core\Cluster\NodeFlows::usePath($rWork . '/flows.json');
		\XcVm\Core\Cluster\NodeLease::usePath($rWork . '/lease_state.json');
		\XcVm\Core\Cluster\NodeLease::useExtension(null, $rWork . '/agent.json');
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]);
		try {
			$rVerdict = \XcVm\Core\Cluster\NodeLease::verdict();
			$this->assertSame([\XcVm\Core\Cluster\NodeLease::SERVING, 'extension', $rLease['exp']], [$rVerdict['state'], $rVerdict['source'], $rVerdict['exp']]);
			$this->assertSame('live', \XC_VM::cluster_lease_state()['state']);
			unlink($this->rDir . '/activation_key');
			$this->assertTrue(\XC_VM::license_valid(), 'the lease licenses the node');
		} finally {
			\XcVm\Core\Cluster\NodeFlows::usePath(null);
			\XcVm\Core\Cluster\NodeLease::usePath(null);
			\XcVm\Core\Cluster\NodeLease::useExtension(null);
			SettingsManager::set([]);
			foreach (['core.pin', 'lease', 'timeanchor'] as $rFile) {
				@unlink($this->rDir . '/cluster/' . $rFile);
			}
			exec('rm -rf ' . escapeshellarg($rWork));
		}
	}

	/** Phase 9: the node's credential actions against the real extension. */
	public function testTheNodesCredentialActionsRunOnTheRealExtension(): void {
		if (!method_exists('XC_VM', 'strip_db_credentials')) {
			$this->markTestSkipped('xcvm_core without credential-free nodes');
		}
		$this->assertTrue(\XC_VM::config_init(['db' => ['host' => '10.0.0.1', 'port' => 3306, 'name' => 'xc_vm', 'user' => 'u', 'pass' => 'p'], 'redis' => ['host' => '10.0.0.1', 'port' => 6379, 'auth' => 'r'], 'server' => ['server_id' => 7, 'is_lb' => 1]]));
		try {
			$rOut = \XcVm\Core\Cluster\NodeCredentials::outcome(\XcVm\Core\Cluster\NodeCredentials::run(['action' => \XcVm\Core\Cluster\NodeCredentials::STRIP]));
			$this->assertSame(['server_id' => 7, 'is_lb' => 1, 'db_credentials' => false, 'redis_auth' => false, 'changed' => true], $rOut);
			$this->assertSame(['server_id' => 7, 'is_lb' => 1], \XC_VM::config_server());
			try {
				\XcVm\Core\Cluster\NodeCredentials::run(['action' => \XcVm\Core\Cluster\NodeCredentials::INSTALL, 'blob' => base64_encode('XCVT-not-for-this-node')]);
				$this->fail('a blob that does not open was installed');
			} catch (\RuntimeException $rE) {
				$this->assertStringContainsString('refused by xcvm_core: CRYPTO', $rE->getMessage());
			}
		} finally {
			@unlink($this->rDir . '/config.enc');
		}
	}
}
