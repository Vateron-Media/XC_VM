<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * MAIN's /metrics and /healthz (nginx: XC_SCOPE api, XC_API metrics|healthz).
 *
 * /metrics answers Prometheus text to a bearer token (Settings → API →
 * Metrics Token; empty, it answers 404): each server's state and load, the
 * lines by state, the age of the root cron, the newest backup and the last
 * cache run, and the dashboard's status checks. /healthz needs nothing and
 * says only 200 `ok` or 503 `fail`: a dashboard status check failing, or the
 * database not answering. Both are computed at most every 15 seconds.
 *
 * @package XC_VM_Public_Controllers_Api
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class MetricsController {
	use DatabaseAware;

	private const TTL = 15;

	/** A status check's state as a number: off, ok, warning, failing. */
	private const STATES = ['off' => -1, 'ok' => 0, 'warn' => 1, 'fail' => 2];

	public function index(): void {
		header('Cache-Control: no-store');
		if (($_SERVER['XC_API'] ?? '') === 'healthz') {
			$rOk = self::healthy();
			http_response_code($rOk ? 200 : 503);
			header('Content-Type: text/plain; charset=utf-8');
			echo $rOk ? "ok\n" : "fail\n";
			return;
		}
		$rToken = (string) SettingsManager::get('metrics_token');
		if ($rToken === '') {
			http_response_code(404);
			return;
		}
		if (!hash_equals($rToken, self::bearer())) {
			http_response_code(401);
			header('WWW-Authenticate: Bearer');
			return;
		}
		header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
		echo (new FileCache(CACHE_TMP_PATH))->remember('metrics', self::TTL, static fn(): string => self::render(time()));
	}

	public function shutdown(): void {
	}

	/** The token a scrape sends: `Authorization: Bearer <token>`. Never the query string, which browsers, proxies and tools keep. */
	private static function bearer(): string {
		$rHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
		if (preg_match('/^Bearer\s+(\S+)$/i', $rHeader, $rMatch)) {
			return $rMatch[1];
		}
		return '';
	}

	public static function healthy(): bool {
		return (bool) (new FileCache(CACHE_TMP_PATH))->remember('healthz', self::TTL, static function (): int {
			try {
				$rChecks = DashboardController::statusChecks(DashboardController::statusServers());
				return self::db()->query('SELECT 1;') && !in_array('fail', array_column($rChecks, 'state'), true) ? 1 : 0;
			} catch (\Throwable) {
				return 0;
			}
		});
	}

	/** The exposition text. */
	public static function render(int $rNow): string {
		$db = self::db();
		$rOut = self::family('xcvm_up', 'The panel answered.', [[[], 1]]);

		$rServers = ServerRepository::getAll();
		$rStreams = [];
		if ($db->query('SELECT `server_id`, SUM(`pid` > 0 AND `stream_status` = 0) AS `running`, SUM(`stream_status` = 1) AS `down` FROM `streams_servers` GROUP BY `server_id`;')) {
			foreach ($db->get_rows() as $rRow) {
				$rStreams[(int) $rRow['server_id']] = $rRow;
			}
		}
		$rSeries = ['online' => [], 'cpu' => [], 'memory' => [], 'disk_free' => [], 'disk_total' => [], 'network' => [], 'connections' => [], 'streams' => []];
		foreach ($rServers as $rID => $rServer) {
			if (empty($rServer['enabled'])) {
				continue;
			}
			$rLabels = ['server' => (string) $rID, 'name' => (string) $rServer['server_name']];
			$rWatch = json_decode((string) ($rServer['watchdog_data'] ?? ''), true) ?: [];
			$rSeries['online'][] = [$rLabels, empty($rServer['server_online']) ? 0 : 1];
			$rSeries['connections'][] = [$rLabels, (int) ($rServer['connections'] ?? 0)];
			foreach (['cpu' => 'cpu', 'memory' => 'total_mem_used_percent', 'disk_free' => 'free_disk_space', 'disk_total' => 'total_disk_space'] as $rName => $rKey) {
				if (isset($rWatch[$rKey]) && is_numeric($rWatch[$rKey])) {
					$rSeries[$rName][] = [$rLabels, (float) $rWatch[$rKey]];
				}
			}
			foreach (['in' => 'bytes_received', 'out' => 'bytes_sent'] as $rDirection => $rKey) {
				if (isset($rWatch[$rKey]) && is_numeric($rWatch[$rKey])) {
					$rSeries['network'][] = [$rLabels + ['direction' => $rDirection], (float) $rWatch[$rKey]];
				}
			}
			foreach (['running', 'down'] as $rState) {
				$rSeries['streams'][] = [$rLabels + ['state' => $rState], (int) ($rStreams[$rID][$rState] ?? 0)];
			}
		}
		$rOut .= self::family('xcvm_server_online', 'Whether an enabled server answers MAIN.', $rSeries['online']);
		$rOut .= self::family('xcvm_server_cpu_percent', 'CPU use the server last reported.', $rSeries['cpu']);
		$rOut .= self::family('xcvm_server_memory_percent', 'Memory use the server last reported.', $rSeries['memory']);
		$rOut .= self::family('xcvm_server_disk_free_bytes', 'Free space on the server\'s panel disk.', $rSeries['disk_free']);
		$rOut .= self::family('xcvm_server_disk_total_bytes', 'Size of the server\'s panel disk.', $rSeries['disk_total']);
		$rOut .= self::family('xcvm_server_network_bytes_per_second', 'Network rate the server last reported.', $rSeries['network']);
		$rOut .= self::family('xcvm_server_connections', 'Viewer connections on the server.', $rSeries['connections']);
		$rOut .= self::family('xcvm_server_streams', 'Streams on the server by state.', $rSeries['streams']);

		if ($db->query('SELECT SUM(`enabled` = 1 AND `admin_enabled` = 1 AND (`exp_date` IS NULL OR `exp_date` > ?)) AS `active`, SUM(`exp_date` IS NOT NULL AND `exp_date` <= ?) AS `expired`, SUM(`enabled` = 0 OR `admin_enabled` = 0) AS `disabled` FROM `lines`;', $rNow, $rNow)) {
			$rLines = $db->get_row();
			$rOut .= self::family('xcvm_lines', 'Lines by state.', array_map(static fn(string $rState): array => [['state' => $rState], (int) ($rLines[$rState] ?? 0)], ['active', 'expired', 'disabled']));
		}

		$rAges = [];
		$rSignals = CONFIG_PATH . 'signals.last';
		if (file_exists($rSignals)) {
			$rAges[] = [['job' => 'root_cron'], $rNow - (int) filemtime($rSignals)];
		}
		if (($rBackup = BackupService::newestBackup()) !== null) {
			$rAges[] = [['job' => 'backup'], $rNow - (int) $rBackup['timestamp']];
		}
		if (($rCache = (int) SettingsManager::get('last_cache')) > 0) {
			$rAges[] = [['job' => 'cache'], $rNow - $rCache];
		}
		$rOut .= self::family('xcvm_last_run_age_seconds', 'Seconds since the job last ran.', $rAges);

		$rChecks = [];
		foreach (DashboardController::statusChecks($rServers) as $rCheck) {
			$rChecks[] = [['check' => (string) $rCheck['key']], self::STATES[$rCheck['state']] ?? -1];
		}
		$rOut .= self::family('xcvm_status_check', 'The dashboard\'s status checks: -1 off, 0 ok, 1 warning, 2 failing.', $rChecks);
		return $rOut;
	}

	/**
	 * One metric family in Prometheus text format: its HELP, TYPE (gauge) and
	 * samples, a label value escaped as the format asks.
	 *
	 * @param list<array{0: array<string, string>, 1: int|float}> $rSamples
	 */
	public static function family(string $rName, string $rHelp, array $rSamples): string {
		$rText = '# HELP ' . $rName . ' ' . $rHelp . "\n# TYPE " . $rName . " gauge\n";
		foreach ($rSamples as [$rLabels, $rValue]) {
			$rPairs = [];
			foreach ($rLabels as $rKey => $rLabel) {
				$rPairs[] = $rKey . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $rLabel) . '"';
			}
			$rText .= $rName . ($rPairs === [] ? '' : '{' . implode(',', $rPairs) . '}') . ' ' . (is_float($rValue) ? rtrim(rtrim(sprintf('%.4F', $rValue), '0'), '.') : $rValue) . "\n";
		}
		return $rText;
	}
}
