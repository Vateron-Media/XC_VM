<?php

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

$rErrorCodes = array('CACHE_INCOMPLETE' => 'Cache is being generated...', 'API_IP_NOT_ALLOWED' => 'IP is not allowed to access the API.', 'ARCHIVE_DOESNT_EXIST' => 'Archive files are missing for this stream ID.', 'ASN_BLOCKED' => 'ASN has been blocked.', 'BANNED' => 'Line has been banned.', 'BLOCKED_USER_AGENT' => 'User-agent has been blocked.', 'DEVICE_NOT_ALLOWED' => 'MAG & Enigma devices are not allowed to access this.', 'DISABLED' => 'Line has been disabled.', 'DOWNLOAD_LIMIT_REACHED' => 'Reached the simultaneous download limit.', 'E2_DEVICE_LOCK_FAILED' => 'Device lock checks failed.', 'E2_DISABLED' => 'Device has been disabled.', 'E2_NO_TOKEN' => 'No token has been specified.', 'E2_TOKEN_DOESNT_MATCH' => "Token doesn't match records.", 'E2_WATCHDOG_TIMEOUT' => 'Time limit reached.', 'EMPTY_USER_AGENT' => 'Empty user-agents are disallowed.', 'EPG_DISABLED' => 'EPG has been disabled.', 'EPG_FILE_MISSING' => 'Cached EPG files are missing.', 'EXPIRED' => 'Line has expired.', 'FORCED_COUNTRY_INVALID' => 'Country does not match forced country.', 'GENERATE_PLAYLIST_FAILED' => 'Playlist failed to generate.', 'HLS_DISABLED' => 'HLS has been disabled.', 'HOSTING_DETECT' => 'Hosting server has been detected.', 'INVALID_API_PASSWORD' => 'API password is invalid.', 'INVALID_CREDENTIALS' => 'Username or password is invalid.', 'INVALID_HOST' => 'Domain name not recognised.', 'INVALID_STREAM_ID' => "Stream ID doesn't exist.", 'INVALID_TYPE_TOKEN' => "Tokens can't be used for this stream type.", 'IP_BLOCKED' => 'IP has been blocked.', 'IP_MISMATCH' => 'Current IP doesn’t match initial connection IP.', 'ISP_BLOCKED' => 'ISP has been blocked.', 'LB_TOKEN_INVALID' => 'AES Token cannot be decrypted.', 'LEGACY_EPG_DISABLED' => 'Legacy epg.php access has been disabled.', 'LEGACY_GET_DISABLED' => 'Legacy get.php access has been disabled.', 'LEGACY_PANEL_API_DISABLED' => 'Legacy panel_api.php access has been disabled.', 'LINE_CREATE_FAIL' => 'Line failed to insert into database.', 'NO_CREDENTIALS' => 'No credentials have been specified.', 'NO_TIMESTAMP' => 'No archive timestamp has been specified.', 'NO_TOKEN_SPECIFIED' => 'No AES encrypted token has been specified.', 'NOT_ENIGMA_DEVICE' => "Line isn't an enigma device.", 'NOT_IN_ALLOWED_COUNTRY' => 'Not in allowed country list.', 'NOT_IN_ALLOWED_IPS' => 'Not in allowed IP list.', 'NOT_IN_ALLOWED_UAS' => 'Not in allowed user-agent list.', 'NOT_IN_BOUQUET' => 'Line doesn’t have access to this stream ID.', 'PLAYER_API_DISABLED' => 'Player API has been disabled.', 'PROXY_DETECT' => 'Proxy has been detected.', 'PROXY_NO_API_ACCESS' => "Can't access API's via proxy.", 'RESTREAM_DETECT' => 'Restreaming has been detected.', 'STALKER_CHANNEL_MISMATCH' => "Stream ID doesn't match stalker token.", 'STALKER_DECRYPT_FAILED' => 'Failed to decrypt stalker token.', 'STALKER_INVALID_KEY' => 'Invalid stalker key.', 'STALKER_IP_MISMATCH' => "IP doesn't match stalker token.", 'STALKER_KEY_EXPIRED' => 'Stalker token has expired.', 'STREAM_OFFLINE' => 'Stream is currently offline.', 'SUBTITLE_DOESNT_EXIST' => 'Subtitle file doesn’t exist.', 'THUMBNAIL_DOESNT_EXIST' => 'Thumbnail file doesn’t exist.', 'THUMBNAILS_NOT_ENABLED' => 'Thumbnail not enabled for this stream.', 'TOKEN_ERROR' => 'AES token has incomplete data.', 'TOKEN_EXPIRED' => 'AES token has expired.', 'TS_DISABLED' => 'MPEG-TS has been disabled.', 'USER_ALREADY_CONNECTED' => 'Line already connected on a different IP.', 'USER_DISALLOW_EXT' => 'Extension is not in allowed list.', 'VOD_DOESNT_EXIST' => "VOD file doesn't exist.", 'WAIT_TIME_EXPIRED' => 'Stream start has timed out, failed to start.', 'NO_SERVERS_AVAILABLE' => 'No servers are currently available for this stream.', 'PROXY_ACCESS_DENIED' => 'You cannot access this stream directly while proxy is enabled.');

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
	generate404();
}

