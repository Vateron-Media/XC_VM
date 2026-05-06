# Module System

## Overview

A module is an isolated directory under `src/modules/` with a known contract. Removing a module **does not break the system** — it continues working with degraded functionality.

### Architecture

```
modules/
├── my-module/
│   ├── module.json            # Metadata (name, description, version, requires_core)
│   ├── MyModule.php           # Source of truth (implements ModuleInterface)
│   ├── MyService.php          # Module services
│   ├── MyController.php       # Controller (if pages exist)
│   ├── MyCron.php             # Cron logic (if any)
│   ├── MyCronJob.php          # CLI cron wrapper (implements CommandInterface)
│   ├── views/                 # Page templates
│   │   ├── my_page.php
│   │   └── my_page_scripts.php
│   └── migrations/            # SQL migrations (if any)
│       └── 001_create_table.sql
```

### Principles

| Rule | Description |
|------|-------------|
| **PHP is the source of truth** | All behavior is defined in the module class, not in JSON |
| **module.json is metadata only** | `name`, `description`, `version`, `requires_core` |
| **Auto-discovery** | `ModuleLoader` scans `modules/*/module.json` — no config registration needed |
| **Isolation** | Module depends on `core/` and `domain/`, but NEVER on other modules |
| **Graceful degradation** | Removing the module directory causes no errors |
| **No reverse dependencies** | Core (`core/`) is unaware of modules |
| **DI via container** | Services registered in `boot()`, not via globals |
| **Explicit command registration** | Module registers commands in `registerCommands()`, no filesystem scanning |

---

## Step 1. Create a directory

```bash
mkdir -p src/modules/my-module
```

Directory name = module name. Use kebab-case: `my-module`, `theft-detection`.

---

## Step 2. Create the manifest `module.json`

```json
{
    "name": "my-module",
    "version": "1.0.0",
    "requires_core": ">=2.0",
    "environment": "main",
    "dependencies": [],
    "has_navbar": false,
    "has_settings": false
}
```

### Manifest fields

| Field | Type | Required | Description |
|-------|------|:---:|-------------|
| `name` | `string` | ✅ | Unique module name (matches directory name) |
| `description` | `string` | ⛔ | Short human-readable module description |
| `version` | `string` | ✅ | Semver version (`1.0.0`) |
| `requires_core` | `string` | ✅ | Minimum core version (`>=2.0`) |
| `environment` | `string` | ✅ | Environment: `main` (primary server), `lb` (load-balancer), `any` (both) |
| `dependencies` | `array` | ✅ | Array of module names that this module depends on. Empty array `[]` if no dependencies |
| `has_navbar` | `boolean` | ✅ | Whether this module has navbar items in admin panel |
| `has_settings` | `boolean` | ✅ | Whether this module has a settings page |

> **Important:**
> - All dependencies must be strings (module names).
> - Dependencies must exist or ModuleLoader will throw an error during loadAll().
> - Cyclic dependencies are automatically detected and cause load failure.
> - Modules are loaded in dependency order: if A depends on B, then B is loaded first.
> - `environment` must be `main`, `lb`, or `any`. If `main`, module only loads on primary servers.

**Manifest examples:**

```json
{
  "name": "watch",
  "description": "Watch activity tracking and statistics",
  "version": "1.2.0",
  "requires_core": ">=2.0",
  "environment": "main",
  "dependencies": [],
  "has_navbar": true,
  "has_settings": true
}
```

```json
{
  "name": "plex",
  "description": "Plex integration module",
  "version": "2.0.0",
  "requires_core": ">=2.0",
  "environment": "any",
  "dependencies": ["ministra"],
  "has_navbar": true,
  "has_settings": true
}
```

```json
{
  "name": "load-balancer-stats",
  "description": "Load balancer statistics collector",
  "version": "1.0.0",
  "requires_core": ">=2.0",
  "environment": "lb",
  "dependencies": [],
  "has_navbar": false,
  "has_settings": false
}
```

