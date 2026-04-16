<?php

/**
 * Контроллер редактирования MAG-устройства (admin/mag.php)
 *
 * @renders Views/admin/mag.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rDevice = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rDevice = MagService::getById(RequestManager::getAll()['id']);
            if (!$rDevice['user_id']) {
                exit();
            }
        }

        if (isset($rDevice) && !isset($rDevice['user'])) {
            $rDevice['user'] = array('bouquet' => array());
        }

        $this->setTitle('MAG Device');
        $this->render('mag', compact('rDevice'));
    }
}
