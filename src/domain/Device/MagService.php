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

	// ──────────── Из DeviceSync ────────────

	public static function syncLineDevices($rUserID, $rDeviceID = null) {
		global $db;
		$rUser = UserRepository::getLineById($rUserID);

		if (!$rUser) {
		} else {
			unset($rUser['id']);

			if ($rDeviceID) {
				$db->query('SELECT * FROM `lines` WHERE `id` = (SELECT `user_id` FROM `mag_devices` WHERE `mag_id` = ?);', $rDeviceID);
			} else {
				$db->query('SELECT * FROM `lines` WHERE `pair_id` = ?;', $rUserID);
			}

			foreach ($db->get_rows() as $rDevice) {
				$rUpdateDevice = $rUser;
				$rUpdateDevice['pair_id'] = intval($rUserID);
				$rUpdateDevice['play_token'] = '';

				foreach (array('id', 'is_mag', 'is_e2', 'is_restreamer', 'max_connections', 'created_at', 'username', 'password', 'admin_notes', 'reseller_notes') as $rKey) {
					$rUpdateDevice[$rKey] = $rDevice[$rKey];
				}

				if (!isset($rUpdateDevice['id'])) {
				} else {
					$rPrepare = prepareArray($rUpdateDevice);
					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
					$db->query($rQuery, ...$rPrepare['data']);
					CoreUtilities::updateLine($rUpdateDevice['id']);
				}
			}
		}
	}
}
