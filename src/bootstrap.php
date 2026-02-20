<?php

/**
 * XC_VM — Единая точка инициализации (bootstrap)
 *
 * Заменяет три дублированных bootstrap-файла:
 *   1. includes/admin.php       (строки 1-100: session, defines, DB, API init)
 *   2. www/init.php             (DB + CoreUtilities для crons и www)
 *   3. www/stream/init.php      (constants + StreamingUtilities для стриминга)
 *
 * Каждый из этих файлов дублирует: загрузку констант, подключение к БД,
 * flood-protection, Logger, error-функции. bootstrap.php объединяет общую
 * часть и предоставляет контекстно-зависимую инициализацию.
 *
 * ──────────────────────────────────────────────────────────────────
 * Контексты инициализации:
 * ──────────────────────────────────────────────────────────────────
 *
 *   CONTEXT_MINIMAL  — только autoload + константы + config + Logger.
 *                      Без подключения к БД. Для скриптов, которым
 *                      нужны только пути и конфигурация.
 *
 *   CONTEXT_CLI      — + Database + CoreUtilities::init().
 *                      Для cron-задач и CLI-скриптов.
 *
 *   CONTEXT_STREAM   — + Database + StreamingUtilities (лёгкий путь).
 *                      Для стриминг-эндпоинтов (live, vod, timeshift).
 *                      Не загружает admin_api, Translator и т.д.
 *
 *   CONTEXT_ADMIN    — + Database + CoreUtilities + API + ResellerAPI
 *                      + Translator + MobileDetect + session.
 *                      Полная инициализация для admin/reseller-панели.
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование:
 * ──────────────────────────────────────────────────────────────────
 *
 *   // В admin-контроллере:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
 *
 *   // В cron-задаче:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
 *
 *   // В стриминг-эндпоинте:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM, ['cached' => true]);
 *
 *   // Только константы (без БД):
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_MINIMAL);
 *
 * ──────────────────────────────────────────────────────────────────
 * Обратная совместимость:
 * ──────────────────────────────────────────────────────────────────
 *
 *   $db остаётся глобальной переменной.
 *   Все статические свойства CoreUtilities / StreamingUtilities
 *   инициализируются как раньше.
 *   Старые файлы (admin.php, init.php, stream/init.php) продолжают
 *   работать — bootstrap.php используется параллельно, а не вместо.
 *   По мере миграции старые bootstrap-файлы будут делегировать сюда.
 */

declare(strict_types=0);

// ─────────────────────────────────────────────────────────────────
//  1. Автозагрузчик классов
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/autoload.php';
// После этого: MAIN_HOME определён, XC_Autoloader инициализирован


// ─────────────────────────────────────────────────────────────────
//  2. Полифиллы (нужны до любой обработки HTTP)
// ─────────────────────────────────────────────────────────────────

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}


// ─────────────────────────────────────────────────────────────────
//  3. Класс XC_Bootstrap
// ─────────────────────────────────────────────────────────────────

class XC_Bootstrap {

    // ── Контексты ─────────────────────────────────────────────
    const CONTEXT_MINIMAL  = 'minimal';
    const CONTEXT_CLI      = 'cli';
    const CONTEXT_STREAM   = 'stream';
    const CONTEXT_ADMIN    = 'admin';

    // ── Внутреннее состояние ──────────────────────────────────
    private static $booted = false;
    private static $context = null;
    private static $options = [];

    // ── Флаги инициализации подсистем ─────────────────────────
    private static $constantsLoaded  = false;
    private static $configLoaded     = false;
    private static $loggerStarted    = false;
    private static $databaseReady    = false;
    private static $coreReady        = false;
    private static $streamingReady   = false;
    private static $adminReady       = false;
    private static $sessionStarted   = false;
    private static $redisReady       = false;

