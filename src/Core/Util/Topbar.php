<?php

namespace XcVm\Core\Util;

use XcVm\Core\Auth\Authorization;

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
        'panel_logs', 'login_logs', 'mysql_syslog', 'client_logs', 'credit_logs',
        'user_logs', 'stream_errors', 'line_activity', 'mag_events', 'watch_output',
        'live_connections',
    ];

    private const LOG_TYPES = [
        'client_logs'   => 'lines_logs',
        'credit_logs'   => 'users_credits_logs',
        'user_logs'     => 'users_logs',
        'stream_errors' => 'streams_errors',
        'line_activity' => 'lines_activity',
        'watch_output'  => 'watch_logs',
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

        $rDropdown = array(
            'ondemand' => array('Manage Streams' => array('streams', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('stream_mass', 'mass_edit_streams'), 'Stream Tools' => array('stream_tools', 'stream_tools'), 'Stream Error Logs' => array('stream_errors', 'stream_errors'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'streams' => array('Add Stream' => array('stream', 'add_stream'), 'Import & Review' => ($rMobile ? array() : array('review?type=1', 'import_streams')), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), "EPG's" => array('epgs', 'epg'), 'Fingerprint' => array('fingerprint', 'fingerprint'), 'On-Demand Scanner' => array('ondemand', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('stream_mass', 'mass_edit_streams'), 'Mass Edit (Review)' => array('stream_review', 'mass_edit_streams'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools'), 'Stream Error Logs' => array('stream_errors', 'stream_errors'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'created_channels' => array('Create Channel' => array('created_channel', 'create_channel'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('created_channel_mass', null), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'stream_view' => array('Edit Stream' => array('stream?id=' . $rID, 'edit_stream'), 'Manage Streams' => array('streams', 'streams')),
            'stream_review' => array(($rImport ? 'Save Changes' : 'Review Streams') => array(null, null, 'id="btn-submit"')),
            'panel_logs' => array('Download log' => array(null, null, 'id="btn-download-log"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'movies' => array('Add Movie' => array('movie', 'add_movie'), 'Import & Review' => ($rMobile ? array() : array('review?type=2', 'import_movies')), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('movie_mass', 'mass_sedits_vod'), 'Watch Folder' => array('watch', 'folder_watch'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'series' => array('Add Series' => array('serie', 'add_series'), 'Episodes' => array('episodes', 'episodes'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('series_mass', 'mass_sedits'), 'Watch Folder' => array('watch', 'folder_watch'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'episodes' => array('Add Episode' => array(null, 'add_episode'), 'TV Series' => array('series', 'series'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('episodes_mass', 'mass_sedits'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'radios' => array('Add Station' => array('radio', 'add_radio'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('radio_mass', 'mass_edit_radio'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'lines' => array('Add Line' => array('line', 'add_user'), "Blocked ASN's" => array('asns', 'block_isps'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Blocked User-Agents' => array('useragents', 'block_uas'), 'Live Connections' => array('live_connections', 'live_connections'), 'Activity Logs' => array('line_activity', 'connection_logs'), "IP's per Line" => array('line_ips', 'connection_logs'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('line_mass', 'mass_edit_users'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'live_connections' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Activity Logs' => array('line_activity', 'connection_logs'), "IP's per Line" => array('line_ips', 'connection_logs')),
            'mags' => array('Add Device' => array('mag', 'add_mag'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Live Connections' => array('live_connections', 'connection_logs'), 'Activity Logs' => array('line_activity', 'connection_logs'), 'MAG Event Logs' => array('mag_events', 'manage_events'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('mag_mass', 'mass_edit_mags'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'enigmas' => array('Add Device' => array('enigma', 'add_e2'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Live Connections' => array('live_connections', 'connection_logs'), 'Activity Logs' => array('line_activity', 'connection_logs'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('enigma_mass', 'mass_edit_enigmas'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'users' => array('Add User' => array('user', 'add_reguser'), 'Groups' => array('groups', 'mng_groups'), 'Packages' => array('packages', 'mng_packages'), 'Subresellers' => array('subresellers', 'subreseller'), 'Client Logs' => array('client_logs', 'client_request_log'), 'Credit Logs' => array('credit_logs', 'credits_log'), 'Reseller Logs' => array('user_logs', 'reg_userlog'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('user_mass', 'mass_edit_reguser'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'bouquet' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Sort Bouquet' => array('bouquet_sort?id=' . $rID, 'edit_bouquet')),
            'bouquet_sort' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Edit Bouquet' => array('bouquet?id=' . $rID, 'edit_bouquet')),
            'bouquet_order' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Add Bouquet' => array('bouquet', 'add_bouquet')),
            'archive' => array('View Stream' => array('stream_view?id=' . $rID, 'streams'), 'Edit Stream' => array('stream?id=' . $rID, 'edit_stream'), 'Create Recording' => array('record', 'add_movie'), 'Manage Streams' => array('streams', 'streams')),
            'asns' => array('Quick Tools' => array('quick_tools', 'quick_tools')),
            'backups' => array('General Settings' => array('settings', 'settings'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Cache Settings' => array('cache', 'backups'), 'Modules' => array('modules', 'settings')),
            'cache' => array('General Settings' => array('settings', 'settings'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Backup Settings' => array('backups', 'database'), 'Modules' => array('modules', 'settings')),
            'settings' => array('Backup Settings' => array('backups', 'database'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Cache Settings' => array('cache', 'backups'), 'Modules' => array('modules', 'settings')),
            'modules' => array('General Settings' => array('settings', 'settings'), 'Backup Settings' => array('backups', 'database'), 'Cache Settings' => array('cache', 'backups')),
            'settings_watch' => array('Folders' => array('watch', 'folder_watch'), 'General Settings' => array('settings', 'settings'), 'Backup Settings' => array('backups', 'database'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output')),
            'settings_plex' => array('Libraries' => array('plex', 'folder_watch'), 'General Settings' => array('settings', 'settings'), 'Backup Settings' => array('backups', 'database'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output')),
            'channel_order' => array('Categories' => array('stream_categories', 'categories'), 'Bouquets' => array('bouquets', 'bouquets')),
            'bouquets' => array('Add Bouquet' => array('bouquet', 'add_bouquet'), 'Order Bouquets' => ($rMobile ? array() : array('bouquet_order', 'edit_bouquet')), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Categories' => array('stream_categories', 'categories')),
            'stream_categories' => array('Add Category' => array('stream_category', 'add_cat'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Bouquets' => array('bouquets', 'bouquets')),
            'client_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'credit_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'user_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'stream_errors' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'line_activity' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')),
            'watch_output' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"'), 'Watch Folder' => array('watch', 'folder_watch')),
            'code' => array('Access Codes' => array('codes', 'add_code')),
            'codes' => array('Add Code' => array('code', 'add_code')),
            'hmacs' => array('Add HMAC' => array('hmac', 'add_hmac')),
            'hmac' => array('HMAC Keys' => array('hmacs', 'add_hmac')),
            'stream' => array('View Stream' => array('stream_view?id=' . $rID, 'streams'), 'Import' => array('stream?import', 'import_streams'), 'Add Single' => array('stream', 'add_stream'), 'Manage Streams' => array('streams', 'streams'), 'Import & Review' => ($rMobile ? array() : array('review?type=1', 'import_streams'))),
            'movie' => array('View Movie' => array('stream_view?id=' . $rID, 'movies'), 'Import' => array('movie?import', 'import_movies'), 'Add Single' => array('movie', 'add_movie'), 'Manage Movies' => array('movies', 'movies'), 'Import & Review' => ($rMobile ? array() : array('review?type=2', 'import_movies'))),
            'episode' => array('Add Multiple' => array('episode?sid=' . $rSID . '&multi', 'add_episode'), 'Add Single' => array('episode?sid=' . $rSID, 'add_episode'), 'View Episodes' => array('episodes?series=' . $rSID, 'episodes'), 'Manage Series' => array('series', 'series')),
            'serie' => array('Import' => array('serie?import', 'import_streams'), 'Add Single' => array('serie', 'add_series'), 'Manage Series' => array('series', 'series'), 'View Episodes' => array('episodes?series=' . $rID, 'episodes')),
            'created_channel' => array('View Channel' => array('stream_view?id=' . $rID, 'streams'), 'Manage Channels' => array('created_channels', 'streams')),
            'epg' => array("Manage EPG's" => array('epgs', 'epg')),
            'epgs' => array('Add EPG' => array('epg', 'add_epg'), 'Force Reload' => array(null, 'add_epg', 'onClick="forceUpdate();" id="force_update"')),
            'fingerprint' => array('Manage Streams' => array('streams', 'streams')),
            'group' => array('Manage Groups' => array('groups', 'mng_groups')),
            'groups' => array('Add Group' => array('group', 'add_group')),
            'package' => array('Manage Packages' => array('packages', 'mng_packages')),
            'packages' => array('Add Package' => array('package', 'add_packages')),
            'provider' => array('Providers' => array('providers', 'streams')),
            'providers' => array('Add Provider' => array('provider', 'streams')),
            'ip' => array('Blocked IPs' => array('ips', 'block_ips')),
            'ips' => array('Block IP' => array('ip', 'block_ips'), 'Flush Blocks' => array('ips?flush=1', 'block_ips')),
            'isp' => array('Blocked ISPs' => array('isps', 'block_isps')),
            'isps' => array('Block ISP' => array('isp', 'block_isps')),
            'line' => array('Manage Lines' => array('lines', 'users')),
            'user' => array('Manage Users' => array('users', 'mng_regusers')),
            'mag' => array('MAG Devices' => array('mags', 'manage_mag')),
            'enigma' => array('Enigma Devices' => array('enigmas', 'manage_e2')),
            'line_ips' => array('Manage Lines' => array('lines', 'users')),
            'line_mass' => array('Manage Lines' => array('lines', 'users'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')),
            'user_mass' => array('Manage Users' => array('users', 'mng_regusers'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')),
            'mag_mass' => array('Manage Devices' => array('mags', 'manage_mag'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')),
            'enigma_mass' => array('Manage Devices' => array('enigmas', 'manage_e2'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')),
            'stream_mass' => array('Manage Streams' => array('streams', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')),
            'created_channel_mass' => array('Manage Channels' => array('created_channels', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')),
            'movie_mass' => array('Manage Movies' => array('movies', 'movies'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')),
            'radio_mass' => array('Manage Stations' => array('radios', 'radio'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')),
            'series_mass' => array('Manage Series' => array('series', 'series'), 'Manage Episodes' => array('episodes', 'episodes'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')),
            'episodes_mass' => array('Manage Episodes' => array('episodes', 'episodes'), 'Manage Series' => array('series', 'series'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')),
            'mag_events' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"'), 'MAG Devices' => array('mags', 'manage_mag')),
            'login_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'mysql_syslog' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Export as JSON' => array(null, null, 'id="btn-export-json"')),
            'mass_delete' => array('Manage Streams' => array('streams', 'streams'), 'Manage Channels' => array('created_channels', 'streams'), 'Manage Series' => array('series', 'series'), 'Manage Episodes' => array('episodes', 'episodes'), 'Manage Stations' => array('radios', 'radio'), 'Manage Lines' => array('lines', 'users'), 'Manage Users' => array('users', 'mng_regusers'), 'Manage MAGs' => array('mags', 'manage_mag'), 'Manage Enigmas' => array('enigmas', 'manage_e2')),
            'quick_tools' => array('Stream Tools' => array('stream_tools', 'stream_tools')),
            'stream_tools' => array('Quick Tools' => array('quick_tools', 'quick_tools')),
            'profile' => array('Manage Profiles' => array('profiles', 'tprofiles')),
            'profiles' => array('Create Profile' => array('profile', 'tprofile')),
            'rtmp_ips' => array('Add IP' => array('rtmp_ip', 'add_rtmp')),
            'rtmp_ip' => array('RTMP IPs' => array('rtmp_ips', 'rtmp')),
            'server' => array('View Server' => array('server_view?id=' . $rID, 'servers'), 'Manage Servers' => array('servers', 'servers')),
            'proxy' => array('View Proxy' => array('server_view?id=' . $rID, 'servers'), 'Manage Proxies' => array('proxies', 'servers')),
            'server_install' => array('Manage Servers' => array('servers', 'servers'), 'Manage Proxies' => array('proxies', 'servers')),
            'servers' => array('Install Server' => array('server_install', 'add_server'), 'Server Order' => array('server_order', 'servers'), 'Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor'), 'Update All Servers' => array(null, 'servers', 'onClick="updateAll();"'), 'Update All Binaries' => array(null, 'servers', 'onClick="updateBinaries();"'), 'Restart All Services' => array(null, 'servers', 'onClick="restartServices();"')),
            'server_order' => array('Servers' => array('servers', 'servers'), 'Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor')),
            'proxies' => array('Install Proxy' => array('server_install?proxy=1', 'add_server'), 'Servers' => array('servers', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor')),
            'stream_category' => array('Manage Categories' => array('stream_categories', 'categories')),
            'ticket' => array('View Ticket' => array('ticket_view?id=' . $rID, 'ticket'), 'View Tickets' => array('tickets', 'manage_tickets')),
            'ticket_view' => array('Add Response' => array('ticket?id=' . $rID, 'ticket'), 'View Tickets' => array('tickets', 'manage_tickets')),
            'useragent' => array('Blocked User-Agents' => array('useragents', 'block_uas')),
            'useragents' => array('Block User-Agent' => array('useragent', 'block_uas')),
            'watch' => array('Add Folder' => array('watch_add', 'folder_watch_add'), 'Settings' => array('settings_watch', 'folder_watch_settings'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Kill Running' => array(null, 'folder_watch_settings', 'onClick="killWatchFolder();"'), 'Enable All' => array(null, 'folder_watch_settings', 'onClick="enableAll();"'), 'Disable All' => array(null, 'folder_watch_settings', 'onClick="disableAll();"')),
            'watch_add' => array('Manage Folders' => array('watch', 'folder_watch')),
            'plex' => array('Add Library' => array('plex_add', 'folder_watch_add'), 'Settings' => array('settings_plex', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output'), 'Kill Running' => array(null, 'folder_watch_settings', 'onClick="killPlexSync();"'), 'Enable All' => array(null, 'folder_watch_settings', 'onClick="enableAll();"'), 'Disable All' => array(null, 'folder_watch_settings', 'onClick="disableAll();"')),
            'plex_add' => array('Manage Libraries' => array('plex', 'folder_watch'))
        );

        $rDropdown['servers'] = array('Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor'));

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
            // Export actions: only on log/report pages, and only with backups perm.
            if (in_array($rLabel, ['Export as CSV', 'Export as JSON'], true)) {
                if (!in_array($page, self::EXPORT_PAGES, true) || !Authorization::check('adv', 'backups')) {
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
                'logType' => ($rId === 'btn-clear-logs' ? (self::LOG_TYPES[$page] ?? null) : null),
                'primary' => $rFirst,
            ];
            $rFirst = false;
        }

        return $rItems;
    }
}
