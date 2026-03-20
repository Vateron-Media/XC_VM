# XC_VM — План миграции

> Этот документ описывает **порядок миграции**, **детали каждой фазы** и **стратегию управления рисками**.
> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).

## Содержание

1. [Принцип миграции](#1-принцип-миграции)
2. [Фазы 0–9: Завершённые](#2-фазы-09-завершённые)
3. [Фаза 10: Удаление legacy admin entry-points ✅](#3-фаза-10-удаление-legacy-admin-entry-points)
4. [Фаза 11: Унификация API ✅ (11.1–11.4)](#4-фаза-11-унификация-api)
5. [Фаза 12: CLI — единый runner ✅](#5-фаза-12-cli--единый-runner)
6. [Фаза 13: Streaming entry points ✅ (13.1–13.3)](#6-фаза-13-streaming-entry-points)
7. [Фаза 14: CSS/JS partials](#7-фаза-14-cssjs-partials)
8. [Фаза 15: Удаление includes/admin.php](#8-фаза-15-удаление-includesadminphp)
9. [Порядок выполнения и риск-матрица](#9-порядок-выполнения-и-риск-матрица)
10. [Стратегия миграции по рискам](#10-стратегия-миграции-по-рискам)
11. [Известные пробелы и TODO](#11-известные-пробелы-и-todo)

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
`getCategories()` → `CategoryService::getAllByType()` (~75+ call-sites). `getOutputs()` → `LineRepository::getOutputFormats()` (3 call-sites). Метод перенесён из удалённого `OutputFormatRepository` в `LineRepository`.

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

## 4. Фаза 11: Унификация API — внешние и внутренние ✅ (11.1–11.4)

> **Цель:** Единый API-слой через `public/Controllers/Api/` с Router-маршрутизацией. Удаление самостоятельных PHP-файлов в `src/www/` (`player_api.php`, `enigma2.php`, `epg.php`, `playlist.php`, `xplugin.php`).

#### Шаг 11.1 — Player API → PlayerApiController ✅

**Результат:** `public/Controllers/Api/PlayerApiController.php` (843 строки, 10 actions). Bootstrap через `stream/init.php` (hot path). Auth через `UserRepository::getStreamingUserInfo()`. Standalone — НЕ наследует BaseApiController.

`www/player_api.php` → thin proxy (7 строк).

#### Шаг 11.2 — Enigma2 API → Enigma2ApiController + XPluginApiController ✅

**Результат:** Разделены (вместо объединения по плану) — разные auth, output format, domain:
- `Enigma2ApiController.php` (507 строк, 10 XML-actions)
- `XPluginApiController.php` (181 строка, device management)
- `SimpleXMLExtended` извлечён в отдельный класс (был inline в switch/default — баг)

`www/enigma2.php` и `www/xplugin.php` → thin proxies (7 строк каждый).

#### Шаг 11.3 — Playlist/EPG → PlaylistApiController, EpgApiController ✅

**Результат:**
- `PlaylistApiController.php` (62 строки) — M3U/M3U8 генерация
- `EpgApiController.php` (105 строк) — XMLTV XML генерация
- `BaseApiController.php` (109 строк) — общий базовый класс

`www/playlist.php` и `www/epg.php` → thin proxies.

#### Шаг 11.4 — Internal Server API → InternalApiController ✅

**Результат:** `InternalApiController.php` (506 строк, 30+ actions). Auth: password + IP whitelist (server-to-server). Извлечены: `serveFile()`, `probeStream()`, `killProcessGroup()`.

`www/api.php` → thin proxy.

**Статистика:** 7 controller-файлов = 2313 строк. 6 proxy-файлов = 42 строки.

#### Шаг 11.5 — Admin/Reseller REST API → единые маршруты ⏳

**Статус:** ОТЛОЖЕНО. `APIWrapper` классы встроены в `index.php` (2065+556 строк, одинаковое имя класса). Требуется: переименование классов, извлечение, разрешение конфликта namespace. Отдельный PR.

#### Шаг 11.6 — Удаление legacy API файлов ⏳

**Статус:** ОТЛОЖЕНО. Требуется FC API routing (scope=api). `www/*.php` thin proxies должны оставаться до маршрутизации API через Front Controller.

**Предусловия:** 11.5 завершён, nginx rewrites обновлены.

---

## 5. Фаза 12: CLI — единый runner и структурированные команды ✅

> **Цель:** Все CLI-скрипты (`includes/cli/`, `crons/`, `src/service`, `src/tools`, `src/status`) запускаются через единую точку входа `console.php`. Каждый скрипт — класс-команда с интерфейсом `CommandInterface`.

#### Шаг 12.1 — Console entry point + CommandInterface ✅

**Создано:**
```
cli/
├── CommandInterface.php     # interface: getName(), getDescription(), execute(array $args): int
├── CommandRegistry.php      # Реестр команд (name → class)
├── DaemonTrait.php          # Общий boilerplate для демонов
├── Commands/                # 26 команд
└── CronJobs/                # 25 cron-задач
console.php                  # Единая точка входа (root-level)
```

**Запуск:**
```bash
# Было:
php /home/xc_vm/includes/cli/startup.php
php /home/xc_vm/crons/servers.php

# Стало:
php /home/xc_vm/console.php startup
php /home/xc_vm/console.php cron:servers
```

#### Шаг 12.2 — Миграция includes/cli/ → cli/Commands/ ✅

**Результат:** 24 файла из `includes/cli/` → 24 Command-класса в `cli/Commands/` + 2 модульных команды + 2 новых. Итого 26 команд (24 core + 2 module). Вся логика перенесена, proxy-файлы были созданы, затем удалены (шаг 12.6).

| Legacy файл | → Command | Тип | Заметки |
|---|---|---|---|
| `startup.php` | `Commands/StartupCommand` | daemon | |
| `watchdog.php` | `Commands/WatchdogCommand` | daemon | |
| `signals.php` | `Commands/SignalsCommand` | daemon | |
| `queue.php` | `Commands/QueueCommand` | daemon | |
| `cache_handler.php` | `Commands/CacheHandlerCommand` | daemon | |
| ~~`connection_sync.php`~~ | — | — | Логика поглощена `MonitorCommand`, файл не создан |
| `monitor.php` | `Commands/MonitorCommand` | CLI | Включает connection sync |
| `scanner.php` | `Commands/ScannerCommand` | CLI | |
| `balancer.php` | `Commands/BalancerCommand` | CLI | |
| `migrate.php` | `Commands/MigrateCommand` | CLI | |
| `tools.php` | `Commands/ToolsCommand` | CLI | |
| `update.php` | `Commands/UpdateCommand` | CLI | |
| `binaries.php` | `Commands/BinariesCommand` | CLI | |
| `archive.php` | `Commands/ArchiveCommand` | CLI | |
| `created.php` | `Commands/CreatedCommand` | CLI | |
| `delay.php` | `Commands/DelayCommand` | CLI | |
| `loopback.php` | `Commands/LoopbackCommand` | CLI | |
| `llod.php` | `Commands/LlodCommand` | CLI | |
| `ondemand.php` | `Commands/OndemandCommand` | CLI | |
| `plex_item.php` | `modules/plex/PlexItemCommand` | CLI | Перемещён в модуль |
| `proxy.php` | `Commands/ProxyCommand` | CLI | |
| `record.php` | `Commands/RecordCommand` | CLI | |
| `thumbnail.php` | `Commands/ThumbnailCommand` | CLI | |
| `watch_item.php` | `modules/watch/WatchItemCommand` | CLI | Перемещён в модуль |
| — | `Commands/CertbotCommand` | CLI | Новый |
| — | `Commands/ServiceCommand` | CLI | Новый |
| `src/status` | `Commands/StatusCommand` | CLI | Из root-level скрипта (12.4) |

#### Шаг 12.3 — Миграция crons/ → cli/CronJobs/ ✅

**Результат:** 25 cron-файлов → 25 CronJob-классов в `cli/CronJobs/`. Proxy-файлы и директория `crons/` удалены (шаг 12.8).

| Legacy файл | → CronJob | Заметки |
|---|---|---|
| `activity.php` | `CronJobs/ActivityCronJob` | |
| `backups.php` | `CronJobs/BackupsCronJob` | |
| `cache.php` | `CronJobs/CacheCronJob` | |
| `cache_engine.php` | `CronJobs/CacheEngineCronJob` | |
| `certbot.php` | `CronJobs/CertbotCronJob` | |
| `cleanup.php` | `CronJobs/CleanupCronJob` | |
| `epg.php` | `CronJobs/EpgCronJob` | Крупный: EPG-класс + парсинг XML |
| `errors.php` | `CronJobs/ErrorsCronJob` | |
| `lines_logs.php` | `CronJobs/LinesLogsCronJob` | |
| `plex.php` | `CronJobs/PlexCronJob` | Модуль plex |
| `providers.php` | `CronJobs/ProvidersCronJob` | |
| `root_mysql.php` | `CronJobs/RootMysqlCronJob` | Требует root, LB-excluded |
| `root_signals.php` | `CronJobs/RootSignalsCronJob` | Требует root |
| `series.php` | `CronJobs/SeriesCronJob` | |
| `servers.php` | `CronJobs/ServersCronJob` | |
| `stats.php` | `CronJobs/StatsCronJob` | |
| `streams.php` | `CronJobs/StreamsCronJob` | |
| `streams_logs.php` | `CronJobs/StreamsLogsCronJob` | |
| `tmdb.php` | `CronJobs/TmdbCronJob` | Модуль tmdb |
| `tmdb_popular.php` | `CronJobs/TmdbPopularCronJob` | Модуль tmdb |
| `tmp.php` | `CronJobs/TmpCronJob` | |
| `update.php` | `CronJobs/UpdateCronJob` | |
| `users.php` | `CronJobs/UsersCronJob` | |
| `vod.php` | `CronJobs/VodCronJob` | |
| `watch.php` | `CronJobs/WatchCronJob` | Модуль watch |

#### Шаг 12.4 — Миграция root-level скриптов ✅

| Legacy | → | Статус |
|---|---|---|
| `src/service` (shell) | `console.php service:{start\|stop\|restart\|reload}` | ✅ ServiceCommand |
| `src/status` (PHP) | `console.php status` | ✅ StatusCommand — proxy удалён |
| `src/tools` (PHP) | `console.php tools:{rescue\|access\|ports}` | ✅ ToolsCommand — proxy удалён |
| `src/update` (Python) | Остаётся Python-скриптом | Без изменений |

#### Шаг 12.5 — Обновление daemons.sh и crontab ✅

1. ✅ `bin/daemons.sh` → запуск через `console.php {command}`
2. ✅ Crontab генерируется `StartupCommand::installCrontab()` / `installRootCrontab()` → `console.php cron:{name}`
3. ✅ `src/service` → `console.php service:{start|stop|restart|reload}`

#### Шаг 12.6 — Удаление legacy CLI файлов ✅

**Выполнено:**
1. ✅ `CLI_PATH` constant удалён из `core/Config/Paths.php`
2. ✅ 37 ссылок на `CLI_PATH` заменены на `MAIN_HOME . 'console.php {command}'` в 14 файлах
3. ✅ `includes/cli/` — **папка удалена** (24 файла)
4. ✅ Makefile: 3 старые записи `includes/cli/` удалены из EXCLUDES
5. ✅ `php -l` — все 14 файлов проверены, синтаксис ОК
6. ✅ `crons/` — все 25 файлов удалены, директория удалена
7. ✅ `src/status`, `src/tools` — legacy-proxy файлы удалены, все вызовы заменены на `console.php`

**Паттерн замены:**
```
# Было:
PHP_BIN . ' ' . CLI_PATH . 'script.php args'
# Стало:
PHP_BIN . ' ' . MAIN_HOME . 'console.php command args'
```

**Особые случаи:**
- `OndemandCommand`: `md5_file(CLI_PATH . 'ondemand.php')` → `md5_file(__FILE__)` (self-change detection)
- `BalancerCommand`: обе ветки `$rType == 2` → `console.php startup` (was using different legacy paths)

#### Шаг 12.8 — Удаление CRON_PATH и crons/ директории ✅

**Проблема:** 25 proxy-файлов в `crons/` и константа `CRON_PATH` — legacy остатки после миграции cron → `console.php cron:{name}`. 34+ ссылки на `CRON_PATH` в кодовой базе.

**Выполнено:**
1. ✅ 35 ссылок на `CRON_PATH` заменены на `MAIN_HOME . 'console.php cron:{name}'` в 9 файлах
2. ✅ Константа `CRON_PATH` удалена из `core/Config/Paths.php`
3. ✅ Мёртвый autoload-маппинг `EPG → crons/epg.php` удалён
4. ✅ Класс `EPG` восстановлен в `domain/Epg/EPG.php` (был потерян при proxy-конвертации)
5. ✅ `src/crons/` — все 25 proxy-файлов удалены, директория `crons/` удалена. Класс `EPG` загружается из `domain/Epg/EPG.php`
6. ✅ Makefile: `crons` убран из `LB_DIRS`, 9 записей `crons/*.php` из `LB_FILES_TO_REMOVE`, 2 строки permissions
7. ✅ 8 CronJob-файлов (MAIN-only) добавлены в `LB_FILES_TO_REMOVE` + `file_exists()` guards в `console.php`
8. ✅ `MigrationRunner::runFileCleanup()` — теперь удаляет опустевшие директории
9. ✅ Legacy-путь в `post.php` (`MAIN_HOME . '/php/bin/php ' . MAIN_HOME . '/crons/epg.php'`) исправлен

**Паттерн замены:**
```
# Было:
PHP_BIN . ' ' . CRON_PATH . 'name.php args'
# Стало:
PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:name args'
```

**Затронутые файлы:**
- `cli/CronJobs/CacheEngineCronJob.php` (9 замен)
- `cli/Commands/StartupCommand.php` (4 замены, вкл. `file_exists()` → проверка `cache_complete`)
- `cli/Commands/SignalsCommand.php` (2 замены)
- `cli/Commands/BalancerCommand.php` (1 замена)
- `cli/Commands/CertbotCommand.php` (1 замена)
- `public/Controllers/Api/InternalApiController.php` (4 замены)
- `public/Controllers/Api/AdminApiController.php` (5 замен)
- `public/Views/admin/api.php` (8 замен)
- `public/Views/admin/post.php` (1 замена)

#### Шаг 12.7 — LB guard для MAIN-only команд ✅

**Проблема:** 4 CLI-класса исключены из LB-сборки через `LB_FILES_TO_REMOVE`, но `console.php` регистрировал их безусловно → crash `require_once` на LB.

**Решение:** `file_exists()` guards в `console.php` для условной регистрации.

| Файл | Guard в console.php | Guard в crontab |
|---|---|---|
| `cli/Commands/MigrateCommand.php` | ✅ `file_exists()` | — (не в crontab) |
| `cli/Commands/CacheHandlerCommand.php` | ✅ `file_exists()` | — (не в crontab) |
| `cli/Commands/BalancerCommand.php` | ✅ `file_exists()` | — (не в crontab) |
| `cli/CronJobs/RootMysqlCronJob.php` | ✅ `file_exists()` | ✅ `file_exists()` в StartupCommand + StatusCommand |
| `cli/CronJobs/BackupsCronJob.php` | ✅ `file_exists()` | — |
| `cli/CronJobs/CacheEngineCronJob.php` | ✅ `file_exists()` | — |
| `cli/CronJobs/EpgCronJob.php` | ✅ `file_exists()` | — |
| `cli/CronJobs/UpdateCronJob.php` | ✅ `file_exists()` | — |
| `cli/CronJobs/ProvidersCronJob.php` | ✅ `file_exists()` | — |
| `cli/CronJobs/SeriesCronJob.php` | ✅ `file_exists()` | — |
| `modules/tmdb/TmdbCronJob.php` | ✅ auto-discovery | Moved to module dir |
| `modules/tmdb/TmdbPopularCronJob.php` | ✅ auto-discovery | Moved to module dir |
| `domain/Epg/EPG.php` | — | — (autoload) |

**Makefile `LB_FILES_TO_REMOVE`:**
```makefile
cli/Commands/MigrateCommand.php
cli/Commands/CacheHandlerCommand.php
cli/Commands/BalancerCommand.php
cli/CronJobs/RootMysqlCronJob.php
cli/CronJobs/BackupsCronJob.php
cli/CronJobs/CacheEngineCronJob.php
cli/CronJobs/EpgCronJob.php
cli/CronJobs/UpdateCronJob.php
cli/CronJobs/ProvidersCronJob.php
cli/CronJobs/SeriesCronJob.php
domain/Epg/EPG.php
```

---

## 6. Фаза 13: Streaming entry points ✅ (13.1–13.3)

> **Цель:** Минимизировать дублирование bootstrap в `www/stream/*.php`, НЕ увеличивая latency. Hot path остаётся < 50ms p99.

> **ВАЖНО:** Streaming — священная территория. Никакого Router, никакого ServiceContainer, никакого полного autoload в hot path. Только точечные улучшения.

#### Шаг 13.1 — Единый streaming entry point (micro-router) ✅

**Результат:** `www/stream/index.php` — micro-router с `?handler=` параметром. Dormant-режим: nginx по-прежнему направляет на конкретные файлы, router активируется опционально.

Overhead < 0.1ms (один array lookup).

#### Шаг 13.2 — Дедупликация shutdown handlers ✅

**Результат:** `streaming/Lifecycle/ShutdownHandler.php` — заменяет дублированные `function shutdown()` в live/vod/timeshift. Context-параметр для live-специфичной очистки (tmp files, on_demand queue).

`auth.php` и `rtmp.php` сохраняют свои shutdown handlers (BruteforceGuard pattern).

#### Шаг 13.3 — Извлечение streaming auth middleware ✅

**Результат:** `streaming/Auth/StreamAuthMiddleware.php` — `sendStreamHeaders()` + `decryptToken()`. Извлекает ~35 дублированных строк из каждого streaming-файла.

**Изменённые файлы:**
- `www/stream/live.php` — ShutdownHandler + StreamAuthMiddleware
- `www/stream/vod.php` — аналогично
- `www/stream/timeshift.php` — аналогично

#### Шаг 13.4 — Ministra JS → modules/ministra/assets/ ⏳

**Статус:** ОТЛОЖЕНО. `www/c` — симлинк на `ministra/` (создаётся `RootSignalsCronJob::enable_ministra`). Перемещение JS ломает симлинк, требует nginx alias + изменения signal handler. Низкий benefit, высокий cost.

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
Фаза 11 ─── Унификация API (controllers ✅ / deletion ⏳)  ✅ 11.1–11.4
    │
    ▼
Фаза 12 ─── CLI единый runner + удаление includes/cli/   ✅ ВЫПОЛНЕНА
    │
    ▼
Фаза 13 ─── Streaming micro-optimizations                ✅ 13.1–13.3
    │
    ▼
Фаза 14 ─── CSS/JS partials (footer.php разбиение)       ⏳ НЕ НАЧАТА
    │
    ▼
Фаза 15 ─── Удаление includes/admin.php                  ⏳ НЕ НАЧАТА
```

### Риск-матрица

| Фаза | Риск | Обоснование |
|------|------|-------------|
| 10 | ✅ Завершена | Все 6 шагов выполнены |
| 11 | ✅ Controllers / ⏳ Deletion | 11.1–11.4 — контроллеры + proxies. 11.5–11.6 — REST API extraction + deletion отложены |
| 12 | ✅ Завершена | console.php, 26 Commands, 25 CronJobs, includes/cli/ удалена, LB guards |
| 13 | ✅ 13.1–13.3 / ⏳ 13.4 | ShutdownHandler + StreamAuthMiddleware. Ministra отложена |
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
| **v2.2** | Фаза 10 (удаление admin/) | ✅ Завершена |
| **v2.3** | Фаза 11 (API controllers) + Фаза 12 (CLI runner) + Фаза 13 (streaming) | ✅ Завершена |
| **v2.4** | Фаза 11 (API deletion) + Фаза 14 (CSS/JS partials) + пробелы §11.1–11.3 | 🟢 + 🟢 + 🟡 |
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

> **Статус:** `use_legacy_fallback` реализован в `public/index.php`. `use_legacy_bootstrap` и `use_legacy_api` — **только в документации**, в коде не реализованы. Будут добавлены при выполнении Фазы 15.

---

## 11. Известные пробелы и TODO

> Пробелы, обнаруженные при аудите (2026-03-17). Не входят в конкретную фазу, но блокируют полноценную работу модульной системы и чистоту кодовой базы.

### 11.1. ~~Legacy-остатки: `crons/epg.php`~~ ✅ RESOLVED

**Статус:** ✅ **Решено.** Файл `crons/epg.php` удалён, директория `crons/` удалена. Proxy-файлы `src/status` и `src/tools` также удалены. Все вызовы заменены на `console.php`. Класс `EPG` загружается из `domain/Epg/EPG.php` через autoload.

**Удалённые файлы:** `src/crons/epg.php`, `src/status`, `src/tools`.
**Обновлённые call sites:** `StartupCommand`, `UpdateCommand`, `BalancerCommand`, `dashboard.php`, `install`, `test_installer`, `Makefile`, docs (6 файлов).

### 11.2. EventDispatcher не зарегистрирован в ServiceContainer

**Проблема:** `core/Events/EventDispatcher.php` существует как статический класс, но НЕ зарегистрирован в `bootstrap.php::populateContainer()` как сервис `events`.

**Следствие:** `ModuleInterface::getEventSubscribers()` не может быть реализован модулями — подписки не будут подхвачены, т.к. диспетчер не доступен через контейнер.

**Зарегистрированные сервисы:** `db`, `config`, `settings`, `servers`, `bouquets`, `categories`, `redis`, `translator`.

**TODO:** Зарегистрировать `events` в `populateContainer()` и вызывать `getEventSubscribers()` в `ModuleLoader::bootAll()`.

### 11.3. Navbar.php — модульная навигация

**Проблема:** `MODULE_SYSTEM_SPEC.md §6` описывает `core/Http/Navbar.php` (статический класс с `::registerFromModule()` и `::renderModuleItems()`). Файл **не создан**.

**Следствие:** Пункты навигации модулей (watch, plex, fingerprint, theft-detection) хардкодированы в `public/Views/admin/header.php` строки 273–700. Новый модуль не может добавить себя в меню через `ModuleInterface`.

**TODO:** Создать `core/Http/Navbar.php`, добавить injection point в `header.php`, перенести хардкод навигации модулей в `modules/*/navbar.php`.

### 11.4. Module routes не загружаются в Front Controller

**Проблема:** `ModuleInterface::registerRoutes(Router $router)` объявлен в контракте, но `public/index.php` **не вызывает** `ModuleLoader::bootAll()` для регистрации маршрутов модулей. Модули могут регистрировать только CLI-команды (через `console.php`).

**Статус:** По текущему дизайну модули не добавляют admin-страницы — только CLI и API. Если это изменится, потребуется вызов `$moduleLoader->registerRoutes($router)` в FC.

### 11.5. Ministra JS — 84 файла не перемещены

**Проблема:** 84 JS-файла Ministra-портала остаются в `src/ministra/` корне (не в `modules/ministra/assets/`). Симлинк `www/c → ministra/` (создаётся `RootSignalsCronJob::enable_ministra`) ломается при перемещении.

**Зависимости:** Требуется nginx alias + изменения в signal handler. Отложено как 13.4.

### 11.6. BalancerCommand — sentinel проверяется на локальном сервере

**Проблема:** `BalancerCommand` после SSH-извлечения tar-архива на *удалённом* LB-сервере проверяет `file_exists(MAIN_HOME . 'console.php')` на *локальном* сервере. Проверка всегда `true` на MAIN, поэтому не обнаруживает реальные сбои деплоя на удалённой машине.

**Затронутые строки:** `cli/Commands/BalancerCommand.php` — два блока `if (file_exists(...))` после `ssh ... tar xzf`.

**TODO:** Заменить `file_exists()` на `ssh $host "test -f /home/xc_vm/console.php"` или проверять exit code SSH-команды tar.

### 11.7. Сводная таблица

| # | Пробел | Приоритет | Блокирует | Усилия |
|---|--------|-----------|-----------|--------|
| 11.1 | ~~`crons/epg.php` не удалён~~ ✅ | — | — | — |
| 11.2 | EventDispatcher не в контейнере | 🟡 Средний | Module event subscribers | 30 мин |
| 11.3 | Navbar.php не создан | 🟡 Средний | Module navigation | 2–4 ч |
| 11.4 | Module routes в FC | 🟢 Низкий | Ничего (by design) | — |
| 11.5 | Ministra JS не перемещены | 🟢 Низкий | Ничего | 2–4 ч |
| 11.6 | BalancerCommand sentinel на локальном хосте | 🟡 Средний | Ложный success при деплое LB | 30 мин |
| 11.5–11.6 (Phase 11) | Admin/Reseller REST API extraction | 🟡 Средний | Удаление `www/*.php` thin proxies | 8–16 ч |
| Phase 14 | CSS/JS partials (footer.php) | 🟢 Низкий | Ничего (frontend-only) | 4–8 ч |
| Phase 15 | Удаление `includes/admin.php` (~3060 стр.) | 🔴 Высокий | Финальный milestone v3.0 | 16–32 ч |
