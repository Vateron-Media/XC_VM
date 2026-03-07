<?php

/**
 * Доступ к config.ini (singleton-кеш)
 *
 * Читает и кеширует config.ini один раз за процесс.
 */
class ConfigReader {
	private static $config = null;

	/**
	 * @return array Полный массив из config.ini
	 */
	public static function getAll() {
		if (self::$config === null) {
			self::$config = parse_ini_file(CONFIG_PATH . 'config.ini');
		}
		return self::$config;
	}

	/**
	 * @param string $key Ключ конфигурации
	 * @param mixed $default Значение по умолчанию
	 * @return mixed
	 */
	public static function get($key, $default = null) {
		$config = self::getAll();
		return $config[$key] ?? $default;
	}
}
