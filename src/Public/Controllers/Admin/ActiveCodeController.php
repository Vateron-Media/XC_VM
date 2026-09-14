<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\PackageService;

/**
 * ActiveCodeController — Admin Generate Active Codes Wizard
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodeController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Generate Active Codes');

        global $db;
        $rPackages = PackageService::getAll(1, 'line') ?: [];
        $rBouquets = BouquetService::getAllSimple() ?: [];

        // Fetch all resellers for creator assignment
        $db->query('SELECT `id`, `username`, `credits` FROM `users` ORDER BY `username` ASC;');
        $rResellers = $db->get_rows() ?: [];

        $categoryTemplates = \XcVm\Domain\Stream\CategoryTemplateService::getTemplatesForUser(
            $GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []),
            true
        );

        $this->render('active_code', [
            'rPackages'         => $rPackages,
            'rBouquets'         => $rBouquets,
            'rResellers'        => $rResellers,
            'categoryTemplates' => $categoryTemplates,
        ]);
    }
}
