<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Phase 5 Task 2: a serve-only LB serves a loopback relay through its Go agent
 * (the puller), not a PHP LoopbackCommand — so PHP must not also feed it, or the
 * daemon gets double-fed. The node recognises itself as serve-only by the Part A
 * marker file `CONFIG_PATH/cluster/serve_only` (the same signal RootSignalsCronJob
 * reads), which `StreamProcess::nodeServeOnly()` reports.
 *
 * @covers \XcVm\Domain\Stream\StreamProcess::nodeServeOnly
 */
final class StreamProcessServeOnlyLoopbackTest extends TestCase {

	private string $rCfg;

	public static function setUpBeforeClass(): void {
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', sys_get_temp_dir() . '/xcvm-cfg-' . getmypid() . '/');
		}
	}

	protected function setUp(): void {
		$this->rCfg = CONFIG_PATH . 'cluster/';
		@mkdir($this->rCfg, 0o777, true);
		@unlink($this->rCfg . 'serve_only');
	}

	protected function tearDown(): void {
		@unlink($this->rCfg . 'serve_only');
	}

	public function testNodeServeOnlyFalseWithoutTheMarker(): void {
		$this->assertFalse(StreamProcess::nodeServeOnly(), 'no marker → a normal node');
	}

	public function testNodeServeOnlyTrueWithTheMarker(): void {
		file_put_contents($this->rCfg . 'serve_only', '2');
		$this->assertTrue(StreamProcess::nodeServeOnly(), 'the Part A marker present → serve-only');
	}
}
