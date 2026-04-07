# XC_VM — План миграции

> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).
> Обновлено: 2026-04-07

## Содержание

1. [Принцип миграции](#1-принцип-миграции)
2. [Завершённые фазы (0–13)](#2-завершённые-фазы-013)
3. [Фаза 14: CSS/JS partials](#3-фаза-14-cssjs-partials)
4. [Фаза 15: Удаление includes/admin.php](#4-фаза-15-удаление-includesadminphp)
5. [Отложенные подшаги](#5-отложенные-подшаги)
6. [Известные пробелы и TODO](#6-известные-пробелы-и-todo)
7. [Стратегия и релизы](#7-стратегия-и-релизы)

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

## 2. Завершённые фазы (0–13)

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

**Новые классы (Phase 8):** SettingsManager, RequestManager, ConfigReader, DatabaseFactory, RedisManager, FfmpegPaths, FileCache, DataEncryptor, InputSanitizer, IpUtils, UrlBuilder, ImageUtils, Helpers, ProcessManager, ConnectionManager, BackupService, ProviderService, ProfileService, RadioService, SystemCheck, InputValidator.

---

## 3. Фаза 14: CSS/JS partials — разбиение footer.php

> **Цель:** Вынести ~800 строк page-specific inline JS из `footer.php` в отдельные файлы. footer.php остаётся < 100 строк (layout-only).

#### 14.1 — Аудит inline JS в footer.php

1. Список всех `<script>` блоков с привязкой к `$_TITLE` / page
2. Подсчёт строк по блокам
3. Группировка: общий JS vs page-specific JS

#### 14.2 — Извлечение page-specific JS

```
// Было:
<?php if ($_TITLE == 'streams'): ?>
<script>// 200 строк</script>
<?php endif; ?>

// Стало:
// public/assets/js/pages/streams.js
// footer.php: <script src="assets/js/pages/<?= $_TITLE ?>.js"></script>
```

1. Для каждого page-блока → `public/assets/js/pages/{page}.js`
2. Общий JS (DataTables init, modals, AJAX helpers) → `public/assets/js/common.js`

#### 14.3 — Минификация (опционально)

1. `make js-minify` target
2. terser для `assets/js/pages/*.js`
3. Версионирование: `?v={hash}`

---

## 4. Фаза 15: Удаление includes/admin.php — финальный legacy bootstrap

> **Цель:** `includes/admin.php` (~3060 стр.) удалён. Весь bootstrap через `XC_Bootstrap::boot()`.

#### 15.1 — Аудит зависимостей

1. `grep -rn "include.*admin\.php\|require.*admin\.php" src/`
2. Для каждого подключения: что ожидается (переменные, функции, сессия)?
3. Что живёт ТОЛЬКО в admin.php и не имеет замены?

#### 15.2 — Перенос оставшихся функций

- глобальные утилиты → `includes/admin_functions.php` или domain-сервисы
- `$language` → `core/I18n/Translator.php`
- session → `core/Auth/SessionManager.php`
- `$rPermissions` → `domain/Auth/AuthorizationService.php`

#### 15.3 — Переключение bootstrap

1. `BaseAdminController::before()` / `BaseResellerController::before()` → только `XC_Bootstrap::boot(CONTEXT_ADMIN)`
2. Feature flag: `use_legacy_bootstrap = false`
3. Тестирование всех 134 страниц

#### 15.4 — Удаление

1. `rm src/includes/admin.php`
2. Удалить все `require 'admin.php'`
3. Убрать dual bootstrap ветку из `bootstrap.php`
4. `php -l` + smoke test

---

## 5. Отложенные подшаги

| Шаг | Суть | Блокер | Усилия |
|-----|------|--------|--------|
| **11.5** | Admin/Reseller REST API → единые маршруты | APIWrapper конфликт namespace (2600+ стр.) | 8–16 ч |
| **11.6** | Удаление `www/*.php` thin proxies | Зависит от 11.5 + nginx rewrites | 2–4 ч |
| **13.4** | Ministra JS → modules/ministra/assets/ | Ломает symlink www/c, нужен nginx alias | 2–4 ч |

---

## 6. Известные пробелы и TODO

> Актуализировано: 2026-04-07.

### ~~6.1. Legacy-остатки: crons/epg.php~~ ✅

Решено. Директория crons/ удалена, все вызовы → console.php, EPG → domain/Epg/EPG.php.

### ~~6.2. EventDispatcher не в ServiceContainer~~ ✅

Решено (2026-04-07). `EventDispatcher::class` зарегистрирован как `events` в `populateContainer()`. `ModuleLoader::bootAll()` использует `EventDispatcher::subscribe()`.

### 6.3. Navbar.php — модульная навигация

**Проблема:** `core/Http/Navbar.php` не создан. Навигация модулей хардкодирована в `header.php` (строки 273–700).

**TODO:** Создать `Navbar.php` с `::registerFromModule()` + `::renderModuleItems()`, добавить injection point в header.php.

### 6.4. Module routes не загружаются в FC

По текущему дизайну модули не добавляют admin-страницы — только CLI и API. Не блокирует.

### 6.5. Ministra JS — 84 файла не перемещены

84 JS-файла в `src/ministra/`. Symlink `www/c → ministra/` ломается при перемещении. Отложено как 13.4.

### ~~6.6. BalancerCommand sentinel~~ ✅

Решено. Обе ветки (type 1 и type 2) используют `$this->runSSH($rConn, 'test -f ...')` — проверка на удалённом сервере.

### Сводная таблица

| # | Пробел | Статус | Блокирует | Усилия |
|---|--------|--------|-----------|--------|
| 6.1 | crons/epg.php | ✅ | — | — |
| 6.2 | EventDispatcher в контейнере | ✅ | — | — |
| 6.3 | Navbar.php | ⏳ | Module navigation | 2–4 ч |
| 6.4 | Module routes в FC | 🟢 by design | — | — |
| 6.5 | Ministra JS | ⏳ | — | 2–4 ч |
| 6.6 | BalancerCommand sentinel | ✅ | — | — |
| §11.5 | Admin/Reseller REST API | ⏳ | Удаление www/ proxies | 8–16 ч |
| Phase 14 | CSS/JS partials | ⏳ | frontend-only | 4–8 ч |
| Phase 15 | Удаление admin.php | ⏳ | Финальный milestone | 16–32 ч |

---

## 7. Стратегия и релизы

### Dual bootstrap (текущее состояние)

```
bootstrap.php          includes/admin.php (legacy)
  ├── autoload.php       ├── ~3060 строк legacy init
  ├── ServiceContainer   ├── session, permissions
  └── core/domain/       └── global $db, define()
```

`bootstrap.php` загружается первым. `includes/admin.php` — для ещё не мигрированного legacy-кода. Удаляется в Фазе 15.

### Feature flags

- `use_legacy_fallback` — FC legacy routing (реализован в `public/index.php`)
- `use_legacy_bootstrap` — для Фазы 15 (планируется)

### Rollback plan

```
1. git revert последний merge
2. make new && make lb
3. Деплой предыдущей сборки
```

### Порядок оставшихся фаз

```
Phase 14 ─── CSS/JS partials (footer.php)          🟢 Низкий риск
    │
    ▼
Phase 11 ─── API deletion (11.5 + 11.6)            🟡 Средний риск
    │
    ▼
Phase 15 ─── Удаление includes/admin.php            🔴 Высокий риск
```

### Релизы

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v2.4** | Phase 14 (CSS/JS) + Phase 11 (API deletion) + пробелы 6.3 | 🟢 + 🟡 |
| **v3.0** | Phase 15 (удаление legacy bootstrap) | 🔴 Финальный |
