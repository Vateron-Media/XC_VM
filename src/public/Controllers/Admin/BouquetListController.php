<?php

/**
 * BouquetListController — Bouquets listing (admin/bouquets.php).
 *
 * Листинг букетов с действиями delete.
 *
 * Legacy: admin/bouquets.php (163 строк)
 * Route:  GET /admin/bouquets → index()
 *
 * @renders Views/admin/bouquets.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BouquetListController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Bouquets');

        $rBouquets = BouquetService::getAllSimple();

        $this->render('bouquets', compact('rBouquets'));
    }
}
