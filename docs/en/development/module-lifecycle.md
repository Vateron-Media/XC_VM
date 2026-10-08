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

> **Panel diagnostics.** The **Modules** page shows a yellow **⚠ Issue** badge next to a module's status when the module reads `Enabled` but is not loaded: a required dependency is missing or not enabled (e.g. `plex` reads `Enabled` but `watch` is `failed`), its `requires_core` rules out this core, or its `module.json` cannot be loaded. The badge tooltip lists the concrete problems, for example `Not loaded: it has a module.json that cannot be loaded (…).` This `dependency_warnings` field is computed by `ModuleManager::listModules()`.
>
> The page, its module list and every module operation (the row actions, a ZIP upload, a store install) need the `settings` permission.

To override the class resolved for a module:

```php
return [
    'my-module' => ['class' => 'XcVm\\Module\\MyModuleV2\\MyModuleV2Module'],
];
```

`config/modules.php` contains only overrides. An empty or missing file means all discovered
modules load.

`ModuleManager` keeps its own record in the same entry. `installed_version` is the version
of the module's files on disk. `schema_version` appears beside it only while the schema is
ahead of those files: after a store **Rollback**, or after an update or an upload that
failed once some of its deltas had applied. It is the version from which the next update
selects its deltas, so a delta that has applied is not applied again. It goes away when the
files catch up, and on uninstall. Do not edit it by hand.

---

## How loading works


`ModuleLoader` follows these steps on every request:

1. Scans `src/Modules/*/module.json`
   - A module whose `module.json` cannot be used is skipped with an `error_log` line, and
     the rest still load: the file is not a JSON object, `dependencies` or
     `optional_dependencies` is not a list of names, or `environment` is not `main`, `lb`
     or `any`. The modules that require it are skipped with it (step 4)
2. Applies overrides from `config/modules.php`
3. Filters by environment (`main` / `lb` / `any`)
4. Resolves the load order:
   - `pruneUnsatisfiableModules()` drops modules whose required dependencies are unavailable (cascading, with a logged warning) so the load never aborts
   - Topological sort (DFS) over the dependency graph
   - Within the same dependency group, sorts by `priority` descending, then alphabetically
   - Throws `ModuleCycleException` on cycles (a subclass of `\RuntimeException`; cyclic dependencies remain fatal).
     `ModuleManager` therefore refuses to put in place (upload, store install, update) a module
     that would close a cycle with the modules on disk; optional dependencies and disabled
     modules count
   - Missing optional dependencies are silently skipped
5. Resolves class name: `my-module` → FQN `XcVm\Module\MyModule\MyModuleModule`
   (kebab-case → PascalCase; can be overridden via `class` key in config)
6. Registers the module's PSR-4 autoloader (maps `XcVm\Module\<Name>` onto the module directory)
7. Instantiates the module class
   - A module whose class file does not parse, or whose constructor throws, is skipped like one
     with an unusable `module.json`: an `error_log` line gives the reason with its file and
     line, the modules that require it are skipped with it, and the rest still load.
     Installing or updating such a module still fails with the reason

In web context:

- `bootAll($container, $router)` → calls `boot()`, `registerRoutes()`, `registerNavbar()`,
  and subscribes to events for every loaded module

In CLI context:

- `registerAllCommands($registry)` → calls `registerCommands()` on every loaded module
- `console.php module:migrate <action> <name>` leaves `<name>` out of the load, and with it
  the modules that require it: that process runs the module's install or update steps
  before the module boots

---

## Marketplace: install via the core extension


Modules from the platform are installed via `ModuleManager::downloadFromPlatform()`:

```php
$manager->downloadFromPlatform(slug: 'my-module', version: '1.2.0', apiKey: $key);
```

Under the hood:

