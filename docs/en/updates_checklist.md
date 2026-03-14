<h1 align="center">✅ XC_VM Release Preparation Checklist</h1>

<p align="center">
  This document describes the process of creating an <b>XC_VM</b> release — a step-by-step guide for developers on updating the version, building archives, and publishing on GitHub.
</p>

---

## 📚 Navigation

* [🔢 1. Update Version](#1-update-version)
* [🧹 2. Deleted Files](#2-deleted-files)
* [⚙️ 3. Build Archives](#3-build-archives)
* [📝 4. Changelog](#4-changelog)
* [🚀 5. GitHub Release](#5-github-release)

---

## 🔢 1. Update Version

* Set the **new `XC_VM_VERSION` value** in the following files:

**Files to edit:**

```
src/core/Config/AppConfig.php
```

**Auto-update command:**

```bash
find -type f -name "*.php" -exec sed -i \
"s/define('XC_VM_VERSION', '[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', 'X.Y.Z');/g" {} \;
```

**Commit the changes with a message:**

```bash
git add .
git commit -m "Bump version to X.Y.Z"
git push
```

> 💡 **Tip:** Replace `X.Y.Z` with the actual version, e.g., `1.2.3`.

---

## 🧹 2. Deleted Files

* Generate a list of deleted files:

```bash
make delete_files_list
```

* Open the file `dist/deleted_files.txt`.
* Copy the contents to `src/cli/Commands/UpdateCommand.php` in the `$rCleanupFiles` array inside the `post-update` case.

> ⚠️ **Important:** Ensure paths are correct to avoid deleting critical files.

**Commit changes with a message:**

```bash
git add .
git commit -m "Added deletion of old files before release"
git push
```

---

## ⚙️ 3. Build Archives

* Run the following commands sequentially:

```bash
make new
make lb
make main
make main_update
make lb_update
```

* Make sure the following files are created in `dist/`:

  - `loadbalancer.tar.gz` — LB installation archive
  - `loadbalancer_update.tar.gz` — LB update archive
  - `XC_VM.zip` — MAIN installation archive
  - `update.tar.gz` — MAIN update archive
  - `hashes.md5` — file with checksums

> 🧰 **Check:** After building, verify archive integrity with `md5sum -c hashes.md5`.

---

## 📝 4. Changelog

Generate the changelog from git first:

```bash
git log --pretty=format:"- %s (%h)" X.Y.Z..main > dist/changes.md
```

* **Then add current release changes** using this JSON:

[https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json](https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json)

* Add current release changes in JSON format:

```json
[
  {
      "version": "X.Y.Z",
      "changes": [
        "Description of change 1",
        "Description of change 2"
      ]
  }
]
```

> 💬 **Recommendation:** Keep descriptions concise and informative, focusing on key improvements and fixes.

---

## 🚀 5. GitHub Release

* Create a new release on [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases).

* Attach the following files:

  - `dist/loadbalancer.tar.gz`
  - `dist/XC_VM.zip`
  - `dist/update.tar.gz`
  - `dist/loadbalancer_update.tar.gz`
  - `dist/hashes.md5`

* Include the changelog in the release description.

> ✅ **Completion:** After publishing, verify that all files are downloadable and checksums match.

---