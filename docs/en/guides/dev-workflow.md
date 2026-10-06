# Development Workflow

How to set up the project locally, run the quality checks, and deploy code to a development server.

---

## Local Setup

**Prerequisites:** PHP **8.1** (the codebase pins `php: 8.1.33`; newer majors are not supported) with `pdo_mysql`, and Composer available locally. The unit tests also need a MariaDB/MySQL server: without one, `make test-db` starts a throwaway MariaDB in Docker (see [Unit Tests](phpunit-phar.md#2-database)).

The committed `src/vendor/` is **production-only**, so the dev tools (PHPStan,
phpcs) are not in the tree. Install them once from the committed lock:

```bash
make dev-tools          # = cd src && composer install
```

This adds the `require-dev` packages into `src/vendor/`. **Never commit them** —
the committed vendor must stay production-only (`composer install --no-dev`).
`.gitignore` keeps the dev packages out of `git add`, and a CI gate
(`check-vendor-prod-only`) fails the build if one is ever committed.

## Quality Checks

Run these before pushing — CI runs the same set:

| Command | Checks |
| --- | --- |
| `make phpstan` | Static analysis against the committed baseline (fails only on NEW issues) |
| `make cs` | Code style — import/namespace hygiene (phpcs + Slevomat) |
| `make cs-fix` | Apply the style fixes in place |
| `make gates` | PSR-4 regression gates (below) |
| `php tests/phpunit.phar -c tests/phpunit.xml.dist` | Unit tests, on MariaDB (`make test-db` if you have none) — see [Unit Tests](phpunit-phar.md) |
| `make e2e` | Browser tests against a live test panel — see [End-to-End Tests](#end-to-end-tests) |
| `make rector` | Dry-run automated refactoring — see [Automated Refactoring (Rector)](refactoring.md) |

`make phpstan` and `make cs` need the dev tools — run `make dev-tools` first.

The PHPStan baseline lives at `build/phpstan-baseline.neon` — it freezes all *pre-existing*
issues so only **new** ones fail CI. If you intentionally change the level or accept a batch of
findings, regenerate it with `make phpstan-baseline` and commit the result. Don't regenerate it
just to silence a real new error — fix the code.

`make gates` bundles five guards:

- **check-procedural-use** — procedural / view files import every migrated class they use (PHP imports are positional, so the `use` must precede the usage);
- **verify-lb-archive** — the Load Balancer build excludes privileged code (admin/reseller controllers, user/device domain, install/root commands), every LB list entry still matches a tracked path of the right kind, every script the LB nginx routes to still ships, and the LB update deletes no shipped file — see [Build System (MAIN vs LB)](../builds/build_system.md#build-verification) for the exclusion boundary;
- **check-vendor-prod-only** — no `require-dev` package is committed under `src/vendor/`;
- **check-lb-settings-keys** — a node replica's settings carry only what the Load Balancer build reads, never a secret;
- **check-core-cluster-refs** — every reference from `src/Core/` to `XcVm\Domain\Cluster` sits behind a `class_exists()` guard (Core ships to load balancers, the cluster domain does not).

## End-to-End Tests

`tests/e2e` is a Playwright suite that drives the admin panel the way an
administrator does: it creates categories, bouquets, packages, lines, devices,
resellers, block-list entries and a live stream, edits them, starts and stops the
stream, and deletes everything again. It needs a **test** panel (never
production) and an admin account used only by the tests — every admin login
re-hashes the password and signs out that account's other sessions.

Set `XC_E2E_BASE_URL` (the admin URL including the access code), `XC_E2E_USER`
and `XC_E2E_PASS`, then run `make e2e-install` once and `make e2e`. The suite's
`README.md` (in `tests/e2e/`) lists what each spec covers, how to provision the
test account with `tests/e2e/tools/create-admin.php`, and what the tests change
on the panel host.

## Deploying Code to VDS via SFTP

For daily development, we recommend the [SFTP extension](https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp) for VS Code — edit locally, auto-upload on save.

### Setup

Create `.vscode/sftp.json`:

```json
[
    {
        "name": "My Dev VDS",
        "host": "YOUR_VDS_IP",
        "protocol": "sftp",
        "port": 22,
        "username": "root",
        "remotePath": "/home/xc_vm",
        "useTempFile": false,
        "uploadOnSave": true,
        "openSsh": false,
        "watcher": {
            "files": "**/*",
            "autoUpload": false,
            "autoDelete": true
        },
        "ignore": [
            ".vscode",
            ".git",
            ".gitattributes",
            ".gitignore",
            "update",
            "*pycache/",
            "*.gitkeep",
            "bin/",
            "config/",
            "tmp/"
        ],
        "context": "./src/",
        "profiles": {}
    },
    {
        "name": "My Dev VDS Tests",
        "host": "YOUR_VDS_IP",
        "protocol": "sftp",
        "port": 22,
        "username": "root",
        "remotePath": "/home/xc_vm/tests",
        "useTempFile": false,
        "uploadOnSave": true,
        "openSsh": false,
        "watcher": {
            "files": "**/*",
            "autoUpload": false,
            "autoDelete": true
        },
        "ignore": [
            ".vscode",
            ".git",
            ".gitattributes",
            ".gitignore",
            "tmp/",
            ".cache/"
        ],
        "context": "./tests/",
        "profiles": {}
    }
]
```

### Key Settings

- **`context: "./src/"`** — maps local `src/` to remote `/home/xc_vm/`
- **`context: "./tests/"`** — maps local `tests/` to remote `/home/xc_vm/tests/`
- **`uploadOnSave: true`** — every Ctrl+S pushes the file to VDS instantly
- **`ignore`** — protects server-specific files (`bin/`, `config/`, `tmp/`)

> ⚠️ **`watcher.autoDelete: true`** — deleting a file locally deletes it on the VDS too. Handy
> for keeping the tree in sync, but a mis-deleted local file (or a bad rename) will remove the
> remote copy. Keep the `ignore` list tight, or set it to `false` if you don't want the watcher
> to propagate deletions.

> **Security:** Use SSH keys instead of password. The `.vscode/` directory is in `.gitignore`, so credentials won't leak to git.

### How to sync the tests folder

1. Add a second SFTP entry with `context: "./tests/"` and `remotePath: "/home/xc_vm/tests"`.
2. Save files under `tests/` locally.
3. The extension will upload them separately from `src/` into `/home/xc_vm/tests`.
4. This is required because tests are stored outside `src/` and will not be uploaded by the main entry.

### Workflow

1. Open project in VS Code
2. Edit any file under `src/`
3. If you add a test, edit the file under `tests/`
4. Save — the matching SFTP entry uploads the file to VDS
5. Run the relevant test on VDS
6. Commit to git as usual

## Related files

| File | Role |
| --- | --- |
| `.vscode/sftp.json` | Local → VDS sync config (gitignored) |
| `Makefile` | `make dev-tools`, `make phpstan`, `make cs`, `make gates` |
| `src/composer.json` | Dependencies + PSR-4 autoload |
