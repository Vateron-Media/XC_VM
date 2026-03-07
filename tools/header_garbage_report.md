# Отчёт: мусорные паттерны в заголовках PHP-файлов

> Автоматический аудит ─ проверены первые 15-20 строк каждого .php файла  
> Директории: `src/core/`, `src/domain/`, `src/infrastructure/`, `src/streaming/`, `src/modules/`, `src/public/Controllers/`

## Легенда паттернов

| # | Код | Паттерн |
|---|-----|---------|
| 1 | `XC_VM—` | `XC_VM —` / `XC_VM —` (префикс в docblock) |
| 2 | `REPL_CU` | `Replaces CoreUtilities` / `Replaces StreamingUtilities` |
| 3 | `ИЗВЛЕЧ` | `Извлечён из` / `Извлечено из` |
| 4 | `PHASE` | `(Phase X.X)` / `(Фаза X)` |
| 5 | `§` | `(§X.X)` — ссылки на секции архитектуры |
| 6 | `ЗАМЕН` | `Заменяет` |
| 7 | `@SEE` | `@see CoreUtilities::` / `@see StreamingUtilities::` / `@see admin/` |
| 8 | `WHAT` | `What it replaces:` |
| 9 | `СОДЕРЖ` | `Содержит логику из` |
| 10 | `MIGR` | `Migrated from` / `Moved from` / `Перенесён` / `вынесены` |

---

## src/core/ (37 файлов с мусором из 48)

| Файл | Паттерны |
|------|----------|
| Auth/BruteforceGuard.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::checkFlood`) |
| Auth/SessionManager.php | `XC_VM—` `WHAT` `@SEE` (`admin/session.php`) |
| Auth/AuthManager.php | — чисто |
| Auth/PermissionChecker.php | — чисто |
| Backup/BackupService.php | `XC_VM—` |
| Cache/CacheInterface.php | `XC_VM—` |
| Cache/FileCache.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::setCache/getCache`) |
| Cache/RedisCache.php | `XC_VM—` `WHAT` |
| Config/AppConfig.php | `XC_VM—` |
| Config/Binaries.php | `XC_VM—` |
| Config/ConfigLoader.php | `XC_VM—` |
| Config/ConfigReader.php | `XC_VM—` `ЗАМЕН` (`CoreUtilities::$rConfig`) |
| Config/Paths.php | `XC_VM—` |
| Config/SettingsManager.php | `ИЗВЛЕЧ` `PHASE` (Phase 8.9) |
| Config/DatabaseConfig.php | — чисто |
| Config/ViewConfig.php | — чисто |
| Container/ServiceContainer.php | `XC_VM—` `ЗАМЕН` |
| Database/DatabaseHandler.php | `XC_VM—` |
| Database/QueryBuilder.php | — чисто |
| Diagnostics/DiagnosticsService.php | `XC_VM—` |
| Error/ErrorCodes.php | `XC_VM—` |
| Error/ErrorHandler.php | `XC_VM—` |
| Events/EventDispatcher.php | — чисто |
| Events/EventListenerInterface.php | — чисто |
| GeoIP/GeoIPService.php | `ИЗВЛЕЧ` |
| Http/Request.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::cleanGlobals`) |
| Http/RequestGuard.php | `XC_VM—` |
| Http/RequestManager.php | `ИЗВЛЕЧ` `PHASE` (Phase 8.10) |
| Http/Response.php | `XC_VM—` |
| Http/Router.php | `XC_VM—` `ЗАМЕН` |
| Http/MiddlewareInterface.php | — чисто |
| Init/Bootstrap.php | — чисто |
| Logging/DatabaseLogger.php | `XC_VM—` `ИЗВЛЕЧ` (`StreamingUtilities::clientLog`) |
| Logging/FileLogger.php | `XC_VM—` `ИЗВЛЕЧ` (`CoreUtilities::saveLog`) |
| Logging/LoggerInterface.php | `XC_VM—` |
| Module/ModuleLoader.php | `XC_VM—` |
| Module/ModuleInterface.php | `XC_VM—` |
| Process/Multithread.php | `XC_VM—` `ИЗВЛЕЧ` |
| Process/ProcessManager.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::checkCron/isProcessRunning/isStreamRunning`) `REPL_CU` (lines 277, 485) |
| Process/Thread.php | `XC_VM—` `ИЗВЛЕЧ` |
| Util/Encryption.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::encryptData`, `StreamingUtilities::mc_decrypt`) |
| Util/GeoIP.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::getISP/getIPInfo`) |
| Util/NetworkUtils.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::getUserIP`) |
| Util/SystemInfo.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::getStats`) |
| Util/TimeUtils.php | `XC_VM—` `WHAT` `@SEE` (`CoreUtilities::secondsToTime`) |
| Util/StringUtils.php | — чисто |
| Util/ArrayUtils.php | — чисто |
| Validation/InputValidator.php | `ИЗВЛЕЧ` `PHASE` (Phase 8.1) |

