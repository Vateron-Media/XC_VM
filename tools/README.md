# `tools/` — development, CI & panel-test utilities

Support tooling for the XC_VM panel. Nothing here ships to production — these are
CI gates, static-analysis helpers, and manual test/QA utilities written to
exercise the panel. Some are wired into the `Makefile` / CI; others are run by
hand during testing. Each is listed below with its purpose and how to run it.

## CI gates (run by `make gates`, enforced in CI)

| Tool | Purpose |
|------|---------|
| `ci/check_procedural_use.php` | Every non-namespaced procedural/view file must `use`-import the migrated `XcVm\` classes it references (PHP `use` is positional — a short name without an import faults at runtime). |
| `ci/check-vendor-prod-only.sh` | The committed `src/vendor/` must be **production-only** — no dev package is tracked or listed in `installed.json` (checked against the git index). |
| `ci/verify-lb-archive.sh` | The load-balancer archive must **exclude privileged code** (admin/reseller/player UI, user/device domain, install/root cron jobs). |

## Static analysis (PHPStan) support

| Tool | Purpose |
|------|---------|
| `phpstan/phpstan-bootstrap.php` | PHPStan bootstrap — defines the global constants (e.g. `MAIN_HOME`) the code sets at runtime, so constant-dependent analysis stays accurate. A `bootstrapFile` in `phpstan.dist.neon`. |
| `phpstan/constants.stub.php` | Auto-generated stub of the ~131 runtime `define()` constants PHPStan cannot see via static scanning. A `bootstrapFile`. **Do not hand-edit** — regenerate it. |
| `phpstan/gen-constants-stub.php` | Regenerates `constants.stub.php` by scanning `src/` for `define()`s. Run via **`make phpstan-stub`** when runtime constants change. |

Run the analysis with `make phpstan` (needs dev tools: `make dev-tools`).

## Tests runner

| Tool | Purpose |
|------|---------|
| `.bin/phpunit.phar` | Committed PHPUnit 10.5 runner. `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist` (config in `tests/phpunit.xml.dist`). |

## Panel test / QA utilities (manual)

Written to test the running panel end-to-end; run by hand as needed.

| Tool | Purpose |
|------|---------|
| `test_player_api.sh` | HTTP smoke-test of every `PlayerApiController` endpoint — checks status, `Content-Type` and JSON shape. `./tools/test_player_api.sh <base_url> <username> <password>`. (Note: the password is passed as an argv arg, so it is visible in `ps`/shell history — use on a local/trusted shell.) |
| `stream_queue_check.py` | Inspects a running stream's packet queue (MPEG-TS `/ts` and HLS) to diagnose buffering/queue behaviour. See `docs/*/development/streaming-subsystem.md`. |
| `test-stream-generator/` | Turns a single `sample.mp4` into a looping, HTTP-served "live" stream you can paste into the panel as a source — end-to-end streaming-pipeline testing, incl. **LLOD** (`src/Cli/Commands/LlodCommand.php`). See its `README.md`. |
| `dts-audio-test/` | Investigates the legacy DTS/AC3 audio path (XUI's `-fix_dts` flag vs stock ffmpeg's `-copyts`) when moving off the bundled ffmpeg `4.0` binary. See its `README.md`. |
| `test-install/` | Docker-based end-to-end install test of the built release archive — unpacks `XC_VM.zip`, runs the installer with scripted answers, and checks the key installed files. See its `README.md`. Referenced from `docs/*/builds/updates_checklist.md`. |

## Repo maintenance

| Tool | Purpose |
|------|---------|
| `update_top_contributors.py` | Regenerates the contributors table in `CONTRIBUTORS.md` from the GitHub Contributors + Pulls APIs. Optional `GITHUB_TOKEN` env var to avoid rate limits. |
