---
applyTo: "**/*.php"
---
# PHP Conventions — XC_VM

## Formatting
- **Brace style:** K&R (opening brace on same line)
- **Indentation:** Tabs (1 tab per level), NOT spaces
- **No trailing whitespace**

## Typing and Declarations
- Do NOT add `declare(strict_types=1)` — project uses `strict_types=0` or omits it
- Do NOT add `namespace` declarations — project does not use namespaces
- Do NOT add PHP docblocks or type annotations to existing code unless explicitly asked
- Parameter type hints: use when writing new service/repository methods, omit when editing legacy code

## Naming
- **Classes:** PascalCase (`StreamService`, `Database`, `FileLogger`)
- **Methods:** camelCase (`getById`, `processStream`, `closeConnection`)
- **Variables:** `$r` prefix for data arrays and results: `$rData`, `$rArray`, `$rReturn`, `$rRow`, `$rStreamID`
- **Constants:** UPPER_SNAKE_CASE (`CONTEXT_ADMIN`, `STATUS_SUCCESS`)
- **Database columns in queries:** snake_case as stored in DB

## Class Patterns
- Services use `public static` methods in current codebase (legacy pattern)
- New domain code follows Controller → Service → Repository pattern per ARCHITECTURE.md
- Constructor injection for new services; legacy code still uses `global $db`, `global $rSettings`

## Global Variables (legacy — do NOT introduce new ones)
- `$db` — Database instance
- `$rSettings` — System settings array
- `$rUserInfo` — Current user info
- `$rServers` — Servers configuration
- `$_INFO` / `$_TITLE` — Page metadata

When editing legacy files that use globals, preserve the pattern. Do NOT refactor globals unless explicitly asked.

## SQL
- Use PDO prepared statements with `?` placeholders: `$db->query('SELECT * FROM streams WHERE id = ?;', $rID)`
- Do NOT use named parameters (`:name` style)
- Terminate SQL strings with semicolon inside the query string

## Error Handling
- Use try-catch + `exit(json_encode(...))` for fatal API errors
- Use status constants (`STATUS_SUCCESS`, etc.) for validation flows
- Do NOT add exception handling to code that doesn't have it unless asked

## File Structure
- No autoloading via Composer — project uses custom `src/autoload.php`
- Admin pages: `src/public/Views/admin/*.php` — mixed PHP/HTML templates (legacy-compatible)
- Domain services: `src/domain/{Context}/{ContextService}.php`
- Core utilities: `src/core/{Subsystem}/*.php`

## What NOT to Do
- Do NOT add `use` / `namespace` / `import` statements
- Do NOT introduce Composer dependencies
- Do NOT restructure files into PSR-4 layout
- Do NOT convert existing `global` usage to DI without explicit request
- Do NOT add PHPDoc to unchanged code
- Do NOT wrap existing code in try-catch "just in case"
