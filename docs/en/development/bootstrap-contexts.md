# Bootstrap Contexts

`XC_Bootstrap` is the single entry point for system initialization.
Each context loads only the subsystems required for its execution path.

---

## Quick Reference

| Constant | Value | Typical usage |
| --- | --- | --- |
| `CONTEXT_MINIMAL` | `minimal` | Scripts that need only paths/config |
| `CONTEXT_CLI` | `cli` | Cron jobs and CLI commands |
| `CONTEXT_STREAM` | `stream` | Streaming endpoints (`live`, `vod`, `timeshift`) |
| `CONTEXT_ADMIN` | `admin` | Admin/reseller panel |

---

## Context Details

### CONTEXT_MINIMAL

Loads constants, paths, config, logger, and error handlers.
No database connection.

Includes:

- autoloader (`autoload.php`)
- path constants (`MAIN_HOME`, `INCLUDES_PATH`, ...)
- config (`$_INFO`)
- logger (`Logger::init()`)
- error helpers (`generateError()`, `generate404()`)

Excludes: DB, Redis, sessions, translator, admin APIs.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_MINIMAL);
```

---

### CONTEXT_CLI

Used for cron and CLI tasks.
Adds database and legacy core initialization over `CONTEXT_MINIMAL`.

Includes:

- DB connection
- `LegacyInitializer`
- optional Redis (`'redis' => true`)
- optional process title (`'process' => '...'`)

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, [
    'cached' => true,
    'process' => 'xc_vm: my-job',
]);
```

---

### CONTEXT_STREAM

Lightweight context for high-load streaming endpoints.

Includes:

- DB connection (`cached=true`)
- flood protection and host verification

Excludes: Redis, translator, admin APIs, sessions.

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM, ['cached' => true]);
```

---

### CONTEXT_ADMIN

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
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
```

---

## Subsystem Matrix

| Subsystem | MINIMAL | CLI | STREAM | ADMIN |
| --- | :---: | :---: | :---: | :---: |
| Constants/paths | ✅ | ✅ | ✅ | ✅ |
| Config (`$_INFO`) | ✅ | ✅ | ✅ | ✅ |
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
XC_Bootstrap::boot(string $context, array $options = []);
```

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `cached` | `bool` | `false` for admin, `true` for stream/cli | Use cached settings |
| `redis` | `bool` | `true` for admin, `false` otherwise | Connect Redis |
| `process` | `string` | `''` | Process title for CLI |
| `shutdown` | `callable` | built-in | Override shutdown callback |

---

## Idempotency

`boot()` is executed once per process. Repeated calls are ignored.

```php
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI); // ignored
```

For tests:

```php
XC_Bootstrap::reset();
```

---

## Public Methods

```php
XC_Bootstrap::getContext(): ?string
XC_Bootstrap::isBooted(): bool
XC_Bootstrap::isCli(): bool
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```
