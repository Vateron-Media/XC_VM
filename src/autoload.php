<?php

/**
 * Lightweight class autoloader for legacy PHP projects without namespaces.
 *
 * The loader builds a runtime map of `ClassName → filePath` by scanning
 * registered directories and extracting class definitions using the PHP
 * tokenizer (`token_get_all`).
 *
 * On first run the class map is generated and stored in a binary cache
 * using igbinary serialization. On subsequent runs the cache is loaded
 * directly into memory providing O(1) class resolution.
 *
 * Resolution order:
 *
 * 1. Explicit class map registered via addClass()
 * 2. Cached class map loaded from disk
 * 3. Runtime directory scan fallback
 *
 * Cache is automatically persisted during shutdown if new classes were
 * discovered during execution.
 *
 * This loader is designed for projects without Composer or namespaces.
 *
 * @package XC_VM
 */

class XC_Autoloader {

    /** @var string Project root directory (e.g. /home/xc_vm/) */
    private static $basePath = '';

    /** @var array<string, string> Explicit map: class name → absolute file path */
    private static $classMap = [];

    /** @var string[] Directories registered for recursive class scanning */
    private static $directories = [];

    /** @var array<string, string> Runtime cache of resolved classes (populated from file cache or fallback) */
    private static $resolved = [];

    /** @var string|null Path to the on-disk cache file (null = cache disabled) */
    private static $cacheFile = null;

    /** @var bool Whether new entries were added since the cache was loaded */
    private static $cacheDirty = false;

    /** @var array<string, true> Negative cache: class names confirmed missing after full scan */
    private static $notFound = [];

    // ─────────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────────

    /**
     * Initialize the autoloader.
     *
     * Registers default project directories, attaches the autoload callback
     * to the SPL autoload stack, and schedules cache persistence on shutdown.
     *
     * This method must be called once during application bootstrap.
     *
     * @param string $basePath Absolute project root path
     *
     * @return void
     */
    public static function init($basePath) {
        self::$basePath = rtrim($basePath, '/') . '/';

        self::registerDirectories();

        spl_autoload_register([__CLASS__, 'load'], true, true);

        // Persist file cache on shutdown if it was modified
        register_shutdown_function([__CLASS__, 'saveCache']);
    }

