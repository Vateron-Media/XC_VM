<?php

use XcVm\Core\Http\Router;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Infrastructure\Bootstrap\StreamingRequestBootstrap;
use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
use XcVm\Public\Controllers\Admin\AjaxController;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\Enigma2ApiController;
use XcVm\Public\Controllers\Api\EpgApiController;
use XcVm\Public\Controllers\Api\InternalApiController;
use XcVm\Public\Controllers\Api\PlayerApiController;
use XcVm\Public\Controllers\Api\PlaylistApiController;
use XcVm\Public\Controllers\Api\ResellerRestApiController;
use XcVm\Public\Controllers\Api\XPluginApiController;

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
require_once MAIN_HOME . 'vendor/autoload.php';

// 2. Разбор URL → scope + pageName

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath    = parse_url($requestUri, PHP_URL_PATH);
$urlPath    = '/' . ltrim($urlPath, '/');

$scope      = 'admin';
$pageName   = '';
$accessCode = null;
$rawScope   = null;

if (!empty($_SERVER['XC_SCOPE'])) {
    // Режим A: access code (nginx XC_SCOPE/XC_CODE)
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
} elseif (preg_match('#^/(admin|reseller)(?:/(.*))?$#', $urlPath, $m)) {
    // Режим B: прямой URL /admin/... или /reseller/...
    $scope    = $m[1];
    $pageName = isset($m[2]) ? trim($m[2], '/') : '';
} else {
    // Режим C: access code без XC_SCOPE (fallback admin)
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
if (
    $pageName === 'index' && $accessCode && in_array($scope, ['admin', 'reseller'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
) {
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

// 6. Streaming / web API (XC_SCOPE=api, XC_API={endpoint})
// Each entry: [controller class, bootstrap kind]. `::class` yields the resolved
// FQN, so `new $class()` works without a manual namespace prefix.
if (isset($rawScope) && $rawScope === 'api' && !empty($_SERVER['XC_API'])) {
    $rApiName = $_SERVER['XC_API'];
    $rApiEndpoints = [
        'player_api' => [PlayerApiController::class,   'stream'],
        'enigma2'    => [Enigma2ApiController::class,  'web'],
        'xplugin'    => [XPluginApiController::class,  'web'],
        'epg'        => [EpgApiController::class,      'web'],
        'playlist'   => [PlaylistApiController::class, 'web'],
        'internal'   => [InternalApiController::class, 'web'],
    ];

    if (!isset($rApiEndpoints[$rApiName])) {
        http_response_code(404);
        exit;
    }

    [$rControllerClass, $rBootstrapKind] = $rApiEndpoints[$rApiName];
    $rFilename = ($rApiName === 'internal') ? 'api' : $rApiName;

    if ($rBootstrapKind === 'stream') {
        StreamingRequestBootstrap::init($rFilename);
    } else {
        WebApiBootstrap::init($rFilename);
    }

    $controller = new $rControllerClass();
    register_shutdown_function([$controller, 'shutdown']);
    $controller->index();
    exit;
}

// 7. Scope bootstrap — working directory + session/functions files
$adminDir = ($scope === 'admin') ? MAIN_HOME . 'Public/Views/admin/' : MAIN_HOME . $scope . '/';
@chdir(is_dir($adminDir) ? $adminDir : MAIN_HOME);

// scope → [session file, functions file]; unknown scopes (e.g. ministra) use admin.
$rBootstrapFiles = [
    'reseller' => ['reseller_session.php', 'reseller_functions.php'],
    'player'   => ['player_session.php',   'player_functions.php'],
    'admin'    => ['admin_session_fc.php', 'admin_functions_fc.php'],
];
[$rSessionName, $rFunctionsName] = $rBootstrapFiles[$scope] ?? $rBootstrapFiles['admin'];
$sessionFile   = MAIN_HOME . 'Infrastructure/Bootstrap/' . $rSessionName;
$functionsFile = MAIN_HOME . 'Infrastructure/Bootstrap/' . $rFunctionsName;

if ($scope === 'player') {
    $noBootstrapPages = ['login'];
} else {
    $noBootstrapPages = ['login', 'setup', 'database', 'index', 'session'];
}

// 7a. Страницы без bootstrap (имеют свой)
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

// 7b. Bootstrap (session → functions → includes/admin)
if (file_exists($sessionFile)) {
    require $sessionFile;
}

if (file_exists($functionsFile)) {
    require $functionsFile;
}

// 8. Загрузка маршрутов
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

// 9. Module web boot (M-1)
if (in_array($scope, ['admin', 'reseller'], true) && class_exists(ModuleLoader::class)) {
    $moduleLoader = new ModuleLoader();
    $router->beginModuleRegistration();
    $moduleLoader->loadAll();
    $moduleLoader->bootAll(XC_Bootstrap::getContainer(), $router);
    $router->endModuleRegistration();

    $routeCollisions = $router->drainRouteCollisions();
    if (!empty($routeCollisions)) {
        $isDevelopment = defined('DEVELOPMENT') ? (bool) constant('DEVELOPMENT') : false;
        if ($isDevelopment) {
            $collisionKeys = [];
            foreach ($routeCollisions as $routeCollision) {
                $collisionKeys[] = $routeCollision['type'] . ':' . $routeCollision['key'];
            }

            $collisionMessage = 'Module route collisions detected (core priority preserved): ' . implode(', ', $collisionKeys);
            error_log($collisionMessage);

            trigger_error($collisionMessage, E_USER_WARNING);
        }
    }
}

// 10. Dispatch
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Module API routes take priority over legacy AjaxController.
// dispatchApi must run BEFORE dispatch('api') because dispatch() exits inside AjaxController.
if ($pageName === 'api' && !empty($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    if ($router->dispatchApi($action)) {
        exit;
    }
}

if ($router->dispatch($pageName, $method)) {
    exit;
}

// 11. 404
http_response_code(404);

if (function_exists('generate404')) {
    generate404();
} else {
    echo '404 Not Found';
}
