<?php
/**
 * ResellerMagController — MAG device edit/create (Phase 6.4 — Reseller).
 */
class ResellerMagController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('mag');
    }
}
