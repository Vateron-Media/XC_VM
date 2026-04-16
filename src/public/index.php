<?php

/**
 * Front Controller — единая точка входа для admin/reseller/player.
 *
 * Flow: nginx → FC → scope/pageName → bootstrap → Router::dispatch() → Controller
 *
 * @see core/Http/Router.php
 * @see public/routes/admin.php
 * @see bin/nginx/conf/codes/template
 *
 * @package XC_VM_Public
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

// 1. MAIN_HOME
if (!defined('MAIN_HOME')) {
    define('MAIN_HOME', dirname(__DIR__) . '/');
}

// 1b. Autoloader
require_once MAIN_HOME . 'autoload.php';

// 2. Разбор URL → scope + pageName

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath    = parse_url($requestUri, PHP_URL_PATH);
$urlPath    = '/' . ltrim($urlPath, '/');

$scope      = 'admin';
$pageName   = '';
$accessCode = null;
$rawScope   = null;

// Режим A: access code (nginx XC_SCOPE/XC_CODE)
if (!empty($_SERVER['XC_SCOPE'])) {
    $rawScope   = $_SERVER['XC_SCOPE'];
    $accessCode = $_SERVER['XC_CODE'] ?? null;

    $scopeMap = [
        'admin'                => 'admin',
        'reseller'             => 'reseller',
        'ministra'             => 'ministra',
        'ministra/new'         => 'ministra',
        'includes/api/admin'   => 'admin',
        'includes/api/reseller' => 'reseller',
        'player'               => 'player',
    ];

    $scope = $scopeMap[$rawScope] ?? 'admin';

    if ($accessCode && preg_match('#^/' . preg_quote($accessCode, '#') . '(?:/(.*))?$#', $urlPath, $m)) {
        $pageName = isset($m[1]) ? trim($m[1], '/') : '';
    } else {
        $pageName = trim($urlPath, '/');
        $parts = explode('/', $pageName, 2);
        $pageName = $parts[1] ?? '';
    }
}
// Режим B: прямой URL /admin/... или /reseller/...
elseif (preg_match('#^/(admin|reseller)(?:/(.*))?$#', $urlPath, $m)) {
    $scope    = $m[1];
    $pageName = isset($m[2]) ? trim($m[2], '/') : '';
}
// Режим C: access code без XC_SCOPE (fallback admin)
else {
    $selfDir = basename(dirname($_SERVER['PHP_SELF'] ?? ''));

    if (!in_array($selfDir, ['admin', 'reseller'], true)) {
        $accessCode = $selfDir;
    } else {
        $scope = $selfDir;
    }

    $parts = explode('/', trim($urlPath, '/'));
    array_shift($parts);
    $pageName = implode('/', $parts);
}

$pageName = preg_replace('/\.php$/', '', $pageName);

if ($pageName === '') {
    $pageName = 'index';
}

// 3. REST API (access code type 3/4) — dispatch до редиректа и роутера
if (isset($rawScope) && in_array($rawScope, ['includes/api/admin', 'includes/api/reseller'], true)) {
	require_once MAIN_HOME . 'bootstrap.php';
	XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
	if ($rawScope === 'includes/api/admin') {
		$controller = new AdminApiController();
	} else {
		$controller = new ResellerRestApiController();
	}
	$controller->index();
	exit;
}

// 4. Redirect /CODE/ → /CODE/login (иначе relative assets ломаются)
if ($pageName === 'index' && $accessCode && in_array($scope, ['admin', 'reseller'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /' . $accessCode . '/login');
    exit;
}

if (!defined('PAGE_NAME')) {
    define('PAGE_NAME', $pageName);
}

// 5. Статические ресурсы (fallback для неверной nginx-конфигурации)
$ext = pathinfo($pageName, PATHINFO_EXTENSION);
if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'], true)) {
    http_response_code(404);
    exit;
}

// 6. Streaming API (XC_SCOPE=api, XC_API={endpoint})
if (isset($rawScope) && $rawScope === 'api' && !empty($_SERVER['XC_API'])) {
	$rApiName = $_SERVER['XC_API'];
	$rApiControllerMap = [
		'player_api' => 'PlayerApiController',
		'enigma2'    => 'Enigma2ApiController',
		'xplugin'    => 'XPluginApiController',
		'epg'        => 'EpgApiController',
		'playlist'   => 'PlaylistApiController',
		'internal'   => 'InternalApiController',
	];

	if (!isset($rApiControllerMap[$rApiName])) {
		http_response_code(404);
		exit;
	}

	$rFilename = ($rApiName === 'internal') ? 'api' : $rApiName;

	chdir(MAIN_HOME . 'www/');
	if ($rApiName === 'player_api') {
		require MAIN_HOME . 'www/stream/init.php';
	} else {
		require MAIN_HOME . 'www/init.php';
	}

	$rControllerClass = $rApiControllerMap[$rApiName];
	$controller = new $rControllerClass();
	register_shutdown_function([$controller, 'shutdown']);
	$controller->index();
	exit;
}

// 4. Bootstrap
if ($scope === 'admin') {
    $adminDir = MAIN_HOME . 'public/Views/admin/';
} else {
    $adminDir = MAIN_HOME . $scope . '/';
}
$cwdTarget = is_dir($adminDir) ? $adminDir : MAIN_HOME;
@chdir($cwdTarget);

if ($scope === 'reseller') {
    $sessionFile    = MAIN_HOME . 'infrastructure/bootstrap/reseller_session.php';
    $functionsFile  = MAIN_HOME . 'infrastructure/bootstrap/reseller_functions.php';
} elseif ($scope === 'player') {
    $sessionFile    = MAIN_HOME . 'infrastructure/bootstrap/player_session.php';
    $functionsFile  = MAIN_HOME . 'infrastructure/bootstrap/player_functions.php';
} else {
    $sessionFile    = MAIN_HOME . 'infrastructure/bootstrap/admin_session_fc.php';
    $functionsFile  = MAIN_HOME . 'infrastructure/bootstrap/admin_functions_fc.php';
}

if ($scope === 'player') {
    $noBootstrapPages = ['login'];
} else {
    $noBootstrapPages = ['login', 'setup', 'database', 'index', 'session'];
}

// 4a. Страницы без bootstrap (имеют свой)
// ВАЖНО: НЕ загружаем includes/admin.php — require_once пропустит повторную
// загрузку в legacy-файлах и переменные ($db и др.) не будут определены.
if (in_array($pageName, $noBootstrapPages, true)) {
    $router = Router::getInstance();
    require_once __DIR__ . '/routes/' . $scope . '.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($router->dispatch($pageName, $method)) {
        exit;
    }
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

// 4b. Bootstrap (session → functions → includes/admin)
if (file_exists($sessionFile)) {
    require $sessionFile;
}

if (file_exists($functionsFile)) {
    require $functionsFile;
}

// 5. Загрузка маршрутов
$router = Router::getInstance();
$routesDir = __DIR__ . '/routes/';

$routeFile = $routesDir . $scope . '.php';
if (file_exists($routeFile)) {
    require_once $routeFile;
}

// API-маршруты (общие для admin и reseller)
$apiRouteFile = $routesDir . 'api.php';
if (file_exists($apiRouteFile)) {
    require_once $apiRouteFile;
}

// 6. Dispatch
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($router->dispatch($pageName, $method)) {
    exit;
}

if ($pageName === 'api' && !empty($_REQUEST['action'])) {
    $action = $_REQUEST['action'];

    if ($router->dispatchApi($action)) {
        exit;
    }

    Response::jsonError('Unknown action: ' . $action, 404);
}

// 7. 404
http_response_code(404);

if (function_exists('generate404')) {
    generate404();
} else {
    echo '404 Not Found';
}