1. `XC_VM::module_install($slug, $version, $apiKey)` — the core extension downloads, decrypts, unpacks
2. `installModule($slug)` — applies the schema, then runs `install()` on the module. A first
   install applies `database.sql` (without one, every delta up to the module's version).
   Over an existing install (an update or a **Rollback**) it
   first applies the `migrations/<semver>.sql` deltas and the `getMigrations()` steps in
   (schema version, served version], then `database.sql` when the module ships one, then
   `install()`
3. `EventDispatcher::dispatch(new PackageInstalledEvent(...))` — dispatches the event
4. `hotReload($slug, $path)` — loads and boots the module in the current request **without PHP-FPM restart**

When the request has already loaded the module's class (an enabled module being updated),
step 2 runs in a `console.php module:migrate` process of its own — see
[Versioned migrations](module-extension-points.md#versioned-migrations-migratableinterface).

If a step fails, the previous files and the recorded version are put back. The versions
whose deltas applied stay on record as `schema_version`, so the next attempt resumes after
them.

An archive uploaded on the **Modules** page over an installed module is installed the same
way. An upload that is refused, or that fails to install, leaves the installed copy in
place: its files, its on/off state and the version shown.

### Migrating a backup: tables of modules

The panel installs no module by itself, and keeps no list of which module owns which table. The
migration (setup page → `console.php migrate`) migrates the core tables of a restored XUI.one /
Xtream Codes backup, then handles every other table of it (`MigrateCommand::moduleTables()`: not in
`bin/install/database.sql`, not migrated by the core, not junk of the old panels, e.g. their activity
logs):

- it saves the table to `Modules/migration/<table>.sql` (its definition and rows) and drops it from
  `xc_vm_migrate`;
- it hands it to the installed modules (`LegacyTableMigrationEvent`); a module that takes it copies
  the rows and the file is removed;
- a table nobody took waits in its file: when its module is installed, the file is loaded into a
  staging table `legacy_<table>`, the module copies the rows, and the file and staging table go.

At the end it drops what is left of `xc_vm_migrate` (tables dropped, grants kept): a backup can weigh
gigabytes. If a table could not be saved, the backup database is left as it is and the log says so.
Before the migration, the setup page lists the tables that will be saved for modules, with their
rows.

### The Modules page

The page (`ModulesController` renders it, `ModuleAjaxController` answers it) has two tabs:

- **Installed** — every module with its status (`enabled`, `disabled`, `not_installed`,
  `installing`, `failed`) and its actions.
- **Store** — the official store's modules (`ModuleStore`): `XC_VM::extensions_list()` gives the
  catalogue, `XC_VM::plugins_check()` tells whether the Modules API key bought a paid one. Every
  module is listed, marked **Free**, **Purchased** or **Paid**; a paid one not bought links to its
  store page (`<store>/extensions/<slug>`) instead of installing. The list can be searched and
  sorted by name, version, price or status, 50 rows a page. The answer is cached for 5 minutes per
  API key (**Refresh** asks again).

Enable and disable are applied at once. Every other action (install, update, delete,
rollback, license renewal, store install, archive upload, update check) is a **background job**
(`ModuleJob`): the request queues it and starts `console.php module:job`, which runs it and records
how it ended in `CACHE_TMP_PATH/module_job.json`. One job runs at a time. The page polls
`api?action=module_status` (the modules and the job) until the job ends, and draws only what that
answers.

| Action (`api?action=`) | Method | Does |
|------------------------|--------|------|
| `module_status` | GET | Every module's state and the current job |
| `module` (`sub`, `name`) | POST | `enable`/`disable` at once; any other `sub` queues a job |
| `module_upload` (`module_zip`) | POST | Installs an uploaded archive as a job |
| `module_store` (`refresh=1`) | GET | The store's modules this panel may install |

`config/modules.php` is read with `require`, which OPcache caches in each of the four PHP-FPM
masters. Every read first drops the cached copy if the file changed on disk
(`ModuleManager::revalidate()`), so a change made by one master is what the others serve and boot.

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
