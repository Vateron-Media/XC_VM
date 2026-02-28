<?php
/**
 * ResellerMagsController — MAG devices listing (Phase 6.4 — Reseller).
 */
class ResellerMagsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('mags');
    }
}
