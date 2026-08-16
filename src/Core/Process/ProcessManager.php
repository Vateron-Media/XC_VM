<?php

namespace XcVm\Core\Process;

/**
 * Process Manager
 *
 * Centralizes process management: PID checking, killing, cron locks,
 * /proc filesystem inspection. Replaces scattered posix_kill(),
 * shell_exec('ps ...'), and file_exists('/proc/PID') calls.
 *
 * Usage:
 *
 *   // Check if a process is running
 *   if (ProcessManager::isRunning($pid)) { ... }
 *
 *   // Check if a process with specific executable is running
 *   if (ProcessManager::isRunning($pid, 'ffmpeg')) { ... }
 *
 *   // Check named process (\XC_VM[123], Thumbnail[456], etc.)
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
 * @package XC_VM_Core_Process
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
     * Check if a named process is running (e.g., \XC_VM[123])
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

    /**
     * Get the age of a process in seconds (how long it has been running).
     *
     * On Linux the mtime of the /proc/PID directory is fixed to the
     * process start time, so `time() - filemtime()` yields the same figure
     * as `ps -o etimes` without shelling out to ps — consistent with the
     * rest of this class reading /proc directly.
     *
     * Used to detect daemons that are still present but wedged (e.g. a
     * watchdog blocked in poll() on a half-open MariaDB socket): a normally
     * short-lived generation that has been alive far too long is stale.
     *
     * @param int $pid Process ID
     * @return int Age in seconds, or -1 if it cannot be determined
     */
    public static function getProcessAge($pid) {
        $pid = (int)$pid;

        if ($pid <= 0 || !self::procExists($pid)) {
            return -1;
        }

        clearstatcache(true, '/proc/' . $pid);
        $rStart = @filemtime('/proc/' . $pid);

        if ($rStart === false) {
            return -1;
        }

        $rAge = time() - $rStart;

        return $rAge > 0 ? $rAge : 0;
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
            // Read content + mtime up front. A competing cron can remove the lock
            // file between the exists() check and these reads (TOCTOU), which would
            // otherwise emit "failed to open stream" / "stat failed" warnings. If it
            // vanished, fall through and take the lock ourselves.
            $contents = @file_get_contents($lockFile);
            $mtime = @filemtime($lockFile);

            if ($contents !== false && $mtime !== false) {
                $pid = (int)trim($contents);

                if (self::procExists($pid)) {
                    // Process is running — check if it's stale
                    if (time() - $mtime >= $timeout) {
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
        }

        // Write our PID
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        // When running as root, chown created dirs to xc_vm so other
        // crons (cache, streams, etc.) can write into tmp/ subtree.
        if (posix_geteuid() === 0 && function_exists('posix_getpwnam') && defined('MAIN_HOME')) {
            $rUser = posix_getpwnam('xc_vm');
            if ($rUser) {
                $rMainHome = rtrim(MAIN_HOME, '/');
                $rDir = $lockDir;
                while ($rDir && $rDir !== $rMainHome && strlen($rDir) > strlen($rMainHome)) {
                    if (is_dir($rDir) && fileowner($rDir) === 0) {
                        @chown($rDir, $rUser['uid']);
                        @chgrp($rDir, $rUser['gid']);
                    }
                    $rDir = dirname($rDir);
                }
            }
        }

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

    // ───────────────────────────────────────────────────────────
    //  Streaming-specific Process Methods
    //  Extracted from StreamingUtilities
    // ───────────────────────────────────────────────────────────

    /**
     * Check if a stream process is alive (simplified cmdline search).
     *
     * Extracted from ProcessManager::isStreamAlive().
     * Searches for $streamID anywhere in /proc/PID/cmdline (case-insensitive).
     *
     * @param int $pid Process ID
     * @param int|string $streamID Stream identifier to search for
     * @return bool
     */
    public static function isStreamAlive($pid, $streamID) {
        $pid = (int)$pid;
        if ($pid <= 1) {
            return false;
        }

        if (!self::procExists($pid)) {
            return false;
        }

        if (!is_link('/proc/' . $pid . '/exe')) {
            return false;
        }

        static $cache = [];
        $cacheKey = $pid . '|' . $streamID;
        if (isset($cache[$cacheKey]) && $cache[$cacheKey]['time'] > time() - 4) {
            return $cache[$cacheKey]['alive'];
        }

        $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmd === false) {
            $alive = false;
        } else {
            $cmd = str_replace("\0", ' ', $cmd);
            $alive = stripos($cmd, $streamID) !== false;
        }

        $cache[$cacheKey] = ['alive' => $alive, 'time' => time()];
        return $alive;
    }

    /**
     * Check if a monitor/proxy process is running.
     *
     * Extracted from ProcessManager::isMonitorAlive().
     * Checks for \XC_VM[streamID] OR XC_VMProxy[streamID] in cmdline.
     *
     * @param int $pid Process ID
     * @param int|string $streamID Stream identifier
     * @param string|null $exe Expected executable (default: PHP_BIN)
     * @return bool
     */
    public static function isMonitorAlive($pid, $streamID, $exe = null) {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return false;
        }

        if ($exe === null && defined('PHP_BIN')) {
            $exe = PHP_BIN;
        }

        if (!self::procExists($pid)) {
            return false;
        }

        if (!$exe || !is_readable('/proc/' . $pid . '/exe')) {
            return false;
        }

        if (strpos(basename(@readlink('/proc/' . $pid . '/exe')), basename($exe)) !== 0) {
            return false;
        }

        $cmdline = trim(@file_get_contents('/proc/' . $pid . '/cmdline'));
        return ($cmdline == 'XC_VM[' . $streamID . ']' || $cmdline == 'XC_VMProxy[' . $streamID . ']');
    }

    /**
     * Start a stream monitor process in background.
     *
     * Extracted from ProcessManager::startMonitor().
     *
     * @param int $streamID
     * @param int $restart
     * @return bool
     */
    public static function startMonitor($streamID, $restart = 0) {
        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php monitor ' . intval($streamID) . ' ' . intval($restart) . ' >/dev/null 2>/dev/null &');
        return true;
    }


    // ───────────────────────────────────────────────────────────
    //  Utility
    // ───────────────────────────────────────────────────────────

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

    /**
     * Check if an nginx master process is running.
     *
     * Replaces CoreUtilities::isRunning().
     *
     * The master is owned by root on typical installs (workers run as
     * xc_vm), so the scan must not be restricted to the xc_vm user —
     * a false negative here makes cron:servers/cron:streams bail out
     * every run and no daemon ever gets revived.
     *
     * @return bool
     */
    public static function isNginxRunning() {
        foreach (glob('/proc/*/cmdline') ?: [] as $rCmdFile) {
            $rRaw = @file_get_contents($rCmdFile);
            if ($rRaw && strpos(str_replace("\0", ' ', $rRaw), 'nginx: master') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find PIDs of processes whose command line contains one of the given
     * substrings. Reads /proc directly instead of ps|grep pipelines, which
     * match unrelated processes (e.g. ffmpeg's -thread_queue_size satisfied
     * the "queue" daemon check, so the encode queue was never revived).
     *
     * @param array $rTerms Cmdline substrings to match (exact, case-sensitive)
     * @param int   $rLimit Stop after this many matches (0 = no limit)
     * @return array<int> Matching PIDs (own PID excluded)
     */
    public static function findProcessPIDs(array $rTerms, $rLimit = 0) {
        $rPIDs = array();
        $rSelf = getmypid();
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $rCmdFile) {
            $rPID = intval(basename(dirname($rCmdFile)));
            if ($rPID == $rSelf) {
                continue;
            }
            $rRaw = @file_get_contents($rCmdFile);
            if (!$rRaw) {
                continue;
            }
            $rCmd = str_replace("\0", ' ', $rRaw);
            foreach ($rTerms as $rTerm) {
                if (strpos($rCmd, $rTerm) !== false) {
                    $rPIDs[] = $rPID;
                    if ($rLimit > 0 && count($rPIDs) >= $rLimit) {
                        return $rPIDs;
                    }
                    break;
                }
            }
        }
        return $rPIDs;
    }

    /**
     * Check whether any process (any user) matches one of the given
     * cmdline substrings.
     *
     * @param array $rTerms Cmdline substrings to match
     * @return bool
     */
    public static function isAnyProcessRunning(array $rTerms) {
        return count(self::findProcessPIDs($rTerms, 1)) > 0;
    }
}
