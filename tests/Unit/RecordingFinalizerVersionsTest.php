<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A finished recording's stream versions and the event batch it arrives in
 * (ADR 0004: a batch counts as applied only with its cursor).
 *
 * RecordingFinalizer::finish() dispatched its StreamsChangedEvent inside the
 * batch's transaction. StreamVersions' bump joined that transaction and
 * swallowed its own failure: a deadlock there rolled back the whole batch on
 * the server, the batch went on, its cursor autocommitted, and the events
 * before it were lost for good. The change is now dispatched once the batch
 * committed, and a bump inside a caller's transaction throws to the caller.
 */
final class RecordingFinalizerVersionsTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int, `movie_properties` text, `tv_archive_server_id` int, `tv_archive_pid` int, `vframes_server_id` int, `vframes_pid` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `parent_id` int, `pid` int, `to_analyze` int, `stream_status` int, `progress_info` text, `bitrate` int)');
		$this->rDb->exec('CREATE TABLE `recordings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `created_id` int, `source_id` int, `status` int DEFAULT 0)');
		$this->rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		// Stream 100 runs on the node; recording 1 of it made VOD 300, which the node converted.
		$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `tv_archive_server_id`, `vframes_server_id`) VALUES (100, 1, 0, 0), (300, 2, 0, 0)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, 5, 0)');
		$this->rDb->query('INSERT INTO `recordings` (`id`, `stream_id`, `created_id`, `source_id`, `status`) VALUES (1, 100, 300, 5, 1)');
		DatabaseFactory::set($this->rDb);
		foreach ([RecordingFinalizer::class, EventIngest::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(self::SID, '77777777-7777-4777-a777-777777777777', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT]);
		EventIngest::onStreamChanged(static function (int $rID): void {
		});
		EventDispatcher::resetInstance();
		EventDispatcher::subscribe(StreamVersions::class);
	}

	protected function tearDown(): void {
		EventDispatcher::resetInstance();
		EventIngest::onStreamChanged(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/**
	 * MAIN's database, on which the bump's REPLACE fails as a deadlock fails
	 * it: InnoDB rolls back the whole transaction open on the connection
	 * (the handler still thinks it open) and Database::query() answers false.
	 * $rSeen gets the node's cursor as it stands when the REPLACE runs.
	 */
	private function deadlockOnBump(?int &$rSeen = null): QueryLogDb {
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `cluster_stream_ver`/';
		$rLog->rBefore = function (string $rQuery) use (&$rSeen): void {
			if (str_starts_with($rQuery, 'REPLACE INTO `cluster_stream_ver`')) {
				$rSeen = (int) $this->rDb->pdo->query('SELECT `useq_p0` FROM `cluster_nodes` WHERE `server_id` = ' . self::SID)->fetchColumn();
				if ($this->rDb->pdo->inTransaction()) {
					$this->rDb->pdo->rollBack(); // the deadlock's victim: the whole transaction
				}
			}
		};
		DatabaseFactory::set($rLog);
		return $rLog;
	}

	private function val(string $rSql): mixed {
		return $this->rDb->pdo->query($rSql)->fetchColumn();
	}

	/** @return array<string, int> "server:stream" => ver */
	private function rows(): array {
		$rOut = [];
		foreach ($this->rDb->pdo->query('SELECT `server_id`, `stream_id`, `ver` FROM `cluster_stream_ver` ORDER BY `server_id`, `stream_id`')->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
			$rOut[$rRow['server_id'] . ':' . $rRow['stream_id']] = (int) $rRow['ver'];
		}
		return $rOut;
	}

	/** A stream's state, then the recording done: a P0 batch of two. */
	private function ingest(): array {
		return EventIngest::ingest(NodeRegistry::byServer(self::SID), 'p0', 1, [
			['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['pid' => 42]]],
			['type' => 'recording.state', 'd' => ['id' => 1, 'status' => RecordingFinalizer::DONE]],
		]);
	}

	public function testABumpThatDeadlocksNeverLosesTheBatch(): void {
		$rSeen = null;
		$this->deadlockOnBump($rSeen);
		$rOut = null;
		try {
			$rOut = $this->ingest();
		} catch (\Throwable $rE) {
			$this->fail('the batch failed after its cursor moved: ' . $rE->getMessage());
		}
		$this->assertSame(['ok' => true, 'useq' => 2, 'applied' => 2, 'dropped' => 0], $rOut);
		$this->assertSame(2, $rSeen, 'the bump ran once the batch and its cursor committed, outside its transaction');
		$this->assertSame(2, (int) $this->val('SELECT `useq_p0` FROM `cluster_nodes` WHERE `server_id` = 5'));
		$this->assertSame(42, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'), 'the event before the recording kept');
		$this->assertSame(2, (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'));
		$this->assertSame(1, (int) $this->val('SELECT COUNT(*) FROM `streams_servers` WHERE `stream_id` = 300 AND `server_id` = 5 AND `pid` = 1 AND `to_analyze` = 1'));
		$this->assertSame([], $this->rows(), 'the version is left to the section hashes');
	}

	public function testTheBatchStampsTheRecordingsStreamsOnceCommitted(): void {
		$this->assertSame(['ok' => true, 'useq' => 2, 'applied' => 2, 'dropped' => 0], $this->ingest());
		$this->assertSame(['5:100' => 2, '5:300' => 3], $this->rows(), 'the VOD and the recorded stream, for the node');
		$this->assertFalse($this->rDb->isInTransaction());
	}

	public function testABatchThatFailsDispatchesNothing(): void {
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^UPDATE `cluster_nodes` SET `useq_p0`/';
		DatabaseFactory::set($rLog);
		$rOut = null;
		try {
			$rOut = $this->ingest();
		} catch (\RuntimeException) {
			// 503 DB: the node sends the batch again.
		}
		$this->assertNull($rOut);
		$this->assertSame([], $this->rows(), 'no version for a batch that was not kept');
		$this->assertSame(1, (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'));
	}

	/**
	 * Inside a caller's open transaction a bump that fails throws: a deadlock
	 * rolled the caller's transaction back, and a caller told nothing would
	 * go on to autocommit its next statements.
	 */
	public function testABumpThatFailsInsideTheCallersTransactionThrows(): void {
		$this->deadlockOnBump();
		$this->assertTrue($this->rDb->beginTransaction());
		$this->rDb->query('UPDATE `streams_servers` SET `pid` = 42 WHERE `server_stream_id` = 11');
		$rThrown = null;
		try {
			EventDispatcher::dispatch(new StreamsChangedEvent([100]));
		} catch (\RuntimeException $rE) {
			$rThrown = $rE->getMessage();
		}
		$this->assertSame('stream versions: a statement failed', $rThrown, 'never swallowed');
		$this->assertTrue($this->rDb->isInTransaction(), 'the caller rolls back its own transaction');
		try {
			$this->rDb->rollback();
		} catch (\PDOException) {
			// MySQL already rolled it back (the deadlock).
		}
		$this->assertSame(0, (int) $this->val('SELECT `pid` FROM `streams_servers` WHERE `server_stream_id` = 11'));
		$this->assertSame([], $this->rows());

		// So does a counter that could not be created there.
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^INSERT INTO `cluster_meta`/';
		$this->assertTrue($this->rDb->beginTransaction());
		$rThrown = null;
		try {
			StreamVersions::bump([100], $rLog);
		} catch (\RuntimeException $rE) {
			$rThrown = $rE->getMessage();
		}
		$this->assertSame('stream versions: the counter could not be created', $rThrown, 'never taken for another bump\'s');
		$this->rDb->rollback();
	}

	/** A writer outside any transaction: the bump is its own, and never fails the change. */
	public function testOutsideABatchNothingChanges(): void {
		$this->deadlockOnBump();
		$this->assertTrue(RecordingFinalizer::finish(1, self::SID));
		$this->assertSame(2, (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'));
		$this->assertSame([], $this->rows());
		$this->assertSame(0, StreamVersions::bump([100]));

		DatabaseFactory::set($this->rDb);
		$this->rDb->query('UPDATE `recordings` SET `status` = 1 WHERE `id` = 1');
		$this->assertTrue(RecordingFinalizer::finish(1, self::SID));
		$this->assertSame(['5:100' => 2, '5:300' => 3], $this->rows(), 'dispatched at once, as before');
	}
}
