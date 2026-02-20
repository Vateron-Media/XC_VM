<?php

/**
 * XC_VM — Cache Interface (Contract)
 *
 * Contract for all cache drivers. Both FileCache and RedisCache
 * implement this interface, allowing swapping drivers without
 * changing consuming code.
 *
 * ---------------------------------------------------------------
 * Usage:
 * ---------------------------------------------------------------
 *
 *   // Getting a cache driver from ServiceContainer:
 *   $cache = $container->get('cache');
 *
 *   // Store data:
 *   $cache->set('settings', $data);
 *   $cache->set('servers', $data, 60); // TTL 60 seconds
 *
 *   // Retrieve data:
 *   $data = $cache->get('settings');
 *   $data = $cache->get('settings', 20); // only if < 20 seconds old
 *
 *   // Delete:
 *   $cache->delete('settings');
 *
 *   // Check existence:
 *   if ($cache->has('settings')) { ... }
 *
 * ---------------------------------------------------------------
 * Backward Compatibility:
 * ---------------------------------------------------------------
 *
 *   CoreUtilities::setCache() and CoreUtilities::getCache() will
 *   delegate to FileCache. No changes needed in existing code.
 *   New code should use the CacheInterface via ServiceContainer.
 */

interface CacheInterface {

    /**
     * Retrieve a value from cache
     *
     * @param string $key Cache key
     * @param int|null $maxAge Maximum age in seconds (null = no limit)
     * @return mixed|false Cached data or false if not found / expired
     */
    public function get($key, $maxAge = null);

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $data Data to store
     * @param int $ttl Time to live in seconds (0 = forever)
     * @return bool Success
     */
    public function set($key, $data, $ttl = 0);

    /**
     * Delete a cache entry
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete($key);

    /**
     * Check if a cache key exists (and is not expired)
     *
     * @param string $key Cache key
     * @param int|null $maxAge Maximum age in seconds (null = no limit)
     * @return bool
     */
    public function has($key, $maxAge = null);

    /**
     * Clear all cache entries
     *
     * @return bool Success
     */
    public function flush();
}
