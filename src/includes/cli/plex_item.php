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
    $UpdateData = $rColumns = $rPlaceholder = $rData = array();

    foreach (array_keys($rArray) as $rKey) {
        $rColumns[] = '`' . preparecolumn($rKey) . '`';
        $UpdateData[] = '`' . preparecolumn($rKey) . '` = ?';
    }

    foreach (array_values($rArray) as $rValue) {
        if (!is_array($rValue)) {
        } else {
            $rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
        }

        $rPlaceholder[] = '?';
        $rData[] = $rValue;
    }

    return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $UpdateData));
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

        // =============================================================
        // MOVIE PROCESSING
        // =============================================================
        if ($rThreadData['type'] == 'movie') {
            echo "=== [MOVIE] Processing movie: {$rThreadData['key']} ===\n";

            $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '?X-Plex-Token=' . $rThreadData['token'];
            echo "LOG: Fetching movie metadata: $rURL\n";

            $rContent = json_decode(json_encode(simplexml_load_string(readURL($rURL))), true);

            if (!$rContent || empty($rContent['Video'])) {
                echo "ERROR: Failed to get movie metadata from Plex!\n";
                exit('Failed to get information.' . PHP_EOL);
            }

            $Video = $rContent['Video'];
            echo "LOG: Movie title: {$Video['@attributes']['title']} (" . ($Video['@attributes']['year'] ?? 'No year') . ")\n";

            $rTMDBID = null;
            $rFirstFile = null;

            // TMDB ID
            $tmdb = getTmdbIdFromPlex($Video);
            $rTMDBID = $tmdb['tmdb_id'];
            echo $rTMDBID ? "LOG: TMDB ID detected: $rTMDBID\n" : "LOG: TMDB ID not found — will work without it\n";

            $rFileArray = array('file' => null, 'size' => null, 'data' => null, 'key' => null);

            foreach (makeArray($Video['Media']) as $rMedia) {
                foreach (makeArray($rMedia['Part']) as $rPart) {
                    $filePath = $rPart['@attributes']['file'];
                    $fileSize = intval($rPart['@attributes']['size']);
                    $fileKey  = $rPart['@attributes']['key'];

                    if (!$rFirstFile) {
                        $rFirstFile = $filePath;
                        echo "LOG: First detected file (fallback): $filePath\n";
                    }

                    $accessible = file_exists($filePath) || $rThreadData['direct_proxy'];

                    if ($accessible && (!$rFileArray['size'] || $fileSize > $rFileArray['size'])) {
                        $rFileArray = array(
                            'file' => $filePath,
                            'size' => $fileSize,
                            'data' => $rMedia,
                            'key'  => $fileKey
                        );
                        echo "LOG: Selected as best source (largest + accessible): $filePath\n";
                    }
                }
            }

            if (!empty($rFileArray['file'])) {
                $movieTitle = $Video['@attributes']['title'];

                $rInternalPath = json_encode(array('s:' . SERVER_ID . ':' . $rFileArray['file']), JSON_UNESCAPED_UNICODE);
                $rDirectURL    = json_encode(array('http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . $rFileArray['key'] . '?X-Plex-Token=' . $rThreadData['token']), JSON_UNESCAPED_UNICODE);

                if (in_array($rInternalPath, $rStreamDatabase) || in_array($rDirectURL, $rStreamDatabase)) {
                    echo "LOG: Movie already exists in database (source match) — skipping import\n";
                } else {
                    echo "LOG: New unique movie detected — preparing import: \"$movieTitle\"\n";

                    $rStreamDatabase[] = $rInternalPath;
                    $rStreamDatabase[] = $rDirectURL;

                    // Container
                    if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container'] && !$rThreadData['direct_proxy']) {
                        $rImportArray['target_container'] = $rThreadData['target_container'];
                    } else {
                        $ext = pathinfo($rFileArray['file'], PATHINFO_EXTENSION) ?: 'mp4';
                        $rImportArray['target_container'] = $ext;
                    }
                    echo "LOG: Target container: {$rImportArray['target_container']}\n";

                    $db->query(
                        'DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;',
                        htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'),
                        $rThreadType,
                        SERVER_ID
                    );

                    // Poster
                    if (!empty($Video['@attributes']['thumb'])) {
                        $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $Video['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                        echo "LOG: Downloading poster: $rThumbURL\n";
                        $rThumb = CoreUtilities::downloadImage($rThumbURL);
                    } else {
                        $rThumb = null;
                        echo "LOG: No poster available in Plex\n";
                    }

                    // Backdrop
                    if (!empty($Video['@attributes']['art'])) {
                        $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $Video['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                        echo "LOG: Downloading backdrop: $rBGURL\n";
                        $rBG = CoreUtilities::downloadImage($rBGURL);
                    } else {
                        $rBG = null;
                        echo "LOG: No backdrop available in Plex\n";
                    }

                    // Cast, Director, Genre
                    $rCast = array();

                    foreach (array_slice(makeArray($Video['Role']), 0, 5) as $rMember) {
                        $rCast[] = $rMember['@attributes']['tag'];
                    }
                    $rDirectors = array();

                    foreach (array_slice(makeArray($Video['Director']), 0, 3) as $rMember) {
                        $rDirectors[] = $rMember['@attributes']['tag'];
                    }
                    $rGenres = array();
                    foreach (array_slice(makeArray($Video['Genre']), 0, $rThreadData['max_genres']) as $rGenre) {
                        $rGenres[] = $rGenre['@attributes']['tag'];
                    }

                    echo "LOG: Genres: " . implode(', ', $rGenres) . "\n";

                    $country = makeArray($Video['Country'])[0]['@attributes']['tag'] ?? null;
                    $rSeconds = intval($Video['@attributes']['duration'] / 1000);

                    $rImportArray['stream_display_name'] = $Video['@attributes']['title'];
                    $rImportArray['year'] = !empty($Video['@attributes']['year']) ? intval($Video['@attributes']['year']) : null;
                    $rImportArray['tmdb_id'] = $rTMDBID ?: null;
                    $rImportArray['movie_properties'] = array(
                        'tmdb_id' => $rTMDBID,
                        'release_date' => $Video['@attributes']['originallyAvailableAt'] ?? null,
                        'plot' => trim($Video['@attributes']['summary'] ?? ''),
                        'duration_secs' => $rSeconds,
                        'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60),
                        'movie_image' => $rThumb,
                        'cover_big' => $rThumb,
                        'backdrop_path' => $rBG ? array($rBG) : array(),
                        'director' => implode(', ', $rDirectors),
                        'actors' => implode(', ', $rCast),
                        'cast' => implode(', ', $rCast),
                        'genre' => implode(', ', $rGenres),
                        'country' => $country,
                        'rating' => floatval($Video['@attributes']['rating'] ?? $Video['@attributes']['audienceRating'] ?? 0)
                    );
                    $rImportArray['rating'] = floatval($Video['@attributes']['rating'] ?? $Video['@attributes']['audienceRating'] ?? 0);

                    echo "LOG: Movie duration: " . $rImportArray['movie_properties']['duration'] . " | Rating: " . $rImportArray['rating'] . "\n";

                    // Source type
                    if ($rThreadData['direct_proxy']) {
                        $rImportArray['stream_source'] = $rDirectURL;
                        $rImportArray['direct_source'] = 1;
                        $rImportArray['direct_proxy'] = 1;
                        echo "LOG: Using direct Plex proxy stream\n";
                    } else {
                        $rImportArray['stream_source'] = $rInternalPath;
                        $rImportArray['direct_source'] = 0;
                        $rImportArray['direct_proxy'] = 0;
                        echo "LOG: Using local file path source\n";
                    }

                    $rImportArray['order'] = getNextOrder();
                    $rImportArray['added'] = time();
                    $rImportArray['plex_uuid'] = $rThreadData['uuid'];

                    // Categories & Bouquets (логика осталась прежней, но с логами)
                    if (empty($rCategoryIDs)) {
                        echo "LOG: Assigning categories based on genres...\n";
                        foreach ($rGenres as $rGenreTag) {
                            if (isset($rPlexCategories[3][$rGenreTag])) {
                                $catId = intval($rPlexCategories[3][$rGenreTag]['category_id']);
                                if ($catId > 0 && !in_array($catId, $rCategoryIDs)) {
                                    $rCategoryIDs[] = $catId;
                                    echo "LOG: → Added category ID $catId ($rGenreTag)\n";
                                }
                            } elseif ($rThreadData['store_categories']) {
                                echo "LOG: New genre \"$rGenreTag\" — creating category\n";
                                addCategory($rThreadData['type'], $rGenreTag);
                            }
                        }
                    }

                    if (empty($rCategoryIDs) && !empty($rThreadData['fb_category_id'])) {
                        $rCategoryIDs = [intval($rThreadData['fb_category_id'])];
                        echo "LOG: Fallback category applied: {$rCategoryIDs[0]}\n";
                    }

                    $rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                    echo "LOG: Final category IDs: " . $rImportArray['category_id'] . "\n";

                    // Check for existing movie (upgrade logic)
                    $rUpgradeData = getMovie($rThreadData['uuid'], ($rThreadData['check_tmdb'] ? $rTMDBID : null));
                    if ($rUpgradeData) {
                        echo "LOG: Movie already exists in DB (ID: {$rUpgradeData['id']})\n";
                        if ($rUpgradeData['source'] != $rFileArray['file']) {
                            if ($rThreadData['auto_upgrade']) {
                                echo "LOG: Better source found → UPGRADING movie (new file: {$rFileArray['file']})\n";
                                $rImportArray['id'] = $rUpgradeData['id'];
                            } else {
                                echo "LOG: Auto-upgrade disabled — skipping\n";
                                exit();
                            }
                        } else {
                            echo "LOG: Same source file — no changes needed\n";
                            exit();
                        }
                    } else {
                        echo "LOG: This is a completely new movie → creating fresh entry\n";
                        if (empty($rCategoryIDs)) {
                            echo "LOG: No categories assigned → logging as failed (status 3)\n";
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 3, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'));
                            exit();
                        }
                    }

                    // Final INSERT / REPLACE
                    $rPrepare = prepareArray($rImportArray);
                    $rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    echo "LOG: Executing REPLACE INTO `streams` (" . ($rUpgradeData ? "upgrade" : "new") . ")\n";

                    if ($db->last_insert_id() > 0) {
                        $rInsertID = $db->last_insert_id();
                    }

                    if ($db->query($rQuery, ...$rPrepare['data'])) {
                        $rInsertID = $db->last_insert_id() ?: $rImportArray['id'];

                        if ($rUpgradeData) {
                            echo "LOG: Movie successfully upgraded! Stream ID: $rInsertID\n";
                            foreach ($rServers as $rServerID) {
                                $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rInsertID, $rServerID);
                            }
                            if ($rThreadData['auto_encode']) {
                                foreach ($rServers as $rServerID) {
                                    CoreUtilities::queueMovie($rInsertID, $rServerID);
                                }
                            }
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'));
                        } else {
                            echo "LOG: New movie imported successfully! Stream ID: $rInsertID\n";
                            foreach ($rServers as $rServerID) {
                                $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                            }
                            foreach ($rBouquetIDs as $rBouquet) {
                                addToBouquet('movie', $rBouquet, $rInsertID);
                            }
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 1, ?);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'), $rInsertID);
                        }
                    } else {
                        echo "ERROR: Failed to insert/update movie in `streams` table!\n";
                        $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 2, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'));
                    }
                }
            } else {
                echo "LOG: No accessible file parts found for this movie\n";
            }

            if ($rFirstFile) {
                echo "LOG: Logging inaccessible file (status 5): $rFirstFile\n";
                $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 5, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFirstFile, ENT_QUOTES, 'UTF-8'));
            }

            echo "=== [MOVIE] Processing completed ===\n";
        }

        // =============================================================
        // SERIES PROCESSING
        // =============================================================
        if ($rThreadData['type'] == 'show') {
            echo "=== [SHOW] Processing TV show: {$rThreadData['key']} ===\n";

            // Getting the basic metadata of the series
            $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '?X-Plex-Token=' . $rThreadData['token'];
            echo "Fetching show metadata: $rURL\n";
            $rContent = json_decode(json_encode(simplexml_load_string(readURL($rURL))), true);
            if (!$rContent || empty($rContent['Directory'])) {
                echo "ERROR: Failed to get show metadata from Plex!\n";
                exit('Failed to get information.' . PHP_EOL);
            }
            $rShowData = $rContent['Directory'];
            echo "Show title: {$rShowData['@attributes']['title']}\n";

            // Get TMDB ID and language
            $tmdbInfo = getTmdbIdFromPlex($rShowData);
            $rTMDBID = $tmdbInfo['tmdb_id'];
            $rLanguage = $tmdbInfo['language'];
            echo $rTMDBID ? "TMDB ID detected: $rTMDBID\n" : "TMDB ID not found — will work without it\n";
            if ($rLanguage) echo "Detected language: $rLanguage\n";

            // Get a list of seasons and all episodes
            echo "Fetching seasons...\n";
            $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '/children?X-Plex-Token=' . $rThreadData['token'];
            $rSeasons = makeArray(json_decode(json_encode(simplexml_load_string(readURL($rURL))), true)['Directory']);
            echo "Fetching all episodes...\n";
            $rURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/library/metadata/' . $rThreadData['key'] . '/allLeaves?X-Plex-Token=' . $rThreadData['token'];
            $rEpisodes = makeArray(json_decode(json_encode(simplexml_load_string(readURL($rURL))), true)['Video']);
            echo "Found " . count($rSeasons) . " season(s), " . count($rEpisodes) . " episode(s)\n";

            // Collecting the release dates of the seasons
            $rSeasonInfo = [];
            foreach ($rEpisodes as $rEpisode) {
                if (!in_array($rEpisode['@attributes']['parentIndex'], array_keys($rSeasonInfo))) {
                    $rSeasonInfo[$rEpisode['@attributes']['parentIndex']] = $rEpisode['@attributes']['originallyAvailableAt'];
                    echo "Season {$rEpisode['@attributes']['parentIndex']} first air date set to {$rEpisode['@attributes']['originallyAvailableAt']}\n";
                }
            }

            // Creating an array of seasons with covers
            $rSeasonData = [];
            foreach ($rSeasons as $rSeason) {
                if ($rSeason['@attributes']['index']) {
                    echo "Processing season {$rSeason['@attributes']['index']} — {$rSeason['@attributes']['title']}\n";
                    $rCover = null;
                    if ($rSeason['@attributes']['thumb']) {
                        $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rSeason['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                        echo "Downloading season cover: $rThumbURL\n";
                        $rCover = CoreUtilities::downloadImage($rThumbURL);
                    }
                    $rSeasonData[] = array(
                        'name' => $rSeason['@attributes']['title'],
                        'air_date' => ($rSeasonInfo[$rSeason['@attributes']['index']] ?: ''),
                        'overview' => (trim($rShowData['@attributes']['summary']) ?: ''),
                        'cover_big' => $rCover,
                        'cover' => $rCover,
                        'episode_count' => $rSeason['@attributes']['leafCount'],
                        'season_number' => $rSeason['@attributes']['index'],
                        'id' => $rSeason['@attributes']['ratingKey']
                    );
                }
            }

            // Checking if the series exists in the database
            $rSeries = getseriesbyid($rThreadData['uuid'], $rTMDBID);

            if (!$rSeries) {
                echo "Creating new series entry...\n";
                $rSeriesArray = array(
                    'title' => $rShowData['@attributes']['title'],
                    'category_id' => array(),
                    'episode_run_time' => (intval($rShowData['@attributes']['duration'] / 1000 / 60) ?: 0),
                    'tmdb_id' => $rTMDBID,
                    'cover' => '',
                    'genre' => '',
                    'plot' => trim($rShowData['@attributes']['summary']),
                    'cast' => '',
                    'rating' => ((floatval($rShowData['@attributes']['rating']) ?: floatval($rShowData['@attributes']['audienceRating'])) ?: 0),
                    'director' => '',
                    'release_date' => $rShowData['@attributes']['originallyAvailableAt'],
                    'last_modified' => time(),
                    'seasons' => $rSeasonData,
                    'backdrop_path' => array(),
                    'youtube_trailer' => '',
                    'year' => null
                );
                if ($rSeriesArray['release_date']) {
                    $rSeriesArray['year'] = intval(substr($rSeriesArray['release_date'], 0, 4));
                    echo "Series year set to {$rSeriesArray['year']}\n";
                }

                if ($rShowData['@attributes']['thumb']) {
                    $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                    echo "Downloading show poster: $rThumbURL\n";
                    $rThumb = CoreUtilities::downloadImage($rThumbURL);
                }
                if ($rShowData['@attributes']['art']) {
                    $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                    echo "Downloading backdrop: $rBGURL\n";
                    $rBG = CoreUtilities::downloadImage($rBGURL);
                }

                $rSeriesArray['cover'] = $rThumb ?? null;
                $rSeriesArray['cover_big'] = $rThumb ?? null;
                $rSeriesArray['backdrop_path'] = isset($rBG) ? array($rBG) : array();

                $rSeriesArray['cast'] = extractTags($rShowData, 'Role', 5);
                $rSeriesArray['director'] = extractTags($rShowData, 'Director', 3);
                $rSeriesArray['genre'] = extractTags($rShowData, 'Genre', 3);

                if (count($rCategoryIDs) == 0) {
                    if (0 < $rThreadData['max_genres']) {
                        $rParsed = array_slice(makeArray($rShowData['Genre']), 0, $rThreadData['max_genres']);
                    } else {
                        $rParsed = makeArray($rShowData['Genre']);
                    }
                    foreach ($rParsed as $rGenre) {
                        $rGenreTag = $rGenre['@attributes']['tag'];

                        if (isset($rPlexCategories[4][$rGenreTag])) {
                            $rCategoryID = intval($rPlexCategories[4][$rGenreTag]['category_id']);

                            if ($rCategoryID > 0) {
                                if (!in_array($rCategoryID, $rCategoryIDs)) {
                                    $rCategoryIDs[] = $rCategoryID;
                                }
                            }
                        } else {
                            if ($rThreadData['store_categories']) {
                                addCategory($rThreadData['type'], $rGenreTag);
                            }
                        }
                    }
                }


                if (count($rCategoryIDs) == 0 && 0 < intval($rThreadData['fb_category_id'])) {
                    $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                }

                if (count($rBouquetIDs) == 0) {
                    if (0 < $rThreadData['max_genres']) {
                        $rParsed = array_slice(makeArray($rShowData['Genre']), 0, $rThreadData['max_genres']);
                    } else {
                        $rParsed = makeArray($rShowData['Genre']);
                    }

                    foreach ($rParsed as $rGenre) {
                        $rGenreTag = $rGenre['@attributes']['tag'];
                        $rBouquets = json_decode($rPlexCategories[4][$rGenreTag]['bouquets'], true);

                        foreach ($rBouquets as $rBouquetID) {
                            if (!in_array($rBouquetID, $rBouquetIDs)) {
                                $rBouquetIDs[] = $rBouquetID;
                            }
                        }
                    }
                }

                if (count($rBouquetIDs) == 0) {
                    $rBouquetIDs = array_map('intval', json_decode($rThreadData['fb_bouquets'], true));
                }

                if (count($rCategoryIDs) != 0) {
                    $rSeriesArray['plex_uuid'] = $rThreadData['uuid'];
                    $rSeriesArray['tmdb_language'] = $rLanguage;
                    $rSeriesArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                    echo "Final category IDs: " . $rSeriesArray['category_id'] . "\n";

                    $rPrepare = prepareArray($rSeriesArray);
                    $rQuery = 'INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    echo "Executing INSERT into streams_series (ID will be auto-generated)\n";
                    if ($db->query($rQuery, ...$rPrepare['data'])) {
                        $rInsertID = $db->last_insert_id();
                        echo "Series created with ID = $rInsertID\n";
                        $rSeries = getSerie($rInsertID);
                        foreach ($rBouquetIDs as $rBouquet) {
                            echo "Adding series $rInsertID to bouquet $rBouquet\n";
                            addToBouquet('series', $rBouquet, $rInsertID);
                        }
                    } else {
                        echo "ERROR: Failed to insert series into DB!\n";
                        $rSeries = null;
                    }
                }
            } else {
                echo "Series already exists in DB (ID = {$rSeries['id']}), updating seasons only\n";
                $db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
                echo "Seasons updated for series ID {$rSeries['id']}\n";

                if (!$rSeries['cover']) {
                    echo "Series has no cover — trying to download from Plex\n";
                    if ($rShowData['@attributes']['thumb']) {
                        $rThumbURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=300&height=450&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['thumb'] . '&X-Plex-Token=' . $rThreadData['token'];
                        $rThumb = CoreUtilities::downloadImage($rThumbURL);
                    }

                    if ($rShowData['@attributes']['art']) {
                        $rBGURL = 'http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . '/photo/:/transcode?width=1280&height=720&minSize=1&quality=100&upscale=1&url=' . $rShowData['@attributes']['art'] . '&X-Plex-Token=' . $rThreadData['token'];
                        $rBG = CoreUtilities::downloadImage($rBGURL);
                    }

                    if ($rThumb || $rBG) {
                        if ($rBG) {
                            $rBG = array($rBG);
                        } else {
                            $rBG = array();
                        }

                        $db->query('UPDATE `streams_series` SET `cover` = ?, `cover_big` = ?, `backdrop_path` = ? WHERE `id` = ?;', $rThumb, $rThumb, $rBG, $rSeries['id']);
                    }
                }
            }

            // === Processing of all episodes ===
            echo "Starting episode import (" . count($rEpisodes) . " episodes)\n";
            foreach ($rEpisodes as $rEpisode) {
                if ($rEpisode['@attributes']['parentIndex'] && $rEpisode['@attributes']['index']) {
                    $rReleaseSeason = $rEpisode['@attributes']['parentIndex'];
                    $rReleaseEpisode = $rEpisode['@attributes']['index'];
                    echo "Processing S" . sprintf('%02d', $rReleaseSeason) . "E" . sprintf('%02d', $rReleaseEpisode) . " — {$rEpisode['@attributes']['title']}\n";

                    $rFirstFile = null;
                    $rFileArray = array('file' => null, 'size' => null, 'data' => null, 'key' => null);
                    foreach (makeArray($rEpisode['Media']) as $rMedia) {
                        if (!$rFirstFile) $rFirstFile = $rMedia['Part']['@attributes']['file'];
                        if ($rFileArray['size'] && $rFileArray['size'] >= intval($rMedia['Part']['@attributes']['size'])) {
                        } else {
                            if (file_exists($rMedia['Part']['@attributes']['file']) || $rThreadData['direct_proxy']) {
                                $rFileArray = array('file' => $rMedia['Part']['@attributes']['file'], 'size' => intval($rMedia['Part']['@attributes']['size']), 'data' => $rMedia, 'key' => $rMedia['Part']['@attributes']['key']);
                            }
                        }
                    }

                    if (!empty($rFileArray['file'])) {
                        $episodeName = $rSeries['title'] . " - S" . sprintf('%02d', $rReleaseSeason) . "E" . sprintf('%02d', $rReleaseEpisode) . " - " . $rEpisode['@attributes']['title'];
                        $rInternalPath = json_encode(array('s:' . SERVER_ID . ':' . $rFileArray['file']), JSON_UNESCAPED_UNICODE);
                        $rDirectURL = json_encode(array('http://' . $rThreadData['ip'] . ':' . $rThreadData['port'] . $rFileArray['key'] . '?X-Plex-Token=' . $rThreadData['token']), JSON_UNESCAPED_UNICODE);

                        if (in_array($rInternalPath, $rStreamDatabase) || in_array($rDirectURL, $rStreamDatabase)) {
                            echo "Episode already exists in database — skipping\n";
                            continue;
                        }

                        echo "New episode found: $episodeName\n";
                        $rStreamDatabase[] = $rInternalPath;
                        $rStreamDatabase[] = $rDirectURL;

                        if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container'] && !$rThreadData['direct_proxy']) {
                            $rImportArray['target_container'] = $rThreadData['target_container'];
                        } else {
                            $rImportArray['target_container'] = pathinfo($rFileArray['file'])['extension'];
                        }

                        if (empty($rImportArray['target_container'])) {
                            $rImportArray['target_container'] = 'mp4';
                        }
                        $rUpgradeData = getEpisode($rThreadData['uuid'], ($rThreadData['check_tmdb'] ? $rTMDBID : null), $rReleaseSeason, $rReleaseEpisode);
                        if (!$rUpgradeData) {
                            echo "Creating new stream entry for this episode\n";
                            $db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'), $rThreadType, SERVER_ID);
                            $rThumb = null;

                            if ($rEpisode['@attributes']['thumb']) {
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
                                echo "Episode imported successfully! Stream ID = $rInsertID\n";
                                foreach ($rServers as $rServerID) {
                                    $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                                }
                                $db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rReleaseSeason, $rSeries['id'], $rInsertID, $rReleaseEpisode);

                                if ($rThreadData['auto_encode']) {
                                    foreach ($rServers as $rServerID) {
                                        CoreUtilities::queueMovie($rInsertID, $rServerID);
                                    }
                                }

                                $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 1, ?);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'), $rInsertID);
                            } else {
                                echo "ERROR: Failed to insert episode into `streams`!\n";
                            }
                        } else {
                            if ($rUpgradeData['source'] != $rFileArray['file']) {
                                if ($rThreadData['auto_upgrade']) {
                                    echo 'Upgrade episode!' . "\n";
                                    $db->query('UPDATE `streams` SET `plex_uuid` = ?, `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rThreadData['uuid'], $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);

                                    foreach ($rServers as $rServerID) {
                                        $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], $rServerID);
                                    }

                                    if ($rThreadData['auto_encode']) {
                                        foreach ($rServers as $rServerID) {
                                            CoreUtilities::queueMovie($rUpgradeData['id'], $rServerID);
                                        }
                                    }

                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFileArray['file'], ENT_QUOTES, 'UTF-8'));
                                } else {
                                    echo 'Upgrade disabled' . "\n";
                                }
                            } else {
                                echo 'File remains unchanged' . "\n";
                            }
                        }
                    } else {
                        echo "No accessible file parts for this episode (possibly not mounted)\n";
                        if ($rFirstFile) {
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 5, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFirstFile, ENT_QUOTES, 'UTF-8'));
                        } else {
                            exit();
                        }
                    }
                }
            }
            echo "=== [SHOW] TV show processing completed ===\n";
        }
    } else {
        exit();
    }
}

