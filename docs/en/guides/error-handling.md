# Error Handling Model

XC_VM error handling has three layers:

- **Error codes** -- what failed (centralized registry of named error strings)
- **Error handlers** -- how the client HTTP response is produced (`generateError()`, `generate404()`)
- **Logger subsystem** -- runtime capture of PHP errors, uncaught exceptions, and fatal crashes

---

## Flow Overview

```text
Application code
  |
  +-- generateError('CODE')        // deliberate error response
  |     -> debug mode:  styled HTML page with code + description
  |     -> production:  generate404() or explicit HTTP code
  |
  +-- PHP warning / notice / error  // runtime errors
  |     -> Logger::handleError()
  |        -> maps errno to level (ERROR, WARNING, NOTICE, INFO)
  |        -> writes base64-encoded JSON to error_log.log
  |        -> optionally displays on screen
  |
  +-- Uncaught exception            // unhandled Throwable
  |     -> Logger::handleException()
  |        -> logs as EXCEPTION with full chained trace
  |
  +-- Fatal error at shutdown       // E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR
        -> Logger::handleFatal()
           -> logs as FATAL (no stack trace available)
```

---

## Error Code Registry

All codes are declared in `src/Core/Error/ErrorCodes.php` as the global `$rErrorCodes` array.

Code format:

- Key: uppercase string (example: `INVALID_CREDENTIALS`)
- Value: human-readable English description

Use centralized code definitions only. Do not hardcode error text in endpoint handlers.

### Full code list

| Code | Description |
| --- | --- |
| `API_IP_NOT_ALLOWED` | IP is not allowed to access the API. |
| `ARCHIVE_DOESNT_EXIST` | Archive files are missing for this stream ID. |
| `ASN_BLOCKED` | ASN has been blocked. |
| `BANNED` | Line has been banned. |
| `BLOCKED_USER_AGENT` | User-agent has been blocked. |
| `CACHE_INCOMPLETE` | Cache is being generated... |
| `DEVICE_NOT_ALLOWED` | MAG & Enigma devices are not allowed to access this. |
| `DISABLED` | Line has been disabled. |
| `DOWNLOAD_LIMIT_REACHED` | Reached the simultaneous download limit. |
| `E2_DEVICE_LOCK_FAILED` | Device lock checks failed. |
| `E2_DISABLED` | Device has been disabled. |
| `E2_NO_TOKEN` | No token has been specified. |
| `E2_TOKEN_DOESNT_MATCH` | Token doesn't match records. |
| `E2_WATCHDOG_TIMEOUT` | Time limit reached. |
| `EMPTY_USER_AGENT` | Empty user-agents are disallowed. |
| `EPG_DISABLED` | EPG has been disabled. |
| `EPG_FILE_MISSING` | Cached EPG files are missing. |
| `EXPIRED` | Line has expired. |
| `FORCED_COUNTRY_INVALID` | Country does not match forced country. |
| `GENERATE_PLAYLIST_FAILED` | Playlist failed to generate. |
| `HLS_DISABLED` | HLS has been disabled. |
| `HOSTING_DETECT` | Hosting server has been detected. |
| `INVALID_API_PASSWORD` | API password is invalid. |
| `INVALID_CREDENTIALS` | Username or password is invalid. |
| `INVALID_HOST` | Domain name not recognised. |
| `INVALID_STREAM_ID` | Stream ID doesn't exist. |
| `INVALID_TYPE_TOKEN` | Tokens can't be used for this stream type. |
| `IP_BLOCKED` | IP has been blocked. |
| `IP_MISMATCH` | Current IP doesn't match initial connection IP. |
| `ISP_BLOCKED` | ISP has been blocked. |
| `LB_TOKEN_INVALID` | AES Token cannot be decrypted. |
| `LEGACY_EPG_DISABLED` | Legacy epg.php access has been disabled. |
| `LEGACY_GET_DISABLED` | Legacy get.php access has been disabled. |
| `LEGACY_PANEL_API_DISABLED` | Legacy panel_api.php access has been disabled. |
| `LINE_CREATE_FAIL` | Line failed to insert into database. |
| `NO_CREDENTIALS` | No credentials have been specified. |
| `NO_SERVERS_AVAILABLE` | No servers are currently available for this stream. |
| `NO_TIMESTAMP` | No archive timestamp has been specified. |
| `NO_TOKEN_SPECIFIED` | No AES encrypted token has been specified. |
| `NOT_ENIGMA_DEVICE` | Line isn't an enigma device. |
| `NOT_IN_ALLOWED_COUNTRY` | Not in allowed country list. |
| `NOT_IN_ALLOWED_IPS` | Not in allowed IP list. |
| `NOT_IN_ALLOWED_UAS` | Not in allowed user-agent list. |
| `NOT_IN_BOUQUET` | Line doesn't have access to this stream ID. |
| `PLAYER_API_DISABLED` | Player API has been disabled. |
| `PROXY_ACCESS_DENIED` | You cannot access this stream directly while proxy is enabled. |
| `PROXY_DETECT` | Proxy has been detected. |
| `PROXY_NO_API_ACCESS` | Can't access API's via proxy. |
| `RESTREAM_DETECT` | Restreaming has been detected. |
| `STALKER_CHANNEL_MISMATCH` | Stream ID doesn't match stalker token. |
| `STALKER_DECRYPT_FAILED` | Failed to decrypt stalker token. |
| `STALKER_INVALID_KEY` | Invalid stalker key. |
| `STALKER_IP_MISMATCH` | IP doesn't match stalker token. |
| `STALKER_KEY_EXPIRED` | Stalker token has expired. |
| `STREAM_OFFLINE` | Stream is currently offline. |
| `SUBTITLE_DOESNT_EXIST` | Subtitle file doesn't exist. |
| `THUMBNAIL_DOESNT_EXIST` | Thumbnail file doesn't exist. |
| `THUMBNAILS_NOT_ENABLED` | Thumbnail not enabled for this stream. |
| `TOKEN_ERROR` | AES token has incomplete data. |
| `TOKEN_EXPIRED` | AES token has expired. |
| `TS_DISABLED` | MPEG-TS has been disabled. |
| `USER_ALREADY_CONNECTED` | Line already connected on a different IP. |
| `USER_DISALLOW_EXT` | Extension is not in allowed list. |
| `VOD_DOESNT_EXIST` | VOD file doesn't exist. |
| `WAIT_TIME_EXPIRED` | Stream start has timed out, failed to start. |

