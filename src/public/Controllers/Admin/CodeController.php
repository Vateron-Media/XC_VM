<?php

/**
 * CodeController — Access Codes (admin/codes.php).
 *
 * Листинг access codes с edit/delete.
 *
 * Legacy: admin/codes.php (273 строки)
 * Route:  GET /admin/codes → index()
 *
 * @renders Views/admin/codes.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CodeController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('Access Codes');

        $codes = AuthRepository::getAllCodes();

        $this->render('codes', [
            'codes' => $codes,
        ]);
    }
}
