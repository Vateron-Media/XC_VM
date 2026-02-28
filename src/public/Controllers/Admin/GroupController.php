<?php

/**
 * GroupController — Groups (admin/groups.php).
 *
 * Листинг групп пользователей с edit/delete (проверка hasPermissions).
 *
 * Legacy: admin/groups.php (240 строк)
 * Route:  GET /admin/groups → index()
 */
class GroupController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Groups');

        $groups = GroupService::getAll();

        $this->render('groups', [
            'groups' => $groups,
        ]);
    }
}
