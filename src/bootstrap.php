<?php

/**
 * Unified initialization entry point (bootstrap)
 *
 * Provides context-dependent initialization for the entire application.
 * Handles: constant loading, DB connection, flood-protection, Logger,
 * error functions, session, Redis, Translator, and admin globals.
 *
 * ──────────────────────────────────────────────────────────────────
 * Initialization contexts:
 * ──────────────────────────────────────────────────────────────────
 *
 *   CONTEXT_MINIMAL  — autoload + constants + config + Logger only.
 *                      No DB connection. For scripts that only need
 *                      paths and configuration.
 *
 *   CONTEXT_CLI      — + Database + LegacyInitializer.
 *                      For cron jobs and CLI scripts.
 *
 *   CONTEXT_STREAM   — + Database + LegacyInitializer (lightweight path).
 *                      For streaming endpoints (live, vod, timeshift).
 *                      Does not load admin_api, Translator, etc.
 *
 *   CONTEXT_ADMIN    — + Database + LegacyInitializer + API + ResellerAPI
 *                      + Translator + MobileDetect + session.
 *                      Full initialization for admin/reseller panel.
 *
 * ──────────────────────────────────────────────────────────────────
 * Usage:
 * ──────────────────────────────────────────────────────────────────
 *
 *   // In an admin controller:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
 *
 *   // In a cron job:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
 *
 *   // In a streaming endpoint:
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM, ['cached' => true]);
 *
 *   // Constants only (no DB):
 *   require_once '/home/xc_vm/bootstrap.php';
 *   XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_MINIMAL);
 *
 * @package XC_VM
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=0);

// ─────────────────────────────────────────────────────────────────
//  1. Class autoloader
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/autoload.php';
// After this: MAIN_HOME is defined, XC_Autoloader is initialized


// ─────────────────────────────────────────────────────────────────
//  2. Polyfills (required before any HTTP processing)
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
//  3. XC_Bootstrap class
// ─────────────────────────────────────────────────────────────────

class XC_Bootstrap {

    // ── Contexts ──────────────────────────────────────────────
    const CONTEXT_MINIMAL  = 'minimal';
    const CONTEXT_CLI      = 'cli';
    const CONTEXT_STREAM   = 'stream';
    const CONTEXT_ADMIN    = 'admin';

    // ── Internal state ───────────────────────────────────────
    private static $booted = false;
    private static $context = null;
    private static $options = [];

    // ── Subsystem initialization flags ────────────────────────
    private static $constantsLoaded  = false;
    private static $configLoaded     = false;
    private static $loggerStarted    = false;
    private static $databaseReady    = false;
    private static $coreReady        = false;
    private static $adminReady       = false;
    private static $sessionStarted   = false;
    private static $redisReady       = false;

    /**
     * Main entry point.
     *
     * @param string $context  Context: CONTEXT_MINIMAL | CONTEXT_CLI | CONTEXT_STREAM | CONTEXT_ADMIN
     * @param array  $options  Additional options:
     *   'cached'      => bool   Use settings cache (for stream/cli, default: false)
     *   'redis'       => bool   Connect Redis (default: true for admin, false for others)
     *   'process'     => string Process name for cli_set_process_title()
     *   'shutdown'    => callable Shutdown callback (replaces register_shutdown_function)
     */
    public static function boot(string $context = self::CONTEXT_CLI, array $options = []): void {
        if (self::$booted) {
            return;
        }

        self::$context = $context;
        self::$options = array_merge(self::defaults($context), $options);

        // ── Create container ────────────────────────────────────
        $container = ServiceContainer::getInstance();
        $container->set('context', $context);
        $container->set('options', self::$options);

        // ── Common for all contexts ────────────────────────────

        // Constants and paths (MAIN_HOME, INCLUDES_PATH, CONFIG_PATH, ...) + $_INFO + Logger + error functions
        self::loadConstants();

        // Register config in the container
        global $_INFO;
        $container->set('config', $_INFO);

        // ── Flood-protection (HTTP only) ───────────────────────
        if (!self::isCli()) {
            self::floodProtection();
            self::hostVerification();
        }

        // ── Context-dependent initialization ───────────────────

        switch ($context) {
            case self::CONTEXT_MINIMAL:
                // Constants + config only. Done.
                break;

            case self::CONTEXT_CLI:
                self::initDatabase(self::$options['cached']);
                self::initLegacyCore(self::$options['cached']);
                if (self::$options['redis']) {
                    self::initRedis();
                }
                if (!empty(self::$options['process'])) {
                    cli_set_process_title(self::$options['process']);
                }
                break;

            case self::CONTEXT_STREAM:
                self::initDatabase(true);
                break;

            case self::CONTEXT_ADMIN:
                self::initSession();
                self::initDatabase(false);
                self::initLegacyCore(false);
                self::initRedis();
                self::initAdminAPI();
                self::initTranslator();
                self::registerAdminShutdown();
                self::defineStatusConstants();
                self::initAdminGlobals();
                break;
        }

        // ── Register services in the container ──────────────────
        self::populateContainer($container);

        self::$booted = true;
    }

    // ─────────────────────────────────────────────────────────
    //  Public getters
    // ─────────────────────────────────────────────────────────

    /**
     * Current boot context.
     */
    public static function getContext(): ?string {
        return self::$context;
    }

    /**
     * Whether bootstrap has been executed.
     */
    public static function isBooted(): bool {
        return self::$booted;
    }

    /**
     * Database reference (backward compatibility).
     */
    public static function getDatabase(): ?Database {
        global $db;
        return $db;
    }

    /**
     * Get the ServiceContainer.
     *
     * @return ServiceContainer
     */
    public static function getContainer(): ServiceContainer {
        return ServiceContainer::getInstance();
    }

    /**
     * Check whether running in CLI mode.
     */
    public static function isCli(): bool {
        return php_sapi_name() === 'cli' || defined('STDIN');
    }

    /**
     * Force reset (for testing).
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
        self::$adminReady      = false;
        self::$sessionStarted  = false;
        self::$redisReady      = false;

        ServiceContainer::resetInstance();
    }

    // ─────────────────────────────────────────────────────────
    //  Subsystem initialization (each called at most once)
    // ─────────────────────────────────────────────────────────

    /**
     * Load constants, paths, $_INFO, Logger, error functions.
     *
     * Delegates to www/constants.php which includes:
     *   core/Error/ErrorCodes.php      — $rErrorCodes
     *   core/Error/ErrorHandler.php    — generateError(), generate404()
     *   core/Config/Paths.php          — *_PATH constants
     *   core/Config/AppConfig.php      — version, Git, flags
     *   core/Config/Binaries.php       — FFMPEG, FFPROBE, GeoIP
     *   core/Config/ConfigLoader.php   — $_INFO from config.ini
     *   core/Http/RequestGuard.php     — flood/host check + Logger
     */
    private static function loadConstants(): void {
        if (self::$constantsLoaded) {
            return;
        }

        require_once MAIN_HOME . 'www/constants.php';

        self::$constantsLoaded = true;
        self::$configLoaded    = true;  // $_INFO loaded inside ConfigLoader.php
        self::$loggerStarted   = true;  // Logger::init() called inside RequestGuard.php
    }

    /**
     * Flood-protection: block banned IPs.
     *
     * Called for HTTP contexts only.
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
     * Host verification: ensure request comes from an allowed domain.
     */
    private static function hostVerification(): void {
        if (self::isCli()) {
            return;
        }

        if (!defined('HOST')) {
            $host = trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
            define('HOST', $host);
        }

        // Domain check via settings cache
        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            $rData = @file_get_contents(CACHE_TMP_PATH . 'settings');
            if ($rData !== false) {
                $rSettings = @igbinary_unserialize($rData);
                if (is_array($rSettings) && !empty($rSettings['verify_host'])) {
                    if (file_exists(CACHE_TMP_PATH . 'allowed_domains')) {
                        $rDomains = @igbinary_unserialize(@file_get_contents(CACHE_TMP_PATH . 'allowed_domains'));
                        if (
                            is_array($rDomains) && count($rDomains) > 0
                            && !in_array(HOST, $rDomains) && HOST !== 'xc_vm'
                            && !filter_var(HOST, FILTER_VALIDATE_IP)
                        ) {
                            generateError('INVALID_HOST');
                        }
                    }
                }
            }
        }
    }

    /**
     * Start PHP session with secure parameters.
     *
     * HTTP contexts only (admin/reseller).
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
     * Connect to MySQL/MariaDB.
     *
     * Creates the global $db variable (backward compatibility).
     *
     * @param bool $cached If true, LegacyInitializer will use
     *                     file cache instead of SQL queries for settings.
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
     * Initialize legacy core subsystems.
     *
     * Sanitizes globals ($_GET, $_POST, $_SESSION, $_COOKIE),
     * parses config, defines SERVER_ID, selects FFmpeg binaries,
     * loads settings (from DB or cache).
     *
     * @param bool $cached Use cache for settings (for high-load paths)
     */
    private static function initLegacyCore(bool $cached = false): void {
        if (self::$coreReady) {
            return;
        }

        global $db;

        require_once MAIN_HOME . 'core/Init/LegacyInitializer.php';

        DatabaseFactory::set($db);
        LegacyInitializer::initCore($cached);

        // If cache was used and is incomplete — reconnect to DB
        if ($cached && !SettingsManager::getAll()['enable_cache']) {
            global $_INFO;
            $db = new DatabaseHandler(
                $_INFO['username'],
                $_INFO['password'],
                $_INFO['database'],
                $_INFO['hostname'],
                $_INFO['port']
            );
            DatabaseFactory::set($db);
        }

        self::$coreReady = true;
    }

    /**
     * Connect to Redis.
     */
    private static function initRedis(): void {
        if (self::$redisReady) {
            return;
        }

        RedisManager::ensureConnected();
        self::$redisReady = true;
    }

    /**
     * Initialize Admin API + Reseller API.
     *
     * Loads reseller_api.php,
     * initializes ResellerAPI class and admin user info.
     */
    private static function initAdminAPI(): void {
        if (self::$adminReady) {
            return;
        }

        global $db;

        require_once INCLUDES_PATH . 'reseller_api.php';

        // Admin user info
        if (isset($_SESSION['hash'])) {
            $GLOBALS['rAdminUserInfo'] = UserRepository::getRegisteredUserById($_SESSION['hash']);
        }

        ResellerAPI::init();

        self::$adminReady = true;
    }

    /**
     * Initialize Translator (i18n).
     */
    private static function initTranslator(): void {
        require_once INCLUDES_PATH . 'libs/Translator.php';

        $language = Translator::class;
        $language::init(MAIN_HOME . 'resources/langs/');
    }

    /**
     * Register shutdown function for admin context.
     *
     * Closes the MySQL connection on script termination.
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
     * Populate the container with initialized services.
     *
     * Called at the end of boot() — all subsystems are already running,
     * so it is safe to reference $db, SettingsManager, etc.
     *
     * Container stores:
     *   'db'           => Database       — PDO wrapper
     *   'config'       => array          — $_INFO from config.ini
     *   'settings'     => array          — panel settings
     *   'redis'        => Redis|null     — Redis connection
     *   'servers'      => array          — server list
     *   'bouquets'     => array          — bouquets
     *   'categories'   => array          — categories
     *   'translator'   => string         — Translator class
     *
     * @param ServiceContainer $container
     */
    private static function populateContainer(ServiceContainer $container): void {
        // Database
        if (self::$databaseReady) {
            global $db;
            $container->set('db', $db);
        }

        // Settings and core data
        if (self::$coreReady) {
            $container->set('settings',   SettingsManager::getAll());
            $container->set('servers',    ServerRepository::getAll());
            $container->set('bouquets',   BouquetService::getAll());
            $container->set('categories', CategoryService::getFromDatabase());

            if (self::$redisReady && RedisManager::isConnected()) {
                $container->set('redis', RedisManager::instance());
            }
        }

        // Translator
        if (class_exists('Translator', false) && Translator::available()) {
            $container->set('translator', Translator::class);
        }
    }

    /**
     * Default option values for each context.
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

    /**
     * Initialize admin globals: MobileDetect, timeouts, servers,
     * protocol, admin_constants.
     */
    private static function initAdminGlobals(): void {
        global $rDetect, $rMobile, $rTimeout, $rSQLTimeout, $rProtocol,
               $allServers, $rServers, $rSettings, $rProxyServers,
               $rPermissions, $language, $allowedLangs;

        if (!defined('SERVER_ID')) {
            define('SERVER_ID', intval(ConfigReader::get('server_id')));
        }

        require_once INCLUDES_PATH . 'libs/mobiledetect.php';
        $rDetect = new \Mobile_Detect();
        $rMobile = $rDetect->isMobile();

        $rTimeout    = 15;
        $rSQLTimeout = 10;
        set_time_limit($rTimeout);
        ini_set('mysql.connect_timeout', (string) $rSQLTimeout);
        ini_set('max_execution_time', (string) $rTimeout);
        ini_set('default_socket_timeout', (string) $rTimeout);

        $rProtocol    = self::detectProtocol();
        $allServers   = ServerRepository::getAllSimple();
        $rServers     = ServerRepository::getStreamingSimple($rPermissions);
        $rSettings    = SettingsManager::getAll();
        $rProxyServers = ServerRepository::getProxySimple($rPermissions);

        $language     = Translator::class;
        $allowedLangs = $language::available();

        require_once MAIN_HOME . 'resources/data/admin_constants.php';
    }

    /**
     * Detect HTTP protocol (http/https).
     */
    private static function detectProtocol(): string {
        $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;
        return ($https || $port443) ? 'https' : 'http';
    }

    // ─────────────────────────────────────────────────────────
    //  Status constants (from admin.php)
    // ─────────────────────────────────────────────────────────

    /**
     * Define status constants (STATUS_FAILURE, STATUS_SUCCESS, ...).
     *
     * Used throughout admin and reseller API handlers.
     * Called automatically in CONTEXT_ADMIN.
     * Can be called manually when needed.
     */
    public static function defineStatusConstants(): void {
        // Guard against duplicate definition
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