> **Note on dependencies:** If your module requires functionality from another module, list its name in `dependencies`. ModuleLoader automatically ensures the required module is loaded before yours, and will throw an error if the required module is missing or disabled.

> **Note on environment:** Use `main` by default for modules on primary servers, `lb` for load-balancer, `any` if the module works everywhere. In practice, `any` is rarely used — most modules are specific to one environment.

---

## Step 3. Create the module class

File `src/modules/my-module/MyModule.php`:

```php
<?php

class MyModule implements ModuleInterface {

    public function getName(): string {
        return 'my-module';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $container->set('my-module.service', 'MyService');
    }

    public function registerRoutes(Router $router): void {
        $router->get('my-module', [MyController::class, 'index'], [
            'permission' => ['adv', 'my_module'],
        ]);
        $router->api('my_action', [MyController::class, 'apiAction'], [
            'permission' => ['adv', 'my_module'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new MyCronJob());
    }

    public function getEventSubscribers(): array {
        return [];
    }

    public function install(): void {
        // Create tables, seed data, etc.
    }

    public function uninstall(): void {
        // Clean up module data
    }

    public function registerNavbar(): void {
        // Register nav items via NavbarRegistry::add()
        // Keep this method empty if the module has no nav entries
    }
}
```

### `ModuleInterface` contract

| Method | Description |
|--------|-------------|
| `getName(): string` | Unique name (matches directory) |
| `getVersion(): string` | Semver version |
| `boot(ServiceContainer)` | Register services. Called once on load |
| `registerRoutes(Router)` | HTTP routes and API actions |
| `registerCommands(CommandRegistry)` | Explicit registration of CLI commands and cron tasks |
| `getEventSubscribers(): array` | Core event subscriptions |
| `install(): void` | Module installation (migrations, seed data) |
| `uninstall(): void` | Module data cleanup |
| `registerNavbar(): void` | Register module items in admin navbar |

---

## Step 4. Automatic registration

**No config registration needed.** `ModuleLoader` automatically discovers all modules from `modules/*/module.json`.

To **disable** a module — add to `src/config/modules.php`:

```php
return [
    'my-module' => ['enabled' => false],
];
```

`config/modules.php` contains only overrides. If the file is empty or missing — all discovered modules are loaded.

### How loading works

1. `ModuleLoader::loadAll()` scans `modules/*/module.json`
2. Checks overrides in `config/modules.php`
3. Filters modules by environment (main/lb/any)
4. Sorts topologically by dependencies (throws error if cyclic or missing dependency)
5. Resolves class by convention: `my-module` → `MyModule` (kebab-case → PascalCase + Module)
6. Creates module instance

In web context (`public/index.php` for admin/reseller):
- `loadAll()` → loads and instantiates all modules
- `bootAll($container, $router)` → calls `boot()`, `registerRoutes()`, `registerNavbar()`, subscribes to events

> **Status M-1:** ✅ Complete. Web module boot is fully integrated in front controller.

In CLI context (console.php):
- `loadAll()` → loads and instantiates all modules
- `registerAllCommands($registry)` → calls `registerCommands()` on each module

---

## Step 4b. Register navbar buttons and menu items

Do not add module menu items directly in `header.php`.
Each module must register its own entries in `registerNavbar()` using `NavbarRegistry::add()`.

Example (module adds one item to service setup and one item to logs):

```php
public function registerNavbar(): void {
    NavbarRegistry::add((new NavbarItem('management.service_setup.my_module'))
        ->parent('management.service_setup')
        ->url('my_module')
        ->label('my_module')
        ->permissions(['my_module'])
        ->order(60));

    NavbarRegistry::add((new NavbarItem('management.logs.my_module_log'))
        ->parent('management.logs')
        ->url('my_module_logs')
        ->label('', 'My Module Logs')
        ->permissions(['my_module'])
        ->order(170));
}
```

Rules for modules:

