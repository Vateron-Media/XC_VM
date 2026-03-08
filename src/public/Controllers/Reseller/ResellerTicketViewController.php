<?php
/**
 * ResellerTicketViewController — View ticket.
 */
class ResellerTicketViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $rRequest = RequestManager::getAll();

        if (!isset($rRequest['id']) || !($rTicketInfo = getTicket($rRequest['id']))) {
            goHome();
            return;
        }

        if (!Authorization::check('user', $rTicketInfo['member_id'])) {
            exit();
        }

        // Mark ticket as read
        global $db;
        $rUserInfo = $GLOBALS['rUserInfo'];
        if ($rUserInfo['id'] != $rTicketInfo['member_id']) {
            $db->query('UPDATE `tickets` SET `admin_read` = 1 WHERE `id` = ?;', $rRequest['id']);
        } else {
            $db->query('UPDATE `tickets` SET `user_read` = 1 WHERE `id` = ?;', $rRequest['id']);
        }

        $this->setTitle('View Ticket');
        $this->render('ticket_view', [
            'rTicketInfo' => $rTicketInfo,
        ]);
    }
}
