<?php

/**
 * XC_VM - Stream initialization (stream/init.php)
 *
 * Bootstrap for streaming endpoints (live, vod, timeshift, thumb, subtitle, rtmp, portal).
 * High-load path - does not load config.ini, does not connect to DB directly.
 *
 * Removed duplicates (now from core/):
 *   - $rErrorCodes          -> core/Error/ErrorCodes.php
 *   - generate404/Error()   -> core/Error/ErrorHandler.php
 *   - ~40 define()          -> core/Config/Paths.php + AppConfig.php + Binaries.php
 *
 * Unique logic (kept here):
 *   - Flood/host checks via $argc (not isset($_SERVER["argc"]))
 *   - $rSettings from cache with stream-specific defaults (exit, enable_cache)
 *   - Routing switch($rFilename) by endpoint type
 */

// -----------------------------------------------------------------
//  1. Autoloader + shared modules
// -----------------------------------------------------------------

require_once dirname(dirname(__DIR__)) . '/autoload.php';

require_once MAIN_HOME . 'core/Error/ErrorCodes.php';     // $rErrorCodes
require_once MAIN_HOME . 'core/Error/ErrorHandler.php';   // generateError(), generate404()
require_once MAIN_HOME . 'core/Config/Paths.php';         // *_PATH, *_TMP_PATH
require_once MAIN_HOME . 'core/Config/AppConfig.php';     // XC_VM_VERSION, DEVELOPMENT, etc.
require_once MAIN_HOME . 'core/Config/Binaries.php';      // FFMPEG_*, PHP_BIN, GeoIP

// -----------------------------------------------------------------
//  2. Polyfill getallheaders()
// -----------------------------------------------------------------

if (!function_exists('getallheaders')) {
	function getallheaders() {
		$headers = array();

		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) == 'HTTP_') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}

		return $headers;
	}
}

// -----------------------------------------------------------------
//  3. Direct access guard
// -----------------------------------------------------------------

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
	generate404();
}

@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
@ini_set('default_socket_timeout', 5);

// -----------------------------------------------------------------
//  4. Flood protection (via $argc - stream-specific)
// -----------------------------------------------------------------

if (!$argc) {
	$rIP = $_SERVER['REMOTE_ADDR'];

	if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
		http_response_code(403);

		exit();
	}
}

// -----------------------------------------------------------------
//  5. Settings from file cache (stream-specific defaults)
// -----------------------------------------------------------------

if (file_exists(CACHE_TMP_PATH . 'settings')) {
	$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
} else {
	$rSettings = array('verify_host' => false, 'debug_show_errors' => false, 'enable_cache' => false, 'exit' => true);
}

$rShowErrors = false;

// -----------------------------------------------------------------
//  6. Host verification (via $argc - stream-specific)
// -----------------------------------------------------------------

if (!$argc) {
	define('HOST', trim(explode(':', $_SERVER['HTTP_HOST'])[0]));

	if (is_array($rSettings) && $rSettings['verify_host']) {
		$rAllowedDomains = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'allowed_domains')) ?: array());

		if (!(is_array($rAllowedDomains) && 0 < count($rAllowedDomains) && !in_array(HOST, $rAllowedDomains) && HOST != 'xc_vm') || filter_var(HOST, FILTER_VALIDATE_IP)) {
		} else {
			generateError('INVALID_HOST');
		}

		unset($rAllowedDomains);
	}

	$rShowErrors = (isset($rSettings['debug_show_errors']) ? $rSettings['debug_show_errors'] : false);
}

define('PHP_ERRORS', $rShowErrors);

// -----------------------------------------------------------------
//  7. Logger
// -----------------------------------------------------------------

// After fixing all the warnings, replace DEVELOPMENT with PHP_ERRORS
require_once INCLUDES_PATH . 'libs/Logger.php';
Logger::init(
	DEVELOPMENT,
	LOGS_TMP_PATH . 'error_log.log'
);

// -----------------------------------------------------------------
//  8. Stream endpoint routing
// -----------------------------------------------------------------

$rFilename = strtolower(basename(get_included_files()[0], '.php'));

if (isset($rSettings['exit']) && $rFilename != 'status') {
	generate404();
}

require_once INCLUDES_PATH . 'libs/AsyncFileOperations.php';
require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';

switch ($rFilename) {
	case 'probe':
	case 'player_api':
		require_once INCLUDES_PATH . 'StreamingUtilities.php';
		StreamingUtilities::$rSettings = $rSettings;
		StreamingUtilities::$rAccess = $rFilename;
		StreamingUtilities::init(false);
		$db = &StreamingUtilities::$db;

		if ($rSettings['enable_cache']) {
		} else {
			StreamingUtilities::connectDatabase();
			$db = &StreamingUtilities::$db;
		}

		break;

	case 'live':
	case 'thumb':
	case 'subtitle':
	case 'timeshift':
	case 'vod':
	case 'status':
		require_once INCLUDES_PATH . 'StreamingUtilities.php';
		StreamingUtilities::$rSettings = $rSettings;
		StreamingUtilities::$rAccess = $rFilename;
		StreamingUtilities::init(false);
		$db = &StreamingUtilities::$db;

		break;

	case 'rtmp':
	case 'portal':
		require_once INCLUDES_PATH . 'StreamingUtilities.php';
		StreamingUtilities::$rSettings = $rSettings;
		StreamingUtilities::$rAccess = $rFilename;
		StreamingUtilities::init();
		$db = &StreamingUtilities::$db;

		break;
}
