<?php

/**
 * Контроллер редактирования EPG (admin/epg.php)
 *
 * @renders Views/admin/epg.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgController extends BaseAdminController {
    public function index() {
        $rEPGArr = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rEPGArr = EpgService::getById(RequestManager::getAll()['id']);
            if (!$rEPGArr) {
                exit();
            }
        }

        $this->setTitle('EPG');
        $this->render('epg', compact('rEPGArr'));
    }
}
