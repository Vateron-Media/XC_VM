<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;

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
		// Installing or removing a module is system-level: the page takes the
		// permission of the settings page, as its menu links and actions do.
		$this->requireAdvPermission('adv', 'settings');

		$this->setTitle('Modules');
		$this->render('modules', [
			'hasStoreKey' => (string) (SettingsManager::get('platform_api_key') ?? '') !== '',
			// The store the core extension talks to (its ini setting, else its default).
			'storeUrl'    => rtrim((string) (ini_get('xcvm_core.server_url') ?: 'https://www.xcvm.tech'), '/'),
		]);
	}
}
