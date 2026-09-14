<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * HomeController — Netflix-Inspired Cinematic Dashboard for Web Player V2.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class HomeController extends BasePlayerV2Controller
{
    public function index()
    {
        global $db, $rUserInfo;

        if (RequestManager::has('search') && RequestManager::has('type')) {
            $type = RequestManager::get('type');
            if (in_array($type, ['live', 'movies', 'series'], true)) {
                header('Location: ' . $type . '?search=' . urlencode(RequestManager::get('search')));
                exit();
            }
        }

        // Support External Xtream Codes Account
        if (!empty($rUserInfo['is_external_xc'])) {
            $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
            $extMovies = $extService ? $extService->getVodStreams(null) : [];
            $extSeries = $extService ? $extService->getSeries(null) : [];
            $extLive = $extService ? $extService->getLiveStreams(null) : [];

            $rPopularNow = [];
            foreach (array_slice($extMovies, 0, 10) as $m) {
                $rPopularNow[] = [
                    'type'     => 'movie',
                    'id'       => (int)$m['id'],
                    'title'    => $m['title'] ?? $m['stream_display_name'],
                    'year'     => $m['year'] ?? null,
                    'rating'   => $m['rating'] ?? null,
                    'cover'    => $m['poster'] ?? '',
                    'backdrop' => $m['poster'] ?? '',
                    'plot'     => '',
                    'genre'    => 'Movie',
                    'duration' => 'Feature Film',
                ];
            }
            foreach (array_slice($extSeries, 0, 5) as $s) {
                $rPopularNow[] = [
                    'type'     => 'series',
                    'id'       => (int)$s['id'],
                    'title'    => $s['title'] ?? $s['stream_display_name'],
                    'year'     => $s['year'] ?? null,
                    'rating'   => $s['rating'] ?? null,
                    'cover'    => $s['cover'] ?? '',
                    'backdrop' => $s['cover'] ?? '',
                    'plot'     => $s['plot'] ?? '',
                    'genre'    => $s['genre'] ?: 'Series',
                    'duration' => 'TV Series',
                ];
            }

            $heroSlides = array_slice($rPopularNow, 0, 5);
            $top10Items = array_slice($rPopularNow, 0, 10);

            $rMovies = ['streams' => array_slice($extMovies, 0, 18)];
            $rSeries = ['streams' => array_slice($extSeries, 0, 18)];
            $rLiveChannels = ['streams' => array_slice($extLive, 0, 8)];
            $rRadioStreams = ['streams' => []];

            $totalLive = count($extLive);
            $totalVod = count($extMovies);
            $totalSeries = count($extSeries);
            $totalRadio = 0;

            $GLOBALS['_TITLE'] = 'Dashboard';
            $GLOBALS['_PAGE']  = 'index';

            $this->render('index', [
                'heroSlides'     => $heroSlides,
                'top10Items'     => $top10Items,
                'rMovies'        => $rMovies,
                'rSeries'        => $rSeries,
                'rLiveChannels'  => $rLiveChannels,
                'rRadioStreams'  => $rRadioStreams,
                'totalLive'      => $totalLive,
                'totalVod'       => $totalVod,
                'totalSeries'    => $totalSeries,
                'totalRadio'     => $totalRadio,
            ]);
            return;
        }

        $rPopularNow = [];
        $rPopular = ['movies' => [], 'series' => []];
        $rPopularFile = CONTENT_PATH . 'tmdb_popular';
        if (is_file($rPopularFile)) {
            $rPopularData = @igbinary_unserialize((string) @file_get_contents($rPopularFile));
            if (is_array($rPopularData)) {
                $rPopular = array_merge($rPopular, $rPopularData);
            }
        }
        if (!is_array($rPopular['movies'])) {
            $rPopular['movies'] = [];
        }
        if (!is_array($rPopular['series'])) {
            $rPopular['series'] = [];
        }

        $vodIdsSafe = array_map('intval', $rUserInfo['vod_ids'] ?? []);
        $seriesIdsSafe = array_map('intval', $rUserInfo['series_ids'] ?? []);
        $hideIncompatible = SettingsManager::get('player_hide_incompatible');

        // 1. Popular Movies
        if (count($rPopular['movies']) > 0 && count($vodIdsSafe) > 0) {
            $popMoviesSafe = array_map('intval', $rPopular['movies']);
            $compatClause = $hideIncompatible
                ? ' AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1'
                : '';

            $db->query(
                'SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` ' .
                'FROM `streams` ' .
                'WHERE `id` IN (' . implode(',', $popMoviesSafe) . ') ' .
                'AND `id` IN (' . implode(',', $vodIdsSafe) . ')' .
                $compatClause . ' ' .
                'ORDER BY FIELD(id, ' . implode(',', $popMoviesSafe) . ') ASC LIMIT 30;'
            );

            $rStreams = $db->get_rows() ?: [];
            foreach ($rStreams as $rStream) {
                $rProperties = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
                $simBackdrop = '';
                if (!empty($rProperties['backdrop_path'])) {
                    $simBackdrop = is_array($rProperties['backdrop_path']) ? ($rProperties['backdrop_path'][0] ?? '') : $rProperties['backdrop_path'];
                }
                $rPopularNow[] = [
                    'type'     => 'movie',
                    'id'       => (int)$rStream['id'],
                    'title'    => $rStream['stream_display_name'],
                    'year'     => $rStream['year'] ?: (!empty($rProperties['release_date']) ? substr($rProperties['release_date'], 0, 4) : null),
                    'rating'   => $rStream['rating'] ?? ($rProperties['rating'] ?? null),
                    'cover'    => ImageUtils::validateURL($rProperties['movie_image'] ?? '') ?: '',
                    'backdrop' => ImageUtils::validateURL($simBackdrop) ?: '',
                    'plot'     => $rProperties['plot'] ?? ($rProperties['description'] ?? ''),
                    'genre'    => $rProperties['genre'] ?? 'Cinema, Blockbuster',
                    'duration' => !empty($rProperties['duration']) ? $rProperties['duration'] : (!empty($rProperties['episode_run_time']) ? $rProperties['episode_run_time'] . ' min' : ''),
                ];
            }
        }

        // 2. Popular Series
        if (count($rPopular['series']) > 0 && count($seriesIdsSafe) > 0) {
            $popSeriesSafe = array_map('intval', $rPopular['series']);
            $compatClause = $hideIncompatible
                ? ' AND (SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1'
                : '';

            $db->query(
                'SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path`, `plot`, `genre` ' .
                'FROM `streams_series` ' .
                'WHERE `id` IN (' . implode(',', $popSeriesSafe) . ') ' .
                'AND `id` IN (' . implode(',', $seriesIdsSafe) . ')' .
                $compatClause . ' ' .
                'ORDER BY FIELD(id, ' . implode(',', $popSeriesSafe) . ') ASC LIMIT 30;'
            );

            $rStreams = $db->get_rows() ?: [];
            foreach ($rStreams as $rStream) {
                $rBackdrop = json_decode($rStream['backdrop_path'] ?? '', true);
                $simBackdrop = is_array($rBackdrop) ? ($rBackdrop[0] ?? '') : '';
                $rPopularNow[] = [
                    'type'     => 'series',
                    'id'       => (int)$rStream['id'],
                    'title'    => $rStream['title'],
                    'year'     => $rStream['year'] ?: null,
                    'rating'   => $rStream['rating'] ?? null,
                    'cover'    => ImageUtils::validateURL($rStream['cover'] ?? '') ?: '',
                    'backdrop' => ImageUtils::validateURL($simBackdrop) ?: '',
                    'plot'     => $rStream['plot'] ?? '',
                    'genre'    => $rStream['genre'] ?? 'TV Series, Drama',
                    'duration' => 'TV Series',
                ];
            }
        }

        // 3. Fallback to direct catalog items if popular file has no matches
        if (empty($rPopularNow)) {
            // Pick up to 5 movies
            if (!empty($vodIdsSafe)) {
                $db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', array_slice($vodIdsSafe, 0, 10)) . ') LIMIT 5;');
                foreach ($db->get_rows() ?: [] as $rStream) {
                    $rProperties = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
                    $simBackdrop = '';
                    if (!empty($rProperties['backdrop_path'])) {
                        $simBackdrop = is_array($rProperties['backdrop_path']) ? ($rProperties['backdrop_path'][0] ?? '') : $rProperties['backdrop_path'];
                    }
                    $rPopularNow[] = [
                        'type'     => 'movie',
                        'id'       => (int)$rStream['id'],
                        'title'    => $rStream['stream_display_name'],
                        'year'     => $rStream['year'] ?: null,
                        'rating'   => $rStream['rating'] ?? ($rProperties['rating'] ?? null),
                        'cover'    => ImageUtils::validateURL($rProperties['movie_image'] ?? '') ?: '',
                        'backdrop' => ImageUtils::validateURL($simBackdrop) ?: '',
                        'plot'     => $rProperties['plot'] ?? ($rProperties['description'] ?? ''),
                        'genre'    => $rProperties['genre'] ?? 'Cinema, Feature Film',
                        'duration' => !empty($rProperties['duration']) ? $rProperties['duration'] : 'Feature Film',
                    ];
                }
            }

            // Pick up to 5 series
            if (!empty($seriesIdsSafe)) {
                $db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path`, `plot`, `genre` FROM `streams_series` WHERE `id` IN (' . implode(',', array_slice($seriesIdsSafe, 0, 10)) . ') LIMIT 5;');
                foreach ($db->get_rows() ?: [] as $rStream) {
                    $rBackdrop = json_decode($rStream['backdrop_path'] ?? '', true);
                    $simBackdrop = is_array($rBackdrop) ? ($rBackdrop[0] ?? '') : '';
                    $rPopularNow[] = [
                        'type'     => 'series',
                        'id'       => (int)$rStream['id'],
                        'title'    => $rStream['title'],
                        'year'     => $rStream['year'] ?: null,
                        'rating'   => $rStream['rating'] ?? null,
                        'cover'    => ImageUtils::validateURL($rStream['cover'] ?? '') ?: '',
                        'backdrop' => ImageUtils::validateURL($simBackdrop) ?: '',
                        'plot'     => $rStream['plot'] ?? '',
                        'genre'    => $rStream['genre'] ?? 'TV Series',
                        'duration' => 'TV Series',
                    ];
                }
            }
        }

        // Prepare Hero Billboard slides (items with backdrops or high ratings)
        $heroSlides = [];
        foreach ($rPopularNow as $item) {
            if (!empty($item['backdrop']) || !empty($item['cover'])) {
                $heroSlides[] = $item;
            }
        }
        if (empty($heroSlides) && !empty($rPopularNow)) {
            $heroSlides = $rPopularNow;
        }
        $heroSlides = array_slice($heroSlides, 0, 5);

        // Top 10 items
        $top10Items = array_slice($rPopularNow, 0, 10);

        // Fetch recent movies
        $rMovies = function_exists('getUserStreams')
            ? getUserStreams($rUserInfo, ['movie'], null, null, 'added', null, null, 0, 18)
            : ['streams' => []];

        // Fetch recent series
        $rSeries = function_exists('getUserSeries')
            ? getUserSeries($rUserInfo, null, null, 'added', null, null, 0, 18)
            : ['streams' => []];

        // Fetch live channels preview (8 channels)
        $rLiveChannels = function_exists('getUserStreams')
            ? getUserStreams($rUserInfo, ['live', 'created_live'], null, null, 'number', null, null, 0, 8)
            : ['streams' => []];

        // Fetch radio preview (6 stations)
        $rRadioStreams = function_exists('getUserStreams')
            ? getUserStreams($rUserInfo, ['radio_streams'], null, null, 'number', null, null, 0, 6)
            : ['streams' => []];

        // Total Counts for quick stats
        $totalLive = count($rUserInfo['live_ids'] ?? []);
        $totalVod  = count($rUserInfo['vod_ids'] ?? []);
        $totalSeries = count($rUserInfo['series_ids'] ?? []);
        $totalRadio  = count($rUserInfo['radio_ids'] ?? []);

        $GLOBALS['_TITLE'] = 'Web Player V2 - Home';
        $GLOBALS['_PAGE']  = 'index';

        $this->render('index', [
            'heroSlides'     => $heroSlides,
            'top10Items'     => $top10Items,
            'rMovies'        => $rMovies,
            'rSeries'        => $rSeries,
            'rLiveChannels'  => $rLiveChannels,
            'rRadioStreams'  => $rRadioStreams,
            'totalLive'      => $totalLive,
            'totalVod'       => $totalVod,
            'totalSeries'    => $totalSeries,
            'totalRadio'     => $totalRadio,
        ]);
    }
}
