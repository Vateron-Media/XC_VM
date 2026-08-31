<?php

declare(strict_types=1);

namespace XcVm\Core\Reference;

use XcVm\Core\Localization\Translator;

/**
 * Reseller sub-permission catalogue for the group editor.
 *
 * Replaces the legacy `$rPermissionKeys` / `$rAdvPermissions` globals from
 * resources/data/admin_constants.php. `advanced()` is built lazily (and
 * memoised) so it can resolve localized labels via {@see Translator},
 * which is always initialised by the time the group view renders.
 *
 * @package XC_VM_Core_Reference
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class PermissionReference {
    /** Ordered list of reseller sub-permission keys. */
    private const KEYS = array(
        'add_rtmp',
        'add_bouquet',
        'add_cat',
        'add_e2',
        'add_epg',
        'add_episode',
        'add_group',
        'add_mag',
        'add_movie',
        'add_packages',
        'add_radio',
        'add_reguser',
        'add_server',
        'add_stream',
        'tprofile',
        'add_series',
        'add_user',
        'block_ips',
        'block_isps',
        'block_uas',
        'create_channel',
        'edit_bouquet',
        'edit_cat',
        'channel_order',
        'edit_cchannel',
        'edit_e2',
        'epg_edit',
        'edit_episode',
        'folder_watch_settings',
        'settings',
        'edit_group',
        'edit_mag',
        'edit_movie',
        'edit_package',
        'edit_radio',
        'edit_reguser',
        'edit_server',
        'edit_stream',
        'edit_series',
        'edit_user',
        'fingerprint',
        'import_episodes',
        'import_movies',
        'import_streams',
        'database',
        'mass_delete',
        'mass_sedits_vod',
        'mass_sedits',
        'mass_edit_users',
        'mass_edit_lines',
        'mass_edit_mags',
        'mass_edit_enigmas',
        'mass_edit_streams',
        'mass_edit_radio',
        'mass_edit_reguser',
        'ticket',
        'subreseller',
        'stream_tools',
        'bouquets',
        'categories',
        'client_request_log',
        'connection_logs',
        'manage_cchannels',
        'credits_log',
        'index',
        'manage_e2',
        'epg',
        'folder_watch',
        'folder_watch_output',
        'mng_groups',
        'live_connections',
        'login_logs',
        'manage_mag',
        'manage_events',
        'movies',
        'mng_packages',
        'player',
        'process_monitor',
        'radio',
        'mng_regusers',
        'reg_userlog',
        'rtmp',
        'servers',
        'stream_errors',
        'streams',
        'subresellers',
        'manage_tickets',
        'tprofiles',
        'series',
        'users',
        'episodes',
        'edit_tprofile',
        'folder_watch_add',
        'add_code',
        'add_hmac',
        'block_asns',
        'panel_logs',
        'quick_tools',
        'restream_logs'
    );

    /** @var array<int, array{0: string, 1: string, 2: string}>|null Memoised advanced-permission rows. */
    private static ?array $advanced = null;

    /**
     * Ordered list of reseller sub-permission keys.
     *
     * @return array<int, string>
     */
    public static function keys(): array {
        return self::KEYS;
    }

    /**
     * Localized advanced-permission rows for the group editor, each row
     * being `[key, title, description]`. Built once and memoised.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function advanced(): array {
        if (self::$advanced !== null) {
            return self::$advanced;
        }

        $rows = [];
        foreach (self::KEYS as $key) {
            $rows[] = array(
                $key,
                Translator::get('permission_' . $key),
                Translator::get('permission_' . $key . '_text'),
            );
        }

        self::$advanced = $rows;
        return $rows;
    }
}
