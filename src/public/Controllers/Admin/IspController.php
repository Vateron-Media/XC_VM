<?php

/**
 * IspController — Blocked ISP's (admin/isps.php).
 *
 * Листинг заблокированных ISP с edit/delete.
 *
 * Legacy: admin/isps.php (264 строки)
 * Route:  GET /admin/isps → index()
 */
class IspController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle("Blocked ISP's");

        $isps = function_exists('getISPs') ? getISPs() : [];

        $this->render('isps', [
            'isps' => $isps,
        ]);
    }
}
