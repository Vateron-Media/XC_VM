<?php

class PlayerMovieController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (($rStream = getStream(RequestManager::getAll()['id'])) && in_array(RequestManager::getAll()['id'], $rUserInfo['vod_ids'])) {
            $rProperties = json_decode($rStream['movie_properties'], true);
            $rSubtitles = array(getSubtitles($rStream['id'], $rProperties['subtitle'] ?? []));
            $rDomainName = DomainResolver::resolve(SERVER_ID, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
            $rURLs = array($rDomainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . $rStream['target_container']);
            $rLegacy = false;

            if ($rStream['target_container'] != 'mp4') {
                $rLegacy = true;
            }

            if ($rProperties['tmdb_id']) {
                if (!file_exists(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id'])) {
                    $rTMDB = json_decode(json_encode(getMovieTMDB($rProperties['tmdb_id'])), true);

                    if ($rTMDB) {
                        file_put_contents(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id'], igbinary_serialize($rTMDB));
                    }
                } else {
                    $rTMDB = igbinary_unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id']));
                }
            }

            $rCover = (ImageUtils::validateURL($rProperties['backdrop_path'][0]) ?: '');
            $rPoster = (ImageUtils::validateURL($rProperties['cover_big']) ?: '');

            $rSimilarIDs = array($rStream['id']);
            $rSimilar = array();
            $rSimilarArray = json_decode($rStream['similar'], true);

            if (0 >= count($rSimilarArray)) {
            } else {
                if (SettingsManager::getAll()['player_hide_incompatible']) {
                    $db->query('SELECT * FROM `streams` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 LIMIT 6;');
                } else {
                    $db->query('SELECT * FROM `streams` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') LIMIT 6;');
                }

                foreach ($db->get_rows() as $rRow) {
                    $rSimilarProperties = json_decode($rRow['movie_properties'], true);
                        $rSimilar[] = array('type' => 'movie', 'id' => $rRow['id'], 'title' => ($rRow['title'] ?? $rRow['stream_display_name']), 'year' => ($rRow['year'] ?: null), 'rating' => $rSimilarProperties['rating'], 'cover' => (ImageUtils::validateURL($rSimilarProperties['movie_image']) ?: ''), 'backdrop' => (ImageUtils::validateURL($rSimilarProperties['backdrop_path'][0] ?? '') ?: ''));
                    $rSimilarIDs[] = $rRow['id'];
                }
            }

            if (count($rSimilar) >= 6) {
            } else {
                if (0 < count($rSimilarIDs)) {
                    $rPrevious = '`stream_id` NOT IN (' . implode(',', $rSimilarIDs) . ') AND ';
                } else {
                    $rPrevious = '';
                }

                if (SettingsManager::getAll()['player_hide_incompatible']) {
                    $db->query('SELECT `streams`.*, COUNT(`user_id`) AS `count` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `user_id` IN (SELECT DISTINCT(`user_id`) FROM `lines_activity` WHERE `stream_id` = ? AND (`date_end` - `date_start` > 60)) AND `type` = 2 AND ' . $rPrevious . ' `stream_id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 GROUP BY `stream_id` ORDER BY `count` DESC LIMIT ' . (6 - count($rSimilar)) . ';', $rStream['id']);
                } else {
                    $db->query('SELECT `streams`.*, COUNT(`user_id`) AS `count` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `user_id` IN (SELECT DISTINCT(`user_id`) FROM `lines_activity` WHERE `stream_id` = ? AND (`date_end` - `date_start` > 60)) AND `type` = 2 AND ' . $rPrevious . ' `stream_id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') GROUP BY `stream_id` ORDER BY `count` DESC LIMIT ' . (6 - count($rSimilar)) . ';', $rStream['id']);
                }

                foreach ($db->get_rows() as $rRow) {
                    if (!$rRow['id']) {
                    } else {
                        $rSimilarProperties = json_decode($rRow['movie_properties'], true);
                        $rSimilar[] = array('type' => 'movie', 'id' => $rRow['id'], 'title' => ($rRow['title'] ?? $rRow['stream_display_name']), 'year' => ($rRow['year'] ?: null), 'rating' => $rSimilarProperties['rating'], 'cover' => (ImageUtils::validateURL($rSimilarProperties['movie_image']) ?: ''), 'backdrop' => (ImageUtils::validateURL($rSimilarProperties['backdrop_path'][0] ?? '') ?: ''));
                        $rSimilarIDs[] = $rRow['id'];
                    }
                }
            }

            $GLOBALS['_TITLE'] = $rStream['stream_display_name'];
            $GLOBALS['rURLs'] = $rURLs;
            $GLOBALS['rSubtitles'] = $rSubtitles;
            $GLOBALS['rLegacy'] = $rLegacy;

            $this->render('movie', [
                'rStream' => $rStream,
                'rProperties' => $rProperties,
                'rSubtitles' => $rSubtitles,
                'rURLs' => $rURLs,
                'rLegacy' => $rLegacy,
                'rCover' => $rCover,
                'rPoster' => $rPoster,
                'rSimilar' => $rSimilar,
            ]);
        } else {
            header('Location: movies');
            exit();
        }
    }
}
