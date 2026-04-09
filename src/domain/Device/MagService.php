<?php

/**
 * MagService — mag service
 *
 * @package XC_VM_Domain_Device
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rMags = json_decode($rData['mags'], true);
		self::deleteDevices($rMags);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		global $db;
		if (InputValidator::validate('massEditMags', $rData)) {
			$rArray = array();
			$rUserArray = array();

			foreach (array('lock_device') as $rItem) {
				if (isset($rData['c_' . $rItem])) {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			foreach (array('is_isplock', 'is_trial') as $rItem) {
				if (isset($rData['c_' . $rItem])) {
					if (isset($rData[$rItem])) {
						$rUserArray[$rItem] = 1;
					} else {
						$rUserArray[$rItem] = 0;
					}
				}
			}

			if (isset($rData['c_modern_theme'])) {
				if (isset($rData['modern_theme'])) {
					$rArray['theme_type'] = 0;
				} else {
					$rArray['theme_type'] = 1;
				}
			}

			if (isset($rData['c_parent_password'])) {
				$rArray['parent_password'] = $rData['parent_password'];
			}

			if (isset($rData['c_admin_notes'])) {
				$rUserArray['admin_notes'] = $rData['admin_notes'];
			}

			if (isset($rData['c_reseller_notes'])) {
				$rUserArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (isset($rData['c_forced_country'])) {
				$rUserArray['forced_country'] = $rData['forced_country'];
			}

			if (isset($rData['c_member_id'])) {
				$rUserArray['member_id'] = intval($rData['member_id']);
			}

			if (isset($rData['c_force_server_id'])) {
				$rUserArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (isset($rData['c_exp_date'])) {
				if (isset($rData['no_expire'])) {
					$rUserArray['exp_date'] = null;
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
					}
				}
			}

			if (isset($rData['c_bouquets'])) {
				$rUserArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (is_numeric($rBouquet)) {
						$rUserArray['bouquet'][] = $rBouquet;
					}
				}
				$rUserArray['bouquet'] = AdminHelpers::sortArrayByArray($rUserArray['bouquet'], array_keys(BouquetService::getOrder()));
				$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';
			}

			if (isset($rData['reset_isp_lock'])) {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = $rUserArray['isp_desc'];
			}

			if (isset($rData['reset_device_lock'])) {
				$rArray['ver'] = '';
				$rArray['device_id2'] = $rArray['ver'];
				$rArray['device_id'] = $rArray['device_id2'];
				$rArray['hw_version'] = $rArray['device_id'];
				$rArray['image_version'] = $rArray['hw_version'];
				$rArray['stb_type'] = $rArray['image_version'];
				$rArray['sn'] = $rArray['stb_type'];
			}

			if (!empty($rData['message_type'])) {
				$rEvent = array('event' => $rData['message_type'], 'need_confirm' => 0, 'msg' => '', 'reboot_after_ok' => intval(isset($rData['reboot_portal'])));

				if ($rData['message_type'] == 'send_msg') {
					$rEvent['need_confirm'] = 1;
					$rEvent['msg'] = $rData['message'];
				} else {
					if ($rData['message_type'] == 'play_channel') {
						$rEvent['msg'] = intval($rData['selected_channel']);
						$rEvent['reboot_after_ok'] = 0;
					} else {
						$rEvent['need_confirm'] = 0;
						$rEvent['reboot_after_ok'] = 0;
					}
				}
			}

			$rDevices = json_decode($rData['devices_selected'], true);

			foreach ($rDevices as $rDevice) {
				$rDeviceInfo = MagService::getById($rDevice);

				if ($rDeviceInfo) {
					if (!empty($rData['message_type'])) {
						$db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rDevice, $rEvent['event'], $rEvent['need_confirm'], $rEvent['msg'], $rEvent['reboot_after_ok'], time());
					}

					if (count($rArray) > 0) {
						$rPrepare = QueryHelper::prepareArray($rArray);

						if (count($rPrepare['data']) > 0) {
							$rPrepare['data'][] = $rDevice;
							$rQuery = 'UPDATE `mag_devices` SET ' . $rPrepare['update'] . ' WHERE `mag_id` = ?;';
							$db->query($rQuery, ...$rPrepare['data']);
						}
					}

					if (count($rUserArray) > 0) {
						$rUserIDs = array();

						if (isset($rDeviceInfo['user']['id'])) {
							$rUserIDs[] = $rDeviceInfo['user']['id'];
						}

						if (isset($rDeviceInfo['user']['paired'])) {
							$rUserIDs[] = $rDeviceInfo['paired']['id'];
						}

						foreach ($rUserIDs as $rUserID) {
							$rPrepare = QueryHelper::prepareArray($rUserArray);

							if (count($rPrepare['data']) > 0) {
								$rPrepare['data'][] = $rUserID;
								$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
								$db->query($rQuery, ...$rPrepare['data']);
								LineService::updateLineSignal($rUserID);
							}
						}
					}
				}
			}

			return array('status' => STATUS_SUCCESS);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processMAG', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_mag')) {
					$rArray = AdminHelpers::overwriteData(MagService::getById($rData['edit']), $rData);
					$rUser = UserRepository::getLineById($rArray['user_id']);

					if ($rUser) {
						$rUserArray = AdminHelpers::overwriteData($rUser, $rData);
					} else {
						$rUserArray = QueryHelper::verifyPostTable('lines', $rData);
						$rUserArray['created_at'] = time();
						unset($rUserArray['id']);
					}
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_mag')) {
					$rArray = QueryHelper::verifyPostTable('mag_devices', $rData);
					$rArray['theme_type'] = SettingsManager::getAll()['mag_default_type'];
					$rUserArray = QueryHelper::verifyPostTable('lines', $rData);
					$rUserArray['created_at'] = time();
					unset($rArray['mag_id'], $rUserArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rUserArray['username']) == 0) {
				$rUserArray['username'] = AdminHelpers::generateString(32);
			}

			if (strlen($rUserArray['password']) == 0) {
				$rUserArray['password'] = AdminHelpers::generateString(32);
			}

			if (strlen($rData['isp_clear']) == 0) {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = null;
			}

			$rUserArray['is_mag'] = 1;
			$rUserArray['is_e2'] = 0;
			$rUserArray['max_connections'] = 1;
			$rUserArray['is_restreamer'] = 0;

			if (isset($rData['is_trial'])) {
				$rUserArray['is_trial'] = 1;
			} else {
				$rUserArray['is_trial'] = 0;
			}

			if (isset($rData['is_isplock'])) {
				$rUserArray['is_isplock'] = 1;
			} else {
				$rUserArray['is_isplock'] = 0;
			}

			if (isset($rData['lock_device'])) {
				$rArray['lock_device'] = 1;
			} else {
				$rArray['lock_device'] = 0;
			}

			$rUserArray['bouquet'] = AdminHelpers::sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
			$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01') {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
						return array('status' => STATUS_INVALID_DATE, 'data' => $rData);
					}
				}
			} else {
				$rUserArray['exp_date'] = null;
			}

			if (!$rUserArray['member_id']) {
				$rUserArray['member_id'] = $GLOBALS['rAdminUserInfo']['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (!is_array($rData['allowed_ips'])) {
					$rData['allowed_ips'] = array($rData['allowed_ips']);
				}

				$rUserArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rUserArray['allowed_ips'] = '[]';
			}

			if (isset($rData['pair_id'])) {
				$rUserArray['pair_id'] = intval($rData['pair_id']);
			} else {
				$rUserArray['pair_id'] = null;
			}

			$rUserArray['allowed_outputs'] = '[' . implode(',', array(1, 2)) . ']';
			$rDevice = $rArray;
			$rDevice['user'] = $rUserArray;

			if ($rDevice['user']['pair_id'] > 0) {
				$rUserCheck = UserRepository::getLineById($rDevice['user']['pair_id']);

				if (!$rUserCheck) {
					return array('status' => STATUS_INVALID_USER, 'data' => $rData);
				}
			}

			if (filter_var($rData['mac'], FILTER_VALIDATE_MAC)) {
				if (isset($rData['edit'])) {
					$db->query('SELECT `mag_id` FROM `mag_devices` WHERE mac = ? AND `mag_id` <> ? LIMIT 1;', $rArray['mac'], $rData['edit']);
				} else {
					$db->query('SELECT `mag_id` FROM `mag_devices` WHERE mac = ? LIMIT 1;', $rArray['mac']);
				}

				if (0 >= $db->num_rows()) {
					$rPrepare = QueryHelper::prepareArray($rUserArray);

					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rArray['user_id'] = $rInsertID;
						LineService::updateLineSignal($rArray['user_id']);
						if (!isset($rData['edit'])) {
							$rArray['ver'] = '';
							$rArray['device_id2'] = $rArray['ver'];
							$rArray['device_id'] = $rArray['device_id2'];
							$rArray['hw_version'] = $rArray['device_id'];
							$rArray['stb_type'] = $rArray['hw_version'];
							$rArray['image_version'] = $rArray['stb_type'];
							$rArray['sn'] = $rArray['image_version'];
						}

						$rPrepare = QueryHelper::prepareArray($rArray);
						$rQuery = 'REPLACE INTO `mag_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = $db->last_insert_id();

							if ($rDevice['user']['pair_id'] > 0) {
								MagService::syncLineDevices($rDevice['user']['pair_id'], $rInsertID);
								LineService::updateLineSignal($rDevice['user']['pair_id']);
							}

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						if (!isset($rData['edit'])) {
							$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rInsertID);
						}
					}

					return array('status' => STATUS_FAILURE, 'data' => $rData);
				}

				return array('status' => STATUS_EXISTS_MAC, 'data' => $rData);
			}

			return array('status' => STATUS_INVALID_MAC, 'data' => $rData);
		}

		return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
	}

	public static function syncLineDevices($rUserID, $rDeviceID = null) {
		global $db;
		$rUser = UserRepository::getLineById($rUserID);

		if ($rUser) {
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

				if (isset($rUpdateDevice['id'])) {
					$rPrepare = QueryHelper::prepareArray($rUpdateDevice);
					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
					$db->query($rQuery, ...$rPrepare['data']);
					LineService::updateLineSignal($rUpdateDevice['id']);
				}
			}
		}
	}

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return array();
		}

		$rRow = $db->get_row();
		$rRow['user'] = UserRepository::getLineById($rRow['user_id']);
		$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

		if ($db->num_rows() != 1) {
		} else {
			$rRow['paired'] = UserRepository::getLineById($rRow['user']['pair_id']);
		}

		return $rRow;
	}

	public static function deleteDevice($rID, $rDeletePaired = false, $rCloseCons = true, $rConvert = false) {
		global $db;
		$rMag = self::getById($rID);

		if (!$rMag) {
			return false;
		}

		$db->query('DELETE FROM `mag_devices` WHERE `mag_id` = ?;', $rID);
		$db->query('DELETE FROM `mag_claims` WHERE `mag_id` = ?;', $rID);
		$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` = ?;', $rID);
		$db->query('DELETE FROM `mag_logs` WHERE `mag_id` = ?;', $rID);

		if (!$rMag['user']) {
		} else {
			if ($rConvert) {
				$db->query('UPDATE `lines` SET `is_mag` = 0 WHERE `id` = ?;', $rMag['user']['id']);
				LineService::updateLineSignal($rMag['user']['id']);
			} else {
				$rCount = 0;
				$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
				$rCount += $db->num_rows();
				$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
				$rCount += $db->num_rows();

				if ($rCount != 0) {
				} else {
					LineService::deleteLineById($rMag['user']['id'], $rDeletePaired, $rCloseCons);
				}
			}
		}

		return true;
	}

	public static function deleteDevices($rIDs) {
		global $db;
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		$rUserIDs = array();
		$db->query('SELECT `user_id` FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rUserIDs[] = $rRow['user_id'];
		}
		$db->query('DELETE FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_claims` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_logs` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

		if (0 >= count($rUserIDs)) {
		} else {
			LineRepository::deleteMany($rUserIDs);
		}

		return true;
	}

	public static function resetSTB($rID) {
		global $db;
		$db->query("UPDATE `mag_devices` SET `ip` = '', `ver` = '', `image_version` = '', `stb_type` = '', `sn` = '', `device_id` = '', `device_id2` = '', `hw_version` = '', `token` = '' WHERE `mag_id` = ?;", $rID);
	}
}
