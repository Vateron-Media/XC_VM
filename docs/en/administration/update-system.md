<h1 align="center">Update Mechanism in XC_VM</h1>

<p align="center">
  The <b>XC_VM</b> update system is implemented as a multi-layered process — from the web interface to system-level scripts.  
  This approach ensures <b>reliability, automation, and data integrity</b> during panel updates.
</p>

---

## Navigation

* [1. Update Initiation](#1-update-initiation)
* [2. CRON Trigger](#2-cron-trigger)
* [3. Update Management (PHP Layer)](#3-update-management-php-layer)
* [4. System-Level Update (Python Layer)](#4-system-level-update-python-layer)
* [5. Update Completion](#5-update-completion)
* [6. Full Workflow Diagram](#6-full-workflow-diagram)
* [Key Features](#key-features)

---

## 1. Update Initiation

The process begins when the administrator clicks the **"Update"** button in the web interface.

* A signal named `update` is inserted into the `signals` table in the database.
* This signal acts as a **trigger** for the entire update procedure.

---

## 2. CRON Trigger

Every **minute**, the following CRON job runs:

```bash
/home/xc_vm/console.php cron:root_signals
```

The `root_signals` cron job checks for new signals.
When it detects an `update` signal, it launches:

```bash
/home/xc_vm/console.php update update

---

## 3. Update Management (PHP Layer)

Core logic resides in the `UpdateCommand` class:

```
src/cli/Commands/UpdateCommand.php
```

At this stage the following actions are performed:

1. Detect the **current panel type** (`MAIN` or `LB`).
2. Fetch update metadata from **GitHub**:
   * Direct link to the update archive.
   * SHA checksum for integrity verification.
3. Download the archive to a temporary directory.
4. Verify the downloaded file matches the expected hash.
5. Hand over control to the system-level updater (Python):

```bash
sudo /usr/bin/python3 /home/xc_vm/update "/home/xc_vm/tmp/.update.tar.gz" "HASH" > /dev/null 2>&1 &
```

> 💡 After the Python updater finishes, it calls `console.php update post-update` which triggers [database migrations](en-us/development/cli-tools.md#database-migrations) and post-update cleanup.

---

## 4. System-Level Update (Python Layer)

Control is transferred to the Python script:

```
/home/xc_vm/update
```

It performs privileged system operations:

1. **Re-verify** the archive checksum.
2. **Stop the panel** to prevent conflicts during update.
3. **Extract** the new version into:

   ```bash
   /home/xc_vm/
   ```
4. **Fix ownership**:

   ```bash
   chown -R xc_vm:xc_vm /home/xc_vm/
   ```
5. Run post-update tasks:

   ```bash
   /home/xc_vm/console.php update post-update
   ```
6. **Restart** the panel in normal operating mode.

---

## 5. Update Completion

Final steps are executed in the `post-update` phase of `UpdateCommand`:

1. If **LB auto-update** is enabled and the main node (`MAIN`) was updated → create `update` signals for all Load Balancers.
2. Update the **panel version** in the database.
3. Remove obsolete files.
4. Re-apply correct permissions:

   ```bash
   chown -R xc_vm:xc_vm /home/xc_vm/
   ```
5. Reload systemd daemons:

   ```bash
   sudo systemctl daemon-reload
   ```
6. Verify panel status:

   ```bash
   sudo /home/xc_vm/console.php status
   ```
7. Mark the update process as complete.

---

## 6. Full Workflow Diagram

```text
[ Web Interface ]
        │
        ▼
[ DB: "update" signal ]
        │
        ▼
[ CRON → console.php cron:root_signals ]
        │
        ▼
[ update.php (PHP) ]
        │
        ▼
[ update (Python) ]
        │
        ▼
[ post-update → update.php ]
        │
        ▼
[ Finalize, restart daemons, update version in DB ]
```

---

## Key Features

* **Double integrity check** (both PHP and Python layers verify the hash).
* **Automatic propagation** of updates from MAIN to all Load Balancers.
* **Cleanup** of deprecated files and permission normalization.
* **Safe panel restart** after installation.
* **Flexibility & autonomy** thanks to CRON + signal-based triggering.

---