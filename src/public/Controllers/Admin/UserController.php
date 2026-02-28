<?php
/**
 * XC_VM — Контроллер редактирования пользователя (admin/user.php)
 */

class UserController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rUser = isset(CoreUtilities::$rRequest['id']) ? UserRepository::getRegisteredUserById(CoreUtilities::$rRequest['id']) : null;
        if ($rUser === false) {
            $this->redirect('users');
            return;
        }

        $rPackages = $rUser ? getPackages($rUser['member_group_id']) : [];

        $this->setTitle('User');
        $this->render('user', compact('rUser', 'rPackages'));
    }
}
