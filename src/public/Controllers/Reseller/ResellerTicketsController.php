<?php
/**
 * ResellerTicketsController — Tickets listing.
 */
class ResellerTicketsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Tickets');

        $statusArray = ['CLOSED', 'OPEN', 'RESPONDED TO', 'READ BY ME', 'NEW RESPONSE', 'READ BY ADMIN', 'READ BY USER'];

        $this->render('tickets', [
            'statusArray' => $statusArray,
            'tickets'     => getTickets($GLOBALS['rUserInfo']['id']),
        ]);
    }
}
