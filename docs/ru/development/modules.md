# Система модулей

## Обзор

Модуль — изолированная директория в `src/modules/` с известным контрактом. Удаление модуля **не ломает систему** — она продолжает работать, деградируя в функциональности.

### Архитектура

```
modules/
├── my-module/
│   ├── module.json            # Метаданные (name, description, version, requires_core)
│   ├── MyModule.php           # Источник истины (implements ModuleInterface)
│   ├── MyService.php          # Сервисы модуля
│   ├── MyController.php       # Контроллер (если есть страницы)
│   ├── MyCron.php             # Крон-логика (если есть)
│   ├── MyCronJob.php          # CLI-обёртка крона (implements CommandInterface)
│   ├── views/                 # Шаблоны страниц
│   │   ├── my_page.php
│   │   └── my_page_scripts.php
│   └── migrations/            # SQL-миграции модуля (если есть)
│       └── 001_create_table.sql
```

### Принципы

| Правило | Описание |
|---------|----------|
| **PHP — источник истины** | Всё поведение определяется в классе модуля, не в JSON |
| **module.json — только метаданные** | `name`, `description`, `version`, `requires_core` |
| **Авто-обнаружение** | `ModuleLoader` сканирует `modules/*/module.json` — регистрация в конфиге не нужна |
| **Изоляция** | Модуль зависит от `core/` и `domain/`, но НИКОГДА от других модулей |
| **Graceful degradation** | Удаление директории модуля не вызывает ошибок |
| **Нет обратных зависимостей** | Ядро (`core/`) не знает о существовании модулей |
| **DI через контейнер** | Сервисы регистрируются в `boot()`, не через глобалы |
| **Явная регистрация команд** | Модуль сам регистрирует команды в `registerCommands()`, без filesystem scanning |

---

## Шаг 1. Создать директорию

```bash
mkdir -p src/modules/my-module
```

Имя директории = имя модуля. Используйте kebab-case: `my-module`.

---

## Шаг 2. Создать манифест `module.json`

```json
{
    "name": "my-module",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "dependencies": [],
    "has_navbar": false,
    "has_settings": false
}
```

### Поля манифеста

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `name` | `string` | ✅ | Уникальное имя модуля (совпадает с именем директории) |
| `description` | `string` | ⛔ | Краткое человекочитаемое описание модуля |
| `version` | `string` | ✅ | Версия в формате semver (`1.0.0`) |
| `requires_core` | `string` | ✅ | Минимальная версия ядра (`>=2.0`) |
| `environment` | `string` | ✅ | Окружение: `main` (основной сервер), `lb` (load-balancer), `any` (оба) |
| `dependencies` | `array` | ✅ | Список имён модулей, от которых зависит этот модуль. Пустой массив `[]` если нет зависимостей |
| `has_navbar` | `boolean` | ✅ | Есть ли пункты навбара в админ-панели |
| `has_settings` | `boolean` | ✅ | Есть ли страница настроек модуля |

> **Важно:** 
> - Все зависимости должны быть строками (имена модулей).
> - Зависимости должны существовать, иначе ModuleLoader выбросит ошибку при loadAll().
> - Циклические зависимости детектируются автоматически и приводят к ошибке загрузки.
> - Модули загружаются в порядке зависимостей: если A зависит от B, то B загружается первым.
> - `environment` должен быть `main`, `lb` или `any`. Если `main`, модуль загружается только на основных серверах.

**Примеры манифестов:**

```json
{
  "name": "watch",
  "description": "Watch activity tracking and statistics",
  "version": "1.2.0",
  "requires_core": ">=2.0",
  "environment": "main",
  "dependencies": [],
  "has_navbar": true,
  "has_settings": true
}
```

```json
{
  "name": "plex",
  "description": "Plex integration module",
  "version": "2.0.0",
  "requires_core": ">=2.0",
  "environment": "any",
  "dependencies": ["ministra"],
  "has_navbar": true,
  "has_settings": true
}
```

```json
{
  "name": "load-balancer-stats",
  "description": "Load balancer statistics collector",
  "version": "1.0.0",
  "requires_core": ">=2.0",
  "environment": "lb",
  "dependencies": [],
  "has_navbar": false,
  "has_settings": false
}
```

