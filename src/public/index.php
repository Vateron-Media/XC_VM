<?php

/**
 * XC_VM — Front Controller (Phase 6.2)
 *
 * Единая точка входа для admin/reseller.
 * Заменяет паттерн «каждая страница — отдельный PHP-файл» на
 * централизованный dispatch через Router.
 *
 * ──────────────────────────────────────────────────────────────────
 * Как это работает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   1. nginx перенаправляет запрос сюда (через access code или напрямую)
 *   2. Определяется scope (admin|reseller) и pageName:
 *      — Через access code: nginx передаёт XC_SCOPE и XC_CODE через fastcgi_param
 *      — Напрямую: URL /admin/... или /reseller/... → scope из пути
 *   3. Загружается bootstrap (session → functions → includes/admin)
 *   4. Загружаются маршруты из public/routes/{scope}.php
 *   5. Router пытается dispatch(pageName, method):
 *      — Если маршрут зарегистрирован → вызывает Controller
 *      — Если нет → fallback: include legacy файл admin/{pageName}.php
 *
 * ──────────────────────────────────────────────────────────────────
 * Access Codes (bin/nginx/conf/codes/):
 * ──────────────────────────────────────────────────────────────────
 *
 *   Панель доступна не по /admin/, а по /RANDOMCODE/.
 *   Коды генерируются из шаблона bin/nginx/conf/codes/template:
 *
 *     location ^~ /#CODE# {
 *         alias /home/xc_vm/#TYPE#;
 *         try_files $uri $uri.html $uri/ @extensionless-php;
 *         ...
 *     }
 *
 *   Типы кодов (#TYPE#):
 *     0 → admin           3 → includes/api/admin
 *     1 → reseller         4 → includes/api/reseller
 *     2 → ministra         5 → ministra/new
 *                          6 → player
 *
 *   Для Front Controller шаблон передаёт scope через fastcgi_param:
 *     fastcgi_param XC_SCOPE admin;   (или reseller)
 *     fastcgi_param XC_CODE  xxxxxx;  (access code)
 *
 * ──────────────────────────────────────────────────────────────────
 * Активация (будущий шаблон template):
 * ──────────────────────────────────────────────────────────────────
 *
 *   location ^~ /#CODE# {
 *       alias /home/xc_vm/#TYPE#;
 *       index index.php;
 *       try_files $uri $uri.html $uri/ @fc_#CODE#;
 *       location ~ \.php$ { ... }
 *   }
 *   location @fc_#CODE# {
 *       fastcgi_param XC_SCOPE #TYPE#;
 *       fastcgi_param XC_CODE  #CODE#;
 *       fastcgi_param SCRIPT_FILENAME /home/xc_vm/public/index.php;
 *       fastcgi_pass php;
 *       include fastcgi_params;
 *   }
 *
 * ──────────────────────────────────────────────────────────────────
 * Обратная совместимость:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Пока nginx шаблон не обновлён — старые URL через access codes
 *   (/MYCODE/dashboard → admin/dashboard.php) продолжают работать.
 *   Front Controller активируется только после обновления template.
 *
 *   Fallback-механизм: если Router не знает маршрут, контроллер
 *   подключает legacy PHP-файл напрямую (include admin/dashboard.php).
 *
 * @see core/Http/Router.php
 * @see domain/Auth/CodeRepository.php
 * @see bin/nginx/conf/codes/template
 * @see public/routes/admin.php
 * @see public/routes/api.php
 */

// ─────────────────────────────────────────────────────────────────
//  1. Определяем MAIN_HOME до всего остального
// ─────────────────────────────────────────────────────────────────

if (!defined('MAIN_HOME')) {
    // public/index.php → 1 уровень до корня (src/)
    define('MAIN_HOME', dirname(__DIR__) . '/');
}

