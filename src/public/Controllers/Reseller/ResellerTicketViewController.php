<?php
/**
 * ResellerTicketViewController — View ticket (Phase 6.4 — Reseller).
 */
class ResellerTicketViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('ticket_view');
    }
}
