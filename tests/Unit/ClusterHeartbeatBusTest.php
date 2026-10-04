<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\HeartbeatService;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Heartbeats on the cluster bus (plan, section 8: "Every 5 s the health loop
 * copies heartbeats from cl:tel:<sid> into servers…"; "Heartbeats … hold no
 * DB connection"). While the bus runs and a flusher is running, a heartbeat
 * leaves last_seen_at, the clock offset, root_ready and its telemetry on the
 * bus and asks MySQL nothing; the flusher (LivenessService::tick, every
 * second) copies them into cluster_nodes at most every 5 s per node and into
 * servers / servers_stats by the direct path's own rules. Liveness judges
 * the freshest of the bus and MySQL. Without the bus, or without a flusher,
 * each heartbeat writes MySQL as before.
 */
final class ClusterHeartbeatBusTest extends TestCase {
	private int $rT0 = 1800000000000;

	/** A sample as clusteragent.Sampler produces it. */
	private const SAMPLE = [
		'v' => 1, 'cpu' => 37.5, 'cpu_cores' => 4, 'cpu_name' => 'Test CPU', 'load' => [2.0, 1.0, 0.5],
		'mem_total_kb' => 8000000, 'mem_avail_kb' => 6000000, 'disk_total' => 100000000000, 'disk_free' => 40000000000,
		'kernel' => '6.1.0', 'uptime_s' => 90061, 'stream_producers' => 3, 'php_pids' => [104, 105], 'interfaces' => ['eth0'],
		'net' => ['eth0' => ['in_bytes' => 1000, 'in_packets' => 10, 'in_errors' => 1, 'out_bytes' => 2000, 'out_packets' => 20, 'out_errors' => 0, 'rx_total' => 3000, 'tx_total' => 6000, 'speed' => 1000]],
		'local' => ['requests_per_second' => 42, 'fanout' => ['running' => true], 'gpu_info' => [], 'iostat_info' => ['avg-cpu' => ['iowait' => 7.4]]],
	];

	private static ?BusServer $rBus = null;

	private TestDb $rDb;

	/** @var list<string> */
	private array $rTemp = [];

	private string $rNoBus;

	private string $rHealth;

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
	}

	protected function setUp(): void {
		$this->rDb = $this->newDb();
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0, 'total_users' => 9]);
		ClusterClock::fix($this->rT0);
		HeartbeatService::useDir($this->temp() . '/');
		$this->rNoBus = sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock';
		ClusterBus::useSocket($this->rNoBus);
		$this->rHealth = $this->temp() . '/health.json';
		ClusterHealth::usePath($this->rHealth);
		// What an earlier test's liveness passes read from the bus.
		(new \ReflectionProperty(LivenessService::class, 'rBusHeard'))->setValue(null, null);
		// No probe of a real cluster_ctl socket under MAIN_HOME: the pool cannot tell.
		LivenessService::useQueueReader(static fn(): ?int => null);
	}

	protected function tearDown(): void {
		LivenessService::useQueueReader(null);
		HeartbeatService::useDir(null);
		ClusterBus::useSocket(null);
		ClusterHealth::usePath(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		foreach ($this->rTemp as $rDir) {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	/** A database with the node tables, servers 5–7 and node 5 active with TELEMETRY (6 and 7 enrolling). */
	private function newDb(): TestDb {
		$rDb = new TestDb();
		$rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		// Every column the later migrations add (root_ready, features, audit,
		// main_port, arch, …), so the fixture keeps up with the real table.
		foreach (glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: [] as $rFile) {
			preg_match_all('/^ALTER TABLE `cluster_nodes` ADD COLUMN (?:IF NOT EXISTS )?(`\w+` [^;]*?)(?: AFTER `\w+`)?;$/m', (string) file_get_contents($rFile), $rAdd);
			foreach ($rAdd[1] as $rColumn) {
				$rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN ' . preg_replace('/ unsigned| COLLATE \w+/', '', $rColumn));
			}
		}
		$rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_name` varchar(64), `status` int NOT NULL DEFAULT 0, `watchdog_data` text, `last_check_ago` int DEFAULT 0, `requests_per_second` int DEFAULT 0, `php_pids` text, `connections` int DEFAULT 0, `users` int DEFAULT 0, `network_interface` varchar(32) DEFAULT NULL)');
		$rDb->exec("INSERT INTO `servers` (`id`, `status`, `watchdog_data`, `network_interface`) VALUES (5, 0, '{\"cpu_average_array\":[10,20]}', 'auto'), (6, 0, NULL, 'auto'), (7, 0, NULL, 'auto')");
		$rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_id` int, `server_id` int, `hls_end` int DEFAULT 0)');
		$rDb->exec('INSERT INTO `lines_live` (`user_id`, `server_id`, `hls_end`) VALUES (1, 5, 0), (1, 5, 0), (2, 5, 0), (3, 5, 1)');
		$rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int)');
		$rDb->exec('CREATE TABLE `streams_servers` (`stream_id` int, `server_id` int, `pid` int)');
		$rDb->exec('INSERT INTO `streams` (`id`, `type`) VALUES (1, 1)');
		$rDb->exec('INSERT INTO `streams_servers` VALUES (1, 5, 10)');
		$rDb->exec('CREATE TABLE `servers_stats` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `connections` int, `total_users` int, `users` int, `streams` int, `cpu` float, `cpu_cores` int, `cpu_avg` float, `total_mem` int, `total_mem_free` int, `total_mem_used` int, `total_mem_used_percent` float, `total_disk_space` bigint, `uptime` varchar(255), `total_running_streams` int, `bytes_sent` bigint, `bytes_received` bigint, `bytes_sent_total` bigint, `bytes_received_total` bigint, `cpu_load_average` float, `gpu_info` text, `iostat_info` text, `time` int)');
		$rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$rWas = DatabaseFactory::get();
		DatabaseFactory::set($rDb);
		ClusterMeta::set('ready_at', (string) ($this->rT0 - 600000));
		foreach ([5, 6, 7] as $rID) {
			NodeRegistry::startEnrolment($rID, sprintf('00000000-0000-4000-a000-%012d', $rID), str_repeat("\1", 32), str_repeat("\2", 32), 1);
		}
		NodeRegistry::update(5, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY]);
		if ($rWas !== null) {
			DatabaseFactory::set($rWas);
		}
		return $rDb;
	}

	/** A directory in the system temp directory, removed after the test. */
	private function temp(): string {
		$rDir = sys_get_temp_dir() . '/xcvm_hb_' . bin2hex(random_bytes(4));
		mkdir($rDir);
		$this->rTemp[] = $rDir;
		return $rDir;
	}

	/** An empty bus (a real redis-server on a unix socket), started once. */
	private function bus(): \Redis {
		self::$rBus ??= BusServer::start('hb-bus');
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		self::$rBus->restart();
		ClusterBus::useSocket(self::$rBus->socket());
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		return $rRedis;
	}

	private function beat(int $rMs, array $rPayload = [], int $rServerID = 5): void {
		ClusterClock::fix($rMs);
		HeartbeatService::record(NodeRegistry::byServer($rServerID), $rPayload + ['root_ready' => false, 'telemetry' => self::SAMPLE], $rMs + 250);
	}

	private function node(int $rServerID = 5, ?TestDb $rDb = null): array {
		$rDb ??= $this->rDb;
		$rDb->query('SELECT `last_seen_at`, `clock_offset_ms`, `root_ready`, `updated_at` FROM `cluster_nodes` WHERE `server_id` = ?', $rServerID);
		return $rDb->get_row();
	}

	private function server(TestDb $rDb, int $rServerID = 5): array {
		$rDb->query('SELECT `status`, `watchdog_data`, `last_check_ago`, `requests_per_second`, `php_pids`, `connections`, `users` FROM `servers` WHERE `id` = ?', $rServerID);
		return $rDb->get_row();
	}

	private function stats(TestDb $rDb): array {
		$rDb->query('SELECT * FROM `servers_stats` ORDER BY `id`');
		return array_map(static function (array $rRow): array {
			unset($rRow['id']);
			return $rRow;
		}, $rDb->get_rows());
	}

	/** Make the bus believe no flusher has run for a minute. */
	private function flusherGone(\Redis $rRedis): void {
		$rRedis->set(HeartbeatService::KEY_FLUSHER, (string) (BusServer::nowMs($rRedis) - 60000));
	}

	/** Make the bus believe a flusher finished a clean pass just now, however long the test has run. */
	private function flusherFresh(\Redis $rRedis): void {
		$rRedis->set(HeartbeatService::KEY_FLUSHER, (string) BusServer::nowMs($rRedis));
	}

	/** A node's `cl:hb` value: heard, offset, root_ready, telemetry heard, authoritative, gen. */
	private function busValue(\Redis $rRedis, int $rServerID = 5): array {
		return explode(':', (string) $rRedis->hGet(HeartbeatService::KEY_BEATS, (string) $rServerID));
	}

	/**
	 * The end of a flush pass, run as the flusher runs it (a pass that read
	 * the bus before a heartbeat came, or one whose lock another holds).
	 */
	private function flushEnd(string $rToken, bool $rClean, array $rForget = []): void {
		$rKeys = [HeartbeatService::KEY_FLUSHED, HeartbeatService::KEY_LOCK, HeartbeatService::KEY_BEATS, HeartbeatService::KEY_FLUSHER];
		$rArgs = [$rToken, $rClean ? 1 : 0, 0];
		foreach ($rForget as $rID => $rRaw) {
			$rKeys[] = HeartbeatService::TEL_PREFIX . $rID;
			array_push($rArgs, $rID, $rRaw);
		}
		$this->assertSame(1, ClusterBus::script((string) (new \ReflectionClassConstant(HeartbeatService::class, 'FLUSH_END_LUA'))->getValue(), $rKeys, $rArgs));
	}

	public function testWithoutTheBusEachHeartbeatWritesMySqlAsBefore(): void {
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$this->beat($this->rT0);
		$this->assertNotSame([], $rLog->writes());
		$this->assertSame([$this->rT0, 250], [(int) $this->node()['last_seen_at'], (int) $this->node()['clock_offset_ms']]);
		$this->assertSame(1, (int) $this->server($this->rDb)['status']);
		$this->assertSame([], HeartbeatService::flush(), 'nothing on a bus that is not there');
		$this->assertSame([], HeartbeatService::lastSeen());
	}

	public function testOnTheBusAHeartbeatAsksMySqlNothing(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush(); // a flusher is running
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$rNode = NodeRegistry::byServer(5);
		$rLog->rQueries = [];
		ClusterClock::fix($this->rT0 + 1000);
		HeartbeatService::record($rNode, ['root_ready' => true, 'telemetry' => self::SAMPLE], $this->rT0 + 1250);
		$this->assertSame([], $rLog->rQueries, 'not one query');
		$this->assertNull($this->node()['last_seen_at'], 'MySQL untouched until the flush');
		$this->assertSame([5 => $this->rT0 + 1000], HeartbeatService::lastSeen());
		$this->assertSame(1, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5'), 'the telemetry document is on the bus');
		$this->assertGreaterThan(0, $rRedis->pTtl(HeartbeatService::TEL_PREFIX . '5'), 'and goes when the node does');

		$this->assertSame([5 => $this->rT0 + 1000], HeartbeatService::flush());
		$rRow = $this->node();
		$this->assertSame([$this->rT0 + 1000, 250, 1, intdiv($this->rT0 + 1000, 1000)], [(int) $rRow['last_seen_at'], (int) $rRow['clock_offset_ms'], (int) $rRow['root_ready'], (int) $rRow['updated_at']]);
		$rServer = $this->server($this->rDb);
		$this->assertSame(1, (int) $rServer['status'], 'the first heartbeat marks the server up');
		$this->assertSame(intdiv($this->rT0 + 1000, 1000), (int) $rServer['last_check_ago']);
		$this->assertSame(42, (int) $rServer['requests_per_second']);
		$this->assertCount(1, $this->stats($this->rDb));
	}

	public function testTheFlushWritesWhatEachHeartbeatWroteBefore(): void {
		$this->bus();
		$rDirect = $this->rDb;
		$rOnBus = $this->newDb();
		$rLog = new QueryLogDb($rOnBus);
		[$rDirDirect, $rDirBus] = [$this->temp() . '/', $this->temp() . '/'];
		$rSide = function (bool $rBus) use ($rDirect, $rLog, $rDirDirect, $rDirBus): void {
			ClusterBus::useSocket($rBus ? self::$rBus->socket() : $this->rNoBus);
			HeartbeatService::useDir($rBus ? $rDirBus : $rDirDirect);
			DatabaseFactory::set($rBus ? $rLog : $rDirect);
		};
		$rSide(true);
		HeartbeatService::flush();
		$rNodeWrites = 0;
		for ($rS = 0; $rS <= 130; $rS++) {
			$rMs = $this->rT0 + $rS * 1000;
			ClusterClock::fix($rMs);
			$rPayload = ['root_ready' => $rS >= 41, 'telemetry' => ['cpu' => (float) ($rS % 97)] + self::SAMPLE];
			foreach ([false, true] as $rBus) {
				$rSide($rBus);
				if ($rS % 2 === 0) {
					$rBefore = count($rLog->rQueries);
					HeartbeatService::record(NodeRegistry::byServer(5), $rPayload, $rMs + 250);
					if ($rBus) {
						$this->assertCount($rBefore + 1, $rLog->rQueries, 'the heartbeat itself asked MySQL nothing (only the test read the node)');
					}
				}
				if ($rBus) {
					$rBefore = count($rLog->writes());
					HeartbeatService::flush();
					$rNodeWrites += count(array_filter(array_slice($rLog->writes(), $rBefore), static fn(string $rQ): bool => str_starts_with($rQ, 'UPDATE `cluster_nodes`')));
				}
			}
			$this->assertSame($this->server($rDirect), $this->server($rOnBus), 'servers at ' . $rS . ' s');
			$this->assertSame($this->stats($rDirect), $this->stats($rOnBus), 'servers_stats at ' . $rS . ' s');
			$rWas = $this->node(5, $rDirect);
			$rNow = $this->node(5, $rOnBus);
			$this->assertSame((int) $rWas['root_ready'], (int) $rNow['root_ready'], 'root_ready at once, at ' . $rS . ' s');
			$this->assertSame((int) $rWas['clock_offset_ms'], (int) $rNow['clock_offset_ms']);
			$this->assertLessThanOrEqual(HeartbeatService::FLUSH_EVERY_MS, (int) $rWas['last_seen_at'] - (int) $rNow['last_seen_at'], 'MySQL at most 5 s behind');
			$this->assertSame((int) $rWas['last_seen_at'], max((int) $rNow['last_seen_at'], HeartbeatService::lastSeen()[5] ?? 0), 'the freshest is what each heartbeat wrote');
		}
		$this->assertCount(3, $this->stats($rOnBus), 'one servers_stats row a minute');
		$this->assertGreaterThan(20, $rNodeWrites);
		$this->assertLessThanOrEqual(intdiv(130, 5) + 2, $rNodeWrites, 'cluster_nodes at most every 5 s (66 heartbeats)');

		// The last heartbeat reaches MySQL without another one after it.
		$rLast = $this->rT0 + 130000;
		ClusterClock::fix($rLast + HeartbeatService::FLUSH_EVERY_MS);
		HeartbeatService::flush();
		$this->assertSame($rLast, (int) $this->node(5, $rOnBus)['last_seen_at']);
		$this->assertSame(intdiv($rLast, 1000), (int) $this->node(5, $rOnBus)['updated_at']);
	}

	public function testWithoutARunningFlusherEachHeartbeatWritesMySql(): void {
		$rRedis = $this->bus();
		$this->beat($this->rT0);
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at'], 'no flusher has run on this bus');
		$this->assertSame([], HeartbeatService::lastSeen());

		HeartbeatService::flush();
		$this->flusherGone($rRedis);
		$this->beat($this->rT0 + 2000);
		$this->assertSame($this->rT0 + 2000, (int) $this->node()['last_seen_at'], 'its flusher stopped: MySQL again');
		$this->assertSame(0, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5'));
	}

	public function testAFlusherThatMySqlRefusesSendsHeartbeatsBackToMySql(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		$this->flusherGone($rRedis); // time passes…
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^UPDATE `cluster_nodes`/';
		DatabaseFactory::set($rLog);
		$this->assertSame([5 => $this->rT0], HeartbeatService::flush(), 'liveness still hears the bus');
		$this->assertNull($this->node()['last_seen_at']);
		$this->beat($this->rT0 + 2000);
		$this->assertSame([5 => $this->rT0], HeartbeatService::lastSeen(), 'no clean pass for 5 s: the heartbeat went to MySQL');
		$this->assertSame(intdiv($this->rT0, 1000), (int) $this->server($this->rDb)['last_check_ago'], 'the telemetry MySQL took is not written twice');

		$rLog->rRefuse = null;
		HeartbeatService::flush();
		$this->beat($this->rT0 + 4000);
		$this->assertSame([5 => $this->rT0 + 4000], HeartbeatService::lastSeen(), 'a clean pass: back on the bus');
	}

	public function testARefusedServersWriteIsTriedAgainAndLeavesThePassUnclean(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		$this->flusherGone($rRedis); // only this pass's stamp could keep heartbeats on the bus
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^UPDATE `servers` SET `watchdog_data`/';
		DatabaseFactory::set($rLog);
		HeartbeatService::flush();
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at'], 'cluster_nodes took its write');
		$this->assertSame(0, (int) $this->server($this->rDb)['requests_per_second'], 'servers refused it');
		$this->assertNotSame((string) $this->rT0, explode(':', (string) $rRedis->hGet(HeartbeatService::KEY_FLUSHED, '5'))[3], 'so it is not recorded as written');
		$this->beat($this->rT0 + 2000);
		$this->assertSame([5 => $this->rT0], HeartbeatService::lastSeen(), 'an unclean pass stamps nothing: the heartbeat went to MySQL');
		$this->assertSame($this->rT0 + 2000, (int) $this->node()['last_seen_at']);

		$rLog->rRefuse = null;
		ClusterClock::fix($this->rT0 + 3000);
		HeartbeatService::flush();
		$rServer = $this->server($this->rDb);
		$this->assertSame([42, intdiv($this->rT0, 1000)], [(int) $rServer['requests_per_second'], (int) $rServer['last_check_ago']], 'written at the next pass, on the time MAIN heard it');
	}

	public function testTelemetryIsJudgedOnTheTimeMainHeardIt(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		ClusterClock::fix($this->rT0 + 3000);
		HeartbeatService::flush();
		$this->assertSame(intdiv($this->rT0, 1000), (int) $this->server($this->rDb)['last_check_ago'], 'not the flush\'s time');
		$this->assertSame([intdiv($this->rT0, 1000)], array_map('intval', array_column($this->stats($this->rDb), 'time')));

		// A document still on the bus when its flusher stopped, and a newer
		// one written directly since: the flusher, back, never writes the
		// older one over it.
		$this->flusherFresh($rRedis);
		$this->beat($this->rT0 + 6000);
		$this->flusherGone($rRedis);
		$this->beat($this->rT0 + 8000);
		$this->assertSame(intdiv($this->rT0 + 8000, 1000), (int) $this->server($this->rDb)['last_check_ago'], 'written directly');
		ClusterClock::fix($this->rT0 + 14000);
		HeartbeatService::flush();
		$this->assertSame(intdiv($this->rT0 + 8000, 1000), (int) $this->server($this->rDb)['last_check_ago'], 'the bus\'s document is older than the row');
		$this->assertCount(1, $this->stats($this->rDb));
		$this->assertSame($this->rT0 + 8000, (int) $this->node()['last_seen_at']);
	}

	public function testTelemetryOfANodeThatIsNotAuthoritativeStaysOutOfServers(): void {
		$rRedis = $this->bus();
		NodeRegistry::update(6, ['state' => 'active', 'flows' => 0]);
		NodeRegistry::update(7, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY, 'mode' => 0]);
		HeartbeatService::flush();
		foreach ([6, 7] as $rID) {
			$this->beat($this->rT0, [], $rID);
			$this->assertSame('0', $this->busValue($rRedis, $rID)[4], 'node ' . $rID . ': shadow only');
		}
		HeartbeatService::flush();
		foreach ([6, 7] as $rID) {
			$rServer = $this->server($this->rDb, $rID);
			$this->assertSame([1, null, 0, 0], [(int) $rServer['status'], $rServer['watchdog_data'], (int) $rServer['last_check_ago'], (int) $rServer['requests_per_second']], 'server ' . $rID . ': its watchdog writes it');
			$this->assertSame($this->rT0, (int) $this->node($rID)['last_seen_at']);
		}
		$this->assertSame([], $this->stats($this->rDb));
	}

	public function testATelemetryDocumentOverTheCapTakesTheDirectPath(): void {
		$this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0, ['telemetry' => ['pad' => str_repeat('x', HeartbeatService::MAX_TELEMETRY)] + self::SAMPLE]);
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at']);
		$this->assertSame(42, (int) $this->server($this->rDb)['requests_per_second'], 'written as before');
		$this->assertSame([], HeartbeatService::lastSeen());
	}

	public function testRootReadyReachesMySqlAtTheNextFlush(): void {
		$this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		HeartbeatService::flush();
		$this->beat($this->rT0 + 1000, ['root_ready' => true]);
		HeartbeatService::flush();
		$this->assertSame(1, (int) $this->node()['root_ready'], 'within the 5 s, because it changed');
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at']);
		$this->beat($this->rT0 + 2000, ['root_ready' => true]);
		HeartbeatService::flush();
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at'], 'unchanged, so it waits its 5 s');
		// A heartbeat without the field keeps the last one.
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$this->flusherFresh($rRedis);
		ClusterClock::fix($this->rT0 + 7000);
		HeartbeatService::record(NodeRegistry::byServer(5), [], $this->rT0 + 7000);
		$rValue = $this->busValue($rRedis);
		$this->assertSame([(string) ($this->rT0 + 7000), '1'], [$rValue[0], $rValue[2]], 'kept on the bus');
		HeartbeatService::flush();
		$this->assertSame([$this->rT0 + 7000, 1], [(int) $this->node()['last_seen_at'], (int) $this->node()['root_ready']]);
	}

	public function testARootReadyChangeThatHelloOvertookStillReachesMySqlAtOnce(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		HeartbeatService::flush();
		$this->flusherFresh($rRedis);
		$this->beat($this->rT0 + 2000, ['root_ready' => true]);
		// hello (the reply named a newer policy) writes last_seen_at before the flush.
		NodeRegistry::update(5, ['last_seen_at' => $this->rT0 + 2100]);
		ClusterClock::fix($this->rT0 + 2500);
		HeartbeatService::flush();
		$this->assertSame([$this->rT0 + 2100, 0], [(int) $this->node()['last_seen_at'], (int) $this->node()['root_ready']], 'hello kept the UPDATE from matching');
		$this->flusherFresh($rRedis);
		$this->beat($this->rT0 + 4000, ['root_ready' => true]);
		ClusterClock::fix($this->rT0 + 4500);
		HeartbeatService::flush();
		$this->assertSame([$this->rT0 + 4000, 1], [(int) $this->node()['last_seen_at'], (int) $this->node()['root_ready']], 'the next heartbeat still counts as a change: 2 s after the last flush, not 5');
	}

	public function testAHeartbeatWithoutTelemetryKeepsThePendingDocument(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0, ['telemetry' => ['cpu' => 99.0] + self::SAMPLE]);
		$this->flusherFresh($rRedis);
		ClusterClock::fix($this->rT0 + 1000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['root_ready' => false], $this->rT0 + 1000);
		$this->assertSame([(string) $this->rT0, '1'], array_slice($this->busValue($rRedis), 3, 2), 'the document heard at T0, authoritative');
		HeartbeatService::flush();
		$rServer = $this->server($this->rDb);
		$this->assertSame(intdiv($this->rT0, 1000), (int) $rServer['last_check_ago']);
		$this->assertSame(99, (int) round(json_decode((string) $rServer['watchdog_data'], true)['cpu']));
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at']);
	}

	public function testAnOlderHeartbeatOnTheBusNeverOverwritesMySql(): void {
		$this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		// hello (not a heartbeat) writes last_seen_at itself, after it.
		NodeRegistry::update(5, ['last_seen_at' => $this->rT0 + 3000, 'clock_offset_ms' => 9]);
		ClusterClock::fix($this->rT0 + 3000);
		HeartbeatService::flush();
		$this->assertSame([$this->rT0 + 3000, 9], [(int) $this->node()['last_seen_at'], (int) $this->node()['clock_offset_ms']]);
	}

	public function testAfterMainsClockStepsBackHeartbeatsFollowIt(): void {
		$this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 60000);
		HeartbeatService::flush();
		$this->assertSame($this->rT0 + 60000, (int) $this->node()['last_seen_at']);
		// The clock steps back a minute: each heartbeat wrote MAIN's time, and so does the bus.
		$this->beat($this->rT0);
		$this->assertSame($this->rT0, HeartbeatService::lastSeen()[5]);
		HeartbeatService::flush();
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at'], 'a last_seen_at ahead of the clock is replaced at once');
		$this->beat($this->rT0 + 2000);
		HeartbeatService::flush();
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at'], 'then every 5 s again');
		ClusterClock::fix($this->rT0 + 5000);
		HeartbeatService::flush();
		$this->assertSame($this->rT0 + 2000, (int) $this->node()['last_seen_at']);
	}

	public function testTheBusCopyIsTheNewerTelemetryDocument(): void {
		$this->assertNull(HeartbeatService::telemetry(5));
		$this->beat($this->rT0);
		$this->assertSame(['at' => $this->rT0, 'telemetry' => json_decode((string) json_encode(self::SAMPLE), true)], HeartbeatService::telemetry(5), 'the shadow file, without the bus (2.0 read back as 2)');

		$this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 2000, ['telemetry' => ['cpu' => 99.0] + self::SAMPLE]);
		$this->assertSame(['at' => $this->rT0 + 2000, 'telemetry' => ['cpu' => 99.0] + self::SAMPLE], HeartbeatService::telemetry(5), 'the bus holds the newer one, exactly');
		self::$rBus->kill();
		$this->assertSame($this->rT0, HeartbeatService::telemetry(5)['at'], 'the file, with the bus gone');
	}

	public function testTwoFlushersNeverWriteTheSameBeat(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		$rRedis->set(HeartbeatService::KEY_LOCK, 'another', ['px' => 10000]);
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$this->assertSame([5 => $this->rT0], HeartbeatService::flush(), 'the freshest still comes back');
		$this->assertSame([], $rLog->writes(), 'the other flusher writes');
		$rRedis->del(HeartbeatService::KEY_LOCK);
		HeartbeatService::flush();
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at']);
		$this->assertSame(0, $rRedis->exists(HeartbeatService::KEY_LOCK), 'given back');
	}

	public function testANodeSilentForTenMinutesLeavesTheBusOnceFlushed(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0, [], 6);
		HeartbeatService::flush(); // node 6 flushed long ago
		$this->flusherFresh($rRedis);
		$this->beat($this->rT0, [], 5); // node 5 not yet
		ClusterClock::fix($this->rT0 + HeartbeatService::FORGET_AFTER_MS + 1000);
		$rHeard = HeartbeatService::flush();
		ksort($rHeard);
		$this->assertSame([5 => $this->rT0, 6 => $this->rT0], $rHeard, 'node 5 flushed first');
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at']);
		$this->assertSame([], HeartbeatService::lastSeen(), 'then both forgotten');
		$this->assertSame([], $rRedis->hGetAll(HeartbeatService::KEY_FLUSHED), 'with their records');
		$this->assertSame(0, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5', HeartbeatService::TEL_PREFIX . '6'), 'and their documents');
	}

	public function testANodeIsForgottenOnlyOnceFlushedAndOnlyIfStillSilent(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^UPDATE `cluster_nodes`/';
		DatabaseFactory::set($rLog);
		$rLate = $this->rT0 + HeartbeatService::FORGET_AFTER_MS + 1000;
		ClusterClock::fix($rLate);
		HeartbeatService::flush();
		$this->assertSame([5 => $this->rT0], HeartbeatService::lastSeen(), 'MySQL refused it: kept until flushed');

		// A pass read the node, then a heartbeat came before the pass ended.
		$rRaw = (string) $rRedis->hGet(HeartbeatService::KEY_BEATS, '5');
		$this->flusherFresh($rRedis);
		$this->beat($rLate + 1000);
		$this->flushEnd('token', false, [5 => $rRaw]);
		$this->assertSame([5 => $rLate + 1000], HeartbeatService::lastSeen(), 'the newer heartbeat stays');
		$this->assertSame(1, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5'), 'with its document');
	}

	public function testAFlusherStampAheadOfTheBussClockIsStale(): void {
		$rRedis = $this->bus();
		// The bus's clock stepped back a minute after the last clean pass.
		$rRedis->set(HeartbeatService::KEY_FLUSHER, (string) (BusServer::nowMs($rRedis) + 60000));
		$this->beat($this->rT0);
		$this->assertSame($this->rT0, (int) $this->node()['last_seen_at'], 'MySQL, until a pass stamps again');
		$this->assertSame([], HeartbeatService::lastSeen());
	}

	public function testAFlusherGivesBackOnlyItsOwnLock(): void {
		$rRedis = $this->bus();
		$rRedis->set(HeartbeatService::KEY_LOCK, 'another', ['px' => 10000]);
		$this->flushEnd('mine', true);
		$this->assertSame('another', $rRedis->get(HeartbeatService::KEY_LOCK), 'a pass whose lock expired leaves the next holder\'s');
		$this->flushEnd('another', true);
		$this->assertSame(0, $rRedis->exists(HeartbeatService::KEY_LOCK));
	}

	public function testAReEnrolmentLeavesNothingOfThePreviousOneOnTheBus(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$rOld = NodeRegistry::byServer(5);
		$this->beat($this->rT0, ['root_ready' => true]);
		NodeRegistry::startEnrolment(5, '00000000-0000-4000-a000-000000000055', str_repeat("\3", 32), str_repeat("\4", 32), 1);
		$this->assertSame([], HeartbeatService::lastSeen(), 'dropped with the enrolment');
		$this->assertSame(0, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5'));

		// A heartbeat of the old enrolment, still in flight, lands after it,
		// and the installer marks the server failed meanwhile.
		$this->rDb->query('UPDATE `servers` SET `status` = 4 WHERE `id` = 5');
		$this->flusherFresh($rRedis);
		ClusterClock::fix($this->rT0 + 500);
		HeartbeatService::record($rOld, ['root_ready' => true, 'telemetry' => self::SAMPLE], $this->rT0 + 750);
		HeartbeatService::flush();
		$rRow = $this->node();
		$this->assertSame([null, 0], [$rRow['last_seen_at'], (int) $rRow['root_ready']], 'the new row is not the old node\'s');
		$this->assertSame(4, (int) $this->server($this->rDb)['status']);

		// The new enrolment's agent, one that sends no root_ready, carries nothing over.
		$rNew = $this->rT0 + 500 + HeartbeatService::FLUSH_EVERY_MS;
		$this->flusherFresh($rRedis);
		ClusterClock::fix($rNew);
		HeartbeatService::record((array) NodeRegistry::byServer(5), [], $rNew);
		$this->assertSame(['-', '0'], array_slice($this->busValue($rRedis), 2, 2));
		HeartbeatService::flush();
		$rRow = $this->node();
		$this->assertSame([$rNew, 0], [(int) $rRow['last_seen_at'], (int) $rRow['root_ready']]);
		$this->assertSame(1, (int) $this->server($this->rDb)['status'], 'the new node marks it up');
	}

	public function testARevocationDropsTheNodesHeartbeats(): void {
		$rRedis = $this->bus();
		HeartbeatService::flush();
		$this->beat($this->rT0);
		NodeRegistry::revoke(5, new FakeClusterCrypto());
		$this->assertSame([], HeartbeatService::lastSeen());
		$this->assertSame(0, $rRedis->exists(HeartbeatService::TEL_PREFIX . '5'));
		HeartbeatService::flush();
		$this->assertNull($this->node()['last_seen_at']);
	}

	// ── Liveness ─────────────────────────────────────────────────────────

	/** Nodes 5, 6 and 7 active with TELEMETRY, last heard (in MySQL) at T0. */
	private function fleet(): void {
		foreach ([5, 6, 7] as $rID) {
			NodeRegistry::update($rID, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY, 'last_seen_at' => $this->rT0]);
		}
	}

	public function testLivenessJudgesTheFreshestOfTheBusAndMySql(): void {
		$rRedis = $this->bus();
		$this->fleet();
		ClusterClock::fix($this->rT0);
		$this->assertSame([5 => [null, 'ok'], 6 => [null, 'ok'], 7 => [null, 'ok']], LivenessService::tick(30));
		// The flusher cannot write (another holds the lock, or MySQL refuses):
		// MySQL still says T0, the bus has heard every node since.
		foreach ([5, 6, 7] as $rID) {
			$this->beat($this->rT0 + 38000, [], $rID);
		}
		$rRedis->set(HeartbeatService::KEY_LOCK, 'another', ['px' => 60000]);
		ClusterClock::fix($this->rT0 + 40000);
		$this->assertSame([], LivenessService::tick(30), 'heard 2 s ago on the bus: ok, not offline');
		$this->assertFalse(ClusterHealth::read()['guard'], 'no fleet silence either');
		$this->assertSame($this->rT0, (int) $this->node(6)['last_seen_at'], 'MySQL did lag');

		// Node 7 goes quiet on the bus too: suspect, then offline, by the bus.
		$this->flusherFresh($rRedis); // the other flusher's clean passes
		foreach ([5, 6] as $rID) {
			$this->beat($this->rT0 + 50000, [], $rID);
		}
		ClusterClock::fix($this->rT0 + 50000);
		$this->assertSame([7 => ['ok', 'suspect']], LivenessService::tick(30));
		$this->flusherFresh($rRedis);
		foreach ([5, 6] as $rID) {
			$this->beat($this->rT0 + 69000, [], $rID);
		}
		ClusterClock::fix($this->rT0 + 69000);
		$this->assertSame([7 => ['suspect', 'offline']], LivenessService::tick(30));

		// MySQL newer than the bus (hello writes it): MySQL wins.
		NodeRegistry::update(7, ['last_seen_at' => $this->rT0 + 69000]);
		$this->assertSame([7 => ['offline', 'suspect']], LivenessService::tick(30));
	}

	public function testTheFleetGuardStillHoldsWithTheBus(): void {
		$rRedis = $this->bus();
		$this->fleet();
		LivenessService::tick(30);
		$this->flusherFresh($rRedis);
		$this->beat($this->rT0 + 38000, [], 5);
		$rRedis->set(HeartbeatService::KEY_LOCK, 'another', ['px' => 60000]);
		ClusterClock::fix($this->rT0 + 40000);
		$this->assertSame([], LivenessService::tick(30), '6 and 7 silent together: held, not offline');
		$this->assertTrue(ClusterHealth::read()['guard']);
	}

	/**
	 * A pass reads the cluster_ctl queue before it flushes the bus: a
	 * heartbeat served while the probe's request waited behind it is in the
	 * same pass, so the pass that sees a queue drain has the heard times the
	 * queue held back.
	 */
	public function testAHeartbeatServedWhileTheQueueProbeWaitsIsInThePass(): void {
		$rRedis = $this->bus();
		$this->fleet();
		LivenessService::tick(30);
		$this->flusherFresh($rRedis); // heartbeats reach the bus alone
		foreach ([5, 6] as $rID) {
			$this->beat($this->rT0 + 9000, [], $rID);
		}
		LivenessService::useQueueReader(function (): ?int {
			$this->beat($this->rT0 + 11000, [], 7);
			return 0;
		});
		ClusterClock::fix($this->rT0 + 11000);
		$this->assertSame([], LivenessService::tick(30), '7 heard in this pass: ok, not suspect');
	}

	public function testSilenceStillCountsFromMainsRestart(): void {
		$this->bus();
		$this->fleet();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 1000);
		// MAIN was down for a minute and came back: the bus (restarted, or
		// not) holds nothing newer, and silence counts from ready_at.
		ClusterMeta::set('ready_at', (string) ($this->rT0 + 60000));
		ClusterClock::fix($this->rT0 + 65000);
		$rStates = LivenessService::tick(30);
		$this->assertSame('ok', $rStates[5][1]);
	}

	public function testABusThatGoesDownFallsBackToMySql(): void {
		$this->bus();
		$this->fleet();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 1000);
		HeartbeatService::flush();
		$this->beat($this->rT0 + 3000); // on the bus only
		self::$rBus->kill();
		ClusterClock::fix($this->rT0 + 4000);
		$this->assertSame([], HeartbeatService::flush(), 'nothing to read, nothing written, no error');
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at']);
		LivenessService::tick(30);
		$this->assertSame('ok', ClusterHealth::state(5), 'MySQL is at most one flush behind');
		$this->beat($this->rT0 + 7000);
		$this->assertSame($this->rT0 + 7000, (int) $this->node()['last_seen_at'], 'the next heartbeat writes MySQL itself');
		$this->assertSame(intdiv($this->rT0 + 7000, 1000), (int) $this->server($this->rDb)['last_check_ago'], '5 s after the flushed one');
	}

	public function testABusLostBetweenHeartbeatAndFlushLosesNothingMySqlHad(): void {
		$this->bus();
		$this->fleet();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 1000);
		HeartbeatService::flush();
		$this->beat($this->rT0 + 3000);
		self::$rBus->restart(); // empty: the heartbeat at +3 s is gone
		ClusterBus::useSocket(self::$rBus->socket());
		ClusterClock::fix($this->rT0 + 7000);
		$this->assertSame([], HeartbeatService::flush());
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at'], 'kept, not rolled back');
		LivenessService::tick(30);
		$this->assertSame('ok', ClusterHealth::state(5));
		// The new bus has a flusher (the pass above): the next heartbeat goes to it.
		$this->beat($this->rT0 + 7000);
		$this->assertSame($this->rT0 + 1000, (int) $this->node()['last_seen_at']);
		HeartbeatService::flush();
		$this->assertSame($this->rT0 + 7000, (int) $this->node()['last_seen_at']);
	}

	public function testABusLostJustBeforeAFlushLeavesNoNodeSuspect(): void {
		$rRedis = $this->bus();
		$this->fleet();
		ClusterClock::fix($this->rT0);
		LivenessService::tick(30);
		foreach ([500, 2500, 4500] as $rAt) {
			$this->flusherFresh($rRedis);
			foreach ([5, 6, 7] as $rID) {
				$this->beat($this->rT0 + $rAt, [], $rID);
			}
			ClusterClock::fix($this->rT0 + $rAt + 500);
			$this->assertSame([], LivenessService::tick(30));
		}
		ClusterClock::fix($this->rT0 + 5900);
		$this->assertSame([], LivenessService::tick(30));
		$this->assertSame($this->rT0 + 500, (int) $this->node()['last_seen_at'], 'flushed at +1 s, not due again before +6 s');
		self::$rBus->kill();

		// MySQL alone says 10.1 s; the loop's last read of the bus 6.1 s.
		ClusterClock::fix($this->rT0 + 10600);
		$this->assertSame([], LivenessService::tick(30), 'no node suspect');
		$this->assertFalse(ClusterHealth::read()['guard']);
		// By the end of that window each node's next heartbeat has written MySQL.
		foreach ([5, 6, 7] as $rID) {
			$this->beat($this->rT0 + 10800, [], $rID);
		}
		ClusterClock::fix($this->rT0 + 12000);
		$this->assertSame([], LivenessService::tick(30));
		// The read is kept no longer than a flush.
		NodeRegistry::update(5, ['last_seen_at' => $this->rT0]);
		$this->assertSame([5 => ['ok', 'suspect']], LivenessService::tick(30));
	}

	public function testTheClusterNodesPageShowsTheFreshest(): void {
		$rRedis = $this->bus();
		$this->fleet();
		HeartbeatService::flush();
		$this->beat($this->rT0 + 1000);
		HeartbeatService::flush();
		$this->beat($this->rT0 + 3000);
		$rRedis->set(HeartbeatService::KEY_LOCK, 'another', ['px' => 60000]);
		ClusterClock::fix($this->rT0 + 12500);
		$rNodes = array_column(ClusterAdmin::nodes([], 30), null, 'server_id');
		$this->assertSame($this->rT0 + 3000, (int) $rNodes[5]['last_seen_at']);
		$this->assertSame('ok', $rNodes[5]['health'], 'silent 11.5 s by MySQL, 9.5 s by the bus');
	}
}
