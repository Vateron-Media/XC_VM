<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * With the Redis connection handler on, a node in mode 2 opens no Redis of
 * MAIN's where a stream endpoint opens its store (ConnectionTracker::openStore):
 * its viewers are its agent's. Asked, the refusal would turn the viewer away
 * (ConnectAudit). MAIN opens it as before.
 */
final class ModeTwoRedisHandlerTest extends TestCase {
	private string $rFlows;

	private int $rConnects = 0;

	private mixed $rDb;

	protected function setUp(): void {
		// The database path (lazy, never opened here) replaces the global handle.
		$this->rDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = null;
		$this->rFlows = (string) tempnam(sys_get_temp_dir(), 'xcvm-flows-');
		file_put_contents($this->rFlows, json_encode(['mode' => 2, 'flows' => 255, 'state' => 'active']));
		NodeFlows::usePath($this->rFlows);
		RedisManager::closeInstance();
		RedisManager::useConnector(function (): ?\Redis {
			$this->rConnects++;
			return null;
		});
	}

	protected function tearDown(): void {
		RedisManager::useConnector(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		@unlink($this->rFlows);
		$GLOBALS['db'] = $this->rDb;
	}

	public function testANodeInModeTwoOpensNoRedisForItsViewers(): void {
		NodeRole::useMainBuild(false);
		ConnectionTracker::openStore(['redis_handler' => 1]);
		$this->assertSame(0, $this->rConnects);

		NodeRole::useMainBuild(true);
		ConnectionTracker::openStore(['redis_handler' => 1]);
		$this->assertSame(1, $this->rConnects, 'MAIN, even with a stray flows.json');
	}
}
