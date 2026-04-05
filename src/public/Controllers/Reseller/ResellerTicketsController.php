<?php
/**
 * ResellerTicketsController — Tickets listing.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerTicketsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Tickets');

        $statusArray = ['CLOSED', 'OPEN', 'RESPONDED TO', 'READ BY ME', 'NEW RESPONSE', 'READ BY ADMIN', 'READ BY USER'];

        $this->render('tickets', [
            'statusArray' => $statusArray,
            'tickets'     => getTickets($GLOBALS['rUserInfo']['id']),
        ]);
    }
}