**Чистые файлы в core/ (11):** Auth/AuthManager.php, Auth/PermissionChecker.php, Config/DatabaseConfig.php, Config/ViewConfig.php, Database/QueryBuilder.php, Events/EventDispatcher.php, Events/EventListenerInterface.php, Http/MiddlewareInterface.php, Init/Bootstrap.php, Util/StringUtils.php, Util/ArrayUtils.php

---

## src/domain/ (4 файла с мусором из 34)

| Файл | Паттерны |
|------|----------|
| Auth/AuthRepository.php | `§` (§2.4) |
| Auth/AuthService.php | `§` (§2.4) |
| Stream/StreamConfigRepository.php | `§` (§7.3) `ИЗВЛЕЧ` |
| Stream/StreamProcess.php | `MIGR` (`Migrated from CoreUtilities::streamLog`) |

**Чистые файлы в domain/ (30):** Line/PackageService.php, Line/OutputFormatRepository.php, Line/LineService.php, Line/LineRepository.php, User/UserService.php, User/UserRepository.php, User/GroupService.php, Vod/SeriesService.php, Vod/MovieService.php, Vod/EpisodeService.php, Stream/ProfileService.php, Stream/ProviderService.php, Stream/RadioService.php, Stream/PlaylistGenerator.php, Stream/StreamSorter.php, Stream/StreamService.php, Stream/StreamRepository.php, Stream/M3UParser.php, Stream/CronGenerator.php, Stream/ConnectionTracker.php, Stream/ChannelService.php, Stream/CategoryService.php, Server/ServerRepository.php, Server/ServerService.php, Server/SettingsService.php, Security/BlocklistService.php, Bouquet/BouquetService.php, Device/MagService.php, Device/EnigmaService.php, Epg/EpgService.php

---

## src/infrastructure/ (1 файл с мусором из 3)

| Файл | Паттерны |
|------|----------|
| redis/RedisManager.php | `ИЗВЛЕЧ` |

**Чистые (2):** database/DatabaseFactory.php, cache/CacheReader.php

---

## src/streaming/ (1 файл с мусором из 18)

| Файл | Паттерны |
|------|----------|
| Codec/FfmpegPaths.php | `REPL_CU` (`Replaces CoreUtilities::$rFFMPEG_CPU, $rFFMPEG_GPU, $rFFPROBE`) |

**Чистые (17):** StreamingBootstrap.php, Protection/ConnectionLimiter.php, Health/WatchdogMonitor.php, Health/ProcessChecker.php, Health/HealthChecker.php, Codec/SubtitleExtractor.php, Codec/FFprobeRunner.php, Codec/FFmpegCommand.php, Delivery/StreamRedirector.php, Delivery/SignalSender.php, Delivery/SegmentReader.php, Delivery/OffAirHandler.php, Delivery/HLSGenerator.php, Balancer/ProxySelector.php, Auth/TokenAuth.php, Auth/StreamAuth.php, Auth/DeviceLock.php

---

## src/modules/ (18 файлов с мусором из 40)

| Файл | Паттерны |
|------|----------|
| fingerprint/FingerprintModule.php | `XC_VM—` `@SEE` (`admin/fingerprint.php`) |
| magscan/MagscanModule.php | `XC_VM—` `@SEE` (`admin/magscan_settings.php`) |
| ministra/MinistraModule.php | `XC_VM—` |
| ministra/PortalHelpers.php | `ИЗВЛЕЧ` |
| plex/PlexController.php | `XC_VM—` `ЗАМЕН` (×8 методов) |
| plex/PlexCron.php | `ИЗВЛЕЧ` `PHASE` (Фаза 5.1) `MIGR` (вынесены) `ЗАМЕН` |
| plex/PlexItem.php | `ИЗВЛЕЧ` `PHASE` (Фаза 5.1) `СОДЕРЖ` `ЗАМЕН` |
| plex/PlexModule.php | `XC_VM—` |
| theft-detection/TheftDetectionModule.php | `XC_VM—` `@SEE` (`admin/theft_detection.php`) |
| tmdb/TmdbCron.php | `XC_VM—` `ИЗВЛЕЧ` |
| tmdb/TmdbModule.php | `XC_VM—` |
| tmdb/TmdbPopularCron.php | `XC_VM—` `ИЗВЛЕЧ` |
| tmdb/TmdbService.php | `XC_VM—` `ИЗВЛЕЧ` |
| tmdb/lib.php | `XC_VM—` |
| watch/WatchController.php | `XC_VM—` `ЗАМЕН` (×9 методов) |
| watch/WatchCron.php | `ИЗВЛЕЧ` `PHASE` (Фаза 5.2) `MIGR` (вынесены) `ЗАМЕН` |
| watch/WatchItem.php | `ИЗВЛЕЧ` `PHASE` (Фаза 5.2) `ЗАМЕН` |
| watch/WatchModule.php | `XC_VM—` |