The streaming-specific codes (`CACHE_INCOMPLETE`, `SUBTITLE_DOESNT_EXIST`, `NO_SERVERS_AVAILABLE`, `PROXY_ACCESS_DENIED`) were migrated from `stream/init.php` into the centralized registry.

---

## Error Handlers

Defined in `src/Core/Error/ErrorHandler.php`. These are plain functions (not class methods) loaded early in bootstrap.

### `generateError(string $rError, bool $rKill = true, ?int $rCode = null)`

Produces an HTTP error response. Behavior depends on the `debug_show_errors` setting:

```text
if debug_show_errors === true
    render styled HTML page showing error key + description
    if $rKill -> exit()
else (production)
    if $rKill
        if $rCode is set -> http_response_code($rCode) + exit()
        else             -> generate404()
    // if !$rKill, does nothing in production mode
```

Parameters:

| Param | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$rError` | `string` | -- | Key from `$rErrorCodes` |
| `$rKill` | `bool` | `true` | Terminate script after output |
| `$rCode` | `int\|null` | `null` | Explicit HTTP response code (bypasses 404 in production) |

Examples:

```php
generateError('INVALID_CREDENTIALS');              // production: 404 + exit
generateError('API_IP_NOT_ALLOWED', true, 403);    // production: 403 + exit
generateError('STREAM_OFFLINE', false);             // production: no output, no exit
```

### `generate404(bool $rKill = true)`

Returns an nginx-style `404 Not Found` page and sets HTTP 404. The HTML includes padding comments to suppress browser-friendly error pages in MSIE and Chrome.

```php
generate404();       // 404 + exit
generate404(false);  // 404, continue execution
```

---

## Logger Subsystem

Defined in `src/Core/Logging/Logger.php`. A `final` class that registers three global PHP handlers to capture all runtime errors and log them to a file.

### Initialization

```php
Logger::init(bool $showErrors, string $logFile): void
```

Registers:

1. `set_error_handler([Logger::class, 'handleError'])` -- PHP warnings, notices, errors
2. `set_exception_handler([Logger::class, 'handleException'])` -- uncaught `Throwable`
3. `register_shutdown_function([Logger::class, 'handleFatal'])` -- fatal errors at shutdown

Also configures `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED)` and sets `display_errors` / `display_startup_errors` based on `$showErrors`.

### Where Logger::init() is called

Logger is initialized in two places, depending on the request path:

| Entry path | File | How |
| --- | --- | --- |
| Bootstrap (all contexts) | `src/bootstrap.php` | `XC_Bootstrap::loadConstants()` calls `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')` |
| Streaming endpoints | `src/Core/Http/RequestGuard.php` | Loads settings from file cache, defines `PHP_ERRORS`, then calls `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')` |

