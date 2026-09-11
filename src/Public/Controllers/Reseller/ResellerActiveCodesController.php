<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;

/**
 * ResellerActiveCodesController — Active Codes Inventory
 *
 * @package XC_VM_Public_Controllers_Reseller
 */
class ResellerActiveCodesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Active Codes');

        $rUserInfo = $GLOBALS['rUserInfo'] ?? [];
        $allowedReports = (array) ($rUserInfo['reports'] ?? [$rUserInfo['id'] ?? 0]);

        $this->render('active_codes', [
            'rPackages' => PackageService::getAll($rUserInfo['member_group_id'] ?? 0, 'line') ?: [],
            'batches'   => ActiveCodeService::getRecentBatchNames($allowedReports),
        ]);
    }
}
