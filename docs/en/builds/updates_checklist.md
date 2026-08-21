# XC_VM Release Preparation Checklist

Step-by-step guide for preparing and publishing an XC_VM release.

---

## 1. Changelog

**Generate commit log (work commits only):**

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
mkdir -p dist
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

> ⚠️ `mkdir -p dist` is required: `dist/` does not exist on a fresh clone and `make new` wipes it — without it the redirect fails with "No such file or directory".

**Update `changelog.json`** in the repository root — this file contains only the changes for the upcoming release:

```json
{
    "version": "X.Y.Z",
    "changes": [
        "Description of change 1",
        "Description of change 2"
    ]
}
```

The panel fetches this file from the release tag automatically via `GithubReleases::getChangelog()`.

> 💬 Keep descriptions concise — focus on user-facing improvements and fixes.

---

## 2. Prepare Release Baseline

First, finish all feature/fix/docs work and make sure it is already in `main`.

Set the version variable once and reuse it in all commands below:

```bash
VERSION="X.Y.Z"
```

> ⚠️ Do not create a separate version-bump commit/push at this step.
> Otherwise `dist/changes.md` will include extra release commits and force additional edits.

### Regenerate translated documentation

Documentation is written in **English only** (`docs/en`). The Russian tree
(`docs/ru`) is a **generated, committed** artifact refreshed locally before each
release — translation is intentionally **not** run in CI (it is slow); CI only
builds the committed tree. If `docs/en` changed since the last release:

```bash
make docs-translate      # regenerate docs/ru from docs/en (free, no API key)
make docs-build          # strict build — fails on any broken link/anchor
```

- `make docs-translate` re-translates only the English files whose content
  changed (per-file cache), so this is fast on an incremental release.
- **Review and commit the regenerated `docs/ru`** — it is included in the single
  release commit (step 5). Never hand-edit `docs/ru`.
- Pushing the docs change triggers `pages.yml`, which builds and publishes the
  site to GitHub Pages.

---

## 3. Deleted Files

Before building, generate the list of files to delete on update:

```bash
make generate_deleted_files
```

This runs `git diff` between `LAST_TAG` and `HEAD`, extracts deleted files under `src/`, strips the `src/` prefix, and writes the result to `src/migrations/deleted_files.txt`.

If `LAST_TAG` cannot be auto-detected (no network / no releases), pass it explicitly:

```bash
make generate_deleted_files LAST_TAG=1.2.16
```

**Review the generated file** — verify no critical files are listed by mistake:

```bash
cat src/migrations/deleted_files.txt
```

After validation, `make main` / `make lb` will pack the file into the archive via `delete_files_list` / `lb_delete_files_list`.

During `php console.php update post-update`, `MigrationRunner::runFileCleanup()` reads it and deletes the listed files automatically.

> ⚠️ Lines starting with `#` are comments and will be ignored. You can comment out files you want to keep.

---

## 4. Pre-Release Validation

Before publishing, verify the build works:

**Quality checks** (CI runs the same set on the tag — confirm it is green):

```bash
make dev-tools && make phpstan && make cs && make gates
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
make dev-clean   # remove the dev tools afterwards, restoring the prod-only vendor/
```

> ℹ️ The Docker test install moved to step 6 — it requires a built `dist/XC_VM.zip`.

**Security scan:** runs automatically on push/PR via `.github/workflows/security-scan.yml` (Semgrep) — no manual step.

---

## 5. Update Version and Create a Single Release Commit

Edit the version constant, disable the phpMiniAdmin access flag, and clear its password in:

```text
src/Core/Config/AppConfig.php
```

**Quick commands:**

```bash
sed -i "s/define('DB_ACCESS_ENABLED', true);/define('DB_ACCESS_ENABLED', false);/" src/Core/Config/AppConfig.php
sed -i "s/define('DB_ACCESS_PWD', *\"[^\"]*\");/define('DB_ACCESS_PWD', \"\");/" src/Core/Config/AppConfig.php
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', '${VERSION}');/" src/Core/Config/AppConfig.php
```

