<?php

/**
 * StreamCategoryController — редактирование категории стрима.
 *
 * @renders Views/admin/stream_category.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamCategoryController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        if (isset(RequestManager::getAll()['id'])) {
            $rCategoryArr = CategoryService::getById(RequestManager::getAll()['id']);
            if (!$rCategoryArr || !Authorization::check('adv', 'edit_cat')) {
                exit();
            }
        }

        $this->setTitle('Stream Category');
        $this->render('stream_category', compact('rCategoryArr'));
    }
}
