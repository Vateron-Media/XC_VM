<?php

/**
 * Контроллер редактирования User-Agent (admin/useragent.php)
 *
 * @renders Views/admin/useragent.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UseragentController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rUAArr = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rUAArr = BlocklistService::getUserAgentById(RequestManager::getAll()['id']);
            if (!$rUAArr) {
                $this->redirect('useragents');
                return;
            }
        }

        $this->setTitle('Block User-Agent');
        $this->render('useragent', compact('rUAArr'));
    }
}
