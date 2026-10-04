<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Phase 6, the P2 lane and `conn.touch` (plan, sections 7 and 8): when a
 * node's viewers last asked for their playlist, the newest value per viewer
 * by the event's time, with no cursor and never a gap. A node whose agent
 * ends its own idle HLS viewers keeps it on the cluster bus only; without
 * the bus, or for a node that does not reap, it goes into MAIN's store.
 */
final class ConnectionTouchTest extends TestCase {
	private const FLOWS = NodeRegistry::FLOW_CONNECTIONS | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS;

	private const T = 1800000000000;

	private static ?string $rRedisDir = null;

	private static int $rRedisPort = 0;

	/** @var resource|null */
	private static $rRedisProc = null;

	private TestDb $rDb;

	public static function setUpBeforeClass(): void {
		if (!class_exists(\Redis::class) || !function_exists('igbinary_serialize') || trim((string) shell_exec('command -v redis-server')) === '') {
			return;
		}
		self::$rRedisDir = sys_get_temp_dir() . '/xcvm-touch-' . bin2hex(random_bytes(4));
		mkdir(self::$rRedisDir);
		self::$rRedisPort = random_int(20000, 40000);
		// One server: MAIN's store on TCP, the cluster bus on its unix socket.
		$rNull = ['file', '/dev/null', 'w'];
		self::$rRedisProc = proc_open(['redis-server', '--port', (string) self::$rRedisPort, '--bind', '127.0.0.1', '--unixsocket', self::$rRedisDir . '/cluster.sock', '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', self::$rRedisDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 50 && (!@fsockopen('127.0.0.1', self::$rRedisPort) || !file_exists(self::$rRedisDir . '/cluster.sock')); $i++) {
			usleep(50000);
		}
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
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) $rM[0]);
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(self::T);
		NodeRegistry::startEnrolment(5, '11111111-1111-4111-a111-111111111111', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'mode' => 1, 'flows' => self::FLOWS]);
		SettingsManager::set(['redis_handler' => 0]);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock');
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		ClusterClock::fix(null);
		ClusterBus::useSocket(null);
		DatabaseFactory::reset();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
	}

	private function node(): array {
		return NodeRegistry::byServer(5);
	}

	private function touch(string $rUUID, int $rT, mixed $rLastRead): array {
		return ['type' => 'conn.touch', 't' => $rT, 'd' => ['uuid' => $rUUID, 'hls_last_read' => $rLastRead]];
	}

	private function live(string $rUUID, int $rServerID, int $rLastRead, int $rEnded = 0): void {
		$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `stream_id`, `container`, `hls_last_read`, `hls_end`) VALUES (?, ?, 7, 100, ?, ?, ?)', $rUUID, $rServerID, 'hls', $rLastRead, $rEnded);
	}

	/** @return array{0: int, 1: int} hls_last_read, hls_end of a lines_live row */
	private function row(string $rUUID): array {
		$this->rDb->query('SELECT `hls_last_read`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rUUID);
		$rRow = $this->rDb->get_row();
		return [(int) $rRow['hls_last_read'], (int) $rRow['hls_end']];
	}

	private function bus(): \Redis {
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, phpredis or igbinary not available');
		}
		ClusterBus::useSocket(self::$rRedisDir . '/cluster.sock');
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	private function store(): \Redis {
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, phpredis or igbinary not available');
		}
		$rRedis = new \Redis();
		$rRedis->connect('127.0.0.1', self::$rRedisPort, 2.0, null, 0, 2.0);
		$rRedis->flushAll();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
		SettingsManager::set(['redis_handler' => 1]);
		return $rRedis;
	}

	// ── The lane ─────────────────────────────────────────────────────────

	public function testP2HasNoCursorAndNeverAGap(): void {
		$this->live('aaaa', 5, 1000);
		foreach ([0, 1, 999, 1] as $i => $rFirst) {
			$rOut = EventIngest::ingest($this->node(), 'p2', $rFirst, [$this->touch('aaaa', self::T + $i, 1001 + $i)]);
			$this->assertSame(['ok' => true, 'useq' => 0, 'applied' => 1, 'dropped' => 0], $rOut, 'whatever number it comes with');
		}
		$this->assertSame([1004, 0], $this->row('aaaa'));
		$this->assertSame([0, 0], [(int) $this->node()['useq_p0'], (int) $this->node()['useq_p1']], 'no cursor moves');
		$this->assertSame(['ok' => true, 'useq' => 0, 'applied' => 0, 'dropped' => 0], EventIngest::ingest($this->node(), 'p2', 0, []));
	}

	public function testWithinABatchTheLatestTouchPerViewerWins(): void {
		$this->live('aaaa', 5, 100);
		$this->live('bbbb', 5, 100);
		$rOut = EventIngest::ingest($this->node(), 'p2', 0, [
			$this->touch('aaaa', self::T + 2000, 150),
			$this->touch('aaaa', self::T + 1000, 900), // an older event, sent later
			$this->touch('aaaa', self::T + 2000, 160), // the same time: the later one
			$this->touch('bbbb', self::T, 120),
		]);
		$this->assertSame([4, 0], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame([160, 0], $this->row('aaaa'), 'by the event\'s time, not by the value');
		$this->assertSame([120, 0], $this->row('bbbb'));
	}

	public function testMalformedAndMisplacedTouchesAreDropped(): void {
		$this->live('aaaa', 5, 100);
		$rOut = EventIngest::ingest($this->node(), 'p2', 0, [
			['type' => 'conn.touch', 'd' => ['uuid' => 'aaaa', 'hls_last_read' => 150]],           // no time
			['type' => 'conn.touch', 't' => '1', 'd' => ['uuid' => 'aaaa', 'hls_last_read' => 150]], // a string time
			$this->touch('bad uuid;', self::T, 150),
			$this->touch('aaaa', self::T, '150'),
			$this->touch('aaaa', self::T, -1),
			['type' => 'conn.upsert', 't' => self::T, 'd' => ['record' => ['uuid' => 'aaaa']]], // a P0 type
			['type' => 'stream.progress', 't' => self::T, 'd' => []],
			'nope',
		]);
		$this->assertSame([0, 8], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p1', 1, [$this->touch('aaaa', self::T, 150)])['applied'], 'P2 only');
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p0', 1, [$this->touch('aaaa', self::T, 150)])['applied']);
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS]);
		$this->assertSame(1, EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)])['dropped'], 'CONNECTIONS off');
		$this->assertSame([100, 0], $this->row('aaaa'));
	}

	// ── Without the bus: MAIN's store ────────────────────────────────────

	public function testWithoutTheBusTheNodesOwnRowsAreTouchedInTheTable(): void {
		$this->live('aaaa', 5, 100);
		$this->live('cccc', 6, 100);    // node 6's
		$this->live('eeee', 5, 100, 1); // ended
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150), $this->touch('cccc', self::T, 150), $this->touch('eeee', self::T, 150), $this->touch('zzzz', self::T, 150)]);
		$this->assertSame([150, 0], $this->row('aaaa'));
		$this->assertSame([100, 0], $this->row('cccc'), 'another node\'s viewer');
		$this->assertSame([150, 1], $this->row('eeee'), 'a touch never re-opens');
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `uuid` = ?', 'zzzz');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'nor creates');

		// The store keeps the latest read: a smaller value never goes back.
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T + 5000, 140)]);
		$this->assertSame([150, 0], $this->row('aaaa'));
	}

	public function testWithoutTheBusOnRedisTheNodesOwnRecordsAreTouched(): void {
		$rRedis = $this->store();
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'date_start' => 1800000000, 'hls_last_read' => 100, 'hls_end' => 0];
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'aaaa'] + $rRec));
		$this->assertTrue(ConnectionIngest::upsert(6, ['uuid' => 'cccc'] + $rRec));
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'eeee', 'hls_end' => 1] + $rRec));
		$rEndedLive = $rRedis->zScore('LIVE', 'eeee');

		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150), $this->touch('cccc', self::T, 150), $this->touch('eeee', self::T, 150), $this->touch('zzzz', self::T, 150)]);
		$rGet = static fn(string $rUUID): array => igbinary_unserialize($rRedis->get($rUUID));
		$this->assertSame([150, 0], [$rGet('aaaa')['hls_last_read'], $rGet('aaaa')['hls_end']]);
		$this->assertNotFalse($rRedis->zScore('LIVE', 'aaaa'));
		$this->assertSame(100, $rGet('cccc')['hls_last_read'], 'another node\'s viewer');
		$this->assertSame([150, 1], [$rGet('eeee')['hls_last_read'], $rGet('eeee')['hls_end']], 'a touch never re-opens');
		$this->assertSame($rEndedLive, $rRedis->zScore('LIVE', 'eeee'), 'nor moves it between sets');
		$this->assertFalse($rRedis->get('zzzz'), 'nor creates');

		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T + 5000, 140)]);
		$this->assertSame(150, $rGet('aaaa')['hls_last_read'], 'never back');
	}

	// ── The bus ──────────────────────────────────────────────────────────

	public function testAReapingNodesTouchesStayOnTheBus(): void {
		$rBus = $this->bus();
		NodeRegistry::update(5, ['features' => 'fanout_events,hls_reaper']);
		$this->live('aaaa', 5, 100);
		$this->assertSame(1, EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T + 2000, 150)])['applied']);
		$this->assertSame([100, 0], $this->row('aaaa'), 'MAIN\'s store is not written');
		$this->assertSame(['aaaa' => 150], ClusterBus::lastReads(5, ['aaaa', 'none']));
		$rTtl = $rBus->pttl('touch:5:aaaa');
		$this->assertGreaterThan(0, $rTtl);
		$this->assertLessThanOrEqual(ClusterBus::TOUCH_TTL_MS, $rTtl);
		$this->assertSame([], ClusterBus::lastReads(6, ['aaaa']), 'another node\'s key');

		// Across batches too, the latest by time wins: a late older event
		// changes nothing, a newer one replaces even a larger value.
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T + 1000, 999)]);
		$this->assertSame(['aaaa' => 150], ClusterBus::lastReads(5, ['aaaa']));
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T + 3000, 140)]);
		$this->assertSame(['aaaa' => 140], ClusterBus::lastReads(5, ['aaaa']));
	}

	public function testANodeThatDoesNotReapStillTouchesTheStore(): void {
		$this->bus();
		$this->live('aaaa', 5, 100);
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
		$this->assertSame([150, 0], $this->row('aaaa'), 'MAIN\'s 30 s rule reads it');
		$this->assertSame([], ClusterBus::lastReads(5, ['aaaa']));
	}

	public function testWithTheBusDownAReapingNodeTouchesTheStore(): void {
		NodeRegistry::update(5, ['features' => 'hls_reaper']);
		$this->live('aaaa', 5, 100);
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
		$this->assertSame([150, 0], $this->row('aaaa'));
		$this->assertNull(ClusterBus::lastReads(5, ['aaaa']), 'no bus');
	}

	public function testOnlyTheViewersTheStoreHoldsForTheNodeReachTheBus(): void {
		$rBus = $this->bus();
		NodeRegistry::update(5, ['features' => 'hls_reaper']);
		$this->live('aaaa', 5, 100);
		$this->live('cccc', 6, 100); // node 6's
		$rOut = EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150), $this->touch('cccc', self::T, 150), $this->touch('zzzz', self::T, 150)]);
		$this->assertSame([3, 0], [$rOut['applied'], $rOut['dropped']], 'taken, as by the store');
		$this->assertSame(['aaaa' => 150], ClusterBus::lastReads(5, ['aaaa', 'cccc', 'zzzz']), 'no key for another node\'s viewer, nor for a made-up one');
		$this->assertSame(['touch:5:aaaa'], $rBus->keys('touch:*'));
		$this->assertSame([100, 0], $this->row('cccc'));
	}

	public function testAModeZeroNodeDoesNotReapSoItsTouchesGoToTheStore(): void {
		$this->bus();
		NodeRegistry::update(5, ['mode' => 0, 'features' => 'hls_reaper']);
		$this->live('aaaa', 5, 100);
		EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
		$this->assertSame([150, 0], $this->row('aaaa'));
		$this->assertSame([], ClusterBus::lastReads(5, ['aaaa']));
	}

	public function testPastItsShareOfTheBussMemoryTouchesGoToTheStore(): void {
		$rBus = $this->bus();
		NodeRegistry::update(5, ['features' => 'hls_reaper']);
		$this->live('aaaa', 5, 100);
		$rUsed = (int) $rBus->info('memory')['used_memory'];
		$rBus->config('SET', 'maxmemory', (string) intdiv($rUsed * 3, 2));
		try {
			EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
		} finally {
			$rBus->config('SET', 'maxmemory', '0');
		}
		$this->assertSame([150, 0], $this->row('aaaa'), 'the reservations and wake-ups keep the bus');
		$this->assertSame([], ClusterBus::lastReads(5, ['aaaa']));
	}

	public function testAStoreThatCannotBeReadFailsTheBatchForTheNodeToResend(): void {
		$this->bus();
		SettingsManager::set(['redis_handler' => 1]);
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, new \Redis()); // not connected
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
		foreach (['a node that does not reap' => null, 'a reaping node' => 'hls_reaper'] as $rCase => $rFeatures) {
			NodeRegistry::update(5, ['features' => $rFeatures]);
			try {
				EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
				$this->fail($rCase . ': the batch must fail (503 DB), not be answered as applied');
			} catch (\Throwable $rE) {
				$this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $rE, $rCase);
			}
		}
	}

	public function testWithoutRedisTheTouchesAreNotAnsweredAsApplied(): void {
		SettingsManager::set(['redis_handler' => 1]);
		RedisManager::closeInstance();
		RedisManager::useConnector(static fn() => null); // Redis unreachable
		$this->expectExceptionMessage('redis unavailable');
		try {
			EventIngest::ingest($this->node(), 'p2', 0, [$this->touch('aaaa', self::T, 150)]);
		} finally {
			RedisManager::useConnector(null);
		}
	}

	public function testAnOlderAgentsTouchAsAP0UpsertStillLands(): void {
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'uuid' => 'aaaa', 'date_start' => 1800000000, 'hls_last_read' => 100, 'hls_end' => 0];
		EventIngest::ingest($this->node(), 'p0', 1, [['type' => 'conn.upsert', 'd' => ['record' => $rRec]]]);
		EventIngest::ingest($this->node(), 'p0', 2, [['type' => 'conn.upsert', 'd' => ['record' => ['hls_last_read' => 110] + $rRec]]]);
		$this->assertSame([110, 0], $this->row('aaaa'));
	}
}
