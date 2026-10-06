<?php

namespace XcVm\Public\Controllers\Admin;

/**
 * AdminActionController — Admin Actions (admin/admin_actions.php): the admin
 * action trail (Core\Audit\AdminAudit), read through TableController's
 * `admin_actions` table.
 *
 * Route:  GET /admin/admin_actions → index()
 *
 * @renders Views/admin/admin_actions.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminActionController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('Admin Actions');
		$this->render('admin_actions');
	}
}
