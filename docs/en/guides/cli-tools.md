# CLI Tools & Console Reference

Reference for XC_VM command-line interface, system tools, and the database update process after version upgrades. Covers daily operations, emergency access, and creating new DB update steps.

---

## Console Entry Point

All CLI commands are executed through `console.php`:

```bash
/home/xc_vm/console.php <command> [args...]
```

The console supports three types of commands:

| Type | Count | Description |
| --- | --- | --- |
| **Commands** | 28 | One-time operations (update, status, tools, etc.) |
| **CronJobs** | 25 | Scheduled tasks (auto-invoked by crontab) |
| **Daemons** | 8 | Long-running background processes (Commands using `DaemonTrait`) |

> **Note:** Daemons are regular Commands that use `DaemonTrait`. There is no separate `Daemons/` directory.

To see all available commands:

```bash
/home/xc_vm/console.php list
```

---

## Full Command Registry

### Utility Commands

| Command | Class | Description | User |
| --- | --- | --- | --- |
| `status` | `StatusCommand` | System status, DB updates, configuration check | root |
| `update` | `UpdateCommand` | System update (update / post-update) | xc_vm |
| `service` | `ServiceCommand` | Manage XC_VM service: start, stop, restart, reload | root |
| `tools` | `ToolsCommand` | Maintenance utilities (see Tools Command section) | root/xc_vm |
| `certbot` | `CertbotCommand` | Generate SSL certificate via certbot | root |
| `binaries` | `BinariesCommand` | Update the runtime bundle (php/nginx/…) from the `XC_VM_Binaries` release | xc_vm |
| `fanout_binary` | `FanoutBinaryCommand` | Install/update the `xc_fanout` daemon binary from its release | root |
| `xcvm_core` | `XcvmCoreCommand` | Install/update the `xcvm_core` PHP extension from the binaries repo | root |
| `ytdlp` | `YtDlpCommand` | Install/update `yt-dlp` from its upstream GitHub release | root |
| `startup` | `StartupCommand` | System initialization: daemons.sh, crontab, cache | root |
| `monitor` | `MonitorCommand` | Monitor stream by ID (start/restart/track) | xc_vm |
| `thumbnail` | `ThumbnailCommand` | Generate thumbnail frames for a stream | xc_vm |
| `plex_item` | `PlexItemCommand` | Process single Plex item (movie/series) | xc_vm |
| `watch_item` | `WatchItemCommand` | Process single Watch item (TMDB search/update) | xc_vm |
| `migrate` | `MigrateCommand` | Transfer data from `xc_vm_migrate` database | xc_vm |
| `db:migrate` | `DbMigrateCommand` | Apply pending database migrations from the `migrations/` directory | xc_vm |
| `server:install` | `ServerInstallCommand` | Install/configure server (Proxy/LB) via SSH | root |
| `server:diagnose` | `ServerDiagnoseCommand` | Diagnose why a proxy/LB node is silent to the main (heartbeat, reachability, iptables, service) | root |

> `console.php` registers **every** class it discovers in `Cli/Commands/` and `Cli/CronJobs/` (glob + reflection) — there is **no** `file_exists()` guard. A command is "optional" only in that it may be **stripped from the LB build** (`Makefile` `LB_FILES_TO_REMOVE`) or **provided by an installed module**. `plex_item` and `watch_item` above are **module-provided** (Plex/Watch) — their command classes are not in the committed core tree and exist only when that module is installed.

### Daemon Commands (persistent processes)

These commands use `DaemonTrait` and run continuously via `while(true)` loops:

| Command | Class | Description |
| --- | --- | --- |
| `signals` | `SignalsCommand` | Process kill/cache signals from DB and Redis |
| `watchdog` | `WatchdogCommand` | System monitoring: CPU, connections, server updates |
| `queue` | `QueueCommand` | Process background queue tasks |
| `scanner` | `ScannerCommand` | Scan for new streams/devices |
| `cache_handler` | `CacheHandlerCommand` | Handle cache operations (optional) |

### Stream Processing Commands

