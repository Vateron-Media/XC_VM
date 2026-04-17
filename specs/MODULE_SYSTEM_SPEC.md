# XC_VM — Техническое задание: Система модулей (постепенная интеграция)

> **Версия:** 1.0.0 | **Дата:** 2026-03-17

---

## Содержание

0. [Анализ текущего состояния](#0-анализ-текущего-состояния)
1. [Архитектура модулей](#1-архитектура-модулей)
2. [Совместимость с текущим кодом](#2-совместимость-с-текущим-кодом)
3. [Поддержка окружений (MAIN / LB)](#3-поддержка-окружений-main--lb)
4. [Система управления модулями](#4-система-управления-модулями)
5. [Система настроек модулей](#5-система-настроек-модулей)
6. [Расширение интерфейса (Navbar)](#6-расширение-интерфейса-navbar)
7. [Роутинг модулей](#7-роутинг-модулей)
8. [Изоляция и зависимости](#8-изоляция-и-зависимости)
9. [Обнаруженные проблемы и баги](#9-обнаруженные-проблемы-и-баги)
10. [Поэтапный план реализации](#10-поэтапный-план-реализации)
11. [Риски](#11-риски)

---

## 0. Анализ текущего состояния

### 0.1. Что уже существует

Система модулей **частично реализована** в Phases 5–6 MIGRATION.md. Существуют:

| Компонент | Файл | Состояние |
|-----------|------|-----------|
| Контракт модуля | `src/core/Module/ModuleInterface.php` | ✅ Полный интерфейс: 8 методов |
| Загрузчик модулей | `src/core/Module/ModuleLoader.php` | ⚠️ Работает в CLI, **НЕ вызывается в web** |
| Конфигурация overrides | `src/config/modules.php` | ✅ Работает (enabled/disabled/class override) |
| DI-контейнер | `src/core/Container/ServiceContainer.php` | ✅ Singleton, set/get/factory/tag |
| Event Dispatcher | `src/core/Events/EventDispatcher.php` | ⚠️ Полностью static; подписки работают через `subscribe()`, но web bootstrap модулей не подключён |
| HTTP Router | `src/core/Http/Router.php` | ✅ get/post/api/group/dispatch/dispatchApi |
| CLI Registry | `src/cli/CommandRegistry.php` | ✅ Модули регистрируют команды через `console.php` |
| 7 модулей | `src/modules/{name}/` | ⚠️ Только CLI-команды работают, веб-маршруты — мёртвый код |

### 0.2. Существующие 7 модулей

| Модуль | module.json | Класс | boot() | registerRoutes() | registerCommands() | getEventSubscribers() |
|--------|:-----------:|:-----:|:------:|:----------------:|:------------------:|:--------------------:|
| `watch` | ✅ | ✅ | Регистрирует 3 сервиса | 5 GET + 4 API | `WatchCronJob`, `WatchItemCommand` | `[]` |
| `plex` | ✅ | ✅ | Регистрирует 3 сервиса | 3 GET + 5 API | `PlexCronJob`, `PlexItemCommand` | `[]` |
| `tmdb` | ✅ | ✅ | Регистрирует 1 сервис | Пусто (TODO) | `TmdbCronJob`, `TmdbPopularCronJob` | `[]` |
| `ministra` | ✅ | ✅ | Пусто | Пусто (standalone) | Пусто | `[]` |
| `fingerprint` | ✅ | ✅ | Пусто | 1 GET + 1 API | Пусто | `[]` |
| `theft-detection` | ✅ | ✅ | Пусто | 1 GET | Пусто | `[]` |
| `magscan` | ✅ | ✅ | Пусто | 1 GET + 1 API | Пусто | `[]` |

### 0.3. Критические разрывы

1. **ModuleLoader НЕ вызывается в web-контексте.** `public/index.php` загружает маршруты только из `public/routes/{scope}.php`. `ModuleLoader::bootAll($container, $router)` нигде не вызывается в HTTP-пути. Маршруты, зарегистрированные модулями через `registerRoutes()`, **никогда не попадают в Router**.

2. **Модульные маршруты продублированы в статических файлах.** Маршруты watch, plex, fingerprint и т.д. зарегистрированы и в `WatchModule::registerRoutes()`, и в `public/routes/admin.php`. Модульная регистрация — мёртвый код.

3. **Event subscribers зависят от `bootAll()`.** Подписки модулей подключаются в `ModuleLoader::bootAll()` через `EventDispatcher::subscribe()`, но пока `bootAll()` не вызывается в web-пути, механизм событий для HTTP-контекста остаётся декларативным.

4. **Navbar — hardcoded HTML.** Навигация в `public/Views/admin/header.php` (~500 строк HTML) полностью статическая. Модули не могут добавить пункты меню динамически. Пункты watch/plex/fingerprint зашиты прямо в HTML header.php.

5. **Нет web-интеграции `boot()`.** Модульные сервисы (зарегистрированные в `boot()`) недоступны в web-контексте, потому что `bootAll()` не вызывается.

6. **`events` не зарегистрирован в контейнере.** `bootstrap.php::populateContainer()` не регистрирует `EventDispatcher` под ключом `events`. Даже если `bootAll()` вызвать, `$container->has('events')` вернёт `false`.

### 0.4. Что работает прямо сейчас

- ✅ CLI-команды модулей (`console.php` → `ModuleLoader::loadAll()` → `registerAllCommands()`)
- ✅ Auto-discovery модулей через `modules/*/module.json`
- ✅ Отключение модулей через `config/modules.php`
- ✅ Маршруты модулей **фактически** работают, но зарегистрированы статически в `routes/admin.php`, а не через `ModuleInterface::registerRoutes()`

---

## 1. Архитектура модулей

### 1.1. Формат модуля (существующий — без изменений)

```
src/modules/{module-name}/
├── module.json                    # Метаданные (name, version, description, requires_core, environment)
├── {ModuleName}Module.php         # Точка входа: implements ModuleInterface
├── {ModuleName}Service.php        # Бизнес-логика (опционально)
├── {ModuleName}Repository.php     # SQL-запросы (опционально)
├── {ModuleName}Controller.php     # HTTP-обработчики (опционально)
├── config.php                     # Настройки модуля (NEW — §5)
├── navbar.php                     # Элементы меню (NEW — §6)
├── views/                         # Шаблоны страниц (опционально)
│   ├── {page}.php
│   └── {page}_scripts.php
└── assets/                        # CSS/JS/изображения (опционально)
```

### 1.2. module.json (расширение формата)

**Текущий формат:**
```json
{
    "name": "watch",
    "description": "Watch activity tracking module",
    "version": "1.0.0",
    "requires_core": ">=2.0"
}
```

**Расширение (backward-compatible):**
```json
{
    "name": "watch",
    "description": "Watch activity tracking module",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "has_settings": true,
    "has_navbar": true,
    "dependencies": []
}
```

| Поле | Тип | Обязательно | По умолчанию | Описание |
|------|-----|:-----------:|:------------:|----------|
| `name` | string | ✅ | — | Совпадает с именем директории |
| `description` | string | ❌ | `""` | Для UI управления |
| `version` | string | ✅ | — | semver |
| `requires_core` | string | ❌ | `"*"` | Версия ядра |
| `environment` | string | ❌ | `"main"` | `"main"` \| `"lb"` \| `"any"` (NEW — §3) |
| `has_settings` | bool | ❌ | `false` | Есть ли config.php (NEW — §5) |
| `has_navbar` | bool | ❌ | `false` | Есть ли navbar.php (NEW — §6) |
| `dependencies` | string[] | ❌ | `[]` | Другие модули (для порядка загрузки) |

### 1.3. Контракт модуля (существующий `ModuleInterface` — без изменений)

```php
// src/core/Module/ModuleInterface.php — UЖЕ СУЩЕСТВУЕТ
interface ModuleInterface {
    public function getName(): string;
    public function getVersion(): string;
    public function boot(ServiceContainer $container): void;
    public function registerRoutes(Router $router): void;
    public function registerCommands(CommandRegistry $registry): void;
    public function getEventSubscribers(): array;
    public function install(): void;
    public function uninstall(): void;
}
```

### 1.4. Жизненный цикл (существующий — уточнение порядка)

```
1. ModuleLoader::loadAll() — сканирует modules/*/module.json
   ├── Считывает config/modules.php (overrides: enabled => false)
   ├── Фильтрует по environment (NEW — §3)
   ├── Разрешает зависимости (topological sort, NEW — §8.3)
   └── Создаёт экземпляры: new {ModuleName}Module()

2. ModuleLoader::bootAll($container, $router)
   ├── module->boot($container)          — регистрация сервисов (try-catch per module)
   ├── module->registerRoutes($router)   — HTTP-маршруты
   └── EventDispatcher::subscribe(...)   — подписки на события

3. NavbarBuilder::getItems($cache, $loader)
   ├── Вычислить modules_hash (sorted name:version)
   ├── Проверить FileCache  →  HIT → вернуть items
   └── MISS → buildFromModules()
       ├── Для каждого модуля: require navbar.php (try-catch per module)
       ├── validateItem() → strict schema, id uniqueness, max depth
       └── Группировка по position, сортировка по order

4. (CLI only) ModuleLoader::registerAllCommands($registry)
   └── module->registerCommands($registry)

5. Runtime — Router dispatch → Controller → Service → Repository
   └── header.php → NavbarBuilder::render($position, $can)
       ├── Фильтрация по пермиссиям через $can closure
       └── HTML-рендеринг модульных пунктов в injection points

6. (При отключении)
   ├── module->uninstall() — очистка таблиц/данных
   └── NavbarBuilder::invalidate($cache) — сброс кеша navbar
```

---

## 2. Совместимость с текущим кодом

### 2.1. Принцип: «одновременная работа двух путей»

Фаза интеграции **не переключает** маршрутизацию с legacy на модульную за один шаг. Вместо этого:

1. Legacy-маршруты в `routes/admin.php` продолжают работать
2. `ModuleLoader::bootAll()` регистрирует модульные маршруты параллельно
3. При dispatch — Router сначала ищет модульный маршрут, затем fallback на legacy
4. По мере миграции legacy-маршруты удаляются из `routes/admin.php`

### 2.2. Карта изменений

| Файл | Действие | Фаза |
|------|----------|:----:|
| `src/public/index.php` | Добавить вызов `ModuleLoader::loadAll()` + `bootAll()` | M-1 |
| `src/bootstrap.php` :: `populateContainer()` | Зарегистрировать `events` в контейнере | M-1 |
| `src/core/Http/NavbarPositions.php` | Создать: константы injection points (NEW) | M-3 |
| `src/core/Http/NavbarBuilder.php` | Создать: builder с FileCache + validation (NEW) | M-3 |
| `src/public/Views/admin/header.php` | Добавить 4 injection points `NavbarBuilder::render()` | M-3 |
| `src/core/Module/ModuleLoader.php` | Добавить `environment` фильтрацию, dependency sort | M-2 |
| `src/config/modules.php` | Без изменений | — |
| `src/core/Module/ModuleInterface.php` | Без изменений | — |
| `src/core/Container/ServiceContainer.php` | Без изменений | — |
| `src/core/Http/Router.php` | Без изменений | — |
| `src/cli/CommandRegistry.php` | Без изменений | — |
| `src/console.php` | Без изменений | — |

### 2.3. Файлы без изменений

- Все 7 существующих `{Module}Module.php` — без изменений после M-1
- `domain/`, `core/` (кроме указанных выше) — без изменений
- `streaming/` — без изменений
- `infrastructure/` — без изменений
- `includes/` — без изменений

---

## 3. Поддержка окружений (MAIN / LB)

### 3.1. Текущее определение окружения

Окружение определяется на этапе сборки (`Makefile`):
- **MAIN** — полная сборка (admin + streaming + modules)
- **LB (LoadBalancer)** — только streaming, без `modules/`, без `public/`, без admin

На runtime: константа-файл или `SERVER_TYPE` из БД (`servers` table, `is_main` flag).

### 3.2. Фильтрация модулей по окружению

**Текущее поведение:** `modules/` полностью исключена из LB-сборки в Makefile. На LB модули вообще не существуют физически.

**Расширение (для будущих LB-модулей):**

Добавить в `ModuleLoader::loadAll()` проверку поля `environment` из `module.json`:

```php
// В ModuleLoader::loadAll(), после чтения module.json:
$manifest = json_decode(file_get_contents($jsonFile), true);
$env = $manifest['environment'] ?? 'main';

// Определяем текущее окружение
$currentEnv = defined('SERVER_TYPE') && SERVER_TYPE === 'lb' ? 'lb' : 'main';

// Фильтрация
if ($env !== 'any' && $env !== $currentEnv) {
    continue;
}
```

| `environment` | MAIN | LB |
|:------------:|:----:|:--:|
| `"main"` | ✅ Загружается | ❌ Пропускается |
| `"lb"` | ❌ Пропускается | ✅ Загружается |
| `"any"` | ✅ Загружается | ✅ Загружается |

### 3.3. Где хранится

- `module.json` → поле `environment` (per-module declaration)
- Runtime: `SERVER_TYPE` / `MAIN_SERVER` constant (определяется в `core/Config/AppConfig.php`)
- Build time: `Makefile` → `LB_DIRS_TO_REMOVE` (физическое исключение)

### 3.4. Влияние на Makefile

Если в будущем понадобятся LB-модули:

```makefile
# Сейчас — модули полностью исключены из LB:
# modules/ отсутствует в LB_DIRS

# В будущем — копировать modules/ в LB, удалять main-only:
LB_DIRS += modules
LB_MODULE_DIRS_TO_REMOVE := modules/watch modules/plex modules/tmdb \
    modules/fingerprint modules/theft-detection modules/magscan
```

**На данном этапе изменения Makefile не требуются.** Все 7 текущих модулей — `environment: "main"`.

---

## 4. Система управления модулями

### 4.1. Хранение состояния (вкл/выкл)

**Текущая реализация (сохраняется):**

```php
// src/config/modules.php
return [
    // 'theft-detection' => ['enabled' => false],
];
```

По умолчанию все обнаруженные модули **включены**. `config/modules.php` хранит только отключения.

### 4.2. Загрузка/отключение (уже работает)

```
Включение:   1. Разместить модуль в modules/{name}/
              2. Добавить module.json
              3. Модуль обнаруживается автоматически при следующем запросе

Отключение:  1. Добавить в config/modules.php: 'name' => ['enabled' => false]
              2. Модуль не загружается, но файлы остаются

Удаление:    1. Вызвать module->uninstall() (если нужна очистка БД)
              2. Удалить директорию modules/{name}/
```

### 4.3. API для управления (NEW)

Новый контроллер: `public/Controllers/Admin/ModuleManagerController.php`

```php
class ModuleManagerController {

    /**
     * GET /modules — список модулей с их состоянием
     */
    public function index() {
        $loader = new ModuleLoader();
        $loader->loadAll();

        // Собираем информацию обо всех модулях (включая отключённые)
        $allModules = [];
        $modulesDir = MAIN_HOME . 'modules';
        foreach (glob($modulesDir . '/*/module.json') as $jsonFile) {
            $manifest = json_decode(file_get_contents($jsonFile), true);
            $name = $manifest['name'] ?? basename(dirname($jsonFile));
            $allModules[$name] = [
                'name'        => $name,
                'description' => $manifest['description'] ?? '',
                'version'     => $manifest['version'] ?? '0.0.0',
                'environment' => $manifest['environment'] ?? 'main',
                'has_settings' => $manifest['has_settings'] ?? false,
                'enabled'     => $loader->isLoaded($name),
            ];
        }

        // Передаём во view
        $rModules = $allModules;
        include VIEW_PATH . '/admin/modules/list.php';
    }

    /**
     * API: enable/disable модуля
     * POST action=module_toggle, module={name}, enabled={0|1}
     */
    public function apiToggle() {
        $name = $_POST['module'] ?? '';
        $enabled = (bool)($_POST['enabled'] ?? true);

        // Валидация: модуль существует
        $modulePath = MAIN_HOME . 'modules/' . basename($name);
        if (!is_dir($modulePath) || !file_exists($modulePath . '/module.json')) {
            echo json_encode(['result' => false, 'error' => 'Module not found']);
            exit;
        }

        // Обновляем config/modules.php
        $configPath = CONFIG_PATH . 'modules.php';
        $overrides = file_exists($configPath) ? require $configPath : [];
        if (!is_array($overrides)) {
            $overrides = [];
        }

        if ($enabled) {
            unset($overrides[$name]);
        } else {
            $overrides[$name] = ['enabled' => false];
        }

        // Записываем
        $content = "<?php\nreturn " . var_export($overrides, true) . ";\n";
        file_put_contents($configPath, $content, LOCK_EX);

        echo json_encode(['result' => true]);
        exit;
    }
}
```

**Маршруты:**
```php
// В public/routes/admin.php:
$router->get('modules', [ModuleManagerController::class, 'index'], [
    'permission' => ['adv', 'settings'],
]);
$router->api('module_toggle', [ModuleManagerController::class, 'apiToggle'], [
    'permission' => ['adv', 'settings'],
]);
```

### 4.4. Graceful degradation

Когда модуль отключён или удалён:

1. `ModuleLoader` пропускает его при `loadAll()` — маршруты не регистрируются
2. Navbar items из `navbar.php` не загружаются
3. CLI-команды не регистрируются
4. Контроллер модуля не инстанцируется → 404 при попытке доступа к странице модуля
5. Event subscribers не подписываются → ядро не вызывает модуль

**Критерий:** удаление `rm -rf modules/watch` не вызывает ни одной ошибки ни в web, ни в CLI.

---

## 5. Система настроек модулей

### 5.1. Условия наличия настроек

Модуль **имеет настройки** если:
- `module.json` содержит `"has_settings": true`
- Файл `modules/{name}/config.php` существует и возвращает массив полей

### 5.2. Контракт config.php (формат описания полей)

```php
// modules/watch/config.php
return [
    'section' => 'Watch Folder Settings',
    'fields' => [
        [
            'key'         => 'watch_enabled',
            'label'       => 'Enable Watch Folders',
            'type'        => 'toggle',       // toggle | text | number | select | textarea
            'default'     => 0,
            'description' => 'Enable or disable all watch folder monitoring',
        ],
        [
            'key'         => 'watch_interval',
            'label'       => 'Scan Interval (seconds)',
            'type'        => 'number',
            'default'     => 300,
            'min'         => 60,
            'max'         => 3600,
        ],
        [
            'key'         => 'watch_format',
            'label'       => 'Output Format',
            'type'        => 'select',
            'options'     => ['ts' => 'MPEG-TS', 'hls' => 'HLS', 'dash' => 'DASH'],
            'default'     => 'ts',
        ],
    ],
];
```

### 5.3. Хранение данных

Настройки модулей хранятся в **существующей таблице `settings`** с префиксом `module_{name}_`:

```sql
-- Пример для модуля watch:
INSERT INTO settings (`key`, `value`) VALUES ('module_watch_enabled', '1');
INSERT INTO settings (`key`, `value`) VALUES ('module_watch_interval', '300');
```

Доступ через существующий `SettingsManager`:

```php
// Чтение:
$enabled = SettingsManager::get('module_watch_enabled', 0);

// Запись (из контроллера настроек):
SettingsManager::update('module_watch_enabled', $value);
```

### 5.4. Генерация UI (автоматическая)

Новый partial: `public/Views/admin/modules/settings_form.php`

Генерирует HTML-форму из `config.php` дескриптора, аналогично тому, как текущие `settings_*.php` работают в ядре. Тип поля → HTML input:

| `type` | HTML |
|--------|------|
| `toggle` | `<input type="checkbox" data-plugin="switchery">` |
| `text` | `<input type="text" class="form-control">` |
| `number` | `<input type="number" class="form-control" min=... max=...>` |
| `select` | `<select class="form-control">` |
| `textarea` | `<textarea class="form-control">` |

**Маршрут:** Каждый модуль с `has_settings: true` автоматически получает `settings/{name}` маршрут, обрабатываемый `ModuleManagerController::settings($name)`.

### 5.5. Альтернатива: собственная страница настроек

Модуль **может** НЕ использовать auto-generated UI и вместо этого предоставить собственный view через `registerRoutes()`. Пример: `WatchModule` уже регистрирует `settings/watch` → `WatchController::settings()` с кастомным view.

Автогенерация — fallback для простых модулей без собственного контроллера настроек.

---

## 6. Расширение интерфейса (Navbar)

### 6.1. Анализ текущей реализации

Файл: `src/public/Views/admin/header.php`, строки 273–714.

Навигация — **полностью hardcoded HTML** внутри `<ul class="navigation-menu">`. Структура:

```
Dashboard → (submenu)
Servers → Install LB, Manage, Proxies, Order, Process Monitor
Users → Lines (add/manage/mass), MAG (add/manage/mass), Enigma (add/manage/mass), Resellers
Content → Streams, Created Channels, Movies, Series, Radio, Archive, EPG View
Bouquets → Add, Manage, Order
Management → Service Setup (Packages, Categories, Groups, EPG, Profiles, Plex, Watch),
             Access Codes, Security, Tools (Channel Order, Fingerprint, Mass Delete, ...),
             Logs (17 пунктов), Tickets
Providers → Add, Manage
```

Каждый пункт обёрнут в `Authorization::check('adv', '...')`. Модульные пункты (Plex, Watch, Fingerprint, Theft Detection) зашиты в HTML напрямую.

### 6.2. Почему нужен data-driven подход

Текущая структура — монолитный HTML. Модули не могут добавить пункты меню без хардкода в header.php.

Требования:
- Модули декларируют navbar items через `navbar.php`
- Данные (структура items) кешируются — пересборка только при изменении списка модулей
- HTML рендерится на каждый запрос с фильтрацией по пермиссиям текущего пользователя (кешировать HTML нельзя — у каждого админа свои права)
- Битый модуль не ломает весь navbar — fail-safe per-module
- Строгая валидация формата items — невалидный модуль логируется и пропускается

### 6.3. Архитектура: 4 точки инъекции в header.php

**Стратегия: точечная инъекция, а не полное переписывание.**

Не трогаем 90% header.php (core-пункты: Dashboard, Servers, Users, Content, Bouquets, Management, Providers). Добавляем **4 injection points** внутри существующих секций, где сейчас живут hardcoded модульные пункты:

```php
// header.php — внутри Management > Service Setup, после "Watch Folders":
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_SERVICE_SETUP, $can); ?>

// header.php — внутри Management > Tools, после "Stream Tools":
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_TOOLS, $can); ?>

// header.php — внутри Management > Logs, перед закрывающим </ul>:
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_LOGS, $can); ?>

// header.php — после секции Providers, перед закрывающим </ul> navigation-menu:
<?php NavbarBuilder::render(NavbarPositions::TOP, $can); ?>
```

Переменная `$can` определяется один раз в начале header.php:

```php
$can = function ($type, $code) {
    return Authorization::check($type, $code);
};
```

Это injection point: в тестах или при будущем ACL-рефакторинге `$can` заменяется без изменения NavbarBuilder.

**Core navbar — transitional architecture.** Injection points через `NavbarPositions` формализуют контракт. Если в будущем core navbar тоже станет data-driven, позиции уже стабильны. Но переписывание core navbar — отдельная задача, не часть M-3.

### 6.4. Класс NavbarPositions

Новый файл: `src/core/Http/NavbarPositions.php`

```php
class NavbarPositions {

	/** После секции Providers — top-level модульные меню */
	const TOP = 'top';

	/** Внутри Management → Service Setup */
	const MANAGEMENT_SERVICE_SETUP = 'management.service_setup';

	/** Внутри Management → Tools */
	const MANAGEMENT_TOOLS = 'management.tools';

	/** Внутри Management → Logs */
	const MANAGEMENT_LOGS = 'management.logs';

	/** Все допустимые позиции (для валидации) */
	const ALL = [
		self::TOP,
		self::MANAGEMENT_SERVICE_SETUP,
		self::MANAGEMENT_TOOLS,
		self::MANAGEMENT_LOGS,
	];

	/** Позиция по умолчанию, если модуль не указал */
	const DEFAULT_POSITION = self::TOP;
}
```

### 6.5. Класс NavbarBuilder

Новый файл: `src/core/Http/NavbarBuilder.php`

Разделение ответственности:
- **Data layer** (`buildFromModules`, `getItems`) — сбор и кеширование структуры items
- **Validation** (`validateItem`, `validatePosition`) — строгая проверка формата
- **Presentation** (`render`) — HTML-рендеринг с фильтрацией по пермиссиям

```php
class NavbarBuilder {

	/** Максимальная глубина вложенности (UI ограничение) */
	const MAX_DEPTH = 2;

	/** Ключ кеша */
	const CACHE_KEY = 'navbar_modules';

	/** Safety net TTL — 1 час. Основная инвалидация через modules_hash */
	const CACHE_TTL = 3600;

	/** @var array|null Закешированные items (in-memory per-request) */
	private static $cachedItems = null;

	// ─── Data Layer ────────────────────────────────────────────────

	/**
	 * Получить items из кеша или собрать заново.
	 *
	 * Кеш автоматически инвалидируется при изменении списка модулей
	 * (добавление, удаление, включение, отключение, обновление версии).
	 *
	 * @param FileCache $cache Кеш-драйвер
	 * @param ModuleLoader $loader Загрузчик модулей (уже loadAll)
	 * @return array Items grouped by position
	 */
	public static function getItems(FileCache $cache, ModuleLoader $loader) {
		if (self::$cachedItems !== null) {
			return self::$cachedItems;
		}

		$currentHash = self::getModulesHash($loader);

		// Чтение кеша
		$cached = $cache->get(self::CACHE_KEY, self::CACHE_TTL);
		if ($cached !== false && isset($cached['hash']) && $cached['hash'] === $currentHash) {
			self::$cachedItems = $cached['items'];
			return self::$cachedItems;
		}

		// Cache miss или hash mismatch → пересборка
		$items = self::buildFromModules($loader);
		$cache->set(self::CACHE_KEY, [
			'hash' => $currentHash,
			'items' => $items,
		]);

		self::$cachedItems = $items;
		return $items;
	}

	/**
	 * Собрать items из navbar.php всех загруженных модулей.
	 *
	 * Fail-safe: ошибка в navbar.php одного модуля не ломает остальные.
	 * Невалидные items логируются и пропускаются.
	 *
	 * @param ModuleLoader $loader
	 * @return array Items grouped by position: ['top' => [...], 'management.tools' => [...]]
	 */
	public static function buildFromModules(ModuleLoader $loader) {
		$grouped = array_fill_keys(NavbarPositions::ALL, []);
		$globalIds = [];

		foreach ($loader->getModules() as $name => $module) {
			try {
				$modulePath = $loader->getModulePath($name);
				$file = $modulePath . '/navbar.php';

				if (!file_exists($file)) {
					continue;
				}

				$items = require $file;
				if (!is_array($items)) {
					error_log("NavbarBuilder: module '{$name}' navbar.php must return array");
					continue;
				}

				foreach ($items as $item) {
					$item['_module'] = $name;

					$errors = self::validateItem($item, 0, $globalIds);
					if (!empty($errors)) {
						foreach ($errors as $err) {
							error_log("NavbarBuilder: module '{$name}': {$err}");
						}
						continue;
					}

					$position = $item['position'] ?? NavbarPositions::DEFAULT_POSITION;
					$grouped[$position][] = $item;
				}
			} catch (\Throwable $e) {
				error_log("NavbarBuilder: module '{$name}' failed: " . $e->getMessage());
			}
		}

		// Сортировка по order внутри каждой позиции
		foreach ($grouped as $pos => &$items) {
			usort($items, function ($a, $b) {
				return ($a['order'] ?? 100) - ($b['order'] ?? 100);
			});
		}

		return $grouped;
	}

	/**
	 * Явная инвалидация кеша navbar.
	 *
	 * Вызывается из ModuleManagerController::apiToggle() при вкл/выкл модуля.
	 * Safety net: даже без вызова, hash fingerprint обнаружит изменение.
	 *
	 * @param FileCache $cache
	 */
	public static function invalidate(FileCache $cache) {
		$cache->delete(self::CACHE_KEY);
		self::$cachedItems = null;
	}

	// ─── Cache Fingerprint ─────────────────────────────────────────

	/**
	 * Вычислить hash от текущего списка модулей.
	 *
	 * Hash меняется при: добавлении/удалении модуля, вкл/выкл,
	 * обновлении версии, изменении has_navbar в module.json.
	 *
	 * @param ModuleLoader $loader
	 * @return string md5 hash
	 */
	private static function getModulesHash(ModuleLoader $loader) {
		$parts = [];
		foreach ($loader->getModules() as $name => $module) {
			$parts[] = $name . ':' . $module->getVersion();
		}
		sort($parts);
		return md5(implode('|', $parts));
	}

	// ─── Validation ────────────────────────────────────────────────

	/**
	 * Валидация одного item.
	 *
	 * Проверяет:
	 * - Обязательные поля (id, label, url)
	 * - Уникальность id (глобально)
	 * - Допустимость position (в NavbarPositions::ALL)
	 * - Максимальная глубина вложенности (MAX_DEPTH)
	 * - Рекурсивная проверка children
	 *
	 * @param array $item Элемент навигации
	 * @param int $depth Текущая глубина (0 = top-level)
	 * @param array &$globalIds Set id, которые уже заняты (для дедупликации)
	 * @return array Массив ошибок (пустой = валидно)
	 */
	public static function validateItem(array $item, int $depth, array &$globalIds) {
		$errors = [];

		// Обязательные поля
		if (empty($item['id']) || !is_string($item['id'])) {
			$errors[] = "missing or invalid 'id'";
		}
		if (empty($item['label']) || !is_string($item['label'])) {
			$errors[] = "missing or invalid 'label'";
		}
		if (empty($item['url']) || !is_string($item['url'])) {
			$errors[] = "missing or invalid 'url'";
		}

		// Уникальность id
		if (!empty($item['id'])) {
			if (isset($globalIds[$item['id']])) {
				$errors[] = "duplicate id '{$item['id']}' (already registered by module '{$globalIds[$item['id']]}')";
			} else {
				$globalIds[$item['id']] = $item['_module'] ?? 'unknown';
			}
		}

		// Валидация position (только для top-level items)
		if ($depth === 0 && !empty($item['position'])) {
			if (!in_array($item['position'], NavbarPositions::ALL, true)) {
				$errors[] = "invalid position '{$item['position']}'. Allowed: " . implode(', ', NavbarPositions::ALL);
			}
		}

		// Проверка permission формата
		if (isset($item['permission'])) {
			if (!is_array($item['permission']) || count($item['permission']) !== 2) {
				$errors[] = "'permission' must be [type, code] array";
			}
		}

		// Рекурсивная проверка children
		if (!empty($item['children'])) {
			if ($depth >= self::MAX_DEPTH - 1) {
				$errors[] = "children exceed max depth " . self::MAX_DEPTH;
			} else {
				foreach ($item['children'] as $child) {
					$child['_module'] = $item['_module'] ?? 'unknown';
					$childErrors = self::validateItem($child, $depth + 1, $globalIds);
					$errors = array_merge($errors, $childErrors);
				}
			}
		}

		return $errors;
	}

	private static function validatePosition($position) {
		return in_array($position, NavbarPositions::ALL, true);
	}

	// ─── Presentation ──────────────────────────────────────────────

	/**
	 * Отрендерить items для конкретной injection point.
	 *
	 * Вызывается из header.php. Фильтрация по пермиссиям — через $can closure.
	 *
	 * @param string $position Позиция из NavbarPositions (e.g. 'management.tools')
	 * @param callable $can fn(string $type, string $code): bool — проверка пермиссий
	 */
	public static function render(string $position, callable $can) {
		if (self::$cachedItems === null) {
			return;
		}

		$items = self::$cachedItems[$position] ?? [];
		if (empty($items)) {
			return;
		}

		foreach ($items as $item) {
			if (!self::checkPermission($item, $can)) {
				continue;
			}

			if (!empty($item['children'])) {
				self::renderSubmenu($item, $can);
			} else {
				self::renderItem($item);
			}
		}
	}

	private static function renderItem($item) {
		echo '<li><a href="' . htmlspecialchars($item['url']) . '">';
		if (!empty($item['icon'])) {
			echo '<i class="' . htmlspecialchars($item['icon']) . '"></i>';
		}
		echo htmlspecialchars($item['label']) . '</a></li>';
	}

	private static function renderSubmenu($item, callable $can) {
		echo '<li class="has-submenu">';
		echo '<a href="#">';
		if (!empty($item['icon'])) {
			echo '<i class="' . htmlspecialchars($item['icon']) . '"></i>';
		}
		echo htmlspecialchars($item['label']);
		echo ' <div class="arrow-down"></div></a>';
		echo '<ul class="submenu">';

		foreach ($item['children'] as $child) {
			if (!self::checkPermission($child, $can)) {
				continue;
			}
			echo '<li><a href="' . htmlspecialchars($child['url']) . '">' 
				. htmlspecialchars($child['label']) . '</a></li>';
		}

		echo '</ul></li>';
	}

	private static function checkPermission($item, callable $can) {
		if (empty($item['permission'])) {
			return true;
		}
		return $can($item['permission'][0], $item['permission'][1]);
	}

	/**
	 * Сбросить in-memory кеш (для тестов)
	 */
	public static function reset() {
		self::$cachedItems = null;
	}
}
```

### 6.6. Strict item schema

Каждый элемент `navbar.php` ДОЛЖЕН соответствовать схеме:

| Поле | Обязательно | Тип | Ограничение |
|------|:----------:|------|--------------|
| `id` | ✅ | string | Уникально глобально, namespaced: `watch.folders`, `plex.sync` |
| `label` | ✅ | string | Non-empty, отображаемый текст |
| `url` | ✅ | string | Non-empty, относительный URL или `#` для parent |
| `permission` | ❌ | array | `[type, code]` — два элемента, или null (= видим всем) |
| `icon` | ❌ | string | CSS-класс иконки: `fas fa-folder` |
| `position` | ❌ | string | Одно из `NavbarPositions::ALL` (default: `top`) |
| `order` | ❌ | int | Сортировка внутри позиции (default: 100) |
| `children` | ❌ | array | Та же схема, НО без children (max depth = 2) |

**Валидация выполняется при build.** Невалидный item логируется в `error_log` с именем модуля и пропускается. Весь navbar продолжает работать.

### 6.7. Кеширование

#### Стратегия: modules_hash fingerprint

Один механизм вместо трёх:

```
getItems(cache, loader)
  ├── hash = md5(sorted list of "name:version" all loaded modules)
  ├── cached = FileCache::get('navbar_modules', TTL=3600)
  ├── if cached.hash === hash → return cached.items     (HIT)
  └── else → buildFromModules() → validate → cache.set() (MISS)
```

**Когда hash меняется автоматически:**
- Добавление/удаление директории модуля
- Включение/отключение через `config/modules.php`
- Обновление версии модуля

**Explicit invalidation (дополнительно):**
`NavbarBuilder::invalidate($cache)` вызывается из `ModuleManagerController::apiToggle()` для мгновенной реакции в текущем запросе.

**Safety net:** TTL = 3600 секунд. Даже при повреждённом кеше или ручном копировании модулей — пересборка через 1 час максимум.

**Что кешируется:** Только структура данных (массив items, grouped by position). **НЕ** HTML. Рендеринг с фильтрацией по пермиссиям — на каждый запрос.

**Формат хранения:** igbinary через `FileCache` (как весь проект). Путь: `CACHE_TMP_PATH . 'navbar_modules'`.

### 6.8. Формат navbar.php модуля

```php
// modules/watch/navbar.php
return [
	[
		'id'         => 'watch.folders',
		'label'      => 'Watch Folders',
		'icon'       => 'fas fa-folder-open',
		'url'        => '#',
		'permission' => ['adv', 'folder_watch'],
		'position'   => 'management.service_setup',
		'order'      => 110,
		'children'   => [
			[
				'id'         => 'watch.manage',
				'label'      => 'Manage Folders',
				'url'        => 'watch',
				'permission' => ['adv', 'folder_watch'],
			],
			[
				'id'         => 'watch.add',
				'label'      => 'Add Folder',
				'url'        => 'watch/add',
				'permission' => ['adv', 'folder_watch'],
			],
			[
				'id'         => 'watch.output',
				'label'      => 'Watch Output',
				'url'        => 'watch_output',
				'permission' => ['adv', 'folder_watch'],
			],
			[
				'id'         => 'watch.settings',
				'label'      => 'Settings',
				'url'        => 'settings_watch',
				'permission' => ['adv', 'folder_watch_settings'],
			],
		],
	],
];
```

### 6.9. Интеграция в жизненный цикл

**В `index.php` (после `ModuleLoader::bootAll()`):**

```php
// Navbar: собрать items (из кеша или rebuild)
$navbarCache = new FileCache(CACHE_TMP_PATH);
NavbarBuilder::getItems($navbarCache, $moduleLoader);
```

**В `header.php` (4 injection points):**

```php
// В начале header.php:
$can = function ($type, $code) {
	return Authorization::check($type, $code);
};

// Внутри Management → Service Setup (после hardcoded Plex/Watch):
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_SERVICE_SETUP, $can); ?>

// Внутри Management → Tools (после hardcoded Fingerprint):
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_TOOLS, $can); ?>

// Внутри Management → Logs (после hardcoded Theft Detection):
<?php NavbarBuilder::render(NavbarPositions::MANAGEMENT_LOGS, $can); ?>

// После секции Providers:
<?php NavbarBuilder::render(NavbarPositions::TOP, $can); ?>
```

**В `ModuleManagerController::apiToggle()` (при вкл/выкл модуля):**

```php
// После записи config/modules.php:
NavbarBuilder::invalidate(new FileCache(CACHE_TMP_PATH));
```

### 6.10. Миграция существующих пунктов (постепенная)

**Фаза M-3a:** Создать `NavbarPositions.php` и `NavbarBuilder.php`. Добавить 4 injection points в header.php. Подключить в `index.php` после `bootAll()`.

**Фаза M-3b:** Создать `navbar.php` для модулей: watch, plex, fingerprint, theft-detection, magscan. Каждый модуль получает корректную `position` и `order`.

**Фаза M-3c:** Удалить hardcoded пункты модулей из header.php (Plex Sync, Watch Folders, Fingerprint, Theft Detection из Management секции). Теперь они приходят через NavbarBuilder.

**Что НЕ трогаем:** Core-пункты (Dashboard, Servers, Users, Content, Bouquets, Management каркас, Providers) остаются hardcoded. Они — часть ядра, не модулей.
### 7.2. Проблема: маршруты не загружаются в web (будет исправлено в M-1)

Текущий `public/index.php` загружает маршруты только из файлов:
```php
require_once $routesDir . $scope . '.php';   // routes/admin.php
require_once $routesDir . 'api.php';         // routes/api.php
```

**Решение (M-1):** После загрузки статических маршрутов, вызвать `ModuleLoader::bootAll()`:

```php
// В public/index.php, после require routes/{scope}.php:

$moduleLoader = new ModuleLoader();
$moduleLoader->loadAll();
$moduleLoader->bootAll(ServiceContainer::getInstance(), $router);
```

### 7.3. Как избежать конфликтов

**Правило 1: Модульный префикс.**
Все маршруты модуля регистрируются через `$router->group($moduleName, ...)`, что автоматически добавляет префикс.

**Правило 2: Модули не перезаписывают core-маршруты.**
Router хранит маршруты в assoc-array. Первая регистрация побеждает. Поскольку `routes/admin.php` загружается ДО `ModuleLoader::bootAll()`, core-маршруты имеют приоритет.

**Правило 3: API-действия с модульным префиксом.**
API-маршруты используют `group()`, Router автоматически добавляет `{prefix}_` к action name:
```php
$router->group('watch', function($r) {
    $r->api('enable', ...);  // → action = "watch_enable"
});
```

**Правило 4: Детекция конфликтов.**
`ModuleLoader::bootAll()` логирует warning при попытке перерегистрации:
```php
// В Router::get() / Router::post() — добавить проверку:
if (isset($this->getRoutes[$fullRoute])) {
    error_log("Router: duplicate route '{$fullRoute}' — keeping first registration");
    return $this;
}
```

### 7.4. Переходный период (дубликаты)

Сейчас маршруты watch/plex/fingerprint/theft-detection/magscan зарегистрированы в ДВУХ местах:
1. `public/routes/admin.php` (статически)
2. `{Module}Module::registerRoutes()` (через ModuleInterface)

**Стратегия:**
1. **M-1:** Включить `ModuleLoader::bootAll()` в web-путь. Модули регистрируют маршруты, но дубликаты из `routes/admin.php` имеют приоритет (зарегистрированы первыми).
2. **M-4:** Удалить модульные маршруты из `routes/admin.php`. Теперь маршруты приходят только из модулей.

---

## 8. Изоляция и зависимости

### 8.1. Правила взаимодействия (из ARCHITECTURE.md §6.2 — без изменений)

```
✅ Модуль МОЖЕТ:
   - Зависеть от core/ через constructor injection или static-вызовы
   - Вызывать Service из domain/ (бизнес-операции)
   - Вызывать Repository из domain/ (чтение данных)
   - Регистрировать маршруты, команды, кроны, navbar-items
   - Подписываться на события ядра
   - Иметь свои views, assets, config

❌ Модуль НЕ МОЖЕТ:
   - Модифицировать файлы core/ или domain/
   - Зависеть от другого модуля (без явной декларации в module.json)
   - Переопределять core-маршруты или core-сервисы
   - Обращаться к базе данных напрямую (только через Repository)
```

### 8.2. Механизмы расширения

| Механизм | Когда использовать | Пример |
|----------|-------------------|--------|
| **Router** маршруты | Модуль добавляет свои HTTP-страницы/API | `$router->group('watch', ...)` |
| **EventDispatcher** подписки | Модуль реагирует на действия ядра | `StreamStartedEvent` → запись DVR |
| **ServiceContainer** регистрация | Модуль регистрирует свои сервисы | `$container->set('watch.service', ...)` |
| **CommandRegistry** команды | Модуль добавляет CLI-команды/кроны | `$registry->register(new WatchCronJob())` |
| **Navbar** пункты меню | Модуль расширяет навигацию | `navbar.php` возвращает массив |
| **config.php** настройки | Модуль имеет конфигурируемые параметры | `config.php` возвращает дескриптор полей |

### 8.3. Зависимости между модулями

**Текущее состояние:** Ни один из 7 модулей не зависит от другого. `dependencies: []` у всех.

**Будущее:** Если модуль A зависит от модуля B, `ModuleLoader` должен загрузить B до A.

Реализация:
```php
// В ModuleLoader::loadAll(), после сбора всех модулей:
private function sortByDependencies() {
    // Topological sort: modules с пустым dependencies — первые
    $sorted = [];
    $visited = [];

    foreach ($this->modules as $name => $module) {
        $this->visit($name, $visited, $sorted);
    }

    $this->modules = $sorted;
}
```

**Ограничение:** Циклические зависимости — Fatal Error с сообщением. Не допускаются.

### 8.4. Прямые зависимости запрещены. Межмодульное взаимодействие — только через ядро

```php
// ❌ ЗАПРЕЩЕНО — модуль watch вызывает plex напрямую:
class WatchService {
    public function scan() {
        $plexService = new PlexService();  // ← нарушение изоляции
        $plexService->sync();
    }
}

// ✅ ПРАВИЛЬНО — через событие ядра:
class WatchService {
    public function scanComplete($folderId) {
        EventDispatcher::publish('watch.scan_complete', ['folder_id' => $folderId]);
    }
}

// Модуль plex подписывается:
class PlexModule implements ModuleInterface {
    public function getEventSubscribers(): array {
        return [
            'watch.scan_complete' => [PlexService::class, 'onWatchScanComplete'],
        ];
    }
}
```

---

## 9. Обнаруженные проблемы и баги

### 9.1. Исторический баг: `listen()` vs `subscribe()`

Этот баг относился к ранней версии `ModuleLoader::bootAll()`. В текущем коде `ModuleLoader` уже использует `EventDispatcher::subscribe()`, поэтому fatal error из-за `listen()` больше не актуален.

Оставшийся практический разрыв другой: `bootAll()` не вызывается в web-пути, поэтому модульные event subscribers не подключаются для HTTP-контекста.

### 9.2. `events` не зарегистрирован в ServiceContainer

**Файл:** `src/bootstrap.php` :: `populateContainer()`
**Баг:** `$container->has('events')` вернёт `false`, потому что EventDispatcher нигде не регистрируется в контейнере.

**Исправление:** В `populateContainer()` добавить:
```php
$container->set('events', EventDispatcher::class);
```

### 9.3. ModuleLoader не вызывается в web-контексте

**Файл:** `src/public/index.php`
**Описание:** `ModuleLoader::bootAll()` вызывается только в `console.php` (CLI). В web-пути модули не загружаются.

**Исправление:** интегрировать в секцию 5 `index.php` (после загрузки routes).

### 9.4. Navbar — нет точки расширения

**Файл:** `src/public/Views/admin/header.php`
**Описание:** Модульные пункты меню (watch, plex, fingerprint и т.д.) hardcoded в HTML. Нет механизма динамического добавления.

**Решение:** Описано в §6.3–6.10.

---

## 10. Поэтапный план реализации

### Фаза M-1: Оживить модульную загрузку в web (Critical Bug Fixes)

**Цель:** Маршруты и сервисы модулей загружаются в HTTP-контексте. Event subscribers работают.

**Задачи:**

| # | Задача | Файл | Строки | Сложность |
|:-:|--------|------|--------|:---------:|
| 1 | Исправить `listen` → `subscribe` в `ModuleLoader::bootAll()` | `core/Module/ModuleLoader.php` L141 | 1 | Тривиальная |
| 2 | Зарегистрировать `events` в `populateContainer()` | `bootstrap.php` :: `populateContainer()` | 1 | Тривиальная |
| 3 | Вызвать `ModuleLoader::loadAll() + bootAll()` в `index.php` | `public/index.php` секция 5 | ~10 | Средняя |
| 4 | Добавить duplicate route protection в Router | `core/Http/Router.php` :: `get/post/api` | ~6 | Лёгкая |

**Точка интеграции в `index.php`:**

```php
// ─── Секция 5: Загрузка маршрутов ───

$router = Router::getInstance();
$routesDir = __DIR__ . '/routes/';

// Статические маршруты ядра (имеют приоритет)
if (file_exists($routesDir . $scope . '.php')) {
    require_once $routesDir . $scope . '.php';
}
if (file_exists($routesDir . 'api.php')) {
    require_once $routesDir . 'api.php';
}

// ── NEW: Загрузка модулей (маршруты + boot + события) ──
$moduleLoader = new ModuleLoader();
$moduleLoader->loadAll();
$moduleLoader->bootAll(ServiceContainer::getInstance(), $router);
```

**Проверка:** После M-1 модульные и статические маршруты работают параллельно. Дубликаты — core побеждает.

**Предусловия:** Нет
**Риск:** Низкий. Добавление — не замена. Legacy-маршруты остаются.

---

### Фаза M-2: Environment фильтрация + dependency ordering

**Цель:** `ModuleLoader` фильтрует модули по `environment` и сортирует по зависимостям.

**Задачи:**

| # | Задача | Файл | Сложность |
|:-:|--------|------|:---------:|
| 1 | Чтение `environment` из `module.json` + фильтрация | `ModuleLoader::loadAll()` | Лёгкая |
| 2 | Чтение `dependencies` + topological sort | `ModuleLoader::sortByDependencies()` | Средняя |
| 3 | Обновить `module.json` — добавить `environment: "main"` в 7 модулей | `modules/*/module.json` | Тривиальная |

**Предусловия:** M-1
**Риск:** Низкий. Обратно совместимо — по умолчанию `environment = "main"`, `dependencies = []`.

---

### Фаза M-3: Динамическая навигация (Navbar)

**Цель:** Модули регистрируют пункты меню через `navbar.php`. Hardcoded модульные пункты удаляются из header.php. Данные кешируются через FileCache с авто-инвалидацией по modules_hash.

**Задачи:**

| # | Задача | Файл | Сложность |
|:-:|--------|------|:---------:|
| 1 | Создать `NavbarPositions` — константы injection points | `core/Http/NavbarPositions.php` NEW | Тривиальная |
| 2 | Создать `NavbarBuilder` — build + cache + validate + render | `core/Http/NavbarBuilder.php` NEW | Высокая |
| 3 | Добавить 4 injection points в header.php | `public/Views/admin/header.php` | Средняя |
| 4 | Добавить `NavbarBuilder::getItems()` вызов в `index.php` | `public/index.php` | Лёгкая |
| 5 | Создать `navbar.php` для watch, plex, fingerprint, theft-detection, magscan | 5 файлов в `modules/*/` | Средняя |
| 6 | Удалить hardcoded модульные пункты из header.php | `public/Views/admin/header.php` | Средняя |
| 7 | Добавить `NavbarBuilder::invalidate()` в `ModuleManagerController::apiToggle()` | `public/Controllers/Admin/ModuleManagerController.php` | Лёгкая |

**Подфазы:**
- **M-3a:** Создать `NavbarPositions.php`, `NavbarBuilder.php`. Добавить injection points в header.php. Подключить `getItems()` в index.php. На этом этапе injection points пустые — нет navbar.php в модулях.
- **M-3b:** Создать `navbar.php` для 5 модулей (watch, plex, fingerprint, theft-detection, magscan). Проверить рендеринг.
- **M-3c:** Удалить hardcoded модульные пункты из header.php. Проверить навигацию.

**Предусловия:** M-1
**Риск:** Средний. Затрагивает UI. Подфазы позволяют откатить M-3c отдельно при регрессии.

---

### Фаза M-4: Консолидация маршрутов — удаление дубликатов

**Цель:** Модульные маршруты приходят ТОЛЬКО из `ModuleInterface::registerRoutes()`. Дубликаты из `routes/admin.php` удалены.

**Задачи:**

| # | Задача | Файл | Сложность |
|:-:|--------|------|:---------:|
| 1 | Удалить маршруты watch/* из `routes/admin.php` | `public/routes/admin.php` | Лёгкая |
| 2 | Удалить маршруты plex/* из `routes/admin.php` | `public/routes/admin.php` | Лёгкая |
| 3 | Удалить маршруты fingerprint, theft_detection, magscan из `routes/admin.php` | `public/routes/admin.php` | Лёгкая |
| 4 | Проверить все модульные страницы доступны через Router | Ручной тест | Средняя |

**Предусловия:** M-1, M-3 (navbar должна работать до удаления статических маршрутов)
**Риск:** Средний. При ошибке — модульные страницы вернут 404. Решение: feature flag.

---

### Фаза M-5: Система настроек модулей

**Цель:** Модули декларируют свои настройки в `config.php`. UI генерируется автоматически.

**Задачи:**

| # | Задача | Файл | Сложность |
|:-:|--------|------|:---------:|
| 1 | Создать `ModuleManagerController` | `public/Controllers/Admin/ModuleManagerController.php` | Средняя |
| 2 | Создать view `modules/list.php` | `public/Views/admin/modules/list.php` | Средняя |
| 3 | Создать view `modules/settings_form.php` (auto-render) | `public/Views/admin/modules/settings_form.php` | Средняя |
| 4 | Маршруты: `modules`, `settings/module/{name}`, `module_toggle` API | `public/routes/admin.php` | Лёгкая |
| 5 | `config.php` для модулей watch, plex | `modules/watch/config.php`, `modules/plex/config.php` | Средняя |
| 6 | Обновить `module.json` — добавить `has_settings: true` | `modules/*/module.json` | Тривиальная |

**Предусловия:** M-1
**Риск:** Низкий. Новая функциональность, не затрагивает существующие.

---

### Фаза M-6: Полная модульная изоляция — миграция оставшихся hardcoded зависимостей

**Цель:** Ядро не содержит прямых ссылок на модульные классы. Event-based расширение для пунктов, которые сейчас hardcoded в ядре.

**Задачи:**

| # | Задача | Файл | Сложность |
|:-:|--------|------|:---------:|
| 1 | Заменить `TmdbService::` вызовы в `admin_api` replacement на event | `domain/Vod/MovieService.php` | Средняя |
| 2 | Audit: `grep -rn 'Plex\|Watch\|Tmdb\|Ministra\|Fingerprint' src/domain/ src/core/` | Весь `domain/` и `core/` | Средняя |
| 3 | Перенести найденные прямые вызовы на событийную модель | По результатам аудита | Высокая |

**Предусловия:** M-1, M-3
**Риск:** Высокий. Может затрагивать бизнес-логику. Требует тщательного тестирования.

---

### Сводная таблица фаз

| Фаза | Название | Зависит от | Файлов меняется | Риск |
|:----:|----------|:----------:|:---------------:|:----:|
| **M-1** | Оживить web-загрузку модулей | — | 4 | Низкий |
| **M-2** | Environment + dependencies | M-1 | 8 | Низкий |
| **M-3** | Динамическая навигация (NavbarBuilder + FileCache) | M-1 | 10 | Средний |
| **M-4** | Удаление дублирующих маршрутов | M-1, M-3 | 1 | Средний |
| **M-5** | Система настроек | M-1 | 6+ | Низкий |
| **M-6** | Полная изоляция ядро↔модули | M-1, M-3 | По аудиту | Высокий |

**Рекомендуемый порядок:** M-1 → M-2 → M-5 → M-3 → M-4 → M-6

Обоснование: M-5 (настройки) не зависит от navbar и даёт немедленную пользу. M-3 (navbar) требует больше UI-работы. M-6 — последний, самый рискованный.

---

## 11. Риски

### 11.1. Технические риски

| Риск | Вероятность | Влияние | Митигация |
|------|:----------:|:-------:|-----------|
| Модульные маршруты конфликтуют с core | Низкая | Высокое | Duplicate detection в Router (M-1.4) |
| `bootAll()` замедляет каждый запрос | Средняя | Среднее | `ModuleLoader` кэширует список модулей в FileCache |
| Navbar rendering ломает layout | Средняя | Высокое | Strict item validation при build + try-catch per module |
| Navbar кеш desync | Низкая | Среднее | modules_hash auto-invalidation + TTL safety net (3600s) |
| Невалидный navbar.php ломает всю навигацию | Средняя | Высокое | Per-module try-catch + validateItem() skip + error_log |
| Конфликт id между модулями | Низкая | Среднее | Global id uniqueness check при build |
| `EventDispatcher` static = нетестируемый | Низкая | Низкое | Достаточно для текущих нужд. Рефактор — будущее |
| Модуль с ошибкой ломает весь bootstrap | Средняя | Высокое | try-catch в `ModuleLoader::bootAll()` per-module |

### 11.2. Организационные риски

| Риск | Митигация |
|------|-----------|
| Контрибьюторы продолжат добавлять маршруты в `routes/admin.php` вместо `registerRoutes()` | Документация + CI-проверка: новые маршруты модулей → только через ModuleInterface |
| Сторонние модули нарушают контракт | `ModuleLoader` проверяет `instanceof ModuleInterface` (уже есть) |
| Модули с `install()` создают таблицы, но `uninstall()` не чистит | Рекомендация: `uninstall()` обязательно зеркалит `install()`. Но не enforce |

### 11.3. Слабость: static EventDispatcher

`EventDispatcher` полностью static → нельзя инжектировать через конструктор → нельзя mockить в тестах → расходится с целевым направлением из §1.2 [ARCHITECTURE.md](ARCHITECTURE.md). **Это осознанный компромисс** — рефактор EventDispatcher на instance-based потребует изменения всех подписчиков. Откладывается до появления реальной потребности в тестировании событий.

### 11.4. Слабость: отсутствие версионной совместимости

Поле `requires_core` в module.json проверяется **нигде**. `ModuleLoader` его читает, но не валидирует. Пока все модули в одном репозитории — это не проблема. Станет проблемой при появлении сторонних/коммерческих модулей.

---

## Приложение A: Чек-лист для создания нового модуля

```
1. [ ] Создать директорию src/modules/{name}/
2. [ ] Создать module.json (name, version, description, requires_core)
3. [ ] Создать {Name}Module.php implements ModuleInterface
4. [ ] Реализовать getName(), getVersion()
5. [ ] boot() — зарегистрировать сервисы в ServiceContainer (если есть)
6. [ ] registerRoutes() — зарегистрировать GET/POST/API маршруты
7. [ ] registerCommands() — зарегистрировать CLI-команды/кроны (если есть)
8. [ ] getEventSubscribers() — подписаться на события ядра (если нужно)
9. [ ] install() — создать таблицы/начальные данные (если нужно)
10. [ ] uninstall() — удалить таблицы/данные (зеркало install)
11. [ ] (Опционально) Создать navbar.php для навигации
12. [ ] (Опционально) Создать config.php для настроек
13. [ ] (Опционально) Создать views/ для HTML-шаблонов
14. [ ] Протестировать:
        - php -l modules/{name}/{Name}Module.php
        - Модуль загружается (проверить /modules в admin)
        - Страницы доступны по маршрутам
        - Отключение через modules.php не ломает систему
        - Удаление директории не ломает систему
```

## Приложение B: Пример минимального модуля

```php
// modules/example/module.json
{
    "name": "example",
    "description": "Example module for demonstration",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "has_settings": false,
    "has_navbar": true
}

// modules/example/ExampleModule.php
<?php
class ExampleModule implements ModuleInterface {

    public function getName(): string { return 'example'; }
    public function getVersion(): string { return '1.0.0'; }

    public function boot(ServiceContainer $container): void {
        // Ничего — нет сервисов
    }

    public function registerRoutes(Router $router): void {
        $router->get('example', [ExampleModule::class, 'page'], [
            'permission' => ['adv', 'settings'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {}
    public function getEventSubscribers(): array { return []; }
    public function install(): void {}
    public function uninstall(): void {}

    // Inline handler (для простых модулей без отдельного Controller)
    public static function page() {
        echo '<h3>Example Module</h3><p>It works!</p>';
    }
}

// modules/example/navbar.php
<?php
return [
    [
        'id'         => 'example.page',
        'label'      => 'Example',
        'icon'       => 'fas fa-puzzle-piece',
        'url'        => 'example',
        'permission' => ['adv', 'settings'],
        'position'   => 'top',       // NavbarPositions::TOP
        'order'      => 200,
    ],
];
```
