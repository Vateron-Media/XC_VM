---
description: "Use when migrating legacy code to services, replacing global variables, extracting logic from god-objects, or refactoring admin pages. Covers safe migration patterns for XC_VM."
---
# Migration Patterns — XC_VM

## Strategy
Iterative refactoring. Each step must be backward-compatible. No big-bang rewrites.

## Replacing Global Variables

### Pattern: Wrap global access in a service method

```php
// BEFORE (legacy):
global $db;
$rStreams = $db->query('SELECT * FROM streams WHERE id = ?;', $rID);

// AFTER (migrated):
// In StreamRepository:
class StreamRepository {
    public function __construct(private Database $db) {}
    public function getById(int $id): ?array {
        return $this->db->query('SELECT * FROM streams WHERE id = ?;', $id);
    }
}
```

### Migration order for globals:
1. `$db` → inject `Database` via constructor into Repository classes
2. `$rSettings` → inject via constructor or use `ConfigLoader::get()`
3. `$rUserInfo` → inject via `SessionManager` or pass as parameter
4. `$rServers` → inject via `ServerRepository`

### Safety rules:
- Do NOT remove `global $xxx` from a file until ALL usages in that file are replaced
- Mark partial migrations: `// @migration-in-progress — $rSettings still used on line 45`
- Test the file after each global removal (php -l at minimum)

## Extracting from God-Objects

### Pattern: One method group → one Service

```php
// CoreUtilities had: getStreamInfo(), updateStream(), deleteStream(), ...
// Extract to: domain/Stream/StreamService.php

// Then replace call sites:
// BEFORE: CoreUtilities::getStreamInfo($id)
// AFTER:  StreamService::getById($id)  (or instance method via DI)
```

### Extraction checklist:
1. Identify method cluster by domain (all stream-related, all user-related, etc.)
2. Create Service + Repository in `src/domain/{Context}/`
3. Move methods, preserving signatures initially
4. Update all call sites (grep for old method name)
5. Remove from god-object
6. Run `php -l` on all changed files

## Admin Page Migration

### Pattern: Extract SQL from presentation

```php
// BEFORE (in admin/streams.php):
global $db;
$rStreams = $db->query('SELECT * FROM streams;');
foreach ($rStreams as $rStream) {
    echo "<tr><td>{$rStream['name']}</td></tr>";
}

// AFTER:
// In StreamRepository: getAllStreams() method
// In admin/streams.php: $rStreams = StreamRepository::getAll();
// HTML stays in the admin file (presentation layer)
```

## Batch Migration Script Pattern

When migrating many files at once:
1. Create a migration script (not committed) that does search-replace
2. Run `grep -rn "OLD_PATTERN" src/` to count affected files before
3. Run the script
4. Run `grep -rn "OLD_PATTERN" src/` to verify count is 0
5. Run `find src/ -name "*.php" -exec php -l {} \;` for syntax verification
6. Delete the migration script
