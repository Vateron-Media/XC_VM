<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Stream\CategoryTemplateService;

/**
 * ResellerActiveCodeController — Generate Active Codes
 *
 * @package XC_VM_Public_Controllers_Reseller
 */
class ResellerActiveCodeController extends BaseResellerController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('Generate Active Codes');

		$rUserInfo = $GLOBALS['rUserInfo'] ?? [];
		$rPackages = PackageService::getAll($rUserInfo['member_group_id'] ?? 0, 'line') ?: [];
		$rBouquets = BouquetService::getAllSimple() ?: [];
		$categoryTemplates = CategoryTemplateService::getTemplatesForUser($rUserInfo, false);

		$this->render('active_code', [
			'rPackages'         => $rPackages,
			'rBouquets'         => $rBouquets,
			'categoryTemplates' => $categoryTemplates,
		]);
	}
}
