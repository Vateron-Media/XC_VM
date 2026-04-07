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
		$_INFO = array();

		if (file_exists(MAIN_HOME . 'config')) {
			$_INFO = parse_ini_file(CONFIG_PATH . 'config.ini');
		} else {
			die('no config found');
		}

		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		self::$instance = $db;
	}

	/**
	 * Закрывает глобальное подключение к БД.
	 */
	public static function close() {
		global $db;
		if ($db) {
			$db->close_mysql();
			$db = null;
		}
		self::$instance = null;
	}
}