// ─────────────────────────────────────────────────────────────────
//  1a. Debug: ловим фатальные ошибки, которые убивают рендеринг
// ─────────────────────────────────────────────────────────────────

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Вывод ошибки для диагностики (убрать после отладки)
        echo '<div style="background:#c00;color:#fff;padding:15px;margin:10px;font-family:monospace;position:fixed;bottom:0;left:0;right:0;z-index:99999;">';
        echo '<b>FATAL ERROR:</b> ' . htmlspecialchars($error['message']);
        echo ' in <b>' . htmlspecialchars($error['file']) . '</b>';
        echo ' on line <b>' . $error['line'] . '</b>';
        echo '</div>';
    }
});

// ─────────────────────────────────────────────────────────────────
//  2. Разбор URL → scope + pageName
// ─────────────────────────────────────────────────────────────────
//
//  Два режима:
//
//  A) Access Code (основной): nginx передаёт XC_SCOPE и XC_CODE
//     URL: /MYCODE/dashboard → scope='admin', pageName='dashboard'
//     nginx alias направляет в правильную директорию
//
//  B) Прямой доступ (fallback/dev): URL содержит /admin/ или /reseller/
//     URL: /admin/dashboard → scope='admin', pageName='dashboard'
// ─────────────────────────────────────────────────────────────────

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath    = parse_url($requestUri, PHP_URL_PATH);
$urlPath    = '/' . ltrim($urlPath, '/');

$scope      = 'admin';
$pageName   = '';
$accessCode = null;

// Режим A: access code — nginx передаёт scope через fastcgi_param
if (!empty($_SERVER['XC_SCOPE'])) {
    // Scope из nginx: 'admin', 'reseller', 'ministra', etc.
    $rawScope   = $_SERVER['XC_SCOPE'];
    $accessCode = $_SERVER['XC_CODE'] ?? null;

    // Маппинг #TYPE# → scope для маршрутизации
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

    // pageName: убираем access code prefix из URL
    // URL: /MYCODE/dashboard → после strip code → 'dashboard'
    if ($accessCode && preg_match('#^/' . preg_quote($accessCode, '#') . '(?:/(.*))?$#', $urlPath, $m)) {
        $pageName = isset($m[1]) ? trim($m[1], '/') : '';
    } else {
        // Fallback: последний сегмент
        $pageName = trim($urlPath, '/');
        // Убираем первый сегмент (это access code)
        $parts = explode('/', $pageName, 2);
        $pageName = $parts[1] ?? '';
    }
}
// Режим B: прямой URL /admin/... или /reseller/...
elseif (preg_match('#^/(admin|reseller)(?:/(.*))?$#', $urlPath, $m)) {
    $scope    = $m[1];
    $pageName = isset($m[2]) ? trim($m[2], '/') : '';
}
// Режим C: access code без XC_SCOPE (старый template)
// Определяем scope через PHP_SELF (как делает AuthRepository::getCurrentCode)
else {
    $selfDir = basename(dirname($_SERVER['PHP_SELF'] ?? ''));

    // Если PHP_SELF содержит код (не 'admin'/'reseller'),
    // нужна дополнительная информация для определения scope.
    // Без XC_SCOPE предполагаем admin (безопасный fallback).
    if (!in_array($selfDir, ['admin', 'reseller'], true)) {
        $accessCode = $selfDir;
    } else {
        $scope = $selfDir;
    }

    // Извлекаем pageName из URI
    $parts = explode('/', trim($urlPath, '/'));
    // Первый сегмент — это access code или scope, остальное — pageName
    array_shift($parts);
    $pageName = implode('/', $parts);
}

// Убираем расширение .php если есть
$pageName = preg_replace('/\.php$/', '', $pageName);

// Пустая страница → index (login redirect)
if ($pageName === '') {
    $pageName = 'index';
}

// Делаем имя страницы доступным глобально для legacy вспомогательных функций
// (например, getPageName() в includes/admin.php), которые иначе видят только index.php.
if (!defined('PAGE_NAME')) {
    define('PAGE_NAME', $pageName);
}

// ─────────────────────────────────────────────────────────────────
//  3. Статические ресурсы — пропускаем (не должны доходить сюда,
//     но на случай неверной nginx-конфигурации)
// ─────────────────────────────────────────────────────────────────

