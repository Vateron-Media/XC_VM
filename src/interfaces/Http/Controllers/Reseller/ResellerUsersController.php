<?php
/**
 * ResellerUsersController — Sub-resellers listing (Phase 6.4 — Reseller).
 */
class ResellerUsersController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('users');
    }
}
