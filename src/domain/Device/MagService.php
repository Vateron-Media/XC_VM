<?php

class MagService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rMags = json_decode($rData['mags'], true);
		deleteMAGs($rMags);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		return API::massEditMagsLegacy($rData);
	}

	public static function process($rData) {
		return API::processMAGLegacy($rData);
	}
}
