# Система модулей

## Обзор

Модуль — изолированная директория в `src/Modules/` с известным контрактом. Удаление модуля **не ломает систему** — она продолжает работать, деградируя в функциональности.

Система построена на принципах **Extensible Platform**:

- Ядро (`Core/`) ничего не знает о модулях
- Модули расширяют ядро через интерфейсы-контракты
- Никакой правки файлов ядра, никакого eval, никакого monkey patching
- Любой модуль отключается через `config/modules.php` без последствий для ядра

---

## Структура директории модуля

```
modules/
└── my-module/
    ├── module.json                # Метаданные + поля загрузки
    ├── MyModule.php               # Главный класс (implements ModuleInterface)
    ├── MyService.php              # Сервисы модуля
    ├── MyController.php           # Контроллер (если есть страницы)
    ├── MyCron.php                 # Крон-логика
    ├── MyCronJob.php              # CLI-обёртка (implements CommandInterface)
    ├── MyStreamMiddleware.php     # Stream-middleware (опционально)
    ├── database.sql               # Мастер-схема — полный текущий CREATE/seed (опц.)
    ├── database_drop.sql          # Удаление — DROP всех таблиц модуля (опц.)
    ├── migrations/                # Дельты между версиями (опц.)
    │   └── 1.1.0.sql              # Применяется только при апгрейде выше 1.1.0
    └── views/
        ├── my_page.php
        └── my_page_scripts.php
```

Модуль владеет своей схемой через **три роли — зеркало ядра** (`bin/install/database.sql`
+ `migrations/`):

| Файл | Роль | Когда выполняется |
| ---- | ---- | ----------------- |
| `database.sql` | **Одна** мастер-схема — полный текущий `CREATE`/seed | свежая **установка** |
| `database_drop.sql` | **Один** файл удаления — `DROP TABLE` всех таблиц модуля | **удаление** |
| `migrations/<semver>.sql` | **Папка** форвардных дельт между версиями | **обновление**, для версий в `(installed, current]` |

Правила:

- **Свежая установка выполняет только `database.sql`**, поэтому он всегда должен отражать
  ПОСЛЕДНЮЮ схему (все дельты уже влиты). Watermark `installed_version` гарантирует, что
  дельты не проигрываются повторно на свежей установке.
- **Дельты только форвардные** (`ALTER`/`INSERT`), имя `<semver>.sql` — удаление одно
  (`database_drop.sql`), поэтому пофайловых `.down` больше нет.
- Держите дельты **идемпотентными** (`ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`).
- Модуль без схемы не поставляет эти файлы. Модуль только с дельтами (без `database.sql`)
  всё равно установится, проиграв все дельты ≤ своей версии.

---

## Манифест `module.json`

```json
{
    "name": "my-module",
    "description": "Краткое описание модуля",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "dependencies": [],
    "optional_dependencies": [],
    "has_navbar": false,
    "has_settings": false,
    "priority": 0
}
```

### Поля манифеста

| Поле | Тип | По умолчанию | Описание |
| ------ | ----- | :---: | ------------ |
| `name` | `string` | — | Уникальное имя (совпадает с именем директории, kebab-case) |
| `description` | `string` | `""` | Краткое человекочитаемое описание |
| `version` | `string` | — | Semver-версия (`1.0.0`) |
| `requires_core` | `string` | — | Минимальная версия ядра (`>=2.0`) |
| `environment` | `string` | `"main"` | `main` — основной сервер, `lb` — load-balancer, `any` — оба |
| `dependencies` | `array` | `[]` | Обязательные зависимости: при недоступности зависимый модуль пропускается (см. ниже) |
| `optional_dependencies` | `array` | `[]` | Мягкие зависимости: при отсутствии модуль загружается без них |
| `has_navbar` | `bool` | `false` | Есть ли пункты навбара |
| `has_settings` | `bool` | `false` | Есть ли страница настроек |
| `priority` | `int` | `0` | Приоритет загрузки: выше значение — раньше загрузится (при топологически равном положении) |

