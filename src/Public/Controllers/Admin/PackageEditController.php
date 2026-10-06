<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\User\UserCredits;

/**
 * PackageEditController — add/edit package.
 *
 * Route: GET /admin/package → index()
 *
 * @renders Views/admin/package.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PackageEditController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$rPackage = null;
		$id = $this->input('id');
		if ($id !== null) {
			// The package as stored: the form escapes what it prints and posts it back,
			// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
			$db = $GLOBALS['db'];
			$db->query('SELECT * FROM `users_packages` WHERE `id` = ?;', intval($id));
			$rPackage = ($rStored = $db->get_raw_row()) ? UserCredits::amounts($rStored) : null;
			if (!$rPackage) {
				AdminHelpers::goHome();
				return;
			}
		}

		$this->setTitle('Package');
		$this->render('package', ['rPackage' => $rPackage]);
	}
}
