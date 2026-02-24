<?php
/**
 * ResellerLineController — Line edit/create (Phase 6.4 — Reseller).
 */
class ResellerLineController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line');
    }
}
