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
> git and writes `deleted_files.txt`, which the updater applies. The LB archive's
> `migrations/deleted_files.txt` also lists every file the LB build strips (see
> [LB Build — Deleted Files on Update](#lb-build-deleted-files-on-update)).

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
Domain/     Infrastructure/         Public/     signals/
Streaming/  tmp/        vendor/
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
| `Public/Controllers/PlayerV2/` | Web player v2 controllers (player scope, not routed on LB) |
| `Public/Controllers/Reseller/` | Reseller panel controllers |
| `Public/Views/` | Panel templates |
| `Public/assets/` | Panel static assets |
| `Public/routes/` | Panel route maps |
| `Domain/User/` | User management |
| `Domain/Device/` | Device registration |
| `Core/Reference/` | Admin reference-data classes (MAIN-only) |
| `Core/Localization/lang/` | Language resource files (`.ini`) |

**Files removed** (these mirror `LB_FILES_TO_REMOVE` in the Makefile):

| File | Reason |
| --- | --- |
| `Public/admin/api.php`, `Public/admin/proxy_api.php` | Admin and proxy APIs (MAIN-only; the LB nginx routes only `/admin/{live,timeshift,thumb,vod}`) |
| `Public/stream/auth.php`, `Public/stream/probe.php` | Viewer auth and stream probe (MAIN-only; they need the stripped `Domain/User`, and the LB nginx does not route them) |
| `Public/Controllers/Api/AdminApiController.php`, `AdminAPIWrapper.php` | Full admin API removed from LB |
| `Public/Controllers/Api/ResellerRestApiController.php`, `ResellerAPIWrapper.php` | Reseller API removed from LB |
| `Public/Controllers/Api/ActiveCodeApiController.php` | Activation-code API (the LB nginx never routes `active_code`) |
| `Infrastructure/ResellerApiDispatcher.php`, `ResellerTableRenderer.php` | Reseller panel helpers |
| `config/rclone.conf` | Backup config |
| `Domain/Epg/EPG.php` | EPG processing class |
| `Core/Enum/Theme.php`, `ResellerAction.php`, `ClientFilter.php` | Panel-only enums |
| `bin/nginx/conf/gzip.conf` | Gzip config (LB uses own) |

The viewer-API controllers (`PlayerApiController`, `Enigma2ApiController`, `XPluginApiController`,
`EpgApiController`, `PlaylistApiController` and their `BaseApiController`) still ship, because
`lb_configs/nginx.conf` still routes `/api/player_api` and the other viewer endpoints to
`Public/index.php`. They are removed together with those routes in a later phase.

**CLI commands removed:**

| File | Reason |
| --- | --- |
| `Cli/Commands/MigrateCommand.php`, `Cli/migration_logic.php` | Migration is MAIN-only |
| `Cli/Commands/DbMigrateCommand.php` | Applies MAIN's schema migrations (MAIN-only) |
| `Cli/Commands/ModuleMigrateCommand.php` | Runs a module's install or update steps (modules are MAIN-only) |
| `Cli/Commands/CacheHandlerCommand.php` | Cache handler is MAIN-only |
| `Cli/Commands/ServerInstallCommand.php` | Server installer (not needed on LB itself) |
| `Cli/Commands/ServerSyncOpensslExtraCommand.php` | Sends MAIN's `OPENSSL_EXTRA` to LBs (MAIN-only) |
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

> **Note:** Module-related crons (Plex, Watch) live inside `src/Modules/<name>/` and are excluded from LB builds automatically — `Modules/` is not in `LB_DIRS`.
> The TMDB crons are **not** module crons: `Cli/CronJobs/TmdbCronJob.php` and
> `Cli/CronJobs/TmdbPopularCronJob.php` are core jobs and ship in the LB archive.
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

### LB Build — Deleted Files on Update

An update extracts the archive over the installed tree, so a file disappears from an installed LB
only when `migrations/deleted_files.txt` lists it (`MigrationRunner::runFileCleanup()` runs in
post-update). `make lb` therefore always writes the LB archive's list as the union of:

- the LB-scoped entries of `src/migrations/deleted_files.txt` (paths under `LB_DIRS`, the retired
  `LB_RETIRED_DIRS` trees `resources/` and `www/`, or an `LB_ROOT_FILES` entry);
- every file the LB build strips: the `LB_FILES_TO_REMOVE` entries and the tracked files under
  `LB_DIRS_TO_REMOVE`, limited to the code trees (`Cli/`, `Core/`, `Domain/`, `Infrastructure/`,
  `Public/`, `Streaming/`). `bin/`, `config/` and `content/` hold runtime and per-server files and
  are never taken from the strip lists. The trees in `LB_KEEP_ON_UPDATE` are left out as well.

A file newly added to a strip list is thus also removed from LBs installed by an older release.

`LB_KEEP_ON_UPDATE` holds `Domain/User`. A fresh LB never gets it, but `Public/stream/rtmp.php` (the
RTMP `on_play` auth) and the viewer-API controllers still call it on routes the LB nginx serves. An
older LB that still carries it keeps it until those routes are removed.

The build verification fails if the list names a file that the LB archive ships (`DELETES-SHIPPED`).

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

### Command Discovery

`console.php` does not list commands. It globs `Cli/Commands/*.php` and `Cli/CronJobs/*.php` and
registers every concrete class that implements `CommandInterface`:

```php
foreach (glob($rDir . '/*.php') as $rFile) {
    $rClass = $rNamespace . basename($rFile, '.php');
    if (!class_exists($rClass)) {
        continue;
    }
    // ... register it if it is a concrete CommandInterface
}
```

A command or cron job that the LB build strips is simply absent on the LB, so it is never registered.
No guard is needed.

### Streaming Dependency Chain

LB servers retain the full streaming pipeline:

```text
Public/stream/index.php (stream gateway) → Public/stream/<handler>.php
  ├── vendor/autoload.php (Composer PSR-4 autoloader)
  ├── Infrastructure/Bootstrap/StreamingRequestBootstrap.php
  ├── Core/* (Config, Database, Cache, Auth, Http, Logging, Util)
  ├── Domain/Stream, Domain/Server, Domain/Vod, Domain/Bouquet
  ├── Streaming/* (Auth, Delivery, Codec, Protection)
  └── Infrastructure/Redis, Infrastructure/Database
```

---

## Adding New Code to Builds

### New streaming-relevant directory under `src/`

Add it to `LB_DIRS` in the Makefile:

```makefile
LB_DIRS := bin Cli config content Core Domain \
    Infrastructure Public signals Streaming tmp vendor your_dir
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

Add the file to `LB_FILES_TO_REMOVE`. If it is privileged, also add it to `SENSITIVE` in
`tools/ci/verify-lb-archive.sh`. `console.php` discovers commands by glob, so it needs no change.

---

## Build Verification

`make gates` runs `tools/ci/verify-lb-archive.sh`, which rebuilds the LB file list from the
Makefile variables (no tarball needed) and fails on:

| Finding | Meaning |
| --- | --- |
| `STALE` | An `LB_DIRS` / `LB_ROOT_FILES` / `LB_DIRS_TO_REMOVE` / `LB_FILES_TO_REMOVE` / `LB_KEEP_ON_UPDATE` entry matches no tracked path under `src/`. A renamed or removed path would otherwise turn its rule into a silent no-op. |
| `WRONG-LIST` | An entry is the wrong kind for its list: a file in `LB_DIRS`, `LB_DIRS_TO_REMOVE` or `LB_KEEP_ON_UPDATE`, or a directory in `LB_ROOT_FILES` or `LB_FILES_TO_REMOVE`. The build strips a `LB_DIRS_TO_REMOVE` path with `rm -rf`, so a file there is stripped. `rm -f` and `cp` skip a directory, so a directory in a file list does nothing. |
| `LEAK` | A privileged path from the script's `SENSITIVE` list would ship to the LB. |
| `MISSING` | A file that the LB nginx routes to is stripped. The script checks every `SCRIPT_FILENAME` and `Public/<scope>/<handler>.php` for each handler the `/stream/` and `/admin/` gateway locations accept. It also checks the controller that `Public/index.php` dispatches for each `XC_API` value (such as `internal` for `/api`), plus `BaseApiController`, `StreamingRequestBootstrap` and `WebApiBootstrap`. An `XC_API` value that `Public/index.php` does not map also fails. |
| `DELETES-SHIPPED` | The LB `migrations/deleted_files.txt` built by `make lb_delete_files_list` names a file that the LB archive ships, so the update would delete it from every LB. |

When you strip a new file, add it to `LB_FILES_TO_REMOVE` (it must be a tracked file) and, if it is
privileged, to `SENSITIVE` in `tools/ci/verify-lb-archive.sh`. When you strip a routed handler, also
remove its route from `lb_configs/nginx.conf`.

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

## Nightly Builds (Dev Channel)

`.github/workflows/build-dev.yml` publishes nightly builds of `main` for panels on the `Dev` update channel. It runs at 02:00 UTC and on manual dispatch (`force` rebuilds an unchanged `main`). It skips the build when `main` is unchanged since the previous nightly or has no commits since the last release.

Nightly builds go to the releases-only repository [`Vateron-Media/XC_VM_Dev`](https://github.com/Vateron-Media/XC_VM_Dev), not to `XC_VM`, for three reasons:

- The panel reads one page of up to 100 releases. Daily releases in `XC_VM` would push the stable ones off that page.
- The release workflows take the newest tag as `LAST_TAG`, and nightly tags would take its place.
- Every release in `XC_VM` notifies the repository's watchers and `release-notifier.yml`.

**Version.** Each build is tagged `<base>-dev.<run number>`. `<base>` is `XC_VM_VERSION` from the source when it is already bumped past the last `X.Y.Z` tag, otherwise the next patch after that tag. The workflow runs the unit tests first, then stamps the version into `ConstantsInitializer.php` and runs `make lb` and `make main` with `LAST_TAG` set to the last release. `deleted_files.txt` therefore covers every deletion since that release, so a panel that skips nightlies still gets a correct cleanup.

**Assets.** A nightly carries the same assets as a release (`xc_vm.tar.gz`, `loadbalancer.tar.gz`, `XC_VM.zip`, `hashes.md5`) plus `changelog.json`, which is built from the commit subjects since the previous nightly. `XC_VM_Dev` has no source tree, so `GitHubReleases::getChangelog()` reads nightly changelogs from this asset, not from the tagged `changelog.json`. The first line of the release notes (`Source: …/commit/<sha>`) records the source commit, and the next run compares against it. Only the newest 20 nightlies are kept.

**Panel side.** `UpdateChannels::mainReleases()` gives the MAIN release client `GIT_REPO_DEV`. On the `dev` channel `GitHubReleases` merges both repositories' releases, orders them with `version_compare()`, and fetches each asset from the repository that published its tag (`GitHubReleases::isDevVersion()`). If `XC_VM_Dev` cannot be reached, the dev channel still receives regular releases.

**Setup.** The workflow publishes with a fine-grained personal access token stored in the repository secret `DEV_RELEASE_TOKEN`. Scope the token to `XC_VM_Dev` only, with **Contents: read and write**. When the token expires, the nightly fails at its first `gh` call; issue a new token and update the secret. `XC_VM_Dev` must be public, because panels download from it without a token, and it needs at least one commit for release tags to point at.
