<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * SeriesController — TV Series Catalog & Details Controller for Web Player V2.
 *
 * Manages the TV Series explorer catalog, category filtering, search, sorting,
 * and the comprehensive cinematic series details view with interactive seasons and episodes.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class SeriesController extends BasePlayerV2Controller
{
    public function index()
    {
        global $db, $rUserInfo;

        if (!isset($rUserInfo['series_ids']) || !is_array($rUserInfo['series_ids'])) {
            $rUserInfo['series_ids'] = [];
        }

        $code = $_SERVER['XC_CODE'] ?? '';
        $baseUrl = $code ? '/' . $code . '/' : '/';

        $id = (int)RequestManager::get('id');

        // ─── Mode A: Series Details View ───────────────────────────────────
        if ($id > 0) {
            $this->renderSeriesDetails($id, $baseUrl);
            return;
        }

        // ─── Mode B: AJAX Series Retrieval ─────────────────────────────────
        if (RequestManager::get('ajax') === '1' || RequestManager::get('action') === 'series') {
            header('Content-Type: application/json; charset=utf-8');

            $catId = RequestManager::has('category_id') && RequestManager::get('category_id') !== 'all' && RequestManager::get('category_id') !== ''
                ? (int)RequestManager::get('category_id')
                : null;

            $sortBy = RequestManager::get('sort') ?: 'number';
            $searchBy = RequestManager::get('search') ?: null;

            // Support External Xtream Codes
            if (!empty($rUserInfo['is_external_xc'])) {
                $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
                $extSeries = $extService ? $extService->getSeries($catId) : [];
                if ($searchBy) {
                    $extSeries = array_filter($extSeries, fn($s) => stripos($s['title'], $searchBy) !== false);
                    $extSeries = array_values($extSeries);
                }
                $seriesItems = [];
                foreach ($extSeries as $s) {
                    $seriesItems[] = [
                        'id'            => (int)$s['id'],
                        'title'         => $s['title'],
                        'year'          => $s['year'],
                        'rating'        => $s['rating'] ?: 'N/A',
                        'cover'         => $s['cover'],
                        'category_id'   => (int)$s['category_id'],
                        'seasons_count' => 1,
                    ];
                }
                echo json_encode([
                    'status' => 'success',
                    'count'  => count($seriesItems),
                    'series' => $seriesItems,
                ]);
                exit;
            }

            if (empty($rUserInfo['series_ids'])) {
                $where = [];
                $whereV = [];
                if (!empty($catId)) {
                    $where[] = "JSON_CONTAINS(`category_id`, ?, '$')";
                    $whereV[] = (string)$catId;
                }
                if (!empty($searchBy)) {
                    $where[] = '`title` LIKE ?';
                    $whereV[] = '%' . $searchBy . '%';
                }
                $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
                $db->query("SELECT * FROM `streams_series` {$whereStr} ORDER BY `id` DESC LIMIT 1000", ...$whereV);
                $seriesList = $db->get_rows() ?: [];
            } else {
                $rSeriesData = getUserSeries(
                    $rUserInfo,
                    $catId,
                    null,
                    $sortBy,
                    $searchBy,
                    [],
                    0,
                    1000
                );
                $seriesList = isset($rSeriesData['streams']) ? $rSeriesData['streams'] : (is_array($rSeriesData) ? $rSeriesData : []);
            }

            $seriesItems = [];

            foreach ($seriesList as $series) {
                if (!is_array($series) || empty($series['id'])) {
                    continue;
                }

                $coverUrl = !empty($series['cover']) ? ImageUtils::validateURL($series['cover']) : '';
                $rating = !empty($series['rating']) ? (float)$series['rating'] : 0;

                // Calculate seasons count
                $seasonsArr = json_decode($series['seasons'] ?? '', true) ?: [];
                $seasonsCount = is_array($seasonsArr) && !empty($seasonsArr) ? count($seasonsArr) : 1;

                $seriesItems[] = [
                    'id'            => (int)$series['id'],
                    'title'         => $series['title'] ?? 'Series #' . $series['id'],
                    'year'          => !empty($series['year']) ? (int)$series['year'] : null,
                    'rating'        => $rating > 0 ? number_format($rating, 1) : 'N/A',
                    'cover'         => $coverUrl ?: '',
                    'category_id'   => $series['category_id'] ?? 0,
                    'seasons_count' => $seasonsCount,
                ];
            }

            echo json_encode([
                'status' => 'success',
                'count'  => count($seriesItems),
                'series' => $seriesItems,
            ]);
            exit;
        }

        // ─── Mode C: Standard Series Catalog Load ───────────────────────────
        $rCategories = PlayerCategoryHelper::getCategories($rUserInfo, 'series');
        $firstCatId = !empty($rCategories[0]['id']) ? (int)$rCategories[0]['id'] : null;

        // Support External Xtream Standard Load
        if (!empty($rUserInfo['is_external_xc'])) {
            $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
            $extSeries = $extService ? $extService->getSeries($firstCatId) : [];

            $initialSeries = [];
            foreach ($extSeries as $s) {
                $initialSeries[] = [
                    'id'            => (int)$s['id'],
                    'title'         => $s['title'],
                    'year'          => $s['year'],
                    'rating'        => $s['rating'] ?: 'N/A',
                    'cover'         => $s['cover'],
                    'category_id'   => (int)$s['category_id'],
                    'seasons_count' => 1,
                ];
            }

            $GLOBALS['_TITLE'] = 'TV Series';
            $GLOBALS['_PAGE']  = 'series';

            $this->render('series', [
                'rCategories'        => $rCategories,
                'initialSeries'      => $initialSeries,
                'selectedCategoryId' => $firstCatId,
                'totalSeriesCount'   => count($initialSeries),
                'baseUrl'            => $baseUrl,
            ]);
            return;
        }

        if (empty($rUserInfo['series_ids'])) {
            $where = [];
            $whereV = [];
            if (!empty($firstCatId)) {
                $where[] = "JSON_CONTAINS(`category_id`, ?, '$')";
                $whereV[] = (string)$firstCatId;
            }
            $whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $db->query("SELECT * FROM `streams_series` {$whereStr} ORDER BY `id` DESC LIMIT 100", ...$whereV);
            $initialSeriesRaw = $db->get_rows() ?: [];
            $db->query('SELECT count(*) as c FROM `streams_series`;');
            $totalCount = (int)($db->get_row()['c'] ?? 0);
        } else {
            $rSeriesData = getUserSeries(
                $rUserInfo,
                $firstCatId,
                null,
                'number',
                null,
                [],
                0,
                100
            );
            $initialSeriesRaw = isset($rSeriesData['streams']) ? $rSeriesData['streams'] : (is_array($rSeriesData) ? $rSeriesData : []);
            $totalCount = count($rUserInfo['series_ids']);
        }

        $initialSeries = [];

        foreach ($initialSeriesRaw as $series) {
            if (!is_array($series) || empty($series['id'])) {
                continue;
            }

            $coverUrl = !empty($series['cover']) ? ImageUtils::validateURL($series['cover']) : '';
            $rating = !empty($series['rating']) ? (float)$series['rating'] : 0;
            $seasonsArr = json_decode($series['seasons'] ?? '', true) ?: [];
            $seasonsCount = is_array($seasonsArr) && !empty($seasonsArr) ? count($seasonsArr) : 1;

            $initialSeries[] = [
                'id'            => (int)$series['id'],
                'title'         => $series['title'] ?? 'Series #' . $series['id'],
                'year'          => !empty($series['year']) ? (int)$series['year'] : null,
                'rating'        => $rating > 0 ? number_format($rating, 1) : 'N/A',
                'cover'         => $coverUrl ?: '',
                'category_id'   => $series['category_id'] ?? 0,
                'seasons_count' => $seasonsCount,
            ];
        }

        $GLOBALS['_TITLE'] = 'TV Series Catalog';
        $GLOBALS['_PAGE'] = 'series';

        $this->render('series', [
            'rCategories'        => $rCategories,
            'initialSeries'      => $initialSeries,
            'selectedCategoryId' => $firstCatId,
            'totalSeriesCount'   => $totalCount,
            'baseUrl'            => $baseUrl,
        ]);
    }

    /**
     * Render the detailed cinematic series page with seasons and episodes navigator.
     *
     * @param int    $seriesId
     * @param string $baseUrl
     */
    private function renderSeriesDetails(int $seriesId, string $baseUrl)
    {
        global $db, $rUserInfo;

        // Support External Xtream Series Details
        if (!empty($rUserInfo['is_external_xc'])) {
            $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
            $seriesInfoData = $extService ? $extService->getSeriesInfo($seriesId) : [];
            $info = $seriesInfoData['info'] ?? [];
            $episodesRaw = $seriesInfoData['episodes'] ?? [];

            $title = $info['name'] ?? ('Series #' . $seriesId);
            $posterUrl = $info['cover'] ?? '';
            $backdropUrl = !empty($info['backdrop_path']) ? (is_array($info['backdrop_path']) ? ($info['backdrop_path'][0] ?? '') : $info['backdrop_path']) : $posterUrl;

            $castRaw = $info['cast'] ?? '';
            $castList = [];
            if (is_string($castRaw) && trim($castRaw) !== '') {
                $castList = array_slice(array_filter(array_map('trim', explode(',', $castRaw))), 0, 12);
            }

            $catId = (int)($info['category_id'] ?? 0);
            $catName = PlayerCategoryHelper::resolveCategoryName($catId, $rUserInfo, 'series');

            $episodesMap = [];
            $totalEpisodes = 0;
            $firstEpisode = null;

            foreach ($episodesRaw as $seasonKey => $seasonEpisodes) {
                $sNum = (int)$seasonKey;
                if (!isset($episodesMap[$sNum])) {
                    $episodesMap[$sNum] = [];
                }

                foreach ($seasonEpisodes as $ep) {
                    $totalEpisodes++;
                    $epNum = (int)($ep['episode_num'] ?? 1);
                    $epStreamId = (int)($ep['id'] ?? 0);
                    $epTitle = $ep['title'] ?? ('Episode ' . $epNum);
                    $epExt = $ep['container_extension'] ?? 'mp4';
                    $epStreamUrl = $extService ? $extService->buildSeriesUrl($epStreamId, $epExt) : '';
                    $epCover = !empty($ep['info']['movie_image']) ? $ep['info']['movie_image'] : $posterUrl;
                    $epDuration = $ep['info']['duration'] ?? '';

                    $epObj = [
                        'season_num'           => $sNum,
                        'episode_num'          => $epNum,
                        'stream_id'            => $epStreamId,
                        'episode_id'           => $epStreamId,
                        'title'                => $epTitle,
                        'cover'                => $epCover,
                        'duration'             => $epDuration,
                        'quality_badge'        => 'HD',
                        'quality_color'        => 'primary',
                        'stream_url'           => $epStreamUrl,
                    ];

                    $episodesMap[$sNum][] = $epObj;

                    if ($firstEpisode === null) {
                        $firstEpisode = $epObj;
                    }
                }
            }

            ksort($episodesMap);
            $seasonsList = array_keys($episodesMap);

            $GLOBALS['_TITLE'] = $title;
            $GLOBALS['_PAGE']  = 'series';

            $this->render('series_detail', [
                'series'         => [
                    'id' => $seriesId,
                    'title' => $title,
                    'year' => !empty($info['releaseDate']) ? substr((string)$info['releaseDate'], 0, 4) : null,
                    'rating' => $info['rating'] ?? 'N/A',
                    'plot' => $info['plot'] ?? '',
                    'genre' => $info['genre'] ?? '',
                    'director' => $info['director'] ?? '',
                    'cast' => $info['cast'] ?? '',
                    'cover' => $posterUrl,
                ],
                'posterUrl'      => $posterUrl,
                'backdropUrl'    => $backdropUrl,
                'backdrops'      => $backdropUrl ? [$backdropUrl] : [],
                'trailerId'      => '',
                'castList'       => $castList,
                'categoryNames'  => [$catName],
                'episodesMap'    => $episodesMap,
                'seasonsList'    => $seasonsList,
                'totalEpisodes'  => $totalEpisodes,
                'firstEpisode'   => $firstEpisode,
                'similarSeries'  => [],
                'baseUrl'        => $baseUrl,
            ]);
            return;
        }

        // Access check
        if (!empty($rUserInfo['series_ids']) && !in_array($seriesId, $rUserInfo['series_ids'], true)) {
            header('Location: ' . $baseUrl . 'series');
            exit;
        }

        $db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $seriesId);
        $series = $db->get_row();

        if (empty($series)) {
            header('Location: ' . $baseUrl . 'series');
            exit;
        }

        // Backdrops
        $backdrops = [];
        if (!empty($series['backdrop_path'])) {
            $rawBackdrops = json_decode($series['backdrop_path'], true);
            if (is_array($rawBackdrops)) {
                foreach ($rawBackdrops as $bd) {
                    if (is_string($bd) && trim($bd) !== '') {
                        $valid = ImageUtils::validateURL(trim($bd));
                        if ($valid) {
                            $backdrops[] = $valid;
                        }
                    }
                }
            } elseif (is_string($series['backdrop_path']) && trim($series['backdrop_path']) !== '') {
                $valid = ImageUtils::validateURL(trim($series['backdrop_path']));
                if ($valid) {
                    $backdrops[] = $valid;
                }
            }
        }
        $backdropUrl = !empty($backdrops[0]) ? $backdrops[0] : '';
        $posterUrl = ImageUtils::validateURL($series['cover'] ?? '') ?: '';

        // YouTube Trailer ID
        $trailerId = '';
        if (!empty($series['youtube_trailer'])) {
            $rawTrailer = (string)$series['youtube_trailer'];
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawTrailer, $match)) {
                $trailerId = $match[1];
            } elseif (preg_match('/^[a-zA-Z0-9_-]{11}$/', $rawTrailer)) {
                $trailerId = $rawTrailer;
            }
        }

        // Cast List
        $castRaw = $series['cast'] ?? '';
        $castList = [];
        if (is_string($castRaw) && trim($castRaw) !== '') {
            $castList = array_slice(array_filter(array_map('trim', explode(',', $castRaw))), 0, 12);
        }

        // Category Names
        $rawCats = $series['category_id'] ?? null;
        $categoryNames = [];
        $categoryIds = [];
        if (is_string($rawCats)) {
            $decoded = json_decode($rawCats, true);
            $categoryIds = is_array($decoded) ? $decoded : [(int)$rawCats];
        } elseif (is_array($rawCats)) {
            $categoryIds = $rawCats;
        } elseif (is_numeric($rawCats)) {
            $categoryIds = [(int)$rawCats];
        }

        foreach ($categoryIds as $catId) {
            $categoryNames[] = PlayerCategoryHelper::resolveCategoryName((int)$catId, $rUserInfo, 'series');
        }

        // Query Episodes grouped by season
        $db->query(
            'SELECT t1.season_num, t1.episode_num, t1.stream_id, t2.id as episode_id, t2.stream_display_name, t2.target_container, t2.movie_properties, t2.added ' .
            'FROM `streams_episodes` t1 ' .
            'INNER JOIN `streams` t2 ON t2.id = t1.stream_id ' .
            'WHERE t1.series_id = ? ' .
            'ORDER BY t1.season_num ASC, t1.episode_num ASC',
            $seriesId
        );
        $episodeRows = $db->get_rows() ?: [];

        $episodesMap = [];
        $totalEpisodes = count($episodeRows);
        $firstEpisode = null;

        foreach ($episodeRows as $row) {
            $sNum = (int)($row['season_num'] ?: 1);
            $epProps = json_decode($row['movie_properties'] ?? '', true) ?: [];

            // Episode Thumbnail
            $epCover = ImageUtils::validateURL($epProps['movie_image'] ?? '') ?: (ImageUtils::validateURL($epProps['cover_big'] ?? '') ?: $posterUrl);

            // Duration
            $epDurationSecs = !empty($epProps['duration_secs']) ? (int)$epProps['duration_secs'] : (!empty($epProps['duration']) ? (int)$epProps['duration'] : 0);
            $epDurationFormatted = '';
            if ($epDurationSecs > 0) {
                $hrs = floor($epDurationSecs / 3600);
                $mins = floor(($epDurationSecs % 3600) / 60);
                $secs = $epDurationSecs % 60;
                $epDurationFormatted = $hrs > 0
                    ? sprintf('%d:%02d:%02d', $hrs, $mins, $secs)
                    : sprintf('%02d:%02d', $mins, $secs);
            }

            // Quality
            $vid = $epProps['video'] ?? null;
            $w = !empty($vid['width']) ? (int)$vid['width'] : 0;
            $qualityBadge = 'HD';
            $qualityColor = 'primary';
            if ($w >= 3840) {
                $qualityBadge = '4K';
                $qualityColor = 'warning';
            } elseif ($w >= 1920) {
                $qualityBadge = '1080p FHD';
                $qualityColor = 'primary';
            } elseif ($w >= 1280) {
                $qualityBadge = '720p HD';
                $qualityColor = 'success';
            } elseif ($w > 0) {
                $qualityBadge = 'SD';
                $qualityColor = 'secondary';
            }

            $epItem = [
                'id'            => (int)$row['stream_id'],
                'stream_id'     => (int)$row['stream_id'],
                'season_num'    => $sNum,
                'episode_num'   => (int)($row['episode_num'] ?: 1),
                'title'         => $row['stream_display_name'] ?? 'Episode ' . $row['episode_num'],
                'container'     => $row['target_container'] ?? 'mp4',
                'cover'         => $epCover,
                'duration'      => $epDurationFormatted,
                'duration_secs' => $epDurationSecs,
                'plot'          => $epProps['plot'] ?? ($epProps['description'] ?? ''),
                'air_date'      => $epProps['air_date'] ?? ($epProps['releasedate'] ?? ''),
                'qualityBadge'  => $qualityBadge,
                'qualityColor'  => $qualityColor,
            ];

            if ($firstEpisode === null) {
                $firstEpisode = $epItem;
            }

            $episodesMap[$sNum][] = $epItem;
        }

        // Seasons list
        $seasonsRaw = json_decode($series['seasons'] ?? '', true) ?: [];
        $seasons = [];

        if (is_array($seasonsRaw) && !empty($seasonsRaw)) {
            foreach ($seasonsRaw as $sKey => $sVal) {
                $sNum = (int)($sVal['season_number'] ?? $sKey);
                if ($sNum <= 0 && is_numeric($sKey)) {
                    $sNum = (int)$sKey;
                }
                $epCount = !empty($episodesMap[$sNum]) ? count($episodesMap[$sNum]) : (int)($sVal['episode_count'] ?? 0);
                $seasons[] = [
                    'season_number' => $sNum,
                    'name'          => $sVal['name'] ?? ('Season ' . $sNum),
                    'cover'         => ImageUtils::validateURL($sVal['cover'] ?? '') ?: $posterUrl,
                    'episode_count' => $epCount,
                ];
            }
        }

        // Fallback seasons from episodes map if seasons table was empty
        if (empty($seasons) && !empty($episodesMap)) {
            $seasonKeys = array_keys($episodesMap);
            sort($seasonKeys, SORT_NUMERIC);
            foreach ($seasonKeys as $sNum) {
                $seasons[] = [
                    'season_number' => (int)$sNum,
                    'name'          => 'Season ' . $sNum,
                    'cover'         => $posterUrl,
                    'episode_count' => count($episodesMap[$sNum]),
                ];
            }
        }

        $GLOBALS['_TITLE'] = ($series['title'] ?? 'TV Series') . ' - Series Details';
        $GLOBALS['_PAGE'] = 'series';

        $this->render('series_detail', [
            'series'         => $series,
            'backdropUrl'    => $backdropUrl,
            'posterUrl'      => $posterUrl,
            'backdrops'      => $backdrops,
            'trailerId'      => $trailerId,
            'castList'       => $castList,
            'categoryNames'  => $categoryNames,
            'seasons'        => $seasons,
            'episodesMap'    => $episodesMap,
            'totalEpisodes'  => $totalEpisodes,
            'firstEpisode'   => $firstEpisode,
            'baseUrl'        => $baseUrl,
        ]);
    }
}
