<?php

/**
 * XC_VM — Process Manager
 *
 * Centralizes process management: PID checking, killing, cron locks,
 * /proc filesystem inspection. Replaces scattered posix_kill(),
 * shell_exec('ps ...'), and file_exists('/proc/PID') calls.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   CoreUtilities::checkCron()        → ProcessManager::acquireCronLock()
 *   CoreUtilities::isProcessRunning() → ProcessManager::isRunning()
 *   CoreUtilities::isStreamRunning()  → ProcessManager::isStreamRunning()
 *   CoreUtilities::isMonitorRunning() → ProcessManager::isNamedProcessRunning()
 *   CoreUtilities::checkPID()         → ProcessManager::checkPID()
 *   posix_kill($pid, 9)              → ProcessManager::kill()
 *
 * ---------------------------------------------------------------
 * Usage:
 * ---------------------------------------------------------------
 *
 *   // Check if a process is running
 *   if (ProcessManager::isRunning($pid)) { ... }
 *
 *   // Check if a process with specific executable is running
 *   if (ProcessManager::isRunning($pid, 'ffmpeg')) { ... }
 *
 *   // Check named process (XC_VM[123], Thumbnail[456], etc.)
 *   if (ProcessManager::isNamedProcessRunning($pid, 'XC_VM', $streamId, PHP_BIN)) { ... }
 *
 *   // Kill a process
 *   ProcessManager::kill($pid);
 *   ProcessManager::kill($pid, SIGTERM); // graceful
 *
 *   // Cron locking
 *   ProcessManager::acquireCronLock('/tmp/cron_streams.pid', 1800);
 *   // ... do work ...
 *   // Lock file cleaned up automatically on exit
 *
 * @see CoreUtilities::checkCron()
 * @see CoreUtilities::isProcessRunning()
 * @see CoreUtilities::isStreamRunning()
 */

class ProcessManager {

    /** @var array Static cache for /proc existence checks */
    protected static $procCache = [];

    /** @var float Cache TTL in seconds */
    protected static $cacheTtl = 1.0;

    // ───────────────────────────────────────────────────────────
    //  Process Checking
    // ───────────────────────────────────────────────────────────

    /**
     * Check if a process is running via /proc filesystem
     *
     * @param int $pid Process ID
     * @param string|null $exe Expected executable name (e.g., 'ffmpeg', 'php')
     * @return bool
     */
    public static function isRunning($pid, $exe = null) {
        $pid = (int)$pid;

        if ($pid <= 0) {
            return false;
        }

        if (!self::procExists($pid)) {
            return false;
        }

        // If no exe filter — just check /proc exists
        if ($exe === null) {
            return true;
        }

        // Check executable matches
        if (!is_readable('/proc/' . $pid . '/exe')) {
            return false;
        }

        $actualExe = @basename(@readlink('/proc/' . $pid . '/exe'));

        return strpos($actualExe, basename($exe)) === 0;
    }

    /**
     * Check if a named process is running (e.g., XC_VM[123])
     *
     * Reads /proc/PID/cmdline and matches against "NAME[ID]" pattern.
     *
     * @param int $pid Process ID
     * @param string $processName Process name prefix (e.g., 'XC_VM', 'Thumbnail', 'TVArchive')
     * @param int|string $identifier Stream/task ID
     * @param string $exe Expected executable (default: PHP_BIN)
     * @return bool
     */
    public static function isNamedProcessRunning($pid, $processName, $identifier, $exe = null) {
        $pid = (int)$pid;

        if ($pid <= 0) {
            return false;
        }

        if ($exe === null && defined('PHP_BIN')) {
            $exe = PHP_BIN;
        }

        clearstatcache(true);

        if (!self::procExists($pid)) {
            return false;
        }

        if ($exe && !is_readable('/proc/' . $pid . '/exe')) {
            return false;
        }

        if ($exe) {
            $actualExe = @basename(@readlink('/proc/' . $pid . '/exe'));
            if (strpos($actualExe, basename($exe)) !== 0) {
                return false;
            }
        }

        $cmdline = trim(@file_get_contents('/proc/' . $pid . '/cmdline'));
        $expected = $processName . '[' . $identifier . ']';

        return $cmdline === $expected;
    }

