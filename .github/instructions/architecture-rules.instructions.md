---
description: "Use when creating or modifying services, repositories, controllers, modules, or any domain/core code. Enforces XC_VM architectural rules — see docs/en/development/architecture.md."
---
# Architecture Rules — XC_VM

Structured PHP monolith with a modular extension layer — intentionally **no DDD /
Hexagonal / Clean Architecture**. Split by context with minimal abstractions.
Authoritative reference: `docs/en/development/architecture.md`.

## Source tree (PSR-4, `XcVm\` → `src/`)

| Path | Role |
|------|------|
| `src/Core/` | Infrastructure primitives: DI container, events, HTTP router, config, auth, logging |
| `src/Domain/` | Business contexts: Stream, VOD, Line, User, Server, Security, … (`Controller → Service → Repository`) |
| `src/Infrastructure/` | Cross-cutting adapters (Database, TMDb client, …) |
| `src/Streaming/` | Streaming runtime — used by both MAIN and LB builds |
| `src/Public/` | Front controller, router, controllers, views, assets |
| `src/Cli/` | Console commands and cron entry points |
| `src/Modules/` | Optional extension layer (`{name}_{hash5}/`), loaded by `ModuleLoader` |
| `src/ministra/` | Stalker Portal — isolated subsystem (`BoundaryInterface`) |

## Layer pattern
Every domain context follows **Controller → Service → Repository → Database**:

```
Public/Controllers/Admin/StreamController.php  → presentation
Domain/Stream/StreamService.php                → business logic
Domain/Stream/StreamRepository.php             → data access
Infrastructure/Database/… + Core/Database/…    → infrastructure
```

## Dependency direction (inward only)

| Layer | May depend on | MUST NOT depend on |
|-------|---------------|--------------------|
| `Public/` | `Domain/` (Service + Repository), `Core/` | `Streaming/`, `Modules/` directly |
| `Domain/` | `Core/`, `Infrastructure/` | `Public/`, `Modules/` |
| `Streaming/` | `Core/` (subset), `Domain/` (read-only) | `Public/`, `Modules/` |
| `Core/` | Only other `Core/` subdirectories | Everything else |
| `Modules/` | `Domain/`, `Core/` | Other modules, `Public/`, `Streaming/` |

## Dependency injection
- `ServiceContainer` (PSR-11) is used ONLY at the composition root (bootstrap paths / module `boot()`).
- After bootstrap, collaborators are passed by constructor; the shared DB connection comes via the `DatabaseAware` trait + `self::db()`.
- No service calls `$container->get()` inside its own methods (no Service Locator).

## Module boundaries
- A module is an isolated directory `src/Modules/{name}_{hash5}/` (`{Pascal}Module extends BaseModule`, namespace `XcVm\Module\{Pascal}`).
- **Modules own their DB schema** via file migrations (`migrations/<semver>.sql` + `database.sql` master + `database_drop.sql` teardown, run by `ModuleMigrator`). Do NOT add module tables to core `src/bin/install/database.sql`.
- **Core must never touch module-owned tables directly** (a module can be uninstalled, dropping them). Instead core dispatches an event (e.g. `StreamsDeletedEvent`) and the owning module subscribes via `#[ListensTo(Event::class)]` to clean up.
- Removing/disabling a module (via `src/config/modules.php`) must NOT break the system — graceful degradation.

## Multi-build awareness
- **MAIN build:** full panel (admin + streaming + MySQL/MariaDB).
- **LoadBalancer build:** streaming subset only — admin/reseller/player and privileged CLI are stripped (`make lb`). Modules are MAIN-only. Code in `Domain/` / `Streaming/` reached by the LB must not pull admin-only dependencies.

## Decision filters
Before an architectural decision, apply:
1. Can a contributor understand it in 5 minutes? If no → simplify.
2. Does it break the streaming hot path? If yes → reject.
3. Can it be isolated as a module? If no → justify why.

If a change improves "code beauty" but raises the entry barrier → reject it.
