<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * SearchController — Comprehensive Global Search Engine for Web Player V2.
 *
 * Searches across Live TV Channels, Movies (VOD), TV Series, Series Episodes,
 * and Radio Stations with instant AJAX preview and dedicated results view.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class SearchController extends BasePlayerV2Controller
{
    public function index()
    {
        global $db, $rUserInfo;

        if (empty($rUserInfo) || empty($rUserInfo['id'])) {
            header('Location: login');
            exit;
        }

        $rawQuery = RequestManager::get('q') ?? RequestManager::get('search') ?? '';
        $query = trim((string)$rawQuery);
        $typeFilter = strtolower(trim((string)(RequestManager::get('type') ?? 'all')));
        if (!in_array($typeFilter, ['all', 'live', 'movies', 'series', 'episodes', 'radio'], true)) {
            $typeFilter = 'all';
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'))
            || RequestManager::has('ajax')
            || RequestManager::get('format') === 'json';

        if ($query === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => 'success',
                    'query' => '',
                    'total' => 0,
                    'counts' => [
                        'all' => 0,
                        'live' => 0,
                        'movies' => 0,
                        'series' => 0,
                        'episodes' => 0,
                        'radio' => 0,
                    ],
                    'results' => [
                        'live' => [],
                        'movies' => [],
                        'series' => [],
                        'episodes' => [],
                        'radio' => [],
                    ],
                ]);
                exit;
            }

            $this->render('search', [
                '_PAGE'       => 'search',
                '_TITLE'      => 'Global Search',
                'query'       => '',
                'typeFilter'  => $typeFilter,
                'total'       => 0,
                'counts'      => ['all' => 0, 'live' => 0, 'movies' => 0, 'series' => 0, 'episodes' => 0, 'radio' => 0],
                'results'     => ['live' => [], 'movies' => [], 'series' => [], 'episodes' => [], 'radio' => []],
            ]);
            return;
        }

        // Cache category definitions for quick lookup
        $db->query('SELECT `id`, `category_name`, `category_type` FROM `streams_categories`;');
        $catRows = $db->get_rows() ?: [];
        $categoryMap = [];
        foreach ($catRows as $cat) {
            $categoryMap[$cat['id']] = $cat['category_name'];
        }

        $searchTerm = '%' . $query . '%';
        $results = [
            'live'     => [],
            'movies'   => [],
            'series'   => [],
            'episodes' => [],
            'radio'    => [],
        ];

        // 1. Live TV Channels (type = 1)
        $liveClause = '';
        if (!empty($rUserInfo['live_ids']) && is_array($rUserInfo['live_ids'])) {
            $liveSafe = array_map('intval', $rUserInfo['live_ids']);
            if (!empty($liveSafe)) {
                $liveClause = ' AND s.id IN (' . implode(',', $liveSafe) . ')';
            }
        }
        $db->query(
            "SELECT s.id, s.stream_display_name, s.stream_icon, s.category_id
             FROM `streams` s
             WHERE s.type = 1 AND s.stream_display_name LIKE ? {$liveClause}
             ORDER BY s.stream_display_name ASC LIMIT 40;",
            $searchTerm
        );
        $liveRows = $db->get_rows() ?: [];
        foreach ($liveRows as $row) {
            $catId = $this->parseCategoryId($row['category_id']);
            $results['live'][] = [
                'type'          => 'live',
                'id'            => (int)$row['id'],
                'title'         => $row['stream_display_name'],
                'icon'          => ImageUtils::validateURL($row['stream_icon'] ?? '') ?: '',
                'category'      => $categoryMap[$catId] ?? 'Live Broadcast',
                'category_id'   => $catId,
                'play_url'      => 'live?channel=' . (int)$row['id'],
            ];
        }

        // 2. Movies / VOD (type = 2)
        $vodClause = '';
        if (!empty($rUserInfo['vod_ids']) && is_array($rUserInfo['vod_ids'])) {
            $vodSafe = array_map('intval', $rUserInfo['vod_ids']);
            if (!empty($vodSafe)) {
                $vodClause = ' AND s.id IN (' . implode(',', $vodSafe) . ')';
            }
        }
        $db->query(
            "SELECT s.id, s.stream_display_name, s.stream_icon, s.movie_properties, s.rating, s.year, s.category_id
             FROM `streams` s
             WHERE s.type = 2 AND (s.stream_display_name LIKE ? OR s.movie_properties LIKE ?) {$vodClause}
             ORDER BY s.stream_display_name ASC LIMIT 40;",
            $searchTerm,
            $searchTerm
        );
        $movieRows = $db->get_rows() ?: [];
        foreach ($movieRows as $row) {
            $props = json_decode($row['movie_properties'] ?? '', true) ?: [];
            $catId = $this->parseCategoryId($row['category_id']);
            $cover = ImageUtils::validateURL($props['movie_image'] ?? ($row['stream_icon'] ?? '')) ?: '';
            $results['movies'][] = [
                'type'          => 'movie',
                'id'            => (int)$row['id'],
                'title'         => $row['stream_display_name'],
                'cover'         => $cover,
                'rating'        => $row['rating'] ?? ($props['rating'] ?? null),
                'year'          => $row['year'] ?? (!empty($props['release_date']) ? substr($props['release_date'], 0, 4) : null),
                'genre'         => $props['genre'] ?? ($categoryMap[$catId] ?? 'Cinema'),
                'category'      => $categoryMap[$catId] ?? 'VOD Cinema',
                'plot'          => $props['plot'] ?? ($props['description'] ?? ''),
                'details_url'   => 'movie?id=' . (int)$row['id'],
                'play_url'      => 'watch?type=movie&id=' . (int)$row['id'],
            ];
        }

        // 3. TV Series (streams_series)
        $seriesClause = '';
        if (!empty($rUserInfo['series_ids']) && is_array($rUserInfo['series_ids'])) {
            $seriesSafe = array_map('intval', $rUserInfo['series_ids']);
            if (!empty($seriesSafe)) {
                $seriesClause = ' AND ss.id IN (' . implode(',', $seriesSafe) . ')';
            }
        }
        $db->query(
            "SELECT ss.id, ss.title, ss.cover, ss.rating, ss.year, ss.category_id, ss.genre, ss.plot, ss.seasons
             FROM `streams_series` ss
             WHERE (ss.title LIKE ? OR ss.genre LIKE ? OR ss.plot LIKE ?) {$seriesClause}
             ORDER BY ss.title ASC LIMIT 40;",
            $searchTerm,
            $searchTerm,
            $searchTerm
        );
        $seriesRows = $db->get_rows() ?: [];
        foreach ($seriesRows as $row) {
            $catId = $this->parseCategoryId($row['category_id']);
            $seasonsCount = 1;
            if (!empty($row['seasons'])) {
                $sData = json_decode($row['seasons'], true);
                if (is_array($sData)) {
                    $seasonsCount = max(1, count($sData));
                }
            }
            $results['series'][] = [
                'type'          => 'series',
                'id'            => (int)$row['id'],
                'title'         => $row['title'],
                'cover'         => ImageUtils::validateURL($row['cover'] ?? '') ?: '',
                'rating'        => $row['rating'] ?? null,
                'year'          => $row['year'] ?? null,
                'genre'         => $row['genre'] ?? ($categoryMap[$catId] ?? 'Drama, Series'),
                'category'      => $categoryMap[$catId] ?? 'TV Series',
                'seasons_count' => $seasonsCount,
                'plot'          => $row['plot'] ?? '',
                'details_url'   => 'series?id=' . (int)$row['id'],
            ];
        }

        // 4. Series Episodes (streams_episodes & streams type = 5)
        $epSeriesClause = '';
        if (!empty($rUserInfo['series_ids']) && is_array($rUserInfo['series_ids'])) {
            $seriesSafe = array_map('intval', $rUserInfo['series_ids']);
            if (!empty($seriesSafe)) {
                $epSeriesClause = ' AND se.series_id IN (' . implode(',', $seriesSafe) . ')';
            }
        }
        $db->query(
            "SELECT se.id as ep_id, se.season_num, se.episode_num, se.series_id,
                    s.id as stream_id, s.stream_display_name, s.stream_icon, s.movie_properties,
                    ss.title as series_title, ss.cover as series_cover
             FROM `streams_episodes` se
             JOIN `streams` s ON s.id = se.stream_id AND s.type = 5
             JOIN `streams_series` ss ON ss.id = se.series_id
             WHERE (s.stream_display_name LIKE ? OR ss.title LIKE ? OR s.movie_properties LIKE ?) {$epSeriesClause}
             ORDER BY ss.title ASC, se.season_num ASC, se.episode_num ASC LIMIT 40;",
            $searchTerm,
            $searchTerm,
            $searchTerm
        );
        $episodeRows = $db->get_rows() ?: [];
        foreach ($episodeRows as $row) {
            $props = json_decode($row['movie_properties'] ?? '', true) ?: [];
            $epImage = ImageUtils::validateURL($props['movie_image'] ?? ($row['stream_icon'] ?? '')) ?: '';
            if (empty($epImage)) {
                $epImage = ImageUtils::validateURL($row['series_cover'] ?? '') ?: '';
            }

            $duration = $props['duration'] ?? '';
            if (empty($duration) && !empty($props['duration_secs'])) {
                $duration = gmdate('H:i:s', (int)$props['duration_secs']);
            }

            $results['episodes'][] = [
                'type'          => 'episode',
                'id'            => (int)$row['stream_id'],
                'ep_id'         => (int)$row['ep_id'],
                'stream_id'     => (int)$row['stream_id'],
                'series_id'     => (int)$row['series_id'],
                'season_num'    => (int)$row['season_num'],
                'episode_num'   => (int)$row['episode_num'],
                'title'         => $row['stream_display_name'] ?: ('Episode ' . $row['episode_num']),
                'series_title'  => $row['series_title'] ?: 'TV Series',
                'badge'         => 'S' . sprintf('%02d', (int)$row['season_num']) . 'E' . sprintf('%02d', (int)$row['episode_num']),
                'thumb'         => $epImage,
                'duration'      => $duration,
                'plot'          => $props['plot'] ?? '',
                'air_date'      => $props['air_date'] ?? '',
                'play_url'      => 'player?type=series&id=' . (int)$row['stream_id'] . '&series_id=' . (int)$row['series_id'] . '&s=' . (int)$row['season_num'] . '&e=' . (int)$row['episode_num'],
                'series_url'    => 'series?id=' . (int)$row['series_id'],
            ];
        }

        // 5. Radio Stations (type = 4)
        $radioClause = '';
        if (!empty($rUserInfo['radio_ids']) && is_array($rUserInfo['radio_ids'])) {
            $radioSafe = array_map('intval', $rUserInfo['radio_ids']);
            if (!empty($radioSafe)) {
                $radioClause = ' AND s.id IN (' . implode(',', $radioSafe) . ')';
            }
        }
        $db->query(
            "SELECT s.id, s.stream_display_name, s.stream_icon, s.category_id
             FROM `streams` s
             WHERE s.type = 4 AND s.stream_display_name LIKE ? {$radioClause}
             ORDER BY s.stream_display_name ASC LIMIT 30;",
            $searchTerm
        );
        $radioRows = $db->get_rows() ?: [];
        foreach ($radioRows as $row) {
            $catId = $this->parseCategoryId($row['category_id']);
            $cleanTitle = preg_replace('/^[\?\s\x{1F300}-\x{1F6FF}\x{2600}-\x{26FF}]+/u', '', $row['stream_display_name']);
            $results['radio'][] = [
                'type'          => 'radio',
                'id'            => (int)$row['id'],
                'title'         => trim($cleanTitle) ?: $row['stream_display_name'],
                'raw_title'     => $row['stream_display_name'],
                'icon'          => ImageUtils::validateURL($row['stream_icon'] ?? '') ?: '',
                'category'      => $categoryMap[$catId] ?? 'Radio Station',
                'category_id'   => $catId,
                'play_url'      => 'radio?station=' . (int)$row['id'],
            ];
        }

        $counts = [
            'all'      => count($results['live']) + count($results['movies']) + count($results['series']) + count($results['episodes']) + count($results['radio']),
            'live'     => count($results['live']),
            'movies'   => count($results['movies']),
            'series'   => count($results['series']),
            'episodes' => count($results['episodes']),
            'radio'    => count($results['radio']),
        ];

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'success',
                'query'   => $query,
                'total'   => $counts['all'],
                'counts'  => $counts,
                'results' => $results,
            ]);
            exit;
        }

        $this->render('search', [
            '_PAGE'       => 'search',
            '_TITLE'      => 'Search: ' . $query,
            'query'       => $query,
            'typeFilter'  => $typeFilter,
            'total'       => $counts['all'],
            'counts'      => $counts,
            'results'     => $results,
        ]);
    }

    /**
     * Parse category ID that might be stored as an integer or JSON string array like '["65"]'.
     */
    private function parseCategoryId($rawCategory): int
    {
        if (is_numeric($rawCategory)) {
            return (int)$rawCategory;
        }
        if (is_string($rawCategory)) {
            $decoded = json_decode($rawCategory, true);
            if (is_array($decoded) && !empty($decoded)) {
                return (int)$decoded[0];
            }
        }
        return 0;
    }
}
