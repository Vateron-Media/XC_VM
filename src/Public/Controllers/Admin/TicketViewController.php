<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\TicketRepository;

/**
 * Контроллер просмотра тикета (admin/ticket_view.php)
 *
 * @renders Views/admin/ticket_view.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TicketViewController extends BaseAdminController {
    public function index() {
        global $db, $rUserInfo;

        $this->requirePermission();

        $rTicketInfo = null;
        if (RequestManager::has('id')) {
            $rTicketInfo = TicketRepository::getById(RequestManager::get('id'));
        }
        if (!$rTicketInfo) {
            $this->redirect('tickets');
            return;
        }

        if ($rUserInfo['id'] != $rTicketInfo['member_id']) {
            $db->query('UPDATE `tickets` SET `admin_read` = 1 WHERE `id` = ?;', RequestManager::get('id'));
        }

        $this->setTitle('View Ticket');
        $this->render('ticket_view', compact('rTicketInfo'));
    }
}
