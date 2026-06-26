# Обработка HTTP-запросов

Этот документ описывает, как обрабатываются HTTP-запросы в XC_VM, охватывая полный жизненный цикл — от первичного входа до маршрутизации и диспетчеризации. Существует несколько runtime-путей в зависимости от типа запроса.

---

## Обзор

HTTP-уровень состоит из следующих основных компонентов:

| Компонент | Файл | Роль |
| --- | --- | --- |
| `RequestGuard` | `src/Core/Http/RequestGuard.php` | Защита до маршрутизации: защита от флуда, проверка хоста, инициализация логгера |
| `InputValidator` | `src/Core/Validation/InputValidator.php` | Санитизация ввода (cleanGlobals, parseIncomingRecursively) |
| `RequestManager` | `src/Core/Http/RequestManager.php` | Статический фасад, хранящий объединённые данные GET+POST |
| `Request` | `src/Core/Http/Request.php` | Объектно-ориентированная обёртка запроса (существует, но не используется в основном продакшен-потоке) |
| `Router` | `src/Core/Http/Router.php` | Регистрация и диспетчеризация маршрутов страниц и API |
| `Response` | `src/Core/Http/Response.php` | Статические хелперы для ответов (JSON, redirect, CORS и т.д.) |
| `LegacyInitializer` | `src/Core/Init/LegacyInitializer.php` | Legacy-bootstrap, который связывает санитизацию с `RequestManager` |
| `StreamingRequestBootstrap` | `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` | Лёгкий bootstrap для стриминговых endpoint'ов |

---

## Поток запроса: страницы Admin/Panel

Точка входа: `src/Public/index.php`

```text
nginx -> Public/index.php
  -> разбор URL (scope + pageName)
  -> XC_Bootstrap::boot(CONTEXT_ADMIN)
       -> floodProtection()          (блокировка забаненных IP)
       -> hostVerification()         (проверка allowed_domains)
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
  -> Загрузка маршрутов из src/Public/routes/{scope}.php
  -> Загрузка маршрутов из src/Public/routes/api.php
  -> ModuleLoader::bootAll() (scope admin/reseller, с детекцией коллизий)
  -> Router::dispatchApi($action)  (проверяется первым для страницы "api")
  -> Router::dispatch($pageName, $method)
  -> Обработчик контроллера
```

### Ключевая деталь: санитизация ввода

Продакшен-поток admin НЕ использует `Request::capture()`. Вместо этого обработкой ввода управляет `LegacyInitializer::initCore()`:

1. `InputValidator::cleanGlobals()` вызывается на `$_GET`, `$_POST`, `$_SESSION` и `$_COOKIE` in-place, удаляя NULL-байты, последовательности path traversal (`../`) и RTL override-символы.
2. `InputValidator::parseIncomingRecursively()` санитизирует ключи и значения (HTML-сущности, теги script, разделители комментариев, переводы строк) и возвращает чистый массив.
3. Результат (GET, объединённый с POST, причём POST имеет приоритет) сохраняется через `RequestManager::set()`.

По всему коду данные запроса читаются через `RequestManager::get($key)` и `RequestManager::getAll()`, а не через объект `Request`.

---

## Поток запроса: REST API

