<?php
/**
 * ProcessMonitorController — Process Monitor (Phase 6.3 — Group M).
 */
class ProcessMonitorController extends BaseAdminController
{
    public function index()
    {
        global $rServers;

        $this->requirePermission();

        if (!isset(CoreUtilities::$rRequest['server']) || !isset($rServers[CoreUtilities::$rRequest['server']])) {
            CoreUtilities::$rRequest['server'] = SERVER_ID;
        }

        if (isset(CoreUtilities::$rRequest['clear'])) {
            freeTemp(CoreUtilities::$rRequest['server']);
            header('Location: ./process_monitor?server=' . CoreUtilities::$rRequest['server']);
            exit();
        }

        if (isset(CoreUtilities::$rRequest['clear_s'])) {
            freeStreams(CoreUtilities::$rRequest['server']);
            header('Location: ./process_monitor?server=' . CoreUtilities::$rRequest['server']);
            exit();
        }

        $rStreams = getStreamPIDs(CoreUtilities::$rRequest['server']) ?: array();
        $rFS = getFreeSpace(CoreUtilities::$rRequest['server']) ?: array();
        $rProcesses = getPIDs(CoreUtilities::$rRequest['server']) ?: array();
        $rStatus = array('D' => 'Uninterruptible Sleep', 'I' => 'Idle', 'R' => 'Running', 'S' => 'Interruptible Sleep', 'T' => 'Stopped', 'W' => 'Paging', 'X' => 'Dead', 'Z' => 'Zombie');

        $this->setTitle('Process Monitor');
        $this->render('process_monitor', compact('rStreams', 'rFS', 'rProcesses', 'rStatus'));
    }
}
