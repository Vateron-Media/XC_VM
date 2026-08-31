<?php

$projectRoot = dirname(__DIR__);
$srcRoot = $projectRoot . '/src';
$flatRoot = $projectRoot;

if (file_exists($srcRoot . '/vendor/autoload.php')) {
	$appRoot = $srcRoot;
} elseif (file_exists($flatRoot . '/vendor/autoload.php')) {
	$appRoot = $flatRoot;
} else {
	throw new RuntimeException('Unable to locate vendor/autoload.php in expected project paths.');
}

$tmpRoot = __DIR__ . '/.tmp';
$binRoot = $tmpRoot . '/bin';

if (!is_dir($tmpRoot)) {
	mkdir($tmpRoot, 0775, true);
}
if (!is_dir($binRoot)) {
	mkdir($binRoot, 0775, true);
}

$fakeBinary = $binRoot . '/fake_bin.sh';
if (!file_exists($fakeBinary)) {
	file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
	chmod($fakeBinary, 0755);
}

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', $appRoot . '/');
}

if (!defined('PHP_BIN')) {
	define('PHP_BIN', PHP_BINARY);
}
// FfmpegPaths::binary() builds paths under BIN_PATH . 'ffmpeg_bin/<ver>/'. No such
// fixtures exist here, so lookups miss and the code falls back to FFMPEG_BIN_40.
if (!defined('BIN_PATH')) {
	define('BIN_PATH', $binRoot . '/');
}
if (!defined('VOD_PATH')) {
	define('VOD_PATH', $tmpRoot . '/vod/');
}
if (!is_dir(VOD_PATH)) {
	mkdir(VOD_PATH, 0775, true);
}

foreach (array('40', '71', '80') as $version) {
	$ffmpegConst = 'FFMPEG_BIN_' . $version;
	$ffprobeConst = 'FFPROBE_BIN_' . $version;
	if (!defined($ffmpegConst)) {
		define($ffmpegConst, $fakeBinary);
	}
	if (!defined($ffprobeConst)) {
		define($ffprobeConst, $fakeBinary);
	}
}

if (!isset($_FILES)) {
	$_FILES = array();
}

if (!function_exists('igbinary_serialize')) {
	function igbinary_serialize($value) {
		return serialize($value);
	}
}
if (!function_exists('igbinary_unserialize')) {
	function igbinary_unserialize($value) {
		return unserialize($value);
	}
}

require_once MAIN_HOME . 'vendor/autoload.php';
require_once __DIR__ . '/Unit/M3uParser/ExtCustomTag.php';

// Test support: in-memory SQLite harness for DB-touching repositories/services.
require_once __DIR__ . '/Support/TestDb.php';
