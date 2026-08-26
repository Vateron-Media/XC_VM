# Module Extension Points

The core extension points a module plugs into: the DI container, stream middleware, cron tasks, versioned migrations, and typed events. To author a module see [Module Authoring](module-authoring.md); for load/lifecycle see [Module Lifecycle](module-lifecycle.md).

## DI container and service decoration


Services are registered in `boot()` via `ServiceContainer`. The container supports:

- **`set(id, factory)`** — lazy singleton via callable, or direct value
- **`factory(id, callable)`** — new instance on every `get()`
- **`decorate(id, callable, priority)`** — wrap an existing service

```php
// Decorate a service (adds behaviour around the original)
$container->decorate('stream.encoder', function (mixed $inner, ServiceContainer $c): MyEncoder {
    return new MyEncoder($inner, $c->get('settings'));
}, priority: 20);
```

Decorators are chained by priority (highest wraps outermost). Protected services
(`db`, `settings`, `config`, `auth`) cannot be decorated — any attempt throws `RuntimeException`.

### PSR-11 compliance

`ServiceContainer` implements `ContainerInterface`:

```php
public function get(string $id): mixed;  // throws NotFoundException if missing
public function has(string $id): bool;
```

`NotFoundException` implements `NotFoundExceptionInterface extends ContainerExceptionInterface`.

---

## PSR-14 events

Modules subscribe to typed events via `getEventSubscribers()` or the `#[ListensTo]` attribute. This is documented in full — dispatch, listener registration, priorities, stoppable events and the built-in event catalogue — on the dedicated [Event System](event-system.md) page.

---

## Stream Middleware


Modules can inject middleware into the stream pipeline by implementing
`StreamMiddlewareProviderInterface` (separate from `ModuleInterface`):

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

`StreamContext` is an attribute bag (`get`, `set`, `has`, `abort`, `isAborted`). `StreamPipeline`
executes middleware sorted by `getPriority()` descending.

### Pipeline priorities

| Range | Owner |
| ---------- | ----------------- |
| `80–100` | Core (Auth, Permission, ConnectionLimit) |
| `0–79` | Modules |

### Reserved navbar slots

| Parent node | Module slots |
| ------------------- | ------------------ |
| `management.service_setup` | `order` ≥ 60 |
| `management.logs` | `order` ≥ 170 |

---

## Cron task


**Cron logic** (`MyCron.php`) — business logic only, no CLI wiring.

**CronJob wrapper** (`MyCronJob.php`) — implements `CommandInterface`, uses `CronTrait`:

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

Registration in the module:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

Declare the crontab entry by overriding `getCronEntries()` in the module class:

```php
public function getCronEntries(): array {
    return [
        '*/5 * * * *' => 'cron:my_task',
    ];
}
```

`ModuleLoader::collectCronEntries()` aggregates all modules' entries and `StartupCommand` /
`StatusCommand` write them to the system crontab automatically — no core file changes needed.

**Format:** key = cron expression, value = console command name registered via `registerCommands()`.

---

## Versioned migrations (MigratableInterface)


> **Two mechanisms, both additive.** The **file-based schema** described under
> [Module directory structure](module-authoring.md#module-directory-structure) (`database.sql` master +
> `database_drop.sql` teardown + `migrations/<semver>.sql` deltas) is the default for
> plain DDL/seed. `MigratableInterface` below is the **programmatic** path for upgrade
> steps that need PHP logic (data backfills, conditional changes). A module can use
> either or both; `ModuleManager::updateModule()` runs the file deltas first, then the
> callable migrations.

Modules whose upgrades need PHP logic implement `MigratableInterface`:

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

`ModuleManager::updateModule()` reads `installed_version` from the override store, filters
the map to only the entries `> fromVersion && <= toVersion`, sorts by semver, and runs each
callable in its own DB transaction. `installModule()` records `installed_version` after
a successful install; `uninstallModule()` clears it.

**Key rules:**

- Keys are semver strings (`'1.1.0'`, `'2.0.0'`) — `version_compare` ordering is used
- Each migration runs in its own transaction — failure rolls back only that step
- `BaseModule` provides a default `getMigrations(): array { return []; }` so implementing
  `MigratableInterface` is optional

---
