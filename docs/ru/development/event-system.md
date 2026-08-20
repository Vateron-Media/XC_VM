# Система событий

XC_VM использует типизированный диспетчер событий в стиле PSR-14. Все события являются простыми PHP классами
отправлено и получено по имени. Диспетчер основан на экземпляре и хранится в
Откройте контейнер под ключом `events`.

---

## Диспетчер событий

`EventDispatcher` - это синглтон с мостом экземпляра. Статические методы делегируют
активный экземпляр, поэтому существующие сайты вызовов работают без изменений.

```php
// bootstrap.php wires the canonical instance:
$dispatcher = new EventDispatcher();
EventDispatcher::setInstance($dispatcher);
$container->set('events', $dispatcher);

// Both paths reach the same listener store:
EventDispatcher::dispatch(new MyEvent(...));           // static call
$container->get('events')->dispatch(new MyEvent(...)); // instance call
```

В тестах изолируйте состояние для каждого теста с помощью:

```php
protected function setUp(): void {
    $dispatcher = new EventDispatcher();
    EventDispatcher::setInstance($dispatcher);
}

protected function tearDown(): void {
    EventDispatcher::resetInstance();
}
```

---

## Диспетчеризация и прослушивание

```php
// Dispatch
EventDispatcher::dispatch(new StreamStartedEvent($lineId, $streamId));

// Listen
EventDispatcher::listen(StreamStartedEvent::class, function (StreamStartedEvent $e): void {
    // handle
}, priority: 10);

// Remove a listener
EventDispatcher::unlisten(StreamStartedEvent::class, $myCallable);

// Check
EventDispatcher::hasListeners(StreamStartedEvent::class); // bool
```

**Приоритет** — более высокое целое число = вызывается первым. По умолчанию `0`.

---

## Регистрация слушателей в модуле

### Вариант 1 — Получить массив eventsubscribers()

```php
public function getEventSubscribers(): array {
    return [
        StreamStartedEvent::class => [$this, 'onStreamStarted'],
        StreamStartedEvent::class => [[$this, 'onStreamStarted'], 20], // with priority
    ];
}
```

### Вариант 2 — атрибут #[ListensTo]

```php
use ListensTo;

class MyModuleModule extends BaseModule {

    #[ListensTo(StreamStartedEvent::class, priority: 20)]
    public function onStreamStarted(StreamStartedEvent $e): void {
        // handle
    }

    // IS_REPEATABLE — multiple attributes on the same method
    #[ListensTo(StreamStartedEvent::class)]
    #[ListensTo(StreamStoppedEvent::class)]
    public function onStreamChange(object $e): void {
        // handle both events
    }
}
```

Оба механизма работают одновременно и могут сосуществовать в одном модуле.
`ModuleLoader::bootAll()` выполняет оба прохода для каждого загруженного модуля.

---

## Останавливаемые события

Наберите `AbstractEvent` и позвоните `$e->stopPropagation()`:

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

Прослушиватели пропускаются, как только `isPropagationStopped()` возвращает `true`.

---

## Встроенные основные события

|Класс события|Местоположение|Когда отправлено|Останавливаемый|
| ----------- | -------- | --------------- | :-------: |
| `ModuleLoadedEvent` | `Events/Module/` |После загрузки файла модуля|Нет|
| `ModuleBootedEvent` | `Events/Module/` |После вызова `boot()`|Нет|
| `PackageInstalledEvent` | `Events/Module/` |После установки marketplace|Нет|
| `UserAuthenticatedEvent` | `Events/Auth/` |После успешного входа в систему|Да|
| `UserLoggedOutEvent` | `Events/Auth/` |После выхода из системы|Нет|
| `StreamStartedEvent` | `Events/Stream/` |После начала трансляции|Нет|
| `StreamStoppedEvent` | `Events/Stream/` |После того, как поток прекратился|Нет|
| `SettingsChangedEvent` | `Events/Settings/` |После сохранения настроек|Нет|

---

## Создание пользовательского события

Простой класс — используйте свойства `readonly` для неизменяемых полезных нагрузок.:

```php
<?php
namespace XcVm\Module\MyModule;

final class MyModuleEvent {
    public function __construct(
        public readonly int    $lineId,
        public readonly string $reason,
    ) {}
}
```

Останавливаемое событие — продлить `AbstractEvent`:

```php
<?php
namespace XcVm\Module\MyModule;

use AbstractEvent;

final class MyModuleGatingEvent extends AbstractEvent {
    public bool $vetoed = false;

    public function __construct(
        public readonly int $resourceId,
    ) {}
}
```

Отправка из любой точки мира после начальной загрузки:

```php
EventDispatcher::dispatch(new MyModuleEvent($lineId, 'reason'));
```

---

## Ссылка на атрибут ListensTo

```php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ListensTo {
    public function __construct(
        public readonly string $eventClass,
        public readonly int    $priority = 0,
    ) {}
}
```

- `eventClass` — полное название класса для мероприятия
- `priority` — приоритет прослушивателя (более высокий = вызывается первым; по умолчанию `0`)
- Размещается в общедоступных методах классов, расширяющих `BaseModule`
- `IS_REPEATABLE` — зарегистрировано несколько `#[ListensTo]` одним и тем же методом
- Если `eventClass` не существует во время выполнения, атрибут корректно пропускается (без исключений).

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/Core/Events/EventDispatcher.php` |Диспетчер событий PSR-14|
| `src/Core/Events/ListensTo.php` |Атрибут слушателя|
| `src/Core/Events/` |Классы событий (Авторизация, модуль, Настройки, поток)|
