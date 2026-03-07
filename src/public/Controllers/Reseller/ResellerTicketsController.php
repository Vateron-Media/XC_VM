<?php
/**
 * ResellerTicketsController — Tickets listing.
 */
class ResellerTicketsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('tickets');
    }
}
