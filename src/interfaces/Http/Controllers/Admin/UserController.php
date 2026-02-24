<?php
/**
 * XC_VM — Контроллер редактирования пользователя (admin/user.php)
 */
namespace App\Http\Controllers\Admin;

class UserController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rUser = isset(\CoreUtilities::$rRequest['id']) ? getRegisteredUser(\CoreUtilities::$rRequest['id']) : null;
        if ($rUser === false) {
            $this->redirect('users');
            return;
        }

        $rPackages = $rUser ? getPackages($rUser['member_group_id']) : [];

        $this->setTitle('User');
        $this->render('user', compact('rUser', 'rPackages'));
    }
}
