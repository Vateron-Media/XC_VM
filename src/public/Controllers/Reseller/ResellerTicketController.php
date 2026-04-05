<?php
/**
 * ResellerTicketController — Create/edit ticket.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
