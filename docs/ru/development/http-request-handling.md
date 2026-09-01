# Обработка HTTP-запросов

Этот документ описывает, как обрабатываются HTTP-запросы в XC_VM, охватывая полный жизненный цикл от первоначального ввода до маршрутизации и отправки. В зависимости от типа запроса существует несколько путей выполнения.

---

## Обзор

Уровень HTTP построен из этих основных компонентов:

|Компонент|Файл|Роль|
| --- | --- | --- |
| `RequestGuard` | `src/Core/Http/RequestGuard.php` |Безопасность перед маршрутизацией: защита от наводнений, проверка хоста, запуск регистратора|
| `InputValidator` | `src/Core/Validation/InputValidator.php` |Очистка входных данных (очистка глобальных объектов, повторный анализ)|
| `RequestManager` | `src/Core/Http/RequestManager.php` |Статический фасад, хранящий объединенные данные запроса GET+POST|
| `Request` | `src/Core/Http/Request.php` |Объектно-ориентированная оболочка запроса (существует, но не используется в основном производственном потоке)|
| `Router` | `src/Core/Http/Router.php` |Регистрация и отправка маршрута по странице и API|
| `Response` | `src/Core/Http/Response.php` |Помощники по статическому ответу (JSON, redirect, CORS и т.д.)|
| `LegacyInitializer` | `src/Core/Init/LegacyInitializer.php` |Устаревший bootstrap, который подключает очистку к `RequestManager`|
| `StreamingRequestBootstrap` | `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` |Облегченный bootstrap для конечных точек потоковой передачи|

---

## Поток запросов: Страницы администратора/панели управления

Точка входа: `src/Public/index.php`

```text
nginx -> Public/index.php
  -> URL parsing (scope + pageName)
  -> XC_Bootstrap::boot(BootContext::Admin)
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
  -> Load routes from src/Public/routes/{scope}.php
  -> Load routes from src/Public/routes/api.php
  -> ModuleLoader::bootAll() (admin/reseller scope, with collision detection)
  -> Router::dispatchApi($action)  (checked first for "api" page)
  -> Router::dispatch($pageName, $method)
  -> Controller handler
```

### Ключевая деталь: санитарная обработка входных данных

В процессе производственного администрирования не используется `Request::capture()`. Вместо этого `LegacyInitializer::initCore()` управляет обработкой входных данных:

1. `InputValidator::cleanGlobals()` вызывается при `$_GET`, `$_POST`, `$_SESSION`, и `$_COOKIE` на месте, удаляя нулевые байты, последовательности обхода пути (`../`) и символы переопределения RTL.
2. `InputValidator::parseIncomingRecursively()` очищает ключи и значения (HTML-объекты, теги скриптов, разделители комментариев, окончания строк) и возвращает чистый массив.
3. Результат (объединяется с сообщением, сообщение имеет приоритет) сохраняется через `RequestManager::set()`.

Во всей кодовой базе доступ к данным запроса осуществляется через `RequestManager::get($key)` и `RequestManager::getAll()`, а не через объект `Request`.

---

## Поток запросов: REST API

Точка входа: `src/Public/index.php` (короткое замыкание перед маршрутизатором)

Когда `XC_SCOPE` равно `includes/api/admin` или `includes/api/reseller`:

```text
nginx -> Public/index.php
  -> XC_Bootstrap::boot(BootContext::Admin)
  -> new AdminApiController() or new ResellerRestApiController()
  -> $controller->index()
  -> exit
```

Этот путь полностью обходит маршрутизатор.

---

## Поток запросов: Потоковая передача

Точка входа: `www/stream/*.php` конечных точек или `Public/index.php` для `player_api`

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

Путь потоковой передачи намеренно упрощен. Он не загружает маршрутизатор, EventDispatcher, транслятор или полный сервисный контейнер. Диспетчеризация маршрутов отсутствует; каждая конечная точка потоковой передачи имеет выделенную точку входа.

---

## `RequestGuard`

Файл: `src/Core/Http/RequestGuard.php`

