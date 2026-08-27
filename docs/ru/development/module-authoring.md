# Разработка модуля

Как создать модуль XC_VM: его расположение на диске, манифест `module.json`, контракт класса модуля + метода, пространства имен и его контроллер. О том, как модуль обнаруживается/загружается/распространяется, смотрите в [Жизненный цикл модуля](module-lifecycle.md); о перехватчиках, к которым он подключается, смотрите в [Точках расширения модуля](module-extension-points.md).

## Обзор


Модуль - это изолированный каталог под `src/Modules/` с известным контрактом. Система
is built on **Extensible Platform** principles:

- Ядро (`Core/`) ничего не знает о модулях
- Модули могут зависеть от `Core/` и `Domain/`, но никогда друг от друга (кроме как через объявленные зависимости).
- Любой модуль можно отключить из `config/modules.php`, не прикасаясь к ядру
- Удаление каталога модуля не приводит к фатальным ошибкам

---

## Структура каталогов модулей


Имя каталога соответствует условию **`{name}_{hash5}`**, где `hash5` - это
первые 5 символов модуля `hash_id`. Логическое имя модуля (`module.json`
`name`, который никогда не содержит `_`) всегда разрешается из манифеста — никогда из
базовое имя каталога. Это позволяет двум модулям с **то же имя** работать в разных
каталоги (`watch_2541a`, `watch_9f1c0`) и установите их без столкновения с файловой системой. То
конфигурация, график зависимостей и пространство имен - все это не соответствует каноническому `name`, поэтому каталог
переименование не требует переноса данных. У каждого модуля **должен** есть `hash_id`: загружаемые файлы, которые отправляются
без такового получите новый идентификатор, сгенерированный и записанный в их `module.json` перед размещением,
таким образом, каталог без хэша никогда не создается. Устаревший пустой каталог `Modules/{name}/` из
более старое развертывание все еще считывается, но имеет значение от **автоматическая миграция** до `{name}_{hash5}` (генерируя
`hash_id`, если отсутствует) при следующем `console.php status` — макет без хэша удаляется, а не
держал.

```text
src/Modules/my-module_9f1c0/   # {name}_{hash5}; canonical name is "my-module"
├── module.json          # Metadata and manifest
├── MyModule.php         # Module class (source of truth)
├── MyService.php        # Business logic
├── MyController.php     # Admin pages (optional)
├── MyCron.php           # Cron logic (optional)
├── MyCronJob.php        # CLI cron wrapper (optional)
├── database.sql         # Master schema — full current CREATE/seed (optional)
├── database_drop.sql    # Teardown — DROP every table the module owns (optional)
├── migrations/          # Forward version deltas (optional)
│   └── 1.1.0.sql        # Applied only when upgrading a panel past 1.1.0
└── views/               # Page templates (optional)
    ├── my_page.php
    └── my_page_scripts.php
```

Модуль владеет своей схемой через **три роли, отражающие суть** (`bin/install/database.sql`
+ `migrations/`):

|Файл|Роль|Работает на|
| ---- | ---- | ------- |
| `database.sql` | **One** master schema — the full current `CREATE`/seed |свежий **устанавливать**|
| `database_drop.sql` |**Один** удаление — `DROP TABLE` для каждой таблицы, которой владеет модуль| **uninstall** |
| `migrations/<semver>.sql` |**Папка** прямых различий между версиями|**обновление**, для версий в `(installed, current]`|

Правила:

- **Выполняется только новая установка `database.sql`**, поэтому он всегда должен отражать последнюю версию
схема (каждая дельта загнута внутрь). Записанный `installed_version` является водяным знаком —
ошибки никогда не воспроизводятся при новой установке.
- **Дельты направлены только вперед** (`ALTER`/`INSERT`), названный `<semver>.sql` — демонтаж - это
одинарный `database_drop.sql`, поэтому нет файлов для каждой версии `.down`.
- Сохраняйте дельты **идемпотентный** (`ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`), чтобы повторные запуски были безопасными.
- Модуль без схемы не отправляет ни один из этих файлов. Модуль, работающий только с разницей (нет `database.sql`)
по-прежнему устанавливается путем повторного воспроизведения каждой дельты в ее версии.

