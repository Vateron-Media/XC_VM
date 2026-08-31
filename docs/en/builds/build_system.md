# XC_VM Build System (MAIN vs LB)

How XC_VM produces two build variants from a single codebase: a full MAIN server and a lightweight Load Balancer (LB) server.

---

## Build Variants

XC_VM supports two deployment roles from a single source tree:

| Variant | Archive | Purpose |
| --- | --- | --- |
| **MAIN** | `xc_vm.tar.gz` | Full application — admin panel, streaming, all modules, cron jobs |
| **LB** (Load Balancer) | `loadbalancer.tar.gz` | Streaming-only server — no admin panel, no user management |

**MAIN** is the primary server that manages everything: admin UI, database writes, user/device management, EPG processing, backups, etc.

**LB** is a lightweight streaming node that receives streams from MAIN (or other sources) and delivers them to clients. It connects to the master database in read-only mode and has no admin panel or management capabilities.

---

## Makefile Targets

| Target | Output | Description |
| --- | --- | --- |
| `make main` | `dist/xc_vm.tar.gz` | Full MAIN build |
| `make lb` | `dist/loadbalancer.tar.gz` | LB build (streaming-only subset) |
| `make new` | (resets `dist/`) | Remove and recreate the empty `dist/` output dir — run before a build; builds nothing itself |
| `make generate_deleted_files` | `src/migrations/deleted_files.txt` | List files removed since the last tag (see below) |

> **Updates reuse the full archive.** There is no separate incremental-update target — the same
> `xc_vm.tar.gz` / `loadbalancer.tar.gz` is used for both install and update; filtering happens on
> the server at update time (see [Update Mechanism](../administration/update-system.md)). To delete
> files that were *removed* between releases, `make generate_deleted_files [LAST_TAG=vX.Y.Z]` diffs
> git and writes `deleted_files.txt`, which the updater applies.

Additional outputs:

- `XC_VM.zip` — installer package (`install/` + `xc_vm.tar.gz`)
- `hashes.md5` — MD5 checksums for integrity verification

---

## Composer Dependencies

`src/vendor/` (the Composer PSR-4 autoloader plus the production dependencies) is
**committed** and shipped as-is — the deploy path has no Composer and never runs
`composer install`. It is kept production-only via `composer install --no-dev`, so
both build variants ship a lean vendor with no dev tooling.

- `src/composer.lock` is committed so `composer install` is reproducible.
- Dev tools (PHPStan, phpcs) are `require-dev` and are **not** in the
  committed vendor or the archives. Developers and CI add them with `make dev-tools`
  (`composer install`); the `check-vendor-prod-only` gate fails if a dev package is
  ever committed under `src/vendor/`.
- There is no build-time vendor step — `make main` / `make lb` copy the committed
  `vendor/` directly into the archive.

---

## What Goes Into Each Build

### MAIN Build

The MAIN build contains the **entire** `src/` directory.

### LB Build — Included Directories

Only these directories are copied into the LB archive:

```text
bin/        Cli/        config/     content/    Core/
Domain/     Infrastructure/         Public/     resources/
signals/    Streaming/  tmp/        vendor/     www/
```

Plus root files: `bootstrap.php`, `console.php`, `service`, `update`.

### LB Build — Excluded Content

After copying, admin-specific content is **removed** from the LB build:

**Directories removed:**