> **Примечание о зависимостях:** Если ваш модуль требует функций другого модуля, перечислите его имя в `dependencies`. ModuleLoader автоматически гарантирует, что требуемый модуль загружен перед вашим, и выбросит ошибку, если требуемый модуль отсутствует или отключен.

> **Примечание об окружении:** По умолчанию используйте `main` для модулей основного сервера, `lb` для load-balancer, `any` если модуль работает везде. На практике `any` используется редко — большинство модулей специфичны для одного окружения.

---

## Шаг 3. Создать класс модуля

Файл `src/modules/my-module/MyModule.php`:

```php
<?php

class MyModule implements ModuleInterface {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', 'MyService');
    }

    public function registerRoutes(Router $router): void {
        $router->get('my-module', [MyController::class, 'index'], [
            'permission' => ['adv', 'my_module'],
        ]);
        $router->api('my_action', [MyController::class, 'apiAction'], [
            'permission' => ['adv', 'my_module'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new MyCronJob());
    }

    public function getEventSubscribers(): array {
        return [];
    }

    public function install(): void {
        // Создание таблиц, начальных данных и т.д.
    }

    public function uninstall(): void {
        // Очистка данных модуля
    }

    public function registerNavbar(): void {
        // Регистрируйте пункты навигации через NavbarRegistry::add()
        // Если пунктов нет — оставьте метод пустым
    }
}
```

### Контракт `ModuleInterface`

| Метод | Описание |
|-------|----------|
| `getName(): string` | Уникальное имя (совпадает с директорией) |
| `getVersion(): string` | Semver-версия |
| `boot(ServiceContainer)` | Регистрация сервисов. Вызывается один раз при загрузке |
| `registerRoutes(Router)` | HTTP-маршруты и API-действия |
| `registerCommands(CommandRegistry)` | Явная регистрация CLI-команд и крон-задач |
| `getEventSubscribers(): array` | Подписки на события ядра |
| `install(): void` | Установка модуля (миграции, начальные данные) |
| `uninstall(): void` | Удаление данных модуля |
| `registerNavbar(): void` | Регистрация пунктов меню в админ-navbar |

---

## Шаг 4. Автоматическая регистрация

**Регистрация в конфиге не нужна.** `ModuleLoader` автоматически обнаруживает все модули из `modules/*/module.json`.

Для **отключения** модуля — добавьте в `src/config/modules.php`:

```php
return [
    'my-module' => ['enabled' => false],
];
```

`config/modules.php` содержит только overrides. Если файл пуст или отсутствует — все обнаруженные модули загружаются.

### Как работает загрузка

1. `ModuleLoader::loadAll()` сканирует `modules/*/module.json`
2. Проверяет overrides в `config/modules.php`
3. Фильтрует модули по окружению (main/lb/any)
4. Сортирует топологически по зависимостям (если циклическая зависимость или missing — error)
5. Определяет класс по конвенции: `my-module` → `MyModule` (kebab-case → PascalCase + Module)
6. Создаёт экземпляр модуля

В web-контексте (`public/index.php` для admin/reseller):
- `loadAll()` → загружает и инстанцирует все модули
- `bootAll($container, $router)` → вызывает `boot()`, `registerRoutes()`, `registerNavbar()`, подписывает на события

> **Статус M-1:** ✅ Завершена. Web boot модулей полностью включен в front controller.

В CLI-контексте (`console.php`):
- `loadAll()` → загружает и инстанцирует все модули
- `registerAllCommands($registry)` → вызывает `registerCommands()` у каждого модуля

---

## Шаг 4б. Регистрировать кнопки и пункты меню в navbar

Пункты меню больше не добавляются вручную в `header.php`.
Каждый модуль добавляет свои кнопки через `registerNavbar()` и `NavbarRegistry::add()`.

Пример (модуль добавляет кнопку в service setup и пункт в logs):

```php
public function registerNavbar(): void {
    NavbarRegistry::add((new NavbarItem('management.service_setup.my_module'))
        ->parent('management.service_setup')
        ->url('my_module')
        ->label('my_module')
        ->permissions(['my_module'])
        ->order(60));

    NavbarRegistry::add((new NavbarItem('management.logs.my_module_log'))
        ->parent('management.logs')
        ->url('my_module_logs')
        ->label('', 'My Module Logs')
        ->permissions(['my_module'])
        ->order(170));
}
```