| Command | Class | Description |
| --- | --- | --- |
| `proxy` | `ProxyCommand` | MPEG-TS stream proxying via sockets |
| `archive` | `ArchiveCommand` | TV Archive — record stream into segments |
| `created` | `CreatedCommand` | Created Channel — compose channel from sources |
| `delay` | `DelayCommand` | Delay HLS stream playback |
| `loopback` | `LoopbackCommand` | Receive MPEG-TS from another server |
| `llod` | `LlodCommand` | Low-Latency On-Demand stream processor |
| `record` | `RecordCommand` | Record stream to MP4 |
| `ondemand` | `OndemandCommand` | Kill streams with no active viewers |

### Cron Jobs

> The command/cron/daemon tables below are hand-maintained and can drift. The source of truth is `console.php list` — run it to see the live registry.

All cron job names are prefixed with `cron:`. They use `CronTrait` and are invoked by the system crontab.

**Core cron jobs** (in `src/Cli/CronJobs/`):

| Command | Class | Description |
| --- | --- | --- |
| `cron:activity` | `ActivityCronJob` | Import user activity logs into DB |
| `cron:backups` | `BackupsCronJob` | Manage backups (optional) |
| `cron:cache` | `CacheCronJob` | Cache management |
| `cron:cache_engine` | `CacheEngineCronJob` | Generate cache for lines, streams, series, groups (optional) |
| `cron:certbot` | `CertbotCronJob` | SSL certificate renewal |
| `cron:cleanup` | `CleanupCronJob` | Cleanup temporary files and logs |
| `cron:epg` | `EpgCronJob` | EPG download and processing (optional) |
| `cron:errors` | `ErrorsCronJob` | Process error logs |
| `cron:lines_logs` | `LinesLogsCronJob` | Import client request logs into DB |
| `cron:maxmind` | `MaxMindCronJob` | Update MaxMind GeoIP databases (Tuesdays only; `--force` to run manually) |
| `cron:providers` | `ProvidersCronJob` | Update providers (optional) |
| `cron:root_mysql` | `RootMysqlCronJob` | Database maintenance (root, optional) |
| `cron:root_signals` | `RootSignalsCronJob` | Process signals, iptables, nginx, service management, and **binary self-heal** (root) |
| `cron:series` | `SeriesCronJob` | Update series data (optional) |
| `cron:servers` | `ServersCronJob` | Monitor server, launch daemons, update statistics |
| `cron:stats` | `StatsCronJob` | Calculate and store statistics |
| `cron:streams` | `StreamsCronJob` | Verify and update stream status |
| `cron:streams_logs` | `StreamsLogsCronJob` | Import stream logs |
| `cron:tmp` | `TmpCronJob` | Cleanup temporary files |
| `cron:update` | `UpdateCronJob` | Check and apply updates (optional) |
| `cron:users` | `UsersCronJob` | Manage user connections, Redis sync, divergence |
| `cron:vod` | `VodCronJob` | Process VOD content |
| `cron:proxy` | `ProxyArchiveCronJob` | Archive/rotate proxy stream data |
| `cron:module_licenses` | `ModuleLicensesCronJob` | Refresh installed-module licenses |
| `cron:module_updates` | `ModuleUpdatesCronJob` | Check for module updates |
| `cron:tmdb` | `TmdbCronJob` | Fetch TMDB metadata (optional) |
| `cron:tmdb_popular` | `TmdbPopularCronJob` | Fetch popular TMDB content (optional) |

**Module-provided cron jobs.** Registered by optional modules via `CronProviderInterface::getCronEntries()`; they exist only when that module is installed and are **not** in the committed core tree (`src/Modules/` ships empty). (`cron:tmdb`/`cron:tmdb_popular` are **core**, listed above — not module cron jobs.)

| Command | Class | Module | Description |
| --- | --- | --- | --- |
| `cron:plex` | `PlexCronJob` | plex | Process Plex updates |
| `cron:watch` | `WatchCronJob` | watch | Process Watch library updates |

> "Optional" cron jobs are **not** conditionally registered — every discovered `CronJob` class is registered. "Optional" means the job no-ops unless its feature/setting is enabled (e.g. `cron:epg`, `cron:series`, `cron:update`), or the job is stripped from the LB build.

---

## Binary self-update (self-heal)

