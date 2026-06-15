# Module System

## Overview

A module is an isolated directory under `src/modules/` with a known contract. The system
is built on **Extensible Platform** principles:

- Core (`core/`) has no knowledge of modules
- Modules may depend on `core/` and `domain/`, never on each other (except via declared dependencies)
- Any module can be disabled from `config/modules.php` without touching core
- Removing a module directory causes no fatal errors

---

## Module directory structure

```text
src/modules/my-module/
├── module.json          # Metadata and manifest
├── MyModule.php         # Module class (source of truth)
├── MyService.php        # Business logic
├── MyController.php     # Admin pages (optional)
├── MyCron.php           # Cron logic (optional)
├── MyCronJob.php        # CLI cron wrapper (optional)
└── views/               # Page templates (optional)
    ├── my_page.php
    └── my_page_scripts.php
```

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
| `dependencies` | `array` | `[]` | Hard dependencies (must exist and be enabled) |
| `optional_dependencies` | `array` | `[]` | Soft dependencies (loaded before if present) |
| `has_navbar` | `bool` | `false` | Whether the module registers navbar items |
| `has_settings` | `bool` | `false` | Whether the module has a settings page |

**Hard vs soft dependencies:**

- `dependencies` — if any is missing or disabled, `ModuleLoader` throws `RuntimeException`
- `optional_dependencies` — loaded before this module if present, silently skipped if absent

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

class MyModule extends BaseModule {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', function (ServiceContainer $c): MyService {
            return new MyService($c->get('db'));
        });
    }

    public function registerRoutes(Router $router): void {
        $router->get('my_page', [MyController::class, 'index'], [
            'permission' => ['adv', 'my_module'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new MyCronJob());
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

---

## Class naming convention

All module classes live in the **global PHP namespace** (no Composer, no PSR-4 autoloader).
To prevent collisions with other modules and with core classes, prefix every class with the
PascalCase module name:

| Module | Prefix | Examples |
| ------- | ------- | ------- |
| `my-module` | `MyModule` | `MyModuleService`, `MyModuleController`, `MyModuleCronJob` |
| `watch` | `Watch` | `WatchService`, `WatchController`, `WatchCronJob` |

**Rules:**

- Main module class: `<ModuleName>Module.php` — required (ModuleLoader convention)
- All other classes: `<ModuleName><Purpose>.php`
- Never use generic names (`Service`, `Controller`, `Helper`) — they will collide
- Until PHP namespaces are introduced, the prefix is the only collision guard

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

All discovered modules load by default. To disable one, add to `src/config/modules.php`:

```php
return [
    'my-module' => ['enabled' => false],
];
```

To override the class resolved for a module:

```php
return [
    'my-module' => ['class' => 'MyModuleV2'],
];
```

`config/modules.php` contains only overrides. An empty or missing file means all discovered
modules load.

---

## How loading works

`ModuleLoader` follows these steps on every request:

1. Scans `src/modules/*/module.json`
2. Applies overrides from `config/modules.php`
3. Filters by environment (`main` / `lb` / `any`)
4. Resolves the load order:
   - Topological sort (DFS) over the dependency graph
   - Within the same dependency group, sorts by `priority` descending, then alphabetically
   - Throws `RuntimeException` on cycles or missing hard dependencies
   - Missing optional dependencies are silently skipped
5. Resolves class name: `my-module` → `MyModule`
   (kebab-case → PascalCase + `Module`; can be overridden in config)
6. Registers per-module autoloader via `XC_Autoloader`
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
        require_once MAIN_HOME . 'public/Views/layouts/admin.php';
        require_once MAIN_HOME . 'public/Views/layouts/footer.php';
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

Add to crontab (`StartupCommand::installCrontab()`):

```php
$rCrons[] = '*/5 * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:my_task # XC_VM';
```

---

## Module checklist

- [ ] Create `src/modules/<name>/`
- [ ] Create `module.json` with `name`, `version`, `requires_core`, `priority`, `dependencies`, `optional_dependencies`
- [ ] Create `<Name>Module.php` extending `BaseModule`
- [ ] Implement `boot()` for all services the module provides
- [ ] Implement `registerRoutes()` for HTTP / API endpoints
- [ ] Implement `registerNavbar()` for admin panel items (or leave empty)
- [ ] (If crons) Create `MyCron.php` + `MyCronJob.php`, register in `registerCommands()`
- [ ] (If crons) Add crontab entry in `StartupCommand::installCrontab()`
- [ ] (If pages) Create controller using `renderUnifiedLayoutHeader/Footer`
- [ ] (If stream middleware) Implement `StreamMiddlewareProviderInterface` separately
- [ ] Verify: `php -l src/modules/<name>/<Name>Module.php`
- [ ] Verify: `php console.php --list` shows the module's commands
- [ ] Verify: removing the module directory causes no fatal error

---

## FAQ

**Q: How do I disable a module?**
In `src/config/modules.php` add `'module-name' => ['enabled' => false]`.

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
