# Backup Strategy

XC_VM supports automated and manual database backups with local storage and optional Dropbox upload.
Backups are managed through the admin panel, CLI commands, and a cron job.

---

## What Gets Backed Up

Backups contain the complete database structure and data, **except** the following tables:

```text
detect_restream_logs, epg_data, lines_activity, lines_live,
lines_logs, login_logs, mag_claims, mag_logs, mysql_syslog,
panel_logs, panel_stats, servers_stats, signals,
streams_errors, streams_logs, streams_stats, syskill_log,
users_credits_logs, users_logs, watch_logs
```

> **Note:** Restoring a backup clears all log data. These tables are excluded to keep backup sizes manageable. The admin action trail (`admin_audit`) is not one of them: a backup keeps it.

Backups do **not** include:

- File system data (recordings, VOD files, EPG XML)
- Configuration files (`config/`)
- Binary dependencies (`bin/`)
- Temporary files (`tmp/`)

---

## Configuration

Settings are in the admin panel under **Backups**:

| Setting | Default | Description |
| --- | --- | --- |
| `automatic_backups` | `daily` | frequency: `off`, `hourly`, `daily`, `weekly`, `monthly` |
| `backups_to_keep` | `0` | local retention count (0 = unlimited) |
| `dropbox_remote` | `0` | enable Dropbox upload |
| `dropbox_keep` | `10` | remote retention count (0 = unlimited) |
| `dropbox_token` | `''` | Dropbox API token |

---

## Creating Backups

### Manual (admin panel)

Click **Create Backup Now** in the backups page. This runs the cron job in force mode:

```bash
/home/xc_vm/console.php cron:backups 1
```

### Automatic (cron)

The `cron:backups` job checks the schedule on each run:

| Schedule | Interval |
| --- | --- |
| `hourly` | 3600s |
| `daily` | 86400s |
| `weekly` | 604800s |
| `monthly` | 2419200s |

Only runs on the main server (`is_main=1`). Uses PID-based locking to prevent overlapping runs.

### Backup process

1. Close MySQL connection before dump.
2. Run `mysqldump --no-data` (structure) + `mysqldump --ignore-table` (data, excluding log tables).
3. Keep the file only when the dump finished: a dump that failed or came out empty is deleted and does not count as a backup.
4. If Dropbox enabled: upload with status tracking.
5. Apply retention policy (delete oldest files exceeding limit).

### File location

```text
/home/xc_vm/backups/backup_YYYY-MM-DD_HH:MM:SS.sql
```

The same folder holds the dumps MAIN takes on its own, listed in the Backups table with the others:

