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
		global $db;
		if (InputValidator::validate('massEditEnigmas', $rData)) {
			$rArray = array();
			$rUserArray = array();

			foreach (array('is_isplock', 'is_trial') as $rItem) {
				if (!isset($rData['c_' . $rItem])) {
				} else {
					if (isset($rData[$rItem])) {
						$rUserArray[$rItem] = 1;
					} else {
						$rUserArray[$rItem] = 0;
					}
				}
			}

			if (!isset($rData['c_admin_notes'])) {
			} else {
				$rUserArray['admin_notes'] = $rData['admin_notes'];
			}

			if (!isset($rData['c_reseller_notes'])) {
			} else {
				$rUserArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (!isset($rData['c_forced_country'])) {
			} else {
				$rUserArray['forced_country'] = $rData['forced_country'];
			}

			if (!isset($rData['c_member_id'])) {
			} else {
				$rUserArray['member_id'] = intval($rData['member_id']);
			}

			if (!isset($rData['c_force_server_id'])) {
			} else {
				$rUserArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (!isset($rData['c_exp_date'])) {
			} else {
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

			if (!isset($rData['c_bouquets'])) {
			} else {
				$rUserArray['bouquet'] = array();

				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (!is_numeric($rBouquet)) {
					} else {
						$rUserArray['bouquet'][] = $rBouquet;
					}
				}
				$rUserArray['bouquet'] = sortArrayByArray($rUserArray['bouquet'], array_keys(BouquetService::getOrder()));
				$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';
			}

			if (!isset($rData['reset_isp_lock'])) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = $rUserArray['isp_desc'];
			}

			if (!isset($rData['reset_device_lock'])) {
			} else {
				$rArray['token'] = '';
				$rArray['lversion'] = $rArray['token'];
				$rArray['cpu'] = $rArray['lversion'];
				$rArray['enigma_version'] = $rArray['cpu'];
				$rArray['modem_mac'] = $rArray['enigma_version'];
				$rArray['local_ip'] = $rArray['modem_mac'];
			}

			$rDevices = json_decode($rData['devices_selected'], true);

			foreach ($rDevices as $rDevice) {
				$rDeviceInfo = getEnigma($rDevice);

				if (!$rDeviceInfo) {
				} else {
					if (0 >= count($rArray)) {
					} else {
						$rPrepare = prepareArray($rArray);

						if (0 >= count($rPrepare['data'])) {
						} else {
							$rPrepare['data'][] = $rDevice;
							$rQuery = 'UPDATE `enigma2_devices` SET ' . $rPrepare['update'] . ' WHERE `device_id` = ?;';
							$db->query($rQuery, ...$rPrepare['data']);
						}
					}

					if (0 >= count($rUserArray)) {
					} else {
						$rUserIDs = array();

						if (!isset($rDeviceInfo['user']['id'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['user']['id'];
						}

						if (!isset($rDeviceInfo['user']['paired'])) {
						} else {
							$rUserIDs[] = $rDeviceInfo['paired']['id'];
						}

						foreach ($rUserIDs as $rUserID) {
							$rPrepare = prepareArray($rUserArray);

							if (0 >= count($rPrepare['data'])) {
							} else {
								$rPrepare['data'][] = $rUserID;
								$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
								$db->query($rQuery, ...$rPrepare['data']);
								CoreUtilities::updateLine($rUserID);
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
		if (InputValidator::validate('processEnigma', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_e2')) {
					$rArray = overwriteData(getEnigma($rData['edit']), $rData);
					$rUser = UserRepository::getLineById($rArray['user_id']);

					if ($rUser) {
						$rUserArray = overwriteData($rUser, $rData);
					} else {
						$rUserArray = verifyPostTable('lines', $rData);
						$rUserArray['created_at'] = time();
						unset($rUserArray['id']);
					}
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_e2')) {
					$rArray = verifyPostTable('enigma2_devices', $rData);
					$rUserArray = verifyPostTable('lines', $rData);
					$rUserArray['created_at'] = time();
					unset($rArray['device_id'], $rUserArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rUserArray['username']) != 0) {
			} else {
				$rUserArray['username'] = generateString(32);
			}

			if (strlen($rUserArray['password']) != 0) {
			} else {
				$rUserArray['password'] = generateString(32);
			}

			if (strlen($rData['isp_clear']) != 0) {
			} else {
				$rUserArray['isp_desc'] = '';
				$rUserArray['as_number'] = null;
			}

			$rUserArray['is_e2'] = 1;
			$rUserArray['is_mag'] = 0;
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

			$rUserArray['bouquet'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
			$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if (!(0 < strlen($rData['exp_date']) && $rData['exp_date'] != '1970-01-01')) {
				} else {
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

			if ($rUserArray['member_id']) {
			} else {
				$rUserArray['member_id'] = $GLOBALS['rAdminUserInfo']['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (is_array($rData['allowed_ips'])) {
				} else {
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

			if (0 >= $rDevice['user']['pair_id']) {
			} else {
				$rUserCheck = UserRepository::getLineById($rDevice['user']['pair_id']);

				if ($rUserCheck) {
				} else {
					return array('status' => STATUS_INVALID_USER, 'data' => $rData);
				}
			}

			if (filter_var($rData['mac'], FILTER_VALIDATE_MAC)) {
				if (isset($rData['edit'])) {
					$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? AND `device_id` <> ? LIMIT 1;', $rArray['mac'], $rData['edit']);
				} else {
					$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? LIMIT 1;', $rArray['mac']);
				}

				if (0 >= $db->num_rows()) {
					$rPrepare = prepareArray($rUserArray);

					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if (!$db->query($rQuery, ...$rPrepare['data'])) {
					} else {
						$rInsertID = $db->last_insert_id();
						$rArray['user_id'] = $rInsertID;
						CoreUtilities::updateLine($rArray['user_id']);
						unset($rArray['user'], $rArray['paired']);

						if (isset($rData['edit'])) {
						} else {
							$rArray['token'] = '';
							$rArray['lversion'] = $rArray['token'];
							$rArray['cpu'] = $rArray['lversion'];
							$rArray['enigma_version'] = $rArray['cpu'];
							$rArray['local_ip'] = $rArray['enigma_version'];
							$rArray['modem_mac'] = $rArray['local_ip'];
						}

						$rPrepare = prepareArray($rArray);
						$rQuery = 'REPLACE INTO `enigma2_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = $db->last_insert_id();

							if (0 >= $rDevice['user']['pair_id']) {
							} else {
								MagService::syncLineDevices($rDevice['user']['pair_id'], $rInsertID);
								CoreUtilities::updateLine($rDevice['user']['pair_id']);
							}

							return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
						}

						if (isset($rData['edit'])) {
						} else {
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
}
