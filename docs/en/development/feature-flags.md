# Development Feature Flags

XC_VM uses constants and settings-driven flags to control environment behavior.

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

---

## Settings-driven Flags (`$rSettings`)

Loaded from settings cache and used in runtime decision points.

| Key | Type | Meaning |
| --- | --- | --- |
| `debug_show_errors` | `bool` | show detailed errors/debug output |
| `recaptcha_enable` | `bool` | enable reCAPTCHA v2 on login |
| `verify_host` | `bool` | enforce host allowlist validation |
| `save_login_logs` | `bool` | persist login attempts in `login_logs` |

These values are loaded from `CACHE_TMP_PATH/settings` by request guards.

---

## Static App Constants

From `src/core/Config/AppConfig.php`:

```php
define('XC_VM_VERSION', '2.1.1');
define('GIT_OWNER', 'Vateron-Media');
define('GIT_REPO_MAIN', 'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN', 'XC_VM_Binaries');
define('MONITOR_CALLS', 3);
define('OPENSSL_EXTRA', '...');
```

---

## Adding New Flags

Use static constants in `AppConfig.php` for fixed infrastructure/runtime constants.
Use settings (`$rSettings`) for values that must be managed from panel UI.

Avoid defining the same behavior in both places.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Config/AppConfig.php` | static app constants |
| `src/core/Http/RequestGuard.php` | loads `$rSettings`, sets `PHP_ERRORS` |
| `src/core/Error/ErrorHandler.php` | uses `debug_show_errors` behavior |
| `src/core/Logging/Logger.php` | debug/verbosity behavior |