### Разница между `dependencies` и `optional_dependencies`

```json
{
    "dependencies": ["tmdb"],
    "optional_dependencies": ["plex"]
}
```

- `dependencies`: модуль `tmdb` **обязан** быть загружен до `my-module`. Если `tmdb` недоступен (отсутствует на диске, отключён или в состоянии `failed`), то `my-module` **пропускается** с предупреждением в лог — каскадно (всё, что зависит от `my-module`, тоже пропустится). Загрузка остальных модулей и работа панели/CLI при этом **не прерывается** (см. [«Как работает загрузка»](#как-работает-загрузка)).
- `optional_dependencies`: если `plex` присутствует — он загрузится **до** `my-module`. Если отсутствует — загрузка продолжается без него.

> **Защита от рассинхрона.** Отключить (`disabled`) модуль, от которого зависят **включённые** модули, через панель/`ModuleManager::setState()` нельзя — операция будет отклонена с пояснением, какие модули его требуют (по аналогии с запретом удаления `uninstallModule()`). Это не даёт создать состояние «`plex` включён, а его зависимость `watch` выключена».

### Приоритет загрузки

При топологически равных позициях (нет зависимости друг от друга), модули с бо́льшим `priority` загружаются и бутятся первыми.

```json
{ "name": "auth-guard", "priority": 100 }   ← загрузится первым
{ "name": "tmdb",       "priority": 50  }   ← второй
{ "name": "watch",      "priority": 0   }   ← третий (по умолчанию)
```

При равных `priority` — алфавитный порядок (детерминированность).

---

## Интерфейсы модуля

`ModuleInterface` — составной интерфейс, объединяющий 4 суб-интерфейса:

```
ModuleInterface
├── ServiceProviderInterface    boot(), getEventSubscribers()
├── RouteProviderInterface      registerRoutes()
├── CommandProviderInterface    registerCommands()
└── NavbarProviderInterface     registerNavbar()

+ getName(), getVersion(), install(), uninstall()
```

Пятый суб-интерфейс — **опциональный**, не входит в `ModuleInterface`:

```
StreamMiddlewareProviderInterface   getStreamMiddleware()
```

Модуль реализует его дополнительно, если хочет участвовать в стрим-pipeline.

---

## Класс модуля

Файл `src/Modules/my-module/MyModule.php`.

Расширяйте `BaseModule` — он предоставляет пустые реализации по умолчанию для всех
необязательных методов. Обязательны только `getName()` и `getVersion()`.

```php
<?php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;
use Router;
use CommandRegistry;

class MyModuleModule extends BaseModule {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', function (ServiceContainer $c) {
            return new MyModuleService($c->get('db'));
        });
    }

    public function getEventSubscribers(): array {
        return [
            StreamStartedEvent::class => [MyModuleHandler::class, 'onStreamStarted'],
            // С приоритетом: [callable, int]
            UserAuthenticatedEvent::class => [[MyModuleHandler::class, 'onAuth'], 20],
        ];
    }

    public function registerRoutes(Router $router): void {
        $router->get('my-module', [MyModuleController::class, 'index'], [
            'permission' => ['adv', 'my_module'],
        ]);
        $router->api('my_action', [MyModuleController::class, 'apiAction'], [
            'permission' => ['adv', 'my_module'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new MyModuleCronJob());
    }

    public function registerNavbar(): void {
        NavbarRegistry::add((new NavbarItem('management.service_setup.my_module'))
            ->parent('management.service_setup')
            ->url('my_module')
            ->label('my_module')
            ->permissions(['my_module'])
            ->order(60));
    }

    // override install()/uninstall() only if migrations or cleanup are needed
}
```

### Контракт методов

| Метод | Интерфейс | Описание |
| ------- | ----------- | ---------- |
| `getName(): string` | `ModuleInterface` | Уникальное имя (совпадает с директорией) |
| `getVersion(): string` | `ModuleInterface` | Semver-версия |
| `install(): void` | `ModuleInterface` | Вызывается при установке из Marketplace |
| `uninstall(): void` | `ModuleInterface` | Вызывается при удалении |
| `boot(ServiceContainer)` | `ServiceProviderInterface` | Регистрация сервисов в DI-контейнере |
| `getEventSubscribers(): array` | `ServiceProviderInterface` | Подписки на типизированные события PSR-14 |
| `registerRoutes(Router)` | `RouteProviderInterface` | HTTP-маршруты и API-экшены |
| `registerCommands(CommandRegistry)` | `CommandProviderInterface` | Явная регистрация CLI-команд и крон-задач |
| `registerNavbar(): void` | `NavbarProviderInterface` | Пункты меню в admin navbar |

> **Важно — версия задаётся в двух местах.** Модуль объявляет свою версию **дважды**:
> поле `"version"` в `module.json` и возвращаемое значение `getVersion()` в классе
> модуля. **Держите их одинаковыми и повышайте обе перед публикацией.** В рантайме
> приоритет у версии из манифеста — установка/обновление и watermark
> `installed_version` сначала читают `module.json` и лишь потом откатываются к
> `getVersion()`, поэтому устаревший `getVersion()` тихо рассинхронизируется и
> становится частой причиной багов «не та миграция выполнилась / не выполнилась».
> Если модуль поставляет файловую схему, `database.sql` (мастер) и старшая дельта
> `migrations/<semver>.sql` тоже должны совпадать с этой версией.

---

## PHP-пространства имён

Каждый модуль живёт в своём PHP-пространстве имён: `XcVm\Module\{Pascal}`, где `{Pascal}` —
PascalCase-вариант имени директории модуля.

```
src/Modules/my-module/   →  namespace XcVm\Module\MyModule;
src/Modules/watch/       →  namespace XcVm\Module\Watch;
```

Главный файл модуля обязан объявлять это пространство имён и расширять `BaseModule`:

```php
<?php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;

class MyModuleModule extends BaseModule {
    // ...
}
```

Все вспомогательные классы в том же модуле разделяют одно пространство имён:

```php
<?php
namespace XcVm\Module\MyModule;

class MyModuleService { /* ... */ }
class MyModuleController { /* ... */ }
class MyModuleCronJob { /* ... */ }
```

Для каждого используемого класса ядра добавляйте `use`:

```php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;
use NavbarRegistry;
use NavbarItem;
```

**Правила:**

- Имя файла главного класса: `<PascalName>Module.php` — обязательно (соглашение ModuleLoader)
- Имена остальных файлов: `<PascalName><Purpose>.php`
- Добавляйте `use` для каждого класса ядра, на который есть ссылка
- Никогда не импортируйте классы из других модулей — общайтесь через события или DI-контейнер

---

## DI-контейнер и декорирование сервисов

### Регистрация сервисов

```php
public function boot(ServiceContainer $container): void {
    // Ленивая фабрика (singleton)
    $container->set('my-module.service', function (ServiceContainer $c) {
        return new MyService($c->get('db'), $c->get('settings'));
    });

    // Фабричный сервис (новый экземпляр при каждом get)
    $container->factory('my-module.request', function (ServiceContainer $c) {
        return new MyRequest($_GET, $_POST);
    });
}
```

### Декорирование чужих сервисов

Модуль может обернуть любой незащищённый сервис декоратором без правки его кода:

```php
public function boot(ServiceContainer $container): void {
    $container->decorate(
        'stream.service',
        MyLoggingDecorator::class,  // class-string: new Decorator($inner)
        priority: 20
    );

    // Или callable-форма
    $container->decorate('stream.service', function ($inner, ServiceContainer $c) {
        return new MyLoggingDecorator($inner, $c->get('logger'));
    }, priority: 20);
}
```

**Защищённые сервисы** — декорировать нельзя: `db`, `settings`, `config`, `auth`.

Попытка задекорировать защищённый сервис выбросит `RuntimeException`.

**Порядок применения декораторов:** наибольший `priority` = самый внешний слой (вызывается первым).

---

## PSR-14 События

Система событий — типизированные классы, а не строки.

### Подписка на события

В `getEventSubscribers()` возвращайте карту `EventClass::class → callable`:

```php
public function getEventSubscribers(): array {
    return [
        // Простой callable
        StreamStartedEvent::class  => [MyHandler::class, 'onStreamStarted'],
        StreamStoppedEvent::class  => [MyHandler::class, 'onStreamStopped'],

        // С приоритетом: [callable, int] — больше приоритет = вызывается раньше
        UserAuthenticatedEvent::class => [
            [MyHandler::class, 'onAuth'],
            50
        ],

        // Замыкание
        SettingsChangedEvent::class => function (SettingsChangedEvent $e): void {
            if (in_array('my_setting', $e->changedKeys())) {
                MyCache::flush();
            }
        },
    ];
}
```

### Диспетчеризация событий из модуля

```php
use EventDispatcher;

EventDispatcher::dispatch(new PackageInstalledEvent(
    slug:        'my-module',
    version:     '1.0.0',
    path:        '/path/to/module',
    installedAt: time(),
));
```

### Прерываемые события (StoppableEventInterface)

Если слушатель вызвал `$event->stopPropagation()`, остальные слушатели **не вызываются**.

```php
EventDispatcher::listen(StreamStartingEvent::class, function (StreamStartingEvent $e): void {
    if ($this->isBlocked($e)) {
        $e->abort('blocked by my-module');  // специфичен для StreamStartingEvent
        $e->stopPropagation();
    }
});
```

### Встроенные события ядра

| Класс события | Когда диспетчеризуется | Прерываемое |
| --------------- | ---------------------- | :-----------: |
| `ModuleLoadedEvent` | После успешной загрузки файла модуля | ❌ |
| `ModuleBootedEvent` | После вызова `boot()` у модуля | ❌ |
| `PackageInstalledEvent` | После установки через Marketplace | ❌ |
| `UserAuthenticatedEvent` | Успешная аутентификация | ❌ |
| `UserLoggedOutEvent` | Выход пользователя | ❌ |
| `StreamStartingEvent` | Перед запуском стрима | ✅ |
| `StreamStartedEvent` | Стрим успешно запущен | ❌ |
| `StreamStoppedEvent` | Стрим остановлен | ❌ |
| `SettingsChangedEvent` | Изменение настроек панели | ❌ |

Все типизированные события находятся в `src/Core/Events/`.

---

## Stream Middleware (опционально)

Если модуль хочет участвовать в обработке стрим-запросов, он реализует `StreamMiddlewareProviderInterface` (не входит в `ModuleInterface`):

```php
class MyModule implements ModuleInterface, StreamMiddlewareProviderInterface {

    // ... обязательные методы ModuleInterface ...

    public function getStreamMiddleware(): array {
        return [
            new MyAuthMiddleware(),
            new MyTheftDetectionMiddleware(),
        ];
    }
}
```

### Реализация middleware

```php
class MyTheftDetectionMiddleware implements StreamMiddlewareInterface {

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        if ($this->isTheft($ctx)) {
            $ctx->abort('theft detected', 403);
            return $ctx;  // pipeline останавливается
        }

        // Сохранить данные в context
        $ctx->set('my-module.fingerprint', $this->getFingerprint($ctx));

        return $next($ctx);  // передать управление следующему
    }

    public function getPriority(): int {
        return 60;  // core: 80-100, modules: 0-79, terminal: -1
    }
}
```

### Приоритеты в pipeline

| Диапазон | Кому принадлежит |
| ---------- | ----------------- |
| `80–100` | Ядро (Auth, Permission, ConnectionLimit) |
| `0–79` | Модули |
| `-1` | Terminal middleware (финальное выполнение стрима) |

### StreamContext

```php
// Прочитать параметры запроса
$streamId = $ctx->get('stream_id');
$userId   = $ctx->get('user_id');

// Записать произвольный атрибут (передаётся по цепочке middleware)
$ctx->set('my-module.checked', true);

// Прервать выполнение
$ctx->abort('reason', 403);
if ($ctx->isAborted()) {
    return $ctx;
}
```

---

## Navbar

### Добавление пунктов меню

Метод `registerNavbar()` вызывается один раз при boot. Используйте `NavbarRegistry::add()`:

```php
public function registerNavbar(): void {
    // Пункт в Service Setup
    NavbarRegistry::add((new NavbarItem('management.service_setup.my_module'))
        ->parent('management.service_setup')
        ->url('my_module')
        ->label('my_module')
        ->permissions(['my_module'])
        ->order(60));

    // Пункт в Logs (megamenu)
    NavbarRegistry::add((new NavbarItem('management.logs.my_module_log'))
        ->parent('management.logs')
        ->url('my_module_logs')
        ->label('', 'My Module Logs')
        ->permissions(['my_module'])
        ->order(170));
}
```

### Зарезервированные слоты для модулей

| Родительский узел | Слоты для модулей |
| ------------------- | ------------------ |
| `management.service_setup` | `order` ≥ 60 |
| `management.logs` | `order` ≥ 170 |
| Прочие секции | Не зарезервировано, уточняйте с core |

### Правила

1. `key` — уникальный, стабильный, формат `section.group.item`
2. `parent` — должен ссылаться на существующий узел core-дерева
3. `order` — позиция внутри одного parent (меньше = выше в списке)
4. `label('key')` — переводимый текст, `label('', 'Literal')` — фиксированный
5. `permissions(['perm'])` — видимость по разрешению (OR-логика)
6. Если нет пунктов меню — оставьте `registerNavbar()` пустым

---

## Отключение и включение модулей

Добавьте в `src/config/modules.php`:

```php
return [
    'my-module' => ['state' => 'disabled'],   // предпочтительно
    // или legacy-форма (обратная совместимость):
    'my-module' => ['enabled' => false],
];
```

Допустимые значения `state` (enum `ModuleState`):

| Значение | Смысл |
| -------- | ----- |
| `enabled` | Модуль загружается и стартует (по умолчанию) |
| `disabled` | Обнаружен, но пропускается |
| `installing` | Переходное состояние при установке |
| `failed` | Установка завершилась ошибкой; пропускается (не загружается) |

Файл содержит только overrides. Если пустой или отсутствует — все найденные модули загружаются.

> **Диагностика в панели.** На странице **Modules** рядом со статусом модуля показывается жёлтый бейдж **⚠ Dependency issue**, если у модуля есть обязательная зависимость, которая отсутствует или не включена (например, `plex` числится `Enabled`, но `watch` в состоянии `failed`). В подсказке бейджа перечислены конкретные проблемы. Это поле (`dependency_warnings`) вычисляет `ModuleManager::listModules()`.

Можно также переопределить класс модуля:

```php
return [
    'my-module' => ['class' => 'XcVm\\Module\\MyModuleCustom\\MyModuleCustomModule'],
];
```

---

## Как работает загрузка

```
ModuleLoader::loadAll()
    │
    ├── glob('Modules/*/module.json')
    ├── читает overrides из config/modules.php
    ├── фильтрует по environment (main/lb/any)
    ├── readManifest() → normalizes: dependencies, optional_dependencies, priority
    │
    ├── resolveLoadOrder()  — топологическая сортировка DFS
    │   ├── pruneUnsatisfiableModules() → модули с недоступной обязательной
    │   │     зависимостью отбрасываются (каскадно, с предупреждением в лог)
    │   ├── optional deps → пропускается если отсутствует
    │   └── при равной позиции: sort по priority desc, затем alphabetically
    │
    └── для каждого модуля в порядке:
        ├── registerModuleAutoloader($path)
        ├── resolveClassName('my-module') → 'MyModule'
        └── new MyModule()

ModuleLoader::bootAll($container, $router, $pipeline)
    ├── (new CoreNavbarProvider())->registerNavbar()   ← core navbar первым
    │
    └── для каждого модуля:
        ├── instanceof ServiceProviderInterface → boot($container)
        │                                       → registerEventSubscribers()
        ├── instanceof StreamMiddlewareProviderInterface → pipeline->pipe(middleware)
        ├── instanceof RouteProviderInterface → registerRoutes($router)
        └── instanceof NavbarProviderInterface → registerNavbar()
```

Соглашение по имени класса: `my-module` → FQN `XcVm\Module\MyModule\MyModuleModule`
(kebab-case → PascalCase; можно переопределить через ключ `class` в конфиге).

Переопределить класс можно через `config/modules.php`:

```php
return [
    'my-module' => ['class' => 'MyModuleV2'],
];
```

---

## Marketplace: установка через C-расширение

Модули из платформы устанавливаются через `ModuleManager::downloadFromPlatform()`:

```php
$manager->downloadFromPlatform(slug: 'my-module', version: '1.2.0', apiKey: $key);
```

Под капотом:

1. `XC_VM::module_install($slug, $version, $apiKey)` — C-расширение скачивает, дешифрует и распаковывает модуль
2. `installModule($slug)` — запускает `install()` у модуля
3. `EventDispatcher::dispatch(new PackageInstalledEvent(...))` — диспетчеризует событие
4. `hotReload($slug, $path)` — загружает и бутит модуль в текущем запросе **без рестарта PHP-FPM**

---

## Изолированные подсистемы (BoundaryInterface)

Если модуль является изолированной подсистемой с собственным bootstrap (как Ministra), он реализует `BoundaryInterface`:

```php
class MyModule extends BaseModule implements BoundaryInterface {

    public function getName(): string { return 'my-module'; }
    public function getVersion(): string { return '1.0.0'; }

    public function getEntryPoint(): string {
        return 'my-module/portal.php';
    }

    public function isIsolated(): bool {
        return true;
    }
}
```

`BoundaryInterface` — маркер изоляции. `isIsolated() = true` означает, что подсистема запускается через собственный entry point с отдельным bootstrap.

---

## Контроллер (опционально)

```php
class MyController {

    private string $viewsPath;

    public function __construct() {
        $this->viewsPath = __DIR__ . '/views';
        require_once MAIN_HOME . 'Public/Views/layouts/admin.php';
        require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
    }

    public function index(): void {
        $_TITLE = 'My Module';
		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/my_page.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/my_page_scripts.php';
    }

    public function apiAction(): void {
        echo json_encode(['result' => true]);
        exit;
    }
}
```

| Правило | |
| --------- | -- |
| `__DIR__ . '/views'` | viewsPath — контроллер внутри директории модуля |
| GET-страницы | `renderUnifiedLayoutHeader` до view, `renderUnifiedLayoutFooter` после |
| API-экшены | Без layout — JSON напрямую |

---

## Крон-задача (опционально)

```php
// src/Modules/my-module/MyCronJob.php

class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string    { return 'cron:my_task'; }
    public function getDescription(): string { return 'My module background task'; }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) { return 1; }

        require INCLUDES_PATH . 'admin.php';
        require_once __DIR__ . '/MyCron.php';

        $this->initCron('XC_VM[MyTask]');
        MyCron::run();

        return 0;
    }
}
```

Регистрация в модуле:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

Объявить расписание через `getCronEntries()` в классе модуля:

```php
public function getCronEntries(): array {
    return [
        '*/5 * * * *' => 'cron:my_task',
    ];
}
```

`ModuleLoader::collectCronEntries()` агрегирует записи всех модулей, `StartupCommand` /
`StatusCommand` автоматически записывают их в системный crontab — изменять файлы ядра не нужно.

**Формат:** ключ = cron-выражение, значение = имя команды из `registerCommands()`.

---

## PSR-11: ContainerInterface

`ServiceContainer` реализует `ContainerInterface`:

```php
public function get(string $id): mixed;  // throws NotFoundException если не найден
public function has(string $id): bool;
```

`NotFoundException` реализует `NotFoundExceptionInterface` → `ContainerExceptionInterface`.

Интерфейсы находятся в `src/Core/Container/Psr/`. Composer не используется — файлы включены в проект напрямую.

---

## Чеклист добавления модуля

- [ ] `mkdir -p src/Modules/<name>/`
- [ ] Создать `module.json` (name, version, requires_core, priority, optional_dependencies)
- [ ] Создать `<Name>Module.php` (extends `BaseModule`)
- [ ] Задать версию в **обоих** местах — `"version"` в `module.json` и `getVersion()` — они должны совпадать (повышать обе перед публикацией)
- [ ] `boot()` — зарегистрировать сервисы через `$container->set()`
- [ ] `getEventSubscribers()` — подписки на типизированные события
- [ ] `registerRoutes()` — маршруты (или пустой метод)
- [ ] `registerNavbar()` — пункты меню (или пустой метод)
- [ ] `registerCommands()` — крон-задачи (или пустой метод)
- [ ] (опц.) `implements StreamMiddlewareProviderInterface` + `getStreamMiddleware()`
- [ ] (опц.) Контроллер + views/
- [ ] (опц.) CronJob + регистрация в StartupCommand
- [ ] (если своя схема) `database.sql` (мастер), `database_drop.sql` (удаление), `migrations/<semver>.sql` (дельты)
- [ ] (если миграции с PHP-логикой) `implements MigratableInterface` + `getMigrations()`
- [ ] Проверить: `php -l src/Modules/<name>/<Name>Module.php`
- [ ] Проверить: `php console.php --list` показывает команды модуля
- [ ] Проверить: удаление директории не вызывает ошибок ядра

---

## FAQ

**Q: Как отключить модуль?**
A: `src/config/modules.php` → `'my-module' => ['state' => 'disabled']`.
Legacy-форма `'enabled' => false` тоже принимается для обратной совместимости.

**Q: Нужна ли регистрация в конфиге для загрузки?**
A: Нет. `ModuleLoader` сам находит все модули по `Modules/*/module.json`.

**Q: Мой модуль зависит от другого. Как объявить?**
A: В `module.json` через `dependencies` (обязательно) или `optional_dependencies` (мягко). Предпочитайте выносить общую логику в `Core/` вместо межмодульных зависимостей.

**Q: Как задекорировать сервис другого модуля?**
A: `$container->decorate('service.id', MyDecorator::class, priority: 10)` в своём `boot()`.

**Q: Как подписаться на событие с приоритетом?**
A: `[EventClass::class => [[MyHandler::class, 'method'], 50]]` в `getEventSubscribers()`. Можно также вызвать `EventDispatcher::listen()` напрямую.

**Q: Как модуль получает $db?**
A: `$db = $container->get('db')` в `boot()`. Прямой `global $db` — устарело.

**Q: Как модуль получает настройки?**
A: `$settings = $container->get('settings')` или `SettingsManager::getAll()['key']`.

**Q: Почему мой middleware не вызывается?**
A: Проверьте, что модуль реализует `StreamMiddlewareProviderInterface` (не `ModuleInterface` — это разные контракты). `bootAll()` должен быть вызван с `$pipeline` аргументом.

**Q: Мой модуль MAIN-only — что делать?**
A: Ничего. Все модули MAIN-only по умолчанию — `modules/` не входит в `LB_DIRS`. Для LB используйте `"environment": "lb"` или `"any"`.

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `src/Core/Module/ModuleLoader.php` | Обнаружение, сортировка и загрузка модулей; PSR-4-резолвер |
| `src/config/modules.php` | Конфиг включения / переопределения класса модуля |
| `src/Modules/` | Каталоги модулей |
| `src/Core/Module/Contract/` | Под-интерфейсы модулей |
