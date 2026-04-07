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
    "requires_core": ">=2.0"
}
```

### Manifest fields

| Field | Type | Required | Description |
|-------|------|:---:|-------------|
| `name` | `string` | ✅ | Unique module name (matches directory name) |
| `description` | `string` | ⛔ | Short human-readable module description |
| `version` | `string` | ✅ | Semver version (`1.0.0`) |
| `requires_core` | `string` | ✅ | Minimum core version (`>=2.0`) |

> **Important:** `module.json` contains only metadata. Crons, commands, routes, events, pages — everything is defined in the module's PHP class.

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
3. Resolves class by convention: `my-module` → `MyModule` (kebab-case → PascalCase + Module)
4. Creates module instance

In web context (bootstrap.php):
- `bootAll($container, $router)` → calls `boot()`, `registerRoutes()`, `getEventSubscribers()`

> ⚠️ **Current limitation:** `ModuleLoader::bootAll()` is not yet invoked in the web front controller. Module routes are currently registered **statically** in `public/routes/admin.php`. This will be addressed in a future update. See `specs/MODULE_SYSTEM_SPEC.md` §0.3 for details.

In CLI context (console.php):
- `registerAllCommands($registry)` → calls `registerCommands()` on each module

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
