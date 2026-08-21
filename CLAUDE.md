# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

XC_VM is an open-source, Xtream-Codes-style IPTV management panel (PHP 8.1+, AGPL-3.0). It is a modular monolith that has been migrated to Composer PSR-4. The application lives under `src/` and is deployed verbatim to `/home/xc_vm/` (so `src/` is the deploy root and `MAIN_HOME` maps to it).

## Layout essentials

- **`src/` is the application root.** `composer.json`, `vendor/`, `bootstrap.php`, `console.php` all live in `src/`, not the repo root. PSR-4: `XcVm\` → `src/` (e.g. `XcVm\Core\Database\DatabaseHandler` = `src/Core/Database/DatabaseHandler.php`).
- The repo root holds build/release tooling: `Makefile`, `tools/`, `tests/`, `install/`, `lb_configs/`, `.php-cs-fixer.dist.php`.

## Commands

Everything is driven from the **repo root via the `Makefile`**. Static-analysis/style tools are `require-dev` packages and are NOT in the committed `vendor/`; install them first.

```bash
make dev-tools        # composer install in src/ — adds PHPStan + PHP-CS-Fixer to src/vendor (do this first)
make phpstan          # static analysis (phpstan.dist.neon, --memory-limit=2G)
make cs               # code-style check (dry-run, fails on diff)
make cs-fix           # apply style fixes in place
make gates            # fast PSR-4 regression gates (see below)
make dev-clean        # prune src/vendor back to production-only (composer install --no-dev)

# Tests — PHPUnit 10.5, config in tests/phpunit.xml.dist (suite "Unit", bootstrap tests/bootstrap.php)
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --filter SomeTestName   # single test

php -l path/to/File.php   # quick syntax check (used constantly; no DB needed)
```

`make gates` runs three CI blockers: `check-procedural-use` (every procedural/view file must `use`-import the classes it references at the top of the file — PHP `use` is positional), `verify-lb-archive` (the load-balancer build must contain no privileged code), and `check-vendor-prod-only` (the committed `vendor/` must stay production-only).

### Build / release (Makefile)

- `make main` — build the full panel archive (`dist/xc_vm.tar.gz` + `XC_VM.zip` installer).
- `make lb` — build the **load-balancer** archive: the same tree with admin/reseller/player code, installers, and privileged commands stripped out (see `LB_DIRS_TO_REMOVE` / `LB_FILES_TO_REMOVE`). **Modules are MAIN-only** and excluded from LB.
- `make new` — wipe `dist/`. Builds copy only git-tracked files and run `verify_no_lfs_pointers`.

## Critical constraints

- **`vendor/` is committed and PRODUCTION-ONLY.** Never run `composer install` on a deploy path. To change autoload, run `composer dump-autoload` from `src/`. After changing deps, re-commit a `composer install --no-dev` vendor (and `composer.lock`).
- **Git LFS:** `src/bin/install/database.sql`, the bundled `ffmpeg`/`ffprobe`, `redis-server`, `yt-dlp`, fonts/videos, etc. are LFS objects. Editing an LFS file is transparent (the clean filter re-stages it as an LFS object on `git add`; `git push` uploads it). A checkout without LFS materialised ships 130-byte pointer stubs — the build's `verify_no_lfs_pointers` guards against this.
- **Never `git push`.** Commit freely (Conventional Commits, English messages, grouped logically), but pushing to remote is the user's call — do not push unless explicitly told to.
- PHP runs with **`short_open_tag=1`** (view templates use `<?`/`<?=`). PHP-CS-Fixer is invoked with `-d short_open_tag=1` so it sees class usage inside short-tag blocks.

## Architecture (big picture)

**Bootstrap & entry points.** `src/bootstrap.php` defines `XC_Bootstrap::boot(BootContext::{Minimal|Cli|Stream|Admin})` — context-scoped init (constants → config → DB → LegacyInitializer → … → DI container + module boot). Entry points:
- `src/Public/index.php` — front controller for admin/reseller/player + the streaming/web API (routes by nginx-supplied `XC_SCOPE`/`XC_API`; instantiates `XcVm\Public\Controllers\Api\*Controller`).
- `src/console.php` — CLI; builds the `CommandRegistry`, loads modules, runs commands/crons.
- Lightweight bootstraps `WebApiBootstrap` and `StreamingRequestBootstrap` for high-traffic API/stream endpoints.

All of these create the DI container and (for admin/CLI) call `ModuleLoader::bootAll()`, which is where module routes, commands, navbar entries, and **event subscribers** get registered.

**Core building blocks (`src/Core/`).** `Container/ServiceContainer` (PSR-11 DI, lazy factories, tags), `Events/EventDispatcher` (static, PSR-14 typed events; modules subscribe with the `#[ListensTo(Event::class)]` attribute on public methods), `Http/Router` + `CommandRegistry` + `Module/NavbarRegistry`. `Init/LegacyInitializer` bridges new code to legacy superglobals.

