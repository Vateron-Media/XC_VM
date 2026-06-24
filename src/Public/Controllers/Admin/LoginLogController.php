<?php

/**
 * LoginLogController — Login Logs (admin/login_logs.php).
 *
 * Server-side DataTable с логами входа. API block IP.
 *
 * Legacy: admin/login_logs.php (257 строк)
 * Route:  GET /admin/login_logs → index()
 *
 * @renders Views/admin/login_logs.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LoginLogController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Login Logs');
        $this->render('login_logs');
    }
}
