# Module Authoring

How to build an XC_VM module: its on-disk layout, the `module.json` manifest, the module class + method contract, namespaces, and its controller. For how a module is discovered/loaded/distributed see [Module Lifecycle](module-lifecycle.md); for the hooks it plugs into see [Module Extension Points](module-extension-points.md).

## Overview


A module is an isolated directory under `src/Modules/` with a known contract. The system
is built on **Extensible Platform** principles:

- Core (`Core/`) has no knowledge of modules
- Modules may depend on `Core/` and `Domain/`, never on each other (except via declared dependencies)
- Any module can be disabled from `config/modules.php` without touching core
- Removing a module directory causes no fatal errors

---

## Module directory structure


The directory name follows the **`{name}_{hash5}`** convention, where `hash5` is the
first 5 characters of the module's `hash_id`. The logical module name (`module.json`
`name`, which never contains `_`) is always resolved from the manifest — never from the
directory basename. This lets two modules with the **same name** live in distinct
directories (`watch_2541a`, `watch_9f1c0`) and install without a filesystem clash. The
config, dependency graph, and namespace all key off the canonical `name`, so a directory
rename needs no data migration. Every module **must** have a `hash_id`: uploads that ship
without one get a fresh id generated and written into their `module.json` before placement,
so a hash-less directory is never created. A legacy bare `Modules/{name}/` directory from an
older deployment is still read, but is **auto-migrated** to `{name}_{hash5}` (generating a
`hash_id` if missing) on the next `console.php status` — the hash-less layout is retired, not
kept.

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

### Manifest fields

| Field | Type | Default | Description |
| ------ | ----- | :---: | ------------ |
| `name` | `string` | — | Canonical module name (kebab-case, no `_`). The directory is `{name}_{hash5}`, but code always keys off this manifest value, not the directory basename. |
| `hash_id` | `string` | generated | **Permanent** module identity — random 32-hex, generated ONCE and never changed on a version bump or rename. Its first 5 chars form the `{name}_{hash5}` directory suffix. Do not hand-edit. |
| `description` | `string` | `""` | Human-readable description |
| `version` | `string` | — | Semver version (`1.0.0`) |
| `requires_core` | `string` | — | Minimum core version (`>=2.0`) |
| `environment` | `string` | `"main"` | `main`, `lb`, or `any` |
| `priority` | `int` | `0` | Load priority — higher loads earlier |
| `dependencies` | `array` | `[]` | Hard dependencies; if unavailable, the dependent is skipped (see below) |
| `optional_dependencies` | `array` | `[]` | Soft dependencies (loaded before if present) |
| `has_navbar` | `bool` | `false` | Whether the module registers navbar items |
| `has_settings` | `bool` | `false` | Whether the module has a settings page |

> **`hash_id` — the module's permanent identity.** It is a random 32-hex value,
> generated **once** and **never** changed afterwards — it must survive version bumps
> and renames (so it is random, not derived from `name`/`version`). Generate one with
> `php -r 'echo bin2hex(random_bytes(16));'` and paste it into `module.json` when
> scaffolding a new module. Do not hand-write it or reuse another module's. It gives modules a stable identity independent of `name`,
> which is the groundwork for moving modules into separate repositories and for an
> explicit per-module **update source** — the `update` manifest block (see below).

**Hard vs soft dependencies:**

- `dependencies` — if any is unavailable (missing on disk, disabled, or in `failed` state), the dependent module is **skipped** with a logged warning — cascading (anything depending on it is skipped too). The rest of the modules, the admin panel, and the CLI keep working; a single unsatisfied dependency no longer aborts the whole load.
- `optional_dependencies` — loaded before this module if present, silently skipped if absent

> **Guard against drift.** A module that still-enabled modules depend on cannot be `disabled` via the panel / `ModuleManager::setState()` — the operation is rejected with the list of dependents (mirroring the `uninstallModule()` guard). This prevents the "`plex` enabled but its `watch` dependency disabled" state.

**Priority:**

- Topological sort respects the dependency graph first, then within the same group sorts by `priority` descending (higher number = loaded earlier), then alphabetically

**Update source (`update` block, optional):**

Where a module gets its updates from. Absent → `bundled` (files ship with the panel and update with it).

```json
"update": {
    "source": "bundled | platform | git | url",
    "repository": "https://github.com/Vateron-Media/xc_vm-module-watch",
    "channel": "stable",
    "slug": "watch",
    "url": "https://…/version.json"
}
```

- `source` — `bundled` (with the panel), `platform` (SaaS store), `git` (repo releases), `url` (self-hosted). Unknown values fall back to `bundled`.
- `repository` — git remote (for `git`); `slug` — store slug (for `platform`, defaults to `name`); `url` — version/archive URL (for `url`); `channel` — `stable`/`beta` (default `stable`).

The block is normalized by `ModuleLoader` and exposed via `ModuleManager::listModules()`. A weekly cron (`cron:module_updates`) checks the `git`/`url` sources and records `available_version`, which drives the **Update to X** button (shown only when a newer version exists). Clicking Update runs `ModuleManager::updateModuleFromSource()`:

- `bundled` — files arrive with the panel; Update just runs the pending migrations.
- `platform` — delegated to the store install/update flow (rollback + LB fan-out inside).
- `git` — downloads the release asset **`module.tar.gz`** at the tag == the new version (md5-verified via the release `hashes.md5` when present).
- `url` — re-reads `version.json` for its `download` (https) + optional `md5`.

For `git`/`url` the fetched `module.json` **`hash_id` must equal the installed one** (identity pinning — a repo/URL can't impersonate another module), then: backup → replace files → migrate → **roll back on any failure** → distribute to LB.

**Standard set & provisioning.** The modules the panel installs by default are listed in `config/bundled_modules.php`, keyed by `hash_id` (stable across renames). Today all are `bundled` (their files are in the panel archive). When a module is extracted into its own repository, flip its entry to a `git`/`url`/`platform` source — `syncBundledModules()` then fetches + installs it automatically via `provisionStandardSet()` (a no-op while everything is bundled on-disk). `ModuleManager::findModuleByHashId()` resolves a module by its stable id regardless of directory/name.

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

> **Tip:** a module with no routes, no navbar items, and no CLI commands only needs
> `getName()`, `getVersion()`, and `boot()`.
> An isolated-subsystem module (its own entry point and bootstrap, like Ministra) typically
> leaves `boot()` and `registerRoutes()` inherited as no-ops.

### Method contract

| Method | Interface | Description |
| ------- | ----------- | ---------- |
| `getName(): string` | `ModuleInterface` | Unique name (matches directory) |
| `getVersion(): string` | `ModuleInterface` | Semver version |
| `boot(ServiceContainer)` | `ServiceProviderInterface` | Register services in DI container |
| `registerRoutes(Router)` | `RouteProviderInterface` | Register HTTP and API routes |
| `registerCommands(CommandRegistry)` | `CommandProviderInterface` | Register CLI commands and cron tasks |
| `registerNavbar(NavbarRegistry $registry)` | `NavbarProviderInterface` | Register navbar items |
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

## Module checklist


- [ ] Create `src/Modules/<name>/`
- [ ] Add `namespace XcVm\Module\<PascalName>;` to every class file
- [ ] Create `module.json` with `name`, `version`, `requires_core`, `priority`, `dependencies`, `optional_dependencies`
- [ ] Stamp a permanent `hash_id` (`php -r 'echo bin2hex(random_bytes(16));'`; never hand-write it)
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
