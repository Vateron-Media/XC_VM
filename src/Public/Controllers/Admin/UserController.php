<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\UserCredits;

/**
 * Контроллер редактирования пользователя (admin/user.php)
 *
 * @renders Views/admin/user.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		global $db;

		// The user as stored: the form escapes what it prints and posts it back,
		// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
		$rUser = null;
		if (RequestManager::has('id')) {
			$db->query('SELECT * FROM `users` WHERE `id` = ?;', intval(RequestManager::get('id')));
			$rUser = ($rStored = $db->get_raw_row()) ? UserCredits::amounts($rStored) : null;
		}
		// An id that names no user (the lookup answers null, never false: this
		// showed the "add user" form in its place).
		if (RequestManager::has('id') && $rUser === null) {
			$this->redirect('users');
			return;
		}

		// The form shows the balance, and posts it back as `credits_shown`, as it is
		// stored: SELECT * reads a FLOAT at six significant digits (1234567 as 1234570).
		if ($rUser) {
			$rUser['credits'] = UserCredits::balance((int) $rUser['id']);
		}

		$rPackages = $rUser ? PackageService::getAll($rUser['member_group_id']) : [];

		$this->setTitle('User');
		$this->render('user', ['rUser' => $rUser, 'rPackages' => $rPackages]);
	}
}
