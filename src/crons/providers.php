<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        $rProviderID = null;
        if (1 >= count($argv)) {
        } else {
            $rProviderID = intval($argv[1]);
        }
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Providers]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rTimeout = 300;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function readURL($rURL) {
    $rContext = stream_context_create(array('http' => array('timeout' => 30)));
    return json_decode(file_get_contents($rURL, false, $rContext), true);
}
function loadCron() {
    global $db;
    global $rProviderID;
    if ($rProviderID) {
        $db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` LIKE ?;', $rProviderID . '_%');
    } else {
        $db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` IS NOT NULL;');
    }
    $rSyncTitle = array();
    foreach ($db->get_rows() as $rRow) {
        list($rSyncID, $rSyncStream) = array_map('intval', explode('_', $rRow['title_sync']));
        if (isset($rSyncTitle[$rSyncID])) {
        } else {
            $rSyncTitle[$rSyncID] = array();
        }
        $rSyncTitle[$rSyncID][$rSyncStream] = array($rRow['id'], $rRow['stream_display_name']);
    }
    if ($rProviderID) {
        $db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rProviderID);
    } else {
        $db->query('SELECT * FROM `providers` WHERE `enabled` = 1;');
    }
    foreach ($db->get_rows() as $rRow) {
        $rArray = array();
        $rURL = (($rRow['ssl'] ? 'https' : 'http')) . '://' . $rRow['ip'] . ':' . $rRow['port'] . '/';
        if ($rRow['legacy']) {
            $rURL .= 'player_api.php?username=' . $rRow['username'] . '&password=' . $rRow['password'];
        } else {
            $rURL .= 'player_api/' . $rRow['username'] . '/' . $rRow['password'] . '?connections=1';
        }
        $rInfo = readurl($rURL);
        if ($rInfo) {
            $rStatus = 1;
            $rArray['max_connections'] = $rInfo['user_info']['max_connections'];
            $rArray['active_connections'] = $rInfo['user_info']['active_cons'];
            $rArray['exp_date'] = $rInfo['user_info']['exp_date'];
        } else {
            $rStatus = 0;
            $rArray['exp_date'] = ($rRow['exp_date'] ?: -1);
        }
        $rCategories = array();
        $rCategoriesURL = $rURL . '&action=get_live_categories';
        foreach (readurl($rCategoriesURL) as $rCategory) {
            $rCategories[$rCategory['category_id']] = $rCategory['category_name'];
        }
        $rCategoriesURL = $rURL . '&action=get_vod_categories';
        foreach (readurl($rCategoriesURL) as $rCategory) {
            $rCategories[$rCategory['category_id']] = $rCategory['category_name'];
        }
        $rStreamsURL = $rURL . '&action=get_live_streams';
        $rStreams = readurl($rStreamsURL);
        $rArray['streams'] = count($rStreams);
        $rVODURL = $rURL . '&action=get_vod_streams';
        $rVOD = readurl($rVODURL);
        $rArray['movies'] = count($rVOD);
        $rSeriesURL = $rURL . '&action=get_series';
        $rSeries = readurl($rSeriesURL);
        $rArray['series'] = count($rSeries);
        $rLastChanged = time();
        $db->query('UPDATE `providers` SET `data` = ?, `last_changed` = ?, `status` = ? WHERE `id` = ?;', json_encode($rArray), $rLastChanged, $rStatus, $rRow['id']);
        $db->query('SELECT `type`, `stream_id`, `category_id`, `stream_display_name`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ?;', $rRow['id']);
        $rNewIDs = $rExistingIDs = array();
        foreach ($db->get_rows() as $rStream) {
            $rExistingIDs[$rStream['stream_id']] = md5($rStream['category_id'] . '_' . (($rStream['stream_display_name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . (($rStream['channel_id'] ?: '')));
        }
        $rTime = time();
        foreach (array('live' => $rStreams, 'movie' => $rVOD) as $rType => $rSelection) {
            foreach ($rSelection as $rStream) {
                $rNewIDs[] = $rStream['stream_id'];
                $rCategoryIDs = (isset($rStream['category_ids']) ? (is_array($rStream['category_ids']) ? $rStream['category_ids'] : array()) : array($rStream['category_id']));
                $rCategoryArray = array();
                foreach ($rCategoryIDs as $rCategoryID) {
                    $rCategoryArray[] = $rCategories[$rCategoryID];
                }
                $rCategoryIDs = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                if (isset($rExistingIDs[$rStream['stream_id']])) {
                    $rUUID = $rExistingIDs[$rStream['stream_id']];
                    if (md5($rCategoryIDs . '_' . (($rStream['name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . ((($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']) ?: ''))) == $rUUID) {
                    } else {
                        $db->query('UPDATE `providers_streams` SET `category_id` = ?, `category_array` = ?, `stream_display_name` = ?, `stream_icon` = ?, `channel_id` = ?, `modified` = ? WHERE `provider_id` = ? AND `stream_id` = ?;', $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], ($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']), $rTime, $rRow['id'], $rStream['stream_id']);
                    }
                } else {
                    $db->query('INSERT INTO `providers_streams`(`provider_id`, `type`, `stream_id`, `category_id`, `category_array`, `stream_display_name`, `stream_icon`, `channel_id`, `added`, `modified`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rRow['id'], $rType, $rStream['stream_id'], $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], ($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']), $rTime, $rTime);
                }
                if (!($rType == 'live' && isset($rSyncTitle[$rRow['id']][$rStream['stream_id']]))) {
                } else {
                    if ($rStream['name'] == $rSyncTitle[$rRow['id']][$rStream['stream_id']][1]) {
                    } else {
                        $db->query('UPDATE `streams` SET `stream_display_name` = ? WHERE `id` = ?;', $rStream['name'], $rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
                        CoreUtilities::updateStream($rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
                    }
                }
            }
        }
        $rDelete = array();
        foreach (array_keys($rExistingIDs) as $rStreamID) {
            if (in_array($rStreamID, $rNewIDs)) {
            } else {
                $rDelete[] = $rStreamID;
            }
        }
        if (0 >= count($rDelete)) {
        } else {
            $db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rDelete)) . ');', $rRow['id']);
        }
    }
}
function shutdown() {
    global $db;
    global $rIdentifier;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
