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
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Logging\Logger;

// ─────────────────────────────────────────────────────────────────
//  1. Class autoloader
// ─────────────────────────────────────────────────────────────────

// Composer PSR-4 autoloader first (resolves migrated XcVm\* classes), then the
// legacy XC_Autoloader as an end-of-queue fallback for still-global classes.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/autoload.php';
// After this: MAIN_HOME is defined, Composer + XC_Autoloader are registered


// ─────────────────────────────────────────────────────────────────
//  2. Polyfills (required before any HTTP processing)
// ─────────────────────────────────────────────────────────────────

if (!function_exists('getallheaders')) {
    /**
     * Polyfill for getallheaders(): reconstruct request headers from $_SERVER.
     *
     * @return array<string,string> Header name => value.
     */
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

    // ── Contexts (kept for backward compatibility — prefer BootContext enum) ──
    /** @deprecated Use BootContext::Minimal */
    const CONTEXT_MINIMAL  = 'minimal';
    /** @deprecated Use BootContext::Cli */
    const CONTEXT_CLI      = 'cli';
    /** @deprecated Use BootContext::Stream */
    const CONTEXT_STREAM   = 'stream';
    /** @deprecated Use BootContext::Admin */
    const CONTEXT_ADMIN    = 'admin';

    // ── Internal state ───────────────────────────────────────
    private static bool    $booted  = false;
    private static ?string $context = null;
    private static array   $options = [];
    private static bool    $devMode = false;

    // ── Subsystem initialization flags ────────────────────────
    private static bool $constantsLoaded = false;
    private static bool $configLoaded    = false;
    private static bool $loggerStarted   = false;
    private static bool $databaseReady   = false;
    private static bool $coreReady       = false;
    private static bool $adminReady      = false;
    private static bool $sessionStarted  = false;
    private static bool $redisReady      = false;

    /**
     * Main entry point.
     *
     * @param string|BootContext $context  Boot context. Accepts BootContext enum or legacy string constant.
     * @param array              $options  Additional options:
     *   'cached'      => bool   Use settings cache (for stream/cli, default: false)
     *   'redis'       => bool   Connect Redis (default: true for admin, false for others)
     *   'process'     => string Process name for cli_set_process_title()
     *   'shutdown'    => callable Shutdown callback (replaces register_shutdown_function)
     */
    public static function boot(string|BootContext $context = BootContext::Cli, array $options = []): void {
        if (self::$booted) {
            return;
        }

        $ctx = $context instanceof BootContext ? $context : BootContext::from($context);

        self::$context = $ctx->value;
        self::$options = array_merge(self::defaults($ctx), $options);

        // ── Create container ────────────────────────────────────
        $container = ServiceContainer::getInstance();
        $container->set('context', $ctx->value);
        $container->set('options', self::$options);

        // ── Common for all contexts ────────────────────────────

        self::loadConstants();

        $container->set('config', ConfigReader::getAll());

        // ── Flood-protection (HTTP only) ───────────────────────
        if (!self::isCli()) {
            self::floodProtection();
            self::hostVerification();
        }

        // ── Context-dependent initialization ───────────────────

        match ($ctx) {
            BootContext::Minimal => null,
            BootContext::Cli     => self::bootCli(),
            BootContext::Stream  => self::bootStream(),
            BootContext::Admin   => self::bootAdmin(),
        };

        // ── Register services in the container ──────────────────
        self::populateContainer($container);

        // ── Verify that expected services were registered ────────
        if ($ctx !== BootContext::Minimal) {
            self::assertContainerHealth($container);
        }

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
     * Whether dev mode is active (DEV_MODE constant in AppConfig.php is true).
     * When true, PHP errors are displayed on-screen regardless of DB settings.
     */
    public static function isDevMode(): bool {
        return self::$devMode;
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
    //  Context boot sequences
    // ─────────────────────────────────────────────────────────

    /**
     * Boot sequence for the CLI context: database, legacy core, optional redis
     * and process title.
     *
     * @return void
     */
    private static function bootCli(): void {
        self::initDatabase(self::$options['cached']);
        self::initLegacyCore(self::$options['cached']);
        if (self::$options['redis']) {
            self::initRedis();
        }
        if (!empty(self::$options['process'])) {
            cli_set_process_title(self::$options['process']);
        }
    }

    /**
     * Boot sequence for the streaming context: cached database connection only.
     *
     * @return void
     */
    private static function bootStream(): void {
        self::initDatabase(true);
    }

    /**
     * Boot sequence for the admin context: session, database, legacy core, redis,
     * admin API, translator, shutdown handler and status constants.
     *
     * @return void
     */
    private static function bootAdmin(): void {
        self::initSession();
        self::initDatabase(false);
        self::initLegacyCore(false);
        self::initRedis();
        self::initAdminAPI();
        self::initTranslator();
        self::registerAdminShutdown();
        self::defineStatusConstants();
        self::initAdminGlobals();
    }

    // ─────────────────────────────────────────────────────────
    //  Subsystem initialization (each called at most once)
    // ─────────────────────────────────────────────────────────

    /**
     * Load constants, paths, Logger, error functions.
     *
     * Loads core configuration directly (without www/constants.php):
     *   core/Error/ErrorCodes.php  — $rErrorCodes
     *   core/Error/ErrorHandler.php — generateError(), generate404()
     *   core/Config/Paths.php      — *_PATH constants
     *   core/Config/AppConfig.php  — version, Git, flags
     *   core/Config/Binaries.php   — FFMPEG, FFPROBE, GeoIP
     */
    private static function loadConstants(): void {
        if (self::$constantsLoaded) {
            return;
        }

        require_once MAIN_HOME . 'Core/Error/ErrorCodes.php';
        require_once MAIN_HOME . 'Core/Error/ErrorHandler.php';
        require_once MAIN_HOME . 'Core/Config/Paths.php';
        require_once MAIN_HOME . 'Core/Config/AppConfig.php';
        require_once MAIN_HOME . 'Core/Config/Binaries.php';
        require_once MAIN_HOME . 'Core/Logging/Logger.php';

        self::$devMode = DEV_MODE;

        if (!defined('PHP_ERRORS')) {
            define('PHP_ERRORS', self::$devMode);
        }

        Logger::init(
            self::$devMode || PHP_ERRORS,
            LOGS_TMP_PATH . 'error_log.log'
        );

        self::$constantsLoaded = true;
        self::$configLoaded    = true;
        self::$loggerStarted   = true;
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

        global $db;

        require_once MAIN_HOME . 'Core/Database/DatabaseHandler.php';

        $db = new DatabaseHandler();

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

        require_once MAIN_HOME . 'Core/Init/LegacyInitializer.php';

        DatabaseFactory::set($db);
        LegacyInitializer::initCore($cached);

        // If cache was used and is incomplete — reconnect to DB
        if ($cached && !SettingsManager::getAll()['enable_cache']) {
            $db = new DatabaseHandler();
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
     * Initializes ResellerAPI class and admin user info.
     */
    private static function initAdminAPI(): void {
        if (self::$adminReady) {
            return;
        }

        global $db;


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
        require_once MAIN_HOME . 'Core/Localization/Translator.php';

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
     *   'events'       => string         — EventDispatcher class
     *
     * @param ServiceContainer $container
     */
    private static function populateContainer(ServiceContainer $container): void {
        // Database
        if (self::$databaseReady) {
            global $db;
            $container->set('db', $db);
            self::wireDomainDatabase($db);
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

        // Events — create an instance, wire it as the static singleton bridge,
        // and register it in the container so it can be injected via DI.
        $dispatcher = new EventDispatcher();
        EventDispatcher::setInstance($dispatcher);
        $container->set('events', $dispatcher);
    }

    /**
     * Wire the injected $db instance into every domain service class.
     *
     * Domain classes use the static setDb() / db() pattern: setDb() stores
     * the injected instance; db() returns it. Calling this method removes the
     * need for the global $db fallback inside each db() helper.
     *
     * @param \XcVm\Core\Database\DatabaseHandler $db DatabaseHandler instance
     */
    private static function wireDomainDatabase(object $db): void {
        // Bouquet
        BouquetService::setDb($db);

        // Device
        EnigmaService::setDb($db);
        MagService::setDb($db);

        // Epg
        EPG::setDb($db);
        EpgService::setDb($db);

        // Line
        LineRepository::setDb($db);
        LineService::setDb($db);
        PackageService::setDb($db);

        // Security
        BlocklistService::setDb($db);

        // Server
        ServerRepository::setDb($db);
        ServerService::setDb($db);
        SettingsService::setDb($db);

        // Stream
        CategoryService::setDb($db);
        ChannelService::setDb($db);
        ConnectionTracker::setDb($db);
        PlaylistGenerator::setDb($db);
        ProfileService::setDb($db);
        ProviderService::setDb($db);
        RadioService::setDb($db);
        StreamConfigRepository::setDb($db);
        StreamProcess::setDb($db);
        StreamRepository::setDb($db);
        StreamService::setDb($db);

        // User
        GroupService::setDb($db);
        ResellerAPI::setDb($db);
        TicketRepository::setDb($db);
        UserRepository::setDb($db);
        UserService::setDb($db);

        // Vod
        EpisodeService::setDb($db);
        MovieService::setDb($db);
        SeriesService::setDb($db);
        TMDbService::setDb($db);
    }

    /**
     * Verify that services which should have been registered actually are.
     *
     * Called after populateContainer() for every context except CONTEXT_MINIMAL.
     * Throws RuntimeException on the first missing service so the application
     * fails loudly instead of silently degrading.
     *
     * @param ServiceContainer $container
     * @throws RuntimeException
     */
    private static function assertContainerHealth(ServiceContainer $container): void {
        $required = ['events'];

        if (self::$databaseReady) {
            $required[] = 'db';
        }

        if (self::$redisReady) {
            $required[] = 'redis';
        }

        $missing = [];
        foreach ($required as $service) {
            if (!$container->has($service)) {
                $missing[] = $service;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'ServiceContainer health check failed — missing required services: '
                . implode(', ', $missing)
            );
        }
    }

    /**
     * Default boot options for a context.
     *
     * @param BootContext $ctx Boot context.
     * @return array Options: cached, redis, process, shutdown.
     */
    private static function defaults(BootContext $ctx): array {
        return match ($ctx) {
            BootContext::Admin   => ['cached' => false, 'redis' => true,  'process' => '', 'shutdown' => null],
            BootContext::Stream  => ['cached' => true,  'redis' => false, 'process' => '', 'shutdown' => null],
            BootContext::Cli     => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null],
            BootContext::Minimal => ['cached' => false, 'redis' => false, 'process' => '', 'shutdown' => null],
        };
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

        require_once MAIN_HOME . 'Core/Util/MobileDetect.php';
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
        if (self::$devMode) {
            $rSettings['debug_show_errors'] = true;
        }
        $rProxyServers = ServerRepository::getProxySimple($rPermissions);

        $language     = Translator::class;
        $allowedLangs = $language::available();

        // Sort servers by order
        if (is_array($rServers)) {
            uasort(
                $rServers,
                function ($a, $b) {
                    return $a['order'] - $b['order'];
                }
            );
        }

        // Ensure legacy 'reseller' assets alias exists as a symlink to 'admin'
        // Some nginx configs and legacy routes expect public/assets/reseller to
        // point to public/assets/admin. Create the symlink if the target exists
        // and the link does not.
        $assetsBase = MAIN_HOME . 'Public/assets/';
        $adminAssets = $assetsBase . 'admin';
        $resellerLink = $assetsBase . 'reseller';
        if (is_dir($adminAssets) && !file_exists($resellerLink)) {
            @symlink($adminAssets, $resellerLink);
        }

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