function getMovie($rPlexID, $rTMDBID) {
    if (file_exists(WATCH_TMP_PATH . 'movie_' . $rPlexID . '.pcache')) {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rPlexID . '.pcache'), true);
    }

    if (file_exists(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.pcache')) {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.pcache'), true);
    }
}

function getEpisode($rPlexID, $rTMDBID, $rSeason, $rEpisode) {
    if (file_exists(WATCH_TMP_PATH . 'series_' . $rPlexID . '.pcache')) {
        $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rPlexID . '.pcache'), true);

        if (isset($rData[$rSeason . '_' . $rEpisode])) {
            return $rData[$rSeason . '_' . $rEpisode];
        }
    }

    if (file_exists(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.pcache')) {
        $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.pcache'), true);

        if (isset($rData[$rSeason . '_' . $rEpisode])) {
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
    if (isset($rArray['@attributes'])) {
        $rArray = array($rArray);
    }

    return $rArray;
}

function extractTags(array $data, string $tag, int $limit): string {
    $items = [];
    foreach (makeArray($data[$tag] ?? []) as $item) {
        if (count($items) >= $limit) break;
        $items[] = $item['@attributes']['tag'] ?? '';
    }
    return implode(', ', $items);
}

// Universal TMDB ID search function (works for movies and shows)
function getTmdbIdFromPlex($rContent) {
    $tmdbId = null;
    $language = null;

    // 1. Try a new format: separate <Guid id="tmdb://12345"/>
    if (isset($rContent['Guid']) && is_array($rContent['Guid'])) {
        foreach (makeArray($rContent['Guid']) as $guid) {
            $id = $guid['@attributes']['id'] ?? '';
            if (strpos($id, 'tmdb://') === 0) {
                $tmdbId = intval(substr($id, 7)); // убираем "tmdb://"
                echo "TMDB ID found (new format): $tmdbId\n";
                break;
            }
        }
    }

    // 2. Old format (just in case, for old libraries)
    if (!$tmdbId && !empty($rContent['@attributes']['guid'])) {
        $guid = $rContent['@attributes']['guid'];
        if (strpos($guid, 'com.plexapp.agents.themoviedb://') !== false) {
            preg_match('/com\.plexapp\.agents\.themoviedb:\/\/(\d+)/', $guid, $m);
            if (!empty($m[1])) {
                $tmdbId = intval($m[1]);
                if (strpos($guid, '?lang=') !== false) {
                    $language = substr($guid, strpos($guid, '?lang=') + 6);
                }
                echo "TMDB ID found (old format): $tmdbId\n";
            }
        }
    }

    // 3. Another old option: tmdb:// in Guid as a string
    if (!$tmdbId && isset($rContent['Guid']) && is_array($rContent['Guid'])) {
        foreach (makeArray($rContent['Guid']) as $guid) {
            $id = $guid['@attributes']['id'] ?? '';
            if (strpos($id, 'tmdb://') === 0) {
                $tmdbId = intval(substr($id, 7));
                echo "TMDB ID found (direct tmdb:// in Guid): $tmdbId\n";
                break;
            }
        }
    }

    return ['tmdb_id' => $tmdbId, 'language' => $language];
}

function shutdown() {
    global $db;

    if (is_object($db)) {
        $db->close_mysql();
    }

    @unlink(WATCH_TMP_PATH . @getmypid() . '.ppid');
}