    /**
     * Check if a stream (ffmpeg/php) process is running
     *
     * Specialized check for streaming processes that match
     * either ffmpeg with specific stream output files, or PHP processes.
     *
     * @param int $pid Process ID
     * @param int $streamId Stream ID
     * @return bool
     */
    public static function isStreamRunning($pid, $streamId) {
        $pid = (int)$pid;

        if ($pid <= 0) {
            return false;
        }

        if (!self::procExists($pid) || !is_readable('/proc/' . $pid . '/exe')) {
            return false;
        }

        $exe = @basename(@readlink('/proc/' . $pid . '/exe'));

        if (strpos($exe, 'ffmpeg') === 0) {
            $cmdline = trim(@file_get_contents('/proc/' . $pid . '/cmdline'));
            return (
                stristr($cmdline, '/' . $streamId . '_.m3u8') ||
                stristr($cmdline, '/' . $streamId . '_%d.ts')
            );
        }

        if (strpos($exe, 'php') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Check if a process is alive using a PID file
     *
     * Reads PID from file, then checks /proc/PID/cmdline for expected string.
     *
     * @param string $pidFile Path to PID file
     * @param string $searchString Expected string in cmdline
     * @return bool
     */
    public static function checkPidFile($pidFile, $searchString) {
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int)trim(file_get_contents($pidFile));

        if ($pid <= 0) {
            return false;
        }

        return self::matchesCmdline($pid, $searchString);
    }

    /**
     * Check if a process cmdline contains a search string
     *
     * @param int $pid Process ID
     * @param string $search String to look for in cmdline
     * @return bool
     */
    public static function matchesCmdline($pid, $search) {
        $pid = (int)$pid;

        if ($pid <= 0 || !self::procExists($pid)) {
            return false;
        }

        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        if ($cmdline === false) {
            return false;
        }

        return stripos($cmdline, $search) !== false;
    }

    // ───────────────────────────────────────────────────────────
    //  Process Control
    // ───────────────────────────────────────────────────────────

    /**
     * Kill a process by PID
     *
     * @param int $pid Process ID
     * @param int $signal Signal to send (default: SIGKILL = 9)
     * @return bool
     */
    public static function kill($pid, $signal = 9) {
        $pid = (int)$pid;

        if ($pid <= 0) {
            return false;
        }

        if (!self::procExists($pid)) {
            return false;
        }

        return posix_kill($pid, $signal);
    }

    /**
     * Kill all processes matching a pattern via cmdline
     *
     * @param string $pattern Pattern to match in ps output
     */
    public static function killByPattern($pattern) {
        $pattern = escapeshellarg($pattern);
        shell_exec("kill -9 `ps -ef | grep {$pattern} | grep -v grep | awk '{print \$2}'`");
    }

    /**
     * Get the current process PID
     *
     * @return int
     */
    public static function currentPid() {
        return getmypid();
    }

    // ───────────────────────────────────────────────────────────
    //  Cron Lock Management
    // ───────────────────────────────────────────────────────────

    /**
     * Acquire a cron lock (PID file)
     *
     * If a lock file exists with a running process, exits with 'Running...'.
     * If the process is stale (older than $timeout), kills it and takes over.
     * Creates a new lock file with the current PID.
     *
     * This replaces CoreUtilities::checkCron().
     *
     * @param string $lockFile Path to PID lock file
     * @param int $timeout Maximum age in seconds before considering stale (default: 1800 = 30min)
     * @return bool Always returns true (exits on conflict)
     */
    public static function acquireCronLock($lockFile, $timeout = 1800) {
        if (file_exists($lockFile)) {
            $pid = (int)trim(file_get_contents($lockFile));

            if (self::procExists($pid)) {
                // Process is running — check if it's stale
                if (time() - filemtime($lockFile) >= $timeout) {
                    // Stale — kill and take over
                    if ($pid > 0) {
                        posix_kill($pid, 9);
                    }
                } else {
                    // Still fresh — another instance is running
                    exit('Running...');
                }
            }
        }

        // Write our PID
        file_put_contents($lockFile, getmypid());

        return true;
    }

    /**
     * Release a cron lock
     *
     * @param string $lockFile Path to PID lock file
     */
    public static function releaseCronLock($lockFile) {
        if (file_exists($lockFile)) {
            $pid = (int)trim(file_get_contents($lockFile));

            // Only remove if it's our lock
            if ($pid === getmypid()) {
                unlink($lockFile);
            }
        }
    }

    // ───────────────────────────────────────────────────────────
    //  Internal Helpers
    // ───────────────────────────────────────────────────────────

    /**
     * Check if /proc/PID exists with caching
     *
     * @param int $pid
     * @return bool
     */
    protected static function procExists($pid) {
        $now = microtime(true);
        $key = (int)$pid;

        if (isset(self::$procCache[$key]) && ($now - self::$procCache[$key]['time']) < self::$cacheTtl) {
            return self::$procCache[$key]['exists'];
        }

        $exists = file_exists('/proc/' . $pid);
        self::$procCache[$key] = ['exists' => $exists, 'time' => $now];

        return $exists;
    }

    /**
     * Clear the proc cache
     *
     * Useful before critical checks where stale cache could be dangerous.
     */
    public static function clearCache() {
        self::$procCache = [];
    }

    /**
     * Count running processes matching a pattern
     *
     * @param string $pattern grep pattern
     * @return int
     */
    public static function countProcesses($pattern) {
        $pattern = escapeshellarg($pattern);
        return (int)trim(shell_exec("ps ax | grep -v grep | grep -c {$pattern}"));
    }
}
