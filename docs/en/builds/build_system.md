<h1 align="center">📦 XC_VM — Build System (MAIN vs LB)</h1>

<p align="center">
  How XC_VM produces two build variants from a single codebase:<br>
  a full MAIN server and a lightweight Load Balancer (LB) server.
</p>

---

## 📚 Navigation

* [🏗 Build Variants](#build-variants)
* [⚙️ Makefile Targets](#makefile-targets)
* [📂 What Goes Into Each Build](#what-goes-into-each-build)
* [🔀 MAIN vs LB — Key Differences](#main-vs-lb--key-differences)
* [🌐 LB Nginx Configuration](#lb-nginx-configuration)
* [🔧 Runtime Behavior on LB](#runtime-behavior-on-lb)
* [➕ Adding New Code to Builds](#adding-new-code-to-builds)
* [✅ Build Verification](#build-verification)

---

## 🏗 Build Variants

XC_VM supports two deployment roles from a single source tree:

| Variant | Archive | Purpose |
|---|---|---|
| **MAIN** | `xc_vm.tar.gz` | Full application — admin panel, streaming, all modules, cron jobs |
| **LB** (Load Balancer) | `loadbalancer.tar.gz` | Streaming-only server — no admin panel, no user management |

**MAIN** is the primary server that manages everything: admin UI, database writes, user/device management, EPG processing, backups, etc.

**LB** is a lightweight streaming node that receives streams from MAIN (or other sources) and delivers them to clients. It connects to the master database in read-only mode and has no admin panel or management capabilities.

---

## ⚙️ Makefile Targets

| Target | Output | Description |
|---|---|---|
| `make main` | `dist/xc_vm.tar.gz` | Full MAIN build |
| `make lb` | `dist/loadbalancer.tar.gz` | LB build (streaming-only subset) |
| `make main_update` | `dist/update.tar.gz` | Incremental MAIN update |
| `make lb_update` | `dist/loadbalancer_update.tar.gz` | Incremental LB update |
| `make new` | Both full builds | Shortcut: `main` + `lb` |

Additional outputs:
- `XC_VM.zip` — installer package (`install/` + `xc_vm.tar.gz`)
- `hashes.md5` — MD5 checksums for integrity verification

---

## 📂 What Goes Into Each Build

### MAIN Build

The MAIN build contains the **entire** `src/` directory.

### LB Build — Included Directories

Only these directories are copied into the LB archive:

```
bin/        cli/        config/     content/    core/
domain/     includes/   infrastructure/         resources/
signals/    streaming/  tmp/        www/
```

Plus root files: `autoload.php`, `bootstrap.php`, `console.php`, `service`, `status`, `update`.

### LB Build — Excluded Content

After copying, admin-specific content is **removed** from the LB build:

**Directories removed:**
| Path | Reason |
|---|---|
| `bin/install/` | Installer scripts (not needed on LB) |
| `bin/redis/` | Redis binary (LB doesn't run its own Redis) |
| `bin/nginx/conf/codes/` | Error code pages (admin UI) |
| `resources/langs/` | Admin UI language files |
| `includes/api/` | Admin API routes |
| `includes/libs/resources/` | Admin resource libraries |
| `domain/User/` | User management |
| `domain/Device/` | Device registration |
| `domain/Auth/` | Auth management (panel auth) |
| `resources/langs/` | Language resource files |
| `resources/libs/` | Admin library resources |

**Files removed:**
| File | Reason |
|---|---|
| `includes/admin.php`, `includes/admin_api.php` | Admin panel logic |
| `includes/reseller_api.php` | Reseller API |
| `www/xplugin.php`, `www/probe.php`, `www/playlist.php` | Admin endpoints |
| `www/player_api.php`, `www/epg.php`, `www/enigma2.php` | Client API endpoints (served by MAIN) |
| `www/admin/api.php`, `www/admin/proxy_api.php` | Admin API |
| `config/rclone.conf` | Backup config |
| `domain/Epg/EPG.php` | EPG processing class |
| `bin/nginx/conf/gzip.conf` | Gzip config (LB uses own) |

**CLI commands removed:**
| File | Reason |
|---|---|
| `cli/Commands/MigrateCommand.php` | Migration is MAIN-only |
| `cli/Commands/CacheHandlerCommand.php` | Cache handler is MAIN-only |
| `cli/Commands/BalancerCommand.php` | LB installer (not needed on LB itself) |

**Cron jobs removed:**
| File | Reason |
|---|---|
| `cli/CronJobs/RootMysqlCronJob.php` | DB maintenance (MAIN-only) |
| `cli/CronJobs/BackupsCronJob.php` | Backups (MAIN-only) |
| `cli/CronJobs/CacheEngineCronJob.php` | Full cache rebuild (MAIN-only) |
| `cli/CronJobs/EpgCronJob.php` | EPG processing (MAIN-only) |
| `cli/CronJobs/UpdateCronJob.php` | Update check (MAIN-only) |
| `cli/CronJobs/ProvidersCronJob.php` | Provider sync (MAIN-only) |
| `cli/CronJobs/SeriesCronJob.php` | Series metadata (MAIN-only) |

> **Note:** Module-related crons (TMDB, Plex, Watch) now live inside `modules/<name>/` and are excluded from LB builds automatically since `modules/` is not in `LB_DIRS`.

### LB Build — Replaced Configs

These files from `lb_configs/` **replace** the MAIN versions:

| Source | Target | Purpose |
|---|---|---|
| `lb_configs/nginx.conf` | `bin/nginx/conf/nginx.conf` | Performance-tuned nginx for streaming |
| `lb_configs/live.conf` | `bin/nginx_rtmp/conf/live.conf` | RTMP callback hooks |

---

## 🔀 MAIN vs LB — Key Differences

| Aspect | MAIN | LB |
|---|---|---|
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

## 🌐 LB Nginx Configuration

The LB build uses a specialized nginx config optimized for high-throughput streaming:

| Setting | Value | Purpose |
|---|---|---|
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

## 🔧 Runtime Behavior on LB

### Conditional Command Loading

`console.php` uses `file_exists()` guards for commands that may not exist on LB servers:

```php
if (file_exists(__DIR__ . '/cli/Commands/CacheHandlerCommand.php')) {
    $rRegistry->register(new CacheHandlerCommand());
}
```

This prevents crashes when LB attempts to register a command whose file was removed during the build.

### Streaming Dependency Chain

LB servers retain the full streaming pipeline:

```
www/stream.php
  ├── autoload.php
  ├── bootstrap.php (CONTEXT_STREAM)
  ├── core/* (Config, Database, Cache, Auth, Http, Logging, Util)
  ├── domain/Stream, domain/Server, domain/Vod, domain/Bouquet
  ├── streaming/* (Auth, Delivery, Codec, Protection)
  ├── infrastructure/redis, infrastructure/database
  └── resources/data
```

---

## ➕ Adding New Code to Builds

### New streaming-relevant directory under `src/`

Add it to `LB_DIRS` in the Makefile:

```makefile
LB_DIRS = bin cli config content core domain ... your_dir
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

## ✅ Build Verification

After modifying the build, verify both variants:

```bash
# Build both
make new

# Check LB contains streaming code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "core/|domain/Stream|streaming/"
# Expected: > 0

# Check LB does NOT contain admin code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "admin/|player/|ministra|reseller"
# Expected: 0

# Compare sizes (LB should be significantly smaller)
ls -lh dist/xc_vm.tar.gz dist/loadbalancer.tar.gz
```
