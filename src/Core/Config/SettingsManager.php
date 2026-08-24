<?php

namespace XcVm\Core\Config;

/**
 * SettingsManager — singleton-хранилище настроек приложения.
 *
 * Entry points вызывают set(), потребители — getAll() или get().
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsManager {
	/** @var array */
	private static $settings = array();

	/**
	 * Сохраняет весь массив настроек.
	 */
	public static function set(array $settings): void {
		self::$settings = $settings;
	}

	/**
	 * Возвращает весь массив настроек.
	 */
	public static function getAll(): array {
		return self::$settings;
	}

	/**
	 * Возвращает значение по ключу.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get(string $key, $default = null) {
		return self::$settings[$key] ?? $default;
	}

	/**
	 * Обновляет отдельный ключ настроек.
	 */
	public static function update(string $key, $value): void {
		self::$settings[$key] = $value;
	}

	/**
	 * Проверяет наличие ключа в настройках.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function has(string $key): bool {
		return array_key_exists($key, self::$settings);
	}

	/**
	 * Возвращает значение как bool.
	 *
	 * Повторяет PHP-truthiness существующих проверок `if (getAll()['key'])`:
	 * '0' и '' → false, '1' и любое непустое значение → true.
	 *
	 * @param string $key
	 * @param bool   $default Значение, если ключ отсутствует.
	 * @return bool
	 */
	public static function getBool(string $key, bool $default = false): bool {
		return array_key_exists($key, self::$settings) ? (bool) self::$settings[$key] : $default;
	}

	/**
	 * Возвращает значение как int.
	 *
	 * @param string $key
	 * @param int    $default
	 * @return int
	 */
	public static function getInt(string $key, int $default = 0): int {
		return array_key_exists($key, self::$settings) ? (int) self::$settings[$key] : $default;
	}

	/**
	 * Возвращает значение как строку.
	 *
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public static function getString(string $key, string $default = ''): string {
		return array_key_exists($key, self::$settings) ? (string) self::$settings[$key] : $default;
	}

	/**
	 * Возвращает значение как массив.
	 *
	 * JSON-поля декодируются в массивы ещё в SettingsRepository, поэтому здесь
	 * достаточно проверить тип; для скаляров/null возвращается $default.
	 *
	 * @param string $key
	 * @param array  $default
	 * @return array
	 */
	public static function getArray(string $key, array $default = array()): array {
		$rValue = self::$settings[$key] ?? null;
		return is_array($rValue) ? $rValue : $default;
	}

	/**
	 * Delete the cached settings file so the next read reloads from source.
	 *
	 * @return void
	 */
	public static function clearCache() {
		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}
	}
}