Процедурный защитный скрипт, включенный ранее в устаревший bootstrap. Выполняется только для HTTP-запросов (пропускается, если установлено значение `$_SERVER['argc']`, указывающее на CLI).

### Обязанности

1. **Защита от наводнений** -- Если файл `FLOOD_TMP_PATH/block_{IP}` существует, запрос отклоняется по протоколу HTTP 403.
2. **Загрузка кэша настроек** -- Считывает `$rSettings` из кэша файлов, сериализованных в igbinary, по адресу `CACHE_TMP_PATH/settings`.
3. **Проверка хостинга** -- Если значение `$rSettings['verify_host']` равно true, проверяется, отображается ли `HOST` в кэшированном списке `allowed_domains`. Исключения: имя хоста `xc_vm` и любой допустимый IP-адрес всегда разрешены.
4. **Флаг отображения ошибки** - Устанавливает константу `PHP_ERRORS` вместо константы `$rSettings['debug_show_errors']`.
5. **Инициализация регистратора** -- Вызывает `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`.

Примечание: В современном bootstrap (`XC_Bootstrap::boot()`) эти обязанности выполняются методами `floodProtection()` и `hostVerification()` напрямую, а не путем включения `RequestGuard.php`.

---

## `InputValidator`

Файл: `src/Core/Validation/InputValidator.php`

Предоставляет статические методы для очистки входных данных и проверки на уровне действий.

### Методы санитарной обработки

|Метод|Описание|
| --- | --- |
| `cleanGlobals(&$data, $iteration)` |Удаление нулевых байтов на месте, обход пути (`../`) и символы переопределения RTL. Максимум 10 уровней рекурсии.|
| `parseIncomingRecursively(&$data, $input, $iteration)` |Возвращает новый обработанный массив. Очищаются как ключи, так и значения. Максимальное количество уровней рекурсии - 20.|
| `parseCleanKey($key)` |Удаляет двойные точки, узоры `__wrapped__`, применяет `htmlspecialchars(urldecode())`.|
| `parseCleanValue($value)` |Удаляет теги `<script`, HTML-комментарии, нормализует разрывы строк, обрабатывает кодировку объектов.|

### Подтверждение действия

|Метод|Описание|
| --- | --- |
| `validate($action, $data)` |Возвращает `true`/`false` для определения того, соответствует ли `$data` минимальным требованиям для данного действия API.|
| `validateOrFail($action, $data)` |Возвращает `null`, если допустимо, или `['status' => STATUS_INVALID_INPUT, 'data' => $data]`, если недопустимо.|
| `confirmIDs($ids)` |Фильтрует массив только по целочисленным положительным идентификаторам.|

---

## `RequestManager`

Файл: `src/Core/Http/RequestManager.php`

Статический интерфейс, в котором хранятся объединенные данные запроса GET+POST. Это основной шаблон доступа к данным запроса, используемый во всей базе кода.

### Как данные попадают в

`LegacyInitializer::initCore()` вызовы:

```php
$rInput = InputValidator::parseIncomingRecursively($_GET, array());
RequestManager::set(InputValidator::parseIncomingRecursively($_POST, $rInput));
```

Параметры POST переопределяют параметры GET с помощью того же ключа (POST объединяется поверх GET).

### интерфейс прикладного программирования

|Метод|Описание|
| --- | --- |
| `set(array $request)` |Храните весь массив данных запроса.|
| `getAll()` |Извлеките все сохраненные данные запроса.|
| `get(string $key, $default = null)` |Извлеките одно значение по ключу.|
| `update(string $key, $value)` |Обновите один ключ в сохраненных данных.|

### Использование

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

Файл: `src/Core/Http/Request.php`

Объектно-ориентированная оболочка запроса. Содержит статическую фабрику `capture()` и методы экземпляра для доступа к обработанным входным данным. Хотя класс существует и полностью функционален, в основном производственном потоке вместо него используются `InputValidator` + `RequestManager`. Методы статической очистки класса `Request` (`cleanGlobals`, `parseIncomingRecursively`) используются `LegacyInitializer::initStreaming()` для обеспечения обратной совместимости.

### Строительство

