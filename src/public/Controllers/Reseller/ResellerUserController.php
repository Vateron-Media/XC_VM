<?php
/**
 * ResellerUserController — Sub-reseller edit/create (Phase 6.4 — Reseller).
 */
class ResellerUserController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('user');
    }
}
