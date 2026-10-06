<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ClusterMaintainStatsCommand — build the indexes `servers_stats` and the log
 * tables are read and pruned by, online (plan, section 8, "MAIN capacity").
 * The log tables had none on the date retention deletes by and their pages
 * sort by.
 *
 * The table had its primary key alone: retention's DELETE by `time` and the
 * per-server reads by `server_id` and `time` scanned every row, one per node
 * per minute. A migration would build the indexes inside the update, on a
 * table that can hold a year of rows, so they are built here instead,
 * without locking the table (`ALGORITHM=INPLACE, LOCK=NONE`). cron:cleanup
 * starts it while one is missing; one run at a time.
 *
 * Usage: `console.php cluster:maintain-stats`
 *
 * Exit code 0 when every index exists, 1 otherwise.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterMaintainStatsCommand implements CommandInterface {
	use DatabaseAware;

	/** Name => leading columns. An index that starts with the same columns counts. */
	public const INDEXES = ['time' => ['time'], 'server_time' => ['server_id', 'time']];

	/** Table => name => leading columns: the log tables, by the date retention and their pages use. */
	public const LOG_INDEXES = ['lines_logs' => ['date' => ['date']], 'login_logs' => ['date' => ['date']], 'streams_logs' => ['date' => ['date']], 'streams_errors' => ['date' => ['date']], 'ondemand_check' => ['date' => ['date']], 'users_logs' => ['owner_date' => ['owner', 'date']]];

	public function getName(): string {
		return 'cluster:maintain-stats';
	}

	public function getDescription(): string {
		return 'Build the servers_stats and log table indexes online';
	}

	public function execute(array $rArgs): int {
		if (!NodeRole::isMain()) {
			echo "Run this on MAIN.\n";
			return 1;
		}
		$rLock = @fopen(TMP_PATH . 'cluster_maintain_stats.lock', 'c');
		if ($rLock !== false && !flock($rLock, LOCK_EX | LOCK_NB)) {
			echo "Already running.\n";
			return 0;
		}
		return self::buildPending(self::db()) === 0 ? 0 : 1;
	}

	/**
	 * Build every pending() index, one after another. Each ALTER waits at most 5 s
	 * for its table's lock (a long statement holding it) and then gives up: the
	 * next cron:cleanup tries again.
	 *
	 * @return int The indexes that could not be built
	 */
	public static function buildPending(object $rDb): int {
		$rPending = self::pending($rDb);
		if ($rPending !== []) {
			$rDb->query('SET SESSION lock_wait_timeout = 5;');
		}
		$rFailed = 0;
		foreach ($rPending as $rTable => $rIndexes) {
			foreach ($rIndexes as $rName => $rColumns) {
				echo $rTable . ': adding index ' . $rName . '... ';
				if (self::build($rDb, $rName, $rColumns, $rTable)) {
					echo "done.\n";
				} else {
					echo "failed.\n";
					$rFailed++;
				}
			}
		}
		return $rFailed;
	}

	/**
	 * The indexes of INDEXES and LOG_INDEXES not built yet, by table.
	 *
	 * @return array<string, array<string, list<string>>>
	 */
	public static function pending(object $rDb): array {
		$rPending = [];
		foreach (['servers_stats' => self::INDEXES] + self::LOG_INDEXES as $rTable => $rWant) {
			$rMissing = self::missing($rDb, $rTable, $rWant);
			if ($rMissing !== []) {
				$rPending[$rTable] = $rMissing;
			}
		}
		return $rPending;
	}

	/**
	 * The indexes of $rWant (INDEXES by default) that $rTable has not got; none
	 * when the table cannot be read.
	 *
	 * @param array<string, list<string>>|null $rWant
	 * @return array<string, list<string>>
	 */
	public static function missing(object $rDb, string $rTable = 'servers_stats', ?array $rWant = null): array {
		if (!$rDb->query('SHOW INDEX FROM `' . $rTable . '`;')) {
			return [];
		}
		$rHave = [];
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rHave[$rRow['Key_name']][(int) $rRow['Seq_in_index']] = $rRow['Column_name'];
		}
		foreach ($rHave as &$rColumns) {
			ksort($rColumns);
			$rColumns = array_values($rColumns);
		}
		unset($rColumns);
		return array_filter($rWant ?? self::INDEXES, static fn(array $rIndex): bool => !array_filter($rHave, static fn(array $rColumns): bool => array_slice($rColumns, 0, count($rIndex)) === $rIndex));
	}

	/**
	 * Add one index without locking the table. A server that cannot build it
	 * in place refuses, and nothing falls back to a locking ALTER.
	 *
	 * @param list<string> $rColumns
	 */
	public static function build(object $rDb, string $rName, array $rColumns, string $rTable = 'servers_stats'): bool {
		return (bool) $rDb->query('ALTER TABLE `' . $rTable . '` ADD INDEX `' . $rName . '` (`' . implode('`, `', $rColumns) . '`), ALGORITHM=INPLACE, LOCK=NONE;');
	}
}
