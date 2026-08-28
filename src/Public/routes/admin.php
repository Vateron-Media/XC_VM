<?php

use XcVm\Public\Controllers\Admin\AdminLogoutController;
use XcVm\Public\Controllers\Admin\AdminResizeController;
use XcVm\Public\Controllers\Admin\Ajax\BackupAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\BlocklistAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\CacheAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\DeviceAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\EpgAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\MiscAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\PackageAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\ProviderAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\SearchAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\ServerAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\StatsAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\StreamAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\StreamToolsAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\UserAjaxController;
use XcVm\Public\Controllers\Admin\AjaxController;
use XcVm\Public\Controllers\Admin\ArchiveController;
use XcVm\Public\Controllers\Admin\AsnsController;
use XcVm\Public\Controllers\Admin\BackupsController;
use XcVm\Public\Controllers\Admin\BouquetController;
use XcVm\Public\Controllers\Admin\BouquetListController;
use XcVm\Public\Controllers\Admin\BouquetOrderController;
use XcVm\Public\Controllers\Admin\BouquetSortController;
use XcVm\Public\Controllers\Admin\CacheController;
use XcVm\Public\Controllers\Admin\ChannelOrderController;
use XcVm\Public\Controllers\Admin\ClientLogController;
use XcVm\Public\Controllers\Admin\CodeController;
use XcVm\Public\Controllers\Admin\CodeEditController;
use XcVm\Public\Controllers\Admin\CreatedChannelController;
use XcVm\Public\Controllers\Admin\CreatedChannelListController;
use XcVm\Public\Controllers\Admin\CreatedChannelMassController;
use XcVm\Public\Controllers\Admin\CreditLogsController;
use XcVm\Public\Controllers\Admin\DashboardController;
use XcVm\Public\Controllers\Admin\EditProfileController;
use XcVm\Public\Controllers\Admin\EnigmaController;
use XcVm\Public\Controllers\Admin\EnigmaMassController;
use XcVm\Public\Controllers\Admin\EnigmasController;
use XcVm\Public\Controllers\Admin\EpgController;
use XcVm\Public\Controllers\Admin\EpgListController;
use XcVm\Public\Controllers\Admin\EpgViewController;
use XcVm\Public\Controllers\Admin\EpisodeController;
use XcVm\Public\Controllers\Admin\EpisodeListController;
use XcVm\Public\Controllers\Admin\EpisodeMassController;
use XcVm\Public\Controllers\Admin\FingerprintController;
use XcVm\Public\Controllers\Admin\GroupController;
use XcVm\Public\Controllers\Admin\GroupEditController;
use XcVm\Public\Controllers\Admin\HmacController;
use XcVm\Public\Controllers\Admin\HmacEditController;
use XcVm\Public\Controllers\Admin\IpController;
use XcVm\Public\Controllers\Admin\IpEditController;
use XcVm\Public\Controllers\Admin\IspController;
use XcVm\Public\Controllers\Admin\IspEditController;
use XcVm\Public\Controllers\Admin\LineActivityController;
use XcVm\Public\Controllers\Admin\LineController;
use XcVm\Public\Controllers\Admin\LineIpsController;
use XcVm\Public\Controllers\Admin\LineListController;
use XcVm\Public\Controllers\Admin\LineMassController;
use XcVm\Public\Controllers\Admin\LiveConnectionsController;
use XcVm\Public\Controllers\Admin\LoginController;
use XcVm\Public\Controllers\Admin\LoginLogController;
use XcVm\Public\Controllers\Admin\MagController;
use XcVm\Public\Controllers\Admin\MagEventController;
use XcVm\Public\Controllers\Admin\MagMassController;
use XcVm\Public\Controllers\Admin\MagscanSettingsController;
use XcVm\Public\Controllers\Admin\MagsController;
use XcVm\Public\Controllers\Admin\MassDeleteController;
use XcVm\Public\Controllers\Admin\ModulesController;
use XcVm\Public\Controllers\Admin\MovieController;
use XcVm\Public\Controllers\Admin\MovieListController;
use XcVm\Public\Controllers\Admin\MovieMassController;
use XcVm\Public\Controllers\Admin\MysqlSyslogController;
use XcVm\Public\Controllers\Admin\OndemandController;
use XcVm\Public\Controllers\Admin\PackageController;
use XcVm\Public\Controllers\Admin\PackageEditController;
use XcVm\Public\Controllers\Admin\PanelLogController;
use XcVm\Public\Controllers\Admin\PlayerEmbedController;
use XcVm\Public\Controllers\Admin\PostController;
use XcVm\Public\Controllers\Admin\ProcessMonitorController;
use XcVm\Public\Controllers\Admin\ProfileController;
use XcVm\Public\Controllers\Admin\ProfileEditController;
use XcVm\Public\Controllers\Admin\ProviderController;
use XcVm\Public\Controllers\Admin\ProviderEditController;
use XcVm\Public\Controllers\Admin\ProxiesController;
use XcVm\Public\Controllers\Admin\ProxyController;
use XcVm\Public\Controllers\Admin\QueueController;
use XcVm\Public\Controllers\Admin\QuickToolsController;
use XcVm\Public\Controllers\Admin\RadioController;
use XcVm\Public\Controllers\Admin\RadioListController;
use XcVm\Public\Controllers\Admin\RadioMassController;
use XcVm\Public\Controllers\Admin\RecordController;
use XcVm\Public\Controllers\Admin\RestreamLogController;
use XcVm\Public\Controllers\Admin\ReviewController;
use XcVm\Public\Controllers\Admin\RtmpIpController;
use XcVm\Public\Controllers\Admin\RtmpIpEditController;
use XcVm\Public\Controllers\Admin\RtmpMonitorController;
use XcVm\Public\Controllers\Admin\SerieController;
use XcVm\Public\Controllers\Admin\SeriesListController;
use XcVm\Public\Controllers\Admin\SeriesMassController;
use XcVm\Public\Controllers\Admin\ServerController;
use XcVm\Public\Controllers\Admin\ServerInstallController;
use XcVm\Public\Controllers\Admin\ServerListController;
use XcVm\Public\Controllers\Admin\ServerOrderController;
use XcVm\Public\Controllers\Admin\ServerViewController;
use XcVm\Public\Controllers\Admin\SessionController;
use XcVm\Public\Controllers\Admin\SettingsController;
use XcVm\Public\Controllers\Admin\SetupController;
use XcVm\Public\Controllers\Admin\StreamCategoriesController;
use XcVm\Public\Controllers\Admin\StreamCategoryController;
use XcVm\Public\Controllers\Admin\StreamController;
use XcVm\Public\Controllers\Admin\StreamErrorsController;
use XcVm\Public\Controllers\Admin\StreamListController;
use XcVm\Public\Controllers\Admin\StreamMassController;
use XcVm\Public\Controllers\Admin\StreamRankController;
use XcVm\Public\Controllers\Admin\StreamReviewController;
use XcVm\Public\Controllers\Admin\StreamToolsController;
use XcVm\Public\Controllers\Admin\StreamViewController;
use XcVm\Public\Controllers\Admin\TableController;
use XcVm\Public\Controllers\Admin\TheftDetectionController;
use XcVm\Public\Controllers\Admin\TicketController;
use XcVm\Public\Controllers\Admin\TicketsController;
use XcVm\Public\Controllers\Admin\TicketViewController;
use XcVm\Public\Controllers\Admin\TmdbController;
use XcVm\Public\Controllers\Admin\UseragentController;
use XcVm\Public\Controllers\Admin\UseragentsController;
use XcVm\Public\Controllers\Admin\UserController;
use XcVm\Public\Controllers\Admin\UserLogsController;
use XcVm\Public\Controllers\Admin\UserMassController;
use XcVm\Public\Controllers\Admin\UsersController;

