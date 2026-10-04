<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Server\SettingsService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

if (!defined('STATUS_FAILURE')) {
	define('STATUS_FAILURE', 0);
}
if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_INVALID_DATA')) {
	define('STATUS_INVALID_DATA', 17);
}

/**
 * `cluster_transport = https_required` with MAIN's HTTPS gone (plan section
 * 3, "Endpoints and HTTPS"; the drill "https_required with the certificate
 * removed, recovered by switching back to auto"):
 *
 * - over plain HTTP, MAIN serves only `GET challenge` (and `health`); every
 *   other op gets a signed HTTPS_REQUIRED;
 * - agents poll the panel-signed challenge over HTTP, which carries the
 *   transport policy;
 * - an admin who switches back to `auto` in Settings recovers the fleet
 *   without SSH: the next challenge carries the HTTP URLs;
 * - every change of the policy raises `cluster_policy_ver`, and agents adopt
 *   a policy only if its version is not lower than theirs, so the policy
 *   never goes backwards: an older signed policy replayed by a MITM is not
 *   adopted, and a settings form cannot lower the version.
 *
 * The switches go through SettingsService::edit(), the admin's save path;
 * the HTTPS self-probe is faked. The node is a test agent doing what the Go
 * agent does, including its policy rule.
 */
final class HttpsRequiredRecoveryTest extends TestCase {
	private const SID = 5;

	/** The settings columns the saves touch: name => [data_type, default]. */
	private const COLUMNS = [
		'cluster_api_enabled' => ['int', '0'],
		'cluster_api_port' => ['int', '0'],
		'cluster_transport' => ['varchar', 'auto'],
		'cluster_main_host' => ['varchar', ''],
		'cluster_policy_ver' => ['int', '1'],
		'cluster_legacy_ports' => ['varchar', ''],
		'cluster_legacy_urls' => ['mediumtext', ''],
		'lb_token_rotation_min' => ['int', '60'],
		'lb_revocation_mode' => ['varchar', 'graceful'],
		'lb_new_node_mode' => ['varchar', 'legacy'],
		'search_items' => ['int', '15'],
		'disable_table_responsive' => ['int', '0'],
		'allowed_stb_types_for_local_recording' => ['text', ''],
		'allowed_stb_types' => ['text', ''],
		'maxmind_editions' => ['text', ''],
		'shared_mount_prefixes' => ['text', ''],
		'allow_countries' => ['text', ''],
		'dropbox_remote' => ['int', '0'],
	];

	private TestDb $rDb;

	private string $rDir;

	private FakeClusterCrypto $rCrypto;

	private array $rMain = [
		'id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'domain_name' => 'panel.example.com',
		'enable_https' => 1, 'http_broadcast_port' => 25461, 'https_broadcast_port' => 25463,
	];

	private string $rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rNodeSk = '';

	/** @var array<string, string> the node's session keys */
	private array $rKeys = [];

