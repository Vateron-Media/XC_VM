<?php

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
	 * @param string          $rType Entity type: 'user', 'line' or 'adv'.
	 * @param string|int|null $rID   Target entity id (null for ungated actions, e.g. CSV export).
	 * @return bool True if the current user may access the entity.
	 */
	public static function check(string $rType, string|int|null $rID): bool {
		global $rUserInfo;
		global $db;
		global $rPermissions;
		if (!(isset($rUserInfo) && isset($rPermissions) && is_array($rPermissions) && $db)) {
			return false;
		}

		if ($rType == 'user') {
			$rReports = array_map('intval', array_merge([$rUserInfo['id']], $rPermissions['all_reports']));

			if (0 < count($rReports)) {
				$db->query('SELECT `id` FROM `users` WHERE `id` = ? AND (`owner_id` IN (' . implode(',', $rReports) . ') OR `id` = ?);', $rID, $rUserInfo['id']);
				return 0 < $db->num_rows();
			}

			return false;
		}

		if ($rType == 'line') {
			$rReports = array_map('intval', array_merge([$rUserInfo['id']], $rPermissions['all_reports']));
			if (0 < count($rReports)) {
				$db->query('SELECT `id` FROM `lines` WHERE `id` = ? AND `member_id` IN (' . implode(',', $rReports) . ');', $rID);
				return 0 < $db->num_rows();
			}
			return false;
		}

		if ($rType != 'adv' || !$rPermissions['is_admin']) {
			return false;
		}

		return self::isFullAdmin() || in_array($rID, ($rPermissions['advanced'] ?: []));
	}

	/**
	 * An admin with no advanced-permission list, or of the Administrators group:
	 * every `adv` check passes.
	 */
	public static function isFullAdmin(): bool {
		global $rUserInfo;
		global $rPermissions;
		return !empty($rPermissions['is_admin']) && (empty($rPermissions['advanced']) || ($rUserInfo['member_group_id'] ?? null) == 1);
	}

	/**
	 * custom_ffmpeg, custom_map and a stream's own transcode attributes hand
	 * ffmpeg arguments of the writer's choosing, and an output among them writes
	 * any file xc_vm can (GHSA-q9p6-374v-3jrj). Only a full admin sets them:
	 * anyone else's value is dropped, and an edit keeps the stored one.
	 *
	 * @param array $rData Submitted stream form data.
	 * @return array
	 */
	public static function dropFfmpegArguments(array $rData): array {
		if (!self::isFullAdmin()) {
			unset($rData['custom_ffmpeg'], $rData['custom_map'], $rData['transcode_attributes']);
		}
		return $rData;
	}
}
