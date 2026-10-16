<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;

final class DashboardAlertsPollingTest extends TestCase {
	public function testAlertUiRecoversAfterARequestTimeout(): void {
		[$rNodeExit] = ProcessRunner::capture(['node', '--version']);
		if ($rNodeExit !== 0) {
			$this->markTestSkipped('Node.js is required to exercise browser polling.');
		}
		[$rExit, $rOutput] = ProcessRunner::capture(['node', dirname(__DIR__) . '/Support/dashboard-alerts-polling.cjs']);
		$this->assertSame(0, $rExit, $rOutput);
	}
}
