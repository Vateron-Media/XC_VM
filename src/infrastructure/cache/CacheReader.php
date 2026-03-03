<?php

/**
 * CacheReader — чтение бинарных кэш-файлов (igbinary).
 */
class CacheReader {
	/**
	 * Читает и десериализует igbinary-файл из CACHE_TMP_PATH.
	 *
	 * @param string $rCache Имя кэш-файла (без пути)
	 * @return mixed|null
	 */
	public static function get($rCache) {
		$rData = (file_get_contents(CACHE_TMP_PATH . $rCache) ?: null);
		return igbinary_unserialize($rData);
	}

	/**
	 * Проверяет, включён и полностью готов ли кэш.
	 *
	 * @param array $rSettings Настройки приложения
	 * @return bool
	 */
	public static function isReady($rSettings) {
		if (!$rSettings['enable_cache']) {
			return false;
		}
		return file_exists(CACHE_TMP_PATH . 'cache_complete');
	}
}
