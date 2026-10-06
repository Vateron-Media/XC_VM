<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CleanupCronJob;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Retention of the connection and log tables deletes in batches of 10,000 rows
 * for a bounded time per table, so a backlog goes over several runs instead
 * of in one statement that holds the table.
 */
final class CleanupPruneLogsTest extends TestCase {
	private const NOW = 1800000000;

	private const TABLES = ['lines_activity', 'lines_logs', 'login_logs', 'streams_errors', 'streams_logs', 'ondemand_check', 'mysql_syslog'];

	private TestDb $rDb;

	private QueryLogDb $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (self::TABLES as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rLog = new QueryLogDb($this->rDb);
	}

	private function rows(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	public function testABacklogGoesInBatchesWithinTheTimeGiven(): void {
		$this->rDb->exec('INSERT INTO `lines_logs` (`date`) SELECT ' . (self::NOW - 2 * 86400) . ' FROM `seq_1_to_25003`');
		$this->rDb->exec('INSERT INTO `lines_logs` (`date`) VALUES (' . (self::NOW - 60) . '), (' . self::NOW . ')');

		$this->assertSame(['lines_logs' => 10000], CleanupCronJob::pruneLogs($this->rLog, ['keep_client' => 86400], self::NOW, 0.0));
		$this->assertSame(['lines_logs' => 15003], CleanupCronJob::pruneLogs($this->rLog, ['keep_client' => 86400], self::NOW, 60.0));
		$this->assertSame(2, $this->rows('lines_logs'));

		foreach ($this->rLog->writes() as $rStatement) {
			$this->assertMatchesRegularExpression('/^DELETE FROM `lines_logs` WHERE `date` < \? LIMIT 10000;$/', $rStatement);
		}
	}

	public function testEachTableFollowsItsOwnKeepPeriodAndColumn(): void {
		$this->rDb->query('INSERT INTO `lines_activity` (`date_start`, `date_end`) VALUES (?, ?), (?, ?)', 1, self::NOW - 100, 1, self::NOW - 10);
		$this->rDb->query('INSERT INTO `ondemand_check` (`date`) VALUES (?), (?)', self::NOW - 100, self::NOW - 10);

		$this->assertSame([], CleanupCronJob::pruneLogs($this->rLog, ['keep_activity' => 0, 'keep_client' => '', 'keep_login' => 0, 'keep_errors' => 0, 'keep_restarts' => 0, 'on_demand_scan_keep' => 0, 'keep_syslog' => 0], self::NOW));
		$this->assertSame([], $this->rLog->writes(), 'nothing kept for 0');

		$this->assertSame(['lines_activity' => 1, 'ondemand_check' => 1], CleanupCronJob::pruneLogs($this->rLog, ['keep_activity' => 50, 'on_demand_scan_keep' => 50], self::NOW));
		$this->assertSame(1, $this->rows('lines_activity'));
		$this->assertSame(1, $this->rows('ondemand_check'));
	}
}
