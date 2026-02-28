<?php
/**
 * XC_VM — Контроллер массового редактирования пользователей (admin/user_mass.php)
 */
class UserMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Mass Edit Users');
        $this->render('user_mass');
    }
}
