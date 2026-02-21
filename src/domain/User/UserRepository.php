<?php

class UserRepository {
	public static function getAuthUserByCredentials($db, $rUsername, $rPassword) {
		$db->query('SELECT `id`, `username`, `password`, `member_group_id`, `status` FROM `users` WHERE `username` = ? LIMIT 1;', $rUsername);

		if ($db->num_rows() == 1) {
			$rRow = $db->get_row();

			if (cryptPassword($rPassword, $rRow['password']) == $rRow['password']) {
				return $rRow;
			}
		}
	}

	public static function getResellers($db, $rOwner, $rIncludeSelf = true) {
		if ($rIncludeSelf) {
			$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ? OR `id` = ? ORDER BY `username` ASC;', $rOwner, $rOwner);
		} else {
			$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ? ORDER BY `username` ASC;', $rOwner);
		}

		return $db->get_rows(true, 'id');
	}

	public static function getDirectReports($db, $rPermissions, $rUserInfo, $rIncludeSelf = true) {
		$rUserIDs = $rPermissions['direct_reports'];

		if (!$rIncludeSelf) {
		} else {
			$rUserIDs[] = $rUserInfo['id'];
		}

		$rReturn = array();

		if (0 >= count($rUserIDs)) {
		} else {
			$db->query('SELECT * FROM `users` WHERE `owner_id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ') ORDER BY `username` ASC;');

			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	public static function getParent($rPermissions, $rUserInfo, $rID) {
		if (!isset($rPermissions['users'][$rID]['parent']) || $rPermissions['users'][$rID]['parent'] == 0 || $rPermissions['users'][$rID]['parent'] == $rUserInfo['id']) {
			return $rID;
		}

		return self::getParent($rPermissions, $rUserInfo, $rPermissions['users'][$rID]['parent']);
	}

	public static function getSubUsers($db, $rUser) {
		$rReturn = array();
		$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ?;', $rUser);

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['id']] = array('username' => $rRow['username'], 'parent' => $rUser);

			foreach (self::getSubUsers($db, $rRow['id']) as $rUserID => $rUserData) {
				$rReturn[$rUserID] = $rUserData;
			}
		}

		return $rReturn;
	}

