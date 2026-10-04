<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutSyncCommand;
use XcVm\Cli\CronJobs\UsersCronJob;
use XcVm\Core\Cluster\DivergenceSink;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * The legacy writers' database: every statement is recorded, and a SELECT
 * starting with a key of $rAnswers gets those rows.
 */
final class DivergenceWriterDb extends DatabaseHandler {
	/** @var list<string> */
	public array $rQueries = [];

	/** @var array<string, list<array<string, mixed>>> */
	public array $rAnswers = [];

	/** @var list<array<string, mixed>> */
	private array $rRows = [];

	public function __construct() {
		$this->dbh = true;
	}

	public function ping(): bool {
		return true;
	}

	public function query($query, ...$args): bool {
		$this->rQueries[] = $query;
		$this->rRows = [];
		foreach ($this->rAnswers as $rStart => $rRows) {
			if (str_starts_with($query, $rStart)) {
				$this->rRows = $rRows;
			}
		}
		return true;
	}

	public function get_rows(bool $use_id = false, string $column_as_id = '', bool $unique_row = true, string $sub_row_id = '') {
		return $this->rRows;
	}

	public function get_row() {
		return $this->rRows[0] ?? [];
	}

	public function num_rows() {
		return count($this->rRows);
	}

	/** @return list<string> the statements that write lines_divergence or lines_live */
	public function writes(): array {
		return array_values(array_filter($this->rQueries, static fn(string $rQuery): bool => (bool) preg_match('/^(INSERT|REPLACE|UPDATE)/', $rQuery)));
	}
}

/**
 * Phase 6, `conn.divergence`: on a node whose CONNECTIONS flow is on, the
 * writers of `lines_divergence` (the users cron's speed files, fanout_sync's
 * daemon rates) spool each viewer's measured rate on P1 instead of writing
 * MAIN's database; MAIN turns it into the viewer's divergence against the
 * stream's bitrate on that node, for the node's own connections only.
 */
final class ConnectionDivergenceTest extends TestCase {
	private const FLOWS = NodeRegistry::FLOW_CONNECTIONS | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS;

	private static ?string $rRedisDir = null;

	private static int $rRedisPort = 0;

	/** @var resource|null */
	private static $rRedisProc = null;

	private TestDb $rDb;