**Database access pattern.** Domain and module classes do NOT receive `$db` by constructor. They `use \XcVm\Infrastructure\Database\DatabaseAware` and call `self::db()`, which lazily resolves the connection from the `DatabaseFactory` singleton (set by every bootstrap path). Do not reintroduce per-class `setDb()` wiring. `Core/Database/DatabaseHandler` is the PDO wrapper used via `$db->query(...)`.

**Module system (`src/Modules/<name>/`, core in `src/Core/Module/`).** Each module ships a `module.json` manifest (name, version, `dependencies`, `optional_dependencies`, `requires_core`) and a `<Name>Module extends BaseModule`. `ModuleLoader` discovers modules, topologically sorts by dependency (with cycle detection), and boots them; `ModuleManager` handles install/update/uninstall and writes state to `config/modules.php`.
- **Modules own their DB schema** via file migrations: `Modules/<name>/migrations/<semver>.up.sql` / `.down.sql`, executed by `ModuleMigrator` (install → `up(null→version)`, update → `up(from→to)`, uninstall → `down`). The recorded `installed_version` is the watermark; there is no per-module tracking table. Do NOT add module tables to `src/bin/install/database.sql`. `ModuleManager::syncBundledModules()` (called from `StatusCommand`/`console.php status`) auto-installs not-yet-installed bundled modules in dependency order.
- **Core must not touch module-owned tables directly** (they may be dropped on uninstall). Instead, core dispatches an event (e.g. `StreamsDeletedEvent`, `BouquetDeletedEvent`) and the owning module subscribes via `#[ListensTo]` to clean up its own data.

**Main vs Load Balancer.** The MAIN server runs the panel + MySQL/MariaDB. LB nodes only restream; they get a stripped codebase (the `make lb` archive) and are provisioned over SSH by `Cli/Commands/ServerInstallCommand` + `LbInstallFlow`, which also pull distribution binaries (PHP/nginx/ffmpeg) from a separate GitHub *binaries* release repo. Streaming code runs in both; admin/reseller/player and privileged CLI commands are MAIN-only.

**`xcvm_core` C extension.** A bundled Zend extension (loaded via `bin/php/lib/php.ini`, alongside ioncube/opcache/maxminddb) provides marketplace module installation, module decryption, and `install_id`/environment fingerprinting. PHP code reaches it via the global `\XC_VM::*` API.

## Conventions & gotchas

- Outbound HTTPS from PHP-FPM must use cURL — `file_get_contents()` over https does not work in this environment.
- The TMDb client is the legacy global `\TMDB` class (vendored in `src/Infrastructure/Tmdb/lib/`, not PSR-4). Build it through `XcVm\Infrastructure\Tmdb\TmdbApiService::createClient($apiKey, $language)` (or load via `TmdbApiService::requireLibrary()`), which loads the library itself — do not `require_once` the lib path manually.
- Config: `config.ini` (DB creds, `server_id`), the `settings` DB table via `SettingsManager`, and `config/modules.php` for module enable/disable/version state.

## Documentation (`docs/`)

The docs are a **MkDocs Material** site (`mkdocs.yml`), deployed to GitHub Pages by `.github/workflows/pages.yml`.

- **Edit ONLY `docs/en/`** — English is the single source of truth. **Never hand-edit `docs/ru/` (or any other language tree)**: it is GENERATED from `docs/en` by `tools/docs/translate.py` and any manual change is overwritten on the next regeneration.
- `docs/ru` is committed but **regenerated locally before a release** (`make docs-translate`), not in CI — translation is slow, so `pages.yml` only builds the committed tree. See the "Regenerate translated documentation" step in `docs/en/builds/updates_checklist.md`.
- After editing English docs: `make docs-build` (strict — fails on broken links/anchors) to verify; before a release also `make docs-translate` and commit the regenerated `docs/ru`.
- The two nav tabs (User Guide / Developer Guide) are a `mkdocs.yml` `nav:` grouping only — do not move files to reorganize; edit `nav:` (and `nav_translations` for ru labels).

## Agent skills

### Issue tracker

Issues and specs live as GitHub issues in `Vateron-Media/XC_VM` (via the `gh` CLI). See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
