<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Public\Controllers\Admin\DashboardController;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The dashboard's connections by country aggregate every closed connection, so
 * they are read only when the map is drawn, and kept five minutes per server
 * tab. A read that failed is not kept.
 */
final class DashboardConnectionRowsTest extends TestCase {
	private TestDb $rDb;

	private QueryLogDb $rLog;

	private string $rDir;

	private FileCache $rCache;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_activity'));
		foreach ([['FR', 1, 0], ['FR', 2, 0], ['DE', 1, 0], ['FR', 3, 1], [null, 1, 0]] as [$rCountry, $rServer, $rProxy]) {
			$this->rDb->query('INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `proxy_id`, `geoip_country_code`, `date_start`) VALUES (1, 1, ?, ?, ?, 0)', $rServer, $rProxy, $rCountry);
		}
		$this->rLog = new QueryLogDb($this->rDb);
		$this->rDir = sys_get_temp_dir() . '/xcvm-dashboard-map-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		$this->rCache = new FileCache($this->rDir);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function rows(int $rServerID = 0): array {
		return DashboardController::connectionRows($this->rLog, true, $rServerID, $this->rCache);
	}

	private static function counts(array $rRows): array {
		return array_map(static fn(array $rRow): string => $rRow['geoip_country_code'] . '=' . $rRow['count'], $rRows);
	}

	public function testNothingIsReadWithTheMapOff(): void {
		$this->assertSame([], DashboardController::connectionRows($this->rLog, false, 0, $this->rCache));
		$this->assertSame([], $this->rLog->rQueries);
	}

	public function testWithTheMapOnTheCountriesAreCountedForAllServersOrOne(): void {
		$this->assertSame(['FR=3', 'DE=1', '=0'], self::counts($this->rows()));
		$this->assertSame(['FR=2', 'DE=1', '=0'], self::counts($this->rows(1)));
	}

	public function testTheRowsAreKeptFiveMinutes(): void {
		$rFirst = $this->rows();
		$this->rDb->exec("INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `geoip_country_code`, `date_start`) VALUES (1, 1, 2, 'IT', 0)");
		$this->rLog->rQueries = [];

		$this->assertSame($rFirst, $this->rows());
		$this->assertSame([], $this->rLog->rQueries, 'no statement within five minutes');

		touch($this->rDir . 'dashboard_map_0', time() - 300);
		clearstatcache();
		$this->assertContains('IT=1', self::counts($this->rows()));
	}

	public function testAFailedReadIsNotKept(): void {
		$this->rLog->rRefuse = '/lines_activity/';
		$this->assertSame([], $this->rows());
		$this->rLog->rRefuse = null;
		$this->assertSame(['FR=3', 'DE=1', '=0'], self::counts($this->rows()));
	}
}
