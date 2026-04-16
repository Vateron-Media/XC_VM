<?php

/**
 * EpgListController — EPG Files (admin/epgs.php).
 *
 * Листинг EPG-файлов с клиентским DataTable.
 * API: delete, reload, force_epg.
 *
 * Legacy: admin/epgs.php (233 строк)
 * Route:  GET /admin/epgs → index()
 *
 * @renders Views/admin/epgs.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgListController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('EPG Files');

        $epgs = EpgService::getAll();

        $this->render('epgs', [
            'epgs' => $epgs,
        ]);
    }
}
