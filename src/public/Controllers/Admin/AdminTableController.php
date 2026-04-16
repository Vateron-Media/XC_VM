<?php

/**
 * AdminTableController — DataTables JSON endpoint for admin panel.
 *
 * Proxy controller that delegates to the self-contained Views/admin/table.php.
 * table.php handles its own auth (session, API key, localhost API),
 * XHR validation, and DataTables JSON response.
 *
 * @renders public/Views/admin/table.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminTableController extends BaseAdminController {
    public function index() {
        require MAIN_HOME . 'public/Views/admin/table.php';
    }
}