/**
 * Admin Routes Definition
 *
 * Defines all HTTP routes for the administrative panel.
 * Maps URL endpoints to their corresponding controller actions.
 *
 * Loaded by the Front Controller when scope = 'admin'.
 *
 * @see public/index.php
 * @see core/Http/Router.php
 *
 * @package XC_VM_Public_Routes
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

/** @var \XcVm\Core\Http\Router $router Injected by the Front Controller (index.php). */

// ─── List Pages ────────────────────────────────────

$router->get('ips', [IpController::class, 'index']);
$router->get('isps', [IspController::class, 'index']);
$router->get('hmacs', [HmacController::class, 'index']);
$router->get('groups', [GroupController::class, 'index']);
$router->get('codes', [CodeController::class, 'index']);
$router->get('packages', [PackageController::class, 'index']);
$router->get('rtmp_ips', [RtmpIpController::class, 'index']);
$router->get('profiles', [ProfileController::class, 'index']);
$router->get('providers', [ProviderController::class, 'index']);
$router->get('theft_detection', [TheftDetectionController::class, 'index']);

// ─── Bouquets ──────────────────────────────────────

$router->get('bouquets', [BouquetListController::class, 'index']);
$router->get('bouquet', [BouquetController::class, 'index']);
$router->get('bouquet_order', [BouquetOrderController::class, 'index']);
$router->get('bouquet_sort', [BouquetSortController::class, 'index']);

