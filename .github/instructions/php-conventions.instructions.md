---
applyTo: "**/*.php"
---
# PHP Conventions — XC_VM

XC_VM is a PHP 8.1+ modular monolith **migrated to Composer PSR-4** (`XcVm\` → `src/`).
The application root is `src/` (composer.json, vendor/, bootstrap.php all live there).
`short_open_tag=1` is on — view templates use `<?` / `<?=`.

## Formatting
- **Brace style:** K&R (opening brace on the same line)
- **Indentation:** tabs (1 tab per level), NOT spaces
- **No trailing whitespace**

## Autoloading & dependencies
- **Composer PSR-4:** `XcVm\` maps to `src/` (e.g. `XcVm\Core\Database\DatabaseHandler` = `src/Core/Database/DatabaseHandler.php`). Modules load via their own namespace autoloader, not the Composer PSR-4 map.
- **`vendor/` is committed and PRODUCTION-ONLY.** Never run `composer install` on a deploy path. To change the autoload map, run `composer dump-autoload` from `src/`. After changing deps, re-commit a `composer install --no-dev` vendor and `composer.lock`.
- **Don't add dependencies casually** — a new Composer package means a lockfile update and a production-only vendor recommit. Prefer the standard library or existing vendored libs.

## Namespaces & strict types (new code)
- **New PHP files** declare a namespace under `XcVm\…` matching their path, and start with `declare(strict_types=1)`.
- **Legacy first-party files** that are not yet namespaced (roughly half the tree) still exist. Do NOT mass-migrate them: only add a namespace / `strict_types` when you are already rewriting that file for another reason. Match the surrounding file when editing.
- Modules are namespaced `XcVm\Module\{Pascal}` with the class `{Pascal}Module` in `src/Modules/{name}_{hash5}/`.

## Database access
- **New code** uses the `DatabaseAware` trait: `use \XcVm\Infrastructure\Database\DatabaseAware;` then call `self::db()` (it resolves the connection from the `DatabaseFactory` singleton). Do NOT reintroduce per-class `setDb()` wiring.
- `Core/Database/DatabaseHandler` is the PDO wrapper. Query with `?` placeholders, never named parameters: `self::db()->query('SELECT * FROM streams WHERE id = ?;', $id)`. Terminate the SQL string with a semicolon.
- Legacy superglobals (`$db`, `$rSettings`, `$rUserInfo`, `$rServers`) are still bridged by `LegacyInitializer` for legacy code — preserve them when editing legacy files, but never introduce new global state in new code.

## Naming
- **Classes:** PascalCase (`StreamService`, `DatabaseHandler`)
- **Methods:** camelCase (`getById`, `processStream`)
- **Constants:** UPPER_SNAKE_CASE; for typed sets prefer enums (`BootContext`, `ModuleState`) over new string constants
- **DB columns in queries:** snake_case as stored

## Class patterns
- New domain code follows **Controller → Service → Repository** (see `docs/en/development/architecture.md`).
- Prefer constructor injection for collaborators; use the `DatabaseAware` trait for the shared connection.

## Comments
- **New code:** English comments and DocBlocks (keep `@param`, `@return`, `@package` tags in English).
- **Legacy files** carry Russian-language DocBlocks in some files. Do NOT mass-translate; translate only a file you are already editing for another reason, or when asked. Don't mix languages within one comment block.

## Error handling
- Prefer typed exceptions from the `XcVmException` hierarchy for new code.
- For fatal API responses the codebase uses `exit(json_encode(...))` — match the surrounding handler.
- Do NOT wrap existing code in try-catch "just in case".

## What NOT to do
- Do NOT run `composer install` on a deploy path, and do NOT add Composer dependencies casually.
- Do NOT reintroduce `setDb()` static injection or new `global` usage in new code.
- Do NOT mass-migrate legacy files to namespaces / `strict_types` / DI without an explicit request — keep changes surgical.
- Do NOT add PHPDoc or type annotations to unchanged code.
