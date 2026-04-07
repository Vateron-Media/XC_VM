<?php

/**
 * Загрузчик конфигурации
 *
 * Читает config.ini и заполняет глобальный массив $_INFO.
 * Зависимости: MAIN_HOME, CONFIG_PATH (из Paths.php).
 *
 * $_INFO содержит:
 *   'username'  — логин MySQL
 *   'password'  — пароль MySQL
 *   'database'  — имя базы данных
 *   'hostname'  — хост MySQL
 *   'port'      — порт MySQL
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

global $_INFO;
$_INFO = array();

if (file_exists(MAIN_HOME . 'config')) {
    $_INFO = parse_ini_file(CONFIG_PATH . 'config.ini');
} else {
    die('no config found');
}
