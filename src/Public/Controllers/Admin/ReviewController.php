<?php

/**
 * ReviewController — Review imported streams/movies.
 * Very complex data-prep: M3U import processing, category matching, stream/movie API calls.
 * Data-prep is ~160 lines; handled in controller index() method.
 *
 * @renders Views/admin/review.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ReviewController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rType = isset(RequestManager::getAll()['type']) ? intval(RequestManager::getAll()['type']) : 1;
        $rCategorySet = [];
        $rLogoSet = [];

        $this->setTitle('Review');
        $this->render('review', compact('rType', 'rCategorySet', 'rLogoSet'));
    }
}
