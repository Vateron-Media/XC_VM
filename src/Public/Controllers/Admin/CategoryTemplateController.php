<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Stream\CategoryTemplateService;

/**
 * CategoryTemplateController — Category Template Editor for Admin.
 *
 * @renders Views/admin/category_template.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CategoryTemplateController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$id = (int) ($this->input('id', 0) ?: ($_GET['id'] ?? 0));
		if ($id <= 0) {
			header('Location: category_templates');
			exit;
		}

		$template = CategoryTemplateService::getTemplateById($id);
		if (!$template) {
			header('Location: category_templates');
			exit;
		}

		$this->setTitle('Category Template: ' . $template['name']);

		$categories = CategoryTemplateService::getEditorCategories($id);
		$user = $GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []);

		$this->render('category_template', [
			'template'    => $template,
			'categories'  => $categories,
			'isAdmin'     => true,
			'currentUser' => $user
		]);
	}
}
