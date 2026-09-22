<?php

namespace XcVm\Domain\User;

use XcVm\Infrastructure\Database\DatabaseAware;

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
	use DatabaseAware;

	/**
	 * Fetch a single ticket (with its messages) by id.
	 *
	 * @param int $rID Ticket id.
	 * @return array|false The ticket row, or false if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `tickets` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$rRow = $db->get_row();
		$rRow['replies'] = [];
		$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `date` ASC;', $rID);

		foreach ($db->get_rows() as $rReply) {
			$rRow['replies'][] = $rReply;
		}
		$rRow['user'] = UserRepository::getRegisteredUserById((int) $rRow['member_id']) ?: ['username' => 'Unknown'];
		return $rRow;
	}

	/**
	 * List tickets, optionally scoped to a user or to the admin view.
	 *
	 * @param int|null $rID    Owner user id, or null for all.
	 * @param bool     $rAdmin Use admin scope (all tickets).
	 * @return array Ticket rows.
	 */
	public static function getAll(?int $rID = null, bool $rAdmin = false) {
		$db = self::db();
		global $rUserInfo;
		global $rPermissions;
		$rReturn = [];

		if ($rAdmin || empty($rID) || (!empty($rUserInfo['member_group_id']) && $rUserInfo['member_group_id'] == 1)) {
			// Administrator view: show all tickets across the system
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, COALESCE(`users`.`username`, "Unknown") AS `username` FROM `tickets` LEFT JOIN `users` ON `users`.`id` = `tickets`.`member_id` ORDER BY `tickets`.`id` DESC;');
		} else {
			// Reseller / scoped view: show tickets for self and direct reports
			$rUserIDs = array_map('intval', array_merge([$rID], $rPermissions['all_reports'] ?? []));
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, COALESCE(`users`.`username`, "Unknown") AS `username` FROM `tickets` LEFT JOIN `users` ON `users`.`id` = `tickets`.`member_id` WHERE `tickets`.`member_id` IN (' . implode(',', $rUserIDs) . ') ORDER BY `tickets`.`id` DESC;');
		}

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$db->query('SELECT MIN(`date`) AS `date` FROM `tickets_replies` WHERE `ticket_id` = ?;', $rRow['id']);

				if ($rDate = $db->get_row()['date']) {
					$rRow['created'] = date('Y-m-d H:i', $rDate);
				} else {
					$rRow['created'] = '';
				}

				$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1;', $rRow['id']);
				$rLastResponse = $db->get_row() ?: [];
				$rRow['last_reply'] = !empty($rLastResponse['date']) ? date('Y-m-d H:i', (int) $rLastResponse['date']) : $rRow['created'];

				if ($rRow['member_id'] == $rID) {
					if ($rRow['status'] != 0) {
						if (!empty($rLastResponse['admin_reply'])) {
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
					if ($rRow['status'] != 0) {
						if (!empty($rLastResponse['admin_reply'])) {
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

	/**
	 * Delete a ticket and its messages.
	 *
	 * @param int $rID Ticket id.
	 * @return bool True on deletion, false if not found.
	 */
	public static function deleteById(int $rID) {
		$db = self::db();
		$db->query('SELECT `id` FROM `tickets` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `tickets` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` = ?;', $rID);

		return true;
	}
}
