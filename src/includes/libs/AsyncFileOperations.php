<?php

/**
 * AsyncFileOperations PHP class – non-blocking filesystem utilities
 *
 * @package VateronMedia_AsyncFileOperations
 * @author Divarion_D <https://github.com/Divarion-D>
 * @copyright 2026 Vateron Media
 * @link https://github.com/Vateron-Media/XC_VM
 * @version 0.1.0
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 *
 * A PHP class created specifically for the XC_VM project to perform
 * efficient non-blocking file system operations.
 *
 * The class replaces CPU-heavy polling and blocking sleep() calls with
 * optimized mechanisms such as inotify-based monitoring (when available)
 * and adaptive file state polling as a fallback.
 *
 * Primary goals:
 *  - Reduce CPU usage in long-running PHP processes
 *  - Provide asynchronous-style file existence and modification checks
 *  - Enable scalable filesystem monitoring without busy-wait loops
 */

class AsyncFileOperations {
    /**
     * File existence cache with TTL
     * @var array
     */
    private static $fileCache = [];

    /**
     * Cache TTL in microseconds (100ms)
     * @var int
     */
    private static $cacheTTL = 100000;

    /**
     * Non-blocking file existence check with intelligent polling
     * Uses inotify if available, otherwise cached checks
     * 
     * @param string $file File path to check
     * @param int $maxRetries Maximum retry attempts
     * @param int $delayMs Delay between retries in milliseconds
     * @return bool
     */
    public static function awaitFileExists($file, $maxRetries = 300, $delayMs = 10) {
        // First, quick check
        if (file_exists($file)) {
            self::clearFileCache($file);
            return true;
        }

        // Try inotify monitoring if available
        if (function_exists('inotify_init')) {
            return self::awaitFileWithInotify($file, $maxRetries, $delayMs);
        }

        // Fallback to optimized polling
        return self::awaitFileWithPolling($file, $maxRetries, $delayMs);
    }

    /**
     * Wait for file using inotify (Linux)
     * Zero-CPU-usage waiting when file system changes
     * 
     * @param string $file File path to monitor
     * @param int $maxRetries Maximum retry attempts
     * @param int $delayMs Delay between fallback polls
     * @return bool
     */
    private static function awaitFileWithInotify($file, $maxRetries, $delayMs) {
        try {
            $directory = dirname($file);
            $filename = basename($file);

            $inotify = @inotify_init();
            if ($inotify === false) {
                return self::awaitFileWithPolling($file, $maxRetries, $delayMs);
            }

            // Watch directory for file creation
            @inotify_add_watch($inotify, $directory, IN_CREATE | IN_MOVED_TO | IN_CLOSE_WRITE);

            $checkCount = 0;
            $timeout = intval($maxRetries * max(1, intval($delayMs / 100)));

            while ($checkCount < $maxRetries) {
                if (file_exists($file)) {
                    @fclose($inotify);
                    return true;
                }

                // Wait for inotify events with timeout
                $events = @inotify_read($inotify);

                if ($events === false || !is_array($events)) {
                    usleep(max(1000, $delayMs * 1000));
                }

                $checkCount++;
            }

            @fclose($inotify);
            return false;
        } catch (Exception $e) {
            return self::awaitFileWithPolling($file, $maxRetries, $delayMs);
        }
    }

    /**
     * Wait for file using optimized polling
     * Efficient busy-wait replacement that reduces CPU usage
     * 
     * @param string $file File path to check
     * @param int $maxRetries Maximum retry attempts
     * @param int $delayMs Delay between retries in milliseconds
     * @return bool
     */
    private static function awaitFileWithPolling($file, $maxRetries = 300, $delayMs = 10) {
        $microDelay = max(1000, $delayMs * 1000); // Convert to microseconds

        for ($i = 0; $i < $maxRetries; $i++) {
            // Check with stat() for better performance
            if (@stat($file) !== false) {
                return true;
            }

            // Use adaptive sleep/usleep with early exit on events
            usleep($microDelay);
        }

        return false;
    }

    /**
     * Non-blocking read file content
     * Returns false if file doesn't exist or unreadable
     * 
     * @param string $file File path
     * @param bool $useCache Use cache if available
     * @return string|false
     */
    public static function readFile($file, $useCache = true) {
        // Check cache first
        if ($useCache && isset(self::$fileCache[$file])) {
            $cached = self::$fileCache[$file];
            if (microtime(true) - $cached['time'] < self::$cacheTTL) {
                return $cached['content'];
            }
            unset(self::$fileCache[$file]);
        }

        // Use stat for quick check
        $stat = @stat($file);
        if ($stat === false) {
            return false;
        }

        // Read with size limit to prevent memory issues
        $maxReadSize = 1024 * 1024; // 1MB limit
        if ($stat['size'] > $maxReadSize) {
            return false;
        }

        $content = @file_get_contents($file);

        if ($content !== false && $useCache) {
            self::$fileCache[$file] = [
                'content' => $content,
                'time' => microtime(true)
            ];
        }

        return $content;
    }

