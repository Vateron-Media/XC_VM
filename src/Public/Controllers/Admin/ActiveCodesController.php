<?php

namespace XcVm\Public\Controllers\Admin;

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
        $this->render('active_codes');
    }
}
