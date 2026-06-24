<?php

declare(strict_types=1);
namespace XcVm\Core\Auth;


/**
 * Authorization — authorization
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Authorization {
	/**
	 * Check whether the current reseller holds a given permission flag.
	 *
	 * @param string $rType Permission key in the global $rPermissions map.
	 * @return bool True if the flag is present and truthy.
	 */
	public static function hasResellerPermissions(string $rType): bool {
		global $rPermissions;
		if (!is_array($rPermissions)) {
			return false;
		}
		return !empty($rPermissions[$rType]);
	}

	/**
	 * Authorize the current user's access to a specific entity.
	 *
	 * Scopes the check to the user's own id plus their report tree (for `user`
	 * and `line`), or to advanced permissions (`adv`) for admins.
	 *
	 * @param string     $rType Entity type: 'user', 'line' or 'adv'.
	 * @param string|int $rID   Target entity id.
	 * @return bool True if the current user may access the entity.
	 */
	public static function check(string $rType, string|int $rID): bool {
		global $rUserInfo;
		global $db;
		global $rPermissions;
		if (!(isset($rUserInfo) && isset($rPermissions) && is_array($rPermissions) && $db)) {
			return false;
		}

		if ($rType == 'user') {
			$rReports = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));

			if (0 < count($rReports)) {
				$db->query('SELECT `id` FROM `users` WHERE `id` = ? AND (`owner_id` IN (' . implode(',', $rReports) . ') OR `id` = ?);', $rID, $rUserInfo['id']);
				return 0 < $db->num_rows();
			}

			return false;
		}

		if ($rType == 'line') {
			$rReports = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));
			if (0 < count($rReports)) {
				$db->query('SELECT `id` FROM `lines` WHERE `id` = ? AND `member_id` IN (' . implode(',', $rReports) . ');', $rID);
				return 0 < $db->num_rows();
			}
			return false;
		}

		if (!($rType == 'adv' && $rPermissions['is_admin'])) {
			return false;
		}

		if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
			return in_array($rID, ($rPermissions['advanced'] ?: array()));
		}

		return true;
	}
}