| Path | Reason |
| --- | --- |
| `bin/install/` | Installer scripts (not needed on LB) |
| `bin/redis/` | Redis binary (LB doesn't run its own Redis) |
| `bin/nginx/conf/codes/` | Error code pages (admin UI) |
| `Public/Controllers/Admin/` | Admin panel controllers |
| `Public/Controllers/Player/` | Player panel controllers |
| `Public/Controllers/Reseller/` | Reseller panel controllers |
| `Public/Views/` | Panel templates |
| `Public/assets/` | Panel static assets |
| `Public/routes/` | Panel route maps |
| `Domain/User/` | User management |
| `Domain/Device/` | Device registration |
| `Domain/Auth/` | Auth management (panel auth) |
| `Core/Reference/` | Admin reference-data classes (MAIN-only) |
| `Core/Localization/lang/` | Language resource files (`.ini`) |

**Files removed** (these mirror `LB_FILES_TO_REMOVE` in the Makefile):

> ⚠️ Several entries here use the legacy `www/…` prefix (e.g. `www/stream/auth.php`,
> `www/xplugin.php`). `src/www/` no longer exists — the streaming/API endpoints moved under
> `src/Public/stream/` and `src/Public/…`. These `www/…` removal entries are therefore **no-ops**
> today and are worth auditing in the Makefile (a file that should be stripped from LB may in fact
> still ship under its `Public/` path).

| File | Reason |
| --- | --- |
| `Public/Controllers/Api/AdminApiController.php` | Full admin API removed from LB |
| `Public/Controllers/Api/ResellerRestApiController.php` | Reseller API removed from LB |
| `www/xplugin.php`, `www/probe.php`, `www/playlist.php` | Admin endpoints |
| `www/player_api.php`, `www/epg.php`, `www/enigma2.php` | Client API endpoints (served by MAIN) |
| `www/stream/auth.php` | Auth endpoint (legacy path — see note) |
| `www/admin/api.php`, `www/admin/proxy_api.php` | Admin API |
| `bin/maxmind/GeoLite2-City.mmdb` | GeoIP DB shipped separately |
| `config/rclone.conf` | Backup config |
| `Domain/Epg/EPG.php` | EPG processing class |
| `bin/nginx/conf/gzip.conf` | Gzip config (LB uses own) |

**CLI commands removed:**

| File | Reason |
| --- | --- |
| `Cli/Commands/MigrateCommand.php` | Migration is MAIN-only |
| `Cli/Commands/CacheHandlerCommand.php` | Cache handler is MAIN-only |
| `Cli/Commands/ServerInstallCommand.php` | Server installer (not needed on LB itself) |
| `Cli/Commands/LbInstallFlow.php` | LB install helper (not needed on LB itself) |
| `Cli/Commands/ProxyInstallFlow.php` | Proxy install helper (not needed on LB itself) |

**Cron jobs removed:**

| File | Reason |
| --- | --- |
| `Cli/CronJobs/RootMysqlCronJob.php` | DB maintenance (MAIN-only) |
| `Cli/CronJobs/BackupsCronJob.php` | Backups (MAIN-only) |
| `Cli/CronJobs/CacheEngineCronJob.php` | Full cache rebuild (MAIN-only) |
| `Cli/CronJobs/EpgCronJob.php` | EPG processing (MAIN-only) |
| `Cli/CronJobs/UpdateCronJob.php` | Update check (MAIN-only) |
| `Cli/CronJobs/ProvidersCronJob.php` | Provider sync (MAIN-only) |
| `Cli/CronJobs/SeriesCronJob.php` | Series metadata (MAIN-only) |

> **Note:** Module-related crons (TMDB, Plex, Watch) live inside `src/Modules/<name>/` and are excluded from LB builds automatically — `Modules/` is not in `LB_DIRS`.
>
> **Ministra** (`src/Ministra/`, the Stalker portal — ~50 MB of assets) is likewise excluded by
> **omission**: it isn't listed in `LB_DIRS`, so it's never copied into the LB archive (there is no
> explicit removal rule for it — hence the `ministra` absence check in *Build Verification* below).

### LB Build — Replaced Configs

These files from `lb_configs/` **replace** the MAIN versions:

| Source | Target | Purpose |
| --- | --- | --- |
| `lb_configs/nginx.conf` | `bin/nginx/conf/nginx.conf` | Performance-tuned nginx for streaming |
| `lb_configs/live.conf` | `bin/nginx_rtmp/conf/live.conf` | RTMP callback hooks |

---

## MAIN vs LB — Key Differences

| Aspect | MAIN | LB |
| --- | --- | --- |
| Admin panel | ✅ Full UI | ❌ Not included |
| Database role | Read + Write | Read-only consumer |
| User/device management | ✅ | ❌ |
| EPG processing | ✅ | ❌ |
| Backups | ✅ | ❌ |
| Migration tool | ✅ | ❌ |
| Stream delivery | ✅ | ✅ |
| RTMP ingestion | ✅ | ✅ |
| Transcoding (FFmpeg) | ✅ | ✅ |
| CLI commands | 26 | ~15 (admin-only removed) |
| Cron jobs | 25 | ~16 (admin-only removed) |
| Module system | ✅ | ❌ |

---

## LB Nginx Configuration

The LB build uses a specialized nginx config optimized for high-throughput streaming:

| Setting | Value | Purpose |
| --- | --- | --- |
| Worker processes | `auto` | Scale to CPU cores |
| Worker connections | 16,000 | High concurrency per worker |
| Max file descriptors | 300,000 | System resource limit |
| Thread pool | `pool_xc_vm` (32 threads) | Async I/O for streaming |
| Gzip | OFF | Streaming data is already compressed |
| Access logs | OFF | Reduce I/O overhead |
| Rate limiting | 20 req/s per IP | DDoS mitigation |
| Send timeout | 20 min | Support long-running streams |

RTMP hooks (`lb_configs/live.conf`) route authentication through local HTTP callbacks instead of the admin panel:

```nginx
on_play http://127.0.0.1:8080/stream/rtmp;
on_publish http://127.0.0.1:8080/stream/rtmp;
on_play_done http://127.0.0.1:8080/stream/rtmp;
```

---

## Runtime Behavior on LB

### Conditional Command Loading

`console.php` uses `file_exists()` guards for commands that may not exist on LB servers:

```php
if (file_exists(__DIR__ . '/Cli/Commands/CacheHandlerCommand.php')) {
    $rRegistry->register(new CacheHandlerCommand());
}
```

This prevents crashes when LB attempts to register a command whose file was removed during the build.

### Streaming Dependency Chain

LB servers retain the full streaming pipeline:

```text
www/stream/*.php
  ├── www/stream/init.php
  ├── vendor/autoload.php (Composer PSR-4 autoloader)
  ├── bootstrap.php (lightweight stream/bootstrap path)
  ├── Core/* (Config, Database, Cache, Auth, Http, Logging, Util)
  ├── Domain/Stream, Domain/Server, Domain/Vod, Domain/Bouquet
  ├── Streaming/* (Auth, Delivery, Codec, Protection)
  ├── Infrastructure/Redis, Infrastructure/Database
  └── resources/data
```

---

## Adding New Code to Builds

### New streaming-relevant directory under `src/`

Add it to `LB_DIRS` in the Makefile:

```makefile
LB_DIRS := bin Cli config content Core Domain \
    Infrastructure Public resources signals Streaming tmp vendor www your_dir
```

### New admin-only directory

Add it to `LB_DIRS_TO_REMOVE`:

```makefile
LB_DIRS_TO_REMOVE = ... your_dir/admin_stuff
```

### New admin-only file

Add it to `LB_FILES_TO_REMOVE`:

```makefile
LB_FILES_TO_REMOVE = ... your_dir/admin_file.php
```

### New CLI command (admin-only)

1. Add `file_exists()` guard in `console.php`
2. Add the file to `LB_FILES_TO_REMOVE`

---

## Build Verification

After modifying the build, verify both variants:

```bash
# Build both
make new

# Check LB contains streaming code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "Core/|Domain/Stream|Streaming/"
# Expected: > 0

# Check LB does NOT contain admin code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "admin/|player/|ministra|reseller"
# Expected: 0

# Compare sizes (LB should be significantly smaller)
ls -lh dist/xc_vm.tar.gz dist/loadbalancer.tar.gz
```
