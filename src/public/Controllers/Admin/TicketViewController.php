<?php
/**
 * Контроллер просмотра тикета (admin/ticket_view.php)
 */

class TicketViewController extends BaseAdminController {
    public function index() {
        global $db, $rUserInfo;

        $this->requirePermission();

        $rTicketInfo = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rTicketInfo = getTicket(RequestManager::getAll()['id']);
        }
        if (!$rTicketInfo) {
            $this->redirect('tickets');
            return;
        }

        if ($rUserInfo['id'] != $rTicketInfo['member_id']) {
            $db->query('UPDATE `tickets` SET `admin_read` = 1 WHERE `id` = ?;', RequestManager::getAll()['id']);
        }

        $this->setTitle('View Ticket');
        $this->render('ticket_view', compact('rTicketInfo'));
    }
}
