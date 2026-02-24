<?php
/**
 * XC_VM — Контроллер списка пользователей (admin/users.php)
 */
namespace App\Http\Controllers\Admin;

class UsersController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Users');
        $this->render('users');
    }
}
