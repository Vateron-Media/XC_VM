<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Auth\ApiTokens;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;

class ResellerAPIWrapper {
	public static $db;

	public static $rKey;

	public static function filterRow($rData, $rShow, $rHide, $rSkipResult = false) {
		if ($rShow || $rHide) {
			if ($rSkipResult) {
				$rRow = $rData;
			} else {
				$rRow = $rData['data'];
			}
			$rReturn = [];
			if ($rRow) {
				foreach (array_keys($rRow) as $rKey) {
					if ($rShow) {
						if (in_array($rKey, $rShow)) {
							$rReturn[$rKey] = $rRow[$rKey];
						}
					} else {
						if ($rHide) {
							if (!in_array($rKey, $rHide)) {
								$rReturn[$rKey] = $rRow[$rKey];
							}
						}
					}
				}
			}
			if ($rSkipResult) {
				return $rReturn;
			}
			$rData['data'] = $rReturn;
			return $rData;
		}
		return $rData;
	}

	public static function filterRows($rRows, $rShow, $rHide) {
		$rReturn = [];
		if ($rRows['data']) {
			foreach ($rRows['data'] as $rRow) {
				$rReturn[] = self::filterRow($rRow, $rShow, $rHide, true);
			}
		}
		return $rReturn;
	}

	public static function TableAPI($rID, $rStart = 0, $rLimit = 10, $rData = [], $rShowColumns = [], $rHideColumns = []) {
		$rTableAPI = 'http://127.0.0.1:' . ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] . '/' . trim(dirname($_SERVER['PHP_SELF']), '/') . '/table.php';
		$rData['api_key'] = self::$rKey;
		$rData['id'] = $rID;
		$rData['start'] = $rStart;
		$rData['length'] = $rLimit;
		$rData['show_columns'] = $rShowColumns;
		$rData['hide_columns'] = $rHideColumns;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rTableAPI);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($rData));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: xmlhttprequest']);
		$rReturn = json_decode(curl_exec($ch), true);
		curl_close($ch);
		return $rReturn;
	}

	public static function createSession() {
		global $rUserInfo;
		global $rPermissions;
		// A token or a legacy key (ApiTokens): a token's scope then narrows what it runs.
		$rUserID = ApiTokens::userFor((string) self::$rKey, (string) NetworkUtils::getUserIP(), 'reseller');
		if ($rUserID === null) {
			return false;
		}
		ResellerAPI::init($rUserID);
		unset(ResellerAPI::$rUserInfo['password']);
		$rUserInfo = ResellerAPI::$rUserInfo;
		$rPermissions = ResellerAPI::$rPermissions;
		if (empty($rUserInfo['reports'])) {
			$rUserInfo['reports'] = array_values(array_unique(array_merge([(int) $rUserInfo['id']], (array) ($rPermissions['all_reports'] ?? []))));
			ResellerAPI::$rUserInfo['reports'] = $rUserInfo['reports'];
		}
		if ((string) $rUserInfo['timezone'] !== '') {
			date_default_timezone_set($rUserInfo['timezone']);
		}
		return true;
	}

	public static function getUserInfo() {
		global $rUserInfo;
		global $rPermissions;
		return ['status' => 'STATUS_SUCCESS', 'data' => $rUserInfo, 'permissions' => $rPermissions];
	}

	public static function getPackages() {
		global $rUserInfo;
		if (!$rUserInfo) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rPackages = [];
		$rOverride = json_decode($rUserInfo['override_packages'], true);
		foreach (PackageService::getAll($rUserInfo['member_group_id']) as $rPackage) {
			// A package that neither sells subscriptions nor gives trials sells
			// a reseller nothing.
			if (!$rPackage['is_official'] && !$rPackage['is_trial']) {
				continue;
			}
			if (isset($rOverride[$rPackage['id']]['official_credits']) && (string) $rOverride[$rPackage['id']]['official_credits'] !== '') {
				$rPackage['official_credits'] = ResellerAPI::amount($rOverride[$rPackage['id']]['official_credits']);
			} else {
				$rPackage['official_credits'] = ResellerAPI::amount($rPackage['official_credits']);
			}
			$rPackages[] = $rPackage;
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rPackages];
	}

	public static function getLine($rID) {
		if (!($rLine = UserRepository::getLineById($rID)) || !Authorization::check('line', $rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rLine];
	}

	public static function createLine($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ResellerAPI::processLine($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editLine($rID, $rData) {
		if (!UserRepository::getLineById($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rReturn = parseerror(ResellerAPI::processLine($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteLine($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleLine).
		if (!Authorization::hasResellerPermissions('create_line')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (UserRepository::getLineById($rID) && Authorization::check('line', $rID)) {
			if (LineService::deleteLineById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableLine($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleLine).
		if (!Authorization::hasResellerPermissions('create_line')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (!UserRepository::getLineById($rID) || !Authorization::check('line', $rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rID);
		LineService::updateLineSignal(intval($rID));
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableLine($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleLine).
		if (!Authorization::hasResellerPermissions('create_line')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$rLine = UserRepository::getLineById($rID);
		// A line that waits for its activation code is switched on by redeeming the code.
		if (!$rLine || !Authorization::check('line', $rID) || ActiveCodeService::lineAwaitsRedemption($rLine)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rID);
		LineService::updateLineSignal(intval($rID));
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getMAG($rID) {
		if ($rDevice = MagService::getById($rID)) {
			if (Authorization::check('line', $rDevice['user_id'])) {
				return ['status' => 'STATUS_SUCCESS', 'data' => $rDevice];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function createMAG($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ResellerAPI::processMAG($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editMAG($rID, $rData) {
		if (!MagService::getById($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rReturn = parseerror(ResellerAPI::processMAG($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteMAG($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleMag).
		if (!Authorization::hasResellerPermissions('create_mag')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (($rDevice = MagService::getById($rID)) && Authorization::check('line', $rDevice['user_id'])) {
			if (MagService::deleteDevice($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableMAG($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleMag).
		if (!Authorization::hasResellerPermissions('create_mag')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (!($rDevice = MagService::getById($rID)) || !Authorization::check('line', $rDevice['user_id'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
		LineService::updateLineSignal($rDevice['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableMAG($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleMag).
		if (!Authorization::hasResellerPermissions('create_mag')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		// A line that waits for an activation code is switched on by redeeming the code.
		if (!($rDevice = MagService::getById($rID)) || !Authorization::check('line', $rDevice['user_id']) || ActiveCodeService::lineAwaitsRedemption($rDevice['user'] ?: [])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
		LineService::updateLineSignal($rDevice['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function convertMAG($rID) {
		global $db;
		// The group permission the panel asks for (ResellerApiDispatcher::handleMag).
		if (!Authorization::hasResellerPermissions('create_mag')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (!($rDevice = MagService::getById($rID)) || !Authorization::check('line', $rDevice['user_id'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		MagService::deleteDevice($rID, false, false, true);
		return ['status' => 'STATUS_SUCCESS', 'data' => UserRepository::getLineById($rDevice['user_id'])];
	}

	public static function getEnigma($rID) {
		if ($rDevice = EnigmaService::getById($rID)) {
			if (Authorization::check('line', $rDevice['user_id'])) {
				return ['status' => 'STATUS_SUCCESS', 'data' => $rDevice];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function createEnigma($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ResellerAPI::processEnigma($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editEnigma($rID, $rData) {
		if (!EnigmaService::getById($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rReturn = parseerror(ResellerAPI::processEnigma($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteEnigma($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleEnigma).
		if (!Authorization::hasResellerPermissions('create_enigma')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (($rDevice = EnigmaService::getById($rID)) && Authorization::check('line', $rDevice['user_id'])) {
			if (EnigmaService::deleteDevice($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableEnigma($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleEnigma).
		if (!Authorization::hasResellerPermissions('create_enigma')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (!($rDevice = EnigmaService::getById($rID)) || !Authorization::check('line', $rDevice['user_id'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
		LineService::updateLineSignal($rDevice['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableEnigma($rID) {
		// The group permission the panel asks for (ResellerApiDispatcher::handleEnigma).
		if (!Authorization::hasResellerPermissions('create_enigma')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		// A line that waits for an activation code is switched on by redeeming the code.
		if (!($rDevice = EnigmaService::getById($rID)) || !Authorization::check('line', $rDevice['user_id']) || ActiveCodeService::lineAwaitsRedemption($rDevice['user'] ?: [])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
		LineService::updateLineSignal($rDevice['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function convertEnigma($rID) {
		global $db;
		// The group permission the panel asks for (ResellerApiDispatcher::handleEnigma).
		if (!Authorization::hasResellerPermissions('create_enigma')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (!($rDevice = EnigmaService::getById($rID)) || !Authorization::check('line', $rDevice['user_id'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		EnigmaService::deleteDevice($rID, false, false, true);
		return ['status' => 'STATUS_SUCCESS', 'data' => UserRepository::getLineById($rDevice['user_id'])];
	}

	public static function getUser($rID) {
		if (!($rUser = UserRepository::getRegisteredUserById($rID)) || !Authorization::check('user', $rUser['id'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		// Never a sub-reseller's password hash or API key, whatever columns are
		// asked for: get_user, and the row create_user and edit_user answer with.
		unset($rUser['password'], $rUser['api_key']);
		return ['status' => 'STATUS_SUCCESS', 'data' => $rUser];
	}

	public static function createUser($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ResellerAPI::processUser($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editUser($rID, $rData) {
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rReturn = parseerror(ResellerAPI::processUser($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteUser($rID) {
		global $rUserInfo;
		global $rPermissions;
		// The group permissions the panel asks for (ResellerApiDispatcher::handleRegUser).
		if (empty($rPermissions['create_sub_resellers']) || empty($rPermissions['delete_users'])) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		// Neither the reseller's own account nor an administrator's is a reseller's to delete (GroupService::reservedGroups).
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data']) || $rUser['data']['id'] == $rUserInfo['id'] || in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rUser = $rUser['data'];
		// As in the panel: the deleted user's credits return to the reseller and
		// its lines and sub-resellers become the reseller's, in one transaction.
		// A user whose credits could not be moved is not deleted. The user's row
		// is held from this read to the delete, so what it holds here is what is moved.
		self::$db->beginTransaction();
		$rHeld = (self::$db->query('SELECT ROUND(COALESCE(`credits`, 0), 4) FROM `users` WHERE `id` = ? FOR UPDATE;', intval($rUser['id'])) ? floatval(self::$db->get_col()) : null);
		$rReturned = UserCredits::transferAll(intval($rUser['id']), $rUserInfo['id']);
		if ($rHeld === null || ($rReturned == 0 && $rHeld != 0) || !UserService::deleteRegisteredUser($rUser['id'], false, false, $rUserInfo['id'])) {
			self::$db->rollback();
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUserInfo['id'], $rUserInfo['id'], $rReturned, time(), 'Deleted user: ' . $rUser['username']);
		self::$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', $rUser['id'], intval($rReturned), intval(UserCredits::balance($rUserInfo['id'])), time(), json_encode($rUser));
		self::$db->commit();
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function disableUser($rID) {
		global $rUserInfo;
		global $rPermissions;
		// The group permission the panel asks for (ResellerApiDispatcher::handleRegUser).
		if (empty($rPermissions['create_sub_resellers'])) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		// Neither the reseller's own account nor an administrator's is a reseller's to switch off or on (GroupService::reservedGroups).
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data']) || $rUser['data']['id'] == $rUserInfo['id'] || in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableUser($rID) {
		global $rUserInfo;
		global $rPermissions;
		// The group permission the panel asks for (ResellerApiDispatcher::handleRegUser).
		if (empty($rPermissions['create_sub_resellers'])) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		// Neither the reseller's own account nor an administrator's is a reseller's to switch off or on (GroupService::reservedGroups).
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data']) || $rUser['data']['id'] == $rUserInfo['id'] || in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function adjustCredits($rID, $rCredits, $rNote) {
		global $rUserInfo;
		global $rPermissions;
		// The group permission the panel asks for (ResellerApiDispatcher::handleAdjustCredits).
		if (empty($rPermissions['create_sub_resellers'])) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		if (strlen($rNote) == 0) {
			$rNote = 'Reseller API Adjustment';
		}
		// An administrator's account keeps its credits: they are not a reseller's to move (GroupService::reservedGroups).
		if (($rUser = self::getUser($rID)) && isset($rUser['data']) && !in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			if (is_numeric($rCredits)) {
				// Credits move between the reseller and one of its sub-resellers:
				// each side gives only what its balance holds now.
				if (UserCredits::transfer($rUserInfo['id'], $rUser['data']['id'], intval($rCredits))) {
					self::$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['data']['id'], $rUserInfo['id'], $rCredits, time(), $rNote);
					self::$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'adjust_credits', $rID, intval($rCredits), intval(UserCredits::balance($rUserInfo['id'])), time(), json_encode($rUser['data']));
					return ['status' => 'STATUS_SUCCESS'];
				}
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	// ─── Active Codes Reseller API Handlers ──────────────────────────────────

	public static function getActiveCodes($rStart = 0, $rLimit = 50, $rData = [], $rShowColumns = null, $rHideColumns = null) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$res = ActiveCodeService::listCodes($rData, $user, false, (int) $rStart, (int) $rLimit);
		return [
			'status' => 'STATUS_SUCCESS',
			'total' => $res['total'],
			'count' => $res['count'],
			'start' => $res['start'],
			'limit' => $res['limit'],
			'data' => self::filterRows(['data' => $res['data']], $rShowColumns, $rHideColumns),
		];
	}

	public static function getActiveCode($rID) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$code = ActiveCodeService::getCodeDetails($rID, $user, false);
		if (!$code) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Active code not found or access denied.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $code];
	}

	public static function generateActiveCodes($rData) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}

		// Verify that package is permitted for this reseller group
		$packageId = (int) ($rData['package_id'] ?? 0);
		$allowedPackages = array_map('intval', array_column(PackageService::getAll($user['member_group_id']), 'id'));
		if (!in_array($packageId, $allowedPackages, true)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'The selected package is not allowed for your account group.'];
		}

		// Force target owner to this reseller or allowed sub-reseller
		if (!empty($rData['created_by'])) {
			$targetOwner = (int) $rData['created_by'];
			$reports = (array) ($user['reports'] ?? [$user['id']]);
			if (!in_array($targetOwner, array_map('intval', $reports), true)) {
				$rData['created_by'] = $user['id'];
			}
		} else {
			$rData['created_by'] = $user['id'];
		}

		$res = ActiveCodeService::generateCodes($rData, $user, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to generate active codes.'];
		}

		// Refresh reseller credits
		$freshUser = UserRepository::getUserById($user['id']);
		if ($freshUser) {
			$user['credits'] = $freshUser['credits'];
			ResellerAPI::$rUserInfo['credits'] = $freshUser['credits'];
		}

		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'batch_name' => $res['batch_name'],
			'qty' => $res['qty'],
			'total_cost' => $res['total_cost'] ?? 0,
			'remaining_credits' => (float) ($freshUser['credits'] ?? $user['credits']),
			'data' => $res['codes'],
		];
	}

	public static function editActiveCode($rID, $rData) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}

		// If package changed, check permission
		if (!empty($rData['package_id'])) {
			$newPkgId = (int) $rData['package_id'];
			$allowedPackages = array_map('intval', array_column(PackageService::getAll($user['member_group_id']), 'id'));
			if (!in_array($newPkgId, $allowedPackages, true)) {
				return ['status' => 'STATUS_FAILURE', 'error' => 'Package not permitted for your reseller group.'];
			}
		}

		$res = ActiveCodeService::updateCode((int) $rID, $rData, $user, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to update active code.'];
		}

		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'data' => ActiveCodeService::getCodeDetails((int) $rID, $user, false),
		];
	}

	public static function deleteActiveCode($rID) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}

		// Deleting unused code will automatically refund credits to reseller
		$res = ActiveCodeService::deleteCode((int) $rID, $user, false, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to delete active code.'];
		}

		// Refresh reseller credits in session
		$freshUser = UserRepository::getUserById($user['id']);
		if ($freshUser) {
			$user['credits'] = $freshUser['credits'];
			ResellerAPI::$rUserInfo['credits'] = $freshUser['credits'];
		}

		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'remaining_credits' => (float) ($freshUser['credits'] ?? $user['credits']),
		];
	}

	public static function enableActiveCode($rID) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$res = ActiveCodeService::massAction('enable', [(int) $rID], $user, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to enable active code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function disableActiveCode($rID) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$res = ActiveCodeService::massAction('disable', [(int) $rID], $user, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to disable active code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function resetActiveCodeDevice($rID) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$res = ActiveCodeService::resetDevice($rID, $user, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to reset device lock.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function massActiveCodes($rAction, $rIDs, $rExtra = []) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}

		if (is_string($rIDs)) {
			$rIDs = explode(',', $rIDs);
		}
		$rIDs = array_filter(array_map('intval', (array) $rIDs));
		if ($rIDs === []) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'No active code IDs provided.'];
		}

		$res = ActiveCodeService::massAction((string) $rAction, $rIDs, $user, false, (array) $rExtra);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Mass action failed.'];
		}

		// Refresh reseller credits
		$freshUser = UserRepository::getUserById($user['id']);
		if ($freshUser) {
			$user['credits'] = $freshUser['credits'];
			ResellerAPI::$rUserInfo['credits'] = $freshUser['credits'];
		}

		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'remaining_credits' => (float) ($freshUser['credits'] ?? $user['credits']),
		];
	}

	public static function getActiveCodesBatches($rBatchName = null) {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		$batches = ActiveCodeService::getBatchSummary($user, false, $rBatchName);
		return ['status' => 'STATUS_SUCCESS', 'data' => $batches];
	}

	public static function exportActiveCodeBatch($rBatchName, $rFormat = 'json') {
		global $rUserInfo;
		$user = ResellerAPI::$rUserInfo ?? $rUserInfo;
		if (empty($user)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Reseller session not found.'];
		}
		if (empty($rBatchName)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Batch name is required.'];
		}
		if (strtolower((string) $rFormat) === 'txt' || strtolower((string) $rFormat) === 'text') {
			$txt = ActiveCodeService::exportBatchTxt((string) $rBatchName, $user, false);
			return ['status' => 'STATUS_SUCCESS', 'format' => 'txt', 'content' => $txt];
		}
		$json = ActiveCodeService::exportBatchJson((string) $rBatchName, $user, false);
		return ['status' => 'STATUS_SUCCESS', 'format' => 'json', 'data' => $json];
	}

	public static function checkActiveCode($rCode) {
		$details = ActiveCodeService::checkCode((string) $rCode);
		if ($details === []) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Invalid or inactive code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $details];
	}
}

if (!function_exists(__NAMESPACE__ . '\\parseError') && !function_exists('parseError')) {
	function parseError($rArray) {
		global $_ERRORS;
		if (isset($rArray['status']) && is_numeric($rArray['status'])) {
			$rArray['status'] = $_ERRORS[$rArray['status']];
		}
		if (!$rArray) {
			$rArray['status'] = 'STATUS_NO_PERMISSIONS';
		}
		return $rArray;
	}
}
