<?php
/**
 * Контроллер редактирования пользователя (admin/user.php)
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

        $rPackages = $rUser ? getPackages($rUser['member_group_id']) : [];

        $this->setTitle('User');
        $this->render('user', compact('rUser', 'rPackages'));
    }
}
