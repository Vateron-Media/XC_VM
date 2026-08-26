# Update Mechanism in XC_VM

The XC_VM update system is implemented as a multi-layered process, from the web interface to system-level scripts. This approach ensures reliability, automation, and data integrity during panel updates.

> 📋 For a step-by-step guide with screenshots, see [Updating a Server](../administration/server-update.md).

---

## 1. Update Initiation

The process begins when the administrator clicks the **"Update"** button in the web interface.

- A signal named `update` is inserted into the `signals` table in the database.
- This signal acts as a **trigger** for the entire update procedure.

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
```

---

## 3. Update Management (PHP Layer)

Core logic resides in the `UpdateCommand` class:

```text
src/Cli/Commands/UpdateCommand.php
```

At this stage the following actions are performed:

1. Detect the **current panel type** (`MAIN` or `LB`).
2. Fetch update metadata from **GitHub**:
   - Direct link to the update archive.
   - SHA checksum for integrity verification.
3. Download the archive to a temporary directory.
4. Verify the downloaded file matches the expected hash.
5. Hand over control to the system-level updater (Python):

```bash
sudo /usr/bin/python3 /home/xc_vm/update "/home/xc_vm/tmp/.update.tar.gz" "HASH" > /dev/null 2>&1 &
```

> 💡 After the Python updater finishes, it calls `console.php update post-update` which triggers [database migrations](../guides/cli-tools.md#database-updates-after-version-upgrade) and post-update cleanup.

---

## 4. System-Level Update (Python Layer)

Control is transferred to the Python script:

```text
/home/xc_vm/update
```

It performs privileged system operations:

1. **Re-verify** the archive checksum.
2. **Stop the panel** to prevent conflicts during update.
3. **Extract** the archive into a temporary directory:

   ```bash
   /tmp/xc_vm_update_*/
   ```

4. **Remove excluded directories** from the temp copy — binaries, configs, and user data that must not be overwritten:

   `bin/ffmpeg_bin`, `bin/nginx`, `bin/nginx_rtmp`, `bin/php`, `bin/redis`, `bin/install`, `bin/maxmind`, `bin/certbot`, `content`, `backups`, `tmp`, `config`, `signals`

5. **Copy remaining files** over the live installation:

   ```bash
   cp -a /tmp/xc_vm_update_*/. /home/xc_vm/
   ```

6. **Fix ownership**:

   ```bash
   chown -R xc_vm:xc_vm /home/xc_vm/
   ```

7. Run post-update tasks:

   ```bash
   /home/xc_vm/console.php update post-update
   ```

8. **Restart** the panel in normal operating mode.
9. **Cleanup** the temporary directory and delete the archive.

> ℹ️ The same archive is used for both installation and update. Filtering happens on the server at update time — the exclude list is defined directly in `src/update`.

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
[ UpdateCommand (PHP): download + verify hash ]
        │
        ▼
[ update (Python): extract to /tmp → remove excluded → copy over ]
        │
        ▼
[ post-update → UpdateCommand ]
        │
        ▼
[ Finalize, restart daemons, update version in DB ]
```

---

## Rollback (Downgrade)

A server can also be rolled back to an **earlier** release. This mirrors the update flow above but targets a chosen version instead of the latest — the same signal → CRON → PHP → Python pipeline and the same `src/update` applier are reused. Rollback is per-server, so `MAIN` and each `LB` can be downgraded independently.

1. **Initiation.** In **Servers → Manage Servers**, the per-server actions menu has a **Rollback Version** item. It opens a dialog listing earlier releases (pre-releases tagged `(beta)`), fetched via the `rollback_versions` API action (`GitHubReleases::getPreviousVersions()`). Choosing a version inserts a signal — `{"action":"rollback","version":"X.Y.Z"}` — for that server.

2. **CRON trigger.** `cron:root_signals` handles the `rollback` signal by launching:

   ```bash
   /home/xc_vm/console.php update rollback X.Y.Z
   ```

3. **PHP layer (`UpdateCommand`, `rollback` case).**
   - Validate the target (`X.Y.Z`, strictly older than the current version).
   - On **MAIN** only: take an automatic database backup to `backups/pre_rollback_<from>_to_<to>_<timestamp>.sql`, aborting if it fails. LB nodes have no database and skip this.
   - Resolve the **exact** version's archive via `GitHubReleases::getVersionFile()` (MAIN → `xc_vm.tar.gz`, LB → `loadbalancer.tar.gz`), download it, and verify the MD5.
   - Hand over to the same Python updater (`src/update`).

4. **System + completion.** Identical to an update: the Python script stops the panel, replaces the tree (preserving binaries/config/data), and `post-update` sets the version in the database to the rolled-back release and restarts the panel.

The version list is channel-aware: the `stable` channel offers only stable releases, `unstable` also offers `(beta)` pre-releases.

> ⚠️ A downgrade **does not undo database migrations** (they are forward-only). The schema is kept backward-compatible, and the automatic MAIN backup is the recovery path. The Python applier copies over the tree (`cp -a`) without deleting files, so files added by a newer release remain until a subsequent update.

---

## Key Features

- **Double integrity check** (both PHP and Python layers verify the hash).
- **Automatic propagation** of updates from MAIN to all Load Balancers.
- **Cleanup** of deprecated files and permission normalization.
- **Safe panel restart** after installation.
- **Flexibility & autonomy** thanks to CRON + signal-based triggering.

---
