<?php

/**
 * XC_VM — Автозагрузчик классов
 *
 * Загружает классы по имени без namespace и без Composer.
 * Работает в двух режимах:
 *
 *   1. Карта классов (class map) — явное соответствие ClassName → file path.
 *      Приоритетный режим. Гарантирует предсказуемость и скорость.
 *
 *   2. Поиск по директориям (fallback) — если класса нет в карте,
 *      ищет файл {ClassName}.php в зарегистрированных директориях.
 *      Используется для новых классов, добавляемых при миграции.
 *
 * Кэширование:
 *   Результаты поиска по директориям кэшируются в runtime массив,
 *   чтобы повторный autoload того же класса не сканировал файловую систему.
 *   Опционально можно включить файловый кэш через XC_Autoloader::enableFileCache().
 *
 * Использование:
 *   require_once '/home/xc_vm/autoload.php';
 *   // Всё. Классы из карты и зарегистрированных директорий загружаются автоматически.
 *
 * Добавление новых директорий для поиска:
 *   XC_Autoloader::addDirectory('/home/xc_vm/core/Config');
 *   XC_Autoloader::addDirectory('/home/xc_vm/domain/Stream');
 *
 * Добавление новых классов в карту (при необходимости):
 *   XC_Autoloader::addClass('MyNewService', '/home/xc_vm/core/MyNewService.php');
 */

class XC_Autoloader {
    /**
     * Корневая директория проекта (/home/xc_vm/)
     */
    private static $basePath = '';

    /**
     * Явная карта: имя класса → абсолютный путь к файлу
     * @var array<string, string>
     */
    private static $classMap = [];

    /**
     * Директории для fallback-поиска (абсолютные пути)
     * @var string[]
     */
    private static $directories = [];

    /**
     * Runtime-кэш найденных через fallback классов
     * @var array<string, string>
     */
    private static $resolved = [];

    /**
     * Путь к файлу кэша (null = файловый кэш отключён)
     * @var string|null
     */
    private static $cacheFile = null;

    /**
     * Флаг: были ли новые разрешения с момента загрузки кэша
     */
    private static $cacheDirty = false;

    /**
     * Инициализация автозагрузчика
     *
     * @param string $basePath Корневая директория проекта
     */
    public static function init($basePath) {
        self::$basePath = rtrim($basePath, '/') . '/';

        self::buildClassMap();
        self::registerDirectories();

        spl_autoload_register([__CLASS__, 'load'], true, true);

        // Сохранение файлового кэша при завершении, если он изменился
        register_shutdown_function([__CLASS__, 'saveCache']);
    }

