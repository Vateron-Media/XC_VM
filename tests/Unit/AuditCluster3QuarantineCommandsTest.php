<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ArtefactGrants;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeAuthCache;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * A quarantine is MAIN saying it does not know who holds the node's keys
 * (ADR 0004, "Granting commands across a quarantine", option c). Each of the
 * four places that quarantine a node (the page's Quarantine, a hello and a
 * re-key from another instance, a P0 batch that goes back) ends every
 * granting command of the node that is not acked, and its audit line names
 * them. None of them is handed out after Trust again, whatever the node's
 * high-water: the operator sends again what is still wanted. A restrictive
 * command is left as it is.
 *
 * The test agent does what the Go agent does, as in ClusterApiTest.
 */
final class AuditCluster3QuarantineCommandsTest extends TestCase {
	private const SID = 5;

	private const T0 = 1800000000000;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rNodeSk = '';

	private ?string $rLockDir = null;

	private array $rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit', '063_widen_cluster_reservation_identity'] as $rName) {
			$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql'));
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		foreach (['`root_ready` tinyint(1) NOT NULL DEFAULT 0', '`features` varchar(255) DEFAULT NULL', '`audit` text DEFAULT NULL', '`arch` varchar(8) DEFAULT NULL'] as $rColumn) {
			$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN ' . $rColumn);
		}
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 0)');
		$this->rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `pid` int)');
		$this->rDb->exec('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, 5, 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set($this->rSettings);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix(self::T0);
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
	}

	protected function tearDown(): void {
		if ($this->rLockDir !== null) {
			exec('rm -rf ' . escapeshellarg((string) EventIngest::useLockDir($this->rLockDir)));
		}
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	// ── The test agent (as ClusterApiTest's) ─────────────────────────────

	/** Enrol, complete, and switch COMMANDS and STREAMS on; @return array<string, string> the session keys */
	private function active(): array {
		$rPair = sodium_crypto_sign_keypair();
		$this->rNodeSk = sodium_crypto_sign_secretkey($rPair);
		$rEphSk = random_bytes(32);
		$rFirst = EnrolmentService::issueFirst($this->rCrypto, self::SID, $this->rUuid, sodium_crypto_sign_publickey($rPair), sodium_crypto_scalarmult_base(random_bytes(32)), sodium_crypto_scalarmult_base($rEphSk), $this->rSettings, $this->rMain);
		$rBody = Seal::open($rEphSk, 'token', $this->rUuid, (string) $rFirst['token_sealed']);
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rKeys = ClusterReference::sessionKeys((string) hex2bin(json_decode(substr($rBody, 4, $rLen), true)['token']));
		$this->served('enrol_complete', ['instance_id' => 'inst-a', 'agent_version' => '0.1.0'], $rKeys);
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS]);
		return $rKeys;
	}

	/** @return array{0: array, 1: string} response, request context */
	private function call(string $rOp, array $rPayload, array $rKeys): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 1, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rBody = Box::box($rKeys['enc_up'], $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => '1', 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rKeys['mac_up'], $rCtx, $rBody)),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->rSettings, $this->rMain), $rCtx];
	}

	/** A request that is answered 200, its reply opened. */
	private function served(string $rOp, array $rPayload, array $rKeys): array {
		[$rRes, $rCtx] = $this->call($rOp, $rPayload, $rKeys);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, $rRes['status'], $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		return json_decode((string) Box::open($rKeys['enc_down'], $rResCtx, $rRes['body']), true);
	}

	/** A request MAIN refuses because the node is not active (its denial body, panel-signed). */
	private function notActive(array $rRes): void {
		$this->assertSame(409, $rRes['status'], $rRes['body']);
		$this->assertSame(['NOT_ACTIVE', 'quarantined'], [json_decode($rRes['body'], true)['reason'], json_decode($rRes['body'], true)['state']]);
	}

	/** POST token_rekey as the agent does (epoch 0, SEALed to the panel box key), from another instance. */
	private function rekeyFromAClone(): array {
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => []], $this->rSettings, $this->rMain);
		$rChallenge = (string) base64_decode(json_decode($rRes['body'], true)['challenge']);
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . 'token_rekey';
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 0, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rPayload = ['challenge' => base64_encode($rChallenge), 'eph_pub' => base64_encode(sodium_crypto_scalarmult_base(random_bytes(32))), 'instance_id' => 'inst-CLONE'];
		$rBody = Seal::seal($this->rCrypto->info()['panel_box_pub'], 'rekey', $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => '0', 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		return ClusterApi::handle($this->rCrypto, ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'], $this->rSettings, $this->rMain);
	}

	/** @return array{type: string, message: string, vars?: array<string, string>} the page's answer to $rAction */
	private function act(string $rAction): array {
		$rServers = [1 => ['server_name' => 'main', 'is_main' => 1, 'server_type' => 0] + $this->rMain, self::SID => ['server_name' => 'lb', 'is_main' => 0, 'server_type' => 0]];
		return ClusterAdmin::act($this->rCrypto, ['cluster_action' => $rAction, 'server_id' => self::SID], $rServers, 1, $this->rSettings, 3);
	}

	/** Quarantine the node the way $rHow names; the page's answer for 'page'. */
	private function quarantine(string $rHow, array $rKeys): ?array {
		switch ($rHow) {
			case 'page':
				return $this->act('quarantine');
			case 'hello':
				$this->assertSame('quarantined', $this->served('hello', ['instance_id' => 'inst-CLONE'], $rKeys)['state']);
				return null;
			case 'rekey':
				// A node whose tokens are gone asks for a new epoch from another install.
				$this->rDb->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ?', self::SID);
				NodeAuthCache::forget(self::SID);
				$this->notActive($this->rekeyFromAClone());
				return null;
			default:
				$this->rLockDir = EventIngest::useLockDir(sys_get_temp_dir() . '/xcvm-ingest-' . bin2hex(random_bytes(4)) . '/');
				$rState = static fn(int $rPid): array => ['type' => 'stream.state', 'd' => ['stream_id' => 100, 'server_id' => self::SID, 'fields' => ['pid' => $rPid]]];
				$this->assertSame(1, $this->served('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState(42)]], $rKeys)['useq']);
				// Other events under a number MAIN applied: another install.
				$this->notActive($this->call('events', ['lane' => 'p0', 'first_useq' => 1, 'events' => [$rState(7)]], $rKeys)[0]);
				return null;
		}
	}

	/**
	 * An active node with one granting command handed out (`node.rpc
	 * get_pids`), one granting command still queued (`stream.start`) and one
	 * restrictive command queued (`stream.stop`), as before a quarantine.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>} session keys, cmd_ids by type
	 */
	private function nodeWithCommands(): array {
		$rKeys = $this->active();
		$rRpc = CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$this->assertSame([$rRpc], array_map(static fn(array $rC): string => json_decode($rC['doc'], true)['cmd_id'], $this->served('commands', ['after_seq' => 0, 'wait_ms' => 0], $rKeys)['commands']));
		$rStart = CommandBus::enqueue($this->rCrypto, self::SID, 'stream.start', ['stream_id' => 3], 'stream.start:3');
		$rStop = CommandBus::enqueue($this->rCrypto, self::SID, 'stream.stop', ['stream_id' => 4], 'stream.stop:4');
		return [$rKeys, ['node.rpc' => $rRpc, 'stream.start' => $rStart, 'stream.stop' => $rStop]];
	}

	/** @return int the command's exp, 0 once its row is gone */
	private function exp(string $rCmdID): int {
		$this->rDb->query('SELECT `exp` FROM `cluster_commands` WHERE `cmd_id` = ?', $rCmdID);
		return $this->rDb->num_rows() > 0 ? (int) $this->rDb->get_row()['exp'] : 0;
	}

	/** @return array<string, mixed> the detail of the node's quarantine audit line */
	private function quarantineAudit(): array {
		$this->rDb->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'node.quarantine' AND `server_id` = ? ORDER BY `id` DESC LIMIT 1", self::SID);
		$this->assertSame(1, $this->rDb->num_rows(), 'the quarantine is audited');
		return (array) json_decode((string) $this->rDb->get_row()['detail'], true);
	}

	/** @return list<string> the types the long-poll hands out from seq 0 */
	private function handedOut(bool $rQuarantined): array {
		return array_map(static fn(array $rC): string => json_decode($rC['doc'], true)['type'], CommandBus::pending(self::SID, 0, 50, $rQuarantined));
	}

	/** @return array<string, array{0: string}> */
	public static function quarantines(): array {
		return ['the page' => ['page'], 'a hello from another instance' => ['hello'], 'a re-key from another instance' => ['rekey'], 'a P0 batch that goes back' => ['p0']];
	}

	/**
	 * Each quarantine ends the granting commands not acked, handed out or
	 * not, and names them (type, action, cmd_id, handed out); a restrictive
	 * one keeps its life and still reaches the quarantined node.
	 *
	 * @dataProvider quarantines
	 */
	public function testEachQuarantineEndsWhatGrantsAndNamesItInItsAuditLine(string $rHow): void {
		[$rKeys, $rIDs] = $this->nodeWithCommands();
		$rStopExp = $this->exp($rIDs['stream.stop']);
		$this->quarantine($rHow, $rKeys);
		$this->assertSame('quarantined', NodeRegistry::byServer(self::SID)['state']);

		$rNow = ClusterClock::now();
		$this->assertSame($rNow, $this->exp($rIDs['node.rpc']), 'the one handed out is ended');
		$this->assertSame($rNow, $this->exp($rIDs['stream.start']), 'the one still queued is ended');
		$this->assertSame($rStopExp, $this->exp($rIDs['stream.stop']), 'a restrictive one keeps its life');
		$this->assertContains('stream.stop', $this->handedOut(true), 'and still reaches the quarantined node');

		$rDetail = $this->quarantineAudit();
		$this->assertSame(2, $rDetail['ended'] ?? null);
		$this->assertSame([
			['type' => 'node.rpc', 'action' => 'get_pids', 'cmd_id' => $rIDs['node.rpc'], 'handed_out' => true],
			['type' => 'stream.start', 'action' => null, 'cmd_id' => $rIDs['stream.start'], 'handed_out' => false],
		], $rDetail['commands'] ?? null);
	}

	/**
	 * After Trust again the long-poll hands out none of them, from any
	 * high-water: before, one came back wherever the node ran no restrictive
	 * command in between (MAIN's own three quarantines queue none).
	 *
	 * @dataProvider quarantines
	 */
	public function testTrustAgainHandsOutNothingThatWasDecidedBeforeTheQuarantine(string $rHow): void {
		[$rKeys] = $this->nodeWithCommands();
		$this->quarantine($rHow, $rKeys);
		$this->assertTrue(ClusterRoute::trust(self::SID));
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);
		$rTypes = $this->handedOut(false);
		$this->assertNotContains('node.rpc', $rTypes);
		$this->assertNotContains('stream.start', $rTypes);
		$this->assertContains('token.rotate_now', $rTypes, 'Trust again still rotates the token');
	}

	/** The page's confirmation says how many were ended; a quarantine that ends none says what it said before. */
	public function testThePageSaysHowManyWereEnded(): void {
		$this->nodeWithCommands();
		$this->assertSame(['type' => 'success', 'message' => 'cluster_quarantine_ended', 'vars' => ['{ENDED}' => '2']], $this->act('quarantine'));
		$this->assertSame('success', $this->act('trust')['type']);
		$this->assertSame(['type' => 'success', 'message' => 'cluster_quarantine_done'], $this->act('quarantine'), 'nothing left to end');
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');
		$this->assertMatchesRegularExpression('/^cluster_quarantine_ended = ".*\{ENDED\}.*"$/m', $rEn);
	}

	/**
	 * One that was handed out keeps its row for its ack until the next
	 * prune, which drops it (a later ack is refused); a credential strip the
	 * node was handed keeps its row a day, as when a mode down ends it.
	 */
	public function testOneHandedOutKeepsItsRowForItsAckUntilThePruneAndAStripForADay(): void {
		$this->active();
		NodeRegistry::update(self::SID, ['mode' => 2]);
		$rAcked = CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		$rSilent = CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'free_temp']);
		$rStrip = CommandBus::enqueue($this->rCrypto, self::SID, 'node.root', ['action' => NodeCredentials::STRIP], null, DbCredentials::STRIP_TTL);
		$this->assertSame(['node.rpc', 'node.rpc', 'node.root'], $this->handedOut(false));
		$this->assertSame([true, true], ClusterRoute::quarantine(self::SID, 'admin'));

		$this->assertTrue(CommandBus::ack(self::SID, $rAcked, true, '[1]'), 'an ack before the prune is taken');
		CommandBus::prune();
		$this->assertSame([true, '[1]'], CommandBus::result($rAcked));
		$this->assertFalse(CommandBus::ack(self::SID, $rSilent, true, ''), 'its row is gone at the prune, and its ack with it');
		$this->assertSame(ClusterClock::now(), $this->exp($rStrip), 'the strip is ended');
		ClusterClock::fix(self::T0 + 86399000);
		CommandBus::prune();
		$this->assertNotSame(0, $this->exp($rStrip), 'a strip the node was handed keeps its row a day, for its ack');
		ClusterClock::fix(self::T0 + 86401000);
		CommandBus::prune();
		$this->assertSame(0, $this->exp($rStrip), 'then it goes');
	}

	/** An artefact's grant ends with its command: the op serves it no more, before or after Trust again. */
	public function testAnArtefactGrantEndsWithItsCommand(): void {
		$this->active();
		NodeRegistry::update(self::SID, ['features' => ArtefactGrants::FEATURE]);
		$rGrant = CommandBus::enqueue($this->rCrypto, self::SID, ArtefactGrants::TYPE, ['artefact' => ['id' => 'offair/banned', 'name' => 'banned.ts', 'size' => 3, 'sha256' => str_repeat('0', 64), 'mtime' => 1799990000, 'ctime' => 1799990000]]);
		$this->assertNotNull(ArtefactGrants::live(NodeRegistry::byServer(self::SID), $rGrant));
		$this->assertSame([true, true], ClusterRoute::quarantine(self::SID, 'admin'));
		$this->assertNull(ArtefactGrants::live(NodeRegistry::byServer(self::SID), $rGrant));
		$this->assertTrue(ClusterRoute::trust(self::SID));
		$this->assertNull(ArtefactGrants::live(NodeRegistry::byServer(self::SID), $rGrant));
	}
}
