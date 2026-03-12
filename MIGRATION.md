# XC_VM — План миграции

> Этот документ описывает **порядок миграции**, **детали каждой фазы** и **стратегию управления рисками**.
> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).

## Содержание

1. [Принцип миграции](#1-принцип-миграции)
2. [Фазы 0–9: Завершённые](#2-фазы-09-завершённые)
3. [Фаза 10: Удаление legacy admin entry-points](#3-фаза-10-удаление-legacy-admin-entry-points)
4. [Фаза 11: Унификация API](#4-фаза-11-унификация-api)
5. [Фаза 12: CLI — единый runner](#5-фаза-12-cli--единый-runner)
6. [Фаза 13: Streaming entry points](#6-фаза-13-streaming-entry-points)
7. [Фаза 14: CSS/JS partials](#7-фаза-14-cssjs-partials)
8. [Фаза 15: Удаление includes/admin.php](#8-фаза-15-удаление-includesadminphp)
9. [Порядок выполнения и риск-матрица](#9-порядок-выполнения-и-риск-матрица)
10. [Стратегия миграции по рискам](#10-стратегия-миграции-по-рискам)

---

## 1. Принцип миграции

### Извлечение → делегирование → замена

Каждый шаг миграции следует одному паттерну:

```
1. Создать новый класс в целевой директории
2. Перенести в него методы из god-объекта
3. В старом файле оставить proxy-метод:
     public static function oldMethod(...$args) {
         return NewClass::method(...$args);
     }
4. Зарегистрировать класс в autoloader
5. Проверить: система работает как раньше
6. (позже) Обновить вызывающий код → удалить proxy
```

Так каждый шаг безопасен и обратимо совместим.

---

## 2. Фазы 0–9: Завершённые

### Фаза 0: Подготовка ✅

Autoload, скелет директорий, bootstrap.php, ServiceContainer, разбиение constants.php → 7 core-файлов.

---

### Фаза 1: Извлечение core/ ✅

Database, Cache, Http/Request, Auth, Process, Util — все базовые компоненты извлечены из god-объектов.

### Фаза 1.7: Оставшиеся извлечения core/ ✅

Логирование, SystemInfo, BruteforceGuard, CurlClient, EventDispatcher, Authorization, Authenticator, ImageUtils — 8 шагов завершены.

---

### Фаза 2: Дедупликация CoreUtilities ↔ StreamingUtilities ✅

53 дублированных метода дедуплицированы: Redis/сигналы, трекинг подключений, справочные данные, init().

---

### Фаза 3: Извлечение domain/ — бизнес-логика ✅

12 доменных контекстов: Stream, Vod, Line, User, Device, Server, Bouquet, Epg, Settings/Ticket, Security, Auth, Playlist. Все entity/repository/service извлечены.

---

### Фаза 4: Извлечение streaming/ (hot path) ✅

streaming/Auth, Delivery, Codec, Protection/Health, StreamingBootstrap — лёгкий bootstrap для hot path.

---

### Фаза 5: Вынесение модулей ✅

6 модулей извлечены атомарно: plex, watch, tmdb, ministra, fingerprint/theft-detection/magscan. ModuleInterface + ModuleLoader. Thread/Multithread дедуплицированы в `core/Process/`.

- 🔲 `ministra/*.js` → modules/ministra/assets/ (JS-файлы портала — отложено до Фазы 13.4)

---

### Фаза 6: Контроллеры и Views (admin/reseller) ✅

#### Шаг 6.1 — Единый layout ✅

Unified wrappers: `public/Views/layouts/admin.php` + `footer.php`.
- **Admin: 112/112 page-файлов — 100% мигрированы**
- **Reseller: 22/22 page-файлов — 100% мигрированы**
- ⏭️ CSS/JS partials — **отложено** до Фазы 14 (footer.php: ~800 стр. page-specific inline JS)

#### Шаг 6.2 — Router + Front Controller ✅

`core/Http/Router.php` (450 стр.), `public/index.php` (Front Controller), `Request.php`, `Response.php`, `RequestGuard.php`. Трёхрежимный URL-парсинг (Access Code + XC_SCOPE / Direct URL / fallback). Access Codes поддержаны.

#### Шаг 6.3 — Конвертация admin-страниц (Controller/View) ✅

**111/111 admin-страниц** мигрированы: Controller + View + Scripts + routes. Паттерн: Thin Controller → Service → View. `BaseAdminController` + `_scripts_init.php`.

#### Шаг 6.4 — Объединение admin/reseller ✅

22 reseller-страницы мигрированы. `BaseResellerController` + 22 контроллера/view/маршрута.

#### Шаг 6.5 — Стабилизация Controller/View контракта ✅

Два прохода стабилизации: viewGlobals расширен, nullable-guards для foreach/in_array/count.

#### Шаг 6.5b — Reseller view nullable audit ✅

Source-level fixes: `getPermissions()` → `[]` fallback, defensive defaults в `functions.php`/`table.php` для `direct_reports`, `all_reports`, `stream_ids`, `category_ids`, `series_ids`, `subresellers`. P0/P1 точечные исправления в 10 файлах.

---

### Фаза 7: Миграция admin.php bootstrap

#### Шаг 7.1 — Вынос inline-данных ✅
Данные bootstrap консолидированы в `resources/data/admin_constants.php`.

#### Шаг 7.2 — Замена процедурного bootstrap ✅
8 инкрементов: runtime → `bootstrapAdminRuntime()` → `admin_session.php` + `admin_runtime.php` → `XC_Bootstrap::boot(CONTEXT_ADMIN)` → фасад `admin_bootstrap.php`.

#### Шаг 7.3 — Удаление proxy-обёрток из admin.php ✅
40 proxy-определений удалены, 560+ call-sites заменены на прямые вызовы domain-сервисов. `admin.php` сокращён с ~4448 до ~3050 строк.

#### Шаг 7.3.1 — Миграция getCategories/getOutputs ✅
`getCategories()` → `CategoryService::getAllByType()` (~75+ call-sites). `getOutputs()` → `OutputFormatRepository::getAll()` (3 call-sites). Создан `domain/Line/OutputFormatRepository.php`.

#### Шаг 7.4 — Устранение параметра `$db` из Domain-классов ✅
28 классов (100 методов) → `global $db` внутри. ~357 call-sites обновлены.

---

### Фаза 8: Ликвидация god-объектов ✅

**Цель:** Удалить три файла-монолита (`CoreUtilities.php`, `StreamingUtilities.php`, `admin_api.php`), заменив ~7 400 внешних вызовов на прямые обращения к целевым классам.

| Файл | Было строк | Методов | Внешних вызовов | Статус |
|------|:----------:|:-------:|:---------------:|--------|
| `admin_api.php` | 3 686 | 79 | ~300 | ✅ 8.1 — удалён |
| `StreamingUtilities.php` | 659 | 78 | 1 344 | ✅ 8.2 — удалён |
| `CoreUtilities.php` | 1 971 | 152 | 5 755 | ✅ 8.3–8.11 — удалён |

#### Шаг 8.1 — admin_api.php ✅
60 PROXY + 18 OWN методов мигрированы в domain-сервисы. Файл удалён.

#### Шаг 8.2 — StreamingUtilities.php ✅
42 PROXY + 33 OWN методов, 18 свойств. ~314 ссылок заменены в 10 батчах. Файл удалён.

#### Шаг 8.3 — CoreUtilities методы ✅
81 PROXY + 69 OWN методов извлечены в целевые классы (17 батчей). CU сокращён с 1 971 до 40 строк.

#### Шаги 8.4–8.6 — Свойства (blocklist, FFmpeg, light) ✅
- **8.4** 7 blocklist/allowlist свойств → `BlocklistService` lazy getters (FileCache)
- **8.5** 3 FFmpeg свойства → value-object `FfmpegPaths::resolve()`
- **8.6** 3 свойства ($rCategories, $rBouquets, $rSegmentSettings) → сервисные геттеры

#### Шаги 8.7–8.10 — Инфраструктурные свойства → singletons ✅
- **8.7** `$rCached` → `FileCache`, `$rConfig` → `ConfigReader`, `$rServers` (184 refs) → `ServerRepository::getAll()`
- **8.8** `$db` (30 refs) → `DatabaseFactory::set()/get()`, `$redis` (62 refs) → `RedisManager::instance()`
- **8.9** `$rSettings` (819 refs, 221 файл) → `SettingsManager::set()/getAll()/get()/update()`
- **8.10** `$rRequest` (3863 refs, 166 файлов) → `RequestManager::set()/getAll()/get()/update()`

#### Шаг 8.11 — Удаление CoreUtilities.php ✅
6 call-sites `CoreUtilities::init()` → `LegacyInitializer::initCore()`. Файл физически удалён.

**Эволюция CU:**

| Фаза | Строк | Методов | Свойств |
|------|------:|--------:|--------:|
| До рефакторинга | 1 971 | 152 | 21 |
| После 8.3 | 40 | 3 | 21 |
| После 8.4–8.6 | 19 | 2 | 8 |
| После 8.7–8.8 | 9 | 1 | 2 |
| После 8.9–8.10 | 5 | 1 | 0 |
| **8.11 — УДАЛЁН** | **0** | **0** | **0** |

**Новые singleton/service классы (Phase 8):** `SettingsManager`, `RequestManager`, `ConfigReader`, `DatabaseFactory` (singleton), `RedisManager` (singleton), `FfmpegPaths`, `FileCache`, `DataEncryptor`, `InputSanitizer`, `IpUtils`, `UrlBuilder`, `ImageUtils`, `Helpers`, `ProcessManager`, `ConnectionManager`, `BackupService`, `ProviderService`, `ProfileService`, `RadioService`, `SystemCheck`, `InputValidator`.

---

### Фаза 9: Стабилизация сборки ✅

> **Цель фазы:** после массовых рефакторингов (Phases 7–8) довести проект до состояния «зелёной» сборки: корректный Makefile, стандартизированные PHP-заголовки, унифицированный layout, экспорт глобалов.

#### Шаг 9.1 — Makefile: LB-сборка для новой архитектуры ✅

**Проблема:** `LB_FILES` не включал `core/`, `domain/`, `streaming/`, `infrastructure/`, `resources/`, `autoload.php`, `bootstrap.php` — LB-сборка не могла работать с мигрированным кодом.

**Решение (5 правок в Makefile):**
1. `LB_FILES` → `LB_DIRS` (14 dirs) + `LB_ROOT_FILES` (5 root files)
2. `lb_copy_files`: второй цикл для root-файлов через `git ls-files --error-unmatch`
3. `lb_update_copy_files`: каскадная проверка (dirs → root files) для delta-обновлений
4. `LB_DIRS_TO_REMOVE`: +6 admin-only исключений (`includes/bootstrap`, `domain/User`, `domain/Device`, `domain/Auth`, `resources/langs`, `resources/libs`)
5. `set_permissions`: `core/ domain/ streaming/ infrastructure/ resources/` → dirs:755, files:644; root PHP → 644

#### Шаг 9.2 — Стандартизация PHP-заголовков (Clean Headers) ✅

**Проблема:** Admin- и reseller-view-файлы начинались разнородно — невозможно безопасно включать как view-фрагменты из layout-контроллера.

**Решение:** Guard-условие `$__viewMode` (скрипт `tools/clean_headers.py`, 4 итерации):

```php
<?php if (!isset($__viewMode)): ?>
    <?php
    include 'session.php';
    include 'functions.php';
    renderUnifiedLayoutHeader('admin');
    ?>
<?php endif; // !$__viewMode ?>
```

**Масштаб:** 112 admin-файлов + 22 reseller-файла.

#### Шаг 9.3 — Миграция view-layout: renderUnifiedLayoutHeader ✅

**Решение:** `renderUnifiedLayoutHeader($scope, $vars)` / `renderUnifiedLayoutFooter($scope, $vars)` — единая обёртка для подключения header/footer с извлечением 16 глобалов.

**Новые файлы:**
- `public/Views/layouts/admin.php`
- `public/Views/layouts/footer.php`

**Масштаб:** 112 admin + 22 reseller файла переведены.

#### Шаг 9.4 — Глобальные переменные: экспорт singleton-данных ✅

`LegacyInitializer::exportGlobals()` — экспортирует singleton-данные в `$GLOBALS` один раз при bootstrap.

**Экспортируемые переменные:** `$rSettings`, `$rRequest`, `$rConfig`, `$rServers`, `$rFFPROBE`, `$rFFMPEG_CPU`, `$rFFMPEG_GPU`, `$db` + streaming-контекст: `$rCached`, `$rBlockedUA/ISP/IPs/Servers`, `$rAllowedIPs`, `$rProxies`, `$rBouquets`, `$rSegmentSettings`.

**Масштаб:** 54 замены в 20 файлах.

---

## 3. Фаза 10: Удаление legacy admin entry-points ✅

> **Цель:** Все admin-запросы обслуживаются ТОЛЬКО через `public/index.php` (Front Controller → Router → Controller → View). Директория `src/admin/` удалена.

#### Шаг 10.1 — Устранение fallback в Front Controller ✅

**Что сделано:**
1. Созданы контроллеры для 7 ещё не маршрутизированных страниц:
   - `LogoutController` — destroySession + redirect
   - `PlayerEmbedController` — проксирует admin/player.php
   - `PostController` — устанавливает `$GLOBALS['__forcePostMode']`, проксирует admin/post.php
   - `LoginController` — проксирует admin/login.php (собственный HTML-документ)
   - `SetupController` — методы `index()` (setup.php) и `database()` (database.php)
2. Добавлены 7 маршрутов в `public/routes/admin.php` (logout, player, post, login, setup, database, index)
3. Унифицирована секция 4a FC: все scope (admin/reseller/player) используют Router для noBootstrapPages
4. Секция 7 (legacy fallback) обёрнута feature flag `$rSettings['use_legacy_fallback']` (default: true)
5. Модифицирован `admin/post.php`: `$rICount` теперь учитывает `$GLOBALS['__forcePostMode']`

#### Шаг 10.2 — Миграция admin/login.php → LoginController ✅

LoginController создан как thin proxy: chdir(admin/) + require login.php. Маршруты `login` и `index` зарегистрированы.

#### Шаг 10.3 — Миграция admin/setup.php, database.php → SetupController ✅

SetupController: методы index() и database() проксируют в legacy-файлы. Маршруты зарегистрированы.

#### Шаг 10.4 — Миграция admin/api.php → AjaxController ✅

AjaxController — thin proxy: chdir(admin/) + require api.php. Маршрут `$router->any('api', ...)`. FC API-fallback обёрнут feature flag.

#### Шаг 10.5 — Консолидация session.php + functions.php ✅

**Создано:**
- `infrastructure/bootstrap/admin_session_fc.php` — clean-версия admin/session.php для FC-пути
- `infrastructure/bootstrap/admin_functions_fc.php` — clean-версия admin/functions.php

FC переключён на `infrastructure/bootstrap/admin_*_fc.php`. Оригиналы оставлены для direct nginx access.

#### Шаг 10.6 — Удаление директории src/admin/ ✅

**Что сделано:**
1. 127 PHP-файлов перемещены из `admin/` → `public/Views/admin/`
2. 423 статических файла (CSS, fonts, images, JS, libs) перемещены из `admin/assets/` → `public/assets/admin/`
3. Директория `src/admin/` полностью удалена
4. Обновлены все ссылки:
   - **FC** (`public/index.php`): `$adminDir = MAIN_HOME . 'public/Views/admin/'`
   - **5 контроллеров**: LoginController, SetupController, PostController, PlayerEmbedController, AjaxController — пути к `public/Views/admin/`
   - **2 layout-файла**: `dirname(__DIR__) . '/admin/header.php'` / `footer.php`
   - **BaseAdminController**: `$__viewMode = true` перед view require
   - **112 view-файлов**: `__DIR__ . '/../layouts/'` (исправлены относительные пути)
   - **8 view-файлов**: `dirname(__DIR__, 3) . '/modules/'` (исправлена глубина)
   - **AuthRepository.php**: `$rAlias` admin → `'public/Views/admin'`
   - **Makefile**: permissions `$(TEMP_DIR)/public/assets/admin`
5. nginx template (`bin/nginx/conf/codes/template`) уже корректный: `alias /home/xc_vm/public/assets/#TYPE#/`
6. Syntax check: 126/127 pass (review.php — pre-existing bug, не регрессия)

---

## 4. Фаза 11: Унификация API — внешние и внутренние

> **Цель:** Единый API-слой через `public/Controllers/Api/` с Router-маршрутизацией. Удаление самостоятельных PHP-файлов в `src/www/` (`player_api.php`, `enigma2.php`, `epg.php`, `playlist.php`, `xplugin.php`).

#### Шаг 11.1 — Player API → PlayerApiController

**Проблема:** `www/player_api.php` (~600 строк) — самостоятельный entry point с собственным bootstrap через `stream/init.php`. Action-based switch (`get_epg`, `get_live_streams`, `get_vod_streams`, `get_series`, `get_series_info`, `get_vod_info`).

**Решение:**
1. Создать `public/Controllers/Api/PlayerApiController.php`
2. Каждый action → метод: `getLiveStreams()`, `getVodStreams()`, `getSeries()`, `getEpg()`, ...
3. Аутентификация (username/password или token) → `StreamingAuth::authenticate()`
4. Entry point: `www/player_api.php` → thin proxy вызов контроллера (обратная совместимость URL)
5. Позже: nginx rewrite на `public/index.php` с scope=`api`

#### Шаг 11.2 — Enigma2 API → Enigma2ApiController

**Проблема:** `www/enigma2.php` (~400 строк) + `www/xplugin.php` (~300 строк) — два entry point для Enigma2-устройств (STB). Отдельные bootstrap-пути, дублирование auth-логики.

**Решение:**
1. `public/Controllers/Api/Enigma2ApiController.php` — объединить enigma2 + xplugin
2. Методы: `getLiveCategories()`, `getVodCategories()`, `getLiveStreams()`, `auth()`, `watchdog()`, ...
3. `www/enigma2.php` и `www/xplugin.php` → thin proxies → удаление после переключения nginx

#### Шаг 11.3 — Playlist/EPG → PlaylistController, EpgController

**Проблема:** `www/playlist.php` (~300 строк) и `www/epg.php` (~200 строк) — генерация M3U и XMLTV. Самостоятельные entry points.

**Решение:**
1. `public/Controllers/Api/PlaylistController.php` — M3U/M3U8 генерация
2. `public/Controllers/Api/EpgController.php` — XMLTV XML генерация
3. Auth-логика уже в `UserRepository::getUserInfo()` — переиспользовать
4. Thin proxy → удаление

#### Шаг 11.4 — Internal Server API → InternalApiController

**Проблема:** `www/api.php` (~800 строк) — межсерверный API (server-to-server). Защищён `live_streaming_pass` + IP whitelist. ~40 case в switch: `stream`, `vod`, `reload_nginx`, `fpm_status`, `restore_images`, ...

**Решение:**
1. `public/Controllers/Api/InternalApiController.php`
2. Группировка: `stream*` → `StreamInternalController`, `vod*` → `VodInternalController`, `server*` → `ServerInternalController`
3. Middleware: IP whitelist + password check → `InternalApiMiddleware`
4. `www/api.php` → маршрутизатор, затем удаление
5. **ВАЖНО:** hot path не затрагивается — внутренний API это cold path (~1 req/min)

#### Шаг 11.5 — Admin/Reseller REST API → единые маршруты

**Проблема:** `includes/api/admin/index.php` и `includes/api/reseller/index.php` — два REST API через `APIWrapper`. Отдельная аутентификация (api_key через access codes).

**Решение:**
1. Admin REST: `public/Controllers/Api/AdminApiController.php`
2. Reseller REST: `public/Controllers/Api/ResellerApiController.php`
3. Auth: access code type 3 (admin) / type 4 (reseller) → middleware
4. `includes/api/admin/` и `includes/api/reseller/` → thin proxies → удаление
5. Маршруты в `public/routes/api.php`

#### Шаг 11.6 — Удаление legacy API файлов ✅

**Предусловия:** 11.1–11.5 завершены, nginx rewrites обновлены.

**Действия:**
1. ✅ Удалить `www/player_api.php`, `www/enigma2.php`, `www/xplugin.php`, `www/epg.php`, `www/playlist.php`
2. ✅ Удалить `www/api.php` (межсерверный)
3. ✅ Удалить `includes/api/admin/index.php`, `includes/api/reseller/index.php` (table.php оставлен — используется через curl из TableAPI)
4. ✅ `www/probe.php`, `www/progress.php` — оставлены как streaming helpers
5. ✅ Обновить nginx-конфиг: rewrite на `public/index.php` с scope=`api`
   - `location ~ ^/(player_api|enigma2|xplugin|epg|playlist)\.php$` → FC с `XC_SCOPE=api`, `XC_API=$1`
   - `location = /api.php` → FC с `XC_SCOPE=api`, `XC_API=internal` (allow 127.0.0.1 only)
6. ✅ `public/index.php` — добавлены секции 3a (REST API dispatch) и 3b (Streaming API dispatch)
   - REST API: `XC_SCOPE=includes/api/admin|reseller` → `includes/admin.php` + controller
   - Streaming API: `XC_SCOPE=api` + `XC_API` → `www/init.php` или `www/stream/init.php` + controller
7. ✅ `www/init.php`, `www/stream/init.php` — добавлен `FC_API_NAME` override для `$rFilename`
8. Smoke test: player_api, enigma2, playlist, epg, internal API — **ожидает деплоя**

---

## 5. Фаза 12: CLI — единый runner и структурированные команды

> **Цель:** Все CLI-скрипты (`includes/cli/`, `crons/`, `src/service`, `src/tools`, `src/status`) запускаются через единую точку входа `cli/console.php`. Каждый скрипт — класс-команда с интерфейсом `CommandInterface`.

#### Шаг 12.1 — Console entry point + CommandInterface

**Создать:**
```
cli/
├── console.php              # Единая точка входа: parse argv → dispatch Command
├── CommandInterface.php     # interface: getName(), getDescription(), execute(array $args): int
└── CommandRegistry.php      # Реестр команд (name → class)
```

**Паттерн:**
```php
// cli/console.php
require_once __DIR__ . '/../bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
$registry = new CommandRegistry();
$registry->registerFromDir(__DIR__ . '/Commands');
$registry->registerFromDir(__DIR__ . '/CronJobs');
exit($registry->dispatch($argv));
```

**Запуск:**
```bash
# Было:
php /home/xc_vm/includes/cli/startup.php
php /home/xc_vm/crons/servers.php

# Стало:
php /home/xc_vm/cli/console.php startup
php /home/xc_vm/cli/console.php cron:servers
```

#### Шаг 12.2 — Миграция демонов includes/cli/ → cli/Commands/

**Скрипты:** `startup.php`, `watchdog.php`, `signals.php`, `queue.php`, `cache_handler.php`, `connection_sync.php`, `monitor.php`, `scanner.php`, `balancer.php`.

**Паттерн миграции (для каждого):**
1. Создать `cli/Commands/XxxCommand.php` implements `CommandInterface`
2. Вынести `loadXxx()` → `execute()`
3. Общий boilerplate (user check, process title, cron lock) → trait `DaemonTrait`
4. В старом файле оставить proxy: `require __DIR__ . '/../cli/console.php'; exit;` (обратная совместимость)
5. Обновить `src/service` (daemons.sh): новые пути запуска

**Инвентарь:** 24 файла в `includes/cli/`:

| Legacy файл | → Command | Тип |
|---|---|---|
| `startup.php` | `Commands/StartupCommand` | daemon |
| `watchdog.php` | `Commands/WatchdogCommand` | daemon |
| `signals.php` | `Commands/SignalsCommand` | daemon |
| `queue.php` | `Commands/QueueCommand` | daemon |
| `cache_handler.php` | `Commands/CacheHandlerCommand` | daemon |
| `connection_sync.php` | `Commands/ConnectionSyncCommand` | daemon |
| `monitor.php` | `Commands/MonitorCommand` | CLI |
| `scanner.php` | `Commands/ScannerCommand` | CLI |
| `balancer.php` | `Commands/BalancerCommand` | CLI |
| `migrate.php` | `Commands/MigrateCommand` | CLI |
| `tools.php` | `Commands/ToolsCommand` | CLI |
| `update.php` | `Commands/UpdateCommand` | CLI |
| `binaries.php` | `Commands/BinariesCommand` | CLI |
| `archive.php` | `Commands/ArchiveCommand` | CLI |
| `created.php` | `Commands/CreatedCommand` | CLI |
| `delay.php` | `Commands/DelayCommand` | CLI |
| `loopback.php` | `Commands/LoopbackCommand` | CLI |
| `llod.php` | `Commands/LlodCommand` | CLI |
| `ondemand.php` | `Commands/OndemandCommand` | CLI |
| `plex_item.php` | `Commands/PlexItemCommand` | CLI |
| `proxy.php` | `Commands/ProxyCommand` | CLI |
| `record.php` | `Commands/RecordCommand` | CLI |
| `thumbnail.php` | `Commands/ThumbnailCommand` | CLI |
| `watch_item.php` | `Commands/WatchItemCommand` | CLI |

#### Шаг 12.3 — Миграция crons/ → cli/CronJobs/

**Инвентарь:** 25 cron-файлов.

**Паттерн миграции (для каждого):**
1. Создать `cli/CronJobs/XxxCron.php` implements `CommandInterface`
2. Общий boilerplate → trait `CronTrait` (user check, lock, shutdown)
3. Вместо `loadCron()` → `execute()`
4. Proxy в старом файле для обратной совместимости crontab

| Legacy файл | → CronJob | Заметки |
|---|---|---|
| `activity.php` | `CronJobs/ActivityCron` | |
| `backups.php` | `CronJobs/BackupsCron` | |
| `cache.php` | `CronJobs/CacheCron` | |
| `cache_engine.php` | `CronJobs/CacheEngineCron` | |
| `certbot.php` | `CronJobs/CertbotCron` | |
| `cleanup.php` | `CronJobs/CleanupCron` | |
| `epg.php` | `CronJobs/EpgCron` | Крупный: EPG-класс + парсинг XML |
| `errors.php` | `CronJobs/ErrorsCron` | |
| `lines_logs.php` | `CronJobs/LinesLogsCron` | |
| `plex.php` | `CronJobs/PlexCron` | Модуль plex |
| `providers.php` | `CronJobs/ProvidersCron` | |
| `root_mysql.php` | `CronJobs/RootMysqlCron` | Требует root |
| `root_signals.php` | `CronJobs/RootSignalsCron` | Требует root |
| `series.php` | `CronJobs/SeriesCron` | |
| `servers.php` | `CronJobs/ServersCron` | |
| `stats.php` | `CronJobs/StatsCron` | |
| `streams.php` | `CronJobs/StreamsCron` | |
| `streams_logs.php` | `CronJobs/StreamsLogsCron` | |
| `tmdb.php` | `CronJobs/TmdbCron` | Модуль tmdb |
| `tmdb_popular.php` | `CronJobs/TmdbPopularCron` | Модуль tmdb |
| `tmp.php` | `CronJobs/TmpCron` | |
| `update.php` | `CronJobs/UpdateCron` | |
| `users.php` | `CronJobs/UsersCron` | |
| `vod.php` | `CronJobs/VodCron` | |
| `watch.php` | `CronJobs/WatchCron` | Модуль watch |

#### Шаг 12.4 — Миграция root-level скриптов

| Legacy | → | Заметки |
|---|---|---|
| `src/service` (shell) | `cli/console.php service:{start\|stop\|restart\|reload}` | Shell-обёртка остаётся для systemd |
| `src/status` (PHP) | `cli/console.php status` | DB migrations, version check |
| `src/tools` (PHP) | `cli/console.php tools:{rescue\|access\|ports}` | Утилиты |
| `src/update` (Python) | Остаётся Python-скриптом, вызывает `cli/console.php update:post` | |

#### Шаг 12.5 — Обновление daemons.sh и crontab

1. `src/bin/daemons.sh` → запуск через `cli/console.php {command}` вместо `includes/cli/{script}.php`
2. Шаблон crontab → `cli/console.php cron:{name}` вместо `php crons/{name}.php`
3. `src/service` → команды start/stop через `cli/console.php`

#### Шаг 12.6 — Удаление legacy CLI файлов

**Предусловия:** 12.2–12.5 завершены, все процессы используют новые пути.

**Действия:**
1. Удалить `includes/cli/*.php` (24 файла)
2. Удалить `crons/*.php` (25 файлов) → оставить директорию для crontab proxies на переходный период
3. Обновить `Makefile`: `CLI_DIRS` включает `cli/`
4. `php -l` + smoke test (запуск демонов, cron-задач)

---

## 6. Фаза 13: Streaming entry points — оптимизация без ломки hot path

> **Цель:** Минимизировать дублирование bootstrap в `www/stream/*.php`, НЕ увеличивая latency. Hot path остаётся < 50ms p99.

> **ВАЖНО:** Streaming — священная территория. Никакого Router, никакого ServiceContainer, никакого полного autoload в hot path. Только точечные улучшения.

#### Шаг 13.1 — Единый streaming entry point (micro-router)

**Проблема:** 11 файлов в `www/stream/` — каждый с `require 'init.php'` + своим shutdown handler. nginx rewrite направляет на конкретный файл. Дублирование инициализации.

**Решение:** Лёгкий micro-router (НЕ полный Router из core/):
```php
// www/stream/index.php (новый)
require_once 'init.php';
$handler = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$map = [
    'live'      => 'live.php',
    'vod'       => 'vod.php',
    'segment'   => 'segment.php',
    'key'       => 'key.php',
    'timeshift' => 'timeshift.php',
    'thumb'     => 'thumb.php',
    'subtitle'  => 'subtitle.php',
    'rtmp'      => 'rtmp.php',
    'auth'      => 'auth.php',
];
if (isset($map[$handler])) {
    require $map[$handler];
} else {
    http_response_code(404);
}
```
- Один `require 'init.php'`, один `register_shutdown_function`
- nginx rewrite не меняется (по-прежнему указывает на конкретные файлы)
- Опционально: переключить nginx на единый `stream/index.php` с параметром

**Бенчмарк:** overhead micro-router < 0.1ms (один array lookup). Допустимо.

#### Шаг 13.2 — Дедупликация shutdown handlers

**Проблема:** Каждый streaming-файл определяет свой `shutdown()`. 80% кода одинаковый: close DB, unlink PID, log error.

**Решение:**
1. `streaming/Lifecycle/ShutdownHandler.php` — общий `shutdown($context)` с контекстом (live/vod/segment и т.д.)
2. В каждом файле: `register_shutdown_function([ShutdownHandler::class, 'handle'], 'live');`
3. Специфическая логика (вроде `$rServers[SERVER_ID]['time_offset']` в vod.php) — callback

#### Шаг 13.3 — Извлечение streaming auth middleware

**Проблема:** `stream/live.php`, `stream/vod.php`, `stream/timeshift.php` — дублируют блок аутентификации (~50 строк): token parse → user lookup → blocklist check → geo check → connection limit.

**Решение:**
1. `streaming/Auth/StreamAuthMiddleware.php` — `authenticate(string $token, string $type): array|false`
2. Возвращает `['user' => $rUserInfo, 'stream' => $rStreamInfo]` или генерирует ошибку
3. Каждый файл: `$auth = StreamAuthMiddleware::authenticate($token, 'live');`

**Ограничение:** Не использовать DI-container — только static-вызов с `global $db, $rSettings`.

#### Шаг 13.4 — Ministra JS → modules/ministra/assets/

**Проблема:** ~80 JS-файлов в `src/ministra/` — клиентский портал для Stalker-устройств. Живут вне модульной структуры.

**Решение:**
1. `mv src/ministra/*.js src/modules/ministra/assets/`
2. `src/ministra/portal.php` → `modules/ministra/PortalHandler.php` (уже запланировано)
3. `src/ministra/index.html` → `modules/ministra/assets/index.html`
4. nginx-конфиг: `alias /home/xc_vm/modules/ministra/assets/`
5. Обновить Makefile

---

## 7. Фаза 14: CSS/JS partials — разбиение footer.php

> **Цель:** Вынести ~800 строк page-specific inline JS из `admin/footer.php` в отдельные файлы. footer.php остаётся < 100 строк (layout-only).

#### Шаг 14.1 — Аудит inline JS в footer.php

1. Список всех `<script>` блоков в `admin/footer.php` с привязкой к `$_TITLE` / page
2. Подсчёт строк по блокам
3. Группировка: общий JS (все страницы) vs page-specific JS

#### Шаг 14.2 — Извлечение page-specific JS

**Паттерн:**
```
// Было в footer.php:
<?php if ($_TITLE == 'streams'): ?>
<script>
    // 200 строк JS для таблицы стримов
</script>
<?php endif; ?>

// Стало:
// public/assets/js/pages/streams.js — отдельный файл
// footer.php: <script src="assets/js/pages/<?= $_TITLE ?>.js"></script>
```

**Действия:**
1. Для каждого page-блока → создать `public/assets/js/pages/{page}.js`
2. В footer.php: динамический `<script src>` по `$_TITLE`
3. Общий JS (DataTables init, modals, AJAX helpers) → `public/assets/js/common.js`

#### Шаг 14.3 — Минификация (опционально)

Если нужна production-оптимизация:
1. `make js-minify` target в Makefile
2. terser или closure-compiler для `assets/js/pages/*.js`
3. Версионирование: `?v={hash}` в `<script src>`

---

## 8. Фаза 15: Удаление includes/admin.php — финальный legacy bootstrap

> **Цель:** `includes/admin.php` (последний legacy-файл) удалён. Весь bootstrap через `XC_Bootstrap::boot()`.

#### Шаг 15.1 — Аудит зависимостей от includes/admin.php

1. `grep -rn "include.*admin\.php\|require.*admin\.php" src/` — все подключения
2. Для каждого подключения: что именно ожидается (глобальные переменные, функции, сессия)?
3. Составить список: что ещё живёт ТОЛЬКО в `includes/admin.php` и не имеет замены

#### Шаг 15.2 — Перенос оставшихся функций

**Ожидаемые остатки:**
- глобальные функции-утилиты → `includes/admin_functions.php` или доменные сервисы
- `$language` инициализация → `core/I18n/Translator.php`
- session bootstrap → `core/Auth/SessionManager.php`
- `$rPermissions` загрузка → `domain/Auth/AuthorizationService.php`

#### Шаг 15.3 — Переключение bootstrap

1. `BaseAdminController::before()`: вместо dual bootstrap → только `XC_Bootstrap::boot(CONTEXT_ADMIN)`
2. `BaseResellerController::before()`: аналогично
3. Feature flag: `use_legacy_bootstrap = false`
4. Тестирование всех 134 страниц

#### Шаг 15.4 — Удаление includes/admin.php

1. `rm src/includes/admin.php`
2. Удалить `require 'admin.php'` из всех файлов
3. Обновить `bootstrap.php` — убрать dual bootstrap ветку
4. `php -l` + полный smoke test

---

## 9. Порядок выполнения и риск-матрица

### Порядок выполнения фаз 10–15

```
Фаза 10 ─── Удаление admin/ legacy entry-points        ✅ ВЫПОЛНЕНА
    │
    ▼
Фаза 11 ─── Унификация API (player_api, enigma2, internal)
    │
    ▼
Фаза 12 ─── CLI единый runner (cli/console.php)
    │
    ▼
Фаза 13 ─── Streaming micro-optimizations (без ломки hot path)
    │
    ▼
Фаза 14 ─── CSS/JS partials (footer.php разбиение)
    │
    ▼
Фаза 15 ─── Удаление includes/admin.php (финальный legacy bootstrap)
```

### Риск-матрица

| Фаза | Риск | Обоснование |
|------|------|-------------|
| 10 | ✅ Завершена | Все 6 шагов выполнены |
| 11 | 🟡 Средний | API backward compat: внешние устройства (MAG, Enigma2, плееры) зависят от URL-формата |
| 12 | 🟢 Низкий | CLI — внутренний, proxy в старых файлах обеспечивает обратную совместимость |
| 13 | 🟡 Средний | Hot path: любая регрессия latency = потеря стримов у всех пользователей |
| 14 | 🟢 Низкий | Только frontend-рефакторинг, не ломает backend |
| 15 | 🔴 Высокий | Удаление последнего legacy bootstrap, нет fallback |

### Разделение релизов (фазы 0–9)

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v1.8** | Фазы 0–2 (core/ extraction, dedup) | 🟢 Низкий — proxy-методы, обратная совместимость |
| **v1.9** | Фазы 3–4 (domain/ + streaming/ extraction) | 🟡 Средний — больше перемещений, proxy покрывает |
| **v2.0** | Фазы 5–6 (modules + controllers) | 🟡 Средний — новая маршрутизация, dual bootstrap |
| **v2.1** | Фазы 7–8 (cleanup, удаление legacy) | 🔴 Высокий — удаление god-объектов, нет fallback |

### Разделение релизов (фазы 10–15)

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v2.2** | Фаза 10 (удаление admin/) + Фаза 14 (CSS/JS partials) | ✅ + 🟢 |
| **v2.3** | Фаза 12 (CLI runner) | 🟢 Низкий |
| **v2.4** | Фаза 11 (API унификация) + Фаза 13 (streaming) | 🟡 + 🟡 |
| **v3.0** | Фаза 15 (удаление legacy bootstrap) | 🔴 Финальный milestone |

---

## 10. Стратегия миграции по рискам

### 10.1. Принцип: каждый шаг обратим

Каждое изменение следует паттерну **extract → delegate → verify → replace**:

```
1. Extract: создать новый класс
2. Delegate: старый код вызывает новый через proxy
3. Verify: система работает как раньше + новый код работает
4. Replace: обновить вызывающий код на прямые вызовы (отдельный шаг)
```

Если шаг 3 провалился — откат = удалить новый класс + убрать proxy. Система возвращается в предыдущее состояние.

### 10.2. Регрессионная стратегия

| Уровень | Что проверяется | Как проверяется | Когда |
|---------|----------------|-----------------|-------|
| **Syntax** | PHP-файлы компилируются | `php -l` на каждый изменённый файл | После каждого коммита |
| **Smoke** | Система запускается | `make new && make lb` + проверка HTTP 200 | После каждой фазы |
| **Functional** | Основные сценарии работают | Ручной checklist (создать поток, запустить, остановить) | После каждой фазы |
| **Integration** | LB-сборка работает | Деплой на тестовый LB-сервер + стриминг-тест | После фаз 1–4 |
| **Backward compat** | API не сломан | Проверка ответов API (формат JSON, коды ошибок) | После фазы 6 |

### 10.3. Dual bootstrap на переходном этапе

**Текущее состояние (фазы 1–6):** Dual bootstrap работает параллельно.

```
bootstrap.php (новый)          includes/admin.php (старый legacy)
      │                                │
      ├── autoload.php                 ├── require Database.php
      ├── ServiceContainer             ├── CoreUtilities::init()
      ├── ConfigLoader                 ├── API/ResellerAPI init
      └── новые core/ классы           └── 50 define() + global $db
```

**Правила dual bootstrap:**
1. `bootstrap.php` загружается ПЕРВЫМ в каждой точке входа
2. `includes/admin.php` загружается ПОСЛЕ — для legacy-кода, который ещё не мигрирован
3. Новые классы (`core/`, `domain/`) инициализируются через `ServiceContainer`
4. Legacy-код (`CoreUtilities`, `admin_api.php`) продолжает работать через proxy-методы
5. После полной миграции (Фаза 15) — `includes/admin.php` удаляется

### 10.4. API backward compatibility

**Правило:** Внешний API (`player.api`, `xmltv.php`, межсерверный API) не меняет формат ответов до Фазы 11.

```
Фазы 1–10: Внутренняя рефакторизация, внешний API неизменен
Фаза 11:   API v2 (опционально) с новой маршрутизацией
            API v1 продолжает работать через compatibility layer
```

### 10.5. Rollback plan

```
Если релиз ломает production:
1. git revert последний merge в main
2. Пересобрать: make new && make lb
3. Задеплоить предыдущую сборку
4. Post-mortem: что сломалось, почему не поймали на smoke test
```

Для Фазы 15 (удаление legacy) — **feature flag:**
```php
// config.ini
[migration]
use_legacy_bootstrap = false    ; true = откат на admin.php
use_legacy_api = false          ; true = откат на admin_api.php switch
```
