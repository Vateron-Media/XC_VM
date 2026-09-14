<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Domain\User\UserRepository;

/**
 * CategoryTemplatesController — Category Templates Management for Admin.
 *
 * @renders Views/admin/category_templates.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CategoryTemplatesController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('Category Templates');

		$user = $GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []);
		$filterOwnerId = RequestManager::has('owner_id') ? (int) RequestManager::get('owner_id') : null;
		$search = RequestManager::has('search') ? trim((string) RequestManager::get('search')) : null;

		$templates = CategoryTemplateService::getTemplatesForUser($user, true, $filterOwnerId, $search);
		$owners = UserRepository::getRegisteredUsers();

		$this->render('category_templates', [
			'templates'     => $templates,
			'owners'        => $owners,
			'selectedOwner' => $filterOwnerId,
			'search'        => $search,
			'isAdmin'       => true,
			'currentUser'   => $user
		]);
	}
}
