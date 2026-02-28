<?php
/**
 * XC_VM — Контроллер редактирования тикета (admin/ticket.php)
 */

class TicketController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rTicket = getTicket(CoreUtilities::$rRequest['id']);
        if (!$rTicket) {
            $this->redirect('tickets');
            return;
        }

        $this->setTitle('Ticket');
        $this->render('ticket', compact('rTicket'));
    }
}