1. `key` must be unique and stable (prefer `section.group.item`).
2. `parent` must point to an existing core tree node.
3. `order` controls position inside the same parent (smaller = higher).
4. Use `label('translation_key')` for translatable text.
5. Use `label('', 'Literal Text')` for literal text.
6. If the module has no menu entries, keep `registerNavbar()` empty.

---

## How navbar rendering works

Rendering is fully declarative and built from `NavbarRegistry`:

1. `ModuleLoader::bootAll()` starts with `CoreNavbarProvider::register()`.
2. Then each module contributes items via `registerNavbar()`.
3. In `public/Views/admin/header.php`, top-level items come from `NavbarRegistry::getTopLevel()`.
4. Child items are rendered recursively via `NavbarRegistry::getChildren()`.

Visibility rules (helper `_xc_nav_visible`):

1. `desktopOnly` hides the item on mobile.
2. `settingDisabled` hides the item when the corresponding setting flag is enabled.
3. `permissions` are checked via `Authorization::check('adv', ...)` using OR logic.
4. A group with `url='#'` is shown only if it has at least one visible child.

Special rendering cases:

1. `divider` renders as a separator without a link.
2. `submenuClass('megamenu')` switches to two-column rendering for long lists.
3. `noMobileSubmenu` disables submenu expansion on mobile.

---

## Step 4a. Create a controller (optional)

If the module has admin pages, create a controller class. The controller uses the **global layout system** via `renderUnifiedLayoutHeader()` / `renderUnifiedLayoutFooter()`.

File `src/modules/my-module/MyController.php`:

```php
<?php

class MyController {

	protected $viewsPath;
	protected $layoutsPath;

	public function __construct() {
		$this->viewsPath = __DIR__ . '/views';
		$this->layoutsPath = MAIN_HOME . 'public/Views/layouts/';
		require_once $this->layoutsPath . 'admin.php';
		require_once $this->layoutsPath . 'footer.php';
	}

	public function index(): void {
		$_TITLE = 'My Module';
		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/my_page.php';
		renderUnifiedLayoutFooter('admin');
		include $this->viewsPath . '/my_page_scripts.php';
	}

	public function apiAction(): void {
		// API actions (POST) — no layout needed
		$action = $_GET['sub'] ?? '';
		// ...
		echo json_encode(['result' => true]);
		exit;
	}
}
```

### Layout rules

| Rule | Description |
|------|-------------|
| **viewsPath** | Always `__DIR__ . '/views'` — the controller is inside the module directory |
| **layoutsPath** | `MAIN_HOME . 'public/Views/layouts/'` — shared across all modules |
| **GET pages** | Must call `renderUnifiedLayoutHeader()` before and `renderUnifiedLayoutFooter()` after the view |
| **API actions** | No layout — return JSON directly |
| **Scripts include** | Module-specific JS is loaded via `<module>_scripts.php` after the footer |

> **Important:** Use `__DIR__ . '/views'` for viewsPath — **not** `dirname(__DIR__) . '/modules/...'`. The controller file is already inside the module directory.

> `renderUnifiedLayoutHeader('admin', [...])` and `renderUnifiedLayoutFooter('admin')` are defined in `public/Views/layouts/admin.php` and `footer.php`. They extract the necessary global variables (`$rSettings`, `$rUserInfo`, `$db`, etc.) and render the shared admin header/footer.

---

## Step 5. Add a cron task (optional)

### 5.1 Cron class (logic) — in the module

File `src/modules/my-module/MyCron.php`:

```php
<?php

class MyCron {

    public static function run(): void {
        $items = Database::query("SELECT * FROM my_table WHERE status = 'pending'");
        foreach ($items as $item) {
            self::processItem($item);
        }
    }

    private static function processItem(array $item): void {
        // Process item
    }
}
```

### 5.2 CronJob wrapper — in the module directory

File `src/modules/my-module/MyCronJob.php`:

