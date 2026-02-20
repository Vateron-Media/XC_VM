<?php

/**
 * XC_VM — Пути к бинарным файлам
 *
 * FFmpeg, FFprobe, GeoIP, PHP CLI и другие исполняемые файлы.
 * Зависимость: BIN_PATH должен быть определён (из Paths.php).
 */

// ── PHP & утилиты ─────────────────────────────────────────────
define('PHP_BIN',     BIN_PATH . 'php/bin/php');
define('YOUTUBE_BIN', BIN_PATH . 'yt-dlp');
define('FFMPEG_FONT', BIN_PATH . 'free-sans.ttf');

// ── GeoIP базы данных ─────────────────────────────────────────
define('GEOLITE2_BIN',  BIN_PATH . 'maxmind/GeoLite2-Country.mmdb');
define('GEOLITE2C_BIN', BIN_PATH . 'maxmind/GeoLite2-City.mmdb');
define('GEOISP_BIN',    BIN_PATH . 'maxmind/GeoIP2-ISP.mmdb');

// ── FFmpeg (по версиям) ───────────────────────────────────────
define('FFMPEG_BIN_40', BIN_PATH . 'ffmpeg_bin/4.0/ffmpeg');
define('FFMPEG_BIN_43', BIN_PATH . 'ffmpeg_bin/4.3/ffmpeg');
define('FFMPEG_BIN_44', BIN_PATH . 'ffmpeg_bin/4.4/ffmpeg');
define('FFMPEG_BIN_51', BIN_PATH . 'ffmpeg_bin/5.1/ffmpeg');
define('FFMPEG_BIN_71', BIN_PATH . 'ffmpeg_bin/7.1/ffmpeg');
define('FFMPEG_BIN_80', BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg');

// ── FFprobe (по версиям) ──────────────────────────────────────
define('FFPROBE_BIN_40', BIN_PATH . 'ffmpeg_bin/4.0/ffprobe');
define('FFPROBE_BIN_43', BIN_PATH . 'ffmpeg_bin/4.3/ffprobe');
define('FFPROBE_BIN_44', BIN_PATH . 'ffmpeg_bin/4.4/ffprobe');
define('FFPROBE_BIN_51', BIN_PATH . 'ffmpeg_bin/5.1/ffprobe');
define('FFPROBE_BIN_71', BIN_PATH . 'ffmpeg_bin/7.1/ffprobe');
define('FFPROBE_BIN_80', BIN_PATH . 'ffmpeg_bin/8.0/ffprobe');
