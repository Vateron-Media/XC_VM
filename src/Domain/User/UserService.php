<?php

namespace XcVm\Domain\User;

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Line\LineService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * UserService — user service
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserService {
	use DatabaseAware;

	/**
	 * Bulk delete a set of selected users.
	 *
	 * @param array $rData Selected user ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rUsers = json_decode($rData['users'], true);
		self::deleteRegisteredUsers($rUsers);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Apply bulk edits to a set of selected users.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		if (InputValidator::validate('massEditUsers', $rData)) {
			$rArray = [];

			foreach (['status'] as $rItem) {
				if (isset($rData['c_' . $rItem])) {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			if (isset($rData['c_owner_id'])) {
				$rArray['owner_id'] = intval($rData['owner_id']);
			}

			if (isset($rData['c_member_group_id'])) {
				$rArray['member_group_id'] = intval($rData['member_group_id']);
			}

			// An administrator group is given out, and an administrator's account
			// changed, by a full administrator (GroupService::reservedGroups).
			$rReserved = GroupService::reservedGroups();

			if (isset($rArray['member_group_id']) && in_array($rArray['member_group_id'], $rReserved)) {
				return ['status' => STATUS_INVALID_GROUP, 'data' => $rData];
			}

			if (isset($rData['c_reseller_dns'])) {
				$rArray['reseller_dns'] = $rData['reseller_dns'];
			}

			if (isset($rData['c_override'])) {
				$rOverride = [];

				foreach ($rData as $rKey => $rCredits) {
					if (substr($rKey, 0, 9) == 'override_') {
						$rID = intval(explode('override_', $rKey)[1]);

						if ((string) $rCredits !== '') {
							$rCredits = intval($rCredits);
						} else {
							$rCredits = null;
						}

						if ($rCredits) {
							$rOverride[$rID] = ['assign' => 1, 'official_credits' => $rCredits];
						}
					}
				}
				$rArray['override_packages'] = json_encode($rOverride);
			}

			$rUsers = AdminHelpers::confirmIDs(json_decode($rData['users_selected'], true));

			if (count($rUsers) > 0) {
				if (isset($rData['c_owner_id']) && $rArray['owner_id'] == 0) {
					unset($rArray['owner_id']);
				}

				$rPrepare = QueryHelper::prepareArray($rArray);

				if (count($rPrepare['data']) > 0) {
					$rQuery = 'UPDATE `users` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ')' . (0 < count($rReserved) ? ' AND COALESCE(`member_group_id`, 0) NOT IN (' . implode(',', $rReserved) . ')' : '') . ';';
					$db->query($rQuery, ...$rPrepare['data']);
				}
			}

			return ['status' => STATUS_SUCCESS];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Create or update a user from admin form data.
	 *
	 * @param array $rData       Submitted form data (includes `edit` id when updating).
	 * @param bool  $rBypassAuth Skip permission checks (internal/trusted callers).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData, bool $rBypassAuth = false) {
		$db = self::db();
		if (InputValidator::validate('processUser', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_reguser') || $rBypassAuth) {
					$rUser = UserRepository::getRegisteredUserById($rData['edit']);
					$rArray = AdminHelpers::overwriteData($rUser, $rData, ['password']);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_reguser') || $rBypassAuth) {
					$rArray = QueryHelper::verifyPostTable('users', $rData);
					$rArray['date_registered'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			// The group is stored as the number it is checked as.
			$rArray['member_group_id'] = intval($rArray['member_group_id']);

			// An administrator's account is changed, and an administrator group
			// given out, by a full administrator (GroupService::reservedGroups).
			$rReserved = ($rBypassAuth ? [] : GroupService::reservedGroups());

			if (in_array($rArray['member_group_id'], $rReserved) || (isset($rUser) && in_array(intval($rUser['member_group_id']), $rReserved))) {
				return ['status' => STATUS_INVALID_GROUP, 'data' => $rData];
			}

			if (!empty($rData['member_group_id'])) {
				if (strlen($rData['username']) == 0) {
					$rArray['username'] = AdminHelpers::generateString(10);
				}

				if (!QueryHelper::checkExists('users', 'username', $rArray['username'], 'id', $rData['edit'] ?? null)) {
					if ((string) $rData['password'] !== '') {
						$rArray['password'] = Authenticator::hashPassword($rData['password']);
					}

					$rOverride = [];

					foreach ($rData as $rKey => $rCredits) {
						if (substr($rKey, 0, 9) == 'override_') {
							$rID = intval(explode('override_', $rKey)[1]);

							if ((string) $rCredits !== '') {
								$rCredits = intval($rCredits);
							} else {
								$rCredits = null;
							}

							if ($rCredits) {
								$rOverride[$rID] = ['assign' => 1, 'official_credits' => $rCredits];
							}
						}
					}

					if (!ctype_xdigit($rArray['api_key']) || strlen($rArray['api_key']) != 32) {
						$rArray['api_key'] = '';
					}

					$rArray['override_packages'] = json_encode($rOverride);
					$rReason = '';

					if (isset($rUser)) {
						// An edit moves the balance by what the form changed it by: the
						// posted balance against the one the form was opened with
						// (`credits_shown`; a caller that sends none means the stored one).
						// The balance itself is not written back: it may have moved since.
						if (is_numeric($rData['credits'] ?? null)) {
							// A caller shown no balance: one that sends back the balance as the
							// panel reads it (six significant digits) changes nothing; any other
							// figure is set against the balance as it is stored.
							$rShown = (is_numeric($rData['credits_shown'] ?? null) ? $rData['credits_shown'] : ($rData['credits'] == $rUser['credits'] ? $rData['credits'] : UserCredits::balance((int) $rUser['id'])));

							if ($rShown != $rData['credits']) {
								$rCreditsAdjustment = $rData['credits'] - $rShown;
								$rReason = $rData['credits_reason'] ?? '';
							}
						}

						$rPrepare = QueryHelper::prepareArray(array_diff_key($rArray, ['id' => 0, 'credits' => 0]));
						$rQuery = 'UPDATE `users` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
						$rPrepare['data'][] = $rUser['id'];
					} else {
						$rPrepare = QueryHelper::prepareArray($rArray);
						$rQuery = 'REPLACE INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
					}

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = (isset($rUser) ? $rUser['id'] : $db->last_insert_id());

						// The log holds the adjustments that were made.
						if (isset($rCreditsAdjustment) && UserCredits::credit($rInsertID, $rCreditsAdjustment)) {
							$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rInsertID, $GLOBALS['rAdminUserInfo']['id'], $rCreditsAdjustment, time(), $rReason);
						}

						return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
					}

					return ['status' => STATUS_FAILURE, 'data' => $rData];
				}
				return ['status' => STATUS_EXISTS_USERNAME, 'data' => $rData];
			}
			return ['status' => STATUS_INVALID_GROUP, 'data' => $rData];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Update the current admin's own profile.
	 *
	 * @param array $rData        Submitted profile fields.
	 * @param array $rUserInfo    Current admin user row.
	 * @param array $allowedLangs Allowed UI languages.
	 * @return array Result status payload.
	 */
	public static function editAdminProfile(array $rData, array $rUserInfo, array $allowedLangs) {
		$db = self::db();
		if ((string) $rData['email'] !== '' && !filter_var($rData['email'], FILTER_VALIDATE_EMAIL)) {
			return ['status' => STATUS_INVALID_EMAIL];
		}

		if ((string) $rData['password'] !== '') {
			$rPassword = Authenticator::hashPassword($rData['password']);
		} else {
			$rPassword = $rUserInfo['password'];
		}

		if (!isset($rData['api_key']) || (!ctype_xdigit($rData['api_key']) || strlen($rData['api_key']) != 32)) {
			$rData['api_key'] = '';
		}

		if (!in_array($rData['lang'], $allowedLangs)) {
			$rData['lang'] = 'en';
		}

		$db->query('UPDATE `users` SET `password` = ?, `email` = ?, `theme` = ?, `hue` = ?, `timezone` = ?, `api_key` = ?, `lang` = ? WHERE `id` = ?;', $rPassword, $rData['email'], $rData['theme'], $rData['hue'], $rData['timezone'], $rData['api_key'], $rData['lang'], $rUserInfo['id']);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Submit a support ticket as an admin user.
	 *
	 * @param array $rData     Ticket payload.
	 * @param array $rUserInfo Current admin user row.
	 * @return array Result status payload.
	 */
	public static function submitTicket(array $rData, array $rUserInfo) {
		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(TicketRepository::getById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('tickets', $rData);
			unset($rArray['id']);
		}

		if (strlen($rData['title']) == 0 && !isset($rData['respond']) || strlen($rData['message']) == 0) {
			return ['status' => STATUS_INVALID_DATA, 'data' => $rData];
		}

		if (!isset($rData['respond'])) {
			$rPrepare = QueryHelper::prepareArray($rArray);
			$rQuery = 'REPLACE INTO `tickets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rInsertID, $rData['message'], time());
				return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
			}

			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		$rTicket = TicketRepository::getById($rData['respond']);
		if (!$rTicket) {
			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		if (intval($rUserInfo['id']) == intval($rTicket['member_id'])) {
			$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 1 WHERE `id` = ?;', $rData['respond']);
			$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rData['respond'], $rData['message'], time());
		} else {
			$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 0 WHERE `id` = ?;', $rData['respond']);
			$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 1, ?, ?);', $rData['respond'], $rData['message'], time());
		}

		return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rData['respond']]];
	}

	/**
	 * Delete a registered user with configurable cascade behavior.
	 *
	 * @param int      $rID             User id.
	 * @param bool     $rDeleteSubUsers Also delete the user's sub-users.
	 * @param bool     $rDeleteLines    Also delete the user's lines.
	 * @param int|null $rReplaceWith    Reassign sub-users/lines to this owner instead.
	 * @return bool True on success.
	 */
	public static function deleteRegisteredUser(int $rID, bool $rDeleteSubUsers = false, bool $rDeleteLines = false, ?int $rReplaceWith = null) {
		$db = self::db();
		$rUser = UserRepository::getRegisteredUserById($rID);

		// An administrator's account is deleted by a full administrator.
		if (!$rUser || in_array(intval($rUser['member_group_id']), GroupService::reservedGroups())) {
			return false;
		}

		$db->query('DELETE FROM `users` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` = ?;', $rID);
		$db->query('DELETE FROM `users_logs` WHERE `owner` = ?;', $rID);
		$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` = ?);', $rID);
		$db->query('DELETE FROM `tickets` WHERE `member_id` = ?;', $rID);

		if ($rDeleteSubUsers) {
			$db->query('SELECT `id` FROM `users` WHERE `owner_id` = ?;', $rID);

			foreach ($db->get_rows() as $rRow) {
				self::deleteRegisteredUser($rRow['id'], $rDeleteSubUsers, $rDeleteLines, $rReplaceWith);
			}
		} else {
			$db->query('UPDATE `users` SET `owner_id` = ? WHERE `owner_id` = ?;', $rReplaceWith, $rID);
		}

		if ($rDeleteLines) {
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` = ?;', $rID);

			foreach ($db->get_rows() as $rRow) {
				LineService::deleteLineById($rRow['id']);
			}
		} else {
			$db->query('UPDATE `lines` SET `member_id` = ? WHERE `member_id` = ?;', $rReplaceWith, $rID);
		}

		return true;
	}

	/**
	 * Bulk delete registered users.
	 *
	 * @param int[] $rIDs User ids.
	 * @return bool True on success.
	 */
	public static function deleteRegisteredUsers(array $rIDs) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		// An administrator's account is deleted by a full administrator.
		if (0 < count($rIDs) && 0 < count($rReserved = GroupService::reservedGroups())) {
			$db->query('SELECT `id` FROM `users` WHERE `id` IN (' . implode(',', $rIDs) . ') AND `member_group_id` IN (' . implode(',', $rReserved) . ');');
			$rIDs = array_values(array_diff($rIDs, array_column($db->get_rows(), 'id')));
		}

		if (0 >= count($rIDs)) {
			return false;
		}

		$db->query('DELETE FROM `users` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `users_logs` WHERE `owner` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . '));');
		$db->query('DELETE FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `users` SET `owner_id` = NULL WHERE `owner_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines` SET `member_id` = NULL WHERE `member_id` IN (' . implode(',', $rIDs) . ');');

		return true;
	}
}