	/** @var array{policy_ver: int, transport: string, main_urls: list<string>} the policy the agent follows */
	private array $rPolicy;

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-https-req-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cache', 0777, true);
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', $this->rDir . 'cache/');
		}

		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec((string) $rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 0)');
		$this->rDb->exec('CREATE TABLE `streams_arguments` (`argument_key` varchar(64), `argument_default_value` varchar(255))');

		// The settings row, and information_schema for QueryHelper::verifyPostTable().
		$rDdl = [];
		foreach (self::COLUMNS as $rName => [$rType, $rDefault]) {
			$rDdl[] = '`' . $rName . '` ' . ($rType === 'int' ? 'int' : 'varchar(255)') . " DEFAULT '" . $rDefault . "'";
		}
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, ' . implode(', ', $rDdl) . ')');
		$this->rDb->exec('INSERT INTO `settings` (`id`, `cluster_api_enabled`) VALUES (1, 1)');
		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		FileCache::setCache('servers', [1 => $this->rMain]);
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set($this->settings());
		unset($_COOKIE['lang']);
		Translator::init(MAIN_HOME . 'Core/Localization/lang');

		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix(1800000000000);
		// MAIN's certificate verifies, for now.
		ClusterSettings::useHttpsProbe(static fn(array $rMain): array => ['ok' => true, 'reason' => 'OK', 'host' => 'panel.example.com', 'days_left' => 80]);
	}

	protected function tearDown(): void {
		ClusterSettings::useHttpsProbe(null);
		ClusterClock::fix(null);
		SettingsManager::set($this->rSettingsBefore);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		unset($GLOBALS['db']);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The stored settings, as the cluster API's front controller reads them. */
	private function settings(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	/** The admin saves Settings (the Cluster tab's fields among the rest). */
	private function save(array $rFields): array {
		$rResult = SettingsService::edit(['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15'] + $rFields);
		SettingsManager::set($this->settings());
		return $rResult;
	}

	/** The agent's rule: a policy is adopted only if it is not older than the one it follows. */
	private function adopt(array $rPolicy): bool {
		if ($rPolicy['policy_ver'] < $this->rPolicy['policy_ver']) {
			return false;
		}
		$this->rPolicy = $rPolicy;
		return true;
	}

	/** GET challenge?cn= as the agent sends it; @return array the signed document, verified as the agent does */
	private function challenge(bool $rHttps): array {
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => [], 'https' => $rHttps], $this->settings(), $this->rMain);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])), 'panel-signed');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($this->rUuid, $rDoc['cn'], 'bound to this node');
		return $rDoc;
	}

	/** A session op as the agent sends it; @return array{0: array, 1: string, 2: array} response, request context, request */
	private function call(string $rOp, array $rPayload, bool $rHttps): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 1, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rBody = Box::box($this->rKeys['enc_up'], $rCtx, (string) json_encode($rPayload));
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'ip' => '10.0.0.5', 'body' => $rBody, 'https' => $rHttps, 'headers' => [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid, 'X-XCVM-Epoch' => '1',
			'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce), 'Content-Type' => 'application/octet-stream',
			'X-XCVM-Sig' => bin2hex(Canonical::mac($this->rKeys['mac_up'], $rCtx, $rBody)),
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		]];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->settings(), $this->rMain), $rCtx, $rReq];
	}

	private function reply(array $rRes, string $rCtx): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, 200, $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		$this->assertTrue(Canonical::verifyMac($this->rKeys['mac_down'], $rResCtx, $rRes['body'], (string) hex2bin($rH['X-XCVM-Sig'])));
		return json_decode((string) Box::open($this->rKeys['enc_down'], $rResCtx, $rRes['body']), true);
	}

	/** The agent's heartbeat: it says hello again when the reply names a newer policy. */
	private function heartbeat(bool $rHttps): array {
		[$rRes, $rCtx] = $this->call('heartbeat', [], $rHttps);
		$rBeat = $this->reply($rRes, $rCtx);
		if ($rBeat['policy_ver'] > $this->rPolicy['policy_ver']) {
			[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a'], $rHttps);
			$this->assertTrue($this->adopt($this->reply($rRes, $rCtx)['policy']));
		}
		return $rBeat;
	}

	private function storedVer(): int {
		return (int) $this->settings()['cluster_policy_ver'];
	}

	public function testAFleetCutOffByHttpsRequiredRecoversOverTheHttpChallenge(): void {
		// Before: auto, plain HTTP. A MITM on the path records a challenge.
		$rOldDoc = $this->challenge(false);
		$this->assertSame(['policy_ver' => 1, 'transport' => 'auto'], array_intersect_key($rOldDoc['policy'], ['policy_ver' => 1, 'transport' => 1]));
		$this->assertStringStartsWith('http://', $rOldDoc['policy']['main_urls'][0]);

		// The admin requires HTTPS (the self-probe passes, no node is active yet).
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_required'])['status']);
		$this->assertSame(2, $this->storedVer(), 'a new transport is a new policy');

		// A node is installed and enrols over HTTPS.
		$rPair = sodium_crypto_sign_keypair();
		$this->rNodeSk = sodium_crypto_sign_secretkey($rPair);
		$rEphSk = random_bytes(32);
		$rFirst = EnrolmentService::issueFirst($this->rCrypto, self::SID, $this->rUuid, sodium_crypto_sign_publickey($rPair), sodium_crypto_scalarmult_base(random_bytes(32)), sodium_crypto_scalarmult_base($rEphSk), $this->settings(), $this->rMain);
		$this->rPolicy = $rFirst['cluster']['policy'];
		$this->assertSame(['policy_ver' => 2, 'transport' => 'https_required', 'heartbeat_sec' => 2, 'main_urls' => ['https://panel.example.com:25463/cluster/v1/']], $this->rPolicy);
		$rBody = (string) Seal::open($rEphSk, 'token', $this->rUuid, (string) $rFirst['token_sealed']);
		$this->rKeys = ClusterReference::sessionKeys((string) hex2bin(json_decode(substr($rBody, 4, unpack('N', substr($rBody, 0, 4))[1]), true)['token']));
		[$rRes] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'agent_version' => '0.1.0'], true);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$this->assertSame(2, $this->heartbeat(true)['policy_ver']);

		// MAIN's certificate is removed: HTTPS fails, and plain HTTP is refused
		// for everything but the challenge (a signed denial, bound to the
		// request): every op MAIN serves, the ingest ones too.
		$rOps = array_diff(array_keys((new \ReflectionClassConstant(ClusterApi::class, 'OPS'))->getValue()), ['health', 'challenge']);
		$this->assertContains('events', $rOps);
		foreach ($rOps as $rOp) {
			[$rRes, , $rReq] = $this->call($rOp, [], false);
			$this->assertSame(403, $rRes['status'], $rOp);
			$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
			$rDoc = json_decode($rRes['body'], true);
			$this->assertSame(['HTTPS_REQUIRED', $this->rUuid, $rReq['headers']['X-XCVM-Nonce']], [$rDoc['reason'], $rDoc['node'], $rDoc['req_nonce']], $rOp);
		}
		$rSeen = (int) NodeRegistry::byServer(self::SID)['last_seen_at'];

		// The agent polls the challenge over HTTP every 60 s. Still required: no change.
		ClusterClock::fix(1800000060000);
		$rDoc = $this->challenge(false);
		$this->assertTrue($this->adopt($rDoc['policy']));
		$this->assertSame('https_required', $this->rPolicy['transport']);

		// A MITM replays the challenge it recorded before: a genuine panel
		// signature, but an older policy, which the agent does not adopt.
		$this->assertFalse($this->adopt($rOldDoc['policy']), 'the policy never goes backwards');
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/'], $this->rPolicy['main_urls']);

		// The admin switches back to auto (no probe needed, nodes active).
		ClusterSettings::useHttpsProbe(static fn(array $rMain): array => ['ok' => false, 'reason' => 'tls_failed', 'host' => 'panel.example.com', 'days_left' => null]);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'auto'])['status']);
		$this->assertSame(3, $this->storedVer());

		// The next challenge over HTTP carries the new policy; the agent adopts
		// it and is back, with no SSH.
		ClusterClock::fix(1800000120000);
		$rDoc = $this->challenge(false);
		$this->assertTrue($this->adopt($rDoc['policy']));
		$this->assertSame(['policy_ver' => 3, 'transport' => 'auto', 'heartbeat_sec' => 2, 'main_urls' => ['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/']], $this->rPolicy);
		$this->assertSame(3, $this->heartbeat(false)['policy_ver'], 'over plain HTTP, on the policy it follows');
		$this->assertGreaterThan($rSeen, (int) NodeRegistry::byServer(self::SID)['last_seen_at'], 'MAIN hears the node again');
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);
	}

	public function testThePolicyVersionNeverGoesBackwards(): void {
		$this->assertSame(1, $this->storedVer());
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_preferred'])['status']);
		$this->assertSame(2, $this->storedVer());
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_preferred'])['status']);
		$this->assertSame(2, $this->storedVer(), 'a save that changes no policy announces nothing');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'main.example.com'])['status']);
		$this->assertSame(3, $this->storedVer(), 'MAIN\'s DNS name is in the URLs too');

		// The version (and the kept ports and URLs) are MAIN's own state: a form
		// that posts them cannot rewind the policy, nor drop an old URL early.
		$rKeptUrls = '{"http://10.0.0.9:25461/cluster/v1/":1800000000}';
		$this->rDb->query('UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_legacy_urls` = ?', '{"25461":1800000000}', $rKeptUrls);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_policy_ver' => '1', 'cluster_legacy_ports' => '', 'cluster_legacy_urls' => ''])['status']);
		$this->assertSame(3, $this->storedVer());
		$this->assertSame('{"25461":1800000000}', $this->settings()['cluster_legacy_ports']);
		$this->assertSame($rKeptUrls, $this->settings()['cluster_legacy_urls']);
		// Nor can the backup settings form, which stores any settings column posted to it.
		$this->assertSame(STATUS_SUCCESS, SettingsService::editBackup(['cluster_policy_ver' => '1', 'cluster_legacy_ports' => '', 'cluster_legacy_urls' => '{"https://attacker.example:22/cluster/v1/":4102444800}'])['status']);
		$this->assertSame([3, '{"25461":1800000000}', $rKeptUrls], [$this->storedVer(), $this->settings()['cluster_legacy_ports'], $this->settings()['cluster_legacy_urls']]);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_policy_ver' => '1', 'cluster_transport' => 'auto'])['status']);
		$this->assertSame(4, $this->storedVer());

		// A refused switch (no working HTTPS) announces nothing.
		ClusterSettings::useHttpsProbe(static fn(array $rMain): array => ['ok' => false, 'reason' => 'tls_failed', 'host' => 'panel.example.com', 'days_left' => null]);
		$this->assertSame(STATUS_INVALID_DATA, $this->save(['cluster_transport' => 'https_required'])['status']);
		$this->assertSame(['auto', 4], [$this->settings()['cluster_transport'], $this->storedVer()]);
	}

	public function testHealthAndTheChallengeStayOnPlainHttp(): void {
		$this->rDb->query("UPDATE `settings` SET `cluster_transport` = 'https_required'");
		$this->assertSame('https_required', $this->challenge(false)['policy']['transport']);
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/health', 'headers' => [], 'https' => false], [], []);
		$this->assertSame(200, $rRes['status'], 'health needs neither the settings nor the database');
		// A request with no node headers still gets a signed denial it cannot mistake for another's.
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'POST', 'path' => '/cluster/v1/heartbeat', 'headers' => [], 'body' => '', 'https' => false], $this->settings(), $this->rMain);
		$this->assertSame(403, $rRes['status']);
		$this->assertSame([null, null], [json_decode($rRes['body'], true)['node'], json_decode($rRes['body'], true)['req_nonce']]);
	}

	public function testMainLearnsTheTransportFromNginx(): void {
		// The only way ClusterApi learns a request came over TLS: nginx's
		// HTTPS param, which the front controller turns into the 'https' flag.
		$rSrc = MAIN_HOME;
		$this->assertMatchesRegularExpression('#\'https\' => !empty\(\$_SERVER\[\'HTTPS\'\]\) && strtolower\(\(string\) \$_SERVER\[\'HTTPS\'\]\) !== \'off\',#', (string) file_get_contents($rSrc . 'Public/cluster/index.php'));
		$this->assertMatchesRegularExpression('#^fastcgi_param\s+HTTPS\s+\$https if_not_empty;$#m', (string) file_get_contents($rSrc . 'bin/nginx/conf/fastcgi_params'));
		$this->assertStringContainsString("    include fastcgi_params;\n", ClusterNginxConfig::locations(), 'the cluster API location passes it');
	}
}
