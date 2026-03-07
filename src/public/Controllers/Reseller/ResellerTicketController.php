<?php
/**
 * ResellerTicketController — Create/edit ticket.
 */
class ResellerTicketController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('ticket');
    }
}
