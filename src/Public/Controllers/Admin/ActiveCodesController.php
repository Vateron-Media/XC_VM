<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;

/**
 * ActiveCodesController — Admin Active Codes Management
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodesController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Active Codes');
        $this->render('active_codes', [
            'rPackages' => PackageService::getAll(1, 'line') ?: [],
            'resellers' => ActiveCodeService::getResellersWithCodes(),
            'batches'   => ActiveCodeService::getRecentBatchNames(),
        ]);
    }
}
