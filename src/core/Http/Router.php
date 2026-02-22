<?php

/**
 * XC_VM — HTTP Router
 *
 * Маршрутизатор запросов. Заменяет паттерн switch($rAction) в admin/api.php
 * и прямые include в admin-страницах. Поддерживает модульные маршруты.
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование:
 * ──────────────────────────────────────────────────────────────────
 *
 *   $router = new Router();
 *
 *   // Регистрация маршрутов напрямую
 *   $router->get('watch', [WatchController::class, 'index']);
 *   $router->get('watch/add', [WatchController::class, 'add']);
 *   $router->post('watch/save', [WatchController::class, 'save']);
 *
 *   // Группировка с префиксом
 *   $router->group('plex', function(Router $r) {
 *       $r->get('', [PlexController::class, 'index']);
 *       $r->get('add', [PlexController::class, 'add']);
 *       $r->post('save', [PlexController::class, 'save']);
 *   });
 *
 *   // API-маршруты (JSON)
 *   $router->api('watch/enable', [WatchController::class, 'apiEnable']);
 *   $router->api('watch/disable', [WatchController::class, 'apiDisable']);
 *
 *   // Dispatch (определяет маршрут по URL и вызывает handler)
 *   $router->dispatch($page, $method);
 *
 * ──────────────────────────────────────────────────────────────────
 * Модульная регистрация:
 * ──────────────────────────────────────────────────────────────────
 *
 *   // В модуле (реализация ModuleInterface::registerRoutes):
 *   class WatchModule implements ModuleInterface {
 *       public function registerRoutes(Router $router): void {
 *           $router->group('watch', function(Router $r) {
 *               $r->get('', [WatchController::class, 'index']);
 *               $r->get('add', [WatchController::class, 'add']);
 *               $r->post('settings', [WatchController::class, 'saveSettings']);
 *               $r->api('enable', [WatchController::class, 'apiEnable']);
 *           });
 *       }
 *   }
 *
 * ──────────────────────────────────────────────────────────────────
 * Обратная совместимость:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Пока admin/api.php использует switch($rAction), модули могут
 *   регистрировать API-маршруты через Router, а legacy-код вызывает
 *   $router->dispatchApi($action) как fallback в конце switch-цепочки.
 *
 * @see core/Http/Request.php
 * @see core/Http/Response.php
 * @see ModuleInterface::registerRoutes()
 */

class Router {

    /**
     * Зарегистрированные маршруты для страниц (GET)
     * Формат: ['route/path' => ['handler' => callable, 'middleware' => [...], 'permission' => [...]]]
     * @var array
     */
    protected $getRoutes = [];

    /**
     * Зарегистрированные маршруты для POST
     * @var array
     */
    protected $postRoutes = [];

    /**
     * API-маршруты (JSON ответ)
     * Формат: ['action_name' => ['handler' => callable, 'permission' => [...]]]
     * @var array
     */
    protected $apiRoutes = [];

    /**
     * Текущий префикс группы
     * @var string
     */
    protected $groupPrefix = '';

    /**
     * Текущий набор middleware для группы
     * @var array
     */
    protected $groupMiddleware = [];

    /**
     * Текущий набор permissions для группы
     * @var array
     */
    protected $groupPermission = [];

    /**
     * Singleton instance
     * @var Router|null
     */
    protected static $instance = null;

    /**
     * Получить singleton
     *
     * @return Router
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Сбросить (для тестов)
     */
    public static function resetInstance() {
        self::$instance = null;
    }

    // ───────────────────────────────────────────────────────────
    //  Регистрация маршрутов
    // ───────────────────────────────────────────────────────────

    /**
     * Зарегистрировать GET-маршрут (страница)
     *
     * @param string $route Путь маршрута (напр. 'watch', 'watch/add')
     * @param callable|array $handler Обработчик: [ClassName, 'method'] или callable
     * @param array $options Доп. опции: 'permission' => ['type', 'key'], 'middleware' => [...]
     * @return $this
     */
    public function get($route, $handler, array $options = []) {
        $fullRoute = $this->buildRoute($route);
        $this->getRoutes[$fullRoute] = $this->buildRouteEntry($handler, $options);
        return $this;
    }

