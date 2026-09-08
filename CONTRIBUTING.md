# Contributing to XC_VM

Thank you for considering contributing! XC_VM is an open-source,
Xtream-Codes-style IPTV management panel (PHP 8.1+, AGPL-3.0) — a modular
monolith migrated to Composer PSR-4. This guide covers the layout, the local dev
loop, the checks CI enforces, and how to open a good pull request.

## 📌 General Guidelines

- Minimally use AI; you own every line you submit and must understand it.
- Follow the project's coding style and best practices.
- Keep pull requests focused on a single change.
- Write meaningful commit messages (Conventional Commits, English).
- Document behaviour that isn't obvious from the code.
- If you refactor and aren't sure code is unused elsewhere, comment it out with a
  note instead of deleting — it can be removed after the next release.

## 🧭 Repository Layout (read this first)

The trap for new contributors: **the application lives under `src/`, not the
repo root.**

- **`src/` is the application root and the deploy root.** `composer.json`,
  `vendor/`, `bootstrap.php`, `console.php` all live in `src/`. It maps 1:1 to
  the install root on a server (`src/Core/X.php` → `/home/xc_vm/Core/X.php`).
- **PSR-4:** `XcVm\` → `src/` (e.g. `XcVm\Core\Database\DatabaseHandler` =
  `src/Core/Database/DatabaseHandler.php`).
- **The repo root holds build & CI tooling only:** `Makefile`, `tools/`,
  `tests/`, `install/`, `lb_configs/`, `build/`, `docs/`.

Two build flavours are produced from the same tree: the full **MAIN** panel and
a stripped **Load Balancer (LB)** archive (restreaming only — admin/reseller/
player code, installers, and privileged commands removed). **Modules are
MAIN-only.** Deeper architecture notes live in `docs/en/development/` and the
repo `CLAUDE.md`.

## ⚠️ Hard Constraints

- **`src/vendor/` is committed and PRODUCTION-ONLY.** Never run
  `composer install` on a deploy path. To change the autoload map, run
  `composer dump-autoload` from `src/`. After changing dependencies, re-commit a
  `composer install --no-dev` vendor tree plus the updated `composer.lock`.
- **Modules own their DB schema** via file migrations
  (`src/Modules/<name>/migrations/<semver>.up.sql`/`.down.sql`). Do **not** add
  module tables to `src/bin/install/database.sql`, and core must not touch
  module-owned tables directly — dispatch an event and let the module clean up.
- **Bundled binaries are regular Git objects (not Git LFS).** The
  `ffmpeg`/`ffprobe`, `redis-server`, `yt-dlp`, MaxMind DBs, `login-bg.mp4`,
  etc. are committed as plain binary blobs (marked `-text -diff` in
  `.gitattributes`) — a normal `git clone` fetches them whole, no `git lfs pull`
  needed. A `verify_no_lfs_pointers` build gate still guards against stray legacy
  LFS pointer stubs.
- Outbound HTTPS from PHP must use **cURL** — `file_get_contents()` over https
  does not work in this environment.

## 🛠️ Local Setup

You need PHP 8.1+, `git`, `make`, and Composer.

```sh
git clone https://github.com/Vateron-Media/XC_VM.git
cd XC_VM

make dev-tools    # composer install in src/ — adds PHPStan +
                  # phpcs (Slevomat) + unused-public to src/vendor
```

`make dev-tools` installs the `require-dev` toolchain (PHPStan 2.2.9, PHP_
CodeSniffer + Slevomat, Tomas Votruba's unused-public) into `src/vendor`. These
are **not** in the committed vendor tree, so run it before the checks below. When
you're done, `make dev-clean` prunes `src/vendor` back to production-only.

## 🔁 The Dev Loop: Deploying to a Live Box

**Prerequisite — you need a working XC_VM install to sync onto.** `sync-dev.sh`
does not provision a server; it pushes changed code onto an existing install root
(`/home/xc_vm/` by default). So first stand up a throwaway/test box the normal
way — install the panel from a release (see [Quick Install](README.md#-quick-install)
in the README: download `XC_VM.zip`, `unzip`, `sudo python3 install`). Only once
the panel is installed and running does `sync-dev.sh` become useful for pushing
your local edits onto it.

Once you have that box, use `tools/sync-dev.sh` to iterate against it **without**
building a release. It works out which files under `src/` your commits changed
and copies their **full current content** to the server's install root. Files
your commits **deleted are removed** on the server; renames delete the old path
and copy the new one.

It is a developer convenience — it never runs DB migrations, ships binaries, or
edits per-server config. For a real upgrade use the release archive + panel
updater.

```sh
# Preview the copy/delete plan — never contacts the server; always safe:
DEV_SERVER=<ip> tools/sync-dev.sh <RANGE> --dry-run