In both cases `PHP_ERRORS` mirrors the `debug_show_errors` setting (defaults to `false` when settings are unavailable).

### Error level mapping

`Logger::handleError()` maps PHP error constants to log level strings via `mapErrorLevel()`:

| PHP constant(s) | Log level |
| --- | --- |
| `E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR` | `ERROR` |
| `E_WARNING`, `E_USER_WARNING` | `WARNING` |
| `E_NOTICE`, `E_USER_NOTICE` | `NOTICE` |
| All other `errno` values | `INFO` |

The shutdown handler (`handleFatal()`) checks `error_get_last()` for these fatal types and logs them as `FATAL`:

| PHP constant(s) at shutdown | Log level |
| --- | --- |
| `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR` | `FATAL` |

Uncaught exceptions logged by `handleException()` always use the level `EXCEPTION`.

Errors suppressed with the `@` operator are ignored (the handler checks `error_reporting() & $errno`).

### Log format

Each log entry is written as a single line: `base64_encode(json_encode($data))` followed by a newline. This prevents line corruption from multi-line messages.

Decoded JSON structure:

```json
{
    "type":        "WARNING",
    "log_message": "Undefined variable $foo",
    "file":        "/home/xc_vm/Domain/Stream/StreamService.php",
    "line":        142,
    "log_extra":   "#0 /home/xc_vm/...(line): function()\n#1 ...",
    "time":        1716220800,
    "env":         "fpm-fcgi"
}
```

| Field | Content |
| --- | --- |
| `type` | Log level: `ERROR`, `WARNING`, `NOTICE`, `INFO`, `EXCEPTION`, or `FATAL` |
| `log_message` | Error/exception message text |
| `file` | Absolute path to the source file |
| `line` | Line number where the error occurred |
| `log_extra` | Stack trace (formatted string). Empty for fatal errors. |
| `time` | Unix timestamp |
| `env` | PHP SAPI name (`cli`, `fpm-fcgi`, etc.) |

### Log file location

Default path: `LOGS_TMP_PATH . 'error_log.log'`

If the log directory does not exist, Logger creates it with permissions `0775`. When running as root (common in containers), the file is chowned to `xc_vm:xc_vm` with mode `0664`.

### Screen output

When `$showErrors` is `true`, Logger also renders errors directly:

- **CLI:** color-coded terminal output (red for FATAL/ERROR, yellow for WARNING, blue for NOTICE)
- **Web:** inline `<div>` with monospace font, red border, and stack trace in a `<pre>` block

---

## Logging Pipeline: File to Database

The Logger writes to `error_log.log` on disk. A separate subsystem reads that file and persists entries to the `panel_logs` database table:

1. **Logger** writes base64-encoded JSON lines to `error_log.log`
2. **FileLogger** (`src/Core/Logging/FileLogger.php`) provides a secondary logging interface used by application code (PDO errors, EPG errors, etc.) that writes to the same file in the same format
3. Entries are ingested into the `panel_logs` table
4. **DiagnosticsService** (`src/Core/Diagnostics/DiagnosticsService.php`) reads from `panel_logs` for:
   - `downloadPanelLogs()` -- retrieves up to 1000 recent non-EPG errors, then truncates the table
   - `submitPanelLogs()` -- sends logs to the central API server for analysis
5. The admin panel exposes these logs under **Management > Logs > Panel Errors**

### FileLogger noise filtering

`FileLogger::log()` skips entries that match:

- Messages containing `panel_logs` in the extra field (prevents recursive logging)
- Messages matching `timeout exceeded`, `lock wait timeout`, or `duplicate entry` (noisy MySQL errors)

