<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Tests\Support\RedisServer;

/**
 * A CONNECTIONS node's `conn.upsert`, `conn.remove` and `conn.close` events
 * apply once (plan, Phase 6; "Ordering and backpressure": P0 is gap-checked
 * and never applied twice), however they reach MAIN again:
 *
 * - the same batch resent after its reply was lost;
 * - the same batch resent while MAIN was still applying the first copy (the
 *   node's request timed out), so both requests read the node's cursor
 *   before either had moved it;
 * - the same event again under a new number (an agent that spooled it twice);
 * - a batch whose cursor MAIN could not write, which must fail so the node
 *   sends it again, rather than be reported applied.
 *
 * MAIN reads the cursor and moves it while it holds the node's lane (a file
 * lock), which is what makes the second of two copies a repeat.
 *
 * "Once" is what MAIN's store holds and how many activity rows a close
 * writes. Both stores: `lines_live` and, when a redis-server is installed,
 * Redis.
 */
final class ConnectionIngestIdempotencyTest extends TestCase {
	private const SID = 5;

	private static ?string $rRedisDir = null;

	private static int $rRedisPort = 0;

	/** @var resource|null */
	private static $rRedisProc = null;

	/** LOGS_TMP_PATH, when this class defined it: removed after the class. */
	private static ?string $rOwnLogs = null;

	private TestDb $rDb;

	/** The lanes' lock files (EventIngest::useLockDir). */
	private string $rLockDir;

