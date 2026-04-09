<?php

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

	public static function clearCache() {
		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}
	}
}
