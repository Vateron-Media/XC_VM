<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Break-glass enrolment by code, end to end against the migrations' schema:
 * MAIN issues a code, a test node (doing what `xc_agent enrol` does) sends
 * enrol_code under the code's K_req, the admin approves by SAS, and the node
 * collects epoch 1 with enrol_code_status and completes enrolment.
 */
final class ClusterEnrolCodeTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	private FakeClusterCrypto $rCrypto;

	private array $rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '031_create_cluster_enrolment', '032_create_cluster_audit'] as $rName) {
			$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql'));
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_enrol_requests` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `arch` varchar(8) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set($this->rSettings);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix($this->rT0);
	}

	protected function tearDown(): void {
		\XcVm\Domain\Cluster\LivenessService::useQueueReader(null);
		ClusterClock::fix(null);
		ClusterBus::useSocket(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}


	// ── The test node ────────────────────────────────────────────────────

	/** @return array{code: string, req: string, res: string} */
	private function code(): array {
		$rOut = EnrolCodeService::generate($this->rCrypto, self::SID, 'http://10.0.0.1:25461');
		$rDec = EnrolCodeService::decode($rOut['code']);
		$this->assertNotNull($rDec);
		[$rReq, $rRes] = EnrolCodeService::keys($rDec['secret'], $rDec['server_id']);
		return ['code' => $rOut['code'], 'req' => $rReq, 'res' => $rRes];
	}

	/** @return array{uuid: string, sign_sk: string, sign_pub: string, box_pub: string, eph_sk: string} */
	private function node(): array {
		$rHex = bin2hex(random_bytes(16));
		$rPair = sodium_crypto_sign_keypair();
		$rEph = random_bytes(32);
		return [
			'uuid' => sprintf('%s-%s-4%s-a%s-%s', substr($rHex, 0, 8), substr($rHex, 8, 4), substr($rHex, 13, 3), substr($rHex, 17, 3), substr($rHex, 20, 12)),
			'sign_sk' => sodium_crypto_sign_secretkey($rPair), 'sign_pub' => sodium_crypto_sign_publickey($rPair),
			'box_pub' => sodium_crypto_scalarmult_base(random_bytes(32)), 'eph_sk' => $rEph,
		];
	}

	/** @return array{0: array, 1: array, 2: string} response, request, request context */
	private function send(string $rOp, string $rReqKey, array $rNode, array $rTamper = []): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rType = $rOp === 'enrol_code' ? 'application/octet-stream' : 'application/json';
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => $rType, 'content_encoding' => '', 'node' => 'sid:' . self::SID,
			'epoch' => 0, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		if ($rOp === 'enrol_code') {
			$rBody = Seal::seal($this->rCrypto->info()['panel_box_pub'], 'enrol_code', $rCtx, (string) json_encode([
				'node_uuid' => $rNode['uuid'], 'sign_pub' => base64_encode($rNode['sign_pub']), 'box_pub' => base64_encode($rNode['box_pub']),
				'eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rNode['eph_sk'])), 'instance_id' => 'inst-c',
			]));
		} else {
			$rBody = (string) json_encode(['node_uuid' => $rNode['uuid']]);
		}
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => 'sid:' . self::SID,
			'X-XCVM-Epoch' => '0', 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rReqKey, $rCtx, $rBody)), 'Content-Type' => $rType,
		];
		if ($rOp === 'enrol_code') {
			$rHeaders['X-XCVM-Node-Sig'] = bin2hex(NodeSig::sign($rNode['sign_sk'], 'request', $rCtx . hash('sha256', $rBody, true)));
		}
		if (isset($rTamper['headers'])) {
			$rHeaders = $rTamper['headers']($rHeaders);
		}
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.9.9.9'];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->rSettings, $this->rMain), $rReq, $rCtx];
	}

	/** Verify a K_res reply as the node does. */
	private function reply(array $rRes, string $rCtx, string $rResKey): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, 200, $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		$this->assertTrue(Canonical::verifyMac($rResKey, $rResCtx, $rRes['body'], (string) hex2bin($rH['X-XCVM-Sig'])), 'reply MAC under K_res');
		return json_decode($rRes['body'], true);
	}

	private function denial(array $rRes, int $rStatus, string $rReason, array $rReq): void {
		$this->assertSame($rStatus, $rRes['status'], $rRes['body']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($rReason, $rDoc['reason']);
		$this->assertSame('sid:' . self::SID, $rDoc['node']);
		$this->assertSame($rReq['headers']['X-XCVM-Nonce'], $rDoc['req_nonce']);
	}

	// ── Tests ────────────────────────────────────────────────────────────

	public function testCodeRoundTrips(): void {
		$rCode = EnrolCodeService::encode(42, 'http://[fd00::1]:8080', str_repeat("\x01", 16), str_repeat("\x02", 16));
		$this->assertMatchesRegularExpression('/^[A-Z2-7]{1,4}(-[A-Z2-7]{1,4})+$/', $rCode);
		$rDec = EnrolCodeService::decode(strtolower(str_replace('-', ' ', $rCode)));
		$this->assertSame(['server_id' => 42, 'main_url' => 'http://[fd00::1]:8080', 'fp' => str_repeat("\x01", 16), 'secret' => str_repeat("\x02", 16)], $rDec);
		$this->assertNull(EnrolCodeService::decode('not a code!'));
		$this->assertNull(EnrolCodeService::decode(substr($rCode, 0, -5)), 'truncated');
		// The fingerprint in a generated code is the panel key's.
		$rGen = EnrolCodeService::decode(EnrolCodeService::generate($this->rCrypto, self::SID, 'http://10.0.0.1:25461')['code']);
		$this->assertSame(substr(hash('sha256', $this->rCrypto->info()['panel_sign_pub'], true), 0, 16), $rGen['fp']);
		$this->assertSame(self::SID, $rGen['server_id']);
	}

	public function testEnrolByCodeEndToEnd(): void {
		$rCode = $this->code();
		$rNode = $this->node();
		[$rRes, , $rCtx] = $this->send('enrol_code', $rCode['req'], $rNode);
		$this->assertSame('pending_approval', $this->reply($rRes, $rCtx, $rCode['res'])['state']);
		// A retried request (lost reply) with the same keys is accepted as it stands.
		[$rRes, , $rCtx] = $this->send('enrol_code', $rCode['req'], $rNode);
		$this->assertSame('pending_approval', $this->reply($rRes, $rCtx, $rCode['res'])['state']);

		[$rRes, , $rCtx] = $this->send('enrol_code_status', $rCode['req'], $rNode);
		$this->assertSame('pending_approval', $this->reply($rRes, $rCtx, $rCode['res'])['state']);
		$this->assertNull(NodeRegistry::byServer(self::SID), 'nothing is enrolled before approval');

		$this->assertSame('wrong_sas', EnrolCodeService::approve($this->rCrypto, self::SID, 'AAAA-AAAA-AAAA-AAAA-AAAA-AAAA', $this->rSettings, $this->rMain));
		$rSas = EnrolmentService::sas($rNode['uuid'], $rNode['sign_pub'], $rNode['box_pub']);
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, strtolower($rSas), $this->rSettings, $this->rMain));
		$this->assertSame('none', EnrolCodeService::approve($this->rCrypto, self::SID, $rSas, $this->rSettings, $this->rMain), 'decided');

		[$rRes, , $rCtx] = $this->send('enrol_code_status', $rCode['req'], $rNode);
		$rDoc = $this->reply($rRes, $rCtx, $rCode['res']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'pre', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])), 'approval is panel-signed');
		$this->assertSame('xcvm-enrol-approved', $rDoc['typ']);
		$this->assertSame($rNode['uuid'], $rDoc['node_uuid']);
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], $rDoc['cluster']['policy']['main_urls']);
		$this->assertSame('enrolling', NodeRegistry::byServer(self::SID)['state']);
		// The token's lease travels inside the signed approval, so a node enrolled
		// by code holds one from its first epoch, as an SSH-enrolled one does.
		$rLeaseDoc = json_decode((string) base64_decode($rDoc['lease']['payload'], true), true);
		$this->assertSame(['xcvm-lease', $rNode['uuid'], (int) $rDoc['lease']['exp']], [$rLeaseDoc['typ'], $rLeaseDoc['node_uuid'], $rLeaseDoc['exp']]);
		$this->assertSame(
			ClusterReference::panelSign($this->rCrypto->rSeed, 'lea', (string) base64_decode($rDoc['lease']['payload'], true)),
			(string) base64_decode($rDoc['lease']['sig'], true),
			'signed under tag lea'
		);

		// The token opens with the node's per-epoch key; enrol_complete activates.
		$rBody = Seal::open($rNode['eph_sk'], 'token', $rNode['uuid'], (string) base64_decode($rDoc['token_sealed']));
		$this->assertNotNull($rBody);
		$rTok = json_decode(substr($rBody, 4, unpack('N', substr($rBody, 0, 4))[1]), true);
		$rKeys = ClusterReference::sessionKeys((string) hex2bin($rTok['token']));
		$rNonce = random_bytes(16);
		$rReqCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => '/cluster/v1/enrol_complete', 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $rNode['uuid'], 'epoch' => 1,
			'ts_ms' => ClusterClock::nowMs(), 'nonce' => $rNonce,
		]);
		$rBoxed = Box::box($rKeys['enc_up'], $rReqCtx, (string) json_encode(['instance_id' => 'inst-c']));
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'POST', 'path' => '/cluster/v1/enrol_complete', 'query' => '', 'headers' => [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $rNode['uuid'], 'X-XCVM-Epoch' => '1',
			'X-XCVM-Ts' => (string) ClusterClock::nowMs(), 'X-XCVM-Nonce' => bin2hex($rNonce), 'Content-Type' => 'application/octet-stream',
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rKeys['mac_up'], $rReqCtx, $rBoxed)),
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($rNode['sign_sk'], 'request', $rReqCtx . hash('sha256', $rBoxed, true))),
		], 'body' => $rBoxed], $this->rSettings, $this->rMain);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);
	}

	public function testOnAFreshBusCodeRequestsStampedAtItsFloorAreRefusedWithAWait(): void {
		$rBus = BusServer::start('code-bus');
		if ($rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		try {
			ClusterBus::useSocket($rBus->socket());
			$rCode = $this->code();
			$rNode = $this->node();
			[$rRes, $rReq] = $this->send('enrol_code', $rCode['req'], $rNode);
			$this->denial($rRes, 401, 'REPLAY', $rReq);
			$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, json_decode($rRes['body'], true)['retry_after_ms']);
			ClusterClock::fix($this->rT0 + 1000);
			[$rRes, , $rCtx] = $this->send('enrol_code', $rCode['req'], $rNode);
			$this->assertSame('pending_approval', $this->reply($rRes, $rCtx, $rCode['res'])['state']);

			// The bus restarts and loses what it held: the status poll is at its floor.
			$rBus->restart();
			ClusterBus::useSocket($rBus->socket());
			[$rRes, $rReq] = $this->send('enrol_code_status', $rCode['req'], $rNode);
			$this->denial($rRes, 401, 'REPLAY', $rReq);
			$this->assertSame(NonceStore::LEAD_MS + 1 + NonceStore::RETRY_MARGIN_MS, json_decode($rRes['body'], true)['retry_after_ms']);
			ClusterClock::fix($this->rT0 + 2000);
			[$rRes, , $rCtx] = $this->send('enrol_code_status', $rCode['req'], $rNode);
			$this->assertSame('pending_approval', $this->reply($rRes, $rCtx, $rCode['res'])['state']);
		} finally {
			$rBus->stop();
		}
	}

	public function testAWrongMacChangesNothing(): void {
		$rCode = $this->code();
		$rNode = $this->node();
		[$rRes, $rReq] = $this->send('enrol_code', random_bytes(32), $rNode);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		$this->assertNull(EnrolCodeService::request(self::SID));
		[$rRes, , $rCtx] = $this->send('enrol_code', $rCode['req'], $rNode);
		$this->reply($rRes, $rCtx, $rCode['res']);

		// Signed by another key than the one it asks to enrol.
		$rOther = $this->node();
		$rCode = $this->code();
		[$rRes, $rReq] = $this->send('enrol_code', $rCode['req'], $rNode, ['headers' => static function ($h) use ($rOther) {
			$h['X-XCVM-Node-Sig'] = bin2hex(NodeSig::sign($rOther['sign_sk'], 'request', 'x'));
			return $h;
		}
		]);
		$this->denial($rRes, 401, 'BAD_NODE_SIG', $rReq);
	}

	public function testOtherKeysUnderAUsedCodeConflict(): void {
		$rCode = $this->code();
		$rNode = $this->node();
		[$rRes] = $this->send('enrol_code', $rCode['req'], $rNode);
		$this->assertSame(200, $rRes['status']);
		[$rRes, $rReq] = $this->send('enrol_code', $rCode['req'], $this->node());
		$this->denial($rRes, 409, 'ENROL_CONFLICT', $rReq);
		$this->assertSame($rNode['uuid'], EnrolCodeService::request(self::SID)['node_uuid'], 'the first request stands');
	}

	public function testFiveWrongSasReject(): void {
		$rCode = $this->code();
		$rNode = $this->node();
		$this->send('enrol_code', $rCode['req'], $rNode);
		for ($i = 1; $i < EnrolCodeService::MAX_ATTEMPTS; $i++) {
			$this->assertSame('wrong_sas', EnrolCodeService::approve($this->rCrypto, self::SID, 'WRONG', $this->rSettings, $this->rMain));
		}
		$this->assertSame('rejected', EnrolCodeService::approve($this->rCrypto, self::SID, 'WRONG', $this->rSettings, $this->rMain));
		$rSas = EnrolmentService::sas($rNode['uuid'], $rNode['sign_pub'], $rNode['box_pub']);
		$this->assertSame('none', EnrolCodeService::approve($this->rCrypto, self::SID, $rSas, $this->rSettings, $this->rMain));
		[$rRes, , $rCtx] = $this->send('enrol_code_status', $rCode['req'], $rNode);
		$this->assertSame('rejected', $this->reply($rRes, $rCtx, $rCode['res'])['state']);
		$this->assertNull(NodeRegistry::byServer(self::SID));
	}

	public function testExpiredAndSupersededCodes(): void {
		$rOld = $this->code();
		$rNew = $this->code();
		[$rRes, $rReq] = $this->send('enrol_code', $rOld['req'], $this->node());
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		ClusterClock::fix($this->rT0 + (EnrolCodeService::TTL + 1) * 1000);
		[$rRes, $rReq] = $this->send('enrol_code', $rNew['req'], $this->node());
		$this->denial($rRes, 401, 'CODE_INVALID', $rReq);
		[$rRes, $rReq] = $this->send('enrol_code_status', $rNew['req'], $this->node());
		$this->denial($rRes, 401, 'CODE_INVALID', $rReq);
	}

	public function testATamperedCodeRowIsRefused(): void {
		$rCode = $this->code();
		$this->rDb->query('UPDATE `cluster_enrol_codes` SET `exp` = `exp` + 86400');
		[$rRes, $rReq] = $this->send('enrol_code', $rCode['req'], $this->node());
		$this->denial($rRes, 401, 'CODE_INVALID', $rReq);
	}

	public function testApprovalNeedsALicence(): void {
		$rCode = $this->code();
		$rNode = $this->node();
		$this->send('enrol_code', $rCode['req'], $rNode);
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		$rSas = EnrolmentService::sas($rNode['uuid'], $rNode['sign_pub'], $rNode['box_pub']);
		try {
			EnrolCodeService::approve($this->rCrypto, self::SID, $rSas, $this->rSettings, $this->rMain);
			$this->fail('approved without a licence');
		} catch (ClusterRefusedException $rE) {
			$this->assertSame('LICENCE', $rE->reason());
		}
		$this->assertSame('pending_approval', EnrolCodeService::request(self::SID)['state']);
	}

	public function testCronHousekeeping(): void {
		$this->code();
		\XcVm\Domain\Cluster\NonceStore::claim('sid:5', random_bytes(16));
		$this->rDb->query('INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `nbf`, `exp`, `refresh_at`, `used`, `created_at`) VALUES (5, 1, ?, 0, ?, 0, 1, 0)', '{}', intdiv($this->rT0, 1000) + 60);
		ClusterClock::fix($this->rT0 + (EnrolCodeService::TTL + 1) * 1000);
		// Its liveness step probes no real cluster_ctl socket under MAIN_HOME: the pool cannot tell.
		\XcVm\Domain\Cluster\LivenessService::useQueueReader(static fn(): ?int => null);
		\XcVm\Cli\CronJobs\ClusterCronJob::housekeep();
		foreach (['cluster_enrol_codes', 'cluster_nonces', 'cluster_node_epochs'] as $rTable) {
			$this->rDb->query('SELECT COUNT(*) AS `n` FROM `' . $rTable . '`');
			$this->assertSame(0, (int) $this->rDb->get_row()['n'], $rTable);
		}
	}

	public function testAdminPageActions(): void {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0, 'server_name' => 'Main'] + $this->rMain, 5 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'LB-5'], 9 => ['is_main' => 0, 'server_type' => 1, 'server_name' => 'Proxy']];
		$rAct = fn(array $rInput) => \XcVm\Domain\Cluster\ClusterAdmin::act($this->rCrypto, $rInput, $rServers, 1, $this->rSettings, 7);
		$this->assertSame([5 => 'LB-5'], \XcVm\Domain\Cluster\ClusterAdmin::loadBalancers($rServers));
		$this->assertSame('cluster_not_a_load_balancer', $rAct(['cluster_action' => 'code', 'server_id' => 9])['message']);
		$this->assertSame('cluster_bad_main_url', $rAct(['cluster_action' => 'code', 'server_id' => 5, 'url' => 'ftp://x'])['message']);

		$rOut = $rAct(['cluster_action' => 'code', 'server_id' => 5]);
		$this->assertSame('cluster_code_issued', $rOut['message']);
		$rDec = EnrolCodeService::decode($rOut['code']);
		$this->assertSame('http://10.0.0.1:25461', $rDec['main_url'], 'defaults to the policy URL');
		[$rReq] = EnrolCodeService::keys($rDec['secret'], 5);
		$rNode = $this->node();
		$this->send('enrol_code', $rReq, $rNode);
		$rPending = \XcVm\Domain\Cluster\ClusterAdmin::pending($rServers);
		$this->assertSame(['LB-5', $rNode['uuid']], [$rPending[0]['server_name'], $rPending[0]['node_uuid']]);

		$this->assertSame('cluster_wrong_sas', $rAct(['cluster_action' => 'approve', 'server_id' => 5, 'sas' => 'nope'])['message']);
		$rSas = EnrolmentService::sas($rNode['uuid'], $rNode['sign_pub'], $rNode['box_pub']);
		$this->assertSame('cluster_enrol_approved', $rAct(['cluster_action' => 'approve', 'server_id' => 5, 'sas' => $rSas])['message']);
		$this->assertSame([], \XcVm\Domain\Cluster\ClusterAdmin::pending($rServers));
		$rNodes = \XcVm\Domain\Cluster\ClusterAdmin::nodes($rServers, 30);
		$this->assertSame(['LB-5', 'enrolling'], [$rNodes[0]['server_name'], $rNodes[0]['health']]);
		$this->rDb->query('SELECT `decided_by` FROM `cluster_enrol_requests` WHERE `server_id` = 5');
		$this->assertSame(7, (int) $this->rDb->get_row()['decided_by'], 'the deciding admin is recorded');

		$this->assertSame('cluster_node_revoked', $rAct(['cluster_action' => 'revoke', 'server_id' => 5])['message']);
		$this->assertSame('revoked', \XcVm\Domain\Cluster\ClusterAdmin::nodes($rServers, 30)[0]['health']);
		$this->assertSame('cluster_unknown_action', $rAct(['cluster_action' => 'x', 'server_id' => 5])['message']);
	}

	/** A MAIN URL with a trailing newline is refused (`$` would match before it). */
	public function testAMainUrlWithATrailingNewlineIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		EnrolCodeService::generate($this->rCrypto, self::SID, "http://10.0.0.1:25461\n");
	}
}
