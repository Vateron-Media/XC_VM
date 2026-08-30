<?php


/**
 * Пути к бинарным файлам
 *
 * FFmpeg, FFprobe, GeoIP, PHP CLI и другие исполняемые файлы.
 * Зависимость: BIN_PATH должен быть определён (из Paths.php).
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

// ── PHP & утилиты ─────────────────────────────────────────────
define('PHP_BIN',     BIN_PATH . 'php/bin/php');
define('YOUTUBE_BIN', BIN_PATH . 'yt-dlp');
define('FFMPEG_FONT', BIN_PATH . 'free-sans.ttf');

// ── GeoIP базы данных ─────────────────────────────────────────
define('GEOLITE2_BIN',  BIN_PATH . 'maxmind/GeoLite2-Country.mmdb');
define('GEOLITE2C_BIN', BIN_PATH . 'maxmind/GeoLite2-City.mmdb');
define('GEOISP_BIN',    BIN_PATH . 'maxmind/GeoIP2-ISP.mmdb');

// ── FFmpeg/FFprobe — legacy 4.0 "DTS" build anchor ────────────
// Only the 4.0 build is pinned as a constant: it is the legacy/DTS-safe binary
// (StreamProcess `-nofix_dts`, RecordCommand, and the FfmpegPaths fallback). Every
// other version is resolved dynamically from bin/ffmpeg_bin/<version>/ by
// FfmpegPaths (paths) and FfmpegBinaries (discovery + capabilities) — no more
// per-version constants to keep in sync with what actually ships in the folder.
define('FFMPEG_BIN_40',  BIN_PATH . 'ffmpeg_bin/4.0/ffmpeg');
define('FFPROBE_BIN_40', BIN_PATH . 'ffmpeg_bin/4.0/ffprobe');
