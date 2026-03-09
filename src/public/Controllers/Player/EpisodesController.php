<?php

class EpisodesController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (($rSeries = getSerie(RequestManager::getAll()['id'])) && in_array(RequestManager::getAll()['id'], $rUserInfo['series_ids'])) {
            $rDomainName = DomainResolver::resolve(SERVER_ID, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
            $rTMDB = null;

            if ($rSeries['tmdb_id']) {
                if (!file_exists(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'])) {
                    $rTMDB = getSeriesTMDB($rSeries['tmdb_id']);

                    if ($rTMDB) {
                        file_put_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'], igbinary_serialize($rTMDB));
                    }
                } else {
                    $rTMDB = igbinary_unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id']));
                }
            }

            $rCover = (ImageUtils::validateURL(json_decode($rSeries['backdrop_path'], true)[0]) ?: '');
            $rPoster = (ImageUtils::validateURL($rSeries['cover_big']) ?: '');

            $rSubtitles = $rURLs = $rSeasons = array();

            $db->query('SELECT DISTINCT(`season_num`) AS `season_num` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC;', $rSeries['id']);

            foreach ($db->get_rows() as $rRow) {
                if (SettingsManager::getAll()['player_hide_incompatible']) {
                    $db->query('SELECT MAX(`compatible`) AS `compatible` FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `series_id` = ? AND `season_num` = ?;', $rSeries['id'], $rRow['season_num']);

                    if (!$db->get_row()['compatible']) {
                    } else {
                        $rSeasons[] = $rRow['season_num'];
                    }
                } else {
                    $rSeasons[] = $rRow['season_num'];
                }
            }
            $rSeasonNo = (intval(RequestManager::getAll()['season'] ?? 0) ?: ($rSeasons[0] ?: 1));

            if (SettingsManager::getAll()['player_hide_incompatible']) {
                $db->query('SELECT * FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `series_id` = ? AND `season_num` = ? AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 ORDER BY `episode_num` ASC;', $rSeries['id'], $rSeasonNo);
            } else {
                $db->query('SELECT * FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `series_id` = ? AND `season_num` = ? ORDER BY `episode_num` ASC;', $rSeries['id'], $rSeasonNo);
            }

            $rLegacy = false;
            $rEpisodes = $db->get_rows();

            for ($i = 0; $i < count($rEpisodes); $i++) {
                $rURLs[$rEpisodes[$i]['id']] = $rDomainName . 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rEpisodes[$i]['id'] . '.' . $rEpisodes[$i]['target_container'];
                $rProperties = json_decode($rEpisodes[$i]['movie_properties'], true);
                $rSubtitles[$rEpisodes[$i]['id']] = getSubtitles($rEpisodes[$i]['id'], $rProperties['subtitle'] ?? []);

                if ($rEpisodes[$i]['target_container'] == 'mp4') {
                } else {
                    $rProxySubtitles = array();

                    foreach ($rSubtitles[$rEpisodes[$i]['id']] as $rSubtitle) {
                        $rSubtitle['file'] = 'proxy.php?url=' . Encryption::encrypt($rSubtitle['file'], SettingsManager::getAll()['live_streaming_pass'], 'd8de497ebccf4f4697a1da20219c7c33');
                        $rProxySubtitles[] = $rSubtitle;
                    }
                    $rSubtitles[$rEpisodes[$i]['id']] = $rProxySubtitles;
                    $rLegacy = true;
                }
            }
            $rSeason = null;

            if (!$rSeries['tmdb_id']) {
            } else {
                if (!file_exists(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo)) {
                    $rSeason = getSeasonTMDB($rSeries['tmdb_id'], $rSeasonNo);

                    if (!$rSeason) {
                    } else {
                        file_put_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo, igbinary_serialize($rSeason));
                    }
                } else {
                    $rSeason = igbinary_unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo));
                }
            }

            if ($rSeason && $rSeason['episodes']) {
                $rSeasonArray = array();

                foreach ($rSeason['episodes'] as $rEpisode) {
                    $rSeasonArray[$rEpisode['episode_number']] = array('title' => $rEpisode['name'], 'description' => ($rEpisode['overview'] ?: 'No description is available...'), 'rating' => ($rEpisode['vote_average'] ?: null), 'image' => ($rEpisode['still_path'] ? 'https://image.tmdb.org/t/p/w500' . $rEpisode['still_path'] : ''), 'image_cover' => ($rEpisode['still_path'] ? 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'] : ''));
                }
            } else {
                $rSeasonArray = array();
                foreach ($rEpisodes as $rEpisode) {
                    $rProperties = json_decode($rEpisode['movie_properties'], true);
                    $rSeasonArray[$rEpisode['episode_num']] = array('title' => 'Episode ' . intval($rEpisode['episode_num']), 'description' => ($rProperties['plot'] ?: 'No description is available...'), 'rating' => ($rProperties['rating'] ?: null), 'image' => (str_replace('w600_and_h900_bestv2', 'w500', ImageUtils::validateURL($rProperties['movie_image'])) ?: ''), 'image_cover' => str_replace('w600_and_h900_bestv2', 'w500', ImageUtils::validateURL($rProperties['movie_image'])));
                }
            }

            $rSimilarIDs = array($rSeries['id']);
            $rSimilar = array();
            $rSimilarArray = json_decode($rSeries['similar'], true);

            if (0 >= count($rSimilarArray)) {
            } else {
                if (SettingsManager::getAll()['player_hide_incompatible']) {
                    $db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1 LIMIT 6;');
                } else {
                    $db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') LIMIT 6;');
                }

                foreach ($db->get_rows() as $rRow) {
                    $rSimilar[] = array('type' => 'series', 'id' => $rRow['id'], 'title' => $rRow['title'], 'year' => ($rRow['year'] ?: ($rRow['releaseDate'] ? substr($rRow['releaseDate'], 0, 4) : null)), 'rating' => $rRow['rating'], 'cover' => (ImageUtils::validateURL($rRow['cover']) ?: ''), 'backdrop' => (ImageUtils::validateURL(json_decode($rRow['backdrop_path'], true)[0]) ?: ''));
                    $rSimilarIDs[] = $rRow['id'];
                }
            }

            $GLOBALS['_TITLE'] = $rSeries['title'];
            $GLOBALS['rURLs'] = $rURLs;
            $GLOBALS['rSubtitles'] = $rSubtitles;
            $GLOBALS['rLegacy'] = $rLegacy;
            $GLOBALS['rSeries'] = $rSeries;

            $this->render('episodes', [
                'rSeries' => $rSeries,
                'rCover' => $rCover,
                'rPoster' => $rPoster,
                'rSeasons' => $rSeasons,
                'rSeasonNo' => $rSeasonNo,
                'rEpisodes' => $rEpisodes,
                'rURLs' => $rURLs,
                'rSubtitles' => $rSubtitles,
                'rLegacy' => $rLegacy,
                'rSeasonArray' => $rSeasonArray,
                'rSimilar' => $rSimilar,
            ]);
        } else {
            header('Location: series');
            exit();
        }
    }
}
