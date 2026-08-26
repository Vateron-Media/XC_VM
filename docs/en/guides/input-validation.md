# Input Validation and Sanitization

XC_VM uses a two-layer defense for incoming request data. First, a **global sanitization** pass strips dangerous content from all PHP superglobals during bootstrap, before any application code runs. Second, an **action-level validation** layer checks that required fields are present before business logic executes.

Both layers are implemented in `src/Core/Validation/InputValidator.php`.

---

## Global Sanitization Flow

Sanitization runs automatically during bootstrap. When `LegacyInitializer::initCore()` is called (in `src/Core/Init/LegacyInitializer.php`), it performs the following steps before any controller or service code:

```
LegacyInitializer::initCore()
  |
  +-- InputValidator::cleanGlobals($_GET)
  +-- InputValidator::cleanGlobals($_POST)
  +-- InputValidator::cleanGlobals($_SESSION)
  +-- InputValidator::cleanGlobals($_COOKIE)
  |
  +-- $input = InputValidator::parseIncomingRecursively($_GET)
  +-- RequestManager::set(InputValidator::parseIncomingRecursively($_POST, $input))
```

After this sequence, all raw superglobals have been sanitized in-place, and the merged/cleaned GET+POST data is available through `RequestManager`.

The streaming context (`LegacyInitializer::initStreaming()`) performs the same sanitization sequence using the `Request` class, which provides equivalent methods for the streaming bootstrap path.

### cleanGlobals(&$rData, $rIteration = 0)

Recursively walks the given superglobal array and removes dangerous content. Applied to `$_GET`, `$_POST`, `$_SESSION`, and `$_COOKIE`.

Strips the following from every scalar value:

| Threat | Pattern removed | Replacement |
| --- | --- | --- |
| Null byte injection | `\0` (chr 0) | removed |
| Path traversal | `../` | `&#46;&#46;/` (HTML-encoded) |
| RTL override (UI spoofing) | `&#8238;` | removed |

Recursion is capped at 10 levels to prevent stack exhaustion from deeply nested input.

### parseIncomingRecursively(&$rData, $rInput, $rIteration = 0)

Recursively walks GET and POST data, applying key and value sanitization to every leaf. For arrays, it recurses up to 20 levels deep. For scalar values, it applies `parseCleanKey()` to the key and `parseCleanValue()` to the value.

The merged result (GET first, then POST overlaid) is stored in `RequestManager` for use throughout the request lifecycle.

### parseCleanKey($rKey)

Sanitizes array keys to prevent injection through key names:

1. URL-decodes and HTML-escapes the key (`htmlspecialchars(urldecode(...))`)
2. Removes double-dot sequences (`..` -> `''`)
3. Strips `__dunder__`-style markers via regex
4. Validates against allowed character set: word characters, dots, hyphens, underscores

### parseCleanValue($rValue)

Sanitizes scalar values with multiple passes:

| Step | What it does |
| --- | --- |
| Unescape | `stripslashes()` and restore `&#032;` to space |
| Normalize newlines | Convert `\r\n`, `\n\r`, `\r` to `\n` |
| HTML comment protection | `<!--` becomes `&#60;&#33;--`, `-->` becomes `--&#62;` |
| Script tag neutralization | `<script` (case-insensitive) becomes `&#60;script` |
| Entity normalization | Fix double-encoded entities and malformed numeric entities |
| Trim | Leading/trailing whitespace removed |

---

## Action-Level Validation

### validate()

```php
InputValidator::validate(string $rAction, array $rData): bool
```

Checks that the minimum required fields are present for a given action. Returns `true` if data is acceptable, `false` if required fields are missing or malformed. Controllers should call this before forwarding data to service/repository layers.

```php
if (!InputValidator::validate($action, $data)) {
    // reject with validation error
}
```

### validateOrFail()

```php
InputValidator::validateOrFail(string $rAction, array $rData): ?array
```

Convenience wrapper around `validate()`. Returns `null` if the data is valid, or an error array if validation fails:

```php
$error = InputValidator::validateOrFail($action, $data);
if ($error !== null) {
    // $error = ['status' => STATUS_INVALID_INPUT, 'data' => $data]
    return $error;
}
```

