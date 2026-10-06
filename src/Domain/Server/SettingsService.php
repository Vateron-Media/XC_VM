<?php

namespace XcVm\Domain\Server;

use XcVm\Core\Audit\AdminAudit;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Settings\CrontabChangedEvent;
use XcVm\Core\Events\Settings\SettingsChangedEvent;
use XcVm\Core\Events\Stream\StreamArgumentsChangedEvent;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Fanout\FanoutConfig;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * SettingsService — settings service
 *
 * @package XC_VM_Domain_Server
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsService {
	use DatabaseAware;

	/** Settings columns MAIN keeps for the cluster API itself (ClusterEndpoint), never set from a form (edit(), editBackup()). */
	private const CLUSTER_STATE = ['cluster_policy_ver', 'cluster_legacy_ports', 'cluster_legacy_urls'];

	/** The settings form's checkboxes, besides the cluster switches (checkboxes()). */
	private const CHECKBOXES = ['alternative_titles', 'fallback_parser', 'php_loopback', 'restreamer_bypass_proxy', 'request_prebuffer', 'enable_search', 'on_demand_checker', 'ondemand_balance_equal', 'disable_mag_token', 'allow_cdn_access', 'dts_legacy_ffmpeg', 'mag_load_all_channels', 'disable_xmltv_restreamer', 'disable_playlist_restreamer', 'ffmpeg_warnings', 'reseller_ssl_domain', 'extract_subtitles', 'show_category_duplicates', 'vod_sort_newest', 'header_stats', 'mag_keep_extension', 'keep_protocol', 'read_native_hls', 'player_allow_playlist', 'player_allow_bouquet', 'player_hide_incompatible', 'player_allow_hevc', 'force_epg_timezone', 'check_vod', 'ignore_keyframes', 'save_login_logs', 'save_restart_logs', 'mag_legacy_redirect', 'restrict_playlists', 'monitor_connection_status', 'kill_rogue_ffmpeg', 'show_images', 'on_demand_instant_off', 'on_demand_failure_exit', 'playlist_from_mysql', 'ignore_invalid_users', 'block_proxies', 'block_streaming_servers', 'ip_subnet_match', 'auto_unban_ip', 'debug_show_errors', 'enable_debug_stalker', 'restart_php_fpm', 'restream_deny_unauthorised', 'api_probe', 'legacy_panel_api', 'hide_failures', 'verify_host', 'encrypt_playlist', 'encrypt_playlist_restreamer', 'mag_disable_ssl', 'legacy_get', 'legacy_xmltv', 'save_closed_connection', 'show_tickets', 'stream_logs_save', 'client_logs_save', 'streams_grouped', 'cloudflare', 'cleanup', 'dashboard_stats', 'dashboard_status', 'dashboard_map', 'recaptcha_enable', 'ip_logout', 'disable_player_api', 'disable_playlist', 'disable_xmltv', 'disable_enigma2', 'disable_ministra', 'enable_isp_lock', 'block_svp', 'disable_ts', 'disable_ts_allow_restream', 'disable_hls', 'disable_hls_allow_restream', 'case_sensitive_line', 'county_override_1st', 'disallow_2nd_ip_con', 'use_mdomain_in_lists', 'encrypt_hls', 'disallow_empty_user_agents', 'detect_restream_block_user', 'download_images', 'api_redirect', 'use_buffer', 'audio_restart_loss', 'show_isps', 'priority_backup', 'rtmp_random', 'show_connected_video', 'show_not_on_air_video', 'show_banned_video', 'show_expired_video', 'show_expiring_video', 'show_all_category_mag', 'always_enabled_subtitles', 'enable_connection_problem_indication', 'show_tv_channel_logo', 'show_channel_logo_in_preview', 'disable_trial', 'restrict_same_ip', 'fanout_source_insecure', 'fanout_enabled', 'fanout_supervise', 'secure_stream_tokens', 'js_navigate', 'api_legacy_keys', 'maintenance_mode', 'reminders_email', 'reminders_mag', 'reminders_webhook'];

	/** The settings that decide the transport policy's URLs besides MAIN's row: a change of them is announced (edit()). */
	private const CLUSTER_POLICY = ['cluster_transport', 'cluster_main_host'];

	/**
	 * Settings were saved: tell the listeners (the node replica's ETag cache,
	 * so nodes see the change at their next poll).
	 *
	 * @param array<string, mixed> $rPrevious Settings before the save.
	 * @param array<string, mixed> $rSaved The keys saved.
	 */
	private static function saved(array $rPrevious, array $rSaved): void {
		// The admin action trail names the settings a save changed, never their values.
		$rChanged = array_keys(array_filter($rSaved, static fn($rValue, $rKey): bool => (string) json_encode($rValue) !== (string) json_encode($rPrevious[$rKey] ?? null), ARRAY_FILTER_USE_BOTH));
		AdminAudit::note(['changed' => mb_substr(implode(',', $rChanged), 0, 1000)]);
		EventDispatcher::dispatch(new SettingsChangedEvent($rPrevious, array_merge($rPrevious, $rSaved), (int) ($GLOBALS['rUserInfo']['id'] ?? 0), microtime(true)));
	}

	/**
	 * The settings form's checkboxes. An unchecked box posts nothing, so a full
	 * save (edit()) stores 0 for every one of these it does not receive.
	 */
	public static function checkboxes(): array {
		return array_merge(self::CHECKBOXES, ClusterSettings::switches());
	}

	/**
	 * The fanout idle buffer ratio as the daemon takes it (0.1-1, two decimals),
	 * or null when the submitted value is not a number.
	 *
	 * A decimal comma counts as a point, as a pt/ru/de keyboard types it. The form
	 * used to strip everything but digits from this field, so 0.25 arrived as 025,
	 * which a decimal(3,2) column refuses.
	 *
	 * @param mixed $rValue The submitted value.
	 */
	public static function normalizeIdleBufferRatio(mixed $rValue): ?float {
		$rValue = str_replace(',', '.', trim((string) $rValue));
		if ($rValue === '' || !is_numeric($rValue)) {
			return null;
		}
		return round(min(1.0, max(0.1, (float) $rValue)), 2);
	}

	/**
	 * Run the cluster keys of a settings save through ClusterSettings::normalize()
	 * in place. Returns the translated refusals (empty when the values stand).
	 *
	 * @param array<string, mixed> $rArray Settings about to be written (modified in place).
	 * @return list<string>
	 */
	private static function normalizeCluster(array &$rArray, object $db): array {
		$rKeys = array_intersect_key($rArray, array_flip(ClusterSettings::keys()));
		if ($rKeys === []) {
			return [];
		}
		$rMain = self::mainServer();
		$rCurrent = SettingsManager::getAll();
		$rEnv = [
			'extension_ok' => ClusterCryptoFactory::available(),
			'api_mode_allowed' => false, // Phase 9 (cutover) enables API-only new nodes
			'credential_free_config' => CredentialFreeConfig::supported(),
		];
		if (($rKeys['cluster_transport'] ?? null) === 'https_required') {
			$rEnv['https_ok'] = ClusterSettings::httpsSelfProbe($rMain)['ok'];
			// Every active node must have reached MAIN over HTTPS already: its
			// agent says so with the `https` feature once MAIN has answered it
			// there. A node that has not would be left talking to nobody.
			$rEnv['nodes_https_ok'] = NodeRegistry::allActiveHaveFeature('https');
		}
		[$rValues, $rErrors] = ClusterSettings::normalize($rKeys, $rMain, $rCurrent, $rEnv);
		if (($rValues['cluster_api_enabled'] ?? 0) === 1 && empty($rCurrent['cluster_api_enabled'])) {
			// Create the cluster root from php-fpm, so its files belong to the
			// user that serves /cluster/v1/. Idempotent.
			try {
				ClusterMeta::init(ClusterCryptoFactory::create());
			} catch (\Throwable) {
				unset($rValues['cluster_api_enabled']);
				$rErrors[] = ['cluster_api_enabled', 'cluster_error_extension'];
			}
		}
		foreach (array_keys($rKeys) as $rKey) {
			unset($rArray[$rKey]);
		}
		$rArray = array_merge($rArray, $rValues);
		return array_map(static fn($rError) => Translator::get($rError[1]), $rErrors);
	}

	/**
	 * A save that changes `cluster_api_port`: nginx gets the new port before
	 * the value is stored (ClusterNginxConfig::stageApiPort(), with the stored
	 * settings and the main server's row). Null when the port stays, or on a
	 * build without the cluster domain (LB).
	 *
	 * @param array<string, mixed> $rArray Settings about to be written.
	 * @return array{refused: ?string, error: string, record: array{0: int, 1: int, 2: array<string, mixed>, 3: array<string, mixed>}}|null
	 */
	private static function stageClusterApiPort(array $rArray): ?array {
		if (!array_key_exists('cluster_api_port', $rArray) || !class_exists(ClusterNginxConfig::class)) {
			return null;
		}
		$rCurrent = SettingsManager::getAll();
		return ClusterNginxConfig::stageApiPort(intval($rCurrent['cluster_api_port'] ?? 0), intval($rArray['cluster_api_port']), $rCurrent, self::mainServer());
	}

	/**
	 * The main server's `servers` row, as the servers cache holds it (empty
	 * when there is none).
	 *
	 * @return array<string, mixed>
	 */
	private static function mainServer(): array {
		foreach (ServerRepository::getAll() as $rServer) {
			if (!empty($rServer['is_main'])) {
				return $rServer;
			}
		}
		return [];
	}

	/**
	 * Does a save change the transport policy the nodes follow
	 * (ClusterPolicy::current): the transport, or MAIN's DNS name in its URLs?
	 * Compared with the settings cache, and asked only without ClusterEndpoint
	 * (the LB build). On MAIN a save that posts either is stored through
	 * ClusterEndpoint::storeSettings(), which compares it with the database.
	 * A new `cluster_api_port` is announced by ClusterEndpoint once stored.
	 *
	 * @param array<string, mixed> $rArray Settings about to be written.
	 */
	private static function changesClusterPolicy(array $rArray): bool {
		$rCurrent = SettingsManager::getAll();
		foreach (self::CLUSTER_POLICY as $rKey) {
			if (array_key_exists($rKey, $rArray) && (string) $rArray[$rKey] !== (string) ($rCurrent[$rKey] ?? '')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save general panel settings from admin form data.
	 *
	 * @param array $rData Submitted settings.
	 * @return array Result status payload.
	 */
	public static function edit(array $rData) {
		$db = self::db();
		// The stream arguments' defaults: a change reaches the streams that use
		// them (their R2 records carry the definition).
		// Form field => argument_key: the proxy's field is http_proxy, its row `proxy`.
		$rArgumentKeys = ['user_agent' => 'user_agent', 'http_proxy' => 'proxy', 'cookie' => 'cookie', 'headers' => 'headers'];
		$db->query('SELECT `argument_key`, `argument_default_value` FROM `streams_arguments` WHERE `argument_key` IN (?, ?, ?, ?);', ...array_values($rArgumentKeys));
		$rWas = array_column($db->get_rows() ?: [], 'argument_default_value', 'argument_key');
		$rChanged = [];
		foreach ($rArgumentKeys as $rField => $rKey) {
			$rValue = ($rData[$rField] ?: null);
			$db->query('UPDATE `streams_arguments` SET `argument_default_value` = ? WHERE `argument_key` = ?;', $rValue, $rKey);
			if (array_key_exists($rKey, $rWas) && (string) $rWas[$rKey] !== (string) $rValue) {
				$rChanged[] = $rKey;
			}
			unset($rData[$rField]);
		}
		if ($rChanged !== []) {
			EventDispatcher::dispatch(new StreamArgumentsChangedEvent($rChanged));
		}

		// The genre mapping (Settings → VOD Import) lives in its own table.
		CategoryService::saveGenreMap($rData);

		$rArray = QueryHelper::verifyPostTable('settings', $rData, true);
		// MAIN's own cluster state, never the form's: a POST cannot rewind the
		// transport policy's version or drop a port kept for the nodes.
		$rArray = array_diff_key($rArray, array_flip(self::CLUSTER_STATE));

		$isFullForm = isset($rData['submit_settings']);
		foreach (self::checkboxes() as $rSetting) {
			if (isset($rData[$rSetting])) {
				$rArray[$rSetting] = 1;
			} elseif ($isFullForm) {
				$rArray[$rSetting] = 0;
			}
		}

		// Maintenance mode's end: the form sends a local date and time, the column
		// holds a Unix time (0: no end).
		if (array_key_exists('maintenance_until', $rArray) && !is_numeric($rArray['maintenance_until'])) {
			$rArray['maintenance_until'] = (int) (strtotime((string) $rArray['maintenance_until']) ?: 0);
		}

		// "Responsive Tables" is presented as a positive toggle (on = columns collapse
		// on narrow screens) but stored in the inverse `disable_table_responsive` column:
		// checked → responsive on → 0; unchecked → full-width tables → 1.
		$rArray['disable_table_responsive'] = empty($rData['responsive_tables']) ? 1 : 0;

		if (array_key_exists('fanout_idle_buffer_ratio', $rArray)) {
			$rRatio = self::normalizeIdleBufferRatio($rArray['fanout_idle_buffer_ratio']);
			if ($rRatio === null) {
				unset($rArray['fanout_idle_buffer_ratio']); // not a number: keep the stored value
			} else {
				$rArray['fanout_idle_buffer_ratio'] = $rRatio;
			}
		}

		// Cluster API settings: clamped, and refused where clamping would change
		// what the admin meant (a taken port, HTTPS required without HTTPS, …).
		// A refusal fails the whole save, so nothing is half-applied.
		$rClusterErrors = self::normalizeCluster($rArray, $db);
		if ($rClusterErrors !== []) {
			return ['status' => STATUS_INVALID_DATA, 'data' => ['message' => implode(' ', $rClusterErrors)]];
		}

		if (!isset($rData['allowed_stb_types_for_local_recording'])) {
			$rArray['allowed_stb_types_for_local_recording'] = [];
		}

		if (!isset($rData['allowed_stb_types'])) {
			$rArray['allowed_stb_types'] = [];
		}

		if (!isset($rData['maxmind_editions'])) {
			$rArray['maxmind_editions'] = [];
		}

		if (!isset($rData['shared_mount_prefixes'])) {
			$rArray['shared_mount_prefixes'] = [];
		}

		if (!isset($rData['allow_countries'])) {
			$rArray['allow_countries'] = ['ALL'];
		}

		if (100 < $rArray['search_items']) {
			$rArray['search_items'] = 100;
		}

		if ($rArray['search_items'] <= 0) {
			$rArray['search_items'] = 1;
		}

		if (isset($rArray['language'])) {
			if (!in_array($rArray['language'], Translator::available(), true)) {
				$rArray['language'] = 'en';
			}
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		if (count($rPrepare['data']) <= 0) {
			return ['status' => STATUS_FAILURE];
		}

		// A new cluster_api_port: nginx serves it before it is stored, or the
		// save is refused and nothing changed.
		$rApiPort = self::stageClusterApiPort($rArray);
		if ($rApiPort !== null && $rApiPort['refused'] !== null) {
			return ['status' => STATUS_INVALID_DATA, 'data' => ['message' => trim(Translator::get($rApiPort['refused']) . ' ' . htmlspecialchars($rApiPort['error'], ENT_QUOTES))]];
		}

		// A new transport policy is announced with the save: every node sees
		// the version go up in its next heartbeat and fetches the policy, and
		// never adopts one older than it holds. On MAIN every save that posts
		// the transport or the name goes through ClusterEndpoint, which
		// compares them with the database, not with this request's settings
		// cache (another admin's save may be newer), and keeps the URLs the
		// policy no longer lists a while, in the same UPDATE (Domain\Cluster
		// is not in the LB build).
		$rPrevious = SettingsManager::getAll();
		if (array_intersect_key($rArray, array_flip(self::CLUSTER_POLICY)) !== [] && class_exists(ClusterEndpoint::class)) {
			$rStored = ClusterEndpoint::storeSettings($rPrepare['update'], $rPrepare['data'], $rArray, self::mainServer());
		} else {
			$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . (self::changesClusterPolicy($rArray) ? ', `cluster_policy_ver` = `cluster_policy_ver` + 1' : '') . ';';
			$rStored = $db->query($rQuery, ...$rPrepare['data']);
		}
		if ($rApiPort !== null) {
			// Stored: the nodes move to the new port (the old one is served for
			// 7 days). Either way nginx follows what is stored.
			ClusterNginxConfig::commitApiPort($rApiPort, (bool) $rStored);
		}
		if ($rStored) {
			// The links already in players' hands were minted under the secret
			// this save replaced: it stays readable for StreamSecret's window,
			// and the nodes get it with the replica's `secrets` section.
			if (array_key_exists('live_streaming_pass', $rArray)) {
				StreamSecret::replaced((string) ($rPrevious['live_streaming_pass'] ?? ''), (string) $rArray['live_streaming_pass']);
			}
			SettingsManager::clearCache();
			self::saved($rPrevious, $rArray);
			FanoutConfig::sync($rArray);
			// Apply the fanout switch on this node now; every other node picks it
			// up from its root cron within a minute (RootSignalsCronJob).
			if (array_key_exists('fanout_enabled', $rArray)) {
				FanoutMode::applyToNode(FanoutMode::enabled($rArray));
			}
			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_FAILURE];
	}

	/**
	 * Save backup-related settings from admin form data.
	 *
	 * @param array $rData Submitted backup settings.
	 * @return array Result status payload.
	 */
	public static function editBackup(array $rData) {
		$db = self::db();
		// This form stores any settings column it is posted: never MAIN's own
		// cluster state, nor a cluster setting, which only edit() checks and
		// announces to the nodes.
		$rArray = array_diff_key(QueryHelper::verifyPostTable('settings', $rData, true), array_flip(array_merge(self::CLUSTER_STATE, ClusterSettings::keys())));

		foreach (['dropbox_remote'] as $rSetting) {
			if (isset($rData[$rSetting])) {
				$rArray[$rSetting] = 1;
			} else {
				$rArray[$rSetting] = 0;
			}
		}

		if (!isset($rData['allowed_stb_types_for_local_recording'])) {
			$rArray['allowed_stb_types_for_local_recording'] = [];
		}

		if (!isset($rData['allowed_stb_types'])) {
			$rArray['allowed_stb_types'] = [];
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		if (count($rPrepare['data']) <= 0) {
			return ['status' => STATUS_FAILURE];
		}

		$rPrevious = SettingsManager::getAll();
		$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . ';';
		if ($db->query($rQuery, ...$rPrepare['data'])) {
			SettingsManager::clearCache();
			self::saved($rPrevious, $rArray);
			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_FAILURE];
	}

	/**
	 * Save cache/cron-related settings from admin form data.
	 *
	 * @param array $rData Submitted cache/cron settings.
	 * @return array Result status payload.
	 */
	public static function editCacheCron(array $rData) {
		$db = self::db();
		$rCheck = [false, false];
		$rCron = ['*', '*', '*', '*', '*'];
		$rPattern = '/^[0-9\/*,-]+$/';
		$rCron[0] = $rData['minute'];
		preg_match($rPattern, $rCron[0], $rMatches);
		$rCheck[0] = 0 < count($rMatches);
		$rCron[1] = $rData['hour'];
		preg_match($rPattern, $rCron[1], $rMatches);
		$rCheck[1] = 0 < count($rMatches);
		$rCronOutput = implode(' ', $rCron);

		if (isset($rData['cache_changes'])) {
			$rCacheChanges = true;
		} else {
			$rCacheChanges = false;
		}

		if ($rCheck[0] && $rCheck[1]) {
			$rPrevious = SettingsManager::getAll();
			$db->query("UPDATE `crontab` SET `time` = ? WHERE `filename` = 'cache_engine';", $rCronOutput);
			$db->query('UPDATE `settings` SET `cache_thread_count` = ?, `cache_changes` = ?;', $rData['cache_thread_count'], $rCacheChanges);

			if (file_exists(TMP_PATH . 'crontab')) {
				unlink(TMP_PATH . 'crontab');
			}

			SettingsManager::clearCache();
			self::saved($rPrevious, ['cache_thread_count' => $rData['cache_thread_count'], 'cache_changes' => $rCacheChanges]);
			EventDispatcher::dispatch(new CrontabChangedEvent());
			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_FAILURE];
	}
}