```php
// Static factory (singleton, not used in production admin flow)
$request = Request::capture();

// Direct construction
$request = new Request($_GET, $_POST, $_SERVER, $_COOKIE);
```

### Методы экземпляра

|Метод|Подпись|Описание|
| --- | --- | --- |
| `input` | `input($key, $default = null)` |Получить из объединенных входных данных (приоритет POST над GET)|
| `get` | `get($key = null, $default = null)` |Получаем из строки запроса (`$_GET`). `null` ключ возвращает все.|
| `post` | `post($key = null, $default = null)` |Получаем из данных POST. `null` ключ возвращает все.|
| `all` | `all()` |Все объединенные входные данные|
| `has` | `has($key)` |Проверьте, существует ли ключ в объединенном вводе|
| `getInt` | `getInt($key, $default = 0)` |Получить значение в виде целого числа|
| `getBool` | `getBool($key, $default = false)` |Получить значение в виде логического значения (через `filter_var`)|
| `server` | `server($key, $default = null)` |Получить значение `$_SERVER`|
| `cookie` | `cookie($key, $default = null)` |Получить значение файла cookie|
| `method` | `method()` |Строка HTTP-метода (GET, POST и т.д.)|
| `isPost` | `isPost()` |Проверьте, является ли метод POST|
| `isAjax` | `isAjax()` |Проверить `X-Requested-With: XMLHttpRequest`|
| `ip` | `ip()` |IP-адрес клиента (проверяет `X-Forwarded-For`, `X-Real-IP`, `REMOTE_ADDR`)|
| `uri` | `uri()` |URI запроса|
| `userAgent` | `userAgent()` |Заголовок пользовательского агента|
| `host` | `host()` |Заголовок узла (возвращается к `SERVER_NAME`)|
| `rawBody` | `rawBody()` |Необработанный текст сообщения из `php://input`|
| `json` | `json($assoc = true)` |Текст СООБЩЕНИЯ, декодированный в формате JSON|

### Методы статической дезинфекции (обратная совместимость)

Они отражают `InputValidator` и используются путем потоковой инициализации:

|Метод|Описание|
| --- | --- |
| `cleanGlobals(&$data, $iteration)` |То же, что `InputValidator::cleanGlobals()`|
| `parseIncomingRecursively(&$data, $input, $iteration)` |То же, что `InputValidator::parseIncomingRecursively()`|
| `parseCleanKey($key)` |То же, что `InputValidator::parseCleanKey()`|
| `parseCleanValue($value)` |То же, что `InputValidator::parseCleanValue()`|

---

## `Router`

Файл: `src/Core/Http/Router.php`

Одноэлементный маршрутизатор для отправки страниц и API. Заменяет устаревший шаблон `switch($rAction)`.

### Регистрация маршрута

|Метод|Подпись|Описание|
| --- | --- | --- |
| `get` | `get($route, $handler, $options = [])` |Зарегистрируйте маршрут получения страницы|
| `post` | `post($route, $handler, $options = [])` |Зарегистрируйте маршрут почтовой формы|
| `any` | `any($route, $handler, $options = [])` |Зарегистрируйте как GET, так и POST для одного и того же маршрута|
| `api` | `api($action, $handler, $options = [])` |Зарегистрируйте маршрут API (JSON, отправляемый по имени действия)|
| `group` | `group($prefix, $callback, $options = [])` |Группируйте маршруты под общим префиксом с общим промежуточным программным обеспечением/разрешениями|

Параметр `$handler` принимает:
- `[ClassName::class, 'method']` -- создается через ServiceContainer (с возможностью возврата к `new`)
- Завершающий или вызываемый
- `[object, 'method']`

Массив `$options` поддерживает:
- `'permission' => ['type', 'key']` -- проверяется с помощью `Authorization::check()` перед запуском обработчика
- `'middleware' => [callable, ...]` -- массив вызываемых объектов, выполняемых после проверки прав доступа, перед обработчиком

### Примеры маршрутов

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

### Нормализация маршрута

Маршрутизатор нормализует устаревшие названия страниц, преобразуя символы подчеркивания в косые черты:

