<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Domain\Line\ActiveCodeService;

/**
 * ResellerActiveCodesBatchController — Batch Manager for Active Codes
 *
 * @package XC_VM_Public_Controllers_Reseller
 */
class ResellerActiveCodesBatchController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Batch Manager');

        $rUserInfo = $GLOBALS['rUserInfo'] ?? [];
        $batches = ActiveCodeService::getBatchSummary($rUserInfo, false);

        $this->render('active_codes_batch', [
            'batches' => $batches,
        ]);
    }
}
