<?php

namespace XcVm\Infrastructure\Redis;

/**
 * RedisManager — \Redis connection lifecycle management.
 *
 * Singleton that holds the active \Redis instance. Provides health-check
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
	/** @var \Redis|null Singleton instance */
	private static $instance = null;
	/** @var int Last ping health-check timestamp */
	private static $lastPingCheck = 0;

	// ──────── Singleton API ────────

	/**
	 * Get the active \Redis instance, connecting if necessary.
	 *
	 * Performs a ping health-check no more than once every 30 seconds.
	 * If the connection is dead, attempts to reconnect automatically.
	 *
	 * @return \Redis|null Active \Redis instance, or null on connection failure.
	 */
	public static function instance(): ?\Redis {
		if (is_object(self::$instance)) {
			$rNow = time();
			if ($rNow - self::$lastPingCheck > 30) {
				try {
					$rPong = self::$instance->ping();
					if ($rPong !== true && $rPong !== '+PONG' && $rPong !== 'PONG') {
						throw new \RedisException('unhealthy ping reply');
					}
					self::$lastPingCheck = $rNow;
				} catch (\RedisException $e) {
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
	 * Connect to \Redis if not already connected.
	 *
	 * @return bool True if connected, false otherwise.
	 */
	public static function ensureConnected(): bool {
		self::$instance = self::connect(self::$instance);
		return is_object(self::$instance);
	}

	/**
	 * Drop the singleton and establish a fresh, authenticated connection.
	 *
	 * phpredis can transparently reconnect a broken socket without replaying
	 * AUTH, so a previously healthy connection may suddenly answer NOAUTH.
	 * A full reconnect through \XC_VM::redis_connect() re-authenticates.
	 *
	 * @return \Redis|null Fresh instance, or null when Redis is unreachable.
	 */
	public static function reconnect(): ?\Redis {
		self::closeInstance();
		return self::instance();
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
	 * @deprecated Signals now live in {@see \XcVm\Infrastructure\Signal\SignalQueue}.
	 * Kept as a thin back-compat alias; call SignalQueue::push() directly.
	 *
	 * @param string $rKey  Signal key.
	 * @param mixed  $rData Signal payload.
	 * @return void
	 */
	public static function setSignal(string $rKey, $rData): void {
		\XcVm\Infrastructure\Signal\SignalQueue::push($rKey, $rData);
	}

	/**
	 * Connect to \Redis (low-level, non-singleton).
	 *
	 * If $rRedis is already a live connection, returns it as-is.
	 * Otherwise creates a new connection via \XC_VM::redis_connect().
	 *
	 * @param \Redis|null $rRedis Existing \Redis instance or null.
	 * @return \Redis|null Connected \Redis instance, or null on failure.
	 */
	public static function connect(?\Redis $rRedis = null): ?\Redis {
		if (is_object($rRedis)) {
			try {
				$rRedis->ping();
				return $rRedis;
			} catch (\RedisException $e) {
				$rRedis = null;
			}
		}

		try {
			$rRedis = \XC_VM::redis_connect();
			if (!is_object($rRedis)) {
				return null;
			}
			$rRedis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
			$rRedis->setOption(\Redis::OPT_TCP_KEEPALIVE, 60);
			// Validate the fresh connection: surfaces NOAUTH/WRONGPASS here
			// instead of on the first real command at a call site.
			$rRedis->ping();
			return $rRedis;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Close a \Redis connection.
	 *
	 * @param \Redis|null $rRedis \Redis instance to close.
	 * @return null Always returns null (for assignment: $redis = close($redis)).
	 */
	public static function close(?\Redis $rRedis): ?\Redis {
		if (is_object($rRedis)) {
			$rRedis->close();
		}
		return null;
	}
}
