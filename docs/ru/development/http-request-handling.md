# Обработка HTTP-запросов и middleware

Слой обработки HTTP-запросов в XC_VM состоит из трёх компонентов: **RequestGuard** (безопасность входящих запросов), **Request** (обёртка над суперглобальными переменными) и **Router** (маршрутизация к обработчикам).

---

## Обзор потока запроса

```
Входящий HTTP-запрос
        │
        ▼
[RequestGuard]
  ├── Flood-protection (блокировка по IP-файлу)
  ├── Host verification (проверка домена)
  ├── Загрузка $rSettings из кеша
  └── Инициализация Logger
        │
        ▼
[Request::capture()]
  ├── Санитизация $_GET, $_POST, $_COOKIE
  └── Создание объекта запроса
        │
        ▼
[Router::dispatch()]
  ├── Определение маршрута по URL
  ├── Проверка метода (GET / POST)
  └── Вызов обработчика [Controller::method]
```

---

## RequestGuard

`src/core/Http/RequestGuard.php`

Выполняется автоматически при загрузке `www/constants.php` в контекстах HTTP (STREAM, ADMIN).

### Flood-protection

Блокирует IP, если существует файл `FLOOD_TMP_PATH/block_{IP}`.

```
/tmp/xc_vm/flood/block_1.2.3.4  →  HTTP 403 + exit()
```

Файлы блокировки создаются отдельной защитной логикой при превышении лимита запросов.

### Host verification

Если в настройках включена проверка хоста (`verify_host = true`) и загружен кеш разрешённых доменов (`allowed_domains`):

- Запрос с не зарегистрированного домена → `generateError('INVALID_HOST')`
- IP-адреса как хост всегда разрешены
- Служебный хост `xc_vm` всегда разрешён

### Загрузка настроек

`$rSettings` загружается из файлового кеша (`CACHE_TMP_PATH/settings`) через `igbinary_unserialize()`. Это позволяет избежать обращения к БД на каждом запросе.

### Инициализация Logger

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

После RequestGuard Logger доступен во всех последующих компонентах.

---

## Request

`src/core/Http/Request.php`

Обёртка над `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE` с санитизацией входных данных.

### Создание объекта

```php
// Захватить текущий HTTP-запрос
$request = Request::capture();

// Или создать вручную
$request = new Request($_GET, $_POST, $_SERVER, $_COOKIE);
```

При создании все данные из `$_GET`, `$_POST`, `$_COOKIE` проходят санитизацию через `cleanGlobals()` и `parseIncomingRecursively()`.

### Получение данных

```php
$request->get('key');           // из $_GET
$request->post('key');          // из $_POST
$request->input('key');         // из POST или GET (POST имеет приоритет)
$request->cookie('key');        // из $_COOKIE
$request->server('key');        // из $_SERVER (без санитизации)

// С дефолтным значением
$request->get('page', 1);
$request->input('action', '');
```

### Вспомогательные методы

```php
$request->method();             // 'GET' или 'POST'
$request->isPost(): bool
$request->isGet(): bool
$request->ip(): string          // IP клиента
$request->all(): array          // Весь merged input
$request->has('key'): bool
$request->only(['a', 'b']): array
$request->except(['x']): array
```

### Санитизация

- Ключи: очищаются через `parseCleanKey()` — удаляются все символы кроме `a-z`, `A-Z`, `0-9`, `_`, `-`
- Значения: очищаются через `parseCleanValue()` — `strip_tags()` + обрезка пробелов
- `$_SERVER` намеренно не санитизируется (содержит пути и бинарные данные)

---

## Router

`src/core/Http/Router.php`

Маршрутизатор для администраторской панели. Заменяет паттерн `switch($rAction)` и прямые `include` в admin-страницах.

### Регистрация маршрутов

```php
$router = new Router();

// GET-маршруты (страницы)
$router->get('streams', [StreamController::class, 'index']);
$router->get('stream/add', [StreamController::class, 'add']);

// POST-маршруты (формы)
$router->post('stream/save', [StreamController::class, 'save']);

// API-маршруты (JSON-ответ)
$router->api('getStreams', [StreamController::class, 'apiList']);
$router->api('deleteStream', [StreamController::class, 'apiDelete']);
```

### Группировка маршрутов

```php
$router->group('plex', function(Router $r) {
    $r->get('', [PlexController::class, 'index']);
    $r->get('add', [PlexController::class, 'add']);
    $r->post('save', [PlexController::class, 'save']);
    $r->api('enable', [PlexController::class, 'apiEnable']);
});
// Результат: 'plex', 'plex/add', 'plex/save', 'plex/enable'
```

### Диспетчеризация

```php
// Страница по URL
$router->dispatch($page, $method);

// API-вызов
$router->dispatchApi($action);
```

### Модульная регистрация маршрутов

Каждый модуль регистрирует свои маршруты через `ModuleInterface::registerRoutes()`:

```php
class WatchModule implements ModuleInterface {
    public function registerRoutes(Router $router): void {
        $router->group('watch', function(Router $r) {
            $r->get('', [WatchController::class, 'index']);
            $r->get('add', [WatchController::class, 'add']);
            $r->post('settings', [WatchController::class, 'saveSettings']);
            $r->api('enable', [WatchController::class, 'apiEnable']);
        });
    }
}
```

### Обратная совместимость

Пока `admin/api.php` использует `switch($rAction)`, Router используется как fallback в конце switch-цепочки:

```php
// В конце switch($rAction):
default:
    $router->dispatchApi($rAction);
```

---

## RequestManager

`src/core/Http/RequestManager.php`

Статический фасад для доступа к параметрам запроса без создания объекта `Request`.

```php
RequestManager::get('key');
RequestManager::post('key');
RequestManager::getAll(): array
RequestManager::postAll(): array
```

Используется в компонентах, которые не получают `Request` через инъекцию.

---

## Связанные файлы

| Файл                              | Назначение                          |
| --------------------------------- | ----------------------------------- |
| `src/core/Http/RequestGuard.php`  | Flood-protection, host check, settings |
| `src/core/Http/Request.php`       | Обёртка над суперглобальными        |
| `src/core/Http/Router.php`        | Маршрутизация GET/POST/API          |
| `src/core/Http/RequestManager.php`| Статический фасад для запросов      |
| `src/core/Http/Response.php`      | Формирование HTTP-ответов           |
| `src/www/constants.php`           | Загружает RequestGuard              |
