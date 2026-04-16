<?php

/**
 * BouquetOrderController — Bouquet Order (admin/bouquet_order.php).
 *
 * Drag & drop упорядочивание букетов. POST обработка — legacy post.php.
 *
 * Legacy: admin/bouquet_order.php (167 строк)
 * Route:  GET /admin/bouquet_order → index()
 *
 * @renders Views/admin/bouquet_order.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BouquetOrderController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Bouquet Order');

        $bouquets = BouquetService::getAllSimple();

        $this->render('bouquet_order', [
            'bouquets' => $bouquets,
        ]);
    }
}
