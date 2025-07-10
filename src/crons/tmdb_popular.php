<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        require_once MAIN_HOME . 'includes/libs/tmdb.php';
        cli_set_process_title('XC_VM[Popular]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        if (0 >= strlen(CoreUtilities::$rSettings['tmdb_api_key'])) {
        } else {
            if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
            } else {
                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
            }
            $rPages = 100;
            $rTMDBIDs = array();
            $db->query('SELECT `id`, `movie_properties` FROM `streams` WHERE `type` = 2 AND `movie_properties` IS NOT NULL AND LENGTH(`movie_properties`) > 0;');
            foreach ($db->get_rows() as $rRow) {
                $rProperties = json_decode($rRow['movie_properties'], true);
                if (!$rProperties['tmdb_id']) {
                } else {
                    $rTMDBIDs[$rProperties['tmdb_id']] = $rRow['id'];
                }
            }
            $db->query('SELECT `id`, `tmdb_id` FROM `streams_series` WHERE `tmdb_id` IS NOT NULL AND LENGTH(`tmdb_id`) > 0;');
            foreach ($db->get_rows() as $rRow) {
                $rTMDBIDs[$rRow['tmdb_id']] = $rRow['id'];
            }
            $rReturn = array('movies' => array(), 'series' => array());
            foreach (range(1, $rPages) as $rPage) {
                $rItems = $rTMDB->getPopularMovies($rPage);
                foreach ($rItems as $rItem) {
                    if (!isset($rTMDBIDs[$rItem->getID()])) {
                    } else {
                        $rReturn['movies'][] = $rTMDBIDs[$rItem->getID()];
                    }
                }
            }
            foreach (range(1, $rPages) as $rPage) {
                $rItems = $rTMDB->getPopularTVShows($rPage);
                foreach ($rItems as $rItem) {
                    if (!isset($rTMDBIDs[$rItem->getID()])) {
                    } else {
                        $rReturn['series'][] = $rTMDBIDs[$rItem->getID()];
                    }
                }
            }
            file_put_contents(CONTENT_PATH . 'tmdb_popular', igbinary_serialize($rReturn));
            $db->query('SELECT COUNT(*) AS `count` FROM `streams` WHERE `type` = 2 AND `similar` IS NULL AND `tmdb_id` > 0;');
            $rCount = $db->get_row()['count'];
            if (0 >= $rCount) {
            } else {
                $rSteps = range(0, $rCount, 1000);
                if ($rSteps) {
                } else {
                    $rSteps = array(0);
                }
                foreach ($rSteps as $rStep) {
                    $db->query('SELECT `id`, `tmdb_id` FROM `streams` WHERE `type` = 2 AND `similar` IS NULL AND `tmdb_id` > 0 LIMIT ' . $rStep . ', 1000;');
                    foreach ($db->get_rows() as $rRow) {
                        $rSimilar = array();
                        foreach (range(1, 3) as $rPage) {
                            foreach (json_decode(json_encode($rTMDB->getSimilarMovies($rRow['tmdb_id'], $rPage)), true) as $rItem) {
                                $rSimilar[] = intval($rItem['_data']['id']);
                            }
                        }
                        $rSimilar = array_unique($rSimilar);
                        $db->query('UPDATE `streams` SET `similar` = ? WHERE `id` = ?;', json_encode($rSimilar), $rRow['id']);
                    }
                }
            }
            $db->query('SELECT COUNT(*) AS `count` FROM `streams_series` WHERE `similar` IS NULL AND `tmdb_id` > 0;');
            $rCount = $db->get_row()['count'];
            if (0 >= $rCount) {
            } else {
                $rSteps = range(0, $rCount, 1000);
                if ($rSteps) {
                } else {
                    $rSteps = array(0);
                }
                foreach ($rSteps as $rStep) {
                    $db->query('SELECT `id`, `tmdb_id` FROM `streams_series` WHERE `similar` IS NULL AND `tmdb_id` > 0 LIMIT ' . $rStep . ', 1000;');
                    foreach ($db->get_rows() as $rRow) {
                        $rSimilar = array();
                        foreach (range(1, 3) as $rPage) {
                            foreach (json_decode(json_encode($rTMDB->getSimilarSeries($rRow['tmdb_id'], $rPage)), true) as $rItem) {
                                $rSimilar[] = intval($rItem['id']);
                            }
                        }
                        $rSimilar = array_unique($rSimilar);
                        $db->query('UPDATE `streams_series` SET `similar` = ? WHERE `id` = ?;', json_encode($rSimilar), $rRow['id']);
                    }
                }
            }
        }
        $rPopularLive = array();
        $db->query('SELECT `stream_id`, COUNT(`activity_id`) AS `count` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `type` = 1 AND `date_end` < UNIX_TIMESTAMP() - (86400*28) GROUP BY `stream_id` ORDER BY `count` DESC LIMIT 500;');
        foreach ($db->get_rows() as $rRow) {
            $rPopularLive[] = $rRow['stream_id'];
        }
        file_put_contents(CONTENT_PATH . 'live_popular', igbinary_serialize($rPopularLive));
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
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
