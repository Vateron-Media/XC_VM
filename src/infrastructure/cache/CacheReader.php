<?php

/**
 * CacheReader — чтение бинарных кэш-файлов (igbinary).
 *
 * @package XC_VM_Infrastructure_Cache
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CacheReader {
	/**
	 * Читает и десериализует igbinary-файл из CACHE_TMP_PATH.
	 *
	 * @param string $rCache Имя кэш-файла (без пути)
	 * @return mixed|null
	 */
	public static function get($rCache) {
		$rPath = CACHE_TMP_PATH . $rCache;
		if (!is_file($rPath)) {
			return null;
		}
		$rData = file_get_contents($rPath);
		return $rData !== false ? igbinary_unserialize($rData) : null;
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
