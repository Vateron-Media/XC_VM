<?php
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(30711);
        $rStreamDatabase = (json_decode(file_get_contents(WATCH_TMP_PATH . 'stream_database.pcache'), true) ?: array());
        $rThreadData = json_decode(base64_decode($argv[1]), true);
        if ($rThreadData) {
            file_put_contents(WATCH_TMP_PATH . getmypid() . '.ppid', time());
            if ($rThreadData['type'] == 'movie') {
                $rTimeout = 60;
            } else {
                $rTimeout = 600;
            }
            set_time_limit($rTimeout);
            ini_set('max_execution_time', $rTimeout);
            loadcli();
        } else {
            exit();
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function preparecolumn($rValue) {
    return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
}
function prepareArray($rArray) {
    $rUpdate = $rColumns = $rPlaceholder = $rData = array();
    foreach (array_keys($rArray) as $rKey) {
        $rColumns[] = '`' . preparecolumn($rKey) . '`';
        $rUpdate[] = '`' . preparecolumn($rKey) . '` = ?';
    }
    foreach (array_values($rArray) as $rValue) {
        if (!is_array($rValue)) {
        } else {
            $rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
        }
        $rPlaceholder[] = '?';
        $rData[] = $rValue;
    }
    return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $rUpdate));
}
function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
    global $db;
    $rReturn = array();
    $db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);
    foreach ($db->get_rows() as $rRow) {
        if ($rRow['column_default'] != 'NULL') {
        } else {
            $rRow['column_default'] = null;
        }
        $rForceDefault = false;
        if ($rRow['is_nullable'] != 'NO' || $rRow['column_default']) {
        } else {
            if (in_array($rRow['data_type'], array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'))) {
                $rRow['column_default'] = 0;
            } else {
                $rRow['column_default'] = '';
            }
            $rForceDefault = true;
        }
        if (array_key_exists($rRow['column_name'], $rData)) {
            if (empty($rData[$rRow['column_name']]) && !is_numeric($rData[$rRow['column_name']]) && is_null($rRow['column_default'])) {
                $rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
            } else {
                $rReturn[$rRow['column_name']] = $rData[$rRow['column_name']];
            }
        } else {
            if ($rOnlyExisting) {
            } else {
                $rReturn[$rRow['column_name']] = $rRow['column_default'];
            }
        }
    }
    return $rReturn;
}
function getSeriesByID($rPlexID, $rTMDBID) {
    global $db;
    if (!(file_exists(WATCH_TMP_PATH . 'series_' . $rPlexID . '.data') && time() - filemtime(WATCH_TMP_PATH . 'series_' . $rPlexID . '.data') < 360)) {
        if (!(file_exists(WATCH_TMP_PATH . 'series_' . intval($rTMDBID) . '.data') && time() - filemtime(WATCH_TMP_PATH . 'series_' . intval($rTMDBID) . '.data') < 360)) {
            $db->query('SELECT * FROM `streams_series` WHERE `plex_uuid` = ? OR `tmdb_id` = ?;', $rPlexID, $rTMDBID);
            if ($db->num_rows() != 1) {
            } else {
                return $db->get_row();
            }
        } else {
            return json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . intval($rTMDBID) . '.data'), true);
        }
    } else {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rPlexID . '.data'), true);
    }
}
function getSerie($rID) {
    global $db;
    $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
    if ($db->num_rows() != 1) {
    } else {
        return $db->get_row();
    }
}
function getNextOrder() {
    global $db;
    $db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');
    if ($db->num_rows() != 1) {
        return 0;
    }
    return intval($db->get_row()['order']) + 1;
}
function addToBouquet($rType, $rBouquetID, $rID) {
    global $rThreadData;
    file_put_contents(WATCH_TMP_PATH . md5($rThreadData['uuid'] . '_' . $rThreadData['key'] . '_' . $rType . '_' . $rBouquetID . '_' . $rID) . '.pbouquet', json_encode(array('type' => $rType, 'bouquet_id' => $rBouquetID, 'id' => $rID)));
}
function loadcli() {
    global $db;
    global $rThreadData;
    global $rStreamDatabase;
    $rServers = array(SERVER_ID);
    if (empty($rThreadData['server_add'])) {
    } else {
        foreach (json_decode($rThreadData['server_add'], true) as $rServerID) {
            $rServers[] = intval($rServerID);
        }
    }
    $rBouquetIDs = $rCategoryIDs = array();
    if (0 >= $rThreadData['category_id']) {
    } else {
        $rCategoryIDs = array(intval($rThreadData['category_id']));
    }
    if (0 >= count(json_decode($rThreadData['bouquets'], true))) {
    } else {
        $rBouquetIDs = json_decode($rThreadData['bouquets'], true);
    }
    $rLanguage = null;
    $rPlexCategories = $rThreadData['plex_categories'];
    $rImportArray = verifyPostTable('streams');
    $rImportArray['type'] = array('movie' => 2, 'show' => 5)[$rThreadData['type']];
    if ($rImportArray['type']) {
        $rThreadType = array('movie' => 1, 'show' => 2)[$rThreadData['type']];
        switch ($rThreadData['type']) {
            case 'movie':
                $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '?X-Plex-Token=' . $rThreadData['token'];
                $rContent = json_decode(json_encode(simplexml_load_string(readURL($rURL))), true);
                if ($rContent) {
                    $rTMDBID = null;
                    $rFirstFile = null;
                    foreach (makeArray($rContent['Video']['Guid']) as $rGUID) {
                        if (substr($rGUID['@attributes']['id'], 0, 7) != 'tmdb://') {
                        } else {
                            $rTMDBID = intval(explode('tmdb://', $rGUID['@attributes']['id'])[1]);
                            echo 'TMDB ID: ' . $rTMDBID . "\n";
                            break;
                        }
                    }
                    $rFileArray = array('file' => null, 'size' => null, 'data' => null, 'key' => null);
                    foreach (makeArray($rContent['Video']['Media']) as $rMedia) {
                        if ($rFirstFile) {
                        } else {
                            $rFirstFile = $rMedia['Part']['@attributes']['file'];
                        }
                        if ($rFileArray['size'] && $rFileArray['size'] >= intval($rMedia['Part']['@attributes']['size'])) {
                        } else {
                            if (!(file_exists($rMedia['Part']['@attributes']['file']) || $rThreadData['direct_proxy'])) {
                            } else {
                                $rFileArray = array('file' => $rMedia['Part']['@attributes']['file'], 'size' => intval($rMedia['Part']['@attributes']['size']), 'data' => $rMedia, 'key' => $rMedia['Part']['@attributes']['key']);
                            }
                        }
                    }
                    if (!empty($rFileArray['file'])) {
                        $rInternalPath = json_encode(array('s:' . SERVER_ID . ':' . $rFileArray['file']), JSON_UNESCAPED_UNICODE);
                        $rDirectURL = json_encode(array('http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . $rFileArray['key'] . '?X-Plex-Token=' . $rThreadData['token']), JSON_UNESCAPED_UNICODE);
                        if (in_array($rInternalPath, $rStreamDatabase) || in_array($rDirectURL, $rStreamDatabase)) {
                        } else {
                            $rStreamDatabase[] = $rInternalPath;
                            $rStreamDatabase[] = $rDirectURL;
                            if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container'] && !$rThreadData['direct_proxy']) {
                                $rImportArray['target_container'] = $rThreadData['target_container'];
                            } else {
                                $rImportArray['target_container'] = pathinfo($rFileArray['file'])['extension'];
                            }
                            if (!empty($rImportArray['target_container'])) {
                            } else {
                                $rImportArray['target_container'] = 'mp4';
                            }
                            $db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', utf8_decode($rFileArray['file']), $rThreadType, SERVER_ID);
                            if (!$rContent['Video']['@attributes']['thumb']) {
                            } else {
                                $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rContent['Video']['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                                $rThumb = CoreUtilities::downloadImage($rThumbURL);
                            }
                            if (!$rContent['Video']['@attributes']['art']) {
                            } else {
                                $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $rContent['Video']['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                                $rBG = CoreUtilities::downloadImage($rBGURL);
                            }
                            $rCast = array();
                            foreach (array_slice(makeArray($rContent['Video']['Role']), 0, 5) as $rMember) {
                                $rCast[] = $rMember['@attributes']['tag'];
                            }
                            $rDirectors = array();
                            foreach (array_slice(makeArray($rContent['Video']['Director']), 0, 3) as $rMember) {
                                $rDirectors[] = $rMember['@attributes']['tag'];
                            }
                            $rGenres = array();
                            foreach (array_slice(makeArray($rContent['Video']['Genre']), 0, $rThreadData['max_genres']) as $rGenre) {
                                $rGenres[] = $rGenre['@attributes']['tag'];
                            }
                            $rCountry = (makeArray($rContent['Video']['Country'])[0]['@attributes']['tag'] ?: null);
                            $rSeconds = intval(intval($rContent['Video']['@attributes']['duration']) / 1000);
                            $rImportArray['stream_display_name'] = $rContent['Video']['@attributes']['title'];
                            if (!$rContent['Video']['@attributes']['year']) {
                            } else {
                                $rImportArray['year'] = intval($rContent['Video']['@attributes']['year']);
                            }
                            $rImportArray['tmdb_id'] = ($rTMDBID ?: null);
                            $rImportArray['movie_properties'] = array('kinopoisk_url' => ($rTMDBID ? 'https://www.themoviedb.org/movie/' . $rTMDBID : null), 'tmdb_id' => $rTMDBID, 'plex_id' => $rThreadData['key'], 'name' => $rContent['Video']['@attributes']['title'], 'o_name' => $rContent['Video']['@attributes']['title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rContent['Video']['@attributes']['originallyAvailableAt'], 'episode_run_time' => intval($rSeconds / 60), 'youtube_trailer' => null, 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => trim($rContent['Video']['@attributes']['summary']), 'plot' => $rContent['Video']['@attributes']['summary'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => array($rBG), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => (floatval($rContent['Video']['@attributes']['rating']) ?: floatval($rContent['Video']['@attributes']['audienceRating'])));
                            $rImportArray['rating'] = ((floatval($rContent['Video']['@attributes']['rating']) ?: floatval($rContent['Video']['@attributes']['audienceRating'])) ?: 0);
                            $rImportArray['read_native'] = $rThreadData['read_native'];
                            $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
                            $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
                            $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
                            if ($rThreadData['direct_proxy']) {
                                $rImportArray['stream_source'] = $rDirectURL;
                                $rImportArray['direct_source'] = 1;
                                $rImportArray['direct_proxy'] = 1;
                            } else {
                                $rImportArray['stream_source'] = $rInternalPath;
                                $rImportArray['direct_source'] = 0;
                                $rImportArray['direct_proxy'] = 0;
                            }
                            $rImportArray['order'] = getNextOrder();
                            $rImportArray['tmdb_language'] = $rLanguage;
                            if (count($rCategoryIDs) != 0) {
                            } else {
                                if (0 < $rThreadData['max_genres']) {
                                    $rParsed = array_slice(makeArray($rContent['Video']['Genre']), 0, $rThreadData['max_genres']);
                                } else {
                                    $rParsed = makeArray($rContent['Video']['Genre']);
                                }
                                foreach ($rParsed as $rGenre) {
                                    $rGenreTag = $rGenre['@attributes']['tag'];
                                    if (isset($rPlexCategories[3][$rGenreTag])) {
                                        $rCategoryID = intval($rPlexCategories[3][$rGenreTag]['category_id']);
                                        if (0 >= $rCategoryID) {
                                        } else {
                                            if (in_array($rCategoryID, $rCategoryIDs)) {
                                            } else {
                                                $rCategoryIDs[] = $rCategoryID;
                                            }
                                        }
                                    } else {
                                        if (!$rThreadData['store_categories']) {
                                        } else {
                                            addCategory($rThreadData['type'], $rGenreTag);
                                        }
                                    }
                                }
                            }
                            if (!(count($rCategoryIDs) == 0 && 0 < intval($rThreadData['fb_category_id']))) {
                            } else {
                                $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                            }
                            if (count($rBouquetIDs) != 0) {
                            } else {
                                if (0 < $rThreadData['max_genres']) {
                                    $rParsed = array_slice(makeArray($rContent['Video']['Genre']), 0, $rThreadData['max_genres']);
                                } else {
                                    $rParsed = makeArray($rContent['Video']['Genre']);
                                }
                                foreach ($rParsed as $rGenre) {
                                    $rGenreTag = $rGenre['@attributes']['tag'];
                                    $rBouquets = json_decode($rPlexCategories[3][$rGenreTag]['bouquets'], true);
                                    foreach ($rBouquets as $rBouquetID) {
                                        if (in_array($rBouquetID, $rBouquetIDs)) {
                                        } else {
                                            $rBouquetIDs[] = $rBouquetID;
                                        }
                                    }
                                }
                            }
                            if (count($rBouquetIDs) != 0) {
                            } else {
                                $rBouquetIDs = array_map('intval', json_decode($rThreadData['fb_bouquets'], true));
                            }
                            if (!$rYear) {
                            } else {
                                $rImportArray['year'] = $rYear;
                            }
                            $rImportArray['added'] = time();
                            $rImportArray['plex_uuid'] = $rThreadData['uuid'];
                            $rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                            if ($rUpgradeData = getMovie($rThreadData['uuid'], ($rThreadData['check_tmdb'] ? $rTMDBID : null))) {
                                if ($rUpgradeData['source'] != $rFileArray['file']) {
                                    if ($rThreadData['auto_upgrade']) {
                                        echo 'Upgrade movie!' . "\n";
                                        $rImportArray['id'] = $rUpgradeData['id'];
                                    } else {
                                        echo 'Upgrade disabled' . "\n";
                                        exit();
                                    }
                                } else {
                                    echo 'File remains unchanged' . "\n";
                                    exit();
                                }
                            } else {
                                if (count($rCategoryIDs) != 0) {
                                } else {
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 3, 0);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']));
                                    exit();
                                }
                            }
                            $rPrepare = prepareArray($rImportArray);
                            $rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                            if ($db->query($rQuery, ...$rPrepare['data'])) {
                                $rInsertID = $db->last_insert_id();
                                if ($rUpgradeData) {
                                    foreach ($rServers as $rServerID) {
                                        $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rInsertID, $rServerID);
                                    }
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']));
                                    if (!$rThreadData['auto_encode']) {
                                    } else {
                                        foreach ($rServers as $rServerID) {
                                            CoreUtilities::queueMovie($rInsertID, $rServerID);
                                        }
                                    }
                                    echo 'Success!' . "\n";
                                } else {
                                    foreach ($rServers as $rServerID) {
                                        $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                                    }
                                    foreach ($rBouquetIDs as $rBouquet) {
                                        addToBouquet('movie', $rBouquet, $rInsertID);
                                    }
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 1, ?);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']), $rInsertID);
                                    exit();
                                }
                            } else {
                                echo 'Insert failed!' . "\n";
                                $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 2, 0);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']));
                                exit();
                            }
                        }
                        break;
                    }
                    if ($rFirstFile) {
                        $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 5, 0);', $rThreadType, SERVER_ID, utf8_decode($rFirstFile));
                        exit();
                    }
                    exit();
                } else {
                    exit('Failed to get information.' . "\n");
                }
                // no break
            case 'show':
                $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '?X-Plex-Token=' . $rThreadData['token'];
                $rContent = json_decode(json_encode(simplexml_load_string(readURL($rURL))), true);
                if ($rContent) {
                    list($rShowData) = makeArray($rContent['Directory']);
                    $rTMDBID = null;
                    if (substr($rShowData['@attributes']['guid'], 0, 32) != 'com.plexapp.agents.themoviedb://') {
                    } else {
                        list(, $rSplit) = explode('com.plexapp.agents.themoviedb://', $rShowData['@attributes']['guid']);
                        $rTMDBID = intval(explode('?lang=', $rSplit)[0]);
                        $rLanguage = (explode('?lang=', $rSplit)[1] ?: null);
                        echo 'TMDB ID: ' . $rTMDBID . "\n";
                    }
                    if ($rTMDBID) {
                    } else {
                        foreach ($rShowData['Guid'] as $rGUID) {
                            if (substr($rGUID['@attributes']['id'], 0, 7) != 'tmdb://') {
                            } else {
                                $rTMDBID = substr($rGUID['@attributes']['id'], 7, strlen($rGUID['@attributes']['id']) - 7);
                                $rLanguage = (explode('?lang=', $rSplit)[1] ?: null);
                                echo 'TMDB ID: ' . $rTMDBID . "\n";
                                break;
                            }
                        }
                    }
                    $rSeasonInfo = $rSeasonData = array();
                    $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '/children?X-Plex-Token=' . $rThreadData['token'];
                    $rSeasons = makeArray(json_decode(json_encode(simplexml_load_string(readURL($rURL))), true)['Directory']);
                    $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '/allLeaves?X-Plex-Token=' . $rThreadData['token'];
                    $rEpisodes = makeArray(json_decode(json_encode(simplexml_load_string(readURL($rURL))), true)['Video']);
                    foreach ($rEpisodes as $rEpisode) {
                        if (in_array($rEpisode['@attributes']['parentIndex'], array_keys($rSeasonInfo))) {
                        } else {
                            $rSeasonInfo[$rEpisode['@attributes']['parentIndex']] = $rEpisode['@attributes']['originallyAvailableAt'];
                        }
                    }
                    foreach ($rSeasons as $rSeason) {
                        if (!$rSeason['@attributes']['index']) {
                        } else {
                            $rCover = null;
                            if (!$rSeason['@attributes']['thumb']) {
                            } else {
                                $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rSeason['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                                $rCover = CoreUtilities::downloadImage($rThumbURL);
                            }
                            $rSeasonData[] = array('name' => $rSeason['@attributes']['title'], 'air_date' => ($rSeasonInfo[$rSeason['@attributes']['index']] ?: ''), 'overview' => (trim($rShowData['@attributes']['summary']) ?: ''), 'cover_big' => $rCover, 'cover' => $rCover, 'episode_count' => $rSeason['@attributes']['leafCount'], 'season_number' => $rSeason['@attributes']['index'], 'id' => $rSeason['@attributes']['ratingKey']);
                        }
                    }
                    $rSeries = getseriesbyid($rThreadData['uuid'], $rTMDBID);
                    if (!$rSeries) {
                        $rSeriesArray = array('title' => $rShowData['@attributes']['title'], 'category_id' => array(), 'episode_run_time' => (intval($rShowData['@attributes']['duration'] / 1000 / 60) ?: 0), 'tmdb_id' => $rTMDBID, 'cover' => '', 'genre' => '', 'plot' => trim($rShowData['@attributes']['summary']), 'cast' => '', 'rating' => ((floatval($rShowData['@attributes']['rating']) ?: floatval($rShowData['@attributes']['audienceRating'])) ?: 0), 'director' => '', 'release_date' => $rShowData['@attributes']['originallyAvailableAt'], 'last_modified' => time(), 'seasons' => $rSeasonData, 'backdrop_path' => array(), 'youtube_trailer' => '', 'year' => null);
                        if (!$rSeriesArray['release_date']) {
                        } else {
                            $rSeriesArray['year'] = intval(substr($rSeriesArray['release_date'], 0, 4));
                        }
                        if (!$rShowData['@attributes']['thumb']) {
                        } else {
                            $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                            $rThumb = CoreUtilities::downloadImage($rThumbURL);
                        }
                        if (!$rShowData['@attributes']['art']) {
                        } else {
                            $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                            $rBG = CoreUtilities::downloadImage($rBGURL);
                        }
                        $rSeriesArray['cover'] = $rThumb;
                        $rSeriesArray['cover_big'] = $rThumb;
                        if ($rBG) {
                            $rSeriesArray['backdrop_path'] = array($rBG);
                        } else {
                            $rSeriesArray['backdrop_path'] = array();
                        }
                        $rCast = array();
                        foreach (array_slice(makeArray($rShowData['Role']), 0, 5) as $rMember) {
                            $rCast[] = $rMember['@attributes']['tag'];
                        }
                        $rSeriesArray['cast'] = implode(', ', $rCast);
                        $rDirectors = array();
                        foreach (array_slice(makeArray($rShowData['Director']), 0, 3) as $rMember) {
                            $rDirectors[] = $rMember['@attributes']['tag'];
                        }
                        $rSeriesArray['director'] = implode(', ', $rDirectors);
                        $rGenres = array();
                        foreach (array_slice(makeArray($rShowData['Genre']), 0, 3) as $rGenre) {
                            $rGenres[] = $rGenre['@attributes']['tag'];
                        }
                        $rSeriesArray['genre'] = implode(', ', $rGenres);
                        if (count($rCategoryIDs) != 0) {
                        } else {
                            if (0 < $rThreadData['max_genres']) {
                                $rParsed = array_slice(makeArray($rShowData['Genre']), 0, $rThreadData['max_genres']);
                            } else {
                                $rParsed = makeArray($rShowData['Genre']);
                            }
                            foreach ($rParsed as $rGenre) {
                                $rGenreTag = $rGenre['@attributes']['tag'];
                                if (isset($rPlexCategories[3][$rGenreTag])) {
                                    $rCategoryID = intval($rPlexCategories[4][$rGenreTag]['category_id']);
                                    if (0 >= $rCategoryID) {
                                    } else {
                                        if (in_array($rCategoryID, $rCategoryIDs)) {
                                        } else {
                                            $rCategoryIDs[] = $rCategoryID;
                                        }
                                    }
                                } else {
                                    if (!$rThreadData['store_categories']) {
                                    } else {
                                        addCategory($rThreadData['type'], $rGenreTag);
                                    }
                                }
                            }
                        }
                        if (!(count($rCategoryIDs) == 0 && 0 < intval($rThreadData['fb_category_id']))) {
                        } else {
                            $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                        }
                        if (count($rBouquetIDs) != 0) {
                        } else {
                            if (0 < $rThreadData['max_genres']) {
                                $rParsed = array_slice(makeArray($rShowData['Genre']), 0, $rThreadData['max_genres']);
                            } else {
                                $rParsed = makeArray($rShowData['Genre']);
                            }
                            foreach ($rParsed as $rGenre) {
                                $rGenreTag = $rGenre['@attributes']['tag'];
                                $rBouquets = json_decode($rPlexCategories[4][$rGenreTag]['bouquets'], true);
                                foreach ($rBouquets as $rBouquetID) {
                                    if (in_array($rBouquetID, $rBouquetIDs)) {
                                    } else {
                                        $rBouquetIDs[] = $rBouquetID;
                                    }
                                }
                            }
                        }
                        if (count($rBouquetIDs) != 0) {
                        } else {
                            $rBouquetIDs = array_map('intval', json_decode($rThreadData['fb_bouquets'], true));
                        }
                        if (count($rCategoryIDs) != 0) {
                            $rSeriesArray['plex_uuid'] = $rThreadData['uuid'];
                            $rSeriesArray['tmdb_language'] = $rLanguage;
                            $rSeriesArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                            $rPrepare = prepareArray($rSeriesArray);
                            $rQuery = 'INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                            if ($db->query($rQuery, ...$rPrepare['data'])) {
                                $rInsertID = $db->last_insert_id();
                                $rSeries = getSerie($rInsertID);
                                foreach ($rBouquetIDs as $rBouquet) {
                                    addToBouquet('series', $rBouquet, $rInsertID);
                                }
                            } else {
                                $rSeries = null;
                            }
                        } else {
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 3, 0);', $rThreadType, SERVER_ID, 'Plex Series: ' . utf8_decode($rSeriesArray['title']));
                            exit();
                        }
                    } else {
                        $db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
                        if ($rSeries['cover']) {
                        } else {
                            if (!$rShowData['@attributes']['thumb']) {
                            } else {
                                $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                                $rThumb = CoreUtilities::downloadImage($rThumbURL);
                            }
                            if (!$rShowData['@attributes']['art']) {
                            } else {
                                $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                                $rBG = CoreUtilities::downloadImage($rBGURL);
                            }
                            if (!($rThumb || $rBG)) {
                            } else {
                                if ($rBG) {
                                    $rBG = array($rBG);
                                } else {
                                    $rBG = array();
                                }
                                $db->query('UPDATE `streams_series` SET `cover` = ?, `cover_big` = ?, `backdrop_path` = ? WHERE `id` = ?;', $rThumb, $rThumb, $rBG, $rSeries['id']);
                            }
                        }
                    }
                    foreach ($rEpisodes as $rEpisode) {
                        if (!($rEpisode['@attributes']['parentIndex'] && $rEpisode['@attributes']['index'])) {
                        } else {
                            $rFirstFile = null;
                            $rReleaseSeason = $rEpisode['@attributes']['parentIndex'];
                            $rReleaseEpisode = $rEpisode['@attributes']['index'];
                            $rFileArray = array('file' => null, 'size' => null, 'data' => null, 'key' => null);
                            foreach (makeArray($rEpisode['Media']) as $rMedia) {
                                if ($rFirstFile) {
                                } else {
                                    $rFirstFile = $rMedia['Part']['@attributes']['file'];
                                }
                                if ($rFileArray['size'] && $rFileArray['size'] >= intval($rMedia['Part']['@attributes']['size'])) {
                                } else {
                                    if (!(file_exists($rMedia['Part']['@attributes']['file']) || $rThreadData['direct_proxy'])) {
                                    } else {
                                        $rFileArray = array('file' => $rMedia['Part']['@attributes']['file'], 'size' => intval($rMedia['Part']['@attributes']['size']), 'data' => $rMedia, 'key' => $rMedia['Part']['@attributes']['key']);
                                    }
                                }
                            }
                            if (!empty($rFileArray['file'])) {
                                $rInternalPath = json_encode(array('s:' . SERVER_ID . ':' . $rFileArray['file']), JSON_UNESCAPED_UNICODE);
                                $rDirectURL = json_encode(array('http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . $rFileArray['key'] . '?X-Plex-Token=' . $rThreadData['token']), JSON_UNESCAPED_UNICODE);
                                if (!in_array($rInternalPath, $rStreamDatabase) && !in_array($rDirectURL, $rStreamDatabase)) {
                                    $rStreamDatabase[] = $rInternalPath;
                                    $rStreamDatabase[] = $rDirectURL;
                                    if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container'] && !$rThreadData['direct_proxy']) {
                                        $rImportArray['target_container'] = $rThreadData['target_container'];
                                    } else {
                                        $rImportArray['target_container'] = pathinfo($rFileArray['file'])['extension'];
                                    }
                                    if (!empty($rImportArray['target_container'])) {
                                    } else {
                                        $rImportArray['target_container'] = 'mp4';
                                    }
                                    if (!($rUpgradeData = getEpisode($rThreadData['uuid'], ($rThreadData['check_tmdb'] ? $rTMDBID : null), $rReleaseSeason, $rReleaseEpisode))) {
                                        $db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', utf8_decode($rFileArray['file']), $rThreadType, SERVER_ID);
                                        $rThumb = null;
                                        if (!$rEpisode['@attributes']['thumb']) {
                                        } else {
                                            $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=450&height=253&minSize=1&quality=100&upscale=1&url=' . $rEpisode['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                                            $rThumb = CoreUtilities::downloadImage($rThumbURL);
                                        }
                                        $rSeconds = intval($rEpisode['@attributes']['duration'] / 1000);
                                        $rImportArray['movie_properties'] = array('tmdb_id' => ($rSeries['tmdb_id'] ?: null), 'release_date' => $rEpisode['@attributes']['originallyAvailableAt'], 'plot' => $rEpisode['@attributes']['summary'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rThumb, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => ((floatval($rEpisode['@attributes']['rating']) ?: floatval($rEpisode['@attributes']['audienceRating'])) ?: $rSeries['rating']), 'season' => $rReleaseSeason);
                                        $rImportArray['stream_display_name'] = $rSeries['title'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode) . ' - ' . $rEpisode['@attributes']['title'];
                                        $rImportArray['read_native'] = $rThreadData['read_native'];
                                        $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
                                        $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
                                        $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
                                        if ($rThreadData['direct_proxy']) {
                                            $rImportArray['stream_source'] = $rDirectURL;
                                            $rImportArray['direct_source'] = 1;
                                            $rImportArray['direct_proxy'] = 1;
                                        } else {
                                            $rImportArray['stream_source'] = $rInternalPath;
                                            $rImportArray['direct_source'] = 0;
                                            $rImportArray['direct_proxy'] = 0;
                                        }
                                        $rImportArray['order'] = getNextOrder();
                                        $rImportArray['tmdb_language'] = $rLanguage;
                                        $rImportArray['added'] = time();
                                        $rImportArray['uuid'] = $rThreadData['uuid'];
                                        $rImportArray['series_no'] = $rSeries['id'];
                                        $rPrepare = prepareArray($rImportArray);
                                        $rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                                        if ($db->query($rQuery, ...$rPrepare['data'])) {
                                            $rInsertID = $db->last_insert_id();
                                            foreach ($rServers as $rServerID) {
                                                $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                                            }
                                            $db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rReleaseSeason, $rSeries['id'], $rInsertID, $rReleaseEpisode);
                                            if (!$rThreadData['auto_encode']) {
                                            } else {
                                                foreach ($rServers as $rServerID) {
                                                    CoreUtilities::queueMovie($rInsertID, $rServerID);
                                                }
                                            }
                                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 1, ?);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']), $rInsertID);
                                        } else {
                                            echo 'Insert failed!' . "\n";
                                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 2, 0);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']));
                                        }
                                    } else {
                                        if ($rUpgradeData['source'] != $rFileArray['file']) {
                                            if ($rThreadData['auto_upgrade']) {
                                                echo 'Upgrade episode!' . "\n";
                                                $db->query('UPDATE `streams` SET `plex_uuid` = ?, `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rThreadData['uuid'], $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);
                                                foreach ($rServers as $rServerID) {
                                                    $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], $rServerID);
                                                }
                                                if (!$rThreadData['auto_encode']) {
                                                } else {
                                                    foreach ($rServers as $rServerID) {
                                                        CoreUtilities::queueMovie($rUpgradeData['id'], $rServerID);
                                                    }
                                                }
                                                $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, utf8_decode($rFileArray['file']));
                                            } else {
                                                echo 'Upgrade disabled' . "\n";
                                            }
                                        } else {
                                            echo 'File remains unchanged' . "\n";
                                        }
                                    }
                                } else {
                                    echo 'Already exists!' . "\n";
                                }
                            } else {
                                if ($rFirstFile) {
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 5, 0);', $rThreadType, SERVER_ID, utf8_decode($rFirstFile));
                                } else {
                                    exit();
                                }
                            }
                        }
                    }
                    break;
                } else {
                    exit('Failed to get information.' . "\n");
                }
        }
    } else {
        exit();
    }
}
function getMovie($rPlexID, $rTMDBID) {
    if (file_exists(WATCH_TMP_PATH . 'movie_' . $rPlexID . '.pcache')) {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rPlexID . '.pcache'), true);
    }
    if (!file_exists(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.pcache')) {
    } else {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.pcache'), true);
    }
}
function getEpisode($rPlexID, $rTMDBID, $rSeason, $rEpisode) {
    if (!file_exists(WATCH_TMP_PATH . 'series_' . $rPlexID . '.pcache')) {
    } else {
        $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rPlexID . '.pcache'), true);
        if (!isset($rData[$rSeason . '_' . $rEpisode])) {
        } else {
            return $rData[$rSeason . '_' . $rEpisode];
        }
    }
    if (!file_exists(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.pcache')) {
    } else {
        $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.pcache'), true);
        if (!isset($rData[$rSeason . '_' . $rEpisode])) {
        } else {
            return $rData[$rSeason . '_' . $rEpisode];
        }
    }
}
function addCategory($rType, $rGenreTag) {
    file_put_contents(WATCH_TMP_PATH . md5($rType . '_' . $rGenreTag) . '.pcat', json_encode(array('type' => $rType, 'title' => $rGenreTag)));
}
function readURL($rURL) {
    $rCurl = curl_init($rURL);
    curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
    return curl_exec($rCurl);
}
function makeArray($rArray) {
    if (!isset($rArray['@attributes'])) {
    } else {
        $rArray = array($rArray);
    }
    return $rArray;
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
    @unlink(WATCH_TMP_PATH . @getmypid() . '.ppid');
}