---

## модуль.json


```json
{
    "name": "my-module",
    "hash_id": "9f1c0b7e4d2a6538c1e0a4b7d6f39e21",
    "description": "Short description",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "priority": 0,
    "dependencies": [],
    "optional_dependencies": [],
    "has_navbar": false,
    "has_settings": false
}
```

### Поля манифеста

|Поле|Тип|По умолчанию|Описание|
| ------ | ----- | :---: | ------------ |
| `name` | `string` |—|Каноническое имя модуля (в случае с kebab, без `_`). Каталог равен `{name}_{hash5}`, но код всегда использует это значение манифеста, а не базовое имя каталога.|
| `hash_id` | `string` |сгенерированный|**Постоянный** идентификатор модуля — случайный 32-разрядный шестнадцатеричный код, генерируемый ОДИН раз и никогда не изменяющийся при изменении версии или переименовании. Его первые 5 символов образуют суффикс каталога `{name}_{hash5}`. Не редактируйте вручную.|
| `description` | `string` | `""` |Удобочитаемое описание|
| `version` | `string` |—|Средняя версия (`1.0.0`)|
| `requires_core` | `string` |—|Минимальная версия ядра (`>=2.0`)|
| `environment` | `string` | `"main"` |`main`, `lb` или `any`|
| `priority` | `int` | `0` |Приоритет загрузки — более высокие нагрузки раньше|
| `dependencies` | `array` | `[]` |Жесткие зависимости; если они недоступны, зависимый объект пропускается (см. ниже)|
| `optional_dependencies` | `array` | `[]` |Мягкие зависимости (загруженные ранее, если они есть)|
| `has_navbar` | `bool` | `false` |Регистрирует ли модуль элементы навигационной панели|
| `has_settings` | `bool` | `false` |Есть ли у модуля страница настроек|

> **`hash_id` — постоянный идентификатор модуля.** Это случайное значение из 32 шестнадцатеричных чисел,
> сгенерированный **однажды** и **никогда** измененный впоследствии — он должен пережить сбои в версии
> и переименовывает (чтобы оно было случайным, а не производным от `name`/`version`). Сгенерируйте его с помощью
> `php -r 'echo bin2hex(random_bytes(16));'` и вставьте его в `module.json`, когда
> создайте новый модуль. Не создавайте его вручную и не используйте повторно другой модуль. Это придает модулям стабильную идентичность, независимую от `name`,
> который является основой для перемещения модулей в отдельные репозитории и для создания
> явный для каждого модуля **источник обновления** - блок манифеста `update` (см. ниже).

**Hard vs soft dependencies:**

- `dependencies` — если какой—либо модуль недоступен (отсутствует на диске, отключен или находится в состоянии `failed`), зависимому модулю присваивается значение **пропущенный** с записанным каскадным предупреждением (все, что зависит от него, также пропускается). Остальные модули, панель администратора и интерфейс командной строки продолжают работать; единственная неудовлетворенная зависимость больше не прерывает всю загрузку.
- `optional_dependencies` — загружается перед этим модулем, если присутствует, автоматически пропускается, если отсутствует

> **Остерегайтесь дрейфа.** Модуль, от которого зависят все еще включенные модули, не может быть запущен `disabled` через панель / `ModuleManager::setState()` - операция отклоняется со списком зависимостей (зеркально отображая защиту `uninstallModule()`). Это предотвращает переход в состояние "`plex` включено, но его зависимость от `watch` отключена".

**Priority:**

- Сначала при топологической сортировке учитывается график зависимостей, затем в пределах той же группы выполняется сортировка по убыванию `priority` (большее число = загружено ранее), затем по алфавиту

**Update source (`update` block, optional):**

Откуда модуль получает свои обновления. Отсутствует → `bundled` (файлы отправляются вместе с панелью и обновляются вместе с ней).

```json
"update": {
    "source": "bundled | platform | git | url",
    "repository": "https://github.com/Vateron-Media/xc_vm-module-watch",
    "channel": "stable",
    "slug": "watch",
    "url": "https://…/version.json"
}
```

