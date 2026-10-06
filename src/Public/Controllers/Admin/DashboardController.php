<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\Maintenance;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Cache\CacheRunState;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * DashboardController — Dashboard page.
 *
 * Complex data-prep: theme colours, connection map queries, server stats.
 * Dashboard has NO PageAuthorization::checkPermissions() — it uses server_id validation instead.
 *
 * @renders Views/admin/dashboard.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DashboardController extends BaseAdminController {
	public function index() {
		global $db, $rUserInfo, $rServers;

		$rCountryCodes = GeoReference::countryCodes();

		// Theme colour map
		if (Theme::fromId($rUserInfo['theme'])->isDark()) {
			$rColours = [1 => ['secondary', '#7e8e9d', '#ffffff'], 2 => ['secondary', '#7e8e9d', '#ffffff'], 3 => ['secondary', '#7e8e9d', '#ffffff'], 4 => ['secondary', '#7e8e9d', '#ffffff']];
			$rColourMap = [['#7e8e9d', 'bg-map-dark-1'], ['#6c7b8a', 'bg-map-dark-2'], ['#5a6977', 'bg-map-dark-3'], ['#485765', 'bg-map-dark-4'], ['#374654', 'bg-map-dark-5'], ['#273643', 'bg-map-dark-6']];
		} else {
			$rColours = [1 => ['purple', '#675db7', '#675db7'], 2 => ['success', '#23b397', '#23b397'], 3 => ['pink', '#e36498', '#e36498'], 4 => ['info', '#56C3D6', '#56C3D6']];
			$rColourMap = [['#23b397', 'bg-success'], ['#56c2d6', 'bg-info'], ['#5089de', 'bg-primary'], ['#675db7', 'bg-purple'], ['#e36498', 'bg-pink'], ['#98a6ad', 'bg-secondary']];
		}

		// Server ID validation
		if (RequestManager::has('server_id') && !isset($rServers[RequestManager::get('server_id')])) {
			$this->redirect('dashboard');
			return;
		}

		// Connection map
		$rConnectionMap = [];
		$rConnectionCount = 0;
		$rMapOn = SettingsManager::get('save_closed_connection') && SettingsManager::get('dashboard_map');
		$rCountryRows = self::connectionRows($db, $rMapOn, intval(RequestManager::get('server_id')));

		if ($rCountryRows !== []) {
			$i = 0;
			foreach ($rCountryRows as $rRow) {
				if ($i < count($rColourMap)) {
					$rRow['colour'] = $rColourMap[$i];
				} else {
					$rRow['colour'] = $rColourMap[count($rColourMap) - 1];
				}
				if (isset($rCountryCodes[$rRow['geoip_country_code']])) {
					$rRow['name'] = $rCountryCodes[$rRow['geoip_country_code']];
				} else {
					$rRow['name'] = 'Unknown Country';
				}
				$rConnectionCount += $rRow['count'];
				$rConnectionMap[] = $rRow;
				$i++;
			}
		}

		// Server stats (when no server filter)
		$rServerStats = [];
		if (!RequestManager::has('server_id')) {
			$rLimit = 3600;
			$rTime = time();
			$rNearestRange = $rTime - $rLimit;
			$db->query('SELECT * FROM `servers_stats` WHERE `time` >= ? ORDER BY `time` ASC;', $rNearestRange);
			if (0 < $db->num_rows()) {
				foreach ($db->get_rows() as $rRow) {
					// {x: epoch-ms, y: cpu%} so the sparkline tooltip shows the
					// real sample time instead of a bare point index.
					$rServerStats[intval($rRow['server_id'])][] = [
						'x' => intval($rRow['time']) * 1000,
						'y' => floatval($rRow['cpu']),
					];
				}
			}
		}

		$rOrderedServers = $rServers;
		array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

		// Service-status checklist (prepared here so the view stays free of
		// filesystem / watchdog probes).
		$rStatusChecks = self::statusChecks(self::statusServers());

		// The Bootstrap 5 dashboard renders CPU/network/connection charts with ApexCharts,
		// and (when enabled and there is data) a jsvectormap world map.
		$rVendors = ['apexcharts'];
		if ($rMapOn && $rConnectionCount > 0) {
			$rVendors[] = 'jsvectormap';
		}
		$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
			(array) ($GLOBALS['xmNewuiVendors'] ?? []),
			$rVendors
		)));

		$this->setTitle('Dashboard');
		$this->render('dashboard', ['rColours' => $rColours, 'rColourMap' => $rColourMap, 'rConnectionMap' => $rConnectionMap, 'rConnectionCount' => $rConnectionCount, 'rServerStats' => $rServerStats, 'rOrderedServers' => $rOrderedServers, 'rStatusChecks' => $rStatusChecks, 'clusterBanners' => ClusterOverview::dashboardBanners($rServers[SERVER_ID] ?? [], SettingsManager::getAll(), time()), 'rMaintenance' => Maintenance::active(SettingsManager::getAll()) ? (int) SettingsManager::get('maintenance_until') : null]);
	}

	/** The servers the status rows judge: every row, offline ones too, keyed by id (the view's lists hold the online ones). */
	public static function statusServers(): array {
		return ServerRepository::getAll();
	}

	/** Free bytes below which MAIN's panel disk is judged: it also holds VOD, archives and created channels. */
	private const DISK_FLOOR = 10 * 1024 ** 3;

	private const DISK_WARN = 90;

	private const DISK_FAIL = 95;

	/** A certificate is renewed from 7 days before it expires, once a day: under 5, two renewals failed. */
	private const CERT_WARN_SEC = 432000;

	/**
	 * Build the "Service Status" checklist: every probe is always listed with
	 * its state, so a healthy panel shows what was checked instead of nothing.
	 *
	 * @param array<int,array<string,mixed>> $servers every server, keyed by id
	 * @return list<array{state:string,icon:string,title:string,detail:string,help:string,key:string}>
	 */
	public static function statusChecks(array $servers): array {
		$bin = ['{bin}' => htmlspecialchars(defined('PHP_BIN') ? PHP_BIN : 'php')];
		$signals = CONFIG_PATH . 'signals.last';
		$now = time();

		$rClusterOn = !empty(SettingsManager::get('cluster_api_enabled'));
		$rNodes = $rPending = [];
		if ($rClusterOn) {
			try {
				$rOfflineAfter = ClusterSettings::int('cluster_offline_after_sec', SettingsManager::get('cluster_offline_after_sec'));
				$rNodes = ClusterAdmin::nodes($servers, $rOfflineAfter);
				$rPending = ClusterAdmin::pending($servers);
			} catch (\Throwable) {
				// The cluster tables are MAIN's and created by cluster:init: a
				// dashboard never fails over a checklist row.
				$rNodes = $rPending = [];
			}
		}

		$rSchedule = (string) SettingsManager::get('automatic_backups');
		$rCache = CacheRunState::state(CACHE_TMP_PATH);
		$rChecks = [
			self::serversCheck($servers),
			self::clockCheck($servers),
			self::schemaCheck((string) SettingsManager::get('status_uuid'), XC_VM_VERSION, $bin),
			self::cronCheck(file_exists($signals) ? filemtime($signals) : null, $now, $bin),
			// A proxy's watchdog blob is its own: the daemon runs on streaming servers.
			self::fanoutCheck(FanoutMode::enabled(), array_filter($servers, static fn(array $rServer): bool => (int) ($rServer['server_type'] ?? 0) === 0), $now, $bin),
			self::clusterCheck($rClusterOn, $rNodes, $rPending, $bin),
			self::diskCheck(['panel' => self::usage(MAIN_HOME), 'tmp' => self::usage(TMP_PATH)]),
			self::backupCheck($rSchedule, isset(BackupService::PERIODS[$rSchedule]) ? BackupService::newestBackup() : null, $now),
			self::certificateCheck($servers, $now, $bin),
			self::cacheCheck(!empty(SettingsManager::get('enable_cache')), file_exists(CACHE_TMP_PATH . 'cache_complete'), $rCache['failed'], $rCache['stalled'], (int) SettingsManager::get('last_cache'), $now),
		];
		// Failing and warning rows first: the card scrolls, and a red row below
		// the fold would only be a number in the badge. The sort is stable.
		$rRank = ['fail' => 0, 'warn' => 1, 'ok' => 2, 'off' => 3];
		usort($rChecks, static fn(array $a, array $b): int => ($rRank[$a['state']] ?? 4) <=> ($rRank[$b['state']] ?? 4));
		return $rChecks;
	}

	/**
	 * MAIN's panel disk and its tmp tmpfs: yellow from 90 %, red from 95 % used. The
	 * panel disk counts only under DISK_FLOOR free, as a large one is often mostly
	 * content; tmp holds the cache, which stops being written when it is full.
	 *
	 * @param array<string, array{0: int, 1: float}|null> $volumes 'panel' / 'tmp' => [used %, free bytes], null unknown
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function diskCheck(array $volumes): array {
		$rState = 'ok';
		$rParts = [];
		foreach ($volumes as $rKey => $rVolume) {
			if ($rVolume === null) {
				continue;
			}
			[$rUsed, $rFree] = $rVolume;
			$rParts[] = Translator::get('dashboard_check_disk_volume', ['{name}' => Translator::get('dashboard_check_disk_' . $rKey), '{used}' => (string) $rUsed]);
			$rJudged = $rKey !== 'panel' || $rFree < self::DISK_FLOOR;
			if ($rJudged && self::DISK_FAIL <= $rUsed) {
				$rState = 'fail';
			} elseif ($rJudged && self::DISK_WARN <= $rUsed && $rState === 'ok') {
				$rState = 'warn';
			}
		}
		if ($rParts === []) {
			return self::check('off', 'tabler-device-sd-card', 'dashboard_check_disk', Translator::get('dashboard_check_disk_nodata'));
		}
		return self::check($rState, 'tabler-device-sd-card', 'dashboard_check_disk', implode(' · ', $rParts), $rState === 'ok' ? '' : Translator::get('dashboard_status_disk_text'));
	}

	/** @return array{0: int, 1: float}|null used % as the Cache page computes it, and free bytes */
	private static function usage(string $rPath): ?array {
		$rTotal = @disk_total_space($rPath);
		$rFree = @disk_free_space($rPath);
		if (!$rTotal || $rFree === false) {
			return null;
		}
		return [100 - (int) ($rFree / $rTotal * 100), (float) $rFree];
	}

	/**
	 * Automatic backups: red with none, or the newest more than a quarter of a period
	 * late; yellow when its Dropbox upload failed (the error is on the Backups page).
	 *
	 * @param array{timestamp: int, upload_failed: bool}|null $newest
	 * @param array{state: string, error: string}|null $verify BackupVerifier's last result
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function backupCheck(string $schedule, ?array $newest, int $now): array {
		if (!isset(BackupService::PERIODS[$schedule])) {
			return self::check('off', 'tabler-database-export', 'dashboard_check_backups', Translator::get('dashboard_check_backups_off'));
		}
		if ($newest === null) {
			return self::check('fail', 'tabler-database-export', 'dashboard_check_backups', Translator::get('dashboard_check_backups_none'), Translator::get('dashboard_status_backups_text'));
		}
		$rDetail = Translator::get('dashboard_check_backups_ok', ['{ago}' => self::formatAgo($now - $newest['timestamp'])]);
		if (BackupService::PERIODS[$schedule] * 1.25 < $now - $newest['timestamp']) {
			return self::check('fail', 'tabler-database-export', 'dashboard_check_backups', $rDetail, Translator::get('dashboard_status_backups_text'));
		}
		if ($newest['upload_failed']) {
			return self::check('warn', 'tabler-database-export', 'dashboard_check_backups', $rDetail . ' · ' . Translator::get('dashboard_check_backups_upload'), Translator::get('dashboard_status_backups_text'));
		}
		return self::check('ok', 'tabler-database-export', 'dashboard_check_backups', $rDetail);
	}

	/**
	 * The certificates cron:certbot keeps (servers.certbot_ssl) of enabled servers with
	 * HTTPS: red when one expired, yellow under 5 days left.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function certificateCheck(array $servers, int $now, array $bin = []): array {
		$rExpired = $rSoon = [];
		$rNext = null;
		foreach ($servers as $rServer) {
			$rExpires = (int) ((json_decode((string) ($rServer['certbot_ssl'] ?? ''), true) ?: [])['expiration'] ?? 0);
			if (empty($rServer['enabled']) || empty($rServer['enable_https']) || $rExpires <= 0) {
				continue;
			}
			$rNext = $rNext === null ? $rExpires : min($rNext, $rExpires);
			if ($rExpires <= $now) {
				$rExpired[] = (string) $rServer['server_name'];
			} elseif ($rExpires - $now < self::CERT_WARN_SEC) {
				$rSoon[] = $rServer['server_name'] . ' (' . intdiv($rExpires - $now, 86400) . ' d)';
			}
		}
		if ($rNext === null) {
			return self::check('off', 'tabler-certificate', 'dashboard_check_certs', Translator::get('dashboard_check_certs_none'));
		}
		if ($rExpired !== []) {
			return self::check('fail', 'tabler-certificate', 'dashboard_check_certs', Translator::get('dashboard_check_certs_expired', ['{names}' => implode(', ', $rExpired)]), Translator::get('dashboard_status_certs_text', $bin));
		}
		if ($rSoon !== []) {
			return self::check('warn', 'tabler-certificate', 'dashboard_check_certs', Translator::get('dashboard_check_certs_expiring', ['{names}' => implode(', ', $rSoon)]), Translator::get('dashboard_status_certs_text', $bin));
		}
		return self::check('ok', 'tabler-certificate', 'dashboard_check_certs', Translator::get('dashboard_check_certs_ok', ['{days}' => (string) intdiv($rNext - $now, 86400)]));
	}

	/**
	 * The cache engine's scheduled runs: red when the last one failed or one never
	 * finished (CacheRunState), yellow until the first build completes.
	 *
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function cacheCheck(bool $enabled, bool $complete, bool $failed, bool $stalled, int $lastGood, int $now): array {
		if (!$enabled) {
			return self::check('off', 'tabler-bolt', 'dashboard_check_cache', Translator::get('dashboard_check_cache_off'));
		}
		$rDetail = Translator::get('dashboard_check_cache_ok', ['{ago}' => self::formatAgo($now - $lastGood)]);
		if ($failed || $stalled) {
			return self::check('fail', 'tabler-bolt', 'dashboard_check_cache', $rDetail, Translator::get('dashboard_status_cache_text'));
		}
		if (!$complete) {
			return self::check('warn', 'tabler-bolt', 'dashboard_check_cache', Translator::get('dashboard_check_cache_building'));
		}
		return self::check('ok', 'tabler-bolt', 'dashboard_check_cache', $rDetail);
	}

	/**
	 * The cluster API, judged on the nodes MAIN has enrolled. Their health is
	 * already settled (ClusterAdmin::nodes(): `ok`, `suspect`, `offline` or
	 * `unknown` for an active node, else its state), so this row only says what
	 * an operator should do about it:
	 *
	 * - a node MAIN has quarantined or revoked, or one gone silent, is a failure
	 *   — it is serving viewers with a replica nobody is refreshing;
	 * - a node waiting for a decision (a code enrolment, or one still enrolling)
	 *   is a warning, because it is *not* serving anything yet;
	 * - the API switched off, or on with no node enrolled, is neither.
	 *
	 * @param list<array<string,mixed>> $nodes   ClusterAdmin::nodes() rows
	 * @param list<array<string,mixed>> $pending ClusterAdmin::pending() rows
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function clusterCheck(bool $enabled, array $nodes, array $pending, array $bin): array {
		if (!$enabled) {
			return self::check('off', 'tabler-hierarchy-2', 'dashboard_check_cluster', Translator::get('dashboard_check_cluster_off'));
		}
		if ($nodes === [] && $pending === []) {
			return self::check('off', 'tabler-hierarchy-2', 'dashboard_check_cluster', Translator::get('dashboard_check_cluster_none'));
		}

		$rName = static fn(array $rNode): string => (string) ($rNode['server_name'] ?? ('#' . ($rNode['server_id'] ?? '?')));
		$rWith = static function (array $rNodes, callable $rWhen) use ($rName): array {
			return array_values(array_map($rName, array_filter($rNodes, $rWhen)));
		};
		$rStopped = $rWith($nodes, static fn(array $rNode): bool => in_array((string) $rNode['state'], ['quarantined', 'revoked'], true));
		$rSilent = $rWith($nodes, static fn(array $rNode): bool => in_array((string) ($rNode['health'] ?? ''), ['offline', 'unknown'], true));
		$rSuspect = $rWith($nodes, static fn(array $rNode): bool => ($rNode['health'] ?? '') === 'suspect');
		$rWaiting = array_merge(
			array_values(array_map($rName, $pending)),
			$rWith($nodes, static fn(array $rNode): bool => (string) $rNode['state'] === 'enrolling')
		);

		$rActive = count($rWith($nodes, static fn(array $rNode): bool => (string) $rNode['state'] === 'active'));
		$rDetail = Translator::get('dashboard_check_cluster_ok', [
			'{answering}' => (string) ($rActive - count($rSilent) - count($rSuspect)),
			'{total}' => (string) $rActive,
		]);
		if ($rWaiting !== []) {
			$rDetail .= ' · ' . Translator::get('dashboard_check_cluster_waiting', ['{names}' => implode(', ', $rWaiting)]);
		}
		if ($rStopped !== []) {
			$rDetail .= ' · ' . Translator::get('dashboard_check_cluster_stopped', ['{names}' => implode(', ', $rStopped)]);
		}

		$rState = $rStopped !== [] || $rSilent !== [] ? 'fail' : ($rWaiting !== [] || $rSuspect !== [] ? 'warn' : 'ok');

		return self::check(
			$rState,
			'tabler-hierarchy-2',
			'dashboard_check_cluster',
			self::withDown($rDetail, $rSilent),
			$rState === 'ok' ? '' : Translator::get('dashboard_status_cluster_text', $bin)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $servers
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function serversCheck(array $servers): array {
		// A server installing (3) or updating (5) is neither counted nor down, as in the header.
		$enabled = array_filter($servers, fn($s) => !empty($s['enabled']) && !in_array((int) ($s['status'] ?? 0), [3, 5], true));
		$offline = array_column(array_filter($enabled, fn($s) => empty($s['server_online'])), 'server_name');
		$total = count($enabled);
		$detail = Translator::get('dashboard_check_servers_ok', ['{online}' => (string) ($total - count($offline)), '{total}' => (string) $total]);

		return self::check($offline !== [] ? 'fail' : 'ok', 'tabler-server-2', 'dashboard_check_servers', self::withDown($detail, $offline));
	}

	/** Seconds a server's clock may be off MAIN's (servers.time_offset) before the row warns. */
	private const CLOCK_WARN_SEC = 5;

	/**
	 * The clock of each enabled server that answers, as MAIN last measured it
	 * against its own: yellow past CLOCK_WARN_SEC, naming the server and its offset.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function clockCheck(array $servers): array {
		$rOff = [];
		foreach ($servers as $rServer) {
			$rOffset = (int) ($rServer['time_offset'] ?? 0);
			if (!empty($rServer['enabled']) && !empty($rServer['server_online']) && self::CLOCK_WARN_SEC < abs($rOffset)) {
				$rOff[] = $rServer['server_name'] . ' ' . sprintf('%+d', $rOffset) . ' s';
			}
		}
		if ($rOff === []) {
			return self::check('ok', 'tabler-clock', 'dashboard_check_clock', Translator::get('dashboard_check_clock_ok', ['{sec}' => (string) self::CLOCK_WARN_SEC]));
		}
		return self::check('warn', 'tabler-clock', 'dashboard_check_clock', Translator::get('dashboard_check_clock_off', ['{names}' => implode(', ', $rOff)]), Translator::get('dashboard_status_clock_text'));
	}

	/**
	 * Connections by country for the map: one aggregate over every closed
	 * connection, so it is only run when the map is drawn, and kept five minutes.
	 *
	 * @param int $rServerID One server's (as streaming or proxy server), or 0 for all
	 * @return list<array{geoip_country_code: ?string, count: int}>
	 */
	public static function connectionRows(object $db, bool $rMapOn, int $rServerID = 0, ?FileCache $rCache = null): array {
		if (!$rMapOn) {
			return [];
		}
		return ($rCache ?? new FileCache(CACHE_TMP_PATH))->remember('dashboard_map_' . $rServerID, 300, static function () use ($db, $rServerID): array|false {
			if (0 < $rServerID) {
				$rOk = $db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` WHERE (`server_id` = ? OR `proxy_id` = ?) GROUP BY `geoip_country_code` ORDER BY `count` DESC;', $rServerID, $rServerID);
			} else {
				$rOk = $db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` GROUP BY `geoip_country_code` ORDER BY `count` DESC;');
			}
			return $rOk ? ($db->get_rows() ?: []) : false;
		}) ?: [];
	}

	/**
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function schemaCheck(string $statusUuid, string $version, array $bin): array {
		// StatusCommand::schemaMark(): the release whose migrations all applied.
		if ($statusUuid !== '' && $statusUuid === $version) {
			return self::check('ok', 'tabler-database', 'dashboard_check_schema', Translator::get('dashboard_check_schema_ok', ['{version}' => $version]));
		}

		return self::check('warn', 'tabler-database', 'dashboard_check_schema', '', Translator::get('dashboard_status_db_incomplete_text', $bin));
	}

	/**
	 * Root crons touch config/signals.last each run; stale after 10 minutes.
	 *
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function cronCheck(?int $lastRun, int $now, array $bin): array {
		if ($lastRun === null) {
			return self::check('fail', 'tabler-clock-play', 'dashboard_check_crons', Translator::get('dashboard_check_crons_never'), Translator::get('dashboard_status_crons_text', $bin));
		}

		$ok = $now - $lastRun <= 600;
		$detail = Translator::get('dashboard_check_crons_ok', ['{ago}' => self::formatAgo($now - $lastRun)]);

		return self::check($ok ? 'ok' : 'fail', 'tabler-clock-play', 'dashboard_check_crons', $detail, $ok ? '' : Translator::get('dashboard_status_crons_text', $bin));
	}

	/**
	 * xc_fanout live-delivery daemon, judged only on servers whose watchdog
	 * reported in the last minute. Switched off by the admin → "off", not a failure.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string,key:string}
	 */
	public static function fanoutCheck(bool $enabled, array $servers, int $now, array $bin): array {
		if (!$enabled) {
			return self::check('off', 'tabler-broadcast', 'dashboard_check_fanout', Translator::get('dashboard_check_fanout_disabled'));
		}

		$states = self::fanoutStates($servers, $now);
		if ($states === []) {
			return self::check('off', 'tabler-broadcast', 'dashboard_check_fanout', Translator::get('dashboard_check_fanout_nodata'));
		}

		$down = array_keys(array_filter($states, fn($running) => !$running));
		$detail = Translator::get('dashboard_check_fanout_ok', ['{running}' => (string) (count($states) - count($down)), '{total}' => (string) count($states)]);

		return self::check($down !== [] ? 'fail' : 'ok', 'tabler-broadcast', 'dashboard_check_fanout', self::withDown($detail, $down), $down !== [] ? Translator::get('dashboard_status_fanout_text', $bin) : '');
	}

	/**
	 * server name => fanout running, for servers that reported in the last minute.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @return array<string,bool>
	 */
	private static function fanoutStates(array $servers, int $now): array {
		$states = [];
		foreach ($servers as $srv) {
			$wd = json_decode($srv['watchdog_data'] ?? '{}', true) ?: [];
			if ($now - intval($srv['last_check_ago'] ?? 0) < 60 && isset($wd['fanout']['running'])) {
				$states[(string) $srv['server_name']] = (bool) $wd['fanout']['running'];
			}
		}

		return $states;
	}

	/** @param list<string> $down */
	private static function withDown(string $detail, array $down): string {
		return $down !== [] ? $detail . ' · ' . Translator::get('dashboard_check_servers_down', ['{names}' => implode(', ', $down)]) : $detail;
	}

	/** @return array{state:string,icon:string,title:string,detail:string,help:string,key:string} */
	private static function check(string $state, string $icon, string $titleKey, string $detail, string $help = ''): array {
		// `key`: the check's name without its prefix (servers, disk, backups…), for /metrics and /healthz.
		return ['state' => $state, 'icon' => $icon, 'title' => Translator::get($titleKey), 'detail' => $detail, 'help' => $help, 'key' => substr($titleKey, strlen('dashboard_check_'))];
	}

	private static function formatAgo(int $seconds): string {
		if ($seconds < 60) {
			return max(0, $seconds) . 's';
		}

		return $seconds < 3600 ? intdiv($seconds, 60) . ' min' : intdiv($seconds, 3600) . ' h';
	}
}