### confirmIDs($ids)

```php
InputValidator::confirmIDs($ids)   // untyped params/return; yields a filtered array of positive int IDs
```

Filters an array to contain only positive integer IDs. Any value where `intval($id) <= 0` is dropped. Used extensively across the codebase (30+ call sites) wherever user-supplied ID lists need to be sanitized before database queries.

```php
$safeIds = InputValidator::confirmIDs($userSuppliedIds);
// [1, 42, 7] -- negative, zero, and non-numeric values removed
```

---

## Validation Action Reference

The `validate()` method uses a `switch` statement over the action name. Actions are grouped below by functional area.

### Content Management

#### Streams and Channels

| Action | Required fields | Notes |
| --- | --- | --- |
| `processStream` | `stream_display_name` OR `review` flag OR `$_FILES['m3u_file']` | Any one of the three satisfies validation |
| `processChannel` | `stream_display_name` OR `review` flag OR `$_FILES['m3u_file']` | Same rules as processStream |
| `processRadio` | `stream_display_name` OR `review` flag OR `$_FILES['m3u_file']` | Same rules as processStream |

#### Movies / VOD

| Action | Required fields | Notes |
| --- | --- | --- |
| `processMovie` | `stream_display_name` OR `review` flag OR `$_FILES['m3u_file']` | Same rules as processStream |

#### Series and Episodes

| Action | Required fields | Notes |
| --- | --- | --- |
| `processSeries` | `title` | Series name is required |
| `processEpisode` | `series` (non-empty) AND `season_num` (numeric) AND (`multi` flag OR `episode` numeric) | Complex multi-path validation |

### Organization

#### Bouquets

| Action | Required fields | Notes |
| --- | --- | --- |
| `processBouquet` | `bouquet_name` | Required scalar |
| `reorderBouquet` | `stream_order_array` | Must decode to a JSON array |
| `sortBouquets` | `bouquet_order_array` | Must decode to a JSON array |

#### Categories

| Action | Required fields | Notes |
| --- | --- | --- |
| `processCategory` | `category_name`, `category_type` | Both required |
| `orderCategories` | `categories` | Must decode to a JSON array |

#### Groups and Codes

| Action | Required fields | Notes |
| --- | --- | --- |
| `processGroup` | `group_name` | Required scalar |
| `processGroupLegacy` | `group_name` | Same as processGroup |
| `processCode` | `code` | Required scalar |
| `processPackage` | `package_name` | Required scalar |

### EPG

| Action | Required fields | Notes |
| --- | --- | --- |
| `processEPG` | `epg_name`, `epg_file` | Both required |

### Devices and Lines

| Action | Required fields | Notes |
| --- | --- | --- |
| `processMAG` | `mac` | MAC address required |
| `processEnigma` | `mac` | MAC address required |
| `setChannelOrder` | `stream_order_array` | Must decode to a JSON array |

### Profiles

| Action | Required fields | Notes |
| --- | --- | --- |
| `processProfile` | `profile_name` | Required scalar |

### Providers

| Action | Required fields | Notes |
| --- | --- | --- |
| `processProvider` | `ip`, `port`, `username`, `password`, `name` | All five required |
| `processISP` | `isp` | Required scalar |
| `processUA` | `user_agent` | Required scalar |

### Security

| Action | Required fields | Notes |
| --- | --- | --- |
| `blockIP` | `ip` | IP address required |
| `processRTMPIP` | `ip` | IP address required |

### Server Management

| Action | Required fields | Notes |
| --- | --- | --- |
| `processServer` | `server_name`, `server_ip` | Both required |
| `processProxy` | `server_name`, `server_ip` | Same as processServer |
| `installServer` | `ssh_port`, `root_password` | Both required |
| `moveStreams` | `content_type`, `source_server`, `replacement_server` | All three required |
| `replaceDNS` | `old_dns`, `new_dns` | Both required |
| `orderServers` | `server_order` | Must decode to a JSON array |

### Recordings and Watch Folders