---

## Other Loggers

The `src/Core/Logging/` directory contains additional specialized loggers:

| Class | File | Purpose |
| --- | --- | --- |
| `Logger` | `Logger.php` | Global PHP error/exception/fatal handler (described above) |
| `FileLogger` | `FileLogger.php` | Application-level logging (PDO errors, EPG, etc.) to `error_log.log` |
| `DatabaseLogger` | `DatabaseLogger.php` | Client streaming request events to `client_request.log` (ingested into `client_logs` table) |
| `UpdateLogger` | `UpdateLogger.php` | System update operations to `MAIN_HOME/update.log` (plain text, not base64) |

All loggers except `UpdateLogger` implement `LoggerInterface` and write base64-encoded JSON.

---

## Exception Types in the Codebase

The codebase defines a small number of custom exception classes. All uncaught exceptions are caught by `Logger::handleException()`, which logs the full exception chain (including `getPrevious()`).

| Exception class | Base class | Location |
| --- | --- | --- |
| `DropboxException` | `Exception` | `src/Core/Storage/DropboxClient.php` |
| `M3uParser\Exception` | `\Exception` | `src/Core/Parsing/M3uParser/src/Exception.php` |
| `DataBuildingException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Parser/DataBuildingException.php` |
| `DefinitionException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Definition/DefinitionException.php` |
| `DumpingException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Dumper/DumpingException.php` |

Most application code uses generic `Exception` throws or relies on PHP's built-in error system. The Logger's exception handler accepts any `Throwable`.

---

## Debug vs Production

### Production (default: `debug_show_errors = false`)

- `generateError()` returns a generic 404 page (or the explicit HTTP code), hiding the internal failure reason
- Logger still writes all errors to `error_log.log` on disk
- `display_errors` and `display_startup_errors` are set to `'0'`
- Errors are only visible through the admin panel (Panel Errors page) or log files

### Debug (`debug_show_errors = true`)

- `generateError()` shows a styled page with the error key and mapped description
- Logger additionally renders errors on screen (color-coded CLI output or inline HTML)
- `display_errors` and `display_startup_errors` are set to `'1'`

Do not enable debug display on production nodes.

---

## Bootstrap Error Handler Registration

The error handling infrastructure is loaded early in the boot sequence:

1. `bootstrap.php` defines `MAIN_HOME` and registers the Composer autoloader
2. `XC_Bootstrap::loadConstants()` loads (in order):
   - `Core/Error/ErrorCodes.php` -- populates `$rErrorCodes`
   - `Core/Error/ErrorHandler.php` -- defines `generateError()` and `generate404()`
   - Path and config files
   - `Core/Logging/Logger.php` -- class definition
3. `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')` is called, registering the three global handlers
4. From this point forward, all PHP errors, uncaught exceptions, and fatal crashes are captured

For streaming endpoints that bypass the full bootstrap, `RequestGuard.php` performs steps 2-3 independently: it loads settings from the file cache, determines `PHP_ERRORS`, and calls `Logger::init()`.

---

## Adding a New Error Code

1. Add a new key to `src/Core/Error/ErrorCodes.php`:

```php
'MY_NEW_ERROR' => 'Human-readable description.',
```

2. Use it in code:

```php
generateError('MY_NEW_ERROR');
```

Descriptions must stay in English for consistency with the existing registry.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Error/ErrorCodes.php` | Centralized error code map (`$rErrorCodes`) |
| `src/Core/Error/ErrorHandler.php` | `generateError()` and `generate404()` functions |
| `src/Core/Logging/Logger.php` | Global PHP error, exception, and fatal handlers |
| `src/Core/Logging/LoggerInterface.php` | Logging contract interface |
| `src/Core/Logging/FileLogger.php` | Application-level file logging (PDO, EPG, etc.) |
| `src/Core/Logging/DatabaseLogger.php` | Client streaming request event logging |
| `src/Core/Logging/UpdateLogger.php` | System update operation logging |
| `src/Core/Http/RequestGuard.php` | Streaming path: flood protection, host check, Logger init |
| `src/Core/Diagnostics/DiagnosticsService.php` | Reads `panel_logs` table for admin display and API submission |
| `src/bootstrap.php` | Includes error layer and Logger in all bootstrap contexts |
