<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterRotateSignKeyCommand;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * `node.root rotate_sign_key` (ADR 0004, Phase 4 seventh increment): MAIN's
 * panel signing key re-pinned on a node's root side without SSH, under a
 * command signed with the key it pins today.
 */
final class RotateSignKeyTest extends TestCase {
	private const SID = 17;

	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			foreach (array_filter(array_map('trim', explode(';', (string) $rSql))) as $rStatement) {
				$this->rDb->exec($rStatement);
			}
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		ClusterMeta::set('panel_sign_pub', base64_encode($this->rCrypto->info()['panel_sign_pub']));
		NodeRegistry::startEnrolment(self::SID, self::UUID, random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS, 'root_ready' => 1]);
		$this->rDir = sys_get_temp_dir() . '/xcvm-pin-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'inbox', 0755, true);
		RootPin::useDirs($this->rDir, $this->rDir . 'inbox/');
		RootPin::write($this->rCrypto->info()['panel_sign_pub'], self::UUID);
		RootPin::raiseHighWater(41);
	}

	protected function tearDown(): void {
		RootPin::useDirs(null, null);
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function runCmd(array $rArgs): array {
		ob_start();
		$rCode = (new ClusterRotateSignKeyCommand())->execute($rArgs);
		return [$rCode, (string) ob_get_clean()];
	}

	public function testTheFleetIsRePinnedUnderTheKeyItTrustsToday(): void {
		$rNew = bin2hex(random_bytes(32));
		[$rCode, $rText] = $this->runCmd(['--pub=' . $rNew]);
		$this->assertSame(1, $rCode, 'the fingerprint is shown and --yes asked for first');
		$this->assertStringContainsString(RootPin::fingerprint((string) hex2bin($rNew)), $rText);
		$this->assertSame([], CommandBus::pending(self::SID, 0));

		[$rCode, $rText] = $this->runCmd(['--pub=' . $rNew, '--yes']);
		$this->assertSame(0, $rCode, $rText);
		$rRow = CommandBus::pending(self::SID, 0)[0];
		// Root verifies it against the pin it holds today, and re-pins.
		$rCmd = RootPin::verify((array) RootPin::read(), $rRow['doc'], (string) Enc::b64urlDecode($rRow['sig']), 1800000000, RootPin::highWater() - 100);
		$this->assertIsArray($rCmd, is_string($rCmd) ? $rCmd : '');
		$this->assertSame(['rotate_sign_key', $rNew], [$rCmd['action'], $rCmd['args']['new_pub']]);
		$this->assertStringStartsWith('Panel signing key re-pinned', RootPin::rotate($rCmd['args']));
		$this->assertSame(['pub' => (string) hex2bin($rNew), 'node' => self::UUID], RootPin::read());
		$this->assertSame(0, RootPin::highWater(), 'a new key is a new command stream');
		$this->assertFalse(RootPin::matches($this->rCrypto->info()['panel_sign_pub'], self::UUID), 'the agent\'s old key no longer matches: root_ready goes false');
	}

	public function testItsOwnKeyAndABadKeyAreRefused(): void {
		$this->assertSame(1, $this->runCmd(['--pub=' . bin2hex($this->rCrypto->info()['panel_sign_pub']), '--yes'])[0]);
		$this->assertSame(1, $this->runCmd(['--pub=xyz', '--yes'])[0]);
		$this->assertSame([], CommandBus::pending(self::SID, 0));
		$this->expectExceptionMessage('32-byte hex');
		RootPin::rotate(['new_pub' => 'nothex']);
	}

	public function testANodeWithoutRootCommandsIsListedNotReached(): void {
		NodeRegistry::update(self::SID, ['root_ready' => 0]);
		[$rCode, $rText] = $this->runCmd(['--pub=' . bin2hex(random_bytes(32)), '--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Not reached (no root commands, or not queued): 17', $rText);
	}

	/** An extension pin blob the node cannot take refuses the whole rotation: root's pin stays. */
	public function testAPinBlobTheExtensionCannotTakeChangesNothing(): void {
		$rWas = RootPin::read();
		try {
			RootPin::rotate(['new_pub' => bin2hex(random_bytes(32)), 'pin_blob' => base64_encode('blob')]);
			$this->fail('re-pinned without the extension pin');
		} catch (\RuntimeException $rE) {
			$this->assertMatchesRegularExpression('/the extension (pin cannot be replaced|refused the pin blob)/', $rE->getMessage(), 'no extension here, or the real one refuses a bogus blob');
		}
		$this->assertSame($rWas, RootPin::read());
	}

	public function testTheActionIsCatalogued(): void {
		$this->assertContains('rotate_sign_key', NodeActions::ROOT_ACTIONS);
		$this->assertContains('rotate_sign_key', NodeActions::CLUSTER_ONLY);
		$this->assertStringContainsString("case 'rotate_sign_key':", (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php'));
	}
}