// ─── Simple Listings ───────────────────────────────

$router->get('login_logs', [LoginLogController::class, 'index']);
$router->get('mysql_syslog', [MysqlSyslogController::class, 'index']);
$router->get('mag_events', [MagEventController::class, 'index']);
$router->get('restream_logs', [RestreamLogController::class, 'index']);
$router->get('panel_logs', [PanelLogController::class, 'index']);
$router->get('epgs', [EpgListController::class, 'index']);

// ─── Servers ───────────────────────────────────────

$router->get('servers', [ServerListController::class, 'index']);
$router->get('server', [ServerController::class, 'index']);
$router->get('server_view', [ServerViewController::class, 'index']);
$router->get('server_install', [ServerInstallController::class, 'index']);

// ─── Settings ──────────────────────────────────────

$router->get('settings', [SettingsController::class, 'index']);
$router->any('modules', [ModulesController::class, 'index']);
$router->get('magscan_settings', [MagscanSettingsController::class, 'index']);

// ─── Lines ─────────────────────────────────────────

$router->get('lines', [LineListController::class, 'index']);
$router->get('line', [LineController::class, 'index']);
$router->get('line_mass', [LineMassController::class, 'index']);
$router->get('line_activity', [LineActivityController::class, 'index']);
$router->get('line_ips', [LineIpsController::class, 'index']);
$router->get('client_logs', [ClientLogController::class, 'index']);

// ─── VOD ───────────────────────────────────────────

$router->get('movies', [MovieListController::class, 'index']);
$router->get('movie', [MovieController::class, 'index']);
$router->get('movie_mass', [MovieMassController::class, 'index']);
$router->get('series', [SeriesListController::class, 'index']);
$router->get('serie', [SerieController::class, 'index']);
$router->get('series_mass', [SeriesMassController::class, 'index']);
$router->get('episodes', [EpisodeListController::class, 'index']);
$router->get('episode', [EpisodeController::class, 'index']);
$router->get('episodes_mass', [EpisodeMassController::class, 'index']);
$router->get('ondemand', [OndemandController::class, 'index']);

// ─── Streams ───────────────────────────────────────

$router->get('streams', [StreamListController::class, 'index']);
$router->get('stream', [StreamController::class, 'index']);
$router->get('stream_mass', [StreamMassController::class, 'index']);
$router->get('stream_categories', [StreamCategoriesController::class, 'index']);
$router->get('stream_category', [StreamCategoryController::class, 'index']);
$router->get('stream_errors', [StreamErrorsController::class, 'index']);
$router->get('stream_rank', [StreamRankController::class, 'index']);
$router->any('stream_review', [StreamReviewController::class, 'index']);
$router->get('stream_tools', [StreamToolsController::class, 'index']);
$router->get('stream_view', [StreamViewController::class, 'index']);
$router->get('channel_order', [ChannelOrderController::class, 'index']);
$router->get('created_channel', [CreatedChannelController::class, 'index']);
$router->get('created_channels', [CreatedChannelListController::class, 'index']);
$router->get('created_channel_mass', [CreatedChannelMassController::class, 'index']);
$router->get('live_connections', [LiveConnectionsController::class, 'index']);
$router->get('rtmp_monitor', [RtmpMonitorController::class, 'index']);
$router->get('radio', [RadioController::class, 'index']);
$router->get('radios', [RadioListController::class, 'index']);
$router->get('radio_mass', [RadioMassController::class, 'index']);
$router->get('record', [RecordController::class, 'index']);