|Ввод|Нормализованный|
| --- | --- |
| `watch` | `watch` |
| `watch_add` | `watch/add` |
| `settings_watch` | `settings/watch` |
| `plex_add.php` | `plex/add` |

Эта нормализация применяется как во время регистрации (`buildRoute`), так и во время отправки (`normalizePage`), поэтому маршруты, зарегистрированные как `watch/add`, соответствуют названиям страниц, подобным `watch_add`.

### Отправка

```php
// Page dispatch (called from Public/index.php)
$router->dispatch($pageName, $method);    // returns true if matched

// API dispatch (called for action= parameter)
$router->dispatchApi($action);            // returns true if matched
```

#### `dispatch($page, $method)` порядок исполнения

1. Нормализовать `$page` (символы подчеркивания заменить косыми чертами, зачеркнуть `.php`).
2. Посмотрите в разделе POST routes (если используется метод POST) или GET routes. Если POST route не найден, вернитесь к GET routes.
3. **Проверка прав доступа** через `checkPermission()`. Если отказано, вызывает `denyAccess()` (перенаправление или 403).
4. **Выполнение промежуточного программного обеспечения**. Вызывается каждый вызываемый объект в массиве `middleware`. Если какой-либо из них возвращает значение `false`, выполнение прекращается.
5. **Вызов обработчика** через `callHandler()`.

#### `dispatchApi($action)` порядок исполнения

1. Найдите в API маршруты по названию действия.
2. **Проверка прав доступа**. Если отказано, выводит `{"result": false}` и завершает работу.
3. **Вызов обработчика**. Промежуточное программное обеспечение не выполняется.

Важно: `dispatchApi()` не запускает промежуточное программное обеспечение. Это намеренное отличие от отправки страниц.

Конечные точки в формате JSON на панели администратора `?action=` регистрируются таким образом и обрабатываются выделенными контроллерами в соответствии с `XcVm\Public\Controllers\Admin\Ajax`. Шаблон контроллера и контракт на структурированный поиск смотрите в [Admin AJAX API](admin-ajax-api.md).

#### Когда ничего не совпадает

И `dispatch()`, и `dispatchApi()` возвращают `false`, если маршрут не совпадает. `Public/index.php` затем выдает `http_response_code(404); echo '404 Not Found';` — есть **нет** универсальный контроллер. (Неправильно введенный путь к ресурсу, который достигает главного контроллера, вместо того, чтобы обслуживаться nginx, попадает на тот же 404.)

> **Pitfall — two sanitization APIs + a global.** Input can be reached three ways: `InputValidator` (the global request-sanitization layer), the `Request` class's static `sanitize*()` methods (kept for backward compatibility), and the global-static `RequestManager`. They are not interchangeable and the sanitization one applies depends on the bootstrap path — pick the layer the surrounding code already uses rather than mixing them, and remember `RequestManager`'s static state makes it order-dependent and awkward to isolate in tests (set it explicitly in a test rather than relying on prior request state).

### Регистрация маршрута модуля

Модули регистрируют маршруты с помощью `ModuleInterface::registerRoutes()`. Маршрутизатор поддерживает безопасный режим регистрации, предотвращающий перезапись модулями основных маршрутов:

```php
$router->beginModuleRegistration();
// Module routes registered here -- duplicates are silently skipped
$moduleLoader->bootAll($container, $router);
$router->endModuleRegistration();

// Check for collisions (logged in development mode)
$collisions = $router->drainRouteCollisions();
```

В режиме регистрации модуля (`preserveExistingRoutes = true`), если модуль пытается зарегистрировать маршрут, который уже существует, существующий маршрут сохраняется и регистрируется коллизия. `drainRouteCollisions()` возвращает и очищает собранные коллизии в виде массива `['type' => 'get'|'post'|'api', 'key' => 'route/path']`.

### Самоанализ

|Метод|Описание|
| --- | --- |
| `hasRoute($page)` |Проверьте, существует ли маршрут страницы (GET или POST).|
| `hasApiRoute($action)` |Проверьте, существует ли маршрут API|
| `getRoutes()` |Возвращает все зарегистрированные ключи маршрута в виде `['get' => [...], 'post' => [...], 'api' => [...]]`|

