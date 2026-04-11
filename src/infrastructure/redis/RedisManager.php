<?php

/**
 * RedisManager — Redis connection lifecycle management.
 *
 * Singleton that holds the active Redis instance. Provides health-check
 * via ping (debounced to 30s), auto-reconnect on failure, and low-level
 * connect/close helpers for non-singleton usage.
 *
 * @package XC_VM_Infrastructure_Redis
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RedisManager {
	/** @var Redis|null Singleton instance */
	private static $instance = null;
	/** @var int Last ping health-check timestamp */
	private static $lastPingCheck = 0;

	// ──────── Singleton API ────────

	/**
	 * Get the active Redis instance, connecting if necessary.
	 *
	 * Performs a ping health-check no more than once every 30 seconds.
	 * If the connection is dead, attempts to reconnect automatically.
	 *
	 * @return Redis|null Active Redis instance, or null on connection failure.
	 */
	public static function instance(): ?Redis {
		if (is_object(self::$instance)) {
			$rNow = time();
			if ($rNow - self::$lastPingCheck > 30) {
				try {
					self::$instance->ping();
					self::$lastPingCheck = $rNow;
				} catch (RedisException $e) {
					self::$instance = null;
				}
			}
		}
		if (!is_object(self::$instance)) {
			self::ensureConnected();
			self::$lastPingCheck = time();
		}
		return self::$instance;
	}

	/**
	 * Connect to Redis if not already connected.
	 *
	 * Uses ConfigReader and SettingsManager for hostname and password.
	 *
	 * @return bool True if connected, false otherwise.
	 */
	public static function ensureConnected(): bool {
		self::$instance = self::connect(self::$instance, ConfigReader::getAll(), SettingsManager::getAll());
		return is_object(self::$instance);
	}

	/**
	 * Close the singleton connection.
	 *
	 * @return bool Always returns true.
	 */
	public static function closeInstance(): bool {
		self::$instance = self::close(self::$instance);
		return true;
	}

	/**
	 * Check whether the singleton is connected.
	 *
	 * @return bool True if connected.
	 */
	public static function isConnected(): bool {
		return is_object(self::$instance);
	}



	/**
	 * Write a signal to the filesystem cache.
	 *
	 * Stores a JSON-encoded [key, data] pair in SIGNALS_TMP_PATH.
	 *
	 * @param string $rKey  Signal key.
	 * @param mixed  $rData Signal payload.
	 * @return void
	 */
	public static function setSignal(string $rKey, $rData): void {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
	}

	/**
	 * Connect to Redis (low-level, non-singleton).
	 *
	 * If $rRedis is already a live connection, returns it as-is.
	 * Otherwise creates a new connection using hostname from $rConfig
	 * and password from $rSettings.
	 *
	 * @param Redis|null $rRedis   Existing Redis instance or null.
	 * @param array      $rConfig   Config array (must contain 'hostname').
	 * @param array      $rSettings Settings array (must contain 'redis_password').
	 * @return Redis|null Connected Redis instance, or null on failure.
	 */
	public static function connect(?Redis $rRedis, array $rConfig, array $rSettings): ?Redis {
		if (is_object($rRedis)) {
			try {
				$rRedis->ping();
				return $rRedis;
			} catch (RedisException $e) {
				$rRedis = null;
			}
		}

		if (empty($rConfig['hostname']) || empty($rSettings['redis_password'])) {
			return null;
		}

		try {
			$rRedis = new Redis();
			$rRedis->connect($rConfig['hostname'], 6379, 2.0);
			$rRedis->auth($rSettings['redis_password']);
			$rRedis->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
			$rRedis->setOption(Redis::OPT_TCP_KEEPALIVE, 60);
			return $rRedis;
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Close a Redis connection.
	 *
	 * @param Redis|null $rRedis Redis instance to close.
	 * @return null Always returns null (for assignment: $redis = close($redis)).
	 */
	public static function close(?Redis $rRedis): ?Redis {
		if (is_object($rRedis)) {
			$rRedis->close();
		}
		return null;
	}
}
