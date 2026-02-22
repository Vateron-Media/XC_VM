<?php

/**
 * XC_VM — TmdbCron
 *
 * Крон обработки очереди TMDB (watch_refresh).
 * Извлечён из crons/tmdb.php.
 *
 * Ответственность:
 *   - Обработка очереди watch_refresh (фильмы, сериалы, эпизоды)
 *   - Поиск совпадений в TMDB API
 *   - Обновление метаданных в БД
 */
class TmdbCron
{
    /**
     * Создать TMDB-клиент с корректным языком.
     *
     * Приоритет: язык потока → глобальная настройка → без языка.
     *
     * @param string|null $streamLang Язык потока (tmdb_language)
     * @return TMDB
     */
    private static function createTmdbClient(?string $streamLang = null): TMDB
    {
        if (0 < strlen($streamLang)) {
            return new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $streamLang);
        }
        if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
            return new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
        }
        return new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
    }

    /**
     * Найти лучшее совпадение в TMDB по названию.
     *
     * Выполняет поиск дважды: сначала с годом, затем без.
     * Возвращает TMDB ID или 0.
     *
     * @param TMDB        $tmdb       Инстанс клиента
     * @param string      $title      Основной заголовок
     * @param string|null $altTitle    Альтернативный заголовок
     * @param string|null $year       Год выпуска
     * @param string      $searchType 'movie' или 'tv'
     * @return int TMDB ID (0 — не найден)
     */
    private static function findBestMatch(TMDB $tmdb, string $title, ?string $altTitle, ?string $year, string $searchType = 'movie'): int
    {
        $rMatch = null;
        $rMatches = array();

        foreach (range(0, 1) as $rIgnoreYear) {
            if (!$rIgnoreYear) {
            } else {
                if ($year) {
                    $year = null;
                } else {
                    break;
                }
            }

            $rResults = ($searchType === 'movie')
                ? $tmdb->searchMovie($title, $year)
                : $tmdb->searchTVShow($title, $year);

            foreach ($rResults as $rResultArr) {
                $rPercentage = 0;
                $rPercentageAlt = 0;

                similar_text(
                    strtoupper($title),
                    strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))),
                    $rPercentage
                );

                if (!$altTitle) {
                } else {
                    similar_text(
                        strtoupper($altTitle),
                        strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))),
                        $rPercentageAlt
                    );
                }

                if (!(CoreUtilities::$rSettings['percentage_match'] <= $rPercentage
                    || CoreUtilities::$rSettings['percentage_match'] <= $rPercentageAlt)) {
                } else {
                    if ($year && !in_array(
                        intval(substr(($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date')), 0, 4)),
                        range(intval($year) - 1, intval($year) + 1)
                    )) {
                    } else {
                        if ($altTitle && strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($altTitle)) {
                            $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                            break;
                        }
                        if (strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($title) && !$altTitle) {
                            $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                            break;
                        }
                        $rMatches[] = array('percentage' => $rPercentage, 'data' => $rResultArr);
                    }
                }
            }

            if (0 >= count($rMatches)) {
            } else {
                break;
            }
        }

        if (0 >= count($rMatches)) {
            return 0;
        }

        $rMax = max(array_column($rMatches, 'percentage'));
        $rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
            return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
        }, $rMatches));
        list($rMatch) = array_values($rKeys);

        return $rMatch ? intval($rMatch->get('id')) : 0;
    }

    /**
     * Обработать обновление фильма (type=1).
     *
     * Ищет в TMDB по названию/файлу, загружает метаданные,
     * обновляет streams и watch_refresh.
     *
     * @param array $row Строка из watch_refresh
     */
    private static function processMovie(array $row): void
    {
        global $db;

        $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $row['stream_id']);
        if ($db->num_rows() != 1) {
            $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $row['id']);
            return;
        }

        $rStream = $db->get_row();
        $rTMDB = self::createTmdbClient($rStream['tmdb_language']);

        /* --- Определяем TMDB ID --- */
        if ($rStream['tmdb_id']) {
            $rTMDBID = $rStream['tmdb_id'];
        } else {
            if (0 < strlen($rStream['movie_properties'])) {
                $rTMDBID = intval(json_decode($rStream['movie_properties'], true)['tmdb_id']);
            } else {
                $rTMDBID = 0;
            }
        }

        /* --- Если TMDB ID неизвестен — ищем --- */
        if ($rTMDBID == 0) {
            $rFilename = pathinfo(json_decode($rStream['stream_source'], true)[0])['filename'];
            foreach (array($rFilename, $rStream['stream_display_name']) as $rStreamTitle) {
                $rRelease = parserelease($rStreamTitle);
                $rTitle = $rRelease['title'];

                if (!isset($rRelease['excess'])) {
                } else {
                    $rTitle = trim($rTitle, (is_array($rRelease['excess']) ? $rRelease['excess'][0] : $rRelease['excess']));
                }

                $rAltTitle = null;
                if (isset($rRelease['group'])) {
                    $rAltTitle = $rTitle . '-' . $rRelease['group'];
                } else {
                    if (!isset($rRelease['alternative_title'])) {
                    } else {
                        $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                    }
                }

                if (!isset($rRelease['season'])) {
                } else {
                    $rTitle .= $rRelease['season'];
                }

                $rYear = $rRelease['year'];
                if ($rTitle) {
                } else {
                    $rTitle = $rStreamTitle;
                }

                $rTMDBID = self::findBestMatch($rTMDB, $rTitle, $rAltTitle, $rYear, 'movie');
                if ($rTMDBID > 0) {
                    break;
                }
            }
        }

        /* --- Загружаем данные фильма --- */
        if (0 < $rTMDBID) {
            $rMovie = $rTMDB->getMovie($rTMDBID);
            $rMovieData = json_decode($rMovie->getJSON(), true);
            $rMovieData['trailer'] = $rMovie->getTrailer();

            $rThumb = ($rMovieData['poster_path']
                ? 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path']
                : '');
            $rBG = ($rMovieData['backdrop_path']
                ? 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path']
                : '');

            if (!CoreUtilities::$rSettings['download_images']) {
            } else {
                if (empty($rThumb)) {
                } else {
                    $rThumb = CoreUtilities::downloadImage($rThumb, 2);
                }
                if (empty($rBG)) {
                } else {
                    $rBG = CoreUtilities::downloadImage($rBG);
                }
            }

            if (!$rBG) {
            } else {
                $rBG = array($rBG);
            }

            $rCast = array();
            foreach ($rMovieData['credits']['cast'] as $rMember) {
                if (count($rCast) >= 5) {
                } else {
                    $rCast[] = $rMember['name'];
                }
            }

            $rDirectors = array();
            foreach ($rMovieData['credits']['crew'] as $rMember) {
                if (!(count($rDirectors) < 5
                    && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing'))
                    || in_array($rMember['name'], $rDirectors)) {
                } else {
                    $rDirectors[] = $rMember['name'];
                }
            }

            $rCountry = '';
            if (!isset($rMovieData['production_countries'][0]['name'])) {
            } else {
                $rCountry = $rMovieData['production_countries'][0]['name'];
            }

            $rGenres = array();
            foreach ($rMovieData['genres'] as $rGenre) {
                if (count($rGenres) >= 3) {
                } else {
                    $rGenres[] = $rGenre['name'];
                }
            }

            $rSeconds = intval($rMovieData['runtime']) * 60;

            $rProperties = array(
                'kinopoisk_url'        => 'https://www.themoviedb.org/movie/' . $rMovieData['id'],
                'tmdb_id'              => $rMovieData['id'],
                'name'                 => $rMovieData['title'],
                'o_name'               => $rMovieData['original_title'],
                'cover_big'            => $rThumb,
                'movie_image'          => $rThumb,
                'release_date'         => $rMovieData['release_date'],
                'episode_run_time'     => $rMovieData['runtime'],
                'youtube_trailer'      => $rMovieData['trailer'],
                'director'             => implode(', ', $rDirectors),
                'actors'               => implode(', ', $rCast),
                'cast'                 => implode(', ', $rCast),
                'description'          => $rMovieData['overview'],
                'plot'                 => $rMovieData['overview'],
                'age'                  => '',
                'mpaa_rating'          => '',
                'rating_count_kinopoisk' => 0,
                'country'              => $rCountry,
                'genre'                => implode(', ', $rGenres),
                'backdrop_path'        => $rBG,
                'duration_secs'        => $rSeconds,
                'duration'             => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60),
                'video'                => array(),
                'audio'                => array(),
                'bitrate'              => 0,
                'rating'               => $rMovieData['vote_average'],
            );

            $rTitle   = $rMovieData['title'];
            $rYear    = null;
            $rRating  = ($rMovieData['vote_average'] ?: 0);

            if (0 >= strlen($rMovieData['release_date'])) {
            } else {
                $rYear = intval(substr($rMovieData['release_date'], 0, 4));
            }

            $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $row['id']);
            $db->query(
                'UPDATE `streams` SET `stream_display_name` = ?, `year` = ?, `movie_properties` = ?, `rating` = ? WHERE `id` = ?;',
                $rTitle,
                $rYear,
                json_encode($rProperties, JSON_UNESCAPED_UNICODE),
                $rRating,
                $row['stream_id']
            );
        } else {
            $db->query('UPDATE `watch_refresh` SET `status` = -1 WHERE `id` = ?;', $row['id']);
        }
    }

    /**
     * Обработать обновление сериала (type=2).
     *
     * Ищет в TMDB по названию, загружает метаданные шоу,
     * обновляет streams_series и watch_refresh.
     *
     * @param array $row           Строка из watch_refresh
     * @param array &$updateSeries Массив ID сериалов для последующего updateSeries()
     */
    private static function processSeries(array $row, array &$updateSeries): void
    {
        global $db;

        $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $row['stream_id']);
        if ($db->num_rows() != 1) {
            $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $row['id']);
            return;
        }

        $rStream = $db->get_row();
        $rTMDB = self::createTmdbClient($rStream['tmdb_language']);
        $rTMDBID = intval($rStream['tmdb_id']);

        /* --- Если TMDB ID неизвестен — ищем --- */
        if ($rTMDBID == 0) {
            $rFilename = $rStream['title'];
            $rRelease = parserelease($rFilename);
            $rTitle = $rRelease['title'];

            if (!isset($rRelease['excess'])) {
            } else {
                $rTitle = trim($rTitle, (is_array($rRelease['excess']) ? $rRelease['excess'][0] : $rRelease['excess']));
            }

            $rAltTitle = null;
            if (isset($rRelease['group'])) {
                $rAltTitle = $rTitle . '-' . $rRelease['group'];
            } else {
                if (!isset($rRelease['alternative_title'])) {
                } else {
                    $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                }
            }

            $rYear = $rRelease['year'];
            if ($rTitle) {
            } else {
                $rTitle = $rFilename;
            }

            $rTMDBID = self::findBestMatch($rTMDB, $rTitle, $rAltTitle, $rYear, 'tv');
        }

        /* --- Загружаем данные шоу --- */
        if (0 < $rTMDBID) {
            $rShow = $rTMDB->getTVShow($rTMDBID);
            $rShowData = json_decode($rShow->getJSON(), true);

            $rSeriesArray = $rStream;
            $rSeriesArray['title']           = $rShowData['name'];
            $rSeriesArray['tmdb_id']         = $rShowData['id'];
            $rSeriesArray['plot']            = $rShowData['overview'];
            $rSeriesArray['rating']          = $rShowData['vote_average'];
            $rSeriesArray['release_date']    = $rShowData['first_air_date'];
            $rSeriesArray['youtube_trailer'] = getSeriesTrailer($rShowData['id']);
            $rSeriesArray['cover']           = ($rShowData['poster_path']
                ? 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rShowData['poster_path']
                : '');
            $rSeriesArray['cover_big']       = $rSeriesArray['cover'];
            $rSeriesArray['backdrop_path']   = array();

            $rBG = ($rShowData['backdrop_path']
                ? 'https://image.tmdb.org/t/p/w1280' . $rShowData['backdrop_path']
                : '');

            if (!CoreUtilities::$rSettings['download_images']) {
            } else {
                if (empty($rSeriesArray['cover'])) {
                } else {
                    $rSeriesArray['cover'] = CoreUtilities::downloadImage($rSeriesArray['cover'], 2);
                }
                if (empty($rBG)) {
                } else {
                    $rBG = CoreUtilities::downloadImage($rBG);
                }
            }

            if (empty($rBG)) {
            } else {
                $rSeriesArray['backdrop_path'][] = $rBG;
            }

            $rCast = array();
            foreach ($rShowData['credits']['cast'] as $rMember) {
                if (count($rCast) >= 5) {
                } else {
                    $rCast[] = $rMember['name'];
                }
            }
            $rSeriesArray['cast'] = implode(', ', $rCast);

            $rDirectors = array();
            foreach ($rShowData['credits']['crew'] as $rMember) {
                if (!(count($rDirectors) < 5
                    && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing'))
                    || in_array($rMember['name'], $rDirectors)) {
                } else {
                    $rDirectors[] = $rMember['name'];
                }
            }
            $rSeriesArray['director'] = implode(', ', $rDirectors);

            $rGenres = array();
            foreach ($rShowData['genres'] as $rGenre) {
                if (count($rGenres) >= 3) {
                } else {
                    $rGenres[] = $rGenre['name'];
                }
            }
            $rSeriesArray['genre'] = implode(', ', $rGenres);

            $rSeriesArray['episode_run_time'] = intval($rShowData['episode_run_time'][0]);

            $rPrepare = prepareArray($rSeriesArray);
            $rQuery = 'REPLACE INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

            if ($db->query($rQuery, ...$rPrepare['data'])) {
                $rInsertID = $db->last_insert_id();
                updateSeries(intval($rInsertID));
                $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $row['id']);
            } else {
                $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $row['id']);
            }
        } else {
            $db->query('UPDATE `watch_refresh` SET `status` = -1 WHERE `id` = ?;', $row['id']);
        }
    }

    /**
     * Обработать обновление эпизода (type=3).
     *
     * Получает данные сезона/эпизода из TMDB,
     * обновляет streams, streams_episodes и watch_refresh.
     *
     * @param array $row           Строка из watch_refresh
     * @param array &$updateSeries Массив ID сериалов для последующего updateSeries()
     */
    private static function processEpisode(array $row, array &$updateSeries): void
    {
        global $db;

        $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $row['stream_id']);
        if ($db->num_rows() != 1) {
            $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $row['id']);
            return;
        }
        $rStream = $db->get_row();

        $db->query('SELECT * FROM `streams_episodes` WHERE `stream_id` = ?;', $row['stream_id']);
        if ($db->num_rows() != 1) {
            $db->query('UPDATE `watch_refresh` SET `status` = -3 WHERE `id` = ?;', $row['id']);
            return;
        }
        $rSeriesEpisode = $db->get_row();

        $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rSeriesEpisode['series_id']);
        if ($db->num_rows() != 1) {
            $db->query('UPDATE `watch_refresh` SET `status` = -4 WHERE `id` = ?;', $row['id']);
            return;
        }
        $rSeries = $db->get_row();

        $rTMDB = self::createTmdbClient($rSeries['tmdb_language']);

        if (!(0 < strlen($rSeries['tmdb_id']))) {
            $db->query('UPDATE `watch_refresh` SET `status` = -5 WHERE `id` = ?;', $row['id']);
            return;
        }

        $rShow = $rTMDB->getTVShow($rSeries['tmdb_id']);
        $rShowData = json_decode($rShow->getJSON(), true);

        if (!isset($rShowData['name'])) {
            return;
        }

        $rFilename = pathinfo(json_decode($rStream['stream_source'], true)[0])['filename'];
        $rRelease = parserelease($rFilename);
        $rReleaseSeason = $rRelease['season'];

        if (is_array($rRelease['episode'])) {
            $rReleaseEpisode = $rRelease['episode'][0];
        } else {
            $rReleaseEpisode = $rRelease['episode'];
        }

        if ($rReleaseSeason && $rReleaseEpisode) {
        } else {
            $rReleaseSeason  = $rSeriesEpisode['season_num'];
            $rReleaseEpisode = $rSeriesEpisode['episode_num'];
        }

        if (is_array($rRelease['episode']) && count($rRelease['episode']) == 2) {
            $rTitle = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason))
                . 'E' . sprintf('%02d', $rRelease['episode'][0])
                . '-' . sprintf('%02d', $rRelease['episode'][1]);
        } else {
            $rTitle = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason))
                . 'E' . sprintf('%02d', $rReleaseEpisode);
        }

        $rEpisodes  = json_decode($rTMDB->getSeason($rShowData['id'], intval($rReleaseSeason))->getJSON(), true);
        $rProperties = array();

        foreach ($rEpisodes['episodes'] as $rEpisode) {
            if (intval($rEpisode['episode_number']) != $rReleaseEpisode) {
            } else {
                $rImage = null;
                if (0 >= strlen($rEpisode['still_path'])) {
                } else {
                    $rImage = 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'];
                    if (!CoreUtilities::$rSettings['download_images']) {
                    } else {
                        $rImage = CoreUtilities::downloadImage($rImage, 5);
                    }
                }

                if (0 >= strlen($rEpisode['name'])) {
                } else {
                    $rTitle .= ' - ' . $rEpisode['name'];
                }

                $rSeconds = intval($rShowData['episode_run_time'][0]) * 60;

                $rProperties = array(
                    'tmdb_id'       => $rEpisode['id'],
                    'release_date'  => $rEpisode['air_date'],
                    'plot'          => $rEpisode['overview'],
                    'duration_secs' => $rSeconds,
                    'duration'      => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60),
                    'movie_image'   => $rImage,
                    'video'         => array(),
                    'audio'         => array(),
                    'bitrate'       => 0,
                    'rating'        => $rEpisode['vote_average'],
                    'season'        => $rReleaseSeason,
                );

                if (strlen($rProperties['movie_image'][0]) != 0) {
                } else {
                    unset($rProperties['movie_image']);
                }

                break;
            }
        }

        $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $row['id']);
        $db->query(
            'UPDATE `streams` SET `stream_display_name` = ?, `movie_properties` = ? WHERE `id` = ?;',
            $rTitle,
            json_encode($rProperties, JSON_UNESCAPED_UNICODE),
            $row['stream_id']
        );
        $db->query(
            'UPDATE `streams_episodes` SET `season_num` = ?, `episode_num` = ? WHERE `stream_id` = ?;',
            $rReleaseSeason,
            $rReleaseEpisode,
            $row['stream_id']
        );

        if (in_array($rSeries['id'], $updateSeries)) {
        } else {
            $updateSeries[] = $rSeries['id'];
        }
    }

    /**
     * Основная точка входа — обработка всей очереди watch_refresh.
     *
     * Подключает библиотеки TMDB, выбирает записи из очереди
     * и делегирует обработку по типу.
     */
    public static function run(): void
    {
        global $db;

        require INCLUDES_PATH . 'libs/tmdb.php';
        require INCLUDES_PATH . 'libs/tmdb_release.php';

        $rUpdateSeries = array();

        $db->query('SELECT `id`, `type`, `stream_id` FROM `watch_refresh` WHERE `status` = 0 ORDER BY `stream_id` ASC;');

        foreach ($db->get_rows() as $rRow) {
            if ($rRow['type'] == 1) {
                self::processMovie($rRow);
            } else if ($rRow['type'] == 2) {
                self::processSeries($rRow, $rUpdateSeries);
            } else if ($rRow['type'] == 3) {
                self::processEpisode($rRow, $rUpdateSeries);
            } else {
                /* type=4 — запрос на updateSeries */
                if (in_array($rRow['stream_id'], $rUpdateSeries)) {
                } else {
                    $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $rRow['id']);
                    $rUpdateSeries[] = intval($rRow['stream_id']);
                }
            }
        }

        foreach ($rUpdateSeries as $rSeriesID) {
            updateSeries(intval($rSeriesID));
        }
    }
}
