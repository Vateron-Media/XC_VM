<?php

/**
 * Legacy admin gateway endpoint.
 *
 * Routes /admin/{handler} compatibility calls to legacy scripts in www/admin
 * without direct nginx SCRIPT_FILENAME mapping to www/*.php.
 *
 * @package XC_VM_Public_Admin
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

$rHandler = $_SERVER['XC_ADMIN'] ?? ($_GET['handler'] ?? null);

$rRouteMap = [
	'api' => MAIN_HOME . 'www/admin/api.php',
	'index' => MAIN_HOME . 'www/admin/index.php',
	'live' => MAIN_HOME . 'www/admin/live.php',
	'proxy_api' => MAIN_HOME . 'www/admin/proxy_api.php',
	'thumb' => MAIN_HOME . 'www/admin/thumb.php',
	'timeshift' => MAIN_HOME . 'www/admin/timeshift.php',
	'vod' => MAIN_HOME . 'www/admin/vod.php',
];

if (!$rHandler || !isset($rRouteMap[$rHandler]) || !file_exists($rRouteMap[$rHandler])) {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rFilename = $rHandler;
@chdir(MAIN_HOME . 'www/admin/');
require $rRouteMap[$rHandler];
exit;
