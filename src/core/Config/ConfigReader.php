<?php

/**
 * Доступ к серверным параметрам (singleton-кеш)
 *
 * Единственная точка доступа к серверным параметрам в приложении.
 * Делегирует чтение в C-расширение XC_VM, которое:
 *   — при первом старте PHP мигрирует config.ini → config.enc (AES-256-GCM)
 *   — возвращает параметры секции 'server' (server_id, is_lb, ...)
 *
 * Учётные данные БД и Redis расширение никогда не возвращает в PHP;
 * для подключений используйте XC_VM::db_connect() / XC_VM::redis_connect().
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ConfigReader {
	private static ?array $config = null;

	/**
	 * @return array Серверные параметры (server_id, is_lb, ...)
	 */
	public static function getAll(): array {
		if (self::$config === null) {
			$result = XC_VM::config_server();
			self::$config = is_array($result) ? $result : [];
		}
		return self::$config;
	}

	/**
	 * @param string $key     Ключ конфигурации
	 * @param mixed  $default Значение по умолчанию
	 * @return mixed
	 */
	public static function get(string $key, $default = null) {
		return self::getAll()[$key] ?? $default;
	}
}