    /**
     * Основная точка входа.
     *
     * @param string $context  Контекст: CONTEXT_MINIMAL | CONTEXT_CLI | CONTEXT_STREAM | CONTEXT_ADMIN
     * @param array  $options  Дополнительные параметры:
     *   'cached'      => bool   Использовать кэш настроек (для stream/cli, default: false)
     *   'redis'       => bool   Подключать Redis (default: true для admin, false для остальных)
     *   'process'     => string Имя процесса для cli_set_process_title()
     *   'shutdown'    => callable Callback при shutdown (заменяет register_shutdown_function)
     */
    public static function boot(string $context = self::CONTEXT_CLI, array $options = []): void {
        if (self::$booted) {
            return;
        }

        self::$context = $context;
        self::$options = array_merge(self::defaults($context), $options);

        // ── Создание контейнера ─────────────────────────────────
        $container = ServiceContainer::getInstance();
        $container->set('context', $context);
        $container->set('options', self::$options);

        // ── Общее для всех контекстов ──────────────────────────

        // Константы и пути (MAIN_HOME, INCLUDES_PATH, CONFIG_PATH, ...) + $_INFO + Logger + error functions
        self::loadConstants();

        // Регистрируем конфигурацию в контейнере
        global $_INFO;
        $container->set('config', $_INFO);

        // ── Flood-protection (только HTTP) ─────────────────────
        if (!self::isCli()) {
            self::floodProtection();
            self::hostVerification();
        }

        // ── Контекстно-зависимая инициализация ─────────────────

        switch ($context) {
            case self::CONTEXT_MINIMAL:
                // Только константы + config. Готово.
                break;

            case self::CONTEXT_CLI:
                self::initDatabase(self::$options['cached']);
                self::initCoreUtilities(self::$options['cached']);
                if (self::$options['redis']) {
                    self::initRedis();
                }
                if (!empty(self::$options['process'])) {
                    cli_set_process_title(self::$options['process']);
                }
                break;

            case self::CONTEXT_STREAM:
                self::initDatabase(true);
                self::initStreamingUtilities();
                break;

            case self::CONTEXT_ADMIN:
                self::initSession();
                self::initDatabase(false);
                self::initCoreUtilities(false);
                self::initRedis();
                self::initAdminAPI();
                self::initTranslator();
                self::registerAdminShutdown();
                break;
        }

        // ── Регистрация сервисов в контейнере ──────────────────
        self::populateContainer($container);

        self::$booted = true;
    }

    // ─────────────────────────────────────────────────────────
    //  Публичные геттеры
    // ─────────────────────────────────────────────────────────

    /**
     * Текущий контекст boot
     */
    public static function getContext(): ?string {
        return self::$context;
    }

    /**
     * Был ли bootstrap выполнен
     */
    public static function isBooted(): bool {
        return self::$booted;
    }

    /**
     * Ссылка на Database (для обратной совместимости)
     */
    public static function getDatabase(): ?Database {
        global $db;
        return $db;
    }

    /**
     * Получить ServiceContainer.
     *
     * @return ServiceContainer
     */
    public static function getContainer(): ServiceContainer {
        return ServiceContainer::getInstance();
    }

    /**
     * Проверка: работает ли в CLI-режиме
     */
    public static function isCli(): bool {
        return php_sapi_name() === 'cli' || defined('STDIN');
    }

    /**
     * Принудительный сброс (для тестирования)
     */
    public static function reset(): void {
        self::$booted          = false;
        self::$context         = null;
        self::$options         = [];
        self::$constantsLoaded = false;
        self::$configLoaded    = false;
        self::$loggerStarted   = false;
        self::$databaseReady   = false;
        self::$coreReady       = false;
        self::$streamingReady  = false;
        self::$adminReady      = false;
        self::$sessionStarted  = false;
        self::$redisReady      = false;

        ServiceContainer::resetInstance();
    }

    // ─────────────────────────────────────────────────────────
    //  Инициализация подсистем (вызываются макс. 1 раз)
    // ─────────────────────────────────────────────────────────

    /**
     * Загрузка констант, путей, $_INFO, Logger, error-функций.
     *
     * Делегирует в www/constants.php — фасад, который подключает:
     *   core/Error/ErrorCodes.php      — $rErrorCodes
     *   core/Error/ErrorHandler.php    — generateError(), generate404()
     *   core/Config/Paths.php          — *_PATH константы
     *   core/Config/AppConfig.php      — версия, Git, флаги
     *   core/Config/Binaries.php       — FFMPEG, FFPROBE, GeoIP
     *   core/Config/ConfigLoader.php   — $_INFO из config.ini
     *   core/Http/RequestGuard.php     — flood/host check + Logger
     *
     * autoload.php уже загружен в bootstrap.php строкой выше,
     * constants.php повторно вызовет require_once — повторно не загрузится.
     */
    private static function loadConstants(): void {
        if (self::$constantsLoaded) {
            return;
        }

        require_once MAIN_HOME . 'www/constants.php';

        self::$constantsLoaded = true;
        self::$configLoaded    = true;  // $_INFO загружен внутри ConfigLoader.php
        self::$loggerStarted   = true;  // Logger::init() вызван внутри RequestGuard.php
    }

