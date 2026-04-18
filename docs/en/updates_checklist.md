# ✅ XC_VM Release Preparation Checklist

Step-by-step guide for preparing and publishing an XC_VM release.

---

## 1. Prepare Release Baseline

First, finish all feature/fix/docs work and make sure it is already in `main`.

Set the version variable once and reuse it in all commands below:

```bash
VERSION="X.Y.Z"
```

> ⚠️ Do not create a separate version-bump commit/push at this step.
> Otherwise `dist/changes.md` will include extra release commits and force additional edits.

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
bash tools/test-install/test_release.sh
```

This builds the image, starts the container with systemd, and runs the installer automatically.
`dist/XC_VM.zip` is mounted into the container as a read-only volume.

> ✅ Verify the panel loads at `http://localhost:8880` and admin login works.

**Security scan** (runs automatically on push via `.github/workflows/security-scan.yml`):

```bash
tools/php_syntax_check.sh
tools/run_scan.sh
```

---

## 📝 4. Changelog

**Generate commit log (work commits only):**

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
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

## 🔢 5. Update Version and Create a Single Release Commit

Edit the version constant and disable development mode in:

```text
src/core/Config/AppConfig.php
```

**Quick commands:**

```bash
sed -i "s/define('DEVELOPMENT', true);/define('DEVELOPMENT', false);/" src/core/Config/AppConfig.php
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', '${VERSION}');/" src/core/Config/AppConfig.php
```

**Create one final release commit/push:**

```bash
git add src/core/Config/AppConfig.php changelog.json src/migrations/deleted_files.txt
git commit -m "Prepare release ${VERSION}"
git push
```

> ⚠️ This removes the need for multiple release commits.

---

## ⚙️ 6. Build Archives

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

---

## 🚀 7. GitHub Release

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

## 📢 8. Post-Release

- [ ] Verify all 4 assets are attached to the release
- [ ] Run `md5sum -c hashes.md5` on downloaded files
- [ ] Check Telegram notification was sent
- [ ] Close related GitHub issues/milestones
