<?php

use XcVm\Core\Process\Multithread;
use PHPUnit\Framework\TestCase;

/**
 * Multithread::pool() runs every command, never more than the pool size at
 * once, and pulls commands from a generator only as slots free up. Each
 * command logs its start and end; the log shows the overlap.
 */
final class MultithreadPoolTest extends TestCase {

	private string $log;

	protected function setUp(): void {
		$this->log = tempnam(sys_get_temp_dir(), 'pool');
	}

	protected function tearDown(): void {
		@unlink($this->log);
	}

	private function command(int $rID): string {
		return 'echo s >> ' . escapeshellarg($this->log) . '; sleep 0.2; echo e' . $rID . ' >> ' . escapeshellarg($this->log);
	}

	/** Highest number of commands running at the same time, from the log. */
	private function maxConcurrent(): int {
		$rRunning = $rMax = 0;
		foreach (file($this->log, FILE_IGNORE_NEW_LINES) as $rLine) {
			$rRunning += ($rLine === 's') ? 1 : -1;
			$rMax = max($rMax, $rRunning);
		}
		return $rMax;
	}

	public function testRunsEveryCommandWithinThePoolSize(): void {
		$this->assertSame(6, Multithread::pool(array_map([$this, 'command'], range(1, 6)), 2, 10000));

		$this->assertCount(12, file($this->log, FILE_IGNORE_NEW_LINES), 'every command started and finished');
		$this->assertSame(2, $this->maxConcurrent());
	}

	public function testTakesCommandsFromAGeneratorOnlyAsSlotsFree(): void {
		$rTaken = 0;
		$rCommands = (function () use (&$rTaken) {
			foreach (range(1, 3) as $rID) {
				$rTaken++;
				yield $this->command($rID);
			}
		})();

		$this->assertSame(3, Multithread::pool($rCommands, 1, 10000));
		$this->assertSame(3, $rTaken);
		$this->assertSame(1, $this->maxConcurrent());
	}

	public function testANonPositiveSizeStillRunsOneAtATime(): void {
		Multithread::pool([$this->command(1), $this->command(2)], 0, 10000);

		$this->assertSame(1, $this->maxConcurrent());
	}
}
