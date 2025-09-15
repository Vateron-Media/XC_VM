<?php
if ((posix_getpwuid(posix_geteuid())['name'] ?? null) === 'xc_vm') {
    set_time_limit(0);

    if (!empty($argc)) {
        register_shutdown_function('shutdown');

        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        require_once MAIN_HOME . 'includes/libs/tmdb.php';

        cli_set_process_title('XC_VM[Popular]');

        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);

        if (strlen(CoreUtilities::$rSettings['tmdb_api_key'] ?? '') > 0) {
            $lang = CoreUtilities::$rSettings['tmdb_language'] ?? '';
            $rTMDB = $lang !== ''
                ? new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $lang)
                : new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);

            $rPages   = 100;
            $rTMDBIDs = [];

            // --- Movies
            $db->query(
                'SELECT `id`, `movie_properties` FROM `streams`
                 WHERE `type` = 2 AND `movie_properties` IS NOT NULL AND LENGTH(`movie_properties`) > 0;'
            );
            foreach ($db->get_rows() as $rRow) {
                $rProperties = json_decode($rRow['movie_properties'], true);
                if (!empty($rProperties['tmdb_id'])) {
                    $rTMDBIDs[$rProperties['tmdb_id']] = $rRow['id'];
                }
            }

            // --- Series
            $db->query(
                'SELECT `id`, `tmdb_id` FROM `streams_series`
                 WHERE `tmdb_id` IS NOT NULL AND LENGTH(`tmdb_id`) > 0;'
            );
            foreach ($db->get_rows() as $rRow) {
                $rTMDBIDs[$rRow['tmdb_id']] = $rRow['id'];
            }

            $rReturn = ['movies' => [], 'series' => []];

            // Popular movies
            foreach (range(1, $rPages) as $rPage) {
                foreach ($rTMDB->getPopularMovies($rPage) as $rItem) {
                    $id = $rItem->getID();
                    if (isset($rTMDBIDs[$id])) {
                        $rReturn['movies'][] = $rTMDBIDs[$id];
                    }
                }
            }

            // Popular TV shows
            foreach (range(1, $rPages) as $rPage) {
                foreach ($rTMDB->getPopularTVShows($rPage) as $rItem) {
                    $id = $rItem->getID();
                    if (isset($rTMDBIDs[$id])) {
                        $rReturn['series'][] = $rTMDBIDs[$id];
                    }
                }
            }

            file_put_contents(CONTENT_PATH . 'tmdb_popular', igbinary_serialize($rReturn));

            // Similar Movies
            $db->query(
                'SELECT COUNT(*) AS `count` FROM `streams`
                 WHERE `type` = 2 AND `similar` IS NULL AND `tmdb_id` > 0;'
            );
            $rCount = (int)($db->get_row()['count'] ?? 0);
            if ($rCount > 0) {
                $rSteps = $rCount >= 1000 ? range(0, $rCount, 1000) : [0];
                foreach ($rSteps as $rStep) {
                    $db->query(
                        'SELECT `id`, `tmdb_id` FROM `streams`
                         WHERE `type` = 2 AND `similar` IS NULL AND `tmdb_id` > 0
                         LIMIT ' . $rStep . ', 1000;'
                    );
                    foreach ($db->get_rows() as $rRow) {
                        $rSimilar = [];
                        foreach (range(1, 3) as $rPage) {
                            $items = $rTMDB->getSimilarMovies($rRow['tmdb_id'], $rPage);
                            foreach (json_decode(json_encode($items), true) as $rItem) {
                                $rSimilar[] = (int)($rItem['_data']['id'] ?? 0);
                            }
                        }
                        $db->query(
                            'UPDATE `streams` SET `similar` = ? WHERE `id` = ?;',
                            json_encode(array_values(array_unique($rSimilar))),
                            $rRow['id']
                        );
                    }
                }
            }

            // Similar Series
            $db->query(
                'SELECT COUNT(*) AS `count` FROM `streams_series`
                 WHERE `similar` IS NULL AND `tmdb_id` > 0;'
            );
            $rCount = (int)($db->get_row()['count'] ?? 0);
            if ($rCount > 0) {
                $rSteps = $rCount >= 1000 ? range(0, $rCount, 1000) : [0];
                foreach ($rSteps as $rStep) {
                    $db->query(
                        'SELECT `id`, `tmdb_id` FROM `streams_series`
                         WHERE `similar` IS NULL AND `tmdb_id` > 0
                         LIMIT ' . $rStep . ', 1000;'
                    );
                    foreach ($db->get_rows() as $rRow) {
                        $rSimilar = [];
                        foreach (range(1, 3) as $rPage) {
                            $items = $rTMDB->getSimilarSeries($rRow['tmdb_id'], $rPage);
                            foreach (json_decode(json_encode($items), true) as $rItem) {
                                $rSimilar[] = (int)($rItem['id'] ?? 0);
                            }
                        }
                        $db->query(
                            'UPDATE `streams_series` SET `similar` = ? WHERE `id` = ?;',
                            json_encode(array_values(array_unique($rSimilar))),
                            $rRow['id']
                        );
                    }
                }
            }
        }

        // Popular live streams
        $rPopularLive = [];
        $db->query(
            'SELECT `stream_id`, COUNT(`activity_id`) AS `count`
             FROM `lines_activity`
             LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id`
             WHERE `type` = 1 AND `date_end` < UNIX_TIMESTAMP() - (86400*28)
             GROUP BY `stream_id`
             ORDER BY `count` DESC
             LIMIT 500;'
        );
        foreach ($db->get_rows() as $rRow) {
            $rPopularLive[] = $rRow['stream_id'];
        }
        file_put_contents(CONTENT_PATH . 'live_popular', igbinary_serialize($rPopularLive));
    } else {
        exit(0);
    }
} else {
    exit("Please run as XC_VM!\n");
}

function shutdown(): void {
    global $db, $rIdentifier;
    if (isset($db) && is_object($db)) {
        $db->close_mysql();
    }
    if (!empty($rIdentifier) && file_exists($rIdentifier)) {
        @unlink($rIdentifier);
    }
}
