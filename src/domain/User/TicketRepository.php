<?php

/**
 * TicketRepository — ticket repository
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TicketRepository {
	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `tickets` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return null;
		}

		$rRow = $db->get_row();
		$rRow['replies'] = array();
		$rRow['title'] = htmlspecialchars($rRow['title']);
		$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `date` ASC;', $rID);

		foreach ($db->get_rows() as $rReply) {
			$rReply['message'] = htmlspecialchars($rReply['message']);

			if (strlen($rReply['message']) >= 80) {
			} else {
				$rReply['message'] .= str_repeat('&nbsp; ', 80 - strlen($rReply['message']));
			}

			$rRow['replies'][] = $rReply;
		}
		$rRow['user'] = UserRepository::getRegisteredUserById($rRow['member_id']);
		return $rRow;
	}

	public static function getAll($rID = null, $rAdmin = false) {
		global $db;
		global $rUserInfo;
		global $rPermissions;
		$rReturn = array();

		if ($rID) {
			if ($rAdmin) {
				$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (SELECT `id` FROM `users` WHERE `owner_id` = ?) AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;', $rID);
			} else {
				$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (' . implode(',', array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']))) . ') AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
			}
		} else {
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
		}

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$db->query('SELECT MIN(`date`) AS `date` FROM `tickets_replies` WHERE `ticket_id` = ?;', $rRow['id']);

				if ($rDate = $db->get_row()['date']) {
					$rRow['created'] = date('Y-m-d H:i', $rDate);
				} else {
					$rRow['created'] = '';
				}

				$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1;', $rRow['id']);
				$rLastResponse = $db->get_row();
				$rRow['last_reply'] = date('Y-m-d H:i', $rLastResponse['date']);

				if ($rRow['member_id'] == $rID) {
					if ($rRow['status'] == 0) {
					} else {
						if ($rLastResponse['admin_reply']) {
							if ($rRow['user_read'] == 1) {
								$rRow['status'] = 3;
							} else {
								$rRow['status'] = 4;
							}
						} else {
							if ($rRow['admin_read'] == 1) {
								$rRow['status'] = 5;
							} else {
								$rRow['status'] = 2;
							}
						}
					}
				} else {
					if ($rRow['status'] == 0) {
					} else {
						if ($rLastResponse['admin_reply']) {
							if ($rRow['user_read'] == 1) {
								$rRow['status'] = 6;
							} else {
								$rRow['status'] = 2;
							}
						} else {
							if ($rRow['admin_read'] == 1) {
								$rRow['status'] = 5;
							} else {
								$rRow['status'] = 4;
							}
						}
					}
				}

				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function deleteById($rID) {
		global $db;
		$db->query('SELECT `id` FROM `tickets` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `tickets` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` = ?;', $rID);

		return true;
	}
}
