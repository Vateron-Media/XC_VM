<?php

namespace XcVm\Core\Cache;

/**
 * File Cache Driver (igbinary)
 *
 * File-based cache using igbinary serialization.
 *
 * Storage Format:
 *
 *   Files stored at: {basePath}/{key}
 *   Format: igbinary_serialize($data) — binary, compact, fast
 *   Writes: atomic (temp file + rename), so readers never see a partial file
 *   TTL: Based on file modification time (filemtime)
 *
 * ServiceContainer Registration:
 *
 *   $container->set('cache', function() {
 *       return new FileCache(CACHE_TMP_PATH);
 *   });
 *
 *   $container->set('cache.streams', function() {
 *       return new FileCache(STREAMS_TMP_PATH);
 *   });
 *
 * @see CacheInterface
 *
 * @package XC_VM_Core_Cache
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FileCache implements CacheInterface {
	/** @var string Base directory for cache files */
	protected $basePath;

	/** @var bool Whether igbinary extension is available */
	protected $useIgbinary;

	/**
	 * @param string $basePath Directory for cache files (must end with /)
	 */
	public function __construct(string $basePath) {
		$this->basePath = rtrim($basePath, '/') . '/';
		$this->useIgbinary = function_exists('igbinary_serialize');

		if (!is_dir($this->basePath)) {
			@mkdir($this->basePath, 0755, true);
		}
		// A root-context boot (installer, `console.php status`) must leave the
		// cache dir owned by the panel user, or xc_vm processes cannot create
		// cache files in it afterwards. Same pattern as set().
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($this->basePath, 'xc_vm');
			@chgrp($this->basePath, 'xc_vm');
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get($key, $maxAge = null) {
		$file = $this->basePath . $key;

		if (!file_exists($file)) {
			return false;
		}

		// Check TTL based on file modification time
		if ($maxAge !== null) {
			$age = time() - filemtime($file);
			if ($age >= $maxAge) {
				return false;
			}
		}

		$data = @file_get_contents($file);

		if ($data === false || $data === '') {
			@unlink($file);
			return false;
		}

		$result = $this->deserialize($data);

		if ($result === false) {
			@unlink($file);
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set($key, $data, $ttl = 0) {
		$file = $this->basePath . $key;
		if (!self::writeAtomic($file, $this->serialize($data))) {
			$this->warnWriteFailure($file);
			return false;
		}
		// A root-context write (installer, `console.php status`) must stay
		// owned by the panel user, or xc_vm daemons cannot refresh the file
		// later. Same pattern as Logger.
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($file, 'xc_vm');
			@chgrp($file, 'xc_vm');
		}
		return true;
	}

	/**
	 * Replace $path with $contents atomically: write a temp file in the same
	 * directory, then rename() it over the target. A reader (stream auth, the
	 * player API) sees either the old file or the new one, never a truncated
	 * one, and a writer killed mid-write leaves the old entry intact.
	 *
	 * The temp name is a dotfile (`.<name>.<pid>.tmp`), so the `stream_*` /
	 * `line_i_*` style globs that enumerate cache entries never match it.
	 * cleanStaleTemps() sweeps the ones a killed writer leaves behind.
	 *
	 * @param string $path     Target file.
	 * @param string $contents Bytes to write.
	 * @return bool False when the temp file could not be written or renamed.
	 */
	public static function writeAtomic(string $path, string $contents): bool {
		$tmp = dirname($path) . '/.' . basename($path) . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $contents) !== strlen($contents)) {
			@unlink($tmp);
			return false;
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Remove the temp files writeAtomic() left in $dir when its writer was
	 * killed between write and rename, once they are older than $maxAge.
	 *
	 * @param string $dir    Cache directory.
	 * @param int    $maxAge Seconds a temp file may live (a write in progress is younger).
	 */
	public static function cleanStaleTemps(string $dir, int $maxAge = 3600): void {
		foreach (glob(rtrim($dir, '/') . '/.*.tmp') ?: [] as $rFile) {
			if (is_file($rFile) && (int) @filemtime($rFile) < time() - $maxAge) {
				@unlink($rFile);
			}
		}
	}

	/**
	 * Report a failed cache write once per process.
	 *
	 * A silently stale cache (bad tmp/ ownership, full or missing tmpfs) keeps
	 * the panel running on outdated settings with no visible symptom, so the
	 * first failure must reach the panel log via the error handler.
	 *
	 * @param string $file Cache file path that could not be written.
	 */
	protected function warnWriteFailure(string $file) {
		static $warned = false;
		if ($warned) {
			return;
		}
		$warned = true;
		trigger_error('FileCache: cache write failed for ' . $file . ' — serving stale cache', E_USER_WARNING);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($key) {
		$file = $this->basePath . $key;

		// A concurrent delete may remove it first; only a file that stays is a failure.
		return @unlink($file) || !file_exists($file);
	}

	/**
	 * {@inheritdoc}
	 */
	public function has($key, $maxAge = null) {
		$file = $this->basePath . $key;

		if (!file_exists($file)) {
			return false;
		}

		if ($maxAge !== null) {
			$age = time() - filemtime($file);
			if ($age >= $maxAge) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function flush() {
		$files = glob($this->basePath . '*');

		if ($files === false) {
			return false;
		}

		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}

		return true;
	}

	/**
	 * Get the file path for a cache key
	 *
	 * Useful for direct file operations (e.g., file_exists checks
	 * in legacy code during migration).
	 *
	 * @param string $key Cache key
	 * @return string Full file path
	 */
	public function getPath(string $key) {
		return $this->basePath . $key;
	}

	/**
	 * Get the base directory path
	 *
	 * @return string
	 */
	public function getBasePath() {
		return $this->basePath;
	}

	/**
	 * Get modification time of a cache entry
	 *
	 * @param string $key Cache key
	 * @return int|false Unix timestamp or false if not found
	 */
	public function getAge(string $key) {
		$file = $this->basePath . $key;

		if (!file_exists($file)) {
			return false;
		}

		return time() - filemtime($file);
	}

	/**
	 * Serialize data using igbinary (if available) or PHP serialize
	 *
	 * @return string
	 */
	protected function serialize(mixed $data) {
		if ($this->useIgbinary) {
			return igbinary_serialize($data);
		}

		return serialize($data);
	}

	/**
	 * Deserialize data using igbinary (if available) or PHP unserialize
	 *
	 * Returns false on corrupted data (cache miss).
	 *
	 * @return mixed|false
	 */
	protected function deserialize(string $data) {
		if ($this->useIgbinary) {
			$result = @igbinary_unserialize($data);
			if ($result === false && $data !== igbinary_serialize(false)) {
				return false;
			}
			return $result;
		}

		$result = @unserialize($data);
		if ($result === false && $data !== serialize(false)) {
			return false;
		}
		return $result;
	}

	// ------------------------------------------------------------------
	//  Static convenience API (drop-in replacement for CoreUtilities)
	// ------------------------------------------------------------------

	/** @var self|null Singleton instance for static calls */
	private static $defaultInstance;

	/**
	 * Get the default singleton instance (uses CACHE_TMP_PATH)
	 *
	 * @return self
	 */
	private static function getDefault() {
		if (!self::$defaultInstance) {
			$tmpPath = defined('CACHE_TMP_PATH') ? CACHE_TMP_PATH : (defined('MAIN_HOME') ? MAIN_HOME . 'tmp/' : '/home/xc_vm/tmp/');
			self::$defaultInstance = new self($tmpPath);
		}
		return self::$defaultInstance;
	}

	/**
	 * The default instance's directory (CACHE_TMP_PATH), for caches kept in
	 * a directory of their own beside the others.
	 */
	public static function defaultPath(): string {
		return self::getDefault()->getBasePath();
	}

	/**
	 * Static write — drop-in for CoreUtilities::setCache()
	 *
	 * @param string $key   Cache key
	 * @param mixed  $data  Data to cache
	 * @return bool
	 */
	public static function setCache(string $key, mixed $data) {
		return self::getDefault()->set($key, $data);
	}

	/**
	 * Static read — drop-in for CoreUtilities::getCache()
	 *
	 * @param string $key     Cache key
	 * @param int|null $maxAge  Maximum age in seconds (null = no limit)
	 * @return mixed|false
	 */
	public static function getCache(string $key, ?int $maxAge = null) {
		return self::getDefault()->get($key, $maxAge);
	}

	/**
	 * Static delete — drop-in for deleting cached entries.
	 *
	 * @param string $key Cache key
	 * @return bool
	 */
	public static function delCache($key) {
		return self::getDefault()->delete($key);
	}

	/**
	 * Alias for delCache.
	 *
	 * @param string $key Cache key
	 * @return bool
	 */
	public static function deleteCache($key) {
		return self::getDefault()->delete($key);
	}
}
