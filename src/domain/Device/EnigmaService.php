<?php

class EnigmaService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rEnigmas = json_decode($rData['enigmas'], true);
		deleteEnigmas($rEnigmas);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		return API::massEditEnigmasLegacy($rData);
	}

	public static function process($rData) {
		return API::processEnigmaLegacy($rData);
	}
}
