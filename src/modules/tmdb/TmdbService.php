<?php

/**
 * XC_VM — TmdbService
 *
 * Сервис для работы с TMDB API.
 * Извлечён из admin/api.php (действия: tmdb_search, tmdb).
 *
 * Ответственность:
 *   - Создание экземпляра TMDB с правильной локализацией
 *   - Поиск фильмов/сериалов по названию или TMDB ID
 *   - Получение детальной информации (getMovie, getTVShow, getSeason)
 *   - Трейлеры сериалов
 *
 * Зависимости:
 *   - includes/libs/tmdb.php (класс TMDB)
 *   - includes/libs/tmdb_release.php (parserelease)
 *   - includes/admin.php (getSeriesTrailer)
 */
class TmdbService {

    /**
     * Создать экземпляр TMDB API-клиента
     *
     * @param string      $apiKey   TMDB API key
     * @param string|null $language Язык запроса (приоритет: явный → настройка → default)
     * @return TMDB
     */
    public static function createClient(string $apiKey, ?string $language = null): TMDB {
        if ($language !== null && strlen($language) > 0) {
            return new TMDB($apiKey, $language);
        }

        $settingsLang = CoreUtilities::$rSettings['tmdb_language'] ?? '';
        if (strlen($settingsLang) > 0) {
            return new TMDB($apiKey, $settingsLang);
        }

        return new TMDB($apiKey);
    }

    /**
     * Поиск по TMDB (фильмы, сериалы, эпизоды)
     *
     * Поддерживает поиск:
     *   - По числовому ID (прямой getMovie/getTVShow/getSeason)
     *   - По текстовому запросу (searchMovie/searchTVShow)
     *
     * @param string      $term     Поисковый запрос или TMDB ID
     * @param string      $type     Тип: movie|series|episode
     * @param string|null $language Язык запроса
     * @param int|null    $season   Номер сезона (для episode)
     * @return array ['result' => bool, 'data' => array|null]
     */
    public static function search(string $term, string $type, ?string $language = null, ?int $season = null): array {
        $apiKey = CoreUtilities::$rSettings['tmdb_api_key'] ?? '';
        if (strlen($apiKey) === 0) {
            return ['result' => false];
        }

        self::requireLibrary();
        $rTMDB = self::createClient($apiKey, $language);

        // Прямой поиск по числовому TMDB ID
        if (is_numeric($term) && in_array($type, ['movie', 'series', 'episode'])) {
            $rResult = self::fetchByID($rTMDB, $term, $type, $season);
            if (is_array($rResult)) {
                return ['result' => true, 'data' => $rResult];
            }
        }

        // Текстовый поиск
        $rRelease = parserelease($term);
        $searchTerm = $rRelease['title'];
        $rJSON = [];

        if ($type === 'movie') {
            foreach ($rTMDB->searchMovie($searchTerm) as $rResult) {
                $rJSON[] = json_decode($rResult->getJSON(), true);
            }
        } elseif ($type === 'series') {
            foreach ($rTMDB->searchTVShow($searchTerm) as $rResult) {
                $rJSON[] = json_decode($rResult->getJSON(), true);
            }
        }

        if (count($rJSON) > 0) {
            return ['result' => true, 'data' => $rJSON];
        }

        return ['result' => false];
    }

    /**
     * Получить детальную информацию о фильме/сериале по TMDB ID
     *
     * @param int         $id       TMDB ID
     * @param string      $type     Тип: movie|series
     * @param string|null $language Язык запроса
     * @return array ['result' => bool, 'data' => array|null]
     */
    public static function getDetails(int $id, string $type, ?string $language = null): array {
        $apiKey = CoreUtilities::$rSettings['tmdb_api_key'] ?? '';
        if (strlen($apiKey) === 0) {
            return ['result' => false];
        }

        self::requireLibrary();
        $rTMDB = self::createClient($apiKey, $language);
        $rResult = null;

        if ($type === 'movie') {
            $rMovie = $rTMDB->getMovie($id);
            $rResult = json_decode($rMovie->getJSON(), true);
            $rResult['trailer'] = $rMovie->getTrailer();
        } elseif ($type === 'series') {
            $rSeries = $rTMDB->getTVShow($id);
            $rResult = json_decode($rSeries->getJSON(), true);
            $settingsLang = CoreUtilities::$rSettings['tmdb_language'] ?? '';
            $rResult['trailer'] = getSeriesTrailer($id, ($language ?: $settingsLang));
        }

        if (!$rResult) {
            return ['result' => false];
        }

        return ['result' => true, 'data' => $rResult];
    }

    /**
     * Получить данные по числовому ID
     *
     * @param TMDB     $tmdb   Экземпляр TMDB
     * @param string   $id     TMDB ID
     * @param string   $type   Тип: movie|series|episode
     * @param int|null $season Номер сезона
     * @return array|null
     */
    private static function fetchByID(TMDB $tmdb, string $id, string $type, ?int $season): ?array {
        if ($type === 'movie') {
            return [json_decode($tmdb->getMovie($id)->getJSON(), true)];
        }

        if ($type === 'series') {
            return [json_decode($tmdb->getTVShow($id)->getJSON(), true)];
        }

        if ($type === 'episode' && $season !== null) {
            $rResult = json_decode($tmdb->getSeason($id, $season)->getJSON(), true);
            if (isset($rResult['tvshow_id']) && $rResult['tvshow_id'] == 0) {
                return null;
            }
            return $rResult;
        }

        return null;
    }

    /**
     * Подключить библиотеку TMDB (один раз)
     */
    private static function requireLibrary(): void {
        static $loaded = false;
        if (!$loaded) {
            require_once INCLUDES_PATH . 'libs/tmdb.php';
            $loaded = true;
        }
    }
}