Some bundled binaries are **not** shipped inside the heavy runtime bundle and would
otherwise never refresh between panel releases (a fresh LB node, or a node left on
an old build, would never converge). `cron:root_signals` (root, every minute) keeps
them current by polling their idempotent per-binary updater commands on a
stamp-throttled schedule — each downloads only on a version mismatch, verifies a
checksum, run-tests the new binary, then swaps it in atomically (a broken download
never replaces a working one). Runs on every node (main **and** LB).

| Binary | Command | Source | Verify | Poll |
| --- | --- | --- | --- | --- |
| `xc_fanout` daemon | `fanout_binary` | `XC_VM_Fanout` release asset | `SHA256SUMS` | ~hourly |
| `xcvm_core` extension | `xcvm_core` | `XC_VM_Binaries` repo tree (`bin/xcvm_core/`) | `SHA256SUMS` + load-test | ~hourly |
| `yt-dlp` | `ytdlp` | upstream `yt-dlp/yt-dlp` release | `SHA2-256SUMS` + `--version` | daily |

Stamps live in `CRONS_TMP_PATH` (`fanout_binary_check`, `xcvm_core_check`,
`ytdlp_check`); the first pass (stamp absent) runs immediately, so a fresh
install/LB gets the binary within a minute. The heavy runtime bundle
(php/nginx/ffmpeg) is instead refreshed by the `binaries` command, triggered by an
`update_binaries` signal from MAIN.

---

## Registering a New Command

All CLI commands implement `CommandInterface`. Core commands are auto-discovered from `src/Cli/` via reflection in `console.php`. Module commands are registered via `ModuleLoader::registerAllCommands()`.

### CommandInterface

```php
interface CommandInterface {
    public function getName(): string;        // Unique command name (used in CLI)
    public function getDescription(): string; // One-line help text (shown in `list`)
    public function execute(array $rArgs): int; // Entry point, returns exit code
}
```

### Step 1. Create the Class

Create a new file in `src/Cli/Commands/` (or `src/Cli/CronJobs/` for cron jobs):

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

### Step 2. Registration is automatic

