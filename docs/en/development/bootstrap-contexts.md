# Bootstrap Contexts

`XC_Bootstrap` is the single entry point for system initialization.
Each context loads only the subsystems required for its execution path.
The context is expressed as a `BootContext` enum value.

---

## Quick Reference

| Enum case | Typical usage |
| --- | --- |
| `BootContext::MINIMAL` | Scripts that need only paths/config |
| `BootContext::CLI` | Cron jobs and CLI commands |
| `BootContext::STREAM` | Streaming endpoints (`live`, `vod`, `timeshift`) |
| `BootContext::ADMIN` | Admin/reseller panel |

---

## Context Details

### BootContext::MINIMAL

Loads constants, paths, config, logger, and error handlers.
No database connection.

Includes:

- autoloader (`autoload.php`)
- path constants (`MAIN_HOME`, `INCLUDES_PATH`, ...)
- logger (`Logger::init()`)
- error helpers (`generateError()`, `generate404()`)

Excludes: DB, Redis, sessions, translator, admin APIs.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::MINIMAL);
```

---

### BootContext::CLI

Used for cron and CLI tasks.
Adds database and legacy core initialization over `MINIMAL`.

Includes:

- DB connection
- `LegacyInitializer`
- optional Redis (`'redis' => true`)
- optional process title (`'process' => '...'`)

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::CLI, [
    'cached' => true,
    'process' => 'xc_vm: my-job',
]);
```

---

### BootContext::STREAM

Lightweight context for high-load streaming endpoints.

Includes:

- DB connection (`cached=true`)
- flood protection and host verification

Excludes: Redis, translator, admin APIs, sessions.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(BootContext::STREAM, ['cached' => true]);
```

---

### BootContext::ADMIN

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
XC_Bootstrap::boot(BootContext::ADMIN);
```

---

## Subsystem Matrix

| Subsystem | MINIMAL | CLI | STREAM | ADMIN |
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
| `cached` | `bool` | `true` for STREAM, `false` otherwise | Use cached settings |
| `redis` | `bool` | `true` for ADMIN, `false` otherwise | Connect Redis |
| `process` | `string` | `''` | Process title for CLI |
| `shutdown` | `callable` | built-in | Override shutdown callback |

---

## Idempotency

`boot()` is executed once per process. Repeated calls are ignored.

```php
XC_Bootstrap::boot(BootContext::ADMIN);
XC_Bootstrap::boot(BootContext::CLI); // ignored
```

For tests:

```php
XC_Bootstrap::reset();
```

---

## Public Methods

```php
XC_Bootstrap::getContext(): ?BootContext
XC_Bootstrap::isBooted(): bool
XC_Bootstrap::isCli(): bool
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```
