<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\TicketRepository;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Reseller-ajax controller for sub-resellers and tickets.
 *
 * Actions: reg_user, adjust_credits, ticket.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class UserAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Create/edit a registered user (JSON) and exit.
	 */
	public function regUser(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_sub_resellers']) {
			if (Authorization::check('user', RequestManager::get('user_id'))) {
				$rSub = RequestManager::get('sub');
				$rUser = UserRepository::getRegisteredUserById(RequestManager::get('user_id'));

				// Neither the reseller's own account nor an administrator's is a
				// reseller's to delete or to switch off or on (GroupService::reservedGroups).
				if ($rUser && ($rUser['id'] == $rUserInfo['id'] || in_array(intval($rUser['member_group_id']), GroupService::reservedGroups()))) {
					echo json_encode(['result' => false]);
					exit();
				}

				if ($rSub == 'delete') {
					if ($rPermissions['delete_users']) {
						// The deleted user's credits return to the reseller: all its
						// balance holds now, moved from one balance to the other, once.
						// The move and the delete are one transaction: a user whose
						// credits could not be moved is not deleted. The user's row is
						// held from this read to the delete, so what it holds here is
						// what is moved.
						$db->beginTransaction();
						$rHeld = ($db->query('SELECT ROUND(COALESCE(`credits`, 0), 4) FROM `users` WHERE `id` = ? FOR UPDATE;', intval($rUser['id'])) ? floatval($db->get_col()) : null);
						$rReturned = UserCredits::transferAll(intval($rUser['id']), $rUserInfo['id']);
						if ($rHeld === null || ($rReturned == 0 && $rHeld != 0) || !UserService::deleteRegisteredUser(RequestManager::get('user_id'), false, false, $rUserInfo['id'])) {
							$db->rollback();
							echo json_encode(['result' => false]);
							exit();
						}
						$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUserInfo['id'], $rUserInfo['id'], $rReturned, time(), 'Deleted user: ' . $rUser['username']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('user_id'), intval($rReturned), intval(UserCredits::balance($rUserInfo['id'])), time(), json_encode($rUser));
						$db->commit();
						echo json_encode(['result' => true]);
						exit();
					}
					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('user_id'));
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rUser));
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('user_id'));
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rUser));
					echo json_encode(['result' => true]);
					exit();
				}

				echo json_encode(['result' => false]);
				exit();
			}

			echo json_encode(['result' => false, 'error' => 'No permissions.']);
			exit();
		}
		exit();
	}

	/**
	 * Adjust a sub-reseller's credit balance (JSON) and exit.
	 */
	public function adjustCredits(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_sub_resellers']) {
			if (Authorization::check('user', RequestManager::get('id'))) {
				$rUser = UserRepository::getRegisteredUserById(RequestManager::get('id'));

				// An administrator's account keeps its credits: they are not a
				// reseller's to move (GroupService::reservedGroups).
				if ($rUser && is_numeric(RequestManager::get('credits')) && !in_array(intval($rUser['member_group_id']), GroupService::reservedGroups())) {
					// Credits move between the reseller and one of its sub-resellers:
					// each side gives only what its balance holds now.
					// Whole credits move, and the logs say what moved: "2.9" moved 2 and was logged as 2.9.
					$rCredits = intval(RequestManager::get('credits'));
					if (UserCredits::transfer($rUserInfo['id'], $rUser['id'], $rCredits)) {
						$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['id'], $rUserInfo['id'], $rCredits, time(), RequestManager::get('reason'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'adjust_credits', RequestManager::get('id'), $rCredits, intval(UserCredits::balance($rUserInfo['id'])), time(), json_encode($rUser));
						echo json_encode(['result' => true]);
						exit();
					}
				}

				echo json_encode(['result' => false]);
				exit();
			}

			echo json_encode(['result' => false, 'error' => 'No permissions.']);
			exit();
		}
		exit();
	}

	/**
	 * Submit/handle a support ticket (JSON) and exit.
	 */
	public function ticket(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rTicket = TicketRepository::getById(RequestManager::get('ticket_id'));

		if ($rTicket) {
			if (Authorization::check('user', $rTicket['member_id'])) {
				$rSub = RequestManager::get('sub');

				if ($rSub == 'close') {
					$db->query('UPDATE `tickets` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('ticket_id'));
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'reopen') {
					if ($rTicket['member_id'] != $rUserInfo['id']) {
						$db->query('UPDATE `tickets` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('ticket_id'));
						echo json_encode(['result' => true]);
						exit();
					}
					exit();
				}
			} else {
				echo json_encode(['result' => false, 'error' => 'No permissions.']);
				exit();
			}
		}

		echo json_encode(['result' => false]);
		exit();
	}
}
