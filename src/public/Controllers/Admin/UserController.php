<?php

/**
 * Контроллер редактирования пользователя (admin/user.php)
 *
 * @renders Views/admin/user.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rUser = isset(RequestManager::getAll()['id']) ? UserRepository::getRegisteredUserById(RequestManager::getAll()['id']) : null;
        if ($rUser === false) {
            $this->redirect('users');
            return;
        }

        $rPackages = $rUser ? PackageService::getAll($rUser['member_group_id']) : [];

        $this->setTitle('User');
        $this->render('user', compact('rUser', 'rPackages'));
    }
}
