<?php

namespace XcVm\Public\Controllers\Admin;

/** VOD import results produced by manual imports and folder scans. */
final class VodImportLogsController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('VOD Import Logs');
		$this->render('vod_import_logs');
	}
}
