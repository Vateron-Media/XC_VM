<?php
/**
 * ResellerTicketController — Create/edit ticket (Phase 6.4 — Reseller).
 */
class ResellerTicketController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('ticket');
    }
}
