<?php

class RtmpIpController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle("RTMP IP's");

        $this->render('rtmp_ips', [
            'ips' => getRTMPIPs(),
        ]);
    }
}
