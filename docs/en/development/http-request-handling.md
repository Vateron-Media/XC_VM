# HTTP Request Handling

This document describes how HTTP requests are processed in XC_VM, covering the full lifecycle from initial entry through routing and dispatch. There are multiple runtime paths depending on the request type.

---

## Overview

The HTTP layer is built from these core components:

| Component | File | Role |
| --- | --- | --- |
| `RequestGuard` | `src/core/Http/RequestGuard.php` | Pre-routing safety: flood protection, host verification, logger init |
| `InputValidator` | `src/core/Validation/InputValidator.php` | Input sanitization (cleanGlobals, parseIncomingRecursively) |
| `RequestManager` | `src/core/Http/RequestManager.php` | Static facade storing merged GET+POST request data |
| `Request` | `src/core/Http/Request.php` | Object-oriented request wrapper (exists but not used in the primary production flow) |
| `Router` | `src/core/Http/Router.php` | Page and API route registration and dispatch |
| `Response` | `src/core/Http/Response.php` | Static response helpers (JSON, redirect, CORS, etc.) |
| `LegacyInitializer` | `src/core/Init/LegacyInitializer.php` | Legacy bootstrap that wires sanitization into `RequestManager` |
| `StreamingRequestBootstrap` | `src/infrastructure/bootstrap/StreamingRequestBootstrap.php` | Lightweight bootstrap for streaming endpoints |

---

## Request Flow: Admin/Panel Pages

Entry point: `src/public/index.php`

```text
nginx -> public/index.php
  -> URL parsing (scope + pageName)
  -> XC_Bootstrap::boot(CONTEXT_ADMIN)
       -> floodProtection()          (block banned IPs)
       -> hostVerification()         (check allowed domains)
       -> initSession()
       -> initDatabase()
       -> initLegacyCore()
            -> LegacyInitializer::initCore()
                 -> InputValidator::cleanGlobals($_GET, $_POST, $_SESSION, $_COOKIE)
                 -> InputValidator::parseIncomingRecursively($_GET) -> $rInput
                 -> InputValidator::parseIncomingRecursively($_POST, $rInput) -> RequestManager::set()
       -> initRedis()
       -> initAdminAPI()
       -> initTranslator()
  -> Load routes from src/public/routes/{scope}.php
  -> Load routes from src/public/routes/api.php
  -> ModuleLoader::bootAll() (admin/reseller scope, with collision detection)
  -> Router::dispatchApi($action)  (checked first for "api" page)
  -> Router::dispatch($pageName, $method)
  -> Controller handler
```

### Key detail: input sanitization

The production admin flow does NOT use `Request::capture()`. Instead, `LegacyInitializer::initCore()` drives input processing:

1. `InputValidator::cleanGlobals()` is called on `$_GET`, `$_POST`, `$_SESSION`, and `$_COOKIE` in place, stripping NULL bytes, path traversal sequences (`../`), and RTL override characters.
2. `InputValidator::parseIncomingRecursively()` sanitizes keys and values (HTML entities, script tags, comment delimiters, line endings) and returns a clean array.
3. The result (GET merged with POST, POST taking priority) is stored via `RequestManager::set()`.

Throughout the codebase, request data is accessed via `RequestManager::get($key)` and `RequestManager::getAll()`, not through the `Request` object.

---

## Request Flow: REST API

Entry point: `src/public/index.php` (short-circuits before Router)

When `XC_SCOPE` is `includes/api/admin` or `includes/api/reseller`:

```text
nginx -> public/index.php
  -> XC_Bootstrap::boot(CONTEXT_ADMIN)
  -> new AdminApiController() or new ResellerRestApiController()
  -> $controller->index()
  -> exit
```

This path bypasses the Router entirely.

---

## Request Flow: Streaming

Entry point: `www/stream/*.php` endpoints, or `public/index.php` for `player_api`

```text
nginx -> StreamingRequestBootstrap::init($filename)
  -> Load error codes, paths, config, binaries
  -> Flood protection (check block_{IP} file)
  -> Load settings from file cache
  -> Host verification
  -> Logger init
  -> StreamingBootstrap::bootstrap($filename, $settings)
       -> LegacyInitializer::initStreaming()
            -> Request::cleanGlobals() on superglobals
            -> Request::parseIncomingRecursively() -> $GLOBALS['rRequest']
            -> RequestManager::set($GLOBALS['rRequest'])
```

