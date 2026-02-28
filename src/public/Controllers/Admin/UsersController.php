<?php
/**
 * XC_VM — Контроллер списка пользователей (admin/users.php)
 */
class UsersController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Users');
        $this->render('users');
    }
}
