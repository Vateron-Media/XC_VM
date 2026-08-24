<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\User\UserRepository;

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

        if (RequestManager::has('user_id')) {
            $rSearchUser = UserRepository::getLineById(RequestManager::get('user_id'));
        }

        if (RequestManager::has('stream_id')) {
            $rSearchStream = StreamRepository::getById(RequestManager::get('stream_id'));
        }

        $this->setTitle('Live Connections');
        $this->render('live_connections', compact('rSearchUser', 'rSearchStream'));
    }
}
