<?php
/**
 * XC_VM — Контроллер списка тикетов (admin/tickets.php)
 */
namespace App\Http\Controllers\Admin;

class TicketsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rStatusArray = array('CLOSED', 'OPEN', 'RESPONDED TO', 'READ BY USER', 'NEW RESPONSE', 'READ BY ME', 'READ BY USER');

        $this->setTitle('Tickets');
        $this->render('tickets', compact('rStatusArray'));
    }
}
