<?php

/**
 * ProcessMonitorController — Process Monitor.
 *
 * @renders Views/admin/process_monitor.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProcessMonitorController extends BaseAdminController {
    public function index() {
        global $rServers;

        $this->requirePermission();

        if (!isset(RequestManager::getAll()['server']) || !isset($rServers[RequestManager::getAll()['server']])) {
            RequestManager::update('server', SERVER_ID);
        }

        if (isset(RequestManager::getAll()['clear'])) {
            ServerRepository::freeTemp(RequestManager::getAll()['server']);
            header('Location: ./process_monitor?server=' . RequestManager::getAll()['server']);
            exit();
        }

        if (isset(RequestManager::getAll()['clear_s'])) {
            ServerRepository::freeStreams(RequestManager::getAll()['server']);
            header('Location: ./process_monitor?server=' . RequestManager::getAll()['server']);
            exit();
        }

        $rStreams = StreamRepository::getPIDs(RequestManager::getAll()['server']) ?: array();
        $rFS = ServerRepository::getFreeSpace(RequestManager::getAll()['server']) ?: array();
        $rProcesses = DiagnosticsService::getPIDs(RequestManager::getAll()['server']) ?: array();
        $rStatus = array('D' => 'Uninterruptible Sleep', 'I' => 'Idle', 'R' => 'Running', 'S' => 'Interruptible Sleep', 'T' => 'Stopped', 'W' => 'Paging', 'X' => 'Dead', 'Z' => 'Zombie');

        $this->setTitle('Process Monitor');
        $this->render('process_monitor', compact('rStreams', 'rFS', 'rProcesses', 'rStatus'));
    }
}
