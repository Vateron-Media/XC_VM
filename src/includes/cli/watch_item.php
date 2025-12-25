<?php
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        $rTimeout = 60;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        require INCLUDES_PATH . 'libs/tmdb.php';
        require INCLUDES_PATH . 'libs/tmdb_release.php';
        $rThreadData = json_decode(base64_decode($argv[1]), true);
        if ($rThreadData) {
            file_put_contents(WATCH_TMP_PATH . getmypid() . '.wpid', time());
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
function loadcli() {
    global $db;
    global $rThreadData;
    global $rTimeout;
    if (strpos($rThreadData['file'], $rThreadData['directory']) === 0 || $rThreadData['import']) {
        $rWatchCategories = $rThreadData['watch_categories'];
        $rLanguage = null;
        $rReleaseSeason = NULL;
        $rReleaseEpisode = NULL;
        $rYear = null;

        if (!empty($rThreadData['language'])) {
            $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $rThreadData['language']);
            $rLanguage = $rThreadData['language'];
        } else {
            if (!empty(CoreUtilities::$rSettings['tmdb_language'])) {
                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
            } else {
                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
            }
        }
        if ($rThreadData['type'] != 'movie') {
            $rThreadData['extract_metadata'] = false;
        }
        $rImportArray = verifyPostTable('streams');
        $rImportArray['type'] = array('movie' => 2, 'series' => 5)[$rThreadData['type']];
        if ($rImportArray['type']) {
            $rThreadType = array('movie' => 1, 'series' => 2)[$rThreadData['type']];
            $rFile = $rThreadData['file'];
            if ($rThreadData['import']) {
                $rImportArray['stream_source'] = json_encode(array($rFile), JSON_UNESCAPED_UNICODE);
            } else {
                $rImportArray['stream_source'] = json_encode(array('s:' . SERVER_ID . ':' . $rFile), JSON_UNESCAPED_UNICODE);
                $db->query('DELETE FROM `watch_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'), $rThreadType, SERVER_ID);
            }
            if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container']) {
                $rImportArray['target_container'] = $rThreadData['target_container'];
            } else {
                $rImportArray['target_container'] = pathinfo(explode('?', $rFile)[0])['extension'];
            }
            if (empty($rImportArray['target_container'])) {
                $rImportArray['target_container'] = 'mp4';
            }
            $rSourceData = null;
            if ($rThreadData['ffprobe_input'] || $rThreadData['extract_metadata']) {
                $rSourceData = checksource($rFile);
            }
            if (!$rThreadData['ffprobe_input'] || isset($rSourceData['streams'])) {
                $rMatch = $rPaths = null;
                $rMetaMatch = false;
                if ($rThreadData['extract_metadata'] && isset($rSourceData['format']) && $rSourceData['tags']['title']) {
                    $rYear = (intval(explode('-', $rSourceData['tags']['date'])[0]));
                    $rPaths = array($rSourceData['tags']['title']);
                    $rMetaMatch = true;
                }
                if (!$rPaths) {
                    if ($rThreadData['fallback_title']) {
                        $rPaths = array(pathinfo($rFile)['filename'], basename(pathinfo($rFile)['dirname']));
                    } else {
                        $rPaths = array(pathinfo($rFile)['filename']);
                    }
                    $rMetaMatch = false;
                }
                foreach ($rPaths as $rFilename) {
                    echo 'Scanning: ' . $rFilename . "\n";
                    $rTitle = null;
                    $rAltTitle = null;
                    if ($rThreadData['import']) {
                        $rFilename = $rThreadData['title'];
                    }
                    if ($rThreadData['fallback_parser'] && !$rThreadData['disable_tmdb'] && !$rMetaMatch) {
                        $rParseTypes = array(CoreUtilities::$rSettings['parse_type'], (CoreUtilities::$rSettings['parse_type'] == 'guessit' ? 'ptn' : 'guessit'));
                    } else {
                        $rParseTypes = array(CoreUtilities::$rSettings['parse_type']);
                    }
                    foreach ($rParseTypes as $rParseType) {
                        if ($rThreadData['disable_tmdb'] || $rMetaMatch) {
                        } else {
                            $rRelease = parserelease($rFilename, $rParseType);
                            $rTitle = $rRelease['title'];
                            if (isset($rRelease['excess'])) {
                                $rTitle = trim($rTitle, (is_array($rRelease['excess']) ? $rRelease['excess'][0] : $rRelease['excess']));
                            }
                            if (isset($rRelease['group'])) {
                                $rAltTitle = $rTitle . '-' . $rRelease['group'];
                            } else {
                                if (isset($rRelease['alternative_title'])) {
                                    $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                                }
                            }
                            if (isset($rRelease['year'])) {
                                if (!$rYear) {
                                    $rYear = intval($rRelease['year']);
                                }
                            }

                            if ($rThreadData['type'] != 'movie') {
                                if ($rReleaseSeason == null && isset($rRelease['season'])) {
                                    $rReleaseSeason = $rRelease['season'];
                                }
                                if ($rReleaseEpisode == null && isset($rRelease['episode'])) {
                                    if (is_array($rRelease['episode'])) {
                                        $rReleaseEpisode = $rRelease['episode'][0];
                                    } else {
                                        $rReleaseEpisode = $rRelease['episode'];
                                    }
                                }
                            }
                        }
                        if (!($rThreadData['type'] == 'series' && (!$rReleaseSeason || !$rReleaseEpisode))) {
                            if (!$rTitle) {
                                $rTitle = $rFilename;
                            }
                            echo 'Title: ' . $rTitle . "\n";
                            if (!$rThreadData['disable_tmdb']) {
                                $rMatches = array();
                                $rSearchYear = $rYear;
                                foreach (range(0, 1) as $rIgnoreYear) {
                                    if ($rIgnoreYear) {
                                        if ($rSearchYear) {
                                            $rSearchYear = null;
                                        } else {
                                            break;
                                        }
                                    }
                                    if ($rThreadData['type'] == 'movie') {
                                        print_r('Searching Movie: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
                                        $rResults = $rTMDB->searchMovie($rTitle, $rSearchYear);
                                    } else {
                                        print_r('Searching TV Show: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
                                        $rResults = $rTMDB->searchTVShow($rTitle, $rSearchYear);
                                    }
                                    foreach ($rResults as $rResultArr) {
                                        $tmdbTitles = [];

                                        if ($rThreadData['type'] === 'movie') {
                                            $tmdbTitles[] = $rResultArr->get('title');
                                            $tmdbTitles[] = $rResultArr->get('original_title');
                                        } else {
                                            $tmdbTitles[] = $rResultArr->get('name');
                                            $tmdbTitles[] = $rResultArr->get('original_name');
                                        }

                                        $tmdbTitles = array_filter($tmdbTitles);

                                        $rPercentage = 0;
                                        $rPercentageAlt = 0;

                                        foreach ($tmdbTitles as $tmdbTitle) {
                                            similar_text(parseTitle($rTitle), parseTitle($tmdbTitle), $p);
                                            $rPercentage = max($rPercentage, $p);

                                            if ($rAltTitle) {
                                                similar_text(parseTitle($rAltTitle), parseTitle($tmdbTitle), $pAlt);
                                                $rPercentageAlt = max($rPercentageAlt, $pAlt);
                                            }
                                        }


                                        $rPercentageAlt = 0;
                                        if ($rAltTitle) {
                                            similar_text(parseTitle($rAltTitle), parseTitle(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentageAlt);
                                        }
                                        if (CoreUtilities::$rSettings['percentage_match'] <= $rPercentage || CoreUtilities::$rSettings['percentage_match'] <= $rPercentageAlt) {
                                            if ($rSearchYear && !in_array(intval(substr(($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date')), 0, 4)), range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
                                            } else {
                                                if ($rAltTitle && parseTitle(($rResultArr->get('title') ?: $rResultArr->get('name'))) == parseTitle($rAltTitle)) {
                                                    $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                    break;
                                                }
                                                foreach ($tmdbTitles as $tmdbTitle) {
                                                    if ($rAltTitle && parseTitle($tmdbTitle) === parseTitle($rAltTitle)) {
                                                        $rMatches = [['percentage' => 100, 'data' => $rResultArr]];
                                                        break 2;
                                                    }

                                                    if (!$rAltTitle && parseTitle($tmdbTitle) === parseTitle($rTitle)) {
                                                        $rMatches = [['percentage' => 100, 'data' => $rResultArr]];
                                                        break 2;
                                                    }
                                                }
                                                $rMatches[] = array('percentage' => $rPercentage, 'data' => $rResultArr);
                                            }
                                        } else {
                                            if ($rThreadData['alternative_titles'] && in_array(intval(substr(($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date')), 0, 4)), range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
                                                $rPartialMatch = false;

                                                foreach ($tmdbTitles as $tmdbTitle) {
                                                    if (strpos(parseTitle($rTitle), parseTitle($tmdbTitle)) === 0) {
                                                        $rPartialMatch = true;
                                                        break;
                                                    }

                                                    if ($rAltTitle && strpos(parseTitle($rAltTitle), parseTitle($tmdbTitle)) === 0) {
                                                        $rPartialMatch = true;
                                                        break;
                                                    }
                                                }
                                                if ($rPartialMatch) {
                                                    if ($rThreadData['type'] == 'movie') {
                                                        $rAlternativeTitles = $rTMDB->getMovieTitles($rResultArr->get('id'))['titles'];
                                                    } else {
                                                        $rAlternativeTitles = $rTMDB->getSeriesTitles($rResultArr->get('id'))['titles'];
                                                    }
                                                    foreach ($rAlternativeTitles as $rAlternativeTitle) {
                                                        if ($rAltTitle && parseTitle($rAlternativeTitle['title']) == parseTitle($rAltTitle)) {
                                                            $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                            break;
                                                        }
                                                        if (parseTitle($rAlternativeTitle['title']) != parseTitle($rTitle) || $rAltTitle) {
                                                        } else {
                                                            $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    if (count($rMatches) > 0) {
                                        break;
                                    }
                                }
                                if (count($rMatches) > 0) {
                                    $rMax = max(array_column($rMatches, 'percentage'));
                                    $rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
                                        return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
                                    }, $rMatches));
                                    list($rMatch) = array_values($rKeys);
                                }
                            }
                            if ($rMatch) {
                                break;
                            }
                        } else {
                            $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 4, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                            exit();
                        }
                    }
                }
                if (!$rMatch && !$rThreadData['ignore_no_match']) {
                    echo 'No match!' . "\n";
                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 4, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                    exit();
                }
                $rBouquetIDs = array();
                $rCategoryIDs = array();
                if (!empty($rThreadData['category_id'])) {
                    if (is_array($rThreadData['category_id'])) {
                        $rCategoryIDs = array_map('intval', $rThreadData['category_id']);
                    } else {
                        $rCategoryIDs = array(intval($rThreadData['category_id']));
                    }
                }
                if (!empty($rThreadData['bouquets'])) {
                    if (is_array($rThreadData['bouquets'])) {
                        $rBouquetIDs = array_map('intval', $rThreadData['bouquets']);
                    } else {
                        $rBouquetIDs = json_decode($rThreadData['bouquets'], true);
                    }
                }
                if ($rMatch) {
                    if ($rThreadData['type'] == 'movie') {
                        if ($rThreadData['duplicate_tmdb']) {
                            $rUpgradeData = null;
                        } else {
                            $rUpgradeData = getMovie($rMatch->get('id'));
                        }

                        if ($rUpgradeData) {
                            if ($rThreadData['auto_upgrade']) {
                                if (substr($rUpgradeData['source'], 0, 3 + strlen(strval(SERVER_ID))) != 's:' . SERVER_ID . ':') {
                                    echo "Old file path doesn't match this server, don't upgrade." . "\n";
                                    exit();
                                }
                                list(, $rActualPath) = explode('s:' . SERVER_ID . ':', $rUpgradeData['source']);
                                if (!file_exists($rActualPath) || filesize($rActualPath) < filesize($rFile)) {
                                    echo 'Upgrade movie!' . "\n";
                                    $db->query('UPDATE `streams` SET `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);
                                    $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], SERVER_ID);
                                    if ($rThreadData['auto_encode']) {
                                        CoreUtilities::queueMovie($rUpgradeData['id']);
                                    }
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                                    file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(array('id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile)));
                                    exit();
                                }
                                echo "File isn't a better source, don't upgrade." . "\n";
                                exit();
                            }
                            echo 'Upgrade disabled' . "\n";
                            exit();
                        }
                        $rMovie = $rTMDB->getMovie($rMatch->get('id'));
                        $rMovieData = json_decode($rMovie->getJSON(), true);
                        $rMovieData['trailer'] = $rMovie->getTrailer();
                        $rThumb = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'];
                        $rBG = 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'];
                        if (CoreUtilities::$rSettings['download_images']) {
                            $rThumb = CoreUtilities::downloadImage($rThumb);
                            $rBG = CoreUtilities::downloadImage($rBG);
                        }
                        $rCast = array();
                        foreach ($rMovieData['credits']['cast'] as $rMember) {
                            if (count($rCast) >= 5) {
                            } else {
                                $rCast[] = $rMember['name'];
                            }
                        }
                        $rDirectors = array();
                        foreach ($rMovieData['credits']['crew'] as $rMember) {
                            if (!(count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing')) || in_array($rMember['name'], $rDirectors)) {
                            } else {
                                $rDirectors[] = $rMember['name'];
                            }
                        }
                        $rCountry = '';
                        if (isset($rMovieData['production_countries'][0]['name'])) {
                            $rCountry = $rMovieData['production_countries'][0]['name'];
                        }
                        $rGenres = array();
                        foreach ($rMovieData['genres'] as $rGenre) {
                            if (count($rGenres) >= 3) {
                            } else {
                                $rGenres[] = $rGenre['name'];
                            }
                        }
                        $rSeconds = intval($rMovieData['runtime']) * 60;
                        $rImportArray['stream_display_name'] = $rMovieData['title'];
                        if (strlen($rMovieData['release_date']) > 0) {
                            $rImportArray['year'] = intval(substr($rMovieData['release_date'], 0, 4));
                        }
                        $rImportArray['tmdb_id'] = ($rMovieData['id'] ?: null);
                        $rImportArray['movie_properties'] = array('kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => array($rBG), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rMovieData['vote_average']);
                        $rImportArray['rating'] = ($rImportArray['movie_properties']['rating'] ?: 0);
                        $rImportArray['read_native'] = $rThreadData['read_native'];
                        $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
                        $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
                        $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
                        if ($rThreadData['import']) {
                            $rImportArray['direct_source'] = $rThreadData['direct_source'];
                            $rImportArray['direct_proxy'] = $rThreadData['direct_proxy'];
                        }
                        $rImportArray['order'] = getNextOrder();
                        $rImportArray['tmdb_language'] = $rLanguage;
                        if (count($rCategoryIDs) == 0) {
                            if (0 < $rThreadData['max_genres']) {
                                $rParsed = array_slice($rMovieData['genres'], 0, $rThreadData['max_genres']);
                            } else {
                                $rParsed = $rMovieData['genres'];
                            }
                            foreach ($rParsed as $rGenre) {
                                $rCategoryID = intval($rWatchCategories[1][intval($rGenre['id'])]['category_id']);
                                if ($rCategoryID > 0) {
                                    if (!in_array($rCategoryID, $rCategoryIDs)) {
                                        $rCategoryIDs[] = $rCategoryID;
                                    }
                                }
                            }
                        }
                        if (count($rBouquetIDs) == 0) {
                            if (0 < $rThreadData['max_genres']) {
                                $rParsed = array_slice($rMovieData['genres'], 0, $rThreadData['max_genres']);
                            } else {
                                $rParsed = $rMovieData['genres'];
                            }
                            foreach ($rParsed as $rGenre) {
                                $rBouquets = json_decode($rWatchCategories[1][intval($rGenre['id'])]['bouquets'], true);
                                foreach ($rBouquets as $rBouquetID) {
                                    if (!in_array($rBouquetID, $rBouquetIDs)) {
                                        $rBouquetIDs[] = $rBouquetID;
                                    }
                                }
                            }
                        }
                    } else {
                        $rShow = $rTMDB->getTVShow($rMatch->get('id'));
                        if ($rThreadData['duplicate_tmdb']) {
                            $rUpgradeData = null;
                        } else {
                            $rUpgradeData = getEpisode($rMatch->get('id'), $rReleaseSeason, $rReleaseEpisode);
                        }
                        if ($rUpgradeData) {
                            if ($rThreadData['auto_upgrade']) {
                                if (substr($rUpgradeData['source'], 0, 3 + strlen(strval(SERVER_ID))) != 's:' . SERVER_ID . ':') {
                                    echo "Old file path doesn't match this server, don't upgrade." . "\n";
                                    exit();
                                }
                                list(, $rActualPath) = explode('s:' . SERVER_ID . ':', $rUpgradeData['source']);
                                if (!file_exists($rActualPath) || filesize($rActualPath) < filesize($rFile)) {
                                    echo 'Upgrade episode!' . "\n";
                                    $db->query('UPDATE `streams` SET `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);
                                    $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], SERVER_ID);
                                    if ($rThreadData['auto_encode']) {
                                        CoreUtilities::queueMovie($rUpgradeData['id']);
                                    }
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 6, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                                    $rCacheData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache'), true);
                                    $rCacheData[$rReleaseSeason . '_' . $rReleaseEpisode] = array('id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile);
                                    file_put_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache', json_encode($rCacheData));
                                    exit();
                                }
                                echo "File isn't a better source, don't upgrade." . "\n";
                                exit();
                            }
                            echo 'Upgrade disabled' . "\n";
                            exit();
                        }
                        $rShowData = json_decode($rShow->getJSON(), true);
                        if ($rShowData['id']) {
                            while (file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
                                if ($rTimeout >= time() - filemtime(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
                                } else {
                                    unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
                                }
                                usleep(100000);
                            }
                            $rFileLock = fopen(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']), 'w');
                            while (!flock($rFileLock, LOCK_EX)) {
                                usleep(100000);
                            }
                            fwrite($rFileLock, time());
                            $rSeasonData = array();
                            foreach ($rShowData['seasons'] as $rSeason) {
                                $rSeason['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rSeason['poster_path'];
                                if (CoreUtilities::$rSettings['download_images']) {
                                    $rSeason['cover'] = CoreUtilities::downloadImage($rSeason['cover'], 2);
                                }
                                $rSeason['cover_big'] = $rSeason['cover'];
                                unset($rSeason['poster_path']);
                                $rSeasonData[] = $rSeason;
                            }
                            $rSeries = getSeriesByTMDB($rShowData['id']);
                            if (!$rSeries) {
                                $rSeriesArray = array('title' => $rShowData['name'], 'category_id' => array(), 'episode_run_time' => 0, 'tmdb_id' => $rShowData['id'], 'cover' => '', 'genre' => '', 'plot' => $rShowData['overview'], 'cast' => '', 'rating' => $rShowData['vote_average'], 'director' => '', 'release_date' => $rShowData['first_air_date'], 'last_modified' => time(), 'seasons' => $rSeasonData, 'backdrop_path' => array(), 'youtube_trailer' => '', 'year' => null);
                                $rSeriesArray['youtube_trailer'] = getSeriesTrailer($rShowData['id'], (!empty($rThreadData['language']) ? $rThreadData['language'] : CoreUtilities::$rSettings['tmdb_language']));
                                $rSeriesArray['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rShowData['poster_path'];
                                $rSeriesArray['cover_big'] = $rSeriesArray['cover'];
                                $rSeriesArray['backdrop_path'] = array('https://image.tmdb.org/t/p/w1280' . $rShowData['backdrop_path']);
                                if (CoreUtilities::$rSettings['download_images']) {
                                    $rSeriesArray['cover'] = CoreUtilities::downloadImage($rSeriesArray['cover'], 2);
                                    $rSeriesArray['backdrop_path'] = array(CoreUtilities::downloadImage($rSeriesArray['backdrop_path'][0]));
                                }
                                $rCast = array();
                                foreach ($rShowData['credits']['cast'] as $rMember) {
                                    if (count($rCast) >= 5) {
                                    } else {
                                        $rCast[] = $rMember['name'];
                                    }
                                }
                                $rSeriesArray['cast'] = implode(', ', $rCast);
                                $rDirectors = array();
                                foreach ($rShowData['credits']['crew'] as $rMember) {
                                    if (!(count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing')) || in_array($rMember['name'], $rDirectors)) {
                                    } else {
                                        $rDirectors[] = $rMember['name'];
                                    }
                                }
                                $rSeriesArray['director'] = implode(', ', $rDirectors);
                                $rGenres = array();
                                foreach ($rShowData['genres'] as $rGenre) {
                                    if (count($rGenres) >= $rThreadData['max_genres']) {
                                    } else {
                                        $rGenres[] = $rGenre['name'];
                                    }
                                }
                                if ($rShowData['first_air_date']) {
                                    $rSeriesArray['year'] = intval(substr($rShowData['first_air_date'], 0, 4));
                                }
                                $rSeriesArray['genre'] = implode(', ', $rGenres);
                                $rSeriesArray['episode_run_time'] = intval($rShowData['episode_run_time'][0]);
                                if (count($rCategoryIDs) == 0) {
                                    if (0 < $rThreadData['max_genres']) {
                                        $rParsed = array_slice($rShowData['genres'], 0, $rThreadData['max_genres']);
                                    } else {
                                        $rParsed = $rShowData['genres'];
                                    }
                                    foreach ($rParsed as $rGenre) {
                                        $rCategoryID = intval($rWatchCategories[2][intval($rGenre['id'])]['category_id']);
                                        if ($rCategoryID > 0) {
                                            if (!in_array($rCategoryID, $rCategoryIDs)) {
                                                $rCategoryIDs[] = $rCategoryID;
                                            }
                                        }
                                    }
                                }
                                if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
                                    if (is_array($rThreadData['fb_category_id'])) {
                                        $rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
                                    } else {
                                        $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                                    }
                                }
                                if (count($rBouquetIDs) == 0) {
                                    if (0 < $rThreadData['max_genres']) {
                                        $rParsed = array_slice($rShowData['genres'], 0, $rThreadData['max_genres']);
                                    } else {
                                        $rParsed = $rShowData['genres'];
                                    }
                                    foreach ($rParsed as $rGenre) {
                                        $rBouquets = json_decode($rWatchCategories[2][intval($rGenre['id'])]['bouquets'], true);
                                        foreach ($rBouquets as $rBouquetID) {
                                            if (!in_array($rBouquetID, $rBouquetIDs)) {
                                                $rBouquetIDs[] = $rBouquetID;
                                            }
                                        }
                                    }
                                }
                                if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
                                    if (is_array($rThreadData['fb_bouquets'])) {
                                        $rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
                                    } else {
                                        $rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
                                    }
                                }
                                if (count($rCategoryIDs) != 0) {
                                    $rSeriesArray['tmdb_language'] = $rLanguage;
                                    $rSeriesArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                                    $rPrepare = prepareArray($rSeriesArray);
                                    $rQuery = 'INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                                    if ($db->query($rQuery, ...$rPrepare['data'])) {
                                        $rInsertID = $db->last_insert_id();
                                        $rSeries = getSerie($rInsertID);
                                        file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
                                        foreach ($rBouquetIDs as $rBouquet) {
                                            addToBouquet('series', $rBouquet, $rInsertID);
                                        }
                                    } else {
                                        $rSeries = null;
                                    }
                                } else {
                                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 3, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                                    exit();
                                }
                            } else {
                                $db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
                                if (file_exists(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']))) {
                                } else {
                                    file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
                                }
                            }
                            flock($rFileLock, LOCK_UN);
                            unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
                            $rImportArray['read_native'] = $rThreadData['read_native'];
                            $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
                            $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
                            $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
                            if ($rThreadData['import']) {
                                $rImportArray['direct_source'] = $rThreadData['direct_source'];
                                $rImportArray['direct_proxy'] = $rThreadData['direct_proxy'];
                            }
                            $rImportArray['order'] = getNextOrder();
                            if ($rReleaseSeason && $rReleaseEpisode) {
                                if (is_array($rRelease['episode']) && count($rRelease['episode']) == 2) {
                                    $rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rRelease['episode'][0]) . '-' . sprintf('%02d', $rRelease['episode'][1]);
                                } else {
                                    $rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode);
                                }
                                $rEpisodes = json_decode($rTMDB->getSeason($rShowData['id'], intval($rReleaseSeason))->getJSON(), true);
                                foreach ($rEpisodes['episodes'] as $rEpisode) {
                                    if (intval($rEpisode['episode_number']) == $rReleaseEpisode) {
                                        if (strlen($rEpisode['still_path']) > 0) {
                                            $rImage = 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'];
                                            if (CoreUtilities::$rSettings['download_images']) {
                                                $rImage = CoreUtilities::downloadImage($rImage, 5);
                                            }
                                        }
                                        if (strlen($rEpisode['name']) > 0) {
                                            $rImportArray['stream_display_name'] .= ' - ' . $rEpisode['name'];
                                        }
                                        $rSeconds = intval($rShowData['episode_run_time'][0]) * 60;
                                        $rImportArray['movie_properties'] = array('tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rReleaseSeason);
                                        if (strlen($rImportArray['movie_properties']['movie_image'][0]) == 0) {
                                            unset($rImportArray['movie_properties']['movie_image']);
                                        }
                                    }
                                }
                                if (strlen($rImportArray['stream_display_name']) == 0) {
                                    $rImportArray['stream_display_name'] = 'No Episode Title';
                                }
                            }
                        }
                    }
                } else {
                    if ($rThreadData['type'] == 'movie') {
                        $rImportArray['stream_display_name'] = $rTitle;
                        if ($rYear) {
                            $rImportArray['year'] = $rYear;
                        }
                    } else {
                        if ($rReleaseSeason && $rReleaseEpisode) {
                            $rImportArray['stream_display_name'] = $rTitle . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode) . ' - ';
                        }
                    }
                    $rImportArray['read_native'] = $rThreadData['read_native'];
                    $rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
                    $rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
                    $rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
                    if ($rThreadData['import']) {
                        $rImportArray['direct_source'] = $rThreadData['direct_source'];
                        $rImportArray['direct_proxy'] = $rThreadData['direct_proxy'];
                    }
                    $rImportArray['order'] = getNextOrder();
                    $rImportArray['tmdb_language'] = $rLanguage;
                }
                if ($rThreadData['type'] == 'movie') {
                    if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
                        if (is_array($rThreadData['fb_category_id'])) {
                            $rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
                        } else {
                            $rCategoryIDs = array(intval($rThreadData['fb_category_id']));
                        }
                    }
                    if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
                        if (is_array($rThreadData['fb_bouquets'])) {
                            $rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
                        } else {
                            $rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
                        }
                    }
                    $rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                    if (count($rCategoryIDs) == 0) {
                        $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 3, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                        exit();
                    }
                } else {
                    if ($rSeries) {
                        $rImportArray['series_no'] = $rSeries['id'];
                    } else {
                        $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 4, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                        exit();
                    }
                }
                if ($rThreadData['subtitles']) {
                    $rImportArray['movie_subtitles'] = $rThreadData['subtitles'];
                }
                $rImportArray['added'] = time();
                $rPrepare = prepareArray($rImportArray);
                $rQuery = 'INSERT INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                if ($db->query($rQuery, ...$rPrepare['data'])) {
                    $rInsertID = $db->last_insert_id();
                    if ($rThreadData['import']) {
                        foreach ($rThreadData['servers'] as $rServerID) {
                            $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
                        }
                    } else {
                        $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, SERVER_ID);
                    }
                    if ($rThreadData['type'] == 'movie') {
                        if (!$rMatch || $rThreadData['import']) {
                        } else {
                            file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(array('id' => $rInsertID, 'source' => 's:' . SERVER_ID . ':' . $rFile)));
                        }
                        foreach ($rBouquetIDs as $rBouquet) {
                            addToBouquet('movie', $rBouquet, $rInsertID);
                        }
                    } else {
                        $db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rReleaseSeason, $rSeries['id'], $rInsertID, $rReleaseEpisode);
                    }
                    if ($rThreadData['auto_encode']) {
                        if ($rThreadData['import']) {
                            foreach ($rThreadData['servers'] as $rServerID) {
                                CoreUtilities::queueMovie($rInsertID, $rServerID);
                            }
                        } else {
                            CoreUtilities::queueMovie($rInsertID);
                        }
                    }
                    echo 'Success!' . "\n";
                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 1, ?);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'), $rInsertID);
                    exit();
                } else {
                    echo 'Insert failed!' . "\n";
                    $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 2, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                    exit();
                }
            } else {
                echo 'File is broken!' . "\n";
                $db->query('INSERT INTO `watch_logs`(`type`, `server_id`, `filename`, `status`, `stream_id`) VALUES(?, ?, ?, 5, 0);', $rThreadType, SERVER_ID, htmlspecialchars($rFile, ENT_QUOTES, 'UTF-8'));
                exit();
            }
        } else {
            exit();
        }
    } else {
        echo 'Incorrect root directory!';
        exit();
    }
}

function addToBouquet($rType, $rBouquetID, $rID) {
    global $db;
    global $rThreadData;
    if ($rThreadData['import']) {
        $rBouquet = getBouquet($rBouquetID);
        if ($rBouquet) {
            if ($rType == 'stream') {
                $rColumn = 'bouquet_channels';
            } elseif ($rType == 'movie') {
                $rColumn = 'bouquet_movies';
            } elseif ($rType == 'radio') {
                $rColumn = 'bouquet_radios';
            } else {
                $rColumn = 'bouquet_series';
            }
            $rChannels = confirmIDs(json_decode($rBouquet[$rColumn], true));
            if (0 >= intval($rID) || in_array($rID, $rChannels)) {
            } else {
                $rChannels[] = $rID;
                if (count($rChannels) > 0) {
                    $db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
                }
            }
        }
    } else {
        file_put_contents(WATCH_TMP_PATH . md5($rThreadData['file'] . '_' . $rType . '_' . $rBouquetID . '_' . $rID) . '.bouquet', json_encode(array('type' => $rType, 'bouquet_id' => $rBouquetID, 'id' => $rID)));
    }
}

function parserelease($rRelease, $rType = 'guessit') {
    if ($rType == 'guessit') {
        $rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg($rRelease . '.mkv');
    } else {
        $rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'includes/python/release.py ' . escapeshellarg(str_replace('-', '_', $rRelease));
    }
    return json_decode(shell_exec($rCommand), true);
}

function getMovie($rTMDBID) {
    if (file_exists(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache')) {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache'), true);
    }
}

function getEpisode($rTMDBID, $rSeason, $rEpisode) {
    if (file_exists(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache')) {
        $rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache'), true);
        if (isset($rData[$rSeason . '_' . $rEpisode])) {
            return $rData[$rSeason . '_' . $rEpisode];
        }
    }
}

function parseTitle($rTitle) {
    return strtolower(preg_replace("/(?![.=\$'€%-])\\p{P}/u", '', $rTitle));
}

function checksource($rFilename) {
    $rCommand = 'timeout 10 ' . CoreUtilities::$rFFPROBE . ' -show_streams -show_format -v quiet ' . escapeshellarg($rFilename) . ' -of json';
    return json_decode(shell_exec($rCommand), true);
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
        if (is_array($rValue)) {
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
        if ($rRow['column_default'] == 'NULL') {
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
            if (!$rOnlyExisting) {
                $rReturn[$rRow['column_name']] = $rRow['column_default'];
            }
        }
    }
    return $rReturn;
}

function getSeriesByTMDB($rID) {
    global $db;
    if (!(file_exists(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') && time() - filemtime(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') < 360)) {
        $db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);
        if ($db->num_rows() == 1) {
            return $db->get_row();
        }
    } else {
        return json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data'), true);
    }
}

function getSeriesTrailer($rTMDBID, $rLanguage = null) {
    $rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(CoreUtilities::$rSettings['tmdb_api_key']);
    if ($rLanguage) {
        $rURL .= '&language=' . urlencode($rLanguage);
    } else {
        if (strlen(CoreUtilities::$rSettings['tmdb_language']) > 0) {
            $rURL .= '&language=' . urlencode(CoreUtilities::$rSettings['tmdb_language']);
        }
    }
    $rJSON = json_decode(file_get_contents($rURL), true);
    foreach ($rJSON['results'] as $rVideo) {
        if (strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube') {
            return $rVideo['key'];
        }
    }
    return '';
}

function getSerie($rID) {
    global $db;
    $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
    if ($db->num_rows() == 1) {
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

function confirmIDs($rIDs) {
    $rReturn = array();
    foreach ($rIDs as $rID) {
        if (intval($rID) > 0) {
            $rReturn[] = $rID;
        }
    }
    return $rReturn;
}

function getBouquet($rID) {
    global $db;
    $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
    if ($db->num_rows() == 1) {
        return $db->get_row();
    }
}

function shutdown() {
    global $db;
    global $rShowData;
    if (is_array($rShowData) && $rShowData['id'] && file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
        unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
    }
    if (is_object($db)) {
        $db->close_mysql();
    }
    @unlink(WATCH_TMP_PATH . @getmypid() . '.wpid');
}
