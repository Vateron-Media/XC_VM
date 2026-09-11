<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Line\ActiveCodeService;

/**
 * ActiveCodesBatchController — Admin Batch Manager
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodesBatchController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Active Codes Batch Manager');

        $batches = ActiveCodeService::getBatchSummary([], true);

        $this->render('active_codes_batch', [
            'batches' => $batches,
        ]);
    }
}
