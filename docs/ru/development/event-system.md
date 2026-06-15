# Система событий

XC_VM использует типизированный диспетчер событий в стиле PSR-14. Все события —
обычные PHP-классы, диспетчируемые и принимаемые по имени класса. Диспетчер
хранится в DI-контейнере под ключом `events`.

---

## EventDispatcher

`EventDispatcher` — синглтон с instance-мостом. Статические методы делегируют
в активный экземпляр, поэтому существующие вызовы работают без изменений.

```php
// bootstrap.php создаёт канонический экземпляр:
$dispatcher = new EventDispatcher();
EventDispatcher::setInstance($dispatcher);
$container->set('events', $dispatcher);

// Оба пути достигают одного хранилища слушателей:
EventDispatcher::dispatch(new MyEvent(...));           // статический вызов
$container->get('events')->dispatch(new MyEvent(...)); // вызов через экземпляр
```

В тестах изолируйте состояние на каждый тест:

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
// Диспетчеризация
EventDispatcher::dispatch(new StreamStartedEvent($lineId, $streamId));

// Прослушивание
EventDispatcher::listen(StreamStartedEvent::class, function (StreamStartedEvent $e): void {
    // обработка
}, priority: 10);

// Удаление слушателя
EventDispatcher::unlisten(StreamStartedEvent::class, $myCallable);

// Проверка
EventDispatcher::hasListeners(StreamStartedEvent::class); // bool
```

**Приоритет** — чем выше целое число, тем раньше вызывается слушатель. По умолчанию `0`.

---

## Регистрация слушателей в модуле

### Вариант 1 — массив getEventSubscribers()

```php
public function getEventSubscribers(): array {
    return [
        StreamStartedEvent::class => [$this, 'onStreamStarted'],
        // с приоритетом:
        UserAuthenticatedEvent::class => [[$this, 'onAuth'], 20],
    ];
}
```

### Вариант 2 — атрибут #[ListensTo]

```php
use ListensTo;

class MyModuleModule extends BaseModule {

    #[ListensTo(StreamStartedEvent::class, priority: 20)]
    public function onStreamStarted(StreamStartedEvent $e): void {
        // обработка
    }

    // IS_REPEATABLE — несколько атрибутов на одном методе
    #[ListensTo(StreamStartedEvent::class)]
    #[ListensTo(StreamStoppedEvent::class)]
    public function onStreamChange(object $e): void {
        // обработка обоих событий
    }
}
```

Оба механизма работают одновременно и могут сосуществовать в одном модуле.
`ModuleLoader::bootAll()` выполняет оба прохода для каждого загруженного модуля.

---

## Останавливаемые события (stoppable)

Расширьте `AbstractEvent` и вызовите `$e->stopPropagation()`:

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

Слушатели пропускаются, как только `isPropagationStopped()` возвращает `true`.

---

## Встроенные события ядра

| Класс события | Расположение | Когда диспетчеризуется | Останавливаемое |
| ------------- | ------------ | ---------------------- | :-------------: |
| `ModuleLoadedEvent` | `Events/Module/` | После загрузки файла модуля | Нет |
| `ModuleBootedEvent` | `Events/Module/` | После вызова `boot()` | Нет |
| `PackageInstalledEvent` | `Events/Module/` | После установки из маркетплейса | Нет |
| `UserAuthenticatedEvent` | `Events/Auth/` | После успешного входа | Да |
| `UserLoggedOutEvent` | `Events/Auth/` | После выхода | Нет |
| `StreamStartedEvent` | `Events/Stream/` | После старта стрима | Нет |
| `StreamStoppedEvent` | `Events/Stream/` | После остановки стрима | Нет |
| `SettingsChangedEvent` | `Events/Settings/` | После сохранения настроек | Нет |

---

## Создание пользовательского события

Обычный класс с `readonly`-свойствами для неизменяемой полезной нагрузки:

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

Останавливаемое событие — расширяйте `AbstractEvent`:

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

Диспетчеризация из любого места после bootstrap:

```php
EventDispatcher::dispatch(new MyModuleEvent($lineId, 'reason'));
```

---

## Справочник атрибута ListensTo

```php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ListensTo {
    public function __construct(
        public readonly string $eventClass,
        public readonly int    $priority = 0,
    ) {}
}
```

- `eventClass` — полное имя класса события (FQN)
- `priority` — приоритет слушателя (выше = вызывается раньше; по умолчанию `0`)
- Размещается на публичных методах классов, расширяющих `BaseModule`
- `IS_REPEATABLE` — несколько `#[ListensTo]` на одном методе регистрируются все
- Если `eventClass` не существует во время выполнения — атрибут пропускается (не исключение)