---

## `Response`

Файл: `src/Core/Http/Response.php`

Статический помощник для отправки HTTP-ответов. Заменяет разрозненные шаблоны `header()` + `echo` + `exit()`.

|Метод|Подпись|Описание|
| --- | --- | --- |
| `json` | `json($data, $statusCode = 200, $options = 0)` |Отправьте ответ в формате JSON и завершите работу|
| `jsonError` | `jsonError($message, $statusCode = 400, $extra = [])` |Отправьте сообщение об ошибке JSON и завершите работу|
| `redirect` | `redirect($url, $statusCode = 302)` |Отправьте перенаправление и завершите работу|
| `notFound` | `notFound($message = 'Not Found')` |Отправьте запрос 404 и выйдите|
| `header` | `header($name, $value)` |Установите один заголовок ответа|
| `cors` | `cors()` |Установить заголовки CORS (`Access-Control-Allow-Origin: *`)|
| `noCache` | `noCache()` |Установка заголовков без кэширования (используется для плейлистов HLS)|
| `raw` | `raw($content, $contentType, $statusCode)` |Отправьте необработанный контент с указанием типа контента и завершите работу|
| `empty` | `empty($statusCode = 204)` |Отправьте пустой ответ и завершите работу|

---

## Контексты начальной загрузки

`XC_Bootstrap::boot($context)` обеспечивает контекстно-зависимую инициализацию. Каждый контекст основывается на предыдущем:

|Контекст|Что он инициализирует|
| --- | --- |
| `BootContext::Minimal` |Автозагрузка + константы + конфигурация + регистратор. Нет подключения к базе данных.|
| `BootContext::Cli` |+ База данных + `LegacyInitializer::initCore()` (очистка входных данных, настройки, пути FFmpeg). Необязательно Redis.|
| `BootContext::Stream` |+ Только база данных (упрощенная, без `LegacyInitializer`). Конечные точки потоковой передачи используют вместо этого `StreamingRequestBootstrap`.|
| `BootContext::Admin` |+ Сессия + База данных + `LegacyInitializer::initCore()` + Redis + API администратора + Переводчик + глобальные настройки администратора. Полная инициализация.|

> `boot()` принимает перечисление `BootContext` (предпочтительно). Устаревшая строка
> константы `XC_Bootstrap::CONTEXT_{MINIMAL,CLI,STREAM,ADMIN}` равны `@deprecated`
> псевдонимы сохранены для обеспечения обратной совместимости — вы все равно увидите их в более старых вызовах
> места. Смотрите [Контексты начальной загрузки](bootstrap-contexts.md) для получения полной матрицы.

Все HTTP-контексты (не CLI) также запускают защиту от наводнений и проверку хоста перед инициализацией, зависящей от контекста.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Http/RequestGuard.php` |Безопасность предварительной маршрутизации и инициализация регистратора (включая устаревшие)|
| `src/Core/Http/Request.php` |Оболочка запроса ООП с методами санитарной обработки|
| `src/Core/Http/Router.php` |Регистрация маршрута и отправка|
| `src/Core/Http/RequestManager.php` |Фасад данных статического запроса (шаблон основного доступа)|
| `src/Core/Http/Response.php` |Помощники по выводу ответов|
| `src/Core/Validation/InputValidator.php` |Очистка входных данных и проверка правильности действий|
| `src/Core/Init/LegacyInitializer.php` |Инициализация устаревшего ядра (очистка проводов в RequestManager)|
| `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` |Облегченный загрузчик конечной точки потоковой передачи|
| `src/Streaming/StreamingBootstrap.php` |Потоковое подключение к базе данных и устаревшая инициализация|
| `src/bootstrap.php` |Унифицированный bootstrap (класс`XC_Bootstrap`)|
| `src/Public/index.php` |Передний контроллер для администратора/реселлера/игрока/API|
| `src/Public/routes/admin.php` |Определения маршрутов на странице администратора|
| `src/Public/routes/reseller.php` |Определения маршрута на странице реселлера|
| `src/Public/routes/player.php` |Определения маршрута на странице игрока|