# Push uncommitted edits to tracked files (fastest inner loop):
DEV_SERVER=<ip> tools/sync-dev.sh --working --restart

# Push committed work since the last sync (watermark in .dev-sync-state):
DEV_SERVER=<ip> tools/sync-dev.sh --restart

# Push everything since a tag / branch / sha:
DEV_SERVER=<ip> tools/sync-dev.sh 2.4.1 --restart
```

Here `<RANGE>` is **usually the version tag of the panel currently installed on
the box** (e.g. `2.4.1`). The box was installed from that release, so syncing
`<that version>..HEAD` pushes exactly the changes your branch adds on top of it,
nothing more.

- `--working` — also include uncommitted changes to tracked files (diff vs HEAD).
- `--restart` — restart the panel so OPcache reloads new **PHP** code. Slow
  (>1 min); run in the background under a short timeout. Pure CSS/JS/template
  changes are served statically and usually need only a hard refresh, not this.
- `--cache` — rebuild the settings cache (for settings-shaped changes).

`RANGE` is a git range `A..B`, or a single ref `REF` (treated as `REF..HEAD`).
Omitted, it continues from `.dev-sync-state` (or `HEAD~1..HEAD` on first run).
SSH auth uses your keys by default; set `DEV_SSH_USER` / `DEV_SSH_PASS` (needs
`sshpass`) to override. All SSH multiplexes over one ControlMaster socket so
repeated syncs don't trip fail2ban. See the header of `tools/sync-dev.sh` (or
`--help`) for the full reference.

## ✅ Pre-Commit Checks

Run these before committing — **CI runs the exact same set and will reject a PR
that fails any of them:**

```sh
make phpstan       # static analysis, level 5 (also catches syntax errors)
make cs            # code style — import/namespace hygiene (phpcs + Slevomat)
make gates         # PSR-4 regression gates (see below)
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist   # unit tests

