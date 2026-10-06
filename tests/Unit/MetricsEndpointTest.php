<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Public\Controllers\Admin\DashboardController;
use XcVm\Public\Controllers\Api\MetricsController;

/**
 * MAIN's /metrics (Prometheus text, bearer token) and the dashboard checks
 * it and /healthz read.
 */
final class MetricsEndpointTest extends TestCase {
	protected function tearDown(): void {
		SettingsManager::set([]);
		unset($_SERVER['XC_API'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['token']);
	}

	public function testAFamilyIsPrometheusText(): void {
		$this->assertSame(
			"# HELP xcvm_server_cpu_percent CPU.\n# TYPE xcvm_server_cpu_percent gauge\n"
			. "xcvm_server_cpu_percent{server=\"2\",name=\"LB \\\"east\\\"\\\\1\\n\"} 12.5\n"
			. "xcvm_server_cpu_percent{server=\"3\",name=\"b\"} 7\n",
			MetricsController::family('xcvm_server_cpu_percent', 'CPU.', [[['server' => '2', 'name' => "LB \"east\"\\1\n"], 12.5], [['server' => '3', 'name' => 'b'], 7.0]])
		);
		$this->assertSame("# HELP xcvm_up Up.\n# TYPE xcvm_up gauge\nxcvm_up 1\n", MetricsController::family('xcvm_up', 'Up.', [[[], 1]]));
		$this->assertSame("# HELP x X.\n# TYPE x gauge\n", MetricsController::family('x', 'X.', []), 'no samples');
	}

	/** @return iterable<string, array{0: string, 1: ?string, 2: ?string}> */
	public static function refusals(): iterable {
		yield 'no token set' => ['', 'Bearer abc', null];
		yield 'no token sent' => ['abc', null, null];
		yield 'a wrong token' => ['abc', 'Bearer abd', null];
		yield 'the token in the query string' => ['abc', null, 'abc'];
	}

	/** @dataProvider refusals */
	public function testMetricsAreRefusedWithoutTheToken(string $rSet, ?string $rHeader, ?string $rQuery): void {
		SettingsManager::set(['metrics_token' => $rSet]);
		$_SERVER['XC_API'] = 'metrics';
		if ($rHeader !== null) {
			$_SERVER['HTTP_AUTHORIZATION'] = $rHeader;
		}
		if ($rQuery !== null) {
			$_GET['token'] = $rQuery;
		}
		ob_start();
		(new MetricsController())->index();
		$this->assertSame('', ob_get_clean());
	}

	public function testEveryStatusCheckHasAKeyForTheMetrics(): void {
		$rCheck = DashboardController::clockCheck([]);
		$this->assertSame('clock', $rCheck['key']);
		$this->assertSame('servers', DashboardController::serversCheck([])['key']);
		$this->assertSame('backups', DashboardController::backupCheck('off', null, 1800000000)['key']);
	}
}
