<?php

/**
 * RequestManager — singleton-хранилище распарсенных параметров запроса.
 *
 * Entry point — LegacyInitializer::initCore() вызывает set().
 * Потребители используют getAll() или get().
 *
 * @package XC_VM_Core_Http
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RequestManager {
	/** @var array */
	private static $request = array();

	/**
	 * Сохраняет весь массив параметров запроса.
	 */
	public static function set(array $request): void {
		self::$request = $request;
	}

	/**
	 * Возвращает весь массив параметров запроса.
	 */
	public static function getAll(): array {
		return self::$request;
	}

	/**
	 * Возвращает значение по ключу.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get(string $key, $default = null) {
		return self::$request[$key] ?? $default;
	}

	/**
	 * Обновляет отдельный ключ.
	 */
	public static function update(string $key, $value): void {
		self::$request[$key] = $value;
	}
}
