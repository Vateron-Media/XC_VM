<?php
/**
 * RtmpMonitorController — мониторинг RTMP.
 */
class RtmpMonitorController extends BaseAdminController
{
    public function index()
    {
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
