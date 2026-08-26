# Architecture Overview

## Project type

Structured PHP monolith with a modular extension layer.

- No DDD, no Hexagonal, no Clean Architecture — intentional.
- Split by context with minimal abstractions: `Controller → Service → Repository`.
- Two build artifacts from one codebase: **MAIN** (full panel) and **LB** (load balancer subset).

---

## Source tree

| Path | Role |
| ---- | ---- |
| `src/Core/` | Framework primitives: DI container, events, HTTP/router, config, auth, logging |
| `src/Domain/` | Business contexts: Stream, VOD, Line, User, Server, Security, etc. |
| `src/Infrastructure/` | External adapters: `DatabaseFactory`, cache readers, Redis, TMDb |
| `src/Streaming/` | Streaming subsystem: bootstrap, auth, delivery, balancer, protection |
| `src/Modules/` | Optional extension layer — loaded by `ModuleLoader` |
| `src/Public/` | Front controller, router, controllers, views, assets |
| `src/Cli/` | Console commands and cron entry points |
| `src/Ministra/` | Stalker Portal — in core; served at `/home/xc_vm/Ministra` |

---

## Runtime model

Dependencies flow inward — modules may use core and domain, never the reverse.

```
Public/index.php
    └── XC_Bootstrap::boot(BootContext::Admin)
            └── ServiceContainer (DI)
                    ├── EventDispatcher (PSR-14)
                    ├── ModuleLoader → loadAll() → bootAll()
                    └── Router → dispatch()
```

Domain and module classes do **not** take `$db` in their constructor. They
`use \XcVm\Infrastructure\Database\DatabaseAware` and call `self::db()`, which lazily
resolves the shared connection. `bootstrap.php::wireDomainDatabase()` sets that connection
**once** per boot (via `DatabaseAware::setDb()`) — there is no per-class wiring and no
`global $db` in the web request path.

---

## Module system

Modules are isolated directories under `src/Modules/` with a `module.json` manifest
and a class extending `BaseModule`. See [Module Authoring](module-authoring.md) for the full reference (and the linked Lifecycle / Extension Points pages).

```
src/Modules/my-module/
├── module.json          # metadata
├── MyModuleModule.php   # extends BaseModule, namespace XcVm\Module\MyModule
└── ...
```

---

## Bootstrap contexts

Four contexts control which subsystems initialize. See [Bootstrap Contexts](bootstrap-contexts.md).

| Context | Used for |
| ------- | -------- |
| `BootContext::Minimal` | Scripts needing only paths/config |
| `BootContext::Cli` | Cron jobs and CLI commands |
| `BootContext::Stream` | Streaming endpoints |
| `BootContext::Admin` | Admin/reseller panel |

---

## Build variants (MAIN vs LB)

| | MAIN | LB |
| --- | ---- | -- |
| Admin panel | ✅ | ❌ |
| Streaming | ✅ | ✅ |
| Module system | ✅ | subset |

Controlled by the `ServerEnvironment` enum and each `module.json`'s `environment` field
(`main` / `lb` / `any`). At boot, `ModuleLoader::getCurrentEnvironment()` resolves the node's
environment from the `SERVER_TYPE` constant (`'lb'` → `ServerEnvironment::LoadBalancer`, else
`ServerEnvironment::Main`); a module whose `environment` doesn't match the node is skipped, so
the LB gets a **subset** of modules.

---

## Key extension points

| Mechanism | How to use |
| --------- | ---------- |
| PSR-14 events | `EventDispatcher::listen()` / `#[ListensTo]` — see [Event System](event-system.md) |
| Service decoration | `$container->decorate('id', callable, priority)` — see [Module Extension Points](module-extension-points.md#di-container-and-service-decoration) |
| Stream middleware | Implement `StreamMiddlewareProviderInterface` — see [Module Extension Points](module-extension-points.md#stream-middleware) |
| Cron entries | `getCronEntries()` in the module class — see [Module Extension Points](module-extension-points.md#cron-task) |
| DB migrations | `MigratableInterface::getMigrations()` — see [Module Extension Points](module-extension-points.md#versioned-migrations-migratableinterface) |

---

## Contributor rules

1. Modules must not modify core files.
2. No `eval`, monkey patching, or runtime file replacement.
3. Any module can be disabled via `config/modules.php` without touching core.
4. Protected services (`db`, `settings`, `config`, `auth`) cannot be decorated.
5. Keep EN and RU docs in sync in the same commit.
