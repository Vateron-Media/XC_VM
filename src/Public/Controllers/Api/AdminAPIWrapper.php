<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\AuthService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Server\ServerService;
use XcVm\Domain\Server\SettingsService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ChannelService;
use XcVm\Domain\Stream\ProfileService;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Domain\Stream\RadioService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Domain\Vod\EpisodeService;
use XcVm\Domain\Vod\MovieService;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Module\Watch\WatchService;

class AdminAPIWrapper {
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
        self::$db->query('SELECT * FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_admin` = 1 AND `status` = 1;', self::$rKey);
        if (0 >= self::$db->num_rows()) {
            return false;
        }
        $rUserID = self::$db->get_row()['id'];
        $GLOBALS['rAdminUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
        unset($GLOBALS['rAdminUserInfo']['password']);
        $rUserInfo = $GLOBALS['rAdminUserInfo'];
        $rPermissions = AuthRepository::getPermissions($rUserInfo['member_group_id']);
        $rPermissions['advanced'] = array();
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
    public static function getLine($rID) {
        if (!($rLine = UserRepository::getLineById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rLine);
    }
    public static function createLine($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(LineService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editLine($rID, $rData) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(LineService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
        } else {
            if (!LineService::deleteLineById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function banLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function unbanLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getUser($rID) {
        if (!($rUser = UserRepository::getRegisteredUserById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rUser);
    }
    public static function createUser($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(UserService::process($rData));
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
        $rReturn = parseerror(UserService::process($rData));
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
    public static function getMAG($rID) {
        if (!($rDevice = MagService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
    }
    public static function createMAG($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(MagService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editMAG($rID, $rData) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(MagService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
        } else {
            if (!MagService::deleteDevice($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function banMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function unbanMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function convertMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        MagService::deleteDevice($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['user_id']));
    }
    public static function getEnigma($rID) {
        if (!($rDevice = EnigmaService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
    }
    public static function createEnigma($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(EnigmaService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editEnigma($rID, $rData) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        if (!isset($rData['isp_clear'])) {
        } else {
            $rData['isp_clear'] = '';
        }
        $rReturn = parseerror(EnigmaService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
        } else {
            if (!EnigmaService::deleteDevice($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function disableEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function enableEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function banEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function unbanEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rDevice['user_id']);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function convertEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        EnigmaService::deleteDevice($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['user_id']));
    }
    public static function getBouquets() {
        return array('status' => 'STATUS_SUCCESS', 'data' => BouquetService::getAllSimple());
    }
    public static function getBouquet($rID) {
        if (!($rBouquet = BouquetService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rBouquet);
    }
    public static function createBouquet($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(BouquetService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getBouquet($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editBouquet($rID, $rData) {
        if (!(($rBouquet = self::getBouquet($rID)) && isset($rBouquet['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(BouquetService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getBouquet($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteBouquet($rID) {
        if (($rBouquet = self::getBouquet($rID)) && isset($rBouquet['data'])) {
            if (BouquetService::deleteById($rID)) {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getAccessCodes() {
        return array('status' => 'STATUS_SUCCESS', 'data' => AuthRepository::getAllCodes());
    }
    public static function getAccessCode($rID) {
        if (!($rCode = AuthRepository::getCodeById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rCode);
    }
    public static function createAccessCode($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(AuthService::processCode($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getAccessCode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editAccessCode($rID, $rData) {
        if (!(($rCode = self::getAccessCode($rID)) && isset($rCode['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(AuthService::processCode($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getAccessCode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteAccessCode($rID) {
        if (!(($rCode = self::getAccessCode($rID)) && isset($rCode['data']))) {
        } else {
            if (!AuthRepository::deleteCode($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getHMACs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => AuthRepository::getAllHMAC());
    }
    public static function getHMAC($rID) {
        if (!($rToken = AuthRepository::getHMACById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rToken);
    }
    public static function createHMAC($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(AuthService::processHMAC($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getHMAC($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editHMAC($rID, $rData) {
        if (!(($rToken = self::getHMAC($rID)) && isset($rToken['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(AuthService::processHMAC($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getHMAC($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteHMAC($rID) {
        if (($rToken = self::getHMAC($rID)) && isset($rToken['data'])) {
            if (AuthRepository::deleteHMAC($rID)) {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getEPGs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => EpgService::getAll());
    }
    public static function getEPG($rID) {
        if (!($rEPG = EpgService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rEPG);
    }
    public static function createEPG($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(EpgService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEPG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editEPG($rID, $rData) {
        if (!(($rEPG = self::getEPG($rID)) && isset($rEPG['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(EpgService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEPG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEPG($rID) {
        if (!(($rEPG = self::getEPG($rID)) && isset($rEPG['data']))) {
        } else {
            if (!EpgService::deleteEpgById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadEPG($rID = null) {
        if ($rID) {
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg "' . intval($rID) . '" > /dev/null 2>/dev/null &');
        } else {
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg > /dev/null 2>/dev/null &');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getProviders() {
        return array('status' => 'STATUS_SUCCESS', 'data' => ProviderService::getAll());
    }
    public static function getProvider($rID) {
        if (!($rProvider = ProviderService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rProvider);
    }
    public static function createProvider($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ProviderService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getProvider($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editProvider($rID, $rData) {
        if (!(($rProvider = self::getProvider($rID)) && isset($rProvider['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(ProviderService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getProvider($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteProvider($rID) {
        if (!(($rProvider = self::getProvider($rID)) && isset($rProvider['data']))) {
        } else {
            if (!ProviderService::deleteById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadProvider($rID = null) {
        if ($rID) {
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:providers "' . intval($rID) . '" > /dev/null 2>/dev/null &');
        } else {
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:providers > /dev/null 2>/dev/null &');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getGroups() {
        return array('status' => 'STATUS_SUCCESS', 'data' => GroupService::getAll());
    }
    public static function getGroup($rID) {
        if (!($rGroup = GroupService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rGroup);
    }
    public static function createGroup($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(GroupService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getGroup($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editGroup($rID, $rData) {
        if (!(($rGroup = self::getGroup($rID)) && isset($rGroup['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(GroupService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getGroup($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteGroup($rID) {
        if (!(($rGroup = self::getGroup($rID)) && isset($rGroup['data']))) {
        } else {
            if (!GroupService::deleteById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getPackages() {
        return array('status' => 'STATUS_SUCCESS', 'data' => PackageService::getAll());
    }
    public static function getPackage($rID) {
        if (!($rPackage = PackageService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rPackage);
    }
    public static function createPackage($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(PackageService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getPackage($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editPackage($rID, $rData) {
        if (!(($rPackage = self::getPackage($rID)) && isset($rPackage['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(PackageService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getPackage($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deletePackage($rID) {
        if (!(($rPackage = self::getPackage($rID)) && isset($rPackage['data']))) {
        } else {
            if (!PackageService::deleteById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getTranscodeProfiles() {
        return array('status' => 'STATUS_SUCCESS', 'data' => StreamConfigRepository::getTranscodeProfiles());
    }
    public static function getTranscodeProfile($rID) {
        if (!($rProfile = StreamConfigRepository::getTranscodeProfile($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rProfile);
    }
    public static function createTranscodeProfile($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ProfileService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getTranscodeProfile($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editTranscodeProfile($rID, $rData) {
        if (!(($rProfile = self::getTranscodeProfile($rID)) && isset($rProfile['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(ProfileService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getTranscodeProfile($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteTranscodeProfile($rID) {
        if (!(($rProfile = self::getTranscodeProfile($rID)) && isset($rProfile['data']))) {
        } else {
            if (!StreamConfigRepository::deleteProfile($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getRTMPIPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getRTMPIPsSimple());
    }
    public static function getRTMPIP($rID) {
        if (!($rIP = BlocklistService::getRTMPIPById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rIP);
    }
    public static function addRTMPIP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(BlocklistService::processRTMPIP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getRTMPIP($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editRTMPIP($rID, $rData) {
        if (!(($rIP = self::getRTMPIP($rID)) && isset($rIP['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(BlocklistService::processRTMPIP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getRTMPIP($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteRTMPIP($rID) {
        if (!(($rIP = self::getRTMPIP($rID)) && isset($rIP['data']))) {
        } else {
            if (!BlocklistService::deleteRTMPIP($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getCategories() {
        return array('status' => 'STATUS_SUCCESS', 'data' => CategoryService::getAllByType());
    }
    public static function getCategory($rID) {
        if (!($rCategory = CategoryService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rCategory);
    }
    public static function createCategory($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(CategoryService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getCategory($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editCategory($rID, $rData) {
        if (!(($rCategory = self::getCategory($rID)) && isset($rCategory['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(CategoryService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getCategory($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteCategory($rID) {
        if (!(($rCategory = self::getCategory($rID)) && isset($rCategory['data']))) {
        } else {
            if (!CategoryService::deleteById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getWatchFolders() {
        return array('status' => 'STATUS_SUCCESS', 'data' => WatchService::getWatchFolders());
    }
    public static function getWatchFolder($rID) {
        if (!($rFolder = StreamRepository::getWatchFolder($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rFolder);
    }
    public static function createWatchFolder($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(WatchService::processWatchFolder($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getWatchFolder($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editWatchFolder($rID, $rData) {
        if (!(($rFolder = self::getWatchFolder($rID)) && isset($rFolder['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(WatchService::processWatchFolder($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getWatchFolder($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteWatchFolder($rID) {
        if (!(($rFolder = self::getWatchFolder($rID)) && isset($rFolder['data']))) {
        } else {
            if (!StreamRepository::deleteWatchFolder($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadWatchFolder($rServerID, $rID) {
        WatchService::forceWatch($rServerID, $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedISPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getAllISPs());
    }
    public static function addBlockedISP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(BlocklistService::processISP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedISP($rID) {
        if (!BlocklistService::deleteBlockedISP($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedUAs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getAllUserAgents());
    }
    public static function addBlockedUA($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(BlocklistService::processUA($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedUA($rID) {
        if (!BlocklistService::deleteBlockedUA($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedIPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getBlockedIPsSimple());
    }
    public static function addBlockedIP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(BlocklistService::blockIP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedIP($rID) {
        if (!BlocklistService::deleteBlockedIP($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function flushBlockedIPs() {
        BlocklistService::flushIPs();
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getStream($rID) {
        if (!(($rStream = StreamRepository::getById($rID)) && $rStream['type'] == 1)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createStream($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(StreamService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStream($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editStream($rID, $rData) {
        if (!(($rStream = self::getStream($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(StreamService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStream($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteStream($rID, $rServerID = -1) {
        if (!(($rStream = self::getStream($rID)) && isset($rStream['data']))) {
        } else {
            if (!StreamRepository::deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function startStream($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(ApiClient::request(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array($rID), 'servers' => array_keys(ServerRepository::getAll()))), true);
        } else {
            $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'stream', 'stream_ids' => array($rID), 'function' => 'start')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function stopStream($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(ApiClient::request(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => array($rID), 'servers' => array_keys(ServerRepository::getAll()))), true);
        } else {
            $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'stream', 'stream_ids' => array($rID), 'function' => 'stop')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getChannel($rID) {
        if (!(($rStream = StreamRepository::getById($rID)) && $rStream['type'] == 3)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createChannel($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(ChannelService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getChannel($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editChannel($rID, $rData) {
        if (!(($rStream = self::getChannel($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(ChannelService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getChannel($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteChannel($rID, $rServerID = -1) {
        if (!(($rStream = self::getChannel($rID)) && isset($rStream['data']))) {
        } else {
            if (!StreamRepository::deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getStation($rID) {
        if (!(($rStream = StreamRepository::getById($rID)) && $rStream['type'] == 4)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createStation($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(RadioService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStation($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editStation($rID, $rData) {
        if (!(($rStream = self::getStation($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(RadioService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStation($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteStation($rID, $rServerID = -1) {
        if (!(($rStream = self::getStation($rID)) && isset($rStream['data']))) {
        } else {
            if (!StreamRepository::deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getMovie($rID) {
        if (!(($rStream = StreamRepository::getById($rID)) && $rStream['type'] == 2)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createMovie($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(MovieService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMovie($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editMovie($rID, $rData) {
        if (!(($rStream = self::getMovie($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(MovieService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMovie($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteMovie($rID, $rServerID = -1) {
        if (!(($rStream = self::getMovie($rID)) && isset($rStream['data']))) {
        } else {
            if (!StreamRepository::deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function startMovie($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(ApiClient::request(array('action' => 'vod', 'sub' => 'start', 'stream_ids' => array($rID), 'servers' => array_keys(ServerRepository::getAll()))), true);
        } else {
            $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'vod', 'stream_ids' => array($rID), 'function' => 'start')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function stopMovie($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(ApiClient::request(array('action' => 'vod', 'sub' => 'stop', 'stream_ids' => array($rID), 'servers' => array_keys(ServerRepository::getAll()))), true);
        } else {
            $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'vod', 'stream_ids' => array($rID), 'function' => 'stop')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getEpisode($rID) {
        if (!(($rStream = StreamRepository::getById($rID)) && $rStream['type'] == 5)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createEpisode($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(EpisodeService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEpisode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editEpisode($rID, $rData) {
        if (!(($rStream = self::getEpisode($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(EpisodeService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEpisode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEpisode($rID, $rServerID = -1) {
        if (!(($rStream = self::getEpisode($rID)) && isset($rStream['data']))) {
        } else {
            if (!StreamRepository::deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getSeries($rID) {
        if (!($rSeries = SeriesService::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rSeries);
    }
    public static function createSeries($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(SeriesService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getSeries($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function editSeries($rID, $rData) {
        if (!(($rStream = self::getSeries($rID)) && isset($rStream['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(SeriesService::process($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getSeries($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteSeries($rID) {
        if (!(($rStream = self::getSeries($rID)) && isset($rStream['data']))) {
        } else {
            if (!SeriesService::deleteSeriesById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getServers() {
        global $rPermissions;
        return array('status' => 'STATUS_SUCCESS', 'data' => ServerRepository::getStreamingSimple($rPermissions));
    }
    public static function getServer($rID) {
        if (!($rServer = ServerRepository::getById($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rServer);
    }
    public static function installServer($rData) {
        global $rPermissions;
        if (!(empty($rData['type']) || empty($rData['ssh_port']) || empty($rData['root_username']) || empty($rData['root_password']))) {
            if (!($rData['type'] == 1 && (empty($rData['type']) || empty($rData['ssh_port'])))) {
                $rReturn = parseerror(ServerService::install($rData, ServerRepository::getStreamingSimple($rPermissions, 'all'), ServerRepository::getProxySimple($rPermissions)));
                if (!isset($rReturn['data']['insert_id'])) {
                } else {
                    $rReturn['data'] = self::getServer($rReturn['data']['insert_id']);
                }
                return array('status' => 'STATUS_FAILURE');
            }
            return array('status' => 'STATUS_INVALID_INPUT');
        }
        return array('status' => 'STATUS_INVALID_INPUT');
    }
    public static function editServer($rID, $rData) {
        if (!(($rServer = self::getServer($rID)) && isset($rServer['data']))) {
            return array('status' => 'STATUS_FAILURE');
        } else {
            $rData['edit'] = $rID;
            $rReturn = parseerror(ServerService::process($rData));
            if (!isset($rReturn['data']['insert_id'])) {
            } else {
                $rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'];
            }
            return $rReturn;
        }
    }
    public static function editProxy($rID, $rData) {
        if (!(($rServer = self::getServer($rID)) && isset($rServer['data']))) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['edit'] = $rID;
        $rReturn = parseerror(ServerService::processProxy($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteServer($rID) {
        if (!(($rServer = self::getServer($rID)) && isset($rServer['data']))) {
        } else {
            if (!ServerRepository::deleteById($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getSettings() {
        return array('status' => 'STATUS_SUCCESS', 'data' => SettingsManager::getAll());
    }
    public static function editSettings($rData) {
        $rReturn = parseerror(SettingsService::edit($rData));
        $rReturn['data'] = self::getSettings()['data'];
        return $rReturn;
    }
    public static function getStats($rServerID) {
        global $db;
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'stats')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['requests_per_second'] = ServerRepository::getAll()[$rServerID]['requests_per_second'];
        $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
        if (0 >= $db->num_rows()) {
        } else {
            $rData['open_connections'] = $db->get_row()['count'];
        }
        $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');
        if (0 >= $db->num_rows()) {
        } else {
            $rData['total_connections'] = $db->get_row()['count'];
        }
        $db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', $rServerID);
        if (0 >= $db->num_rows()) {
        } else {
            $rData['online_users'] = $db->num_rows();
        }
        $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
        if (0 >= $db->num_rows()) {
        } else {
            $rData['total_users'] = $db->num_rows();
        }
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `stream_status` <> 2 AND `type` = 1;', $rServerID);
        if (0 >= $db->num_rows()) {
        } else {
            $rData['total_streams'] = $db->get_row()['count'];
        }
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);
        if (0 >= $db->num_rows()) {
        } else {
            $rData['total_running_streams'] = $db->get_row()['count'];
        }
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServerID);
        if (0 >= $db->num_rows()) {
        } else {
            $rData['offline_streams'] = $db->get_row()['count'];
        }
        $rData['network_guaranteed_speed'] = ServerRepository::getAll()[$rServerID]['network_guaranteed_speed'];
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getFPMStatus($rServerID) {
        $rData = ApiClient::systemRequest($rServerID, array('action' => 'fpm_status'));
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getRTMPStats($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'rtmp_stats')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getFreeSpace($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'get_free_space')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getPIDs($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'get_pids')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getCertificateInfo($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'get_certificate_info')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function reloadNGINX($rServerID) {
        ApiClient::systemRequest($rServerID, array('action' => 'reload_nginx'));
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function clearTemp($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'free_temp')), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function clearStreams($rServerID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'free_streams')), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getDirectory($rServerID, $rDirectory) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'scandir', 'dir' => $rDirectory)), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        unset($rData['result']);
        if (!isset($rData['result']) || $rData['result']) {
            return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function killPID($rServerID, $rPID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'kill_pid', 'pid' => intval($rPID))), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function killConnection($rServerID, $rActivityID) {
        $rData = json_decode(ApiClient::systemRequest($rServerID, array('action' => 'closeConnection', 'activity_id' => intval($rActivityID))), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function adjustCredits($rID, $rCredits, $rReason = '') {
        global $db;
        global $rUserInfo;
        if (!(is_numeric($rCredits) && ($rUser = self::getUser($rID)) && isset($rUser['data']))) {
        } else {
            $rCredits = intval($rUser['data']['credits']) + intval($rCredits);
            if (0 > $rCredits) {
            } else {
                $db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rCredits, $rID);
                $db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rID, $rUserInfo['id'], $rCredits, time(), $rReason);
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadCache() {
        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine > /dev/null 2>/dev/null &');
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function runQuery($rQuery) {
        global $db;
        if (!$db->query($rQuery)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $db->get_rows(), 'insert_id' => $db->last_insert_id());
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
