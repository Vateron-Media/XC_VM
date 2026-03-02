<?php

class RtmpIpController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        global $db;
        $this->setTitle("RTMP IP's");

        $this->render('rtmp_ips', [
            'ips' => BlocklistService::getRTMPIPsSimple(),
        ]);
    }
}
