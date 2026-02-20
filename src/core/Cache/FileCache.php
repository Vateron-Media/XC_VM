<?php

/**
 * XC_VM — File Cache Driver (igbinary)
 *
 * File-based cache using igbinary serialization. Direct replacement
 * for the inline igbinary_serialize/unserialize pattern used
 * throughout CoreUtilities and StreamingUtilities.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   BEFORE (CoreUtilities):
 *     CoreUtilities::setCache('settings', $data);
 *     $data = CoreUtilities::getCache('settings', 20);
 *
 *   BEFORE (inline):
 *     $rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
 *     file_put_contents(CACHE_TMP_PATH . 'settings', igbinary_serialize($data), LOCK_EX);
 *
 *   AFTER:
 *     $cache = new FileCache(CACHE_TMP_PATH);
 *     $cache->set('settings', $data);
 *     $data = $cache->get('settings', 20);
 *
 * ---------------------------------------------------------------
 * Storage Format:
 * ---------------------------------------------------------------
 *
 *   Files stored at: {basePath}/{key}
 *   Format: igbinary_serialize($data) — binary, compact, fast
 *   Locking: LOCK_EX on write to prevent corruption
 *   TTL: Based on file modification time (filemtime)
 *
 * ---------------------------------------------------------------
 * ServiceContainer Registration:
 * ---------------------------------------------------------------
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
 * @see CoreUtilities::setCache()
 * @see CoreUtilities::getCache()
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

        $data = file_get_contents($file);

        if ($data === false) {
            return false;
        }

        return $this->deserialize($data);
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $data, $ttl = 0) {
        $file = $this->basePath . $key;
        $serialized = $this->serialize($data);

        $result = file_put_contents($file, $serialized, LOCK_EX);

        return $result !== false;
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
     * @param string $data
     * @return mixed
     */
    protected function deserialize($data) {
        if ($this->useIgbinary) {
            return igbinary_unserialize($data);
        }

        return unserialize($data);
    }
}
