---
description: "Use when creating or modifying services, repositories, controllers, modules, or any domain/core code. Enforces XC_VM architectural rules from ARCHITECTURE.md."
---
# Architecture Rules — XC_VM

## Layer Pattern
Every domain context follows: **Controller → Service → Repository → Database**

```
public/Controllers/Admin/StreamController.php  → presentation
domain/Stream/StreamService.php                → business logic
domain/Stream/StreamRepository.php             → data access
core/Database/Database.php                     → infrastructure
```

## Dependency Direction (strict)

| Layer | Can depend on | CANNOT depend on |
|-------|---------------|------------------|
| `public/` | `domain/` (Service+Repository), `core/` | `streaming/`, `modules/` directly |
| `domain/` | `core/` (Database, Cache, Events) | `public/`, `streaming/`, `modules/`, `infrastructure/` |
| `core/` | Only other `core/` subdirectories | Everything else |
| `streaming/` | `core/` (subset), `domain/` (read-only) | `public/`, `modules/` |
| `modules/` | `domain/`, `core/` | Other modules, `public/`, `streaming/` |

## Constructor Injection
- `ServiceContainer` is used ONLY in bootstrap (composition root)
- After bootstrap, all dependencies are passed via constructor
- No service may call `$container->get()` inside its methods
- Legacy exceptions marked with `// @legacy-container`

## File Consolidation Rules
- Do NOT create separate file if class has < 150 lines AND < 5 public methods
- Small Repository + Service in same context → combine in one file
- Service with 1-2 methods → merge into nearest related context
- Separate files only when class > 150 lines OR ≥ 5 public methods OR used from 3+ contexts

## Module Boundaries
- Module = isolated directory under `src/modules/`
- Removing a module must NOT break the system (graceful degradation)
- Modules depend on `domain/` and `core/`, never on other modules
- Core never depends on modules

## Decision Filters (from ARCHITECTURE.md)
Before any architectural decision, apply:
1. Can a contributor understand it in 5 minutes? → if no, simplify
2. Does it break the streaming hot path? → if yes, reject
3. Can it be isolated as a module? → if no, justify why

If a decision improves "code beauty" but raises the entry barrier → reject it.

## Multi-Build Awareness
- **MAIN build**: full admin + streaming
- **LoadBalancer build**: streaming subset only, admin dirs stripped
- Code in `domain/` used by streaming must NOT pull admin-only dependencies
