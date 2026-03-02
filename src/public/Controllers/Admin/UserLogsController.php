<?php
/**
 * XC_VM — Контроллер логов пользователей (admin/user_logs.php)
 */
class UserLogsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('User Logs');
        $this->render('user_logs');
    }
}