**Чистые файлы в modules/ (22):** watch/WatchService.php, watch/RecordingService.php, watch/views/*.php (7 шт.), tmdb/TmdbPopularCron.php views, plex/PlexService.php, plex/PlexRepository.php, plex/PlexAuth.php, plex/views/*.php (6 шт.), ministra/PortalHandler.php

---

## src/public/Controllers/ (86 файлов с мусором из ~135)

### Admin контроллеры (все — `PHASE` как минимум)

Все контроллеры ниже содержат **`PHASE`** (Phase 6.3) в строке 3.  
Те, что также помечены **`XC_VM—`**, показаны отдельно.

#### С XC_VM— + PHASE (17 шт.)

| Файл | Доп. паттерны |
|------|---------------|
| Admin/EnigmaController.php | `XC_VM—` `PHASE` |
| Admin/EnigmaMassController.php | `XC_VM—` `PHASE` |
| Admin/EnigmasController.php | `XC_VM—` `PHASE` |
| Admin/EpgController.php | `XC_VM—` `PHASE` |
| Admin/EpgViewController.php | `XC_VM—` `PHASE` |
| Admin/MagController.php | `XC_VM—` `PHASE` |
| Admin/MagMassController.php | `XC_VM—` `PHASE` |
| Admin/MagsController.php | `XC_VM—` `PHASE` |
| Admin/TicketController.php | `XC_VM—` `PHASE` |
| Admin/TicketsController.php | `XC_VM—` `PHASE` |
| Admin/TicketViewController.php | `XC_VM—` `PHASE` |
| Admin/UseragentController.php | `XC_VM—` `PHASE` |
| Admin/UseragentsController.php | `XC_VM—` `PHASE` |
| Admin/UserController.php | `XC_VM—` `PHASE` |
| Admin/UserLogsController.php | `XC_VM—` `PHASE` |
| Admin/UserMassController.php | `XC_VM—` `PHASE` |
| Admin/UsersController.php | `XC_VM—` `PHASE` |

#### Только PHASE (без XC_VM—) (63 шт.)

| Файл |
|------|
| Admin/ArchiveController.php |
| Admin/AsnsController.php |
| Admin/BackupsController.php |
| Admin/BouquetController.php |
| Admin/BouquetListController.php |
| Admin/BouquetOrderController.php |
| Admin/BouquetSortController.php |
| Admin/CacheController.php |
| Admin/ChannelOrderController.php |
| Admin/ClientLogController.php |
| Admin/CodeController.php |
| Admin/CodeEditController.php |
| Admin/CreatedChannelController.php |
| Admin/CreatedChannelListController.php |
| Admin/CreatedChannelMassController.php |
| Admin/CreditLogsController.php |
| Admin/DashboardController.php |
| Admin/EditProfileController.php |
| Admin/EpisodeController.php |
| Admin/EpisodeListController.php |
| Admin/EpisodeMassController.php |
| Admin/EpgListController.php |
| Admin/FingerprintController.php |
| Admin/GroupController.php |
| Admin/GroupEditController.php |
| Admin/HmacController.php |
| Admin/HmacEditController.php |
| Admin/IpController.php |
| Admin/IpEditController.php |
| Admin/IspController.php |
| Admin/IspEditController.php |
| Admin/LineActivityController.php |
| Admin/LineController.php |
| Admin/LineIpsController.php |
| Admin/LineListController.php |
| Admin/LineMassController.php |
| Admin/LiveConnectionsController.php |
| Admin/LoginLogController.php |
| Admin/MagEventController.php |
| Admin/MagscanSettingsController.php |
| Admin/MassDeleteController.php |
| Admin/MovieController.php |
| Admin/MovieListController.php |
| Admin/MovieMassController.php |
| Admin/MysqlSyslogController.php |
| Admin/OndemandController.php |
| Admin/PackageController.php |
| Admin/PackageEditController.php |
| Admin/PanelLogController.php |
| Admin/PlexAddController.php |
| Admin/PlexController.php |
| Admin/ProcessMonitorController.php |
| Admin/ProfileController.php |
| Admin/ProfileEditController.php |
| Admin/ProxiesController.php |
| Admin/ProxyController.php |
| Admin/ProviderController.php |
| Admin/ProviderEditController.php |
| Admin/QueueController.php |
| Admin/QuickToolsController.php |
| Admin/RadioController.php |
| Admin/RadioListController.php |
| Admin/RadioMassController.php |
| Admin/RecordController.php |
| Admin/RestreamLogController.php |
| Admin/ReviewController.php |
| Admin/RtmpIpController.php |
| Admin/RtmpIpEditController.php |
| Admin/RtmpMonitorController.php |
| Admin/SerieController.php |
| Admin/SeriesListController.php |
| Admin/SeriesMassController.php |
| Admin/ServerController.php |
| Admin/ServerInstallController.php |
| Admin/ServerListController.php |
| Admin/ServerOrderController.php |
| Admin/ServerViewController.php |
| Admin/SettingsController.php |
| Admin/SettingsPlexController.php |
| Admin/SettingsWatchController.php |
| Admin/StreamCategoriesController.php |
| Admin/StreamCategoryController.php |
| Admin/StreamController.php |
| Admin/StreamErrorsController.php |
| Admin/StreamListController.php |
| Admin/StreamMassController.php |
| Admin/StreamRankController.php |
| Admin/StreamReviewController.php |
| Admin/StreamToolsController.php |
| Admin/StreamViewController.php |
| Admin/TheftDetectionController.php |
| Admin/WatchAddController.php |
| Admin/WatchController.php |
| Admin/WatchOutputController.php |

#### Reseller контроллеры (1 шт.)

| Файл | Паттерны |
|------|----------|
| Reseller/ResellerUsersController.php | `PHASE` (Phase 6.4) |

**Чистые контроллеры:** Admin/BaseAdminController.php, Reseller/BaseResellerController.php, и 20+ Reseller контроллеров (ResellerDashboardController, ResellerLineController и т.д.)

---

## ⚠️ Дополнительно: src/public/ (не Controllers)

Хотя не входят в исходные 6 директорий, замечены паттерны:

- **src/public/index.php**: `XC_VM—` + `PHASE` (Phase 6.2) + `ЗАМЕН`
- **src/public/routes/admin.php**: `PHASE` (Phase 6.3) — 14 комментариев-разделителей
- **src/public/routes/reseller.php**: `PHASE` (Phase 6.4) — 7 комментариев-разделителей
- **src/public/Views/layouts/admin.php**: `PHASE` (Phase 6.1)
- **src/public/Views/layouts/footer.php**: `PHASE` (Phase 6.1)
- **src/public/Views/admin/*.php**: ~75 файлов с `PHASE` или `XC_VM—` (views)
- **src/public/Views/reseller/*.php**: ~15 файлов с `PHASE` (views)

---

## Сводка

| Директория | Всего PHP | С мусором | Чистых | Доля мусора |
|------------|-----------|-----------|--------|-------------|
| src/core/ | 48 | 37 | 11 | **77%** |
| src/domain/ | 34 | 4 | 30 | 12% |
| src/infrastructure/ | 3 | 1 | 2 | 33% |
| src/streaming/ | 18 | 1 | 17 | 6% |
| src/modules/ | 40 | 18 | 22 | 45% |
| src/public/Controllers/ | ~135 | ~86 | ~49 | **64%** |
| **Итого** | **~278** | **~147** | **~131** | **53%** |

### По типам паттернов (кол-во уникальных файлов)

| Паттерн | Файлов (в заголовках) |
|---------|---------|
| `XC_VM—` | ~89 (core: 30, modules: 13, Controllers: 17, Views: ~29) |
| `PHASE` / `Фаза` | ~175 (Controllers: ~81, Views: ~90, core: 3, modules: 3) |
| `ИЗВЛЕЧ` | 18 |
| `ЗАМЕН` | 7 файлов (в заголовках), + десятки упоминаний в методах |
| `@SEE` | 13 файлов |
| `WHAT` (What it replaces) | 11 файлов (все в core/) |
| `§` | 3 файла (все в domain/) |
| `REPL_CU` | 2 файла |
| `СОДЕРЖ` | 1 файл |
| `MIGR` | 3 файла |
