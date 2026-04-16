<?php

/**
 * RtmpMonitorController — мониторинг RTMP.
 *
 * @renders Views/admin/rtmp_monitor.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RtmpMonitorController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        if (!isset(RequestManager::getAll()['server']) || !isset($rServers[RequestManager::getAll()['server']])) {
            RequestManager::update('server', SERVER_ID);
        }

        $rRTMPInfo = ServerRepository::getRTMPStats(RequestManager::getAll()['server']);

        $this->setTitle('RTMP Monitor');
        $this->render('rtmp_monitor', compact('rRTMPInfo'));
    }
}
