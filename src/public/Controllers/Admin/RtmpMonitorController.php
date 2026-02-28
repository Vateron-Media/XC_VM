<?php
/**
 * RtmpMonitorController — мониторинг RTMP (Phase 6.3 — Group A).
 */
class RtmpMonitorController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        if (!isset(CoreUtilities::$rRequest['server']) || !isset($rServers[CoreUtilities::$rRequest['server']])) {
            CoreUtilities::$rRequest['server'] = SERVER_ID;
        }

        $rRTMPInfo = ServerRepository::getRTMPStats('systemapirequest', CoreUtilities::$rRequest['server']);

        $this->setTitle('RTMP Monitor');
        $this->render('rtmp_monitor', compact('rRTMPInfo'));
    }
}
