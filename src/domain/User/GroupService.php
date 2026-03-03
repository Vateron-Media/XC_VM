<?php

class GroupService {
	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processGroup', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_group')) {
					$rArray = overwriteData(self::getById($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_group')) {
					$rArray = verifyPostTable('users_groups', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			foreach (array('is_admin', 'is_reseller', 'allow_restrictions', 'create_sub_resellers', 'delete_users', 'allow_download', 'can_view_vod', 'reseller_client_connection_logs', 'allow_change_bouquets', 'allow_change_username', 'allow_change_password') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if ($rArray['can_delete'] || !isset($rData['edit'])) {
			} else {
				$rGroup = self::getById($rData['edit']);
				$rArray['is_admin'] = $rGroup['is_admin'];
				$rArray['is_reseller'] = $rGroup['is_reseller'];
			}

			$rArray['allowed_pages'] = array_values(json_decode($rData['permissions_selected'], true));

			if (strlen($rData['group_name']) != 0) {
				$rArray['subresellers'] = '[' . implode(',', array_map('intval', json_decode($rData['groups_selected'], true))) . ']';
				$rArray['notice_html'] = htmlentities($rData['notice_html']);
				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					$rPackages = json_decode($rData['packages_selected'], true);

					foreach ($rPackages as $rPackage) {
						$db->query('SELECT `groups` FROM `users_packages` WHERE `id` = ?;', $rPackage);

						if ($db->num_rows() != 1) {
						} else {
							$rGroups = json_decode($db->get_row()['groups'], true);

							if (in_array($rInsertID, $rGroups)) {
							} else {
								$rGroups[] = $rInsertID;
								$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rPackage);
							}
						}
					}
					$db->query("SELECT `id`, `groups` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rInsertID);

					foreach ($db->get_rows() as $rRow) {
						if (in_array($rRow['id'], $rPackages)) {
						} else {
							$rGroups = json_decode($rRow['groups'], true);

							if (($rKey = array_search($rInsertID, $rGroups)) === false) {
							} else {
								unset($rGroups[$rKey]);
								$db->query('UPDATE `users_packages` SET `groups` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rGroups)) . ']', $rRow['id']);
							}
						}
					}

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				} else {
					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}
			} else {
				return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	// ──────────── Из GroupRepository ────────────

	public static function getAll() {
		global $db;
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

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function deleteById($rID) {
		global $db;
		$rGroup = self::getById($rID);

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
