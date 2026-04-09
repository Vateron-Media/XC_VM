<?php

/**
 * PackageService — package service
 *
 * @package XC_VM_Domain_Line
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PackageService {
	public static function process($rData) {
		global $db;
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'edit_package')) {
				exit();
			}
			$rArray = AdminHelpers::overwriteData(PackageService::getById($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'add_packages')) {
				exit();
			}
			$rArray = QueryHelper::verifyPostTable('users_packages', $rData);
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
		$rArray['bouquets'] = AdminHelpers::sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $rArray['bouquets'])) . ']';

		if (isset($rData['output_formats'])) {
			$rArray['output_formats'] = array();
			foreach ($rData['output_formats'] as $rOutput) {
				$rArray['output_formats'][] = $rOutput;
			}
			$rArray['output_formats'] = '[' . implode(',', array_map('intval', $rArray['output_formats'])) . ']';
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `users_packages`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function deleteById($rID) {
		global $db;
		$rPackage = self::getById($rID);

		if (!$rPackage) {
			return false;
		}

		$db->query('UPDATE `lines` SET `package_id` = null WHERE `package_id` = ?;', $rID);
		$db->query('DELETE FROM `users_packages` WHERE `id` = ?;', $rID);

		return true;
	}

	public static function getAll($rGroup = null, $rType = null) {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `users_packages` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				if (isset($rGroup) && !in_array(intval($rGroup), json_decode($rRow['groups'], true))) {
				} else {
					if ($rType && !$rRow['is_' . $rType]) {
					} else {
						$rReturn[intval($rRow['id'])] = $rRow;
					}
				}
			}
		}

		return $rReturn;
	}

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `users_packages` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	public static function checkCompatible($rIDA, $rIDB) {
		$rPackageA = self::getById($rIDA);
		$rPackageB = self::getById($rIDB);
		$rCompatible = true;

		if (!($rPackageA && $rPackageB)) {
		} else {
			foreach (array('bouquets', 'output_formats') as $rKey) {
				if (json_decode($rPackageA[$rKey], true) == json_decode($rPackageB[$rKey], true)) {
				} else {
					$rCompatible = false;
				}
			}

			foreach (array('is_restreamer', 'is_isplock', 'max_connections', 'force_server_id', 'forced_country', 'lock_device') as $rKey) {
				if ($rPackageA[$rKey] == $rPackageB[$rKey]) {
				} else {
					$rCompatible = false;
				}
			}
		}

		return $rCompatible;
	}
}
