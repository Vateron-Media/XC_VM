# Модульная система

## Обзор

Модуль - это изолированный каталог под `src/Modules/` с известным контрактом. Система
построен на принципах **Расширяемой платформы**:

- Ядро (`Core/`) ничего не знает о модулях
- Модули могут зависеть от `Core/` и `Domain/`, но никогда друг от друга (кроме как через объявленные зависимости).
- Любой модуль можно отключить из `config/modules.php`, не прикасаясь к ядру
- Удаление каталога модуля не приводит к фатальным ошибкам

---

## Структура каталогов модулей

The directory name follows the **`{name}_{hash5}`** convention, where `hash5` is the
первые 5 символов модуля `hash_id`. Логическое имя модуля (`module.json`
`name`, который никогда не содержит `_`) всегда разрешается из манифеста — никогда из
базовое имя каталога. Это позволяет двум модулям с **одинаковым именем** работать в разных
каталоги (`watch_2541a`, `watch_9f1c0`) и установите их без столкновения с файловой системой. То
конфигурация, график зависимостей и пространство имен - все это не соответствует каноническому `name`, поэтому каталог
переименование не требует переноса данных. Каждый модуль **должен ** иметь `hash_id`: загружаемые файлы, которые отправляются
без такового получите новый идентификатор, сгенерированный и записанный в их `module.json` перед размещением,
таким образом, каталог без хэша никогда не создается. Устаревший пустой каталог `Modules/{name}/` из
более старое развертывание по-прежнему считывается, но оно ** автоматически переносится** в `{name}_{hash5}` (генерируя
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

Модуль владеет своей схемой через **три роли, которые отражают ядро** (`bin/install/database.sql`
+ `migrations/`):

|Файл|Роль|Работает на|
| ---- | ---- | ------- |
| `database.sql` | **One** master schema — the full current `CREATE`/seed |новая **установка**|
| `database_drop.sql` |**Одно** удаление — `DROP TABLE` для каждой таблицы, которой владеет модуль|**удалить**|
| `migrations/<semver>.sql` |**Папка** прямых переходов между версиями|**обновить** для версий в `(installed, current]`|

Правила:

- **Новая установка выполняется только `database.sql`**, поэтому она всегда должна соответствовать последней версии.
схема (каждая дельта загнута внутрь). Записанный `installed_version` является водяным знаком —
ошибки никогда не воспроизводятся при новой установке.
- **Дельты направлены только вперед** (`ALTER`/`INSERT`), названный `<semver>.sql` — демонтаж - это
одинарный `database_drop.sql`, поэтому нет файлов для каждой версии `.down`.
- Сохраняйте дельты ** идемпотентными** (`ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`), чтобы повторные запуски были безопасными.
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
> сгенерированный ** один раз ** и ** никогда** впоследствии не изменявшийся — он должен пережить изменения версий
> и переименовывает (чтобы оно было случайным, а не производным от `name`/`version`). Сгенерируйте его с помощью
> `php -r 'echo bin2hex(random_bytes(16));'` и вставьте его в `module.json`, когда
> создайте новый модуль. Не создавайте его вручную и не используйте повторно другой модуль. Это придает модулям стабильную идентичность, независимую от `name`,
> который является основой для перемещения модулей в отдельные репозитории и для создания
> явный источник обновления для каждого модуля ** - блок манифеста `update` (смотрите ниже).

**Жесткие и мягкие зависимости:**

- `dependencies` — если какой—либо модуль недоступен (отсутствует на диске, отключен или находится в состоянии `failed`), зависимый модуль ** пропускается** с каскадным предупреждением в журнале (все, что зависит от него, также пропускается). Остальные модули, панель администратора и интерфейс командной строки продолжают работать; единственная неудовлетворенная зависимость больше не прерывает всю загрузку.
- `optional_dependencies` — загружается перед этим модулем, если присутствует, автоматически пропускается, если отсутствует

> **Защита от смещения.** Модуль, от которого зависят все еще включенные модули, не может быть `disabled` передан через панель / `ModuleManager::setState()` - операция отклоняется вместе со списком зависимых объектов (зеркальное отображение защиты `uninstallModule()`). Это предотвращает переход в состояние "`plex` включено, но его зависимость `watch` отключена".

**Приоритет:**

- Сначала при топологической сортировке учитывается график зависимостей, затем в пределах той же группы выполняется сортировка по убыванию `priority` (большее число = загружено ранее), затем по алфавиту

**Источник обновления (блок `update`, необязательный):**

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
- `repository` — удаленный git (для `git`); `slug` — модуль хранилища (для `platform`, по умолчанию используется `name`); `url` — URL версии/архива (для `url`); `channel` — `stable`/`beta` (по умолчанию `stable`).

Блок нормализуется по `ModuleLoader` и выставляется через `ModuleManager::listModules()`. Еженедельный cron (`cron:module_updates`) проверяет источники `git`/`url` и записывает данные `available_version`, что приводит к нажатию кнопки обновления ** на X** (отображается только при наличии более новой версии). Нажатие кнопки обновления запускает `ModuleManager::updateModuleFromSource()`:

- `bundled` — файлы поступают вместе с панелью; Обновление просто запускает отложенные миграции.
- `platform` — делегировано потоку установки/обновления в магазине (откат + разветвление LB внутри).
- `git` — загружает ресурс выпуска **`module.tar.gz`** по тегу == новая версия (md5-проверяется с помощью выпуска `hashes.md5`, если присутствует).
- `url` — перечитывает `version.json` для его `download` (https) + необязательно `md5`.

Для `git`/`url` выбранное значение `module.json` **`hash_id` должно совпадать с установленным значением ** (идентификация — репозиторий /URL-адрес не может выдавать себя за другой модуль), затем: резервное копирование → замена файлов → миграция → ** откат при любом сбое ** → распространение до фунта стерлингов.

**Стандартный набор и подготовка.** Модули, которые панель устанавливает по умолчанию, перечислены в `config/bundled_modules.php`, а их ключ - в `hash_id` (неизменен при переименовании). На сегодняшний день все модули имеют `bundled` (их файлы находятся в архиве панели). Когда модуль извлекается в свой собственный репозиторий, измените его запись на `git`/`url`/`platform` source — `syncBundledModules()`, затем автоматически извлекает и устанавливает его с помощью `provisionStandardSet()` (это не требуется, пока все находится в комплекте на диске). `ModuleManager::findModuleByHashId()` определяет модуль по его стабильному идентификатору независимо от каталога/имени.

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

`StreamMiddlewareProviderInterface` является **необязательным** — он не является частью `ModuleInterface`.
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

    public function registerNavbar(): void {
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

> ** Совет:** модулю без маршрутов, элементов навигационной панели и команд CLI требуется только
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
| `registerNavbar()` | `NavbarProviderInterface` |Регистрация элементов навигационной панели|
| `install(): void` | `ModuleInterface` |Запуск при установке модуля (миграции, начальный запуск)|
| `uninstall(): void` | `ModuleInterface` |Запуск при удалении модуля (очистка)|

> **Важно — версия может храниться в двух местах.** Модуль объявляет свою версию
> **дважды**: поле `"version"` в `module.json` и возвращаемое значение
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

**Правила:**

- Имя файла основного класса модуля: `<PascalName>Module.php` — обязательно (соглашение с загрузчиком модулей)
- All other class filenames: `<PascalName><Purpose>.php`
- Добавьте `use ClassName;` для каждого базового класса, на который ссылается ссылка (базовый модуль, ServiceContainer, маршрутизатор и т.д.)
- Никогда не импортируйте классы из других модулей — общайтесь через события или контейнер DI

---

## Оформление контейнеров и сервизов DI

Сервисы регистрируются в `boot()` через `ServiceContainer`. Контейнер поддерживает:

- **`set(id, factory)`** — отложенный синглтон с помощью вызываемого или прямого значения
- **`factory(id, callable)`** — новый экземпляр для каждого `get()`
- **`decorate(id, callable, priority)`** — завершение существующей службы

```php
// Decorate a service (adds behaviour around the original)
$container->decorate('stream.encoder', function (mixed $inner, ServiceContainer $c): MyEncoder {
    return new MyEncoder($inner, $c->get('settings'));
}, priority: 20);
```

Декораторы объединены в цепочки по приоритету (самый высокий и самый внешний). Защищенные сервисы
(`db`, `settings`, `config`, `auth`) не удается оформить — любая попытка приводит к результату `RuntimeException`.

### Соответствие требованиям стандарта PSR-11

`ServiceContainer` реализует `ContainerInterface`:

```php
public function get(string $id): mixed;  // throws NotFoundException if missing
public function has(string $id): bool;
```

`NotFoundException` реализует `NotFoundExceptionInterface extends ContainerExceptionInterface`.

---

## События PSR-14

События - это простые классы PHP. Отправляйте их через `EventDispatcher`:

```php
// In any module
EventDispatcher::dispatch(new MyEvent($payload));

// Subscribe
EventDispatcher::listen(MyEvent::class, function (MyEvent $e): void {
    // handle
}, priority: 10);
```

**Приоритет** — более высокое целое число = вызывается первым. По умолчанию `0`.

**Останавливаемые события** — продлить `AbstractEvent` и вызвать `$e->stopPropagation()`:

```php
class MyGatingEvent extends AbstractEvent {
    public bool $allowed = true;
}

EventDispatcher::listen(MyGatingEvent::class, function (MyGatingEvent $e): void {
    if (!$this->check()) {
        $e->allowed = false;
        $e->stopPropagation();
    }
}, priority: 100);
```

### Встроенные основные события

|Класс события|Когда отправлено|Останавливаемый|
| --------------- | ---------------------- | :-----------: |
| `ModuleLoadedEvent` |После загрузки файла модуля|❌|
| `ModuleBootedEvent` |После вызова `boot()`|❌|
| `PackageInstalledEvent` |После установки marketplace|❌|
| `UserAuthenticatedEvent` |После успешного входа в систему|✅|
| `UserLoggedOutEvent` |После выхода из системы|❌|
| `StreamStartingEvent` |Перед началом трансляции|✅|
| `StreamStartedEvent` |После начала трансляции|❌|
| `StreamStoppedEvent` |После того, как поток прекратился|❌|
| `SettingsChangedEvent` |После сохранения настроек|❌|

---

## Потоковое промежуточное программное обеспечение

Модули могут внедрять промежуточное программное обеспечение в потоковый конвейер, реализуя
`StreamMiddlewareProviderInterface` (отдельно от `ModuleInterface`):

```php
class MyStreamMiddleware implements StreamMiddlewareInterface {

    public function getPriority(): int {
        return 50;
    }

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        // before — read or set attributes
        $ctx->set('my.key', 'value');
        $ctx = $next($ctx);
        // after
        return $ctx;
    }
}
```

`StreamContext` - это набор атрибутов (`get`, `set`, `has`, `abort`, `isAborted`). `StreamPipeline`
выполняет промежуточное программное обеспечение, отсортированное по убыванию `getPriority()`.

### Приоритеты трубопровода

|Диапазон|Владелец|
| ---------- | ----------------- |
| `80–100` |Ядро (авторизация, разрешение, ограничение подключения)|
| `0–79` |Модули|

### Зарезервированные слоты на панели навигации

|Родительский узел|Гнезда для модулей|
| ------------------- | ------------------ |
| `management.service_setup` |`order` ≥ 60|
| `management.logs` |`order` ≥ 170|

---

## Включение / выключение модулей

Все обнаруженные модули загружаются по умолчанию. Используйте `src/config/modules.php` для переопределения состояния:

```php
return [
    'my-module' => ['state' => 'disabled'],  // preferred
    // or legacy boolean (still accepted):
    'my-module' => ['enabled' => false],
];
```

Доступные значения `state` (подкрепленные перечислением `ModuleState`):

|Ценность|Значение|
| ----- | ------- |
| `enabled` |Загрузка модуля (по умолчанию)|
| `disabled` |Модуль обнаружен, но пропущен|
| `installing` |Переходное состояние, заданное значением `ModuleManager` во время установки|
| `failed` |Ошибка установки; модуль пропущен (не загружен)|

> **Диагностика панели.** На странице "Модули" отображается желтый значок "Проблема с зависимостями" рядом со статусом модуля, когда требуемая зависимость отсутствует или не включена (например, `plex` означает `Enabled`, а `watch` - `failed`). Во всплывающей подсказке к значку перечислены конкретные проблемы. Это поле `dependency_warnings` вычисляется с помощью `ModuleManager::listModules()`.

Чтобы переопределить класс, разрешенный для модуля:

```php
return [
    'my-module' => ['class' => 'XcVm\\Module\\MyModuleV2\\MyModuleV2Module'],
];
```

`config/modules.php` содержит только переопределения. Пустой или отсутствующий файл означает, что все обнаруженные
загружаются модули.

---

## Как работает загрузка

`ModuleLoader` выполняет эти действия при каждом запросе:

1. Сканирование `src/Modules/*/module.json`
2. Применяет переопределения из `config/modules.php`
3. Фильтры по окружающей среде (`main` / `lb` / `any`)
4. Определяет порядок загрузки:
   - `pruneUnsatisfiableModules()` удаляет модули, требуемые зависимости которых недоступны (каскадно, с зарегистрированным предупреждением), чтобы загрузка никогда не прерывалась
   - Топологическая сортировка (DFS) по графу зависимостей
   - В пределах одной и той же группы зависимостей выполните сортировку по убыванию `priority`, затем по алфавиту
   - Выдает `RuntimeException` для циклов (циклические зависимости остаются фатальными)
   - Отсутствующие необязательные зависимости автоматически пропускаются
5. Resolves class name: `my-module` → FQN `XcVm\Module\MyModule\MyModuleModule`
(kebab-case → PascalCase; может быть переопределен с помощью клавиши `class` в конфигурации)
6. Регистрирует автозагрузчик модуля PSR-4 (сопоставляет `XcVm\Module\<Name>` с каталогом модуля)
7. Создает экземпляр класса module

В веб-контексте:

- `bootAll($container, $router)` → вызовы `boot()`, `registerRoutes()`, `registerNavbar()`,
и подписывается на события для каждого загруженного модуля

В контексте командной строки:

- `registerAllCommands($registry)` → вызывает `registerCommands()` для каждого загруженного модуля

---

## Marketplace: установка через расширение C

Модули с платформы устанавливаются через `ModuleManager::downloadFromPlatform()`:

```php
$manager->downloadFromPlatform(slug: 'my-module', version: '1.2.0', apiKey: $key);
```

Под капотом:

1. `XC_VM::module_install($slug, $version, $apiKey)` — Расширение C загружает, расшифровывает и распаковывает файлы
2. `installModule($slug)` — запускает `install()` в модуле
3. `EventDispatcher::dispatch(new PackageInstalledEvent(...))` — отправляет событие
4. `hotReload($slug, $path)` — загружает модуль в текущем запросе **без перезапуска PHP-FPM.**

---

## Изолированные подсистемы

Модуль может быть полностью изолированной подсистемой со своей собственной точкой входа и начальной загрузкой
(например, Ministra). Это ** соглашение**, а не маркерный интерфейс — он остается
обычный модуль `ModuleInterface`/`BaseModule`:

```php
class MyModule extends BaseModule {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }
}
```

Изоляция означает, что подсистема работает через свою собственную общедоступную точку входа (например,
`my-module/portal.php`, путь относительно `src/`, который обрабатывает свой собственный bootstrap)
с отдельным путем начальной загрузки. Он использует общую инфраструктуру (базу данных, кэш, конфигурацию), но
**не** участвует в основных `Router`, `ModuleLoader::bootAll()` или
`NavbarRegistry`. Реализации `boot()` и `registerRoutes()` обычно являются
оставлено как унаследованное бездействие.

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

## Задача Cron

**Cron logic** (`MyCron.php`) — только бизнес-логика, без подключения к CLI.

**Оболочка CronJob** (`MyCronJob.php`) — реализует `CommandInterface`, использует `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string { return 'cron:my_task'; }
    public function getDescription(): string { return 'Cron: my task'; }

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

Регистрация в модуле:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

Объявите запись crontab, переопределив `getCronEntries()` в классе module:

```php
public function getCronEntries(): array {
    return [
        '*/5 * * * *' => 'cron:my_task',
    ];
}
```

`ModuleLoader::collectCronEntries()` объединяет записи всех модулей и `StartupCommand` /
`StatusCommand` автоматически записывайте их в системный crontab — никаких изменений в основных файлах не требуется.

**Формат:** ключ = выражение cron, значение = имя консольной команды, зарегистрированное через `registerCommands()`.

---

## Версионные миграции (MigratableInterface)

> **Два механизма, оба аддитивные.** Схема на основе файлов**, описанная в разделе
> [Структура каталогов модулей](#module-directory-structure) (`database.sql` мастер +
> `database_drop.sql` разборка + `migrations/<semver>.sql` дельты) используется по умолчанию для
> простой DDL/seed. `MigratableInterface` ниже приведен программный путь для обновления.
> шаги, требующие логики PHP (повторное заполнение данных, условные изменения). Модуль может использовать
> один из них или оба; `ModuleManager::updateModule()` сначала запускает файл delta, затем
> вызываемые миграции.

Модули, для обновления которых требуется PHP логическая реализация `MigratableInterface`:

```php
namespace XcVm\Module\MyModule;

use BaseModule;
use MigratableInterface;
use ServiceContainer;

class MyModuleModule extends BaseModule implements MigratableInterface {

    public function getMigrations(): array {
        return [
            '1.1.0' => function (): void {
                // runs when upgrading from any version < 1.1.0 to >= 1.1.0
                global $db;
                $db->query("ALTER TABLE xc_my_table ADD COLUMN new_col INT DEFAULT 0");
            },
            '1.2.0' => function (): void {
                // runs when upgrading from < 1.2.0 to >= 1.2.0
            },
        ];
    }
}
```

`ModuleManager::updateModule()` считывает `installed_version` из хранилища переопределений, фильтрует
сопоставляет только записи `> fromVersion && <= toVersion`, сортирует по полу и запускает каждую из них.
вызываемый в своей собственной транзакции базы данных. `installModule()` записи `installed_version` после
успешная установка; `uninstallModule()` удаляет ее.

**Основные правила:**

- Ключи - это полустрочные строки (`'1.1.0'`, `'2.0.0'`) — `version_compare` используется упорядочение
- Каждая миграция выполняется в рамках своей собственной транзакции — сбой откатывает только этот шаг
- `BaseModule` предоставляет значение по умолчанию `getMigrations(): array { return []; }`, поэтому реализация
`MigratableInterface` является необязательным

---

## Composer обнаружение пакета

Модули могут распространяться в виде Composer пакетов с `"type": "xcvm-module"`:

```json
{
    "name": "vendor/my-xcvm-module",
    "type": "xcvm-module",
    "extra": {
        "xcvm": {
            "module-path": "src"
        }
    }
}
```

`ModuleLoader` автоматически сканирует `vendor/composer/installed.json` (Composer 1 и 2
форматирует) и обнаруживает все установленные пакеты `xcvm-module` вместе со встроенным
каталог `src/Modules/`. Пакеты дедуплицируются — модуль как в `modules/`, так и в
`vendor/` загружается только один раз.

---

## Контрольный список модулей

- [ ] Создать `src/Modules/<name>/`
- [ ] Добавить `namespace XcVm\Module\<PascalName>;` к каждому файлу класса
- [ ] Создать `module.json` с помощью `name`, `version`, `requires_core`, `priority`, `dependencies`, `optional_dependencies`
- [ ] Поставьте постоянную отметку `hash_id` (`php -r 'echo bin2hex(random_bytes(16));'`; никогда не пишите ее от руки)
- [ ] Create `<PascalName>Module.php` extending `BaseModule`
- [ ] Укажите версию в ** как ** `module.json` `"version"`, так и `getVersion()` — они должны совпадать (измените обе версии перед публикацией)
- [ ] Реализовать `boot()` для всех сервисов, предоставляемых модулем
- [ ] Реализовать `registerRoutes()` для конечных точек HTTP/API
- [ ] Внедрить `registerNavbar()` для элементов панели администратора (или оставить пустым)
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

**Вопрос: Как мне отключить модуль?**
В поле `src/config/modules.php` добавьте `'module-name' => ['state' => 'disabled']`.
Устаревшая форма `'enabled' => false` также принята для обеспечения обратной совместимости.

** Вопрос: Как мне объявить, что мой модуль зависит от другого?**
Используйте `dependencies` в `module.json` для жестких удалений (должно присутствовать) или `optional_dependencies`
для мягкого удаления (загружается раньше вашего, если присутствует, и автоматически пропускается, если отсутствует).

** Вопрос: Могу ли я украсить основную услугу?**
Да — используйте `$container->decorate('service-id', callable, priority)` в `boot()`.
Защищенные сервисы (`db`, `settings`, `config`, `auth`) не могут быть оформлены.

**Вопрос: Как мне прослушивать основные события?**
Вызовите `EventDispatcher::listen(EventClass::class, callable, priority)` в любом месте после начальной загрузки,
обычно внутри `boot()` или выделенного класса подписчиков.

**Вопрос: Могу ли я отправлять пользовательские события из модуля?**
Да. Создайте простой класс или расширьте `AbstractEvent` и вызовите `EventDispatcher::dispatch(new MyEvent(...))`.

**Вопрос: Для чего используется `StreamMiddlewareProviderInterface`?**
Это позволяет модулю вводить значение `StreamMiddlewareInterface` в конвейер потоковой обработки
без изменения `StreamProcess.php`. При необходимости реализуйте его вместе с `ModuleInterface`.

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/Core/Module/ModuleLoader.php` | Discovers, sorts and boots modules; PSR-4 class resolver |
| `src/config/modules.php` |Конфигурация включения модуля / переопределения класса|
| `src/Modules/` |Каталоги модулей|
| `src/Core/Module/Contract/` |Подинтерфейсы модуля|
