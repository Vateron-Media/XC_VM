<?php
/**
 * XC_VM — Контроллер просмотра тикета (admin/ticket_view.php)
 */

class TicketViewController extends BaseAdminController {
    public function index() {
        global $db, $rUserInfo;

        $this->requirePermission();

        $rTicketInfo = null;
        if (isset(CoreUtilities::$rRequest['id'])) {
            $rTicketInfo = getTicket(CoreUtilities::$rRequest['id']);
        }
        if (!$rTicketInfo) {
            $this->redirect('tickets');
            return;
        }

        if ($rUserInfo['id'] != $rTicketInfo['member_id']) {
            $db->query('UPDATE `tickets` SET `admin_read` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['id']);
        }

        $this->setTitle('View Ticket');
        $this->render('ticket_view', compact('rTicketInfo'));
    }
}
