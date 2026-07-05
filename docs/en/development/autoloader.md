# Autoloading (PSR-4)

XC_VM autoloads classes with a standard **Composer PSR-4** autoloader; the namespace encodes the file path, so resolution is a direct `file_exists` with no scan and no cache.

---

## Overview

Every first-party class lives under the `XcVm\` root namespace, mapped to `src/`:

```text
XcVm\Core\Auth\Authenticator   ->  src/Core/Auth/Authenticator.php
XcVm\Domain\Stream\StreamService ->  src/Domain/Stream/StreamService.php
XcVm\Public\Controllers\Admin\UserController -> src/Public/Controllers/Admin/UserController.php
```

The mapping is declared in `src/composer.json`:

```json
"autoload": {
    "psr-4": {
        "XcVm\\": "./",
        "M3uParser\\": "Core/Parsing/M3uParser/src/",
        "Chrisyue\\PhpM3u8\\": "Core/Parsing/PhpM3u8/src/"
    }
}
```

`src/vendor/` (the Composer autoloader + production dependencies) is committed and
shipped — the deploy path has no Composer and never runs `composer install`. There
is **no class-map cache** (no `optimize-autoloader`): a class miss is a plain path
lookup, not a directory rescan.

## Adding a new class

Create the file at the path its namespace maps to — that is all; Composer resolves it on demand:

```php
// src/Domain/Billing/InvoiceService.php
namespace XcVm\Domain\Billing;

class InvoiceService {
    public static function generate(int $userId): string { /* ... */ }
}
```

Reference it from other namespaced code with a `use` import, or by its FQCN:

```php
use XcVm\Domain\Billing\InvoiceService;
```

No cache to clear, no registry to edit. A brand-new sub-namespace (e.g.
`XcVm\Domain\Billing`) works immediately because it maps straight onto the
directory.

## Naming rules

| Rule | Example |
| --- | --- |
| File name **must** match the class name | `InvoiceService.php` → `class InvoiceService` |
| One class per file | PSR-4 resolves one class per path; split multi-class files |
| Namespace **must** match the directory path (case-sensitive) | `src/Domain/Billing/` → `namespace XcVm\Domain\Billing;` |
| PascalCase classes and directories | `StreamService`, `DatabaseHandler`, `Core/Auth/` |
| Project convention: no `declare(strict_types=1)` | — |

Because the namespace carries the location, duplicate short names in different
namespaces no longer collide — `XcVm\Public\Controllers\Admin\PlexController` and
`XcVm\Module\Plex\PlexController` are distinct.

## Procedural and third-party files

Some files are intentionally **not** namespaced and are loaded by explicit
`require`, not the autoloader:

- procedural entry points, views and bootstrap glue (e.g. `Public/index.php`,
  `Public/Views/**`, `Infrastructure/Bootstrap/*.php`);
- global constants and functions (`Core/Config/*`, error handler);
- the ioncube `XC_VM` class and bundled `Infrastructure/Tmdb/lib/*`.

The vendored `M3uParser` and `Chrisyue\PhpM3u8` packages have their own PSR-4
prefixes (above) and autoload normally.

## Modules

Module classes use the `XcVm\Module\<Name>\…` namespace but are **not** registered
in `composer.json` (module/marketplace slug directories — `plex`, `watch-d2bho` —
do not fit a single PSR-4 rule). They are resolved by `ModuleLoader`'s own PSR-4
resolver: it strips the module's base namespace and maps the remainder onto a
sub-path under the module directory. See [Module System](modules.md).

## Dev tooling

The committed `vendor/` is production-only. PHPStan and PHP-CS-Fixer are
`require-dev` packages — install them locally with:

```bash
make dev-tools   # = cd src && composer install
```

They are never committed (a CI gate enforces a prod-only committed vendor). See
[Development Workflow](../guides/dev-workflow.md).

## Related files

| File | Role |
| --- | --- |
| `src/composer.json` | PSR-4 prefix map + dependencies |
| `src/composer.lock` | committed lock for reproducible `composer install` |
| `src/vendor/` | committed Composer autoloader + production deps |
| `src/bootstrap.php` | defines `MAIN_HOME`, requires `vendor/autoload.php` |
| `src/Core/Module/ModuleLoader.php` | PSR-4 resolver for module classes |
