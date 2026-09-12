<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;

/**
 * PlayerWatchController — Dedicated Cinema Theater Player for Web Player V2.
 *
 * Handles dedicated cinema theater playback for both Movies (VOD) and TV Series Episodes.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerWatchController extends BasePlayerV2Controller
{
    public function index()
    {
        global $db, $rUserInfo;

        $type = RequestManager::get('type') === 'series' ? 'series' : 'movie';
        $id = (int)RequestManager::get('id');

        $code = $_SERVER['XC_CODE'] ?? '';
        $baseUrl = $code ? '/' . $code . '/' : '/';

        $domainName = DomainResolver::resolve(
            SERVER_ID,
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );

        if ($type === 'series') {
            $seriesId = (int)RequestManager::get('series_id');

            // Validate subscriber access
            $hasSeriesAccess = empty($rUserInfo['series_ids']) || (!empty($seriesId) && in_array($seriesId, $rUserInfo['series_ids'], true));
            $hasEpisodeAccess = empty($rUserInfo['episode_ids']) || (!empty($id) && (in_array($id, $rUserInfo['episode_ids'], true) || $hasSeriesAccess));

            if ($id <= 0 || !$hasEpisodeAccess || !($rStream = getStream($id))) {
                header('Location: ' . $baseUrl . 'series');
                exit;
            }

            // Series metadata
            $rSeries = null;
            if ($seriesId > 0) {
                $db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $seriesId);
                $rSeries = $db->get_row();
            }

            // Episode info from streams_episodes
            $db->query('SELECT * FROM `streams_episodes` WHERE `stream_id` = ? LIMIT 1', $id);
            $epMeta = $db->get_row();
            if (!$seriesId && !empty($epMeta['series_id'])) {
                $seriesId = (int)$epMeta['series_id'];
                $db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $seriesId);
                $rSeries = $db->get_row();
            }

            $seasonNum = (int)($epMeta['season_num'] ?? RequestManager::get('s') ?? 1);
            $episodeNum = (int)($epMeta['episode_num'] ?? RequestManager::get('e') ?? 1);

            // Adjacent episodes navigation
            $prevEp = null;
            $nextEp = null;
            if ($seriesId > 0) {
                $db->query('SELECT t1.season_num, t1.episode_num, t1.stream_id, t2.stream_display_name FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num ASC, t1.episode_num ASC', $seriesId);
                $allEps = $db->get_rows() ?: [];
                foreach ($allEps as $idx => $epRow) {
                    if ((int)$epRow['stream_id'] === $id) {
                        if (isset($allEps[$idx - 1])) {
                            $prevEp = $allEps[$idx - 1];
                        }
                        if (isset($allEps[$idx + 1])) {
                            $nextEp = $allEps[$idx + 1];
                        }
                        break;
                    }
                }
            }

            $container = $rStream['target_container'] ?? 'mp4';
            $streamUrl = $domainName . 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . $container;

            $props = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
            $video = $props['video'] ?? null;
            $width = !empty($video['width']) ? (int)$video['width'] : 0;
            $qualityBadge = 'HD';
            $qualityColor = 'primary';
            if ($width >= 3840) {
                $qualityBadge = '4K';
                $qualityColor = 'warning';
            } elseif ($width >= 1920) {
                $qualityBadge = '1080p FHD';
                $qualityColor = 'primary';
            } elseif ($width >= 1280) {
                $qualityBadge = '720p HD';
                $qualityColor = 'success';
            } elseif ($width > 0) {
                $qualityBadge = 'SD';
                $qualityColor = 'secondary';
            }

            $seriesTitle = $rSeries['title'] ?? 'TV Series';
            $playbackTitle = $seriesTitle . ' — S' . sprintf('%02d', $seasonNum) . 'E' . sprintf('%02d', $episodeNum) . ' — ' . $rStream['stream_display_name'];
            $backUrl = $seriesId > 0 ? $baseUrl . 'series?id=' . $seriesId : $baseUrl . 'series';

            $GLOBALS['_TITLE'] = 'Playing: ' . $playbackTitle;
            $GLOBALS['_PAGE'] = 'series';

            $this->render('player', [
                'type'          => 'series',
                'movie'         => $rStream,
                'series'        => $rSeries,
                'seriesId'      => $seriesId,
                'seasonNum'     => $seasonNum,
                'episodeNum'    => $episodeNum,
                'prevEp'        => $prevEp,
                'nextEp'        => $nextEp,
                'playbackTitle' => $playbackTitle,
                'backUrl'       => $backUrl,
                'props'         => $props,
                'streamUrl'     => $streamUrl,
                'qualityBadge'  => $qualityBadge,
                'qualityColor'  => $qualityColor,
                'categoryName'  => $seriesTitle,
                'baseUrl'       => $baseUrl,
            ]);
            return;
        }

        // Movie Playback
        if ($id <= 0 || !in_array($id, $rUserInfo['vod_ids'] ?? [], true) || !($rStream = getStream($id))) {
            header('Location: ' . $baseUrl . 'movies');
            exit;
        }

        $props = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
        $streamUrl = $domainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . ($rStream['target_container'] ?? 'mp4');

        $video = $props['video'] ?? null;
        $width = !empty($video['width']) ? (int)$video['width'] : 0;
        $qualityBadge = 'HD';
        $qualityColor = 'primary';
        if ($width >= 3840) {
            $qualityBadge = '4K';
            $qualityColor = 'warning';
        } elseif ($width >= 1920) {
            $qualityBadge = '1080p FHD';
            $qualityColor = 'primary';
        } elseif ($width >= 1280) {
            $qualityBadge = '720p HD';
            $qualityColor = 'success';
        } elseif ($width > 0) {
            $qualityBadge = 'SD';
            $qualityColor = 'secondary';
        }

        // Category Name
        $rawCats = $rStream['category_id'] ?? null;
        $primaryCatId = 0;
        if (is_numeric($rawCats)) {
            $primaryCatId = (int)$rawCats;
        } elseif (is_string($rawCats)) {
            $decoded = json_decode($rawCats, true);
            $primaryCatId = is_array($decoded) && !empty($decoded[0]) ? (int)$decoded[0] : 0;
        } elseif (is_array($rawCats) && !empty($rawCats[0])) {
            $primaryCatId = (int)$rawCats[0];
        }

        $categoryName = $primaryCatId > 0 ? PlayerCategoryHelper::resolveCategoryName($primaryCatId, $rUserInfo, 'movie') : 'Movies';
        $backUrl = $baseUrl . 'movie?id=' . $rStream['id'];

        $GLOBALS['_TITLE'] = 'Playing: ' . $rStream['stream_display_name'];
        $GLOBALS['_PAGE'] = 'movies';

        $this->render('player', [
            'type'          => 'movie',
            'movie'         => $rStream,
            'playbackTitle' => $rStream['stream_display_name'],
            'backUrl'       => $backUrl,
            'props'         => $props,
            'streamUrl'     => $streamUrl,
            'qualityBadge'  => $qualityBadge,
            'qualityColor'  => $qualityColor,
            'categoryName'  => $categoryName,
            'baseUrl'       => $baseUrl,
        ]);
    }
}
