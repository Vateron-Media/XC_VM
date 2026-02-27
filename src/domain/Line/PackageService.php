<?php

class PackageService {
	public static function process($rData, $rGetPackageCallback) {
		global $db;
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'edit_package')) {
				exit();
			}
			$rArray = overwriteData(call_user_func($rGetPackageCallback, $rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'add_packages')) {
				exit();
			}
			$rArray = verifyPostTable('users_packages', $rData);
			unset($rArray['id']);
		}

		if (strlen($rData['package_name']) == 0) {
			return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
		}

		foreach (array('is_trial', 'is_official', 'is_mag', 'is_e2', 'is_line', 'lock_device', 'is_restreamer', 'is_isplock', 'check_compatible') as $rSelection) {
			if (isset($rData[$rSelection])) {
				$rArray[$rSelection] = 1;
			} else {
				$rArray[$rSelection] = 0;
			}
		}

		$rArray['groups'] = '[' . implode(',', array_map('intval', json_decode($rData['groups_selected'], true))) . ']';
		$rArray['bouquets'] = sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $rArray['bouquets'])) . ']';

		if (isset($rData['output_formats'])) {
			$rArray['output_formats'] = array();
			foreach ($rData['output_formats'] as $rOutput) {
				$rArray['output_formats'][] = $rOutput;
			}
			$rArray['output_formats'] = '[' . implode(',', array_map('intval', $rArray['output_formats'])) . ']';
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `users_packages`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	// ──────────── Из PackageRepository ────────────

	public static function deleteById($rGetPackageCallback, $rID) {
		global $db;
		$rPackage = call_user_func($rGetPackageCallback, $rID);

		if (!$rPackage) {
			return false;
		}

		$db->query('UPDATE `lines` SET `package_id` = null WHERE `package_id` = ?;', $rID);
		$db->query('DELETE FROM `users_packages` WHERE `id` = ?;', $rID);

		return true;
	}
}
