<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;

/**
 * ResellerCategoryTemplateController — Category Template Editor for Resellers.
 *
 * @renders Views/reseller/category_template.php
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ResellerCategoryTemplateController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $id = (int)RequestManager::get('id', 0);
        if ($id <= 0) {
            header('Location: category_templates');
            exit;
        }

        $template = CategoryTemplateService::getTemplateById($id);
        if (!$template) {
            header('Location: category_templates');
            exit;
        }

        $user = $GLOBALS['rUserInfo'] ?? [];
        $isOwner = ((int)$template['owner_id'] === (int)($user['id'] ?? 0));
        $isSystem = ((int)$template['is_system'] === 1);
        $canEdit = $isOwner && !$isSystem;

        $this->setTitle('Category Template: ' . $template['name']);

        $categories = CategoryTemplateService::getEditorCategories($id);

        $this->render('category_template', [
            'template'    => $template,
            'categories'  => $categories,
            'isAdmin'     => false,
            'isOwner'     => $isOwner,
            'canEdit'     => $canEdit,
            'currentUser' => $user
        ]);
    }
}
