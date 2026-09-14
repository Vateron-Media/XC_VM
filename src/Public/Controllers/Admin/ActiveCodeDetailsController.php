<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Line\PackageService;

/**
 * ActiveCodeDetailsController — voucher-details modal body.
 *
 * Renders Views/admin/active_code_details.php as a raw HTML fragment for the
 * Active Codes management modal (loaded over ajax). The controller only prepares
 * data and includes the view — no output buffering, the view is the response
 * body; all markup lives in the template.
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodeDetailsController
{
    /**
     * action=active_code_details — full voucher & companion line details.
     */
    public function index(): never
    {
        if (
            !(defined('PHP_ERRORS') && PHP_ERRORS)
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
        ) {
            exit();
        }

        global $db;
        $codeId = (int) (RequestManager::get('id') ?? 0);

        $code = $codeId ? $db->fetchOne(
            "SELECT `activation_codes`.*, `lines`.`username` as `sub_username`, `lines`.`password` as `sub_password`,
                    `lines`.`exp_date` as `sub_exp_date`, `lines`.`max_connections` as `line_max_conn`
             FROM `activation_codes`
             LEFT JOIN `lines` ON `lines`.`id` = `activation_codes`.`subscriber_id`
             WHERE `activation_codes`.`id` = ? LIMIT 1;",
            $codeId
        ) : null;

        if (!$code) {
            http_response_code(404);
            exit();
        }

        $package = PackageService::getById((int) $code['package_id']);
        $portalUrl = $this->resolveBaseUrl((string) ($code['dns_base'] ?? ''));
        $portalParsed = parse_url($portalUrl);

        $m3uHls = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=hls";
        $m3uTs  = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=ts";

        $portalCode = AuthRepository::getActiveCodePortalCode();
        $playerCode = AuthRepository::getWebPlayerCode();

        $subscriberPortalUrl = $portalCode ? "{$portalUrl}/{$portalCode}/" : "{$portalUrl}/portal";
        $directActivateUrl   = "{$subscriberPortalUrl}?code=" . urlencode((string) $code['activation_code']);
        $webPlayerUrl        = $playerCode ? "{$portalUrl}/{$playerCode}/" : null;

        $d = [
            'id' => (int) $code['id'],
            'code' => $code['activation_code'],
            'batch_name' => $code['batch_name'],
            'status' => (int) $code['status'],
            'status_text' => ($code['status'] == 1) ? 'Ready (Stock)' : (($code['status'] == 2) ? 'Active' : 'Disabled'),
            'package_name' => $package['package_name'] ?? 'Custom Package',
            'is_trial' => (bool) $code['is_trial'],
            'max_connections' => (int) ($code['line_max_conn'] ?: $code['max_connections']),
            'exp_date' => $code['sub_exp_date'] ? date('Y-m-d H:i:s', (int) $code['sub_exp_date']) : 'Frozen (Stock)',
            'activated_at' => $code['activated_at'] ? date('Y-m-d H:i:s', (int) $code['activated_at']) : 'Never',
            'created_at' => $code['created_at'] ? date('Y-m-d H:i:s', (int) $code['created_at']) : '-',
            'mac' => $code['mac'] ?: 'None',
            'device_id' => $code['device_id'] ?: 'None',
            'username' => $code['sub_username'],
            'password' => $code['sub_password'],
            'server' => $portalParsed['host'] ?? 'localhost',
            'port' => $portalParsed['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80),
            'portal_url' => $portalUrl,
            'activation_portal_url' => $subscriberPortalUrl,
            'direct_activate_url' => $directActivateUrl,
            'web_player_url' => $webPlayerUrl,
            'm3u_hls' => $m3uHls,
            'm3u_ts' => $m3uTs,
        ];

        // Expose the translator to the view the same way BaseAdminController does,
        // so the fragment can use $language::get(...) like every other view.
        $language = Translator::class;

        header('Content-Type: text/html; charset=utf-8');
        require MAIN_HOME . 'Public/Views/admin/active_code_details.php';
        exit();
    }

    /**
     * Public base URL for the subscriber portal / credential links.
     *
     * Honours a well-formed per-code dns_base (one that carries an http(s)://
     * scheme); otherwise falls back to this panel's own request origin, which is
     * where the portal is served. Returns no trailing slash.
     */
    private function resolveBaseUrl(string $dnsBase): string
    {
        $dnsBase = trim($dnsBase);
        if ($dnsBase !== '' && preg_match('#^https?://#i', $dnsBase)) {
            return rtrim($dnsBase, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return $host !== '' ? $scheme . '://' . $host : '';
    }
}
