<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Line\PackageService;

/**
 * ActiveCodesMassController — Admin Mass Edit Active Codes
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodesMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Mass Edit Active Codes');

        $rPackages = PackageService::getAll(1, 'line') ?: [];

        $this->render('active_codes_mass', [
            'rPackages' => $rPackages,
        ]);
    }
}
