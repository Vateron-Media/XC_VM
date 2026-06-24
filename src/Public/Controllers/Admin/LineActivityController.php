<?php

/**
 * LineActivityController — логи активности линий.
 *
 * @renders Views/admin/line_activity.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineActivityController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $data = [];

        if (isset(RequestManager::getAll()['user_id'])) {
            $rSearchUser = UserRepository::getLineById(RequestManager::getAll()['user_id']);
            if ($rSearchUser) {
                $data['rSearchUser'] = $rSearchUser;
            }
        }

        if (isset(RequestManager::getAll()['stream_id'])) {
            $rSearchStream = StreamRepository::getById(RequestManager::getAll()['stream_id']);
            if ($rSearchStream) {
                $data['rSearchStream'] = $rSearchStream;
            }
        }

        $this->render('line_activity', $data);
    }
}
