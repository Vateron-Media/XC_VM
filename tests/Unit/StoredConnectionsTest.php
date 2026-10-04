<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * The one reader of a node's connections in MAIN's store and the one line
 * identity rule (cluster plan, Phase 6): the digest check and a snapshot's
 * removals read the open ones, cluster:seed-connections reads them all, and
 * ingest keeps a record under the identity its admission reserved.
 */
final class StoredConnectionsTest extends TestCase {
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
		self::$rRedisPort = random_int(20000, 40000);
		$rNull = ['file', '/dev/null', 'w'];
		self::$rRedisProc = proc_open(['redis-server', '--port', (string) self::$rRedisPort, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', self::$rRedisDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$rRedisPort); $i++) {
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
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_id` int, `stream_id` int, `server_id` int, `proxy_id` int, `user_agent` text, `user_ip` text, `container` text, `pid` int, `date_start` int, `geoip_country_code` text, `isp` text, `external_device` text, `hls_last_read` int, `hls_end` int DEFAULT 0, `hmac_id` int, `hmac_identifier` text, `uuid` text, `divergence` int DEFAULT 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		DatabaseFactory::reset();
		$this->redis(null);
	}

	/** Point RedisManager at the test server (or nowhere). */
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

	private function row(?string $rUUID, int $rServer, ?int $rUser, int $rEnd = 0, ?int $rHMAC = null, ?string $rIdentifier = null): void {
		$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `hls_end`, `hmac_id`, `hmac_identifier`, `divergence`) VALUES (?, ?, ?, ?, ?, ?, 40)', $rUUID, $rServer, $rUser, $rEnd, $rHMAC, $rIdentifier);
	}

	public function testTheIdentityIsTheLineOrTheHmacPair(): void {
		$this->assertSame('7', StoredConnections::identity(['user_id' => 7, 'hmac_id' => 3, 'hmac_identifier' => 'dev']));
		$this->assertSame('7', StoredConnections::identity(['user_id' => '7']));
		$this->assertSame('3_dev', StoredConnections::identity(['user_id' => null, 'hmac_id' => 3, 'hmac_identifier' => 'dev']));
		$this->assertSame('3_' . str_repeat('i', 255), StoredConnections::identity(['hmac_id' => 3, 'hmac_identifier' => str_repeat('i', 300)]), 'the identifier cut to 255 bytes, as a token\'s and a node\'s request both give it');
		$this->assertSame('3_dev', StoredConnections::identity(['user_id' => '0', 'hmac_id' => '3', 'hmac_identifier' => 'dev']), 'a line id of 0 is no line');
		$this->assertSame('3_', StoredConnections::identity(['hmac_id' => 3]));
		$this->assertSame('0_', StoredConnections::identity([]));
	}

	public function testTheIdentityReadsBothIdsAsTheDigestsOwnerDoes(): void {
		// A non-canonical id is the owner's integer (ConnectionDigest::owner()),
		// as the admission that reserved the viewer computed it.
		foreach ([['user_id' => '042'], ['user_id' => 42.0], ['hmac_id' => '03', 'hmac_identifier' => 'x'], ['hmac_id' => 3, 'hmac_identifier' => 'x']] as $rRecord) {
			$rOwner = ConnectionDigest::owner($rRecord);
			$rIdentity = StoredConnections::identity($rRecord);
			$this->assertSame(str_starts_with($rOwner, 'u:') ? substr($rOwner, 2) : str_replace(':', '_', substr($rOwner, 2)), $rIdentity);
		}
		$this->assertSame('42', StoredConnections::identity(['user_id' => '042']));
		$this->assertSame('3_x', StoredConnections::identity(['hmac_id' => '03', 'hmac_identifier' => 'x']));
	}

	public function testTheTableReaderTakesTheOpenOnesOrAllOfTheNodes(): void {
		$this->row('a', 5, 7);
		$this->row('h', 5, null, 0, 3, 'dev');
		$this->row('e', 5, 9, 1); // ended
		$this->row('x', 6, 7); // another node's
		$this->row(null, 5, 8); // no uuid
		$this->row('', 5, 8);

		$rOpen = StoredConnections::ofServer(5, true);
		$this->assertSame(['a', 'h'], array_column($rOpen, 'uuid'));
		$this->assertSame(['7', '3_dev'], array_column($rOpen, 'identity'));

		$rAll = StoredConnections::ofServer(5, false);
		$this->assertSame(['a', 'h', 'e'], array_column($rAll, 'uuid'), 'ended HLS rows too, as the registry holds them');
		$this->assertSame(['7', '3_dev', '9'], array_column($rAll, 'identity'));
		$rKeys = array_keys($rAll[0]);
		sort($rKeys);
		$rWant = array_values(array_diff(AgentConnections::RECORD_KEYS, ['on_demand']));
		sort($rWant);
		$this->assertSame($rWant, $rKeys, 'only the registry record\'s keys, never the line\'s other columns');

		$rDigest = ConnectionDigest::stored(5);
		$this->assertSame(['a', 'h'], array_keys($rDigest), 'the digest reads the open ones, by uuid');
		$this->assertSame(['uuid', 'user_id', 'hmac_id', 'hmac_identifier', 'hls_end'], array_keys($rDigest['a']), 'only what the digest reads of them');
	}

	public function testTheReaderTakesOnlyTheKeysAskedFor(): void {
		$this->row('h', 5, null, 0, 3, 'dev');
		$this->row(null, 5, 8);
		$this->row('', 5, 8);
		$this->assertSame([['uuid' => 'h', 'identity' => '3_dev']], StoredConnections::ofServer(5, false, ['uuid', 'identity']), 'the identity from the owner\'s columns, which are not asked for');
		$this->assertSame([['hls_end' => 0]], StoredConnections::ofServer(5, true, ['hls_end']), 'rows without a uuid stay out when the uuid is not asked for');
	}

	public function testWithoutItsDatabaseTheReaderThrows(): void {
		DatabaseFactory::reset();
		$this->expectExceptionMessage('no database');
		StoredConnections::ofServer(5, false);
	}

	public function testWithoutRedisTheReaderThrows(): void {
		SettingsManager::set(['redis_handler' => 1]);
		RedisManager::closeInstance();
		RedisManager::useConnector(static fn() => null); // Redis unreachable
		$this->expectExceptionMessage('redis unavailable');
		try {
			ConnectionDigest::stored(5);
		} finally {
			RedisManager::useConnector(null);
		}
	}

	public function testTheRedisReaderTakesTheNodesRecordsAsStored(): void {
		$rRedis = $this->connectRedis();
		SettingsManager::set(['redis_handler' => 1]);
		$rBase = ['stream_id' => 100, 'proxy_id' => null, 'user_agent' => 'VLC', 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => 0, 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0];
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'a', 'user_id' => '042'] + $rBase));
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'h', 'hmac_id' => 3, 'hmac_identifier' => 'dev'] + $rBase));
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'e', 'user_id' => 9, 'hls_end' => 1] + $rBase));
		$this->assertTrue(ConnectionIngest::upsert(6, ['uuid' => 'x', 'user_id' => 7] + $rBase));
		$rStray = ['uuid' => 'y', 'server_id' => 6, 'user_id' => 8, 'divergence' => 40];
		$rRedis->set('y', igbinary_serialize($rStray));
		$rRedis->zAdd('SERVER#5', 1, 'y'); // listed under node 5, but another node's record

		$this->assertSame(['a'], $rRedis->zRange('LINE#42', 0, -1), 'kept under the line\'s integer id, where its admission reserved it');
		$this->assertSame([], $rRedis->zRange('LINE#042', 0, -1));

		$this->assertContains('e', $rRedis->zRange('SERVER#5', 0, -1), 'a record upserted as ended is listed under its node');

		$rAll = StoredConnections::ofServer(5, false);
		usort($rAll, static fn($rA, $rB) => strcmp($rA['uuid'], $rB['uuid']));
		$this->assertSame(['a', 'e', 'h'], array_column($rAll, 'uuid'), 'the ended one too, as the registry holds it');
		$this->assertSame(['42', '9', '3_dev'], array_column($rAll, 'identity'), 'the identity the record was stored under');
		$this->assertSame([], array_diff(array_keys($rAll[0]), AgentConnections::RECORD_KEYS));

		$rOpen = array_column(StoredConnections::ofServer(5, true), 'uuid');
		sort($rOpen);
		$this->assertSame(['a', 'h'], $rOpen, 'the open ones only, as in lines_live');
		$rDigest = ConnectionDigest::stored(5);
		ksort($rDigest);
		$this->assertSame(['a', 'h'], array_keys($rDigest), 'the digest reads the open ones, by uuid');
		$this->assertSame([], array_diff(array_keys($rDigest['a']), ['uuid', 'user_id', 'hmac_id', 'hmac_identifier', 'hls_end']), 'only what the digest reads of them');
	}
}
