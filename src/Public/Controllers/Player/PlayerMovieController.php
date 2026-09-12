<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Vod\TMDbService;

/**
 * PlayerMovieController — player movie controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerMovieController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        $id = intval(RequestManager::get('id'));
        if ($id > 0 && ($rStream = getStream($id)) && in_array($id, $rUserInfo['vod_ids'] ?? [])) {
            $rProperties = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
            $rSubtitles = array(getSubtitles($rStream['id'], $rProperties['subtitle'] ?? []));
            $rDomainName = DomainResolver::resolve(SERVER_ID, (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443));
            $rURLs = array($rDomainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . ($rStream['target_container'] ?? 'mp4'));
            $rLegacy = false;

            if (($rStream['target_container'] ?? 'mp4') != 'mp4') {
                $rLegacy = true;
            }

            if (!empty($rProperties['tmdb_id'])) {
                if (!file_exists(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id'])) {
                    $rTMDB = json_decode(json_encode(TMDbService::getMovie($rProperties['tmdb_id'])), true);

                    if ($rTMDB) {
                        file_put_contents(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id'], igbinary_serialize($rTMDB));
                    }
                } else {
                    $rTMDB = igbinary_unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rProperties['tmdb_id']));
                }
            }

            $coverPath = '';
            if (!empty($rProperties['backdrop_path'])) {
                $coverPath = is_array($rProperties['backdrop_path']) ? ($rProperties['backdrop_path'][0] ?? '') : $rProperties['backdrop_path'];
            }
            $rCover = ImageUtils::validateURL($coverPath) ?: '';
            $rPoster = ImageUtils::validateURL($rProperties['cover_big'] ?? '') ?: '';

            $rSimilarIDs = array($rStream['id']);
            $rSimilar = array();
            $rSimilarArray = json_decode($rStream['similar'] ?? '', true);

            if (is_array($rSimilarArray) && count($rSimilarArray) > 0) {
                $cleanSimilar = implode(',', array_map('intval', $rSimilarArray));
                if (SettingsManager::get('player_hide_incompatible')) {
                    $db->query('SELECT * FROM `streams` WHERE `tmdb_id` IN (' . $cleanSimilar . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 LIMIT 6;');
                } else {
                    $db->query('SELECT * FROM `streams` WHERE `tmdb_id` IN (' . $cleanSimilar . ') LIMIT 6;');
                }

                foreach ($db->get_rows() as $rRow) {
                    $rSimilarProperties = json_decode($rRow['movie_properties'] ?? '', true) ?: [];
                    $simBackdrop = '';
                    if (!empty($rSimilarProperties['backdrop_path'])) {
                        $simBackdrop = is_array($rSimilarProperties['backdrop_path']) ? ($rSimilarProperties['backdrop_path'][0] ?? '') : $rSimilarProperties['backdrop_path'];
                    }
                    $rSimilar[] = array(
                        'type' => 'movie',
                        'id' => $rRow['id'],
                        'title' => ($rRow['title'] ?? $rRow['stream_display_name']),
                        'year' => ($rRow['year'] ?: null),
                        'rating' => ($rSimilarProperties['rating'] ?? null),
                        'cover' => (ImageUtils::validateURL($rSimilarProperties['movie_image'] ?? '') ?: ''),
                        'backdrop' => (ImageUtils::validateURL($simBackdrop) ?: '')
                    );
                    $rSimilarIDs[] = $rRow['id'];
                }
            }

            if (count($rSimilar) < 6 && !empty($rUserInfo['vod_ids'])) {
                $rPrevious = (count($rSimilarIDs) > 0) ? '`stream_id` NOT IN (' . implode(',', array_map('intval', $rSimilarIDs)) . ') AND ' : '';
                $cleanVodIds = implode(',', array_map('intval', $rUserInfo['vod_ids']));

                if (SettingsManager::get('player_hide_incompatible')) {
                    $db->query('SELECT `streams`.*, COUNT(`user_id`) AS `count` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `user_id` IN (SELECT DISTINCT(`user_id`) FROM `lines_activity` WHERE `stream_id` = ? AND (`date_end` - `date_start` > 60)) AND `type` = 2 AND ' . $rPrevious . ' `stream_id` IN (' . $cleanVodIds . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 GROUP BY `stream_id` ORDER BY `count` DESC LIMIT ' . (6 - count($rSimilar)) . ';', $rStream['id']);
                } else {
                    $db->query('SELECT `streams`.*, COUNT(`user_id`) AS `count` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `user_id` IN (SELECT DISTINCT(`user_id`) FROM `lines_activity` WHERE `stream_id` = ? AND (`date_end` - `date_start` > 60)) AND `type` = 2 AND ' . $rPrevious . ' `stream_id` IN (' . $cleanVodIds . ') GROUP BY `stream_id` ORDER BY `count` DESC LIMIT ' . (6 - count($rSimilar)) . ';', $rStream['id']);
                }

                foreach ($db->get_rows() as $rRow) {
                    if (!empty($rRow['id'])) {
                        $rSimilarProperties = json_decode($rRow['movie_properties'] ?? '', true) ?: [];
                        $simBackdrop = '';
                        if (!empty($rSimilarProperties['backdrop_path'])) {
                            $simBackdrop = is_array($rSimilarProperties['backdrop_path']) ? ($rSimilarProperties['backdrop_path'][0] ?? '') : $rSimilarProperties['backdrop_path'];
                        }
                        $rSimilar[] = array(
                            'type' => 'movie',
                            'id' => $rRow['id'],
                            'title' => ($rRow['title'] ?? $rRow['stream_display_name']),
                            'year' => ($rRow['year'] ?: null),
                            'rating' => ($rSimilarProperties['rating'] ?? null),
                            'cover' => (ImageUtils::validateURL($rSimilarProperties['movie_image'] ?? '') ?: ''),
                            'backdrop' => (ImageUtils::validateURL($simBackdrop) ?: '')
                        );
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
