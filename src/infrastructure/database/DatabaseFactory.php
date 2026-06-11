<?php

/**
 * DatabaseFactory — создание, хранение и закрытие глобального подключения к БД.
 *
 * Singleton-реестр: entry points вызывают set($db), потребители — get().
 *
 * @package XC_VM_Infrastructure_Database
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DatabaseFactory {
	/** @var DatabaseHandler|null */
	private static $instance = null;

	/**
	 * Сохраняет экземпляр DatabaseHandler в singleton-реестре.
	 */
	public static function set(DatabaseHandler $db): void {
		self::$instance = $db;
	}

	/**
	 * Возвращает текущий DatabaseHandler.
	 */
	public static function get(): ?DatabaseHandler {
		return self::$instance;
	}

	/**
	 * Создаёт DatabaseHandler из config.ini и кладёт в global $db + singleton.
	 */
	public static function connect() {
		global $db;

		if (is_object($db) && method_exists($db, 'ping') && $db->ping()) {
			self::$instance = $db;
			return;
		}

		if (is_object($db)) {
			$db->close_mysql();
			$db = null;
		}

		$db = new DatabaseHandler();
		self::$instance = $db;
	}

	/**
	 * Закрывает глобальное подключение к БД.
	 */
	public static function close() {
		global $db;
		if (is_object($db)) {
			$db->close_mysql();
			$db = null;
		}
		self::$instance = null;
	}
}
