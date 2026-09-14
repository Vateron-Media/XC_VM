<?php

namespace XcVm\Core\Http;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleInterface;
use XcVm\Core\Util\AdminHelpers;

/**
 * HTTP Router
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
 *
 * @package XC_VM_Core_Http
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Router {
	/**
	 * Registered GET (page) routes.
	 * Shape: ['route/path' => ['handler' => callable, 'middleware' => [...], 'permission' => [...]]]
	 * @var array<string, array{handler:mixed,middleware:array,permission:mixed}>
	 */
	protected array $getRoutes = [];

	/**
	 * Registered POST routes.
	 * @var array<string, array{handler:mixed,middleware:array,permission:mixed}>
	 */
	protected array $postRoutes = [];

	/**
	 * API routes (JSON response), keyed by action name.
	 * @var array<string, array{handler:mixed,middleware:array,permission:mixed}>
	 */
	protected array $apiRoutes = [];

	/**
	 * Preserve existing routes during the module registration phase.
	 * When enabled, duplicate route keys are skipped instead of overwritten.
	 */
	protected bool $preserveExistingRoutes = false;

	/**
	 * Route/API collisions collected during the preserve phase.
	 * @var array<int, array{type:string,key:string}>
	 */
	protected array $routeCollisions = [];

	/** Current group prefix. */
	protected string $groupPrefix = '';

	/**
	 * Current middleware stack for the active group.
	 * @var array<int, callable>
	 */
	protected array $groupMiddleware = [];

	/**
	 * Current permission spec for the active group.
	 */
	protected array $groupPermission = [];

	/** Singleton instance. */
	protected static ?Router $instance = null;

	/**
	 * Get the singleton instance.
	 */
	public static function getInstance(): self {
		if (!self::$instance instanceof \XcVm\Core\Http\Router) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (used by tests).
	 */
	public static function resetInstance(): void {
		self::$instance = null;
	}

	// ───────────────────────────────────────────────────────────
	//  Route registration
	// ───────────────────────────────────────────────────────────

	/**
	 * Register a GET route (page).
	 *
	 * @param string $route Route path (e.g. 'watch', 'watch/add')
	 * @param callable|array $handler Handler: [ClassName, 'method'] or a callable
	 * @param array $options Extra options: 'permission' => ['type', 'key'], 'middleware' => [...]
	 */
	public function get(string $route, callable|array $handler, array $options = []): self {
		$fullRoute = $this->buildRoute($route);

		if ($this->preserveExistingRoutes && isset($this->getRoutes[$fullRoute])) {
			$this->routeCollisions[] = ['type' => 'get', 'key' => $fullRoute];
			return $this;
		}

		$this->getRoutes[$fullRoute] = $this->buildRouteEntry($handler, $options);
		return $this;
	}

	/**
	 * Register a POST route (form handling).
	 *
	 * @param string $route Route path
	 * @param callable|array $handler Handler
	 * @param array $options Extra options
	 */
	public function post(string $route, callable|array $handler, array $options = []): self {
		$fullRoute = $this->buildRoute($route);

		if ($this->preserveExistingRoutes && isset($this->postRoutes[$fullRoute])) {
			$this->routeCollisions[] = ['type' => 'post', 'key' => $fullRoute];
			return $this;
		}

		$this->postRoutes[$fullRoute] = $this->buildRouteEntry($handler, $options);
		return $this;
	}

	/**
	 * Register a route for both GET and POST.
	 *
	 * @param string $route Route path
	 * @param callable|array $handler Handler
	 * @param array $options Extra options
	 */
	public function any(string $route, callable|array $handler, array $options = []): self {
		$this->get($route, $handler, $options);
		$this->post($route, $handler, $options);
		return $this;
	}

	/**
	 * Register an API route (JSON response via action=...).
	 *
	 * API routes are dispatched through admin/api.php by action name. When
	 * $router->dispatchApi('watch_enable') is called, the Router looks up the
	 * registered route and invokes its handler.
	 *
	 * @param string $action Action name (e.g. 'enable_watch', 'disable_plex')
	 * @param callable|array $handler Handler
	 * @param array $options Extra options: 'permission' => ['type', 'key']
	 */
	public function api(string $action, callable|array $handler, array $options = []): self {
		$fullAction = $this->groupPrefix ? $this->groupPrefix . '_' . $action : $action;

		if ($this->preserveExistingRoutes && isset($this->apiRoutes[$fullAction])) {
			$this->routeCollisions[] = ['type' => 'api', 'key' => $fullAction];
			return $this;
		}

		$this->apiRoutes[$fullAction] = $this->buildRouteEntry($handler, $options);
		return $this;
	}

	/**
	 * Enable safe module registration mode.
	 * Existing routes keep priority; duplicates are collected as collisions.
	 *
	 */
	public function beginModuleRegistration(): self {
		$this->preserveExistingRoutes = true;
		return $this;
	}

	/**
	 * Disable safe module registration mode.
	 *
	 */
	public function endModuleRegistration(): self {
		$this->preserveExistingRoutes = false;
		return $this;
	}

	/**
	 * Return and clear the collected route collisions.
	 *
	 * @return array<int, array{type:string,key:string}>
	 */
	public function drainRouteCollisions(): array {
		$collisions = $this->routeCollisions;
		$this->routeCollisions = [];
		return $collisions;
	}

	/**
	 * Group routes under a shared prefix, middleware and permissions.
	 *
	 * @param string $prefix Prefix (e.g. 'watch', 'plex')
	 * @param callable $callback function(Router $router) — registers the routes in the group
	 * @param array $options Group options: 'middleware' => [...], 'permission' => [...]
	 */
	public function group(string $prefix, callable $callback, array $options = []): self {
		// Save the current context
		$prevPrefix     = $this->groupPrefix;
		$prevMiddleware = $this->groupMiddleware;
		$prevPermission = $this->groupPermission;

		// Set the new context
		$this->groupPrefix     = $prevPrefix ? $prevPrefix . '/' . $prefix : $prefix;
		$this->groupMiddleware = array_merge($prevMiddleware, $options['middleware'] ?? []);
		$this->groupPermission = $options['permission'] ?? $prevPermission;

		// Invoke the callback, which registers the routes
		$callback($this);

		// Restore the previous context
		$this->groupPrefix     = $prevPrefix;
		$this->groupMiddleware = $prevMiddleware;
		$this->groupPermission = $prevPermission;

		return $this;
	}

	// ───────────────────────────────────────────────────────────
	//  Dispatch
	// ───────────────────────────────────────────────────────────

	/**
	 * Resolve the page route and invoke its handler.
	 *
	 * @param string $page Page name from the URL (e.g. 'watch', 'plex_add' → 'plex/add')
	 * @param string $method HTTP method ('GET' or 'POST')
	 * @return bool true if a route was found and executed, false otherwise
	 */
	public function dispatch(string $page, string $method = 'GET'): bool {
		// Normalize: 'plex_add' → 'plex/add', 'watch' → 'watch'
		$route = $this->normalizePage($page);

		// Pick the route set based on the method
		$routes = ($method === 'POST') ? $this->postRoutes : $this->getRoutes;

		// Fallback: if no POST route matches, look it up among GET routes
		if ($method === 'POST' && !isset($routes[$route]) && isset($this->getRoutes[$route])) {
			$routes = $this->getRoutes;
		}

		if (!isset($routes[$route])) {
			return false;
		}

		$entry = $routes[$route];

		if (!$this->checkPermission($entry)) {
			$this->denyAccess();
			return true;
		}

		// Run middleware
		foreach ($entry['middleware'] as $mw) {
			if (is_callable($mw)) {
				$result = call_user_func($mw);
				if ($result === false) {
					return true; // middleware halted execution
				}
			}
		}

		$this->callHandler($entry['handler']);
		return true;
	}

	/**
	 * Resolve an API route and invoke its handler.
	 *
	 * Used in admin/api.php as a fallback for module-provided actions. Example:
	 * if action='enable_watch' was registered by a module, the Router invokes
	 * [WatchController::class, 'apiEnable'].
	 *
	 * @param string $action Action name (from $_GET['action'])
	 * @return bool true if a route was found and executed, false otherwise
	 */
	public function dispatchApi(string $action): bool {
		if (!isset($this->apiRoutes[$action])) {
			return false;
		}

		$entry = $this->apiRoutes[$action];

		if (!$this->checkPermission($entry)) {
			echo json_encode(['result' => false]);
			exit();
		}

		$this->callHandler($entry['handler']);
		return true;
	}

	/**
	 * Check whether a page route is registered.
	 *
	 * @param string $page Page name
	 */
	public function hasRoute(string $page): bool {
		$route = $this->normalizePage($page);
		return isset($this->getRoutes[$route]) || isset($this->postRoutes[$route]);
	}

	/**
	 * Check whether an API route is registered.
	 *
	 * @param string $action Action name
	 */
	public function hasApiRoute(string $action): bool {
		return isset($this->apiRoutes[$action]);
	}

	/**
	 * Return all registered routes (for debugging).
	 *
	 * @return array{get:string[],post:string[],api:string[]}
	 */
	public function getRoutes(): array {
		return [
			'get'  => array_keys($this->getRoutes),
			'post' => array_keys($this->postRoutes),
			'api'  => array_keys($this->apiRoutes),
		];
	}

	// ───────────────────────────────────────────────────────────
	//  Internal helpers
	// ───────────────────────────────────────────────────────────

	/**
	 * Build the full path taking groupPrefix into account.
	 */
	protected function buildRoute(string $route): string {
		if ($this->groupPrefix && $route !== '') {
			$full = $this->groupPrefix . '/' . $route;
		} else {
			$full = $this->groupPrefix ?: $route;
		}
		// Normalize on registration so dispatch() looks up the same key
		return $this->normalizePage($full);
	}

	/**
	 * Assemble a route entry.
	 *
	 * @return array{handler:mixed,middleware:array,permission:mixed}
	 */
	protected function buildRouteEntry(callable|array $handler, array $options): array {
		return [
			'handler'    => $handler,
			'middleware' => array_merge($this->groupMiddleware, $options['middleware'] ?? []),
			'permission' => $options['permission'] ?? $this->groupPermission,
		];
	}

	/**
	 * Normalize a page name into a route.
	 *
	 * Converts legacy (admin-style) names into route format:
	 *   'watch'          → 'watch'
	 *   'watch_add'      → 'watch/add'
	 *   'settings_watch' → 'settings/watch'
	 *   'plex_add'       → 'plex/add'
	 *   'settings_plex'  → 'settings/plex'
	 */
	protected function normalizePage(string $page): string {
		// Strip a trailing .php if present
		$page = preg_replace('/\.php$/', '', $page);
		// Convert _ to /
		return str_replace('_', '/', $page);
	}

	/**
	 * Check the permissions for a route.
	 *
	 * @param array $entry Route entry
	 */
	protected function checkPermission(array $entry): bool {
		if (empty($entry['permission'])) {
			return true;
		}

		$perm = $entry['permission'];

		// Support the ['type', 'key'] format for Authorization::check()
		if (is_array($perm) && count($perm) === 2 && is_string($perm[0])) {
			return Authorization::check($perm[0], $perm[1]);
		}

		// Arbitrary callable
		if (is_callable($perm)) {
			return call_user_func($perm);
		}

		return true;
	}

	/**
	 * Invoke a route handler.
	 *
	 * Supports:
	 *   - [ClassName::class, 'method'] → (new ClassName())->method()
	 *   - callable (closure)
	 *   - [object, 'method']
	 *
	 */
	protected function callHandler(callable|array $handler): void {
		if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
			// [ClassName, 'method'] → instantiate (via the DI container if available)
			$class  = $handler[0];
			$method = $handler[1];

			// Resolve through the ServiceContainer (DI) when it is already loaded
			if (class_exists(ServiceContainer::class, false)) {
				$container = ServiceContainer::getInstance();
				try {
					$obj = $container->get($class);
				} catch (\Throwable) {
					// Fallback: parameterless constructor
					$obj = new $class();
				}
			} else {
				$obj = new $class();
			}

			$obj->$method();
		} elseif (is_callable($handler)) {
			call_user_func($handler);
		}
	}

	/**
	 * Send an "access denied" response.
	 *
	 * Prefers redirecting an authenticated-but-unauthorized user to the
	 * dashboard; falls back to a bare 403 if the helper is unavailable.
	 */
	protected function denyAccess(): void {
		if (class_exists(AdminHelpers::class)) {
			AdminHelpers::goHome(); // redirects and exits
		}

		http_response_code(403);
		echo 'Access denied';
		exit();
	}
}
