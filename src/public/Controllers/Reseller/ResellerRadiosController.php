<?php
/**
 * ResellerRadiosController — Radio stations listing (read-only) (Phase 6.4 — Reseller).
 */
class ResellerRadiosController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('radios');
    }
}