// ─── Pilot Detail Pages ────────────────────────────

$router->get('ip', [IpEditController::class, 'index']);
$router->get('isp', [IspEditController::class, 'index']);
$router->get('hmac', [HmacEditController::class, 'index']);
$router->get('group', [GroupEditController::class, 'index']);
$router->get('code', [CodeEditController::class, 'index']);
$router->get('package', [PackageEditController::class, 'index']);
$router->get('rtmp_ip', [RtmpIpEditController::class, 'index']);
$router->get('profile', [ProfileEditController::class, 'index']);
$router->get('provider', [ProviderEditController::class, 'index']);

// ─── Users / Agents ────────────────────────────────

$router->get('users', [UsersController::class, 'index']);
$router->any('user', [UserController::class, 'index']);
$router->any('user_mass', [UserMassController::class, 'index']);
$router->get('user_logs', [UserLogsController::class, 'index']);
$router->get('useragents', [UseragentsController::class, 'index']);
$router->any('useragent', [UseragentController::class, 'index']);

// ─── Devices MAG / Enigma ──────────────────────────

$router->get('mags', [MagsController::class, 'index']);
$router->any('mag', [MagController::class, 'index']);
$router->any('mag_mass', [MagMassController::class, 'index']);
$router->get('enigmas', [EnigmasController::class, 'index']);
$router->any('enigma', [EnigmaController::class, 'index']);
$router->any('enigma_mass', [EnigmaMassController::class, 'index']);

// ─── Tickets / EPG ─────────────────────────────────

$router->get('tickets', [TicketsController::class, 'index']);
$router->any('ticket', [TicketController::class, 'index']);
$router->get('ticket_view', [TicketViewController::class, 'index']);
$router->any('epg', [EpgController::class, 'index']);
$router->get('epg_view', [EpgViewController::class, 'index']);

// ─── System ────────────────────────────────────────

$router->get('dashboard', [DashboardController::class, 'index']);
$router->get('backups', [BackupsController::class, 'index']);
$router->any('cache', [CacheController::class, 'index']);
$router->any('process_monitor', [ProcessMonitorController::class, 'index']);
$router->get('queue', [QueueController::class, 'index']);
$router->any('quick_tools', [QuickToolsController::class, 'index']);
$router->any('mass_delete', [MassDeleteController::class, 'index']);
$router->any('server_order', [ServerOrderController::class, 'index']);

// ─── Misc ──────────────────────────────────────────

$router->get('credit_logs', [CreditLogsController::class, 'index']);
$router->get('edit_profile', [EditProfileController::class, 'index']);
$router->get('fingerprint', [FingerprintController::class, 'index']);
$router->get('proxies', [ProxiesController::class, 'index']);
$router->any('proxy', [ProxyController::class, 'index']);
$router->any('review', [ReviewController::class, 'index']);
$router->any('archive', [ArchiveController::class, 'index']);
$router->get('asns', [AsnsController::class, 'index']);
$router->get('resize', [AdminResizeController::class, 'index']);

// ─── Formerly unrouted pages ─────────────────────────

$router->get('logout', [AdminLogoutController::class, 'index']);
$router->any('player', [PlayerEmbedController::class, 'index']);
$router->any('post', [PostController::class, 'index']);
$router->any('table', [TableController::class, 'index']);
$router->any('api', [AjaxController::class, 'index']);

// ─── Admin-ajax API actions (consumed via $router->dispatchApi() fallback) ───

$router->api('tmdb_search', [TmdbController::class, 'search']);
$router->api('tmdb',        [TmdbController::class, 'details']);

