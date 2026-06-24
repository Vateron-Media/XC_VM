<?php

/**
 * ServerInstallController — установка/переустановка сервера (admin/server_install.php).
 *
 * GET /server_install?proxy (type=1) или GET /server_install (type=2)
 * Опционально: ?id=N (reinstall), ?update (update).
 * Форма: 1–2 таба (Details + Server Coverage для proxy).
 *
 * @renders Views/admin/server_install.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerInstallController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();

        global $allServers, $rProxyServers;

        $rType = isset(\RequestManager::getAll()['proxy']) ? 1 : 2;
        $rServerArr = null;

        if ($this->input('id')) {
            $id = intval($this->input('id'));
            if ($rType === 1) {
                $rServerArr = $rProxyServers[$id] ?? null;
            } else {
                $rServerArr = $allServers[$id] ?? null;
            }

            if (!$rServerArr) {
                $this->redirect('servers');
                return;
            }
        }

        $title = ($rType === 1) ? 'Install Proxy' : 'Install Server';
        $this->setTitle($title);

        $this->render('server_install', compact('rType', 'rServerArr'));
    }
}
