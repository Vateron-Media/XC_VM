<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The restrictive commands CommandBus::RESTRICTIVE listed and nothing sent
 * (ADR 0004, Phase 9): `stream.stop` / `vod.stop` for the stops the admin
 * already made as granting `node.rpc`s, the operator's `node.fence`,
 * `node.unfence`, `node.quarantine` and `resync`, the hard revocation mode's
 * licence fence, and the node's half — cluster:exec running the stops, the
 * command fence NodeLease reads, and live.php honouring a drop.
 */
final class ClusterRestrictiveProducersTest extends TestCase {
	private const SID = 17;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rLog;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec((string) $rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS]);
		$this->rLog = (string) tempnam(sys_get_temp_dir(), 'xcvm-log');
		FileLogger::setLogFile($this->rLog);
		$this->rDir = sys_get_temp_dir() . '/xcvm-fence-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
		ClusterRoute::useCrypto(null);
		ClusterExecCommand::useStopper(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		NodeFlows::usePath(null);
		NodeLease::usePath(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array{type: string, class: string, dedupe: ?string, args: array<string, mixed>}> */
	private function queued(): array {
		$this->rDb->query("SELECT `type`, `class`, `dedupe_key`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `state` = 'queued' ORDER BY `seq`", self::SID);
		return array_map(static fn(array $rRow): array => ['type' => (string) $rRow['type'], 'class' => (string) $rRow['class'], 'dedupe' => $rRow['dedupe_key'], 'args' => (array) json_decode((string) $rRow['payload'], true)['args']], $this->rDb->get_rows());
	}

	public function testAStopIsRestrictiveAndEverythingElseStaysAnRpc(): void {
		$this->assertSame(['stream.stop', [3, 4]], ClusterRoute::stops(['action' => 'stream', 'function' => 'stop', 'stream_ids' => ['3', 4, 4, 0]]));
		$this->assertSame(['vod.stop', [9]], ClusterRoute::stops(['action' => 'vod', 'function' => 'stop', 'stream_ids' => [9]]));
		$this->assertNull(ClusterRoute::stops(['action' => 'stream', 'function' => 'start', 'stream_ids' => [3]]));
		$this->assertNull(ClusterRoute::stops(['action' => 'stream', 'function' => 'stop', 'stream_ids' => []]));
		$this->assertNull(ClusterRoute::stops(['action' => 'get_pids']));

		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'stop', 'stream_ids' => [3, 4]]));
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'stop', 'stream_ids' => [3]]), 'a second click');
		$this->assertSame([
			['type' => 'stream.stop', 'class' => 'R', 'dedupe' => 'stream.stop:4', 'args' => ['stream_id' => 4]],
			['type' => 'stream.stop', 'class' => 'R', 'dedupe' => 'stream.stop:3', 'args' => ['stream_id' => 3]],
		], $this->queued(), 'one stop per stream, deduped');
	}

	/**
	 * A start becomes its typed command (`stream.start`, `vod.start`, one per
	 * stream, deduped, granting) only on a node whose PHP runs it, which its
	 * agent says at hello (`typed_starts`); any other node keeps `node.rpc`,
	 * which an older PHP understands.
	 */
	public function testAStartIsTypedOnlyWhereTheNodesPhpRunsIt(): void {
		$this->assertSame(['stream.start', [3, 4], false], ClusterRoute::starts(['action' => 'stream', 'function' => 'start', 'stream_ids' => ['3', 4, 4, 0]]));
		$this->assertSame(['vod.start', [9], true], ClusterRoute::starts(['action' => 'vod', 'function' => 'start', 'stream_ids' => [9], 'force' => 1]));
		$this->assertNull(ClusterRoute::starts(['action' => 'stream', 'function' => 'stop', 'stream_ids' => [3]]));

		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'start', 'stream_ids' => [3]]));
		$this->assertSame(['node.rpc'], array_column($this->queued(), 'type'), 'no typed_starts: the RPC');

		$this->rDb->exec('DELETE FROM `cluster_commands`');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->query('UPDATE `cluster_nodes` SET `features` = ? WHERE `server_id` = ?', 'artefact,' . ClusterRoute::FEATURE_TYPED_STARTS, self::SID);
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'start', 'stream_ids' => [3, 4]]));
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'start', 'stream_ids' => [3]]), 'a second click');
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'vod', 'function' => 'start', 'stream_ids' => [9], 'force' => true]));
		$this->assertSame([
			['type' => 'stream.start', 'class' => 'G', 'dedupe' => 'stream.start:4', 'args' => ['stream_id' => 4]],
			['type' => 'stream.start', 'class' => 'G', 'dedupe' => 'stream.start:3', 'args' => ['stream_id' => 3]],
			['type' => 'vod.start', 'class' => 'G', 'dedupe' => 'vod.start:9', 'args' => ['stream_id' => 9, 'force' => true]],
		], $this->queued(), 'one start per stream, deduped');
	}

	/** Stops are restrictive: they are signed when the licence is gone, which is when they matter. */
	public function testAStopIsSignedWithoutALicence(): void {
		$this->rCrypto->rRefuseSign = 'LICENCE';
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'vod', 'function' => 'stop', 'stream_ids' => [9]]));
		$this->assertSame([true, false], ClusterRoute::send(self::SID, ['action' => 'vod', 'function' => 'start', 'stream_ids' => [9]]), 'a start still needs the licence');
	}

	public function testAnUnfenceSupersedesAFenceNotYetTaken(): void {
		$this->assertSame([true, true], ClusterRoute::fence(self::SID, 'admin', 99));
		$this->assertSame([['type' => 'node.fence', 'class' => 'R', 'dedupe' => 'node.fence', 'args' => ['reason' => 'admin', 'drain_min' => 60]]], $this->queued(), 'the drain is held to its bound');
		$this->assertSame([true, true], ClusterRoute::unfence(self::SID));
		$this->assertSame(['node.unfence'], array_column($this->queued(), 'type'));
		$this->assertSame('G', $this->queued()[0]['class'], 'lifting a fence needs the licence');
	}

	/**
	 * The hard revocation mode without a licence fences every node that takes
	 * commands, once while a fence waits; the graceful mode and a licensed panel
	 * fence nothing (the lease does it there).
	 */
	public function testTheLicenceFenceIsQueuedOnceInTheHardModeOnly(): void {
		$rHard = ['lb_revocation_mode' => 'hard', 'lb_fence_drain_min' => 5];
		$this->assertSame(0, ClusterRoute::licenceFences($this->rCrypto, $rHard), 'licensed');
		$this->rCrypto->rLicensed = false;
		$this->assertSame(0, ClusterRoute::licenceFences($this->rCrypto, ['lb_revocation_mode' => 'graceful']));
		$this->assertSame(1, ClusterRoute::licenceFences($this->rCrypto, $rHard));
		$this->assertSame(0, ClusterRoute::licenceFences($this->rCrypto, $rHard), 'one fence waiting is enough');
		$this->assertSame([['type' => 'node.fence', 'class' => 'R', 'dedupe' => 'node.fence', 'args' => ['reason' => ClusterRoute::LICENCE_FENCE, 'drain_min' => 5]]], $this->queued());
		$this->assertTrue(CommandBus::waiting(self::SID, ClusterRoute::FENCE_KEY));
	}

	/** A quarantined node is handed its restrictive commands only, until an admin trusts it again. */
	public function testAQuarantineHoldsBackWhatGrantsUntilTrust(): void {
		$this->assertSame([true, true], ClusterRoute::quarantine(self::SID, 'admin'));
		$this->assertSame('quarantined', NodeRegistry::byServer(self::SID)['state']);
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'stop', 'stream_ids' => [3]]), 'a stop still reaches it');
		$this->assertSame([false, false], ClusterRoute::send(self::SID, ['action' => 'get_pids']), 'an RPC does not');
		$rTypes = array_map(static fn(array $rC): string => json_decode($rC['doc'], true)['type'], CommandBus::pending(self::SID, 0, 50, true));
		$this->assertSame(['node.quarantine', 'stream.stop'], $rTypes);

		$this->assertTrue(ClusterRoute::trust(self::SID));
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);
		$this->assertContains('token.rotate_now', array_column($this->queued(), 'type'), 'trusting again rotates the token');
		$this->assertFalse(ClusterRoute::trust(self::SID), 'only a quarantined node is trusted again');
	}

	public function testClusterExecRunsTheStops(): void {
		$rRan = [];
		ClusterExecCommand::useStopper(static function (string $rType, int $rID) use (&$rRan): void {
			$rRan[] = [$rType, $rID];
		});
		foreach (['stream.stop', 'vod.stop', 'stream.start', 'vod.start'] as $rType) {
			$this->assertContains($rType, ClusterExecCommand::TYPES);
		}
		ob_start();
		$this->assertSame(0, ClusterExecCommand::run(['type' => 'stream.stop', 'args' => ['stream_id' => 5]]));
		$this->assertSame(0, ClusterExecCommand::run(['type' => 'vod.stop', 'args' => ['stream_id' => 6]]));
		$this->assertSame(0, ClusterExecCommand::run(['type' => 'stream.start', 'args' => ['stream_id' => 7]]));
		$this->assertSame(0, ClusterExecCommand::run(['type' => 'vod.start', 'args' => ['stream_id' => 8, 'force' => true]]));
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'stream.stop', 'args' => ['stream_id' => '5; rm']]));
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'stream.start', 'args' => []]));
		ob_end_clean();
		$this->assertSame([['stream.stop', 5], ['vod.stop', 6], ['stream.start', 7], ['vod.start', 8]], $rRan);
	}

	private function fenceFile(string $rState, int $rAgeMs = 0): void {
		$rNow = (int) round(microtime(true) * 1000);
		file_put_contents($this->rDir . '/fence.json', (string) json_encode(['state' => $rState, 'reason' => 'admin', 'since_ms' => $rNow - 1000, 'drain_until_ms' => $rNow + 60000, 'wrote_at_ms' => $rNow - $rAgeMs]));
		file_put_contents($this->rDir . '/flows.json', (string) json_encode(['mode' => 1, 'flows' => NodeFlows::CONFIG, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		NodeLease::usePath($this->rDir . '/absent.json', $this->rDir . '/fence.json');
	}

	/** MAIN's fence needs no switch: it is an explicit word the agent verified. */
	public function testNodeLeaseHonoursTheCommandFence(): void {
		SettingsManager::set([]);
		$this->fenceFile('draining');
		$this->assertTrue(NodeLease::refusesNewSessions());
		$this->assertFalse(NodeLease::refusesEverything(), 'the running viewers drain');
		$this->assertSame('fenced by MAIN (admin)', NodeLease::verdict()['why']);

		$this->fenceFile('fenced');
		$this->assertTrue(NodeLease::refusesEverything());

		// A file the agent stopped refreshing serves, as every uncertainty does.
		$this->fenceFile('fenced', (NodeLease::STALE_SEC + 1) * 1000);
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
		$this->fenceFile('bogus');
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
	}

	/** A live TS request past the drain ends at its next segment, by the verdict or by the agent's drop. */
	public function testLiveTsHonoursTheFenceAndTheDrop(): void {
		$rLive = (string) file_get_contents(MAIN_HOME . 'Public/stream/live.php');
		$this->assertMatchesRegularExpression('/while \(true\) \{.*?NodeLease::refusesEverything\(\$rSettings\).*?exit\(\);/s', $rLive);
		$this->assertMatchesRegularExpression('/\(\$rSignalData\["type"\] \?\? \'\'\) == "drop"\) \{\s*@unlink\(SIGNALS_PATH \. \$rTokenData\["uuid"\]\);\s*exit\(\);/', $rLive);
	}

	public function testTheOperatorHasTheButtonsAndTheStrings(): void {
		$rAdmin = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/ClusterAdmin.php');
		foreach (['fence', 'unfence', 'quarantine', 'resync', 'trust'] as $rAction) {
			$this->assertStringContainsString("case '" . $rAction . "':", $rAdmin);
		}
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/cluster_nodes.php');
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');
		foreach (['fence', 'unfence', 'quarantine', 'resync', 'trust'] as $rAction) {
			$this->assertStringContainsString('value="' . $rAction . '"', $rView);
			$this->assertStringContainsString('cluster_' . $rAction . '_done = ', $rEn);
			$this->assertStringContainsString('cluster_' . $rAction . '_help = ', $rEn);
		}
		foreach (['cluster_command_failed', 'cluster_trust_not_quarantined', 'cluster_fence_confirm', 'cluster_quarantine_confirm'] as $rKey) {
			$this->assertStringContainsString($rKey . ' = ', $rEn);
		}
		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/ClusterCronJob.php');
		$this->assertStringContainsString('ClusterRoute::licenceFences(', $rCron, 'the lease-fence path runs every minute');
	}
}
