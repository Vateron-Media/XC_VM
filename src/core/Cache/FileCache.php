<?php

/**
 * File Cache Driver (igbinary)
 *
 * File-based cache using igbinary serialization.
 *
 * Storage Format:
 *
 *   Files stored at: {basePath}/{key}
 *   Format: igbinary_serialize($data) — binary, compact, fast
 *   Locking: LOCK_EX on write to prevent corruption
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
    public function __construct($basePath) {
        $this->basePath = rtrim($basePath, '/') . '/';
        $this->useIgbinary = function_exists('igbinary_serialize');

        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0755, true);
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
        $serialized = $this->serialize($data);

        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $serialized, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        return @rename($tmp, $file);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key) {
        $file = $this->basePath . $key;

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
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
    public function getPath($key) {
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
    public function getAge($key) {
        $file = $this->basePath . $key;

        if (!file_exists($file)) {
            return false;
        }

        return time() - filemtime($file);
    }

    /**
     * Serialize data using igbinary (if available) or PHP serialize
     *
     * @param mixed $data
     * @return string
     */
    protected function serialize($data) {
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
     * @param string $data
     * @return mixed|false
     */
    protected function deserialize($data) {
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
            self::$defaultInstance = new self(CACHE_TMP_PATH);
        }
        return self::$defaultInstance;
    }

    /**
     * Static write — drop-in for CoreUtilities::setCache()
     *
     * @param string $key   Cache key
     * @param mixed  $data  Data to cache
     * @return bool
     */
    public static function setCache($key, $data) {
        return self::getDefault()->set($key, $data);
    }

    /**
     * Static read — drop-in for CoreUtilities::getCache()
     *
     * @param string $key     Cache key
     * @param int|null $maxAge  Maximum age in seconds (null = no limit)
     * @return mixed|false
     */
    public static function getCache($key, $maxAge = null) {
        return self::getDefault()->get($key, $maxAge);
    }
}
