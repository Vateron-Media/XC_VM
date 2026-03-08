<?php
/**
 * ResellerTicketController — Create/edit ticket.
 */
class ResellerTicketController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Ticket');

        $rRequest = RequestManager::getAll();
        $rTicketInfo = null;

        if (isset($rRequest['id'])) {
            $rTicketInfo = getTicket($rRequest['id']);
            if (!$rTicketInfo) {
                goHome();
                return;
            }
            if (!Authorization::check('user', $rTicketInfo['member_id'])) {
                exit();
            }
        }

        $this->render('ticket', [
            'rTicketInfo' => $rTicketInfo,
        ]);
    }
}
