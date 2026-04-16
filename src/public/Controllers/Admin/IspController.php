<?php

/**
 * IspController — Blocked ISP's (admin/isps.php).
 *
 * Листинг заблокированных ISP с edit/delete.
 *
 * Legacy: admin/isps.php (264 строки)
 * Route:  GET /admin/isps → index()
 *
 * @renders Views/admin/isps.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class IspController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle("Blocked ISP's");

        $isps = BlocklistService::getAllISPs();

        $this->render('isps', [
            'isps' => $isps,
        ]);
    }
}
