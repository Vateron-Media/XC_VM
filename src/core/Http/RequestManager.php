<?php

/**
 * RequestManager — singleton-хранилище распарсенных параметров запроса.
 *
 * Entry point — LegacyInitializer::initCore() вызывает set().
 * Потребители используют getAll() или get().
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