$ext = pathinfo($pageName, PATHINFO_EXTENSION);
if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'], true)) {
    http_response_code(404);
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  4. Bootstrap — сессия + глобальные данные (legacy chain)
// ─────────────────────────────────────────────────────────────────

$adminDir    = MAIN_HOME . $scope . '/';
$cwdTarget   = is_dir($adminDir) ? $adminDir : MAIN_HOME;

// Поддерживаем legacy относительные include внутри admin/reseller файлов
// (некоторые страницы всё ещё используют include 'header.php' и т.п.).
@chdir($cwdTarget);
$sessionFile = $adminDir . 'session.php';

// Некоторые страницы пропускают bootstrap (login, setup, database)
$noBootstrapPages = ['login', 'setup', 'database', 'index'];

// ─────────────────────────────────────────────────────────────────
//  4a. Страницы без bootstrap → сразу legacy fallback
// ─────────────────────────────────────────────────────────────────
//
//  login, setup, database, index — имеют свой собственный bootstrap.
//  Автозагрузчик и Router ещё не доступны → идём напрямую в legacy файл.
// ─────────────────────────────────────────────────────────────────

if (in_array($pageName, $noBootstrapPages, true)) {
    $legacyFile = $adminDir . $pageName . '.php';
    if (file_exists($legacyFile)) {
        require $legacyFile;
        exit;
    }
    // Файл не найден
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  4b. Bootstrap — сессия + глобальные данные (legacy chain)
// ─────────────────────────────────────────────────────────────────
//
//  session.php → functions.php → includes/admin.php → autoload.php
//  После этого доступны: Router, Request, Response, все domain-классы.
// ─────────────────────────────────────────────────────────────────

if (file_exists($sessionFile)) {
    require $sessionFile;
}

$functionsFile = $adminDir . 'functions.php';
if (file_exists($functionsFile)) {
    require $functionsFile;
}

// ─────────────────────────────────────────────────────────────────
//  5. Загрузка маршрутов
// ─────────────────────────────────────────────────────────────────

$router = Router::getInstance();
$routesDir = dirname(__DIR__) . '/routes/';

// Загружаем маршруты для текущего scope
$routeFile = $routesDir . $scope . '.php';
if (file_exists($routeFile)) {
    require_once $routeFile;
}

// API-маршруты (общие для admin и reseller)
$apiRouteFile = $routesDir . 'api.php';
if (file_exists($apiRouteFile)) {
    require_once $apiRouteFile;
}

// ─────────────────────────────────────────────────────────────────
//  6. Dispatch через Router
// ─────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// API-запросы: /admin/api?action=... обрабатываются отдельно
if ($pageName === 'api' && !empty($_REQUEST['action'])) {
    $action = $_REQUEST['action'];

    if ($router->dispatchApi($action)) {
        exit; // Router обработал API-запрос
    }

    // Fallback: передаём в legacy admin/api.php
    $legacyApi = $adminDir . 'api.php';
    if (file_exists($legacyApi)) {
        require $legacyApi;
        exit;
    }

    Response::jsonError('Unknown action: ' . $action, 404);
}

// Страничные маршруты: /admin/dashboard, /admin/streams и т.д.
if ($router->dispatch($pageName, $method)) {
    exit; // Router обработал запрос
}

// ─────────────────────────────────────────────────────────────────
//  7. Fallback — legacy include
// ─────────────────────────────────────────────────────────────────
//
//  Если Router не знает маршрут, подключаем legacy PHP-файл.
//  Bootstrap уже загружен (шаг 4b), поэтому session/functions доступны.
//
//  По мере миграции (Step 6.3) страницы будут регистрироваться
//  в Router и fallback перестанет использоваться.
// ─────────────────────────────────────────────────────────────────

$legacyFile = $adminDir . $pageName . '.php';

if (file_exists($legacyFile)) {
    require $legacyFile;
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  8. 404 — страница не найдена
// ─────────────────────────────────────────────────────────────────

http_response_code(404);

if (function_exists('generate404')) {
    generate404();
} else {
    echo '404 Not Found';
}
