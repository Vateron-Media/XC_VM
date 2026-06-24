<?php

/**
 * LiveConnectionsController — активные подключения.
 *
 * @renders Views/admin/live_connections.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LiveConnectionsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rSearchUser = null;
        $rSearchStream = null;

        if (isset(RequestManager::getAll()['user_id'])) {
            $rSearchUser = UserRepository::getLineById(RequestManager::getAll()['user_id']);
        }

        if (isset(RequestManager::getAll()['stream_id'])) {
            $rSearchStream = StreamRepository::getById(RequestManager::getAll()['stream_id']);
        }

        $this->setTitle('Live Connections');
        $this->render('live_connections', compact('rSearchUser', 'rSearchStream'));
    }
}
