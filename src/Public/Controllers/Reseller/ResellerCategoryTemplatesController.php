<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;

/**
 * ResellerCategoryTemplatesController — Category Templates Management for Resellers.
 *
 * @renders Views/reseller/category_templates.php
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ResellerCategoryTemplatesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Category Templates');

        $user = $GLOBALS['rUserInfo'] ?? [];
        $search = RequestManager::has('search') ? trim((string)RequestManager::get('search')) : null;

        $templates = CategoryTemplateService::getTemplatesForUser($user, false, null, $search);

        $this->render('category_templates', [
            'templates'   => $templates,
            'search'      => $search,
            'isAdmin'     => false,
            'currentUser' => $user
        ]);
    }
}
