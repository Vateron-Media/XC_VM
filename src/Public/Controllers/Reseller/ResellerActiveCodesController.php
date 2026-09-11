<?php

namespace XcVm\Public\Controllers\Reseller;

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
        $this->render('active_codes');
    }
}
