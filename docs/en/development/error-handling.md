# Error Handling Model

XC_VM error handling has two layers:

- error codes (what failed)
- error handlers (how the client response is produced)

---

## Flow

```text
error code string
      -> generateError(code)
         -> debug_show_errors=true: debug HTML with code + description
         -> otherwise: generate404() or provided HTTP code
```

---

## Error Code Registry

All codes are declared in `src/core/Error/ErrorCodes.php` (`$rErrorCodes`).

Code format:

- key: uppercase string (example: `INVALID_CREDENTIALS`)
- value: human-readable English description

Use centralized code definitions only. Do not hardcode text in endpoint handlers.

---

## Handlers

Defined in `src/core/Error/ErrorHandler.php`.

### `generateError(string $code, bool $kill = true, ?int $httpCode = null)`

Behavior:

```text
if debug_show_errors
  render debug HTML page
else
  if httpCode is set -> send that code and exit
  else -> generate404()
```

Parameters:

| Param | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$rError` | `string` | — | key from `$rErrorCodes` |
| `$rKill` | `bool` | `true` | terminate script after output |
| `$rCode` | `int\|null` | `null` | explicit HTTP code |

Examples:

```php
generateError('INVALID_CREDENTIALS');
generateError('API_IP_NOT_ALLOWED', false, 403);
generateError('STREAM_OFFLINE', false);
```

---

### `generate404(bool $kill = true)`

Returns a nginx-like `404 Not Found` page and sets HTTP 404.

```php
generate404();       // 404 + exit
generate404(false);  // 404 only
```

---

## Debug vs Production

Production (default):

- returns generic 404 page
- hides internal failure reason

Debug (`debug_show_errors=true`):

- shows error key and mapped description

Do not enable debug display on production nodes.

---

## Adding a New Error Code

1. Add a new key to `src/core/Error/ErrorCodes.php`:

```php
'MY_NEW_ERROR' => 'Human-readable description.',
```

1. Use it in code:

```php
generateError('MY_NEW_ERROR');
```

Descriptions must stay in English for consistency with existing registry.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Error/ErrorCodes.php` | centralized error code map |
| `src/core/Error/ErrorHandler.php` | `generateError()` and `generate404()` |
| `src/bootstrap.php` | includes error layer in bootstrap |
| `src/www/constants.php` | compatibility entry point |