- `source` — `bundled` (с панелью управления), `platform` (хранилище SaaS), `git` (репозитории), `url` (автономный хостинг). Неизвестные значения возвращаются к значению `bundled`.
- `repository` — git remote (для `git`); `slug` — хранилище slug (для `platform`, по умолчанию используется `name`); `url` — URL версии/архива (для `url`); `channel` — `stable`/`beta` (по умолчанию `stable`).

Блок нормализуется с помощью `ModuleLoader` и отображается с помощью `ModuleManager::listModules()`. Еженедельный cron (`cron:module_updates`) проверяет источники `git`/`url` и записывает `available_version`, что приводит к нажатию кнопки **Обновить до X** (отображается только при наличии более новой версии). Нажатие кнопки Обновить запускает `ModuleManager::updateModuleFromSource()`:

- `bundled` — файлы поступают вместе с панелью; Обновление просто запускает отложенные миграции.
- `platform` — делегировано потоку установки/обновления в магазине (откат + разветвление LB внутри).
- `git` — загружает ресурс выпуска **`module.tar.gz`** по тегу == новая версия (md5-проверяется с помощью выпуска `hashes.md5`, если присутствует).
- `url` — перечитывает `version.json` для его `download` (https) + необязательно `md5`.

Для `git`/`url` выбранного `module.json` **`hash_id` должно быть равно установленному значению** (идентификация — репозиторий /URL-адрес не может выдавать себя за другой модуль), затем: резервное копирование → замена файлов → перенос → **откат при любом сбое** → распространение в LB.

**Стандартный набор и подготовка.** Модули, которые панель устанавливает по умолчанию, перечислены в `config/bundled_modules.php`, с ключом `hash_id` (неизменны при переименовании). На сегодняшний день все модули имеют `bundled` (их файлы находятся в архиве панели). Когда модуль извлекается в свой собственный репозиторий, измените его запись на `git`/`url`/`platform` source — `syncBundledModules()`, затем автоматически извлекает и устанавливает его с помощью `provisionStandardSet()` (это не требуется, пока все находится в комплекте на диске). `ModuleManager::findModuleByHashId()` определяет модуль по его стабильному идентификатору независимо от каталога/имени.

---

## Подинтерфейсы


`ModuleInterface` разбивает площадь поверхности модуля на типизированные субдоговоры:

```text
ModuleInterface
├── ServiceProviderInterface   → boot(ServiceContainer)
├── RouteProviderInterface     → registerRoutes(Router)
├── CommandProviderInterface   → registerCommands(CommandRegistry)
└── NavbarProviderInterface    → registerNavbar()
```

`StreamMiddlewareProviderInterface` равно **необязательный** — оно не является частью `ModuleInterface`.
Реализуйте это только в том случае, если модулю необходимо внедрить себя в потоковый конвейер.

```php
// Optional — not in ModuleInterface
class MyModule implements ModuleInterface, StreamMiddlewareProviderInterface {
    public function getStreamMiddleware(): array {
        return [new MyStreamMiddleware()];
    }
}
```

---

## Класс модуля


Extend `BaseModule` — он предоставляет значения по умолчанию без операций для каждого необязательного метода, так что вы можете использовать только
переопределите то, что на самом деле использует модуль. Требуются только `getName()` и `getVersion()`.

```php
<?php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;
use Router;
use CommandRegistry;
use NavbarRegistry;
use NavbarItem;

class MyModuleModule extends BaseModule {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', function (ServiceContainer $c): MyModuleService {
            return new MyModuleService($c->get('db'));
        });
    }

    public function registerRoutes(Router $router): void {
        $router->get('my_page', [MyModuleController::class, 'index'], [
            'permission' => ['adv', 'my_module'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new MyModuleCronJob());
    }

    public function registerNavbar(NavbarRegistry $registry): void {
        NavbarRegistry::add(
            (new NavbarItem('management.service_setup.my_module'))
                ->parent('management.service_setup')
                ->url('my_page')
                ->label('my_module')
                ->permissions(['my_module'])
                ->order(60)
        );
    }
}
```

