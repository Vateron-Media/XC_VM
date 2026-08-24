<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * HomeController — home controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class HomeController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (RequestManager::has('search') && RequestManager::has('type')) {
            if (in_array(RequestManager::get('type'), array('live', 'movies', 'series'))) {
                header('Location: ' . RequestManager::get('type') . '?search=' . urlencode(RequestManager::get('search')));
                exit();
            }
        }

        $rPopularNow = array();
        // tmdb_popular is written by a cron and may be absent (fresh install /
        // TMDB disabled) or corrupt; guard the read + unserialize so the home
        // page never fatals on a missing file or a non-array payload.
        $rPopular = array('movies' => array(), 'series' => array());
        $rPopularFile = CONTENT_PATH . 'tmdb_popular';
        if (is_file($rPopularFile)) {
            $rPopularData = igbinary_unserialize((string) @file_get_contents($rPopularFile));
            if (is_array($rPopularData)) {
                $rPopular = array_merge($rPopular, $rPopularData);
            }
        }
        if (!is_array($rPopular['movies'])) {
            $rPopular['movies'] = array();
        }
        if (!is_array($rPopular['series'])) {
            $rPopular['series'] = array();
        }

        if (!(0 < count($rPopular['movies']) && 0 < count($rUserInfo['vod_ids'] ?? array()))) {
        } else {
            if (SettingsManager::get('player_hide_incompatible')) {
                $db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular['movies']) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 ORDER BY FIELD(id, ' . implode(',', $rPopular['movies']) . ') ASC LIMIT 50;');
            } else {
                $db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular['movies']) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['movies']) . ') ASC LIMIT 50;');
            }

            $rStreams = $db->get_rows();

            foreach ($rStreams as $rStream) {
                $rProperties = json_decode($rStream['movie_properties'], true);
                $rPopularNow[] = array('type' => 'movie', 'id' => $rStream['id'], 'title' => $rStream['stream_display_name'], 'year' => ($rStream['year'] ?: null), 'rating' => $rStream['rating'], 'cover' => (ImageUtils::validateURL($rProperties['movie_image']) ?: ''), 'backdrop' => (ImageUtils::validateURL($rProperties['backdrop_path'][0]) ?: ''));
            }
        }

        if (!(0 < count($rPopular['series']) && 0 < count($rUserInfo['series_ids'] ?? array()))) {
        } else {
            if (SettingsManager::get('player_hide_incompatible')) {
                $db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular['series']) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1 ORDER BY FIELD(id, ' . implode(',', $rPopular['series']) . ') ASC LIMIT 50;');
            } else {
                $db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular['series']) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['series']) . ') ASC LIMIT 50;');
            }

            $rStreams = $db->get_rows();

            foreach ($rStreams as $rStream) {
                $rBackdrop = json_decode($rStream['backdrop_path'], true);
                $rPopularNow[] = array('type' => 'episodes', 'id' => $rStream['id'], 'title' => $rStream['title'], 'year' => ($rStream['year'] ?: (substr($rStream['releaseDate'], 0, 4) ?: null)), 'rating' => $rStream['rating'], 'cover' => (ImageUtils::validateURL($rStream['cover']) ?: ''), 'backdrop' => (ImageUtils::validateURL($rBackdrop[0]) ?: ''));
            }
        }

        shuffle($rPopularNow);
        $searchParam = isset($rSearchBy) ? $rSearchBy : null;
        $rPopularNow = array_slice($rPopularNow, 0, 20);
        $rMovies = getUserStreams($rUserInfo, array('movie'), null, null, 'added', null, null, 0, 20);
        $rSeries = getUserSeries($rUserInfo, null, null, 'added', $searchParam, null, 0, 20);

        $GLOBALS['_TITLE'] = 'Home';

        $this->render('index', [
            'rPopularNow' => $rPopularNow,
            'rMovies' => $rMovies,
            'rSeries' => $rSeries,
        ]);
    }
}
