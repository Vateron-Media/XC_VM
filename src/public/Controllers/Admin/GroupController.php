<?php

/**
 * GroupController — Groups (admin/groups.php).
 *
 * Листинг групп пользователей с edit/delete (проверка hasPermissions).
 *
 * Legacy: admin/groups.php (240 строк)
 * Route:  GET /admin/groups → index()
 *
 * @renders Views/admin/groups.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class GroupController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('Groups');

        $groups = GroupService::getAll();

        $this->render('groups', [
            'groups' => $groups,
        ]);
    }
}
