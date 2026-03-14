<h1 align="center">🛠 CLI Tools & Database Migrations</h1>

<p align="center">
  Reference for XC_VM command-line interface, system tools, and the database migration system.<br>
  Covers daily operations, emergency access, and creating new DB migrations.
</p>

---

## 📚 Navigation

* [🖥 Console Entry Point](#console-entry-point)
* [� Full Command Registry](#full-command-registry)
* [🆕 Registering a New Command](#registering-a-new-command)
* [🔧 Tools Command](#tools-command)
* [🗃 Database Migrations](#database-migrations)
* [📝 Creating a New Migration](#creating-a-new-migration)
* [⚙️ Common CLI Operations](#common-cli-operations)

---

## 🖥 Console Entry Point

All CLI commands are executed through `console.php`:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php <command> [args...]
```

The console supports three types of commands:

| Type | Count | Description |
|---|---|---|
| **Commands** | 26 | One-time operations (update, status, tools, etc.) |
| **CronJobs** | 25 | Scheduled tasks (auto-invoked by crontab) |
| **Daemons** | 8 | Long-running background processes (Commands using `DaemonTrait`) |

> **Note:** Daemons are regular Commands that use `DaemonTrait`. There is no separate `Daemons/` directory.

To see all available commands:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php list
```

---

## 📋 Full Command Registry

### Utility Commands

| Command | Class | Description | User |
|---|---|---|---|
| `status` | `StatusCommand` | System status, DB migrations, configuration check | root |
| `update` | `UpdateCommand` | System update (update / post-update) | xc_vm |
| `service` | `ServiceCommand` | Manage XC_VM service: start, stop, restart, reload | root |
| `tools` | `ToolsCommand` | Maintenance utilities (see [Tools Command](#tools-command)) | root/xc_vm |
| `certbot` | `CertbotCommand` | Generate SSL certificate via certbot | root |
| `binaries` | `BinariesCommand` | Update binaries and GeoLite DB from GitHub | xc_vm |
| `startup` | `StartupCommand` | System initialization: daemons.sh, crontab, cache | root |
| `monitor` | `MonitorCommand` | Monitor stream by ID (start/restart/track) | xc_vm |
| `thumbnail` | `ThumbnailCommand` | Generate thumbnail frames for a stream | xc_vm |
| `plex_item` | `PlexItemCommand` | Process single Plex item (movie/series) | xc_vm |
| `watch_item` | `WatchItemCommand` | Process single Watch item (TMDB search/update) | xc_vm |
| `migrate` | `MigrateCommand` | Migrate data from `xc_vm_migrate` database | xc_vm |
| `balancer` | `BalancerCommand` | Install/configure load balancer via SSH | root |

> Commands marked **optional** are conditionally registered via `file_exists()` guard: `cache_handler`, `balancer`, `migrate`.

### Daemon Commands (persistent processes)

These commands use `DaemonTrait` and run continuously via `while(true)` loops:

| Command | Class | Description |
|---|---|---|
| `signals` | `SignalsCommand` | Process kill/cache signals from DB and Redis |
| `watchdog` | `WatchdogCommand` | System monitoring: CPU, connections, server updates |
| `queue` | `QueueCommand` | Process background queue tasks |
| `scanner` | `ScannerCommand` | Scan for new streams/devices |
| `cache_handler` | `CacheHandlerCommand` | Handle cache operations (optional) |

### Stream Processing Commands

| Command | Class | Description |
|---|---|---|
| `proxy` | `ProxyCommand` | MPEG-TS stream proxying via sockets |
| `archive` | `ArchiveCommand` | TV Archive — record stream into segments |
| `created` | `CreatedCommand` | Created Channel — compose channel from sources |
| `delay` | `DelayCommand` | Delay HLS stream playback |
| `loopback` | `LoopbackCommand` | Receive MPEG-TS from another server |
| `llod` | `LlodCommand` | Low-Latency On-Demand stream processor |
| `record` | `RecordCommand` | Record stream to MP4 |
| `ondemand` | `OndemandCommand` | Kill streams with no active viewers |

### Cron Jobs (25 total)

All cron job names are prefixed with `cron:`. They use `CronTrait` and are invoked by the system crontab.

| Command | Class | Description |
|---|---|---|
| `cron:activity` | `ActivityCronJob` | Import user activity logs into DB |
| `cron:backups` | `BackupsCronJob` | Manage backups (optional) |
| `cron:cache` | `CacheCronJob` | Cache management |
| `cron:cache_engine` | `CacheEngineCronJob` | Generate cache for lines, streams, series, groups (optional) |
| `cron:certbot` | `CertbotCronJob` | SSL certificate renewal |
| `cron:cleanup` | `CleanupCronJob` | Cleanup temporary files and logs |
| `cron:epg` | `EpgCronJob` | EPG download and processing (optional) |
| `cron:errors` | `ErrorsCronJob` | Process error logs |
| `cron:lines_logs` | `LinesLogsCronJob` | Import client request logs into DB |
| `cron:plex` | `PlexCronJob` | Process Plex updates |
| `cron:providers` | `ProvidersCronJob` | Update providers (optional) |
| `cron:root_mysql` | `RootMysqlCronJob` | Database maintenance (root, optional) |
| `cron:root_signals` | `RootSignalsCronJob` | Process signals, iptables, nginx, service management (root) |
| `cron:series` | `SeriesCronJob` | Update series data (optional) |
| `cron:servers` | `ServersCronJob` | Monitor server, launch daemons, update statistics |
| `cron:stats` | `StatsCronJob` | Calculate and store statistics |
| `cron:streams` | `StreamsCronJob` | Verify and update stream status |
| `cron:streams_logs` | `StreamsLogsCronJob` | Import stream logs |
| `cron:tmdb` | `TmdbCronJob` | Fetch TMDB metadata (optional) |
| `cron:tmdb_popular` | `TmdbPopularCronJob` | Fetch popular TMDB content (optional) |
| `cron:tmp` | `TmpCronJob` | Cleanup temporary files |
| `cron:update` | `UpdateCronJob` | Check and apply updates (optional) |
| `cron:users` | `UsersCronJob` | Manage user connections, Redis sync, divergence |
| `cron:vod` | `VodCronJob` | Process VOD content |
| `cron:watch` | `WatchCronJob` | Process Watch library updates |

> Optional cron jobs (conditionally registered): `cron:backups`, `cron:cache_engine`, `cron:epg`, `cron:providers`, `cron:root_mysql`, `cron:series`, `cron:tmdb`, `cron:tmdb_popular`, `cron:update`.

---

## 🆕 Registering a New Command

All CLI commands implement `CommandInterface` and are explicitly registered in `console.php`.

### CommandInterface

```php
interface CommandInterface {
    public function getName(): string;        // Unique command name (used in CLI)
    public function getDescription(): string; // One-line help text (shown in `list`)
    public function execute(array $rArgs): int; // Entry point, returns exit code
}
```

### Step 1. Create the Class

Create a new file in `src/cli/Commands/` (or `src/cli/CronJobs/` for cron jobs):

```php
<?php

class MyNewCommand implements CommandInterface {

    public function getName(): string {
        return 'my_command';
    }

    public function getDescription(): string {
        return 'Short description of what it does';
    }

    public function execute(array $rArgs): int {
        // Your logic here
        echo "Done.\n";
        return 0; // 0 = success, 1 = error
    }
}
```

For **daemon** commands, also use `DaemonTrait`:

```php
class MyDaemonCommand implements CommandInterface {
    use DaemonTrait;
    // ...
}
```

For **cron jobs**, use `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_job'; // Cron names are prefixed with cron:
    }
    // ...
}
```

### Step 2. Register in console.php

Add to `console.php`:

```php
// Always loaded
$rRegistry->register(new MyNewCommand());

// Or conditionally (for optional features)
if (file_exists(CLI_PATH . 'Commands/MyNewCommand.php')) {
    $rRegistry->register(new MyNewCommand());
}
```

### Step 3. Add to Makefile (if LB-excluded)

If the command should NOT be included in Load Balancer builds, add its path to `LB_FILES_TO_REMOVE` in the `Makefile`.

### Step 4. Test

```bash
# Verify it appears in the list
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php list

# Run it
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php my_command
```

---

## 🔧 Tools Command

The `tools` command provides system maintenance utilities.

```bash
console.php tools <subcommand>
```

### Subcommands (run as `root`)

| Subcommand | Description |
|---|---|
| `rescue` | Create a temporary rescue access code for emergency panel access. Prints the URL. **Delete this code after use!** |
| `access` | Regenerate all nginx access code configs and reload nginx. Prints URLs for all admin panel codes. |
| `ports` | Regenerate nginx port configs (HTTP, HTTPS, RTMP) from the database and reload nginx. |
| `migration` | Clear the migration database (`xc_vm_migrate`) and optionally restore a `.sql` backup into it. |
| `user` | Create a rescue admin user with random credentials. Prints username and password. **Delete this user after use!** |
| `mysql` | Reauthorise MySQL privileges for all load balancer servers. |
| `database` | Restore a blank XC_VM database from `database.sql`. **Erases ALL data!** Requires `--confirm` flag. |
| `flush` | Flush all blocked IPs — clears iptables rules, removes block files, and truncates the `blocked_ips` table. |

### Subcommands (run as `xc_vm`)

| Subcommand | Description |
|---|---|
| `images` | Download missing stream/movie/series images from TMDB. Scans DB for image URLs and downloads missing files. |
| `duplicates` | Find and remove duplicate VOD streams. Groups by identical source, keeps first, deletes rest. **Destructive!** |
| `bouquets` | Clean stale references from bouquets. Removes IDs that no longer exist in the database. |

### Examples

```bash
# Emergency panel access (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools rescue

# Regenerate access codes (root) — required after nginx template changes
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools access

# Regenerate port configuration (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools ports

# Clear migration database (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools migration

# Clear migration database and restore a backup (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools migration /path/to/backup.sql

# Create rescue admin user (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools user

# Reauthorise MySQL privileges on all servers (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools mysql

# Restore blank database (root) — DESTRUCTIVE!
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools database --confirm

# Flush all blocked IPs (root)
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools flush

# Download missing images (xc_vm)
su - xc_vm -c '/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools images'

# Remove duplicate VOD entries (xc_vm)
su - xc_vm -c '/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools duplicates'

# Clean orphaned bouquet references (xc_vm)
su - xc_vm -c '/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php tools bouquets'
```

> ⚠️ **Warning:** `duplicates` permanently deletes streams and all associated data (logs, stats, episodes, recordings). Always back up before running.

> ⚠️ **Warning:** `database --confirm` erases the entire database and replaces it with a blank schema. This is irreversible.

> 💡 **Tip:** After running `rescue`, always delete the code through the admin panel or by running `tools access` once you have regained access.

> 💡 **Tip:** After running `user`, change the password immediately and delete the rescue user when done.

---

## 🗃 Database Migrations

XC_VM uses a file-based migration system to manage database schema changes between versions. Migrations are executed automatically during updates and system status checks.

### How It Works

1. Migration files are stored as `.sql` files in:
   ```
   /home/xc_vm/migrations/
   ```

2. Each file is named with a sequential number prefix:
   ```
   001_drop_watch_folders_plex_token.sql
   002_panel_logs_add_file_env.sql
   003_drop_settings_segment_type.sql
   ```

3. Applied migrations are tracked in the `migrations` database table. Each migration runs **exactly once** — if a migration has already been applied, it is skipped.

4. Migrations are executed automatically by:
   - `console.php update post-update` — after a panel update
   - `console.php status` — during system status check (MAIN server only)

### Migration Execution Flow

```text
[ MigrationRunner::run() ]
        │
        ▼
[ CREATE TABLE IF NOT EXISTS `migrations` ]
        │
        ▼
[ Read all *.sql files from migrations/ ]
        │
        ▼
[ For each file not in `migrations` table: ]
    ├── Execute SQL statements
    ├── Record in `migrations` table
    └── Output [OK] or [WARN]
```

---

## 📝 Creating a New Migration

When you need to modify the database schema (add columns, create tables, insert data, etc.), create a new SQL migration file.

### Step 1. Choose a File Name

Use the next sequential number and a descriptive name:

```
NNN_short_description.sql
```

**Format rules:**
- Number prefix: 3 digits, zero-padded (e.g., `006`, `007`)
- Separator: underscore `_`
- Name: lowercase, underscores, describing what the migration does
- Extension: `.sql`

**Examples:**
```
006_add_user_timezone.sql
007_create_audit_log_table.sql
008_insert_default_codec_settings.sql
```

### Step 2. Write the SQL

Place raw SQL statements in the file. Multiple statements are separated by `;`.

**Rules for migration SQL:**

1. **Use `IF EXISTS` / `IF NOT EXISTS`** to make migrations idempotent:

```sql
-- Adding a column (safe)
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) DEFAULT 'UTC';

-- Dropping a column (safe)
ALTER TABLE `settings` DROP COLUMN IF EXISTS `old_column`;

-- Creating a table (safe)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

2. **Use conditional `INSERT`** to avoid duplicates:

```sql
INSERT INTO `streams_arguments` (argument_key, argument_name, argument_cmd)
SELECT 'my_key', 'My Argument', '-my_flag %s'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE argument_key = 'my_key');
```

3. **Do not mix DDL and DML** that depend on each other in the same file. If you need to add a column and then populate it — use two migration files.

4. **Comments** are supported with `--` prefix (they are skipped during execution).

### Step 3. Place the File

Copy the migration file to:

```
/home/xc_vm/migrations/
```

> 💡 In the source repository, this is `src/migrations/`.

### Step 4. Test

Run the status command to apply pending migrations:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status first-run
```

Expected output:

```
Migrations
------------------------------
  [OK]   006_add_user_timezone.sql

```

If a statement fails, the migration will still be recorded but show `[WARN]` — review the SQL and fix any issues.

---

## ⚙️ Common CLI Operations

### Status Check

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status
```

Checks if XC_VM is running, connects to the database, runs pending migrations, fixes permissions, and validates nginx configuration. Required after installation or recovery.

With `first-run` argument, skips the running check — used for initial setup:

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status first-run
```

### Service Management

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php service start|stop|restart|reload
```

### Manual Update

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php update update
```

Downloads and applies the latest update from GitHub. Usually triggered automatically through the web panel.

### Stream Diagnostics

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php monitor <stream_id>
```

Starts a stream manually and displays any errors. Useful for diagnosing stream startup failures.

### SSL Certificate

```bash
sudo /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php certbot
```

### Migration (from Other Systems)

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php migrate
```

Transfers data from a migration database. See the [Migration Guide](en-us/info/migration_guide.md) for details.

---
