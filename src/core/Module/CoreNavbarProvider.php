<?php

/**
 * CoreNavbarProvider — registers all built-in admin navigation items.
 *
 * Called once at the start of ModuleLoader::bootAll() before any module
 * registers its own items. Modules inject additional items via the same
 * NavbarRegistry::add() API at reserved order slots (60+, 170+, etc.).
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CoreNavbarProvider implements NavbarProviderInterface {

    /**
     * NavbarProviderInterface implementation — delegates to register().
     */
    public function registerNavbar(): void {
        self::register();
    }

    /**
     * Register all core navigation items with the NavbarRegistry.
     *
     * @return void
     */
    public static function register(): void {
        self::_dashboard();
        self::_servers();
        self::_users();
        self::_content();
        self::_bouquets();
        self::_management();
        self::_suppliers();
    }

    // ── Dashboard ─────────────────────────────────────────────────

    /**
     * Register Dashboard navigation items.
     * 
     * Adds the main dashboard menu item and its live connections sub-item.
     *
     * @return void
     */
    private static function _dashboard(): void {
        NavbarRegistry::add((new NavbarItem('dashboard'))
            ->url('index')->label('dashboard')
            ->icon('fe-activity')->noMobileSubmenu()->order(100));

        NavbarRegistry::add((new NavbarItem('dashboard.live_connections'))
            ->parent('dashboard')->url('live_connections')
            ->label('live_connections')->permissions(['live_connections'])->order(10));
    }

    // ── Servers ───────────────────────────────────────────────────

    /**
     * Register Servers navigation items.
     * 
     * Adds server management items including load balancer installation,
     * server/proxy management, ordering, and process monitoring.
     *
     * @return void
     */
    private static function _servers(): void {
        NavbarRegistry::add((new NavbarItem('servers'))
            ->url('#')->label('servers')
            ->icon('fas fa-server')->order(200));

        NavbarRegistry::add((new NavbarItem('servers.install'))
            ->parent('servers')->url('server_install')
            ->label('install_load_balancer')->permissions(['servers'])->order(10));

        NavbarRegistry::add((new NavbarItem('servers.manage'))
            ->parent('servers')->url('servers')
            ->label('manage_servers')->permissions(['servers'])->order(20));

        NavbarRegistry::add((new NavbarItem('servers.proxies'))
            ->parent('servers')->url('proxies')
            ->label('manage_proxies')->permissions(['proxies'])->order(30));

        NavbarRegistry::add((new NavbarItem('servers.order'))
            ->parent('servers')->url('server_order')
            ->label('server_order')->permissions(['server_order'])->order(40));

        NavbarRegistry::add((new NavbarItem('servers.process_monitor'))
            ->parent('servers')->url('process_monitor')
            ->label('process_monitor')->permissions(['process_monitor'])->order(50));
    }

    // ── Users ─────────────────────────────────────────────────────

    /**
     * Register Users navigation items.
     * 
     * Adds complete user management structure including Lines, MAG devices,
     * Enigma2 devices, and Reseller management with their respective
     * add/manage/mass-edit operations.
     *
     * @return void
     */
    private static function _users(): void {
        NavbarRegistry::add((new NavbarItem('users'))
            ->url('#')->label('users')
            ->icon('fas fa-desktop')->order(300));

        // Lines
        NavbarRegistry::add((new NavbarItem('users.lines'))
            ->parent('users')->url('#')
            ->label('user_lines')->permissions(['add_user', 'users'])->order(10));
        NavbarRegistry::add((new NavbarItem('users.lines.add'))
            ->parent('users.lines')->url('line')
            ->label('add_users')->permissions(['add_user'])->order(10));
        NavbarRegistry::add((new NavbarItem('users.lines.manage'))
            ->parent('users.lines')->url('lines')
            ->label('manage_users')->permissions(['users'])->order(20));
        NavbarRegistry::add((new NavbarItem('users.lines.mass'))
            ->parent('users.lines')->url('line_mass')
            ->label('mass_edit_users')->permissions(['mass_edit_lines'])->order(30));

        // MAG
        NavbarRegistry::add((new NavbarItem('users.mag'))
            ->parent('users')->url('#')
            ->label('mag_devices')->permissions(['add_mag', 'manage_mag'])->order(20));
        NavbarRegistry::add((new NavbarItem('users.mag.add'))
            ->parent('users.mag')->url('mag')
            ->label('add_mag')->permissions(['add_mag'])->order(10));
        NavbarRegistry::add((new NavbarItem('users.mag.manage'))
            ->parent('users.mag')->url('mags')
            ->label('manage_mag_devices')->permissions(['manage_mag'])->order(20));
        NavbarRegistry::add((new NavbarItem('users.mag.mass'))
            ->parent('users.mag')->url('mag_mass')
            ->label('mass_edit_mags')->permissions(['mass_edit_mags'])->order(30));

        // Enigma
        NavbarRegistry::add((new NavbarItem('users.e2'))
            ->parent('users')->url('#')
            ->label('enigma_devices')->permissions(['add_e2', 'manage_e2'])->order(30));
        NavbarRegistry::add((new NavbarItem('users.e2.add'))
            ->parent('users.e2')->url('enigma')
            ->label('add_enigma')->permissions(['add_e2'])->order(10));
        NavbarRegistry::add((new NavbarItem('users.e2.manage'))
            ->parent('users.e2')->url('enigmas')
            ->label('manage_enigma_devices')->permissions(['manage_e2'])->order(20));
        NavbarRegistry::add((new NavbarItem('users.e2.mass'))
            ->parent('users.e2')->url('enigma_mass')
            ->label('mass_edit_enigmas')->permissions(['mass_edit_enigmas'])->order(30));

        // Reseller
        NavbarRegistry::add((new NavbarItem('users.reseller'))
            ->parent('users')->url('#')
            ->label('reseller')->permissions(['add_reguser', 'mng_regusers'])->order(40));
        NavbarRegistry::add((new NavbarItem('users.reseller.add'))
            ->parent('users.reseller')->url('user')
            ->label('add_registered_user')->permissions(['add_reguser'])->order(10));
        NavbarRegistry::add((new NavbarItem('users.reseller.manage'))
            ->parent('users.reseller')->url('users')
            ->label('manage_registered_user')->permissions(['mng_regusers'])->order(20));
        NavbarRegistry::add((new NavbarItem('users.reseller.mass'))
            ->parent('users.reseller')->url('user_mass')
            ->label('mass_edit_resellers')->permissions(['mass_edit_users'])->order(30));
    }

    // ── Content ───────────────────────────────────────────────────

    /**
     * Register Content navigation items.
     * 
     * Adds content management structure including Streams, Created Channels,
     * Movies, Series, Radio Stations, Recordings, and TV Guide.
     *
     * @return void
     */
    private static function _content(): void {
        NavbarRegistry::add((new NavbarItem('content'))
            ->url('#')->label('content')
            ->icon('fas fa-play')->order(400));

        // Streams
        NavbarRegistry::add((new NavbarItem('content.streams'))
            ->parent('content')->url('#')
            ->label('streams')->permissions(['add_stream', 'streams'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.streams.add'))
            ->parent('content.streams')->url('stream')
            ->label('add_stream')->permissions(['add_stream'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.streams.import'))
            ->parent('content.streams')->url('stream?import=1')
            ->label('import_multiple_stream')->permissions(['import_streams'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.streams.import_review'))
            ->parent('content.streams')->url('review?type=1')
            ->label('import_review_stream')->permissions(['import_streams'])->order(30));
        NavbarRegistry::add((new NavbarItem('content.streams.manage'))
            ->parent('content.streams')->url('streams')
            ->label('manage_streams')->permissions(['streams'])->order(40));
        NavbarRegistry::add((new NavbarItem('content.streams.mass'))
            ->parent('content.streams')->url('stream_mass')
            ->label('mass_edit_streams')->permissions(['mass_edit_streams'])->order(50));

        // Created channels
        NavbarRegistry::add((new NavbarItem('content.channels'))
            ->parent('content')->url('#')
            ->label('created_channels')->permissions(['create_channel', 'streams'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.channels.add'))
            ->parent('content.channels')->url('created_channel')
            ->label('create_channel')->permissions(['create_channel'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.channels.manage'))
            ->parent('content.channels')->url('created_channels')
            ->label('manage_created_channels')->permissions(['streams'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.channels.mass'))
            ->parent('content.channels')->url('created_channel_mass')
            ->label('mass_edit_created_channels')->permissions(['streams'])->order(30));

        // Movies
        NavbarRegistry::add((new NavbarItem('content.movies'))
            ->parent('content')->url('#')
            ->label('movies')->permissions(['add_movie', 'import_movies', 'movies'])->order(30));
        NavbarRegistry::add((new NavbarItem('content.movies.add'))
            ->parent('content.movies')->url('movie')
            ->label('add_movie')->permissions(['add_movie'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.movies.import'))
            ->parent('content.movies')->url('movie?import=1')
            ->label('import_multiple_movies')->permissions(['import_movies'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.movies.import_review'))
            ->parent('content.movies')->url('review?type=2')
            ->label('import_review_movies')->permissions(['import_movies'])->order(30));
        NavbarRegistry::add((new NavbarItem('content.movies.manage'))
            ->parent('content.movies')->url('movies')
            ->label('manage_movies')->permissions(['movies'])->order(40));
        NavbarRegistry::add((new NavbarItem('content.movies.mass'))
            ->parent('content.movies')->url('movie_mass')
            ->label('mass_edit_movies')->permissions(['mass_sedits_vod'])->order(50));

        // Series
        NavbarRegistry::add((new NavbarItem('content.series'))
            ->parent('content')->url('#')
            ->label('series')->permissions(['add_series', 'series', 'episodes'])->order(40));
        NavbarRegistry::add((new NavbarItem('content.series.add'))
            ->parent('content.series')->url('serie')
            ->label('add_series')->permissions(['add_series'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.series.manage'))
            ->parent('content.series')->url('series')
            ->label('manage_series')->permissions(['series'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.series.episodes'))
            ->parent('content.series')->url('episodes')
            ->label('manage_episodes')->permissions(['episodes'])->order(30));
        NavbarRegistry::add((new NavbarItem('content.series.mass'))
            ->parent('content.series')->url('series_mass')
            ->label('', 'Mass Edit Series')->permissions(['mass_sedits'])->order(40));
        NavbarRegistry::add((new NavbarItem('content.series.episodes_mass'))
            ->parent('content.series')->url('episodes_mass')
            ->label('', 'Mass Edit Episodes')->permissions(['mass_sedits'])->order(50));

        // Radio stations
        NavbarRegistry::add((new NavbarItem('content.stations'))
            ->parent('content')->url('#')
            ->label('stations')->permissions(['add_radio', 'radio'])->order(50));
        NavbarRegistry::add((new NavbarItem('content.stations.add'))
            ->parent('content.stations')->url('radio')
            ->label('add_station')->permissions(['add_radio'])->order(10));
        NavbarRegistry::add((new NavbarItem('content.stations.manage'))
            ->parent('content.stations')->url('radios')
            ->label('manage_stations')->permissions(['radio'])->order(20));
        NavbarRegistry::add((new NavbarItem('content.stations.mass'))
            ->parent('content.stations')->url('radio_mass')
            ->label('mass_edit_stations')->permissions(['mass_edit_radio'])->order(30));

        NavbarRegistry::add((new NavbarItem('content.recordings'))
            ->parent('content')->url('archive')
            ->label('recordings')->permissions(['movies'])->order(60));

        NavbarRegistry::add((new NavbarItem('content.tv_guide'))
            ->parent('content')->url('epg_view')
            ->label('tv_guide')->permissions(['streams'])
            ->desktopOnly()->order(70));
    }

    // ── Bouquets ──────────────────────────────────────────────────

    /**
     * Register Bouquets navigation items.
     * 
     * Adds bouquet management items for adding, managing, and ordering channel bouquets.
     *
     * @return void
     */
    private static function _bouquets(): void {
        NavbarRegistry::add((new NavbarItem('bouquets'))
            ->url('#')->label('bouquets')
            ->icon('fas fa-spa')->order(500));

        NavbarRegistry::add((new NavbarItem('bouquets.add'))
            ->parent('bouquets')->url('bouquet')
            ->label('add_bouquet')->permissions(['add_bouquet'])->order(10));

        NavbarRegistry::add((new NavbarItem('bouquets.manage'))
            ->parent('bouquets')->url('bouquets')
            ->label('manage_bouquets')->permissions(['bouquets'])->order(20));

        NavbarRegistry::add((new NavbarItem('bouquets.order'))
            ->parent('bouquets')->url('bouquet_order')
            ->label('bouquet_order')->permissions(['bouquet_order'])
            ->desktopOnly()->order(30));
    }

    // ── Management ────────────────────────────────────────────────

    /**
     * Register Management navigation items.
     * 
     * Adds system management structure including Service Setup, Access Codes,
     * Security, Tools, Logs (with megamenu), and Tickets.
     *
     * @return void
     */
    private static function _management(): void {
        NavbarRegistry::add((new NavbarItem('management'))
            ->url('#')->label('management')
            ->icon('fas fa-wrench')->order(600));

        // Service setup
        NavbarRegistry::add((new NavbarItem('management.service_setup'))
            ->parent('management')->url('#')
            ->label('service_setup')->permissions(['mng_packages', 'categories', 'mng_groups', 'epg', 'tprofiles', 'folder_watch'])->order(10));
        NavbarRegistry::add((new NavbarItem('management.service_setup.packages'))
            ->parent('management.service_setup')->url('packages')
            ->label('packages')->permissions(['mng_packages'])->order(10));
        NavbarRegistry::add((new NavbarItem('management.service_setup.categories'))
            ->parent('management.service_setup')->url('stream_categories')
            ->label('categories')->permissions(['categories'])->order(20));
        NavbarRegistry::add((new NavbarItem('management.service_setup.groups'))
            ->parent('management.service_setup')->url('groups')
            ->label('groups')->permissions(['mng_groups'])->order(30));
        NavbarRegistry::add((new NavbarItem('management.service_setup.epg'))
            ->parent('management.service_setup')->url('epgs')
            ->label('epgs')->permissions(['epg'])->order(40));
        NavbarRegistry::add((new NavbarItem('management.service_setup.profiles'))
            ->parent('management.service_setup')->url('profiles')
            ->label('transcode_profiles')->permissions(['tprofiles'])->order(50));
        // Modules inject at order 60+

        // Access codes
        NavbarRegistry::add((new NavbarItem('management.access_codes'))
            ->parent('management')->url('#')
            ->label('', 'Access Codes')->permissions(['add_code'])->order(20));
        NavbarRegistry::add((new NavbarItem('management.access_codes.add'))
            ->parent('management.access_codes')->url('code')
            ->label('add_access_codes')->permissions(['add_code'])->order(10));
        NavbarRegistry::add((new NavbarItem('management.access_codes.manage'))
            ->parent('management.access_codes')->url('codes')
            ->label('menage_access_codes')->permissions(['add_code'])->order(20));

        // Security
        NavbarRegistry::add((new NavbarItem('management.security'))
            ->parent('management')->url('#')
            ->label('', 'Security')->permissions(['block_asns', 'block_ips', 'block_isps', 'block_uas', 'add_hmac', 'rtmp'])->order(30));
        NavbarRegistry::add((new NavbarItem('management.security.asns'))
            ->parent('management.security')->url('asns')
            ->label('blocked_asns')->permissions(['block_asns'])->order(10));
        NavbarRegistry::add((new NavbarItem('management.security.ips'))
            ->parent('management.security')->url('ips')
            ->label('blocked_ips')->permissions(['block_ips'])->order(20));
        NavbarRegistry::add((new NavbarItem('management.security.isps'))
            ->parent('management.security')->url('isps')
            ->label('blocked_isps')->permissions(['block_isps'])->order(30));
        NavbarRegistry::add((new NavbarItem('management.security.uas'))
            ->parent('management.security')->url('useragents')
            ->label('blocked_uas')->permissions(['block_uas'])->order(40));
        NavbarRegistry::add((new NavbarItem('management.security.hmac'))
            ->parent('management.security')->url('hmacs')
            ->label('hmac_keys')->permissions(['add_hmac'])->order(50));
        NavbarRegistry::add((new NavbarItem('management.security.rtmp'))
            ->parent('management.security')->url('rtmp_ips')
            ->label('rtmp_ips')->permissions(['rtmp'])->order(60));

        NavbarRegistry::add((new NavbarItem('management.tools'))
            ->parent('management')->url('#')
            ->label('tools')->permissions(['channel_order', 'fingerprint', 'mass_delete', 'quick_tools', 'rtmp', 'stream_tools'])->order(40));
        NavbarRegistry::add((new NavbarItem('management.tools.channel_order'))
            ->parent('management.tools')->url('channel_order')
            ->label('channel_order')->permissions(['channel_order'])->desktopOnly()->order(10));
        NavbarRegistry::add((new NavbarItem('management.tools.fingerprint'))
            ->parent('management.tools')->url('fingerprint')
            ->label('fingerprint')->permissions(['fingerprint'])->order(20));
        NavbarRegistry::add((new NavbarItem('management.tools.mass_delete'))
            ->parent('management.tools')->url('mass_delete')
            ->label('mass_delete')->permissions(['mass_delete'])->order(30));
        NavbarRegistry::add((new NavbarItem('management.tools.quick_tools'))
            ->parent('management.tools')->url('quick_tools')
            ->label('quick_tools')->permissions(['quick_tools'])->order(40));
        NavbarRegistry::add((new NavbarItem('management.tools.rtmp_monitor'))
            ->parent('management.tools')->url('rtmp_monitor')
            ->label('', 'RTMP Monitor')->permissions(['rtmp'])->order(50));
        NavbarRegistry::add((new NavbarItem('management.tools.stream_tools'))
            ->parent('management.tools')->url('stream_tools')
            ->label('stream_tools')->permissions(['stream_tools'])->order(60));

        // Logs (megamenu)
        NavbarRegistry::add((new NavbarItem('management.logs'))
            ->parent('management')->url('#')
            ->label('logs')->permissions(['movies', 'streams', 'connection_logs', 'client_request_log', 'login_logs', 'panel_logs', 'credits_log', 'live_connections', 'manage_events', 'reg_userlog', 'stream_errors'])->submenuClass('megamenu')->order(50));
        NavbarRegistry::add((new NavbarItem('management.logs.activity'))
            ->parent('management.logs')->url('line_activity')
            ->label('activity_logs')->permissions(['connection_logs'])->order(10));
        NavbarRegistry::add((new NavbarItem('management.logs.client'))
            ->parent('management.logs')->url('client_logs')
            ->label('client_logs')->permissions(['client_request_log'])->order(20));
        NavbarRegistry::add((new NavbarItem('management.logs.credit'))
            ->parent('management.logs')->url('credit_logs')
            ->label('credit_logs')->permissions(['credits_log'])->order(30));
        NavbarRegistry::add((new NavbarItem('management.logs.queue'))
            ->parent('management.logs')->url('queue')
            ->label('', 'Encoding Queue')->permissions(['streams', 'episodes', 'series'])->order(40));
        NavbarRegistry::add((new NavbarItem('management.logs.line_ips'))
            ->parent('management.logs')->url('line_ips')
            ->label('ips_per_line')->permissions(['connection_logs'])->order(50));
        NavbarRegistry::add((new NavbarItem('management.logs.live_connections'))
            ->parent('management.logs')->url('live_connections')
            ->label('live_connections')->permissions(['live_connections'])->order(60));
        NavbarRegistry::add((new NavbarItem('management.logs.login'))
            ->parent('management.logs')->url('login_logs')
            ->label('', 'Login Logs')->permissions(['login_logs'])->order(70));
        NavbarRegistry::add((new NavbarItem('management.logs.mag_events'))
            ->parent('management.logs')->url('mag_events')
            ->label('mag_event_logs')->permissions(['manage_events'])->order(80));
        NavbarRegistry::add((new NavbarItem('management.logs.ondemand'))
            ->parent('management.logs')->url('ondemand')
            ->label('', 'On-Demand Scanner')->permissions(['streams'])->order(90));
        NavbarRegistry::add((new NavbarItem('management.logs.panel_logs'))
            ->parent('management.logs')->url('panel_logs')
            ->label('', 'Panel Errors')->permissions(['panel_logs'])->order(100));
        NavbarRegistry::add((new NavbarItem('management.logs.user_logs'))
            ->parent('management.logs')->url('user_logs')
            ->label('reseller_logs')->permissions(['reg_userlog'])->order(110));
        NavbarRegistry::add((new NavbarItem('management.logs.restream'))
            ->parent('management.logs')->url('restream_logs')
            ->label('', 'Restream Detection')->permissions(['restream_logs'])->order(120));
        NavbarRegistry::add((new NavbarItem('management.logs.stream_errors'))
            ->parent('management.logs')->url('stream_errors')
            ->label('stream_errors')->permissions(['stream_errors'])->order(130));
        NavbarRegistry::add((new NavbarItem('management.logs.stream_rank'))
            ->parent('management.logs')->url('stream_rank')
            ->label('', 'Stream Rank')->permissions(['streams'])->order(140));
        NavbarRegistry::add((new NavbarItem('management.logs.system'))
            ->parent('management.logs')->url('mysql_syslog')
            ->label('', 'System Logs')->permissions(['panel_logs'])->order(150));
        NavbarRegistry::add((new NavbarItem('management.logs.vod_theft'))
            ->parent('management.logs')->url('theft_detection')
            ->label('', 'VOD Theft Detection')->permissions(['movies'])->order(160));
        // Modules inject at order 170+

        NavbarRegistry::add((new NavbarItem('management.tickets'))
            ->parent('management')->url('tickets')
            ->label('tickets')->permissions(['manage_tickets'])
            ->settingDisabled('show_tickets')->order(60));
    }

    // ── Suppliers ─────────────────────────────────────────────────

    /**
     * Register Suppliers navigation items.
     * 
     * Adds stream provider/supplier management items.
     *
     * @return void
     */
    private static function _suppliers(): void {
        NavbarRegistry::add((new NavbarItem('suppliers'))
            ->url('#')->label('supplirs')
            ->icon('fas fa-users')->order(700));

        NavbarRegistry::add((new NavbarItem('suppliers.add'))
            ->parent('suppliers')->url('provider')
            ->label('add_providers')->permissions(['streams'])->order(10));

        NavbarRegistry::add((new NavbarItem('suppliers.manage'))
            ->parent('suppliers')->url('providers')
            ->label('stream_providers')->permissions(['streams'])->order(20));
    }
}
