<?php
/**
 * LineIpsController — IP-использование линий (Phase 6.3 — Group C).
 */
class LineIpsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rRange = intval(CoreUtilities::$rRequest['range'] ?? 0);
        $rLineIPs = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'lines_per_ip')) ?: [];

        $this->render('line_ips', compact('rRange', 'rLineIPs'));
    }
}