    /**
     * Flood-protection: блокировка забаненных IP.
     *
     * Вызывается только для HTTP-контекстов.
     * Логика из constants.php / stream/init.php — проверка файла block_{IP}.
     */
    private static function floodProtection(): void {
        if (self::isCli()) {
            return;
        }

        $rIP = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($rIP) && file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
            http_response_code(403);
            exit();
        }
    }

    /**
     * Проверка хоста: запрос пришёл с разрешённого домена.
     *
     * Логика из constants.php / stream/init.php — verify_host + allowed_domains.
     */
    private static function hostVerification(): void {
        if (self::isCli()) {
            return;
        }

        if (!defined('HOST')) {
            $host = trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
            define('HOST', $host);
        }

        // Проверка домена через кэш настроек
        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            $rData = @file_get_contents(CACHE_TMP_PATH . 'settings');
            if ($rData !== false) {
                $rSettings = @igbinary_unserialize($rData);
                if (is_array($rSettings) && !empty($rSettings['verify_host'])) {
                    if (file_exists(CACHE_TMP_PATH . 'allowed_domains')) {
                        $rDomains = @igbinary_unserialize(@file_get_contents(CACHE_TMP_PATH . 'allowed_domains'));
                        if (is_array($rDomains) && count($rDomains) > 0
                            && !in_array(HOST, $rDomains) && HOST !== 'xc_vm'
                            && !filter_var(HOST, FILTER_VALIDATE_IP)) {
                            generateError('INVALID_HOST');
                        }
                    }
                }
            }
        }
    }

    /**
     * Старт PHP-сессии с безопасными параметрами.
     *
     * Логика из admin.php строки 3-8.
     * Только для HTTP-контекстов (admin/reseller).
     */
    private static function initSession(): void {
        if (self::$sessionStarted || self::isCli()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            $rParams = session_get_cookie_params() ?: [];
            $rParams['samesite'] = 'Strict';
            session_set_cookie_params($rParams);
            session_start();
        }

        self::$sessionStarted = true;
    }

    /**
     * Подключение к MySQL/MariaDB.
     *
     * Создаёт глобальную переменную $db (обратная совместимость).
     *
     * @param bool $cached Если true, CoreUtilities::init(true) будет использовать
     *                     файловый кэш вместо SQL-запросов для настроек.
     */
    private static function initDatabase(bool $cached = false): void {
        if (self::$databaseReady) {
            return;
        }

        global $db, $_INFO;

        require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';

        $db = new DatabaseHandler(
            $_INFO['username'],
            $_INFO['password'],
            $_INFO['database'],
            $_INFO['hostname'],
            $_INFO['port']
        );

        self::$databaseReady = true;
    }

    /**
     * Инициализация CoreUtilities.
     *
     * Очищает глобалы ($_GET, $_POST, $_SESSION, $_COOKIE),
     * парсит конфиг, определяет SERVER_ID, выбирает FFmpeg-бинарники,
     * загружает настройки (из БД или кэша).
     *
     * @param bool $cached Использовать кэш для настроек (для высоко-нагруженных путей)
     */
    private static function initCoreUtilities(bool $cached = false): void {
        if (self::$coreReady) {
            return;
        }

        global $db;

        require_once INCLUDES_PATH . 'CoreUtilities.php';

        CoreUtilities::$db = &$db;
        CoreUtilities::init($cached);

        // Если использовался кэш и кэш неполный — переподключаемся к БД
        if ($cached && !CoreUtilities::$rCached) {
            global $_INFO;
            $db = new DatabaseHandler(
                $_INFO['username'],
                $_INFO['password'],
                $_INFO['database'],
                $_INFO['hostname'],
                $_INFO['port']
            );
            CoreUtilities::$db = &$db;
        }

        self::$coreReady = true;
    }

    /**
     * Инициализация StreamingUtilities (лёгкий путь).
     *
     * Для стриминг-эндпоинтов (live, vod, timeshift и т.д.).
     * НЕ загружает admin_api, Translator, MobileDetect.
     * Использует кэш настроек для минимальной нагрузки на БД.
     */
    private static function initStreamingUtilities(): void {
        if (self::$streamingReady) {
            return;
        }

        global $db;

        require_once INCLUDES_PATH . 'StreamingUtilities.php';

        // Настройки из файлового кэша
        $rSettings = [];
        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            $rSettings = @igbinary_unserialize(@file_get_contents(CACHE_TMP_PATH . 'settings'));
            if (!is_array($rSettings)) {
                $rSettings = ['verify_host' => false, 'debug_show_errors' => false, 'enable_cache' => false];
            }
        }

        StreamingUtilities::$rSettings = $rSettings;
        StreamingUtilities::init(false);
        $db = &StreamingUtilities::$db;

        self::$streamingReady = true;
    }

    /**
     * Подключение к Redis.
     */
    private static function initRedis(): void {
        if (self::$redisReady) {
            return;
        }

        CoreUtilities::connectRedis();
        self::$redisReady = true;
    }

    /**
     * Инициализация Admin API + Reseller API.
     *
     * Загружает admin_api.php и reseller_api.php,
     * инициализирует классы API и ResellerAPI.
     */
    private static function initAdminAPI(): void {
        if (self::$adminReady) {
            return;
        }

        global $db;

        require_once INCLUDES_PATH . 'admin_api.php';
        require_once INCLUDES_PATH . 'reseller_api.php';

        API::$db = &$db;
        API::init();

        ResellerAPI::$db = &$db;
        ResellerAPI::init();

        self::$adminReady = true;
    }

    /**
     * Инициализация Translator (мультиязычность).
     */
    private static function initTranslator(): void {
        require_once INCLUDES_PATH . 'libs/Translator.php';

        $language = Translator::class;
        $language::init(MAIN_HOME . 'includes/langs/');
    }

    /**
     * Регистрация shutdown-функции для admin-контекста.
     *
     * Закрывает MySQL-подключение при завершении скрипта.
     */
    private static function registerAdminShutdown(): void {
        register_shutdown_function(function () {
            global $db;
            if (is_object($db)) {
                $db->close_mysql();
            }
        });
    }

    /**
     * Заполнить контейнер инициализированными сервисами.
     *
     * Вызывается в конце boot() — все подсистемы уже запущены,
     * можно безопасно ссылаться на $db, CoreUtilities и т.д.
     *
     * Контейнер хранит:
     *   'db'           => Database       — PDO-обёртка
     *   'config'       => array          — $_INFO из config.ini
     *   'settings'     => array          — настройки панели
     *   'redis'        => Redis|null     — подключение к Redis
     *   'servers'      => array          — список серверов
     *   'bouquets'     => array          — букеты
     *   'categories'   => array          — категории
     *   'translator'   => string         — класс Translator
     *
     * @param ServiceContainer $container
     */
    private static function populateContainer(ServiceContainer $container): void {
        // База данных
        if (self::$databaseReady) {
            global $db;
            $container->set('db', $db);
        }

        // Настройки и данные CoreUtilities
        if (self::$coreReady) {
            $container->set('settings',   CoreUtilities::$rSettings);
            $container->set('servers',    CoreUtilities::$rServers);
            $container->set('bouquets',   CoreUtilities::$rBouquets);
            $container->set('categories', CoreUtilities::$rCategories);

            if (self::$redisReady && CoreUtilities::$redis !== null) {
                $container->set('redis', CoreUtilities::$redis);
            }
        }

        // Настройки StreamingUtilities (stream-контекст)
        if (self::$streamingReady) {
            $container->set('settings', StreamingUtilities::$rSettings);
        }

        // Translator
        if (class_exists('Translator', false) && Translator::available()) {
            $container->set('translator', Translator::class);
        }
    }

    /**
     * Дефолтные значения опций для каждого контекста.
     *
     * @param string $context
     * @return array
     */
    private static function defaults(string $context): array {
        switch ($context) {
            case self::CONTEXT_ADMIN:
                return [
                    'cached'   => false,
                    'redis'    => true,
                    'process'  => '',
                    'shutdown' => null,
                ];

            case self::CONTEXT_STREAM:
                return [
                    'cached'   => true,
                    'redis'    => false,
                    'process'  => '',
                    'shutdown' => null,
                ];

            case self::CONTEXT_CLI:
                return [
                    'cached'   => false,
                    'redis'    => false,
                    'process'  => '',
                    'shutdown' => null,
                ];

            case self::CONTEXT_MINIMAL:
            default:
                return [
                    'cached'   => false,
                    'redis'    => false,
                    'process'  => '',
                    'shutdown' => null,
                ];
        }
    }

    // ─────────────────────────────────────────────────────────
    //  Вспомогательные статусные константы (из admin.php)
    // ─────────────────────────────────────────────────────────

    /**
     * Определить status-константы (STATUS_FAILURE, STATUS_SUCCESS, ...).
     *
     * Эти константы определены в admin.php (строки 10-55) и используются
     * повсеместно в admin_api.php / reseller_api.php.
     * Вызывается автоматически при CONTEXT_ADMIN.
     * Может быть вызван вручную при необходимости.
     */
    public static function defineStatusConstants(): void {
        // Защита от повторного определения
        if (defined('STATUS_FAILURE')) {
            return;
        }

        define('STATUS_FAILURE', 0);
        define('STATUS_SUCCESS', 1);
        define('STATUS_SUCCESS_MULTI', 2);
        define('STATUS_CODE_LENGTH', 3);
        define('STATUS_NO_SOURCES', 4);
        define('STATUS_DISABLED', 5);
        define('STATUS_NOT_ADMIN', 6);
        define('STATUS_INVALID_EMAIL', 7);
        define('STATUS_INVALID_PASSWORD', 8);
        define('STATUS_INVALID_IP', 9);
        define('STATUS_INVALID_PLAYLIST', 10);
        define('STATUS_INVALID_NAME', 11);
        define('STATUS_INVALID_CAPTCHA', 12);
        define('STATUS_INVALID_CODE', 13);
        define('STATUS_INVALID_DATE', 14);
        define('STATUS_INVALID_FILE', 15);
        define('STATUS_INVALID_GROUP', 16);
        define('STATUS_INVALID_DATA', 17);
        define('STATUS_INVALID_DIR', 18);
        define('STATUS_INVALID_MAC', 19);
        define('STATUS_EXISTS_CODE', 20);
        define('STATUS_EXISTS_NAME', 21);
        define('STATUS_EXISTS_USERNAME', 22);
        define('STATUS_EXISTS_MAC', 23);
        define('STATUS_EXISTS_SOURCE', 24);
        define('STATUS_EXISTS_IP', 25);
        define('STATUS_EXISTS_DIR', 26);
        define('STATUS_SUCCESS_REPLACE', 27);
        define('STATUS_FLUSH', 28);
        define('STATUS_TOO_MANY_RESULTS', 29);
        define('STATUS_SPACE_ISSUE', 30);
        define('STATUS_INVALID_USER', 31);
        define('STATUS_CERTBOT', 32);
        define('STATUS_CERTBOT_INVALID', 33);
        define('STATUS_INVALID_INPUT', 34);
        define('STATUS_NOT_RESELLER', 35);
        define('STATUS_NO_TRIALS', 36);
        define('STATUS_INSUFFICIENT_CREDITS', 37);
        define('STATUS_INVALID_PACKAGE', 38);
        define('STATUS_INVALID_TYPE', 39);
        define('STATUS_INVALID_USERNAME', 40);
        define('STATUS_INVALID_SUBRESELLER', 41);
        define('STATUS_NO_DESCRIPTION', 42);
        define('STATUS_NO_KEY', 43);
        define('STATUS_EXISTS_HMAC', 44);
        define('STATUS_CERTBOT_RUNNING', 45);
        define('STATUS_RESERVED_CODE', 46);
        define('STATUS_NO_TITLE', 47);
        define('STATUS_NO_SOURCE', 48);
    }
}
