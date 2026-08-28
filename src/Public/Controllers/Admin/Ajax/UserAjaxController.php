<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\TicketRepository;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;

/**
 * Admin-ajax controller for the "Users & Lines" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: line, line_activity,
 * adjust_credits, reg_user, ticket. Block logic ported faithfully (scaffolding
 * via gate/ok/fail from {@see BaseAjaxController}; empty-then / nested if-else
 * cascades flattened — behaviour-preserving; comments English).
 *
 * `line`'s enable/disable/ban/unban/kill sub-actions are shared with the
 * device controllers via {@see LineStateTrait}.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class UserAjaxController extends BaseAjaxController {

    use LineStateTrait;

    /** action=line — line operations (delete + shared line-state sub-actions). */
    public function line(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_user');

        $rUserID = intval(RequestManager::get('user_id'));
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            LineService::deleteLineById($rUserID);
            $this->ok();
        }

        $this->lineStateAction($rSub, $rUserID);
    }

    /** action=line_activity — kill a single live connection by pid. */
    public function lineActivity(): never {
        $this->requireXhr();
        $this->gate('adv', 'connection_logs');

        if (RequestManager::get('sub') != 'kill') {
            $this->fail();
        }

        ConnectionTracker::closeConnection(RequestManager::get('pid'));

        $this->ok();
    }

    /** action=adjust_credits — add/subtract reseller credits and log it. */
    public function adjustCredits(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_reguser');

        global $db, $rUserInfo;
        $rUser = UserRepository::getRegisteredUserById(RequestManager::get('id'));

        if ($rUser && is_numeric(RequestManager::get('credits'))) {
            $rCredits = intval($rUser['credits']) + intval(RequestManager::get('credits'));

            if (0 <= $rCredits) {
                $db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rCredits, $rUser['id']);
                $db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['id'], $rUserInfo['id'], RequestManager::get('credits'), time(), RequestManager::get('reason'));
                $this->ok();
            }

            $this->fail();
        }

        $this->fail();
    }

    /** action=reg_user — delete/enable/disable a registered (reseller) user. */
    public function regUser(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_reguser');

        global $db, $rUserInfo;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            UserService::deleteRegisteredUser(RequestManager::get('user_id'), false, false, $rUserInfo['id']);
            $this->ok();
        }

        if ($rSub == 'enable') {
            $db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('user_id'));
            $this->ok();
        }

        if ($rSub == 'disable') {
            $db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('user_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=ticket — delete/close/reopen a support ticket. */
    public function ticket(): never {
        $this->requireXhr();
        $this->gate('adv', 'ticket');

        global $db;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            TicketRepository::deleteById(RequestManager::get('ticket_id'));
            $this->ok();
        }

        if ($rSub == 'close') {
            $db->query('UPDATE `tickets` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('ticket_id'));
            $this->ok();
        }

        if ($rSub == 'reopen') {
            $db->query('UPDATE `tickets` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('ticket_id'));
            $this->ok();
        }

        $this->fail();
    }
}