	public static function getLineById($db, $rID) {
		$db->query('SELECT * FROM `lines` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function getRegisteredUserById($db, $rID) {
		$db->query('SELECT * FROM `users` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function getRegisteredUsers($db, $rOwner = null, $rIncludeSelf = true) {
		$rReturn = array();
		$db->query('SELECT * FROM `users` ORDER BY `username` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				if (!(!$rOwner || $rRow['owner_id'] == $rOwner || $rRow['id'] == $rOwner && $rIncludeSelf)) {
				} else {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}

		if (count($rReturn) == 0) {
			$rReturn[-1] = array();
		}

		return $rReturn;
	}

	public static function getStreamingUserInfo($db, $rSettings, $rCached, $rBouquets, $rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '', $rCallbacks = array()) {
		$rUserInfo = null;

		if ($rCached) {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if ($rSettings['case_sensitive_line']) {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . $rUsername));
				} else {
					$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_t_' . strtolower($rUsername)));
				}
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					if ($rSettings['case_sensitive_line']) {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . $rUsername . '_' . $rPassword));
					} else {
						$rUserID = intval(file_get_contents(LINES_TMP_PATH . 'line_c_' . strtolower($rUsername) . '_' . strtolower($rPassword)));
					}
				} else {
					if (empty($rUserID)) {
						return false;
					}
				}
			}

			if ($rUserID) {
				$rUserInfo = igbinary_unserialize(file_get_contents(LINES_TMP_PATH . 'line_i_' . $rUserID));
			}
		} else {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				$db->query('SELECT * FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `access_token` = ? AND LENGTH(`access_token`) = 32', $rUsername);
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `username` = ? AND `password` = ? LIMIT 1', $rUsername, $rPassword);
				} else {
					if (!empty($rUserID)) {
						$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `id` = ?', $rUserID);
					} else {
						return false;
					}
				}
			}

			if (0 < $db->num_rows()) {
				$rUserInfo = $db->get_row();
			}
		}

		if (!$rUserInfo) {
			return false;
		}

		if ($rCached) {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				if ($rUsername != $rUserInfo['access_token']) {
					return false;
				}
			} else {
				if (!empty($rUsername) && !empty($rPassword)) {
					if ($rUsername != $rUserInfo['username'] || $rPassword != $rUserInfo['password']) {
						return false;
					}
				}
			}
		}

		if ($rSettings['county_override_1st'] == 1 && empty($rUserInfo['forced_country']) && !empty($rIP) && $rUserInfo['max_connections'] == 1) {
			$rUserInfo['forced_country'] = call_user_func($rCallbacks['getIPInfo'], $rIP)['registered_country']['iso_code'];

			if ($rCached) {
				call_user_func($rCallbacks['setSignal'], 'forced_country/' . $rUserInfo['id'], $rUserInfo['forced_country']);
			} else {
				$db->query('UPDATE `lines` SET `forced_country` = ? WHERE `id` = ?', $rUserInfo['forced_country'], $rUserInfo['id']);
			}
		}

		$allowedIPS = json_decode($rUserInfo['allowed_ips'], true);
		$allowedUa = json_decode($rUserInfo['allowed_ua'], true);
		$rUserInfo['bouquet'] = json_decode($rUserInfo['bouquet'], true);
		$rUserInfo['allowed_ips'] = array_filter(array_map('trim', is_array($allowedIPS) ? $allowedIPS : []));
		$rUserInfo['allowed_ua'] = array_filter(array_map('trim', is_array($allowedUa) ? $allowedUa : []));
		$rUserInfo['allowed_outputs'] = array_map('intval', json_decode($rUserInfo['allowed_outputs'], true));
		$rUserInfo['output_formats'] = array();

		if ($rCached) {
			foreach (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'output_formats')) as $rRow) {
				if (in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		} else {
			$db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');

			foreach ($db->get_rows() as $rRow) {
				if (in_array(intval($rRow['access_output_id']), $rUserInfo['allowed_outputs'])) {
					$rUserInfo['output_formats'][] = $rRow['output_key'];
				}
			}
		}

		$rUserInfo['con_isp_name'] = null;
		$rUserInfo['isp_violate'] = 0;
		$rUserInfo['isp_is_server'] = 0;

		if ($rSettings['show_isps'] == 1 && !empty($rIP)) {
			$rISPLock = call_user_func($rCallbacks['getISP'], $rIP);

			if (is_array($rISPLock) && !empty($rISPLock['isp'])) {
				$rUserInfo['con_isp_name'] = $rISPLock['isp'];
				$rUserInfo['isp_asn'] = $rISPLock['autonomous_system_number'];
				$rUserInfo['isp_violate'] = call_user_func($rCallbacks['checkISP'], $rUserInfo['con_isp_name']);

				if ($rSettings['block_svp'] == 1) {
					$rUserInfo['isp_is_server'] = intval(call_user_func($rCallbacks['checkServer'], $rUserInfo['isp_asn']));
				}
			}

			if (!empty($rUserInfo['con_isp_name']) && $rSettings['enable_isp_lock'] == 1 && $rUserInfo['is_stalker'] == 0 && $rUserInfo['is_isplock'] == 1 && !empty($rUserInfo['isp_desc']) && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc'])) {
				$rUserInfo['isp_violate'] = 1;
			}

			if ($rUserInfo['isp_violate'] == 0 && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc'])) {
				if ($rCached) {
					call_user_func($rCallbacks['setSignal'], 'isp/' . $rUserInfo['id'], json_encode(array($rUserInfo['con_isp_name'], $rUserInfo['isp_asn'])));
				} else {
					$db->query('UPDATE `lines` SET `isp_desc` = ?, `as_number` = ? WHERE `id` = ?', $rUserInfo['con_isp_name'], $rUserInfo['isp_asn'], $rUserInfo['id']);
				}
			}
		}

		if ($rGetChannelIDs) {
			$rLiveIDs = $rVODIDs = $rRadioIDs = $rCategoryIDs = $rChannelIDs = $rSeriesIDs = array();

			foreach ($rUserInfo['bouquet'] as $rID) {
				if (isset($rBouquets[$rID]['streams'])) {
					$rChannelIDs = array_merge($rChannelIDs, $rBouquets[$rID]['streams']);
				}

				if (isset($rBouquets[$rID]['series'])) {
					$rSeriesIDs = array_merge($rSeriesIDs, $rBouquets[$rID]['series']);
				}

				if (isset($rBouquets[$rID]['channels'])) {
					$rLiveIDs = array_merge($rLiveIDs, $rBouquets[$rID]['channels']);
				}

				if (isset($rBouquets[$rID]['movies'])) {
					$rVODIDs = array_merge($rVODIDs, $rBouquets[$rID]['movies']);
				}

				if (isset($rBouquets[$rID]['radios'])) {
					$rRadioIDs = array_merge($rRadioIDs, $rBouquets[$rID]['radios']);
				}
			}

			$rUserInfo['channel_ids'] = array_map('intval', array_unique($rChannelIDs));
			$rUserInfo['series_ids'] = array_map('intval', array_unique($rSeriesIDs));
			$rUserInfo['vod_ids'] = array_map('intval', array_unique($rVODIDs));
			$rUserInfo['live_ids'] = array_map('intval', array_unique($rLiveIDs));
			$rUserInfo['radio_ids'] = array_map('intval', array_unique($rRadioIDs));
		}

		$rAllowedCategories = array();
		$rCategoryMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'category_map'));

		foreach ($rUserInfo['bouquet'] as $rID) {
			$rAllowedCategories = array_merge($rAllowedCategories, ($rCategoryMap[$rID] ?: array()));
		}

		$rUserInfo['category_ids'] = array_values(array_unique($rAllowedCategories));
		return $rUserInfo;
	}
}