    /**
     * SPL autoload callback responsible for resolving and loading a class.
     *
     * Resolution strategy:
     *
     * 1. Explicit class map registered via addClass()
     * 2. Cached class map (loaded from disk or previously resolved)
     * 3. Negative cache — instant reject for previously missing classes
     * 4. Flat directory lookup (Dir/ClassName.php)
     * 5. Full cache rebuild via warmCache() + retry
     * 6. Negative cache registration on definitive miss
     *
     * Recursive filesystem traversal is never performed per-request.
     * If a class is not found in the prebuilt cache, a single full
     * rebuild is triggered instead.
     *
     * @param string $className Class name requested by the runtime
     *
     * @return bool
     *      TRUE  if the class file was found and loaded
     *      FALSE if the class could not be resolved
     */
    public static function load($className) {
        // 1. Check explicit class map
        if (isset(self::$classMap[$className])) {
            $file = self::$classMap[$className];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 2. Check resolved cache (file cache or previous runtime lookup)
        if (isset(self::$resolved[$className])) {
            $file = self::$resolved[$className];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 3. Negative cache — class was previously confirmed missing
        if (isset(self::$notFound[$className])) {
            return false;
        }

        // 4. Fast path: direct file in a registered directory
        $file = self::findInDirectories($className);
        if ($file !== null) {
            self::$resolved[$className] = $file;
            self::$cacheDirty = true;
            require_once $file;
            return true;
        }

        // 5. Rebuild full cache and retry
        self::warmCache();
        if (isset(self::$resolved[$className])) {
            $file = self::$resolved[$className];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 6. Confirmed missing — remember for future calls
        self::$notFound[$className] = true;
        return false;
    }

    /**
     * Manually register a class-to-file mapping.
     *
     * Entries added via this method have the highest priority and override
     * any automatically discovered mappings.
     *
     * Useful for:
     *  - legacy classes with non-standard file names
     *  - files containing multiple class definitions
     *  - temporary overrides
     *
     * @param string $className Class name
     * @param string $filePath  Absolute path to the class file
     *
     * @return void
     */
    public static function addClass($className, $filePath) {
        self::$classMap[$className] = $filePath;
    }

    /**
     * Register a directory for class discovery.
     *
     * The directory will be scanned recursively when building the class map
     * during cache warm-up.
     *
     * Duplicate directories are ignored.
     *
     * @param string $directory Absolute directory path
     *
     * @return void
     */
    public static function addDirectory($directory) {
        $dir = rtrim($directory, '/');
        if (is_dir($dir) && !in_array($dir, self::$directories, true)) {
            self::$directories[] = $dir;
        }
    }

    /**
     * Enable persistent class map caching.
     *
     * If the cache file exists it will be loaded into memory. If the file
     * is missing or corrupted a full cache warm-up scan will be triggered.
     *
     * Cache is stored using igbinary serialization for improved
     * performance and reduced memory footprint.
     *
     * @param string|null $path Absolute path to cache file.
     *                          NULL disables persistent caching.
     *
     * @return void
     */
    public static function enableFileCache($path) {
        self::$cacheFile = $path;
        if ($path !== null && file_exists($path)) {
            $data = @file_get_contents($path);
            if ($data !== false) {
                $cached = @igbinary_unserialize($data);
                if (is_array($cached)) {
                    self::$resolved = $cached;
                    return;
                }
            }
        }

        // Cache missing or corrupted — warm on first run
        if ($path !== null) {
            self::warmCache();
        }
    }

    /**
     * Persist the resolved class map to disk.
     *
     * This method is normally executed automatically via the registered
     * shutdown handler. The cache will only be written if new class
     * entries were discovered during execution.
     *
     * @return void
     */
    public static function saveCache() {
        if (self::$cacheFile !== null && self::$cacheDirty) {
            $dir = dirname(self::$cacheFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents(
                self::$cacheFile,
                igbinary_serialize(self::$resolved),
                LOCK_EX
            );
            self::$cacheDirty = false;
        }
    }

    /**
     * Perform full cache warm-up.
     *
     * All registered directories are recursively scanned and PHP files
     * are parsed using the tokenizer to extract class, interface, trait,
     * and enum declarations.
     *
     * The resulting class map is stored in memory and immediately written
     * to the persistent cache. The negative cache is cleared so that
     * previously missing classes are re-evaluated.
     *
     * This method is typically executed automatically when the cache
     * file is missing or a class is not found in the existing cache.
     *
     * @return void
     */
    public static function warmCache() {
        self::$notFound = [];
        foreach (self::$directories as $dir) {
            self::scanDirectoryForClasses($dir);
        }
        self::$cacheDirty = true;
        self::saveCache();
    }

    /**
     * Clear all caches: runtime, persistent, and negative.
     *
     * This forces the autoloader to rebuild the class map on the next run.
     *
     * @return void
     */
    public static function clearCache() {
        self::$resolved = [];
        self::$notFound = [];
        self::$cacheDirty = false;
        if (self::$cacheFile !== null && file_exists(self::$cacheFile)) {
            @unlink(self::$cacheFile);
        }
    }

    /**
     * Retrieve the manually registered class map.
     *
     * Intended for debugging and diagnostics.
     *
     * @return array<string, string>
     *      Array of ClassName => filePath mappings
     */
    public static function getClassMap() {
        return self::$classMap;
    }

    /**
     * Retrieve the list of directories registered for class discovery.
     *
     * Intended for debugging and diagnostics.
     *
     * @return string[]
     *      List of absolute directory paths
     */
    public static function getDirectories() {
        return self::$directories;
    }

    // ─────────────────────────────────────────────────────────────
    //  Private methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Attempt a flat (non-recursive) class file lookup.
     *
     * Checks each registered directory for Dir/ClassName.php.
     * No recursive traversal is performed — if the file is not at the
     * top level of a registered directory, the caller should trigger
     * a full cache rebuild via warmCache().
     *
     * @param string $className Class name
     *
     * @return string|null
     *      Absolute path to the file or NULL if not found
     */
    private static function findInDirectories($className) {
        $fileName = $className . '.php';

        foreach (self::$directories as $dir) {
            $path = $dir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Recursively scan a directory for PHP files and extract class
     * declarations using the tokenizer.
     *
     * All discovered classes are registered in the runtime class map.
     *
     * @param string $dir Directory to scan
     *
     * @return void
     */
    private static function scanDirectoryForClasses($dir) {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $dir . '/' . $item;
            if (is_dir($fullPath)) {
                self::scanDirectoryForClasses($fullPath);
            } elseif (str_ends_with($item, '.php')) {
                self::extractAndRegister($fullPath);
            }
        }
    }

    /**
     * Parse a PHP file and extract declared class-like structures.
     *
     * The tokenizer is used to identify the following constructs:
     *
     *  - classes
     *  - interfaces
     *  - traits
     *  - enums (PHP 8.1+)
     *
     * If a declaration is found and no existing mapping exists, the
     * class name is registered in the runtime class map.
     *
     * @param string $filePath Absolute path to PHP file
     *
     * @return void
     */
    private static function extractAndRegister($filePath) {
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return;
        }

        $tokens = @token_get_all($code);
        if (!is_array($tokens)) {
            return;
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            $type = $tokens[$i][0];
            if (
                $type !== T_CLASS && $type !== T_INTERFACE && $type !== T_TRAIT
                && !(defined('T_ENUM') && $type === T_ENUM)
            ) {
                continue;
            }

            // Skip ::class expression
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                continue;
            }

            // Next non-whitespace token = class name
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];
                    // First-found wins; never overwrite classMap or earlier entries
                    if (!isset(self::$classMap[$className]) && !isset(self::$resolved[$className])) {
                        self::$resolved[$className] = $filePath;
                    }
                }
                break;
            }
        }
    }

    /**
     * Register default project directories containing PHP classes.
     *
     * All directories will be scanned recursively during cache warm-up.
     * Adding a new top-level source directory here is sufficient to make
     * its classes discoverable by the autoloader.
     *
     * @return void
     */
    private static function registerDirectories() {
        $base = self::$basePath;

        // Legacy directories
        self::addDirectory($base . 'includes');
        self::addDirectory($base . 'includes/libs');

        // New architecture directories
        self::addDirectory($base . 'core');
        self::addDirectory($base . 'domain');
        self::addDirectory($base . 'infrastructure');
        self::addDirectory($base . 'streaming');
        self::addDirectory($base . 'modules');
        self::addDirectory($base . 'public');
    }
}

// ─────────────────────────────────────────────────────────────────
//  Bootstrap: auto-initialize when this file is included
// ─────────────────────────────────────────────────────────────────

if (!defined('MAIN_HOME')) {
    // autoload.php lives in src/, so __DIR__ == src/
    define('MAIN_HOME', __DIR__ . '/');
}

XC_Autoloader::init(MAIN_HOME);

// File cache: warm on first run, O(1) on subsequent requests
XC_Autoloader::enableFileCache(MAIN_HOME . 'tmp/cache/autoload_map');
