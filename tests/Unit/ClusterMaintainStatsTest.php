<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterMaintainStatsCommand;
use XcVm\Cli\CronJobs\CleanupCronJob;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * `servers_stats` retention in batches (CleanupCronJob::prune) and the
 * indexes cluster:maintain-stats builds online (plan, section 8), on it and on
 * the log tables retention and the log pages read by date.
 */
final class ClusterMaintainStatsTest extends TestCase {
	public function testThePruneDeletesInBatchesAndStopsAtItsDeadline(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `servers_stats` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `time` int)');
		// Batches, not a recursive CTE: MariaDB stops one at max_recursive_iterations (1000) without an error.
		foreach (array_chunk(range(1, 25003), 1000) as $rChunk) {
			$rDb->exec('INSERT INTO `servers_stats` (`server_id`, `time`) VALUES ' . implode(', ', array_map(static fn(int $i): string => '(' . ($i % 7) . ', 100)', $rChunk)));
		}
		$rDb->exec('INSERT INTO `servers_stats` (`server_id`, `time`) VALUES (1, 900), (2, 900)');

		$this->assertSame(10000, CleanupCronJob::prune($rDb, 'servers_stats', 500, 0.0), 'past its deadline: one batch, the next run goes on');
		$this->assertSame(15003, CleanupCronJob::prune($rDb, 'servers_stats', 500, microtime(true) + 60), 'the rest, batch after batch');
		$rDb->query('SELECT COUNT(*) AS `n` FROM `servers_stats`');
		$this->assertSame(2, (int) $rDb->get_row()['n'], 'the rows retention keeps');
		$this->assertSame(0, CleanupCronJob::prune($rDb, 'servers_stats', 500, microtime(true) + 60));
	}

	public function testOnlyTheMissingIndexesAreBuiltAndNeverWithALock(): void {
		$rDb = $this->mysql([['PRIMARY', 1, 'id']]);
		$this->assertSame(ClusterMaintainStatsCommand::INDEXES, ClusterMaintainStatsCommand::missing($rDb));
		$this->assertTrue(ClusterMaintainStatsCommand::build($rDb, 'server_time', ['server_id', 'time']));
		$this->assertSame('ALTER TABLE `servers_stats` ADD INDEX `server_time` (`server_id`, `time`), ALGORITHM=INPLACE, LOCK=NONE;', end($rDb->rQueries));

		// An operator's own index that starts with the same columns counts.
		$rDb = $this->mysql([['PRIMARY', 1, 'id'], ['mine', 2, 'time'], ['mine', 1, 'server_id'], ['mine', 3, 'bytes_sent']]);
		$this->assertSame(['time' => ['time']], ClusterMaintainStatsCommand::missing($rDb));
		$rDb = $this->mysql([['PRIMARY', 1, 'id'], ['time', 1, 'time'], ['server_time', 1, 'server_id'], ['server_time', 2, 'time']]);
		$this->assertSame([], ClusterMaintainStatsCommand::missing($rDb));
		$this->assertSame([], ClusterMaintainStatsCommand::missing($this->mysql(null)), 'no answer: nothing to start');
	}

	public function testTheLogTablesGetTheirIndexOnTheDate(): void {
		$rDb = $this->mysql([['PRIMARY', 1, 'id']]);
		$this->assertSame(['owner_date' => ['owner', 'date']], ClusterMaintainStatsCommand::missing($rDb, 'users_logs', ClusterMaintainStatsCommand::LOG_INDEXES['users_logs']));
		$rDb = $this->mysql([['PRIMARY', 1, 'id'], ['mine', 1, 'owner'], ['mine', 2, 'date']]);
		$this->assertSame([], ClusterMaintainStatsCommand::missing($rDb, 'users_logs', ClusterMaintainStatsCommand::LOG_INDEXES['users_logs']));
		$this->assertTrue(ClusterMaintainStatsCommand::build($rDb, 'date', ['date'], 'lines_logs'));
		$this->assertSame('ALTER TABLE `lines_logs` ADD INDEX `date` (`date`), ALGORITHM=INPLACE, LOCK=NONE;', end($rDb->rQueries));
	}

	public function testEveryPendingIndexIsBuiltAndRetentionThenUsesIt(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `servers_stats` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `time` int)');
		foreach (array_keys(ClusterMaintainStatsCommand::LOG_INDEXES) as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$rLog = new QueryLogDb($rDb);
		$this->assertSame(['servers_stats', 'lines_logs', 'login_logs', 'streams_logs', 'streams_errors', 'ondemand_check', 'users_logs'], array_keys(ClusterMaintainStatsCommand::pending($rLog)));

		ob_start();
		$rFailed = ClusterMaintainStatsCommand::buildPending($rLog);
		ob_end_clean();

		$this->assertSame(0, $rFailed);
		$this->assertSame([], ClusterMaintainStatsCommand::pending($rLog));
		$rWrites = array_values(array_filter($rLog->rQueries, static fn(string $rQuery): bool => !str_starts_with($rQuery, 'SHOW')));
		$this->assertSame('SET SESSION lock_wait_timeout = 5;', $rWrites[0], 'before the first ALTER');
		$rPlan = $rDb->pdo->query('EXPLAIN DELETE FROM `lines_logs` WHERE `date` < 1000')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame('date', $rPlan['key']);
	}

	/**
	 * A scripted SHOW INDEX: the index states a test needs.
	 *
	 * @param list<array{0: string, 1: int, 2: string}>|null $rIndexes [Key_name, Seq_in_index, Column_name]; null fails
	 */
	private function mysql(?array $rIndexes): object {
		return new class ($rIndexes) {
			/** @var list<string> */
			public array $rQueries = [];

			/** @param list<array{0: string, 1: int, 2: string}>|null $rIndexes */
			public function __construct(private ?array $rIndexes) {
			}

			public function query(string $rSql): bool {
				$this->rQueries[] = $rSql;
				return $this->rIndexes !== null;
			}

			/** @return list<array<string, mixed>> */
			public function get_rows(): array {
				return array_map(static fn(array $rRow): array => ['Key_name' => $rRow[0], 'Seq_in_index' => $rRow[1], 'Column_name' => $rRow[2]], $this->rIndexes ?? []);
			}
		};
	}
}
