<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutBinaryCommand;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * LbInstallFlow::provisionCluster(): SSH enrolment of a new LB. A fake LB
 * plays the node's side of the SSH session the way xc_agent does (keygen,
 * probe, install); opt-in, the real xc_agent binary runs the same commands
 * (XCVM_AGENT_BIN=/path/to/xc_agent).
 */
final class LbProvisionClusterTest extends TestCase {
	private const SID = 9;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	/** @var list<string> */
	private array $rCommands = [];

	/** @var array<string, string> remote path => content */
	private array $rSent = [];

	private ?string $rEphSk = null;

	private bool $rProbeOk = true;

	/** Does the node install the agent from its release (`fanout_binary agent`)? */
	private bool $rAgentInstalls = true;

	/** How the node answers the agent's start: STARTED, NO_RUNSH, or '' (it did not start). */
	private string $rAgentStart = 'STARTED';

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
		foreach (['029_create_cluster_nodes', '032_create_cluster_audit'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec($rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (9, 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	private function servers(): array {
		return [SERVER_ID => ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0], self::SID => []];
	}

	/** The node's side of the SSH session, as xc_agent answers it. */
	private function fakeSsh(): array {
		$rRun = function ($rConn, string $rCmd): array {
			$this->rCommands[] = $rCmd;
			if ($rCmd === 'uname -m') {
				return ['output' => "x86_64\n", 'error' => ''];
			}
			if (preg_match('/ keygen .* -uuid ([0-9a-f-]{36})$/', $rCmd, $rM)) {
				$rPair = sodium_crypto_sign_keypair();
				$rSign = sodium_crypto_sign_publickey($rPair);
				$rBox = sodium_crypto_scalarmult_base(random_bytes(32));
				$this->rEphSk = random_bytes(32);
				return ['output' => json_encode([
					'node_uuid' => $rM[1], 'sign_pub' => bin2hex($rSign), 'box_pub' => bin2hex($rBox),
					'eph_pub' => bin2hex(sodium_crypto_scalarmult_base($this->rEphSk)), 'sas' => EnrolmentService::sas($rM[1], $rSign, $rBox),
				]) . "\n", 'error' => ''
				];
			}
			if (str_contains($rCmd, 'console.php fanout_binary agent')) {
				return ['output' => $this->rAgentInstalls ? "xc_agent 1.5.0 installed.\nAGENT_OK\n" : "Failed to download xc_agent-linux-amd64\n", 'error' => ''];
			}
			if (str_contains($rCmd, ' probe ')) {
				return ['output' => $this->rProbeOk ? "OK http://10.0.0.1:25461/cluster/v1/\n" : "xc_agent probe: connection refused\n", 'error' => ''];
			}
			if (str_contains($rCmd, ' install ')) {
				return ['output' => "OK\n", 'error' => ''];
			}
			if (str_contains($rCmd, 'run.sh') && str_contains($rCmd, 'pgrep')) {
				return ['output' => $this->rAgentStart . "\n", 'error' => ''];
			}
			return ['output' => '', 'error' => ''];
		};
		$rSend = function ($rConn, string $rLocal, string $rRemote): bool {
			$this->rSent[$rRemote] = (string) file_get_contents($rLocal);
			return true;
		};
		return [$rRun, $rSend];
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 9');
		return (int) $this->rDb->get_row()['status'];
	}

	public function testEnrolsTheNodeOverSsh(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, $rLog);
		$this->assertArrayNotHasKey(LbInstallFlow::AGENT_BIN, $this->rSent, 'MAIN pushes no agent');
		$rInstall = array_values(array_filter($this->rCommands, static fn($c) => str_contains($c, 'console.php fanout_binary agent')));
		$this->assertCount(1, $rInstall, 'the node installs it from its release');
		$this->assertStringNotContainsString(ReplicaBoot::OPTION, $rInstall[0], 'a legacy node boots through MAIN\'s database');
		$rKeygenAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' keygen ')));
		$rChownAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'chown -R xc_vm:xc_vm')));
		$this->assertLessThan(array_search($rInstall[0], $this->rCommands, true), $rChownAt, 'into a directory that is the agent\'s');
		$this->assertLessThan($rKeygenAt, array_search($rInstall[0], $this->rCommands, true));

		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('enrolling', $rNode['state']);
		$this->assertStringContainsString($rNode['node_uuid'], $rLog);

		$rInstall = json_decode(end($this->rSent), true);
		$this->assertSame(self::SID, $rInstall['server_id']);
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], $rInstall['main_urls']);
		$this->assertSame($this->rCrypto->info()['panel_sign_pub'], base64_decode($rInstall['panel_sign_pub']));
		$this->assertSame($this->rCrypto->info()['panel_box_pub'], base64_decode($rInstall['panel_box_pub']), 'the box key re-keys are sealed to');
		// The token opens only with the node's per-epoch key and carries the panel's signature.
		$rBody = Seal::open((string) $this->rEphSk, 'token', $rNode['node_uuid'], (string) base64_decode($rInstall['token_sealed']));
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'tok', substr($rBody, 4, $rLen), substr($rBody, 4 + $rLen)));
		// And the lease that token serves on, panel-signed beside it (tag `lea`).
		$rLease = (string) base64_decode((string) $rInstall['lease']['payload'], true);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'lea', $rLease, (string) base64_decode((string) $rInstall['lease']['sig'], true)));
		$this->assertSame($rNode['node_uuid'], json_decode($rLease, true)['node_uuid']);

		// The probe ran before the token existed, and the agent was started last.
		$rProbeAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' probe ')));
		$rInstallAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' install ')));
		$this->assertLessThan($rInstallAt, $rProbeAt);
		$this->assertStringContainsString('bin/xc_agent/run.sh', end($this->rCommands));
		$this->assertStringContainsString(bin2hex($this->rCrypto->info()['panel_sign_pub']), $this->rCommands[$rProbeAt]);
		// Root's own pin of the panel key, root-owned, before the agent starts.
		$rPinAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'main_sign.pub')));
		$this->assertNotNull($rPinAt, 'the root pin is written');
		$this->assertStringContainsString("echo '" . bin2hex($this->rCrypto->info()['panel_sign_pub']) . "' | sudo tee '/etc/xc_vm/cluster/main_sign.pub'", $this->rCommands[$rPinAt]);
		$this->assertStringContainsString('sudo chown root:root', $this->rCommands[$rPinAt]);
		$this->assertStringContainsString("sudo rm -f '/etc/xc_vm/cluster/root.seq'", $this->rCommands[$rPinAt]);
		$rRunAt = array_key_last(array_filter($this->rCommands, static fn($c) => str_contains($c, 'bash') && str_contains($c, 'run.sh')));
		$this->assertLessThan($rRunAt, $rPinAt, 'pinned before the agent runs');
	}

	/**
	 * Phase 9: the extension's own pin (core.pin) over the same session — the
	 * node's install_id read and recorded, the pin packed for it and pinned
	 * there as root (replacing an earlier MAIN's: the session is verified),
	 * the node answering with this panel's key, before the agent starts.
	 */
	public function testPinsTheNodesCoreOverTheSameSession(): void {
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `install_id` varchar(64) DEFAULT NULL');
		\XcVm\Domain\Cluster\ClusterMeta::set('panel_sign_pub', base64_encode($this->rCrypto->info()['panel_sign_pub']));
		$rFp = hash('sha256', $this->rCrypto->info()['panel_sign_pub']);
		$rIid = '6c1b5bd2-2c86-4a4a-9a57-0f6e6d3b1f10';
		[$rRun, $rSend] = $this->fakeSsh();
		$rNode = function ($rConn, string $rCmd) use ($rRun, $rIid, $rFp): array {
			if (str_contains($rCmd, 'XC_VM::install_id()')) {
				$this->rCommands[] = $rCmd;
				return ['output' => "PHP Warning: noise\n{$rIid}\n", 'error' => ''];
			}
			if (str_contains($rCmd, 'XC_VM::cluster_pin(')) {
				$this->rCommands[] = $rCmd;
				return ['output' => 'OK ' . $rFp . "\n", 'error' => ''];
			}
			return $rRun($rConn, $rCmd);
		};
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rNode, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, $rLog);
		$this->assertStringContainsString("Panel key pinned in the node's xcvm_core", $rLog);
		$this->assertSame($rIid, \XcVm\Domain\Cluster\CorePins::installId(self::SID), 'recorded for later packs');
		$this->assertTrue(\XcVm\Domain\Cluster\CorePins::current(NodeRegistry::byServer(self::SID)));

		$rAskAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'XC_VM::install_id()')));
		$rPinAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'XC_VM::cluster_pin(')));
		$this->assertStringStartsWith('sudo ', $this->rCommands[$rAskAt], 'as root');
		$this->assertStringContainsString('is_file(', $this->rCommands[$rAskAt], 'never creates an install_id');
		$this->assertStringContainsString(base64_encode($this->rCrypto->pack($rIid)), $this->rCommands[$rPinAt], 'the blob packed for that install_id');
		$this->assertStringContainsString('), true);', $this->rCommands[$rPinAt], 'replace: the SSH session is the authorised re-pin path');
		$rRunAt = array_key_last(array_filter($this->rCommands, static fn($c) => str_contains($c, 'bash') && str_contains($c, 'run.sh')));
		$this->assertLessThan($rRunAt, $rPinAt, 'pinned before the agent runs');
	}

	public function testAPinThatFailsDoesNotFailTheInstall(): void {
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `install_id` varchar(64) DEFAULT NULL');
		[$rRun, $rSend] = $this->fakeSsh();
		$rNode = static function ($rConn, string $rCmd) use ($rRun): array {
			if (str_contains($rCmd, 'XC_VM::install_id()')) {
				return ['output' => "6c1b5bd2-2c86-4a4a-9a57-0f6e6d3b1f10\n", 'error' => ''];
			}
			if (str_contains($rCmd, 'XC_VM::cluster_pin(')) {
				return ['output' => "ERR CRYPTO\n", 'error' => ''];
			}
			return $rRun($rConn, $rCmd);
		};
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rNode, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, $rLog);
		$this->assertStringContainsString('not pinned yet (cluster_pin: ERR CRYPTO); MAIN pins it once the node takes root commands', $rLog);

		// An old extension on the node answers nothing: only a reason, too.
		$this->assertStringContainsString('no install_id', (string) LbInstallFlow::pinCore(null, $rRun, $this->rCrypto, self::SID));
		// And an unlicensed MAIN packs nothing.
		$this->rCrypto->rLicensed = false;
		$this->assertSame('cluster_pack refused: LICENCE', LbInstallFlow::pinCore(null, $rNode, $this->rCrypto, self::SID));
	}

	public function testUnreachableMainStopsBeforeAnyToken(): void {
		$this->rProbeOk = false;
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertFalse($rOk);
		$this->assertStringContainsString("cannot reach MAIN's cluster API", $rLog);
		$this->assertNull(NodeRegistry::byServer(self::SID), 'no node row, so no token');
		$this->assertSame(4, $this->serverStatus());
	}

	/** @return array{0: bool, 1: string} whether the install succeeded, and what it printed */
	private function provision(): array {
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		return [$rOk, (string) ob_get_clean()];
	}

	public function testANodeWithoutRunShIsNotReportedEnrolled(): void {
		$this->rAgentStart = 'NO_RUNSH';
		[$rOk, $rLog] = $this->provision();
		$this->assertFalse($rOk);
		$this->assertStringContainsString('no bin/xc_agent/run.sh', $rLog);
		$this->assertStringNotContainsString('Node enrolled', $rLog);
		$this->assertSame(4, $this->serverStatus());
	}

	public function testAnAgentThatDoesNotStartIsNotReportedEnrolled(): void {
		$this->rAgentStart = '';
		[$rOk, $rLog] = $this->provision();
		$this->assertFalse($rOk);
		$this->assertStringContainsString('xc_agent did not start', $rLog);
		$this->assertStringNotContainsString('Node enrolled', $rLog);
	}

	public function testLicenceRefusalStopsTheInstall(): void {
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertFalse($rOk);
		$this->assertStringContainsString('CLUSTER_LICENCE_REQUIRED', $rLog);
		$this->assertSame(4, $this->serverStatus());
	}

	public function testEnrolOnALiveNodeNeverMarksItFailed(): void {
		// server:enrol passes $rMarkFailed = false: a failed enrolment leaves a
		// serving LB as it was.
		$this->rDb->query('UPDATE `servers` SET `status` = 1 WHERE `id` = 9');
		$this->rProbeOk = false;
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, false);
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertSame(1, $this->serverStatus());
	}

	public function testReEnrolmentStopsTheRunningAgentFirstAndRaisesTheGeneration(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		ob_start();
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, false));
		$rFirst = NodeRegistry::byServer(self::SID);
		$this->rCommands = [];
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto, false));
		ob_end_clean();
		$rSecond = NodeRegistry::byServer(self::SID);
		$this->assertNotSame($rFirst['node_uuid'], $rSecond['node_uuid'], 'a new identity');
		$this->assertSame((int) $rFirst['gen'] + 1, (int) $rSecond['gen']);
		$rStopAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, 'pkill -u xc_vm -x xc_agent')));
		$rKeygenAt = array_key_first(array_filter($this->rCommands, static fn($c) => str_contains($c, ' keygen ')));
		$this->assertNotNull($rStopAt);
		$this->assertLessThan($rKeygenAt, $rStopAt, 'the old agent is stopped before its state is replaced');
		$this->assertStringContainsString('run.sh', $this->rCommands[$rStopAt], 'the supervisor too, first');
	}

	public function testLegacyWhenDisabledOrWithoutAnAgent(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		SettingsManager::set(['cluster_api_enabled' => 0]);
		$this->assertTrue(LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto));
		$this->assertSame([], $this->rCommands, 'API off: the node is not touched');

		SettingsManager::set(['cluster_api_enabled' => 1]);
		ob_start();
		$this->rAgentInstalls = false;
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, 'no agent binary: the install goes on, legacy');
		$this->assertStringContainsString('No xc_agent on the node (Failed to download xc_agent-linux-amd64); the node stays legacy', $rLog);
		$this->assertNull(NodeRegistry::byServer(self::SID));
		$this->assertSame(0, $this->serverStatus());
	}

	/**
	 * An API-mode node holds no credentials and has no replica before its
	 * enrolment: it fetches the agent booted from its empty replica, handed
	 * MAIN's channel and canary, which a legacy node reads from MAIN's database.
	 */
	public function testAnApiModeNodeFetchesItsAgentFromItsReplicaWithMainsCanary(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'api', 'update_channel_fanout' => 'beta', 'lb_binary_canary_server' => 4, 'lb_release_pin' => '1.5.0']);
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		$rLog = (string) ob_get_clean();
		$this->assertTrue($rOk, $rLog);
		$rFetch = array_values(array_filter($this->rCommands, static fn($c) => str_contains($c, 'console.php fanout_binary agent')));
		$this->assertStringContainsString("console.php fanout_binary agent --replica '--channel=beta' '--canary=4' '--pin=1.5.0' 2>&1", $rFetch[0]);
		// What the node's console makes of that line.
		$rArgv = ['console.php', 'fanout_binary', 'agent', '--replica', '--channel=beta', '--canary=4', '--pin=1.5.0'];
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv($rArgv));
		$this->assertSame(['update_channel_fanout' => 'beta', 'lb_binary_canary_server' => '4', 'lb_release_pin' => '1.5.0'], FanoutBinaryCommand::mainSettings(array_slice($rArgv, 2)));
		$this->assertSame([], FanoutBinaryCommand::mainSettings(['agent', 'force', '--pin', '--other=1']), 'a bare option, or another, is no setting');
	}

	/** An API-mode node starts once its replica is built: XC_VM restarted, then the startup's commands; never before. */
	public function testAnApiModeNodeStartsOnceItsReplicaIsBuilt(): void {
		$rAnswers = ['WAIT', 'WAIT', 'READY'];
		$rRan = [];
		$rRun = static function ($rConn, string $rCmd) use (&$rAnswers, &$rRan): array {
			$rRan[] = $rCmd;
			return ['output' => str_contains($rCmd, 'ReplicaBoot::now()') ? array_shift($rAnswers) . "\n" : '', 'error' => ''];
		};
		$rPauses = 0;
		$rLine = LbInstallFlow::startOnReplica(null, $rRun, static function () use (&$rPauses): void {
			$rPauses++;
		});
		$this->assertSame('The node booted from its replica: XC_VM restarted on it', $rLine);
		$this->assertSame(2, $rPauses);
		$this->assertSame(3, array_search('sudo systemctl restart xc_vm', $rRan, true), 'after the look that saw it built');
		$rStartupAt = array_key_first(array_filter($rRan, static fn($c) => str_contains($c, 'console.php startup')));
		$this->assertGreaterThan(3, $rStartupAt);
		$this->assertStringContainsString('console.php ffmpeg', end($rRan));

		$rRan = [];
		$rAnswers = array_fill(0, 100, 'WAIT');
		$rLine = LbInstallFlow::startOnReplica(null, $rRun, static function (): void {
		});
		$this->assertStringContainsString('restart XC_VM on the node (systemctl restart xc_vm)', $rLine);
		$this->assertNotContains('sudo systemctl restart xc_vm', $rRan, 'never started from no replica');
		$this->assertCount(60, $rRan);
	}

	public function testKeysThatDoNotMatchTheirSasAreRefused(): void {
		[$rRun, $rSend] = $this->fakeSsh();
		$rTampered = function ($rConn, string $rCmd) use ($rRun): array {
			$rOut = $rRun($rConn, $rCmd);
			if (str_contains($rCmd, ' keygen ')) {
				$rJ = json_decode($rOut['output'], true);
				$rJ['sas'] = 'AAAA-AAAA-AAAA-AAAA-AAAA-AAAA';
				$rOut['output'] = json_encode($rJ);
			}
			return $rOut;
		};
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, $rTampered, $rSend, $this->servers(), self::SID, $this->rDb, $this->rCrypto);
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertNull(NodeRegistry::byServer(self::SID));
	}

	/**
	 * The same flow with the real xc_agent on a "node" that is a temp dir on
	 * this machine: the command strings, the JSON both ways and the token must
	 * work with the Go binary. MAIN's health is served by `php -S`.
	 *
	 *   XCVM_AGENT_BIN=/path/to/xc_agent php tests/phpunit.phar -c tests/phpunit.xml.dist --filter LbProvisionClusterTest
	 */
	public function testWithTheRealAgent(): void {
		$rBin = (string) getenv('XCVM_AGENT_BIN');
		if ($rBin === '' || !is_executable($rBin)) {
			$this->markTestSkipped('XCVM_AGENT_BIN not set');
		}
		$rNode = sys_get_temp_dir() . '/xcvm-node-' . bin2hex(random_bytes(4));
		mkdir($rNode, 0700, true);
		$rMap = [MAIN_HOME => $rNode . '/home/', CONFIG_PATH => $rNode . '/config/'];
		$rLocal = static fn(string $rS) => strtr($rS, $rMap);

		// MAIN's health endpoint, signed by the same fake panel key.
		$rSock = stream_socket_server('tcp://127.0.0.1:0');
		$rPort = (int) substr(strrchr(stream_socket_get_name($rSock, false), ':'), 1);
		fclose($rSock);
		$rRouter = $rNode . '/router.php';
		file_put_contents($rRouter, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' $r = \XcVm\Domain\Cluster\ClusterApi::handle(new \XcVm\Tests\Support\FakeClusterCrypto(), ["method" => "GET", "path" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "headers" => []], [], []);'
			. ' http_response_code($r["status"]); foreach ($r["headers"] as $k => $v) { header("$k: $v"); } echo $r["body"]; return true;');
		$rServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rPort, $rRouter], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $rPort); $i++) {
			usleep(100000);
		}
		try {
			$rRun = function ($rConn, string $rCmd) use ($rLocal, $rBin): array {
				if (str_contains($rCmd, 'console.php fanout_binary agent')) {
					@mkdir(dirname($rLocal(LbInstallFlow::AGENT_BIN)), 0700, true);
					return ['output' => copy($rBin, $rLocal(LbInstallFlow::AGENT_BIN)) && chmod($rLocal(LbInstallFlow::AGENT_BIN), 0700) ? "AGENT_OK\n" : '', 'error' => ''];
				}
				// The node is this machine: drop sudo, map the node's paths into the temp dir.
				$rCmd = (string) preg_replace('/sudo( -u xc_vm)? /', '', $rLocal($rCmd));
				if (str_contains($rCmd, 'chown') || str_contains($rCmd, 'run.sh')) {
					$rCmd = (string) preg_replace('/(chown|chmod) [^&;]*(&&|;|$)/', 'true $2', $rCmd);
					if (str_contains($rCmd, 'run.sh')) {
						// The daemon is not this test's: seen running, as provisionCluster() checks.
						return ['output' => "STARTED\n", 'error' => ''];
					}
				}
				exec($rCmd . ' 2>&1', $rOut);
				return ['output' => implode("\n", $rOut) . "\n", 'error' => ''];
			};
			$rSend = static function ($rConn, string $rFrom, string $rTo) use ($rLocal): bool {
				$rTo = $rLocal($rTo);
				@mkdir(dirname($rTo), 0700, true);
				return copy($rFrom, $rTo) && chmod($rTo, 0700);
			};
			$rServers = [SERVER_ID => ['server_ip' => '127.0.0.1', 'http_broadcast_port' => $rPort, 'enable_https' => 0], self::SID => []];
			ob_start();
			$rOk = LbInstallFlow::provisionCluster(null, $rRun, $rSend, $rServers, self::SID, $this->rDb, $this->rCrypto);
			$rLog = (string) ob_get_clean();
			$this->assertTrue($rOk, $rLog);
			exec(escapeshellarg($rBin) . ' health -state ' . escapeshellarg($rLocal(LbInstallFlow::AGENT_STATE)) . ' 2>&1', $rHealth, $rCode);
			$this->assertSame(0, $rCode, implode("\n", $rHealth));
			$this->assertSame('enrolling', NodeRegistry::byServer(self::SID)['state']);
		} finally {
			proc_terminate($rServer);
			exec('rm -rf ' . escapeshellarg($rNode));
		}
	}
}
