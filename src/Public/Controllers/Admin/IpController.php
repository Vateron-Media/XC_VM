<?php

/**
 * IpController — Blocked IP's (admin/ips.php).
 *
 * Листинг заблокированных IP-адресов с поддержкой flush и delete.
 *
 * Legacy: admin/ips.php (227 строк)
 * Route:  GET /admin/ips → index()
 *
 * @renders Views/admin/ips.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class IpController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        global $db;

        // Обработка flush перед рендером
        if ($this->input('flush') !== null) {
            BlocklistService::flushIPs();
            $this->redirect('./ips?status=' . STATUS_FLUSH);
        }

        $this->setTitle("Blocked IP's");

        $ips = BlocklistService::getBlockedIPsSimple();

        $this->render('ips', [
            'ips' => $ips,
        ]);
    }
}