Правила для модулей:

1. `key` должен быть уникальным и стабильным (лучше в формате `section.group.item`).
2. `parent` должен ссылаться на существующий узел из core-дерева.
3. `order` управляет позицией внутри одного parent (меньше = выше).
4. Для переводимого текста используйте `label('translation_key')`.
5. Для literal-текста используйте `label('', 'Literal Text')`.
6. Если модулю нечего добавлять в меню — оставьте `registerNavbar()` пустым.

---

## Как рендерится navbar

Подробная техническая документация по рендеру navbar вынесена в отдельный системный раздел:

- [Рендер navbar в панели модулей](../system/modules-navbar-rendering.md)

---

## Шаг 4а. Создать контроллер (опционально)

Если модуль имеет страницы в админке, создайте класс контроллера. Контроллер использует **глобальную систему layout** через `renderUnifiedLayoutHeader()` / `renderUnifiedLayoutFooter()`.

Файл `src/modules/my-module/MyController.php`:

```php
<?php

class MyController {

	protected $viewsPath;
	protected $layoutsPath;

	public function __construct() {
		$this->viewsPath = __DIR__ . '/views';
		$this->layoutsPath = MAIN_HOME . 'public/Views/layouts/';
		require_once $this->layoutsPath . 'admin.php';
		require_once $this->layoutsPath . 'footer.php';
	}

	public function index(): void {
		$_TITLE = 'My Module';
		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/my_page.php';
		renderUnifiedLayoutFooter('admin');
		include $this->viewsPath . '/my_page_scripts.php';
	}

	public function apiAction(): void {
		// API-действия (POST) — layout не нужен
		$action = $_GET['sub'] ?? '';
		// ...
		echo json_encode(['result' => true]);
		exit;
	}
}
```

### Правила layout

| Правило | Описание |
|---------|----------|
| **viewsPath** | Всегда `__DIR__ . '/views'` — контроллер уже находится внутри директории модуля |
| **layoutsPath** | `MAIN_HOME . 'public/Views/layouts/'` — общий для всех модулей |
| **GET-страницы** | Обязательно вызвать `renderUnifiedLayoutHeader()` до и `renderUnifiedLayoutFooter()` после view |
| **API-действия** | Без layout — возвращаем JSON напрямую |
| **Скрипты** | JS модуля загружается через `<module>_scripts.php` после footer |

> **Важно:** Используйте `__DIR__ . '/views'` для viewsPath — **не** `dirname(__DIR__) . '/modules/...'`. Файл контроллера уже внутри директории модуля.

> `renderUnifiedLayoutHeader('admin', [...])` и `renderUnifiedLayoutFooter('admin')` определены в `public/Views/layouts/admin.php` и `footer.php`. Они извлекают необходимые глобальные переменные (`$rSettings`, `$rUserInfo`, `$db` и др.) и рендерят общий header/footer админки.

---

## Шаг 5. Добавить крон-задачу (опционально)

### 5.1 Крон-класс (логика) — в модуле

Файл `src/modules/my-module/MyCron.php`:

```php
<?php

class MyCron {

    public static function run(): void {
        $items = Database::query("SELECT * FROM my_table WHERE status = 'pending'");
        foreach ($items as $item) {
            self::processItem($item);
        }
    }

    private static function processItem(array $item): void {
        // Обработка элемента
    }
}
```

### 5.2 CronJob-обёртка — в директории модуля

Файл `src/modules/my-module/MyCronJob.php`:

```php
<?php

require_once MAIN_HOME . 'cli/CronTrait.php';

class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_task';
    }

    public function getDescription(): string {
        return 'Cron: описание задачи';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        require INCLUDES_PATH . 'admin.php';
        require_once __DIR__ . '/MyCron.php';

        $this->initCron('XC_VM[MyTask]');

        MyCron::run();

        return 0;
    }
}
```

### 5.3 Регистрация в модуле

Команда регистрируется **явно** в `registerCommands()`:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

> **Важно:** Filesystem scanning модулей не используется. Каждый модуль сам знает свои команды и регистрирует их в `registerCommands()`.

### 5.4 Добавить в crontab

В `src/cli/Commands/StartupCommand.php` метод `installCrontab()` добавьте запись:

