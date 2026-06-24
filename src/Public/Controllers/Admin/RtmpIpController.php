<?php

/**
 * RtmpIpController — rtmp ip controller
 *
 * @renders Views/admin/rtmp_ips.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RtmpIpController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        global $db;
        $this->setTitle("RTMP IP's");

        $this->render('rtmp_ips', [
            'ips' => BlocklistService::getRTMPIPsSimple(),
        ]);
    }
}
