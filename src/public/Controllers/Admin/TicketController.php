<?php
/**
 * Контроллер редактирования тикета (admin/ticket.php)
 */

class TicketController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rTicket = getTicket(RequestManager::getAll()['id']);
        if (!$rTicket) {
            $this->redirect('tickets');
            return;
        }

        $this->setTitle('Ticket');
        $this->render('ticket', compact('rTicket'));
    }
}
