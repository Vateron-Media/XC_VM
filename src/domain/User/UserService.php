<?php

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
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rUsers = json_decode($rData['users'], true);
		UserService::deleteRegisteredUser($rUsers);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		global $db;
		if (InputValidator::validate('massEditUsers', $rData)) {
			$rArray = array();

			foreach (array('status') as $rItem) {
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

			if (isset($rData['c_reseller_dns'])) {
				$rArray['reseller_dns'] = $rData['reseller_dns'];
			}

			if (isset($rData['c_override'])) {
				$rOverride = array();

				foreach ($rData as $rKey => $rCredits) {
					if (substr($rKey, 0, 9) == 'override_') {
						$rID = intval(explode('override_', $rKey)[1]);

						if (0 < strlen($rCredits)) {
							$rCredits = intval($rCredits);
						} else {
							$rCredits = null;
						}

						if ($rCredits) {
							$rOverride[$rID] = array('assign' => 1, 'official_credits' => $rCredits);
						}
					}
				}
				$rArray['override_packages'] = json_encode($rOverride);
			}

			$rUsers = AdminHelpers::confirmIDs(json_decode($rData['users_selected'], true));

			if (count($rUsers) > 0) {
				if (isset($rData['c_owner_id']) && $rUser == $rArray['owner_id']) {
					unset($rArray['owner_id']);
				}

				$rPrepare = QueryHelper::prepareArray($rArray);

				if (count($rPrepare['data']) > 0) {
					$rQuery = 'UPDATE `users` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ');';
					$db->query($rQuery, ...$rPrepare['data']);
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function process($rData, $rBypassAuth = false) {
		global $db;
		if (InputValidator::validate('processUser', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_reguser') || $rBypassAuth) {
					$rUser = UserRepository::getRegisteredUserById($rData['edit']);
					$rArray = AdminHelpers::overwriteData($rUser, $rData, array('password'));
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

			if (!empty($rData['member_group_id'])) {
				if (strlen($rData['username']) == 0) {
					$rArray['username'] = AdminHelpers::generateString(10);
				}

				if (!QueryHelper::checkExists('users', 'username', $rArray['username'], 'id', $rData['edit'] ?? null)) {
					if (strlen($rData['password']) > 0) {
						$rArray['password'] = Authenticator::hashPassword($rData['password']);
					}

					$rOverride = array();

					foreach ($rData as $rKey => $rCredits) {
						if (substr($rKey, 0, 9) == 'override_') {
							$rID = intval(explode('override_', $rKey)[1]);

							if (0 < strlen($rCredits)) {
								$rCredits = intval($rCredits);
							} else {
								$rCredits = null;
							}

							if ($rCredits) {
								$rOverride[$rID] = array('assign' => 1, 'official_credits' => $rCredits);
							}
						}
					}

					if (!ctype_xdigit($rArray['api_key']) || strlen($rArray['api_key']) != 32) {
						$rArray['api_key'] = '';
					}

					$rArray['override_packages'] = json_encode($rOverride);

					if (isset($rUser) && $rUser['credits'] != $rData['credits']) {
						$rCreditsAdjustment = $rData['credits'] - $rUser['credits'];
						$rReason = $rData['credits_reason'];
					}

					$rPrepare = QueryHelper::prepareArray($rArray);
					$rQuery = 'REPLACE INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();

						if (isset($rCreditsAdjustment)) {
							$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rInsertID, $GLOBALS['rAdminUserInfo']['id'], $rCreditsAdjustment, time(), $rReason);
						}

						return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				} else {
					return array('status' => STATUS_EXISTS_USERNAME, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_GROUP, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function editAdminProfile($rData, $rUserInfo, $allowedLangs) {
		global $db;
		if (!(0 >= strlen($rData['email']) || filter_var($rData['email'], FILTER_VALIDATE_EMAIL))) {
			return array('status' => STATUS_INVALID_EMAIL);
		}

		if (0 < strlen($rData['password'])) {
			$rPassword = Authenticator::hashPassword($rData['password']);
		} else {
			$rPassword = $rUserInfo['password'];
		}

		if (!isset($rData['api_key']) || !(ctype_xdigit($rData['api_key']) && strlen($rData['api_key']) == 32)) {
			$rData['api_key'] = '';
		}

		if (!in_array($rData['lang'], $allowedLangs)) {
			$rData['lang'] = 'en';
		}

		$db->query('UPDATE `users` SET `password` = ?, `email` = ?, `theme` = ?, `hue` = ?, `timezone` = ?, `api_key` = ?, `lang` = ? WHERE `id` = ?;', $rPassword, $rData['email'], $rData['theme'], $rData['hue'], $rData['timezone'], $rData['api_key'], $rData['lang'], $rUserInfo['id']);

		return array('status' => STATUS_SUCCESS);
	}

	public static function submitTicket($rData, $rUserInfo) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(TicketRepository::getById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('tickets', $rData);
			unset($rArray['id']);
		}

		if (strlen($rData['title']) == 0 && !isset($rData['respond']) || strlen($rData['message']) == 0) {
			return array('status' => STATUS_INVALID_DATA, 'data' => $rData);
		}

		if (!isset($rData['respond'])) {
			$rPrepare = QueryHelper::prepareArray($rArray);
			$rQuery = 'REPLACE INTO `tickets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rInsertID, $rData['message'], time());
				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rTicket = TicketRepository::getById($rData['respond']);
		if (!$rTicket) {
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		if (intval($rUserInfo['id']) == intval($rTicket['member_id'])) {
			$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 1 WHERE `id` = ?;', $rData['respond']);
			$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rData['respond'], $rData['message'], time());
		} else {
			$db->query('UPDATE `tickets` SET `admin_read` = 0, `user_read` = 0 WHERE `id` = ?;', $rData['respond']);
			$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 1, ?, ?);', $rData['respond'], $rData['message'], time());
		}

		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rData['respond']));
	}

	public static function deleteRegisteredUser($rID, $rDeleteSubUsers = false, $rDeleteLines = false, $rReplaceWith = null) {
		global $db;
		$rUser = UserRepository::getRegisteredUserById($rID);

		if (!$rUser) {
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

	public static function deleteRegisteredUsers($rIDs) {
		global $db;
		$rIDs = AdminHelpers::confirmIDs($rIDs);

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
