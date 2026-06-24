<?php

/**
 * RtmpIpEditController — add/edit RTMP IP.
 *
 * Route: GET /admin/rtmp_ip → index()
 *
 * @renders Views/admin/rtmp_ip.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RtmpIpEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rIPArr = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rIPArr = BlocklistService::getRTMPIPById($id);
            if (!$rIPArr) {
                AdminHelpers::goHome();
                return;
            }
        }

        $this->setTitle('RTMP IP');
        $this->render('rtmp_ip', compact('rIPArr'));
    }
}
