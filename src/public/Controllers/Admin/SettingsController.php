<?php

/**
 * SettingsController — General Settings (admin/settings.php).
 *
 * GET /settings
 * Massive multi-tab form with ~80+ switchery toggles, select2 dropdowns,
 * 9 tabs (General, Security, API, Streaming, MAG, Web Player, Logs, Info, Database).
 *
 * @renders Views/admin/settings.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();

        $rSettings = SettingsManager::getAll();
        $rStreamArguments = StreamConfigRepository::getStreamArguments();

        $versionData = json_decode(@file_get_contents(BIN_PATH . 'maxmind/version.json'), true) ?: [];
        $GeoLite2 = $versionData['geolite2_version'] ?? 'N/A';
        $GeoISP   = $versionData['geoisp_version'] ?? 'N/A';
        $Nginx    = trim(shell_exec(BIN_PATH . 'nginx/sbin/nginx -v 2>&1 | cut -d\'/\' -f2') ?: '');
        $rUpdate  = json_decode((string) ($rSettings['update_data'] ?? ''), true) ?: [];

        // Global lookup arrays from includes/admin.php needed by settings view
        $rTMDBLanguages = $GLOBALS['rTMDBLanguages'] ?? [];
        $rGeoCountries  = $GLOBALS['rGeoCountries'] ?? [];
        $rMAGs          = $GLOBALS['rMAGs'] ?? [];

        $this->setTitle('Settings');
        $this->render('settings', compact(
            'rSettings',
            'rStreamArguments',
            'GeoLite2',
            'GeoISP',
            'Nginx',
            'rUpdate',
            'rTMDBLanguages',
            'rGeoCountries',
            'rMAGs'
        ));
    }
}
