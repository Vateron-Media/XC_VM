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
        $scope = RequestManager::has('scope') ? trim((string)RequestManager::get('scope')) : '';

        $allTemplates = CategoryTemplateService::getTemplatesForUser($user, false, null, $search);

        $counts = [
            'total'       => count($allTemplates),
            'mine'        => count(array_filter($allTemplates, static fn($t) => !empty($t['is_mine']))),
            'subreseller' => count(array_filter($allTemplates, static fn($t) => !empty($t['is_subreseller']))),
            'admin'       => count(array_filter($allTemplates, static fn($t) => !empty($t['is_system']) || !empty($t['is_admin_shared']))),
        ];

        $templates = $allTemplates;
        if ($scope === 'mine') {
            $templates = array_values(array_filter($allTemplates, static fn($t) => !empty($t['is_mine'])));
        } elseif ($scope === 'subreseller') {
            $templates = array_values(array_filter($allTemplates, static fn($t) => !empty($t['is_subreseller'])));
        } elseif ($scope === 'admin') {
            $templates = array_values(array_filter($allTemplates, static fn($t) => !empty($t['is_system']) || !empty($t['is_admin_shared'])));
        }

        $this->render('category_templates', [
            'templates'    => $templates,
            'search'       => $search,
            'scope'        => $scope,
            'counts'       => $counts,
            'isAdmin'      => false,
            'currentUser'  => $user
        ]);
    }
}
