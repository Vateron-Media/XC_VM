# ✅ XC\_VM Release Preparation Checklist

> Follow all steps in order before publishing the update.

---

## 🔢 1. Update the Version

* Set the **new `XC_VM_VERSION` value** in the following files:

  * `src/www/constants.php`
  * `src/www/stream/init.php`
  * `src/player/functions.php`
* Commit the changes with the message:

  ```
  Bump version to X.Y.Z
  ```

---

## 🧹 2. Deleted Files

* Run the command:

  ```bash
  make delete_files_list
  ```
* Open the file `dist/deleted_files.txt`
* For each path in the list, add the following block to `src/includes/cli/update.php` **after the comment** `// Update checkpoint`:

  ```php
  if (file_exists(MAIN_HOME . 'file_path')) {
      unlink(MAIN_HOME . 'file_path');
  }
  ```

---

## ⚙️ 3. Build Archives

* Execute the following commands in order:

  ```bash
  make lb
  make main
  make main_update
  make lb_update
  ```
* Make sure the following files are created:

  * `dist/loadbalancer.tar.gz` — LB installation archive
  * `dist/XC_VM.zip` — MAIN installation archive
  * `dist/update.tar.gz` — MAIN update archive
  * `dist/loadbalancer_update.tar.gz` — LB update archive
  * `dist/hashes.md5` — file with hash checksums

---

## 📝 4. Changelog

First, generate the changes file from git:
```bash
git log --pretty=format:"- %s (%h)" X.Y.Z..main > dist/changes.md
```

---

*   **Go to the link and add the changes for the current release:**
    [https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json](https://github.com/Vateron-Media/XC_VM_Update/blob/main/changelog.json)

*   **Add the changes for the current release in JSON format:**

  ```json
  [
    {
        "version": "X.Y.Z",
        "changes": [
          "Change description 1",
          "Change description 2"
        ]
    }
  ]
  ```

---

## 🚀 5. GitHub Release

* Create a new release on [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
* Attach the following files to the release:

  * `dist/loadbalancer.tar.gz`
  * `dist/XC_VM.zip`
  * `dist/update.tar.gz`
  * `dist/loadbalancer_update.tar.gz`
  * `dist/hashes.md5`
* Add the changelog in the release description

---
