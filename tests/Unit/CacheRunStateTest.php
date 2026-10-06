<?php

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Cache\CacheRunState;

/**
 * The cache engine's scheduled runs leave a trail the dashboard reads: a run
 * that starts while the last one never finished, or ended failed, marks the
 * cache stalled until a run finishes cleanly.
 */
final class CacheRunStateTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-cache-run-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function state(): array {
		return CacheRunState::state($this->rDir);
	}

	public function testCleanRunsAreNeverStalled(): void {
		foreach ([1, 2, 3] as $rRun) {
			CacheRunState::started($this->rDir);
			$this->assertSame(['failed' => false, 'stalled' => false], $this->state(), 'run ' . $rRun . ' going');
			CacheRunState::finished($this->rDir, false);
		}
		$this->assertSame([], array_values(array_diff(scandir($this->rDir), ['.', '..'])), 'no trail left');
	}

	public function testAFailedRunStallsTheCacheUntilACleanOne(): void {
		CacheRunState::started($this->rDir);
		touch($this->rDir . CacheRunState::FAILED); // a worker
		CacheRunState::finished($this->rDir, true);
		$this->assertSame(['failed' => true, 'stalled' => false], $this->state());

		CacheRunState::started($this->rDir);
		$this->assertSame(['failed' => false, 'stalled' => true], $this->state());
		CacheRunState::finished($this->rDir, false);
		$this->assertSame(['failed' => false, 'stalled' => false], $this->state());
	}

	public function testARunThatNeverFinishedStallsTheNext(): void {
		CacheRunState::started($this->rDir);
		CacheRunState::started($this->rDir); // the first was killed by the next
		$this->assertTrue($this->state()['stalled']);
		CacheRunState::finished($this->rDir, false);
		$this->assertFalse($this->state()['stalled']);
	}

	public function testTurningTheCacheOffClearsTheTrail(): void {
		CacheRunState::started($this->rDir);
		CacheRunState::started($this->rDir);
		touch($this->rDir . CacheRunState::FAILED);
		CacheRunState::clear($this->rDir);
		$this->assertSame(['failed' => false, 'stalled' => false], $this->state());
	}
}
