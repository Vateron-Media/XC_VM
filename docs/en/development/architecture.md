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
| `src/Core/` | Infrastructure primitives: DI container, events, HTTP, config, auth, logging |
| `src/Domain/` | Business contexts: Stream, VOD, Line, User, Server, Security, etc. |
| `src/Modules/` | Optional extension layer — loaded by `ModuleLoader` |
| `src/Public/` | Front controller, router, controllers, views, assets |
| `src/Cli/` | Console commands and cron entry points |
| `src/Ministra/` | Stalker Portal — in core; served at `/home/xc_vm/Ministra` |

---

## Runtime model

Dependencies flow inward — modules may use core and domain, never the reverse.

```
Public/index.php
    └── XC_Bootstrap::boot(BootContext::ADMIN)
            └── ServiceContainer (DI)
                    ├── EventDispatcher (PSR-14)
                    ├── ModuleLoader → loadAll() → bootAll()
                    └── Router → dispatch()
```

Domain classes receive the database via `setDb()` injection (called from
`bootstrap.php::wireDomainDatabase()`). No `global $db` in the web request path.

---

## Module system

Modules are isolated directories under `src/Modules/` with a `module.json` manifest
and a class extending `BaseModule`. See [Module System](modules.md) for the full reference.

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
| `BootContext::MINIMAL` | Scripts needing only paths/config |
| `BootContext::CLI` | Cron jobs and CLI commands |
| `BootContext::STREAM` | Streaming endpoints |
| `BootContext::ADMIN` | Admin/reseller panel |

---

## Build variants (MAIN vs LB)

| | MAIN | LB |
| --- | ---- | -- |
| Admin panel | ✅ | ❌ |
| Streaming | ✅ | ✅ |
| Module system | ✅ | subset |

Controlled by `ServerEnvironment` enum and `module.json` `environment` field (`main` / `lb` / `any`).

---

## Key extension points

| Mechanism | How to use |
| --------- | ---------- |
| PSR-14 events | `EventDispatcher::listen()` or `#[ListensTo]` attribute |
| Service decoration | `$container->decorate('id', callable, priority)` |
| Stream middleware | Implement `StreamMiddlewareProviderInterface` |
| Cron entries | Override `getCronEntries()` in module class |
| DB migrations | Implement `MigratableInterface::getMigrations()` |

---

## Contributor rules

1. Modules must not modify core files.
2. No `eval`, monkey patching, or runtime file replacement.
3. Any module can be disabled via `config/modules.php` without touching core.
4. Protected services (`db`, `settings`, `config`, `auth`) cannot be decorated.
5. Keep EN and RU docs in sync in the same commit.

## Related files

| File | Role |
| --- | --- |
| `src/Core/` | Framework primitives (DI, events, HTTP, config, auth, logging) |
| `src/Domain/` | Business contexts (Stream, VOD, Line, User, Server, Security) |
| `src/Infrastructure/` | External adapters (DatabaseFactory, CacheReader, Redis) |
| `src/Streaming/` | Streaming subsystem |
| `src/Modules/` | Optional modules (loaded by ModuleLoader) |
| `src/Public/` | Front controller, controllers, views |
| `src/Cli/` | Console commands and cron jobs |