> **Совет:** модулю без маршрутов, элементов навигационной панели и команд CLI требуется только
> `getName()`, `getVersion()` и `boot()`.
> Модуль изолированной подсистемы (его собственная точка входа и bootstrap, например Ministra) обычно
> оставляет `boot()` и `registerRoutes()` унаследованными как не выполняемые операции.

### Метод контракта

|Метод|Интерфейс|Описание|
| ------- | ----------- | ---------- |
| `getName(): string` | `ModuleInterface` |Уникальное имя (соответствует каталогу)|
| `getVersion(): string` | `ModuleInterface` |Версия Semver|
| `boot(ServiceContainer)` | `ServiceProviderInterface` |Регистрация сервисов в контейнере DI|
| `registerRoutes(Router)` | `RouteProviderInterface` |Регистрация HTTP- и API-маршрутов|
| `registerCommands(CommandRegistry)` | `CommandProviderInterface` |Регистрация команд CLI и задач cron|
| `registerNavbar(NavbarRegistry $registry)` | `NavbarProviderInterface` |Регистрация элементов навигационной панели|
| `install(): void` | `ModuleInterface` |Запуск при установке модуля (миграции, начальный запуск)|
| `uninstall(): void` | `ModuleInterface` |Запуск при удалении модуля (очистка)|

> **Важно — версия хранится в двух местах.** Модуль объявляет свою версию
> **дважды**: поле `"version"` в `module.json` и возвращаемое значение из
> `getVersion()` в классе module. **Сохраняйте их идентичными и изменяйте оба перед
> издательский.** Во время выполнения манифест `version` имеет приоритет — установка/обновление
> и водяной знак `installed_version` сначала читается как `module.json`, и только потом возвращается
> to `getVersion()` — so a stale `getVersion()` silently drifts out of sync and is a
> распространенный источник ошибок типа "выполнена /не выполнена неправильная миграция". Если модуль отправляет файл
> migrations, `database.sql` (master schema) and the highest `migrations/<semver>.sql`
> дельта также должна соответствовать этой версии.

---

## PHP пространства имен


Каждый модуль находится в выделенном пространстве имен PHP: _BOS_0}, где _BOS_1} - это
преобразование имени каталога модуля в PascalCase.

```
src/Modules/my-module/   →  namespace XcVm\Module\MyModule;
src/Modules/watch/       →  namespace XcVm\Module\Watch;
```

В главном файле модуля должно быть объявлено это пространство имен и расширено `BaseModule`:

```php
<?php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;
use Router;

class MyModuleModule extends BaseModule {
    // ...
}
```

Все дополнительные классы в одном модуле используют одно и то же пространство имен:

```php
<?php
namespace XcVm\Module\MyModule;

class MyModuleService { /* ... */ }
class MyModuleController { /* ... */ }
class MyModuleCronJob { /* ... */ }
```

`use` классы, на которые вы ссылаетесь:

```php
namespace XcVm\Module\MyModule;

use BaseModule;
use ServiceContainer;
use NavbarRegistry;
use NavbarItem;

class MyModuleModule extends BaseModule {
    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', fn () => new MyModuleService());
    }
}
```

**Rules:**

- Имя файла основного класса модуля: `<PascalName>Module.php` — обязательно (соглашение с загрузчиком модулей)
- All other class filenames: `<PascalName><Purpose>.php`
- Добавьте `use ClassName;` для каждого базового класса, на который ссылается ссылка (базовый модуль, ServiceContainer, маршрутизатор и т.д.)
- Никогда не импортируйте классы из других модулей — общайтесь через события или контейнер DI

---

## Контроллер


```php
class MyController {

    protected string $viewsPath;

    public function __construct() {
        $this->viewsPath = __DIR__ . '/views';
        require_once MAIN_HOME . 'Public/Views/layouts/admin.php';
        require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
    }

    public function index(): void {
        renderUnifiedLayoutHeader('admin', ['_TITLE' => 'My Module']);
        include $this->viewsPath . '/my_page.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/my_page_scripts.php';
    }
}
```

