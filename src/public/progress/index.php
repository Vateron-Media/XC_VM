<?php

/**
 * Legacy progress gateway endpoint.
 *
 * Routes /progress compatibility calls to legacy www/progress.php without
 * direct nginx SCRIPT_FILENAME mapping to www/*.php.
 *
 * @package XC_VM_Public_Progress
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

$rTarget = MAIN_HOME . 'www/progress.php';

if (!file_exists($rTarget)) {
	http_response_code(404);
	echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
	exit;
}

$rFilename = 'progress';
@chdir(MAIN_HOME . 'www/');
require $rTarget;
exit;
