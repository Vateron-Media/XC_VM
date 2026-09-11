<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessManager;

/**
 * Per-process CPU and memory readings — what the admin streams list shows for
 * each stream's producer. The readings come from /proc, so they are taken
 * against this test's own process.
 */
final class ProcessResourceUsageTest extends TestCase {

	public function testSamplesThisProcess(): void {
		$rSample = ProcessManager::resourceSample(getmypid());
		$this->assertIsArray($rSample);
		$this->assertArrayHasKey('ticks', $rSample);
		$this->assertGreaterThan(0, $rSample['rss'], 'a running process holds memory');
		$this->assertGreaterThan(0, $rSample['at']);
		// PHP itself is a few MB at least, and nothing here is a gigabyte: a
		// wrong page size or a misread field shows up as an absurd figure.
		$this->assertGreaterThan(1 << 20, $rSample['rss']);
		$this->assertLessThan(4 << 30, $rSample['rss']);
	}

	public function testAProcessThatDoesNotExistReadsNothing(): void {
		$rMax = (int) @file_get_contents('/proc/sys/kernel/pid_max');
		$this->assertNull(ProcessManager::resourceSample($rMax > 0 ? $rMax + 1 : 4194305));
		$this->assertNull(ProcessManager::resourceSample(0));
		$this->assertNull(ProcessManager::resourceSample(-1));
	}

	public function testCpuIsPercentOfOneCore(): void {
		// /proc counts CPU time in USER_HZ = 100, so 150 ticks is 1.5 s of CPU;
		// spent over 3 s of wall clock that is half a core.
		$this->assertSame(50.0, ProcessManager::cpuPercent(
			['ticks' => 150, 'at' => 103.0],
			['ticks' => 0, 'at' => 100.0]
		));
		// A transcode on several cores legitimately passes 100%.
		$this->assertSame(250.0, ProcessManager::cpuPercent(
			['ticks' => 500, 'at' => 102.0],
			['ticks' => 0, 'at' => 100.0]
		));
	}

	public function testAPairThatSaysNothingReportsNothing(): void {
		// Restarted producer: the pid is new, its counter starts over. Reporting
		// the difference would show a wild negative percentage.
		$this->assertNull(ProcessManager::cpuPercent(['ticks' => 5, 'at' => 200.0], ['ticks' => 900, 'at' => 100.0]));
		// Two readings from the same instant divide by zero.
		$this->assertNull(ProcessManager::cpuPercent(['ticks' => 10, 'at' => 100.0], ['ticks' => 5, 'at' => 100.0]));
		// No previous reading at all (the first pass after a start).
		$this->assertNull(ProcessManager::cpuPercent(['ticks' => 10, 'at' => 100.0], []));
	}

	public function testProducerKind(): void {
		$this->assertSame('php', ProcessManager::producerKind(getmypid()));
		$rMax = (int) @file_get_contents('/proc/sys/kernel/pid_max');
		$this->assertNull(ProcessManager::producerKind($rMax > 0 ? $rMax + 1 : 4194305));
	}
}
