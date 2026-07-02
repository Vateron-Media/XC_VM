# Module System

## Overview

A module is an isolated directory under `src/Modules/` with a known contract. The system
is built on **Extensible Platform** principles:

- Core (`Core/`) has no knowledge of modules
- Modules may depend on `Core/` and `Domain/`, never on each other (except via declared dependencies)
- Any module can be disabled from `config/modules.php` without touching core
- Removing a module directory causes no fatal errors

---

## Module directory structure

```text
src/Modules/my-module/
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

A module owns its schema through **three roles that mirror core** (`bin/install/database.sql`
+ `migrations/`):

| File | Role | Runs on |
| ---- | ---- | ------- |
| `database.sql` | **One** master schema — the full current `CREATE`/seed | fresh **install** |
| `database_drop.sql` | **One** teardown — `DROP TABLE` for every table the module owns | **uninstall** |
| `migrations/<semver>.sql` | **Folder** of forward deltas between versions | **update**, for versions in `(installed, current]` |

Rules:

- **Fresh install runs only `database.sql`**, so it must always reflect the LATEST
  schema (every delta folded in). The recorded `installed_version` is the watermark —
  deltas never replay on a fresh install.
- **Deltas are forward-only** (`ALTER`/`INSERT`), named `<semver>.sql` — teardown is the
  single `database_drop.sql`, so there are no per-version `.down` files.
- Keep deltas **idempotent** (`ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`) so re-runs are safe.
- A module with no schema ships none of these files. A delta-only module (no `database.sql`)
  still installs by replaying every delta ≤ its version.

---

## module.json

```json
{
    "name": "my-module",
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

### Manifest fields

| Field | Type | Default | Description |
| ------ | ----- | :---: | ------------ |
| `name` | `string` | — | Unique module name (matches directory) |
| `description` | `string` | `""` | Human-readable description |
| `version` | `string` | — | Semver version (`1.0.0`) |
| `requires_core` | `string` | — | Minimum core version (`>=2.0`) |
| `environment` | `string` | `"main"` | `main`, `lb`, or `any` |
| `priority` | `int` | `0` | Load priority — higher loads earlier |
| `dependencies` | `array` | `[]` | Hard dependencies; if unavailable, the dependent is skipped (see below) |
| `optional_dependencies` | `array` | `[]` | Soft dependencies (loaded before if present) |
| `has_navbar` | `bool` | `false` | Whether the module registers navbar items |
| `has_settings` | `bool` | `false` | Whether the module has a settings page |

**Hard vs soft dependencies:**

- `dependencies` — if any is unavailable (missing on disk, disabled, or in `failed` state), the dependent module is **skipped** with a logged warning — cascading (anything depending on it is skipped too). The rest of the modules, the admin panel, and the CLI keep working; a single unsatisfied dependency no longer aborts the whole load.
- `optional_dependencies` — loaded before this module if present, silently skipped if absent

> **Guard against drift.** A module that still-enabled modules depend on cannot be `disabled` via the panel / `ModuleManager::setState()` — the operation is rejected with the list of dependents (mirroring the `uninstallModule()` guard). This prevents the "`plex` enabled but its `watch` dependency disabled" state.

**Priority:**

- Topological sort respects the dependency graph first, then within the same group sorts by `priority` descending (higher number = loaded earlier), then alphabetically

---

## Sub-interfaces

`ModuleInterface` splits the module's surface area into typed sub-contracts:

```text
ModuleInterface
├── ServiceProviderInterface   → boot(ServiceContainer)
├── RouteProviderInterface     → registerRoutes(Router)
├── CommandProviderInterface   → registerCommands(CommandRegistry)
└── NavbarProviderInterface    → registerNavbar()
```

`StreamMiddlewareProviderInterface` is **optional** — it is NOT part of `ModuleInterface`.
Implement it only if the module needs to inject itself into the stream pipeline.

```php
// Optional — not in ModuleInterface
class MyModule implements ModuleInterface, StreamMiddlewareProviderInterface {
    public function getStreamMiddleware(): array {
        return [new MyStreamMiddleware()];
    }
}
```

---

## Module class

Extend `BaseModule` — it provides no-op defaults for every optional method so you only
override what the module actually uses. Only `getName()` and `getVersion()` are required.

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

> **Tip:** a module with no routes, no navbar items, and no CLI commands only needs
> `getName()`, `getVersion()`, and `boot()`.
> A `BoundaryInterface` module (isolated subsystem with its own entry point) typically
> leaves `boot()` and `registerRoutes()` inherited as no-ops.

### Method contract

| Method | Interface | Description |
| ------- | ----------- | ---------- |
| `getName(): string` | `ModuleInterface` | Unique name (matches directory) |
| `getVersion(): string` | `ModuleInterface` | Semver version |
| `boot(ServiceContainer)` | `ServiceProviderInterface` | Register services in DI container |
| `registerRoutes(Router)` | `RouteProviderInterface` | Register HTTP and API routes |
| `registerCommands(CommandRegistry)` | `CommandProviderInterface` | Register CLI commands and cron tasks |
| `registerNavbar()` | `NavbarProviderInterface` | Register navbar items |
| `install(): void` | `ModuleInterface` | Run on module install (migrations, seed) |
| `uninstall(): void` | `ModuleInterface` | Run on module remove (cleanup) |

> **Important — the version lives in two places.** A module declares its version
> **twice**: the `"version"` field in `module.json` and the return value of
> `getVersion()` in the module class. **Keep them identical and bump both before
> publishing.** At runtime the manifest `version` takes precedence — install/update
> and the `installed_version` watermark read `module.json` first and only fall back
> to `getVersion()` — so a stale `getVersion()` silently drifts out of sync and is a
> common source of "wrong migration ran / didn't run" bugs. If the module ships file
> migrations, `database.sql` (master schema) and the highest `migrations/<semver>.sql`
> delta should match this version too.

---

## PHP namespaces

Every module lives in a dedicated PHP namespace: `XcVm\Module\{Pascal}`, where `{Pascal}` is
the PascalCase conversion of the module directory name.

```
src/Modules/my-module/   →  namespace XcVm\Module\MyModule;
src/Modules/watch/       →  namespace XcVm\Module\Watch;
```

The main module file must declare this namespace and extend `BaseModule`:

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

All secondary classes in the same module share the same namespace:

```php
<?php
namespace XcVm\Module\MyModule;

class MyModuleService { /* ... */ }
class MyModuleController { /* ... */ }
class MyModuleCronJob { /* ... */ }
```

`use` the classes you reference:

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

- Main module class filename: `<PascalName>Module.php` — required (ModuleLoader convention)
- All other class filenames: `<PascalName><Purpose>.php`
- Add `use ClassName;` for every core class referenced (BaseModule, ServiceContainer, Router, etc.)
- Never import classes from other modules — communicate via events or the DI container

---

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

Events are plain PHP classes. Dispatch them via `EventDispatcher`:

```php
// In any module
EventDispatcher::dispatch(new MyEvent($payload));

// Subscribe
EventDispatcher::listen(MyEvent::class, function (MyEvent $e): void {
    // handle
}, priority: 10);
```

**Priority** — higher integer = called first. Default `0`.

**Stoppable events** — extend `AbstractEvent` and call `$e->stopPropagation()`:

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

### Built-in core events

| Event class | When dispatched | Stoppable |
| --------------- | ---------------------- | :-----------: |
| `ModuleLoadedEvent` | After module file is loaded | ❌ |
| `ModuleBootedEvent` | After `boot()` is called | ❌ |
| `PackageInstalledEvent` | After marketplace install | ❌ |
| `UserAuthenticatedEvent` | After successful login | ✅ |
| `UserLoggedOutEvent` | After logout | ❌ |
| `StreamStartingEvent` | Before stream starts | ✅ |
| `StreamStartedEvent` | After stream started | ❌ |
| `StreamStoppedEvent` | After stream stopped | ❌ |
| `SettingsChangedEvent` | After settings saved | ❌ |

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

## Enable / disable modules

All discovered modules load by default. Use `src/config/modules.php` to override state:

```php
return [
    'my-module' => ['state' => 'disabled'],  // preferred
    // or legacy boolean (still accepted):
    'my-module' => ['enabled' => false],
];
```

Available `state` values (backed by `ModuleState` enum):

| Value | Meaning |
| ----- | ------- |
| `enabled` | Module loads and boots (default) |
| `disabled` | Module is discovered but skipped |
| `installing` | Transient state set by `ModuleManager` during install |
| `failed` | Install failed; module skipped (not loaded) |

> **Panel diagnostics.** The **Modules** page shows a yellow **⚠ Dependency issue** badge next to a module's status when a required dependency is missing or not enabled (e.g. `plex` reads `Enabled` but `watch` is `failed`). The badge tooltip lists the concrete problems. This `dependency_warnings` field is computed by `ModuleManager::listModules()`.

To override the class resolved for a module:

```php
return [
    'my-module' => ['class' => 'XcVm\\Module\\MyModuleV2\\MyModuleV2Module'],
];
```

`config/modules.php` contains only overrides. An empty or missing file means all discovered
modules load.

---

## How loading works

`ModuleLoader` follows these steps on every request:

1. Scans `src/Modules/*/module.json`
2. Applies overrides from `config/modules.php`
3. Filters by environment (`main` / `lb` / `any`)
4. Resolves the load order:
   - `pruneUnsatisfiableModules()` drops modules whose required dependencies are unavailable (cascading, with a logged warning) so the load never aborts
   - Topological sort (DFS) over the dependency graph
   - Within the same dependency group, sorts by `priority` descending, then alphabetically
   - Throws `RuntimeException` on cycles (cyclic dependencies remain fatal)
   - Missing optional dependencies are silently skipped
5. Resolves class name: `my-module` → FQN `XcVm\Module\MyModule\MyModuleModule`
   (kebab-case → PascalCase; can be overridden via `class` key in config)
6. Registers the module's PSR-4 autoloader (maps `XcVm\Module\<Name>` onto the module directory)
7. Instantiates the module class

In web context:

- `bootAll($container, $router)` → calls `boot()`, `registerRoutes()`, `registerNavbar()`,
  and subscribes to events for every loaded module

In CLI context:

- `registerAllCommands($registry)` → calls `registerCommands()` on every loaded module

---

## Marketplace: install via C extension

Modules from the platform are installed via `ModuleManager::downloadFromPlatform()`:

```php
$manager->downloadFromPlatform(slug: 'my-module', version: '1.2.0', apiKey: $key);
```

Under the hood:

1. `XC_VM::module_install($slug, $version, $apiKey)` — C extension downloads, decrypts, unpacks
2. `installModule($slug)` — runs `install()` on the module
3. `EventDispatcher::dispatch(new PackageInstalledEvent(...))` — dispatches the event
4. `hotReload($slug, $path)` — loads and boots the module in the current request **without PHP-FPM restart**

---

## Isolated subsystems (BoundaryInterface)

A module that is a fully isolated subsystem with its own entry point and bootstrap
(like Ministra) implements `BoundaryInterface` alongside `ModuleInterface`:

```php
class MyModule implements ModuleInterface, BoundaryInterface {

    public function getName(): string {
        return 'my-module';
    }

    public function getEntryPoint(): string {
        // Path relative to src/ — this file handles its own bootstrap
        return 'my-module/portal.php';
    }

    public function isIsolated(): bool {
        return true;
    }
}
```

`BoundaryInterface` is an isolation marker. `isIsolated() = true` means the subsystem
runs through its own entry point with a separate bootstrap path. It shares infrastructure
(db, cache, config) but does **not** participate in the main `Router`, `ModuleLoader::bootAll()`,
or `NavbarRegistry`. The `boot()` and `registerRoutes()` implementations may be left as
no-ops in this case.

---

## Controller

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

| Rule | |
| --------- | -- |
| `__DIR__ . '/views'` | viewsPath — the controller is inside the module directory |
| GET pages | call `renderUnifiedLayoutHeader` before view, `renderUnifiedLayoutFooter` after |
| API actions | no layout — return JSON and exit |

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
> [Module directory structure](#module-directory-structure) (`database.sql` master +
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

## Composer package discovery

Modules can be distributed as Composer packages with `"type": "xcvm-module"`:

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

`ModuleLoader` automatically scans `vendor/composer/installed.json` (Composer 1 and 2
formats) and discovers any installed `xcvm-module` packages alongside the built-in
`src/Modules/` directory. Packages are deduplicated — a module in both `modules/` and
`vendor/` is loaded only once.

---

## Module checklist

- [ ] Create `src/Modules/<name>/`
- [ ] Add `namespace XcVm\Module\<PascalName>;` to every class file
- [ ] Create `module.json` with `name`, `version`, `requires_core`, `priority`, `dependencies`, `optional_dependencies`
- [ ] Create `<PascalName>Module.php` extending `BaseModule`
- [ ] Set the version in **both** `module.json` `"version"` and `getVersion()` — they must match (bump both before publishing)
- [ ] Implement `boot()` for all services the module provides
- [ ] Implement `registerRoutes()` for HTTP / API endpoints
- [ ] Implement `registerNavbar()` for admin panel items (or leave empty)
- [ ] (If crons) Create `MyCron.php` + `MyCronJob.php`, register in `registerCommands()`
- [ ] (If crons) Override `getCronEntries()` in the module class (no core file changes)
- [ ] (If schema) Ship `database.sql` (master), `database_drop.sql` (teardown), and `migrations/<semver>.sql` deltas
- [ ] (If PHP-logic migrations) Implement `MigratableInterface::getMigrations()`
- [ ] (If pages) Create controller using `renderUnifiedLayoutHeader/Footer`
- [ ] (If stream middleware) Implement `StreamMiddlewareProviderInterface` separately
- [ ] Verify: `php -l src/Modules/<name>/<PascalName>Module.php`
- [ ] Verify: `php console.php --list` shows the module's commands
- [ ] Verify: removing the module directory causes no fatal error

---

## FAQ

**Q: How do I disable a module?**
In `src/config/modules.php` add `'module-name' => ['state' => 'disabled']`.
The legacy `'enabled' => false` form is also accepted for backward compatibility.

**Q: How do I declare that my module depends on another?**
Use `dependencies` in `module.json` for hard deps (must be present) or `optional_dependencies`
for soft deps (loaded before yours if present, silently skipped if absent).

**Q: Can I decorate a core service?**
Yes — use `$container->decorate('service-id', callable, priority)` in `boot()`.
Protected services (`db`, `settings`, `config`, `auth`) cannot be decorated.

**Q: How do I listen to core events?**
Call `EventDispatcher::listen(EventClass::class, callable, priority)` anywhere after bootstrap,
typically inside `boot()` or a dedicated subscriber class.

**Q: Can I dispatch custom events from a module?**
Yes. Create a plain class or extend `AbstractEvent` and call `EventDispatcher::dispatch(new MyEvent(...))`.

**Q: What is `StreamMiddlewareProviderInterface` for?**
It lets the module inject a `StreamMiddlewareInterface` into the stream processing pipeline
without modifying `StreamProcess.php`. Implement it alongside `ModuleInterface` when needed.

## Related files

| File | Role |
| --- | --- |
| `src/Core/Module/ModuleLoader.php` | Discovers, sorts and boots modules; PSR-4 class resolver |
| `src/config/modules.php` | Module enable / class-override config |
| `src/Modules/` | Module directories |
| `src/Core/Module/Contract/` | Module sub-interfaces |
