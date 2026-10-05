<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * ClusterAdmin::modeGate() — what an operator may do to a node's mode. Mode 2
 * stops the node reaching MAIN's database, so it is gated on what a node needs
 * to run that way: every flow (the data plane included, since Phase 8), root's
 * pin, and a node that is active, heard a moment ago and says it runs from its
 * own copy. The connect audit is not asked: a node in mode 1 reads MAIN's
 * database by design, so the zero the gate once waited seven days for could
 * only come in mode 2. Those days now stand before the step with no way back
 * (DbCredentials::strip, ClusterCredentialsActionTest).
 */
final class ClusterModeGateTest extends TestCase {
	private function node(int $rMode, int $rFlows, string $rState = 'active', int $rRootReady = 1): array {
		return ['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState, 'root_ready' => $rRootReady];
	}

	/** The gate for a node that is heard and says its streams are local, unless told otherwise. */
	private function gate(array $rNode, int $rMode, bool $rHeard = true, ?bool $rStreamsLocal = true): array {
		return ClusterAdmin::modeGate($rNode, $rMode, $rHeard, $rStreamsLocal);
	}

	public function testGoingDownIsAlwaysAllowed(): void {
		$this->assertTrue($this->gate($this->node(2, 0), 1, false, null)[0]);
		$this->assertTrue($this->gate($this->node(1, 0), 0, false, null)[0]);
		$this->assertTrue($this->gate($this->node(2, 0, 'quarantined', 0), 1, false, false)[0], 'the way back asks nothing');
	}

	public function testModeOneNeedsTheConfigFlow(): void {
		[$rOk, $rWhy] = $this->gate($this->node(0, NodeRegistry::FLOW_TELEMETRY), 1);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_config', $rWhy);

		// And nothing of what mode 2 asks: the node still has MAIN's database there.
		$this->assertTrue($this->gate($this->node(0, NodeRegistry::FLOW_CONFIG, 'quarantined', 0), 1, false, null)[0]);
	}

	public function testModeTwoNeedsEveryFlowTheDataPlaneIncluded(): void {
		[$rOk, $rWhy] = $this->gate($this->node(1, NodeRegistry::FLOW_CONFIG), 2);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_flows', $rWhy);

		$this->assertSame([true, 'cluster_mode_done'], $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS), 2));
		// Phase 8: a node in mode 2 puts no stream secret in a URL either.
		$this->assertSame(NodeRegistry::FLOW_DATAPLANE, ClusterAdmin::MODE2_FLOWS & NodeRegistry::FLOW_DATAPLANE);
		[$rOk, $rWhy] = $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_DATAPLANE), 2);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_flows', $rWhy);
	}

	/** ADR 0004, Mode 2: every flow on and root's pin in place (root_ready). */
	public function testModeTwoNeedsRootsPin(): void {
		[$rOk, $rWhy] = $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS, 'active', 0), 2);
		$this->assertFalse($rOk);
		$this->assertSame('cluster_mode_needs_root', $rWhy);

		// A row without the column (never reported) is refused the same way.
		$this->assertSame('cluster_mode_needs_root', $this->gate(['mode' => 1, 'flows' => ClusterAdmin::MODE2_FLOWS, 'state' => 'active'], 2)[1]);

		// Mode 1 and going down do not need it.
		$this->assertTrue($this->gate($this->node(0, NodeRegistry::FLOW_CONFIG, 'active', 0), 1)[0]);
		$this->assertTrue($this->gate($this->node(2, ClusterAdmin::MODE2_FLOWS, 'active', 0), 1)[0]);
	}

	/**
	 * A node that can run without MAIN's database now: active (a quarantined
	 * one takes no command), heard a moment ago (its report is only as old as
	 * its last heartbeat), and saying by itself that it boots and reads its
	 * streams from its own copy. In mode 2 it can neither seed that store nor
	 * read them from MAIN.
	 */
	public function testModeTwoNeedsANodeThatRunsFromItsOwnCopyNow(): void {
		$rNode = $this->node(1, ClusterAdmin::MODE2_FLOWS);
		$this->assertSame([true, 'cluster_mode_done'], $this->gate($rNode, 2, true, true));

		$this->assertSame([false, 'cluster_mode_needs_active'], $this->gate($this->node(1, ClusterAdmin::MODE2_FLOWS, 'quarantined'), 2));
		$this->assertSame([false, 'cluster_mode_not_heard'], $this->gate($rNode, 2, false, true));
		$this->assertSame([false, 'cluster_mode_needs_streams'], $this->gate($rNode, 2, true, false), 'its store is not whole, or it boots from MAIN');
		$this->assertSame([false, 'cluster_mode_needs_streams'], $this->gate($rNode, 2, true, null), 'an older release, or no report yet');
		// The default is a node not heard: a caller that does not say is refused.
		$this->assertSame('cluster_mode_not_heard', ClusterAdmin::modeGate($rNode, 2)[1]);
	}

	public function testOnlyModesZeroToTwoExist(): void {
		$this->assertFalse($this->gate($this->node(2, ClusterAdmin::MODE2_FLOWS), 3)[0]);
		$this->assertFalse($this->gate($this->node(0, 0), -1)[0]);
	}

	public function testEveryRefusalHasItsString(): void {
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		foreach ([
			'cluster_mode_done', 'cluster_mode_unknown', 'cluster_mode_needs_config', 'cluster_mode_needs_flows', 'cluster_mode_needs_root',
			'cluster_mode_needs_active', 'cluster_mode_not_heard', 'cluster_mode_needs_streams', 'cluster_mode_redis_handler',
			'cluster_mode_up_tip', 'cluster_mode_down_help', 'cluster_mode_two_warning', 'cluster_mode_down_revoked_confirm', 'cluster_mode_moved',
			'cluster_strip_too_soon', 'cluster_strip_not_local',
		] as $rKey) {
			$this->assertStringContainsString("\n" . $rKey . ' = ', $rEn, $rKey);
		}
		// The move to mode 2 warns of what cannot be undone, in so many words.
		preg_match('/^cluster_mode_two_warning = "(.*)"$/m', $rEn, $rMatch);
		$this->assertStringContainsString('WARNING', $rMatch[1] ?? '');
		$this->assertStringContainsString('no way back', $rMatch[1] ?? '');
		// The seven days are no longer a reason a move is refused.
		foreach (['cluster_mode_no_audit', 'cluster_mode_still_connects', 'cluster_mode_too_soon'] as $rKey) {
			$this->assertStringNotContainsString("\n" . $rKey . ' = ', $rEn, $rKey);
		}
	}
}
