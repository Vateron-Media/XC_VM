# HTTP Request Handling and Middleware

XC_VM HTTP flow is built from three core pieces:

- `RequestGuard` (request safety and runtime flags)
- `Request` (sanitized request wrapper)
- `Router` (page/API dispatch)

---

## Request Flow

```text
incoming request
  -> RequestGuard
  -> Request::capture()
  -> Router::dispatch() / dispatchApi()
  -> controller handler
```

---

## `RequestGuard`

File: `src/core/Http/RequestGuard.php`

Responsibilities:

- flood-protection via blocked-IP marker files
- host verification (`verify_host`, `allowed_domains`)
- settings cache load (`$rSettings`)
- runtime error display flag (`PHP_ERRORS`)
- logger initialization

### Flood Protection

If `FLOOD_TMP_PATH/block_{IP}` exists, request is rejected with HTTP 403.

### Host Verification

If `verify_host=true`, host must be in cached `allowed_domains` (with allowed exceptions like `xc_vm` and valid IP hosts).

### Logger Initialization

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

---

## `Request`

File: `src/core/Http/Request.php`

Provides normalized access to input sources:

- query (`$_GET`)
- post (`$_POST`)
- cookies (`$_COOKIE`)
- server (`$_SERVER`)

Creation:

```php
$request = Request::capture();
// or
$request = new Request($_GET, $_POST, $_SERVER, $_COOKIE);
```

Input sanitation is applied to GET/POST/COOKIE through cleaner methods.

Common accessors:

```php
$request->get('key');
$request->post('key');
$request->input('key');
$request->cookie('key');
$request->server('key');
```

Utility methods:

```php
$request->method();
$request->isGet();
$request->isPost();
$request->ip();
$request->all();
$request->only([...]);
$request->except([...]);
```

---

## `Router`

File: `src/core/Http/Router.php`

Supports:

- GET page routes
- POST form routes
- API routes
- grouped prefixes

Example:

```php
$router = new Router();
$router->get('streams', [StreamController::class, 'index']);
$router->post('stream/save', [StreamController::class, 'save']);
$router->api('deleteStream', [StreamController::class, 'apiDelete']);
```

Group example:

```php
$router->group('watch', function (Router $r) {
    $r->get('', [WatchController::class, 'index']);
    $r->api('enable', [WatchController::class, 'apiEnable']);
});
```

Dispatch:

```php
$router->dispatch($page, $method);
$router->dispatchApi($action);
```

---

## `RequestManager`

File: `src/core/Http/RequestManager.php`

Static facade for legacy-friendly access:

```php
RequestManager::get('key');
RequestManager::post('key');
RequestManager::getAll();
RequestManager::postAll();
```

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Http/RequestGuard.php` | pre-routing safety and flags |
| `src/core/Http/Request.php` | normalized request wrapper |
| `src/core/Http/Router.php` | route registration/dispatch |
| `src/core/Http/RequestManager.php` | static request facade |
| `src/core/Http/Response.php` | response helpers |
| `src/www/constants.php` | compatibility include chain |
