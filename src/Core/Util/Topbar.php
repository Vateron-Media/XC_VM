<?php

namespace XcVm\Core\Util;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Module\TopbarRegistry;

/**
 * Topbar — single source of truth for the per-page action bar.
 *
 * The admin panel shows a per-page toolbar (primary action, clear-filters,
 * refresh, and a dropdown of related tools/logs + Export CSV/JSON). Historically
 * this lived as a big `$rDropdown` literal inside Views/admin/topbar.php that
 * echoed legacy HTML. This class hoists that config out so BOTH the legacy
 * renderer (topbar.php) and the Bootstrap 5 renderer (topbar.newui.php) build from the
 * same data.
 *
 * - config() returns the raw per-page map `label => [url, permission, attr]`.
 * - items() returns an ordered, permission-filtered, structured list ready to
 *   render (JSON-serialisable) — the shape the Bootstrap 5 partial consumes.
 *
 * Note: the per-EDIT-page `switch` unset adjustments and the stream_view /
 * server_view overrides from topbar.php are intentionally NOT reproduced here —
 * they only apply to edit pages (stream, movie, serie, server, …), none of which
 * are Bootstrap 5-migrated table pages. When an edit page migrates, port its adjustment.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class Topbar {
	/**
	 * `clear_logs` table type per topbar page (BackupAjaxController::clearLogs).
	 */
	/**
	 * Pages where "Export as CSV/JSON" is offered — log / report screens only.
	 * On content/management tables (streams, lines, movies, users, …) export is
	 * intentionally hidden.
	 */
	private const EXPORT_PAGES = [
		'panel_logs',
		'login_logs',
		'mysql_syslog',
		'client_logs',
		'credit_logs',
		'user_logs',
		'stream_errors',
		'line_activity',
		'mag_events',
		'live_connections',
	];

	private const LOG_TYPES = [
		'client_logs'   => 'lines_logs',
		'credit_logs'   => 'users_credits_logs',
		'user_logs'     => 'users_logs',
		'stream_errors' => 'streams_errors',
		'line_activity' => 'lines_activity',
		'panel_logs'    => 'panel_logs',
	];

	/**
	 * Per-page dropdown configuration: `page => [label => [url, permission, attr]]`.
	 * Verbatim copy of the legacy topbar.php literal; runtime bits come from $ctx.
	 *
	 * @param array{rID?:int|null,rSID?:int|null,rMobile?:bool,rImport?:bool} $ctx
	 * @return array<string,array<string,array>>
	 */
	public static function config(array $ctx = []): array {
		$rID = $ctx['rID'] ?? null;
		$rSID = $ctx['rSID'] ?? null;
		$rMobile = (bool) ($ctx['rMobile'] ?? false);
		$rImport = (bool) ($ctx['rImport'] ?? false);
		$rMulti = (bool) ($ctx['rMulti'] ?? false);

		$rDropdown = [
			'ondemand' => ['Manage Streams' => ['streams', 'streams'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['stream_mass', 'mass_edit_streams'], 'Stream Tools' => ['stream_tools', 'stream_tools'], 'Stream Error Logs' => ['stream_errors', 'stream_errors'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'streams' => ['Add Stream' => ['stream', 'add_stream'], 'Import & Review' => ($rMobile ? [] : ['review?type=1', 'import_streams']), 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), "EPG's" => ['epgs', 'epg'], 'Fingerprint' => ['fingerprint', 'fingerprint'], 'On-Demand Scanner' => ['ondemand', 'streams'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['stream_mass', 'mass_edit_streams'], 'Mass Edit (Review)' => ['stream_review', 'mass_edit_streams'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools'], 'Stream Error Logs' => ['stream_errors', 'stream_errors'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'created_channels' => ['Create Channel' => ['created_channel', 'create_channel'], 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['created_channel_mass', null], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'stream_view' => ['Edit Stream' => ['stream?id=' . $rID, 'edit_stream'], 'Manage Streams' => ['streams', 'streams']],
			'stream_review' => [($rImport ? 'Save Changes' : 'Review Streams') => [null, null, 'id="btn-submit"']],
			'panel_logs' => ['Download log' => [null, null, 'id="btn-download-log"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'movies' => ['Add Movie' => ['movie', 'add_movie'], 'Import & Review' => ($rMobile ? [] : ['review?type=2', 'import_movies']), 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['movie_mass', 'mass_sedits_vod'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'series' => ['Add Series' => ['serie', 'add_series'], 'Episodes' => ['episodes', 'episodes'], 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['series_mass', 'mass_sedits'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'episodes' => ['Add Episode' => [null, 'add_episode', 'id="add-episode-btn"'], 'TV Series' => ['series', 'series'], 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['episodes_mass', 'mass_sedits'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'radios' => ['Add Station' => ['radio', 'add_radio'], 'Categories' => ['stream_categories', 'categories'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['radio_mass', 'mass_edit_radio'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'lines' => ['Add Line' => ['line', 'add_user'], "Blocked ASN's" => ['asns', 'block_isps'], "Blocked IP's" => ['ips', 'block_ips'], "Blocked ISP's" => ['isps', 'block_isps'], 'Blocked User-Agents' => ['useragents', 'block_uas'], 'Live Connections' => ['live_connections', 'live_connections'], 'Activity Logs' => ['line_activity', 'connection_logs'], "IP's per Line" => ['line_ips', 'connection_logs'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['line_mass', 'mass_edit_lines'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'live_connections' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Activity Logs' => ['line_activity', 'connection_logs'], "IP's per Line" => ['line_ips', 'connection_logs']],
			'mags' => ['Add Device' => ['mag', 'add_mag'], "Blocked IP's" => ['ips', 'block_ips'], "Blocked ISP's" => ['isps', 'block_isps'], 'Live Connections' => ['live_connections', 'connection_logs'], 'Activity Logs' => ['line_activity', 'connection_logs'], 'MAG Event Logs' => ['mag_events', 'manage_events'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['mag_mass', 'mass_edit_mags'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'enigmas' => ['Add Device' => ['enigma', 'add_e2'], "Blocked IP's" => ['ips', 'block_ips'], "Blocked ISP's" => ['isps', 'block_isps'], 'Live Connections' => ['live_connections', 'connection_logs'], 'Activity Logs' => ['line_activity', 'connection_logs'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['enigma_mass', 'mass_edit_enigmas'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'users' => ['Add User' => ['user', 'add_reguser'], 'Groups' => ['groups', 'mng_groups'], 'Packages' => ['packages', 'mng_packages'], 'Subresellers' => ['subresellers', 'subreseller'], 'Client Logs' => ['client_logs', 'client_request_log'], 'Credit Logs' => ['credit_logs', 'credits_log'], 'Reseller Logs' => ['user_logs', 'reg_userlog'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Mass Edit' => ['user_mass', 'mass_edit_users'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'bouquet' => ['Manage Bouquets' => ['bouquets', 'bouquets'], 'Sort Bouquet' => ['bouquet_sort?id=' . $rID, 'edit_bouquet']],
			'bouquet_sort' => ['Manage Bouquets' => ['bouquets', 'bouquets'], 'Edit Bouquet' => ['bouquet?id=' . $rID, 'edit_bouquet']],
			'bouquet_order' => ['Manage Bouquets' => ['bouquets', 'bouquets'], 'Add Bouquet' => ['bouquet', 'add_bouquet']],
			'archive' => ['View Stream' => ['stream_view?id=' . $rID, 'streams'], 'Edit Stream' => ['stream?id=' . $rID, 'edit_stream'], 'Create Recording' => ['record', 'add_movie'], 'Manage Streams' => ['streams', 'streams']],
			'asns' => ['Quick Tools' => ['quick_tools', 'quick_tools']],
			'backups' => ['General Settings' => ['settings', 'settings'], 'Cache Settings' => ['cache', 'database'], 'Modules' => ['modules', 'settings']],
			'cache' => ['General Settings' => ['settings', 'settings'], 'Backup Settings' => ['backups', 'database'], 'Modules' => ['modules', 'settings']],
			'settings' => ['Backup Settings' => ['backups', 'database'], 'Cache Settings' => ['cache', 'database'], 'Modules' => ['modules', 'settings']],
			'modules' => ['General Settings' => ['settings', 'settings'], 'Backup Settings' => ['backups', 'database'], 'Cache Settings' => ['cache', 'database']],
			'channel_order' => ['Categories' => ['stream_categories', 'categories'], 'Bouquets' => ['bouquets', 'bouquets']],
			'bouquets' => ['Add Bouquet' => ['bouquet', 'add_bouquet'], 'Order Bouquets' => ($rMobile ? [] : ['bouquet_order', 'edit_bouquet']), 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Categories' => ['stream_categories', 'categories']],
			'stream_categories' => ['Add Category' => ['stream_category', 'add_cat'], 'Channel Order' => ($rMobile ? [] : ['channel_order', 'channel_order']), 'Bouquets' => ['bouquets', 'bouquets']],
			'client_logs' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'credit_logs' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'user_logs' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'stream_errors' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'line_activity' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'Clear Logs' => [null, null, 'id="btn-clear-logs"']],
			'code' => ['Access Codes' => ['codes', 'add_code']],
			'codes' => ['Add Code' => ['code', 'add_code']],
			'hmacs' => ['Add HMAC' => ['hmac', 'add_hmac']],
			'hmac' => ['HMAC Keys' => ['hmacs', 'add_hmac']],
			'stream' => ['View Stream' => ['stream_view?id=' . $rID, 'streams'], 'Import' => ['stream?import', 'import_streams'], 'Add Single' => ['stream', 'add_stream'], 'Manage Streams' => ['streams', 'streams'], 'Import & Review' => ($rMobile ? [] : ['review?type=1', 'import_streams'])],
			'movie' => ['View Movie' => ['stream_view?id=' . $rID, 'movies'], 'Import' => ['movie?import', 'import_movies'], 'Add Single' => ['movie', 'add_movie'], 'Manage Movies' => ['movies', 'movies'], 'Import & Review' => ($rMobile ? [] : ['review?type=2', 'import_movies'])],
			// Primary offers the OPPOSITE mode to the one currently open.
			'episode' => ($rMulti
				? ['Add Single' => ['episode?sid=' . $rSID, 'add_episode'], 'Add Multiple' => ['episode?sid=' . $rSID . '&multi', 'add_episode'], 'View Episodes' => ['episodes?series=' . $rSID, 'episodes'], 'Manage Series' => ['series', 'series']]
				: ['Add Multiple' => ['episode?sid=' . $rSID . '&multi', 'add_episode'], 'Add Single' => ['episode?sid=' . $rSID, 'add_episode'], 'View Episodes' => ['episodes?series=' . $rSID, 'episodes'], 'Manage Series' => ['series', 'series']]),
			'serie' => ['Import' => ['serie?import', 'import_streams'], 'Add Single' => ['serie', 'add_series'], 'Manage Series' => ['series', 'series'], 'View Episodes' => ['episodes?series=' . $rID, 'episodes']],
			'created_channel' => ['View Channel' => ['stream_view?id=' . $rID, 'streams'], 'Manage Channels' => ['created_channels', 'streams']],
			'epg' => ["Manage EPG's" => ['epgs', 'epg']],
			'epgs' => ['Add EPG' => ['epg', 'add_epg'], 'Force Reload' => [null, 'add_epg', 'onClick="forceUpdate();" id="force_update"']],
			'fingerprint' => ['Manage Streams' => ['streams', 'streams']],
			'group' => ['Manage Groups' => ['groups', 'mng_groups']],
			'groups' => ['Add Group' => ['group', 'add_group']],
			'package' => ['Manage Packages' => ['packages', 'mng_packages']],
			'packages' => ['Add Package' => ['package', 'add_packages']],
			'provider' => ['Providers' => ['providers', 'streams']],
			'providers' => ['Add Provider' => ['provider', 'streams']],
			'ip' => ['Blocked IPs' => ['ips', 'block_ips']],
			'ips' => ['Block IP' => ['ip', 'block_ips'], 'Flush Blocks' => ['ips?flush=1', 'block_ips']],
			'isp' => ['Blocked ISPs' => ['isps', 'block_isps']],
			'isps' => ['Block ISP' => ['isp', 'block_isps']],
			'line' => ['Manage Lines' => ['lines', 'users']],
			'user' => ['Manage Users' => ['users', 'mng_regusers']],
			'mag' => ['MAG Devices' => ['mags', 'manage_mag']],
			'enigma' => ['Enigma Devices' => ['enigmas', 'manage_e2']],
			'line_ips' => ['Manage Lines' => ['lines', 'users']],
			'line_mass' => ['Manage Lines' => ['lines', 'users'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools']],
			'user_mass' => ['Manage Users' => ['users', 'mng_regusers'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools']],
			'mag_mass' => ['Manage Devices' => ['mags', 'manage_mag'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools']],
			'enigma_mass' => ['Manage Devices' => ['enigmas', 'manage_e2'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools']],
			'stream_mass' => ['Manage Streams' => ['streams', 'streams'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools']],
			'created_channel_mass' => ['Manage Channels' => ['created_channels', 'streams'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools']],
			'movie_mass' => ['Manage Movies' => ['movies', 'movies'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools']],
			'radio_mass' => ['Manage Stations' => ['radios', 'radio'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools']],
			'series_mass' => ['Manage Series' => ['series', 'series'], 'Manage Episodes' => ['episodes', 'episodes'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools']],
			'episodes_mass' => ['Manage Episodes' => ['episodes', 'episodes'], 'Manage Series' => ['series', 'series'], 'Mass Delete' => ['mass_delete', 'mass_delete'], 'Quick Tools' => ['quick_tools', 'quick_tools'], 'Stream Tools' => ['stream_tools', 'stream_tools']],
			'mag_events' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"'], 'MAG Devices' => ['mags', 'manage_mag']],
			'login_logs' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'mysql_syslog' => ['Export as CSV' => [null, null, 'id="btn-export-csv"'], 'Export as JSON' => [null, null, 'id="btn-export-json"']],
			'mass_delete' => ['Manage Streams' => ['streams', 'streams'], 'Manage Channels' => ['created_channels', 'streams'], 'Manage Series' => ['series', 'series'], 'Manage Episodes' => ['episodes', 'episodes'], 'Manage Stations' => ['radios', 'radio'], 'Manage Lines' => ['lines', 'users'], 'Manage Users' => ['users', 'mng_regusers'], 'Manage MAGs' => ['mags', 'manage_mag'], 'Manage Enigmas' => ['enigmas', 'manage_e2']],
			'quick_tools' => ['Stream Tools' => ['stream_tools', 'stream_tools']],
			'stream_tools' => ['Quick Tools' => ['quick_tools', 'quick_tools']],
			'profile' => ['Manage Profiles' => ['profiles', 'tprofiles']],
			'profiles' => ['Create Profile' => ['profile', 'tprofile']],
			'rtmp_ips' => ['Add IP' => ['rtmp_ip', 'add_rtmp']],
			'rtmp_ip' => ['RTMP IPs' => ['rtmp_ips', 'rtmp']],
			'server' => ['View Server' => ['server_view?id=' . $rID, 'servers'], 'Manage Servers' => ['servers', 'servers']],
			'proxy' => ['View Proxy' => ['server_view?id=' . $rID, 'servers'], 'Manage Proxies' => ['proxies', 'servers']],
			'server_install' => ['Manage Servers' => ['servers', 'servers'], 'Manage Proxies' => ['proxies', 'servers']],
			'servers' => ['Install Server' => ['server_install', 'add_server'], 'Server Order' => ['server_order', 'servers'], 'Proxies' => ['proxies', 'servers'], 'Process Monitor' => ['process_monitor', 'process_monitor'], 'Update All Servers' => [null, 'servers', 'onClick="updateAll();"'], 'Update All Binaries' => [null, 'servers', 'onClick="updateBinaries();"'], 'Restart All Services' => [null, 'servers', 'onClick="restartServices();"']],
			'server_order' => ['Servers' => ['servers', 'servers'], 'Proxies' => ['proxies', 'servers'], 'Process Monitor' => ['process_monitor', 'process_monitor']],
			'proxies' => ['Install Proxy' => ['server_install?proxy=1', 'add_server'], 'Servers' => ['servers', 'servers'], 'Process Monitor' => ['process_monitor', 'process_monitor']],
			'stream_category' => ['Manage Categories' => ['stream_categories', 'categories']],
			'ticket' => ['View Ticket' => ['ticket_view?id=' . $rID, 'ticket'], 'View Tickets' => ['tickets', 'manage_tickets']],
			'ticket_view' => ['Add Response' => ['ticket?id=' . $rID, 'ticket'], 'View Tickets' => ['tickets', 'manage_tickets']],
			'useragent' => ['Blocked User-Agents' => ['useragents', 'block_uas']],
			'useragents' => ['Block User-Agent' => ['useragent', 'block_uas']],
		];

		$rDropdown['servers'] = ['Proxies' => ['proxies', 'servers'], 'Process Monitor' => ['process_monitor', 'process_monitor']];

		// Merge module-contributed buttons (TopbarProviderInterface). Modules can
		// both inject entries into an existing core page (e.g. 'movies') — appended
		// after the core entries — and register a brand-new page of their own
		// (e.g. 'watch'). Populated by ModuleLoader::bootAll before any render.
		foreach (TopbarRegistry::pages() as $rPage) {
			foreach (TopbarRegistry::forPage($rPage) as $rLabel => $rSpec) {
				$rDropdown[$rPage][$rLabel] = $rSpec;
			}
		}

		return $rDropdown;
	}

	/**
	 * Ordered, permission-filtered items for one page, ready to render.
	 *
	 * @param array{rID?:int|null,rSID?:int|null,rMobile?:bool,rImport?:bool} $ctx
	 * @return list<array{label:string,url:?string,attr:?string,id:?string,logType:?string,primary:bool}>
	 */
	public static function items(string $page, array $ctx = []): array {
		$rConfig = self::config($ctx);
		if (!isset($rConfig[$page]) || !is_array($rConfig[$page])) {
			return [];
		}

		$rItems = [];
		$rFirst = true;
		foreach ($rConfig[$page] as $rLabel => $rData) {
			if (!is_string($rLabel) || $rLabel === '' || !is_array($rData)) {
				continue;
			}
			// Export actions: only on log/report pages (core list OR a page a
			// module marked via TopbarRegistry), and only with the permission the
			// export itself asks for (BackupAjaxController::report).
			if (in_array($rLabel, ['Export as CSV', 'Export as JSON'], true)) {
				$rIsExportPage = in_array($page, self::EXPORT_PAGES, true) || TopbarRegistry::isExportPage($page);
				if (!$rIsExportPage || !Authorization::check('adv', 'database')) {
					continue;
				}
			}
			// Per-item permission gate.
			if (!empty($rData[1]) && !Authorization::check('adv', $rData[1])) {
				continue;
			}

			$rAttr = (count($rData) === 3 && !empty($rData[2])) ? (string) $rData[2] : null;
			$rId = null;
			if ($rAttr !== null && preg_match('/id="([^"]+)"/', $rAttr, $rM)) {
				$rId = $rM[1];
			}

			$rItems[] = [
				'label'   => $rLabel,
				'url'     => (!empty($rData[0]) ? (string) $rData[0] : null),
				'attr'    => $rAttr,
				'id'      => $rId,
				'logType' => ($rId === 'btn-clear-logs' ? (self::LOG_TYPES[$page] ?? TopbarRegistry::logType($page)) : null),
				'primary' => $rFirst,
			];
			$rFirst = false;
		}

		return $rItems;
	}
}
