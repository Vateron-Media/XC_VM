<?php

/**
 * SettingsController — General Settings (admin/settings.php).
 *
 * GET /settings
 * Massive multi-tab form with ~80+ switchery toggles, select2 dropdowns,
 * 9 tabs (General, Security, API, Streaming, MAG, Web Player, Logs, Info, Database).
 */
class SettingsController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        $rSettings = getSettings();
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
