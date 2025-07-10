<?php
if (defined('MAIN_HOME')) {
} else {
    define('MAIN_HOME', '/home/xc_vm/');
}
require_once MAIN_HOME . 'includes/admin.php';
$_ERRORS = array();
foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
    if (substr($rKey, 0, 7) != 'STATUS_') {
    } else {
        $_ERRORS[intval($rValue)] = $rKey;
    }
}
$rData = CoreUtilities::$rRequest;
APIWrapper::$db = &$db;
APIWrapper::$rKey = $rData['api_key'];
if (!empty(CoreUtilities::$rRequest['api_key']) && APIWrapper::createSession()) {
    $rAction = $rData['action'];
    $rStart = (intval($rData['start']) ?: 0);
    $rLimit = (intval($rData['limit']) ?: 50);
    unset($rData['api_key'], $rData['action'], $rData['start'], $rData['limit']);
    if (isset(CoreUtilities::$rRequest['show_columns'])) {
        $rShowColumns = explode(',', CoreUtilities::$rRequest['show_columns']);
    } else {
        $rShowColumns = null;
    }
    if (isset(CoreUtilities::$rRequest['hide_columns'])) {
        $rHideColumns = explode(',', CoreUtilities::$rRequest['hide_columns']);
    } else {
        $rHideColumns = null;
    }
    switch ($rAction) {
        case 'mysql_query':
            echo json_encode(APIWrapper::runQuery($rData['query']));
            break;
        case 'user_info':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getUserInfo(), $rShowColumns, $rHideColumns));
            break;
        case 'get_lines':
            echo json_encode(APIWrapper::TableAPI('lines', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_mags':
            echo json_encode(APIWrapper::TableAPI('mags', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_enigmas':
            echo json_encode(APIWrapper::TableAPI('enigmas', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_users':
            echo json_encode(APIWrapper::TableAPI('reg_users', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_streams':
            echo json_encode(APIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_provider_streams':
            echo json_encode(APIWrapper::TableAPI('provider_streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_channels':
            $rData['created'] = true;
            echo json_encode(APIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_stations':
            echo json_encode(APIWrapper::TableAPI('radios', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_movies':
            echo json_encode(APIWrapper::TableAPI('movies', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_series_list':
            echo json_encode(APIWrapper::TableAPI('series', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_episodes':
            echo json_encode(APIWrapper::TableAPI('episodes', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'activity_logs':
            echo json_encode(APIWrapper::TableAPI('line_activity', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'live_connections':
            echo json_encode(APIWrapper::TableAPI('live_connections', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'credit_logs':
            echo json_encode(APIWrapper::TableAPI('credits_log', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'client_logs':
            echo json_encode(APIWrapper::TableAPI('client_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'user_logs':
            echo json_encode(APIWrapper::TableAPI('reg_user_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'stream_errors':
            echo json_encode(APIWrapper::TableAPI('stream_errors', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'watch_output':
            echo json_encode(APIWrapper::TableAPI('watch_output', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'system_logs':
            echo json_encode(APIWrapper::TableAPI('mysql_syslog', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'login_logs':
            echo json_encode(APIWrapper::TableAPI('login_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'restream_logs':
            echo json_encode(APIWrapper::TableAPI('restream_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'mag_events':
            echo json_encode(APIWrapper::TableAPI('mag_events', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
            break;
        case 'get_line':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getLine($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_line':
            echo json_encode(APIWrapper::createLine($rData));
            break;
        case 'edit_line':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editLine($rID, $rData));
            break;
        case 'delete_line':
            echo json_encode(APIWrapper::deleteLine($rData['id']));
            break;
        case 'disable_line':
            echo json_encode(APIWrapper::disableLine($rData['id']));
            break;
        case 'enable_line':
            echo json_encode(APIWrapper::enableLine($rData['id']));
            break;
        case 'unban_line':
            echo json_encode(APIWrapper::unbanLine($rData['id']));
            break;
        case 'ban_line':
            echo json_encode(APIWrapper::banLine($rData['id']));
            break;
        case 'get_user':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getUser($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_user':
            echo json_encode(APIWrapper::createUser($rData));
            break;
        case 'edit_user':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editUser($rID, $rData));
            break;
        case 'delete_user':
            echo json_encode(APIWrapper::deleteUser($rData['id']));
            break;
        case 'disable_user':
            echo json_encode(APIWrapper::disableUser($rData['id']));
            break;
        case 'enable_user':
            echo json_encode(APIWrapper::enableUser($rData['id']));
            break;
        case 'get_mag':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getMAG($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_mag':
            echo json_encode(APIWrapper::createMAG($rData));
            break;
        case 'edit_mag':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editMAG($rID, $rData));
            break;
        case 'delete_mag':
            echo json_encode(APIWrapper::deleteMAG($rData['id']));
            break;
        case 'disable_mag':
            echo json_encode(APIWrapper::disableMAG($rData['id']));
            break;
        case 'enable_mag':
            echo json_encode(APIWrapper::enableMAG($rData['id']));
            break;
        case 'unban_mag':
            echo json_encode(APIWrapper::unbanMAG($rData['id']));
            break;
        case 'ban_mag':
            echo json_encode(APIWrapper::banMAG($rData['id']));
            break;
        case 'convert_mag':
            echo json_encode(APIWrapper::convertMAG($rData['id']));
            break;
        case 'get_enigma':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getEnigma($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_enigma':
            echo json_encode(APIWrapper::createEnigma($rData));
            break;
        case 'edit_enigma':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editEnigma($rID, $rData));
            break;
        case 'delete_enigma':
            echo json_encode(APIWrapper::deleteEnigma($rData['id']));
            break;
        case 'disable_enigma':
            echo json_encode(APIWrapper::disableEnigma($rData['id']));
            break;
        case 'enable_enigma':
            echo json_encode(APIWrapper::enableEnigma($rData['id']));
            break;
        case 'unban_enigma':
            echo json_encode(APIWrapper::unbanEnigma($rData['id']));
            break;
        case 'ban_enigma':
            echo json_encode(APIWrapper::banEnigma($rData['id']));
            break;
        case 'convert_enigma':
            echo json_encode(APIWrapper::convertEnigma($rData['id']));
            break;
        case 'get_bouquets':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getBouquets(), $rShowColumns, $rHideColumns));
            break;
        case 'get_bouquet':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getBouquet($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_bouquet':
            echo json_encode(APIWrapper::createBouquet($rData));
            break;
        case 'edit_bouquet':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editBouquet($rID, $rData));
            break;
        case 'delete_bouquet':
            echo json_encode(APIWrapper::deleteBouquet($rData['id']));
            break;
        case 'get_access_codes':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getAccessCodes(), $rShowColumns, $rHideColumns));
            break;
        case 'get_access_code':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getAccessCode($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_access_code':
            echo json_encode(APIWrapper::createAccessCode($rData));
            break;
        case 'edit_access_code':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editAccessCode($rID, $rData));
            break;
        case 'delete_access_code':
            echo json_encode(APIWrapper::deleteAccessCode($rData['id']));
            break;
        case 'get_hmacs':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getHMACs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_hmac':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getHMAC($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_hmac':
            echo json_encode(APIWrapper::createHMAC($rData));
            break;
        case 'edit_hmac':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editHMAC($rID, $rData));
            break;
        case 'delete_hmac':
            echo json_encode(APIWrapper::deleteHMAC($rData['id']));
            break;
        case 'get_epgs':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getEPGs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_epg':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getEPG($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_epg':
            echo json_encode(APIWrapper::createEPG($rData));
            break;
        case 'edit_epg':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editEPG($rID, $rData));
            break;
        case 'delete_epg':
            echo json_encode(APIWrapper::deleteEPG($rData['id']));
            break;
        case 'reload_epg':
            echo json_encode(APIWrapper::reloadEPG((isset($rData['id']) ? intval($rData['id']) : null)));
            break;
        case 'get_providers':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getProviders(), $rShowColumns, $rHideColumns));
            break;
        case 'get_provider':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getProvider($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_provider':
            echo json_encode(APIWrapper::createProvider($rData));
            break;
        case 'edit_provider':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editProvider($rID, $rData));
            break;
        case 'delete_provider':
            echo json_encode(APIWrapper::deleteProvider($rData['id']));
            break;
        case 'reload_provider':
            echo json_encode(APIWrapper::reloadProvider((isset($rData['id']) ? intval($rData['id']) : null)));
            break;
        case 'get_groups':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getGroups(), $rShowColumns, $rHideColumns));
            break;
        case 'get_group':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getGroup($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_group':
            echo json_encode(APIWrapper::createGroup($rData));
            break;
        case 'edit_group':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editGroup($rID, $rData));
            break;
        case 'delete_group':
            echo json_encode(APIWrapper::deleteGroup($rData['id']));
            break;
        case 'get_packages':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getPackages(), $rShowColumns, $rHideColumns));
            break;
        case 'get_package':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getPackage($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_package':
            echo json_encode(APIWrapper::createPackage($rData));
            break;
        case 'edit_package':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editPackage($rID, $rData));
            break;
        case 'delete_package':
            echo json_encode(APIWrapper::deletePackage($rData['id']));
            break;
        case 'get_transcode_profiles':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getTranscodeProfiles(), $rShowColumns, $rHideColumns));
            break;
        case 'get_transcode_profile':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getTranscodeProfile($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_transcode_profile':
            echo json_encode(APIWrapper::createTranscodeProfile($rData));
            break;
        case 'edit_transcode_profile':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editTranscodeProfile($rID, $rData));
            break;
        case 'delete_transcode_profile':
            echo json_encode(APIWrapper::deleteTranscodeProfile($rData['id']));
            break;
        case 'get_rtmp_ips':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getRTMPIPs(), $rShowColumns, $rHideColumns));
            break;
        case 'get_rtmp_ip':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getRTMPIP($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_rtmp_ip':
            echo json_encode(APIWrapper::addRTMPIP($rData));
            break;
        case 'edit_rtmp_ip':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editRTMPIP($rID, $rData));
            break;
        case 'delete_rtmp_ip':
            echo json_encode(APIWrapper::deleteRTMPIP($rData['id']));
            break;
        case 'get_categories':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getCategories(), $rShowColumns, $rHideColumns));
            break;
        case 'get_category':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getCategory($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_category':
            echo json_encode(APIWrapper::createCategory($rData));
            break;
        case 'edit_category':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editCategory($rID, $rData));
            break;
        case 'delete_category':
            echo json_encode(APIWrapper::deleteCategory($rData['id']));
            break;
        case 'get_watch_folders':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getWatchFolders(), $rShowColumns, $rHideColumns));
            break;
        case 'get_watch_folder':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getWatchFolder($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_watch_folder':
            echo json_encode(APIWrapper::createWatchFolder($rData));
            break;
        case 'edit_watch_folder':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editWatchFolder($rID, $rData));
            break;
        case 'delete_watch_folder':
            echo json_encode(APIWrapper::deleteWatchFolder($rData['id']));
            break;
        case 'reload_watch_folder':
            echo json_encode(APIWrapper::reloadWatchFolder((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['id']));
            break;
        case 'get_blocked_isps':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getBlockedISPs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_isp':
            echo json_encode(APIWrapper::addBlockedISP($rData['id']));
            break;
        case 'delete_blocked_isp':
            echo json_encode(APIWrapper::deleteBlockedISP($rData['id']));
            break;
        case 'get_blocked_uas':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getBlockedUAs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_ua':
            echo json_encode(APIWrapper::addBlockedUA($rData));
            break;
        case 'delete_blocked_ua':
            echo json_encode(APIWrapper::deleteBlockedUA($rData['id']));
            break;
        case 'get_blocked_ips':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getBlockedIPs(), $rShowColumns, $rHideColumns));
            break;
        case 'add_blocked_ip':
            echo json_encode(APIWrapper::addBlockedIP($rData['id']));
            break;
        case 'delete_blocked_ip':
            echo json_encode(APIWrapper::deleteBlockedIP($rData['id']));
            break;
        case 'flush_blocked_ips':
            echo json_encode(APIWrapper::flushBlockedIPs());
            break;
        case 'get_stream':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getStream($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_stream':
            echo json_encode(APIWrapper::createStream($rData));
            break;
        case 'edit_stream':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editStream($rID, $rData));
            break;
        case 'delete_stream':
            echo json_encode(APIWrapper::deleteStream($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'start_station':
        case 'start_channel':
        case 'start_stream':
            echo json_encode(APIWrapper::startStream($rData['id'], $rData['server_id']));
            break;
        case 'stop_station':
        case 'stop_channel':
        case 'stop_stream':
            echo json_encode(APIWrapper::stopStream($rData['id'], $rData['server_id']));
            break;
        case 'get_channel':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getChannel($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_channel':
            echo json_encode(APIWrapper::createChannel($rData));
            break;
        case 'edit_channel':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editChannel($rID, $rData));
            break;
        case 'delete_channel':
            echo json_encode(APIWrapper::deleteChannel($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_station':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getStation($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_station':
            echo json_encode(APIWrapper::createStation($rData));
            break;
        case 'edit_station':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editStation($rID, $rData));
            break;
        case 'delete_station':
            echo json_encode(APIWrapper::deleteStation($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_movie':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getMovie($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_movie':
            echo json_encode(APIWrapper::createMovie($rData));
            break;
        case 'edit_movie':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editMovie($rID, $rData));
            break;
        case 'delete_movie':
            echo json_encode(APIWrapper::deleteMovie($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'start_episode':
        case 'start_movie':
            echo json_encode(APIWrapper::startMovie($rData['id'], $rData['server_id']));
            break;
        case 'stop_episode':
        case 'stop_movie':
            echo json_encode(APIWrapper::stopMovie($rData['id'], $rData['server_id']));
            break;
        case 'get_episode':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getEpisode($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_episode':
            echo json_encode(APIWrapper::createEpisode($rData));
            break;
        case 'edit_episode':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editEpisode($rID, $rData));
            break;
        case 'delete_episode':
            echo json_encode(APIWrapper::deleteEpisode($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
            break;
        case 'get_series':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getSeries($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'create_series':
            echo json_encode(APIWrapper::createSeries($rData));
            break;
        case 'edit_series':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editSeries($rID, $rData));
            break;
        case 'delete_series':
            echo json_encode(APIWrapper::deleteSeries($rData['id']));
            break;
        case 'get_servers':
            echo json_encode(APIWrapper::filterRows(APIWrapper::getServers(), $rShowColumns, $rHideColumns));
            break;
        case 'get_server':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getServer($rData['id']), $rShowColumns, $rHideColumns));
            break;
        case 'install_server':
            $rData['type'] = 0;
            echo json_encode(APIWrapper::installServer($rData));
            break;
        case 'install_proxy':
            $rData['type'] = 1;
            echo json_encode(APIWrapper::installServer($rData));
            break;
        case 'edit_server':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editServer($rID, $rData));
            break;
        case 'edit_proxy':
            $rID = $rData['id'];
            unset($rData['id']);
            echo json_encode(APIWrapper::editProxy($rID, $rData));
            break;
        case 'delete_server':
            echo json_encode(APIWrapper::deleteServer($rData['id']));
            break;
        case 'get_settings':
            echo json_encode(APIWrapper::filterRow(APIWrapper::getSettings(), $rShowColumns, $rHideColumns));
            break;
        case 'edit_settings':
            echo json_encode(APIWrapper::editSettings($rData));
            break;
        case 'get_server_stats':
            echo json_encode(APIWrapper::getStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_fpm_status':
            echo json_encode(APIWrapper::getFPMStatus((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_rtmp_stats':
            echo json_encode(APIWrapper::getRTMPStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_free_space':
            echo json_encode(APIWrapper::getFreeSpace((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_pids':
            echo json_encode(APIWrapper::getPIDs((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_certificate_info':
            echo json_encode(APIWrapper::getCertificateInfo((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'reload_nginx':
            echo json_encode(APIWrapper::reloadNGINX((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'clear_temp':
            echo json_encode(APIWrapper::clearTemp((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'clear_streams':
            echo json_encode(APIWrapper::clearStreams((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
            break;
        case 'get_directory':
            echo json_encode(APIWrapper::getDirectory((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['dir']));
            break;
        case 'kill_pid':
            echo json_encode(APIWrapper::killPID((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['pid']));
            break;
        case 'kill_connection':
            echo json_encode(APIWrapper::killConnection((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['activity_id']));
            break;
        case 'adjust_credits':
            echo json_encode(APIWrapper::adjustCredits($rData['id'], $rData['credits'], (isset($rData['reason']) ? $rData['reason'] : '')));
            break;
        case 'reload_cache':
            echo json_encode(APIWrapper::reloadCache());
            break;
        default:
            echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid action.'));
            break;
    }
} else {
    echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.'));
}
class APIWrapper {
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
        $rTableAPI = 'http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/' . trim(dirname($_SERVER['PHP_SELF']), '/') . '/table.php';
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
        API::$db = &self::$db;
        API::init(self::$db->get_row()['id']);
        unset(API::$rUserInfo['password']);
        $rUserInfo = API::$rUserInfo;
        $rPermissions = getPermissions($rUserInfo['member_group_id']);
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
        if (!($rLine = getUser($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rLine);
    }
    public static function createLine($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processLine($rData));
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
        $rReturn = parseerror(API::processLine($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteLine($rID) {
        if (!(($rLine = self::getLine($rID)) && isset($rLine['data']))) {
        } else {
            if (!deleteLine($rID)) {
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
        if (!($rUser = getRegisteredUser($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rUser);
    }
    public static function createUser($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processUser($rData));
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
        $rReturn = parseerror(API::processUser($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteUser($rID) {
        if (!(($rUser = self::getUser($rID)) && isset($rUser['data']))) {
        } else {
            if (!deleteUser($rID)) {
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
        if (!($rDevice = getMag($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
    }
    public static function createMAG($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processMAG($rData));
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
        $rReturn = parseerror(API::processMAG($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteMAG($rID) {
        if (!(($rDevice = self::getMAG($rID)) && isset($rDevice['data']))) {
        } else {
            if (!deleteMAG($rID)) {
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
        deleteMAG($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['user_id']));
    }
    public static function getEnigma($rID) {
        if (!($rDevice = getEnigma($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rDevice);
    }
    public static function createEnigma($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processEnigma($rData));
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
        $rReturn = parseerror(API::processEnigma($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEnigma($rID) {
        if (!(($rDevice = self::getEnigma($rID)) && isset($rDevice['data']))) {
        } else {
            if (!deleteEnigma($rID)) {
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
        deleteEnigma($rID, false, false, true);
        return array('status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['user_id']));
    }
    public static function getBouquets() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getBouquets());
    }
    public static function getBouquet($rID) {
        if (!($rBouquet = getBouquet($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rBouquet);
    }
    public static function createBouquet($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processBouquet($rData));
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
        $rReturn = parseerror(API::processBouquet($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getBouquet($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteBouquet($rID) {
        if (!(($rBouquet = self::getBouquet($rID)) && isset($rBouquet['data']))) {
        } else {
            if (!deleteBouquet($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getAccessCodes() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getcodes());
    }
    public static function getAccessCode($rID) {
        if (!($rCode = getCode($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rCode);
    }
    public static function createAccessCode($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processCode($rData));
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
        $rReturn = parseerror(API::processCode($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getAccessCode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteAccessCode($rID) {
        if (!(($rCode = self::getAccessCode($rID)) && isset($rCode['data']))) {
        } else {
            if (!removeAccessEntry($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getHMACs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getHMACTokens());
    }
    public static function getHMAC($rID) {
        if (!($rToken = getHMACToken($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rToken);
    }
    public static function createHMAC($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processHMAC($rData));
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
        $rReturn = parseerror(API::processHMAC($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getHMAC($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteHMAC($rID) {
        if (!(($rToken = self::getHMAC($rID)) && isset($rToken['data']))) {
        } else {
            if (!validateHMAC($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getEPGs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getEPGs());
    }
    public static function getEPG($rID) {
        if (!($rEPG = getEPG($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rEPG);
    }
    public static function createEPG($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processEPG($rData));
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
        $rReturn = parseerror(API::processEPG($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEPG($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEPG($rID) {
        if (!(($rEPG = self::getEPG($rID)) && isset($rEPG['data']))) {
        } else {
            if (!deleteEPG($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadEPG($rID = null) {
        if ($rID) {
            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'epg.php "' . intval($rID) . '" > /dev/null 2>/dev/null &');
        } else {
            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'epg.php > /dev/null 2>/dev/null &');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getProviders() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getStreamProviders());
    }
    public static function getProvider($rID) {
        if (!($rProvider = getStreamProvider($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rProvider);
    }
    public static function createProvider($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processProvider($rData));
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
        $rReturn = parseerror(API::processProvider($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getProvider($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteProvider($rID) {
        if (!(($rProvider = self::getProvider($rID)) && isset($rProvider['data']))) {
        } else {
            if (!deleteProvider($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadProvider($rID = null) {
        if ($rID) {
            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'providers.php "' . intval($rID) . '" > /dev/null 2>/dev/null &');
        } else {
            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'providers.php > /dev/null 2>/dev/null &');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getGroups() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getMemberGroups());
    }
    public static function getGroup($rID) {
        if (!($rGroup = getMemberGroup($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rGroup);
    }
    public static function createGroup($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processGroup($rData));
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
        $rReturn = parseerror(API::processGroup($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getGroup($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteGroup($rID) {
        if (!(($rGroup = self::getGroup($rID)) && isset($rGroup['data']))) {
        } else {
            if (!deleteGroup($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getPackages() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getPackages());
    }
    public static function getPackage($rID) {
        if (!($rPackage = getPackage($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rPackage);
    }
    public static function createPackage($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processPackage($rData));
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
        $rReturn = parseerror(API::processPackage($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getPackage($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deletePackage($rID) {
        if (!(($rPackage = self::getPackage($rID)) && isset($rPackage['data']))) {
        } else {
            if (!deletePackage($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getTranscodeProfiles() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getTranscodeProfiles());
    }
    public static function getTranscodeProfile($rID) {
        if (!($rProfile = getTranscodeProfile($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rProfile);
    }
    public static function createTranscodeProfile($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processProfile($rData));
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
        $rReturn = parseerror(API::processProfile($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getTranscodeProfile($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteTranscodeProfile($rID) {
        if (!(($rProfile = self::getTranscodeProfile($rID)) && isset($rProfile['data']))) {
        } else {
            if (!deleteProfile($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getRTMPIPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getRTMPIPs());
    }
    public static function getRTMPIP($rID) {
        if (!($rIP = getRTMPIP($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rIP);
    }
    public static function addRTMPIP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processRTMPIP($rData));
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
        $rReturn = parseerror(API::processRTMPIP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getRTMPIP($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteRTMPIP($rID) {
        if (!(($rIP = self::getRTMPIP($rID)) && isset($rIP['data']))) {
        } else {
            if (!deleteRTMPIP($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getCategories() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getCategories());
    }
    public static function getCategory($rID) {
        if (!($rCategory = getCategory($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rCategory);
    }
    public static function createCategory($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processCategory($rData));
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
        $rReturn = parseerror(API::processCategory($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getCategory($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteCategory($rID) {
        if (!(($rCategory = self::getCategory($rID)) && isset($rCategory['data']))) {
        } else {
            if (!deleteCategory($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getWatchFolders() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getWatchFolders());
    }
    public static function getWatchFolder($rID) {
        if (!($rFolder = getWatchFolder($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rFolder);
    }
    public static function createWatchFolder($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processWatchFolder($rData));
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
        $rReturn = parseerror(API::processWatchFolder($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getWatchFolder($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteWatchFolder($rID) {
        if (!(($rFolder = self::getWatchFolder($rID)) && isset($rFolder['data']))) {
        } else {
            if (!deleteWatchFolder($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function reloadWatchFolder($rServerID, $rID) {
        forceWatch($rServerID, $rID);
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedISPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getISPs());
    }
    public static function addBlockedISP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processISP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedISP($rID) {
        if (!rdeleteBlockedISP($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedUAs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getUserAgents());
    }
    public static function addBlockedUA($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processUA($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedUA($rID) {
        if (!rdeleteBlockedUA($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getBlockedIPs() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getBlockedIPs());
    }
    public static function addBlockedIP($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::blockIP($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = $rReturn['data']['insert_id'];
        }
        return $rReturn;
    }
    public static function deleteBlockedIP($rID) {
        if (!rdeleteBlockedIP($rID)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function flushBlockedIPs() {
        flushIPs();
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getStream($rID) {
        if (!(($rStream = getStream($rID)) && $rStream['type'] == 1)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createStream($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processStream($rData));
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
        $rReturn = parseerror(API::processStream($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStream($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteStream($rID, $rServerID = -1) {
        if (!(($rStream = self::getStream($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function startStream($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array($rID), 'servers' => array_keys(CoreUtilities::$rServers))), true);
        } else {
            $rData = json_decode(systemapirequest($rServerID, array('action' => 'stream', 'stream_ids' => array($rID), 'function' => 'start')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function stopStream($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => array($rID), 'servers' => array_keys(CoreUtilities::$rServers))), true);
        } else {
            $rData = json_decode(systemapirequest($rServerID, array('action' => 'stream', 'stream_ids' => array($rID), 'function' => 'stop')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getChannel($rID) {
        if (!(($rStream = getStream($rID)) && $rStream['type'] == 3)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createChannel($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processChannel($rData));
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
        $rReturn = parseerror(API::processChannel($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getChannel($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteChannel($rID, $rServerID = -1) {
        if (!(($rStream = self::getChannel($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getStation($rID) {
        if (!(($rStream = getStream($rID)) && $rStream['type'] == 4)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createStation($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processRadio($rData));
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
        $rReturn = parseerror(API::processRadio($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getStation($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteStation($rID, $rServerID = -1) {
        if (!(($rStream = self::getStation($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getMovie($rID) {
        if (!(($rStream = getStream($rID)) && $rStream['type'] == 2)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createMovie($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processMovie($rData));
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
        $rReturn = parseerror(API::processMovie($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getMovie($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteMovie($rID, $rServerID = -1) {
        if (!(($rStream = self::getMovie($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function startMovie($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(APIRequest(array('action' => 'vod', 'sub' => 'start', 'stream_ids' => array($rID), 'servers' => array_keys(CoreUtilities::$rServers))), true);
        } else {
            $rData = json_decode(systemapirequest($rServerID, array('action' => 'vod', 'stream_ids' => array($rID), 'function' => 'start')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function stopMovie($rID, $rServerID = -1) {
        if ($rServerID == -1) {
            $rData = json_decode(APIRequest(array('action' => 'vod', 'sub' => 'stop', 'stream_ids' => array($rID), 'servers' => array_keys(CoreUtilities::$rServers))), true);
        } else {
            $rData = json_decode(systemapirequest($rServerID, array('action' => 'vod', 'stream_ids' => array($rID), 'function' => 'stop')), true);
        }
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getEpisode($rID) {
        if (!(($rStream = getStream($rID)) && $rStream['type'] == 5)) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rStream);
    }
    public static function createEpisode($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processEpisode($rData));
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
        $rReturn = parseerror(API::processEpisode($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getEpisode($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteEpisode($rID, $rServerID = -1) {
        if (!(($rStream = self::getEpisode($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteStream($rID, $rServerID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getSeries($rID) {
        if (!($rSeries = getSerie($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rSeries);
    }
    public static function createSeries($rData) {
        if (!isset($rData['edit'])) {
        } else {
            unset($rData['edit']);
        }
        $rReturn = parseerror(API::processSeries($rData));
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
        $rReturn = parseerror(API::processSeries($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getSeries($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteSeries($rID) {
        if (!(($rStream = self::getSeries($rID)) && isset($rStream['data']))) {
        } else {
            if (!deleteSeries($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getServers() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getStreamingServers());
    }
    public static function getServer($rID) {
        if (!($rServer = getStreamingServersByID($rID))) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rServer);
    }
    public static function installServer($rData) {
        if (!(empty($rData['type']) || empty($rData['ssh_port']) || empty($rData['root_username']) || empty($rData['root_password']))) {
            if (!($rData['type'] == 1 && (empty($rData['type']) || empty($rData['ssh_port'])))) {
                $rReturn = parseerror(API::installServer($rData));
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
            $rReturn = parseerror(API::processServer($rData));
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
        $rReturn = parseerror(API::processProxy($rData));
        if (!isset($rReturn['data']['insert_id'])) {
        } else {
            $rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'];
        }
        return $rReturn;
    }
    public static function deleteServer($rID) {
        if (!(($rServer = self::getServer($rID)) && isset($rServer['data']))) {
        } else {
            if (!deleteServer($rID)) {
            } else {
                return array('status' => 'STATUS_SUCCESS');
            }
        }
        return array('status' => 'STATUS_FAILURE');
    }
    public static function getSettings() {
        return array('status' => 'STATUS_SUCCESS', 'data' => getSettings());
    }
    public static function editSettings($rData) {
        $rReturn = parseerror(API::editSettings($rData));
        $rReturn['data'] = self::getSettings()['data'];
        return $rReturn;
    }
    public static function getStats($rServerID) {
        global $db;
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'stats')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        $rData['requests_per_second'] = CoreUtilities::$rServers[$rServerID]['requests_per_second'];
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
        $rData['network_guaranteed_speed'] = CoreUtilities::$rServers[$rServerID]['network_guaranteed_speed'];
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getFPMStatus($rServerID) {
        $rData = systemapirequest($rServerID, array('action' => 'fpm_status'));
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getRTMPStats($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'rtmp_stats')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getFreeSpace($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'get_free_space')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getPIDs($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'get_pids')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function getCertificateInfo($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'get_certificate_info')), true);
        if (!$rData) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS', 'data' => $rData);
    }
    public static function reloadNGINX($rServerID) {
        systemapirequest($rServerID, array('action' => 'reload_nginx'));
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function clearTemp($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'free_temp')), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function clearStreams($rServerID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'free_streams')), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function getDirectory($rServerID, $rDirectory) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'scandir', 'dir' => $rDirectory)), true);
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
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'kill_pid', 'pid' => intval($rPID))), true);
        if (!$rData['result']) {
            return array('status' => 'STATUS_FAILURE');
        }
        return array('status' => 'STATUS_SUCCESS');
    }
    public static function killConnection($rServerID, $rActivityID) {
        $rData = json_decode(systemapirequest($rServerID, array('action' => 'closeConnection', 'activity_id' => intval($rActivityID))), true);
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
        shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php > /dev/null 2>/dev/null &');
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
