# Module Lifecycle

How XC_VM discovers, loads, enables/disables, installs and distributes modules at runtime. To author a module see [Module Authoring](module-authoring.md); for its extension hooks see [Module Extension Points](module-extension-points.md).

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
   - Throws `ModuleCycleException` on cycles (a subclass of `\RuntimeException`; cyclic dependencies remain fatal)
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

## Isolated subsystems


A module can be a fully isolated subsystem with its own entry point and bootstrap
(like Ministra). This is a **convention**, not a marker interface — it stays an
ordinary `ModuleInterface`/`BaseModule` module:

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

Isolation means the subsystem runs through its own public entry point (e.g.
`my-module/portal.php`, a path relative to `src/` that handles its own bootstrap)
with a separate bootstrap path. It shares infrastructure (db, cache, config) but
does **not** participate in the main `Router`, `ModuleLoader::bootAll()`, or
`NavbarRegistry`. The `boot()` and `registerRoutes()` implementations are typically
left as inherited no-ops.

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
