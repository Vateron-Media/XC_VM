<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;

/**
 * Phase 5 Task 2: a serve-only LB serves a loopback relay through its Go agent
 * (the puller), not a PHP LoopbackCommand — so PHP must not also feed it, or the
 * daemon gets double-fed. The node recognises itself as serve-only by the Part A
 * marker file `CONFIG_PATH/cluster/serve_only` (the same signal RootSignalsCronJob
 * reads), which `StreamProcess::nodeServeOnly()` reports, and `startMonitor` then
 * hands a loopback stream to the agent/daemon (MONITOR_FANOUT) instead of spawning
 * a PHP monitor.
 *
 * @covers \XcVm\Domain\Stream\StreamProcess::nodeServeOnly
 * @covers \XcVm\Domain\Stream\StreamProcess::startMonitor
 */
final class StreamProcessServeOnlyLoopbackTest extends TestCase {

	private string $rCfg;

	public static function setUpBeforeClass(): void {
		foreach (['CONFIG_PATH' => sys_get_temp_dir() . '/xcvm-cfg-' . getmypid() . '/', 'SERVER_ID' => 1] as $k => $v) {
			if (!defined($k)) {
				define($k, $v);
			}
		}
	}

	protected function setUp(): void {
		$this->rCfg = CONFIG_PATH . 'cluster/';
		@mkdir($this->rCfg, 0o777, true);
		@unlink($this->rCfg . 'serve_only');
	}

	protected function tearDown(): void {
		@unlink($this->rCfg . 'serve_only');
		StreamSource::useLoader(null);
	}

	public function testNodeServeOnlyFalseWithoutTheMarker(): void {
		$this->assertFalse(StreamProcess::nodeServeOnly(), 'no marker → a normal node');
	}

	public function testNodeServeOnlyTrueWithTheMarker(): void {
		file_put_contents($this->rCfg . 'serve_only', '2');
		$this->assertTrue(StreamProcess::nodeServeOnly(), 'the Part A marker present → serve-only');
	}

	public function testStartMonitorHandsServeOnlyLoopbackToTheAgent(): void {
		// Serve-only node + a loopback stream (parent_id > 0): startMonitor returns
		// MONITOR_FANOUT before superviseStream / the PHP monitor spawn, so cron:streams
		// never PHP-feeds it. Delete the gate and this returns MONITOR_PHP instead.
		file_put_contents($this->rCfg . 'serve_only', '2');
		StreamSource::useLoader(fn(string $rWhat, int $rID, array $rOpts) => $rWhat === 'server' ? ['parent_id' => 3, 'server_id' => 1] : []);

		$this->assertSame(StreamProcess::MONITOR_FANOUT, StreamProcess::startMonitor(7));
	}
}
