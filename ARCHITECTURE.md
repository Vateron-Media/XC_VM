# XC_VM - Architecture

## Table of Contents

1. [Architectural Style and Principles](#1-architectural-style-and-principles)
2. [Structure of `/src`](#2-structure-src)
3. [Component Overview](#3-component-overview)
4. [Module System](#4-module-system)
5. [Build Variants: MAIN vs LoadBalancer](#5-build-variants-main-vs-loadbalancer)
6. [Transactions and Performance](#6-transactions-and-performance)

> **Migration plan** (phases, risk strategy, execution order) - see [MIGRATION.md](MIGRATION.md).
> **Contributor rules** - see [CONTRIBUTING.md](CONTRIBUTING.md).

---

## 1. Architectural Style and Principles

### 1.1. Formalization

**Structured monolith with predictable context-based organization.**

Not DDD. Not Hexagonal. Not Clean Architecture. Not microservices.
A simple PHP monolith, split by contexts with minimal abstractions.

#### Pattern: Controller -> Service -> Repository

Each context (Streams, VOD, Lines, Users...) is organized the same way:

```
domain/Stream/
  ├── StreamService.php       # Business logic + orchestration
  ├── StreamRepository.php    # SQL queries (SELECT/INSERT/UPDATE/DELETE)
  └── StreamProcess.php       # Specialized operations (ffmpeg, kill)

public/Controllers/Admin/
  └── StreamController.php    # Accept request -> call Service -> return response
```

#### Dependency rules

```
Controller  ->  Service  ->  Repository  ->  Database
                  │
                  v
          Infrastructure (nginx, redis, ffmpeg)
```

│ Layer │ Can depend on │ MUST NOT depend on │
├──-----├──------------------├──-------------------│
│ `public/` │ `domain/` (Service + Repository), `core/` │ `streaming/`, `modules/` directly │
│ `domain/` │ `core/` (Database, Cache, Events) │ `public/`, `streaming/`, `modules/`, `infrastructure/` │
│ `core/` │ Only other `core/` subdirectories │ Everything else │
│ `streaming/` │ `core/` (subset), `domain/` (read-only queries) │ `public/`, `modules/` │
│ `modules/` │ `domain/` (Service, Repository), `core/` │ Other modules (without explicit dependency), `public/`, `streaming/` │

### 1.2. Constructor Injection - target standard, but not the current global state

`ServiceContainer` is intended as a composition root, and new code should receive dependencies through constructors where possible. However, the current system state is mixed:

- `bootstrap.php` creates and populates `ServiceContainer`
- `Router` and part of legacy initialization still resolve dependencies through the container during dispatch/bootstrap
- A significant part of `domain/` and part of `streaming/` still use `global $db`, `global $rSettings`, and legacy bootstrap compatibility

In other words, constructor injection is used as a migration direction, but it is not a strict rule across the entire `src/` tree.

```php
// CORRECT - constructor injection:
class StreamService {
    public function __construct(
        private StreamRepository $repository,
        private EventDispatcher $events,
        private FileLogger $logger
    ) {}
}

// FORBIDDEN - Service Locator inside a service:
class StreamService {
    public function create(array $data): int {
        $db = ServiceContainer::getInstance()->get('db');  // <- ANTIPATTERN
    }
}
```

**Actual exceptions:**
The legacy layer in `infrastructure/legacy/`, part of bootstrap code, `Router`, and also a significant portion of `domain/` are not yet migrated to pure constructor injection.

### 1.3. No Entity classes

Data is passed as `array`. This is PHP - arrays are simpler and more practical than anemic DTO objects.

### 1.4. Module = isolated directory

A module is a directory with a known contract. It can be removed, and the system will continue to work (with degraded functionality, but without crashing). The core (`core/`) contains no license checks, encryption, or hidden restrictions.

---

## 2. Structure of `/src`

```
src/
├── autoload.php                     # PSR-like autoloader (class map)
├── bootstrap.php                    # Unified bootstrap: DI container, config, contexts
├── console.php                      # CLI entry point (php console.php cron:*, cmd:*)
├── service                          # Bash: daemon management
├── update                           # Python: update process
│
├── core/                            # === CORE (infrastructure services) ===
│   ├── Auth/                        # SessionManager, Authenticator, Authorization,
│   │                                #   BruteforceGuard, PageAuthorization
│   ├── Backup/                      # BackupService
│   ├── Cache/                       # CacheInterface, FileCache, RedisCache
│   ├── Config/                      # AppConfig, ConfigLoader, ConfigReader, Paths, Binaries,
│   │                                #   DomainResolver, SettingsManager, SettingsRepository
│   ├── Container/                   # ServiceContainer (DI)
│   ├── Database/                    # Database (PDO), DatabaseHandler, MigrationRunner, QueryHelper
│   ├── Device/                      # MobileDetect
│   ├── Diagnostics/                 # DiagnosticsService
│   ├── Error/                       # ErrorHandler, ErrorCodes
│   ├── Events/                      # EventDispatcher, EventInterface
│   ├── GeoIP/                       # GeoIPService
│   ├── Http/                        # Request, Response, Router, ApiClient, CurlClient,
│   │                                #   RequestGuard, RequestManager, Middleware/
│   ├── Init/                        # LegacyInitializer
│   ├── Localization/                # Translator
│   ├── Logging/                     # LoggerInterface, FileLogger, DatabaseLogger, Logger
│   ├── Module/                      # ModuleInterface, ModuleLoader
│   ├── Parsing/                     # XmlStringStreamer
│   ├── Process/                     # ProcessManager, Multithread, Thread
│   ├── Storage/                     # DropboxClient
│   ├── Updates/                     # GithubReleases
│   ├── Util/                        # Encryption, GeoIP, ImageUtils, NetworkUtils,
│   │                                #   StreamUtils, SystemInfo, TimeUtils, AdminHelpers
│   └── Validation/                  # InputValidator
│
├── domain/                          # === BUSINESS LOGIC (services and repositories) ===
│   ├── Auth/                        # AuthService, AuthRepository
│   ├── Bouquet/                     # BouquetService
│   ├── Device/                      # MagService, EnigmaService
│   ├── Epg/                         # EPG (XML parser), EpgService
│   ├── Line/                        # LineService, LineRepository, PackageService
│   ├── Security/                    # BlocklistService
│   ├── Server/                      # ServerService, ServerRepository, SettingsService
│   ├── Stream/                      # StreamService, StreamRepository, StreamProcess,
│   │                                #   ChannelService, CategoryService, ConnectionTracker,
│   │                                #   StreamSorter, PlaylistGenerator, M3UEntry, M3UParser,
│   │                                #   ProfileService, ProviderService, RadioService,
│   │                                #   StreamConfigRepository
│   ├── User/                        # UserService, UserRepository, GroupService, TicketRepository
│   └── Vod/                         # MovieService, SeriesService, EpisodeService, TMDbService
│
├── streaming/                       # === STREAMING ENGINE (hot path) ===
│   ├── StreamingBootstrap.php       # Lightweight init for streaming context
│   ├── AsyncFileOperations.php      # Async file operations
│   ├── TimeshiftClient.php          # Client for timeshift requests
│   ├── Auth/                        # StreamAuth, StreamAuthMiddleware
│   ├── Balancer/                    # ProxySelector
│   ├── Codec/                       # FFmpegCommand, FFprobeRunner, FfmpegPaths
│   ├── Delivery/                    # HLSGenerator, OffAirHandler, SegmentReader,
│   │                                #   SignalSender, StreamRedirector
│   ├── Health/                      # ProcessChecker
│   ├── Lifecycle/                   # ShutdownHandler
│   └── Protection/                  # ConnectionLimiter
│
├── public/                          # === HTTP ENTRY POINTS ===
│   ├── index.php                    # Unified front controller
│   ├── routes/                      # admin.php, reseller.php, player.php
│   ├── Controllers/
│   │   ├── Admin/                   # Admin controllers and fallback handlers
│   │   ├── Reseller/                # Reseller panel controllers
│   │   ├── Api/                     # API controllers (Admin, Reseller, Player, Internal, Enigma2...)
│   │   └── Player/                  # Player panel controllers
│   ├── Views/
│   │   ├── admin/                   # Admin templates and partials
│   │   ├── reseller/                # Reseller templates
│   │   ├── player/                  # Player templates
│   │   └── layouts/                 # admin.php, footer.php, player/, reseller/
│   └── assets/                      # admin/, player/, reseller/ (CSS/JS/images/fonts)
│
├── cli/                             # === CLI ENTRY POINTS ===
│   ├── CommandInterface.php
│   ├── CommandRegistry.php
│   ├── CronTrait.php
│   ├── DaemonTrait.php
│   ├── migration_logic.php
│   ├── Commands/                    # CLI daemon and utility commands
│   └── CronJobs/                    # Panel cron jobs
│
├── modules/                         # === OPTIONAL MODULES ===
│   ├── ministra/                    # Ministra/Stalker Portal middleware
│   ├── plex/                        # Plex integration
│   ├── tmdb/                        # TMDB metadata fetching
│   ├── watch/                       # Watch/DVR recording
│   ├── fingerprint/                 # Watermarking
│   ├── theft-detection/             # Anti-theft detection
│   └── magscan/                     # MAG device scanning
│
├── infrastructure/                  # === SYSTEM INFRASTRUCTURE ===
│   ├── bootstrap/                   # Functions/sessions for admin, reseller, player (facade layer)
│   ├── cache/                       # CacheReader
│   ├── database/                    # DatabaseFactory
│   ├── legacy/                      # resize_body, reseller_api_actions, etc.
│   ├── nginx/                       # templates/
│   ├── redis/                       # RedisManager
│   └── service/                     # (bash daemon scripts)
│
├── resources/                       # === RESOURCES ===
│   ├── data/                        # admin_constants.php
│   ├── langs/                       # bg, de, en, es, fr, pt, ru (.ini)
│   └── libs/                        # (reserved)
│
├── config/                          # === CONFIGURATION ===
│   ├── modules.php                  # List of enabled modules
│   ├── permissions.php              # Access permission definitions
│   └── rclone.conf
│
├── www/                             # === WEB ENTRY POINTS ===
│   ├── constants.php                # Backward compatibility facade
│   ├── init.php                     # Legacy init
│   ├── index.html                   # Ministra portal HTML
│   ├── probe.php                    # FFprobe endpoint
│   ├── progress.php                 # Progress reporting
│   ├── images/                      # Static images
│   ├── admin/                       # 7 entry points (api.php, index.php, live.php...)
│   └── stream/                      # 11 streaming endpoints (auth, live, vod, segment...)
│
├── bin/                             # External binaries (ffmpeg, certbot, yt-dlp, nginx, nginx_rtmp, redis, php...)
├── content/                         # Media content (archive, created, delayed, epg, playlists, streams, video, vod)
├── backups/                         # Backups
├── signals/                         # Signal files
├── tmp/                             # Temporary files
├── migrations/                      # SQL migrations (001_update_crontab_filenames.sql...)
└── ministra/                        # Ministra JS files (served directly by nginx)
```

---

## 3. Component Overview

### 3.1. `core/` - Core

Infrastructure services for any execution context. Does not contain business logic.

**Key rule:** `core/` does not know about `domain/`, `streaming/`, `modules/`, or `public/`. Dependencies are directed only inward.

│ Subdirectory │ Purpose │
├──-----------├──---------│
│ `Auth/` │ Unified authorization (admin + reseller), RBAC, brute-force protection, page authorization │
│ `Backup/` │ Backups (BackupService) │
│ `Cache/` │ Unified cache (File + Redis) through `CacheInterface` │
│ `Config/` │ Config loading, path resolution, settings management │
│ `Container/` │ DI container (composition root only - 1.2) │
│ `Database/` │ PDO wrapper (Database + DatabaseHandler + QueryHelper), migrations │
│ `Device/` │ Device detection (MobileDetect) │
│ `Diagnostics/` │ System diagnostics │
│ `Error/` │ Error handler, error codes │
│ `Events/` │ Event bus for module hooks │
│ `GeoIP/` │ Geo resolution by IP │
│ `Http/` │ Request abstraction, routing, ApiClient, CurlClient, middleware pipeline │
│ `Init/` │ Legacy initialization (LegacyInitializer) │
│ `Localization/` │ Interface translation (Translator) │
│ `Logging/` │ Unified logging (File + Database + Logger) │
│ `Module/` │ Module loader, `ModuleInterface` │
│ `Parsing/` │ XML parsing (XmlStringStreamer) │
│ `Process/` │ PID/thread management (Multithread, Thread) │
│ `Storage/` │ Cloud storage (DropboxClient) │
│ `Updates/` │ Updates (GithubReleases) │
│ `Util/` │ Stateless utilities (Encryption, Network, Time, Image, Stream, AdminHelpers) │
│ `Validation/` │ Input validation │

### 3.2. `domain/` - Business logic and compatibility layer

`domain/` is organized by contexts, but currently contains two styles at the same time:

1. New code with dedicated Service/Repository classes and explicit dependencies.
2. Legacy classes that still work through `global $db`, `global $rSettings`, and procedural helper flows.

**Actual rules at the current stage:**
1. Service/Repository separation exists in many contexts, but is not applied equally strictly in all folders.
2. Repository is not the only SQL access point: part of Service classes and legacy components execute queries directly.
3. Source of truth for DB schema is SQL files in `src/migrations/` plus runtime table `migrations`, created by `MigrationRunner`.

> **Current state:** `global $db` is heavily used across major domain contexts (`Stream`, `Vod`, `User`, `Server`, `Line`, `Auth`, `Bouquet`, `Security`, etc.). Migration toward constructor injection is not finished yet.

```php
// === TARGET PATTERN (new code) ===

// Service does everything:
class StreamService {
    public function __construct(
        private StreamRepository $repository,
        private ProcessManager $processManager,
        private FileLogger $logger,
        private Database $db
    ) {}

    public function create(array $data): int {
        if (empty($data['stream_source'])) {
            throw new \InvalidArgumentException('Source required');
        }
        $this->db->beginTransaction();
        try {
            $id = $this->repository->insert($data);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->logger->log('stream', "Created stream {$id}");
        return $id;
    }
}

// Repository = SQL only:
class StreamRepository {
    public function __construct(private Database $db) {}
    public function findById(int $id): ?array {
        return $this->db->row("SELECT * FROM streams WHERE id = ?", [$id]);
    }
}
```

```php
// === REAL LEGACY PATTERN (widely used in current code) ===
// Many domain classes still access global $db

class StreamRepository {
    public static function getById($rID) {
        global $db;
        $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
        return $db->fetchRecord();
    }
}
```

### 3.3. `streaming/` - Hot delivery path, but not the only streaming logic point

`streaming/` contains specialized hot path classes, but in practice runtime is split into two layers:

- `www/stream/*.php` and `www/stream/init.php` provide procedural bootstrap of streaming endpoints
- `streaming/StreamingBootstrap.php` and related classes finish initialization and delivery handling

**Key fact:** current web streaming endpoints **do not use** `XC_Bootstrap::CONTEXT_STREAM`. This context is declared in `bootstrap.php`, but hot path actually starts through `www/stream/init.php`.

**What hot path actually does:**

1. Loads constants/error/config/bin helpers from `www/stream/init.php`
2. Reads `settings` from file cache
3. Calls `StreamingBootstrap::bootstrap($endpoint, $settings)`
4. Performs further processing through `streaming/` classes and part of legacy/global-state logic

Some classes in `streaming/` still use `global $db`, so isolation from legacy is not complete yet.

### 3.4. `public/` - Main HTTP entry, but not the only runtime path

`public/index.php` is a front controller for admin/reseller/player pages, but the project currently uses several HTTP flows at once.

**Current layout:**

- Admin/Reseller/Player pages go through `public/index.php` -> `public/routes/{scope}.php` -> `Router` -> controller
- REST path for `includes/api/admin` and `includes/api/reseller` short-circuits in `public/index.php` and directly calls `AdminApiController` / `ResellerRestApiController`
- Streaming/API path with `XC_SCOPE=api` and `XC_API=*` loads `www/init.php` or `www/stream/init.php`, then directly calls API controller

This means Router covers panel pages, but is not a single dispatcher for all HTTP requests.

### 3.5. `cli/` - CLI entry points

- Each daemon/cron is a separate Command/CronJob class
- All calls go through `console.php` (for example `php console.php cron:streams`)
- Common initialization through `bootstrap.php` + `ServiceContainer`

### 3.6. `modules/` - Optional modules

Each module is a directory with `module.json` and a PHP class implementing `ModuleInterface`.

│ Module │ Purpose │
├──-------├──----------│
│ `ministra/` │ Ministra/Stalker Portal middleware │
│ `plex/` │ Plex integration │
│ `tmdb/` │ TMDB metadata fetching │
│ `watch/` │ Watch/DVR recording │
│ `fingerprint/` │ Watermarking │
│ `theft-detection/` │ Anti-theft detection │
│ `magscan/` │ MAG device scanning │

**Current limitation:** `console.php` does call `ModuleLoader::loadAll()` and `registerAllCommands()`, so CLI module integration is active. `public/index.php` **does not call** `ModuleLoader::bootAll()`, therefore `boot()` and `registerRoutes()` do not participate in the current web runtime path. Module pages that are currently available in admin are statically wired in `public/routes/admin.php`.

### 3.7. `infrastructure/legacy/` - Residual legacy code

Residual legacy code moved from removed `includes/`:
- `resize_body.php` - resize logic for admin
- `reseller_api.php` - legacy reseller API handler
- `reseller_api_actions.php` - reseller API actions
- `reseller_table_body.php` - reseller table rendering

**Status:** Directory `includes/` was fully removed (Phase 15 completed). Remaining code in `infrastructure/legacy/` is planned for further migration into `domain/` services.

### 3.8. Bootstrap - initialization contexts

`bootstrap.php` provides `XC_Bootstrap` with four contexts:

│ Context │ What it loads │ Purpose │
├──---------├──-------------├──---------│
│ `CONTEXT_MINIMAL` │ autoload + constants + config + Logger │ Scripts that only need paths and config │
│ `CONTEXT_CLI` │ + Database + LegacyInitializer (+ Redis optional) │ Cron jobs, CLI scripts │
│ `CONTEXT_STREAM` │ + Database (cached) │ Declared for a lightweight stream/bootstrap scenario, but current web streaming endpoints do not use it │
│ `CONTEXT_ADMIN` │ + Database + LegacyInitializer + Redis + API + ResellerAPI + Translator + MobileDetect + session │ Admin/reseller panel │

### 3.9. Actual runtime flows

#### Admin/Reseller/Player pages

`nginx -> public/index.php -> scope/pageName resolution -> optional session/functions bootstrap -> public/routes/{scope}.php -> Router::dispatch() -> controller -> domain/core`

#### Admin/Reseller REST API

`nginx -> public/index.php -> rawScope=includes/api/* -> XC_Bootstrap::CONTEXT_ADMIN -> AdminApiController│ResellerRestApiController -> legacy/domain calls`

#### Streaming API via `XC_SCOPE=api`

`nginx -> public/index.php -> XC_API dispatch -> www/init.php │ www/stream/init.php -> ApiController -> legacy/domain/streaming`

#### Streaming endpoints `www/stream/*.php`

`nginx -> www/stream/{endpoint}.php -> www/stream/init.php -> StreamingBootstrap::bootstrap() -> streaming/* + global-state helpers`

---

## 4. Module System

### 4.1. Module contract

```php
interface ModuleInterface {
    public function getName(): string;
    public function getVersion(): string;
    public function boot(ServiceContainer $container): void;
    public function registerRoutes(Router $router): void;
    public function registerCommands(CommandRegistry $registry): void;
    public function getEventSubscribers(): array;
    public function install(): void;
    public function uninstall(): void;
}
```

```json
// module.json
{
    "name": "ministra",
    "version": "1.0.0",
    "description": "Ministra portal integration module",
    "requires_core": ">=2.0"
}
```

### 4.2. Lifecycle

1. Module is placed in `modules/{name}/`
2. `ModuleLoader` scans `modules/*/module.json`
3. `config/modules.php` is checked for overrides (`enabled => false`)
4. `boot(ServiceContainer)` -> service registration
5. `registerRoutes(Router)` -> HTTP routes
6. `registerCommands(CommandRegistry)` -> CLI commands and crons
7. `getEventSubscribers()` -> event subscriptions
8. Install: `install()` -> create tables, initial data
9. Uninstall: `uninstall()` -> remove from `modules.php` -> delete directory

> **Important:** points 4-7 run only where `ModuleLoader::bootAll()` is called. In the current repository this does not happen in `public/index.php`, so web module integration remains partially declarative.

### 4.3. Isolation rules

```
OK - Module MAY:
    - Use services from core/ and domain/
   - Call Service/Repository from domain/
   - Register its own routes, commands, crons
   - Subscribe to core events
   - Have its own views, assets, configs

NOT OK - Module MUST NOT:
   - Modify files in core/ or domain/
   - Access DB bypassing Repository
   - Depend on another module without declaration in module.json
   - Override core routes or services
```

### 4.4. Event hooks

Events are used **only for module hooks**, not inside regular CRUD.

- **When to use:** Module wants to react to a core action (DVR record on stream start)
- **When NOT to use:** Inside CRUD operations - this is a direct Service call

---

## 5. Build Variants: MAIN vs LoadBalancer

### 5.1. Two artifacts from one codebase

│ Artifact │ Purpose │ What it includes │ What it excludes │
├──---------├──----------├──-------------├──--------------│
│ **MAIN** (`xc_vm.tar.gz`, installer: `XC_VM.zip`) │ Main server │ Full contents of `src/` │ Nothing │
│ **LoadBalancer (LB)** (`loadbalancer.tar.gz`) │ Streaming-focused build for LB nodes │ Subset of `src/` required for streaming, CLI, and service endpoints │ Modules, `ministra/`, most admin UI and part of admin-only API/cron │

**Principle:** LB is a subset of MAIN. Code is not forked; it is filtered at build time.

### 5.2. Build configuration

LB is assembled from the following directories and files (source of truth: `Makefile`):

**Directories (`LB_DIRS`):** `bin`, `cli`, `config`, `content`, `core`, `domain`, `includes`, `infrastructure`, `public`, `resources`, `signals`, `streaming`, `tmp`, `www`

**Root files (`LB_ROOT_FILES`):** `autoload.php`, `bootstrap.php`, `console.php`, `service`, `update`

**Directories removed from LB (`LB_DIRS_TO_REMOVE`):** `bin/install`, `bin/redis`, `bin/nginx/conf/codes`, `includes/api`, `includes/libs/resources`, `domain/User`, `domain/Device`, `domain/Auth`, `public/Controllers/Admin`, `public/Controllers/Player`, `public/Controllers/Reseller`, `public/Views`, `public/assets`, `public/routes`, `resources/langs`, `resources/libs`

**Files removed from LB (`LB_FILES_TO_REMOVE`):** including `bin/maxmind/GeoLite2-City.mmdb`, `infrastructure/legacy/reseller_api.php`, selected API controllers and `www/*` endpoints, `config/rclone.conf`, admin-only CLI commands/cronjobs, `domain/Epg/EPG.php`, `bin/nginx/conf/gzip.conf`

### 5.3. Development rules considering LB

1. Code in `domain/` used by streaming **must not** pull admin-only dependencies
2. When adding new root directories - update `LB_DIRS` in Makefile
3. `domain/` is partially required by LB - do not exclude it entirely, only admin-specific subdomains
4. All modules and directory `ministra/` are excluded from LB
5. Build check: `make new && make lb`

### 5.4. Package dependency diagram

```
                    +--------------+
                    │   public/    │   <- HTTP (Controllers, Views, Routes)
                    +------+-------+
                           │
                           │ depends on
              +------------+------------+
              v                         v
        +----------+               +----------+               +----------+
        │ domain/  │               │streaming/│               │ modules/ │
        +----+-----+               +----+-----+               +----+-----+
             │                          │                          │
             +--------------------------+--------------------------+
                                        v
        +----------------------------------------------------------+
        │                          core/                           │
        +----------------------------------------------------------+
                                        │
                                        v
        +----------------------------------------------------------+
        │                   infrastructure/                        │
        +----------------------------------------------------------+
```

**Arrows are always directed downward.** No lower layer knows about upper ones.

### 5.5. Update architecture

Update is implemented in two layers:

1. `php console.php update update` downloads the required archive (`xc_vm.tar.gz` for MAIN or `loadbalancer.tar.gz` for LB), validates MD5, and launches `src/update` in background.
2. `src/update` is a Python script that performs file update on the server.

#### What `src/update` does

1. Stops systemd service `xc_vm`
2. Extracts archive into temporary directory `xc_vm_update_*`
3. Deletes paths from temporary copy using hardcoded `UPDATE_EXCLUDE_DIRS`
4. Copies remaining files over live installation
5. Runs `chown -R xc_vm:xc_vm`
6. Runs `php console.php update post-update`
7. Starts service again
8. Deletes temporary directory and archive

#### What `update post-update` does

- On MAIN, runs `MigrationRunner::run()` for SQL migrations
- On all roles, runs `MigrationRunner::runFileCleanup()`, which reads `migrations/deleted_files.txt`, deletes listed files, then removes the list itself
- On MAIN with `auto_update_lbs`, sends update signal to LB servers

#### Important difference from old documentation

List of excluded directories is **not** read from `migrations/update_exclude_dirs.txt`. In current implementation it is hardcoded in Python constant `UPDATE_EXCLUDE_DIRS` inside `src/update`.

---

## 6. Transactions and Performance

### 6.1. Who owns transactions

**Target rule:** transaction is owned by Service. **Actual state:** this is only true in migrated sections; legacy code and part of domain services still execute SQL directly through `global $db`.

│ Context │ Transaction │ Owner │
├──---------├──----------├──--------------│
│ **Admin CRUD** │ One operation = one transaction │ Service │
│ **Mass edit** │ Entire batch = one transaction │ Service │
│ **Import** │ Chunk by 100 records │ Service │
│ **Cron** │ Each iteration = separate transaction │ CronJob │
│ **Streaming** │ No transactions │ - (hot path does not mutate through transactions) │

### 6.2. External processes

Operation "create stream + start ffmpeg + update nginx" is non-atomic. FFmpeg/nginx are external processes.

**Pattern:** DB operations in transaction -> external process after commit -> on failure update status (`status = 'error'`).

### 6.3. Two operation modes

│ Mode │ Path │ Frequency │ Allowed latency │
├──-----├──-----├──--------├──------------------│
│ **Hot path** (streaming) │ `www/stream/*.php` │ ~10K-100K req/min │ < 50ms p99 │
│ **Cold path** (admin) │ `public/index.php`, API │ ~1-100 req/min │ < 500ms p99 │

### 6.4. Hot path budget

```
Bootstrap:     < 5ms  (autoload + constants + DB)
Auth:          < 10ms (token + Redis + bruteforce)
Stream lookup: < 5ms  (Redis cache) │ < 15ms (DB fallback)
Delivery:      < 10ms (redirect + headers)
-------------------------------------
Total:         < 30ms (target) │ < 50ms (max)
```

**MUST NOT load in hot path:** Router, EventDispatcher with subscribers, full ServiceContainer boot.
**Allowed:** Database (persistent), Redis (single connection), GeoIP (mmap), `streaming/*`.