// ─── Cache & Handlers ──────────────────────────────
$router->api('regenerate_cache', [CacheAjaxController::class, 'regenerate']);
$router->api('enable_cache',     [CacheAjaxController::class, 'enableCache']);
$router->api('disable_cache',    [CacheAjaxController::class, 'disableCache']);
$router->api('enable_handler',   [CacheAjaxController::class, 'enableHandler']);
$router->api('disable_handler',  [CacheAjaxController::class, 'disableHandler']);
$router->api('clear_redis',      [CacheAjaxController::class, 'clearRedis']);

// ─── Servers & Ops ─────────────────────────────────
$router->api('rtmp_ip',              [ServerAjaxController::class, 'rtmpIp']);
$router->api('rollback_versions',    [ServerAjaxController::class, 'rollbackVersions']);
$router->api('server',               [ServerAjaxController::class, 'server']);
$router->api('proxy',                [ServerAjaxController::class, 'proxy']);
$router->api('fingerprint',          [ServerAjaxController::class, 'fingerprint']);
$router->api('restart_all_services', [ServerAjaxController::class, 'restartAllServices']);
$router->api('restart_services',     [ServerAjaxController::class, 'restartServices']);
$router->api('reboot_server',        [ServerAjaxController::class, 'rebootServer']);
$router->api('update_binaries',      [ServerAjaxController::class, 'updateBinaries']);
$router->api('server_view',          [ServerAjaxController::class, 'serverView']);
$router->api('server_stats',         [ServerAjaxController::class, 'serverStats']);
$router->api('rtmp_kill',            [ServerAjaxController::class, 'rtmpKill']);
$router->api('install_status',       [ServerAjaxController::class, 'installStatus']);
$router->api('reinstall_server',     [ServerAjaxController::class, 'reinstallServer']);
$router->api('fpm_status',           [ServerAjaxController::class, 'fpmStatus']);
$router->api('update_all_servers',   [ServerAjaxController::class, 'updateAllServers']);
$router->api('update_all_binaries',  [ServerAjaxController::class, 'updateAllBinaries']);

// ─── Blocklists & Security ─────────────────────────
$router->api('useragent',    [BlocklistAjaxController::class, 'useragent']);
$router->api('isp',          [BlocklistAjaxController::class, 'isp']);
$router->api('mysql_syslog', [BlocklistAjaxController::class, 'mysqlSyslog']);
$router->api('ip',           [BlocklistAjaxController::class, 'ip']);
$router->api('ip_whois',     [BlocklistAjaxController::class, 'ipWhois']);
$router->api('asn',          [BlocklistAjaxController::class, 'asn']);
$router->api('decrypt_text', [BlocklistAjaxController::class, 'decryptText']);

// ─── Devices ───────────────────────────────────────
$router->api('mag',        [DeviceAjaxController::class, 'mag']);
$router->api('enigma',     [DeviceAjaxController::class, 'enigma']);
$router->api('mag_event',  [DeviceAjaxController::class, 'magEvent']);
$router->api('send_event', [DeviceAjaxController::class, 'sendEvent']);

// ─── EPG ───────────────────────────────────────────
$router->api('epg',                [EpgAjaxController::class, 'epg']);
$router->api('epglist',            [EpgAjaxController::class, 'epglist']);
$router->api('force_epg',          [EpgAjaxController::class, 'forceEpg']);
$router->api('epg_auto_assign',    [EpgAjaxController::class, 'epgAutoAssign']);
$router->api('epg_categories',     [EpgAjaxController::class, 'epgCategories']);
$router->api('provider_import_epg', [EpgAjaxController::class, 'providerImportEpg']);
$router->api('get_epg',            [EpgAjaxController::class, 'getEpg']);
$router->api('get_programme',      [EpgAjaxController::class, 'getProgramme']);