    /**
     * Основной метод загрузки класса
     *
     * @param string $className Имя класса
     * @return bool
     */
    public static function load($className) {
        // 1. Проверяем явную карту
        if (isset(self::$classMap[$className])) {
            $file = self::$classMap[$className];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 2. Проверяем runtime-кэш (уже находили через fallback)
        if (isset(self::$resolved[$className])) {
            $file = self::$resolved[$className];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 3. Fallback: поиск по зарегистрированным директориям
        $file = self::findInDirectories($className);
        if ($file !== null) {
            self::$resolved[$className] = $file;
            self::$cacheDirty = true;
            require_once $file;
            return true;
        }

        // Класс не найден — возвращаем false, пусть следующий autoloader попробует
        return false;
    }

    /**
     * Добавить класс в карту вручную
     *
     * @param string $className Имя класса
     * @param string $filePath  Абсолютный путь к файлу
     */
    public static function addClass($className, $filePath) {
        self::$classMap[$className] = $filePath;
    }

    /**
     * Добавить директорию для fallback-поиска
     *
     * @param string $directory Абсолютный путь к директории
     */
    public static function addDirectory($directory) {
        $dir = rtrim($directory, '/');
        if (is_dir($dir) && !in_array($dir, self::$directories, true)) {
            self::$directories[] = $dir;
        }
    }

    /**
     * Включить файловый кэш для ускорения повторных запросов
     *
     * @param string|null $path Путь к файлу кэша (null = отключить)
     */
    public static function enableFileCache($path) {
        self::$cacheFile = $path;
        if ($path !== null && file_exists($path)) {
            $data = @file_get_contents($path);
            if ($data !== false) {
                $cached = @igbinary_unserialize($data);
                if (is_array($cached)) {
                    self::$resolved = $cached;
                }
            }
        }
    }

    /**
     * Сохранить файловый кэш (вызывается автоматически при shutdown)
     */
    public static function saveCache() {
        if (self::$cacheFile !== null && self::$cacheDirty) {
            $dir = dirname(self::$cacheFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents(self::$cacheFile, igbinary_serialize(self::$resolved), LOCK_EX);
            self::$cacheDirty = false;
        }
    }

    /**
     * Сбросить файловый кэш
     */
    public static function clearCache() {
        self::$resolved = [];
        self::$cacheDirty = false;
        if (self::$cacheFile !== null && file_exists(self::$cacheFile)) {
            @unlink(self::$cacheFile);
        }
    }

    /**
     * Получить текущую карту классов (для отладки)
     *
     * @return array
     */
    public static function getClassMap() {
        return self::$classMap;
    }

    /**
     * Получить список зарегистрированных директорий (для отладки)
     *
     * @return string[]
     */
    public static function getDirectories() {
        return self::$directories;
    }

    // ─────────────────────────────────────────────────────────────
    //  Приватные методы
    // ─────────────────────────────────────────────────────────────

    /**
     * Поиск файла класса в зарегистрированных директориях
     *
     * @param string $className
     * @return string|null Абсолютный путь или null
     */
    private static function findInDirectories($className) {
        $fileName = $className . '.php';

        foreach (self::$directories as $dir) {
            // Прямой поиск: directory/ClassName.php
            $path = $dir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        // Рекурсивный поиск по зарегистрированным корневым директориям
        foreach (self::$directories as $dir) {
            $found = self::findRecursive($dir, $fileName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Рекурсивный поиск файла в поддиректориях
     *
     * @param string $dir      Директория для поиска
     * @param string $fileName Имя файла (ClassName.php)
     * @return string|null
     */
    private static function findRecursive($dir, $fileName) {
        $items = @scandir($dir);
        if ($items === false) {
            return null;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $dir . '/' . $item;
            if (is_dir($fullPath)) {
                $found = self::findRecursive($fullPath, $fileName);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Карта существующих классов проекта
     *
     * Содержит ВСЕ текущие классы с явными путями.
     * При миграции: новый класс переносится → обновляется путь здесь.
     * Когда старый класс удаляется → удаляется строка отсюда.
     */
    private static function buildClassMap() {
        $base = self::$basePath;

        self::$classMap = [
            // ── Ядро (новая архитектура) ──────────────────────────
            'ServiceContainer'      => $base . 'core/Container/ServiceContainer.php',
            'DatabaseHandler'       => $base . 'core/Database/DatabaseHandler.php',
            'CacheInterface'        => $base . 'core/Cache/CacheInterface.php',
            'FileCache'             => $base . 'core/Cache/FileCache.php',
            'RedisCache'            => $base . 'core/Cache/RedisCache.php',
            'Request'               => $base . 'core/Http/Request.php',
            'Response'              => $base . 'core/Http/Response.php',
            'CurlClient'            => $base . 'core/Http/CurlClient.php',
            'DomainResolver'        => $base . 'core/Config/DomainResolver.php',
            'SettingsRepository'    => $base . 'core/Config/SettingsRepository.php',
            'SessionManager'        => $base . 'core/Auth/SessionManager.php',
            'BruteforceGuard'       => $base . 'core/Auth/BruteforceGuard.php',
            'Authorization'         => $base . 'core/Auth/Authorization.php',
            'Authenticator'         => $base . 'core/Auth/Authenticator.php',
            'ProcessManager'        => $base . 'core/Process/ProcessManager.php',
            'LegacyInitializer'     => $base . 'core/Init/LegacyInitializer.php',
            'Encryption'            => $base . 'core/Util/Encryption.php',
            'TimeUtils'             => $base . 'core/Util/TimeUtils.php',
            'GeoIP'                 => $base . 'core/Util/GeoIP.php',
            'NetworkUtils'          => $base . 'core/Util/NetworkUtils.php',
            'ImageUtils'            => $base . 'core/Util/ImageUtils.php',
            'ConnectionTracker'     => $base . 'domain/Stream/ConnectionTracker.php',
            'StreamSorter'          => $base . 'domain/Stream/StreamSorter.php',
            'CategoryRepository'    => $base . 'domain/Stream/CategoryRepository.php',
            'BouquetMapper'         => $base . 'domain/Bouquet/BouquetMapper.php',
            'BouquetRepository'     => $base . 'domain/Bouquet/BouquetRepository.php',
            'ServerRepository'      => $base . 'domain/Server/ServerRepository.php',
            'SystemInfo'            => $base . 'core/Util/SystemInfo.php',
            'EventInterface'        => $base . 'core/Events/EventInterface.php',
            'EventDispatcher'       => $base . 'core/Events/EventDispatcher.php',
            'LoggerInterface'       => $base . 'core/Logging/LoggerInterface.php',
            'FileLogger'            => $base . 'core/Logging/FileLogger.php',
            'DatabaseLogger'        => $base . 'core/Logging/DatabaseLogger.php',
            'RedisManager'          => $base . 'infrastructure/redis/RedisManager.php',

            // ── Ядро (legacy) ─────────────────────────────────────
            'Database'              => $base . 'core/Database/Database.php',
            'CoreUtilities'         => $base . 'includes/CoreUtilities.php',
            'StreamingUtilities'    => $base . 'includes/StreamingUtilities.php',
            'API'                   => $base . 'includes/admin_api.php',
            'ResellerAPI'           => $base . 'includes/reseller_api.php',
            'TS'                    => $base . 'includes/ts.php',

            // ── Библиотеки ────────────────────────────────────────
            'AsyncFileOperations'   => $base . 'includes/libs/AsyncFileOperations.php',
            'DropboxClient'         => $base . 'includes/libs/Dropbox.php',
            'DropboxException'      => $base . 'includes/libs/Dropbox.php',
            'GitHubReleases'        => $base . 'includes/libs/GithubReleases.php',
            'Mobile_Detect'         => $base . 'includes/libs/mobiledetect.php',
            'Translator'            => $base . 'includes/libs/Translator.php',
            'XmlStringStreamer'      => $base . 'includes/libs/XmlStringStreamer.php',
            'TMDB'                  => $base . 'includes/libs/tmdb.php',
            'Release'               => $base . 'includes/libs/tmdb_release.php',
            'Logger'                => $base . 'includes/libs/Logger.php',

            // ── Библиотеки: iptables ──────────────────────────────
            'Chain'                 => $base . 'includes/libs/iptables.php',
            'Rule'                  => $base . 'includes/libs/iptables.php',
            'IptablesService'       => $base . 'includes/libs/iptables.php',
            'Command'               => $base . 'includes/libs/iptables.php',
            'Table'                 => $base . 'includes/libs/iptables.php',
            'TableFactory'          => $base . 'includes/libs/iptables.php',
            'FilterTable'           => $base . 'includes/libs/iptables.php',
            'MangleTable'           => $base . 'includes/libs/iptables.php',
            'NatTable'              => $base . 'includes/libs/iptables.php',
            'RawTable'              => $base . 'includes/libs/iptables.php',
            'SecurityTable'         => $base . 'includes/libs/iptables.php',

            // ── Библиотеки: m3u ───────────────────────────────────
            'M3uEntry'              => $base . 'includes/libs/m3u.php',
            'M3uParser'             => $base . 'includes/libs/m3u.php',
            'M3uData'               => $base . 'includes/libs/m3u.php',
            'ExtInf'                => $base . 'includes/libs/m3u.php',
            'ExtLogo'               => $base . 'includes/libs/m3u.php',
            'ExtTv'                 => $base . 'includes/libs/m3u.php',

            // ── Библиотеки: m3u v2 ───────────────────────────────
            'ParserFacade'          => $base . 'includes/libs/m3u_v2.php',
            'DumperFacade'          => $base . 'includes/libs/m3u_v2.php',
            'DataBuilder'           => $base . 'includes/libs/m3u_v2.php',

            // ── Библиотеки: TMDb ──────────────────────────────────
            'Collection'            => $base . 'includes/libs/TMDb/Collection.php',
            'Company'               => $base . 'includes/libs/TMDb/Company.php',
            'APIConfiguration'      => $base . 'includes/libs/TMDb/config/APIConfiguration.php',
            'Episode'               => $base . 'includes/libs/TMDb/Episode.php',
            'Genre'                 => $base . 'includes/libs/TMDb/Genre.php',
            'Movie'                 => $base . 'includes/libs/TMDb/Movie.php',
            'Person'                => $base . 'includes/libs/TMDb/Person.php',
            'Review'                => $base . 'includes/libs/TMDb/Review.php',
            'Role'                  => $base . 'includes/libs/TMDb/Role.php',
            'MovieRole'             => $base . 'includes/libs/TMDb/roles/MovieRole.php',
            'TVShowRole'            => $base . 'includes/libs/TMDb/roles/TVShowRole.php',
            'Season'                => $base . 'includes/libs/TMDb/Season.php',
            'TVShow'                => $base . 'includes/libs/TMDb/TVShow.php',

            // ── Crons (локальные классы) ──────────────────────────
            'EPG'                   => $base . 'crons/epg.php',

            // ── WWW ───────────────────────────────────────────────
            'SimpleXMLExtended'     => $base . 'www/enigma2.php',
        ];
    }

    /**
     * Регистрация директорий для fallback-поиска
     *
     * Сюда добавляются директории новой архитектуры по мере миграции.
     * При создании нового класса в одной из этих директорий
     * он будет найден автоматически (без добавления в classMap).
     */
    private static function registerDirectories() {
        $base = self::$basePath;

        // Текущие директории с классами
        self::addDirectory($base . 'includes');
        self::addDirectory($base . 'includes/libs');

        // ── Новая архитектура ──────────────────────────────────────
        self::addDirectory($base . 'core');
        //
        // self::addDirectory($base . 'core');  // LEGACY: уже активен выше
        // self::addDirectory($base . 'domain');
        // self::addDirectory($base . 'streaming');
        // self::addDirectory($base . 'modules');
    }
}

// ─────────────────────────────────────────────────────────────────
//  Автоматическая инициализация при подключении файла
// ─────────────────────────────────────────────────────────────────

if (!defined('MAIN_HOME')) {
    define('MAIN_HOME', '/home/xc_vm/');
}

XC_Autoloader::init(MAIN_HOME);

// Файловый кэш (опционально, ускоряет fallback-поиск в production)
// XC_Autoloader::enableFileCache(MAIN_HOME . 'tmp/cache/autoload_map');
