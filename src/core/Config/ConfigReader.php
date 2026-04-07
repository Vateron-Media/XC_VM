<?php

/**
 * Доступ к config.ini (singleton-кеш)
 *
 * Читает и кеширует config.ini один раз за процесс.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
