<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CacheEngineCronJob;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Line IP Usage and VOD Theft Detection: the cache engine rebuilds the last
 * hour, day and week every pass, and the All Time range, which reads every
 * closed connection, at most once an hour.
 */
final class CacheReportsTest extends TestCase {
	private const NOW = 1800000000;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	private string $rMarker;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'lines_activity', 'streams'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `is_mag`, `is_e2`, `is_restreamer`) VALUES (1, 'one', 'p', 0, 0, 0), (2, 'two', 'p', 0, 0, 0)");
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`) VALUES (10, 2), (11, 1)');
		foreach ([[1, 10, '192.0.2.1', 60], [1, 11, '192.0.2.2', 7200], [2, 10, '192.0.2.3', 30 * 86400]] as [$rUser, $rStream, $rIP, $rAgo]) {
			$this->rDb->query('INSERT INTO `lines_activity` (`user_id`, `stream_id`, `user_ip`, `date_start`, `date_end`) VALUES (?, ?, ?, ?, ?)', $rUser, $rStream, $rIP, self::NOW - $rAgo, self::NOW - $rAgo + 10);
		}
		$this->rLog = new QueryLogDb($this->rDb);
		$this->rMarker = sys_get_temp_dir() . '/xcvm-report-all-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		@unlink($this->rMarker);
	}

	public function testTheAllTimeRangeIsDueOnceAnHour(): void {
		$this->assertTrue(CacheEngineCronJob::allTimeDue($this->rMarker, self::NOW));
		$this->assertFalse(CacheEngineCronJob::allTimeDue($this->rMarker, self::NOW + 10));
		$this->assertTrue(CacheEngineCronJob::allTimeDue($this->rMarker, self::NOW + 3601));
	}

	public function testTheStatementsAreTheOnesTheReportsAlwaysSent(): void {
		CacheEngineCronJob::linesPerIp($this->rLog, null, self::NOW, $this->rMarker);
		$rWhere = '`lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0';
		$rIP = 'SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE ';
		$this->assertSame([
			$rIP . '`date_start` >= ? AND ' . $rWhere . ' GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;',
			$rIP . '`date_start` >= ? AND ' . $rWhere . ' GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;',
			$rIP . '`date_start` >= ? AND ' . $rWhere . ' GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;',
			$rIP . $rWhere . ' GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;',
		], $this->rLog->rQueries);

		$this->rLog->rQueries = [];
		@unlink($this->rMarker);
		CacheEngineCronJob::theftDetection($this->rLog, null, self::NOW, $this->rMarker);
		$this->assertSame('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE ' . $rWhere . ' AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5)) GROUP BY `lines_activity`.`user_id` ORDER BY `vod_count` DESC LIMIT 1000;', $this->rLog->rQueries[3]);
	}

	public function testBetweenHoursTheAllTimeRangeIsKept(): void {
		$rFirst = CacheEngineCronJob::linesPerIp($this->rLog, null, self::NOW, $this->rMarker);
		$this->assertSame([[1, 1], [1, 2], [1, 2], [1, 2, 2, 1]], array_map(static fn(array $rRows): array => array_merge(...array_map(static fn(array $rRow): array => [(int) $rRow['user_id'], (int) $rRow['ip_count']], $rRows)), array_values($rFirst)));

		$this->rLog->rQueries = [];
		$this->rDb->exec("INSERT INTO `lines_activity` (`user_id`, `stream_id`, `user_ip`, `date_start`, `date_end`) VALUES (2, 10, '192.0.2.9', 1, 2)");
		$rNext = CacheEngineCronJob::linesPerIp($this->rLog, $rFirst, self::NOW + 300, $this->rMarker);

		$this->assertCount(3, $this->rLog->rQueries);
		$this->assertSame($rFirst[0], $rNext[0]);
	}

	public function testAnAllTimeRangeThatCannotBeReadKeepsTheLastOne(): void {
		$rFirst = CacheEngineCronJob::theftDetection($this->rLog, null, self::NOW, $this->rMarker);
		$this->rLog->rRefuse = '/^SELECT .* WHERE `lines`/';

		$rNext = CacheEngineCronJob::theftDetection($this->rLog, $rFirst, self::NOW + 3601, $this->rMarker);

		$this->assertSame($rFirst[0], $rNext[0]);
		$this->assertFileDoesNotExist($this->rMarker, 'tried again at the next pass');
	}
}
