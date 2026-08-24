<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Http\RequestManager;

/**
 * AdminApiController — admin api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminApiController {
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
AdminAPIWrapper::$db = &$db;
AdminAPIWrapper::$rKey = $rData['api_key'] ?? '';
if (!empty($rData['api_key']) && AdminAPIWrapper::createSession()) {
    $rAction = $rData['action'] ?? '';
    $rStart = intval($rData['start'] ?? 0);
    $rLimit = intval($rData['limit'] ?? 50) ?: 50;
    unset($rData['api_key'], $rData['action'], $rData['start'], $rData['limit']);
    if (RequestManager::has('show_columns')) {
        $rShowColumns = explode(',', RequestManager::get('show_columns'));
    } else {
        $rShowColumns = null;
    }
    if (RequestManager::has('hide_columns')) {
        $rHideColumns = explode(',', RequestManager::get('hide_columns'));
    } else {
        $rHideColumns = null;
    }
    switch ($rAction) {
        case 'mysql_query':
            echo json_encode(AdminAPIWrapper::runQuery($rData['query']));
            break;
        case 'user_info':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getUserInfo(), $rShowColumns, $rHideColumns));
            break;
        case 'get_lines':
            echo json_encode(AdminAPIWrapper::TableAPI('lines', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_mags':
            echo json_encode(AdminAPIWrapper::TableAPI('mags', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_enigmas':
            echo json_encode(AdminAPIWrapper::TableAPI('enigmas', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_users':
            echo json_encode(AdminAPIWrapper::TableAPI('reg_users', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_streams':
            echo json_encode(AdminAPIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_provider_streams':
            echo json_encode(AdminAPIWrapper::TableAPI('provider_streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_channels':
            $rData['created'] = true;
            echo json_encode(AdminAPIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_stations':
            echo json_encode(AdminAPIWrapper::TableAPI('radios', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_movies':
            echo json_encode(AdminAPIWrapper::TableAPI('movies', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_series_list':
            echo json_encode(AdminAPIWrapper::TableAPI('series', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_episodes':
            echo json_encode(AdminAPIWrapper::TableAPI('episodes', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'activity_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('line_activity', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'live_connections':
            echo json_encode(AdminAPIWrapper::TableAPI('live_connections', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'credit_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('credits_log', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'client_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('client_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'user_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('reg_user_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'stream_errors':
            echo json_encode(AdminAPIWrapper::TableAPI('stream_errors', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'watch_output':
            echo json_encode(AdminAPIWrapper::TableAPI('watch_output', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'system_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('mysql_syslog', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'login_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('login_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'restream_logs':
            echo json_encode(AdminAPIWrapper::TableAPI('restream_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'mag_events':
            echo json_encode(AdminAPIWrapper::TableAPI('mag_events', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_line':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getLine($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_line':
            echo json_encode(AdminAPIWrapper::createLine($rData));
            break;
        case 'edit_line':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editLine($rID, $rData));
            break;
        case 'delete_line':
            echo json_encode(AdminAPIWrapper::deleteLine($rData['id']));
            break;
        case 'disable_line':
            echo json_encode(AdminAPIWrapper::disableLine($rData['id']));
            break;
        case 'enable_line':
            echo json_encode(AdminAPIWrapper::enableLine($rData['id']));
            break;
        case 'unban_line':
            echo json_encode(AdminAPIWrapper::unbanLine($rData['id']));
            break;
        case 'ban_line':
            echo json_encode(AdminAPIWrapper::banLine($rData['id']));
            break;
        case 'get_user':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getUser($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_user':
            echo json_encode(AdminAPIWrapper::createUser($rData));
            break;
        case 'edit_user':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editUser($rID, $rData));
            break;
        case 'delete_user':
            echo json_encode(AdminAPIWrapper::deleteUser($rData['id']));
            break;
        case 'disable_user':
            echo json_encode(AdminAPIWrapper::disableUser($rData['id']));
            break;
        case 'enable_user':
            echo json_encode(AdminAPIWrapper::enableUser($rData['id']));
            break;
        case 'get_mag':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getMAG($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_mag':
            echo json_encode(AdminAPIWrapper::createMAG($rData));
            break;
        case 'edit_mag':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editMAG($rID, $rData));
            break;
        case 'delete_mag':
            echo json_encode(AdminAPIWrapper::deleteMAG($rData['id']));
            break;
        case 'disable_mag':
            echo json_encode(AdminAPIWrapper::disableMAG($rData['id']));
            break;
        case 'enable_mag':
            echo json_encode(AdminAPIWrapper::enableMAG($rData['id']));
            break;
        case 'unban_mag':
            echo json_encode(AdminAPIWrapper::unbanMAG($rData['id']));
            break;
        case 'ban_mag':
            echo json_encode(AdminAPIWrapper::banMAG($rData['id']));
            break;
        case 'convert_mag':
            echo json_encode(AdminAPIWrapper::convertMAG($rData['id']));
            break;
        case 'get_enigma':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEnigma($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_enigma':
            echo json_encode(AdminAPIWrapper::createEnigma($rData));
            break;
        case 'edit_enigma':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editEnigma($rID, $rData));
            break;
        case 'delete_enigma':
            echo json_encode(AdminAPIWrapper::deleteEnigma($rData['id']));
            break;
        case 'disable_enigma':
            echo json_encode(AdminAPIWrapper::disableEnigma($rData['id']));
            break;
        case 'enable_enigma':
            echo json_encode(AdminAPIWrapper::enableEnigma($rData['id']));
            break;
        case 'unban_enigma':
            echo json_encode(AdminAPIWrapper::unbanEnigma($rData['id']));
            break;
        case 'ban_enigma':
            echo json_encode(AdminAPIWrapper::banEnigma($rData['id']));
            break;
        case 'convert_enigma':
            echo json_encode(AdminAPIWrapper::convertEnigma($rData['id']));
            break;
        case 'get_bouquets':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBouquets(), $rShowColumns, $rHideColumns));
            break;
        case 'get_bouquet':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getBouquet($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_bouquet':
            echo json_encode(AdminAPIWrapper::createBouquet($rData));
            break;
        case 'edit_bouquet':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editBouquet($rID, $rData));
            break;
        case 'delete_bouquet':
            echo json_encode(AdminAPIWrapper::deleteBouquet($rData['id']));
            break;
        case 'get_access_codes':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getAccessCodes(), $rShowColumns, $rHideColumns));
            break;
        case 'get_access_code':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getAccessCode($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_access_code':
            echo json_encode(AdminAPIWrapper::createAccessCode($rData));
            break;
        case 'edit_access_code':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editAccessCode($rID, $rData));
            break;
        case 'delete_access_code':
            echo json_encode(AdminAPIWrapper::deleteAccessCode($rData['id']));
            break;
        case 'get_hmacs':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getHMACs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_hmac':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getHMAC($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_hmac':
            echo json_encode(AdminAPIWrapper::createHMAC($rData));
            break;
        case 'edit_hmac':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editHMAC($rID, $rData));
            break;
        case 'delete_hmac':
            echo json_encode(AdminAPIWrapper::deleteHMAC($rData['id']));
            break;
        case 'get_epgs':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getEPGs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_epg':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEPG($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_epg':
            echo json_encode(AdminAPIWrapper::createEPG($rData));
            break;
        case 'edit_epg':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editEPG($rID, $rData));
            break;
        case 'delete_epg':
            echo json_encode(AdminAPIWrapper::deleteEPG($rData['id']));
            break;
        case 'reload_epg':
            echo json_encode(AdminAPIWrapper::reloadEPG((isset($rData['id']) ? intval($rData['id']) : null)));
            break;
        case 'get_providers':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getProviders(), $rShowColumns, $rHideColumns));
            break;
        case 'get_provider':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getProvider($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_provider':
            echo json_encode(AdminAPIWrapper::createProvider($rData));
            break;
        case 'edit_provider':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editProvider($rID, $rData));
            break;
        case 'delete_provider':
            echo json_encode(AdminAPIWrapper::deleteProvider($rData['id']));
            break;
        case 'reload_provider':
            echo json_encode(AdminAPIWrapper::reloadProvider((isset($rData['id']) ? intval($rData['id']) : null)));
            break;
        case 'get_groups':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getGroups(), $rShowColumns, $rHideColumns));
            break;
        case 'get_group':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getGroup($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_group':
            echo json_encode(AdminAPIWrapper::createGroup($rData));
            break;
        case 'edit_group':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editGroup($rID, $rData));
            break;
        case 'delete_group':
            echo json_encode(AdminAPIWrapper::deleteGroup($rData['id']));
            break;
        case 'get_packages':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getPackages(), $rShowColumns, $rHideColumns));
            break;
        case 'get_package':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getPackage($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_package':
            echo json_encode(AdminAPIWrapper::createPackage($rData));
            break;
        case 'edit_package':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editPackage($rID, $rData));
            break;
        case 'delete_package':
            echo json_encode(AdminAPIWrapper::deletePackage($rData['id']));
            break;
        case 'get_transcode_profiles':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getTranscodeProfiles(), $rShowColumns, $rHideColumns));
            break;
        case 'get_transcode_profile':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getTranscodeProfile($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_transcode_profile':
            echo json_encode(AdminAPIWrapper::createTranscodeProfile($rData));
            break;
        case 'edit_transcode_profile':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editTranscodeProfile($rID, $rData));
            break;
        case 'delete_transcode_profile':
            echo json_encode(AdminAPIWrapper::deleteTranscodeProfile($rData['id']));
            break;
        case 'get_rtmp_ips':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getRTMPIPs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_rtmp_ip':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getRTMPIP($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_rtmp_ip':
            echo json_encode(AdminAPIWrapper::addRTMPIP($rData));
            break;
        case 'edit_rtmp_ip':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editRTMPIP($rID, $rData));
            break;
        case 'delete_rtmp_ip':
            echo json_encode(AdminAPIWrapper::deleteRTMPIP($rData['id']));
            break;
        case 'get_categories':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getCategories(), $rShowColumns, $rHideColumns));
            break;
        case 'get_category':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getCategory($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_category':
            echo json_encode(AdminAPIWrapper::createCategory($rData));
            break;
        case 'edit_category':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editCategory($rID, $rData));
            break;
        case 'delete_category':
            echo json_encode(AdminAPIWrapper::deleteCategory($rData['id']));
            break;
        case 'get_watch_folders':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getWatchFolders(), $rShowColumns, $rHideColumns));
            break;
        case 'get_watch_folder':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getWatchFolder($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_watch_folder':
            echo json_encode(AdminAPIWrapper::createWatchFolder($rData));
            break;
        case 'edit_watch_folder':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editWatchFolder($rID, $rData));
            break;
        case 'delete_watch_folder':
            echo json_encode(AdminAPIWrapper::deleteWatchFolder($rData['id']));
            break;
        case 'reload_watch_folder':
            echo json_encode(AdminAPIWrapper::reloadWatchFolder((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['id']));
            break;
        case 'get_blocked_isps':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getBlockedISPs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_isp':
            echo json_encode(AdminAPIWrapper::addBlockedISP($rData['id']));
            break;
        case 'delete_blocked_isp':
            echo json_encode(AdminAPIWrapper::deleteBlockedISP($rData['id']));
            break;
        case 'get_blocked_uas':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBlockedUAs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_ua':
            echo json_encode(AdminAPIWrapper::addBlockedUA($rData));
            break;
        case 'delete_blocked_ua':
            echo json_encode(AdminAPIWrapper::deleteBlockedUA($rData['id']));
            break;
        case 'get_blocked_ips':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBlockedIPs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_ip':
            echo json_encode(AdminAPIWrapper::addBlockedIP($rData['id']));
            break;
        case 'delete_blocked_ip':
            echo json_encode(AdminAPIWrapper::deleteBlockedIP($rData['id']));
            break;
        case 'flush_blocked_ips':
            echo json_encode(AdminAPIWrapper::flushBlockedIPs());
            break;
        case 'get_stream':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getStream($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_stream':
            echo json_encode(AdminAPIWrapper::createStream($rData));
            break;
        case 'edit_stream':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editStream($rID, $rData));
            break;
        case 'delete_stream':
            echo json_encode(AdminAPIWrapper::deleteStream($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'start_station':
        case 'start_channel':
        case 'start_stream':
            echo json_encode(AdminAPIWrapper::startStream($rData['id'], $rData['server_id']));
            break;
        case 'stop_station':
        case 'stop_channel':
        case 'stop_stream':
            echo json_encode(AdminAPIWrapper::stopStream($rData['id'], $rData['server_id']));
            break;
        case 'get_channel':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getChannel($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_channel':
            echo json_encode(AdminAPIWrapper::createChannel($rData));
            break;
        case 'edit_channel':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editChannel($rID, $rData));
            break;
        case 'delete_channel':
            echo json_encode(AdminAPIWrapper::deleteChannel($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_station':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getStation($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_station':
            echo json_encode(AdminAPIWrapper::createStation($rData));
            break;
        case 'edit_station':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editStation($rID, $rData));
            break;
        case 'delete_station':
            echo json_encode(AdminAPIWrapper::deleteStation($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_movie':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getMovie($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_movie':
            echo json_encode(AdminAPIWrapper::createMovie($rData));
            break;
        case 'edit_movie':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editMovie($rID, $rData));
            break;
        case 'delete_movie':
            echo json_encode(AdminAPIWrapper::deleteMovie($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'start_episode':
        case 'start_movie':
            echo json_encode(AdminAPIWrapper::startMovie($rData['id'], $rData['server_id']));
            break;
        case 'stop_episode':
        case 'stop_movie':
            echo json_encode(AdminAPIWrapper::stopMovie($rData['id'], $rData['server_id']));
            break;
        case 'get_episode':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEpisode($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_episode':
            echo json_encode(AdminAPIWrapper::createEpisode($rData));
            break;
        case 'edit_episode':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editEpisode($rID, $rData));
            break;
        case 'delete_episode':
            echo json_encode(AdminAPIWrapper::deleteEpisode($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_series':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getSeries($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_series':
            echo json_encode(AdminAPIWrapper::createSeries($rData));
            break;
        case 'edit_series':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editSeries($rID, $rData));
            break;
        case 'delete_series':
            echo json_encode(AdminAPIWrapper::deleteSeries($rData['id']));
            break;
        case 'get_servers':
            echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getServers(), $rShowColumns, $rHideColumns));
            break;
        case 'get_server':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getServer($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'install_server':
            $rData['type'] = 0;
            echo json_encode(AdminAPIWrapper::installServer($rData));
            break;
        case 'install_proxy':
            $rData['type'] = 1;
            echo json_encode(AdminAPIWrapper::installServer($rData));
            break;
        case 'edit_server':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editServer($rID, $rData));
            break;
        case 'edit_proxy':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(AdminAPIWrapper::editProxy($rID, $rData));
            break;
        case 'delete_server':
            echo json_encode(AdminAPIWrapper::deleteServer($rData['id']));
            break;
        case 'get_settings':
            echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getSettings(), $rShowColumns, $rHideColumns));
            break;
        case 'edit_settings':
            echo json_encode(AdminAPIWrapper::editSettings($rData));
            break;
        case 'get_server_stats':
            echo json_encode(AdminAPIWrapper::getStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_fpm_status':
            echo json_encode(AdminAPIWrapper::getFPMStatus((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_rtmp_stats':
            echo json_encode(AdminAPIWrapper::getRTMPStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_free_space':
            echo json_encode(AdminAPIWrapper::getFreeSpace((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_pids':
            echo json_encode(AdminAPIWrapper::getPIDs((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_certificate_info':
            echo json_encode(AdminAPIWrapper::getCertificateInfo((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'reload_nginx':
            echo json_encode(AdminAPIWrapper::reloadNGINX((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'clear_temp':
            echo json_encode(AdminAPIWrapper::clearTemp((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'clear_streams':
            echo json_encode(AdminAPIWrapper::clearStreams((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_directory':
            echo json_encode(AdminAPIWrapper::getDirectory((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['dir']));
            break;
        case 'kill_pid':
            echo json_encode(AdminAPIWrapper::killPID((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['pid']));
            break;
        case 'kill_connection':
            echo json_encode(AdminAPIWrapper::killConnection((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['activity_id']));
            break;
        case 'adjust_credits':
            echo json_encode(AdminAPIWrapper::adjustCredits($rData['id'], $rData['credits'], (isset($rData['reason']) ? $rData['reason'] : '')));
            break;
        case 'reload_cache':
            echo json_encode(AdminAPIWrapper::reloadCache());
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
