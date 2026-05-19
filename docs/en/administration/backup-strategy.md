# Backup Strategy

XC_VM supports automated and manual database backups with local storage and optional Dropbox upload.
Backups are managed through the admin panel, CLI commands, and a cron job.

---

## Navigation

- [What Gets Backed Up](#what-gets-backed-up)
- [Configuration](#configuration)
- [Creating Backups](#creating-backups)
- [Restoring Backups](#restoring-backups)
- [Retention](#retention)
- [Dropbox Integration](#dropbox-integration)
- [CLI Commands](#cli-commands)
- [Related Files](#related-files)

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

> **Note:** Restoring a backup clears all log data. These tables are excluded to keep backup sizes manageable.

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
| `automatic_backups` | `off` | frequency: `off`, `hourly`, `daily`, `weekly`, `monthly` |
| `backups_to_keep` | `0` | local retention count (0 = unlimited) |
| `dropbox_remote` | `0` | enable Dropbox upload |
| `dropbox_keep` | `0` | remote retention count (0 = unlimited) |
| `dropbox_token` | `''` | Dropbox API token |

---

## Creating Backups

### Manual (admin panel)

Click **Create Backup Now** in the backups page. This runs the cron job in force mode:

```bash
php /home/xc_vm/console.php cron:backups 1
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
3. Validate file size (empty files are deleted).
4. If Dropbox enabled: upload with status tracking.
5. Apply retention policy (delete oldest files exceeding limit).

### File location

```text
/home/xc_vm/backups/backup_YYYY-MM-DD_HH:MM:SS.sql
```

---

## Restoring Backups

### From admin panel

Click **Restore** on any backup entry. Requires confirmation.

Process:

1. If local file exists, use it. Otherwise download from Dropbox to `/home/xc_vm/tmp/restore.sql`.
2. Drop and recreate the database.
3. Import the SQL file.
4. Re-dump structure after import.

```php
BackupService::restore($filename, $config)
```

> **Important:** Restore drops the entire database and recreates it. All data not in the backup will be lost.

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

### Remote retention

- If `dropbox_keep > 0`: keeps only the N most recent files on Dropbox. Oldest deleted first.
- If `dropbox_keep = 0`: keeps all remote files (unlimited).

Cleanup runs automatically after each backup via `BackupsCronJob`.

---

## Dropbox Integration

File: `src/core/Storage/DropboxClient.php`

When `dropbox_remote` is enabled:

1. After local backup creation, upload to Dropbox.
2. A `.uploading` marker file is created during upload.
3. On success: `.uploading` is deleted.
4. On failure: `.error` file is created with the error message.

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
BackupService::uploadRemote($path, $filename)  // upload backup
BackupService::downloadRemote($path, $filename) // download backup
BackupService::deleteRemote($path)             // delete remote backup
BackupService::getRemote()                     // list remote backups
```

---

## CLI Commands

### cron:backups

Automated backup cron job. Can be forced with argument `1`:

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:backups
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:backups 1  # force
```

### tools migration

Restore a backup to a migration database for selective import:

```bash
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql
```

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
| `restore` | restore database from backup |

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Backup/BackupService.php` | backup/restore logic |
| `src/core/Storage/DropboxClient.php` | Dropbox API client |
| `src/cli/CronJobs/BackupsCronJob.php` | automated backup cron |
| `src/cli/Commands/ToolsCommand.php` | CLI migration and database tools |
| `src/public/Views/admin/backups.php` | admin panel UI |
| `src/public/Views/admin/api.php` | API endpoint handler |
| `src/public/Controllers/Admin/BackupsController.php` | admin controller |
