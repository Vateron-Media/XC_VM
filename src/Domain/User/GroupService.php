<?php

namespace XcVm\Domain\User;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Validation\InputValidator;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * GroupService — group service
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class GroupService {
	use DatabaseAware;

	/**
	 * Create or update a user group from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processGroup', $rData)) {
			// A group is saved under its own key, the one it has or a new one:
			// the request does not name it.
			$rGroup = null;

			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_group')) {
					// An edit is of a group that exists, and starts from the group as
					// stored: the row cleaner's escaping would be written back.
					$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', intval($rData['edit']));
					if (!($rGroup = $db->get_raw_row())) {
						return ['status' => STATUS_INVALID_GROUP, 'data' => $rData];
					}

					$rGroup = UserCredits::amounts($rGroup);

					$rArray = AdminHelpers::overwriteData($rGroup, $rData, ['group_id']);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_group')) {
					$rArray = QueryHelper::verifyPostTable('users_groups', $rData);
					unset($rArray['group_id']);
				} else {
					exit();
				}
			}

			foreach (['is_admin', 'is_reseller', 'allow_restrictions', 'create_sub_resellers', 'delete_users', 'allow_download', 'can_view_vod', 'reseller_client_connection_logs', 'allow_change_bouquets', 'allow_change_username', 'allow_change_password'] as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if (!$rArray['can_delete'] && $rGroup) {
				$rArray['is_admin'] = $rGroup['is_admin'];
				$rArray['is_reseller'] = $rGroup['is_reseller'];
			}

			// An administrator group is changed, made or replaced by a full
			// administrator: for anyone else every one of them is reserved.
			$rReserved = self::reservedGroups();

			if (0 < count($rReserved) && ($rArray['is_admin'] || in_array(intval($rArray['group_id'] ?? 0), $rReserved))) {
				return ['status' => STATUS_INVALID_GROUP, 'data' => $rData];
			}

			$rArray['allowed_pages'] = array_values(json_decode($rData['permissions_selected'], true));

			if (strlen($rData['group_name']) != 0) {
				// A reserved group is not given out as a sub-reseller group either.
				$rArray['subresellers'] = '[' . implode(',', array_diff(array_map('intval', json_decode($rData['groups_selected'], true)), $rReserved)) . ']';
				$rArray['notice_html'] = htmlentities($rData['notice_html']);
				$rPrepare = QueryHelper::prepareArray($rArray);
				$rQuery = 'REPLACE INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					$rPackages = json_decode($rData['packages_selected'], true);

					foreach ($rPackages as $rPackage) {
						$db->query('SELECT `groups` FROM `users_packages` WHERE `id` = ?;', $rPackage);

						if ($db->num_rows() == 1) {
							$rGroups = json_decode($db->get_row()['groups'], true);
							if (!in_array($rInsertID, $rGroups)) {
								$rGroups[] = $rInsertID;
								$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rPackage);
							}
						}
					}
					$db->query("SELECT `id`, `groups` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rInsertID);

					foreach ($db->get_rows() as $rRow) {
						if (!in_array($rRow['id'], $rPackages)) {
							$rGroups = json_decode($rRow['groups'], true);
							if (($rKey = array_search($rInsertID, $rGroups)) !== false) {
								unset($rGroups[$rKey]);
								$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rRow['id']);
							}
						}
					}

					return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
				}
				return ['status' => STATUS_FAILURE, 'data' => $rData];
			}
			return ['status' => STATUS_INVALID_NAME, 'data' => $rData];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * The groups only a full administrator manages: the administrator groups.
	 *
	 * A full administrator is a member of the first group, or of an
	 * administrator group without a permission list (Authorization::check('adv')
	 * lets both do everything); for one, no group is reserved. Anyone else
	 * neither gives a user one of these groups nor changes their members. The
	 * groups as they are stored decide, not the permission list a request holds.
	 *
	 * @return int[] Group ids reserved from the acting user; empty for a full administrator.
	 */
	public static function reservedGroups() {
		$db = self::db();
		$rActorGroup = intval($GLOBALS['rUserInfo']['member_group_id'] ?? 0);

		if ($rActorGroup == 1) {
			return [];
		}

		$rReserved = [1];
		$db->query('SELECT `group_id`, `allowed_pages` FROM `users_groups` WHERE `is_admin` = 1;');

		foreach ($db->get_rows() as $rRow) {
			if ($rRow['group_id'] == $rActorGroup && !(json_decode((string) $rRow['allowed_pages'], true) ?: [])) {
				return [];
			}

			$rReserved[] = intval($rRow['group_id']);
		}

		return array_values(array_unique($rReserved));
	}

	// ──────────── Из GroupRepository ────────────

	/**
	 * Fetch all user groups.
	 *
	 * @return array Group rows.
	 */
	public static function getAll() {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `users_groups` ORDER BY `group_id` ASC;');

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['group_id'])] = UserCredits::amounts($rRow);
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a single user group by id.
	 *
	 * @param int $rID Group id.
	 * @return array|false The group row, or false if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return UserCredits::amounts($db->get_row());
		}
		return false;
	}

	/**
	 * Delete a user group.
	 *
	 * @param int $rID Group id.
	 * @return bool True on deletion, false if the group does not exist.
	 */
	public static function deleteById(int $rID) {
		$db = self::db();
		$rGroup = self::getById($rID);

		// An administrator group is deleted by a full administrator.
		if (!$rGroup || !$rGroup['can_delete'] || in_array($rID, self::reservedGroups())) {
			return false;
		}

		$db->query("SELECT `id`, `groups` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['groups'] = json_decode($rRow['groups'], true);

			if (($rKey = array_search($rID, $rRow['groups'])) !== false) {
				unset($rRow['groups'][$rKey]);
			}

			$groups = array_map('intval', $rRow['groups']);

			$db->query("UPDATE `users_packages` SET `groups` = '[" . implode(',', $groups) . "]' WHERE `id` = ?;", $rRow['id']);
		}
		$db->query('UPDATE `users` SET `member_group_id` = 0 WHERE `member_group_id` = ?;', $rID);
		$db->query('DELETE FROM `users_groups` WHERE `group_id` = ?;', $rID);

		return true;
	}
}