    /**
     * Зарегистрировать POST-маршрут (обработка формы)
     *
     * @param string $route Путь маршрута
     * @param callable|array $handler Обработчик
     * @param array $options Доп. опции
     * @return $this
     */
    public function post($route, $handler, array $options = []) {
        $fullRoute = $this->buildRoute($route);
        $this->postRoutes[$fullRoute] = $this->buildRouteEntry($handler, $options);
        return $this;
    }

    /**
     * Зарегистрировать маршрут для GET и POST одновременно
     *
     * @param string $route Путь маршрута
     * @param callable|array $handler Обработчик
     * @param array $options Доп. опции
     * @return $this
     */
    public function any($route, $handler, array $options = []) {
        $this->get($route, $handler, $options);
        $this->post($route, $handler, $options);
        return $this;
    }

    /**
     * Зарегистрировать API-маршрут (JSON-ответ через action=...)
     *
     * API-маршруты обрабатываются через admin/api.php по action-имени.
     * При вызове $router->dispatchApi('watch_enable') Router ищет
     * зарегистрированный маршрут и вызывает его handler.
     *
     * @param string $action Имя действия (напр. 'enable_watch', 'disable_plex')
     * @param callable|array $handler Обработчик
     * @param array $options Доп. опции: 'permission' => ['type', 'key']
     * @return $this
     */
    public function api($action, $handler, array $options = []) {
        $fullAction = $this->groupPrefix ? $this->groupPrefix . '_' . $action : $action;
        $this->apiRoutes[$fullAction] = $this->buildRouteEntry($handler, $options);
        return $this;
    }

    /**
     * Группировка маршрутов с общим префиксом, middleware и permissions
     *
     * @param string $prefix Префикс (напр. 'watch', 'plex')
     * @param callable $callback function(Router $router) — регистрирует маршруты внутри группы
     * @param array $options Опции группы: 'middleware' => [...], 'permission' => [...]
     * @return $this
     */
    public function group($prefix, callable $callback, array $options = []) {
        // Сохраняем текущий контекст
        $prevPrefix     = $this->groupPrefix;
        $prevMiddleware = $this->groupMiddleware;
        $prevPermission = $this->groupPermission;

        // Устанавливаем новый контекст
        $this->groupPrefix     = $prevPrefix ? $prevPrefix . '/' . $prefix : $prefix;
        $this->groupMiddleware = array_merge($prevMiddleware, $options['middleware'] ?? []);
        $this->groupPermission = $options['permission'] ?? $prevPermission;

        // Вызываем callback, который регистрирует маршруты
        $callback($this);

        // Восстанавливаем контекст
        $this->groupPrefix     = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
        $this->groupPermission = $prevPermission;

        return $this;
    }

    // ───────────────────────────────────────────────────────────
    //  Dispatch
    // ───────────────────────────────────────────────────────────

    /**
     * Определить маршрут для страницы и вызвать обработчик
     *
     * @param string $page Имя страницы из URL (напр. 'watch', 'plex_add' → 'plex/add')
     * @param string $method HTTP-метод ('GET' или 'POST')
     * @return bool true если маршрут найден и выполнен, false — не найден
     */
    public function dispatch($page, $method = 'GET') {
        // Нормализация: 'plex_add' → 'plex/add', 'watch' → 'watch'
        $route = $this->normalizePage($page);

        // Выбираем набор маршрутов в зависимости от метода
        $routes = ($method === 'POST') ? $this->postRoutes : $this->getRoutes;

        // Fallback: если POST-маршрут не найден, ищем в GET
        if ($method === 'POST' && !isset($routes[$route]) && isset($this->getRoutes[$route])) {
            $routes = $this->getRoutes;
        }

        if (!isset($routes[$route])) {
            return false;
        }

        $entry = $routes[$route];

        // Проверка прав
        if (!$this->checkPermission($entry)) {
            $this->denyAccess();
            return true;
        }

        // Выполнение middleware
        foreach ($entry['middleware'] as $mw) {
            if (is_callable($mw)) {
                $result = call_user_func($mw);
                if ($result === false) {
                    return true; // middleware остановил выполнение
                }
            }
        }

        // Вызов обработчика
        $this->callHandler($entry['handler']);
        return true;
    }

