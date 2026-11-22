<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Module\ModuleManager;

/**
 * ModulesController — admin module management page.
 *
 * Renders the page only: the modules, their actions, the background job and
 * the store are ModuleAjaxController's endpoints, which the page calls.
 *
 * @renders Views/admin/modules.php
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ModulesController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		if (!Authorization::isFullAdmin()) {
			http_response_code(403);
			exit;
		}

		$this->setTitle('Modules');
		$this->render('modules', [
			'hasStoreKey' => (string) (SettingsManager::get('platform_api_key') ?? '') !== '',
			'localUploadsEnabled' => ModuleManager::localUploadsEnabled(),
			// The store the core extension talks to (its ini setting, else its default).
			'storeUrl'    => rtrim((string) (ini_get('xcvm_core.server_url') ?: 'https://www.xcvm.tech'), '/'),
		]);
	}
}
