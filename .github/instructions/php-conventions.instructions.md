---
applyTo: "**/*.php"
---
# PHP Conventions — XC_VM

## Formatting
- **Brace style:** K&R (opening brace on same line)
- **Indentation:** Tabs (1 tab per level), NOT spaces
- **No trailing whitespace**

## Comment Language
- **New code:** write all comments and DocBlocks in **English** (keep PHPDoc tags
  `@param`, `@return`, `@package`, etc. in English as they already are).
- **Legacy files:** the existing codebase has Russian-language DocBlocks in ~23%
  of files (notably `src/cli/`, `src/core/Auth/`, `src/public/Controllers/`).
  Do NOT mass-translate. Translate Russian comments to English only when you are
  already editing that file for another reason, or when explicitly asked.
- **Do not mix languages** within a single new comment block.

## Typing and Declarations
- **`declare(strict_types=1)`:** legacy code (controllers, `cli/`, most of `core/`) omits it — do NOT add it there. New code already uses it (e.g. `src/core/Auth/*`, `src/core/Enum/*`, `src/core/Events/ListensTo.php`) — follow the pattern of neighbouring files.
- **Namespaces:** legacy first-party code has none — do NOT add namespaces to it. The module system *is* namespaced (`src/modules/*/`…`Module.php`), as are bundled parsers (`core/Parsing/M3uParser`, `core/Parsing/PhpM3u8`) — match the surrounding file when working there.
- Do NOT add PHP docblocks or type annotations to existing code unless explicitly asked
- Parameter type hints: use when writing new service/repository methods, omit when editing legacy code

## Naming
- **Classes:** PascalCase (`StreamService`, `Database`, `FileLogger`)
- **Methods:** camelCase (`getById`, `processStream`, `closeConnection`)
- **Variables:** `$r` prefix for data arrays and results: `$rData`, `$rArray`, `$rReturn`, `$rRow`, `$rStreamID`
- **Constants:** UPPER_SNAKE_CASE (`STATUS_SUCCESS`, `GIT_OWNER`); for typed sets prefer enums (`BootContext`, `ModuleState`) over new string constants
- **Database columns in queries:** snake_case as stored in DB

## Class Patterns
- Services use `public static` methods in current codebase (legacy pattern)
- New domain code follows Controller → Service → Repository pattern per `docs/en/development/architecture.md`
- Constructor injection for new services; legacy code still uses `global $db`, `global $rSettings`

## Global Variables (legacy — do NOT introduce new ones)
- `$db` — Database instance
- `$rSettings` — System settings array
- `$rUserInfo` — Current user info
- `$rServers` — Servers configuration
- `$_TITLE` — Page metadata

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
- Do NOT add `use` / `namespace` to legacy first-party code (controllers, `cli/`, legacy `core/`) — but keep namespaces when editing the module system (`src/modules/*`), which is already namespaced
- Do NOT introduce Composer dependencies
- Do NOT restructure legacy files into PSR-4 layout (modules are already namespaced / PSR-4-like)
- Do NOT convert existing `global` usage to DI without explicit request
- Do NOT add PHPDoc to unchanged code
- Do NOT wrap existing code in try-catch "just in case"
