<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;

final class EpgForceReloadTest extends TestCase {
	public function testTopbarReloadSendsTheRequestAndHandlesFailures(): void {
		[$rNodeExit] = ProcessRunner::capture(['node', '--version']);
		if ($rNodeExit !== 0) {
			$this->markTestSkipped('Node.js is required to exercise the EPG reload handler.');
		}
		[$rExit, $rOutput] = ProcessRunner::capture(['node', dirname(__DIR__) . '/Support/epg-force-reload.cjs']);
		$this->assertSame(0, $rExit, $rOutput);
	}
}
