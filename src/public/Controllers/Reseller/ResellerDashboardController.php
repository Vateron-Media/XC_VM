<?php
/**
 * ResellerDashboardController — Reseller dashboard.
 */
class ResellerDashboardController extends BaseResellerController
{
    public function index()
    {
        $this->render('dashboard');
    }
}
