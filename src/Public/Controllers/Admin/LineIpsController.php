<?php

/**
 * LineIpsController — IP-использование линий.
 *
 * @renders Views/admin/line_ips.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineIpsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rRange = intval(RequestManager::getAll()['range'] ?? 0);
        $rLineIPs = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'lines_per_ip')) ?: [];

        $this->render('line_ips', compact('rRange', 'rLineIPs'));
    }
}
