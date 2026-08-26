# Core Wiring & Registration

How the panel assembles itself at boot: the one service container, how it is populated, and how
modules push their routes, events, commands, cron entries and navbar items into the core registries.

This page is the **end-to-end narrative and the consume-side** of the core registries. The
author-side of each extension point is documented elsewhere and linked from
[What lives elsewhere](#what-lives-elsewhere) — this page does not repeat it.

---

## The one container

Everything hangs off a single process-wide `ServiceContainer`
(`src/Core/Container/ServiceContainer.php`), a PSR-11 `ContainerInterface` singleton obtained with
`ServiceContainer::getInstance()`. `XC_Bootstrap::boot()` creates it, populates it, and every later
consumer (`XC_Bootstrap::getContainer()`, module `boot()`, route handler resolution) reads the same
instance. Tests reset it with `ServiceContainer::resetInstance()`.

There is **no service-provider auto-discovery** for core services: the canonical set is registered
imperatively by the bootstrap (see below), and modules add their own services inside their
`boot()`. What you see registered is exactly what the code `set()`s — nothing is wired by
convention or annotation scanning.

---

## Container population at boot

This is the spine of the system. `XC_Bootstrap::boot()` registers services in two phases.

**Early (in `boot()` itself), before any subsystem loads:**

| Key | Value | Source |
| --- | --- | --- |
| `context` | the active `BootContext`'s string value | `src/bootstrap.php` |
| `options` | the `$options` array passed to `boot()` | `src/bootstrap.php` |
| `config` | `ConfigReader::getAll()` (parsed `config.ini`) | `src/bootstrap.php` |

**Canonical service set — `XC_Bootstrap::populateContainer()`** (runs for every context except
`Minimal`, i.e. once the DB is up):

| Key | Value | Notes |
| --- | --- | --- |
| `db` | the `Database` handle | protected (see below) |
| `settings` | `SettingsManager::getAll()` | protected |
| `servers` | `ServerRepository::getAll()` | |
| `bouquets` | `BouquetService::getAll()` | |
| `categories` | `CategoryService::getFromDatabase()` | |
| `redis` | `RedisManager::instance()` | only when Redis was booted for this context |
| `translator` | `Translator::class` | |
| `events` | a fresh `EventDispatcher` instance | also bridged to the static facade (see [Events](#events)) |

Immediately after, **`XC_Bootstrap::assertContainerHealth()`** hard-requires that `events` is
present (plus `db`/`redis` when those were booted) and throws if not — a **fail-loud** guarantee
that later code can assume these services exist rather than null-checking each one.

> The `context`/`config`/`settings`/`servers`/`bouquets`/`categories` entries are plain data
> snapshots taken at boot, not lazy factories — they are read, not recomputed, for the life of the
> request.

---

## ServiceContainer reference

All setters are chainable (`return $this`).

| Method | Purpose |
| --- | --- |
| `set(id, value)` | Register a service. A **`Closure`** value becomes a **lazy singleton factory** (called once on first `get()`, then cached); anything else — a scalar, an object, or a `[Class, 'method']` array — is stored as a ready value. |
| `factory(id, callable)` | Register a factory that returns a **new instance on every `get()`** (no caching). |
| `register(array)` | Bulk `set()` from an `id => value` map. |
| `get(id)` | Resolve a service. Returns the cached singleton if present; otherwise runs the factory once, caches it, and applies decorators. Guards against cyclic factories and throws `CircularDependencyException`; throws `NotFoundException` for an unknown id. |
| `getOrDefault(id, default)` | Like `get()` but returns `default` instead of throwing when absent. |
| `has(id)` / `keys()` / `remove(id)` / `dump()` | Introspection and teardown. |
| `decorate(id, decorator, priority)` | Wrap an existing service, highest priority applied last. **Forbidden** on the protected services `['db', 'settings', 'config', 'auth']` — decorating one throws. See [Module Extension Points](module-extension-points.md). |
| `tag(id, tag)` / `getTagged(tag)` | Group services under a label for batch retrieval. |

> **Tags are currently unused by core wiring.** `tag()`/`getTagged()` exist, but the boot path
> collects module contributions by `instanceof` checks over the loaded-module list (see
> [`bootAll`](#moduleloaderbootall-the-orchestrator)), **not** by tag. A source docblock still
> describes tags as the collection mechanism for subscribers/cron/routes — that describes an
> intended design, not the current code. Don't rely on tag-based collection until it is actually
> implemented.

Resolving a `[Class, 'method']` handler (used by the Router) goes **through the container**, so
handler classes are DI-resolved when registered, falling back to `new` when not.

---

## `ModuleLoader::bootAll` — the orchestrator

After `ModuleLoader::loadAll()` has discovered, filtered and **topologically sorted** the modules
(see [Module Lifecycle](module-lifecycle.md)), `bootAll()` is the single place that pushes each
module's contributions into the core registries. It checks each **sub-interface** with `instanceof`
so a module implements only the hooks it needs (`ModuleInterface` is a composite of them).

Per-module order inside `bootAll(ServiceContainer $container, ?Router $router, ?StreamPipeline $pipeline)`:

1. **Core navbar first, once** — `(new CoreNavbarProvider())->registerNavbar(...)` before any module, so core menu nodes exist as parents.
2. `ServiceProviderInterface` → `boot($container)` **then** `registerEventSubscribers()` — services are registered before that module's listeners are wired.
3. `StreamMiddlewareProviderInterface` → `registerStreamMiddleware($pipeline)` — **only if a `$pipeline` was passed**.
4. `RouteProviderInterface` → `registerRoutes($router)` — **only if a `$router` was passed** (`$router !== null`).
5. `NavbarProviderInterface` → `registerNavbar(...)`.

Two contributions are **separate passes, not part of `bootAll`**:

- `registerAllCommands($registry)` — CLI commands, each module wrapped in try/catch so one broken module can't brick the whole CLI.
- `collectCronEntries()` — crontab lines gathered from `CronProviderInterface::getCronEntries()`, consumed by the startup/status commands.

Because routes and stream-middleware are gated on the optional `$router`/`$pipeline` arguments,
**the same `bootAll()` does different work depending on the entry point** — the CLI passes neither,
so only services + event subscribers (and the harmless navbar) are wired there.

---

## Events

`EventDispatcher` (`src/Core/Events/EventDispatcher.php`) is a singleton with a static facade.
`populateContainer()` does `new EventDispatcher()` → `EventDispatcher::setInstance($d)` →
`$container->set('events', $d)`, so the container entry `events` and the static
`EventDispatcher::dispatch()/listen()` share **one** listener store. Modules register listeners
during step 2 of `bootAll` above, via `getEventSubscribers()` or the `#[ListensTo]` attribute.

Full details — registration forms, priorities, stoppable events, the built-in event catalogue — are
in [Event System](event-system.md); this page only covers *where in the boot sequence* listeners
get wired.

---

## CLI command registration

The CLI path (`src/console.php`) builds its command set in two steps:

1. **Core auto-discovery.** `new CommandRegistry()`, then glob `Cli/Commands/*.php` and
   `Cli/CronJobs/*.php`, map each directory to its namespace, and via Reflection `register()` every
   **non-abstract** class implementing `CommandInterface`. Adding a core command is therefore just
   dropping a class in one of those directories — no manual registration.
2. **Module commands.** `ModuleLoader::registerAllCommands($registry)` calls each
   `CommandProviderInterface::registerCommands()`.

`CommandRegistry` (`src/Cli/CommandRegistry.php`) is a plain `name → CommandInterface` map:
`register()`, `dispatch($argv)` (handles `--list`/`--help`, groups help by the `group:` prefix in a
command's name), `get($name)`, `getAll()`.

---

## End-to-end boot — Admin (web)

`src/Public/index.php`:

1. `XC_Bootstrap::boot(BootContext::Admin)` — creates the container; sets `context`/`options`/`config`; loads constants; runs flood/host checks; `bootAdmin()` (session, DB, `LegacyInitializer`, Redis, admin/reseller API, translator, shutdown handler, status constants); **`populateContainer()`**; **`assertContainerHealth()`**.
2. `Router::getInstance()`, then `require` the core route files `routes/{scope}.php` (+ `routes/api.php`).
3. Module boot block: `router->beginModuleRegistration()` → `new ModuleLoader; loadAll(); bootAll($container, $router)` → `router->endModuleRegistration()` → `drainRouteCollisions()`. Module-registration mode makes **core routes win** over any module route with the same path; collisions are captured, not silently overwritten.
4. `Router::dispatch()` / `dispatchApi()` handles the request, resolving `[Class, 'method']` handlers through the container.

See [Bootstrap Contexts](bootstrap-contexts.md) for exactly which subsystems each context initialises, and [HTTP Request Handling](http-request-handling.md) for the Router API and dispatch.

---

## End-to-end boot — CLI

`src/console.php`:

1. `require bootstrap.php`; `XC_Bootstrap::boot(BootContext::Cli)` — DB, `LegacyInitializer`, optional Redis, process title, then the **same** `populateContainer()` (so `events` and friends exist in CLI too).
2. `new CommandRegistry()`; auto-discover core `Cli/Commands` + `Cli/CronJobs` (glob + Reflection) → `register()`.
3. `new ModuleLoader; loadAll(); registerAllCommands($registry)` (module commands, **before** `bootAll`), then `bootAll(getContainer())` **with no router and no pipeline** — so routes and stream-middleware are skipped; only module services + event subscribers wire up.
4. `registry->dispatch($argv)` runs the requested command.

---

## What lives elsewhere

To avoid duplication, the author-side and per-subsystem detail live on their own pages:

| Topic | Page |
| --- | --- |
| Which subsystems each context initialises; `boot()` options; idempotency | [Bootstrap Contexts](bootstrap-contexts.md) |
| Event registration forms, priorities, stoppable events, event catalogue | [Event System](event-system.md) |
| Router API, `begin/endModuleRegistration`, dispatch, handler resolution | [HTTP Request Handling](http-request-handling.md) |
| Navbar item builder, visibility rules, rendering | [Navbar Rendering](navbar-rendering.md) |
| Module discovery, env filtering, topo-sort, enable/disable, install/update | [Module Lifecycle](module-lifecycle.md) |
| DI decoration, stream middleware, cron, migrations — the module author's hooks | [Module Extension Points](module-extension-points.md) |
| Writing a module (manifest, class contract, directory layout) | [Module Authoring](module-authoring.md) |

## Related files

| File | Role |
| --- | --- |
| `src/Core/Container/ServiceContainer.php` | The DI container: `set`/`factory`/`get`/`decorate`/`tag` |
| `src/bootstrap.php` | `XC_Bootstrap::boot`, `populateContainer`, `assertContainerHealth` |
| `src/Core/Module/ModuleLoader.php` | `bootAll`, `registerAllCommands`, `collectCronEntries` |
| `src/Core/Events/EventDispatcher.php` | Event dispatcher + static facade bridged into `events` |
| `src/Cli/CommandRegistry.php` | CLI command map + `dispatch` |
| `src/console.php` | CLI entry point: core command auto-discovery + module boot |
| `src/Public/index.php` | Web entry point: route files + module boot block |
