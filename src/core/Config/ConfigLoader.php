<?php

/**
 * XC_VM — Загрузчик конфигурации
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
 */

$_INFO = array();

if (file_exists(MAIN_HOME . 'config')) {
    $_INFO = parse_ini_file(CONFIG_PATH . 'config.ini');
} else {
    die('no config found');
}
