<?php

/**
 * SettingsManager — singleton-хранилище настроек приложения.
 *
 * Entry points вызывают set(), потребители — getAll() или get().
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
}
