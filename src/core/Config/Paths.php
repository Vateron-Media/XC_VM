<?php

/**
 * XC_VM — Константы путей
 *
 * Все define() для файловой структуры проекта.
 * Зависимость: MAIN_HOME должен быть определён до подключения этого файла.
 *
 * Группы:
 *   1. Базовые пути (content/, tmp/)
 *   2. Системные директории (config/, bin/, includes/, www/, crons/, signals/)
 *   3. Контент-директории (streams/, epg/, vod/, archive/, ...)
 *   4. Временные директории (cache/, flood/, logs/, ...)
 *   5. Веб-ресурсы (images/, enigma2/)
 */

// ─────────────────────────────────────────────────────────────────
//  1. Базовые пути (с защитой от повторного определения)
// ─────────────────────────────────────────────────────────────────

if (!defined('CONTENT_PATH')) {
    define('CONTENT_PATH', MAIN_HOME . 'content/');
}

if (!defined('TMP_PATH')) {
    define('TMP_PATH', MAIN_HOME . 'tmp/');
}

// ─────────────────────────────────────────────────────────────────
//  2. Системные директории
// ─────────────────────────────────────────────────────────────────

define('CONFIG_PATH',   MAIN_HOME . 'config/');
define('BIN_PATH',      MAIN_HOME . 'bin/');
define('INCLUDES_PATH', MAIN_HOME . 'includes/');
define('WWW_PATH',      MAIN_HOME . 'www/');
define('CRON_PATH',     MAIN_HOME . 'crons/');
define('SIGNALS_PATH',  MAIN_HOME . 'signals/');
define('CLI_PATH',      INCLUDES_PATH . 'cli/');

// ─────────────────────────────────────────────────────────────────
//  3. Контент-директории
// ─────────────────────────────────────────────────────────────────

define('STREAMS_PATH',  CONTENT_PATH . 'streams/');
define('EPG_PATH',      CONTENT_PATH . 'epg/');
define('VOD_PATH',      CONTENT_PATH . 'vod/');
define('ARCHIVE_PATH',  CONTENT_PATH . 'archive/');
define('CREATED_PATH',  CONTENT_PATH . 'created/');
define('DELAY_PATH',    CONTENT_PATH . 'delayed/');
define('VIDEO_PATH',    CONTENT_PATH . 'video/');
define('PLAYLIST_PATH', CONTENT_PATH . 'playlists/');

// ─────────────────────────────────────────────────────────────────
//  4. Временные директории
// ─────────────────────────────────────────────────────────────────

define('CONS_TMP_PATH',       TMP_PATH . 'opened_cons/');
define('CRONS_TMP_PATH',      TMP_PATH . 'crons/');
define('CIDR_TMP_PATH',       TMP_PATH . 'cidr/');
define('CACHE_TMP_PATH',      TMP_PATH . 'cache/');
define('STREAMS_TMP_PATH',    TMP_PATH . 'cache/streams/');
define('SERIES_TMP_PATH',     TMP_PATH . 'cache/series/');
define('LINES_TMP_PATH',      TMP_PATH . 'cache/lines/');
define('DIVERGENCE_TMP_PATH', TMP_PATH . 'divergence/');
define('FLOOD_TMP_PATH',      TMP_PATH . 'flood/');
define('PLAYER_TMP_PATH',     TMP_PATH . 'player/');
define('MINISTRA_TMP_PATH',   TMP_PATH . 'ministra/');
define('SIGNALS_TMP_PATH',    TMP_PATH . 'signals/');
define('LOGS_TMP_PATH',       TMP_PATH . 'logs/');
define('WATCH_TMP_PATH',      TMP_PATH . 'watch/');

// ─────────────────────────────────────────────────────────────────
//  5. Веб-ресурсы
// ─────────────────────────────────────────────────────────────────

define('IMAGES_PATH',    WWW_PATH . 'images/');
define('E2_IMAGES_PATH', IMAGES_PATH . 'enigma2/');
