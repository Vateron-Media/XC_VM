<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;

/**
 * ActiveCodeAjaxController — Admin-ajax controller for Activation Codes.
 *
 * Endpoints:
 * - action=active_code_details
 * - action=generate_active_codes
 * - action=active_codes_batch_action
 * - action=active_codes_export_txt
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodeAjaxController extends BaseAjaxController
{
    /**
     * action=active_code_details — Get full voucher & companion line details for modal.
     */
    public function details(): never
    {
        $this->requireXhr();

        global $db;
        $codeId = intval(RequestManager::get('id') ?? 0);
        if (!$codeId) {
            $this->fail(['message' => 'Missing code ID.']);
        }

        $code = $db->fetchOne(
            "SELECT `activation_codes`.*, `lines`.`username` as `sub_username`, `lines`.`password` as `sub_password`,
                    `lines`.`exp_date` as `sub_exp_date`, `lines`.`max_connections` as `line_max_conn`
             FROM `activation_codes`
             LEFT JOIN `lines` ON `lines`.`id` = `activation_codes`.`subscriber_id`
             WHERE `activation_codes`.`id` = ? LIMIT 1;",
            $codeId
        );

        if (!$code) {
            $this->fail(['message' => 'Activation code not found.']);
        }

        $package = PackageService::getById((int)$code['package_id']);
        $portalUrl = rtrim($code['dns_base'] ?: DomainResolver::resolve(SERVER_ID), '/');
        $portalParsed = parse_url($portalUrl);

        $m3uHls = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=hls";
        $m3uTs  = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=ts";

        $portalCode = AuthRepository::getActiveCodePortalCode();
        $playerCode = AuthRepository::getWebPlayerCode();

        $subscriberPortalUrl = $portalCode ? "{$portalUrl}/{$portalCode}/" : "{$portalUrl}/portal";
        $directActivateUrl   = "{$subscriberPortalUrl}?code=" . urlencode((string)$code['activation_code']);
        $webPlayerUrl        = $playerCode ? "{$portalUrl}/{$playerCode}/" : null;

        $this->ok([
            'data' => [
                'id' => (int)$code['id'],
                'code' => $code['activation_code'],
                'batch_name' => $code['batch_name'],
                'status' => (int)$code['status'],
                'status_text' => ($code['status'] == 1) ? 'Ready (Stock)' : (($code['status'] == 2) ? 'Active' : 'Disabled'),
                'package_name' => $package['package_name'] ?? 'Custom Package',
                'is_trial' => (bool)$code['is_trial'],
                'max_connections' => (int)($code['line_max_conn'] ?: $code['max_connections']),
                'exp_date' => $code['sub_exp_date'] ? date('Y-m-d H:i:s', (int)$code['sub_exp_date']) : 'Frozen (Stock)',
                'activated_at' => $code['activated_at'] ? date('Y-m-d H:i:s', (int)$code['activated_at']) : 'Never',
                'created_at' => $code['created_at'] ? date('Y-m-d H:i:s', (int)$code['created_at']) : '-',
                'mac' => $code['mac'] ?: 'None',
                'device_id' => $code['device_id'] ?: 'None',
                'username' => $code['sub_username'],
                'password' => $code['sub_password'],
                'server' => $portalParsed['host'] ?? 'localhost',
                'port' => $portalParsed['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80),
                'portal_url' => $portalUrl,
                'activation_portal_url' => $subscriberPortalUrl,
                'direct_activate_url' => $directActivateUrl,
                'web_player_url' => $webPlayerUrl,
                'm3u_hls' => $m3uHls,
                'm3u_ts' => $m3uTs,
            ]
        ]);
    }

    /**
     * action=generate_active_codes — Generate active codes batch (Admin).
     */
    public function generate(): never
    {
        $this->requireXhr();

        $data = RequestManager::getAll();
        $user = $GLOBALS['rUserInfo'] ?? [];
        $res = ActiveCodeService::generateCodes($data, $user, true);

        if ($res['status'] === 'SUCCESS') {
            $this->ok([
                'message' => $res['message'] ?? '',
                'batch_name' => $res['batch_name'] ?? null,
                'qty' => $res['qty'] ?? 0,
                'codes' => $res['codes'] ?? []
            ]);
        }

        $this->fail([
            'message' => $res['message'] ?? 'Failed to generate codes.'
        ]);
    }

    /**
     * action=active_codes_batch_action — Batch enable/disable/delete (Admin).
     */
    public function batchAction(): never
    {
        $this->requireXhr();

        global $db;
        $batchName = trim(RequestManager::get('batch_name') ?? '');
        $subAction = trim(RequestManager::get('sub_action') ?? '');
        $refund = !empty(RequestManager::get('refund_credits'));

        if (empty($batchName)) {
            $this->fail(['message' => 'Missing batch name.']);
        }

        $codes = $db->fetchAll(
            "SELECT `id` FROM `activation_codes` WHERE `batch_name` = ?;",
            $batchName
        );

        if (empty($codes)) {
            $this->fail(['message' => 'No codes found for this batch.']);
        }

        $ids = array_column($codes, 'id');
        $user = $GLOBALS['rUserInfo'] ?? [];
        $res = ActiveCodeService::massAction($subAction, $ids, $user, true, ['refund_credits' => $refund]);

        if ($res['status'] === 'SUCCESS') {
            $this->ok(['message' => $res['message'] ?? 'Batch action processed.']);
        }

        $this->fail(['message' => $res['message'] ?? 'Batch action failed.']);
    }

    /**
     * action=active_codes_export_txt — Export physical scratch card vouchers as .txt.
     */
    public function exportTxt(): never
    {
        $batchName = trim(RequestManager::get('batch_name') ?? '');
        if (empty($batchName)) {
            exit('Invalid batch name');
        }

        $user = $GLOBALS['rUserInfo'] ?? [];
        $content = ActiveCodeService::exportBatchTxt($batchName, $user, true);
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $batchName) . '_vouchers.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit();
    }
}
