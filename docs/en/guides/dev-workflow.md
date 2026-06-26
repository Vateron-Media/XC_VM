# Development Workflow

How to set up the project locally, run the quality checks, and deploy code to a development server.

---

## Local Setup

The committed `src/vendor/` is **production-only**, so the dev tools (PHPStan,
PHP-CS-Fixer) are not in the tree. Install them once from the committed lock:

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
| `make cs` | Code style — import/namespace hygiene (PHP-CS-Fixer, dry-run) |
| `make cs-fix` | Apply the style fixes in place |
| `make gates` | PSR-4 regression gates (below) |
| `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist` | Unit tests |

`make phpstan` and `make cs` need the dev tools — run `make dev-tools` first.

`make gates` bundles three guards:

- **check-procedural-use** — procedural / view files import every migrated class they use (PHP imports are positional, so the `use` must precede the usage);
- **verify-lb-archive** — the Load Balancer build excludes privileged code (admin/reseller controllers, user/device domain, install/root commands);
- **check-vendor-prod-only** — no `require-dev` package is committed under `src/vendor/`.

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
