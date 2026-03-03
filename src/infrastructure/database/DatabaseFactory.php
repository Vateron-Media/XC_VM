<?php

/**
 * DatabaseFactory — создание и закрытие глобального подключения к БД.
 */
class DatabaseFactory {
	/**
	 * Создаёт DatabaseHandler из config.ini и кладёт в global $db.
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
	}
}
