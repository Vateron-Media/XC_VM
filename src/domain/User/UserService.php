<?php

class UserService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rUsers = json_decode($rData['users'], true);
		deleteUser($rUsers);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		return API::massEditUsersLegacy($rData);
	}

	public static function process($rData, $rBypassAuth = false) {
		return API::processUserLegacy($rData, $rBypassAuth);
	}

	// ──────────── Из ProfileService ────────────

	public static function editAdminProfile($rData, $rUserInfo, $allowedLangs) {
		global $db;
		if (!(0 >= strlen($rData['email']) || filter_var($rData['email'], FILTER_VALIDATE_EMAIL))) {
			return array('status' => STATUS_INVALID_EMAIL);
		}

		if (0 < strlen($rData['password'])) {
			$rPassword = cryptPassword($rData['password']);
		} else {
			$rPassword = $rUserInfo['password'];
		}

		if (!(ctype_xdigit($rData['api_key']) && strlen($rData['api_key']) == 32)) {
			$rData['api_key'] = '';
		}

		if (!in_array($rData['lang'], $allowedLangs)) {
			$rData['lang'] = 'en';
		}

		$db->query('UPDATE `users` SET `password` = ?, `email` = ?, `theme` = ?, `hue` = ?, `timezone` = ?, `api_key` = ?, `lang` = ? WHERE `id` = ?;', $rPassword, $rData['email'], $rData['theme'], $rData['hue'], $rData['timezone'], $rData['api_key'], $rData['lang'], $rUserInfo['id']);

		return array('status' => STATUS_SUCCESS);
	}

	// ──────────── Из TicketService ────────────

	public static function submitTicket($rData, $rUserInfo, $rGetTicketCallback) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($rGetTicketCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('tickets', $rData);
			unset($rArray['id']);
		}

		if (strlen($rData['title']) == 0 && !isset($rData['respond']) || strlen($rData['message']) == 0) {
			return array('status' => STATUS_INVALID_DATA, 'data' => $rData);
		}

		if (!isset($rData['respond'])) {
			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `tickets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				$db->query('INSERT INTO `tickets_replies`(`ticket_id`, `admin_reply`, `message`, `date`) VALUES(?, 0, ?, ?);', $rInsertID, $rData['message'], time());
				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			}

			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		$rTicket = call_user_func($rGetTicketCallback, $rData['respond']);
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
}
