<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Line\LineService;
use XcVm\Public\Controllers\Admin\BaseAdminController;

/**
 * BaseResellerController — базовый контроллер для reseller-страниц.
 *
 * Наследует BaseAdminController, переопределяя:
 *   - $scope = 'reseller' (для renderUnifiedLayout и путей views)
 *   - requirePermission() → checkResellerPermissions()
 *
 * @see BaseAdminController
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BaseResellerController extends BaseAdminController {
	/** @var string Scope: 'reseller' */
	protected $scope = 'reseller';

	/**
	 * Проверка прав доступа реселлера.
	 * При отказе — redirect на главную + exit.
	 */
	protected function requirePermission() {
		if (!PageAuthorization::checkResellerPermissions()) {
			AdminHelpers::goHome();
			exit;
		}
	}

	/**
	 * Проверка расширенных прав реселлера.
	 * Reseller permissions загружены через getGroupPermissions().
	 *
	 * @param string $type Тип прав
	 * @param string $key  Ключ прав
	 */
	protected function requireAdvPermission(string $type, string $key) {
		if (!Authorization::check($type, $key)) {
			AdminHelpers::goHome();
			exit;
		}
	}

	/**
	 * Publish the trial gate before the page renders: the "Generate Trial" menu
	 * entries check can_generate_trials through Authorization (the global
	 * $rPermissions) and the line/MAG/Enigma views read $rGenTrials.
	 */
	protected function render(string $view, array $data = []) {
		$rGenTrials = LineService::canGenerateTrials((int) $GLOBALS['rUserInfo']['id']);
		$GLOBALS['rGenTrials'] = $rGenTrials;
		$GLOBALS['rPermissions']['can_generate_trials'] = $rGenTrials;

		return parent::render($view, $data);
	}
}
