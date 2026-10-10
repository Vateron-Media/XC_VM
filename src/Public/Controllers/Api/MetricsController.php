<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Gateway\GatewayShadow;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\NodeLag;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * MAIN's /metrics and /healthz (nginx: XC_SCOPE api, XC_API metrics|healthz).
 *
 * /metrics answers Prometheus text to a bearer token (Settings → API →
 * Metrics Token; empty, it answers 404): each server's state and load, the
 * lines by state, the age of the root cron, the newest backup and the last
 * cache run, the dashboard's status checks, and with the cluster API on its
 * nodes and commands (cluster()). /healthz needs nothing and
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
		return $rOut . self::cluster($rServers, $rNow);
	}

	/**
	 * The load balancers enrolled in the cluster API, as Cluster Nodes shows
	 * them (ClusterAdmin::nodes): each one's state, health and mode, seconds
	 * since MAIN last heard it, its clock offset, how long each event lane has
	 * lagged (0: not lagging), the MAIN URLs it cannot reach, whether it reads
	 * its streams on itself, its queued commands, and MAIN's command latency.
	 * Nothing while the cluster API is off.
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 */
	public static function cluster(array $rServers, int $rNow): string {
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			return '';
		}
		try {
			$rNodes = ClusterAdmin::nodes($rServers, ClusterSettings::int('cluster_offline_after_sec', SettingsManager::get('cluster_offline_after_sec')));
			$rCommands = ClusterOverview::commandMetrics($rNow);
		} catch (\Throwable) {
			return ''; // the cluster tables or the bus not there: the rest still answers
		}
		$rSeries = ['node' => [], 'seen' => [], 'clock' => [], 'lag' => [], 'urls' => [], 'local' => [], 'queued' => [], 'gw' => [], 'gw_shadow' => [], 'gw_ready' => []];
		foreach ($rNodes as $rNode) {
			$rLabels = ['server' => (string) $rNode['server_id'], 'name' => (string) $rNode['server_name']];
			$rSeries['node'][] = [$rLabels + ['state' => (string) $rNode['state'], 'health' => (string) $rNode['health'], 'mode' => (string) (int) $rNode['mode']], 1];
			if ($rNode['last_seen_at'] !== null) {
				$rSeries['seen'][] = [$rLabels, max(0, $rNow - intdiv((int) $rNode['last_seen_at'], 1000))];
			}
			$rSeries['clock'][] = [$rLabels, round((int) ($rNode['clock_offset_ms'] ?? 0) / 1000, 3)];
			foreach (NodeLag::LANES as $rLane) {
				$rSince = $rNode[$rLane . '_lag_since'] ?? null;
				$rSeries['lag'][] = [$rLabels + ['lane' => $rLane], $rSince === null ? 0 : max(0, $rNow - (int) $rSince)];
			}
			$rSeries['urls'][] = [$rLabels, count(array_filter(explode(' ', (string) ($rNode['unreachable_urls'] ?? ''))))];
			if ($rNode['streams_local'] !== null) {
				$rSeries['local'][] = [$rLabels, $rNode['streams_local'] ? 1 : 0];
			}
			$rSeries['queued'][] = [$rLabels, (int) ($rCommands['per_node'][(int) $rNode['server_id']] ?? 0)];
			// The segment gateway, as the node reported it (NodeAudit::gatewayOf).
			if (is_array($rNode['gateway'] ?? null)) {
				foreach ($rNode['gateway']['counts'] as $rKey => $rCount) {
					[$rKind, $rAction, $rReason] = explode(' ', (string) $rKey, 3);
					$rSeries['gw'][] = [$rLabels + ['kind' => $rKind, 'action' => $rAction, 'reason' => $rReason], (int) $rCount];
				}
				foreach (['agree', 'disagree', 'deferred', 'unmatched'] as $rResult) {
					$rSeries['gw_shadow'][] = [$rLabels + ['result' => $rResult], (int) $rNode['gateway']['shadow'][$rResult]];
				}
				$rReadiness = GatewayShadow::readiness($rNode['gateway']['shadow'], $rNow);
				$rSeries['gw_ready'][] = [$rLabels + ['mode' => (string) $rNode['gateway']['mode'], 'readiness' => $rReadiness], $rReadiness === 'ready' ? 1 : 0];
			}
		}
		$rLatency = [];
		foreach (['deliver', 'ack'] as $rStage) {
			foreach (['0.5' => 'p50', '0.99' => 'p99'] as $rQuantile => $rKey) {
				if ($rCommands[$rStage . '_' . $rKey] !== null) {
					$rLatency[] = [['stage' => $rStage, 'quantile' => (string) $rQuantile], (int) $rCommands[$rStage . '_' . $rKey]];
				}
			}
		}
		return self::family('xcvm_cluster_node', 'A load balancer enrolled in the cluster API, with its state, health and mode as labels.', $rSeries['node'])
			. self::family('xcvm_cluster_node_last_seen_seconds', 'Seconds since MAIN last heard the node.', $rSeries['seen'])
			. self::family('xcvm_cluster_node_clock_offset_seconds', 'The node\'s clock less MAIN\'s.', $rSeries['clock'])
			. self::family('xcvm_cluster_node_lane_lag_seconds', 'How long the node\'s oldest unsent event on the lane has waited past the lag threshold; 0 when not lagging.', $rSeries['lag'])
			. self::family('xcvm_cluster_node_unreachable_urls', 'MAIN URLs the node reports it cannot reach.', $rSeries['urls'])
			. self::family('xcvm_cluster_node_streams_local', 'Whether the node reads its streams on itself (1) or not (0), as it reports.', $rSeries['local'])
			. self::family('xcvm_cluster_node_commands_queued', 'Commands queued for the node and not yet acknowledged.', $rSeries['queued'])
			. self::family('xcvm_cluster_command_latency_seconds', 'MAIN\'s commands over the last window: seconds from queued to delivered or acknowledged.', $rLatency)
			. self::family('xcvm_gateway_requests', 'Requests the node\'s segment gateway judged since it started, by kind, action and reason.', $rSeries['gw'])
			. self::family('xcvm_gateway_shadow_requests', 'The segment gateway\'s shadow comparison with PHP: requests agreeing, disagreeing, deferred to PHP, unmatched.', $rSeries['gw_shadow'])
			. self::family('xcvm_gateway_ready', 'Whether the node\'s segment gateway is ready to serve (1): compared with PHP for 7 days since its last disagreement.', $rSeries['gw_ready']);
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
