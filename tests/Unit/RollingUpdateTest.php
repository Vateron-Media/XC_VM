<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\RollingUpdate;

/**
 * The rolling update of the load balancers (Domain\Cluster\RollingUpdate):
 * one at a time, each back online on MAIN's release and staying so before
 * the next one goes, a failure stopping the run. Run against a fleet of the
 * test's own on a clock of its own.
 */
final class RollingUpdateTest extends TestCase {
	private const NEW = '2.7.0';

	private int $rClock = 1000;

	/** @var array<int, int> server id => clock at its update */
	private array $rUpdated = [];

	/** @var array<int, array{back?: ?int, drops?: int}> seconds after its update: back on NEW, offline again */
	private array $rFleet = [];

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm-rolling-' . getmypid() . '/');
		}
		@mkdir(CACHE_TMP_PATH, 0777, true);
		@unlink(RollingUpdate::stateFile());
		@unlink(RollingUpdate::cancelFile());
	}

	protected function tearDown(): void {
		@unlink(RollingUpdate::stateFile());
		@unlink(RollingUpdate::cancelFile());
	}

	/** @return array<int, array<string, mixed>> the fleet now, as ServerRepository::getAll() has it */
	private function servers(): array {
		$rOut = [1 => ['id' => 1, 'is_main' => 1, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => self::NEW, 'server_name' => 'MAIN']];
		foreach ($this->rFleet as $rID => $rNode) {
			$rSince = isset($this->rUpdated[$rID]) ? $this->rClock - $this->rUpdated[$rID] : null;
			$rBack = $rSince !== null && ($rNode['back'] ?? null) !== null && $rSince >= $rNode['back'];
			$rDropped = $rBack && isset($rNode['drops']) && $rSince >= $rNode['drops'];
			$rOut[$rID] = ['id' => $rID, 'is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'server_name' => 'LB-' . $rID,
				'server_online' => $rSince === null || ($rBack && !$rDropped), 'xc_vm_version' => $rBack ? self::NEW : '2.6.1'];
		}
		return $rOut;
	}

	/** @return array<string, mixed> the run's last state */
	private function roll(?callable $rOnUpdate = null): array {
		return RollingUpdate::run(
			self::NEW,
			fn(): array => $this->servers(),
			function (int $rID) use ($rOnUpdate): bool {
				$this->rUpdated[$rID] = $this->rClock;
				if ($rOnUpdate !== null) {
					$rOnUpdate($rID);
				}
				return true;
			},
			function (int $rSeconds): void {
				$this->rClock += $rSeconds;
			},
			fn(): int => $this->rClock
		);
	}

	public function testTheLoadBalancersBehindMainAreUpdated(): void {
		$rServers = [
			1 => ['id' => 1, 'is_main' => 1, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => '2.6.1'],
			9 => ['id' => 9, 'is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => '2.6.1'],
			3 => ['id' => 3, 'is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => '2.6.1'],
			4 => ['id' => 4, 'is_main' => 0, 'server_type' => 1, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => '1.0.0'],
			5 => ['id' => 5, 'is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'server_online' => false, 'xc_vm_version' => '2.6.1'],
			6 => ['id' => 6, 'is_main' => 0, 'server_type' => 0, 'enabled' => 0, 'server_online' => false, 'xc_vm_version' => '2.6.1'],
			7 => ['id' => 7, 'is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'server_online' => true, 'xc_vm_version' => self::NEW],
		];
		$this->assertSame([3, 9], RollingUpdate::targets($rServers, self::NEW), 'not MAIN, a proxy, an offline or disabled one, one on it already');
	}

	public function testOneAtATimeEachBackAndWatchedBeforeTheNext(): void {
		$this->rFleet = [3 => ['back' => 300], 7 => ['back' => 120]];
		$rState = $this->roll();
		$this->assertSame('done', $rState['status']);
		$this->assertSame(['done', 'done'], array_column($rState['nodes'], 'state'));
		$this->assertSame([3, 7], array_keys($this->rUpdated));
		$this->assertGreaterThanOrEqual($this->rUpdated[3] + 300 + RollingUpdate::SOAK, $this->rUpdated[7], 'LB-7 after LB-3 was back and watched');
		$this->assertSame($rState, RollingUpdate::state(), 'what the page reads');
	}

	public function testOneThatDoesNotComeBackStopsTheRun(): void {
		$this->rFleet = [3 => ['back' => null], 7 => ['back' => 60]];
		$rState = $this->roll();
		$this->assertSame('failed', $rState['status']);
		$this->assertSame(['failed', 'waiting'], array_column($rState['nodes'], 'state'));
		$this->assertStringContainsString('not back online on ' . self::NEW, $rState['nodes'][0]['error']);
		$this->assertSame([3], array_keys($this->rUpdated), 'LB-7 is left as it was');
		$this->assertGreaterThanOrEqual($this->rUpdated[3] + RollingUpdate::UPDATE_TIMEOUT, $this->rClock);
	}

	public function testOneThatDropsWhileWatchedStopsTheRun(): void {
		$this->rFleet = [3 => ['back' => 60, 'drops' => 80], 7 => ['back' => 60]];
		$rState = $this->roll();
		$this->assertSame('failed', $rState['status']);
		$this->assertSame('went offline after its update', $rState['nodes'][0]['error']);
		$this->assertSame([3], array_keys($this->rUpdated));
	}

	public function testACancelStopsBeforeTheNext(): void {
		$this->rFleet = [3 => ['back' => 60], 7 => ['back' => 60]];
		$rState = $this->roll(static function (): void {
			RollingUpdate::cancel();
		});
		$this->assertSame('cancelled', $rState['status']);
		$this->assertSame([3], array_keys($this->rUpdated), 'no other load balancer is started');
		$this->assertFileDoesNotExist(RollingUpdate::cancelFile(), 'the next run starts clean');
	}

	public function testNothingToUpdateIsDone(): void {
		$rState = $this->roll();
		$this->assertSame(['done', []], [$rState['status'], $rState['nodes']]);
		$this->assertFalse(RollingUpdate::running($rState), 'over');
	}
}