The streaming path is deliberately lightweight. It does not load the Router, EventDispatcher, Translator, or full ServiceContainer. There is no route dispatch; each streaming endpoint has a dedicated entry point.

---

## `RequestGuard`

File: `src/core/Http/RequestGuard.php`

A procedural guard script included early in the legacy bootstrap. Runs only for HTTP requests (skipped when `$_SERVER['argc']` is set, indicating CLI).

### Responsibilities

1. **Flood protection** -- If the file `FLOOD_TMP_PATH/block_{IP}` exists, the request is rejected with HTTP 403.
2. **Settings cache load** -- Reads `$rSettings` from the igbinary-serialized file cache at `CACHE_TMP_PATH/settings`.
3. **Host verification** -- When `$rSettings['verify_host']` is true, checks that `HOST` appears in the cached `allowed_domains` list. Exceptions: the hostname `xc_vm` and any valid IP address are always allowed.
4. **Error display flag** -- Sets the `PHP_ERRORS` constant from `$rSettings['debug_show_errors']`.
5. **Logger initialization** -- Calls `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`.

Note: In the modern bootstrap (`XC_Bootstrap::boot()`), these responsibilities are handled by `floodProtection()` and `hostVerification()` methods directly, not by including `RequestGuard.php`.

---

## `InputValidator`

File: `src/core/Validation/InputValidator.php`

Provides static methods for input sanitization and action-level validation.

### Sanitization methods

| Method | Description |
| --- | --- |
| `cleanGlobals(&$data, $iteration)` | In-place removal of NULL bytes, path traversal (`../`), and RTL override chars. Max 10 levels of recursion. |
| `parseIncomingRecursively(&$data, $input, $iteration)` | Returns a new sanitized array. Cleans both keys and values. Max 20 levels of recursion. |
| `parseCleanKey($key)` | Strips double dots, `__wrapped__` patterns, applies `htmlspecialchars(urldecode())`. |
| `parseCleanValue($value)` | Strips `<script` tags, HTML comments, normalizes line breaks, handles entity encoding. |

### Action validation

| Method | Description |
| --- | --- |
| `validate($action, $data)` | Returns `true`/`false` for whether `$data` meets minimum requirements for the given API action. |
| `validateOrFail($action, $data)` | Returns `null` if valid, or `['status' => STATUS_INVALID_INPUT, 'data' => $data]` if invalid. |
| `confirmIDs($ids)` | Filters an array to only positive integer IDs. |

---

## `RequestManager`

File: `src/core/Http/RequestManager.php`

Static facade that stores the merged GET+POST request data. This is the primary request data access pattern used throughout the codebase.

### How data gets in

`LegacyInitializer::initCore()` calls:

```php
$rInput = InputValidator::parseIncomingRecursively($_GET, array());
RequestManager::set(InputValidator::parseIncomingRecursively($_POST, $rInput));
```

POST parameters override GET parameters with the same key (POST is merged on top of GET).

### API

| Method | Description |
| --- | --- |
| `set(array $request)` | Store the entire request data array. |
| `getAll()` | Retrieve all stored request data. |
| `get(string $key, $default = null)` | Retrieve a single value by key. |
| `update(string $key, $value)` | Update a single key in the stored data. |

### Usage

```php
// Read a request parameter
$streamId = RequestManager::get('stream_id');

// Read all parameters
$allParams = RequestManager::getAll();

// Update a value (rare, used by some legacy handlers)
RequestManager::update('status', 'active');
```

---

## `Request`

File: `src/core/Http/Request.php`

Object-oriented request wrapper. Contains a static `capture()` factory and instance methods for accessing sanitized input. While the class exists and is fully functional, the primary production flow uses `InputValidator` + `RequestManager` instead. The `Request` class's static sanitization methods (`cleanGlobals`, `parseIncomingRecursively`) are used by `LegacyInitializer::initStreaming()` for backward compatibility.

### Construction

```php
// Static factory (singleton, not used in production admin flow)
$request = Request::capture();

// Direct construction
$request = new Request($_GET, $_POST, $_SERVER, $_COOKIE);
```

### Instance methods

