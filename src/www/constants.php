<?php

/**
 * XC_VM — Фасад конфигурации (constants.php)
 *
 * Этот файл остаётся единой точкой подключения для обратной совместимости.
 * Вся логика разнесена по модулям:
 *
 *   core/Error/ErrorCodes.php      — массив $rErrorCodes
 *   core/Error/ErrorHandler.php    — generateError(), generate404()
 *   core/Config/Paths.php          — константы путей (*_PATH, *_TMP_PATH)
 *   core/Config/AppConfig.php      — версия, Git, флаги приложения
 *   core/Config/Binaries.php       — пути к FFmpeg, FFprobe, GeoIP, PHP CLI
 *   core/Config/ConfigLoader.php   — загрузка $_INFO из config.ini
 *   core/Http/RequestGuard.php     — flood-protection, host verify, Logger init
 *
 * Порядок загрузки имеет значение:
 *   1. autoload.php     → MAIN_HOME + XC_Autoloader
 *   2. ErrorCodes       → $rErrorCodes (нужен для ErrorHandler)
 *   3. ErrorHandler     → generate404() (нужен для access guard ниже)
 *   4. Paths            → все *_PATH константы (нужны для Binaries, ConfigLoader)
 *   5. AppConfig        → XC_VM_VERSION, DEVELOPMENT, etc.
 *   6. Binaries         → FFMPEG_*, FFPROBE_*, GeoIP, PHP_BIN
 *   7. ConfigLoader     → $_INFO из config.ini
 *   8. RequestGuard     → flood/host check + Logger::init()
 */

// ─────────────────────────────────────────────────────────────────
//  1. Автозагрузчик (определяет MAIN_HOME, регистрирует spl_autoload)
// ─────────────────────────────────────────────────────────────────

require_once dirname(__DIR__) . '/autoload.php';

// ─────────────────────────────────────────────────────────────────
//  2. Ошибки (нужны до access guard)
// ─────────────────────────────────────────────────────────────────

require_once MAIN_HOME . 'core/Error/ErrorCodes.php';
require_once MAIN_HOME . 'core/Error/ErrorHandler.php';

// ─────────────────────────────────────────────────────────────────
//  3. Защита от прямого доступа
// ─────────────────────────────────────────────────────────────────

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    generate404();
}

// ─────────────────────────────────────────────────────────────────
//  4. Глобальные настройки PHP
// ─────────────────────────────────────────────────────────────────

@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
@ini_set('default_socket_timeout', 5);

// ─────────────────────────────────────────────────────────────────
//  5. Константы: пути, приложение, бинарники
// ─────────────────────────────────────────────────────────────────

require_once MAIN_HOME . 'core/Config/Paths.php';
require_once MAIN_HOME . 'core/Config/AppConfig.php';
require_once MAIN_HOME . 'core/Config/Binaries.php';

// ─────────────────────────────────────────────────────────────────
//  6. Загрузка конфигурации из config.ini
// ─────────────────────────────────────────────────────────────────

require_once MAIN_HOME . 'core/Config/ConfigLoader.php';

// ─────────────────────────────────────────────────────────────────
//  7. HTTP: flood protection, host verification, Logger init
// ─────────────────────────────────────────────────────────────────

require_once MAIN_HOME . 'core/Http/RequestGuard.php';
