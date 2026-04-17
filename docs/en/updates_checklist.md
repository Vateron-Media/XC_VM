<h1 align="center">✅ XC_VM Release Preparation Checklist</h1>

<p align="center">
  Step-by-step guide for preparing and publishing an <b>XC_VM</b> release.
</p>

---

## 📚 Navigation

* [🔢 1. Update Version](#1-update-version)
* [🧹 2. Deleted Files](#2-deleted-files)
* [🧪 3. Pre-Release Validation](#3-pre-release-validation)
* [⚙️ 4. Build Archives](#4-build-archives)
* [📝 5. Changelog](#5-changelog)
* [🚀 6. GitHub Release](#6-github-release)
* [📢 7. Post-Release](#7-post-release)

---

## 🔢 1. Update Version

Edit the version constant and disable development mode in:

```
src/core/Config/AppConfig.php
```

**Quick commands:**

```bash
sed -i "s/define('DEVELOPMENT', true);/define('DEVELOPMENT', false);/" src/core/Config/AppConfig.php
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', 'X.Y.Z');/" src/core/Config/AppConfig.php
```

> ⚠️ Make sure `DEVELOPMENT` is set to `false` before every release.

**Commit:**

```bash
git add src/core/Config/AppConfig.php
git commit -m "Bump version to X.Y.Z"
git push
```

> 💡 Replace `X.Y.Z` with the actual version.

---

## 🧹 2. Deleted Files

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

## 🧪 3. Pre-Release Validation

Before publishing, verify the build works:

**PHP syntax check:**

```bash
make syntax_check
```

**Docker test install** (see `tools/test-install/`):

```bash
cd tools/test-install
docker compose up -d --build
docker exec -it xc_test bash /opt/auto_install.sh
```

> ✅ Verify the panel loads at `http://localhost:8880` and admin login works.

**Security scan** (runs automatically on push via `.github/workflows/security-scan.yml`):

```bash
tools/php_syntax_check.sh
tools/run_scan.sh
```

---

## ⚙️ 4. Build Archives

> 🤖 **Production builds** are handled by GitHub Actions (`.github/workflows/build-release.yml`) when a release is published. Assets are attached automatically.

**For local builds:**

```bash
make new
make lb
make main
```

After building, `dist/` should contain:

| File | Description |
|------|-------------|
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

---

## 📝 5. Changelog

**Generate commit log:**

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
echo "Previous release: $PREV_TAG"
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

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

## 🚀 6. GitHub Release

1. Go to [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Create a new release with tag `X.Y.Z`
3. Paste the changelog as the release description
4. Publish **without attaching files** — GitHub Actions will build and attach them

After publishing, the workflow will automatically:
* Build all archives + checksums
* Attach them to the release
* Send a Telegram notification via `release-notifier.yml`

> ✅ Wait for the Actions workflow to finish, then verify all files are downloadable.

---

## 📢 7. Post-Release

* [ ] Verify all 4 assets are attached to the release
* [ ] Run `md5sum -c hashes.md5` on downloaded files
* [ ] Check Telegram notification was sent
* [ ] Close related GitHub issues/milestones
