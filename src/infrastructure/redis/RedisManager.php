<?php

/**
 * RedisManager — управление жизненным циклом Redis-подключения.
 *
 * Singleton хранит активный экземпляр Redis.
 */
class RedisManager {
	/** @var Redis|null Singleton-экземпляр */
	private static $instance = null;

	// ──────── Singleton API ────────

	/**
	 * Возвращает активный Redis, при необходимости подключаясь.
	 * @return Redis|null
	 */
	public static function instance() {
		if (!is_object(self::$instance)) {
			self::ensureConnected();
		}
		return self::$instance;
	}

	/**
	 * Подключается к Redis, если ещё нет соединения.
	 * @return bool
	 */
	public static function ensureConnected() {
		self::$instance = self::connect(self::$instance, ConfigReader::getAll(), SettingsManager::getAll());
		return is_object(self::$instance);
	}

	/**
	 * Закрывает singleton-подключение.
	 * @return bool
	 */
	public static function closeInstance() {
		self::$instance = self::close(self::$instance);
		return true;
	}

	/**
	 * Проверяет, подключён ли singleton.
	 * @return bool
	 */
	public static function isConnected() {
		return is_object(self::$instance);
	}

	// ──────── Low-level API (без singleton) ────────

	public static function setSignal($rKey, $rData) {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
	}

	public static function connect($rRedis, $rConfig, $rSettings) {
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

	public static function close($rRedis) {
		if (is_object($rRedis)) {
			$rRedis->close();
		}
		return null;
	}
}
