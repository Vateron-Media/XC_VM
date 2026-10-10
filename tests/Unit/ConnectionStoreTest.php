<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\RedisServer;

/**
 * The connection store seam (cluster plan, Phase 6): the stream endpoints
 * (live, vod, timeshift, rtmp) record, find, refresh and check in their
 * viewers through ConnectionTracker only, on both stores — `lines_live` and
 * Redis. These pin what each store holds after each call, so moving the
 * endpoints onto the seam changed nothing, and so the node's agent can take
 * over behind it later.
 */
final class ConnectionStoreTest extends TestCase {
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
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$rDdl = (string) $rM[0];
		$this->rDb->exec($rDdl);
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		AgentClient::useSocket(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		$this->redis(null);
	}

	/** Point RedisManager at the test server (or nowhere). */
	private function redis(?\Redis $rRedis): void {
		$rProp = new \ReflectionProperty(RedisManager::class, 'instance');
		$rProp->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
	}

	private function connectRedis(): \Redis {
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, phpredis or igbinary not available');
		}
		$rRedis = new \Redis();
		// Explicit timeouts: other suites set default_socket_timeout to 0, which
		// phpredis would take as its read timeout.
		$rRedis->connect('127.0.0.1', self::$rRedisPort, 2.0, null, 0, 2.0);
		$this->redis($rRedis);
		return $rRedis;
	}

	private function row(string $rUUID): ?array {
		$this->rDb->query('SELECT * FROM `lines_live` WHERE `uuid` = ?', $rUUID);
		return $this->rDb->get_rows()[0] ?? null;
	}

	/** @return array<string, mixed> */
	private function record(string $rUUID, array $rExtra = []): array {
		return $rExtra + ['user_id' => 7, 'stream_id' => 100, 'server_id' => 5, 'proxy_id' => null, 'user_agent' => 'VLC', 'user_ip' => '10.0.0.9', 'container' => 'VOD', 'pid' => 4321, 'date_start' => 1800000000, 'geoip_country_code' => 'PT', 'isp' => 'ISP', 'external_device' => '', 'hls_end' => 0, 'hls_last_read' => 1800000000, 'on_demand' => 0, 'identity' => 7, 'uuid' => $rUUID];
	}

	// ── lines_live ───────────────────────────────────────────────────────

	public function testTablePathWritesExactlyTheCallersColumns(): void {
		$rSettings = ['redis_handler' => 0];
		$rRec = $this->record('aaaa');
		// As vod.php writes it: no external_device column, so it stays NULL.
		$this->assertTrue(ConnectionTracker::openRecord($rSettings, $rRec, ['user_id' => 7, 'stream_id' => 100, 'server_id' => 5, 'proxy_id' => null, 'user_agent' => 'VLC', 'user_ip' => '10.0.0.9', 'container' => 'VOD', 'pid' => 4321, 'uuid' => 'aaaa', 'date_start' => 1800000000, 'geoip_country_code' => 'PT', 'isp' => 'ISP', 'hls_last_read' => 1800000000]));
		$rRow = $this->row('aaaa');
		$this->assertSame([7, 'VOD', 4321, null, 0], [(int) $rRow['user_id'], $rRow['container'], (int) $rRow['pid'], $rRow['external_device'], (int) $rRow['hls_end']]);

		$rFound = ConnectionTracker::findByUuid($rSettings, 'aaaa', '`server_id`, `activity_id`, `pid`, `user_ip`');
		$this->assertSame(['server_id', 'activity_id', 'pid', 'user_ip'], array_keys($rFound));
		$this->assertNull(ConnectionTracker::findByUuid($rSettings, 'none', '`activity_id`'));

		// A Range request without the uuid: matched on line, container, agent, stream.
		$rByRange = ConnectionTracker::findByUuid($rSettings, 'none', '`activity_id`, `pid`', ['user_id' => 7, 'container' => 'VOD', 'user_agent' => 'VLC', 'stream_id' => 100]);
		$this->assertSame((int) $rFound['activity_id'], (int) $rByRange['activity_id']);
		$this->assertNull(ConnectionTracker::findByUuid($rSettings, 'none', '`activity_id`', ['user_id' => 8, 'container' => 'VOD', 'user_agent' => 'VLC', 'stream_id' => 100]));

		// Refresh re-opens it.
		$this->rDb->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `uuid` = ?', 'aaaa');
		$this->assertTrue(ConnectionTracker::updateLive($rSettings, $rFound, ['pid' => 99, 'hls_last_read' => 1800000060]));
		$rRow = $this->row('aaaa');
		$this->assertSame([99, 1800000060, 0], [(int) $rRow['pid'], (int) $rRow['hls_last_read'], (int) $rRow['hls_end']]);
	}

	public function testTablePathHeartbeatAndAcceptedIP(): void {
		$rSettings = ['redis_handler' => 0];
		ConnectionTracker::openRecord($rSettings, $this->record('bbbb'), ['user_id' => 7, 'user_ip' => '10.0.0.1', 'pid' => 55, 'uuid' => 'bbbb', 'hls_last_read' => 1]);
		ConnectionTracker::openRecord($rSettings, $this->record('cccc'), ['user_id' => 7, 'user_ip' => '10.0.0.2', 'pid' => 56, 'uuid' => 'cccc', 'hls_last_read' => 1]);
		$this->assertSame('10.0.0.1', ConnectionTracker::acceptedIP($rSettings, 7), 'the first open connection');
		$this->assertNull(ConnectionTracker::acceptedIP($rSettings, 8));

		global $db;
		$db = $this->rDb; // the endpoints' loops reconnect through the global handle
		$rBeat = ConnectionTracker::heartbeat($rSettings, 'bbbb', 1800000300);
		DatabaseFactory::set($this->rDb); // heartbeat closes its connection, as the loops do
		$this->assertSame([55, 0], [(int) $rBeat['pid'], (int) $rBeat['hls_end']]);
		$this->assertSame(1800000300, (int) $this->row('bbbb')['hls_last_read']);
		$db = $this->rDb;
		$this->assertNull(ConnectionTracker::heartbeat($rSettings, 'gone', 1));
	}

	public function testCreateLiveOnTheTablePathKeepsItsColumnsAndReusesAClosedHlsUuid(): void {
		$rSettings = ['redis_handler' => 0];
		$rCtx = ['is_hmac' => null, 'identifier' => null, 'user_id' => 7, 'stream_id' => 100, 'server_id' => 5, 'proxy_id' => 0, 'user_agent' => 'VLC', 'user_ip' => '10.0.0.9', 'date_start' => 1800000000, 'geoip_country_code' => 'PT', 'isp' => 'ISP', 'external_device' => 'box', 'on_demand' => 0, 'uuid' => 'dddd', 'time_offset' => 0];
		$this->assertTrue(ConnectionTracker::createLive($rSettings, $rCtx, 'hls', null));
		$this->rDb->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `uuid` = ?', 'dddd');
		$this->assertTrue(ConnectionTracker::createLive($rSettings, $rCtx, 'hls', null));
		$this->rDb->query('SELECT COUNT(*) AS `n`, MAX(`external_device`) AS `d`, MAX(`hls_end`) AS `e` FROM `lines_live` WHERE `uuid` = ?', 'dddd');
		$this->assertSame(['1', 'box', '0'], array_map('strval', array_values($this->rDb->get_row())), 'the closed row made way for the new one');

		// A node in mode 1 whose agent holds its viewers, and the agent does not
		// answer: the record goes to the table after all, and the closed row made way.
		$rDir = sys_get_temp_dir() . '/xcvm-silent-' . bin2hex(random_bytes(4));
		mkdir($rDir);
		file_put_contents($rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($rDir . '/flows.json');
		AgentClient::useSocket($rDir . '/agent.sock');
		$this->rDb->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `uuid` = ?', 'dddd');
		$this->assertTrue((bool) ConnectionTracker::createLive($rSettings, $rCtx, 'hls', null));
		$this->rDb->query('SELECT COUNT(*) AS `n`, MAX(`hls_end`) AS `e` FROM `lines_live` WHERE `uuid` = ?', 'dddd');
		$this->assertSame(['1', '0'], array_map('strval', array_values($this->rDb->get_row())), 'no closed twin for the reaper to take the new row down with');
		NodeFlows::usePath(null);
		AgentClient::useSocket(null);
		exec('rm -rf ' . escapeshellarg($rDir));

		$rHmac = ['is_hmac' => 3, 'identifier' => 'dev'] + $rCtx;
		$rHmac['uuid'] = 'eeee';
		ConnectionTracker::createLive($rSettings, $rHmac, 'ts', 77);
		$rRow = $this->row('eeee');
		$this->assertSame([null, 3, 'dev', 77], [$rRow['user_id'], (int) $rRow['hmac_id'], $rRow['hmac_identifier'], (int) $rRow['pid']]);
	}

	public function testTheSeamUsesTheCurrentHandleNotOneInjectedAtBoot(): void {
		// Boot wiring injects the handle of the moment; the endpoints later close
		// and reopen theirs. The seam must follow the current one.
		ConnectionTracker::setDb(new TestDb()); // a stale handle: no lines_live in it
		try {
			$this->assertTrue(ConnectionTracker::openRecord(['redis_handler' => 0], $this->record('gggg'), ['user_id' => 7, 'uuid' => 'gggg']));
			$this->assertNotNull(ConnectionTracker::findByUuid(['redis_handler' => 0], 'gggg', '`activity_id`'));
		} finally {
			(new \ReflectionProperty(ConnectionTracker::class, 'db'))->setValue(null, null);
		}
	}

	public function testAnAgentThatDoesNotAnswerLeavesTheViewerOnMainsStore(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-flows-' . bin2hex(random_bytes(4));
		mkdir($rDir);
		file_put_contents($rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($rDir . '/flows.json');
		AgentClient::useSocket($rDir . '/no-agent.sock');
		try {
			$rSettings = ['redis_handler' => 0];
			$this->assertTrue(ConnectionTracker::openRecord($rSettings, $this->record('hhhh'), ['user_id' => 7, 'user_ip' => '10.0.0.1', 'uuid' => 'hhhh', 'hls_last_read' => 1]));
			$this->assertNotNull($this->row('hhhh'), 'written to MAIN\'s store instead');
			$rFound = ConnectionTracker::findByUuid($rSettings, 'hhhh', '`activity_id`, `pid`, `user_ip`');
			$this->assertSame('10.0.0.1', $rFound['user_ip']);
			$this->assertTrue(ConnectionTracker::updateLive($rSettings, $rFound, ['pid' => 3]), 'a table row (no identity) refreshes in the table');
			$this->assertSame('10.0.0.1', ConnectionTracker::acceptedIP($rSettings, 7));
		} finally {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testAnUpsertOfAViewerMainClosedDoesNotOpenItAgain(): void {
		$rBus = BusServer::start('tomb');
		if ($rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		ClusterBus::useSocket($rBus->socket());
		try {
			SettingsManager::set(['redis_handler' => 0]);
			$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'uuid' => 'tttt', 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0];
			$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
			// MAIN kicks it (ConnectionLimiter, ConnectionTracker::closeConnection)...
			ClusterRoute::tombstone(5, 'tttt', 1800000000);
			$this->rDb->query("DELETE FROM `lines_live` WHERE `uuid` = 'tttt'");
			// ...while the node's upsert of it was on its way.
			$this->assertTrue(ConnectionIngest::upsert(5, ['hls_last_read' => 1800000010] + $rRec), 'accepted, as the close of a viewer already gone is');
			$this->assertNull($this->row('tttt'), 'not opened again');
			// The player comes back: a new connection under its playlist key.
			$this->assertTrue(ConnectionIngest::upsert(5, ['date_start' => 1800000020, 'hls_last_read' => 1800000020] + $rRec));
			$this->assertSame(1800000020, (int) $this->row('tttt')['date_start']);
			// MAIN closes that one too; an upsert of either connection still on its way opens neither.
			ClusterRoute::tombstone(5, 'tttt', 1800000020);
			$this->rDb->query("DELETE FROM `lines_live` WHERE `uuid` = 'tttt'");
			$this->assertTrue(ConnectionIngest::upsert(5, ['hls_last_read' => 1800000015] + $rRec));
			$this->assertNull($this->row('tttt'), 'the first connection stays closed');
			$this->assertTrue(ConnectionIngest::upsert(5, ['date_start' => 1800000020, 'hls_last_read' => 1800000025] + $rRec));
			$this->assertNull($this->row('tttt'), 'and so does the second');
		} finally {
			ClusterBus::useSocket(null);
			$rBus->stop();
		}
	}

	public function testMainRemembersAClosedViewerBeforeItChangesTheStore(): void {
		// Afterwards, an upsert ingested between the store's change and the
		// tombstone would still open it again.
		foreach (['Domain/Stream/ConnectionTracker.php' => '/function closeConnection\(.*?ClusterRoute::tombstone\(.*?(UPDATE|DELETE FROM) `lines_live`/s', 'Streaming/Protection/ConnectionLimiter.php' => '/function closeConnection\(.*?ClusterRoute::tombstone\(.*?UPDATE `lines_live` SET `hls_end` = 1/s'] as $rFile => $rOrder) {
			$this->assertMatchesRegularExpression($rOrder, (string) file_get_contents(MAIN_HOME . $rFile), $rFile);
		}
	}

	public function testIngestWritesOnlyTheSendersConnectionsInTheTable(): void {
		SettingsManager::set(['redis_handler' => 0]);
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'server_id' => 99, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'uuid' => 'iiii', 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0, 'identity' => 'forged', 'activity_id' => 1];
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		$rRow = $this->row('iiii');
		$this->assertSame([5, 7, 'hls', 0], [(int) $rRow['server_id'], (int) $rRow['user_id'], $rRow['container'], (int) $rRow['hls_end']], 'server_id is the sender');

		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_last_read' => 1800000030, 'hls_end' => 1] + $rRec));
		$rAfter = $this->row('iiii');
		$this->assertSame([(int) $rRow['activity_id'], 1800000030, 1], [(int) $rAfter['activity_id'], (int) $rAfter['hls_last_read'], (int) $rAfter['hls_end']], 'updated in place');

		$this->assertFalse(ConnectionIngest::upsert(6, $rRec), 'another node cannot take it over');
		$this->assertFalse(ConnectionIngest::upsert(5, ['uuid' => 'jjjj', 'stream_id' => 1]), 'no owner');
		$this->assertFalse(ConnectionIngest::upsert(5, ['uuid' => 'bad uuid;'] + $rRec));
		ConnectionIngest::remove(6, 'iiii');
		$this->assertNotNull($this->row('iiii'), 'another node cannot remove it');
		ConnectionIngest::remove(5, 'iiii');
		$this->assertNull($this->row('iiii'));
	}

	public function testAFanoutCloseWritesTheActivityRowThenDropsTheRow(): void {
		if (!defined('LOGS_TMP_PATH')) {
			define('LOGS_TMP_PATH', sys_get_temp_dir() . '/xcvm-logs-' . bin2hex(random_bytes(4)) . '/');
		}
		@mkdir(LOGS_TMP_PATH, 0777, true);
		@unlink(LOGS_TMP_PATH . 'activity');
		SettingsManager::set(['redis_handler' => 0, 'save_closed_connection' => 1]);
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'ts', 'pid' => 0, 'uuid' => 'llll', 'date_start' => 1800000000, 'hls_last_read' => 1800000000];
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		$this->assertFalse(ConnectionIngest::close(6, 'llll'), 'another node cannot close it');
		$this->assertNotNull($this->row('llll'));
		$this->assertTrue(ConnectionIngest::close(5, 'llll'));
		$this->assertNull($this->row('llll'));
		$rLines = file(LOGS_TMP_PATH . 'activity', FILE_IGNORE_NEW_LINES) ?: [];
		$this->assertCount(1, $rLines);
		$rActivity = json_decode(base64_decode($rLines[0]), true);
		$this->assertSame([7, 100, 5, 'ts', 1800000000], [$rActivity['user_id'], $rActivity['stream_id'], $rActivity['server_id'], $rActivity['container'], $rActivity['date_start']]);
		$this->assertTrue(ConnectionIngest::close(5, 'llll'), 'already closed (fanout_sync, a kick): nothing more');
		$this->assertCount(1, file(LOGS_TMP_PATH . 'activity') ?: []);
		$this->assertFalse(ConnectionIngest::close(5, 'bad uuid;'));
	}

	/**
	 * MAIN closes a row its server said has ended (its sweep for a node in
	 * mode 2, and its mint): the row, then its activity line. One its server
	 * opened again since it was read stays, with nothing logged.
	 */
	public function testMainRetiresAnEndedRowOnlyWhileItIsStillEnded(): void {
		if (!defined('LOGS_TMP_PATH')) {
			define('LOGS_TMP_PATH', sys_get_temp_dir() . '/xcvm-logs-' . bin2hex(random_bytes(4)) . '/');
		}
		@mkdir(LOGS_TMP_PATH, 0777, true);
		@unlink(LOGS_TMP_PATH . 'activity');
		SettingsManager::set(['redis_handler' => 0, 'save_closed_connection' => 1]);
		$this->rDb->exec('CREATE TABLE `signals` (`signal_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `time` int, `custom_data` text, `cache` tinyint DEFAULT 0)');
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'uuid' => 'mmmm', 'date_start' => 1800000000, 'hls_last_read' => 1800000040, 'hls_end' => 1];
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		$rEnded = $this->row('mmmm');

		// Its node opened it again after MAIN read it: it stays, and nothing is logged.
		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec));
		$this->assertFalse(ConnectionIngest::retireEnded($rEnded, 1800000040));
		$this->assertSame(0, (int) $this->row('mmmm')['hls_end']);
		$this->assertFileDoesNotExist(LOGS_TMP_PATH . 'activity');

		// In a sweep the lines of rows still to be removed are held, and dropped
		// when their removal fails. This row is gone already: its line is written
		// at once, and survives that.
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		ConnectionTracker::holdActivity();
		try {
			$this->assertTrue(ConnectionIngest::retireEnded((array) $this->row('mmmm'), 1800000040));
			$this->assertNull($this->row('mmmm'));
			$this->assertCount(1, file(LOGS_TMP_PATH . 'activity') ?: [], 'not held');
			ConnectionTracker::releaseActivity(false);
		} finally {
			ConnectionTracker::holdActivity(false);
		}
		$rLines = file(LOGS_TMP_PATH . 'activity', FILE_IGNORE_NEW_LINES) ?: [];
		$this->assertCount(1, $rLines);
		$rActivity = json_decode(base64_decode($rLines[0]), true);
		$this->assertSame([7, 100, 5, 'hls', 1800000000, 1800000040], [$rActivity['user_id'], $rActivity['stream_id'], $rActivity['server_id'], $rActivity['container'], $rActivity['date_start'], $rActivity['date_end']]);
		$this->assertFalse(ConnectionIngest::retireEnded($rEnded, 1800000040), 'gone already');
		$this->assertCount(1, file(LOGS_TMP_PATH . 'activity') ?: []);
		// Its server is told once to drop the viewer's marker.
		$this->rDb->query('SELECT `server_id`, `custom_data` FROM `signals`');
		$this->assertSame([['server_id' => 5, 'custom_data' => '{"type":"delete_con","uuid":"mmmm"}']], array_map(static fn(array $rRow): array => ['server_id' => (int) $rRow['server_id'], 'custom_data' => $rRow['custom_data']], $this->rDb->get_rows()));
		// The sweep tells each server itself, once for all its rows.
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'nnnn'] + $rRec));
		$this->assertTrue(ConnectionIngest::retireEnded((array) $this->row('nnnn'), 1800000040, false));
		$this->rDb->query('SELECT COUNT(*) FROM `signals`');
		$this->assertSame(1, (int) $this->rDb->get_col());
	}

	/**
	 * MAIN closed a node's ended row (its sweep, its mint) between this
	 * event's read and its UPDATE: the UPDATE changes nothing, and the viewer
	 * that opened again must not be acknowledged with no row. A snapshot's
	 * record is dropped (the digest asks again); an events batch is not applied.
	 */
	public function testAReopenWhoseRowMainJustClosedIsNotAcknowledged(): void {
		SettingsManager::set(['redis_handler' => 0]);
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'hls', 'pid' => null, 'uuid' => 'pppp', 'date_start' => 1800000000, 'hls_last_read' => 1800000040, 'hls_end' => 1];
		$rRacing = new class ($this->rDb) extends DatabaseHandler {
			public function __construct(private TestDb $rInner) {
			}

			public function query($query, ...$args): bool {
				if (str_starts_with((string) $query, 'UPDATE `lines_live` SET')) {
					$this->rInner->query('DELETE FROM `lines_live` WHERE `uuid` = ?', 'pppp'); // MAIN's retire, committed in between
				}
				return $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function num_rows(): int {
				return $this->rInner->num_rows();
			}
		};

		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		DatabaseFactory::set($rRacing);
		$this->assertFalse(ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec), 'a snapshot record: dropped');
		$this->assertNull($this->row('pppp'));

		DatabaseFactory::set($this->rDb);
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		DatabaseFactory::set($rRacing);
		try {
			ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec, true);
			$this->fail('an events batch is not applied');
		} catch (\RuntimeException) {
			// The node sends the batch again.
		}

		// Sent again, it finds no row and records the viewer.
		DatabaseFactory::set($this->rDb);
		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec, true));
		$this->assertSame([5, 0], [(int) $this->row('pppp')['server_id'], (int) $this->row('pppp')['hls_end']]);
		// The same values again change nothing and are no failure: the row is there.
		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec, true));

		// MariaDB counts changed rows, so the same values again report none: the row
		// is then looked for, found, and the event stands.
		$rUnchanged = new class ($this->rDb) extends DatabaseHandler {
			private bool $rAfterUpdate = false;

			/** @var list<string> */
			public array $rRan = [];

			public function __construct(private TestDb $rInner) {
			}

			public function query($query, ...$args): bool {
				$this->rRan[] = (string) $query;
				$this->rAfterUpdate = str_starts_with((string) $query, 'UPDATE `lines_live` SET');
				return $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function num_rows(): int {
				return $this->rAfterUpdate ? 0 : $this->rInner->num_rows();
			}
		};
		DatabaseFactory::set($rUnchanged);
		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_end' => 0] + $rRec, true), 'a resent, unchanged record is no failure');
		$this->assertContains('SELECT 1 FROM `lines_live` WHERE `activity_id` = ? FOR UPDATE;', $rUnchanged->rRan, 'the row was looked for');
		DatabaseFactory::set($this->rDb);
		$this->assertSame(0, (int) $this->row('pppp')['hls_end']);
	}

	// ── Redis ────────────────────────────────────────────────────────────

	public function testRedisPathKeepsTheRecordAndItsSets(): void {
		$rRedis = $this->connectRedis();
		$rRedis->flushAll();
		$rSettings = ['redis_handler' => 1];
		$rRec = $this->record('ffff');
		$this->assertNotFalse(ConnectionTracker::openRecord($rSettings, $rRec, ['ignored' => 'on this path']));
		$this->assertSame($rRec, igbinary_unserialize($rRedis->get('ffff')));
		foreach (['LINE#7', 'STREAM#100', 'SERVER#5', 'LIVE'] as $rSet) {
			$this->assertNotFalse($rRedis->zScore($rSet, 'ffff'), $rSet);
		}
		foreach (['LINE_ALL#7', 'CONNECTIONS'] as $rSet) {
			$this->assertFalse($rRedis->zScore($rSet, 'ffff'), $rSet . ': written for every connection, read by nothing');
		}
		$this->assertSame($rRec, ConnectionTracker::findByUuid($rSettings, 'ffff', 'unused', ['user_id' => 7]));
		$this->assertNull(ConnectionTracker::findByUuid($rSettings, 'none', 'unused', ['user_id' => 7]), 'no Range fallback on Redis, as before');
		$this->assertSame('10.0.0.9', ConnectionTracker::acceptedIP($rSettings, 7));

		$rFound = ConnectionTracker::findByUuid($rSettings, 'ffff', 'unused');
		$this->assertTrue(ConnectionTracker::updateLive($rSettings, $rFound, ['pid' => 99]));
		$this->assertSame(99, $rFound['pid'], 'updated in place');
		$this->assertSame(99, igbinary_unserialize($rRedis->get('ffff'))['pid']);

		$rBeat = ConnectionTracker::heartbeat($rSettings, 'ffff', 1800000300);
		$this->assertSame([99, 1800000300], [$rBeat['pid'], $rBeat['hls_last_read']]);
		$this->assertFalse(RedisManager::isConnected(), 'the check-in closes its connection');
		$this->connectRedis();
		$this->assertSame(1800000300, igbinary_unserialize($rRedis->get('ffff'))['hls_last_read']);
		$this->assertNull(ConnectionTracker::heartbeat($rSettings, 'gone', 1));
	}

	public function testIngestOnRedisKeepsTheNodesRecordAndSets(): void {
		$rRedis = $this->connectRedis();
		$rRedis->flushAll();
		SettingsManager::set(['redis_handler' => 1]);
		$rRec = ['hmac_id' => 3, 'hmac_identifier' => 'dev', 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'ts', 'pid' => 0, 'uuid' => 'kkkk', 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0];
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		$rStored = igbinary_unserialize($rRedis->get('kkkk'));
		$this->assertSame([5, '3_dev'], [$rStored['server_id'], $rStored['identity']]);
		foreach (['LINE#3_dev', 'STREAM#100', 'SERVER#5', 'LIVE'] as $rSet) {
			$this->assertNotFalse($rRedis->zScore($rSet, 'kkkk'), $rSet);
		}
		$this->assertTrue(ConnectionIngest::upsert(5, ['pid' => 42] + $rRec));
		$this->assertSame(42, igbinary_unserialize($rRedis->get('kkkk'))['pid']);
		$this->assertFalse(ConnectionIngest::upsert(6, $rRec));
		$this->assertFalse(ConnectionIngest::remove(6, 'kkkk'));
		$this->assertTrue(ConnectionIngest::remove(5, 'kkkk'));
		$this->assertFalse($rRedis->get('kkkk'));
		$this->assertFalse($rRedis->zScore('SERVER#5', 'kkkk'));
	}

	public function testAFanoutCloseOnRedisDropsTheRecordAndItsSets(): void {
		$rRedis = $this->connectRedis();
		$rRedis->flushAll();
		SettingsManager::set(['redis_handler' => 1, 'save_closed_connection' => 0]);
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'ts', 'pid' => 0, 'uuid' => 'mmmm', 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0];
		$this->assertTrue(ConnectionIngest::upsert(5, $rRec));
		$this->assertFalse(ConnectionIngest::close(6, 'mmmm'));
		$this->assertTrue(ConnectionIngest::close(5, 'mmmm'));
		$this->assertFalse($rRedis->get('mmmm'));
		foreach (['LINE#7', 'STREAM#100', 'SERVER#5', 'LIVE'] as $rSet) {
			$this->assertFalse($rRedis->zScore($rSet, 'mmmm'), $rSet);
		}
	}
}
