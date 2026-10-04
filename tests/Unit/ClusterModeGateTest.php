<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * ClusterAdmin::modeGate() — what an operator may do to a node's mode. Mode 2
 * stops the node reaching MAIN's database, so it is gated on every flow (the
 * data plane included, since Phase 8) and on the node's own connect audit (plan, section 11: zero MySQL
 * and zero Redis connects for seven days).
 */
final class ClusterModeGateTest extends TestCase {
	private const NOW = 1790000000;
	private const CLEAN = ['sql_connects' => 0, 'redis_connects' => 0, 'connects_since' => self::NOW - 8 * 86400];

	private function node(int $rMode, int $rFlows, string $rState = 'active', int $rRootReady = 1): array {
		return ['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState, 'root_ready' => $rRootReady];
	}

	private function gate(array $rNode, int $rMode, ?array $rConnects = null): array {
		return ClusterAdmin::modeGate($rNode, $rConnects, $rMode, self::NOW);
	}

	public function testGoingDownIsAlwaysAllowed(): void {
		$this->assertTrue($this->gate($this->node(2, 0), 1)[0]);
		$this->assertTrue($this->gate($this->node(1, 0), 0)[0]);
	}

	public function testModeOneNeedsTheConfigFlow(): void {
		[$rOk, $rWhy] = $this->gate($this->node(0, NodeRegistry::FLOW_TELEMETRY), 1);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_config', $rWhy);

		$this->assertTrue($this->gate($this->node(0, NodeRegistry::FLOW_CONFIG), 1)[0]);
	}

	public function testModeTwoNeedsEveryFlowTheDataPlaneIncluded(): void {
		[$rOk, $rWhy] = $this->gate($this->node(1, NodeRegistry::FLOW_CONFIG), 2, self::CLEAN);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_flows', $rWhy);

		$this->assertTrue($this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS), 2, self::CLEAN)[0]);
		// Phase 8: a node in mode 2 puts no stream secret in a URL either.
		$this->assertSame(NodeRegistry::FLOW_DATAPLANE, ClusterAdmin::MODE2_FLOWS & NodeRegistry::FLOW_DATAPLANE);
		[$rOk, $rWhy] = $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_DATAPLANE), 2, self::CLEAN);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_flows', $rWhy);
	}

	public function testModeTwoNeedsASevenDayCleanAudit(): void {
		$rNode = $this->node(1, ClusterAdmin::MODE2_FLOWS);

		$this->assertSame('cluster_mode_no_audit', $this->gate($rNode, 2, null)[1]);
		$this->assertSame(
			'cluster_mode_still_connects',
			$this->gate($rNode, 2, ['sql_connects' => 3, 'redis_connects' => 0, 'connects_since' => self::NOW - 9 * 86400])[1]
		);
		$this->assertSame(
			'cluster_mode_still_connects',
			$this->gate($rNode, 2, ['sql_connects' => 0, 'redis_connects' => 1, 'connects_since' => self::NOW - 9 * 86400])[1]
		);
		$this->assertSame(
			'cluster_mode_too_soon',
			$this->gate($rNode, 2, ['sql_connects' => 0, 'redis_connects' => 0, 'connects_since' => self::NOW - 3 * 86400])[1]
		);
		$this->assertSame(
			'cluster_mode_too_soon',
			$this->gate($rNode, 2, ['sql_connects' => 0, 'redis_connects' => 0])[1]
		);
		$this->assertTrue($this->gate($rNode, 2, self::CLEAN)[0]);
	}

	/** ADR 0004, Mode 2: every flow on and root's pin in place (root_ready). */
	public function testModeTwoNeedsRootsPin(): void {
		[$rOk, $rWhy] = $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS, 'active', 0), 2, self::CLEAN);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_root', $rWhy);

		// A row without the column (never reported) is refused the same way.
		$this->assertSame('cluster_mode_needs_root', $this->gate(['mode' => 1, 'flows' => ClusterAdmin::MODE2_FLOWS, 'state' => 'active'], 2, self::CLEAN)[1]);

		// Mode 1 and going down do not need it.
		$this->assertTrue($this->gate($this->node(0, NodeRegistry::FLOW_CONFIG, 'active', 0), 1)[0]);
		$this->assertTrue($this->gate($this->node(2, ClusterAdmin::MODE2_FLOWS, 'active', 0), 1)[0]);

		$this->assertTrue($this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS, 'active', 1), 2, self::CLEAN)[0]);
	}

	public function testOnlyModesZeroToTwoExist(): void {
		$this->assertFalse($this->gate($this->node(2, ClusterAdmin::MODE2_FLOWS), 3, self::CLEAN)[0]);
		$this->assertFalse($this->gate($this->node(0, 0), -1)[0]);
	}

	public function testEveryRefusalHasItsString(): void {
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		foreach ([
			'cluster_mode_done', 'cluster_mode_unknown', 'cluster_mode_needs_config', 'cluster_mode_needs_flows', 'cluster_mode_needs_root',
			'cluster_mode_no_audit', 'cluster_mode_still_connects', 'cluster_mode_too_soon',
			'cluster_mode_up_help', 'cluster_mode_down_help',
		] as $rKey) {
			$this->assertStringContainsString($rKey . ' = ', $rEn, $rKey);
		}
	}
}
