<?php

/**
 * LineService — line service
 *
 * @package XC_VM_Domain_Line
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rLines = json_decode($rData['lines'], true);
		LineRepository::deleteMany($rLines);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		global $db;
		if (InputValidator::validate('massEditLines', $rData)) {
			$rArray = array();

			foreach (array('is_stalker', 'is_isplock', 'is_restreamer', 'is_trial') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_admin_notes'])) {
			} else {
				$rArray['admin_notes'] = $rData['admin_notes'];
			}

			if (!isset($rData['c_reseller_notes'])) {
			} else {
				$rArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (!isset($rData['c_forced_country'])) {
			} else {
				$rArray['forced_country'] = $rData['forced_country'];
			}

			if (!isset($rData['c_member_id'])) {
			} else {
				$rArray['member_id'] = intval($rData['member_id']);
			}

			if (!isset($rData['c_force_server_id'])) {
			} else {
				$rArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (!isset($rData['c_max_connections'])) {
			} else {
				$rArray['max_connections'] = intval($rData['max_connections']);
			}

			if (!isset($rData['c_exp_date'])) {
			} else {
				if (isset($rData['no_expire'])) {
					$rArray['exp_date'] = null;
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
					}
				}
			}

			if (!isset($rData['c_access_output'])) {
			} else {
				$rOutputs = array();

				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
				$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';
			}

			if (!isset($rData['c_bouquets'])) {
			} else {
				$rArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (!is_numeric($rBouquet)) {
					} else {
						$rArray['bouquet'][] = $rBouquet;
					}
				}
				$rArray['bouquet'] = AdminHelpers::sortArrayByArray($rArray['bouquet'], array_keys(BouquetService::getOrder()));
				$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';
			}

			if (!isset($rData['reset_isp_lock'])) {
			} else {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = $rArray['isp_desc'];
			}

			$rUsers = AdminHelpers::confirmIDs(json_decode($rData['users_selected'], true));

			if (0 >= count($rUsers)) {
			} else {
				$rPrepare = QueryHelper::prepareArray($rArray);

				if (0 >= count($rPrepare['data'])) {
				} else {
					$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ');';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				$db->query('SELECT `pair_id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rUsers) . ');');

				foreach ($db->get_rows() as $rRow) {
					MagService::syncLineDevices($rRow['pair_id']);
				}
				LineService::updateLinesSignal($rUsers);
			}

			return array('status' => STATUS_SUCCESS);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processLine', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_user')) {
					$rArray = AdminHelpers::overwriteData(UserRepository::getLineById($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_user')) {
					$rArray = QueryHelper::verifyPostTable('lines', $rData);
					$rArray['created_at'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rData['username']) != 0) {
			} else {
				$rArray['username'] = AdminHelpers::generateString(10);
			}

			if (strlen($rData['password']) != 0) {
			} else {
				$rArray['password'] = AdminHelpers::generateString(10);
			}

			foreach (array('max_connections', 'enabled', 'admin_enabled') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = intval($rData[$rSelection]);
				} else {
					$rArray[$rSelection] = 1;
				}
			}

			foreach (array('is_stalker', 'is_restreamer', 'is_trial', 'is_isplock', 'bypass_ua') as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if (strlen($rData['isp_clear']) != 0) {
			} else {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = null;
			}

			$rArray['bouquet'] = AdminHelpers::sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
			$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (!(0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01')) {
				} else {
					try {
						$rDate = new DateTime($rData['exp_date']);
						$rArray['exp_date'] = $rDate->format('U');
					} catch (Exception $e) {
						return array('status' => STATUS_INVALID_DATE, 'data' => $rData);
					}
				}
			} else {
				$rArray['exp_date'] = null;
			}

			if ($rArray['member_id']) {
			} else {
				$rArray['member_id'] = $GLOBALS['rAdminUserInfo']['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (is_array($rData['allowed_ips'])) {
				} else {
					$rData['allowed_ips'] = array($rData['allowed_ips']);
				}

				$rArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rArray['allowed_ips'] = '[]';
			}

			if (isset($rData['allowed_ua'])) {
				if (is_array($rData['allowed_ua'])) {
				} else {
					$rData['allowed_ua'] = array($rData['allowed_ua']);
				}

				$rArray['allowed_ua'] = json_encode($rData['allowed_ua']);
			} else {
				$rArray['allowed_ua'] = '[]';
			}

			$rOutputs = array();

			if (!isset($rData['access_output'])) {
			} else {
				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
			}

			$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';

			if (!QueryHelper::checkExists('lines', 'username', $rArray['username'], 'id', $rData['edit'])) {
				$rPrepare = QueryHelper::prepareArray($rArray);

				$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					MagService::syncLineDevices($rInsertID);
					LineService::updateLineSignal($rInsertID);

					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_USERNAME, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function deleteLineSignal($rUserID, $rForce = false) {
		self::updateLineSignal($rUserID, $rForce);
	}

	public static function deleteLinesSignal($rUserIDs, $rForce = false) {
		self::updateLinesSignal($rUserIDs);
	}

	public static function updateLineSignal($rUserID, $rForce = false) {
		global $db;
		$rCached = SettingsManager::getAll()['enable_cache'];
		$rMainID = ConnectionTracker::getMainID();
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			}
			return true;
		}
		return false;
	}

	public static function updateLinesSignal($rUserIDs) {
		global $db;
		$rCached = SettingsManager::getAll()['enable_cache'];
		$rMainID = ConnectionTracker::getMainID();
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			}
			return true;
		}
		return false;
	}

	public static function deleteLineById($rID, $rDeletePaired = false, $rCloseCons = true) {
		global $db;
		$rLine = UserRepository::getLineById($rID);

		if (!$rLine) {
			return false;
		}

		self::deleteLineSignal($rID);
		$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `lines_logs` WHERE `user_id` = ?;', $rID);
		$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` = ?;', $rID);

		if (!$rCloseCons) {
		} else {
			if (SettingsManager::getAll()['redis_handler']) {
				foreach (ConnectionTracker::getRedisConnections($rID, null, null, true, false, false) as $rConnection) {
					ConnectionTracker::closeConnection($rConnection);
				}
			} else {
				$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rID);

				foreach ($db->get_rows() as $rRow) {
					ConnectionTracker::closeConnection($rRow);
				}
			}
		}

		$db->query('SELECT `id` FROM `lines` WHERE `pair_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			if ($rDeletePaired) {
				self::deleteLineById($rRow['id'], true, $rCloseCons);
			} else {
				$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = ?;', $rRow['id']);
				self::updateLineSignal($rRow['id']);
			}
		}

		return true;
	}

	public static function getExpiring($rLimit = 2419200) {
		global $db;
		global $rUserInfo;
		global $rPermissions;
		$rReturn = array();
		$rReports = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));

		if (0 >= count($rReports)) {
		} else {
			$db->query('SELECT `is_mag`, `is_e2`, `lines`.`id` AS `line_id`, `lines`.`reseller_notes`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` AS `e2_id`, `member_id`, `username`, `password`, `exp_date`, `mag_devices`.`mac` AS `mag_mac`, `enigma2_devices`.`mac` AS `e2_mac` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rReports) . ') AND `exp_date` IS NOT NULL AND `exp_date` >= ? AND `exp_date` < ? ORDER BY `exp_date` ASC LIMIT 250;', time(), time() + $rLimit);

			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function canGenerateTrials($rUserID) {
		global $db;
		global $rSettings;
		$rUser = UserRepository::getRegisteredUserById($rUserID);
		$rPermissions = AuthRepository::getPermissions($rUser['member_group_id']);

		if ($rSettings['disable_trial']) {
			return false;
		}

		if (floatval($rUser['credits']) < floatval($rPermissions['minimum_trial_credits'])) {
			return false;
		}

		$rTotal = $rPermissions['total_allowed_gen_trials'];

		if (0 >= $rTotal) {
			return false;
		}

		$rTotalIn = $rPermissions['total_allowed_gen_in'];

		if ($rTotalIn == 'hours') {
			$rTime = time() - intval($rTotal) * 3600;
		} else {
			$rTime = time() - intval($rTotal) * 3600 * 24;
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` = ? AND `created_at` >= ? AND `is_trial` = 1;', $rUser['id'], $rTime);

		return $db->get_row()['count'] < $rTotal;
	}
}