**Create one final release commit/push:**

```bash
git add src/Core/Config/AppConfig.php changelog.json src/migrations/deleted_files.txt
git add docs/en docs/ru   # include any doc edits + the regenerated ru (step 2)
git commit -m "Prepare release ${VERSION}"
git push
```

> ⚠️ This removes the need for multiple release commits.

---

## 6. Build Archives

> 🤖 **Production builds** are handled by GitHub Actions (`.github/workflows/build-release.yml`) when a release is published. Assets are attached automatically.

**For local builds:**

```bash
make new
make lb
make main
```

After building, `dist/` should contain:

| File | Description |
| --- | --- |
| `XC_VM.zip` | MAIN installer (install script + xc_vm.tar.gz) |
| `xc_vm.tar.gz` | MAIN archive (install & update) |
| `loadbalancer.tar.gz` | LB archive (install & update) |
| `hashes.md5` | MD5 checksums |

> The same archive is used for both clean installation and updates.
> The update script (`src/update`) filters out binary/config directories at runtime using the hardcoded `UPDATE_EXCLUDE_DIRS` list inside the Python script itself.

**Verify integrity:**

```bash
cd dist && md5sum -c hashes.md5
```

**Docker test install** (see `tools/test-install/`) — only after building, since it needs `dist/XC_VM.zip`:

```bash
bash tools/test-install/test_release.sh
```

This builds the image, starts the container with systemd, and runs the installer automatically.
`dist/XC_VM.zip` is mounted into the container as a read-only volume.

> ✅ Verify the panel loads at `http://localhost:8880` and admin login works.

---

## 7. GitHub Release

1. Go to [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Create a new release with the tag from the first step
3. Paste the changelog as the release description
4. Publish **without attaching files** — GitHub Actions will build and attach them

After publishing, the workflow will automatically:

- Build all archives + checksums
- Attach them to the release
- Send a Telegram notification via `release-notifier.yml`

> ✅ Wait for the Actions workflow to finish, then verify all files are downloadable.

---

## 8. Post-Release

- [ ] Verify all 4 assets are attached to the release
- [ ] Run `md5sum -c hashes.md5` on downloaded files
- [ ] Check Telegram notification was sent
- [ ] Close related GitHub issues/milestones

---

## Command Reference

Every `make` target used during release prep, in one place.

**Quality checks** — run `make dev-tools` first, `make dev-clean` when done:

| Command | Purpose |
| --- | --- |
| `make dev-tools` | Install dev tooling (PHPStan, PHP-CS-Fixer) via `composer install` |
| `make phpstan` | Static analysis (also catches syntax errors) |
| `make phpstan-baseline` | Regenerate the PHPStan baseline |
| `make cs` | Code-style check — import/namespace hygiene (PHP-CS-Fixer, dry-run) |
| `make cs-fix` | Apply code-style fixes in place |
| `make gates` | PSR-4 regression gates (procedural-use, LB-archive, vendor-prod-only) |
| `make dev-clean` | Remove the dev tools again, restoring the production-only `vendor/` |
| `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist` | Unit tests |

**Release prep & build:**

| Command | Purpose |
| --- | --- |
| `make generate_deleted_files` | Regenerate `src/migrations/deleted_files.txt` |
| `make new` | Reset the `dist/` output directory (run before building) |
| `make lb` | Build the LoadBalancer archive into `dist/` |
| `make main` | Build the MAIN archive into `dist/` |
| `bash tools/test-install/test_release.sh` | Docker install test of the built release |

**Documentation** (English source in `docs/en`; `docs/ru` is generated + committed):

| Command | Purpose |
| --- | --- |
| `make docs-venv` | One-time: local venv (build + translation deps) |
| `make docs-translate` | Regenerate `docs/ru` from `docs/en` (before a release) |
| `make docs-build` | Strict MkDocs build into `./build/site` (what CI runs) |
| `make docs-serve` | Live docs preview at `http://127.0.0.1:8000` |
