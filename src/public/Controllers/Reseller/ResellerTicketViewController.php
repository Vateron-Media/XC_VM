<?php
/**
 * ResellerTicketViewController — View ticket.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerTicketViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $rRequest = RequestManager::getAll();

        if (!isset($rRequest['id']) || !($rTicketInfo = TicketRepository::getById($rRequest['id']))) {
            AdminHelpers::goHome();
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
