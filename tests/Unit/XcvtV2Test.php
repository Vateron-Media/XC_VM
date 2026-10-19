<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Cli\Commands\ServerXcvtPinCommand;
use XcVm\Cli\Commands\ServerXcvtReportCommand;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\CorePins;
use XcVm\Domain\Cluster\NodeConfigPack;
use XcVm\Domain\Cluster\XcvtTargets;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\FakeSshFleet;

/**
 * MAIN's side of XCVT v2 (xcvm_core's ADR-005): the record MAIN keeps per
 * load balancer and its predicate (XcvtTargets), the one owner of
 * cluster_init (ensureRoot), the one packer (NodeConfigPack), the install's
 * bootstrap order and readback (LbInstallFlow), the backfill over SSH
 * (server:xcvt-pin) and over the cluster API (CorePins), the N+1 gate
 * (server:xcvt-report), and the re-probe after a failed install_config.
 */
final class XcvtV2Test extends TestCase {
	private const SID = 7;

	private const IID = '6c1b5bd2-2c86-4a4a-9a57-0f6e6d3b1f10';

	private const KEY = 'abababababababababababababababababababababababababababababababab';

	private const NEW_KEY = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	/** @var list<string> commands the fake node ran, and "SEND <path>" for each upload */
	private array $rCommands = [];

	/** @var array<string, string> how the fake node answers: install_id, panel_pk, cluster_pin, cluster_pinned, readback */
	private array $rNode = [];

