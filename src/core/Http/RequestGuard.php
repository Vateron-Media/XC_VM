<?php

/**
 * Защита HTTP-запросов и инициализация логгера
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
 *   LOGS_TMP_PATH (из Paths.php)
 *   generateError() (из ErrorHandler.php)
 *
 * Заполняет глобальные переменные:
 *   $rSettings   — массив настроек панели (из файлового кэша)
 *   $rShowErrors — флаг показа ошибок
 *
 * @package XC_VM_Core_Http
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
require_once MAIN_HOME . 'core/Logging/Logger.php';
Logger::init(
    PHP_ERRORS,
    LOGS_TMP_PATH . 'error_log.log'
);
