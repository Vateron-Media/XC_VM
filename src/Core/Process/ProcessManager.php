<?php

namespace XcVm\Core\Process;

use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Core\Util\Encryption;

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
 *   // Check named process (XC_VM[123], Thumbnail[456], etc.)
 *   if (ProcessManager::isNamedProcessRunning($pid, 'XC_VM', $streamId, PHP_BIN)) { ... }
 *
 *   // Kill a process
 *   ProcessManager::kill($pid);
 *   ProcessManager::kill($pid, SIGTERM); // graceful
 *
 *   // Cron locking
 *   ProcessManager::acquireCronLock('/tmp/cron_streams.pid');
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
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param string|null $exe Expected executable name (e.g., 'ffmpeg', 'php')
	 * @return bool
	 */
	public static function isRunning(?int $pid, ?string $exe = null) {
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
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param string $processName Process name prefix (e.g., 'XC_VM', 'Thumbnail', 'TVArchive')
	 * @param int|string $identifier Stream/task ID
	 * @param string $exe Expected executable (default: PHP_BIN)
	 * @return bool
	 */
	public static function isNamedProcessRunning(?int $pid, string $processName, int|string $identifier, ?string $exe = null) {
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
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param int $streamId Stream ID
	 * @return bool
	 */
	public static function isStreamRunning(?int $pid, int $streamId) {
		if ($pid <= 0) {
			return false;
		}

		if (!self::procExists($pid) || !is_readable('/proc/' . $pid . '/exe')) {
			return false;
		}

		$exe = @basename(@readlink('/proc/' . $pid . '/exe'));

		if (strpos($exe, 'php') === 0) {
			return true;
		}
		return self::producerRunsStream($exe, (string) @file_get_contents('/proc/' . $pid . '/cmdline'), $streamId);
	}

	/**
	 * Whether a non-PHP process is this stream's producer, from its executable
	 * basename and NUL-separated command line. PURE.
	 */
	public static function producerRunsStream(string $exe, string $cmdline, int $streamId): bool {
		if (strpos($exe, 'ffmpeg') === 0) {
			return stristr($cmdline, '/' . $streamId . '_.m3u8') || stristr($cmdline, '/' . $streamId . '_%d.ts');
		}

		// The fanout daemon's native remuxer (`xc_fanout remux … <streams>/<id>_.m3u8`),
		// which produces a copy-only stream in ffmpeg's place. Its own subcommand
		// and this stream's playlist must both be there: the daemon process shares
		// the executable but names no stream playlist.
		if (strpos($exe, 'xc_fanout') === 0) {
			return strpos($cmdline, "\0remux\0") !== false && strpos($cmdline, '/' . $streamId . '_.m3u8') !== false;
		}

		return self::driverRunsStream($exe, $cmdline, $streamId);
	}

	/** A module's source-driver engine, which the contract makes name the playlist too. PURE. */
	private static function driverRunsStream(string $exe, string $cmdline, int $streamId): bool {
		return in_array($exe, SourceDriverRegistry::binaries(), true) && strpos($cmdline, '/' . $streamId . '_.m3u8') !== false;
	}

	/**
	 * What kind of producer a stream's pid is, for the panel's stream list.
	 *
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @return string|null 'fanout' (xc_fanout remux), 'ffmpeg', 'php' (the LLOD
	 *                     segmenter / loopback relay), or null when it cannot be read.
	 */
	public static function producerKind(?int $pid) {
		if ($pid <= 0 || !self::procExists($pid) || !is_readable('/proc/' . $pid . '/exe')) {
			return null;
		}

		$exe = @basename(@readlink('/proc/' . $pid . '/exe'));

		if (strpos($exe, 'xc_fanout') === 0) {
			$cmdline = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
			return strpos($cmdline, "\0remux\0") !== false ? 'fanout' : null;
		}
		if (preg_match('/^(ffmpeg|php)/', $exe, $m)) {
			return $m[1];
		}

		return in_array($exe, SourceDriverRegistry::binaries(), true) ? 'driver' : null;
	}

	/**
	 * One CPU/memory reading for a process, from /proc/PID/stat.
	 *
	 * CPU is cumulative (the ticks the process has burned since it started), so a
	 * percentage needs two readings — see cpuPercent(). Memory is resident set
	 * size in bytes, which is the figure that matters for a box running hundreds
	 * of encoders: what they actually hold in RAM.
	 *
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @return array{ticks:int,rss:int,at:float,start:int}|null Null when the process is gone.
	 */
	public static function resourceSample(?int $pid) {
		if ($pid <= 1) {
			return null;
		}

		$stat = @file_get_contents('/proc/' . $pid . '/stat');
		if (!is_string($stat) || $stat === '') {
			return null;
		}

		// Field 2 (comm) is parenthesised and may itself contain spaces and
		// brackets — "(ffmpeg (x))" is a legal name — so everything before the
		// LAST ')' is skipped and the split starts at field 3 (state).
		$rClose = strrpos($stat, ')');
		if ($rClose === false) {
			return null;
		}
		$rFields = preg_split('/\s+/', trim(substr($stat, $rClose + 1)));
		if (!is_array($rFields) || count($rFields) < 22) {
			return null;
		}

		// $rFields[$i] is /proc/PID/stat field $i + 3: utime 14, stime 15,
		// starttime 22 (ticks after boot), rss 24.
		return [
			'ticks' => (int) $rFields[11] + (int) $rFields[12],
			'rss'   => (int) $rFields[21] * self::pageSize(),
			'at'    => microtime(true),
			'start' => (int) $rFields[19],
		];
	}

	/**
	 * CPU use averaged over the process's whole life, in percent of one core —
	 * what `ps` reports. The fallback when there is no earlier sample to take a
	 * difference against (the first reading of a producer), so a stream shows a
	 * figure at once rather than a dash for a whole pass.
	 *
	 * The age comes from the process's own start time in /proc/PID/stat against
	 * /proc/uptime, not from the mtime of /proc/PID: that inode is created when
	 * something first looks at the directory, which for a long-running process
	 * can be much later than its start.
	 *
	 * @param array $sample A resourceSample() reading.
	 * @return float|null Null when the age cannot be read.
	 */
	public static function cpuPercentSinceStart(array $sample) {
		if (!isset($sample['ticks'], $sample['start'])) {
			return null;
		}
		$rUptime = (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');
		$rAge = $rUptime - ((int) $sample['start'] / 100);
		if ($rUptime <= 0 || $rAge < 1) {
			return null; // a process younger than a second says nothing yet
		}

		return round(((int) $sample['ticks'] / 100) / $rAge * 100, 1);
	}

	/**
	 * CPU use between two resourceSample() readings, in percent of one core.
	 *
	 * @param array $now  The newer sample.
	 * @param array $prev The older one (from the previous pass).
	 * @return float|null Null when the pair says nothing: no previous reading, a
	 *                    restarted process (its counter went backwards), or two
	 *                    readings from the same instant.
	 */
	public static function cpuPercent(array $now, array $prev) {
		if (!isset($now['ticks'], $now['at'], $prev['ticks'], $prev['at'])) {
			return null;
		}

		$rSeconds = (float) $now['at'] - (float) $prev['at'];
		$rTicks = (int) $now['ticks'] - (int) $prev['ticks'];
		if ($rSeconds <= 0 || $rTicks < 0) {
			return null;
		}

		// /proc reports CPU time in USER_HZ, which is 100 on Linux whatever the
		// kernel's own tick rate — it is part of the /proc ABI, not CONFIG_HZ.
		return round(($rTicks / 100) / $rSeconds * 100, 1);
	}

	/**
	 * The kernel page size, in bytes — /proc/PID/stat counts RSS in pages.
	 *
	 * Derived from this process's own two views of its memory (statm in pages,
	 * status in kB) rather than assumed, because 64K pages are normal on arm64.
	 *
	 * @return int
	 */
	protected static function pageSize() {
		static $rSize = null;
		if ($rSize !== null) {
			return $rSize;
		}

		$rSize = 4096;
		$rStatm = @file_get_contents('/proc/self/statm');
		$rStatus = @file_get_contents('/proc/self/status');
		if (is_string($rStatm) && is_string($rStatus) && preg_match('/^VmRSS:\s+(\d+) kB/m', $rStatus, $rMatch)) {
			$rPages = (int) (preg_split('/\s+/', trim($rStatm))[1] ?? 0);
			if ($rPages > 0) {
				// The two files are read a moment apart, so snap the ratio to a
				// real page size instead of trusting it to the byte.
				$rRatio = ((int) $rMatch[1] * 1024) / $rPages;
				foreach ([4096, 8192, 16384, 65536] as $rCandidate) {
					if ($rRatio >= $rCandidate * 0.75 && $rRatio <= $rCandidate * 1.25) {
						$rSize = $rCandidate;
						break;
					}
				}
			}
		}

		return $rSize;
	}

	// ───────────────────────────────────────────────────────────
	//  Process Control
	// ───────────────────────────────────────────────────────────

	/**
	 * Kill a process by PID
	 *
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param int $signal Signal to send (default: SIGKILL = 9)
	 * @return bool
	 */
	public static function kill(?int $pid, int $signal = 9) {
		if ($pid <= 0) {
			return false;
		}

		if (!self::procExists($pid)) {
			return false;
		}

		return posix_kill($pid, $signal);
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
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @return int Age in seconds, or -1 if it cannot be determined
	 */
	public static function getProcessAge(?int $pid) {
		if ($pid <= 0 || !self::procExists($pid)) {
			return -1;
		}

		clearstatcache(true, '/proc/' . $pid);
		$rStart = @filemtime('/proc/' . $pid);

		if ($rStart === false) {
			return -1;
		}

		$rAge = time() - $rStart;

		return max($rAge, 0);
	}

	// ───────────────────────────────────────────────────────────
	//  Cron Lock Management
	// ───────────────────────────────────────────────────────────

	/**
	 * Lock file of a cron class. Stable across secret rotations: the old name
	 * hashed live_streaming_pass, so rotating that secret let a second
	 * instance start beside one still running under the old name.
	 */
	public static function cronLockPath(string $rClass): string {
		return CRONS_TMP_PATH . 'lock_' . md5($rClass);
	}

	/**
	 * The name a cron class's lock had before cronLockPath(). Only consulted
	 * so that an instance started by the previous release is still honoured.
	 */
	public static function legacyCronLockPath(string $rClass, ?string $rStreamingPass): string {
		return CRONS_TMP_PATH . md5(Encryption::generateUniqueCode($rStreamingPass) . $rClass);
	}

	/**
	 * The pid that holds $lockFile, 0 when nobody does (no lock, or its
	 * process is gone).
	 *
	 * A lock carries "pid starttime", the start time as /proc/PID/stat gives
	 * it, and is held for as long as that very process lives: a pid the system
	 * has handed to another process since is not the holder, nor is a process
	 * that has ended and only waits for its parent to collect it. A lock of the
	 * previous release carries the pid alone and is judged as that release
	 * judged it, by the pid and for thirty minutes; with $rPinned it has no
	 * holder.
	 *
	 * @param bool $rPinned Count only a lock that names its holder's start time.
	 */
	public static function cronLockHolder(string $lockFile, bool $rPinned = false): int {
		// The file may be removed at any moment by a competing run: no warning.
		$contents = @file_get_contents($lockFile);
		if ($contents === false) {
			return 0;
		}

		$rParts = explode(' ', trim($contents));
		$pid = (int) $rParts[0];
		if ($pid <= 0 || !self::procExists($pid)) {
			return 0;
		}

		if (!isset($rParts[1])) {
			$mtime = @filemtime($lockFile);
			return (!$rPinned && $mtime !== false && time() - $mtime < 1800) ? $pid : 0;
		}

		// Ended, and still in /proc until its parent collects it (state Z, the
		// field after the name): it holds nothing.
		$rStat = (string) @file_get_contents('/proc/' . $pid . '/stat');
		if (substr($rStat, (int) strrpos($rStat, ')') + 1, 2) === ' Z') {
			return 0;
		}

		// An unreadable stat says nothing about who the process is: by its pid.
		$rSample = self::resourceSample($pid);
		return ($rSample === null || $rSample['start'] === (int) $rParts[1]) ? $pid : 0;
	}

	/**
	 * Exit like acquireCronLock() when $lockFile is held; never takes or
	 * writes the lock.
	 *
	 * The holder is never ended here, however long it has run: a backup or a
	 * clean-up may take hours. So a cron that hangs keeps its lock, and no new
	 * run of it starts, until someone ends that process; each run that finds a
	 * holder over an hour old says so in the panel's log. A root cron writes
	 * that line as the owner of the logs directory (SettingsAudit::asAgentUser):
	 * the directory is xc_vm's, where root neither creates a file of its own
	 * nor follows a link. PHP's error_log (the cron's stderr) only when root
	 * cannot switch.
	 */
	public static function exitIfCronLockHeld(string $lockFile): void {
		$pid = self::cronLockHolder($lockFile);
		if ($pid === 0) {
			return;
		}

		$mtime = @filemtime($lockFile);
		if ($mtime !== false && time() - $mtime >= 3600) {
			// The same text for every run: cron:errors folds the runs of one pass into one row.
			$rName = trim(str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $pid . '/cmdline')));
			$rLine = ($rName !== '' ? $rName : 'A cron') . ' (pid ' . $pid . ') has held its cron lock since ' . date('Y-m-d H:i:s', $mtime) . ': no new run starts while it lives, and nothing ends it. If it hangs, kill it';
			$rKept = SettingsAudit::asAgentUser(static function () use ($rLine, $lockFile): bool {
				FileLogger::log('cron', $rLine, $lockFile);
				return true;
			}, dirname(FileLogger::getLogFile()));
			if (!$rKept) {
				error_log('XC_VM ' . $rLine);
			}
		}

		exit('Running...');
	}

	/**
	 * Acquire a cron lock (PID file)
	 *
	 * If the lock is held (cronLockHolder()), exits with 'Running...'; the
	 * holder is left alone. Otherwise writes the lock with the current PID and
	 * its start time.
	 *
	 * This replaces CoreUtilities::checkCron().
	 *
	 * @param string $lockFile Path to PID lock file
	 * @return bool Always returns true (exits on conflict)
	 */
	public static function acquireCronLock(string $lockFile) {
		self::exitIfCronLockHeld($lockFile);

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

		// Without a readable start time the lock carries the pid alone, and is
		// judged like one of the previous release.
		$rSample = self::resourceSample(getmypid());
		file_put_contents($lockFile, getmypid() . ($rSample !== null ? ' ' . $rSample['start'] : ''));

		return true;
	}

	// ───────────────────────────────────────────────────────────
	//  Internal Helpers
	// ───────────────────────────────────────────────────────────

	/**
	 * Check if /proc/PID exists with caching
	 *
	 * @return bool
	 */
	protected static function procExists(int $pid) {
		$now = microtime(true);
		$key = $pid;

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
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param int|string $streamID Stream identifier to search for
	 * @return bool
	 */
	public static function isStreamAlive(?int $pid, int|string $streamID) {
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
	 * Check if a stream's PHP monitor (MonitorCommand) is running.
	 *
	 * Matches the title XC_VM[streamID] only. A ProxyCommand (XC_VMProxy[streamID])
	 * is checked with isNamedProcessRunning($pid, 'XC_VMProxy', $streamID).
	 *
	 * @param int|null $pid Process ID (null/0 -> not running)
	 * @param int|string $streamID Stream identifier
	 * @param string|null $exe Expected executable (default: PHP_BIN)
	 * @return bool
	 */
	public static function isMonitorAlive(?int $pid, int|string $streamID, ?string $exe = null) {
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
		return ($cmdline == 'XC_VM[' . $streamID . ']');
	}

	/**
	 * Start a stream monitor process in background.
	 *
	 * Always the PHP watchdog. Stream code calls StreamProcess::startMonitor()
	 * instead, which hands the stream to the fanout daemon's supervisor when this
	 * server supervises and only falls back to this.
	 *
	 * @return bool
	 */
	public static function startMonitor(int $streamID, int $restart = 0) {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php monitor ' . intval($streamID) . ' ' . intval($restart) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	// ───────────────────────────────────────────────────────────
	//  Utility
	// ───────────────────────────────────────────────────────────

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
	 * @param array  $rTerms    Cmdline substrings to match (exact, case-sensitive)
	 * @param int    $rLimit    Stop after this many matches (0 = no limit)
	 * @param string $rProcRoot procfs mount point (overridable for tests)
	 * @return array<int> Matching PIDs (own PID excluded)
	 */
	public static function findProcessPIDs(array $rTerms, int $rLimit = 0, string $rProcRoot = '/proc') {
		$rPIDs = [];
		$rSelf = getmypid();
		foreach (glob($rProcRoot . '/[0-9]*/cmdline') ?: [] as $rCmdFile) {
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

	/**
	 * Whether a raw /proc/PID/cmdline is a stream producer: ffmpeg itself, or
	 * the fanout daemon's native remuxer (`xc_fanout remux`). Only the program
	 * name is matched, so ffprobe (under bin/ffmpeg_bin/ as well), a shell
	 * wrapper naming ffmpeg and the fanout daemon are not producers.
	 *
	 * @param string $rRawCmdline NUL-separated argv, as read from /proc
	 * @return bool
	 */
	public static function isStreamProducerCmdline(string $rRawCmdline) {
		$rArgv = explode("\0", $rRawCmdline);
		$rProgram = basename($rArgv[0]);
		if ($rProgram === 'ffmpeg') {
			return true;
		}
		return $rProgram === 'xc_fanout' && ($rArgv[1] ?? '') === 'remux';
	}

	/**
	 * Count the running stream producers (see isStreamProducerCmdline()) —
	 * total_running_streams in the watchdog heartbeat. Reads /proc directly
	 * instead of `ps ax | grep -c ffmpeg`.
	 *
	 * @param string $rProcRoot procfs mount point (overridable for tests)
	 * @return int
	 */
	public static function countStreamProducers(string $rProcRoot = '/proc') {
		$rCount = 0;
		foreach (glob($rProcRoot . '/[0-9]*/cmdline') ?: [] as $rCmdFile) {
			$rRaw = @file_get_contents($rCmdFile);
			if ($rRaw && self::isStreamProducerCmdline($rRaw)) {
				$rCount++;
			}
		}
		return $rCount;
	}

	/**
	 * PIDs of the live PHP-FPM workers of the xc_vm pool — the pids that
	 * connection rows record (getmypid() in the stream endpoints). The watchdog
	 * publishes them as servers.php_pids. FPM titles a worker "php-fpm: pool
	 * xc_vm" and its master "php-fpm: master process (...)", so the pool pid
	 * files (masters only) would never match a connection.
	 *
	 * With pm = ondemand an idle pool has no workers, so an empty list is only
	 * reported while an FPM master is visible. With no master either, FPM is
	 * down or hidden from this scan, and the list is unknown.
	 *
	 * @param string $rProcRoot procfs mount point (overridable for tests)
	 * @return array<int>|null Worker PIDs, ascending; null when unknown
	 */
	public static function phpFpmWorkerPIDs(string $rProcRoot = '/proc') {
		$rPIDs = self::findProcessPIDs(['php-fpm: pool xc_vm'], 0, $rProcRoot);
		if (count($rPIDs) == 0 && count(self::findProcessPIDs(['php-fpm: master process'], 1, $rProcRoot)) == 0) {
			return null;
		}
		sort($rPIDs);
		return $rPIDs;
	}
}
