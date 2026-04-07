<h1 align="center">✅ XC_VM Release Preparation Checklist</h1>

<p align="center">
  Step-by-step guide for preparing and publishing an <b>XC_VM</b> release.
</p>

---

## 📚 Navigation

* [🔢 1. Update Version](#1-update-version)
* [🧹 2. Deleted Files (Automated)](#2-deleted-files-automated)
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
sed -i "s/define('XC_VM_VERSION', *'[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', 'X.Y.Z');/" src/core/Config/AppConfig.php
sed -i "s/define('DEVELOPMENT', true);/define('DEVELOPMENT', false);/" src/core/Config/AppConfig.php
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

## 🧹 2. Deleted Files (Automated)

File cleanup is **fully automated**. No manual steps required.

**How it works:**

1. `make main_update` / `make lb_update` internally runs `make delete_files_list`
2. This generates `dist/migrations/deleted_files.txt` (diff of deleted files since last tag)
3. The file is packed into the update archive at `migrations/deleted_files.txt`
4. During `php console.php update post-update`, `MigrationRunner::runFileCleanup()` reads it and deletes the listed files automatically

> ⚠️ **Review only:** After building, inspect `dist/migrations/deleted_files.txt` to verify no critical files are listed by mistake.

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
make main_update
make lb_update
```

After building, `dist/` should contain:

| File | Description |
|------|-------------|
| `XC_VM.zip` | MAIN installation archive |
| `update.tar.gz` | MAIN update archive |
| `loadbalancer.tar.gz` | LB installation archive |
| `loadbalancer_update.tar.gz` | LB update archive |
| `hashes.md5` | MD5 checksums |

**Verify integrity:**

```bash
cd dist && md5sum -c hashes.md5
```

---

## 📝 5. Changelog

**Generate commit log:**

```bash
PREV_TAG=$(curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest \
  | grep -Po '"tag_name":\s*"\K[^"]+')
echo "Previous release: $PREV_TAG"
git log --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

**Update the public changelog** at:
[XC_VM_Update/changelog.json](https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json)

```json
{
    "version": "X.Y.Z",
    "changes": [
      "Description of change 1",
      "Description of change 2"
    ]
}
```

> 💬 Keep descriptions concise — focus on user-facing improvements and fixes.

---

## 🚀 6. GitHub Release

1. Go to [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Create a new release with tag `X.Y.Z`
3. Paste the changelog as the release description
4. Publish **without attaching files** — GitHub Actions will build and attach them

After publishing, the workflow will automatically:
* Build all 4 archives + checksums
* Attach them to the release
* Send a Telegram notification via `release-notifier.yml`

> ✅ Wait for the Actions workflow to finish, then verify all files are downloadable.

---

## 📢 7. Post-Release

* [ ] Verify all 5 assets are attached to the release
* [ ] Run `md5sum -c hashes.md5` on downloaded files
* [ ] Check Telegram notification was sent
* [ ] Update `changelog.json` in `XC_VM_Update` repo if not done yet
* [ ] Close related GitHub issues/milestones