// ─── Packages, Bouquets & Groups ───────────────────
$router->api('package',            [PackageAjaxController::class, 'package']);
$router->api('code',               [PackageAjaxController::class, 'code']);
$router->api('hmac',               [PackageAjaxController::class, 'hmac']);
$router->api('group',              [PackageAjaxController::class, 'group']);
$router->api('bouquet',            [PackageAjaxController::class, 'bouquet']);
$router->api('category',           [PackageAjaxController::class, 'category']);
$router->api('get_package',        [PackageAjaxController::class, 'getPackage']);
$router->api('get_package_trial',  [PackageAjaxController::class, 'getPackageTrial']);

// ─── Stats & Graphs ────────────────────────────────
$router->api('graph_stats',  [StatsAjaxController::class, 'graphStats']);
$router->api('stats',        [StatsAjaxController::class, 'stats']);
$router->api('header_stats',  [StatsAjaxController::class, 'headerStats']);

// ─── Backups, Logs & Reports ───────────────────────
$router->api('clear_logs',           [BackupAjaxController::class, 'clearLogs']);
$router->api('backup',               [BackupAjaxController::class, 'backup']);
$router->api('report',               [BackupAjaxController::class, 'report']);
$router->api('download_panel_logs',  [BackupAjaxController::class, 'downloadPanelLogs']);

// ─── Providers ─────────────────────────────────────
$router->api('provider',         [ProviderAjaxController::class, 'provider']);
$router->api('provider_streams', [ProviderAjaxController::class, 'providerStreams']);

// ─── Search ────────────────────────────────────────
$router->api('search', [SearchAjaxController::class, 'search']);

// ─── Misc ──────────────────────────────────────────
$router->api('process',          [MiscAjaxController::class, 'process']);
$router->api('profile',          [MiscAjaxController::class, 'profile']);
$router->api('watch_output',     [MiscAjaxController::class, 'watchOutput']);
$router->api('reguserlist',      [MiscAjaxController::class, 'reguserlist']);
$router->api('userlist',         [MiscAjaxController::class, 'userlist']);
$router->api('listdir',          [MiscAjaxController::class, 'listdir']);
$router->api('queue',            [MiscAjaxController::class, 'queue']);
$router->api('delete_recording', [MiscAjaxController::class, 'deleteRecording']);
$router->api('clear_failures',   [MiscAjaxController::class, 'clearFailures']);

// ─── Users & Lines ─────────────────────────────────
$router->api('line',           [UserAjaxController::class, 'line']);
$router->api('line_activity',  [UserAjaxController::class, 'lineActivity']);
$router->api('adjust_credits', [UserAjaxController::class, 'adjustCredits']);
$router->api('reg_user',       [UserAjaxController::class, 'regUser']);
$router->api('ticket',         [UserAjaxController::class, 'ticket']);

// ─── Streams & VOD ─────────────────────────────────
$router->api('stream',  [StreamAjaxController::class, 'stream']);
$router->api('movie',   [StreamAjaxController::class, 'movie']);
$router->api('episode', [StreamAjaxController::class, 'episode']);
$router->api('series',  [StreamAjaxController::class, 'series']);

// ─── Stream Tools, Lists & Reviews ─────────────────
$router->api('review_selection', [StreamToolsAjaxController::class, 'reviewSelection']);
$router->api('review_bouquet',   [StreamToolsAjaxController::class, 'reviewBouquet']);
$router->api('serieslist',       [StreamToolsAjaxController::class, 'serieslist']);
$router->api('streamlist',       [StreamToolsAjaxController::class, 'streamlist']);
$router->api('adaptivelist',     [StreamToolsAjaxController::class, 'adaptivelist']);
$router->api('titlesync',        [StreamToolsAjaxController::class, 'titlesync']);
$router->api('probe_stream',     [StreamToolsAjaxController::class, 'probeStream']);
$router->api('check_stream',     [StreamToolsAjaxController::class, 'checkStream']);
$router->api('get_episode_ids',  [StreamToolsAjaxController::class, 'getEpisodeIds']);

// ─── No-bootstrap pages (login, setup, database, session) ────

$router->get('session', [SessionController::class, 'index']);
$router->any('login', [LoginController::class, 'index']);
$router->any('setup', [SetupController::class, 'index']);
$router->any('database', [SetupController::class, 'database']);
$router->get('index', [LoginController::class, 'index']);