| Action | Required fields | Notes |
| --- | --- | --- |
| `scheduleRecording` | `title`, `source_id` | Both required |
| `processWatchFolder` | `folder_type`, `selected_path`, `server_id` | All three required |

### Mass Operations (JSON Array Payloads)

All mass operations require a JSON-encoded array in the specified field. The field must decode to a valid PHP array.

| Action | JSON field |
| --- | --- |
| `massEditEpisodes` | `streams` |
| `massEditMovies` | `streams` |
| `massEditRadios` | `streams` |
| `massEditStreams` | `streams` |
| `massEditChannels` | `streams` |
| `massDeleteStreams` | `streams` |
| `massEditSeries` | `series` |
| `massDeleteSeries` | `series` |
| `massEditLines` | `users_selected` |
| `massEditUsers` | `users_selected` |
| `massEditMags` | `devices_selected` |
| `massEditEnigmas` | `devices_selected` |
| `massDeleteMovies` | `movies` |
| `massDeleteLines` | `lines` |
| `massDeleteUsers` | `users` |
| `massDeleteStations` | `radios` |
| `massDeleteMags` | `mags` |
| `massDeleteEnigmas` | `enigmas` |
| `massDeleteEpisodes` | `episodes` |

---

## Default Fallthrough Behavior

Actions not explicitly listed in the `switch` statement fall through to `return true`, meaning they always pass validation. This is intentional -- these actions either have no required fields at the gate level, or perform their own validation deeper in the business logic layer.

**Explicitly pass-through actions** (listed in the switch with `return true`):

- `processUser`
- `processLine`
- `processHMAC`
- `editAdminProfile`
- `editSettings`
- `editBackupSettings`
- `editCacheCron`
- `editPlexSettings`
- `editWatchSettings`
- `processPlexSync`
- `processLogin`
- `submitTicket`

**Implicitly pass-through actions** (not in the switch at all, caught by the default `return true`):

Any action string not matching a `case` will also return `true`. If a new action needs input gating, a case must be added explicitly.

---

## Validation Patterns Used

The `validate()` method uses a small set of patterns consistently:

| Pattern | Purpose | Example |
| --- | --- | --- |
| `!empty($rData['field'])` | Required scalar field (non-null, non-empty, non-zero) | `!empty($rData['bouquet_name'])` |
| `is_numeric($rData['field'] ?? null)` | Numeric validation with null-safe fallback | `is_numeric($rData['season_num'] ?? null)` |
| `is_array(json_decode($rData['field'] ?? '', true))` | JSON string that must decode to an array | `is_array(json_decode($rData['streams'] ?? '', true))` |
| `isset($rData['field'])` | Field presence check (value can be empty/falsy) | `isset($rData['review'])` |
| `isset($_FILES['field'])` | File upload presence check | `isset($_FILES['m3u_file'])` |
| OR conditions | Multi-path validation (any one path satisfies) | `!empty($rData['stream_display_name']) \|\| isset($rData['review']) \|\| isset($_FILES['m3u_file'])` |

---

## Adding Validation for a New Action

Add a `case` to the `switch` in `src/Core/Validation/InputValidator.php`:

```php
case 'myNewAction':
    return !empty($rData['required_field'])
        && is_numeric($rData['numeric_field'] ?? null);
```

Guidelines:

- Validate only the minimum required inputs at this layer. Keep domain-specific rules (format validation, business constraints, uniqueness checks) in the service layer.
- Use `!empty()` for required scalars, `is_numeric()` for numeric fields, and `is_array(json_decode(..., true))` for JSON array payloads.
- For actions that accept file uploads as an alternative to form fields, include `isset($_FILES['field'])` as an OR condition.
- If the action needs no gate-level validation, add it to the explicit pass-through block with `return true` so future maintainers know the omission is intentional rather than accidental.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Validation/InputValidator.php` | All sanitization and validation logic |
| `src/Core/Init/LegacyInitializer.php` | Bootstrap caller that triggers sanitization via `initCore()` |
| `src/Core/Http/RequestManager.php` | Stores the sanitized, merged GET+POST data |
| `src/Public/Controllers/` | Controllers that call `validate()` / `validateOrFail()` before business logic |