@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
@ini_set('default_socket_timeout', 5);

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', '/home/xc_vm/');
}

if (!defined('CONTENT_PATH')) {
	define('CONTENT_PATH', MAIN_HOME . 'content/');
}

if (!defined('TMP_PATH')) {
	define('TMP_PATH', MAIN_HOME . 'tmp/');
}

define('DEVELOPMENT', false); // It will be deleted in the future.
define('XC_VM_VERSION', '1.2.16');
define('CONFIG_PATH', MAIN_HOME . 'config/');
define('BIN_PATH', MAIN_HOME . 'bin/');
define('INCLUDES_PATH', MAIN_HOME . 'includes/');
define('WWW_PATH', MAIN_HOME . 'www/');
define('CRON_PATH', MAIN_HOME . 'crons/');
define('SIGNALS_PATH', MAIN_HOME . 'signals/');
define('PHP_BIN', BIN_PATH . 'php/bin/php');
define('FFMPEG_FONT', BIN_PATH . 'free-sans.ttf');
define('STREAMS_PATH', CONTENT_PATH . 'streams/');
define('VOD_PATH', CONTENT_PATH . 'vod/');
define('ARCHIVE_PATH', CONTENT_PATH . 'archive/');
define('VIDEO_PATH', CONTENT_PATH . 'video/');
define('EPG_PATH', CONTENT_PATH . 'epg/');
define('CLI_PATH', INCLUDES_PATH . 'cli/');
define('GEOLITE2_BIN', BIN_PATH . 'maxmind/GeoLite2-Country.mmdb');
define('GEOISP_BIN', BIN_PATH . 'maxmind/GeoIP2-ISP.mmdb');
define('CIDR_TMP_PATH', TMP_PATH . 'cidr/');
define('CONS_TMP_PATH', TMP_PATH . 'opened_cons/');
define('CACHE_TMP_PATH', TMP_PATH . 'cache/');
define('DIVERGENCE_TMP_PATH', TMP_PATH . 'divergence/');
define('FLOOD_TMP_PATH', TMP_PATH . 'flood/');
define('SIGNALS_TMP_PATH', TMP_PATH . 'signals/');
define('LOGS_TMP_PATH', TMP_PATH . 'logs/');
define('WATCH_TMP_PATH', TMP_PATH . 'watch/');
define('STREAMS_TMP_PATH', TMP_PATH . 'cache/streams/');
define('SERIES_TMP_PATH', TMP_PATH . 'cache/series/');
define('LINES_TMP_PATH', TMP_PATH . 'cache/lines/');
define('MINISTRA_TMP_PATH', TMP_PATH . 'ministra/');
define('OPENSSL_EXTRA', 'fNiu3XD448xTDa27xoY4');
define('FFMPEG_BIN_40', BIN_PATH . 'ffmpeg_bin/4.0/ffmpeg');
define('FFMPEG_BIN_43', BIN_PATH . 'ffmpeg_bin/4.3/ffmpeg');
define('FFMPEG_BIN_44', BIN_PATH . 'ffmpeg_bin/4.4/ffmpeg');
define('FFMPEG_BIN_51', BIN_PATH . 'ffmpeg_bin/5.1/ffmpeg');
define('FFMPEG_BIN_71', BIN_PATH . 'ffmpeg_bin/7.1/ffmpeg');
define('FFMPEG_BIN_80', BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg');

if (!$argc) {
	$rIP = $_SERVER['REMOTE_ADDR'];

	if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
		http_response_code(403);

		exit();
	}
}

