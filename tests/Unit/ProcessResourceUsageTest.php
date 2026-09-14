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

	/** The first reading of a producer still shows a figure: its lifetime average. */
	public function testLifetimeAverageStandsInWithoutAPreviousReading(): void {
		$rSample = ProcessManager::resourceSample(getmypid());
		$this->assertArrayHasKey('start', $rSample);
		$this->assertGreaterThan(0, $rSample['start'], 'starttime, in ticks after boot');

		// 300 ticks (3 s of CPU) over a 6 s life is half a core. The age is read
		// against /proc/uptime, so place the start 6 s before now.
		$rUptime = (float) strtok((string) file_get_contents('/proc/uptime'), ' ');
		$rOld = ['ticks' => 300, 'start' => (int) round(($rUptime - 6) * 100)];
		$rCPU = ProcessManager::cpuPercentSinceStart($rOld);
		$this->assertGreaterThan(45.0, $rCPU);
		$this->assertLessThan(55.0, $rCPU);

		// Younger than a second: not enough life to average over.
		$this->assertNull(ProcessManager::cpuPercentSinceStart(['ticks' => 1, 'start' => (int) round($rUptime * 100)]));
		$this->assertNull(ProcessManager::cpuPercentSinceStart([]));
	}

	public function testProducerKind(): void {
		$this->assertSame('php', ProcessManager::producerKind(getmypid()));
		$rMax = (int) @file_get_contents('/proc/sys/kernel/pid_max');
		$this->assertNull(ProcessManager::producerKind($rMax > 0 ? $rMax + 1 : 4194305));
	}
}
