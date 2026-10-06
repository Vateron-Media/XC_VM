<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\PackageService;

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
		// The packages codes are sold from: those that sell subscriptions or give trials.
		$rPackages = array_filter(PackageService::getAll($rUserInfo['member_group_id'] ?? 0, 'line') ?: [], static fn(array $rPackage): bool => $rPackage['is_official'] || $rPackage['is_trial']);
		$rBouquets = BouquetService::getAllSimple() ?: [];
		$categoryTemplates = \XcVm\Domain\Stream\CategoryTemplateService::getTemplatesForUser(
			$rUserInfo,
			false
		);

		$this->render('active_code', [
			'rPackages'         => $rPackages,
			'rBouquets'         => $rBouquets,
			'categoryTemplates' => $categoryTemplates,
		]);
	}
}
