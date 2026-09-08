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
| `logs.system` | `order` ≥ 50 |
| `profile` | `order` 100–980 |

Logs are a top-level `logs` tab with the sub-groups `logs.connections`,
`logs.streams`, `logs.system`, `logs.users` — attach an operational module log
under `logs.system`. Attaching a child to a parent key that does not exist
silently drops it, so keep these keys in sync with `CoreNavbarProvider`.

---

## Topbar buttons (`TopbarProviderInterface`)

The per-page **topbar** (the primary action button plus the related-tools
dropdown above a page) is assembled by `XcVm\Core\Util\Topbar`. Core pages come
from Topbar's own list; a module contributes its buttons through
`TopbarProviderInterface::registerTopbar(TopbarRegistry $registry)`, called in
the same boot phase as `registerNavbar()`. `BaseModule` ships a no-op default,
so override it only when you need topbar buttons.

A module can do **both** of these, in one `registerTopbar()`:

- **Inject buttons into an existing core page** — pass that page's key (e.g.
  `movies`); your buttons are appended after the core ones.
- **Define a brand-new page of its own** — pass a page key core does not know
  (e.g. `watch`); the whole topbar for that page comes from your module.

```php
use XcVm\Core\Module\TopbarRegistry;

public function registerTopbar(TopbarRegistry $registry): void
{
    // add($page, $label, $url = null, $permission = null, $attr = null, $order = 100)

    // A page the module owns — first entry becomes the primary button.
    $registry->add('watch', 'Add Folder', 'watch_add', 'folder_watch_add', null, 10);
    $registry->add('watch', 'Settings',   'settings_watch', 'folder_watch_settings', null, 20);
    // JS-only action: no url, carry an onClick via $attr.
    $registry->add('watch', 'Kill Running', null, 'folder_watch_settings', 'onClick="killWatchFolder();"', 40);

    // Inject a button into an existing CORE page.
    $registry->add('movies', 'Watch Folder', 'watch', 'folder_watch', null, 200);
}
```

**Page key** is `AdminHelpers::getPageName()` for the page the button appears on
— the same value Topbar matches against.

**Entry shape** mirrors core's `[url, permission, attr]`:

| Arg | Meaning |
| --- | --- |
| `$url` | Target page/URL. `null` for a JS-only action (pair with `$attr`). |
| `$permission` | `adv` sub-permission gating the button. `null` = always shown. |
| `$attr` | Raw extra attributes: `onClick="…"`, or a well-known `id="…"`. |
| `$order` | Sort order **among a page's module entries** (ascending). |

**Ordering & the primary button.** Within a page, core entries come first, then
module entries sorted by `$order`. `Topbar::items()` marks the first
permission-surviving entry as the **primary** button; the rest fall into the
dropdown. On a page the module owns outright, its lowest-`$order` entry is the
primary.

**Permission filtering.** Every entry with a non-null `$permission` is dropped
unless `Authorization::check('adv', $permission)` passes, so buttons never leak
to roles that lack the right.

**Well-known action ids** are wired generically by the shell (`footer.php`) and
gated by core, so a module only needs to emit the id:

| `id="…"` | Effect |
| --- | --- |
| `btn-export-csv` / `btn-export-json` | Report export — only rendered on a core-listed log/report page **and** with the `backups` permission. |
| `btn-clear-logs` | Clear-logs modal — the log type comes from core's `LOG_TYPES` map for the page. |

Re-registering the same `(page, label)` overrides the earlier entry
(last-wins), matching `NavbarRegistry`.

---

## Table data (`TableProviderInterface`)

A serverSide DataTable posts its `id` to the admin `./table` endpoint
(`TableController`). Core table ids are a hard-coded switch; a module serves
its OWN table id through `TableProviderInterface::registerTables(TableRegistry
$registry)` (same boot phase as the others), so the builder lives in the module
instead of core. When `./table` gets an id that is not a core case, it looks it
up in the registry. `BaseModule` ships a no-op default.

```php
use XcVm\Core\Module\TableRegistry;

public function registerTables(TableRegistry $registry): void
{
    $registry->register('watch_output', [WatchController::class, 'tableWatchOutput']);
}
```

