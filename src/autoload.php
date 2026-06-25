<?php

/**
 * Lightweight class autoloader for legacy PHP projects without namespaces.
 *
 * The loader builds a runtime map of `ClassName → filePath` by scanning
 * registered directories and extracting class definitions using the PHP
 * tokenizer (`token_get_all`).
 *
 * Resolution order:
 *
 * 1. Explicit class map registered via addClass()
 * 2. In-memory resolved map (populated lazily / via warmCache())
 * 3. Runtime directory scan fallback
 *
 * During the PSR-4 migration this loader runs purely in memory and is
 * registered at the END of the SPL autoload stack as a fallback for
 * still-global (non-namespaced) classes. The Composer PSR-4 autoloader
 * (registered first) resolves migrated `XcVm\*` classes. There is no
 * persistent (igbinary) cache: PSR-4 encodes the path, so no class map is
 * persisted to disk.
 *
 * This loader is designed for legacy classes without namespaces.
 *
 * @package XC_VM
 *
 * @package XC_VM
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class XC_Autoloader {

    /** @var string Project root directory (e.g. /home/xc_vm/) */
    private static $basePath = '';

    /** @var array<string, string> Explicit map: class name → absolute file path */
    private static $classMap = [];

    /** @var string[] Directories registered for recursive class scanning */
    private static $directories = [];

    /** @var array<string, string> Runtime (in-memory) map of resolved classes */
    private static $resolved = [];

    /** @var array<string, true> Negative cache: class names confirmed missing after full scan */
    private static $notFound = [];

    // ─────────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────────

    /**
     * Initialize the autoloader.
     *
     * Registers default project directories and appends the autoload callback
     * to the END of the SPL autoload stack (prepend = false) so the Composer
     * PSR-4 loader, registered first, wins for migrated `XcVm\*` classes and
     * only still-global classes fall through to this scanner.
     *
     * This method must be called once during application bootstrap.
     *
     * @param string $basePath Absolute project root path
     *
     * @return void
     */
    public static function init($basePath) {
        // Retired no-op stub. The PSR-4 migration is complete: every class is
        // resolved by the Composer autoloader (src/vendor/) or an explicit
        // require (vendored libs, procedural entry points). The legacy directory
        // scanner is no longer registered. The class is kept for one release as a
        // no-op so StartupCommand::clearCache()/warmCache() keep working; it will
        // be removed entirely in a follow-up.
        self::$basePath = rtrim($basePath, '/') . '/';
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

        // 2. Check in-memory resolved map (previous runtime lookup / warmCache)
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
     * Perform a full in-memory class-map build.
     *
     * All registered directories are recursively scanned and PHP files
     * are parsed using the tokenizer to extract class, interface, trait,
     * and enum declarations. The resulting map lives only for the current
     * request (no persistent cache). The negative cache is cleared so that
     * previously missing classes are re-evaluated.
     *
     * Triggered automatically on a class miss before the definitive reject.
     *
     * @return void
     */
    public static function warmCache() {
        self::$resolved = [];
        self::$notFound = [];
        foreach (self::$directories as $dir) {
            self::scanDirectoryForClasses($dir);
        }
    }

    /**
     * Clear the in-memory runtime and negative caches.
     *
     * Forces the autoloader to rebuild the class map on the next miss.
     *
     * @return void
     */
    public static function clearCache() {
        self::$resolved = [];
        self::$notFound = [];
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

        // New architecture directories
        self::addDirectory($base . 'Core');
        self::addDirectory($base . 'Domain');
        self::addDirectory($base . 'Infrastructure');
        self::addDirectory($base . 'Streaming');
        self::addDirectory($base . 'Modules');
        self::addDirectory($base . 'Public');
    }
}

// ─────────────────────────────────────────────────────────────────
//  Bootstrap: auto-initialize when this file is included
// ─────────────────────────────────────────────────────────────────

if (!defined('MAIN_HOME')) {
    // autoload.php lives in src/, so __DIR__ == src/
    define('MAIN_HOME', __DIR__ . '/');
}

// In-memory fallback scanner, appended after the Composer PSR-4 loader.
// No persistent (igbinary) cache — resolution is per-request only.
XC_Autoloader::init(MAIN_HOME);
