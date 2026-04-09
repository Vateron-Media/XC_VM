# XC_VM — План миграции

> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).
> Обновлено: 2026-04-09

## Содержание

1. [Принцип миграции](#1-принцип-миграции)
2. [Завершённые фазы (0–14, 16)](#2-завершённые-фазы-014-16)
3. [Фаза 14: CSS/JS partials](#3-фаза-14-cssjs-partials)
4. [Фаза 15: Ликвидация src/includes/](#4-фаза-15-ликвидация-srcincludes)
5. [Фаза 16: Удаление includes/admin.php](#5-фаза-16-удаление-includesadminphp)
6. [Отложенные подшаги](#6-отложенные-подшаги)
7. [Известные пробелы и TODO](#7-известные-пробелы-и-todo)
8. [Стратегия и релизы](#8-стратегия-и-релизы)

---

## 1. Принцип миграции

Каждый шаг: **extract → delegate → verify → replace**.

```
1. Создать новый класс
2. Старый код → proxy на новый
3. Проверить работу
4. Обновить вызывающий код → удалить proxy
```

Откат: удалить новый класс + убрать proxy.

---

## 2. Завершённые фазы (0–14, 16)

| Фаза | Суть | Ключевой результат |
|------|------|--------------------|
| **0** | Подготовка | autoload, bootstrap.php, ServiceContainer, 7 core-файлов |
| **1** | Извлечение core/ | Database, Cache, Http, Auth, Process, Util + 8 доп. компонентов |
| **2** | Дедупликация CU ↔ SU | 53 дублированных метода устранены |
| **3** | Извлечение domain/ | 12 контекстов: Stream, Vod, Line, User, Device, Server, Bouquet, Epg, Settings, Security, Auth, Playlist |
| **4** | Извлечение streaming/ | streaming/Auth, Delivery, Codec, Protection, StreamingBootstrap (hot path) |
| **5** | Модули | 6 модулей: plex, watch, tmdb, ministra, fingerprint, theft-detection. ModuleInterface + ModuleLoader |
| **6** | Контроллеры + Views | 112 admin + 22 reseller страниц, Router, Front Controller, BaseAdminController |
| **7** | admin.php bootstrap | Вынос данных, замена процедурного bootstrap → XC_Bootstrap, удаление 40 proxy, admin.php: 4448→3050 строк |
| **8** | Ликвидация god-объектов | admin_api.php (3686 стр.), StreamingUtilities (659 стр.), CoreUtilities (1971 стр.) — все три удалены. ~7400 вызовов заменены |
| **9** | Стабилизация сборки | Makefile LB-сборка, PHP-заголовки ($__viewMode), unified layout, экспорт глобалов |
| **10** | Удаление admin/ | 127 PHP + 423 static → public/Views/admin/ + public/assets/admin/. Директория src/admin/ удалена |
| **11** | Унификация API (11.1–11.4) | 7 API-контроллеров (2313 стр.), 6 thin proxies в www/. PlayerApi, Enigma2, XPlugin, Playlist, Epg, InternalApi |
| **12** | CLI runner | console.php + 26 Commands + 25 CronJobs. includes/cli/ и crons/ удалены. LB guards |
| **13** | Streaming (13.1–13.3) | ShutdownHandler, StreamAuthMiddleware, micro-router. Hot path не затронут |
| **14** | CSS/JS partials | footer.php: 810→85 строк, common.js (730 стр.), XC_VM.Config bridge |
| **16** | Удаление admin.php + proxy | 121 функция → 17 классов, 869 замен в 160+ файлах, admin_proxies.php удалён |

**Новые классы (Phase 8):** SettingsManager, RequestManager, ConfigReader, DatabaseFactory, RedisManager, FfmpegPaths, FileCache, DataEncryptor, InputSanitizer, IpUtils, UrlBuilder, ImageUtils, Helpers, ProcessManager, ConnectionManager, BackupService, ProviderService, ProfileService, RadioService, SystemCheck, InputValidator.

---

## 3. Фаза 14: CSS/JS partials — разбиение footer.php ✅

> **Завершено:** 2026-04-07. footer.php: 810 → 85 строк.

#### Результат

**Открытие при аудите:** footer.php НЕ содержал page-specific `$_TITLE` блоков. Весь JS (~745 строк) — общие утилиты для всех admin-страниц.

**Что сделано:**

1. **`public/assets/admin/js/common.js`** — вынесены все общие функции (~730 строк):
   - UI: `hideModal`, `showError`, `showSuccess`, `refreshTooltips`, `setSwitch`
   - Validation: `isValidDomain`, `isValidIP`, `isValidDate`, `isNumberKey`
   - SPA navigation: `navigate`, `reloadMenu`, `bindHref`, `killTimeouts`, `deleteSwitches`, `deleteSelect2`
   - URL: `setURL`, `delParam(s)`, `setParam`, `getParam`, `hasParam(s)`
   - Session: `pingSession` (30s heartbeat)
   - Modals: `modalFingerprint`, `setModalFingerprint`, `addCredits`, `submitCredits`, `editModal`, `closeEditModal`
   - API: `headerStats` (1s polling), `searchAPI` (stream/movie/episode/user/line), `whois`
   - Search: `initSearch` (Select2 AJAX)
2. **footer.php** — inline `<script>` заменён на:
   - Маленький PHP-конфиг `XC_VM.Config` (jsNavigate + i18n строки через `json_encode()`)
   - `<script src="assets/js/common.js"></script>`
3. **PHP → JS bridge:** 6 вызовов `$language::get()` + `$rSettings['js_navigate']` → `window.XC_VM.Config`
4. Глобальные переменные (`rSwitches`, `jBoxes`, etc.) → `window.*` для совместимости со страничными скриптами

---

## 4. Фаза 15: Ликвидация src/includes/ — распределение по архитектуре

> **Цель:** Полностью удалить директорию `src/includes/`, распределив содержимое по `core/`, `domain/`, `modules/`, `streaming/`, `config/`, `bin/`. Стратегия: proxy-redirect (переместить + оставить тонкий `require_once` на старом месте → потом удалить proxy).

> **Статус:** ✅ Фаза 15 полностью завершена (15.0–15.6). Директория `src/includes/` удалена. Константа `INCLUDES_PATH` удалена.

#### 15.0 — Удаление пустых файлов ✅

| Файл | Действие |
|------|----------|
| `admin_new.php` | N/A — уже не существует |
| `.do_swap.sh` | N/A — уже не существует |

#### 15.1 — Перенос библиотек (libs/) ✅

| Файл | Новое расположение | Обоснование |
|------|-------------------|-------------|
| `libs/Translator.php` | `core/Localization/Translator.php` | Core i18n |
| `libs/Logger.php` | `core/Logging/Logger.php` | Уже есть `core/Logging/` |
| `libs/GithubReleases.php` | `core/Updates/GithubReleases.php` | Обновления = core |
| `libs/AsyncFileOperations.php` | `streaming/AsyncFileOperations.php` | Используется только streaming |
| `libs/Dropbox.php` | `core/Storage/DropboxClient.php` | Backup storage |
| `libs/XmlStringStreamer.php` | `core/Parsing/XmlStringStreamer.php` | XML парсинг = core utility |
| `libs/mobiledetect.php` | `core/Device/MobileDetect.php` | Device detection |
| `libs/m3u.php` | `domain/Stream/M3UEntry.php` | M3U парсинг |
| `libs/m3u_v2.php` | `domain/Stream/M3UParser.php` | M3U v2 (✅ hardcoded paths исправлены на `__DIR__`) |
| `libs/resources/*` | `domain/Stream/resources/` | Данные для M3U-парсера |

#### 15.2 — Перенос TMDb-пакета в модуль ✅

| Файл | Новое расположение |
|------|-------------------|
| `libs/tmdb.php` | `modules/tmdb/lib/TmdbClient.php` |
| `libs/tmdb_release.php` | `modules/tmdb/lib/Release.php` |
| `libs/TMDb/*.php` (10 entity) | `modules/tmdb/lib/Entities/` |
| `libs/TMDb/config/` | `modules/tmdb/lib/config/` |
| `libs/TMDb/roles/` | `modules/tmdb/lib/roles/` |

#### 15.3 — Перенос API-контроллеров и данных ✅

| Файл | Новое расположение | Обоснование |
|------|-------------------|-------------|
| `api/admin/table.php` | `public/Controllers/Admin/TableController.php` | Presentation layer |
| `api/reseller/table.php` | `public/Controllers/Reseller/TableController.php` | Presentation layer |
| `reseller_api.php` | `infrastructure/legacy/reseller_api.php` | Утилитарный класс `ResellerAPI` (не контроллер) |
| `data/permissions.php` | `config/permissions.php` | Конфигурация |
| `ts.php` | `streaming/TimeshiftClient.php` | Streaming domain |

#### 15.4 — Перенос Python-утилит ✅

| Файл | Новое расположение |
|------|-------------------|
| `python/release.py` | `bin/python/release.py` |
| `python/PTN/` | `bin/python/PTN/` |

#### 15.5 — admin.php → infrastructure/legacy/ ✅

| Файл | Новое расположение | Причина |
|------|-------------------|---------|
| `admin.php` | `infrastructure/legacy/admin.php` | Legacy bootstrap, 70+ callers |
| `admin_functions.php` | N/A — не существует | Упоминался в плане, но файл отсутствует |

> После 15.5: на старых местах остаются proxy-файлы (32 шт.). Proxy удаляются в Фазе 16.
> Makefile обновлён: `infrastructure/legacy/admin.php` и `infrastructure/legacy/reseller_api.php` добавлены в `LB_FILES_TO_REMOVE`.

#### 15.6 — Удаление src/includes/ ✅

> **Завершено:** 2025-07-06. Директория `src/includes/` полностью удалена (40 файлов: 34 proxy + 6 оригиналов).

Выполнено:

1. 7 мёртвых `shell_exec()` вызовов (`INCLUDES_PATH . 'cli/...'`) заменены на `MAIN_HOME . 'console.php ...'` в:
   `VodCronJob`, `SeriesService`, `MovieService`, `RootSignalsCronJob`, `WatchCron`, `PlexCron`
2. `INCLUDES_PATH` define удалён из `LoopbackCommand`, `LlodCommand`
3. Autoloader: убраны `addDirectory('includes')` и `addDirectory('includes/libs')`
4. Константа `INCLUDES_PATH` удалена из `core/Config/Paths.php`
5. `rm -rf src/includes/` — удалены все 40 файлов
6. Makefile: убраны `includes/admin_api.php`, `includes/admin.php`, `includes/reseller_api.php` из `LB_FILES_TO_REMOVE`
7. `php -l` всех 10 модифицированных файлов — 0 ошибок

> **Сохранены** маршрутные идентификаторы nginx: `includes/api/admin` и `includes/api/reseller` в `index.php` и `AuthRepository.php` — это протокольные значения, не файловые пути.

---

## 5. Фаза 16: Удаление includes/admin.php — финальный legacy bootstrap ✅

> **Завершено:** 2026-04-08. `infrastructure/legacy/admin.php` удалён. Весь bootstrap через `XC_Bootstrap::boot()`.
> admin.php: 3060 → 2641 → 2135 → 1131 → 518 → 27 → **удалён**.

#### 16.1 — Аудит зависимостей ✅

1. `grep -rn "include.*admin\.php\|require.*admin\.php" src/` — найдено 18 callers
2. Для каждого подключения: что ожидается (переменные, функции, сессия)?
3. Что живёт ТОЛЬКО в admin.php и не имеет замены? → Ничего. Все 121 функция извлечены.

#### 16.2 — Перенос оставшихся функций ✅

- 121 функция извлечена в 17 целевых классов (core/, domain/)
- Новые файлы: `core/Database/QueryHelper.php`, `core/Util/AdminHelpers.php`, `core/Auth/PageAuthorization.php`, `core/Http/ApiClient.php`, `domain/Vod/TMDbService.php`, `domain/User/TicketRepository.php`
- 11 существующих файлов расширены: ServerService, ServerRepository, BackupService, BlocklistService, DiagnosticsService, StreamService, ConnectionTracker, SettingsManager, CategoryService, ProviderService, EpgService и др.
- admin.php: 3060 → 518 строк (все функции → one-liner proxies)

#### 16.3 — Переключение bootstrap ✅

1. Создан `infrastructure/legacy/admin_proxies.php` (121 proxy, 508 строк)
2. Добавлен `XC_Bootstrap::loadLegacyProxies()` — идемпотентный метод, вызывается в конце CONTEXT_ADMIN boot
3. Переключены все 18 callers: 3 bootstrap + 5 CronJobs/CLI + 10 FC/Controllers/Views
4. admin.php: 518 → 27 строк (backward-compat wrapper)

#### 16.4 — Удаление ✅

1. `rm src/infrastructure/legacy/admin.php` — файл удалён (0 runtime callers)
2. Все `require 'admin.php'` заменены в Phase 16.3
3. BaseAdminController / BaseResellerController: `function_exists()` guards → прямые вызовы `PageAuthorization`, `AdminHelpers`, `Authorization`
4. Makefile: `admin.php` → `admin_proxies.php` в `LB_FILES_TO_REMOVE`
5. `php -l` — all pass

#### 16.5 — Ликвидация proxy-функций ✅

> **Завершено:** 2026-04-09. `admin_proxies.php` удалён. `loadLegacyProxies()` удалён.

1. 869 замен в 160+ файлах: все proxy-функции (`goHome()`, `getBouquet()`, `deleteStream()`, `APIRequest()`, `systemapirequest()`, `checkPermissions()`, `destroySession()` и 114 др.) заменены прямыми вызовами классов.
2. `function_exists()` guards удалены из BaseAdminController, BaseResellerController, BouquetController и др.
3. `admin_proxies.php` — файл удалён.
4. `XC_Bootstrap::loadLegacyProxies()` — метод удалён из bootstrap.php.
5. Вызовы `loadLegacyProxies()` удалены из 5 CronJobs/Commands.
6. Makefile: `admin_proxies.php` убран из `LB_FILES_TO_REMOVE`.
7. SQL-запросы: конкатенация `'[' . implode(...) . ']'` → `?` placeholders (BouquetService, CategoryService).
8. PHP 8.x warnings: null-safe access fixes (live.php, server_view.php, stream.php).

---

## 6. Отложенные подшаги

| Шаг | Суть | Блокер | Усилия |
|-----|------|--------|--------|
| **11.5** | Admin/Reseller REST API → единые маршруты | APIWrapper конфликт namespace (2600+ стр.) | 8–16 ч |
| **11.6** | Удаление `www/*.php` thin proxies | Зависит от 11.5 + nginx rewrites | 2–4 ч |
| **13.4** | Ministra JS → modules/ministra/assets/ | Ломает symlink www/c, нужен nginx alias | 2–4 ч |

---

## 7. Известные пробелы и TODO

> Актуализировано: 2026-04-09.

### 6.3. Navbar.php — модульная навигация

**Проблема:** `core/Http/Navbar.php` не создан. Навигация модулей хардкодирована в `header.php` (строки 273–700).

**TODO:** Создать `Navbar.php` с `::registerFromModule()` + `::renderModuleItems()`, добавить injection point в header.php.

### 6.4. Module routes не загружаются в FC

По текущему дизайну модули не добавляют admin-страницы — только CLI и API. Не блокирует.

### 6.5. Ministra JS — 84 файла не перемещены

84 JS-файла в `src/ministra/`. Symlink `www/c → ministra/` ломается при перемещении. Отложено как 13.4.

### Сводная таблица

| # | Пробел | Статус | Блокирует | Усилия |
|---|--------|--------|-----------|--------|
| 6.3 | Navbar.php | ⏳ | Module navigation | 2–4 ч |
| 6.4 | Module routes в FC | 🟢 by design | — | — |
| 6.5 | Ministra JS | ⏳ | — | 2–4 ч |
| §11.5 | Admin/Reseller REST API | ⏳ | Удаление www/ proxies | 8–16 ч |

---

## 8. Стратегия и релизы

### Unified bootstrap (текущее состояние)

```
bootstrap.php (единственный entry point)
  ├── autoload.php
  ├── ServiceContainer
  └── core/domain/
```

`bootstrap.php` — единственный bootstrap. Все legacy-файлы удалены:
- `infrastructure/legacy/admin.php` — удалён (Phase 16.4)
- `infrastructure/legacy/admin_proxies.php` — удалён (Phase 16.5)
- `includes/admin.php` — удалён (Phase 15.6)

Proxy-функций больше нет. Весь код использует прямые вызовы классов.

### Feature flags

- `use_legacy_fallback` — FC legacy routing (реализован в `public/index.php`)

### Rollback plan

```
1. git revert последний merge
2. make new && make lb
3. Деплой предыдущей сборки
```

### Порядок оставшихся фаз

```
Phase 14 ─── CSS/JS partials (footer.php)          ✅ Завершено
    │
    ▼
Phase 16 ─── Удаление legacy admin.php + proxy     ✅ Завершено
    │
    ▼
Phase 15 ─── Ликвидация src/includes/              ✅ Завершено
    │
    ▼
Phase 11 ─── API deletion (11.5 + 11.6)            🟡 Средний риск
```

### Релизы

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v2.4** | Phase 14 ✅ + Phase 15 ✅ + Phase 16 (вкл. 16.5 proxy elimination) ✅ | ✅ |
| **v2.5** | Phase 11 (API deletion) + пробелы 6.3 | 🟡 |
