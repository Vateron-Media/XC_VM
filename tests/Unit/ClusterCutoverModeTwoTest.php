<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCutover;

/**
 * The opt-in "continue to mode 2" path of the guided cutover: with every flow
 * already on and the node healthy at mode 1, run(..., toMode2: true) soaks once
 * more and takes the gated Mode up to mode 2. Without the opt-in it stops at
 * mode 1, and a refused mode-2 step leaves the node at mode 1 (done, not failed).
 */
final class ClusterCutoverModeTwoTest extends TestCase {

	private const NODE = 77;

	public static function setUpBeforeClass(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm_cutover_' . getmypid() . '/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
	}

	protected function setUp(): void {
		@unlink(ClusterCutover::stateFile(self::NODE));
		@unlink(ClusterCutover::cancelFile(self::NODE));
	}

	/** Every flow bit set, so the flow loop is a no-op and only the mode moves are exercised. */
	private static function allFlows(): int {
		return array_reduce(ClusterAdmin::FLOW_BITS, static fn(int $rC, int $rB): int => $rC | $rB, 0);
	}

	/** Drivers whose clock advances through $rSleep so the SOAK loop exits without real waiting. */
	private function drivers(int &$rMode, array &$rCalls): array {
		$rTime = 1000;
		return [
			'node' => function () use (&$rMode): array {
				return ['server_id' => self::NODE, 'state' => 'active', 'mode' => $rMode, 'flows' => self::allFlows(), 'health' => 'ok', 'relay' => 1];
			},
			'seed' => static fn(): ?string => null,
			'badges' => static fn(array $rNode): array => [],
			'sleep' => function (int $rSeconds) use (&$rTime): void {
				$rTime += $rSeconds;
			},
			'now' => function () use (&$rTime): int {
				return $rTime;
			},
			'calls' => &$rCalls,
		];
	}

	public function testOptInContinuesToModeTwo(): void {
		$rMode = 1;
		$rCalls = [];
		$d = $this->drivers($rMode, $rCalls);
		$rAct = function (string $rAction, array $rExtra) use (&$rMode, &$rCalls): array {
			$rCalls[] = [$rAction, $rExtra];
			if ($rAction === 'mode_up' && (string) ($rExtra['mode'] ?? '') === (string) $rMode) {
				$rMode++;
				return ['type' => 'success', 'message' => 'cluster_mode_done'];
			}
			return ['type' => 'success', 'message' => 'ok'];
		};
		$rState = ClusterCutover::run(self::NODE, $d['node'], $rAct, $d['seed'], $d['badges'], $d['sleep'], $d['now'], true);

		$this->assertSame('done', $rState['status']);
		$this->assertStringContainsString('Moved to mode 2.', $rState['note']);
		$this->assertSame(2, $rMode, 'the node ended at mode 2');
		$this->assertContains(['mode_up', ['mode' => '1']], $rCalls, 'the step to mode 2 confirmed the current mode (1)');
	}

	public function testWithoutOptInStopsAtModeOne(): void {
		$rMode = 1;
		$rCalls = [];
		$d = $this->drivers($rMode, $rCalls);
		$rAct = function (string $rAction) use (&$rMode): array {
			if ($rAction === 'mode_up') {
				$rMode++;
			}
			return ['type' => 'success', 'message' => 'ok'];
		};
		$rState = ClusterCutover::run(self::NODE, $d['node'], $rAct, $d['seed'], $d['badges'], $d['sleep'], $d['now'], false);

		$this->assertSame('done', $rState['status']);
		$this->assertSame(1, $rMode, 'stays at mode 1 without the opt-in');
		$this->assertStringNotContainsString('mode 2', $rState['note']);
	}

	public function testRefusedModeTwoLeavesModeOneDone(): void {
		$rMode = 1;
		$rCalls = [];
		$d = $this->drivers($rMode, $rCalls);
		$rAct = static fn(string $rAction): array => $rAction === 'mode_up'
			? ['type' => 'warning', 'message' => 'cluster_mode_need_all_flows']
			: ['type' => 'success', 'message' => 'ok'];
		$rState = ClusterCutover::run(self::NODE, $d['node'], $rAct, $d['seed'], $d['badges'], $d['sleep'], $d['now'], true);

		$this->assertSame('done', $rState['status'], 'a refused mode-2 step does not fail the cutover');
		$this->assertSame(1, $rMode, 'the node is left at mode 1');
		$this->assertStringContainsString('Not moved to mode 2', $rState['note']);
	}
}
