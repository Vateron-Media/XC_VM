<?php

/**
 * XC_VM — Защита HTTP-запросов и инициализация логгера
 *
 * Выполняет:
 *   1. Flood-protection — блокировка забаненных IP
 *   2. Host verification — проверка домена через кэш allowed_domains
 *   3. Загрузка настроек ($rSettings) из файлового кэша
 *   4. Определение PHP_ERRORS
 *   5. Инициализация Logger
 *
 * Зависимости:
 *   FLOOD_TMP_PATH, CACHE_TMP_PATH, INCLUDES_PATH (из Paths.php)
 *   DEVELOPMENT (из AppConfig.php)
 *   LOGS_TMP_PATH (из Paths.php)
 *   generateError() (из ErrorHandler.php)
 *
 * Заполняет глобальные переменные:
 *   $rSettings   — массив настроек панели (из файлового кэша)
 *   $rShowErrors — флаг показа ошибок
 */

$rShowErrors = false;

if (!isset($_SERVER['argc'])) {
    $rIP = $_SERVER['REMOTE_ADDR'];
    if (empty($rIP) || !file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
        define('HOST', trim(explode(':', $_SERVER['HTTP_HOST'])[0]));

        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            $rData = file_get_contents(CACHE_TMP_PATH . 'settings');
            $rSettings = igbinary_unserialize($rData);

            if (is_array($rSettings) && file_exists(CACHE_TMP_PATH . 'allowed_domains') && $rSettings['verify_host']) {
                $rData = file_get_contents(CACHE_TMP_PATH . 'allowed_domains');
                $rAllowedDomains = igbinary_unserialize($rData);

                if (!(is_array($rAllowedDomains) && !in_array(HOST, $rAllowedDomains) && HOST != 'xc_vm') || filter_var(HOST, FILTER_VALIDATE_IP)) {
                } else {
                    generateError('INVALID_HOST');
                }
            }

            $rShowErrors = (isset($rSettings['debug_show_errors']) ? $rSettings['debug_show_errors'] : false);
        }
    } else {
        http_response_code(403);

        exit();
    }
}

define('PHP_ERRORS', $rShowErrors);

// ── Logger ─────────────────────────────────────────────────────
// After fixing all the warnings, replace DEVELOPMENT with PHP_ERRORS
require_once INCLUDES_PATH . 'libs/Logger.php';
Logger::init(
    DEVELOPMENT,
    LOGS_TMP_PATH . 'error_log.log'
);