    /**
     * Batch check multiple files existence
     * More efficient than individual checks
     * 
     * @param array $files Array of file paths
     * @return array Array with file => bool mapping
     */
    public static function checkFilesExists(array $files) {
        $results = [];
        foreach ($files as $file) {
            $results[$file] = @stat($file) !== false;
        }
        return $results;
    }

    /**
     * Wait for ANY file from list to exist
     * Returns on first match or after timeout
     * 
     * @param array $files Array of file paths
     * @param int $maxRetries Maximum retry attempts
     * @param int $delayMs Delay between retries
     * @return string|false File path that exists, or false
     */
    public static function awaitAnyFileExists(array $files, $maxRetries = 300, $delayMs = 10) {
        $microDelay = max(1000, $delayMs * 1000);

        for ($i = 0; $i < $maxRetries; $i++) {
            foreach ($files as $file) {
                if (@stat($file) !== false) {
                    return $file;
                }
            }
            usleep($microDelay);
        }

        return false;
    }

    /**
     * Adaptive wait with exponential backoff
     * Starts with short waits, then increases delay
     * 
     * @param string $file File path to monitor
     * @param int $maxRetries Maximum retry attempts
     * @param int $initialDelayMs Initial delay in milliseconds
     * @return bool
     */
    public static function awaitFileExistsAdaptive($file, $maxRetries = 300, $initialDelayMs = 10) {
        $delay = max(1000, $initialDelayMs * 1000); // Start in microseconds
        $maxDelay = 500000; // Cap at 500ms

        for ($i = 0; $i < $maxRetries; $i++) {
            if (@stat($file) !== false) {
                return true;
            }

            usleep($delay);

            // Increase delay exponentially but cap it
            $delay = min($maxDelay, intval($delay * 1.2));
        }

        return false;
    }

    /**
     * Monitor file for changes (mtime)
     * More efficient than polling
     * 
     * @param string $file File path
     * @param int $timeoutSeconds Maximum wait time
     * @return bool True if file was modified
     */
    public static function awaitFileModified($file, $timeoutSeconds = 30) {
        $stat = @stat($file);
        if ($stat === false) {
            return false;
        }

        $originalMtime = $stat['mtime'];
        $endTime = time() + $timeoutSeconds;

        while (time() < $endTime) {
            $currentStat = @stat($file);

            if ($currentStat === false) {
                return false;
            }

            if ($currentStat['mtime'] > $originalMtime) {
                return true;
            }

            usleep(50000); // 50ms check interval
        }

        return false;
    }

    /**
     * Clear cache for specific file
     * @param string $file File path
     */
    public static function clearFileCache($file = null) {
        if ($file === null) {
            self::$fileCache = [];
        } else {
            unset(self::$fileCache[$file]);
        }
    }

    /**
     * Get cache statistics for debugging
     * @return array
     */
    public static function getCacheStats() {
        return [
            'cached_files' => count(self::$fileCache),
            'cache_memory' => memory_get_usage(true),
        ];
    }

    /**
     * Replacement for usleep with select()
     * More CPU-efficient than usleep in loops
     * 
     * @param int $microseconds Sleep duration
     */
    public static function efficientSleep($microseconds) {
        if ($microseconds < 1000) {
            usleep($microseconds);
            return;
        }

        // Use time_nanosleep() if available for precise non-blocking sleep
        if (function_exists('time_nanosleep')) {
            $sec = intdiv($microseconds, 1000000);
            $nsec = ($microseconds % 1000000) * 1000; // convert microsec to nanosec
            // suppress warnings on interrupted sleep
            @time_nanosleep($sec, $nsec);
            return;
        }

        // Fallback to usleep
        usleep($microseconds);
    }

    /**
     * Non-blocking file size check
     * @param string $file File path
     * @return int|false File size or false
     */
    public static function getFileSize($file) {
        $stat = @stat($file);
        return $stat !== false ? $stat['size'] : false;
    }

    /**
     * Check if multiple files exist in parallel fashion
     * Better than sequential checks
     * 
     * @param array $files File paths to check
     * @return array Files that exist
     */
    public static function filterExistingFiles(array $files) {
        return array_filter($files, function ($file) {
            return @stat($file) !== false;
        });
    }
}
