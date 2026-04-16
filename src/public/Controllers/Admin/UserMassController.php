<?php

/**
 * Контроллер массового редактирования пользователей (admin/user_mass.php)
 *
 * @renders Views/admin/user_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Mass Edit Users');
        $this->render('user_mass');
    }
}
