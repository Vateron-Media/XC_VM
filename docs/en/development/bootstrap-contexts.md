# Bootstrap Contexts

`XC_Bootstrap` is the single entry point for system initialization.
Each context loads only the subsystems required for its execution path.
The context is expressed as a `BootContext` enum value.

> `boot()` accepts `string|BootContext`, so the legacy `XC_Bootstrap::CONTEXT_*` string
> constants (`CONTEXT_ADMIN`, `CONTEXT_CLI`, …) still work but are **`@deprecated`** aliases —
> new code should pass the enum case (`BootContext::Admin`). Enum cases are **PascalCase**
> (`Minimal`, `Cli`, `Stream`, `Admin`), not upper-case.

---

## Quick Reference

| Enum case | Typical usage |
| --- | --- |
| `BootContext::Minimal` | Scripts that need only paths/config |
| `BootContext::Cli` | Cron jobs and CLI commands |
| `BootContext::Stream` | Streaming endpoints (`live`, `vod`, `timeshift`) |
| `BootContext::Admin` | Admin/reseller panel |

---

## Context Details

### BootContext::Minimal

Loads constants, paths, config, logger, and error handlers.
No database connection.

Includes:

- Composer PSR-4 autoloader (`vendor/autoload.php`)
- path constants (`MAIN_HOME`, `INCLUDES_PATH`, ...)
- logger (`Logger::init()`)
- error helpers (`generateError()`, `generate404()`)

Excludes: DB, Redis, sessions, translator, admin APIs.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Minimal);
```

---

### BootContext::Cli

Used for cron and CLI tasks.
Adds database and legacy core initialization over `MINIMAL`.

Includes:

- DB connection
- `LegacyInitializer`
- optional Redis (`'redis' => true`)
- optional process title (`'process' => '...'`)

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Cli, [
    'cached' => true,
    'process' => 'xc_vm: my-job',
]);
```

---

### BootContext::Stream

Lightweight context for high-load streaming endpoints.

Includes:

- DB connection (`cached=true`)
- flood protection and host verification

Excludes: Redis, translator, admin APIs, sessions.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Stream, ['cached' => true]);
```

---

### BootContext::Admin

Full initialization for admin/reseller panel.

Includes:

- secure session (`SameSite=Strict`)
- DB connection (`cached=false`)
- `LegacyInitializer`
- Redis
- admin/reseller APIs
- translator
- shutdown handler
- status constants and admin globals

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::Admin);
```

---

## Subsystem Matrix

| Subsystem | Minimal | Cli | Stream | Admin |
| --- | :---: | :---: | :---: | :---: |
| Constants/paths | ✅ | ✅ | ✅ | ✅ |
| Logger | ✅ | ✅ | ✅ | ✅ |
| Flood protection | — | — | ✅ | ✅ |
| Host verification | — | — | ✅ | ✅ |
| Database | — | ✅ | ✅ | ✅ |
| LegacyInitializer | — | ✅ | — | ✅ |
| Redis | — | opt | — | ✅ |
| Session | — | — | — | ✅ |
| Admin API | — | — | — | ✅ |
| Translator | — | — | — | ✅ |

---

## `boot()` options

```php
XC_Bootstrap::boot(BootContext $context, array $options = []);
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `cached` | `bool` | `true` for Stream, `false` otherwise | Use cached settings |
| `redis` | `bool` | `true` for Admin, `false` otherwise | Connect Redis |
| `process` | `string` | `''` | Process title for CLI |
| `shutdown` | `callable` | built-in | Override shutdown callback |

---

## Idempotency

`boot()` is executed once per process. Repeated calls are ignored.

```php
XC_Bootstrap::boot(BootContext::Admin);
XC_Bootstrap::boot(BootContext::Cli); // ignored
```

For tests:

```php
XC_Bootstrap::reset();
```

---

## Public Methods

```php
XC_Bootstrap::getContext(): ?string          // active context's string value, e.g. 'admin' (null before boot)
XC_Bootstrap::isBooted(): bool
XC_Bootstrap::isCli(): bool                   // true when the active context is Cli
XC_Bootstrap::isDevMode(): bool               // DEV_MODE flag (see Feature Flags)
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```

## Related files

| File | Role |
| --- | --- |
| `src/bootstrap.php` | Defines MAIN_HOME, requires the Composer autoloader, boots a context |
| `src/Core/Enum/BootContext.php` | Boot context enum |
| `src/Core/Init/LegacyInitializer.php` | Per-context legacy initialization |
