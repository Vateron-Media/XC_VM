<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\Response;
use XcVm\Domain\Stream\StreamConfigRepository;

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
        Response::noCache();

        $rSettings = SettingsManager::getAll();
        $rStreamArguments = StreamConfigRepository::getStreamArguments();

        $versionData = json_decode(@file_get_contents(BIN_PATH . 'maxmind/version.json'), true) ?: [];
        $GeoLite2 = $versionData['geolite2_version'] ?? 'N/A';
        $GeoISP   = $versionData['geoisp_version'] ?? 'N/A';
        $Nginx    = trim(shell_exec(BIN_PATH . 'nginx/sbin/nginx -v 2>&1 | cut -d\'/\' -f2') ?: '');
        $rUpdate  = json_decode((string) ($rSettings['update_data'] ?? ''), true) ?: [];

        $binVersionData = json_decode(@file_get_contents(BIN_PATH . 'bin_version.json'), true) ?: [];
        $BinVersion = $binVersionData['release'] ?? 'N/A';
        $BinOS = self::resolveOsLabel($binVersionData);

        // xc_fanout daemon (`-version`), xcvm_core extension marker and yt-dlp — Info tab.
        $rFanoutBin = BIN_PATH . 'xc_fanout/xc_fanout';
        $FanoutVersion = (is_file($rFanoutBin) && is_executable($rFanoutBin))
            ? (ltrim(trim((string) shell_exec(escapeshellarg($rFanoutBin) . ' -version 2>/dev/null')), 'vV') ?: 'N/A')
            : 'N/A';

        $rCoreVerFile = rtrim((string) ini_get('extension_dir'), '/') . '/xcvm_core.version';
        $XcvmCoreVersion = is_file($rCoreVerFile)
            ? (trim((string) file_get_contents($rCoreVerFile)) ?: 'N/A')
            : (extension_loaded('xcvm_core') ? (phpversion('xcvm_core') ?: 'N/A') : 'N/A');

        $YtDlpVersion = (is_file(YOUTUBE_BIN) && is_executable(YOUTUBE_BIN))
            ? (trim((string) shell_exec(escapeshellarg(YOUTUBE_BIN) . ' --version 2>/dev/null')) ?: 'N/A')
            : 'N/A';

        $this->setTitle('Settings');
        $this->render('settings', compact(
            'rSettings',
            'rStreamArguments',
            'GeoLite2',
            'GeoISP',
            'Nginx',
            'BinVersion',
            'BinOS',
            'rUpdate',
            'FanoutVersion',
            'XcvmCoreVersion',
            'YtDlpVersion'
        ));
    }

    /**
     * Build the OS label for the Versions tab.
     *
     * Prefers the distribution recorded in bin_version.json (set by
     * update_binaries.sh). When that metadata is missing or still holds the
     * seed "unknown" placeholder, falls back to the running host's
     * /etc/os-release so the tab never shows "unknown unknown".
     *
     * @param array<string,mixed> $binVersionData
     */
    private static function resolveOsLabel(array $binVersionData): string {
        $dist    = trim((string) ($binVersionData['distribution'] ?? ''));
        $version = trim((string) ($binVersionData['distribution_version'] ?? ''));

        if ($dist !== '' && strcasecmp($dist, 'unknown') !== 0) {
            return ucfirst($dist) . ($version !== '' && strcasecmp($version, 'unknown') !== 0 ? ' ' . $version : '');
        }

        $osRelease = @parse_ini_file('/etc/os-release');
        if (is_array($osRelease)) {
            if (!empty($osRelease['PRETTY_NAME'])) {
                return (string) $osRelease['PRETTY_NAME'];
            }
            $label = trim((string) ($osRelease['NAME'] ?? '') . ' ' . (string) ($osRelease['VERSION_ID'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return 'N/A';
    }
}
