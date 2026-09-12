<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;

/**
 * ActiveCodeAjaxController — Admin-ajax controller for Activation Codes.
 *
 * Endpoints:
 * - action=generate_active_codes
 * - action=active_codes_batch_action
 * - action=active_codes_export_txt
 *
 * (action=active_code_details renders an HTML fragment, so it lives in its own
 * {@see \XcVm\Public\Controllers\Admin\ActiveCodeDetailsController}.)
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodeAjaxController extends BaseAjaxController
{

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