- `pre_update_<from>_to_<to>_<timestamp>.sql`: before an update's migrations run. One is kept, the newest, and it counts toward *Local Backups to Keep*. After restoring it, run `sudo /home/xc_vm/console.php status` so the migrations are applied again.
- `pre_rollback_<from>_to_<to>_<timestamp>.sql`: before a rollback. It counts toward *Local Backups to Keep*.
- `pre_restore_<timestamp>.sql`: before a restore, see [Restoring Backups](#restoring-backups).

---

## Restoring Backups

### From admin panel

Click **Restore** on any backup entry. Requires confirmation.

Process:

1. If local file exists, use it. Otherwise download from Dropbox to `/home/xc_vm/tmp/restore.sql`.
2. Dump the live database to `/home/xc_vm/backups/pre_restore_YYYY-MM-DD_HH:MM:SS.sql`, the way back. When that dump fails (a full disk, a table mysqldump cannot read), nothing is changed and the page asks whether to restore anyway; the current data is then lost.
3. Drop and recreate the database.
4. Import the SQL file. The file restored from is left as it is.

```php
BackupService::restore($filename, $force = false) // true, false (the import failed) or null (refused: no dump of the live database)
```

> **Important:** Restore drops the entire database and recreates it. All data not in the backup will be lost.
>
> While the database is gone, `cron:cleanup` and `cron:streams` on MAIN, and on every load balancer that reads its stream lists from MAIN's database, cannot read those lists. They leave stream files, TV archives, running monitors and their encoders as they are, and streams are not checked or restarted until the database answers again. Restoring while streams are live is still not advised: a list read from a table that is only partly loaded is acted on.

### From CLI

For migration scenarios with selective table import:

```bash
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql
```

This restores to a `xc_vm_migrate` database for selective data migration, rather than overwriting the live database.

---

## Retention

### Local retention

- If `backups_to_keep > 0`: keeps only the N most recent files. Oldest deleted first.
- If `backups_to_keep = 0`: keeps all files (unlimited).
- Files are ordered by modification time. A run never deletes the dump it has just written.
- `pre_restore_*.sql` files, the dumps a restore takes of the live database first, are neither counted nor deleted: they stay in the Backups table until you delete them. A `.sql` file dated in the future counts as more recent than it, so the folder can hold one more than the limit until that date has passed.

### Remote retention

- If `dropbox_keep > 0`: keeps only the N most recent files on Dropbox. Oldest deleted first.
- If `dropbox_keep = 0`: keeps all remote files (unlimited).

Local cleanup runs every minute via `BackupsCronJob`; Dropbox cleanup runs after each backup the job makes.

---

## Dropbox Integration

File: `src/Core/Storage/DropboxClient.php`

When `dropbox_remote` is enabled:

1. After local backup creation, upload to Dropbox.
2. A `.uploading` marker file is created during upload.
3. On success: `.uploading` is deleted.
4. On failure: `.error` file is created with the error message.

Requests to Dropbox verify the server's certificate. An upload that fails certificate verification is reported like any other upload error: the backup shows the red indicator on the Backups page.

Admin panel status indicators:

| Indicator | Meaning |
| --- | --- |
| Green | successfully uploaded |
| Yellow | currently uploading (< 10 minutes old) |
| Red | upload failed (hover for error message) |
| Gray | not uploaded |

Methods:

```php
BackupService::checkRemoteConnection()        // validate Dropbox token
BackupService::uploadRemote($path, $filename, $overwrite = true)  // upload backup
BackupService::downloadRemote($path, $filename) // download backup
BackupService::deleteRemote($path)             // delete remote backup
BackupService::getRemote()                     // list remote backups
```

---

## CLI Commands

### cron:backups

Automated backup cron job. Can be forced with argument `1`:

```bash
sudo -u xc_vm /home/xc_vm/console.php cron:backups
sudo -u xc_vm /home/xc_vm/console.php cron:backups 1  # force
```

### tools migration

Restore a backup to a migration database for selective import:

```bash
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql
```

The backup always goes into `xc_vm_migrate`: `USE` and `CREATE DATABASE` statements in
the dump (written by `mysqldump --databases` / `--all-databases`) are left out, because
mysql would otherwise restore into the database they name — the live panel's, when
that is `xc_vm` — and leave `xc_vm_migrate` empty. The command fails if
`xc_vm_migrate` is still empty after the restore. Then run
`sudo /home/xc_vm/console.php migrate`.

### tools database --confirm

Reset to a blank database (destroys all data):

```bash
sudo /home/xc_vm/console.php tools database --confirm
```

### tools mysql

Re-authorize load balancers on MySQL:

```bash
sudo /home/xc_vm/console.php tools mysql
```

---

## API Endpoint

Action: `backup` (requires `adv:database` permission)

| Sub-action | Description |
| --- | --- |
| `backup` | trigger immediate backup (background) |
| `delete` | delete local backup + Dropbox copy |
| `restore` | restore database from backup; answers `{"result":false,"error":"safety_dump"}` when the live database could not be dumped first, and `force=1` restores without that dump |

---

## Cluster keys (MAIN replacement)

The database backup does not contain MAIN's **cluster keys**. When the cluster API is enabled, enrolled load balancers trust those keys, and `xcvm_core` seals them to MAIN's machine. A backup made on one machine cannot be read on another. Without a separate export, replacing MAIN's hardware means re-enrolling every node.

**Export** (on MAIN, after enabling the cluster API, and again whenever you like):

```bash
php console.php cluster:export-keys /root/cluster-keys.xcdr
```

- You type a passphrase twice; it is not echoed. It needs 20+ characters, or 12+ using three character classes. `--passphrase-file=<path>` reads it from a file instead.
- The bundle is written 0600 and never overwrites an existing file.
- The key derivation uses about 1 GiB of memory for a few seconds.
- Keep the bundle and the passphrase **apart**, and both off MAIN. The bundle opens only inside `xcvm_core`, and only with the passphrase.

**Import** (on the replacement MAIN, after restoring the database and before any node reconnects):

```bash
php console.php cluster:import-keys /root/cluster-keys.xcdr
```

- **Retire the old MAIN first.** Two MAINs sharing the same keys issue tokens independently, and a node revoked on one stays valid on the other.
- Revoked nodes stay revoked. The import refuses to replace a *different* set of keys already on the machine. Importing the same bundle twice changes nothing.
- Nodes re-key by themselves once they reach the new MAIN. Tokens issued by the old MAIN do not open on the new machine, and the agents replace them automatically.

**No bundle:** restore the database first. Then run `php console.php cluster:init` on the new MAIN, which creates new keys and records that the root changed. Then re-enrol every node over SSH with `cluster:reenrol`:

1. Write the nodes' SSH credentials to an owner-only file in `bin/install/`. The top level applies to every node; `nodes` overrides it per server ID:

    ```bash
    sudo -u xc_vm sh -c 'umask 077; cat > /home/xc_vm/bin/install/fleet.cred' <<'EOF'
    {"u": "root", "p": "root-password",
     "nodes": {"7": {"p": "other-password", "port": 2222, "hostkey": "SHA1:…"}}}
    EOF
    ```

    A node needs a `hostkey` when the database holds none for it (a node installed before host keys were recorded) or holds an old one (the node was rebuilt since). Read it on the node with `ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub`. A node with no key at all is never contacted.

    The SSH port is the node's `port`, else the file's top-level `port`, else 22. The panel does not keep the port a node was installed with, so give `port` for every node whose SSH server listens elsewhere.

2. Check what would happen. A dry run contacts no node and keeps the file:

    ```bash
    sudo -u xc_vm /home/xc_vm/console.php cluster:reenrol --all --cred-file=/home/xc_vm/bin/install/fleet.cred --dry-run
    ```

3. Re-enrol one node by ID and check that it comes up on *Servers → Cluster Nodes*. Then re-enrol the others, by ID or with `--all` (which takes the first node again). A run without `--dry-run` deletes the file as soon as it starts, even when it then refuses to run, so write the file again before each run. A file that other users can read is refused, and deleted too:

    ```bash
    sudo -u xc_vm /home/xc_vm/console.php cluster:reenrol 7 --cred-file=/home/xc_vm/bin/install/fleet.cred
    sudo -u xc_vm /home/xc_vm/console.php cluster:reenrol --all --cred-file=/home/xc_vm/bin/install/fleet.cred
    ```

- `--all` takes the nodes that are enrolling or active. Revoked and quarantined nodes stay as they are, unless you name them or pass `--state=`. A node you revoke while the run goes on is skipped when the run reaches it.
- `--all --pending` leaves out the nodes that are active and were enrolled since `cluster:init` created the new keys, so a run after the first node, or after a partial failure, takes only the rest. It refuses on a panel whose keys were created before this was recorded: name the nodes by ID there.
- Only one `cluster:reenrol` runs at a time, and a node is never enrolled by `server:enrol` and `cluster:reenrol` at once.
- A node that fails is listed with the reason, and the run goes on: for example a changed host key, a node that does not run this release yet, or a node that cannot reach MAIN's cluster API. Fix the cause and name the node in a new run. The node's agent was already stopped and given new keys if the run got as far as the reachability check, so it may not work again until that new run.
- A licence refusal stops the run before the next node.
- Each re-enrolled node starts over like a new node: in the mode *New Node Mode* (Settings → Cluster) gives, with every flow off. Switch its flows on again on *Servers → Cluster Nodes*.
- `server:enrol` still re-enrols a single node.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Backup/BackupService.php` | backup/restore logic |
| `src/Core/Storage/DropboxClient.php` | Dropbox API client |
| `src/Cli/CronJobs/BackupsCronJob.php` | automated backup cron |
| `src/Cli/Commands/ToolsCommand.php` | CLI migration and database tools |
| `src/Cli/Commands/ClusterExportKeysCommand.php`, `ClusterImportKeysCommand.php` | cluster keys export and import |
| `src/Cli/Commands/ClusterReenrolCommand.php` | re-enrols the fleet over SSH after a MAIN replaced without keys |
| `src/Public/Views/admin/backups.php` | admin panel UI |
| `src/Public/Views/admin/api.php` | API endpoint handler |
| `src/Public/Controllers/Admin/BackupsController.php` | admin controller |
