<?php

/**
 * Admin Routes
 *
 * Маршруты административной панели.
 * Файл подключается Front Controller'ом (index.php) при scope = 'admin'.
 * Переменная $router (Router::getInstance()) доступна из вызывающего контекста.
 *
 * @see interfaces/Http/public/index.php
 * @see core/Http/Router.php
 */

// ─── List Pages (Phase 6.3) ────────────────────────────────────

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

// ─── Group E: Bouquets (Phase 6.3) ─────────────────────────────

$router->get('bouquets', [BouquetListController::class, 'index']);
$router->get('bouquet', [BouquetController::class, 'index']);
$router->get('bouquet_order', [BouquetOrderController::class, 'index']);
$router->get('bouquet_sort', [BouquetSortController::class, 'index']);

// ─── Group G: Simple Listings (Phase 6.3) ──────────────────────

$router->get('login_logs', [LoginLogController::class, 'index']);
$router->get('mysql_syslog', [MysqlSyslogController::class, 'index']);
$router->get('mag_events', [MagEventController::class, 'index']);
$router->get('restream_logs', [RestreamLogController::class, 'index']);
$router->get('panel_logs', [PanelLogController::class, 'index']);
$router->get('epgs', [EpgListController::class, 'index']);

// ─── Group D: Servers (Phase 6.3) ──────────────────────────────

$router->get('servers', [ServerListController::class, 'index']);
$router->get('server', [ServerController::class, 'index']);
$router->get('server_view', [ServerViewController::class, 'index']);
$router->get('server_install', [ServerInstallController::class, 'index']);

// ─── Group F: Settings (Phase 6.3) ─────────────────────────────

$router->get('settings', [SettingsController::class, 'index']);
$router->get('settings_watch', [SettingsWatchController::class, 'index']);
$router->get('settings_plex', [SettingsPlexController::class, 'index']);
$router->get('magscan_settings', [MagscanSettingsController::class, 'index']);

// ─── Group C: Lines (Phase 6.3) ────────────────────────────────

$router->get('lines', [LineListController::class, 'index']);
$router->get('line', [LineController::class, 'index']);
$router->get('line_mass', [LineMassController::class, 'index']);
$router->get('line_activity', [LineActivityController::class, 'index']);
$router->get('line_ips', [LineIpsController::class, 'index']);
$router->get('client_logs', [ClientLogController::class, 'index']);

// ─── Group B: VOD (Phase 6.3) ──────────────────────────────────

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

// ─── Group A: Streams (Phase 6.3) ──────────────────────────────

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

// ─── Group H: Pilot Detail Pages (Phase 6.3) ──────────────────

$router->get('ip', [IpEditController::class, 'index']);
$router->get('isp', [IspEditController::class, 'index']);
$router->get('hmac', [HmacEditController::class, 'index']);
$router->get('group', [GroupEditController::class, 'index']);
$router->get('code', [CodeEditController::class, 'index']);
$router->get('package', [PackageEditController::class, 'index']);
$router->get('rtmp_ip', [RtmpIpEditController::class, 'index']);
$router->get('profile', [ProfileEditController::class, 'index']);
$router->get('provider', [ProviderEditController::class, 'index']);

// ─── Group I: Users / Agents (Phase 6.3) ──────────────────────

$router->get('users', [UsersController::class, 'index']);
$router->any('user', [UserController::class, 'index']);
$router->any('user_mass', [UserMassController::class, 'index']);
$router->get('user_logs', [UserLogsController::class, 'index']);
$router->get('useragents', [UseragentsController::class, 'index']);
$router->any('useragent', [UseragentController::class, 'index']);

// ─── Group J: Devices MAG / Enigma (Phase 6.3) ────────────────

$router->get('mags', [MagsController::class, 'index']);
$router->any('mag', [MagController::class, 'index']);
$router->any('mag_mass', [MagMassController::class, 'index']);
$router->get('enigmas', [EnigmasController::class, 'index']);
$router->any('enigma', [EnigmaController::class, 'index']);
$router->any('enigma_mass', [EnigmaMassController::class, 'index']);

// ─── Group K: Tickets / EPG (Phase 6.3) ───────────────────────

$router->get('tickets', [TicketsController::class, 'index']);
$router->any('ticket', [TicketController::class, 'index']);
$router->get('ticket_view', [TicketViewController::class, 'index']);
$router->any('epg', [EpgController::class, 'index']);
$router->get('epg_view', [EpgViewController::class, 'index']);

// ─── Group L: Watch / Plex (Phase 6.3) ────────────────────────

$router->get('watch', [WatchController::class, 'index']);
$router->any('watch_add', [WatchAddController::class, 'index']);
$router->get('watch_output', [WatchOutputController::class, 'index']);
$router->get('plex', [PlexController::class, 'index']);
$router->any('plex_add', [PlexAddController::class, 'index']);

// ─── Group M: System (Phase 6.3) ──────────────────────────────

$router->get('dashboard', [DashboardController::class, 'index']);
$router->get('backups', [BackupsController::class, 'index']);
$router->any('cache', [CacheController::class, 'index']);
$router->any('process_monitor', [ProcessMonitorController::class, 'index']);
$router->get('queue', [QueueController::class, 'index']);
$router->any('quick_tools', [QuickToolsController::class, 'index']);
$router->any('mass_delete', [MassDeleteController::class, 'index']);
$router->any('server_order', [ServerOrderController::class, 'index']);

// ─── Group N: Misc (Phase 6.3) ────────────────────────────────

$router->get('credit_logs', [CreditLogsController::class, 'index']);
$router->get('edit_profile', [EditProfileController::class, 'index']);
$router->get('fingerprint', [FingerprintController::class, 'index']);
$router->get('proxies', [ProxiesController::class, 'index']);
$router->any('proxy', [ProxyController::class, 'index']);
$router->any('record', [RecordController::class, 'index']);
$router->any('review', [ReviewController::class, 'index']);
$router->any('archive', [ArchiveController::class, 'index']);
$router->get('asns', [AsnsController::class, 'index']);
