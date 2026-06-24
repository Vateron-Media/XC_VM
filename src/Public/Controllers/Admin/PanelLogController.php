<?php

/**
 * PanelLogController — Panel Errors (admin/panel_logs.php).
 *
 * Server-side DataTable с логами ошибок панели. Download/Clear logs.
 *
 * Legacy: admin/panel_logs.php (292 строк)
 * Route:  GET /admin/panel_logs → index()
 *
 * @renders Views/admin/panel_logs.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PanelLogController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Panel Errors');
        $this->render('panel_logs');
    }
}