| Method | Signature | Description |
| --- | --- | --- |
| `input` | `input($key, $default = null)` | Get from merged input (POST priority over GET) |
| `get` | `get($key = null, $default = null)` | Get from query string (`$_GET`). `null` key returns all. |
| `post` | `post($key = null, $default = null)` | Get from POST data. `null` key returns all. |
| `all` | `all()` | All merged input data |
| `has` | `has($key)` | Check if key exists in merged input |
| `getInt` | `getInt($key, $default = 0)` | Get value as integer |
| `getBool` | `getBool($key, $default = false)` | Get value as boolean (via `filter_var`) |
| `server` | `server($key, $default = null)` | Get `$_SERVER` value |
| `cookie` | `cookie($key, $default = null)` | Get cookie value |
| `method` | `method()` | HTTP method string (GET, POST, etc.) |
| `isPost` | `isPost()` | Check if method is POST |
| `isAjax` | `isAjax()` | Check `X-Requested-With: XMLHttpRequest` |
| `ip` | `ip()` | Client IP (checks `X-Forwarded-For`, `X-Real-IP`, `REMOTE_ADDR`) |
| `uri` | `uri()` | Request URI |
| `userAgent` | `userAgent()` | User-Agent header |
| `host` | `host()` | Host header (falls back to `SERVER_NAME`) |
| `rawBody` | `rawBody()` | Raw POST body from `php://input` |
| `json` | `json($assoc = true)` | JSON-decoded POST body |

### Static sanitization methods (backward compatibility)

These mirror `InputValidator` and are used by the streaming init path:

| Method | Description |
| --- | --- |
| `cleanGlobals(&$data, $iteration)` | Same as `InputValidator::cleanGlobals()` |
| `parseIncomingRecursively(&$data, $input, $iteration)` | Same as `InputValidator::parseIncomingRecursively()` |
| `parseCleanKey($key)` | Same as `InputValidator::parseCleanKey()` |
| `parseCleanValue($value)` | Same as `InputValidator::parseCleanValue()` |

---

## `Router`

File: `src/core/Http/Router.php`

Singleton router for page and API dispatch. Replaces the legacy `switch($rAction)` pattern.

### Route registration

| Method | Signature | Description |
| --- | --- | --- |
| `get` | `get($route, $handler, $options = [])` | Register a GET page route |
| `post` | `post($route, $handler, $options = [])` | Register a POST form route |
| `any` | `any($route, $handler, $options = [])` | Register both GET and POST for the same route |
| `api` | `api($action, $handler, $options = [])` | Register an API route (JSON, dispatched by action name) |
| `group` | `group($prefix, $callback, $options = [])` | Group routes under a common prefix with shared middleware/permissions |

The `$handler` parameter accepts:
- `[ClassName::class, 'method']` -- instantiated via ServiceContainer (with fallback to `new`)
- A closure or callable
- `[object, 'method']`

The `$options` array supports:
- `'permission' => ['type', 'key']` -- checked via `Authorization::check()` before the handler runs
- `'middleware' => [callable, ...]` -- array of callables executed after permission check, before handler

### Route examples

```php
$router = Router::getInstance();

// Simple page routes
$router->get('streams', [StreamController::class, 'index']);
$router->post('stream/save', [StreamController::class, 'save']);

// API route (JSON)
$router->api('deleteStream', [StreamController::class, 'apiDelete']);

// Grouped routes with middleware and permissions
$router->group('watch', function (Router $r) {
    $r->get('', [WatchController::class, 'index']);
    $r->get('add', [WatchController::class, 'add']);
    $r->post('settings', [WatchController::class, 'saveSettings']);
    $r->api('enable', [WatchController::class, 'apiEnable']);
}, [
    'permission' => ['admin', 'watch'],
    'middleware' => [$authCheck],
]);
```

### Route normalization

The router normalizes legacy page names by converting underscores to slashes:

| Input | Normalized |
| --- | --- |
| `watch` | `watch` |
| `watch_add` | `watch/add` |
| `settings_watch` | `settings/watch` |
| `plex_add.php` | `plex/add` |

This normalization is applied both at registration time (`buildRoute`) and at dispatch time (`normalizePage`), so routes registered as `watch/add` match page names like `watch_add`.

### Dispatch

```php
// Page dispatch (called from public/index.php)
$router->dispatch($pageName, $method);    // returns true if matched

// API dispatch (called for action= parameter)
$router->dispatchApi($action);            // returns true if matched
```

#### `dispatch($page, $method)` execution order

1. Normalize `$page` (underscores to slashes, strip `.php`).
2. Look up in POST routes (if method is POST) or GET routes. If POST route not found, falls back to GET routes.
3. **Permission check** via `checkPermission()`. If denied, calls `denyAccess()` (redirect or 403).
4. **Middleware execution**. Each callable in the `middleware` array is called. If any returns `false`, execution stops.
5. **Handler invocation** via `callHandler()`.

