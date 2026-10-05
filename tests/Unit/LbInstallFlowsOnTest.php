<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * LbInstallFlow::flowsOn — a fresh install switches its node's flows on, as
 * the Cluster Nodes page does one at a time: once the node is active, and the
 * data plane only for an agent that says `relay`. A node that enrols any other
 * way keeps its flows off (EnrolmentService), which this does not touch.
 */
final class LbInstallFlowsOnTest extends TestCase {
	private const ALL_BUT_DATAPLANE = ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_DATAPLANE;

	private TestDb $rDb;

	private int $rPauses = 0;

	/** The node's generation before the install: none, so the row it finds (gen 1) is the install's own. */
	private int $rGenBefore = 0;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'enrolling', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 0, `gen` int NOT NULL DEFAULT 1, `features` varchar(255) DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		DatabaseFactory::set($this->rDb);
		foreach ([NodeRegistry::class, ClusterAudit::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		ClusterClock::fix(1800000000000);
		$this->rPauses = 0;
		$this->rGenBefore = 0;
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function node(string $rState, ?string $rFeatures, int $rFlows = 0, int $rMode = 1): void {
		$this->rDb->query('REPLACE INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `features`) VALUES (7, ?, ?, ?, ?, ?)', '0f8fad5b-d9cb-469f-a165-70867728950e', $rState, $rMode, $rFlows, $rFeatures);
	}

	/** flowsOn() with a pause that counts, and runs $rThen (the agent, meanwhile) at its $rAt-th. */
	private function flowsOn(?callable $rThen = null, int $rAt = 1): string {
		return LbInstallFlow::flowsOn(7, $this->rGenBefore, function () use ($rThen, $rAt): void {
			if (++$this->rPauses === $rAt && $rThen !== null) {
				$rThen();
			}
		});
	}

	private function flows(): int {
		return (int) $this->rDb->pdo->query('SELECT `flows` FROM `cluster_nodes` WHERE `server_id` = 7')->fetchColumn();
	}

	/** @return list<string> "actor detail" of each node.flows entry */
	private function audit(): array {
		return $this->rDb->pdo->query("SELECT CONCAT(`actor`, ' ', `detail`) FROM `cluster_audit` WHERE `event` = 'node.flows' ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testAnActiveNodeWhoseAgentRelaysGetsEveryFlow(): void {
		$this->node('active', 'hls_reaper,relay,streams');
		$this->assertSame("The node's cluster flows are on (all eight)", $this->flowsOn());
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->flows());
		$this->assertSame(0, $this->rPauses);
		$this->assertSame(['install {"flows":255,"was":0}'], $this->audit());
	}

	public function testTheDataPlaneWaitsForAnAgentThatRelays(): void {
		// An agent that names its features, without the relay: no waiting for more.
		$this->node('active', 'hls_reaper,streams');
		$this->assertStringContainsString('but for the data plane', $this->flowsOn());
		$this->assertSame(self::ALL_BUT_DATAPLANE, $this->flows());
		$this->assertSame(0, $this->rPauses);

		// One that names none (an older agent) is given a few looks to say hello first.
		$this->node('active', null);
		$this->assertStringContainsString('but for the data plane', $this->flowsOn());
		$this->assertSame(self::ALL_BUT_DATAPLANE, $this->flows());
		$this->assertSame(5, $this->rPauses);
		$this->assertTrue(NodeRegistry::validFlows(self::ALL_BUT_DATAPLANE));
	}

	public function testItWaitsForTheNodeToCompleteItsEnrolment(): void {
		$this->node('enrolling', null);
		$rLine = $this->flowsOn(fn() => $this->node('active', 'relay'), 3);
		$this->assertSame("The node's cluster flows are on (all eight)", $rLine);
		$this->assertSame(3, $this->rPauses);
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->flows());
	}

	public function testANodeThatNeverCompletesKeepsItsFlowsOff(): void {
		$this->node('enrolling', null);
		$this->assertStringContainsString('stay off: it has not completed its enrolment yet', $this->flowsOn());
		$this->assertSame(30, $this->rPauses, 'a minute, two seconds at a time');
		$this->assertSame(0, $this->flows());
		$this->assertSame([], $this->audit());

		// Nor one that MAIN quarantined or revoked meanwhile.
		$this->node('quarantined', 'relay');
		$this->assertStringContainsString('stay off', $this->flowsOn());
		$this->assertSame(0, $this->flows());
	}

	public function testNothingIsSaidWhereNothingIsToSwitch(): void {
		// No node row: the cluster API is off, or the node took no agent.
		$this->assertSame('', $this->flowsOn());
		$this->assertSame(0, $this->rPauses);

		// Born in mode 2: its row had every flow from its first write, enrolled yet or not.
		$this->node('enrolling', null, ClusterAdmin::MODE2_FLOWS, 2);
		$this->assertSame('', $this->flowsOn());
		$this->assertSame(0, $this->rPauses, 'nothing to wait for');
		$this->assertSame([], $this->audit());
	}

	/**
	 * An install that could not enrol its node (no agent to be had, the API
	 * down) says so and goes on: the row it finds is an earlier enrolment's,
	 * whose agent it has stopped.
	 */
	public function testARowTheInstallDidNotMakeIsLeftAlone(): void {
		$this->node('active', 'relay');
		$this->assertSame(1, LbInstallFlow::nodeGen(7));
		$this->rGenBefore = 1;
		$this->assertSame('', $this->flowsOn());
		$this->assertSame(0, $this->flows());
		$this->assertSame(0, $this->rPauses);
		$this->assertSame([], $this->audit());
		$this->assertSame(0, LbInstallFlow::nodeGen(8), 'a server with no node row');
	}
}
