# XC_VM — План миграции

> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).
> Обновлено: 2026-04-09

## Содержание

1. [Принцип миграции](#1-принцип-миграции)
2. [Завершённые фазы (0–16)](#2-завершённые-фазы-016)
3. [Отложенные подшаги](#3-отложенные-подшаги)
4. [Известные пробелы и TODO](#4-известные-пробелы-и-todo)
5. [Стратегия и релизы](#5-стратегия-и-релизы)

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

## 2. Завершённые фазы (0–16)

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

## 3. Отложенные подшаги

| Шаг | Суть | Блокер | Усилия |
|-----|------|--------|--------|
| **11.5** | Admin/Reseller REST API → единые маршруты | APIWrapper конфликт namespace (2600+ стр.) | 8–16 ч |
| **11.6** | Удаление `www/*.php` thin proxies | Зависит от 11.5 + nginx rewrites | 2–4 ч |
| **13.4** | Ministra JS → modules/ministra/assets/ | Ломает symlink www/c, нужен nginx alias | 2–4 ч |

---

## 4. Известные пробелы и TODO

> Актуализировано: 2026-04-09.

### 6.3. Navbar.php — модульная навигация

**Проблема:** `core/Http/Navbar.php` не создан. Навигация модулей хардкодирована в `header.php` (строки 273–700).

**TODO:** Создать `Navbar.php` с `::registerFromModule()` + `::renderModuleItems()`, добавить injection point в header.php.

### 6.5. Ministra JS — 84 файла не перемещены

84 JS-файла в `src/ministra/`. Symlink `www/c → ministra/` ломается при перемещении. Отложено как 13.4.

### Сводная таблица

| # | Пробел | Статус | Блокирует | Усилия |
|---|--------|--------|-----------|--------|
| 6.3 | Navbar.php | ⏳ | Module navigation | 2–4 ч |
| 6.5 | Ministra JS | ⏳ | — | 2–4 ч |
| §11.5 | Admin/Reseller REST API | ⏳ | Удаление www/ proxies | 8–16 ч |

---

## 5. Стратегия и релизы

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
Phase 11 ─── API deletion (11.5 + 11.6)            🟡 Средний риск
    │
    ▼
Phase 13 ─── Ministra JS (13.4)                    🟢 Низкий риск
```

### Релизы

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v2.4** | Phase 14 + Phase 15 + Phase 16 | ✅ Выпущен |
| **v2.5** | Phase 11 (API deletion) + пробелы 6.3, 6.5, 13.4 | 🟡 |
