# XC_VM — Актуальный план миграции

> Архитектурные принципы, структура проекта и описание компонентов — см. [ARCHITECTURE.md](ARCHITECTURE.md).
> Этот файл содержит только незавершённые изменения.
> История завершённых фаз фиксируется в git-истории и release notes.
> Обновлено: 2026-04-17

## Содержание

1. [Цель миграции](#1-цель-миграции)
2. [Текущий технический долг](#2-текущий-технический-долг)
3. [Актуальный backlog](#3-актуальный-backlog)
4. [Пошаговый план ликвидации legacy и `www/`](#4-пошаговый-план-ликвидации-legacy-и-www)
5. [Правила безопасности и проверки](#5-правила-безопасности-и-проверки)
6. [Порядок выполнения](#6-порядок-выполнения)

---

## 1. Цель миграции

Цель текущего этапа — убрать остаточный legacy-слой и полностью вывести проект из зависимости от:

- `src/www/` как runtime bootstrap и webroot-логики
- `src/infrastructure/legacy/` как исполняемого application-кода
- procedural entry-point'ов, которые требуют `www/init.php` или `www/stream/init.php`
- hardcoded-интеграции модулей в `public/routes/admin.php` и `public/Views/admin/header.php`

Миграция должна выполняться без потери работоспособности:

- admin/reseller/player панелей
- streaming hot path
- REST/API endpoint'ов
- Ministra-совместимости
- certbot/nginx/service-инфраструктуры

---

## 2. Текущий технический долг

### 2.1. Прямые зависимости от `www/`

На момент обновления файла `www/` всё ещё используется как рабочий runtime-слой:

| Узел | Текущая зависимость | Риск удаления |
| ----- | -------------------- | ------------- |
| `public/index.php` | Прямой `require` `www/init.php` и `www/stream/init.php` для API-path | Ломает API и streaming dispatch |
| `bootstrap.php` | Загружает `www/constants.php` | Ломает константы, config-loader и bootstrap |
| `bin/nginx/conf/nginx.conf` | `root /home/xc_vm/www/` и rewrite на `/stream/*.php`, `/playlist.php`, `/epg.php`, `/player_api.php`, `/probe.php` | Ломает внешний HTTP-трафик |
| `console.php status` / service tooling | Использует `www/stream/init.php` | Ломает часть статуса и диагностики |
| certbot / nginx ops | Используют `/home/xc_vm/www/` как webroot | Ломает выпуск и продление сертификатов |
| Ministra integration | Управляет `www/c` и `www/portal.php` symlink'ами | Ломает MAG legacy redirect |

### 2.2. Исполняемый legacy-код в `infrastructure/legacy/`

Остаточные файлы, которые всё ещё исполняются из production flow:

| Файл | Кто использует | Что блокирует |
| ----- | -------------- | ------------- |
| `resize_body.php` | Admin/Reseller/Player resize controllers | Полное удаление legacy resize flow |
| `reseller_api.php` | `bootstrap.php` admin context | Чистый admin bootstrap |
| `reseller_api_actions.php` | `ResellerApiController` | Полная миграция reseller AJAX/API |
| `reseller_table_body.php` | `ResellerTableController` | Полная миграция reseller table endpoints |

### 2.3. Переходные контроллеры и procedural handlers

В `public/Controllers/` часть контроллеров остаётся thin-wrapper над legacy/procedural кодом:

- `AdminTableController` делегирует в `public/Views/admin/table.php`
- `TableController` и `Reseller/TableController` требуют `www/init.php`
- `ResellerApiController` требует `infrastructure/legacy/reseller_api_actions.php`
- `ResellerTableController` требует `infrastructure/legacy/reseller_table_body.php`
- `AdminResizeController`, `ResellerResizeController`, `PlayerResizeController` требуют `infrastructure/legacy/resize_body.php`

### 2.4. Незавершённая интеграция модулей

Что уже реализовано и не должно оставаться в backlog как “не сделано”:

- `ModuleInterface`
- `ModuleLoader`
- `ModuleManager`
- `ModulesController`
- `public/Views/admin/modules.php`
- `config/modules.php`
- регистрация `events` в контейнере

Что всё ещё не доведено до целевого состояния:

| Проблема | Текущее состояние |
| -------- | ----------------- |
| Web boot модулей | `public/index.php` не вызывает `ModuleLoader::bootAll()` |
| Маршруты модулей | Продублированы статически в `public/routes/admin.php` |
| Навигация модулей | Жёстко зашита в `public/Views/admin/header.php` |
| `module.json` | Содержит только `name`, `description`, `version`, `requires_core` |
| Порядок загрузки модулей | Нет dependency sort и environment filter |
| Core patching | Есть временный stopgap через `CoreCodePatchableModuleInterface`, но это не целевой механизм расширения |

---

## 3. Актуальный backlog

| ID | Задача | Основные файлы | Блокер | Результат |
| -- | ------ | -------------- | ------ | --------- |
| `L-1` | Вынести bootstrap-константы из `www/constants.php` в устойчивый `core/` bootstrap-layer | `src/bootstrap.php`, `src/www/constants.php`, `src/core/Config/*`, `src/core/Error/*` | Нет | `bootstrap.php` больше не зависит от `www/constants.php` |
| `L-2` | Заменить `www/init.php` и `www/stream/init.php` на нормализованные bootstrap-сервисы | `src/public/index.php`, `src/www/init.php`, `src/www/stream/init.php`, `src/streaming/StreamingBootstrap.php` | `L-1` | HTTP/API/streaming path больше не требуют `www/*init.php` |
| `L-3` | Переписать thin-wrapper контроллеры на service/repository flow | `src/public/Controllers/Admin/AdminTableController.php`, `src/public/Controllers/Admin/TableController.php`, `src/public/Controllers/Reseller/*`, `src/public/Controllers/Player/PlayerResizeController.php`, `src/public/Views/admin/table.php` | `L-2` | Ни один контроллер не требует `www/init.php` или `infrastructure/legacy/*` |
| `L-4` | Заменить содержимое `infrastructure/legacy/` на доменные сервисы и специализированные action classes | `src/infrastructure/legacy/*`, `src/domain/**`, `src/public/Controllers/**` | `L-3` | `infrastructure/legacy/` больше не участвует в runtime |
| `L-5` | Перевести внешний HTTP routing с `www` на `public` или новые endpoints | `src/bin/nginx/conf/nginx.conf`, `src/public/index.php`, `src/www/admin/*`, `src/www/stream/*` | `L-2` | nginx больше не маршрутизирует запросы в `www/*.php` |
| `L-6` | Развязать Ministra от `www/c` и `www/portal.php` | `src/cli/CronJobs/RootSignalsCronJob.php`, `src/domain/Server/SettingsService.php`, `src/ministra/*`, nginx-конфиги | `L-5` | MAG legacy redirect управляется без symlink'ов в `www` |
| `L-7` | Удалить `src/www/` после cutover и smoke-check | `src/www/**`, nginx, certbot, status tooling | `L-5`, `L-6` | `www/` больше не нужен ни коду, ни инфраструктуре |
| `M-1` | Включить web boot модулей | `src/public/index.php`, `src/core/Module/ModuleLoader.php` | Нет | `boot()` и `registerRoutes()` реально работают в web-контексте |
| `M-2` | Убрать дублирование модульных маршрутов и зашитых меню | `src/public/routes/admin.php`, `src/public/Views/admin/header.php`, module controllers | `M-1` | Маршруты и пункты меню модулей больше не хардкодятся в ядре |
| `M-3` | Ввести navbar extension points и builder | `src/core/Http/*`, `src/public/Views/admin/header.php`, `src/modules/*` | `M-2` | Модули добавляют навигацию декларативно |
| `M-4` | Расширить manifest и порядок загрузки модулей | `src/core/Module/ModuleLoader.php`, `src/modules/*/module.json` | `M-1` | Поддержка `environment`, `dependencies`, `has_navbar`, `has_settings` |
| `M-5` | Убрать временный core patching как основной путь расширения | `src/core/Module/CoreCodePatchableModuleInterface.php`, `src/core/Module/CoreCodePatcher.php`, hook points в core/public | `M-2` | Новые модули расширяют систему только через контракты и hook points |
| `M-6` | Перевести Ministra в модульные assets/runtime правила | `src/ministra/*`, `src/modules/ministra/*`, nginx | `L-6`, `M-4` | Ministra перестаёт быть отдельным legacy-островом |

---

## 4. Пошаговый план ликвидации legacy и `www/`

### Этап 0. Заморозка входных точек

1. Зафиксировать полный список runtime-зависимостей от `www/` и `infrastructure/legacy/` в smoke-check матрице.
2. Добавить отдельный CI-check: новые вызовы `require ... 'www/*.php'` и `require ... 'infrastructure/legacy/*.php'` запрещены.
3. Прекратить добавление новых nginx rewrite на `www/*.php`.

### Этап 1. Отвязка bootstrap от `www/constants.php`

1. Перенести загрузку констант, путей и error bootstrap в `core/Config` и `core/Error`.
2. Оставить `www/constants.php` только как compatibility shim на время перехода.
3. Переключить `bootstrap.php` на новый источник.

**Критерий завершения:** `bootstrap.php` больше не делает `require_once MAIN_HOME . 'www/constants.php'`.

### Этап 2. Замена `www/init.php` и `www/stream/init.php`

1. Создать два явных bootstrap-сценария: `WebApiBootstrap` и `StreamingRequestBootstrap`.
2. Перенести в них логику из `www/init.php` и `www/stream/init.php` без сохранения procedural entry-point API.
3. Переключить `public/index.php`, `console.php status` и прочие call sites на новые bootstrap-классы.
4. Оставить старые `www/*init.php` как временные shim-файлы на один релиз.

**Критерий завершения:** ни один runtime path не требует `www/init.php` и `www/stream/init.php` напрямую.

### Этап 3. Ликвидация исполняемого legacy в `public/Controllers/`

1. Переписать admin/reseller table endpoints без включения `public/Views/admin/table.php` и `reseller_table_body.php`.
2. Вытащить resize-логику из `resize_body.php` в отдельный сервис `ImageResizeService`.
3. Разделить reseller API actions на action classes и application services.
4. Удалить thin-wrapper контроллеры, оставив только реальные controller classes.

**Критерий завершения:** `public/Controllers/**` больше не содержат `require ... www/init.php` и `require ... infrastructure/legacy/*.php`.

### Этап 4. Удаление `infrastructure/legacy/`

1. После миграции каждого файла включить runtime guard: попытка require legacy-файла должна падать в dev-режиме.
2. Удалить `resize_body.php` после перехода Admin/Reseller/Player на новый сервис.
3. Удалить `reseller_api_actions.php` и `reseller_table_body.php` после переписывания контроллеров.
4. Удалить `reseller_api.php` после разборки admin bootstrap.

**Критерий завершения:** директория `src/infrastructure/legacy/` либо пуста, либо удалена полностью.

### Этап 5. Cutover внешнего HTTP-трафика

1. Перенастроить nginx rewrite так, чтобы внешние маршруты указывали на `public/index.php` или новые специализированные endpoints.
2. Убрать `root /home/xc_vm/www/` как обязательный runtime-root.
3. Вынести certbot webroot в отдельную техническую директорию, не связанную с legacy runtime.
4. Перевести health/status/probe endpoints на новые контроллеры или CLI-backed endpoints.

**Критерий завершения:** nginx больше не обращается к `www/playlist.php`, `www/epg.php`, `www/player_api.php`, `www/probe.php`, `www/stream/*.php`, `www/admin/*.php`.

### Этап 6. Развязка Ministra

1. Убрать зависимость `mag_legacy_redirect` от наличия `www/c` и `www/portal.php` symlink'ов.
2. Перевести Ministra routing на отдельный location/alias либо модульный runtime path.
3. Перенести lifecycle-операции из `RootSignalsCronJob` и `SettingsService` на новую схему без файлового управления в `www/`.

**Критерий завершения:** включение и отключение Ministra больше не создаёт и не удаляет файлы в `www/`.

### Этап 7. Финальное удаление `www/`

1. На один релиз оставить `www/` в режиме compatibility-only shim.
2. Снять продовые метрики по всем старым URL и убедиться, что трафик ушёл на новые точки входа.
3. Удалить содержимое в следующем порядке: `www/admin/*`, затем `www/stream/*`, затем `playlist.php`, `epg.php`, `player_api.php`, `probe.php`, `progress.php`, `init.php`, `constants.php`, и только после этого symlink'и Ministra.
4. Удалить директорию `src/www/` только после чистого smoke-check и nginx cutover.

**Критерий завершения:** в репозитории отсутствует `src/www/`, а все сервисы проходят smoke-check.

---

## 5. Правила безопасности и проверки

### 5.1. Жёсткие правила

1. Никакие секреты, токены, внутренние API-ключи и учётные данные не попадают в клиентский код, HTML, JS, CSS и шаблоны модулей.
2. Новые модули не расширяют систему через прямую запись в `core/` и `domain/`.
3. Любой новый HTTP-path должен иметь явный владелец: `public/`, `streaming/` или отдельный infrastructure entrypoint. Больше не допускается “временный файл в `www/`”.
4. Любой этап удаления должен быть обратимым в пределах одного релиза.

### 5.2. Обязательные проверки перед удалением каждого слоя

| Слой | Минимальная проверка |
| ---- | -------------------- |
| bootstrap | admin login, reseller login, player login, `console.php --list` |
| API | `player_api`, `epg`, `playlist`, `enigma2`, reseller AJAX |
| streaming | live, vod, timeshift, subtitle, thumb, auth, key, segment |
| Ministra | `/c/portal.php`, MAG redirect, portal auth |
| infra | nginx reload, certbot issue/renew path, status endpoint |

### 5.3. Правило удаления

Файл удаляется только если соблюдены все условия:

- у него есть новый владелец или замена
- все call sites переключены
- smoke-check пройден
- rollback понятен и документирован

---

## 6. Порядок выполнения

### Волна A. Подготовка к удалению `www/`

1. `L-1` — отвязка bootstrap от `www/constants.php`
2. `L-2` — замена `www/init.php` и `www/stream/init.php`
3. `L-3` — переписывание thin-wrapper контроллеров
4. `L-4` — удаление исполняемого legacy в `infrastructure/legacy/`

### Волна B. Модульный cutover

1. `M-1` — включение web boot модулей
2. `M-2` — удаление hardcoded маршрутов модулей и меню
3. `M-3` — navbar extension points
4. `M-4` — manifest v2 и dependency sort
5. `M-5` — отказ от core patching как основного пути расширения

### Волна C. Финальный infra cutover

1. `L-5` — nginx/certbot/status cutover
2. `L-6` — Ministra без `www/` symlink'ов
3. `M-6` — модульная интеграция Ministra
4. `L-7` — физическое удаление `src/www/`

> До завершения волны C удаление `src/www/` запрещено.
