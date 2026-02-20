<?php

class RedisManager {
	public static function setSignal($rKey, $rData) {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_' . md5($rKey), json_encode(array($rKey, $rData)));
	}

	public static function connect($rRedis, $rConfig, $rSettings) {
		if (is_object($rRedis)) {
			return $rRedis;
		}

		try {
			$rRedis = new Redis();
			$rRedis->connect($rConfig['hostname'], 6379);
			$rRedis->auth($rSettings['redis_password']);
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
