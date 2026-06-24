<?php

/**
 * WatchAddController — Add/Edit Watch Folder.
 *
 * @renders Views/admin/watch_add.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchAddController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rFolder = null;
        $id = $this->input('id');
        if (isset($id)) {
            $rFolder = StreamRepository::getWatchFolder($id);
            if (!$rFolder) {
                $this->redirect('watch');
                return;
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Add Folder');
        $this->render('watch_add', compact('rFolder', 'rBouquets'));
    }
}
