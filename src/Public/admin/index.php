<?php

use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
/**
 * Admin gateway endpoint.
 *
 * Routes /admin/{handler} to handler scripts in public/admin/.
 * Calls WebApiBootstrap::init() for DB, settings and core init,
 * then delegates to the handler.
 *
 * @package XC_VM_Public_Admin
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

require_once MAIN_HOME . 'autoload.php';

$rHandler = $_SERVER['XC_ADMIN'] ?? ($_GET['handler'] ?? null);

if (!$rHandler || $rHandler === 'index') {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rRouteMap = [
	'api'       => MAIN_HOME . 'Public/admin/api.php',
	'live'      => MAIN_HOME . 'Public/admin/live.php',
	'proxy_api' => MAIN_HOME . 'Public/admin/proxy_api.php',
	'thumb'     => MAIN_HOME . 'Public/admin/thumb.php',
	'timeshift' => MAIN_HOME . 'Public/admin/timeshift.php',
	'vod'       => MAIN_HOME . 'Public/admin/vod.php',
];

if (!isset($rRouteMap[$rHandler]) || !file_exists($rRouteMap[$rHandler])) {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rFilename = $rHandler;
WebApiBootstrap::init($rHandler);
require $rRouteMap[$rHandler];
exit;
