# Input Validation Strategy

XC_VM centralizes request pre-validation in `InputValidator`.
It performs required-field checks before business logic execution.

---

## Core Contract

```php
InputValidator::validate(string $action, array $data): bool
```

If validation returns `false`, the action should be rejected before service/repository calls.

```php
if (!InputValidator::validate($action, $data)) {
    // return validation error
}
```

---

## Validation Patterns

From `src/core/Validation/InputValidator.php`, current checks are mostly:

- required scalar fields via `!empty(...)`
- numeric checks via `is_numeric(...)`
- JSON array checks via `is_array(json_decode(..., true))`

Examples:

| Action | Required fields |
| --- | --- |
| `scheduleRecording` | `title`, `source_id` |
| `processProvider` | `ip`, `port`, `username`, `password`, `name` |
| `processCategory` | `category_name`, `category_type` |
| `processServer` / `processProxy` | `server_name`, `server_ip` |
| `processMAG` / `processEnigma` | `mac` |
| `processUA` | `user_agent` |
| `processEPG` | `epg_name`, `epg_file` |
| `processWatchFolder` | `folder_type`, `selected_path`, `server_id` |

JSON-array actions (representative):

| Action | JSON field |
| --- | --- |
| `massEditStreams` / `massDeleteStreams` | `streams` |
| `massDeleteMovies` | `movies` |
| `massDeleteLines` | `lines` |
| `massDeleteUsers` | `users` |
| `massDeleteEpisodes` | `episodes` |
| `massEditLines` / `massEditUsers` | `users_selected` |
| `massEditMags` / `massEditEnigmas` | `devices_selected` |
| `orderCategories` | `categories` |
| `orderServers` | `server_order` |

---

## Actions with Minimal Gate

Some actions intentionally pass this layer and are validated deeper in business logic:

- `processUser`
- `processLine`
- `processHMAC`
- `editAdminProfile`

---

## Adding Validation for a New Action

Add a `case` in `src/core/Validation/InputValidator.php`:

```php
case 'myNewAction':
    return !empty($rData['required_one'])
        && is_numeric($rData['required_two'] ?? null);
```

Rules:

- validate only required minimum inputs here
- keep domain rules in service layer
- use JSON decode checks for list payloads

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Validation/InputValidator.php` | centralized action validation |
| `src/public/Controllers/` | callers that execute pre-validation |
