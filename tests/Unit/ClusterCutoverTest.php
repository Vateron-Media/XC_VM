<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCutover;

/**
 * The guided cutover (Domain\Cluster\ClusterCutover): a load balancer's flows
 * switched on in the guide's order through the page's action, each watched
 * before the next, its viewers loaded into its agent before CONNECTIONS, a
 * flow after which the node is unwell switched off again, and never mode 2.
 * Run against a node of the test's own on a clock of its own.
 */
final class ClusterCutoverTest extends TestCase {
	private const SID = 5;

	private int $rClock = 1000;

	/** @var array<string, mixed> the node's row, as ClusterAdmin::nodes() has it */
	private array $rNode = [];

	/** @var list<string> what was done, in order: the page's actions and the seed */
	private array $rDone = [];

	/** @var array<string, array{type: string, message: string}> action => its answer, when not a success */
	private array $rRefuse = [];

	private ?string $rSeedError = null;

	/** The action after which the node shows a red badge, if any. */
	private ?string $rSickAfter = null;

	private bool $rSick = false;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm-cutover-' . getmypid() . '/');
		}
		@mkdir(CACHE_TMP_PATH, 0777, true);
		@unlink(ClusterCutover::stateFile(self::SID));
		@unlink(ClusterCutover::cancelFile(self::SID));
		$this->rNode = ['server_id' => self::SID, 'state' => 'active', 'health' => 'ok', 'mode' => 1, 'flows' => 0, 'relay' => true];
	}

	protected function tearDown(): void {
		@unlink(ClusterCutover::stateFile(self::SID));
		@unlink(ClusterCutover::cancelFile(self::SID));
	}

	/** @return array<string, mixed> the cutover's last state */
	private function cut(): array {
		return ClusterCutover::run(
			self::SID,
			fn(): ?array => $this->rNode,
			function (string $rAction, array $rExtra): array {
				$this->rDone[] = $rAction . ($rExtra === [] ? '' : ' ' . json_encode($rExtra));
				if (isset($this->rRefuse[$rAction])) {
					return $this->rRefuse[$rAction];
				}
				if (preg_match('/^([a-z]+)_(on|off)$/', $rAction, $rM)) {
					$rBit = ClusterAdmin::FLOW_BITS[$rM[1]];
					$this->rNode['flows'] = $rM[2] === 'on' ? $this->rNode['flows'] | $rBit : $this->rNode['flows'] & ~$rBit;
				} elseif ($rAction === 'mode_up') {
					$this->rNode['mode']++;
				}
				$this->rSick = $this->rSick || $rAction === $this->rSickAfter;
				return ['type' => 'success', 'message' => 'ok'];
			},
			function (): ?string {
				$this->rDone[] = 'seed';
				return $this->rSeedError;
			},
			fn(array $rNode): array => $this->rSick ? [['tone' => 'danger', 'key' => 'cluster_lane_lag', 'vars' => ['{LANE}' => 'P0']]] : [['tone' => 'warning', 'key' => 'cluster_conn_resynced', 'vars' => []]],
			function (int $rSeconds): void {
				$this->rClock += $rSeconds;
			},
			fn(): int => $this->rClock
		);
	}

	public function testEveryFlowInTheGuidesOrderEachWatchedUpToModeOne(): void {
		$rState = $this->cut();
		$this->assertSame('done', $rState['status']);
		$this->assertSame(['telemetry_on', 'commands_on', 'logs_on', 'streams_on', 'content_on', 'config_on', 'seed', 'connections_on', 'dataplane_on'], $this->rDone, 'the viewers loaded before CONNECTIONS; never mode 2');
		$this->assertSame(array_fill(0, 8, 'on'), array_column($rState['steps'], 'state'));
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->rNode['flows']);
		$this->assertGreaterThanOrEqual(1000 + 8 * ClusterCutover::SOAK, $this->rClock, 'each flow watched');
		$this->assertSame($rState, ClusterCutover::state(self::SID), 'what the page shows');
	}

	public function testFlowsOnAlreadyAreLeftAndModeZeroEndsInModeOne(): void {
		$this->rNode['mode'] = 0;
		$this->rNode['flows'] = ClusterAdmin::FLOW_BITS['telemetry'] | ClusterAdmin::FLOW_BITS['commands'];
		$rState = $this->cut();
		$this->assertSame('logs_on', $this->rDone[0]);
		$this->assertSame('mode_up {"mode":"0"}', end($this->rDone), 'once, from mode 0');
		$this->assertSame([1, 'Moved to mode 1.'], [$this->rNode['mode'], $rState['note']]);
	}

	public function testAFlowAfterWhichTheNodeIsUnwellIsSwitchedOffAndTheCutoverStops(): void {
		$this->rSickAfter = 'streams_on';
		$rState = $this->cut();
		$this->assertSame('failed', $rState['status']);
		$this->assertSame(['telemetry_on', 'commands_on', 'logs_on', 'streams_on', 'streams_off'], $this->rDone);
		$this->assertSame('failed', $rState['steps'][3]['state']);
		$this->assertStringStartsWith('Switched off again: it shows cluster_lane_lag (P0)', $rState['steps'][3]['note']);
		$this->assertSame('waiting', $rState['steps'][4]['state'], 'content is never tried');
	}

	public function testWithoutItsViewersInItsAgentConnectionsStaysOff(): void {
		$this->rSeedError = 'it did not answer within 5 minutes';
		$rState = $this->cut();
		$this->assertSame('failed', $rState['status']);
		$this->assertNotContains('connections_on', $this->rDone);
		$this->assertStringContainsString('it did not answer within 5 minutes', $rState['note']);
	}

	public function testWithoutTheRelayTheDataPlaneIsLeftOff(): void {
		$this->rNode['relay'] = false;
		$rState = $this->cut();
		$this->assertSame('done', $rState['status']);
		$this->assertNotContains('dataplane_on', $this->rDone);
		$this->assertSame('skipped', $rState['steps'][7]['state']);
	}

	public function testARefusedFlowStopsIt(): void {
		$this->rRefuse['config_on'] = ['type' => 'warning', 'message' => 'cluster_flow_needs'];
		$rState = $this->cut();
		$this->assertSame(['failed', 'Refused: cluster_flow_needs.'], [$rState['status'], $rState['note']]);
		$this->assertSame('config_on', end($this->rDone));
	}

	public function testANodeInModeTwoOrNotActiveIsLeftAlone(): void {
		$this->rNode['mode'] = 2;
		$this->assertSame('done', $this->cut()['status']);
		$this->rNode = ['mode' => 1, 'flows' => 0, 'state' => 'quarantined', 'health' => 'quarantined', 'relay' => true];
		$this->assertSame('failed', $this->cut()['status']);
		$this->assertSame([], $this->rDone);
	}

	public function testACancelStopsBeforeTheNextFlow(): void {
		ClusterCutover::cancel(self::SID);
		$rState = $this->cut();
		$this->assertSame(['cancelled', []], [$rState['status'], $this->rDone]);
		$this->assertFileDoesNotExist(ClusterCutover::cancelFile(self::SID));
	}

	public function testWhatMakesANodeUnfit(): void {
		$rOk = ['state' => 'active', 'health' => 'ok'];
		$this->assertNull(ClusterCutover::unhealthy($rOk, [['tone' => 'warning', 'key' => 'cluster_conn_resynced']]), 'a warning is not a stop');
		$this->assertSame('it is quarantined', ClusterCutover::unhealthy(['state' => 'quarantined'] + $rOk, []));
		$this->assertSame('MAIN does not hear it (suspect)', ClusterCutover::unhealthy(['health' => 'suspect'] + $rOk, []));
		$this->assertSame('it shows cluster_clock_degraded (+90s)', ClusterCutover::unhealthy($rOk, [['tone' => 'danger', 'key' => 'cluster_clock_degraded', 'vars' => ['{OFFSET}' => '+90s']]]));
		$this->assertSame('it is no longer enrolled', ClusterCutover::unhealthy(null, []));
	}
}
