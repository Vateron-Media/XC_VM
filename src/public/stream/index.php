<?php

/**
 * Stream gateway endpoint.
 *
 * Receives nginx rewrites for /stream/{handler}.
 * Calls StreamingRequestBootstrap::init() for flood check, settings,
 * host verification and DB bootstrap, then delegates to the handler.
 *
 * @package XC_VM_Public_Stream
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

require_once MAIN_HOME . 'autoload.php';

$rHandler = $_SERVER['XC_STREAM'] ?? ($_GET['handler'] ?? null);

if ($rHandler === 'status') {
	StreamingRequestBootstrap::init('status');
	exit;
}

$rRouteMap = [
	'auth'      => MAIN_HOME . 'public/stream/auth.php',
	'key'       => MAIN_HOME . 'public/stream/key.php',
	'segment'   => MAIN_HOME . 'public/stream/segment.php',
	'live'      => MAIN_HOME . 'public/stream/live.php',
	'vod'       => MAIN_HOME . 'public/stream/vod.php',
	'timeshift' => MAIN_HOME . 'public/stream/timeshift.php',
	'thumb'     => MAIN_HOME . 'public/stream/thumb.php',
	'subtitle'  => MAIN_HOME . 'public/stream/subtitle.php',
	'rtmp'      => MAIN_HOME . 'public/stream/rtmp.php',
	'probe'     => MAIN_HOME . 'public/stream/probe.php',
];

if (!$rHandler || !isset($rRouteMap[$rHandler]) || !file_exists($rRouteMap[$rHandler])) {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rFilename = $rHandler;
StreamingRequestBootstrap::init($rHandler);
require $rRouteMap[$rHandler];
exit;
