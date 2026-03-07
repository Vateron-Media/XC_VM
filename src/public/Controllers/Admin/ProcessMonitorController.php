<?php
/**
 * ProcessMonitorController — Process Monitor.
 */
class ProcessMonitorController extends BaseAdminController
{
    public function index()
    {
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
        $rProcesses = getPIDs(RequestManager::getAll()['server']) ?: array();
        $rStatus = array('D' => 'Uninterruptible Sleep', 'I' => 'Idle', 'R' => 'Running', 'S' => 'Interruptible Sleep', 'T' => 'Stopped', 'W' => 'Paging', 'X' => 'Dead', 'Z' => 'Zombie');

        $this->setTitle('Process Monitor');
        $this->render('process_monitor', compact('rStreams', 'rFS', 'rProcesses', 'rStatus'));
    }
}
