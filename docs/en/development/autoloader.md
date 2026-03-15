# Autoloader — Class Registration

XC_VM uses a custom autoloader (`src/autoload.php`) instead of Composer.
Classes are discovered **automatically** — no manual registration required.

---

## How It Works

```
Request → autoload.php included
              │
              ├── Cache file exists?
              │       YES → load igbinary cache → O(1) lookups
              │       NO  → warmCache(): scan all directories via token_get_all()
              │                          → build ClassName → filePath map
              │                          → persist to tmp/cache/autoload_map
              │
              └── Class requested by PHP
                      │
                      ├── 1. Explicit classMap (addClass())
                      ├── 2. Resolved cache (from file or previous lookup)
                      └── 3. Live directory scan (fallback, cached for next time)
```

## Adding a New Class

**Just create the file.** That's it.

Place your PHP file in any registered directory (or its subdirectory):

| Directory | Purpose |
|-----------|---------|
| `src/core/` | Framework core (Database, Cache, Auth, Http, Process, etc.) |
| `src/domain/` | Business logic (Services, Repositories) |
| `src/infrastructure/` | External adapters (DatabaseFactory, CacheReader, Redis) |
| `src/streaming/` | Streaming subsystem (Auth, Delivery, Codec, Health) |
| `src/modules/` | Optional modules (Plex, Watch, TMDB, Ministra, etc.) |
| `src/public/` | Controllers and Views |
| `src/includes/` | Legacy code |
| `src/includes/libs/` | Third-party libraries |

### Example

```php
// src/domain/Billing/InvoiceService.php
class InvoiceService {
    public static function generate($userId) { ... }
}
```

After creating the file, **delete the cache** so the autoloader rediscovers classes:

```bash
rm -f /home/xc_vm/tmp/cache/autoload_map
```

On the next request, `warmCache()` runs automatically, finds `InvoiceService`, and caches it.

## Cache Invalidation

The cache file `tmp/cache/autoload_map` is a binary file (igbinary format).
It must be deleted whenever:

- A new class file is added
- A class file is renamed or moved
- A class file is deleted

```bash
# Delete manually
rm -f /home/xc_vm/tmp/cache/autoload_map

# Or via PHP
XC_Autoloader::clearCache();
```

> **Note:** If a class is requested that isn't in the cache, the autoloader falls back to a live directory scan and caches the result automatically. So the cache only needs to be cleared when files are moved/renamed/deleted — new classes will eventually be found via fallback.

## Manual Registration (Rare)

For special cases where a file contains multiple classes or the filename doesn't match the class name:

```php
XC_Autoloader::addClass('DropboxClient', '/home/xc_vm/includes/libs/Dropbox.php');
XC_Autoloader::addClass('DropboxException', '/home/xc_vm/includes/libs/Dropbox.php');
```

This is mainly needed for legacy library files that bundle multiple classes in one file (e.g., `iptables.php`, `m3u.php`).

## Adding a New Source Directory

Edit `registerDirectories()` in `src/autoload.php`:

```php
private static function registerDirectories() {
    $base = self::$basePath;

    self::addDirectory($base . 'includes');
    self::addDirectory($base . 'includes/libs');
    self::addDirectory($base . 'core');
    self::addDirectory($base . 'domain');
    self::addDirectory($base . 'infrastructure');
    self::addDirectory($base . 'streaming');
    self::addDirectory($base . 'modules');
    self::addDirectory($base . 'public');

    // Add your new directory here:
    self::addDirectory($base . 'my_new_dir');
}
```

Then delete the cache.

## Naming Rules

| Rule | Example |
|------|---------|
| File name **must** match class name | `InvoiceService.php` → `class InvoiceService` |
| One class per file (recommended) | Multi-class files need `addClass()` |
| PascalCase | `StreamService`, `DatabaseHandler` |
| No namespaces | `class StreamService { }` — no `namespace` keyword |
| No `declare(strict_types=1)` | Project convention |

## Duplicate Class Names

If two files define the same class name, **first-found wins** based on directory scan order. This is fragile — avoid duplicate names. Use prefixed names instead:

```
✗  public/Controllers/Admin/PlexController.php    ← conflict
✗  modules/plex/PlexController.php                ← conflict

✓  public/Controllers/Admin/AdminPlexController.php   ← unique
✓  modules/plex/PlexController.php                    ← unique
```

## Debugging

```php
// See all registered directories
print_r(XC_Autoloader::getDirectories());

// See explicit class map
print_r(XC_Autoloader::getClassMap());

// Force full rescan
XC_Autoloader::clearCache();
XC_Autoloader::warmCache();
```