	/** @var list<array{0: string, 1: array<string, mixed>, 2: ?string}> what NodeConfigPack packed */
	private array $rPacked = [];

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'TMP_PATH' => sys_get_temp_dir() . '/xcvm-test-tmp/', 'CONFIG_PATH' => sys_get_temp_dir() . '/xcvm-test-config/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		@mkdir(TMP_PATH, 0700, true);
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` mediumtext, `updated_at` int)');
		$this->rDb->exec('CREATE TABLE `cluster_commands` (`server_id` int, `cmd_id` char(32), `type` varchar(32), `payload` text)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 2, `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `install_id` varchar(64) DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `server_name` varchar(255), `server_ip` varchar(255), `server_type` int DEFAULT 0, `is_main` int DEFAULT 0, `enabled` int DEFAULT 1, `status` int DEFAULT 0, `ssh_hostkey_sha1` char(40) DEFAULT NULL)');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `is_main`) VALUES (1, 'main', '10.0.0.1', 1), (7, 'lb-7', '10.0.0.7', 0)");
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000 * 1000);
		$this->rCrypto = new FakeClusterCrypto();
		XcvtTargets::useCrypto($this->rCrypto);
		$this->recordRoot();
		$this->rNode = ['install_id' => self::IID, 'panel_pk' => 'PK ' . self::KEY, 'cluster_pin' => 'OK ' . $this->fp(), 'cluster_pinned' => 'NOT_PINNED'];
		NodeConfigPack::usePack(function (string $rIid, array $rParams, ?string $rKey): string {
			$this->rPacked[] = [$rIid, $rParams, $rKey];
			return $rKey === null ? 'XCVT-v1' : 'XCVT-v2';
		});
	}

	protected function tearDown(): void {
		XcvtTargets::useCrypto(null);
		NodeConfigPack::usePack(null);
		NodeConfigPack::useV1Allowed(null);
		NodeCorePin::useExtension(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/** MAIN's root as cluster:init recorded it. */
	private function recordRoot(): void {
		$rInfo = $this->rCrypto->info();
		ClusterMeta::set('panel_sign_pub', base64_encode($rInfo['panel_sign_pub']));
		ClusterMeta::set('panel_fp', bin2hex($rInfo['panel_fp']));
	}

	private function fp(): string {
		return hash('sha256', $this->rCrypto->info()['panel_sign_pub']);
	}

	private function ready(int $rServerID = self::SID, string $rKey = self::KEY, string $rIid = self::IID): void {
		$this->assertTrue(XcvtTargets::record($rServerID, $rIid, $rKey, $this->fp(), 2, 'test'));
	}

	/** @return list<string> */
	private function events(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['detail'], $this->rDb->get_rows() ?: []);
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = ?', self::SID);
		return (int) $this->rDb->get_row()['status'];
	}

	/** The node's side of the install's SSH session. @return array{0: \Closure, 1: \Closure} */
	private function node(): array {
		$rRun = function ($rConn, string $rCmd): array {
			$this->rCommands[] = $rCmd;
			$rOut = match (true) {
				str_contains($rCmd, 'config_server') => $this->rNode['readback'] ?? '',
				str_contains($rCmd, 'XC_VM::install_id()') => $this->rNode['install_id'],
				str_contains($rCmd, 'XC_VM::cluster_pin(') => $this->rNode['cluster_pin'],
				str_contains($rCmd, 'cluster_pinned') => $this->rNode['cluster_pinned'],
				str_contains($rCmd, '"panel_pk"') => $this->rNode['panel_pk'],
				default => '',
			};
			if (str_contains($rCmd, 'XC_VM::cluster_pin(') && str_starts_with($this->rNode['cluster_pin'], 'OK ')) {
				$this->rNode['cluster_pinned'] = $this->rNode['cluster_pin'];
			}
			return ['output' => $rOut . "\n", 'error' => ''];
		};
		$rSend = function ($rConn, string $rLocal, string $rRemote): bool {
			$this->rCommands[] = 'SEND ' . $rRemote;
			return true;
		};
		return [$rRun, $rSend];
	}

	private function provision(): bool {
		[$rRun, $rSend] = $this->node();
		ob_start();
		try {
			return LbInstallFlow::provisionConfig(null, $rRun, $rSend, [SERVER_ID => ['server_ip' => '10.0.0.1'], self::SID => []], self::SID, $this->rDb, false, false, $this->rCrypto);
		} finally {
			ob_end_clean();
		}
	}

	/** Index of the first command containing $rNeedle. */
	private function at(string $rNeedle): int {
		$rAt = array_key_first(array_filter($this->rCommands, static fn(string $rC): bool => str_contains($rC, $rNeedle)));
		$this->assertNotNull($rAt, 'ran: ' . $rNeedle);
		return (int) $rAt;
	}

	// ── The record and its predicate (Decision 9) ──────────────────────────

	public function testReadyNeedsAV2PinOfThisPanelsKeyAndTheNodesKey(): void {
		$this->assertFalse(XcvtTargets::ready(self::SID));
		$this->assertSame('no_pin', XcvtTargets::reason(self::SID), 'nothing recorded');

		XcvtTargets::record(self::SID, self::IID, self::KEY, null, 0, 'test');
		$this->assertSame('no_pin', XcvtTargets::reason(self::SID), 'a key alone never counts');

		$this->ready();
		$this->assertTrue(XcvtTargets::ready(self::SID));
		$this->assertSame(self::KEY, XcvtTargets::get(self::SID)['panel_pk']);

		// A new root on MAIN (another fp): stale, fails closed.
		ClusterMeta::set('panel_sign_pub', base64_encode(str_repeat("\x33", 32)));
		$this->assertSame('stale_fp', XcvtTargets::reason(self::SID));
		$this->recordRoot();

		// A version-1 pin is not a v2 target.
		XcvtTargets::record(self::SID, self::IID, self::KEY, $this->fp(), 1, 'test');
		$this->assertFalse(XcvtTargets::ready(self::SID));

		$this->assertFalse(XcvtTargets::record(self::SID, 'bad id!', self::KEY, $this->fp(), 2, 'test'));
		$this->assertFalse(XcvtTargets::record(self::SID, self::IID, 'not-a-key', $this->fp(), 2, 'test'));
	}

	/** The stored key is sealed to its server and install: a DB writer cannot swap it in from another row. */
	public function testAKeyMovedInTheDatabaseDoesNotOpen(): void {
		$this->ready();
		$this->ready(8, self::NEW_KEY);
		$rEight = json_decode((string) ClusterMeta::get(XcvtTargets::META . '8'), true);
		$rSeven = json_decode((string) ClusterMeta::get(XcvtTargets::META . self::SID), true);
		$rSeven['panel_pk'] = $rEight['panel_pk'];
		ClusterMeta::set(XcvtTargets::META . self::SID, (string) json_encode($rSeven));
		$this->assertNull(XcvtTargets::get(self::SID)['panel_pk']);
		$this->assertFalse(XcvtTargets::ready(self::SID));
		$this->assertSame('no_pk', XcvtTargets::reason(self::SID));
	}

	public function testAKeyIsNeverRecordedUnsealed(): void {
		XcvtTargets::useCrypto(null); // no extension in this PHP
		$this->assertFalse(XcvtTargets::record(self::SID, self::IID, self::KEY, null, 0, 'test'));
		$this->assertNull(XcvtTargets::get(self::SID));
		$this->assertTrue(XcvtTargets::record(self::SID, self::IID, null, null, 0, 'test', null, null, 'ext_old'), 'no key: nothing to seal');
	}

	// ── ensureRoot (Decision 9) ─────────────────────────────────────────────

	public function testEnsureRootAcceptsTheRecordedRootAndRefusesAnother(): void {
		$this->assertNull(XcvtTargets::ensureRoot($this->rCrypto));
		ClusterMeta::set('panel_fp', str_repeat('0', 32));
		$this->assertStringContainsString('not the one this panel recorded', (string) XcvtTargets::ensureRoot($this->rCrypto));
	}

	public function testEnsureRootRefusesAnUnreadableRoot(): void {
		$this->rCrypto->rRootError = 'ROOT_UNREADABLE';
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		$this->assertStringContainsString('ROOT_UNREADABLE', (string) XcvtTargets::ensureRoot($this->rCrypto));
		$this->assertNull(ClusterMeta::get('panel_fp'), 'never replaced');
	}

	public function testEnsureRootCreatesOnlyWhenNoRootWasEverRecorded(): void {
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		$this->rCrypto->rRootError = 'NOT_INITIALISED';
		ob_start();
		$rWhy = XcvtTargets::ensureRoot($this->rCrypto, $rNotice);
		$this->assertSame('', (string) ob_get_clean(), 'nothing printed: FPM may be the caller');
		$this->assertNull($rWhy);
		$this->assertSame(bin2hex($this->rCrypto->info()['panel_fp']), ClusterMeta::get('panel_fp'), 'through ClusterMeta::init, which records it');
		$this->assertStringContainsString('cluster:export-keys', (string) $rNotice, 'the operator is told to back it up');
	}

	/** A root that is there but was never recorded is recorded only when every pin on record is of it. */
	public function testAnUnrecordedRootIsRecordedOnlyWhenThePinsAreOfIt(): void {
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		ClusterMeta::set('core_pin:9', (string) json_encode(['gen' => 1, 'fp' => $this->fp(), 'pinned_at' => 1, 'tried_at' => 1]));
		$this->assertNull(XcvtTargets::ensureRoot($this->rCrypto));
		$this->assertSame(bin2hex($this->rCrypto->info()['panel_fp']), ClusterMeta::get('panel_fp'));

		$this->rDb->exec('DELETE FROM `cluster_meta`');
		ClusterMeta::set('core_pin:9', (string) json_encode(['gen' => 1, 'fp' => str_repeat('e', 64), 'pinned_at' => 1, 'tried_at' => 1]));
		$this->assertStringContainsString('cluster:import-keys', (string) XcvtTargets::ensureRoot($this->rCrypto));
		$this->assertNull(ClusterMeta::get('panel_fp'));
	}

	/** cluster:init and Settings go through initRoot: a MAIN restored without its root.enc gets no new root. */
	public function testClusterInitNeverReplacesARecordedRoot(): void {
		$this->rCrypto->rRootError = 'NOT_INITIALISED';
		ob_start();
		$this->assertFalse(\XcVm\Cli\Commands\ClusterInitCommand::root($this->rCrypto));
		$rOut = (string) ob_get_clean();
		$this->assertStringContainsString('cluster:import-keys', $rOut);
		$this->assertSame('NOT_INITIALISED', $this->rCrypto->rRootError, 'nothing created');

		// --force: a new root on purpose, audit-logged.
		ob_start();
		$this->assertTrue(\XcVm\Cli\Commands\ClusterInitCommand::root($this->rCrypto, true));
		ob_end_clean();
		$this->assertNull($this->rCrypto->rRootError);
		$this->assertNotEmpty(array_filter($this->events(), static fn(string $rE): bool => str_starts_with($rE, 'cluster.init_forced')));

		// A fresh panel: created, and told to back it up.
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		$this->rCrypto->rRootError = 'NOT_INITIALISED';
		ob_start();
		$this->assertTrue(\XcVm\Cli\Commands\ClusterInitCommand::root($this->rCrypto));
		$rOut = (string) ob_get_clean();
		$this->assertStringContainsString('Created', $rOut);
		$this->assertStringContainsString('cluster:export-keys', $rOut);
		$this->assertSame([null, false], XcvtTargets::initRoot($this->rCrypto), 'idempotent');

		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Server/SettingsService.php');
		$this->assertStringContainsString('[$rWhy] = XcvtTargets::initRoot(ClusterCryptoFactory::create());', $rSrc, 'Settings too');
		$this->assertStringNotContainsString('ClusterMeta::init(', $rSrc);
		$this->assertStringContainsString("'cluster_error_root'", $rSrc);
		$this->assertStringContainsString('cluster_error_root = ', (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini'));
	}

	/** @return iterable<string, array{0: \Closure(self): void}> */
	public static function recordedFingerprints(): iterable {
		yield 'the panel key' => [static fn(self $rT) => ClusterMeta::set('panel_sign_pub', base64_encode(str_repeat("\x44", 32)))];
		yield 'a node pin' => [static fn(self $rT) => ClusterMeta::set('core_pin:9', (string) json_encode(['gen' => 1, 'fp' => str_repeat('e', 64), 'pinned_at' => 1, 'tried_at' => 1]))];
		yield 'an XCVT record' => [static fn(self $rT) => ClusterMeta::set(XcvtTargets::META . '9', (string) json_encode(['install_id' => null, 'pin_fp' => str_repeat('e', 64), 'pin_ver' => 2]))];
		yield 'an enrolled node' => [static fn(self $rT) => DatabaseFactory::get()->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`) VALUES (9, 'x')")];
	}

	/** @dataProvider recordedFingerprints */
	public function testEnsureRootNeverCreatesWhereARootWasRecorded(\Closure $rRecord): void {
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		// Attempts that pinned nothing do not count.
		ClusterMeta::set('core_pin:8', (string) json_encode(['gen' => 1, 'fp' => '', 'pinned_at' => null, 'tried_at' => 1]));
		XcvtTargets::noteProbe(8, 'ext_old');
		$rRecord($this);
		$this->rCrypto->rRootError = 'NOT_INITIALISED';
		$this->assertStringContainsString('cluster:import-keys', (string) XcvtTargets::ensureRoot($this->rCrypto));
		$this->assertNull(ClusterMeta::get('panel_fp'));
	}

	// ── NodeConfigPack: the one packer ──────────────────────────────────────

	public function testAV2TargetGetsV2ForItsRecordedInstall(): void {
		$this->ready();
		$this->assertSame('XCVT-v2', NodeConfigPack::pack(self::SID, ['is_lb' => 1]));
		$this->assertSame([[self::IID, ['is_lb' => 1], self::KEY]], $this->rPacked);
		$this->assertSame([], array_filter($this->events(), static fn(string $rE): bool => str_starts_with($rE, 'node.config_')), 'no warning');
	}

	public function testANodeThatIsNotAV2TargetGetsV1WithAWarning(): void {
		XcvtTargets::record(self::SID, self::IID, null, null, 0, 'install', null, false, 'ext_old');
		$this->assertSame('XCVT-v1', NodeConfigPack::pack(self::SID, ['is_lb' => 1]));
		$this->assertSame([[self::IID, ['is_lb' => 1], null]], $this->rPacked);
		$this->assertContains('node.config_v1 {"why":"ext_old"}', $this->events());

		// No record: the install_id the cluster row has.
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (8, 'x', ?)", self::IID);
		$this->assertSame('XCVT-v1', NodeConfigPack::pack(8, []));
		$this->assertFalse(NodeConfigPack::pack(9, []), 'no install_id anywhere');
	}

	/** Never "pinned + v1": a node root reported pinned before MAIN knew its key gets no v1 (it is asked for its key); one whose extension predates v2 still does. */
	public function testANodePinnedBeforeV2GetsNoV1(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (?, 'x', ?)", self::SID, self::IID);
		$this->assertTrue(CorePins::recorded(self::SID, $this->fp()));
		$this->assertFalse(NodeConfigPack::pack(self::SID, ['db_credentials' => true]));
		$this->assertSame([], $this->rPacked, 'no credential-bearing v1 blob');
		$this->assertNotEmpty(array_filter($this->events(), static fn(string $rE): bool => str_starts_with($rE, 'node.config_refused {"why":"pinned_v1"')));

		XcvtTargets::noteProbe(self::SID, 'ext_old');
		$this->assertSame('XCVT-v1', NodeConfigPack::pack(self::SID, []), 'Decision 12: an old extension still gets v1 in N');
	}

	/** Never "pinned + v1": a node MAIN v2-pinned is not sent v1 without a fresh probe. */
	public function testANodeOnceV2PinnedGetsNoV1(): void {
		$this->ready();
		XcvtTargets::clear(self::SID); // a CRYPTO ack
		$this->assertFalse(NodeConfigPack::pack(self::SID, []));
		$this->assertSame([], $this->rPacked);
		$this->assertContains('node.config_refused {"why":"no_pk","v1_allowed":true}', $this->events());
	}

	// ── The install's bootstrap (Decision 7, 8) ─────────────────────────────

	public function testTheInstallPinsV2BeforeTheConfigAndTheChown(): void {
		$this->rCrypto->rLicensed = false; // a v2 pin needs no licence
		$this->assertTrue($this->provision());
		$rMkdir = $this->at('sudo mkdir -p ' . CONFIG_PATH);
		$rId = $this->at('XC_VM::install_id()');
		$rPk = $this->at('"panel_pk"');
		$rPin = $this->at('XC_VM::cluster_pin(');
		$rConfig = $this->at('SEND ' . CONFIG_PATH . 'config.enc');
		$rChown = $this->at('sudo chown -R xc_vm:xc_vm ' . CONFIG_PATH);
		$this->assertTrue($rMkdir < $rId && $rId < $rPk && $rPk < $rPin && $rPin < $rConfig && $rConfig < $rChown, implode("\n", $this->rCommands));
		foreach ([$rId, $rPk, $rPin] as $rAt) {
			$this->assertStringStartsWith('sudo ' . PHP_BIN, $this->rCommands[$rAt], 'as root');
		}
		$this->assertStringContainsString(base64_encode($this->rCrypto->pack(self::IID, 2)), $this->rCommands[$rPin], 'the v2 pin for that install');
		$this->assertStringContainsString('), true);', $this->rCommands[$rPin], 'with replace');
		$this->assertTrue(XcvtTargets::ready(self::SID));
		$this->assertSame([[self::IID, LbInstallFlow::configPackParams([SERVER_ID => ['server_ip' => '10.0.0.1']], self::SID), self::KEY]], $this->rPacked, 'then a v2 config');
		$this->assertSame(0, $this->serverStatus());
	}

	/** Release N: a node the v2 pin did not reach, seen unpinned, gets v1. */
	public function testAFailedV2PinFallsBackToV1OnAnUnpinnedNode(): void {
		$this->rNode['cluster_pin'] = 'ERR CRYPTO';
		$this->assertTrue($this->provision());
		$this->assertSame(null, $this->rPacked[0][2], 'a v1 config');
		$this->assertFalse(XcvtTargets::get(self::SID)['v2']);
		$this->assertSame('no_pin', XcvtTargets::reason(self::SID));

		// An extension before ADR-005 on the node: the same, and said so.
		$this->rPacked = [];
		$this->rNode['panel_pk'] = 'NONE';
		$this->assertTrue($this->provision());
		$this->assertNull($this->rPacked[0][2]);
		$this->assertSame('ext_old', XcvtTargets::reason(self::SID));
	}

	/** Never "pinned + v1": a node that holds a pin the v2 step could not replace fails the install. */
	public function testAFailedV2PinOnAPinnedNodeFailsTheInstall(): void {
		foreach (['OK ' . str_repeat('e', 64), 'ERR RECORD', ''] as $rPinned) {
			$this->rNode['cluster_pin'] = 'ERR PIN_MISMATCH';
			$this->rNode['cluster_pinned'] = $rPinned;
			$this->assertFalse($this->provision(), $rPinned);
			$this->assertSame(4, $this->serverStatus());
			$this->assertSame([], $this->rPacked, 'nothing packed');
		}
	}

	/** Release N+1 (V1_ALLOWED false): no v1 from MAIN, and a v2 pin that fails fails the install. */
	public function testReleaseNPlusOneNeverEmitsV1(): void {
		NodeConfigPack::useV1Allowed(false);
		XcvtTargets::record(self::SID, self::IID, null, null, 0, 'install', null, false, 'ext_old');
		$this->assertFalse(NodeConfigPack::pack(self::SID, []));
		$this->assertContains('node.config_refused {"why":"ext_old","v1_allowed":false}', $this->events());

		$this->rNode['cluster_pin'] = 'ERR CRYPTO';
		$this->assertFalse($this->provision(), 'on an unpinned node too');
		$this->assertSame(4, $this->serverStatus());
		$this->assertSame([], $this->rPacked);

		$this->rNode['cluster_pin'] = 'OK ' . $this->fp();
		$this->assertTrue($this->provision(), 'a v2 target installs as in N');
		$this->assertSame(self::KEY, $this->rPacked[0][2]);
	}

	public function testANodeThatAnswersAnotherKeyIsNotRecorded(): void {
		$this->rNode['cluster_pin'] = 'OK ' . str_repeat('f', 64);
		$this->rNode['cluster_pinned'] = 'OK ' . str_repeat('f', 64);
		$this->assertFalse($this->provision(), 'pinned to another key: no v1 either');
		$this->assertFalse(XcvtTargets::ready(self::SID));
	}

	public function testMainWithAnOldExtensionCannotPin(): void {
		$this->rCrypto->rV2 = false;
		[$rRun] = $this->node();
		$this->assertStringContainsString('predates XCVT v2', (string) LbInstallFlow::pinCore(null, $rRun, $this->rCrypto, self::IID));
		$this->assertSame([], array_filter($this->rCommands, static fn(string $rC): bool => str_contains($rC, 'cluster_pin(')));
	}

	// ── Readback (Decision 7) ───────────────────────────────────────────────

	public function testTheReadbackChecksWhatFpmSees(): void {
		[$rRun] = $this->node();
		$this->ready();
		$this->rNode['cluster_pinned'] = 'OK ' . $this->fp();
		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 1, 'pk' => self::KEY]);
		$this->assertNull(LbInstallFlow::readback(null, $rRun, self::SID));
		$rAsk = $this->rCommands[$this->at('config_server')];
		$this->assertStringStartsWith('sudo -u xc_vm ', $rAsk, 'as FPM runs');
		$this->assertStringContainsString('is_file(', $rAsk, 'creates no panel.sk');
		$this->assertStringStartsWith("sudo -u 'xc_vm' ", $this->rCommands[$this->at('cluster_pinned')]);

		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 1, 'pk' => self::NEW_KEY]);
		$this->assertStringContainsString('is not the one MAIN recorded', (string) LbInstallFlow::readback(null, $rRun, self::SID));
		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 1, 'pk' => false]);
		$this->assertStringContainsString('is not the one MAIN recorded', (string) LbInstallFlow::readback(null, $rRun, self::SID), 'a root-owned panel.sk');
		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 1, 'pk' => self::KEY]);
		$this->rNode['cluster_pinned'] = 'ERR IO';
		$this->assertStringContainsString("is not this panel's key", (string) LbInstallFlow::readback(null, $rRun, self::SID), 'a root-owned core.pin');
		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 0, 'pk' => self::KEY]);
		$this->assertStringContainsString("load balancer's", (string) LbInstallFlow::readback(null, $rRun, self::SID));
		$this->rNode['readback'] = (string) json_encode(['server_id' => 1, 'is_lb' => 1, 'pk' => self::KEY]);
		$this->assertStringContainsString('server_id 1', (string) LbInstallFlow::readback(null, $rRun, self::SID), 'another node\'s config');
		$this->rNode['readback'] = (string) json_encode(['error' => 'install_id']);
		$this->assertSame('xc_vm cannot read its install_id', LbInstallFlow::readback(null, $rRun, self::SID));
		$this->assertStringContainsString('is_readable(', $rAsk, 'config_server() never mints an install_id');
		$this->rNode['readback'] = '';
		$this->assertNotNull(LbInstallFlow::readback(null, $rRun, self::SID));

		// A v1 node (release N): only its config is checked.
		XcvtTargets::clear(self::SID);
		$this->rNode['readback'] = (string) json_encode(['server_id' => self::SID, 'is_lb' => 1, 'pk' => null]);
		$this->assertNull(LbInstallFlow::readback(null, $rRun, self::SID));
	}

	public function testTheInstallMarksAMismatchFailed(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rStartup = strpos($rSrc, 'LbInstallFlow::runStartup($rConn, $rRunSSH, !$rApiMode);');
		$rReadback = strpos($rSrc, '$rReadback = LbInstallFlow::readback($rConn, $rRunSSH, $rServerID);');
		$rDone = strpos($rSrc, 'SET `status` = 1, `http_broadcast_port`');
		$this->assertNotFalse($rReadback);
		$this->assertTrue($rStartup < $rReadback && $rReadback < $rDone, 'after runStartup, before status 1');
		$this->assertMatchesRegularExpression('/if \(\$rReadback !== null\) \{\s*\$db->query\(\'UPDATE `servers` SET `status` = 4/', $rSrc);
	}

	// ── server:xcvt-pin (Decision 10) ───────────────────────────────────────

	/** @return array{0: array<int, array<string, mixed>>, 1: FakeSshFleet} */
	private function fleet(?string $rStored): array {
		$rSsh = new FakeSshFleet();
		$rSsh->rNodes['10.0.0.7'] = ['hostkey' => sha1('host-7'), 'password' => 'pw', 'iid' => self::IID, 'pk' => 'PK ' . self::KEY, 'pin' => $this->fp()];
		$rServers = [
			SERVER_ID => ['id' => SERVER_ID, 'is_main' => 1, 'server_type' => 0, 'server_ip' => '10.0.0.1'],
			self::SID => ['id' => self::SID, 'is_main' => 0, 'server_type' => 0, 'server_ip' => '10.0.0.7', 'ssh_hostkey_sha1' => $rStored],
		];
		return [$rServers, $rSsh];
	}

	private function xcvtPin(array $rServers, FakeSshFleet $rSsh, ?string $rExpected): ?string {
		ob_start();
		try {
			return ServerXcvtPinCommand::pin($rServers, self::SID, 22, ['username' => 'root', 'password' => 'pw'], $rExpected, $this->rCrypto, $rSsh);
		} finally {
			ob_end_clean();
		}
	}

	public function testXcvtPinRefusesAFirstUseHostKey(): void {
		[$rServers, $rSsh] = $this->fleet(sha1('host-7'));
		$this->assertStringContainsString('taken on first use', (string) $this->xcvtPin($rServers, $rSsh, null));
		$this->assertSame([], $rSsh->rLog, 'nothing contacted');
		[$rServers, $rSsh] = $this->fleet(null);
		$this->assertStringContainsString('--expect-hostkey', (string) $this->xcvtPin($rServers, $rSsh, null));
		$this->assertSame([], $rSsh->rLog);
		$this->assertStringContainsString('mismatch', (string) $this->xcvtPin($rServers, $rSsh, sha1('another host')));
		$this->assertNotContains('login 10.0.0.7 root', $rSsh->rLog, 'the password is never sent to the wrong host');
	}

	public function testXcvtPinPinsAndRecordsOverAVerifiedSession(): void {
		[$rServers, $rSsh] = $this->fleet(sha1('host-7'));
		$this->assertNull($this->xcvtPin($rServers, $rSsh, sha1('host-7')));
		$this->assertTrue(XcvtTargets::ready(self::SID));
		$rRun = $rSsh->commands('10.0.0.7');
		$rPin = array_values(array_filter($rRun, static fn(string $rC): bool => str_contains($rC, 'XC_VM::cluster_pin(')));
		$this->assertCount(1, $rPin);
		$this->assertStringContainsString(base64_encode($this->rCrypto->pack(self::IID, 2)), $rPin[0]);
		$this->assertNotEmpty(array_filter($rRun, static fn(string $rC): bool => str_contains($rC, 'cluster_pinned')), 'the pin read back');
		$this->assertContains('node.xcvt_pinned {"install_id":"' . self::IID . '","panel_pk":"' . self::KEY . '","pin_fp":"' . $this->fp() . '"}', $this->events());

		// Confirmed once, the stored key serves again without --expect-hostkey.
		$this->assertTrue(XcvtTargets::hostKeyVerified(self::SID, sha1('host-7')));
		$this->assertNull($this->xcvtPin($rServers, $rSsh, null));
	}

	public function testXcvtPinRecordsWhyANodeIsNotCovered(): void {
		[$rServers, $rSsh] = $this->fleet(sha1('host-7'));
		$rSsh->rNodes['10.0.0.7']['pk'] = 'NONE';
		$this->assertStringContainsString('predates XCVT v2', (string) $this->xcvtPin($rServers, $rSsh, sha1('host-7')));
		$this->assertSame('ext_old', XcvtTargets::reason(self::SID));
		$rSsh->rNodes['10.0.0.7']['pk'] = 'ERR';
		$this->xcvtPin($rServers, $rSsh, sha1('host-7'));
		$this->assertSame('no_pk', XcvtTargets::reason(self::SID));
		unset($rSsh->rNodes['10.0.0.7']);
		$this->xcvtPin($rServers, $rSsh, sha1('host-7'));
		$this->assertSame('ssh_unreachable', XcvtTargets::reason(self::SID));
	}

	// ── server:xcvt-report (Decision 10) ────────────────────────────────────

	public function testTheReportGivesEveryLoadBalancerItsReason(): void {
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`, `server_type`, `is_main`, `enabled`) VALUES (4, 'proxy', 1, 0, 1), (8, 'lb-8', 0, 0, 1), (9, 'lb-9', 0, 0, 1), (10, 'lb-10', 0, 0, 1), (11, 'lb-11', 0, 0, 1), (12, 'lb-12', 0, 0, 1), (13, 'off', 0, 0, 0)");
		$this->ready();
		XcvtTargets::noteProbe(8, 'ext_old');
		XcvtTargets::record(9, self::IID, null, null, 0, 'test', null, null, 'no_pk');
		XcvtTargets::record(11, self::IID, self::KEY, str_repeat('e', 64), 2, 'test');
		XcvtTargets::noteProbe(12, 'ssh_unreachable');
		[$rRows, $rPass] = ServerXcvtReportCommand::report();
		$this->assertFalse($rPass);
		$this->assertSame([7 => 'covered:', 8 => 'uncovered:ext_old', 9 => 'uncovered:no_pk', 10 => 'uncovered:no_pin', 11 => 'uncovered:stale_fp', 12 => 'uncovered:ssh_unreachable'], array_column(array_map(static fn(array $rR): array => ['id' => $rR['id'], 'v' => $rR['state'] . ':' . $rR['detail']], $rRows), 'v', 'id'), 'not MAIN, the proxy or a disabled server');

		foreach ([8, 9, 10, 11, 12] as $rID) {
			ServerXcvtReportCommand::waive($rID, 'decommissioning');
		}
		[$rRows, $rPass] = ServerXcvtReportCommand::report();
		$this->assertTrue($rPass);
		$this->assertSame('waived', $rRows[1]['state']);
		$this->assertContains('node.xcvt_waived {"reason":"decommissioning"}', $this->events());
		ServerXcvtReportCommand::unwaive(8);
		$this->assertFalse(ServerXcvtReportCommand::report()[1]);
	}

	// ── Backfill over the cluster API, and the re-probe (Decisions 10, 13) ──

	private function command(string $rCmdID, string $rAction, array $rArgs = []): void {
		$this->rDb->query('INSERT INTO `cluster_commands` (`server_id`, `cmd_id`, `type`, `payload`) VALUES (?, ?, ?, ?)', self::SID, $rCmdID, 'node.root', json_encode(['type' => 'node.root', 'action' => $rAction, 'args' => $rArgs]));
	}

	public function testRootShowsItsKeyAndMainSendsTheV2Pin(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`) VALUES (?, 'x')", self::SID);
		$rSent = [];
		$rSend = static function (int $rSid, ?string $rBlob) use (&$rSent): bool {
			$rSent[] = $rBlob;
			return true;
		};
		$rPack = fn(string $rIid, int $rVersion): string => $this->rCrypto->pack($rIid, $rVersion);

		// Step 1: root shows its install_id and key, and holds a v1 pin of this panel.
		$this->command(str_repeat('a', 32), NodeCorePin::ACTION);
		$this->assertTrue(CorePins::acked(self::SID, str_repeat('a', 32), true, (string) json_encode(['core' => ['install_id' => self::IID, 'pinned' => $this->fp(), 'verdict' => true, 'panel_pk' => self::KEY]]), $rPack, $rSend));
		$this->assertSame(self::KEY, XcvtTargets::get(self::SID)['panel_pk']);
		$this->assertFalse(XcvtTargets::ready(self::SID), 'not yet: MAIN has not pinned it with v2');
		$this->assertCount(1, $rSent);
		$this->assertSame(2, ord((string) $rSent[0][4]), 'the v2 pin');

		// Step 2: root acks it.
		$this->command(str_repeat('b', 32), NodeCorePin::ACTION, ['blob' => base64_encode((string) $rSent[0])]);
		$this->assertTrue(CorePins::acked(self::SID, str_repeat('b', 32), true, (string) json_encode(['core' => ['pinned' => $this->fp(), 'verdict' => true]]), $rPack, $rSend));
		$this->assertTrue(XcvtTargets::ready(self::SID));

		// A node whose extension predates ADR-005: a v1 pin, and why.
		XcvtTargets::useCrypto($this->rCrypto);
		$this->rDb->query("UPDATE `cluster_nodes` SET `install_id` = NULL WHERE `server_id` = ?", self::SID);
		$this->rDb->exec('DELETE FROM `cluster_meta` WHERE `name` LIKE \'xcvt.%\'');
		$this->command(str_repeat('c', 32), NodeCorePin::ACTION);
		CorePins::acked(self::SID, str_repeat('c', 32), true, (string) json_encode(['core' => ['install_id' => self::IID, 'pinned' => null, 'verdict' => true]]), $rPack, $rSend);
		$this->assertSame(1, ord((string) end($rSent)[4]));
		$this->assertSame('ext_old', XcvtTargets::reason(self::SID));
	}

	/**
	 * The daily backfill of a node pinned before v2 asks root for its key, never
	 * re-sends a v1 pin: one that knew only the install_id looped on v1 pins,
	 * and the key, which only step 1 carries, never came (seen on a live MAIN).
	 */
	public function testTheBackfillAsksAPinnedNodeForItsKeyNotAV1Pin(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (?, 'x', ?)", self::SID, self::IID);
		$this->assertTrue(CorePins::recorded(self::SID, $this->fp()));
		$rSent = [];
		$rSend = static function (int $rSid, ?string $rBlob) use (&$rSent): bool {
			$rSent[] = $rBlob;
			return true;
		};
		$rPack = fn(string $rIid, int $rVersion): string => $this->rCrypto->pack($rIid, $rVersion);

		$this->assertSame(1, CorePins::offer($rSend, 1800000000, $rPack));
		$this->assertSame([null], $rSent, 'step 1: the question, not a v1 pin');
		$this->assertContains('node.core_pin {"step":"install_id","queued":true}', $this->events());

		// Root shows its key: the v2 pin follows.
		$this->command(str_repeat('a', 32), NodeCorePin::ACTION);
		CorePins::acked(self::SID, str_repeat('a', 32), true, (string) json_encode(['core' => ['install_id' => self::IID, 'pinned' => $this->fp(), 'verdict' => true, 'panel_pk' => self::KEY]]), $rPack, $rSend);
		$this->assertCount(2, $rSent);
		$this->assertSame(2, ord((string) $rSent[1][4]), 'the v2 pin');

		// An extension before ADR-005 shows no key: nothing more is sent (no loop),
		// and the next day's backfill asks again.
		$rSent = [];
		XcvtTargets::clear(self::SID);
		$this->command(str_repeat('b', 32), NodeCorePin::ACTION);
		CorePins::acked(self::SID, str_repeat('b', 32), true, (string) json_encode(['core' => ['install_id' => self::IID, 'pinned' => $this->fp(), 'verdict' => true]]), $rPack, $rSend);
		$this->assertSame([], $rSent);
		$this->assertSame(0, CorePins::offer($rSend, 1800000000 + 60, $rPack), 'asked today already');
		$this->assertSame(1, CorePins::offer($rSend, 1800000000 + CorePins::BACKFILL_SEC, $rPack));
		$this->assertSame([null], $rSent);
	}

	public function testRootReportsItsKeyOnlyFromAnExistingPanelSk(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-xcvt-' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir, 0700, true);
		file_put_contents($rDir . 'install_id', self::IID);
		$rCalls = [];
		NodeCorePin::useExtension(static function (string $rMethod, mixed ...$rArgs) use (&$rCalls): mixed {
			$rCalls[] = $rMethod;
			return match ($rMethod) {
				'install_id' => self::IID,
				'panel_pk' => self::KEY,
				'has' => ($rArgs[0] ?? '') !== 'identity_present',
				default => false,
			};
		}, $rDir);
		try {
			$this->assertNull(NodeCorePin::outcome(NodeCorePin::run(['action' => 'pin_core'], null))['panel_pk']);
			$this->assertNotContains('panel_pk', $rCalls, 'no panel.sk: none is created');
			file_put_contents($rDir . 'panel.sk', 'sealed');
			$this->assertSame(self::KEY, NodeCorePin::outcome(NodeCorePin::run(['action' => 'pin_core'], null))['panel_pk']);
		} finally {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	/** Decision 13: a config that did not install sends MAIN to ask again, and only what the node shows is kept. */
	public function testAFailedInstallConfigReprobesTheNode(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (?, 'x', ?)", self::SID, self::IID);
		$this->ready();
		CorePins::recorded(self::SID, $this->fp());
		$rSent = [];
		$rSend = static function (int $rSid, ?string $rBlob) use (&$rSent): bool {
			$rSent[] = $rBlob;
			return true;
		};
		$rPack = fn(string $rIid, int $rVersion): string => $this->rCrypto->pack($rIid, $rVersion);

		$this->command(str_repeat('d', 32), NodeCredentials::INSTALL, ['blob' => 'eA==']);
		$this->assertFalse(CorePins::acked(self::SID, str_repeat('d', 32), false, 'install_config: refused by xcvm_core: CRYPTO', $rPack, $rSend));
		$this->assertSame([null], $rSent, 'pin_core step 1: the node shows its key again');
		$this->assertNull(XcvtTargets::get(self::SID)['panel_pk'], 'the old key is not used meanwhile');
		$this->assertFalse(NodeConfigPack::pack(self::SID, []), 'nor v1');
		$this->assertStringStartsWith('node.xcvt_reprobe', (string) current(array_filter($this->events(), static fn(string $rE): bool => str_starts_with($rE, 'node.xcvt_reprobe'))));

		// Its panel.sk was regenerated: the new key, then the v2 pin for it.
		$this->command(str_repeat('e', 32), NodeCorePin::ACTION);
		CorePins::acked(self::SID, str_repeat('e', 32), true, (string) json_encode(['core' => ['install_id' => self::IID, 'pinned' => $this->fp(), 'verdict' => true, 'panel_pk' => self::NEW_KEY]]), $rPack, $rSend);
		$this->assertSame(self::NEW_KEY, XcvtTargets::get(self::SID)['panel_pk']);
		$this->command(str_repeat('f', 32), NodeCorePin::ACTION, ['blob' => base64_encode((string) end($rSent))]);
		CorePins::acked(self::SID, str_repeat('f', 32), true, (string) json_encode(['core' => ['pinned' => $this->fp(), 'verdict' => true]]), $rPack, $rSend);
		$this->assertTrue(XcvtTargets::ready(self::SID));
		$this->assertSame('XCVT-v2', NodeConfigPack::pack(self::SID, []));
		$this->assertSame(self::NEW_KEY, end($this->rPacked)[2]);
	}

	/** A replay (two MAIN processes, one millisecond) is packed afresh and resent once, never looped, and is no reason to re-probe. */
	public function testAReplayedConfigIsRepackedOnce(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (?, 'x', ?)", self::SID, self::IID);
		$this->ready();
		$rPacks = 0;
		\XcVm\Domain\Cluster\DbCredentials::usePack(static function () use (&$rPacks): string {
			$rPacks++;
			return 'XCVT-fresh';
		});
		$rSent = [];
		$rSend = static function (int $rSid, ?string $rBlob) use (&$rSent): bool {
			$rSent[] = $rBlob;
			return true;
		};
		try {
			ClusterMeta::set('install_config.' . self::SID, (string) json_encode(['credentials' => true, 'repack' => false]));
			$this->command(str_repeat('a', 32), NodeCredentials::INSTALL, ['blob' => 'eA==']);
			CorePins::acked(self::SID, str_repeat('a', 32), false, 'install_config: refused by xcvm_core: RECORD:replay', null, $rSend);
			$this->assertSame(1, $rPacks, 'packed again');
			$this->assertSame([], $rSent, 'no re-probe');
			$this->assertTrue(XcvtTargets::ready(self::SID), 'the key stays');

			// The resend refused the same way: not sent again.
			ClusterMeta::set('install_config.' . self::SID, (string) json_encode(['credentials' => true, 'repack' => true]));
			$this->command(str_repeat('b', 32), NodeCredentials::INSTALL, ['blob' => 'eA==']);
			CorePins::acked(self::SID, str_repeat('b', 32), false, 'install_config: refused by xcvm_core: RECORD:replay', null, $rSend);
			$this->assertSame(1, $rPacks);
			$this->assertContains('node.install_config_replay {"resent":false}', $this->events());
		} finally {
			\XcVm\Domain\Cluster\DbCredentials::usePack(null);
		}
	}

	public function testACryptoPinAckForgetsTheKey(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (?, 'x', ?)", self::SID, self::IID);
		$this->ready();
		$this->command(str_repeat('a', 32), NodeCorePin::ACTION, ['blob' => 'eA==']);
		CorePins::acked(self::SID, str_repeat('a', 32), false, 'pin_core: refused by xcvm_core: CRYPTO');
		$this->assertNull(CorePins::installId(self::SID));
		$this->assertSame(['install_id' => null, 'panel_pk' => null], array_intersect_key((array) XcvtTargets::get(self::SID), ['install_id' => 1, 'panel_pk' => 1]));
	}

	/** Another install than the record's (a reinstall MAIN learns of from root): the old key goes. */
	public function testANewInstallIdClearsTheRecord(): void {
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`) VALUES (?, 'x')", self::SID);
		$this->ready();
		CorePins::rememberInstallId(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e');
		$this->assertNull(XcvtTargets::get(self::SID)['panel_pk']);
		$this->assertFalse(XcvtTargets::ready(self::SID));
	}
}
