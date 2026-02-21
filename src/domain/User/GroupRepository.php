<?php

class GroupRepository {
	public static function getAll($db) {
		$rReturn = array();
		$db->query('SELECT * FROM `users_groups` ORDER BY `group_id` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['group_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getById($db, $rID) {
		$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function deleteById($db, $rID) {
		$rGroup = getMemberGroup($rID);

		if (!($rGroup && $rGroup['can_delete'])) {
			return false;
		}

		$db->query("SELECT `id`, `groups` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['groups'] = json_decode($rRow['groups'], true);

			if ($rKey = array_search($rID, $rRow['groups']) !== false) {
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
