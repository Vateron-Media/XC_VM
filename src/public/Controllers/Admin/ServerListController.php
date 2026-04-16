<?php

/**
 * ServerListController — список серверов (admin/servers.php).
 *
 * GET /servers
 * Client-side DataTable — PHP рендерит <tbody>.
 * Данные: ServerRepository::getAll(true).
 *
 * @renders Views/admin/servers.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerListController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();
        $this->setTitle('Servers');

        $rServers = ServerRepository::getAll(true);
        $this->render('servers', compact('rServers'));
    }
}
