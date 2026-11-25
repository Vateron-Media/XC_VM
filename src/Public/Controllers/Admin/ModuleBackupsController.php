<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Localization\Translator;
use XcVm\Core\Module\ModuleBackup;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Module-scoped database backup management page. */
final class ModuleBackupsController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		$db = DatabaseFactory::get();
		if ($db === null) {
			http_response_code(503);
			exit;
		}
		$this->setTitle(Translator::get('module_backups'));
		$this->render('module_backups', ['backups' => (new ModuleBackup($db, MAIN_HOME . 'Modules'))->list()]);
	}
}
