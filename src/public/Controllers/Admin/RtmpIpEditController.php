<?php
/**
 * RtmpIpEditController — add/edit RTMP IP (Phase 6.3 — Group H).
 *
 * Route: GET /admin/rtmp_ip → index()
 */
class RtmpIpEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rIPArr = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rIPArr = function_exists('getRTMPIP') ? getRTMPIP($id) : null;
            if (!$rIPArr) {
                if (function_exists('goHome')) {
                    goHome();
                }
                return;
            }
        }

        $this->setTitle('RTMP IP');
        $this->render('rtmp_ip', compact('rIPArr'));
    }
}
