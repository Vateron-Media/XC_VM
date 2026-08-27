# Точки расширения модуля

Основные точки расширения, к которым подключается модуль: контейнер DI, потоковое промежуточное программное обеспечение, задачи cron, миграции версий и типизированные события. Чтобы создать модуль, смотрите [Создание модуля](module-authoring.md); для загрузки/жизненного цикла смотрите [Жизненный цикл модуля](module-lifecycle.md).

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

Модули подписываются на типизированные события с помощью атрибута `getEventSubscribers()` или `#[ListensTo]`. Это описано в полном объеме — диспетчеризация, регистрация слушателей, приоритеты, события, которые можно остановить, и встроенный каталог событий - на специальной странице [Система событий](event-system.md).

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

## Задача Cron


**Логика Cron** (`MyCron.php`) — только бизнес-логика, без подключения к интерфейсу командной строки.

**Обертка от CronJob** (`MyCronJob.php`) — реализует `CommandInterface`, использует `CronTrait`:

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

**Формат:** ключ = выражение cron, значение = имя консольной команды, зарегистрированное с помощью `registerCommands()`.

---

## Версионные миграции (MigratableInterface)


> **Два механизма, оба аддитивные.** **файловая схема**, описанный в разделе
> [Структура каталогов модулей](module-authoring.md#module-directory-structure) (`database.sql` мастер +
> `database_drop.sql` разборка + `migrations/<semver>.sql` дельты) используется по умолчанию для
> обычный DDL/seed. `MigratableInterface` ниже приведен **программный** путь для обновления
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

**Key rules:**

- Ключи - это полустрочные строки (`'1.1.0'`, `'2.0.0'`) — `version_compare` используется упорядочение
- Каждая миграция выполняется в рамках своей собственной транзакции — сбой откатывает только этот шаг
- `BaseModule` предоставляет значение по умолчанию `getMigrations(): array { return []; }`, поэтому реализация
`MigratableInterface` является необязательным

---