There is **nothing to add to `console.php`**. On startup it globs `Cli/Commands/*.php` and
`Cli/CronJobs/*.php` and, via reflection, `register()`s every non-abstract class implementing
`CommandInterface`. Dropping your class in the right directory (with a `getName()` that returns
its command name) is all that is required — see [Core Wiring → CLI command registration](../development/core-wiring.md#cli-command-registration).

### Step 3. Add to Makefile (if LB-excluded)

If the command should NOT be included in Load Balancer builds, add its path to `LB_FILES_TO_REMOVE` in the `Makefile`.

### Step 4. Test

```bash
# Verify it appears in the list
/home/xc_vm/console.php list

# Run it
/home/xc_vm/console.php my_command
```

---

## Tools Command

The `tools` command provides system maintenance utilities.

```bash
/home/xc_vm/console.php  tools <subcommand>
```

### Subcommands (run as `root`)

| Subcommand | Description |
| --- | --- |
| `rescue` | Create a temporary rescue access code for emergency panel access. Prints the URL. **Delete this code after use!** |
| `recaptcha` | Disable reCAPTCHA (`recaptcha_enable = 0`) to restore admin panel login when captcha verification is failing. |
| `access` | Regenerate all nginx access code configs and reload nginx. Prints URLs for all admin panel codes. |
| `ports` | Regenerate nginx port configs (HTTP, HTTPS, RTMP) from the database and reload nginx. |
| `migration` | Clear the staging database (`xc_vm_migrate`) and optionally restore a `.sql` backup into it. |
| `user` | Create a rescue admin user with random credentials. Prints username and password. **Delete this user after use!** |
| `mysql` | Reauthorise MySQL privileges for all load balancer servers. |
| `database` | Restore a blank XC_VM database from `database.sql`. **Erases ALL data!** Requires `--confirm` flag. |
| `flush` | Flush all blocked IPs — clears iptables rules, removes block files, and truncates the `blocked_ips` table. |

### Subcommands (run as `xc_vm`)

| Subcommand | Description |
| --- | --- |
| `images` | Download missing stream/movie/series images from TMDB. Scans DB for image URLs and downloads missing files. |
| `duplicates` | Find and remove duplicate VOD streams. Groups by identical source, keeps first, deletes rest. **Destructive!** |
| `bouquets` | Clean stale references from bouquets. Removes IDs that no longer exist in the database. |

### Examples

```bash
# Emergency panel access (root)
sudo /home/xc_vm/console.php tools rescue

# Disable reCAPTCHA to recover admin login (root)
sudo /home/xc_vm/console.php tools recaptcha

# Regenerate access codes (root) — required after nginx template changes
sudo /home/xc_vm/console.php tools access

# Regenerate port configuration (root)
sudo /home/xc_vm/console.php tools ports

# Clear staging database (root)
sudo /home/xc_vm/console.php tools migration

# Clear staging database and restore a backup (root)
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql

# Create rescue admin user (root)
sudo /home/xc_vm/console.php tools user

# Reauthorise MySQL privileges on all servers (root)
sudo /home/xc_vm/console.php tools mysql

# Restore blank database (root) — DESTRUCTIVE!
sudo /home/xc_vm/console.php tools database --confirm

# Flush all blocked IPs (root)
sudo /home/xc_vm/console.php tools flush

# Download missing images (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools images'

# Remove duplicate VOD entries (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools duplicates'

# Clean orphaned bouquet references (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools bouquets'
```

- ⚠️ **Warning:** `duplicates` permanently deletes streams and all associated data (logs, stats, episodes, recordings). Always back up before running.
- ⚠️ **Warning:** `database --confirm` erases the entire database and replaces it with a blank schema. This is irreversible.
- 💡 **Tip:** After running `rescue`, always delete the code through the admin panel or by running `tools access` once you have regained access.
- 💡 **Tip:** After running `user`, change the password immediately and delete the rescue user when done.

---

## Database updates / migrations

The file-based DB update system (authoring a `.sql` step, the `migrations` table, `db:migrate`, execution flow) now lives in its own page — see [Database Updates / Migrations](database-migrations.md).

---

## Common CLI Operations

### Status Check

```bash
sudo /home/xc_vm/console.php status
```

Checks if XC_VM is running, connects to the database, runs pending DB update steps, fixes permissions, and validates nginx configuration. Required after installation or recovery.

With `first-run` argument, skips the running check — used for initial setup:

```bash
sudo /home/xc_vm/console.php status first-run
```

### Service Management

```bash
sudo /home/xc_vm/console.php service start|stop|restart|reload
```

### Manual Update

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

Downloads and applies the latest update from GitHub. Usually triggered automatically through the web panel.

### Stream Diagnostics

```bash
sudo -u xc_vm /home/xc_vm/console.php monitor <stream_id>
```

Starts a stream manually and displays any errors. Useful for diagnosing stream startup failures.

### Server (Node) Diagnostics

```bash
# On the MAIN — remote-probe a node by its server id
sudo /home/xc_vm/console.php server:diagnose <server_id>

# On the LB/proxy node itself — local self-diagnosis (no arguments)
sudo /home/xc_vm/console.php server:diagnose
```

Finds out **why** a proxy/LB node shows offline in the panel: checks the heartbeat, reachability (ICMP/TCP/HTTP `/api`), clock skew, the signal queue, and — locally on the node — whether the node firewalled the main's IP in its own iptables, whether the `xc_vm` service/nginx are up, whether the `watchdog` heartbeat daemon is running, and whether `cron:servers` is in the `xc_vm` crontab. Read-only; exit code `0` = no problems found, `2` = probable causes printed. See the [Server Diagnostics guide](../administration/server-diagnostics.md) for details.

### SSL Certificate

```bash
sudo /home/xc_vm/console.php certbot
```

### Database migrations

Apply pending `.sql` steps by hand, or import data from another system — see [Database Updates / Migrations](database-migrations.md#applying-migrations-manually).

---

## Related files

| File | Role |
| --- | --- |
| `src/console.php` | CLI entry point + FQCN command discovery |
| `src/Cli/Commands/` | Console commands |
| `src/Cli/CronJobs/` | Cron job classes |
| `src/migrations/` | Database migrations |
