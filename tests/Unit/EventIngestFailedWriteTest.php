<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A P0 or P1 batch counts as applied only with its cursor (ADR 0004), so a
 * statement of it that fails fails the whole batch: MAIN answers 503 DB, and
 * the node sends it again.
 *
 * Database::query() answers a failed statement with false, and an InnoDB
 * deadlock rolls back the batch's whole transaction. The writers ignored that
 * false (or took it as an event refused, dropped and counted), so the
 * statements after it autocommitted, the cursor among them: the events
 * applied before the deadlock were lost, and the node never sent them again.
 *
 * Each case fails one statement of a batch as a deadlock does (the
 * transaction rolled back, false), and checks that the cursor did not move,
 * that nothing of the batch was kept, and that the batch sent again applies
 * whole. A snapshot's record is the exception the ADR keeps: dropped, not
 * failed. What an event writes besides its rows (audit, change log, cache
 * signal) swallows its own failure, so it runs once the batch committed.
 */
final class EventIngestFailedWriteTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	/** The database the code under test sees (failingDb()). */
	private object $rFailing;

	/** @var list<int> Streams whose cache the batches signalled. */
	private array $rChanged = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) preg_replace(['/^--.*$/m', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['', '', '', ');'], (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql')));
		$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) preg_replace(['/`activity_id` int\(11\) NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`activity_id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)( USING BTREE)?/', '/ COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'], $rM[0]));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY, `type` int, `movie_properties` text, `tv_archive_server_id` int, `tv_archive_pid` int, `vframes_server_id` int, `vframes_pid` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY, `stream_id` int, `server_id` int, `parent_id` int, `pid` int, `to_analyze` int, `stream_status` int, `bitrate` int)');
		$this->rDb->exec('CREATE TABLE `recordings` (`id` INTEGER PRIMARY KEY, `stream_id` int, `created_id` int, `source_id` int, `status` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `ip` varchar(39) UNIQUE, `notes` text, `date` int)');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `server_ip` varchar(64), `private_ip` varchar(64), `whitelist_ips` text, `governor` text, `ping` int DEFAULT 0, `time_offset` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `streams_logs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `stream_id` int, `server_id` int, `action` varchar(500), `source` varchar(1024), `date` int)');
		$this->rDb->exec('CREATE TABLE `lines_divergence` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `uuid` varchar(32) UNIQUE, `divergence` float)');
		$this->rDb->exec('CREATE TABLE `lines_activity` (`activity_id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` int, `stream_id` int, `server_id` int, `proxy_id` int, `user_agent` varchar(255), `user_ip` varchar(39), `container` varchar(50), `date_start` int, `date_end` int, `geoip_country_code` varchar(22), `isp` varchar(255), `external_device` varchar(255), `divergence` float DEFAULT 0, `hmac_id` int, `hmac_identifier` varchar(255))');
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY, `last_ip` varchar(39), `last_activity` int, `last_activity_array` text, `updated` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `cluster_changes` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `section` varchar(32), `op` varchar(8), `kind` varchar(16), `value` varchar(255), `time` int)');
		$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `movie_properties`, `tv_archive_server_id`) VALUES (100, 1, NULL, 5), (200, 2, \'{"name":"Film"}\', NULL), (300, 2, NULL, NULL)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`, `bitrate`) VALUES (11, 100, 5, 0, 8000), (20, 200, 5, 1, 0)');
		$this->rDb->query('INSERT INTO `recordings` (`id`, `stream_id`, `created_id`, `source_id`, `status`) VALUES (1, 100, NULL, 5, 1), (2, 100, 300, 5, 1)');
		$this->rDb->query('INSERT INTO `lines` (`id`) VALUES (7)');
		$this->rDb->query('INSERT INTO `servers` (`id`, `server_ip`) VALUES (1, \'198.51.100.1\'), (5, \'198.51.100.5\')');
		$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `stream_id`, `container`, `hls_last_read`, `hls_end`, `divergence`) VALUES (\'cccc\', 5, 7, 100, \'ts\', 1, 0, 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0, 'save_closed_connection' => 0, 'allowed_ips_admin' => '']);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(self::SID, '66666666-6666-4666-a666-666666666666', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT | NodeRegistry::FLOW_CONFIG | NodeRegistry::FLOW_TELEMETRY | NodeRegistry::FLOW_CONNECTIONS | NodeRegistry::FLOW_LOGS]);
		EventIngest::onStreamChanged(function (int $rID): void {
			$this->rChanged[] = $rID;
		});
	}

	protected function tearDown(): void {
		EventIngest::onStreamChanged(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	/**
	 * MAIN's database, on which a statement matching $rFail fails as a
	 * deadlock fails it: InnoDB rolls back the whole transaction and
	 * Database::query() answers false. What runs after it autocommits, as it
	 * would on MySQL. $rFail null: nothing fails. With $rFailCommit the commit
	 * fails instead (false, the transaction gone).
	 */
	private function failingDb(?string $rFail, bool $rFailCommit = false, bool $rFailBegin = false): object {
		$this->rFailing = new class ($this->rDb, $rFail, $rFailCommit, $rFailBegin) extends DatabaseHandler {
			/** @var list<string> */
			public array $rRan = [];

			/** False: the statement fails alone, as a read does (no transaction goes with it). */
			public bool $rRollsBack = true;

			/** Statements matching $rFail that still run before one fails. */
			public int $rSkip = 0;

			public function __construct(private TestDb $rInner, public ?string $rFail, public bool $rFailCommit, public bool $rFailBegin) {
			}

			public function query($query, ...$args): bool {
				$this->rRan[] = (string) $query;
				if ($this->rFail !== null && preg_match($this->rFail, (string) $query) && $this->rSkip-- <= 0) {
					if ($this->rRollsBack && $this->rInner->pdo->inTransaction()) {
						$this->rInner->pdo->rollBack(); // the deadlock's victim: the whole transaction
					}
					return false;
				}
				return $this->rInner->query($query, ...$args);
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

			public function last_insert_id() {
				return $this->rInner->last_insert_id();
			}

			public function beginTransaction() {
				return !$this->rFailBegin && $this->rInner->pdo->beginTransaction();
			}

			public function commit() {
				if ($this->rFailCommit) {
					$this->rInner->pdo->rollBack();
					return false;
				}
				return $this->rInner->pdo->commit();
			}

			// PDO refuses a rollback of a transaction MySQL already rolled
			// back, as it does here: EventIngest must keep its own failure.
			public function rollback() {
				return $this->rInner->pdo->rollBack();
			}
		};
		DatabaseFactory::set($this->rFailing);
		return $this->rFailing;
	}

	/** @return list<array<string, mixed>> P0: one event of each writer, all the node's own */
	private function p0(): array {
		return [
			['type' => 'stream.state', 'd' => ['stream_id' => 100, 'fields' => ['pid' => 42]]],
			['type' => 'stream.worker', 'd' => ['stream_id' => 100, 'worker' => 'tv_archive', 'pid' => 4242]],
			['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 3]],
			['type' => 'recording.state', 'd' => ['id' => 2, 'status' => 2]],
			['type' => 'vod.analysis', 'd' => ['stream_id' => 200, 'props' => ['duration_secs' => 99]]],
			['type' => 'security.block_ip', 'd' => ['ip' => '203.0.113.9', 'reason' => 'FLOOD ATTACK']],
			['type' => 'node.state', 'd' => ['fields' => ['governor' => 'performance']]],
			['type' => 'conn.upsert', 'd' => ['record' => ['uuid' => 'aaaa', 'user_id' => 7, 'stream_id' => 100, 'container' => 'ts', 'date_start' => 1799999000, 'hls_last_read' => 1, 'hls_end' => 0]]],
			['type' => 'conn.upsert', 'd' => ['record' => ['uuid' => 'bbbb', 'user_id' => 8, 'stream_id' => 100, 'container' => 'ts', 'date_start' => 1799999000, 'hls_last_read' => 1, 'hls_end' => 0]]],
			['type' => 'conn.remove', 'd' => ['uuid' => 'bbbb']],
			['type' => 'conn.close', 'd' => ['uuid' => 'cccc']],
		];
	}

	/** @return list<array<string, mixed>> P1: log rows, an inventory and a divergence report */
	private function p1(): array {
		return [
			['type' => 'log.stream', 'd' => ['rows' => [['stream_id' => 100, 'action' => 'start', 'source' => 'http://origin/1', 'date' => 1]]]],
			['type' => 'log.activity', 'd' => ['rows' => [['user_id' => 7, 'stream_id' => 100, 'user_ip' => '192.0.2.7', 'date_start' => 1, 'date_end' => 2, 'container' => 'ts']]]],
			['type' => 'node.inventory', 'd' => ['fields' => ['ping' => 3]]],
			['type' => 'conn.divergence', 'd' => ['rows' => [['uuid' => 'cccc', 'rate' => 460]]]],
		];
	}

	/** Every table a batch writes, as it is now. */
	private function state(): array {
		$rOut = [];
		foreach (['streams', 'streams_servers', 'recordings', 'blocked_ips', 'servers', 'streams_logs', 'lines_divergence', 'lines_activity', 'lines', 'cluster_audit', 'cluster_changes'] as $rTable) {
			$this->rDb->query('SELECT * FROM `' . $rTable . '` ORDER BY 1');
			$rOut[$rTable] = $this->rDb->get_rows();
		}
		$this->rDb->query('SELECT `uuid`, `server_id`, `hls_end`, `divergence` FROM `lines_live` ORDER BY `uuid`');
		$rOut['lines_live'] = $this->rDb->get_rows();
		$this->rDb->query('SELECT `useq_p0`, `useq_p1` FROM `cluster_nodes` WHERE `server_id` = ?', self::SID);
		$rOut['cursors'] = array_map('intval', $this->rDb->get_row());
		return $rOut;
	}

	/** @param list<array<string, mixed>> $rEvents */
	private function ingest(string $rLane, array $rEvents): array {
		return EventIngest::ingest(NodeRegistry::byServer(self::SID), $rLane, 1, $rEvents);
	}

	/** @return array<string, array{0: string, 1?: int}> a statement of the P0 batch (past how many alike), and what fails with it */
	public static function p0Statements(): array {
		return [
			'stream.state: its UPDATE' => ['/^UPDATE `streams_servers` SET `pid`/'],
			'stream.worker: its owner check' => ['/^SELECT COUNT\(\*\) AS `n` FROM `streams`/'],
			'stream.worker: its UPDATE' => ['/^UPDATE `streams` SET `tv_archive_pid`/'],
			'recording.state: its UPDATE' => ['/^UPDATE `recordings`/'],
			'recording.state done: its recording' => ['/^SELECT `created_id`, `stream_id` FROM `recordings`/'],
			'recording.state done: the node\'s VOD row' => ['/^SELECT COUNT\(\*\) AS `n` FROM `streams_servers`/'],
			'recording.state done: its INSERT INTO streams_servers' => ['/^INSERT INTO `streams_servers`/'],
			'recording.state done: its UPDATE' => ['/^UPDATE `recordings`/', 1],
			'vod.analysis: its UPDATE' => ['/^UPDATE `streams` SET `movie_properties`/'],
			'security.block_ip: the servers it never blocks' => ['/^SELECT `server_ip`/'],
			'security.block_ip: its INSERT' => ['/^INSERT INTO `blocked_ips`/'],
			'node.state: its UPDATE' => ['/^UPDATE `servers`/'],
			'conn.upsert: its read' => ['/^SELECT `activity_id`, `server_id` FROM `lines_live`/'],
			'conn.upsert: its INSERT' => ['/^INSERT INTO `lines_live`/'],
			'conn.remove and conn.close: the DELETE' => ['/^DELETE FROM `lines_live`/'],
			'conn.close: its read' => ['/^SELECT \* FROM `lines_live`/'],
			'the cursor' => ['/^UPDATE `cluster_nodes` SET `useq_p0`/'],
		];
	}

	#[DataProvider('p0Statements')]
	public function testAP0StatementThatFailsFailsTheBatchAndLeavesTheCursor(string $rFail, int $rSkip = 0): void {
		$rBefore = $this->state();
		$rDb = $this->failingDb($rFail);
		$rDb->rSkip = $rSkip;
		$rOut = null;
		try {
			$rOut = $this->ingest('p0', $this->p0());
		} catch (\RuntimeException) {
			// ClusterApi answers 503 DB, and the node sends the batch again.
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		$this->assertSame($rBefore, $this->state(), 'the cursor did not move, and nothing of the batch was kept');
		$this->assertMatchesRegularExpression($rFail, (string) end($rDb->rRan), 'nothing ran after the statement that failed');
		$this->assertSame([], $this->rChanged, 'no cache signal for a batch that was not kept');

		$rDb->rFail = null;
		$this->assertSame(['ok' => true, 'useq' => 11, 'applied' => 11, 'dropped' => 0], $this->ingest('p0', $this->p0()), 'sent again, it applies whole');
		$rAfter = $this->state();
		$this->assertSame(11, $rAfter['cursors']['useq_p0']);
		$this->assertSame([3, 2], array_map('intval', array_column($rAfter['recordings'], 'status')), 'the one failed, the other done');
		$this->assertSame([[300, 5]], array_map(static fn(array $rRow): array => [(int) $rRow['stream_id'], (int) $rRow['server_id']], array_values(array_filter($rAfter['streams_servers'], static fn(array $rRow): bool => (int) $rRow['stream_id'] === 300))), 'and its VOD on the node');
		$this->assertSame(['security.block_ip'], array_column($rAfter['cluster_audit'], 'event'), 'audited once, for the batch kept');
		$this->assertSame(['203.0.113.9'], array_column($rAfter['cluster_changes'], 'value'));
		$this->assertSame(['aaaa'], array_column($rAfter['lines_live'], 'uuid'));
		$this->assertSame(['203.0.113.9'], array_column($rAfter['blocked_ips'], 'ip'));
		$this->assertSame([42, 4242, 'performance'], [(int) $rAfter['streams_servers'][0]['pid'], (int) $rAfter['streams'][0]['tv_archive_pid'], $rAfter['servers'][1]['governor']]);
		$this->assertSame(['name' => 'Film', 'duration_secs' => 99], json_decode((string) $rAfter['streams'][1]['movie_properties'], true));
	}

	/** @return array<string, array{0: string}> */
	public static function p1Statements(): array {
		return [
			'log.<type>: its INSERT' => ['/^INSERT INTO `streams_logs`/'],
			'log.activity: its INSERT' => ['/^INSERT INTO `lines_activity`/'],
			'log.activity: the UPDATE `lines`' => ['/^UPDATE `lines` SET `last_ip`/'],
			'node.inventory: its UPDATE' => ['/^UPDATE `servers`/'],
			'conn.divergence: the node\'s bitrates' => ['/^SELECT `stream_id`, `bitrate` FROM `streams_servers`/'],
			'conn.divergence: its REPLACE' => ['/^REPLACE INTO `lines_divergence`/'],
			'conn.divergence: the store\'s divergence' => ['/^UPDATE `lines_live` SET `divergence`/'],
			'the cursor' => ['/^UPDATE `cluster_nodes` SET `useq_p1`/'],
		];
	}

	#[DataProvider('p1Statements')]
	public function testAP1StatementThatFailsFailsTheBatchAndLeavesTheCursor(string $rFail): void {
		$rBefore = $this->state();
		$rDb = $this->failingDb($rFail);
		$rOut = null;
		try {
			$rOut = $this->ingest('p1', $this->p1());
		} catch (\RuntimeException) {
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		$this->assertSame($rBefore, $this->state());
		$this->assertMatchesRegularExpression($rFail, (string) end($rDb->rRan), 'nothing ran after the statement that failed');

		$rDb->rFail = null;
		$this->assertSame(['ok' => true, 'useq' => 4, 'applied' => 4, 'dropped' => 0], $this->ingest('p1', $this->p1()));
		$rAfter = $this->state();
		$this->assertSame(['start'], array_column($rAfter['streams_logs'], 'action'));
		$this->assertSame(['192.0.2.7'], array_column($rAfter['lines_activity'], 'user_ip'));
		$this->assertSame(['192.0.2.7', (int) $rAfter['lines_activity'][0]['activity_id']], [$rAfter['lines'][0]['last_ip'], (int) $rAfter['lines'][0]['last_activity']]);
		$this->assertSame(3, (int) $rAfter['servers'][1]['ping']);
		$this->assertSame(['cccc'], array_column($rAfter['lines_divergence'], 'uuid'));
	}

	/**
	 * The ADR's one exception: a divergence report whose store cannot be read
	 * is dropped, and the lane's logs are not held up for it. A read changes
	 * nothing, so the rest of the batch stands.
	 */
	public function testADivergenceReportWhoseStoreCannotBeReadIsDropped(): void {
		$this->failingDb('/^SELECT `uuid`, `activity_id`, `stream_id` FROM `lines_live`/')->rRollsBack = false;
		$this->assertSame(['ok' => true, 'useq' => 4, 'applied' => 3, 'dropped' => 1], $this->ingest('p1', $this->p1()));
		$rAfter = $this->state();
		$this->assertSame(['start'], array_column($rAfter['streams_logs'], 'action'));
		$this->assertSame([], $rAfter['lines_divergence']);
	}

	/**
	 * The audit line, the blocklist's change log and the cache signal each
	 * swallow their own failure: inside the batch's transaction a deadlock
	 * on one would have rolled the batch back unseen. After the commit one
	 * that fails costs only itself.
	 */
	public function testWhatFollowsTheBatchRunsAfterItsCommitAndCannotLoseIt(): void {
		$rDb = $this->failingDb('/^INSERT INTO `(cluster_audit|cluster_changes)`/');
		$rCommitted = null;
		EventIngest::onStreamChanged(function (int $rID) use (&$rCommitted): void {
			$rCommitted ??= !$this->rDb->pdo->inTransaction();
			$this->rChanged[] = $rID;
			throw new \RuntimeException('the cache signal failed');
		});
		$this->assertSame(['ok' => true, 'useq' => 11, 'applied' => 11, 'dropped' => 0], $this->ingest('p0', $this->p0()));
		$rAfter = $this->state();
		$this->assertSame(11, $rAfter['cursors']['useq_p0']);
		$this->assertSame(['203.0.113.9'], array_column($rAfter['blocked_ips'], 'ip'), 'the block stands');
		$this->assertSame([[], []], [$rAfter['cluster_audit'], $rAfter['cluster_changes']]);
		$this->assertTrue($rCommitted, 'the cache signal follows the commit');
		$this->assertSame([100, 100, 200], $this->rChanged);
		$this->assertMatchesRegularExpression('/^INSERT INTO `cluster_audit`/', (string) end($rDb->rRan), 'the audit ran last, after the cursor');

		$rDb->rFail = null;
		$this->assertSame(['ok' => true, 'useq' => 12, 'applied' => 1, 'dropped' => 0], EventIngest::ingest(NodeRegistry::byServer(self::SID), 'p0', 12, [['type' => 'security.block_ip', 'd' => ['ip' => '203.0.113.10', 'reason' => 'FLOOD ATTACK']]]));
		$rAfter = $this->state();
		$this->assertSame(['security.block_ip'], array_column($rAfter['cluster_audit'], 'event'));
		$this->assertSame(['203.0.113.10'], array_column($rAfter['cluster_changes'], 'value'));
	}

	public function testACommitThatFailsFailsTheBatch(): void {
		$rBefore = $this->state();
		$this->failingDb(null, true);
		$rOut = null;
		try {
			$rOut = $this->ingest('p0', $this->p0());
		} catch (\RuntimeException) {
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		$this->assertSame($rBefore, $this->state());
	}

	public function testABatchWithoutItsTransactionIsNotApplied(): void {
		$rBefore = $this->state();
		$rDb = $this->failingDb(null, false, true);
		$rOut = null;
		try {
			$rOut = $this->ingest('p0', $this->p0());
		} catch (\RuntimeException) {
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		$this->assertSame($rBefore, $this->state());
		$this->assertStringContainsString('`useq_p0` AS `useq`', (string) end($rDb->rRan), 'nothing ran after the cursor was read');
	}

	/**
	 * A snapshot's record (ConnectionSnapshot) that MAIN cannot write is
	 * dropped, not failed (ADR 0004): a record the database rejects would
	 * otherwise fail every snapshot the node sends.
	 */
	public function testASnapshotsRecordMainCannotWriteIsDroppedNotFailed(): void {
		$rDb = $this->failingDb('/^(INSERT INTO|DELETE FROM) `lines_live`/');
		$this->assertFalse(ConnectionIngest::upsert(self::SID, ['uuid' => 'dddd', 'user_id' => 7, 'stream_id' => 100]));
		$this->assertFalse(ConnectionIngest::remove(self::SID, 'cccc'));
		$rDb->rFail = null;
		$this->assertTrue(ConnectionIngest::upsert(self::SID, ['uuid' => 'dddd', 'user_id' => 7, 'stream_id' => 100]));
	}
}