#### `dispatchApi($action)` execution order

1. Look up in API routes by action name.
2. **Permission check**. If denied, outputs `{"result": false}` and exits.
3. **Handler invocation**. No middleware is executed.

Important: `dispatchApi()` does NOT run middleware. This is a deliberate difference from page dispatch.

### Module route registration

Modules register routes via `ModuleInterface::registerRoutes()`. The router supports a safe registration mode to prevent modules from overwriting core routes:

```php
$router->beginModuleRegistration();
// Module routes registered here -- duplicates are silently skipped
$moduleLoader->bootAll($container, $router);
$router->endModuleRegistration();

// Check for collisions (logged in development mode)
$collisions = $router->drainRouteCollisions();
```

During module registration mode (`preserveExistingRoutes = true`), if a module tries to register a route that already exists, the existing route is preserved and the collision is recorded. `drainRouteCollisions()` returns and clears the collected collisions as an array of `['type' => 'get'|'post'|'api', 'key' => 'route/path']`.

### Introspection

| Method | Description |
| --- | --- |
| `hasRoute($page)` | Check if a page route exists (GET or POST) |
| `hasApiRoute($action)` | Check if an API route exists |
| `getRoutes()` | Return all registered route keys as `['get' => [...], 'post' => [...], 'api' => [...]]` |

---

## `Response`

File: `src/core/Http/Response.php`

Static helper for sending HTTP responses. Replaces scattered `header()` + `echo` + `exit()` patterns.

| Method | Signature | Description |
| --- | --- | --- |
| `json` | `json($data, $statusCode = 200, $options = 0)` | Send JSON response and exit |
| `jsonError` | `jsonError($message, $statusCode = 400, $extra = [])` | Send JSON error and exit |
| `redirect` | `redirect($url, $statusCode = 302)` | Send redirect and exit |
| `notFound` | `notFound($message = 'Not Found')` | Send 404 and exit |
| `header` | `header($name, $value)` | Set a single response header |
| `cors` | `cors()` | Set CORS headers (`Access-Control-Allow-Origin: *`) |
| `noCache` | `noCache()` | Set no-cache headers (used for HLS playlists) |
| `raw` | `raw($content, $contentType, $statusCode)` | Send raw content with content type and exit |
| `empty` | `empty($statusCode = 204)` | Send empty response and exit |

---

## Bootstrap Contexts

`XC_Bootstrap::boot($context)` provides context-dependent initialization. Each context builds on the previous:

| Context | What it initializes |
| --- | --- |
| `CONTEXT_MINIMAL` | Autoload + constants + config + Logger. No DB connection. |
| `CONTEXT_CLI` | + Database + `LegacyInitializer::initCore()` (input sanitization, settings, FFmpeg paths). Optional Redis. |
| `CONTEXT_STREAM` | + Database only (lightweight, no `LegacyInitializer`). Streaming endpoints use `StreamingRequestBootstrap` instead. |
| `CONTEXT_ADMIN` | + Session + Database + `LegacyInitializer::initCore()` + Redis + Admin API + Translator + admin globals. Full initialization. |

All HTTP contexts (not CLI) also run flood protection and host verification before context-specific initialization.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Http/RequestGuard.php` | Pre-routing safety and logger init (legacy include) |
| `src/core/Http/Request.php` | OOP request wrapper with sanitization methods |
| `src/core/Http/Router.php` | Route registration and dispatch |
| `src/core/Http/RequestManager.php` | Static request data facade (primary access pattern) |
| `src/core/Http/Response.php` | Response output helpers |
| `src/core/Validation/InputValidator.php` | Input sanitization and action validation |
| `src/core/Init/LegacyInitializer.php` | Legacy core initialization (wires sanitization into RequestManager) |
| `src/infrastructure/bootstrap/StreamingRequestBootstrap.php` | Lightweight streaming endpoint bootstrap |
| `src/streaming/StreamingBootstrap.php` | Streaming DB connection and legacy init |
| `src/bootstrap.php` | Unified bootstrap (`XC_Bootstrap` class) |
| `src/public/index.php` | Front controller for admin/reseller/player/API |
| `src/public/routes/admin.php` | Admin page route definitions |
| `src/public/routes/reseller.php` | Reseller page route definitions |
| `src/public/routes/player.php` | Player page route definitions |
