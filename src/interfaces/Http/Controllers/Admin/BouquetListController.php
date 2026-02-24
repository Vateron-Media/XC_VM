<?php

/**
 * BouquetListController — Bouquets listing (admin/bouquets.php).
 *
 * Листинг букетов с действиями delete.
 *
 * Legacy: admin/bouquets.php (163 строк)
 * Route:  GET /admin/bouquets → index()
 */
class BouquetListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Bouquets');

        $bouquets = function_exists('getBouquets') ? getBouquets() : [];

        $this->render('bouquets', [
            'bouquets' => $bouquets,
        ]);
    }
}
