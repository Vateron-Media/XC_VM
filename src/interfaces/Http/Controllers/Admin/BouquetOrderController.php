<?php

/**
 * BouquetOrderController — Bouquet Order (admin/bouquet_order.php).
 *
 * Drag & drop упорядочивание букетов. POST обработка — legacy post.php.
 *
 * Legacy: admin/bouquet_order.php (167 строк)
 * Route:  GET /admin/bouquet_order → index()
 */
class BouquetOrderController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Bouquet Order');

        $bouquets = function_exists('getBouquets') ? getBouquets() : [];

        $this->render('bouquet_order', [
            'bouquets' => $bouquets,
        ]);
    }
}
