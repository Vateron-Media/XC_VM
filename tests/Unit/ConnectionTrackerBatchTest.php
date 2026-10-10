<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Tests\Support\RedisServer;

/**
 * ConnectionTracker's batch readers, which share one walk each: the users'
 * and the servers' sorted sets (one MULTI pipeline, grouped by owner or
 * server), the per-stream counts over `streams_servers` and `lines_live`,
 * and the Redis reader's grouping by line identity.
 */
final class ConnectionTrackerBatchTest extends TestCase {
	private static ?string $rRedisDir = null;

	private static int $rRedisPort = 0;

	/** @var resource|null */
	private static $rRedisProc = null;

	private TestDb $rDb;

	public static function setUpBeforeClass(): void {
		if (!class_exists(\Redis::class) || !function_exists('igbinary_serialize') || trim((string) shell_exec('command -v redis-server')) === '') {
			return;
		}
		self::$rRedisDir = sys_get_temp_dir() . '/xcvm-redis-' . bin2hex(random_bytes(4));
		mkdir(self::$rRedisDir);
		[self::$rRedisProc, self::$rRedisPort] = RedisServer::start(self::$rRedisDir) ?? [null, 0];
	}

	public static function tearDownAfterClass(): void {
		if (self::$rRedisProc !== null) {
			proc_terminate(self::$rRedisProc);
			proc_close(self::$rRedisProc);
			self::$rRedisProc = null;
		}
		if (self::$rRedisDir !== null) {
			exec('rm -rf ' . escapeshellarg(self::$rRedisDir));
		}
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `hls_end` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`stream_id` int, `server_id` int, `parent_id` int, `pid` int, `monitor_pid` int)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		$this->redis(null);
	}

	private function redis(?\Redis $rRedis): void {
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
	}

	private function connectRedis(): \Redis {
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, phpredis or igbinary not available');
		}
		$rRedis = new \Redis();
		$rRedis->connect('127.0.0.1', self::$rRedisPort, 2.0, null, 0, 2.0);
		$rRedis->flushAll();
		$this->redis($rRedis);
		return $rRedis;
	}

	/** @param array<string, mixed> $rRecord */
	private function store(\Redis $rRedis, array $rRecord): void {
		$rIdentity = !empty($rRecord['user_id']) ? $rRecord['user_id'] : $rRecord['hmac_id'] . '_' . $rRecord['hmac_identifier'];
		$rRedis->set($rRecord['uuid'], igbinary_serialize($rRecord));
		$rRedis->zAdd('LIVE', $rRecord['date_start'], $rRecord['uuid']);
		$rRedis->zAdd('LINE#' . $rIdentity, $rRecord['date_start'], $rRecord['uuid']);
		$rRedis->zAdd('SERVER#' . $rRecord['server_id'], $rRecord['date_start'], $rRecord['uuid']);
		if (!empty($rRecord['proxy_id'])) {
			$rRedis->zAdd('PROXY#' . $rRecord['proxy_id'], $rRecord['date_start'], $rRecord['uuid']);
		}
	}

	private function fixture(): \Redis {
		$rRedis = $this->connectRedis();
		$this->store($rRedis, ['uuid' => 'u1', 'user_id' => 7, 'server_id' => 1, 'proxy_id' => 3, 'stream_id' => 100, 'container' => 'ts', 'date_start' => 10]);
		$this->store($rRedis, ['uuid' => 'u2', 'user_id' => 7, 'server_id' => 2, 'proxy_id' => 0, 'stream_id' => 100, 'container' => 'hls', 'date_start' => 20]);
		$this->store($rRedis, ['uuid' => 'u3', 'user_id' => 8, 'server_id' => 1, 'proxy_id' => 0, 'stream_id' => 101, 'container' => 'ts', 'date_start' => 30]);
		$this->store($rRedis, ['uuid' => 'h1', 'user_id' => null, 'hmac_id' => 4, 'hmac_identifier' => 'box', 'server_id' => 2, 'proxy_id' => 0, 'stream_id' => 101, 'container' => 'ts', 'date_start' => 40]);
		return $rRedis;
	}

	/** @param array<int|string, list<array<string, mixed>>> $rMap */
	private function uuids(array $rMap): array {
		$rOut = [];
		foreach ($rMap as $rKey => $rRows) {
			$rOut[$rKey] = array_column($rRows, 'uuid');
			sort($rOut[$rKey]);
		}
		ksort($rOut);
		return $rOut;
	}

	public function testUsersConnectionsCountsKeysAndRecordsByOwner(): void {
		$this->fixture();
		$this->assertSame([7 => 2, 8 => 1, 9 => 0], ConnectionTracker::getUserConnections([7, 8, 9], true));
		$rKeys = ConnectionTracker::getUserConnections([7, 8], false, true);
		sort($rKeys);
		$this->assertSame(['u1', 'u2', 'u3'], $rKeys);
		$this->assertSame([7 => ['u1', 'u2'], 8 => ['u3']], $this->uuids(ConnectionTracker::getUserConnections([7, 8])));
		$this->assertSame([], ConnectionTracker::getUserConnections([9]));
	}

	public function testServersConnectionsReadTheServerOrTheProxySets(): void {
		$this->fixture();
		$this->assertSame([1 => 2, 2 => 2], ConnectionTracker::getServerConnections([1, 2], false, true));
		$this->assertSame([3 => 1], ConnectionTracker::getServerConnections([3], true, true));
		$this->assertSame([1 => ['u1', 'u3'], 2 => ['h1', 'u2']], $this->uuids(ConnectionTracker::getServerConnections([1, 2])));
		$this->assertSame([1 => ['u1']], $this->uuids(ConnectionTracker::getServerConnections([3], true)), 'grouped by the record\'s server');
		$this->assertSame(['u1'], ConnectionTracker::getServerConnections([3], true, false, true));
	}

	public function testTheRedisReaderGroupsByLineIdentity(): void {
		$this->fixture();
		$this->assertSame(['4_box' => ['h1'], 7 => ['u1', 'u2'], 8 => ['u3']], $this->uuids(ConnectionTracker::getRedisConnections()));
		$this->assertSame([4, 3], ConnectionTracker::getRedisConnections(null, null, null, false, true));
	}

	public function testPerStreamCountsOverEachTable(): void {
		$this->rDb->exec('INSERT INTO `lines_live` (`stream_id`, `server_id`, `hls_end`) VALUES (100, 1, 0), (100, 1, 0), (100, 1, 1), (101, 1, 0), (100, 2, 0), (102, 1, 0)');
		$this->rDb->exec('INSERT INTO `streams_servers` VALUES (100, 5, 1, 10, 11), (100, 6, 1, 12, 13), (100, 7, 1, 0, 13), (101, 5, 1, 14, 0), (101, 6, 2, 15, 16), (102, 5, 1, 17, 18)');
		$this->assertSame([100 => 2, 101 => 1], ConnectionTracker::onlineClientCounts([100, 101], 1));
		$this->assertSame([100 => 2], ConnectionTracker::attachedRestreamCounts([100, 101], 1));
		$this->assertSame([], ConnectionTracker::onlineClientCounts([], 1));
		$this->assertSame([], ConnectionTracker::attachedRestreamCounts([], 1));
	}

	/**
	 * A request names a connection by its uuid (the Redis handler has no row
	 * ids) or by its row id. The admin API's "kill connection" made both a
	 * number, so under the Redis handler it named connection 0: none.
	 */
	public function testAConnectionIsNamedByItsUuidOrItsRowId(): void {
		$rUuid = md5('viewer');
		$this->assertSame($rUuid, ConnectionTracker::reference($rUuid));
		$this->assertSame('4711', ConnectionTracker::reference(4711));
		$this->assertSame('4711', ConnectionTracker::reference('4711'));
		foreach (['', null, [], 'LIVE', 'ENDED', "a\nb", '../x', str_repeat('a', 65)] as $rNone) {
			$this->assertSame('', ConnectionTracker::reference($rNone), var_export($rNone, true));
		}
	}
}
