<?php

class ProfileService {
	public static function editAdminProfile($db, $rData, $rUserInfo, $allowedLangs) {
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
}
