<?php

/**
 * ResellerRestApiController — reseller rest api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerRestApiController {
	public function index() {
		global $db;
		global $_ERRORS;

		$_ERRORS = array();
foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
    if (substr($rKey, 0, 7) != 'STATUS_') {
    } else {
        $_ERRORS[intval($rValue)] = $rKey;
    }
}
$rData = RequestManager::getAll();
ResellerAPIWrapper::$db = &$db;
ResellerAPIWrapper::$rKey = $rData['api_key'];
if (!empty(RequestManager::getAll()['api_key']) && ResellerAPIWrapper::createSession()) {
    $rAction = $rData['action'];
    $rStart = (intval($rData['start']) ?: 0);
    $rLimit = (intval($rData['limit']) ?: 50);
    unset($rData['api_key'], $rData['action'], $rData['start'], $rData['limit']);
    if (isset(RequestManager::getAll()['show_columns'])) {
        $rShowColumns = explode(',', RequestManager::getAll()['show_columns']);
    } else {
        $rShowColumns = null;
    }
    if (isset(RequestManager::getAll()['hide_columns'])) {
        $rHideColumns = explode(',', RequestManager::getAll()['hide_columns']);
    } else {
        $rHideColumns = null;
    }
    switch ($rAction) {
        case 'packages':
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getPackages(), $rShowColumns, $rHideColumns));
            break;
        case 'user_info':
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getUserInfo(), $rShowColumns, $rHideColumns));
            break;
        case 'get_lines':
            echo json_encode(ResellerAPIWrapper::TableAPI('lines', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_mags':
            echo json_encode(ResellerAPIWrapper::TableAPI('mags', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_enigmas':
            echo json_encode(ResellerAPIWrapper::TableAPI('enigmas', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_users':
            echo json_encode(ResellerAPIWrapper::TableAPI('reg_users', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'activity_logs':
            echo json_encode(ResellerAPIWrapper::TableAPI('line_activity', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'live_connections':
            echo json_encode(ResellerAPIWrapper::TableAPI('live_connections', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'user_logs':
            echo json_encode(ResellerAPIWrapper::TableAPI('reg_user_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_line':
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getLine(RequestManager::getAll()['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_line':
            echo json_encode(ResellerAPIWrapper::createLine(RequestManager::getAll()));
            break;
        case 'edit_line':
            $rData = RequestManager::getAll();
            unset($rData['id']);
            echo json_encode(ResellerAPIWrapper::editLine(RequestManager::getAll()['id'], $rData));
            break;
        case 'delete_line':
            echo json_encode(ResellerAPIWrapper::deleteLine(RequestManager::getAll()['id']));
            break;
        case 'disable_line':
            echo json_encode(ResellerAPIWrapper::disableLine(RequestManager::getAll()['id']));
            break;
        case 'enable_line':
            echo json_encode(ResellerAPIWrapper::enableLine(RequestManager::getAll()['id']));
            break;
        case 'get_mag':
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getMAG(RequestManager::getAll()['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_mag':
            echo json_encode(ResellerAPIWrapper::createMAG(RequestManager::getAll()));
            break;
        case 'edit_mag':
            $rData = RequestManager::getAll();
            unset($rData['id']);
            echo json_encode(ResellerAPIWrapper::editMAG(RequestManager::getAll()['id'], $rData));
            break;
        case 'delete_mag':
            echo json_encode(ResellerAPIWrapper::deleteMAG(RequestManager::getAll()['id']));
            break;
        case 'disable_mag':
            echo json_encode(ResellerAPIWrapper::disableMAG(RequestManager::getAll()['id']));
            break;
        case 'enable_mag':
            echo json_encode(ResellerAPIWrapper::enableMAG(RequestManager::getAll()['id']));
            break;
        case 'convert_mag':
            echo json_encode(ResellerAPIWrapper::convertMAG(RequestManager::getAll()['id']));
            break;
        case 'get_enigma':
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getEnigma(RequestManager::getAll()['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_enigma':
            echo json_encode(ResellerAPIWrapper::createEnigma(RequestManager::getAll()));
            break;
        case 'edit_enigma':
            $rData = RequestManager::getAll();
            unset($rData['id']);
            echo json_encode(ResellerAPIWrapper::editEnigma(RequestManager::getAll()['id'], $rData));
            break;
        case 'delete_enigma':
            echo json_encode(ResellerAPIWrapper::deleteEnigma(RequestManager::getAll()['id']));
            break;
        case 'disable_enigma':
            echo json_encode(ResellerAPIWrapper::disableEnigma(RequestManager::getAll()['id']));
            break;
        case 'enable_enigma':
            echo json_encode(ResellerAPIWrapper::enableEnigma(RequestManager::getAll()['id']));
            break;
        case 'convert_enigma':
            echo json_encode(ResellerAPIWrapper::convertEnigma(RequestManager::getAll()['id']));
            break;
        case 'get_user':
            if (in_array('password', $rHideColumns)) {
            } else {
                $rHideColumns[] = 'password';
            }
            echo json_encode(ResellerAPIWrapper::filterRow(ResellerAPIWrapper::getUser($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_user':
            echo json_encode(ResellerAPIWrapper::createUser($rData));
            break;
        case 'edit_user':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(ResellerAPIWrapper::editUser($rID, $rData));
            break;
        case 'delete_user':
            echo json_encode(ResellerAPIWrapper::deleteUser($rData['id']));
            break;
        case 'disable_user':
            echo json_encode(ResellerAPIWrapper::disableUser($rData['id']));
            break;
        case 'enable_user':
            echo json_encode(ResellerAPIWrapper::enableUser($rData['id']));
            break;
        case 'adjust_credits':
            echo json_encode(ResellerAPIWrapper::adjustCredits($rData['id'], $rData['credits'], ($rData['note'] ?: '')));
            break;
        default:
            echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid action.'));
            break;
    }
} else {
    echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.'));
}
	}
}

class ResellerAPIWrapper {
    public static $db = null;
    public static $rKey = null;
    public static function filterRow($rData, $rShow, $rHide, $rSkipResult = false) {
        if ($rShow || $rHide) {
            if ($rSkipResult) {
                $rRow = $rData;
            } else {
                $rRow = $rData['data'];
            }
            $rReturn = array();
            if (!$rRow) {
            } else {
                foreach (array_keys($rRow) as $rKey) {
                    if ($rShow) {
                        if (!in_array($rKey, $rShow)) {
                        } else {
                            $rReturn[$rKey] = $rRow[$rKey];
                        }
                    } else {
                        if (!$rHide) {
                        } else {
                            if (in_array($rKey, $rHide)) {
                            } else {
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
        $rReturn = array();
        if (!$rRows['data']) {
        } else {
            foreach ($rRows['data'] as $rRow) {
                $rReturn[] = self::filterRow($rRow, $rShow, $rHide, true);
            }
        }
        return $rReturn;
    }
    public static function TableAPI($rID, $rStart = 0, $rLimit = 10, $rData = array(), $rShowColumns = array(), $rHideColumns = array()) {
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: xmlhttprequest'));
        $rReturn = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $rReturn;
    }
    public static function createSession() {
        global $rUserInfo;
        global $rPermissions;
        self::$db->query('SELECT * FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_reseller` = 1 AND `status` = 1;', self::$rKey);
        if (0 >= self::$db->num_rows()) {
            return false;
        }
        ResellerAPI::init(self::$db->get_row()['id']);
        unset(ResellerAPI::$rUserInfo['password']);
        $rUserInfo = ResellerAPI::$rUserInfo;
        $rPermissions = ResellerAPI::$rPermissions;
        if (0 >= strlen($rUserInfo['timezone'])) {
        } else {
            date_default_timezone_set($rUserInfo['timezone']);
        }
        return true;
    }
    public static function getUserInfo() {
        global $rUserInfo;
        global $rPermissions;
        return array('status' => 'STATUS_SUCCESS', 'data' => $rUserInfo, 'permissions' => $rPermissions);
    }
    public static function getPackages() {
        global $rUserInfo;
        if (!$rUserInfo) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rPackages = array();
        $rOverride = json_decode($rUserInfo['override_packages'], true);
        foreach (PackageService::getAll($rUserInfo['member_group_id']) as $rPackage) {
            if (isset($rOverride[$rPackage['id']]['official_credits']) && 0 < strlen($rOverride[$rPackage['id']]['official_credits'])) {
                $rPackage['official_credits'] = intval($rOverride[$rPackage['id']]['official_credits']);
            } else {
                $rPackage['official_credits'] = intval($rPackage['official_credits']);
            }
            $rPackages[] = $rPackage;
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rPackages);
    }
    public static function getLine($rID) {
        if (!(($rLine = UserRepository::getLineById($rID)) && Authorization::check('line', $rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rLine);
    }
    public static function createLine($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ResellerAPI::processLine($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editLine($rID, $rData) {
        if (!UserRepository::getLineById($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(ResellerAPI::processLine($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteLine($rID) {
        if (!UserRepository::getLineById($rID)) {
        } else {
            if (!LineService::deleteLineById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableLine($rID) {
        if (!UserRepository::getLineById($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableLine($rID) {
        if (!UserRepository::getLineById($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getMAG($rID) {
        if (!($rDevice = MagService::getById($rID))) {
        } else {
            if (!Authorization::check('line', $rDevice['user_id'])) {
            } else {
                return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function createMAG($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ResellerAPI::processMAG($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editMAG($rID, $rData) {
        if (!MagService::getById($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(ResellerAPI::processMAG($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteMAG($rID) {
        if (!MagService::getById($rID)) {
        } else {
            if (!MagService::deleteDevice($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableMAG($rID) {
        if (!($rDevice = MagService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableMAG($rID) {
        if (!($rDevice = MagService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function convertMAG($rID) {
        global $db;
        if (!($rDevice = MagService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        MagService::deleteDevice($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => UserRepository::getLineById($rDevice['user_id']));
    }
    public static function getEnigma($rID) {
        if (!($rDevice = EnigmaService::getById($rID))) {
        } else {
            if (!Authorization::check('line', $rDevice['user_id'])) {
            } else {
                return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function createEnigma($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ResellerAPI::processEnigma($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editEnigma($rID, $rData) {
        if (!EnigmaService::getById($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(ResellerAPI::processEnigma($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEnigma($rID) {
        if (!EnigmaService::getById($rID)) {
        } else {
            if (!EnigmaService::deleteDevice($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableEnigma($rID) {
        if (!($rDevice = EnigmaService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableEnigma($rID) {
        if (!($rDevice = EnigmaService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function convertEnigma($rID) {
        global $db;
        if (!($rDevice = EnigmaService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        EnigmaService::deleteDevice($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => UserRepository::getLineById($rDevice['user_id']));
    }
    public static function getUser($rID) {
        if (!(($rUser = UserRepository::getRegisteredUserById($rID)) && Authorization::check('user', $rUser['id']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rUser);
    }
    public static function createUser($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ResellerAPI::processUser($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editUser($rID, $rData) {
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(ResellerAPI::processUser($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteUser($rID) {
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
        } else {
            if (!UserService::deleteRegisteredUser($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableUser($rID) {
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableUser($rID) {
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function adjustCredits($rID, $rCredits, $rNote) {
        global $rUserInfo;
        if (strlen($rNote) != 0) {
        } else {
            $rNote = 'Reseller API Adjustment';
        }
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
        } else {
            if (!is_numeric($rCredits)) {
            } else {
                $rOwnerCredits = intval($rUserInfo['credits']) - intval($rCredits);
                $rNewCredits = intval($rUser['data']['credits']) + intval($rCredits);
                if (!(0 <= $rNewCredits && 0 <= $rOwnerCredits)) {
                } else {
                    self::$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rOwnerCredits, $rUserInfo['id']);
                    self::$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rNewCredits, $rUser['data']['id']);
                    self::$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['data']['id'], $rUserInfo['id'], $rCredits, time(), $rNote);
                    self::$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'adjust_credits', $rID, intval($rCredits), $rOwnerCredits, time(), json_encode($rUser['data']));
                    return array('status' => 'STATUS_SUCCESS');
                }
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
}

if (!function_exists('parseError')) {
	function parseError($rArray) {
		global $_ERRORS;
		if (!(isset($rArray['status']) && is_numeric($rArray['status']))) {
		} else {
			$rArray['status'] = $_ERRORS[$rArray['status']];
		}
		if ($rArray) {
		} else {
			$rArray['status'] = 'STATUS_NO_PERMISSIONS';
		}
		return $rArray;
	}
}