**Handler contract** — `fn(array $return, int $start, int $limit, bool $isApi): array`:

```php
public static function tableWatchOutput(array $rReturn, int $rStart, int $rLimit, bool $rIsAPI): array
{
    global $db;                       // same access the core handlers use
    if (!Authorization::check('adv', 'folder_watch_output')) {
        return $rReturn;              // empty skeleton = no access
    }
    // …read RequestManager params, run COUNT + paged SELECT…
    $rReturn['recordsTotal']    = $rTotal;
    $rReturn['recordsFiltered'] = $rTotal;
    foreach ($rRows as $rRow) {
        // Return CLEAN, KEYED JSON — never HTML. The view renders every cell.
        $rReturn['data'][] = ['id' => (int) $rRow['id'], 'status' => (int) $rRow['status'], /* … */];
    }
    return $rReturn;                  // do NOT echo/exit — TableController encodes it
}
```

Rules:

- The handler receives the response skeleton (`recordsTotal`, `recordsFiltered`,
  `data`) and returns it populated. It must **not** `echo` or `exit` —
  `TableController` JSON-encodes the returned array.
- Return **clean, keyed JSON rows — no server-built HTML**. Status badges,
  action buttons and links are rendered client-side by the view (the same
  convention the core tables follow), which keeps presentation out of the
  controller and lets permission-dependent cells use flags emitted by the view.
- For the REST API branch (`$isApi`), reuse
  `TableController::filterRow($row, $show, $hide)` for column include/exclude.
- The DataTables ajax `d.id` in the view must match the registered id.

---

## Reseller permissions (`PermissionProviderInterface`)

The group editor's reseller sub-permission catalogue
(`XcVm\Core\Reference\PermissionReference`) is a core list. A module adds its
OWN permission keys through `PermissionProviderInterface::registerPermissions(PermissionRegistry
$registry)` (same boot phase) so the module owns the permissions it gates on,
instead of them being hard-coded in core. Keys are merged after the core list.

```php
use XcVm\Core\Module\PermissionRegistry;

public function registerPermissions(PermissionRegistry $registry): void
{
    $registry->add('folder_watch');
    $registry->add('folder_watch_output');
}
```

Each key shows in the editor with labels from the Translator — add
`permission_<key>` and `permission_<key>_text` language entries. Gate routes,
navbar and topbar items on the key exactly as with a core permission
(`Authorization::check('adv', 'folder_watch')`); enforcement reads the stored
group permissions and is unaffected by where the key is declared.

> **Owning a module table end-to-end.** A module log/data table is fully the
> module's: build its rows via `TableProviderInterface` (clean JSON), and keep
> its delete / clear / import bookkeeping in the module too — expose module
> `->api(...)` routes for row actions, and react to a core **event** (e.g.
> `VodImportedEvent`) with `#[ListensTo]` instead of core writing the table
> directly. Core must never `DELETE`/`UPDATE`/`TRUNCATE` a module-owned table
> (it may not exist once the module is uninstalled).

---

## Quick Tools (`QuickToolsProviderInterface`)

The admin Quick Tools page is a grid of one-shot maintenance buttons; each posts
its key to `post.php?action=quick_tools`, which runs the matching action. Both
the button list and the handlers are core. A module adds its OWN tool — button
**and** action — via `QuickToolsProviderInterface::registerQuickTools(QuickToolsRegistry
$registry)`.

```php
use XcVm\Core\Module\QuickToolsRegistry;

public function registerQuickTools(QuickToolsRegistry $registry): void
{
    // add($group, $key, $label, $handler)
    $registry->add('logs', 'clear_watch_logs', 'clear_watch_logs', static function (): void {
        WatchService::clearAllLogs();   // do the work; query via global $db
    });
}
```

- `$group` is an existing tab key (`streams`, `lines`, `logs`, `general`, …) —
  the tool is appended to it — or a new key, rendered as a new tab with a
  generic icon and `$group` as its label key.
- `$label` is a translation key for the button.
- `$handler` (`fn(): void`) performs the action and must **not** echo/exit —
  `post.php` emits the standard `{result:true}` success JSON after it runs.

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
