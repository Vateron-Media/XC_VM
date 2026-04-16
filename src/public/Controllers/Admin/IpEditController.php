<?php

/**
 * IpEditController — add/edit blocked IP.
 *
 * Route: GET /admin/ip → index()
 *
 * @renders Views/admin/ip.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class IpEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Blocked IP');
        $this->render('ip');
    }
}
