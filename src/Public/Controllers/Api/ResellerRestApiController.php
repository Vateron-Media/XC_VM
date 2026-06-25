<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Domain\User\UserService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
/**
 * ResellerRestApiController — reseller rest api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
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
