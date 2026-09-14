<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Stream\CategoryTemplateService;

/**
 * ActiveCodeController — Admin Generate Active Codes Wizard
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ActiveCodeController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('Generate Active Codes');

		$categoryTemplates = CategoryTemplateService::getTemplatesForUser(
			$GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []),
			true
		);

		$this->render('active_code', [
			'rPackages'         => PackageService::getAll(null, 'line') ?: [],
			'rBouquets'         => BouquetService::getAllSimple() ?: [],
			'rResellers'        => ActiveCodeService::getResellersForAssignment(),
			'categoryTemplates' => $categoryTemplates,
		]);
	}
}
