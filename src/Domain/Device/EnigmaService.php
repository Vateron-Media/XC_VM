<?php

namespace XcVm\Domain\Device;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * EnigmaService — enigma service
 *
 * @package XC_VM_Domain_Device
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EnigmaService {
	use DatabaseAware;

	/**
	 * Bulk delete selected Enigma2 devices.
	 *
	 * @param array $rData Selected device ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rEnigmas = json_decode($rData['enigmas'], true);
		self::deleteDevices($rEnigmas);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Apply bulk edits to selected Enigma2 devices.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		if (InputValidator::validate('massEditEnigmas', $rData)) {
			$rArray = [];
			$rUserArray = [];

			foreach (['is_isplock', 'is_trial'] as $rItem) {
				if (isset($rData['c_' . $rItem])) {
					if (isset($rData[$rItem])) {
						$rUserArray[$rItem] = 1;
					} else {
						$rUserArray[$rItem] = 0;
					}
				}
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
						$rDate = new \DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (\Exception $e) {
					}
				}
			}

			if (isset($rData['c_bouquets'])) {
				$rUserArray['bouquet'] = [];
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
				$rArray['token'] = '';
				$rArray['lversion'] = $rArray['token'];
				$rArray['cpu'] = $rArray['lversion'];
				$rArray['enigma_version'] = $rArray['cpu'];
				$rArray['modem_mac'] = $rArray['enigma_version'];
				$rArray['local_ip'] = $rArray['modem_mac'];
			}

			$rDevices = json_decode($rData['devices_selected'], true);

			foreach ($rDevices as $rDevice) {
				$rDeviceInfo = self::getById($rDevice);

				if ($rDeviceInfo) {
					if (0 < count($rArray)) {
						$rPrepare = QueryHelper::prepareArray($rArray);
						if (0 < count($rPrepare['data'])) {
							$rPrepare['data'][] = $rDevice;
							$rQuery = 'UPDATE `enigma2_devices` SET ' . $rPrepare['update'] . ' WHERE `device_id` = ?;';
							$db->query($rQuery, ...$rPrepare['data']);
						}
					}
					if (0 < count($rUserArray)) {
						$rUserIDs = [];
						if (isset($rDeviceInfo['user']['id'])) {
							$rUserIDs[] = $rDeviceInfo['user']['id'];
						}
						if (isset($rDeviceInfo['user']['paired'])) {
							$rUserIDs[] = $rDeviceInfo['paired']['id'];
						}
						foreach ($rUserIDs as $rUserID) {
							$rPrepare = QueryHelper::prepareArray($rUserArray);

							if (0 < count($rPrepare['data'])) {
								$rPrepare['data'][] = $rUserID;
								$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
								$db->query($rQuery, ...$rPrepare['data']);
								LineService::updateLineSignal($rUserID);
							}
						}
					}
				}
			}

			return ['status' => STATUS_SUCCESS];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Create or update an Enigma2 device from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processEnigma', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_e2')) {
					$rArray = AdminHelpers::overwriteData(self::getById($rData['edit']), $rData);
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
				if (Authorization::check('adv', 'add_e2')) {
					$rArray = QueryHelper::verifyPostTable('enigma2_devices', $rData);
					$rUserArray = QueryHelper::verifyPostTable('lines', $rData);
					$rUserArray['created_at'] = time();
					unset($rArray['device_id'], $rUserArray['id']);
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

			$rUserArray['bouquet'] = AdminHelpers::sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
			$rUserArray['bouquet'] = '[' . implode(',', array_map('intval', $rUserArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if ((string) $rData['exp_date'] !== '' && $rData['exp_date'] != '1970-01-01') {
					try {
						$rDate = new \DateTime($rData['exp_date']);
						$rUserArray['exp_date'] = $rDate->format('U');
					} catch (\Exception $e) {
						return ['status' => STATUS_INVALID_DATE, 'data' => $rData];
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
					$rData['allowed_ips'] = [$rData['allowed_ips']];
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

			$rUserArray['allowed_outputs'] = '[' . implode(',', [1, 2]) . ']';
			$rDevice = $rArray;
			$rDevice['user'] = $rUserArray;

			if (0 < $rDevice['user']['pair_id']) {
				$rUserCheck = UserRepository::getLineById($rDevice['user']['pair_id']);
				if (!$rUserCheck) {
					return ['status' => STATUS_INVALID_USER, 'data' => $rData];
				}
			}

			if (filter_var($rData['mac'], FILTER_VALIDATE_MAC)) {
				if (isset($rData['edit'])) {
					$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? AND `device_id` <> ? LIMIT 1;', $rArray['mac'], $rData['edit']);
				} else {
					$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE mac = ? LIMIT 1;', $rArray['mac']);
				}

				if (0 >= $db->num_rows()) {
					if (isset($rData['category_template_id'])) {
						if ($rData['category_template_id'] === '0' || $rData['category_template_id'] === 'none') {
							$rUserArray['custom_data'] = null;
						} elseif (intval($rData['category_template_id']) > 0) {
							$customDataObj = CategoryTemplateService::buildCustomData(intval($rData['category_template_id']));
							$rUserArray['custom_data'] = json_encode($customDataObj, JSON_UNESCAPED_UNICODE);
						}
					} elseif (isset($rData['custom_data'])) {
						$rUserArray['custom_data'] = ((string) $rData['custom_data'] !== '')
							? (is_array($rData['custom_data']) ? json_encode($rData['custom_data'], JSON_UNESCAPED_UNICODE) : $rData['custom_data'])
							: null;
					}

					$rPrepare = QueryHelper::prepareArray($rUserArray);

					$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rArray['user_id'] = $rInsertID;
						LineService::updateLineSignal($rArray['user_id']);
						if (!isset($rData['edit'])) {
							$rArray['token'] = '';
							$rArray['lversion'] = $rArray['token'];
							$rArray['cpu'] = $rArray['lversion'];
							$rArray['enigma_version'] = $rArray['cpu'];
							$rArray['local_ip'] = $rArray['enigma_version'];
							$rArray['modem_mac'] = $rArray['local_ip'];
						}
						$rPrepare = QueryHelper::prepareArray($rArray);
						$rQuery = 'REPLACE INTO `enigma2_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rInsertID = $db->last_insert_id();

							if (0 < $rDevice['user']['pair_id']) {
								MagService::syncLineDevices($rDevice['user']['pair_id'], $rInsertID);
								LineService::updateLineSignal($rDevice['user']['pair_id']);
							}

							return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
						}
						if (!isset($rData['edit'])) {
							$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rInsertID);
						}
					}

					return ['status' => STATUS_FAILURE, 'data' => $rData];
				}

				return ['status' => STATUS_EXISTS_MAC, 'data' => $rData];
			}

			return ['status' => STATUS_INVALID_MAC, 'data' => $rData];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Fetch a single Enigma2 device by id.
	 *
	 * @param int $rID Device id.
	 * @return array|null The device row, or null if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return [];
		}

		$rRow = $db->get_row();
		$rRow['user'] = UserRepository::getLineById($rRow['user_id']);

		$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

		if ($db->num_rows() == 1) {
			$rRow['paired'] = UserRepository::getLineById($rRow['user']['pair_id']);
		}

		return $rRow;
	}

	/**
	 * Fetch Enigma2 device(s) belonging to a user/line.
	 *
	 * @param int $rID Owner/line id.
	 * @return array Device row(s).
	 */
	public static function getByUserId(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `enigma2_devices` WHERE `user_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return [];
		}

		return $db->get_row();
	}

	/**
	 * Delete an Enigma2 device.
	 *
	 * @param int  $rID           Device id.
	 * @param bool $rDeletePaired Also delete the paired line/device.
	 * @param bool $rCloseCons    Close active connections first.
	 * @param bool $rConvert      Convert (rather than delete) the paired line.
	 * @return bool True on success.
	 */
	public static function deleteDevice(int $rID, bool $rDeletePaired = false, bool $rCloseCons = true, bool $rConvert = false) {
		$db = self::db();
		$rEnigma = self::getById($rID);

		if (!$rEnigma) {
			return false;
		}

		$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);
		$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` = ?;', $rID);

		if ($rEnigma['user']) {
			if ($rConvert) {
				$db->query('UPDATE `lines` SET `is_e2` = 0 WHERE `id` = ?;', $rEnigma['user']['id']);
				LineService::updateLineSignal($rEnigma['user']['id']);
			} else {
				$rCount = 0;
				$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
				$rCount += $db->num_rows();
				$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
				$rCount += $db->num_rows();

				if ($rCount == 0) {
					LineService::deleteLineById($rEnigma['user']['id'], $rDeletePaired, $rCloseCons);
				}
			}
		}

		return true;
	}

	/**
	 * Bulk delete Enigma2 devices.
	 *
	 * @param int[] $rIDs Device ids.
	 * @return bool True on success.
	 */
	public static function deleteDevices(array $rIDs) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		$rUserIDs = [];
		$db->query('SELECT `user_id` FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rUserIDs[] = $rRow['user_id'];
		}
		$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');

		if (0 < count($rUserIDs)) {
			LineRepository::deleteMany($rUserIDs);
		}

		return true;
	}
}
