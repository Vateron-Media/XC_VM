<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Viewers who reach a stopped on-demand stream at the same moment must start
 * it once. Each simulated viewer below runs live.php's shape in its own
 * process: take the start lock, look for a monitor, and — finding none — spend
 * the monitor's start-up window starting one.
 */
class OnDemandStartLockTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', sys_get_temp_dir() . '/xcvm-ondemand-lock/');
		}
		$this->rDir = STREAMS_PATH;
		@mkdir($this->rDir, 0777, true);
		@unlink($this->rDir . '77_.started');
		@unlink($this->rDir . '77_.start');
	}

	private function viewer(): mixed {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'define("STREAMS_PATH", ' . var_export($this->rDir, true) . ');'
			. '$l = \XcVm\Domain\Stream\StreamProcess::lockOnDemandStart(77);'
			. '$m = STREAMS_PATH . "77_.started";'
			. 'if (!file_exists($m)) { usleep(300000); file_put_contents($m, getmypid() . "\n", FILE_APPEND); }'
			. '\XcVm\Domain\Stream\StreamProcess::unlockOnDemandStart($l);';

		return proc_open([PHP_BINARY, '-r', $rCode], [], $rPipes);
	}

	public function testViewersArrivingTogetherStartTheStreamOnce(): void {
		$rViewers = [];
		for ($i = 0; $i < 4; $i++) {
			$rViewers[] = $this->viewer();
		}
		foreach ($rViewers as $rProc) {
			proc_close($rProc);
		}

		$rStarts = array_filter(explode("\n", (string) @file_get_contents($this->rDir . '77_.started')));
		$this->assertCount(1, $rStarts, 'monitors started: ' . count($rStarts));
	}

	public function testUnlockToleratesNoLock(): void {
		StreamProcess::unlockOnDemandStart(null);
		$this->assertTrue(true);
	}
}
