# Event System

XC_VM uses a PSR-14-style typed event dispatcher. All events are plain PHP classes
dispatched and received by name. The dispatcher is instance-based and stored in the
DI container under the key `events`.

---

## EventDispatcher

`EventDispatcher` is a singleton with an instance bridge. Static methods delegate to the
active instance so existing call sites work without changes.

```php
// bootstrap.php wires the canonical instance:
$dispatcher = new EventDispatcher();
EventDispatcher::setInstance($dispatcher);
$container->set('events', $dispatcher);

// Both paths reach the same listener store:
EventDispatcher::dispatch(new MyEvent(...));           // static call
$container->get('events')->dispatch(new MyEvent(...)); // instance call
```

In tests, isolate state per-test with:

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

## Dispatching and listening

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

**Priority** — higher integer = called first. Default `0`.

---

## Registering listeners in a module

### Option 1 — getEventSubscribers() array

```php
public function getEventSubscribers(): array {
    return [
        StreamStartedEvent::class => [$this, 'onStreamStarted'],
        StreamStartedEvent::class => [[$this, 'onStreamStarted'], 20], // with priority
    ];
}
```

### Option 2 — #[ListensTo] attribute

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

Both mechanisms work simultaneously and can coexist in the same module.
`ModuleLoader::bootAll()` runs both passes for every loaded module.

---

## Stoppable events

Extend `AbstractEvent` and call `$e->stopPropagation()`:

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

Listeners are skipped once `isPropagationStopped()` returns `true`.

---

## Built-in core events

| Event class | Location | When dispatched | Stoppable |
| ----------- | -------- | --------------- | :-------: |
| `ModuleLoadedEvent` | `Events/Module/` | After module file is loaded | No |
| `ModuleBootedEvent` | `Events/Module/` | After `boot()` is called | No |
| `PackageInstalledEvent` | `Events/Module/` | After marketplace install | No |
| `UserAuthenticatedEvent` | `Events/Auth/` | After successful login | Yes |
| `UserLoggedOutEvent` | `Events/Auth/` | After logout | No |
| `StreamStartedEvent` | `Events/Stream/` | After stream started | No |
| `StreamStoppedEvent` | `Events/Stream/` | After stream stopped | No |
| `SettingsChangedEvent` | `Events/Settings/` | After settings saved | No |

---

## Writing a custom event

Plain class — use `readonly` properties for immutable payloads:

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

Stoppable event — extend `AbstractEvent`:

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

Dispatch from anywhere after bootstrap:

```php
EventDispatcher::dispatch(new MyModuleEvent($lineId, 'reason'));
```

---

## ListensTo attribute reference

```php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ListensTo {
    public function __construct(
        public readonly string $eventClass,
        public readonly int    $priority = 0,
    ) {}
}
```

- `eventClass` — fully-qualified class name of the event
- `priority` — listener priority (higher = called first; default `0`)
- Placed on public methods of classes that extend `BaseModule`
- `IS_REPEATABLE` — multiple `#[ListensTo]` on the same method are all registered
- If `eventClass` does not exist at runtime, the attribute is skipped gracefully (no exception)

## Related files

| File | Role |
| --- | --- |
| `src/Core/Events/EventDispatcher.php` | PSR-14 event dispatcher |
| `src/Core/Events/ListensTo.php` | Listener attribute |
| `src/Core/Events/` | Event classes (Auth, Module, Settings, Stream) |
