<?php

class Authorization {
	public static function hasResellerPermissions($rPermissions, $rType) {
		if (!is_array($rPermissions)) {
			return false;
		}
		return !empty($rPermissions[$rType]);
	}

	public static function check($rType, $rID, $rUserInfo, $rPermissions, $db) {
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
