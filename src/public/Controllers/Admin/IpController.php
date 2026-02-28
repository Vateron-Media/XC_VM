<?php

/**
 * IpController — Blocked IP's (admin/ips.php).
 *
 * Листинг заблокированных IP-адресов с поддержкой flush и delete.
 *
 * Legacy: admin/ips.php (227 строк)
 * Route:  GET /admin/ips → index()
 */
class IpController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        global $db;

        // Обработка flush перед рендером
        if ($this->input('flush') !== null) {
            if (function_exists('flushIPs')) {
                flushIPs();
            }
            $this->redirect('./ips?status=' . STATUS_FLUSH);
        }

        $this->setTitle("Blocked IP's");

        $ips = BlocklistService::getBlockedIPsSimple();

        $this->render('ips', [
            'ips' => $ips,
        ]);
    }
}
