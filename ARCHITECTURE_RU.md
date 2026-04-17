# XC_VM — Архитектура

## Содержание

1. [Архитектурный стиль и принципы](#1-архитектурный-стиль-и-принципы)
2. [Структура `/src`](#2-структура-src)
3. [Описание компонентов](#3-описание-компонентов)
4. [Система модулей](#4-система-модулей)
5. [Варианты сборки: MAIN vs LoadBalancer](#5-варианты-сборки-main-vs-loadbalancer)
6. [Транзакции и производительность](#6-транзакции-и-производительность)

> **План миграции** (фазы, стратегия рисков, порядок выполнения) — см. [MIGRATION.md](MIGRATION.md).
> **Правила для контрибьюторов** — см. [CONTRIBUTING.md](CONTRIBUTING.md).

---

## 1. Архитектурный стиль и принципы

### 1.1. Формализация

**Структурированный монолит с предсказуемой организацией по контексту.**

Не DDD. Не Hexagonal. Не Clean Architecture. Не микросервисы.
Простой PHP-монолит, разложенный по контекстам с минимумом абстракций.

#### Паттерн: Controller → Service → Repository

Каждый контекст (Streams, VOD, Lines, Users...) внутри устроен одинаково:

```
domain/Stream/
  ├── StreamService.php       # Бизнес-логика + оркестрация
  ├── StreamRepository.php    # SQL-запросы (SELECT/INSERT/UPDATE/DELETE)
  └── StreamProcess.php       # Специализированные операции (ffmpeg, kill)

public/Controllers/Admin/
  └── StreamController.php    # Принять запрос → вызвать Service → отдать ответ
```

#### Правила зависимостей

```
Controller  →  Service  →  Repository  →  Database
                  ↓
          Infrastructure (nginx, redis, ffmpeg)
```

| Слой | Можно зависеть от | НЕЛЬЗЯ зависеть от |
|------|-------------------|--------------------|
| `public/` | `domain/` (Service + Repository), `core/` | `streaming/`, `modules/` напрямую |
| `domain/` | `core/` (Database, Cache, Events) | `public/`, `streaming/`, `modules/`, `infrastructure/` |
| `core/` | Только другие `core/` подкаталоги | Всё остальное |
| `streaming/` | `core/` (subset), `domain/` (read-only queries) | `public/`, `modules/` |
| `modules/` | `domain/` (Service, Repository), `core/` | Другие модули (без явной зависимости), `public/`, `streaming/` |

### 1.2. Constructor Injection — целевой стандарт, но не текущее глобальное состояние

`ServiceContainer` задуман как composition root, и новый код по возможности должен получать зависимости через конструктор. Однако текущее состояние системы смешанное:

- `bootstrap.php` создаёт и наполняет `ServiceContainer`
- `Router` и часть legacy-инициализации всё ещё получают зависимости через контейнер во время dispatch/bootstrap
- Значительная часть `domain/` и часть `streaming/` по-прежнему используют `global $db`, `global $rSettings` и совместимость с legacy bootstrap

Иными словами, constructor injection уже используется как направление миграции, но не является жёстко соблюдаемым правилом для всего дерева `src/`.

```php
// ✅ ПРАВИЛЬНО — constructor injection:
class StreamService {
    public function __construct(
        private StreamRepository $repository,
        private EventDispatcher $events,
        private FileLogger $logger
    ) {}
}

// ❌ ЗАПРЕЩЕНО — Service Locator внутри сервиса:
class StreamService {
    public function create(array $data): int {
        $db = ServiceContainer::getInstance()->get('db');  // ← АНТИПАТТЕРН
    }
}
```

**Фактические исключения:**
Legacy-слой в `infrastructure/legacy/`, часть bootstrap-кода, `Router`, а также значительная часть `domain/` пока ещё не приведены к чистому constructor injection.

### 1.3. Нет Entity-классов

Данные передаются как `array`. Это PHP — массивы проще и понятнее, чем анемичные DTO-объекты.

### 1.4. Модуль = изолированная директория

Модуль — это директория с известным контрактом. Его можно удалить, и система продолжит работать (деградируя в функциональности, но не падая). Ядро (`core/`) не содержит проверок лицензий, шифрования или скрытых ограничений.

---

## 2. Структура `/src`

```
src/
├── autoload.php                     # PSR-подобный автозагрузчик (class map)
├── bootstrap.php                    # Единый bootstrap: DI container, config, контексты
├── console.php                      # CLI entry point (php console.php cron:*, cmd:*)
├── service                          # Bash: управление демонами
├── update                           # Python: процесс обновления
│
├── core/                            # ═══ ЯДРО (инфраструктурные сервисы) ═══
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
├── domain/                          # ═══ БИЗНЕС-ЛОГИКА (сервисы и репозитории) ═══
│   ├── Auth/                        # AuthService, AuthRepository
│   ├── Bouquet/                     # BouquetService
│   ├── Device/                      # MagService, EnigmaService
│   ├── Epg/                         # EPG (XML-парсер), EpgService
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
├── streaming/                       # ═══ СТРИМИНГ-ДВИЖОК (hot path) ═══
│   ├── StreamingBootstrap.php       # Лёгкий init для стриминг-контекста
│   ├── AsyncFileOperations.php      # Асинхронные файловые операции
│   ├── TimeshiftClient.php          # Клиент для timeshift-запросов
│   ├── Auth/                        # StreamAuth, StreamAuthMiddleware
│   ├── Balancer/                    # ProxySelector
│   ├── Codec/                       # FFmpegCommand, FFprobeRunner, FfmpegPaths
│   ├── Delivery/                    # HLSGenerator, OffAirHandler, SegmentReader,
│   │                                #   SignalSender, StreamRedirector
│   ├── Health/                      # ProcessChecker
│   ├── Lifecycle/                   # ShutdownHandler
│   └── Protection/                  # ConnectionLimiter
│
├── public/                          # ═══ HTTP ТОЧКИ ВХОДА ═══
│   ├── index.php                    # Единый front controller
│   ├── routes/                      # admin.php, reseller.php, player.php
│   ├── Controllers/
│   │   ├── Admin/                   # Админ-контроллеры и fallback-обработчики
│   │   ├── Reseller/                # Контроллеры reseller-панели
│   │   ├── Api/                     # API-контроллеры (Admin, Reseller, Player, Internal, Enigma2...)
│   │   └── Player/                  # Контроллеры player-панели
│   ├── Views/
│   │   ├── admin/                   # Admin templates и partials
│   │   ├── reseller/                # Reseller templates
│   │   ├── player/                  # Player templates
│   │   └── layouts/                 # admin.php, footer.php, player/, reseller/
│   └── assets/                      # admin/, player/, reseller/ (CSS/JS/images/fonts)
│
├── cli/                             # ═══ CLI ТОЧКИ ВХОДА ═══
│   ├── CommandInterface.php
│   ├── CommandRegistry.php
│   ├── CronTrait.php
│   ├── DaemonTrait.php
│   ├── migration_logic.php
│   ├── Commands/                    # CLI-команды демонов и служебных операций
│   └── CronJobs/                    # Cron-задачи панели
│
├── modules/                         # ═══ ОПЦИОНАЛЬНЫЕ МОДУЛИ ═══
│   ├── ministra/                    # Ministra/Stalker Portal middleware
│   ├── plex/                        # Plex integration
│   ├── tmdb/                        # TMDB metadata fetching
│   ├── watch/                       # Watch/DVR recording
│   ├── fingerprint/                 # Watermarking
│   ├── theft-detection/             # Anti-theft detection
│   └── magscan/                     # MAG device scanning
│
├── infrastructure/                  # ═══ СИСТЕМНАЯ ИНФРАСТРУКТУРА ═══
│   ├── bootstrap/                   # Функции/сессии для admin, reseller, player (facade layer)
│   ├── cache/                       # CacheReader
│   ├── database/                    # DatabaseFactory
│   ├── legacy/                      # resize_body, reseller_api_actions и др.
│   ├── nginx/                       # templates/
│   ├── redis/                       # RedisManager
│   └── service/                     # (bash-скрипты демонов)
│
├── resources/                       # ═══ РЕСУРСЫ ═══
│   ├── data/                        # admin_constants.php
│   ├── langs/                       # bg, de, en, es, fr, pt, ru (.ini)
│   └── libs/                        # (зарезервировано)
│
├── config/                          # ═══ КОНФИГУРАЦИЯ ═══
│   ├── modules.php                  # Список включённых модулей
│   ├── permissions.php              # Определения прав доступа
│   └── rclone.conf
│
├── www/                             # ═══ WEB ENTRY POINTS ═══
│   ├── constants.php                # Фасад обратной совместимости
│   ├── init.php                     # Legacy init
│   ├── index.html                   # Ministra portal HTML
│   ├── probe.php                    # FFprobe endpoint
│   ├── progress.php                 # Progress reporting
│   ├── images/                      # Статические изображения
│   ├── admin/                       # 7 entry-points (api.php, index.php, live.php...)
│   └── stream/                      # 11 streaming endpoints (auth, live, vod, segment...)
│
├── bin/                             # Внешние бинарники (ffmpeg, certbot, yt-dlp, nginx, nginx_rtmp, redis, php...)
├── content/                         # Медиа-контент (archive, created, delayed, epg, playlists, streams, video, vod)
├── backups/                         # Резервные копии
├── signals/                         # Сигнальные файлы
├── tmp/                             # Временные файлы
├── migrations/                      # SQL-миграции (001_update_crontab_filenames.sql...)
└── ministra/                        # Ministra JS-файлы (отдаются nginx напрямую)
```

---

## 3. Описание компонентов

### 3.1. `core/` — Ядро

Инфраструктурные сервисы для любого контекста исполнения. Не содержит бизнес-логики.

**Ключевое правило:** `core/` не знает о существовании `domain/`, `streaming/`, `modules/`, `public/`. Зависимости направлены только внутрь ядра.

| Подкаталог | Что даёт |
|------------|----------|
| `Auth/` | Единая авторизация (admin + reseller), RBAC, brute-force защита, авторизация страниц |
| `Backup/` | Бэкапы (BackupService) |
| `Cache/` | Унифицированный кэш (File + Redis) через `CacheInterface` |
| `Config/` | Загрузка конфигурации, резолв путей, управление настройками |
| `Container/` | DI-контейнер (composition root only — §1.2) |
| `Database/` | PDO-обёртка (Database + DatabaseHandler + QueryHelper), миграции |
| `Device/` | Определение устройства (MobileDetect) |
| `Diagnostics/` | Диагностика системы |
| `Error/` | Обработчик ошибок, коды ошибок |
| `Events/` | Event bus для модульных хуков |
| `GeoIP/` | Гео-определение по IP |
| `Http/` | Абстракция запросов, роутинг, ApiClient, CurlClient, middleware pipeline |
| `Init/` | Legacy-инициализация (LegacyInitializer) |
| `Localization/` | Перевод интерфейса (Translator) |
| `Logging/` | Унифицированное логирование (File + Database + Logger) |
| `Module/` | Загрузчик модулей, `ModuleInterface` |
| `Parsing/` | Парсинг XML (XmlStringStreamer) |
| `Process/` | Управление PID, потоками (Multithread, Thread) |
| `Storage/` | Облачное хранилище (DropboxClient) |
| `Updates/` | Обновления (GithubReleases) |
| `Util/` | Утилиты без состояния (Encryption, Network, Time, Image, Stream, AdminHelpers) |
| `Validation/` | Валидация входных данных |

### 3.2. `domain/` — Бизнес-логика и слой совместимости

`domain/` организован по контекстам, но внутри него одновременно сосуществуют два стиля:

1. Новый код с отдельными Service/Repository-классами и явными зависимостями.
2. Legacy-классы, которые продолжают работать через `global $db`, `global $rSettings` и процедурные helper-потоки.

**Фактические правила на текущий момент:**
1. Service/Repository-разделение есть во многих контекстах, но не применяется одинаково строго во всех папках.
2. Repository не является единственной точкой доступа к SQL: часть Service-классов и legacy-компонентов выполняют запросы напрямую.
3. Источник истины для схемы БД — SQL-файлы в `src/migrations/` плюс runtime-таблица `migrations`, которую создаёт `MigrationRunner`.

> **Текущее состояние:** `global $db` массово используется во всех крупных доменных контекстах (`Stream`, `Vod`, `User`, `Server`, `Line`, `Auth`, `Bouquet`, `Security` и др.). Миграция в сторону constructor injection ещё не завершена.

```php
// ═══ ЦЕЛЕВОЙ ПАТТЕРН (новый код) ═══

// Service делает всё:
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

// Repository = только SQL:
class StreamRepository {
    public function __construct(private Database $db) {}
    public function findById(int $id): ?array {
        return $this->db->row("SELECT * FROM streams WHERE id = ?", [$id]);
    }
}
```

```php
// ═══ РЕАЛЬНЫЙ LEGACY-ПАТТЕРН (широко используется в текущем коде) ═══
// Многие domain-классы продолжают обращаться к global $db

class StreamRepository {
    public static function getById($rID) {
        global $db;
        $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
        return $db->fetchRecord();
    }
}
```

### 3.3. `streaming/` — Hot path доставки, но не единственная точка стриминг-логики

`streaming/` содержит специализированные классы hot path, однако фактический runtime-поток распределён между двумя слоями:

- `www/stream/*.php` и `www/stream/init.php` выполняют procedural bootstrap стриминг-эндпоинтов
- `streaming/StreamingBootstrap.php` и соседние классы завершают инициализацию и обработку доставки

**Ключевой факт:** текущие web streaming endpoints **не используют** `XC_Bootstrap::CONTEXT_STREAM`. Этот контекст объявлен в `bootstrap.php`, но hot path реально стартует через `www/stream/init.php`.

**Что реально делает hot path:**

1. Загружает constants/error/config/bin helpers из `www/stream/init.php`
2. Читает `settings` из файлового кэша
3. Вызывает `StreamingBootstrap::bootstrap($endpoint, $settings)`
4. Выполняет дальнейшую обработку через классы `streaming/` и часть legacy/global-state логики

Часть классов в `streaming/` до сих пор использует `global $db`, поэтому изоляция от legacy достигнута не полностью.

### 3.4. `public/` — Основной HTTP-вход, но не единственный runtime-path

`public/index.php` является front controller для admin/reseller/player страниц, однако в проекте одновременно используются несколько HTTP-потоков.

**Текущий расклад:**

- Admin/Reseller/Player страницы идут через `public/index.php` → `public/routes/{scope}.php` → `Router` → controller
- REST-путь для `includes/api/admin` и `includes/api/reseller` short-circuit'ится в `public/index.php` и вызывает `AdminApiController` / `ResellerRestApiController` напрямую
- Streaming/API путь с `XC_SCOPE=api` и `XC_API=*` загружает `www/init.php` или `www/stream/init.php`, после чего вызывает API-контроллер напрямую

Это означает, что Router покрывает панельные страницы, но не является единым диспетчером для всех HTTP-запросов в системе.

### 3.5. `cli/` — CLI точки входа

- Каждый демон/крон — отдельный Command/CronJob класс
- Все вызовы через `console.php` (например `php console.php cron:streams`)
- Общая инициализация через `bootstrap.php` + `ServiceContainer`

### 3.6. `modules/` — Опциональные модули

Каждый модуль — директория с `module.json` и PHP-классом, реализующим `ModuleInterface`.

| Модуль | Назначение |
|--------|-----------|
| `ministra/` | Ministra/Stalker Portal middleware |
| `plex/` | Plex integration |
| `tmdb/` | TMDB metadata fetching |
| `watch/` | Watch/DVR recording |
| `fingerprint/` | Watermarking |
| `theft-detection/` | Anti-theft detection |
| `magscan/` | MAG device scanning |

**Текущее ограничение:** `console.php` действительно вызывает `ModuleLoader::loadAll()` и `registerAllCommands()`, поэтому CLI-интеграция модулей активна. `public/index.php` **не вызывает** `ModuleLoader::bootAll()`, поэтому `boot()` и `registerRoutes()` не участвуют в текущем web runtime-path. Модульные страницы, которые уже доступны в админке, заведены статически в `public/routes/admin.php`.

### 3.7. `infrastructure/legacy/` — Остаточный legacy-код

Остаточный legacy-код, вынесенный из удалённого `includes/`:
- `resize_body.php` — логика resize для admin
- `reseller_api.php` — legacy reseller API-обработчик
- `reseller_api_actions.php` — действия reseller API
- `reseller_table_body.php` — формирование таблиц для reseller

**Статус:** Директория `includes/` полностью удалена (Phase 15 завершена). Оставшийся код в `infrastructure/legacy/` подлежит дальнейшей миграции в `domain/` сервисы.

### 3.8. Bootstrap — контексты инициализации

`bootstrap.php` предоставляет `XC_Bootstrap` с четырьмя контекстами:

| Контекст | Что загружает | Для чего |
|----------|--------------|----------|
| `CONTEXT_MINIMAL` | autoload + constants + config + Logger | Скрипты, которым нужны только пути и конфиг |
| `CONTEXT_CLI` | + Database + LegacyInitializer (+ Redis опционально) | Cron-задачи, CLI-скрипты |
| `CONTEXT_STREAM` | + Database (cached) | Объявлен для лёгкого стриминг/bootstrap-сценария, но текущие web streaming endpoints его не используют |
| `CONTEXT_ADMIN` | + Database + LegacyInitializer + Redis + API + ResellerAPI + Translator + MobileDetect + session | Админ/реселлер-панель |

### 3.9. Фактические runtime-потоки

#### Admin/Reseller/Player страницы

`nginx -> public/index.php -> scope/pageName resolution -> optional session/functions bootstrap -> public/routes/{scope}.php -> Router::dispatch() -> controller -> domain/core`

#### Admin/Reseller REST API

`nginx -> public/index.php -> rawScope=includes/api/* -> XC_Bootstrap::CONTEXT_ADMIN -> AdminApiController|ResellerRestApiController -> legacy/domain calls`

#### Streaming API через `XC_SCOPE=api`

`nginx -> public/index.php -> XC_API dispatch -> www/init.php | www/stream/init.php -> ApiController -> legacy/domain/streaming`

#### Streaming endpoints `www/stream/*.php`

`nginx -> www/stream/{endpoint}.php -> www/stream/init.php -> StreamingBootstrap::bootstrap() -> streaming/* + global-state helpers`

---

## 4. Система модулей

### 4.1. Контракт модуля

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

### 4.2. Жизненный цикл

1. Модуль размещается в `modules/{name}/`
2. `ModuleLoader` сканирует `modules/*/module.json`
3. `config/modules.php` проверяется на overrides (`enabled => false`)
4. `boot(ServiceContainer)` → регистрация сервисов
5. `registerRoutes(Router)` → HTTP-маршруты
6. `registerCommands(CommandRegistry)` → CLI-команды и кроны
7. `getEventSubscribers()` → подписки на события
8. Установка: `install()` — создание таблиц, начальные данные
9. Удаление: `uninstall()` → убрать из `modules.php` → удалить папку

> **Важно:** пункты 4–7 выполняются только там, где вызывается `ModuleLoader::bootAll()`. В текущем репозитории это не происходит в `public/index.php`, поэтому web-интеграция модулей остаётся частично декларативной.

### 4.3. Правила изоляции

```
✅ Модуль МОЖЕТ:
    - Использовать сервисы из core/ и domain/
   - Вызывать Service/Repository из domain/
   - Регистрировать свои маршруты, команды, кроны
   - Подписываться на события ядра
   - Иметь свои views, assets, конфиги

❌ Модуль НЕ МОЖЕТ:
   - Модифицировать файлы core/ или domain/
   - Обращаться к БД мимо Repository
   - Зависеть от другого модуля без декларации в module.json
   - Переопределять маршруты или сервисы ядра
```

### 4.4. События-хуки

События используются **только для модульных хуков**, а не внутри обычного CRUD.

- **Когда использовать:** Модуль хочет отреагировать на действие ядра (DVR-запись при запуске потока)
- **Когда НЕ использовать:** Внутри CRUD-операций — это прямой вызов в Service

---

## 5. Варианты сборки: MAIN vs LoadBalancer

### 5.1. Два артефакта из одной кодовой базы

| Артефакт | Назначение | Что включает | Что исключает |
|----------|-----------|--------------|---------------|
| **MAIN** (`xc_vm.tar.gz`, installer: `XC_VM.zip`) | Основной сервер | Полное содержимое `src/` | Ничего |
| **LoadBalancer (LB)** (`loadbalancer.tar.gz`) | Streaming-focused сборка для LB-узлов | Подмножество `src/`, нужное для стриминга, CLI и служебных endpoint'ов | Модули, `ministra/`, большая часть admin UI и часть admin-only API/cron |

**Принцип:** LB — подмножество MAIN. Код не форкается, а фильтруется при сборке.

### 5.2. Конфигурация сборки

LB собирается из следующих директорий и файлов (источник истины — `Makefile`):

**Директории (`LB_DIRS`):** `bin`, `cli`, `config`, `content`, `core`, `domain`, `includes`, `infrastructure`, `public`, `resources`, `signals`, `streaming`, `tmp`, `www`

**Root-файлы (`LB_ROOT_FILES`):** `autoload.php`, `bootstrap.php`, `console.php`, `service`, `update`

**Директории, удаляемые из LB (`LB_DIRS_TO_REMOVE`):** `bin/install`, `bin/redis`, `bin/nginx/conf/codes`, `includes/api`, `includes/libs/resources`, `domain/User`, `domain/Device`, `domain/Auth`, `public/Controllers/Admin`, `public/Controllers/Player`, `public/Controllers/Reseller`, `public/Views`, `public/assets`, `public/routes`, `resources/langs`, `resources/libs`

**Файлы, удаляемые из LB (`LB_FILES_TO_REMOVE`):** среди прочего `bin/maxmind/GeoLite2-City.mmdb`, `infrastructure/legacy/reseller_api.php`, отдельные API-контроллеры и `www/*` endpoints, `config/rclone.conf`, admin-only CLI-команды/cronjobs, `domain/Epg/EPG.php`, `bin/nginx/conf/gzip.conf`


### 5.3. Правила для разработки с учётом LB

1. Код в `domain/`, используемый через streaming, **не должен** тянуть admin-only зависимости
2. При добавлении новых root-директорий — обновить `LB_DIRS` в Makefile
3. `domain/` частично нужен LB — нельзя целиком исключать, только admin-specific поддомены
4. Все модули и директория `ministra/` не попадают в LB
5. Проверка сборки: `make new && make lb`

### 5.4. Пакетная диаграмма зависимостей

```
                    ┌──────────────┐
                    │   public/    │   ← HTTP (Controllers, Views, Routes)
                    └──────┬───────┘
                           │ depends on
              ┌────────────┼────────────┐
              ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │ domain/  │ │streaming/│ │ modules/ │
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │            │            │
             └────────────┼────────────┘
                          ▼
        ┌──────────────────────────────────┐
        │             core/                │
        └──────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────────┐
        │        infrastructure/           │
        └──────────────────────────────────┘
```

**Стрелки всегда направлены вниз.** Ни один нижний слой не знает о верхних.

### 5.5. Архитектура обновления

Обновление реализовано в двух слоях:

1. `php console.php update update` скачивает нужный архив (`xc_vm.tar.gz` для MAIN или `loadbalancer.tar.gz` для LB), проверяет MD5 и запускает `src/update` в фоне.
2. `src/update` — Python-скрипт, который выполняет файловое обновление на сервере.

#### Что делает `src/update`

1. Останавливает systemd-сервис `xc_vm`
2. Распаковывает архив во временную директорию `xc_vm_update_*`
3. Удаляет из временной копии пути из hardcoded-константы `UPDATE_EXCLUDE_DIRS`
4. Копирует оставшиеся файлы поверх live installation
5. Выполняет `chown -R xc_vm:xc_vm`
6. Запускает `php console.php update post-update`
7. Запускает сервис обратно
8. Удаляет временную директорию и архив

#### Что делает `update post-update`

- На MAIN запускает `MigrationRunner::run()` для SQL-миграций
- На всех ролях запускает `MigrationRunner::runFileCleanup()`, который читает `migrations/deleted_files.txt`, удаляет перечисленные файлы и затем удаляет сам список
- На MAIN при `auto_update_lbs` рассылает сигнал обновления LB-серверам

#### Важное отличие от старой документации

Список исключаемых директорий **не** берётся из `migrations/update_exclude_dirs.txt`. В текущей реализации он жёстко задан в Python-константе `UPDATE_EXCLUDE_DIRS` внутри `src/update`.

---

## 6. Транзакции и производительность

### 6.1. Кто управляет транзакциями

**Целевое правило:** транзакцией управляет Service. **Фактическое состояние:** это соблюдается только в мигрированных участках; legacy-код и часть domain-сервисов всё ещё выполняют SQL-операции напрямую через `global $db`.

| Контекст | Транзакция | Кто управляет |
|----------|-----------|---------------|
| **Admin CRUD** | Одна операция = одна транзакция | Service |
| **Mass edit** | Весь batch = одна транзакция | Service |
| **Import** | Chunk по 100 записей | Service |
| **Cron** | Каждая итерация = отдельная транзакция | CronJob |
| **Streaming** | Нет транзакций | — (hot path не мутирует через транзакции) |

### 6.2. Внешние процессы

Операция «создать поток + запустить ffmpeg + обновить nginx» — не атомарна. FFmpeg/nginx — внешние процессы.

**Паттерн:** DB-операции в транзакции → внешние процессы после commit → при сбое обновить статус (`status = 'error'`).

### 6.3. Два режима работы

| Режим | Путь | Частота | Допустимая latency |
|-------|------|---------|-------------------|
| **Hot path** (streaming) | `www/stream/*.php` | ~10K–100K req/min | < 50ms p99 |
| **Cold path** (admin) | `public/index.php`, API | ~1–100 req/min | < 500ms p99 |

### 6.4. Бюджет hot path

```
Bootstrap:     < 5ms  (autoload + constants + DB)
Auth:          < 10ms (token + Redis + bruteforce)
Stream lookup: < 5ms  (Redis cache) | < 15ms (DB fallback)
Delivery:      < 10ms (redirect + headers)
─────────────────────────────────────
Total:         < 30ms (target) | < 50ms (max)
```

**НЕЛЬЗЯ загружать в hot path:** Router, EventDispatcher с подписчиками, ServiceContainer полный boot.
**МОЖНО:** Database (persistent), Redis (single connection), GeoIP (mmap), `streaming/*`.
