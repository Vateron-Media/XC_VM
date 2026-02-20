<?php

class Authenticator {
	public static function login($db, $rSettings, $rData, $rBypassRecaptcha = false) {
		if (!empty($rSettings['recaptcha_enable']) && !$rBypassRecaptcha) {
			$rResponse = json_decode(file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . $rSettings['recaptcha_v2_secret_key'] . '&response=' . $rData['g-recaptcha-response']), true);
			if (!$rResponse['success']) {
				return array('status' => STATUS_INVALID_CAPTCHA);
			}
		}

		$rIP = getIP();
		$rUserInfo = getUserInfo($rData['username'], $rData['password']);
		$rAccessCode = getCurrentCode(true);

		if (!isset($rUserInfo)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, 0, ?, ?, ?);", $rAccessCode['id'], 'INVALID_LOGIN', $rIP, time());
			}
			return array('status' => STATUS_FAILURE);
		}

		$db->query('SELECT COUNT(*) AS `count` FROM `access_codes`;');
		$rCodeCount = $db->get_row()['count'];

		if (!($rCodeCount == 0 || in_array($rUserInfo['member_group_id'], json_decode($rAccessCode['groups'], true)))) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'INVALID_CODE', $rIP, time());
			}
			return array('status' => STATUS_INVALID_CODE);
		}

		$rPermissions = getPermissions($rUserInfo['member_group_id']);
		if (!$rPermissions['is_admin']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'NOT_ADMIN', $rIP, time());
			}
			return array('status' => STATUS_NOT_ADMIN);
		}

		if ($rUserInfo['status'] == 1) {
			$rCrypt = cryptPassword($rData['password']);
			if ($rUserInfo['password'] != $rCrypt) {
				$db->query('UPDATE `users` SET `password` = ?, `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rCrypt, $rIP, $rUserInfo['id']);
			} else {
				$db->query('UPDATE `users` SET `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rIP, $rUserInfo['id']);
			}

			$_SESSION['hash'] = $rUserInfo['id'];
			$_SESSION['ip'] = $rIP;
			$_SESSION['code'] = getCurrentCode();
			$_SESSION['verify'] = md5($rUserInfo['username'] . '||' . $rCrypt);

			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'SUCCESS', $rIP, time());
			}
			return array('status' => STATUS_SUCCESS);
		}

		if ($rPermissions && ($rPermissions['is_admin'] || $rPermissions['is_reseller']) && !$rUserInfo['status']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'DISABLED', $rIP, time());
			}
			return array('status' => STATUS_DISABLED);
		}

		return array('status' => STATUS_FAILURE);
	}

	public static function resellerLogin($db, $rSettings, $rData) {
		if (!empty($rSettings['recaptcha_enable'])) {
			$rResponse = json_decode(file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . $rSettings['recaptcha_v2_secret_key'] . '&response=' . $rData['g-recaptcha-response']), true);
			if (!$rResponse['success']) {
				return array('status' => STATUS_INVALID_CAPTCHA);
			}
		}

		$rIP = getIP();
		$rUserInfo = getUserInfo($rData['username'], $rData['password']);
		$rAccessCode = getCurrentCode(true);

		if (!isset($rUserInfo)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, 0, ?, ?, ?);", $rAccessCode['id'], 'INVALID_LOGIN', $rIP, time());
			}
			return array('status' => STATUS_FAILURE);
		}

		if (!(in_array($rUserInfo['member_group_id'], json_decode($rAccessCode['groups'], true)) || count(getActiveCodes()) == 0)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'INVALID_CODE', $rIP, time());
			}
			return array('status' => STATUS_INVALID_CODE);
		}

		$rPermissions = getPermissions($rUserInfo['member_group_id']);
		if (!$rPermissions['is_reseller']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'NOT_ADMIN', $rIP, time());
			}
			return array('status' => STATUS_NOT_RESELLER);
		}

		if ($rUserInfo['status'] == 1) {
			$rCrypt = cryptPassword($rData['password']);
			if ($rUserInfo['password'] != $rCrypt) {
				$db->query('UPDATE `users` SET `password` = ?, `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rCrypt, $rIP, $rUserInfo['id']);
			} else {
				$db->query('UPDATE `users` SET `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rIP, $rUserInfo['id']);
			}

			$_SESSION['reseller'] = $rUserInfo['id'];
			$_SESSION['rip'] = $rIP;
			$_SESSION['rcode'] = getCurrentCode();
			$_SESSION['rverify'] = md5($rUserInfo['username'] . '||' . $rCrypt);

			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'SUCCESS', $rIP, time());
			}
			return array('status' => STATUS_SUCCESS);
		}

		if ($rPermissions && ($rPermissions['is_admin'] || $rPermissions['is_reseller']) && !$rUserInfo['status']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'DISABLED', $rIP, time());
			}
			return array('status' => STATUS_DISABLED);
		}

		return array('status' => STATUS_FAILURE);
	}
}
