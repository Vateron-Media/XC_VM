<?php
/**
 * ResellerTicketViewController — View ticket.
 */
class ResellerTicketViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('ticket_view');
    }
}
