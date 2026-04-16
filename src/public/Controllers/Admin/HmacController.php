<?php

/**
 * HmacController — HMAC Keys (admin/hmacs.php).
 *
 * Листинг HMAC-токенов с edit/delete.
 *
 * Legacy: admin/hmacs.php (273 строки)
 * Route:  GET /admin/hmacs → index()
 *
 * @renders Views/admin/hmacs.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class HmacController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('HMAC Keys');

        $hmacs = AuthRepository::getAllHMAC();

        $this->render('hmacs', [
            'hmacs' => $hmacs,
        ]);
    }
}
