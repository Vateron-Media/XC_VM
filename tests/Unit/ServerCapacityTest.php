<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * The servers' load, where nothing set the global servers: the cluster API,
 * whose rtmp_auth places a load balancer's RTMP viewer (StreamRedirector)
 * and threw on array_keys(null) with the Redis connection handler on.
 */
final class ServerCapacityTest extends TestCase {
	public function testItReadsTheServersWhereNoGlobalOnesAreSet(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `lines_live` (`server_id` int, `proxy_id` int DEFAULT 0, `hls_end` int DEFAULT 0)');
		$rDb->exec('INSERT INTO `lines_live` (`server_id`) VALUES (2), (2)');
		DatabaseFactory::set($rDb);
		RedisManager::closeInstance();
		RedisManager::useConnector(static fn() => null);
		FileCache::setCache('servers', [2 => ['server_online' => 1, 'server_hardware' => '{}', 'network_guaranteed_speed' => 1000, 'watchdog' => ['bytes_sent' => 0], 'total_clients' => 10]]);
		$rWas = [$GLOBALS['rSettings'] ?? null, $GLOBALS['rServers'] ?? null];
		$GLOBALS['rSettings'] = ['redis_handler' => 0, 'split_by' => 'band'];
		unset($GLOBALS['rServers']);
		try {
			$rRows = ConnectionTracker::getCapacity();
		} finally {
			[$GLOBALS['rSettings'], $GLOBALS['rServers']] = $rWas;
			DatabaseFactory::reset();
			RedisManager::useConnector(null);
			FileCache::deleteCache('servers');
		}
		$this->assertSame(2, (int) $rRows[2]['online_clients']);
		$this->assertSame(0.0, $rRows[2]['capacity'], 'nothing sent yet, on its guaranteed speed');
	}
}
