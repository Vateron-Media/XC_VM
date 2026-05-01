<?php

/**
 * Stream gateway endpoint.
 *
 * Receives nginx rewrites for /stream/{handler} without direct routing to
 * /www/*.php files.
 *
 * @package XC_VM_Public_Stream
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

require_once MAIN_HOME . 'autoload.php';

$rHandler = $_SERVER['XC_STREAM'] ?? ($_GET['handler'] ?? null);

$rRouteMap = [
	'auth' => MAIN_HOME . 'www/stream/auth.php',
	'key' => MAIN_HOME . 'www/stream/key.php',
	'segment' => MAIN_HOME . 'www/stream/segment.php',
	'live' => MAIN_HOME . 'www/stream/live.php',
	'vod' => MAIN_HOME . 'www/stream/vod.php',
	'timeshift' => MAIN_HOME . 'www/stream/timeshift.php',
	'thumb' => MAIN_HOME . 'www/stream/thumb.php',
	'subtitle' => MAIN_HOME . 'www/stream/subtitle.php',
	'rtmp' => MAIN_HOME . 'www/stream/rtmp.php',
	'probe' => MAIN_HOME . 'www/probe.php',
];

if ($rHandler === 'status') {
	StreamingRequestBootstrap::init('status');
	exit;
}

if (!$rHandler || !isset($rRouteMap[$rHandler]) || !file_exists($rRouteMap[$rHandler])) {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rFilename = $rHandler;
require $rRouteMap[$rHandler];
exit;