	/** The lock directory before this test (the suite's, from tests/bootstrap.php). */
	private ?string $rPrevLockDir = null;

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
		if (self::$rOwnLogs !== null) {
			exec('rm -rf ' . escapeshellarg(self::$rOwnLogs));
		}
	}

	public static function stores(): array {
		return ['lines_live' => [false], 'redis' => [true]];
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) $rM[0]);
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(self::SID, '55555555-5555-4555-a555-555555555555', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS]);
		if (!defined('LOGS_TMP_PATH')) {
			define('LOGS_TMP_PATH', sys_get_temp_dir() . '/xcvm-logs-' . bin2hex(random_bytes(4)) . '/');
			self::$rOwnLogs = LOGS_TMP_PATH;
		}
		@mkdir(LOGS_TMP_PATH, 0777, true);
		@unlink(LOGS_TMP_PATH . 'activity');
		$this->rLockDir = sys_get_temp_dir() . '/xcvm-ingest-' . bin2hex(random_bytes(4)) . '/';
		$this->rPrevLockDir = EventIngest::useLockDir($this->rLockDir);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		EventIngest::useLockDir($this->rPrevLockDir);
		exec('rm -rf ' . escapeshellarg($this->rLockDir));
		$this->redis(null);
		@unlink(LOGS_TMP_PATH . 'activity');
	}

	/**
	 * MAIN's database, with $rHook run before each query: it sees the SQL,
	 * and a false from it fails the query, as DatabaseHandler::query() answers
	 * when the connection is gone.
	 *
	 * @param callable(string): ?bool $rHook
	 */
	private function hookedDb(callable $rHook): void {
		DatabaseFactory::set(new class ($this->rDb, $rHook) extends DatabaseHandler {
			/** @var callable(string): ?bool */
			private $rHook;

			public function __construct(private TestDb $rInner, callable $rHook) {
				$this->rHook = $rHook;
			}

			public function query($query, ...$args): bool {
				return ($this->rHook)((string) $query) !== false && $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function get_raw_rows(): array {
				return $this->rInner->get_raw_rows();
			}

			public function get_raw_row(): ?array {
				return $this->rInner->get_raw_row();
			}

			public function num_rows(): int {
				return $this->rInner->num_rows();
			}

			public function beginTransaction() {
				return $this->rInner->pdo->beginTransaction();
			}

			public function commit() {
				return $this->rInner->pdo->commit();
			}

			public function rollback() {
				return $this->rInner->pdo->rollBack();
			}
		});
	}

	/** Is the node's P0 lane held by someone? A lock of our own is refused while it is. */
	private function laneHeld(): bool {
		$rProbe = fopen($this->rLockDir . self::SID . '_p0.lock', 'c');
		$this->assertIsResource($rProbe);
		$rFree = flock($rProbe, LOCK_EX | LOCK_NB);
		fclose($rProbe);
		return !$rFree;
	}

	private function redis(?\Redis $rRedis): void {
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, $rRedis);
		(new \ReflectionProperty(RedisManager::class, 'lastPingCheck'))->setValue(null, time());
	}

	private function useStore(bool $rRedis): void {
		SettingsManager::set(['redis_handler' => $rRedis ? 1 : 0, 'save_closed_connection' => 1]);
		if (!$rRedis) {
			return;
		}
		if (self::$rRedisProc === null) {
			$this->markTestSkipped('redis-server, php-redis or igbinary is not installed');
		}
		$rClient = new \Redis();
		// Explicit timeouts: other suites set default_socket_timeout to 0.
		$rClient->connect('127.0.0.1', self::$rRedisPort, 2.0, null, 0, 2.0);
		$rClient->flushAll();
		$this->redis($rClient);
	}

	/** @return array<string, mixed> */
	private function upsert(string $rUUID, int $rLastRead = 1800000000, int $rEnd = 0): array {
		return ['type' => 'conn.upsert', 'd' => ['record' => [
			'uuid' => $rUUID, 'user_id' => 7, 'stream_id' => 100, 'user_ip' => '10.0.0.9', 'user_agent' => 'VLC', 'container' => 'hls',
			'pid' => 0, 'date_start' => 1799999000, 'hls_last_read' => $rLastRead, 'hls_end' => $rEnd,
		]]];
	}

	/** @return array<string, mixed> */
	private function remove(string $rUUID): array {
		return ['type' => 'conn.remove', 'd' => ['uuid' => $rUUID]];
	}

	/** @return array<string, mixed> */
	private function close(string $rUUID): array {
		return ['type' => 'conn.close', 'd' => ['uuid' => $rUUID]];
	}

	/** @param list<array<string, mixed>> $rEvents */
	private function ingest(int $rFirst, array $rEvents, ?array $rNode = null): array {
		return EventIngest::ingest($rNode ?? NodeRegistry::byServer(self::SID), 'p0', $rFirst, $rEvents);
	}

	/** What MAIN's store holds for the node: uuid => [hls_last_read, hls_end], plus how many records hold each uuid. */
	private function store(): array {
		$rOut = [];
		if (SettingsManager::get('redis_handler')) {
			foreach (StoredConnections::ofServer(self::SID, false) as $rRecord) {
				$rOut[(string) $rRecord['uuid']] = [(int) $rRecord['hls_last_read'], (int) $rRecord['hls_end'], 1];
			}
		} else {
			$this->rDb->query('SELECT `uuid`, MAX(`hls_last_read`) AS `r`, MAX(`hls_end`) AS `e`, COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ? GROUP BY `uuid`', self::SID);
			foreach ($this->rDb->get_rows() as $rRow) {
				$rOut[(string) $rRow['uuid']] = [(int) $rRow['r'], (int) $rRow['e'], (int) $rRow['n']];
			}
		}
		ksort($rOut);
		return $rOut;
	}

	/** Activity rows written by closes (ConnectionTracker::writeOfflineActivity). */
	private function activity(): int {
		return is_file(LOGS_TMP_PATH . 'activity') ? count(file(LOGS_TMP_PATH . 'activity', FILE_IGNORE_NEW_LINES) ?: []) : 0;
	}

	private function cursor(): int {
		return (int) NodeRegistry::byServer(self::SID)['useq_p0'];
	}

	#[DataProvider('stores')]
	public function testABatchResentAfterItsReplyWasLostAppliesOnce(bool $rRedis): void {
		$this->useStore($rRedis);
		$rBatch = [$this->upsert('aaaa'), $this->upsert('bbbb'), $this->close('aaaa'), $this->upsert('cccc'), $this->remove('cccc')];
		$this->assertSame(['ok' => true, 'useq' => 5, 'applied' => 5, 'dropped' => 0], $this->ingest(1, $rBatch));
		$rStore = $this->store();
		$this->assertSame(['bbbb' => [1800000000, 0, 1]], $rStore);
		$this->assertSame(1, $this->activity());

		$this->assertSame(['ok' => true, 'useq' => 5, 'applied' => 0, 'dropped' => 0], $this->ingest(1, $rBatch), 'the same batch again');
		// Its tail, or a batch that overlaps it and goes on: an agent resends
		// its batch in flight whole, so these are another install's
		// (ClusterApi quarantines the node). Nothing of them is applied.
		$this->assertSame(['ok' => false, 'useq' => 5, 'backwards' => true], $this->ingest(3, array_slice($rBatch, 2)), 'its tail');
		$rOverlap = [$this->upsert('cccc'), $this->remove('cccc'), $this->upsert('dddd')];
		$this->assertSame(['ok' => false, 'useq' => 5, 'backwards' => true], $this->ingest(4, $rOverlap), 'an overlap');
		$this->assertSame($rStore, $this->store(), 'aaaa is not re-opened, cccc not re-created');
		$this->assertSame(1, $this->activity(), 'one activity row for one close');
		// Past the cursor: a gap, and the node resends from the number MAIN expects.
		$this->assertSame(['ok' => false, 'useq' => 5, 'expected_useq' => 6], $this->ingest(8, [$this->upsert('dddd')]));
		$this->assertSame(1, $this->ingest(6, [$this->upsert('dddd')])['applied']);
		$this->assertSame(['bbbb', 'dddd'], array_keys($this->store()));
		$this->assertSame(6, $this->cursor());
	}

	#[DataProvider('stores')]
	public function testABatchResentWhileTheFirstCopyWasBeingAppliedAppliesOnce(bool $rRedis): void {
		$this->useStore($rRedis);
		// Both requests authenticated, and read the node's row, before either
		// batch was applied: the node's request timed out and it sent the
		// batch again while MAIN was still working on the first copy.
		$rNodeAsBothRequestsReadIt = NodeRegistry::byServer(self::SID);
		$rBatch = [$this->upsert('aaaa'), $this->close('aaaa'), $this->upsert('bbbb', 1800000010)];
		$this->assertSame(3, $this->ingest(1, $rBatch, $rNodeAsBothRequestsReadIt)['applied']);
		$this->assertSame(['ok' => true, 'useq' => 3, 'applied' => 0, 'dropped' => 0], $this->ingest(1, $rBatch, $rNodeAsBothRequestsReadIt));
		$this->assertSame(['bbbb' => [1800000010, 0, 1]], $this->store(), 'aaaa is not re-opened by the second copy');
		$this->assertSame(1, $this->activity(), 'and its close is not written twice');
		$this->assertSame(3, $this->cursor());
	}

	#[DataProvider('stores')]
	public function testTheSameEventTwiceUnderNewNumbersAppliesOnce(bool $rRedis): void {
		$this->useStore($rRedis);
		$this->ingest(1, [$this->upsert('aaaa')]);
		$this->rDb->query('SELECT `activity_id` FROM `lines_live` WHERE `uuid` = ?', 'aaaa');
		$rRowID = $this->rDb->get_rows()[0]['activity_id'] ?? null;
		$this->assertSame(1, $this->ingest(2, [$this->upsert('aaaa')])['applied'], 'accepted, as the node would otherwise resend it for ever');
		$this->assertSame(['aaaa' => [1800000000, 0, 1]], $this->store(), 'one record, not two');
		$this->ingest(3, [$this->upsert('aaaa', 1800000020)]);
		$this->assertSame(['aaaa' => [1800000020, 0, 1]], $this->store(), 'a later touch updates it in place');
		if (!$rRedis) {
			$this->rDb->query('SELECT `activity_id` FROM `lines_live` WHERE `uuid` = ?', 'aaaa');
			$this->assertSame($rRowID, $this->rDb->get_rows()[0]['activity_id']);
		}

		$this->assertSame(['ok' => true, 'useq' => 5, 'applied' => 2, 'dropped' => 0], $this->ingest(4, [$this->close('aaaa'), $this->close('aaaa')]));
		$this->assertSame(1, $this->ingest(6, [$this->close('aaaa')])['applied']);
		$this->assertSame([], $this->store());
		$this->assertSame(1, $this->activity(), 'one activity row however often the close comes');

		$this->ingest(7, [$this->upsert('bbbb'), $this->upsert('cccc'), $this->remove('bbbb')]);
		$this->assertSame(['ok' => true, 'useq' => 11, 'applied' => 2, 'dropped' => 0], $this->ingest(10, [$this->remove('bbbb'), $this->remove('bbbb')]));
		$this->assertSame(['cccc'], array_keys($this->store()), 'removing a removed viewer touches nobody else');
		$this->assertSame(1, $this->activity(), 'a remove writes no activity row');
	}

	public function testTheCursorIsReadAndMovedWhileTheLaneIsHeld(): void {
		$this->useStore(false);
		$rSeen = [];
		$this->hookedDb(function (string $rSql) use (&$rSeen): ?bool {
			if (str_contains($rSql, '`useq_p0`')) {
				$rSeen[] = [strtok($rSql, ' '), $this->laneHeld()];
			}
			return null;
		});
		$this->assertSame(2, $this->ingest(1, [$this->upsert('aaaa'), $this->close('aaaa')])['applied']);
		$this->assertSame([['SELECT', true], ['UPDATE', true]], $rSeen, 'a copy sent again waits, then reads the cursor this batch moved');
		$this->assertFalse($this->laneHeld(), 'and the lane is free once the batch is applied');
	}

	public function testABatchWaitsForTheOneBeingAppliedOnItsLane(): void {
		$this->useStore(false);
		@mkdir($this->rLockDir, 0750, true);
		// Another request is applying a batch of this node's P0 lane.
		$rChild = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; usleep(400000);', $this->rLockDir . self::SID . '_p0.lock'], [1 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rChild);
		$this->assertSame("held\n", fgets($rPipes[1]));
		$rStart = microtime(true);
		$this->assertSame(1, $this->ingest(1, [$this->upsert('aaaa')])['applied']);
		$this->assertGreaterThan(0.3, microtime(true) - $rStart, 'it waited for that request to finish');
		proc_close($rChild);
	}

	#[DataProvider('stores')]
	public function testABatchWhoseCursorWasNotWrittenFailsAndIsAppliedOnceWhenResent(bool $rRedis): void {
		$this->useStore($rRedis);
		// MAIN's database connection drops just as the batch's cursor is
		// written: DatabaseHandler::query() answers false inside a
		// transaction, and the transaction's writes are gone with it.
		$rFail = true;
		$this->hookedDb(static function (string $rSql) use (&$rFail): ?bool {
			if ($rFail && preg_match('/^UPDATE `cluster_nodes` SET `useq_p0` = \?/', $rSql)) {
				$rFail = false;
				return false;
			}
			return null;
		});
		$rBatch = [$this->upsert('aaaa'), $this->upsert('bbbb'), $this->close('aaaa')];
		$rOut = null;
		try {
			$rOut = $this->ingest(1, $rBatch);
		} catch (\RuntimeException) {
			// ClusterApi answers 503 DB, and the node sends the batch again.
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		// lines_live rolls back with the cursor. Redis has no transaction: the
		// batch's writes stay, and the resend makes the same store again.
		$this->assertSame($rRedis ? ['bbbb' => [1800000000, 0, 1]] : [], $this->store());
		$this->assertSame(0, $this->cursor());
		$this->assertSame(0, $this->activity(), 'the close\'s activity row waits for the batch to commit');

		$this->assertSame(['ok' => true, 'useq' => 3, 'applied' => 3, 'dropped' => 0], $this->ingest(1, $rBatch));
		$this->assertSame(['bbbb' => [1800000000, 0, 1]], $this->store());
		$this->assertSame(1, $this->activity(), 'the resent close writes it once');
		$this->assertSame(['ok' => true, 'useq' => 3, 'applied' => 0, 'dropped' => 0], $this->ingest(1, $rBatch));
	}
}