    /**
     * Определить API-маршрут и вызвать обработчик
     *
     * Используется в admin/api.php как fallback для модульных действий.
     * Пример: если action='enable_watch' зарегистрирован модулем,
     * Router вызовет [WatchController::class, 'apiEnable'].
     *
     * @param string $action Имя действия (из $_GET['action'])
     * @return bool true если маршрут найден и выполнен, false — не найден
     */
    public function dispatchApi($action) {
        if (!isset($this->apiRoutes[$action])) {
            return false;
        }

        $entry = $this->apiRoutes[$action];

        // Проверка прав
        if (!$this->checkPermission($entry)) {
            echo json_encode(['result' => false]);
            exit();
        }

        // Вызов обработчика
        $this->callHandler($entry['handler']);
        return true;
    }

    /**
     * Проверить, зарегистрирован ли маршрут страницы
     *
     * @param string $page Имя страницы
     * @return bool
     */
    public function hasRoute($page) {
        $route = $this->normalizePage($page);
        return isset($this->getRoutes[$route]) || isset($this->postRoutes[$route]);
    }

    /**
     * Проверить, зарегистрирован ли API-маршрут
     *
     * @param string $action Имя действия
     * @return bool
     */
    public function hasApiRoute($action) {
        return isset($this->apiRoutes[$action]);
    }

    /**
     * Получить все зарегистрированные маршруты (для отладки)
     *
     * @return array ['get' => [...], 'post' => [...], 'api' => [...]]
     */
    public function getRoutes() {
        return [
            'get'  => array_keys($this->getRoutes),
            'post' => array_keys($this->postRoutes),
            'api'  => array_keys($this->apiRoutes),
        ];
    }

    // ───────────────────────────────────────────────────────────
    //  Internal Helpers
    // ───────────────────────────────────────────────────────────

    /**
     * Построить полный путь с учётом groupPrefix
     *
     * @param string $route
     * @return string
     */
    protected function buildRoute($route) {
        if ($this->groupPrefix && $route !== '') {
            return $this->groupPrefix . '/' . $route;
        }
        return $this->groupPrefix ?: $route;
    }

    /**
     * Сформировать запись маршрута
     *
     * @param callable|array $handler
     * @param array $options
     * @return array
     */
    protected function buildRouteEntry($handler, array $options) {
        return [
            'handler'    => $handler,
            'middleware'  => array_merge($this->groupMiddleware, $options['middleware'] ?? []),
            'permission' => $options['permission'] ?? $this->groupPermission,
        ];
    }

    /**
     * Нормализовать имя страницы в маршрут
     *
     * Конвертирует legacy-имена (admin-style) в формат маршрута:
     *   'watch'          → 'watch'
     *   'watch_add'      → 'watch/add'
     *   'settings_watch' → 'settings/watch'
     *   'plex_add'       → 'plex/add'
     *   'settings_plex'  → 'settings/plex'
     *
     * @param string $page
     * @return string
     */
    protected function normalizePage($page) {
        // Убираем расширение .php если есть
        $page = preg_replace('/\.php$/', '', $page);
        // Конвертируем _ в /
        return str_replace('_', '/', $page);
    }

    /**
     * Проверить разрешения для маршрута
     *
     * @param array $entry Запись маршрута
     * @return bool
     */
    protected function checkPermission(array $entry) {
        if (empty($entry['permission'])) {
            return true;
        }

        $perm = $entry['permission'];

        // Поддержка формата ['type', 'key'] для hasPermissions()
        if (is_array($perm) && count($perm) === 2 && is_string($perm[0])) {
            if (function_exists('hasPermissions')) {
                return hasPermissions($perm[0], $perm[1]);
            }
            return true; // fallback если функция недоступна
        }

        // Произвольный callable
        if (is_callable($perm)) {
            return call_user_func($perm);
        }

        return true;
    }

    /**
     * Вызвать обработчик маршрута
     *
     * Поддерживает:
     *   - [ClassName::class, 'method'] → new ClassName()->method()
     *   - callable (closure)
     *   - [object, 'method']
     *
     * @param callable|array $handler
     */
    protected function callHandler($handler) {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            // [ClassName, 'method'] → инстанцируем и вызываем
            $class  = $handler[0];
            $method = $handler[1];
            $obj    = new $class();
            $obj->$method();
        } elseif (is_callable($handler)) {
            call_user_func($handler);
        }
    }

    /**
     * Отправить ответ "доступ запрещён"
     */
    protected function denyAccess() {
        if (function_exists('goHome')) {
            goHome();
        } else {
            http_response_code(403);
            echo 'Access denied';
            exit();
        }
    }
}
