<?php

/**
 * WatchOutputController — Watch Folder Logs.
 *
 * @renders Views/admin/watch_output.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchOutputController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rServers = ServerRepository::getStreamingSimple($rPermissions);
        if (!is_array($rServers)) {
            $rServers = [];
        }

        $this->setTitle('Watch Folder Logs');
        $this->render('watch_output', compact('rServers'));
    }
}
