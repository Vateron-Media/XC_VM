<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\StatsCronJob;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * cron:stats rebuilds streams_stats (Stream Rank) from one aggregate per
 * window and replaces the table in one transaction: the ranks are the ones the
 * ranking statement gave, readers see the old set until the new one commits,
 * a failed read or write leaves the old set, and the ids do not climb.
 */
final class StatsRebuildTest extends TestCase {
	private const NOW = 1800000000;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines_activity', 'streams', 'streams_stats'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		// [stream, user, started this long ago, seconds watched]: every window, a tie, stream 0, no stream.
		foreach ([[1, 1, 3600, 600], [1, 2, 3600, 600], [2, 1, 7200, 1200], [3, 3, 3 * 86400, 500], [4, 1, 3 * 86400, 500], [0, 4, 3600, 50], [null, 5, 3600, 50], [5, 1, 20 * 86400, 900], [6, 2, 90 * 86400, 100]] as [$rStream, $rUser, $rAgo, $rLength]) {
			$this->rDb->query('INSERT INTO `lines_activity` (`stream_id`, `user_id`, `date_start`, `date_end`) VALUES (?, ?, ?, ?)', $rStream, $rUser, self::NOW - $rAgo, self::NOW - $rAgo + $rLength);
		}
		$this->rLog = new QueryLogDb($this->rDb);
	}

	/** @return list<string> "type stream rank time connections users", in rank order per window */
	private function table(): array {
		$this->rDb->query("SELECT CONCAT_WS(' ', `type`, IFNULL(`stream_id`, 'null'), `rank`, `time`, `connections`, `users`) FROM `streams_stats` ORDER BY FIELD(`type`, 'today', 'week', 'month', 'all'), `rank`");
		return $this->rDb->get_column();
	}

	/** What the two statements of the job before gave: the aggregate, ranked by the ranking statement's order. */
	private function expected(): array {
		$rRows = [];
		foreach (['today' => self::NOW - 86400, 'week' => self::NOW - 604800, 'month' => self::NOW - 2592000, 'all' => 0] as $rType => $rFrom) {
			$this->rDb->query("SELECT CONCAT_WS(' ', ?, IFNULL(`stream_id`, 'null'), ROW_NUMBER() OVER (ORDER BY SUM(`date_end` - `date_start`) DESC, `stream_id` DESC), SUM(`date_end` - `date_start`), COUNT(*), COUNT(DISTINCT `user_id`)) AS `row` FROM `lines_activity` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id` ORDER BY SUM(`date_end` - `date_start`) DESC, `stream_id` DESC", $rType, $rFrom, self::NOW);
			$rRows = array_merge($rRows, $this->rDb->get_column());
		}
		return $rRows;
	}

	private function sentinel(): void {
		$this->rDb->exec("INSERT INTO `streams_stats` (`stream_id`, `rank`, `type`) VALUES (99, 1, 'today')");
	}

	public function testTheWindowsAndRanksAreTheOnesTheJobGave(): void {
		$rWritten = StatsCronJob::rebuild($this->rLog, self::NOW);

		$this->assertSame($this->expected(), $this->table());
		$this->assertSame(count($this->expected()), $rWritten);
		$this->assertContains('today null 4 50 1 1', $this->table(), 'no stream ranks after stream 0 at the same time');
	}

	public function testOneReadPerWindowAndNoTruncate(): void {
		StatsCronJob::rebuild($this->rLog, self::NOW);

		$this->assertCount(4, preg_grep('/FROM `lines_activity`/', $this->rLog->rQueries));
		$this->assertSame([], preg_grep('/TRUNCATE/i', $this->rLog->rQueries));
		$this->assertCount(2, $this->rLog->writes(), 'one delete and one insert');
	}

	public function testAFailedReadOrWriteLeavesTheOldFigures(): void {
		$this->sentinel();
		foreach (['/FROM `lines_activity`/', '/^INSERT INTO `streams_stats`/'] as $rRefused) {
			$this->rLog->rRefuse = $rRefused;
			$this->assertNull(StatsCronJob::rebuild($this->rLog, self::NOW), $rRefused);
			$this->assertSame(['today 99 1 0 0 0'], $this->table(), $rRefused);
		}
	}

	public function testReadersSeeTheOldFiguresUntilTheNewOnesCommit(): void {
		$this->sentinel();
		$rOther = TestDb::connect($this->rDb->schema());
		$rSeen = [];
		$this->rLog->rBefore = static function (string $rQuery) use ($rOther, &$rSeen): void {
			if (str_starts_with($rQuery, 'INSERT INTO `streams_stats`')) {
				$rSeen[] = (int) $rOther->query('SELECT COUNT(*) FROM `streams_stats`')->fetchColumn();
			}
		};

		StatsCronJob::rebuild($this->rLog, self::NOW);

		$this->assertSame([1], $rSeen);
	}

	public function testTheIdsDoNotClimb(): void {
		StatsCronJob::rebuild($this->rLog, self::NOW);
		StatsCronJob::rebuild($this->rLog, self::NOW);

		$this->rDb->query('SELECT MAX(`id`) = COUNT(*) FROM `streams_stats`');
		$this->assertSame(1, (int) $this->rDb->get_col());
	}
}