	private string $rDir;

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
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `bitrate` int)');
		$this->rDb->exec('CREATE TABLE `lines_divergence` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` varchar(32) UNIQUE, `divergence` float)');
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) $rM[0]);
		// Stream 100 runs at 1000 kbps on node 5 (115 KiB/s expected) and 4000 on node 6.
		$this->rDb->query('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `bitrate`) VALUES (100, 5, 1000), (101, 5, NULL), (100, 6, 4000)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(5, '11111111-1111-4111-a111-111111111111', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'mode' => 1, 'flows' => self::FLOWS]);

		$this->rDir = sys_get_temp_dir() . '/xcvm-divergence-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		EventSpool::useDir($this->rDir . '/spool/');
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rSettings']);
		EventSpool::useDir(null);
		SettingsManager::set([]);
		NodeFlows::usePath(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows, int $rAge = 0): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		touch($this->rDir . '/flows.json', time() - $rAge);
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	/** @return list<array<string, mixed>> the spooled events of a lane, in file order */
	private function spooled(string $rLane): array {
		$rFiles = glob($this->rDir . '/spool/' . $rLane . '/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	/** @return array<string, int> lines_divergence, by uuid */
	private function divergences(): array {
		$this->rDb->query('SELECT `uuid`, `divergence` FROM `lines_divergence` ORDER BY `uuid`');
		$rOut = [];
		foreach ($this->rDb->get_rows() as $rRow) {
			$rOut[(string) $rRow['uuid']] = (int) $rRow['divergence'];
		}
		return $rOut;
	}

	private function live(string $rUUID, int $rServerID, int $rStreamID): void {
		$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `stream_id`, `user_id`, `container`, `hls_end`) VALUES (?, ?, ?, 7, ?, 0)', $rUUID, $rServerID, $rStreamID, 'ts');
	}

	/** @param array<string, int> $rRates */
	private function event(array $rRates): array {
		$rRows = [];
		foreach ($rRates as $rUUID => $rRate) {
			$rRows[] = ['uuid' => (string) $rUUID, 'rate' => $rRate];
		}
		return ['type' => 'conn.divergence', 't' => 1800000000000, 'd' => ['rows' => $rRows]];
	}

	private function node(): array {
		return NodeRegistry::byServer(5);
	}

	// ── Node side ────────────────────────────────────────────────────────

	public function testTheNodeSpoolsItsViewersRatesOnP1WhenConnectionsIsOn(): void {
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$this->assertTrue(DivergenceSink::spool(['aaaa' => 120, 'bad uuid;' => 5, 'bbbb' => 0, 'cccc' => -3, str_repeat('d', 33) => 1], 'cron'));
		$this->assertSame([['conn.divergence', ['rows' => [['uuid' => 'aaaa', 'rate' => 120], ['uuid' => 'bbbb', 'rate' => 0]]]]], array_map(static fn($e) => [$e['type'], $e['d']], $this->spooled('p1')));
		$this->assertSame([], $this->spooled('p0'), 'divergence is P1');

		// Many viewers: one file, CHUNK rows per event.
		$rMany = [];
		for ($i = 0; $i < DivergenceSink::CHUNK + 5; $i++) {
			$rMany[sprintf('v%05d', $i)] = $i;
		}
		$this->assertTrue(DivergenceSink::spool($rMany, 'fanout'));
		$this->assertCount(2, glob($this->rDir . '/spool/p1/*.ndjson') ?: []);
		$rEvents = array_slice($this->spooled('p1'), 1);
		$this->assertSame([DivergenceSink::CHUNK, 5], array_map(static fn($e) => count($e['d']['rows']), $rEvents));
		$this->assertFalse(DivergenceSink::spool(['aaaa' => 1], 'nobody'), 'a writer it does not know');
	}

	public function testAWritersNextReportWaitsWhileItsLastIsStillInTheSpool(): void {
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$this->assertTrue(DivergenceSink::spool(['aaaa' => 1], 'cron'));
		$this->assertTrue(DivergenceSink::spool(['aaaa' => 2], 'cron'), 'handled: MAIN gets the one before');
		$this->assertTrue(DivergenceSink::spool(['aaaa' => 3], 'fanout'), 'another writer\'s report does not wait for it');
		$this->assertSame([[1], [3]], array_map(static fn($e) => array_column($e['d']['rows'], 'rate'), $this->spooled('p1')), 'no backlog of stale reports for the lane\'s cap to drop logs for');

		// Once the agent has sent it, the next report goes.
		array_map('unlink', glob($this->rDir . '/spool/p1/*.ndjson') ?: []);
		$this->assertTrue(DivergenceSink::spool(['aaaa' => 4], 'cron'));
		$this->assertSame([[4]], array_map(static fn($e) => array_column($e['d']['rows'], 'rate'), $this->spooled('p1')));

		// An agent that stopped sends neither: the writer writes MAIN's tables.
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS, EventSpool::STALE_AFTER + 30);
		$this->assertFalse(DivergenceSink::spool(['aaaa' => 5], 'cron'));
	}

	public function testWithoutTheFlowOrWithAStoppedAgentTheNodeWritesItself(): void {
		$this->flows(NodeFlows::STREAMS | NodeFlows::COMMANDS);
		$this->assertFalse(DivergenceSink::spool(['aaaa' => 120], 'cron'), 'CONNECTIONS off: MAIN\'s database, as before');
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS, EventSpool::STALE_AFTER + 30);
		$this->assertFalse(DivergenceSink::spool(['aaaa' => 120], 'cron'), 'agent stopped');
		$this->assertSame([], $this->spooled('p1'));
		$this->flows(0);
		$this->assertFalse(DivergenceSink::spool(['aaaa' => 120], 'cron'), 'a legacy node');
	}

	/** The recording database the legacy writers reach through `global $db`. */
	private function writerDb(): DivergenceWriterDb {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$rDb = new DivergenceWriterDb();
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rSettings'] = ['redis_handler' => 0];
		return $rDb;
	}

	public function testTheUsersCronSpoolsItsSpeedFilesOrWritesMainsTablesNeverBoth(): void {
		$rSpeeds = $this->rDir . '/divergence/';
		mkdir($rSpeeds);
		$rWrite = new \ReflectionMethod(UsersCronJob::class, 'writeDivergence');
		$rSpeedFiles = static function () use ($rSpeeds): void {
			file_put_contents($rSpeeds . 'aaaa', '300');
			file_put_contents($rSpeeds . 'bbbb', "10\n");
		};

		// CONNECTIONS off: MAIN's tables, as before, and nothing spooled.
		$this->flows(NodeFlows::STREAMS);
		$rDb = $this->writerDb();
		$rSpeedFiles();
		$rWrite->invoke(new UsersCronJob(), false, $rSpeeds);
		$this->assertCount(1, $rDb->writes());
		$this->assertStringStartsWith('INSERT INTO `lines_divergence`', $rDb->writes()[0]);
		$this->assertStringContainsString("('aaaa', 0)", $rDb->writes()[0]);
		$this->assertSame([], $this->spooled('p1'));
		$this->assertSame([], glob($rSpeeds . '*') ?: [], 'the files it read are gone');

		// CONNECTIONS on: one P1 event, and MAIN's tables are not written.
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$rDb = $this->writerDb();
		$rSpeedFiles();
		$rWrite->invoke(new UsersCronJob(), false, $rSpeeds);
		$this->assertSame([], $rDb->rQueries, 'no write of MAIN\'s tables, not even of zeros');
		$this->assertSame([['uuid' => 'aaaa', 'rate' => 300], ['uuid' => 'bbbb', 'rate' => 10]], $this->spooled('p1')[0]['d']['rows']);
		$this->assertSame([], glob($rSpeeds . '*') ?: []);
	}

	public function testFanoutSyncSpoolsItsOwnDaemonViewersOnceAMinuteOrWritesMainsTablesNeverBoth(): void {
		$rWrite = new \ReflectionMethod(FanoutSyncCommand::class, 'writeDivergence');
		$rConns = [['uuid' => 'tttt', 'stream_id' => 100, 'pid' => 0, 'activity_id' => 9], ['uuid' => 'uuuu', 'stream_id' => 100, 'pid' => 0, 'activity_id' => 10]];
		$rRates = ['tttt' => 57, 'elsewhere' => 50];

		// CONNECTIONS off: MAIN's tables, from the stream's bitrate, as before.
		$this->flows(NodeFlows::STREAMS);
		$rDb = $this->writerDb();
		$rDb->rAnswers = ['SELECT `stream_id`, `bitrate` FROM `streams_servers`' => [['stream_id' => 100, 'bitrate' => 1000]]];
		$rSync = new FanoutSyncCommand();
		$rWrite->invoke($rSync, $rConns, $rRates);
		$this->assertCount(2, $rDb->writes());
		$this->assertStringContainsString("('tttt', 50)", $rDb->writes()[0]);
		$this->assertStringContainsString('(9, 50)', $rDb->writes()[1]);
		$this->assertSame([], $this->spooled('p1'));

		// CONNECTIONS on: the node's own daemon viewers' rates, and MAIN's
		// tables are not written.
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS);
		$rDb = $this->writerDb();
		$rSync = new FanoutSyncCommand();
		$rWrite->invoke($rSync, $rConns, $rRates);
		$this->assertSame([], $rDb->rQueries);
		$this->assertSame([['uuid' => 'tttt', 'rate' => 57]], $this->spooled('p1')[0]['d']['rows']);

		// The next pass, 10 s on: nothing, until a minute has passed.
		array_map('unlink', glob($this->rDir . '/spool/p1/*.ndjson') ?: []);
		$rWrite->invoke($rSync, $rConns, ['tttt' => 58]);
		$this->assertSame([], $rDb->rQueries);
		$this->assertSame([], $this->spooled('p1'), 'P1 is shared with the node\'s logs');
		(new \ReflectionProperty(FanoutSyncCommand::class, 'rDivergenceAt'))->setValue($rSync, time() - 60);
		$rWrite->invoke($rSync, $rConns, ['tttt' => 58]);
		$this->assertSame([['uuid' => 'tttt', 'rate' => 58]], $this->spooled('p1')[0]['d']['rows']);

		// An agent that stopped: MAIN's tables again.
		$this->flows(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS, EventSpool::STALE_AFTER + 30);
		$rDb = $this->writerDb();
		$rDb->rAnswers = ['SELECT `stream_id`, `bitrate` FROM `streams_servers`' => [['stream_id' => 100, 'bitrate' => 1000]]];
		(new \ReflectionProperty(FanoutSyncCommand::class, 'rDivergenceAt'))->setValue($rSync, 0);
		$rWrite->invoke($rSync, $rConns, $rRates);
		$this->assertCount(2, $rDb->writes());
	}

	public function testTheFormulaIsTheLegacyOne(): void {
		$this->assertSame(115, DivergenceSink::expected(1000), 'bitrate / 8 * 0.92, in KiB/s');
		$this->assertSame(50, DivergenceSink::of(57, 115), 'how far below the expected rate, in percent');
		$this->assertSame(0, DivergenceSink::of(200, 115), 'faster than real time is no shortfall');
		$this->assertSame(100, DivergenceSink::of(0, 115));
		$this->assertSame(0, DivergenceSink::of(10, 0), 'no bitrate known');
	}

	// ── MAIN side ────────────────────────────────────────────────────────

	public function testMainWritesTheDivergenceOfTheNodesOwnViewersFromItsBitrate(): void {
		SettingsManager::set(['redis_handler' => 0]);
		$this->live('aaaa', 5, 100);
		$this->live('bbbb', 5, 100);
		$this->live('cccc', 6, 100); // node 6's viewer
		$this->live('dddd', 5, 101); // no bitrate
		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [$this->event(['aaaa' => 57, 'bbbb' => 200, 'cccc' => 10, 'dddd' => 30, 'eeee' => 10])]);
		$this->assertSame([1, 1, 0], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
		$this->assertSame(['aaaa' => 50, 'bbbb' => 0, 'dddd' => 0], $this->divergences(), 'only the node\'s own viewers, as its store holds them');
		$this->rDb->query('SELECT `uuid`, `divergence` FROM `lines_live` ORDER BY `uuid`');
		$this->assertSame(['aaaa' => 50, 'bbbb' => 0, 'cccc' => 0, 'dddd' => 0], array_map('intval', array_column($this->rDb->get_rows(), 'divergence', 'uuid')), 'and the live row, as the cron wrote it');

		// The next report replaces the value.
		EventIngest::ingest($this->node(), 'p1', 2, [$this->event(['aaaa' => 115])]);
		$this->assertSame(0, $this->divergences()['aaaa']);
		$this->assertCount(3, $this->divergences(), 'one row per viewer');
	}

	public function testMainRefusesDivergenceItCannotTake(): void {
		SettingsManager::set(['redis_handler' => 0]);
		$this->live('aaaa', 5, 100);
		$this->live('cccc', 6, 100);
		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [
			$this->event(['cccc' => 10]),                                                      // not the node's
			['type' => 'conn.divergence', 'd' => ['rows' => []]],
			['type' => 'conn.divergence', 'd' => ['rows' => [['uuid' => 'aaaa', 'rate' => '57']]]], // a string rate
			['type' => 'conn.divergence', 'd' => ['rows' => array_fill(0, DivergenceSink::CHUNK + 1, ['uuid' => 'aaaa', 'rate' => 1])]],
		]);
		$this->assertSame([0, 4], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p0', 1, [$this->event(['aaaa' => 57])])['applied'], 'P1 only');
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$this->assertSame(0, EventIngest::ingest($this->node(), 'p1', 5, [$this->event(['aaaa' => 57])])['applied'], 'CONNECTIONS off');
		$this->assertSame([], $this->divergences());
	}

	public function testOnRedisMainChecksTheNodesRecords(): void {
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, phpredis or igbinary not available');
		}
		$rRedis = new \Redis();
		$rRedis->connect('127.0.0.1', self::$rRedisPort, 2.0, null, 0, 2.0);
		$rRedis->flushAll();
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
		SettingsManager::set(['redis_handler' => 1]);
		$rRec = ['user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'container' => 'ts', 'pid' => 0, 'date_start' => 1800000000, 'hls_last_read' => 1800000000, 'hls_end' => 0];
		$this->assertTrue(ConnectionIngest::upsert(5, ['uuid' => 'aaaa'] + $rRec));
		$this->assertTrue(ConnectionIngest::upsert(6, ['uuid' => 'cccc'] + $rRec));

		$rOut = EventIngest::ingest($this->node(), 'p1', 1, [$this->event(['aaaa' => 57, 'cccc' => 1, 'zzzz' => 1])]);
		$this->assertSame(1, $rOut['applied']);
		$this->assertSame(['aaaa' => 50], $this->divergences());

		// Redis gone: the event is dropped, and the lane (logs too) goes on.
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, new \Redis());
		$rOut = EventIngest::ingest($this->node(), 'p1', 2, [$this->event(['aaaa' => 0])]);
		$this->assertSame([2, 0, 1], [$rOut['useq'], $rOut['applied'], $rOut['dropped']]);
	}
}