|Правило| |
| --------- | -- |
| `__DIR__ . '/views'` |viewsPath — контроллер находится внутри каталога модуля|
|ПОЛУЧАТЬ страницы|вызовите `renderUnifiedLayoutHeader` перед просмотром, `renderUnifiedLayoutFooter` после|
|Действия API|нет макета — возвращаем JSON и выходим|

---

## Контрольный список модулей


- [ ] Создать `src/Modules/<name>/`
- [ ] Добавить `namespace XcVm\Module\<PascalName>;` к каждому файлу класса
- [ ] Создать `module.json` с помощью `name`, `version`, `requires_core`, `priority`, `dependencies`, `optional_dependencies`
- [ ] Поставьте постоянный штамп `hash_id` (`php -r 'echo bin2hex(random_bytes(16));'`; никогда не пишите его от руки)
- [ ] Create `<PascalName>Module.php` extending `BaseModule`
- [ ] Укажите версию в **оба** `module.json` `"version"` и `getVersion()` — они должны совпадать (измените обе версии перед публикацией)
- [ ] Реализовать `boot()` для всех сервисов, предоставляемых модулем
- [ ] Реализовать `registerRoutes()` для конечных точек HTTP/API
- [ ] Ввести `registerNavbar()` для элементов панели администратора (или оставить пустым)
- [ ] (Если кроны) Создайте `MyCron.php` + `MyCronJob.php`, зарегистрируйтесь в `registerCommands()`
- [ ] (Если crons) Переопределяет `getCronEntries()` в классе модуля (основной файл не изменяется)
- [ ] (Схема If) Отправляет значения `database.sql` (мастер), `database_drop.sql` (демонтаж) и `migrations/<semver>.sql` дельт
- [ ] (При переносе PHP-логики) Реализовать `MigratableInterface::getMigrations()`
- [ ] (Если страницы) Создайте контроллер, используя `renderUnifiedLayoutHeader/Footer`
- [ ] (Если потоковое промежуточное программное обеспечение) Реализовать `StreamMiddlewareProviderInterface` отдельно
- [ ] Проверить: `php -l src/Modules/<name>/<PascalName>Module.php`
- [ ] Verify: `php console.php --list` shows the module's commands
- [ ] Проверьте: удаление каталога модуля не приводит к фатальной ошибке

---

## часто задаваемые вопросы


**Q: How do I disable a module?**
В поле `src/config/modules.php` добавьте `'module-name' => ['state' => 'disabled']`.
Устаревшая форма `'enabled' => false` также принята для обеспечения обратной совместимости.

**Q: How do I declare that my module depends on another?**
Используйте `dependencies` в `module.json` для жестких удалений (должно присутствовать) или `optional_dependencies`
для мягкого удаления (загружается раньше вашего, если присутствует, и автоматически пропускается, если отсутствует).

**Q: Can I decorate a core service?**
Да — используйте `$container->decorate('service-id', callable, priority)` в `boot()`.
Защищенные сервисы (`db`, `settings`, `config`, `auth`) не могут быть оформлены.

**Q: How do I listen to core events?**
Вызовите `EventDispatcher::listen(EventClass::class, callable, priority)` в любом месте после начальной загрузки,
обычно внутри `boot()` или выделенного класса подписчиков.

**Q: Can I dispatch custom events from a module?**
Да. Создайте простой класс или расширьте `AbstractEvent` и вызовите `EventDispatcher::dispatch(new MyEvent(...))`.

**Q: What is `StreamMiddlewareProviderInterface` for?**
Это позволяет модулю вводить значение `StreamMiddlewareInterface` в конвейер потоковой обработки
без изменения `StreamProcess.php`. При необходимости реализуйте его вместе с `ModuleInterface`.

## Связанные файлы


|Файл|Роль|
| --- | --- |
| `src/Core/Module/ModuleLoader.php` | Discovers, sorts and boots modules; PSR-4 class resolver |
| `src/config/modules.php` |Конфигурация включения модуля / переопределения класса|
| `src/Modules/` |Каталоги модулей|
| `src/Core/Module/Contract/` |Подинтерфейсы модуля|