if (file_exists(CACHE_TMP_PATH . 'settings')) {
	$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
} else {
	$rSettings = array('verify_host' => false, 'debug_show_errors' => false, 'enable_cache' => false, 'exit' => true);
}

$rShowErrors = false;

if (!$argc) {
	define('HOST', trim(explode(':', $_SERVER['HTTP_HOST'])[0]));

	if (is_array($rSettings) && $rSettings['verify_host']) {
		$rAllowedDomains = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'allowed_domains')) ?: array());

		if (!(is_array($rAllowedDomains) && 0 < count($rAllowedDomains) && !in_array(HOST, $rAllowedDomains) && HOST != 'xc_vm') || filter_var(HOST, FILTER_VALIDATE_IP)) {
		} else {
			generateerror('INVALID_HOST');
		}

		unset($rAllowedDomains);
	}

	$rShowErrors = (isset($rSettings['debug_show_errors']) ? $rSettings['debug_show_errors'] : false);
}

define('PHP_ERRORS', $rShowErrors);

// After fixing all the warnings, replace DEVELOPMENT with PHP_ERRORS
require_once INCLUDES_PATH . 'libs/Logger.php';
Logger::init(
	DEVELOPMENT,
	LOGS_TMP_PATH . 'error_log.log'
);

$rFilename = strtolower(basename(get_included_files()[0], '.php'));

if (isset($rSettings['exit']) && $rFilename != 'status') {
	generate404();
}

require_once INCLUDES_PATH . 'libs/AsyncFileOperations.php';
require_once INCLUDES_PATH . 'Database.php';

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

function generate404($rKill = true) {
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n<!-- a padding to disable MSIE and Chrome friendly error page -->";
	http_response_code(404);

	if ($rKill) {
		exit();
	}
}

function generateError($rError, $rKill = true, $rCode = null) {
	global $rErrorCodes;
	global $rSettings;

	if ($rSettings['debug_show_errors']) {
		$rErrorDescription = ($rErrorCodes[$rError] ?: '');
		$rStyle = '*{-webkit-box-sizing:border-box;box-sizing:border-box}body{padding:0;margin:0}#notfound{position:relative;height:100vh}#notfound .notfound{position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound{max-width:520px;width:100%;line-height:1.4;text-align:center}.notfound .notfound-404{position:relative;height:200px;margin:0 auto 20px;z-index:-1}.notfound .notfound-404 h1{font-family:Montserrat,sans-serif;font-size:236px;font-weight:200;margin:0;color:#211b19;text-transform:uppercase;position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound .notfound-404 h2{font-family:Montserrat,sans-serif;font-size:28px;font-weight:400;text-transform:uppercase;color:#211b19;background:#fff;padding:10px 5px;margin:auto;display:inline-block;position:absolute;bottom:0;left:0;right:0}.notfound p{font-family:Montserrat,sans-serif;font-size:14px;font-weight:300;text-transform:uppercase}@media only screen and (max-width:767px){.notfound .notfound-404 h1{font-size:148px}}@media only screen and (max-width:480px){.notfound .notfound-404{height:148px;margin:0 auto 10px}.notfound .notfound-404 h1{font-size:86px}.notfound .notfound-404 h2{font-size:16px}}';
		echo '<html><head><title>XC_VM - Debug Mode</title><link href="https://fonts.googleapis.com/css?family=Montserrat:200,400,700" rel="stylesheet"><style>' . $rStyle . '</style></head><body><div id="notfound"><div class="notfound"><div class="notfound-404"><h1>XC_VM</h1><h2>' . $rError . '</h2><br/></div><p>' . $rErrorDescription . '</p></div></div></body></html>';

		if ($rKill) {
			exit();
		}
	} else {
		if ($rKill) {
			if (!$rCode) {
				generate404();
			} else {
				http_response_code($rCode);
				exit();
			}
		}
	}
}
