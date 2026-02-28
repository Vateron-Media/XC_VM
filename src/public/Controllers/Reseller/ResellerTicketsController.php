<?php
/**
 * ResellerTicketsController — Tickets listing (Phase 6.4 — Reseller).
 */
class ResellerTicketsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('tickets');
    }
}
