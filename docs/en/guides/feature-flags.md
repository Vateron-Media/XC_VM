# Development Feature Flags

XC_VM uses constants and settings-driven flags to control environment behavior.

Application constants are defined by the `appConfig()` map in `src/Core/Config/ConstantsInitializer.php`.

---

## Active Runtime Flag

### `PHP_ERRORS`

```php
define('PHP_ERRORS', $rShowErrors); // derived from $rSettings['debug_show_errors']
```

`PHP_ERRORS` controls PHP/debug verbosity and logger screen output:

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

### `DB_ACCESS_ENABLED`

```php
define('DB_ACCESS_ENABLED', false); // enables phpMiniAdmin tab/page in admin panel
```

`DB_ACCESS_ENABLED` controls access to phpMiniAdmin from admin UI only.
It does not block core application database connections. Its companion `DB_ACCESS_PWD`
(also in the `appConfig()` map) sets the password protecting that page — leave it empty to keep the
tab off, set a strong value only while you need it.

### `DEV_MODE`

```php
define('DEV_MODE', false); // master development-mode flag
```

`DEV_MODE` is the compile-time development switch (`bootstrap.php` reads it into `self::$devMode`).
When `true` it forces `PHP_ERRORS` on (verbose on-screen errors), surfaces reCAPTCHA diagnostics,
and enables other developer conveniences.

> ⚠️ **Never enable `DEV_MODE` or `debug_show_errors` on production** — both expose internal
> errors/paths to visitors. `PHP_ERRORS` ends up `true` if **either** the `DEV_MODE` constant is
> set (bootstrap path) **or** the `debug_show_errors` setting is on (request-guard path); that is
> the resolution rule when the two overlap.

---

## Settings-driven Flags (`$rSettings`)

Loaded from settings cache and used in runtime decision points.

| Key | Type | Meaning |
| --- | --- | --- |
| `debug_show_errors` | `bool` | show detailed errors/debug output |
| `recaptcha_enable` | `bool` | enable reCAPTCHA v2 on login |
| `verify_host` | `bool` | enforce host allowlist validation (default on): a request's host name must be a server's `domain_name` or IP, or an active reseller's `reseller_dns`; an IP address always passes, so an admin locked out by a host name can still sign in by IP and switch it off |
| `save_login_logs` | `bool` | persist login attempts in `login_logs` |
| `fanout_enabled` | `bool` | master switch for the xc_fanout daemon (default on); off stops it on every node and live delivery uses the pre-fanout paths (see streaming-subsystem, "Switching fanout off") |
| `fanout_supervise` | `bool` | hand live streams to the xc_fanout supervisor instead of a PHP monitor (default on) |
| `fanout_source_backend` | `auto` / `ffmpeg` / `native` | how sources become MPEG-TS; with supervision, whether copy-only streams run the native remuxer (`auto`: with ffmpeg fallback) |
| `gateway_mode` | `off` / `shadow` / `segments` / `segments+playlist` | the segment gateway in xc_fanout answers HLS segments, keys and (with `segments+playlist`) playlist refreshes without PHP-FPM; `shadow` only judges a mirrored copy (see streaming-subsystem, "Segment gateway") |

These values are loaded from `CACHE_TMP_PATH/settings` by request guards.

---

## Static App Constants

From the `appConfig()` map in `src/Core/Config/ConstantsInitializer.php` (each entry is
`define()`'d by `ConstantsInitializer::init()`):

```php
'DB_ACCESS_ENABLED' => false,
'DB_ACCESS_PWD'     => '',       // password for the phpMiniAdmin tab (empty = off)
'DEV_MODE'          => false,    // master development-mode switch
'XC_VM_VERSION'     => '2.4.1',  // bumped every release — treat as illustrative
'GIT_OWNER'         => 'Vateron-Media',
'GIT_REPO_MAIN'     => 'XC_VM',
'GIT_REPO_UPDATE'   => 'XC_VM_Update',
'GIT_REPO_BIN'      => 'XC_VM_Binaries',
'GIT_REPO_FANOUT'   => 'XC_VM_Fanout', // xc_fanout daemon source + binaries
'GIT_REPO_PROXY'    => 'XC_VM_Proxy',
'MONITOR_CALLS'     => 3,
'OPENSSL_EXTRA'     => '...',    // literal fallback; the real per-install secret is read
                                 // from the config/openssl_extra file by init()
```

---

## Adding New Flags

Use static constants in the `appConfig()` map (`ConstantsInitializer.php`) for fixed infrastructure/runtime constants (edited in
code, take effect on next request). Use settings (`$rSettings`) for values an operator toggles
from the admin panel (**Settings** page) — those are persisted in the `settings` DB table and
read from `CACHE_TMP_PATH/settings`.

Avoid defining the same behavior in both places. When they unavoidably overlap (as with
`DEV_MODE` vs `debug_show_errors` → `PHP_ERRORS`), the effective value is the **OR** of the two —
either turning it on wins.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Config/ConstantsInitializer.php` | static app constants (`appConfig()` map) |
| `src/Core/Http/RequestGuard.php` | loads `$rSettings`, sets `PHP_ERRORS` |
| `src/Core/Error/ErrorHandler.php` | uses `debug_show_errors` behavior |
| `src/Core/Logging/Logger.php` | debug/verbosity behavior |
