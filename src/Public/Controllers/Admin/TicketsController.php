<?php

/**
 * Контроллер списка тикетов (admin/tickets.php)
 *
 * @renders Views/admin/tickets.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TicketsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rStatusArray = array('CLOSED', 'OPEN', 'RESPONDED TO', 'READ BY USER', 'NEW RESPONSE', 'READ BY ME', 'READ BY USER');

        $this->setTitle('Tickets');
        $this->render('tickets', compact('rStatusArray'));
    }
}