make cs-fix        # auto-apply fixable style issues
```

`make gates` runs three CI blockers:

- **`check-procedural-use`** — every procedural/view file must `use`-import the
  `XcVm\` classes it references at the top (PHP `use` is positional; a bare short
  name faults at runtime).
- **`verify-lb-archive`** — the load-balancer build must contain no privileged
  code (admin/reseller/player UI, user/device domain, install/root crons).
- **`check-vendor-prod-only`** — the committed `src/vendor/` must stay
  production-only (no dev package tracked in the git index).

Quick single-file syntax check while editing (no DB needed):

```sh
php -l path/to/File.php
```

## 🔬 Static Analysis (PHPStan)

The project is analysed at **level 5** with the config in
`build/phpstan.dist.neon`. PHPStan is a `require-dev` Composer package installed
by `make dev-tools` (into `src/vendor/bin/phpstan`); a bootstrap in
`tools/phpstan/` defines the ~131 runtime `define()` constants static scanning
can't see.

```sh
make phpstan       # must report [OK] No errors
```

How the gate works:

- A committed baseline (`build/phpstan-baseline.neon`) freezes pre-existing
  findings, so CI fails only on **new** issues your change introduces — fix
  those.
- The baseline is **not** a list of accepted bugs (most entries are false
  positives from dynamic DB-row shapes or templates). Do **not** grow it to hide
  a real problem in new code.
- If you clear a batch of existing findings, regenerate the smaller baseline:
  `make phpstan-baseline`.
- After runtime constants change, regenerate the stub with `make phpstan-stub`;
  after editing PHPDoc types, clear the cache first:
  `php src/vendor/bin/phpstan clear-result-cache`.

## ✨ Code Style

- **K&R** brace style for PHP; enforced by phpcs (Slevomat) via `make cs` /
  `make cs-fix`. The ruleset (`build/phpcs.xml.dist`) **excludes view templates**
  (`Public/Views/`, `Modules/*/views/`), so short-tag templates (`<?`, `<?=`)
  need no special handling there.
- All code comments and docblocks in **English**.
- Prefer inverting empty-else guards: `if (!$c) { body }` over
  `if ($c) {} else { body }`.
- Avoid unused functions and redundant code.
- Follow best practices for Python and Bash tooling under `tools/`.

## 🧪 Tests

**Unit tests** (PHPUnit 10.5, config `tests/phpunit.xml.dist`, suite "Unit"):

```sh
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist                    # all
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --filter SomeTest  # one
```

> On an installed server, use the bundled interpreter instead of system PHP:
> `/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar ...` (see
> `docs/en/guides/phpunit-phar.md`). On a dev machine, plain `php` is fine.

Guidelines:

- Add PHP tests under `tests/Unit/`, named after the class under test
  (e.g. `GitHubReleasesTest.php`).
- Prefer focused tests for the file you changed over broad project-wide mocks.
- Cover valid inputs, invalid inputs, edge cases, and side effects.
- If code writes to stdout, capture it in the test so PHPUnit output stays clean.

**End-to-end tests** (Playwright, in `tests/e2e/`) exercise the running panel UI:

```sh
make e2e-install   # one-time: npm ci + playwright install chromium
make e2e           # run the suite
make e2e-ui        # interactive UI mode
```

## 🧰 Developer Tooling (`tools/`)

Nothing in `tools/` ships to production. Highlights useful while developing —
see `tools/README.md` for the full list:

| Tool                                     | Purpose                                                                                                                   |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `sync-dev.sh`                            | Incremental code deploy to a live box (see the dev loop above).                                                           |
| `test_player_api.sh <url> <user> <pass>` | HTTP smoke-test of every Player API endpoint (status / content-type / JSON shape).                                        |
| `stream-check/`                          | Dependency-free MPEG-TS + HLS stream integrity checker (`stream_queue_check.py`) with an SVG grapher (`stream_graph.py`). |
| `test-stream-generator/`                 | Turns `sample.mp4` into a looping HTTP "live" source for end-to-end pipeline testing.                                     |
| `test-install/`                          | Docker end-to-end install test of the built release archive.                                                              |
| `dts-audio-test/`                        | Investigates the legacy DTS/AC3 audio path vs stock ffmpeg.                                                               |

## 📦 Building a Release (maintainers)

```sh
make main    # full panel archive: dist/xc_vm.tar.gz + XC_VM.zip installer
make lb      # load-balancer archive (privileged code stripped; MAIN-only modules excluded)
make new     # wipe dist/
```

Builds copy only git-tracked files and run `verify_no_lfs_pointers`.

## 📚 Documentation

Docs are a **MkDocs Material** site under `docs/`.

- **Edit only `docs/en/`** — English is the single source of truth.
- **Never hand-edit `docs/ru/`** (or any other language tree): it is generated
  from `docs/en` by `tools/docs/translate.py` and overwritten on the next run.
- After editing English docs, verify with `make docs-build` (strict — fails on
  broken links/anchors). Preview locally with `make docs-serve`.
- `docs/ru` is regenerated locally before a release (`make docs-translate`), not
  in CI.

## 🔥 Submitting a Pull Request

1. Fork and branch (see naming conventions below):

    ```sh
    git checkout -b feature/your-feature
    ```

2. Make your changes; run the pre-commit checks; commit with a Conventional
   Commits message:

    ```sh
    git commit -m "feat: short description of the change"
    ```

3. Push and open a pull request on GitHub:

    ```sh
    git push origin feature/your-feature
    ```

Keep the PR focused on a single change and describe what and why.

### Branch Naming Conventions

| Type          | Template                       | Example                       |
| ------------- | ------------------------------ | ----------------------------- |
| Features      | `feature/<short-description>`  | `feature/user-authentication` |
| Bug Fixes     | `fix/<short-description>`      | `fix/login-bug`               |
| Hotfixes      | `hotfix/<short-description>`   | `hotfix/critical-error`       |
| Refactoring   | `refactor/<short-description>` | `refactor/code-cleanup`       |
| Testing       | `test/<short-description>`     | `test/api-endpoints`          |
| Documentation | `docs/<short-description>`     | `docs/documentation-api`      |

## 👀 Code Reviews

- All PRs must be reviewed by at least 2 maintainers.
- Address review comments before merging.

## 🚀 Reporting Issues

- Use **GitHub Issues** to report bugs and suggest features.
- Provide clear steps to reproduce, plus relevant logs or error messages.

## 🌟 Recognition

Your GitHub profile will be added to [CONTRIBUTORS.md](CONTRIBUTORS.md).

Thank you for contributing! 🎉
