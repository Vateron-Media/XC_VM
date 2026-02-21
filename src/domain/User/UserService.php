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
}
