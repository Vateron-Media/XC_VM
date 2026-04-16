<?php

/**
 * StreamCategoriesController — список категорий стримов.
 *
 * @renders Views/admin/stream_categories.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamCategoriesController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rCategories = [1 => CategoryService::getAllByType(), 2 => CategoryService::getAllByType('movie'), 3 => CategoryService::getAllByType('series'), 4 => CategoryService::getAllByType('radio')];
        $rMainCategories = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ([1, 2, 3, 4] as $rID) {
            foreach ($rCategories[$rID] as $rCategoryData) {
                $rMainCategories[$rID][] = $rCategoryData;
            }
        }

        $this->setTitle('Stream Categories');
        $this->render('stream_categories', compact('rCategories', 'rMainCategories'));
    }
}