```php
$rCrons[] = '*/5 * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:my_task # XC_VM';
```

---

## Шаг 6. Настройка сборки (Makefile)

Директория `modules/` **не** входит в `LB_DIRS` — все модули присутствуют только в MAIN-сборках по умолчанию. Файлы модуля (кроны, команды, вьюхи) автоматически исключены из LoadBalancer сборок.

---

## Полные примеры

### Минимальный модуль (без кронов, без маршрутов)

```
modules/my-module/
├── module.json
└── MyModule.php
```

`module.json`:
```json
{
    "name": "my-module",
    "version": "1.0.0",
    "requires_core": ">=2.0"
}
```

`MyModule.php` — реализует все методы `ModuleInterface`. Методы без поведения остаются пустыми.

### Полный модуль (сервисы + маршруты + команды + события)

Пример: `plex`, `watch`.

```
modules/my-module/
├── module.json
├── MyModule.php
├── MyService.php
├── MyRepository.php
├── MyController.php
├── MyCron.php
├── MyCronJob.php
└── views/
    ├── my_page.php
    └── my_page_scripts.php
```

Все файлы модуля живут внутри его директории. CronJob-обёртки регистрируются через `registerCommands()`.

Контроллеры используют глобальную систему layout — см. [Шаг 4а](#шаг-4а-создать-контроллер-опционально) для паттерна.

### Модуль с событиями

```php
public function getEventSubscribers(): array {
    return [
        'stream.started'  => [MyHandler::class, 'onStreamStarted'],
        'stream.stopped'  => [MyHandler::class, 'onStreamStopped'],
        'user.connected'  => [MyHandler::class, 'onUserConnected'],
    ];
}
```

---

## Чеклист добавления модуля

- [ ] Создать директорию `src/modules/<name>/`
- [ ] Создать `module.json` (`name`, `version`, `requires_core`)
- [ ] Создать `<Name>Module.php` (implements `ModuleInterface`)
- [ ] (Если есть кроны) Создать `<Name>Cron.php` + `<Name>CronJob.php` в модуле
- [ ] (Если есть кроны) Зарегистрировать в `registerCommands()`
- [ ] (Если есть кроны) Добавить в crontab через `StartupCommand`
- [ ] (Если есть страницы) Создать контроллер с `renderUnifiedLayoutHeader/Footer`
- [ ] (Если есть страницы) Создать директорию `views/` с шаблонами страниц
- [ ] (Если есть страницы) Зарегистрировать маршруты в `registerRoutes()` (и временно в `public/routes/admin.php`)
- [ ] Проверить: `php -l src/modules/<name>/<Name>Module.php`
- [ ] Проверить: модуль загружается при `php console.php --list`
- [ ] Проверить: удаление директории модуля не вызывает fatal error

---

## Доступные события ядра

| Событие | Описание | Данные |
|---------|----------|--------|
| `stream.started` | Стрим запущен | `['stream_id' => int]` |
| `stream.stopped` | Стрим остановлен | `['stream_id' => int]` |
| `user.connected` | Пользователь подключился | `['user_id' => int, 'stream_id' => int]` |
| `cache.rebuilt` | Кэш перестроен | `[]` |

---

## FAQ

**Q: Как отключить модуль?**
A: В `src/config/modules.php` добавьте `'module-name' => ['enabled' => false]`.

**Q: Нужно ли регистрировать модуль в конфиге?**
A: Нет. `ModuleLoader` автоматически обнаруживает все модули из `modules/*/module.json`. Конфиг нужен только для отключения.

**Q: Модуль зависит от другого модуля — как?**
A: **Не допускайте зависимостей между модулями.** Модуль зависит только от `core/` и `domain/`. Если нужна общая функциональность — вынесите в ядро.

**Q: Могу ли я использовать `$db` напрямую?**
A: Технически да (через `global $db`), но архитектурно правильно использовать `Database` через `ServiceContainer` или Repository.

**Q: Как модуль получает доступ к настройкам?**
A: Через `SettingsManager::getAll()['my_key']`. Ключи настроек модуля хранятся в общей таблице `settings`.

**Q: Мой модуль нужен только на MAIN — что делать?**
A: Все модули уже MAIN-only по умолчанию — `modules/` не входит в `LB_DIRS`.