Точка входа: `src/Public/index.php` (срабатывает до Router'а)

Когда `XC_SCOPE` равно `includes/api/admin` или `includes/api/reseller`:

```text
nginx -> Public/index.php
  -> XC_Bootstrap::boot(CONTEXT_ADMIN)
  -> new AdminApiController() или new ResellerRestApiController()
  -> $controller->index()
  -> exit
```

Этот путь полностью обходит Router.

---

## Поток запроса: стриминг

Точка входа: endpoint'ы `www/stream/*.php` или `Public/index.php` для `player_api`

```text
nginx -> StreamingRequestBootstrap::init($filename)
  -> Загрузка кодов ошибок, путей, конфигурации, бинарников
  -> Защита от флуда (проверка файла block_{IP})
  -> Загрузка настроек из файлового кэша
  -> Проверка хоста
  -> Инициализация логгера
  -> StreamingBootstrap::bootstrap($filename, $settings)
       -> LegacyInitializer::initStreaming()
            -> Request::cleanGlobals() на суперглобалах
            -> Request::parseIncomingRecursively() -> $GLOBALS['rRequest']
            -> RequestManager::set($GLOBALS['rRequest'])
```

Стриминговый путь намеренно лёгкий. Он не загружает Router, EventDispatcher, Translator или полный ServiceContainer. Диспетчеризации маршрутов нет; у каждого стримингового endpoint'а — собственная точка входа.

---

## `RequestGuard`

Файл: `src/Core/Http/RequestGuard.php`

Процедурный guard-скрипт, подключаемый рано в legacy-bootstrap. Выполняется только для HTTP-запросов (пропускается при наличии `$_SERVER['argc']`, что указывает на CLI).

### Обязанности

1. **Защита от флуда** — если файл `FLOOD_TMP_PATH/block_{IP}` существует, запрос отклоняется с HTTP 403.
2. **Загрузка кэша настроек** — читает `$rSettings` из igbinary-сериализованного файлового кэша по пути `CACHE_TMP_PATH/settings`.
3. **Проверка хоста** — когда `$rSettings['verify_host']` равно true, проверяет, что `HOST` присутствует в кэшированном списке `allowed_domains`. Исключения: имя хоста `xc_vm` и любой валидный IP-адрес всегда разрешены.
4. **Флаг отображения ошибок** — устанавливает константу `PHP_ERRORS` из `$rSettings['debug_show_errors']`.
5. **Инициализация логгера** — вызывает `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`.

Примечание: В современном bootstrap (`XC_Bootstrap::boot()`) эти обязанности выполняют методы `floodProtection()` и `hostVerification()` напрямую, а не через подключение `RequestGuard.php`.

---

## `InputValidator`

Файл: `src/Core/Validation/InputValidator.php`

Предоставляет статические методы для санитизации ввода и валидации на уровне действий.

### Методы санитизации

| Метод | Описание |
| --- | --- |
| `cleanGlobals(&$data, $iteration)` | In-place удаление NULL-байтов, path traversal (`../`) и RTL override-символов. Максимум 10 уровней рекурсии. |
| `parseIncomingRecursively(&$data, $input, $iteration)` | Возвращает новый санитизированный массив. Очищает и ключи, и значения. Максимум 20 уровней рекурсии. |
| `parseCleanKey($key)` | Удаляет двойные точки, паттерны `__wrapped__`, применяет `htmlspecialchars(urldecode())`. |
| `parseCleanValue($value)` | Удаляет теги `<script`, HTML-комментарии, нормализует переводы строк, обрабатывает entity-кодирование. |

### Валидация действий

| Метод | Описание |
| --- | --- |
| `validate($action, $data)` | Возвращает `true`/`false` — соответствуют ли `$data` минимальным требованиям для заданного API-действия. |
| `validateOrFail($action, $data)` | Возвращает `null`, если валидно, или `['status' => STATUS_INVALID_INPUT, 'data' => $data]`, если нет. |
| `confirmIDs($ids)` | Фильтрует массив, оставляя только положительные целочисленные ID. |

---

## `RequestManager`

Файл: `src/Core/Http/RequestManager.php`

Статический фасад, хранящий объединённые данные GET+POST. Это основной паттерн доступа к данным запроса, используемый по всему коду.

### Как данные туда попадают

`LegacyInitializer::initCore()` вызывает:

```php
$rInput = InputValidator::parseIncomingRecursively($_GET, array());
RequestManager::set(InputValidator::parseIncomingRecursively($_POST, $rInput));
```

POST-параметры переопределяют GET-параметры с тем же ключом (POST накладывается поверх GET).

### API

| Метод | Описание |
| --- | --- |
| `set(array $request)` | Сохранить весь массив данных запроса. |
| `getAll()` | Получить все сохранённые данные запроса. |
| `get(string $key, $default = null)` | Получить одно значение по ключу. |
| `update(string $key, $value)` | Обновить один ключ в сохранённых данных. |

### Использование

```php
// Чтение параметра запроса
$streamId = RequestManager::get('stream_id');

// Чтение всех параметров
$allParams = RequestManager::getAll();

// Обновление значения (редко, используется некоторыми legacy-обработчиками)
RequestManager::update('status', 'active');
```

---

## `Request`

Файл: `src/Core/Http/Request.php`

Объектно-ориентированная обёртка запроса. Содержит статическую factory `capture()` и instance-методы для доступа к санитизированному вводу. Хотя класс существует и полностью функционален, основной продакшен-поток вместо этого использует `InputValidator` + `RequestManager`. Статические методы санитизации класса `Request` (`cleanGlobals`, `parseIncomingRecursively`) используются в `LegacyInitializer::initStreaming()` для обратной совместимости.

### Создание

```php
// Статическая factory (singleton, не используется в продакшен-потоке admin)
$request = Request::capture();

// Прямое создание
$request = new Request($_GET, $_POST, $_SERVER, $_COOKIE);
```

### Instance-методы

| Метод | Сигнатура | Описание |
| --- | --- | --- |
| `input` | `input($key, $default = null)` | Получить из объединённого ввода (POST имеет приоритет над GET) |
| `get` | `get($key = null, $default = null)` | Получить из query string (`$_GET`). Ключ `null` возвращает всё. |
| `post` | `post($key = null, $default = null)` | Получить из POST-данных. Ключ `null` возвращает всё. |
| `all` | `all()` | Все объединённые данные ввода |
| `has` | `has($key)` | Проверить, существует ли ключ в объединённом вводе |
| `getInt` | `getInt($key, $default = 0)` | Получить значение как integer |
| `getBool` | `getBool($key, $default = false)` | Получить значение как boolean (через `filter_var`) |
| `server` | `server($key, $default = null)` | Получить значение из `$_SERVER` |
| `cookie` | `cookie($key, $default = null)` | Получить значение cookie |
| `method` | `method()` | HTTP-метод (GET, POST и т.д.) |
| `isPost` | `isPost()` | Проверить, является ли метод POST |
| `isAjax` | `isAjax()` | Проверка `X-Requested-With: XMLHttpRequest` |
| `ip` | `ip()` | IP клиента (проверяет `X-Forwarded-For`, `X-Real-IP`, `REMOTE_ADDR`) |
| `uri` | `uri()` | URI запроса |
| `userAgent` | `userAgent()` | Заголовок User-Agent |
| `host` | `host()` | Заголовок Host (с fallback на `SERVER_NAME`) |
| `rawBody` | `rawBody()` | Сырое тело POST из `php://input` |
| `json` | `json($assoc = true)` | JSON-декодированное тело POST |

### Статические методы санитизации (обратная совместимость)

Зеркалят `InputValidator` и используются на пути инициализации стриминга:

| Метод | Описание |
| --- | --- |
| `cleanGlobals(&$data, $iteration)` | То же, что `InputValidator::cleanGlobals()` |
| `parseIncomingRecursively(&$data, $input, $iteration)` | То же, что `InputValidator::parseIncomingRecursively()` |
| `parseCleanKey($key)` | То же, что `InputValidator::parseCleanKey()` |
| `parseCleanValue($value)` | То же, что `InputValidator::parseCleanValue()` |

---

## `Router`

Файл: `src/Core/Http/Router.php`

Singleton-роутер для диспетчеризации страниц и API. Заменяет legacy-паттерн `switch($rAction)`.

### Регистрация маршрутов

| Метод | Сигнатура | Описание |
| --- | --- | --- |
| `get` | `get($route, $handler, $options = [])` | Зарегистрировать GET-маршрут страницы |
| `post` | `post($route, $handler, $options = [])` | Зарегистрировать POST-маршрут формы |
| `any` | `any($route, $handler, $options = [])` | Зарегистрировать одновременно GET и POST для одного маршрута |
| `api` | `api($action, $handler, $options = [])` | Зарегистрировать API-маршрут (JSON, диспетчеризируется по имени action) |
| `group` | `group($prefix, $callback, $options = [])` | Сгруппировать маршруты под общим префиксом с общим middleware/правами |

Параметр `$handler` принимает:
- `[ClassName::class, 'method']` — создаётся через ServiceContainer (с fallback на `new`)
- Closure или callable
- `[object, 'method']`

Массив `$options` поддерживает:
- `'permission' => ['type', 'key']` — проверяется через `Authorization::check()` перед запуском обработчика
- `'middleware' => [callable, ...]` — массив callables, выполняемых после проверки прав, перед обработчиком

### Примеры маршрутов

```php
$router = Router::getInstance();

// Простые маршруты страниц
$router->get('streams', [StreamController::class, 'index']);
$router->post('stream/save', [StreamController::class, 'save']);

// API-маршрут (JSON)
$router->api('deleteStream', [StreamController::class, 'apiDelete']);

// Сгруппированные маршруты с middleware и правами
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

### Нормализация маршрутов

Роутер нормализует legacy-имена страниц, преобразуя символы подчёркивания в слэши:

| Вход | Нормализованное |
| --- | --- |
| `watch` | `watch` |
| `watch_add` | `watch/add` |
| `settings_watch` | `settings/watch` |
| `plex_add.php` | `plex/add` |

Эта нормализация применяется как при регистрации (`buildRoute`), так и при диспетчеризации (`normalizePage`), поэтому маршруты, зарегистрированные как `watch/add`, совпадают с именами страниц вроде `watch_add`.

### Диспетчеризация

```php
// Диспетчеризация страницы (вызывается из Public/index.php)
$router->dispatch($pageName, $method);    // возвращает true при совпадении

// Диспетчеризация API (вызывается для параметра action=)
$router->dispatchApi($action);            // возвращает true при совпадении
```

#### Порядок выполнения `dispatch($page, $method)`

1. Нормализовать `$page` (подчёркивания → слэши, удалить `.php`).
2. Найти в POST-маршрутах (если метод POST) или GET-маршрутах. Если POST-маршрут не найден, идёт fallback на GET-маршруты.
3. **Проверка прав** через `checkPermission()`. При отказе вызывается `denyAccess()` (редирект или 403).
4. **Выполнение middleware**. Каждый callable из массива `middleware` вызывается. Если любой возвращает `false`, выполнение останавливается.
5. **Вызов обработчика** через `callHandler()`.

#### Порядок выполнения `dispatchApi($action)`

1. Найти в API-маршрутах по имени действия.
2. **Проверка прав**. При отказе выводится `{"result": false}` и происходит выход.
3. **Вызов обработчика**. Middleware не выполняется.

Важно: `dispatchApi()` НЕ запускает middleware. Это намеренное отличие от диспетчеризации страниц.

### Регистрация модульных маршрутов

Модули регистрируют маршруты через `ModuleInterface::registerRoutes()`. Роутер поддерживает безопасный режим регистрации, предотвращающий перезапись модулями core-маршрутов:

```php
$router->beginModuleRegistration();
// Маршруты модулей регистрируются здесь — дубликаты молча пропускаются
$moduleLoader->bootAll($container, $router);
$router->endModuleRegistration();

// Проверка коллизий (логируется в режиме development)
$collisions = $router->drainRouteCollisions();
```

В режиме регистрации модулей (`preserveExistingRoutes = true`), если модуль пытается зарегистрировать уже существующий маршрут, существующий сохраняется, а коллизия фиксируется. `drainRouteCollisions()` возвращает и очищает накопленные коллизии в виде массива `['type' => 'get'|'post'|'api', 'key' => 'route/path']`.

### Интроспекция

| Метод | Описание |
| --- | --- |
| `hasRoute($page)` | Проверить, существует ли маршрут страницы (GET или POST) |
| `hasApiRoute($action)` | Проверить, существует ли API-маршрут |
| `getRoutes()` | Вернуть все зарегистрированные ключи маршрутов как `['get' => [...], 'post' => [...], 'api' => [...]]` |

---

## `Response`

Файл: `src/Core/Http/Response.php`

Статический хелпер для отправки HTTP-ответов. Заменяет разрозненные паттерны `header()` + `echo` + `exit()`.

| Метод | Сигнатура | Описание |
| --- | --- | --- |
| `json` | `json($data, $statusCode = 200, $options = 0)` | Отправить JSON-ответ и выйти |
| `jsonError` | `jsonError($message, $statusCode = 400, $extra = [])` | Отправить JSON-ошибку и выйти |
| `redirect` | `redirect($url, $statusCode = 302)` | Отправить редирект и выйти |
| `notFound` | `notFound($message = 'Not Found')` | Отправить 404 и выйти |
| `header` | `header($name, $value)` | Установить один заголовок ответа |
| `cors` | `cors()` | Установить CORS-заголовки (`Access-Control-Allow-Origin: *`) |
| `noCache` | `noCache()` | Установить no-cache заголовки (используется для HLS-плейлистов) |
| `raw` | `raw($content, $contentType, $statusCode)` | Отправить сырой контент с указанным content type и выйти |
| `empty` | `empty($statusCode = 204)` | Отправить пустой ответ и выйти |

---

## Контексты bootstrap

`XC_Bootstrap::boot($context)` обеспечивает контекстно-зависимую инициализацию. Каждый контекст надстраивается над предыдущим:

| Контекст | Что инициализируется |
| --- | --- |
| `CONTEXT_MINIMAL` | Autoload + константы + конфигурация + Logger. Без подключения к БД. |
| `CONTEXT_CLI` | + База данных + `LegacyInitializer::initCore()` (санитизация ввода, настройки, пути FFmpeg). Опционально Redis. |
| `CONTEXT_STREAM` | + Только база данных (лёгкий, без `LegacyInitializer`). Стриминговые endpoint'ы используют `StreamingRequestBootstrap`. |
| `CONTEXT_ADMIN` | + Сессия + база данных + `LegacyInitializer::initCore()` + Redis + Admin API + Translator + admin globals. Полная инициализация. |

Все HTTP-контексты (не CLI) также выполняют защиту от флуда и проверку хоста перед контекстно-зависимой инициализацией.

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Core/Http/RequestGuard.php` | Защита до маршрутизации и инициализация логгера (legacy include) |
| `src/Core/Http/Request.php` | OOP-обёртка запроса с методами санитизации |
| `src/Core/Http/Router.php` | Регистрация и диспетчеризация маршрутов |
| `src/Core/Http/RequestManager.php` | Статический фасад данных запроса (основной паттерн доступа) |
| `src/Core/Http/Response.php` | Хелперы вывода ответов |
| `src/Core/Validation/InputValidator.php` | Санитизация ввода и валидация действий |
| `src/Core/Init/LegacyInitializer.php` | Legacy core-инициализация (связывает санитизацию с RequestManager) |
| `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` | Лёгкий bootstrap стримингового endpoint'а |
| `src/Streaming/StreamingBootstrap.php` | Подключение к БД и legacy-инициализация стриминга |
| `src/bootstrap.php` | Унифицированный bootstrap (класс `XC_Bootstrap`) |
| `src/Public/index.php` | Front-контроллер для admin/reseller/player/API |
| `src/Public/routes/admin.php` | Определения маршрутов admin-страниц |
| `src/Public/routes/reseller.php` | Определения маршрутов reseller-страниц |
| `src/Public/routes/player.php` | Определения маршрутов player-страниц |
