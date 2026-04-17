# MODULES.md

```text
┌────────────────────────────────────────────────────────────────────┐
│ XC_VM / MODULES / SPEC                                            │
│ АКТУАЛЬНЫЙ ПЛАН РЕФАКТОРИНГА И РАЗВИТИЯ МОДУЛЕЙ                   │
│ СТИЛЬ: MINIMAL BRUTALISM                                          │
└────────────────────────────────────────────────────────────────────┘
```

> Язык документа: русский.
> Этот файл описывает текущее состояние модульной системы и только актуальный roadmap.
> Никакие секреты, токены и API-ключи не должны попадать в клиентскую часть модулей.
> Обновлено: 2026-04-17

## Содержание

1. [Текущее состояние](#1-текущее-состояние)
2. [Что уже реализовано](#2-что-уже-реализовано)
3. [Что ещё не доведено до цели](#3-что-ещё-не-доведено-до-цели)
4. [Целевая модель модулей](#4-целевая-модель-модулей)
5. [Пошаговый план рефакторинга](#5-пошаговый-план-рефакторинга)
6. [Правила разработки модулей](#6-правила-разработки-модулей)
7. [Definition of Done](#7-definition-of-done)

---

## 1. Текущее состояние

```text
СИСТЕМА МОДУЛЕЙ В XC_VM УЖЕ СУЩЕСТВУЕТ.
ПРОБЛЕМА НЕ В ОТСУТСТВИИ МОДУЛЕЙ,
А В НЕПОЛНОЙ WEB-ИНТЕГРАЦИИ И LEGACY-СЦЕПЛЕНИЯХ.
```

На текущий момент:

- модули обнаруживаются через `modules/*/module.json`
- CLI-интеграция работает
- есть UI для install, uninstall, enable, disable, update и upload ZIP
- часть модульных маршрутов уже описана в самих модулях
- web runtime всё ещё не использует модульный boot как основной путь

---

## 2. Что уже реализовано

| Компонент | Статус | Примечание |
| --------- | ------ | ---------- |
| `ModuleInterface` | `ГОТОВО` | Базовый контракт присутствует |
| `ModuleLoader::loadAll()` | `ГОТОВО` | Auto-discovery по `module.json` |
| `ModuleLoader::registerAllCommands()` | `ГОТОВО` | CLI-команды модулей реально подключаются |
| `ModuleLoader::bootAll()` | `ЧАСТИЧНО` | Реализован, но не встроен в `public/index.php` |
| `config/modules.php` | `ГОТОВО` | Overrides для enabled/class |
| `ModuleManager` | `ГОТОВО` | Список, install, uninstall, enable, disable, update, upload |
| Админ-страница модулей | `ГОТОВО` | `ModulesController` + `Views/admin/modules.php` |
| Регистрация `events` в контейнере | `ГОТОВО` | Это больше не backlog |
| 7 модулей в `src/modules/` | `ГОТОВО` | `watch`, `plex`, `tmdb`, `ministra`, `fingerprint`, `theft-detection`, `magscan` |

```text
ВЫВОД:
УПРАВЛЕНИЕ МОДУЛЯМИ УЖЕ ЕСТЬ.
ПЕРЕПИСЫВАТЬ ЕГО С НУЛЯ НЕ НУЖНО.
```

---

## 3. Что ещё не доведено до цели

### 3.1. Web boot модулей не включён

- `public/index.php` не вызывает `ModuleLoader::loadAll()` и `bootAll()`
- из-за этого `boot()` и `registerRoutes()` не являются реальным источником web routing

### 3.2. Маршруты модулей продублированы в ядре

- `watch`, `plex`, `fingerprint`, `theft_detection` и связанные страницы зарегистрированы статически в `public/routes/admin.php`
- это делает модульную маршрутизацию вторичной и неавторитетной

### 3.3. Навигация модулей хардкодится в `header.php`

- пункты `Plex Sync`, `Watch`, `Fingerprint` и часть связанных ссылок зашиты в `public/Views/admin/header.php`
- модуль не может сам заявить навигацию как контракт

### 3.4. `module.json` слишком бедный

Сейчас manifest хранит только:

- `name`
- `description`
- `version`
- `requires_core`

Этого недостаточно для:

- фильтрации по окружению `main` и `lb`
- декларации зависимостей между модулями
- описания navbar/settings capabilities

### 3.5. Core patching остаётся аварийным обходным путём

- `CoreCodePatchableModuleInterface` и `CoreCodePatcher` уже есть
- это допустимо только как временный stopgap
- целевая архитектура не должна расширяться заменой файлов ядра

### 3.6. Ministra остаётся особым случаем

- часть поведения завязана на `www/c` и `www/portal.php`
- модуль `ministra` есть, но интеграция не доведена до единой модульной модели

---

## 4. Целевая модель модулей

```text
ЦЕЛЬ:
МОДУЛЬ = ИЗОЛИРОВАННАЯ ДИРЕКТОРИЯ + ДЕКЛАРАТИВНЫЙ MANIFEST +
РЕАЛЬНЫЙ WEB/CLI LIFECYCLE БЕЗ ПАТЧИНГА ЯДРА.
```

### 4.1. Целевой lifecycle

1. `ModuleLoader::loadAll()`
2. `ModuleLoader::bootAll($container, $router)` в web-контексте
3. `ModuleLoader::registerAllCommands($registry)` в CLI-контексте
4. navbar, settings и hooks строятся из декларации модуля
5. ядро не содержит hardcoded пунктов модульной логики

### 4.2. Целевой manifest v2

```json
{
  "name": "watch",
  "description": "Watch activity tracking module",
  "version": "1.1.0",
  "requires_core": ">=2.0",
  "environment": "main",
  "dependencies": [],
  "has_navbar": true,
  "has_settings": true
}
```

### 4.3. Целевая интеграция UI

Модуль должен уметь декларативно регистрировать:

- маршруты
- команды
- event subscribers
- navbar items
- страницу настроек

Без прямого редактирования:

- `public/routes/admin.php`
- `public/Views/admin/header.php`
- файлов ядра в `core/`

---

## 5. Пошаговый план рефакторинга

```text
PHASE M-1  /  WEB BOOT
```

1. Подключить `ModuleLoader::loadAll()` и `bootAll()` в `public/index.php` после загрузки статических route files.
2. Добавить dev-guard на конфликты маршрутов.
3. Зафиксировать правило приоритета: core route first, module route second, затем планомерное удаление legacy-дубликатов.

**Результат:** web runtime впервые начинает реально использовать lifecycle модулей.

---

```text
PHASE M-2  /  ROUTE CUTOVER
```

1. Удалить из `public/routes/admin.php` маршруты, уже описанные в модулях.
2. Для каждого удаления подготовить smoke-check страницы и API-действий.
3. Включить логирование route collisions на переходный период.

**Результат:** источник истины по маршрутам перемещается в модули.

---

```text
PHASE M-3  /  NAVBAR API
```

1. Создать extension points для header navigation.
2. Вынести builder/render слой модульной навигации из `header.php`.
3. Добавить декларацию navbar items на стороне модулей.
4. Удалить hardcoded ссылки `plex`, `watch`, `fingerprint` из header runtime.

**Результат:** ядро перестаёт знать о конкретных модулях на уровне меню.

---

```text
PHASE M-4  /  MANIFEST V2
```

1. Расширить `module.json` полями `environment`, `dependencies`, `has_navbar`, `has_settings`.
2. Научить `ModuleLoader` фильтрации по окружению и dependency sort.
3. Оставить старый формат manifest backward-compatible на один релиз.

**Результат:** модульная система получает управляемый порядок загрузки и awareness по окружению.

---

```text
PHASE M-5  /  HOOKS INSTEAD OF PATCHES
```

1. Инвентаризировать, где реально нужен `CoreCodePatcher`.
2. Для каждого кейса ввести hook point в ядре или публичный extension contract.
3. Запретить новые модули, требующие full-file patching core-файлов.

**Результат:** модульная система становится расширяемой без замены файлов ядра.

---

```text
PHASE M-6  /  MINISTRA NORMALIZATION
```

1. Перевести `ministra` на единые asset/runtime правила.
2. Убрать управление `www/c` и `www/portal.php` из module lifecycle.
3. Завести отдельную документированную integration boundary для MAG и portal compatibility.

**Результат:** Ministra перестаёт быть legacy-исключением внутри модульной модели.

---

## 6. Правила разработки модулей

### 6.1. Архитектурные правила

1. Модуль зависит только от `core/`, `domain/` и публичных extension contracts.
2. Модуль не модифицирует `core/` и `domain/` напрямую.
3. Модуль не ходит в БД мимо agreed service/repository boundary, если такой boundary уже существует.
4. CLI-регистрация всегда явная через `registerCommands()`.
5. Web-интеграция всегда проходит через `boot()` и `registerRoutes()`.

### 6.2. UI-правила

1. Модуль не вставляет секреты, API-ключи, служебные токены и внутренние endpoint credentials в HTML, JS, CSS и шаблоны.
2. Клиентская часть получает только публичные флаги и данные, уже разрешённые сервером.
3. Настройки модуля рендерятся через server-side flow, а не через захардкоженные JS-константы с чувствительными данными.

### 6.3. Правило маршрутов

```text
НОВЫЙ ROUTE В МОДУЛЕ НЕЛЬЗЯ ДОБАВЛЯТЬ В public/routes/admin.php.
НОВЫЙ ROUTE ОБЯЗАН ЖИТЬ В registerRoutes().
```

### 6.4. Правило навигации

```text
НОВЫЙ ПУНКТ МЕНЮ НЕЛЬЗЯ ВСТАВЛЯТЬ В header.php РУКАМИ.
ОН ДОЛЖЕН ПРИХОДИТЬ ИЗ NAVBAR CONTRACT МОДУЛЯ.
```

---

## 7. Definition of Done

Модульная система считается доведённой до целевого состояния, когда одновременно выполнены все условия:

1. `public/index.php` реально вызывает `ModuleLoader::bootAll()`.
2. В `public/routes/admin.php` не осталось hardcoded маршрутов модулей.
3. В `public/Views/admin/header.php` не осталось hardcoded ссылок на конкретные модули.
4. Новый manifest поддерживает `environment` и `dependencies`.
5. `CoreCodePatcher` не нужен для штатного расширения ядра.
6. `ministra` работает без опоры на `www/c` и `www/portal.php`.
7. Ни один модуль не раскрывает секреты или API-ключи в клиентской части.

```text
ФИНАЛЬНОЕ СОСТОЯНИЕ:
МОДУЛИ = ПОЛНОЦЕННЫЙ EXTENSION LAYER.
НЕ ХАРДКОД В ЯДРЕ.
НЕ ЛОКАЛЬНЫЕ ИСКЛЮЧЕНИЯ.
НЕ ПАТЧИНГ ФАЙЛОВ.
```