```php
<?php

require_once MAIN_HOME . 'cli/CronTrait.php';

class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_task';
    }

    public function getDescription(): string {
        return 'Cron: task description';
    }

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

### 5.3 Registration in the module

Commands are registered **explicitly** in `registerCommands()`:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

> **Important:** Filesystem scanning of modules is not used. Each module knows its own commands and registers them in `registerCommands()`.

### 5.4 Add to crontab

In `src/cli/Commands/StartupCommand.php` method `installCrontab()`, add:

```php
$rCrons[] = '*/5 * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:my_task # XC_VM';
```

---

## Step 6. Build configuration (Makefile)

The `modules/` directory is **not** included in `LB_DIRS` — all modules are only present in MAIN builds by default. Module files (crons, commands, views) are automatically excluded from LoadBalancer builds.

---

## Complete examples

### Minimal module (no crons, no routes)

Example: `fingerprint`, `theft-detection`, `magscan`.

```
modules/my-module/
├── module.json
└── MyModule.php
```

`module.json`:
```json
{
    "name": "my-module",
    "version": "1.0.0",
    "requires_core": ">=2.0"
}
```

`MyModule.php` — implements all `ModuleInterface` methods. Methods without behavior are left empty.

### Full module (services + routes + commands + events)

Example: `plex`, `watch`.

```
modules/my-module/
├── module.json
├── MyModule.php
├── MyService.php
├── MyRepository.php
├── MyController.php
├── MyCron.php
├── MyCronJob.php
└── views/
    ├── my_page.php
    └── my_page_scripts.php
```

All module files live inside its directory. CronJob wrappers are registered via `registerCommands()`.

Controllers use the global layout system — see [Step 4a](#step-4a-create-a-controller-optional) for the pattern.

### Module with events

```php
public function getEventSubscribers(): array {
    return [
        'stream.started'  => [MyHandler::class, 'onStreamStarted'],
        'stream.stopped'  => [MyHandler::class, 'onStreamStopped'],
        'user.connected'  => [MyHandler::class, 'onUserConnected'],
    ];
}
```

---

## Module addition checklist

- [ ] Create directory `src/modules/<name>/`
- [ ] Create `module.json` (`name`, `version`, `requires_core`)
- [ ] Create `<Name>Module.php` (implements `ModuleInterface`)
- [ ] (If crons) Create `<Name>Cron.php` + `<Name>CronJob.php` in the module
- [ ] (If crons) Register in `registerCommands()`
- [ ] (If crons) Add to crontab via `StartupCommand`
- [ ] (If pages) Create controller with `renderUnifiedLayoutHeader/Footer`
- [ ] (If pages) Create `views/` directory with page templates
- [ ] (If pages) Register routes in `registerRoutes()` (and temporarily in `public/routes/admin.php`)
- [ ] Verify: `php -l src/modules/<name>/<Name>Module.php`
- [ ] Verify: module loads with `php console.php --list`
- [ ] Verify: removing module directory causes no fatal error

---

## Available core events

| Event | Description | Data |
|-------|-------------|------|
| `stream.started` | Stream started | `['stream_id' => int]` |
| `stream.stopped` | Stream stopped | `['stream_id' => int]` |
| `user.connected` | User connected | `['user_id' => int, 'stream_id' => int]` |
| `cache.rebuilt` | Cache rebuilt | `[]` |

---

## FAQ

**Q: How do I disable a module?**
A: In `src/config/modules.php` add `'module-name' => ['enabled' => false]`.

**Q: Do I need to register the module in config?**
A: No. `ModuleLoader` automatically discovers all modules from `modules/*/module.json`. Config is only needed for disabling.

**Q: My module depends on another module — how?**
A: **Do not allow inter-module dependencies.** A module depends only on `core/` and `domain/`. If shared functionality is needed — extract it to core.

**Q: Can I use `$db` directly?**
A: Technically yes (via `global $db`), but architecturally correct is to use `Database` through `ServiceContainer` or Repository.

**Q: How does a module access settings?**
A: Via `SettingsManager::getAll()['my_key']`. Module settings keys are stored in the shared `settings` table.

**Q: My module is MAIN-only — what do I do?**
A: All modules are already MAIN-only by default — `modules/` is not included in `LB_DIRS`.
