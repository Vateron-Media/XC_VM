<?php

/**
 * MagEventController — MAG Events (admin/mag_events.php).
 *
 * Server-side DataTable с MAG-событиями. API delete.
 *
 * Legacy: admin/mag_events.php (176 строк)
 * Route:  GET /admin/mag_events → index()
 *
 * @renders Views/admin/mag_events.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagEventController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('MAG Events');
        $this->render('mag_events');
    }
}
