<?php

/**
 * XC_VM — Redis Cache Driver
 *
 * Redis-based cache driver implementing CacheInterface.
 * Wraps the phpredis extension with serialization and TTL support.
 *
 * ---------------------------------------------------------------
 * What it replaces:
 * ---------------------------------------------------------------
 *
 *   BEFORE (CoreUtilities):
 *     CoreUtilities::connectRedis();
 *     CoreUtilities::$redis->get('key');
 *     CoreUtilities::$redis->set('key', igbinary_serialize($data));
 *
 *   AFTER:
 *     $redis = new RedisCache('127.0.0.1', 6379, $password);
 *     $redis->set('key', $data, 300);
 *     $data = $redis->get('key');
 *
 * ---------------------------------------------------------------
 * ServiceContainer Registration:
 * ---------------------------------------------------------------
 *
 *   $container->set('redis', function($c) {
 *       $settings = $c->get('settings');
 *       $config   = $c->get('config');
 *       return new RedisCache(
 *           $config['hostname'],
 *           6379,
 *           $settings['redis_password']
 *       );
 *   });
 *
 * ---------------------------------------------------------------
 * Raw Redis Access:
 * ---------------------------------------------------------------
 *
 *   During migration, legacy code may need raw Redis:
 *     $rawRedis = $redisCache->getConnection();
 *     $rawRedis->multi();
 *     $rawRedis->zAdd('LIVE', $timestamp, $uuid);
 *     $rawRedis->exec();
 *
 * @see CacheInterface
 * @see CoreUtilities::connectRedis()
 */

class RedisCache implements CacheInterface {

    /** @var Redis|null phpredis connection */
    protected $redis = null;

    /** @var string Redis host */
    protected $host;

    /** @var int Redis port */
    protected $port;

    /** @var string|null Redis password */
    protected $password;

    /** @var bool Whether connection is established */
    protected $connected = false;

    /** @var string Key prefix to avoid collisions */
    protected $prefix = '';

    /**
     * @param string $host Redis host
     * @param int $port Redis port
     * @param string|null $password Redis AUTH password
     * @param string $prefix Optional key prefix
     */
    public function __construct($host = '127.0.0.1', $port = 6379, $password = null, $prefix = '') {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->prefix = $prefix;
    }

    /**
     * Establish Redis connection (lazy — called on first operation)
     *
     * @return bool
     */
    public function connect() {
        if ($this->connected && $this->redis !== null) {
            return true;
        }

        try {
            $this->redis = new \Redis();
            $this->redis->connect($this->host, $this->port);

            if ($this->password) {
                $this->redis->auth($this->password);
            }

            // Use igbinary serializer if available for consistency with FileCache
            if (defined('Redis::SERIALIZER_IGBINARY')) {
                $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
            }

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            $this->redis = null;
            $this->connected = false;
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $maxAge = null) {
        if (!$this->ensureConnected()) {
            return false;
        }

        $prefixedKey = $this->prefix . $key;
        $data = $this->redis->get($prefixedKey);

        if ($data === false) {
            return false;
        }

        // maxAge is handled by Redis TTL, not by us
        // But if caller wants to check age, we can't — Redis doesn't store creation time
        // For file-based TTL compat, we ignore maxAge here (Redis uses its own TTL)

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $data, $ttl = 0) {
        if (!$this->ensureConnected()) {
            return false;
        }

        $prefixedKey = $this->prefix . $key;

        if ($ttl > 0) {
            return $this->redis->setex($prefixedKey, $ttl, $data);
        }

        return $this->redis->set($prefixedKey, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key) {
        if (!$this->ensureConnected()) {
            return false;
        }

        $prefixedKey = $this->prefix . $key;
        $this->redis->del($prefixedKey);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has($key, $maxAge = null) {
        if (!$this->ensureConnected()) {
            return false;
        }

        $prefixedKey = $this->prefix . $key;

        return $this->redis->exists($prefixedKey) > 0;
    }

    /**
     * {@inheritdoc}
     *
     * WARNING: Flushes the ENTIRE Redis database. Use with caution.
     */
    public function flush() {
        if (!$this->ensureConnected()) {
            return false;
        }

        return $this->redis->flushDB();
    }

    // ───────────────────────────────────────────────────────────
    //  Raw Redis Access (for migration period)
    // ───────────────────────────────────────────────────────────

    /**
     * Get the raw phpredis connection
     *
     * Allows legacy code to use Redis-specific operations (sorted sets,
     * pipelines, pub/sub, etc.) that don't fit the CacheInterface.
     *
     * @return Redis|null
     */
    public function getConnection() {
        $this->ensureConnected();
        return $this->redis;
    }

    /**
     * Check if connection is alive
     *
     * @return bool
     */
    public function isConnected() {
        if (!$this->connected || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->ping() !== false;
        } catch (\Exception $e) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * Close connection
     */
    public function disconnect() {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // ignore
            }
        }

        $this->redis = null;
        $this->connected = false;
    }

    /**
     * Ensure connection is active, reconnect if needed
     *
     * @return bool
     */
    protected function ensureConnected() {
        if ($this->connected && $this->redis !== null) {
            return true;
        }

        return $this->connect();
    }

    public function __destruct() {
        $this->disconnect();
    }
}
