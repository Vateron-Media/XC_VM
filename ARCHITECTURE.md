# XC_VM — Архитектура

## Содержание

0. [Стратегические цели](#0-стратегические-цели)
1. [Архитектурный стиль и принципы](#1-архитектурный-стиль-и-принципы)
2. [Структура `/src`](#2-структура-src)
3. [Описание компонентов](#3-описание-компонентов)
4. [Система модулей](#4-система-модулей)
5. [Варианты сборки: MAIN vs LoadBalancer](#5-варианты-сборки-main-vs-loadbalancer)
6. [Транзакции и производительность](#6-транзакции-и-производительность)

> **План миграции** (фазы, стратегия рисков, порядок выполнения) — см. [MIGRATION.md](MIGRATION.md).
> **Правила для контрибьюторов** — см. [CONTRIBUTING.md](CONTRIBUTING.md).

---

## 0. Стратегические цели

| # | Цель | Приоритет | Метрика успеха |
|---|------|-----------|----------------|
| 1 | **Контрибьюторская доступность** | 🔴 Критический | PHP-разработчик среднего уровня делает первый PR за 2 часа, не зная DDD |
| 2 | **Поддерживаемость** | 🔴 Критический | Типичное изменение (добавить поток, изменить лимиты) < 1 час без риска сайд-эффектов |
| 3 | **Изоляция отказов** | 🔴 Критический | Баг в admin-панели не ломает стриминг. Баг в модуле не ломает ядро |
| 4 | **Multi-node** | 🟡 Важный | LB-сервер собирается из подмножества кода. Streaming-путь не загружает admin-логику |
| 5 | **Open-core коммерция** | 🟡 Важный | Коммерческие модули подключаются без модификации ядра. Удаление модуля не ломает систему |
| 6 | **Тестируемость** | 🟢 Поддерживающий | Любой сервис можно тестировать, подставив mock зависимости |

### Чем это НЕ является

- **Не DDD.** Нет Event Sourcing, CQRS, Aggregate Root. Простой паттерн: Controller → Service → Repository.
- **Не академический проект.** Архитектура оптимизирована под понятность, а не под теоретическую чистоту.

### Принцип принятия решений

1. **Контрибьютор поймёт за 5 минут?** → если нет — упростить
2. **Не ломает streaming hot path?** → если ломает — отклонить
3. **Можно изолировать в модуль?** → если нет — обосновать

Если решение улучшает «красоту кода», но повышает барьер входа — **отклонить**.

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

### 1.2. Strict Constructor Injection

`ServiceContainer` используется **ТОЛЬКО** на этапе bootstrap (composition root).
После bootstrap все зависимости передаются **через конструктор**. Ни один сервис не вызывает `$container->get()` внутри своих методов.

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

**Исключения (временные, фаза миграции):**
Legacy-код в `includes/` может обращаться к контейнеру напрямую. Каждое такое обращение помечается `// @legacy-container`.

### 1.3. Консолидация мелких классов

Не создавать отдельный файл/класс, если в нём < 150 строк **и** < 5 публичных методов.

| Ситуация | Что делать |
|----------|-----------|
| Repository < 5 методов | Объединить Service + Repository в один файл |
| Контекст = Service с 1-2 методами | Влить в ближайший родственный контекст |
| Отдельный «Validator» с 1 методом | Сделать private-методом в Service |
| Service + Repository > 300 строк суммарно | Разделить в отдельные файлы |

**Когда МОЖНО создавать отдельный файл:**
- Класс > 150 строк
- Класс имеет ≥ 5 публичных методов
- Класс используется из 3+ разных контекстов
- Интерфейс для DI (`CacheInterface`, `LoggerInterface`)

### 1.4. Нет Entity-классов

Данные передаются как `array`. Это PHP — массивы проще и понятнее, чем анемичные DTO-объекты.

### 1.5. Модуль = изолированная директория

Модуль — это директория с известным контрактом. Его можно удалить, и система продолжит работать (деградируя в функциональности, но не падая). Ядро (`core/`) не содержит проверок лицензий, шифрования или скрытых ограничений.

---

## 2. Структура `/src`

```
src/
├── autoload.php                     # PSR-подобный автозагрузчик (class map)
├── bootstrap.php                    # Единый bootstrap: DI container, config, контексты
├── console.php                      # CLI entry point (php console.php cron:*, cmd:*)
├── service                          # Bash: управление демонами
├── update                           # Bash: процесс обновления
│
├── core/                            # ═══ ЯДРО (инфраструктурные сервисы) ═══
│   ├── Auth/                        # SessionManager, Authenticator, Authorization, BruteforceGuard
│   ├── Backup/                      # BackupService
│   ├── Cache/                       # CacheInterface, FileCache, RedisCache
│   ├── Config/                      # AppConfig, ConfigLoader, ConfigReader, Paths, Binaries,
│   │                                #   DomainResolver, SettingsManager, SettingsRepository
│   ├── Container/                   # ServiceContainer (DI)
│   ├── Database/                    # Database (PDO), DatabaseHandler, MigrationRunner
│   ├── Diagnostics/                 # DiagnosticsService
│   ├── Error/                       # ErrorHandler, ErrorCodes
│   ├── Events/                      # EventDispatcher, EventInterface
│   ├── GeoIP/                       # GeoIPService
│   ├── Http/                        # Request, Response, Router, CurlClient, RequestGuard,
│   │                                #   RequestManager, Middleware/
│   ├── Init/                        # LegacyInitializer
│   ├── Logging/                     # LoggerInterface, FileLogger, DatabaseLogger
│   ├── Module/                      # ModuleInterface, ModuleLoader
│   ├── Process/                     # ProcessManager, Multithread, Thread
│   ├── Util/                        # Encryption, GeoIP, ImageUtils, NetworkUtils,
│   │                                #   StreamUtils, SystemInfo, TimeUtils
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
│   │                                #   StreamSorter, PlaylistGenerator,
│   │                                #   ProfileService, ProviderService, RadioService,
│   │                                #   StreamConfigRepository
│   ├── User/                        # UserService, UserRepository, GroupService
│   └── Vod/                         # MovieService, SeriesService, EpisodeService
│
├── streaming/                       # ═══ СТРИМИНГ-ДВИЖОК (hot path) ═══
│   ├── StreamingBootstrap.php       # Лёгкий init для стриминг-контекста
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
│   │   ├── Admin/                   # 96 контроллеров (BaseAdminController + по одному на страницу)
│   │   ├── Reseller/                # 30 контроллеров
│   │   ├── Api/                     # 9 контроллеров (Admin, Reseller, Player, Internal, Enigma2...)
│   │   └── Player/                  # 13 контроллеров
│   ├── Views/
│   │   ├── admin/                   # 149 шаблонов
│   │   ├── reseller/                # 24 шаблона
│   │   ├── player/                  # 7 шаблонов
│   │   └── layouts/                 # admin.php, footer.php, player/, reseller/
│   └── assets/                      # admin/, player/, reseller/ (CSS/JS/images/fonts)
│
├── cli/                             # ═══ CLI ТОЧКИ ВХОДА ═══
│   ├── CommandInterface.php
│   ├── CommandRegistry.php
│   ├── CronTrait.php
│   ├── DaemonTrait.php
│   ├── migration_logic.php
│   ├── Commands/                    # 24 команды (Monitor, Watchdog, Startup, Scanner, Queue...)
│   └── CronJobs/                    # 21 крон (StreamsCronJob, ServersCronJob, CacheCronJob...)
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
├── includes/                        # ═══ LEGACY (в процессе удаления) ═══
│   ├── admin.php                    # Legacy bootstrap — proxy к domain/core (Phase 15: удалить)
│   ├── reseller_api.php             # Legacy API
│   ├── ts.php                       # Timeshift утилиты
│   ├── api/                         # admin/table.php, reseller/table.php
│   ├── data/                        # permissions.php
│   ├── libs/                        # TMDb/, Translator, Logger, XmlStringStreamer и др.
│   └── python/                      # PTN/, release.py
│
├── resources/                       # ═══ РЕСУРСЫ ═══
│   ├── data/                        # admin_constants.php
│   ├── langs/                       # bg, de, en, es, fr, pt, ru (.ini)
│   └── libs/                        # (зарезервировано)
│
├── config/                          # ═══ КОНФИГУРАЦИЯ ═══
│   ├── modules.php                  # Список включённых модулей
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
| `Config/` | Загрузка конфигурации, резолв путей, управление настройками |
| `Database/` | PDO-обёртка (Database + DatabaseHandler), миграции |
| `Cache/` | Унифицированный кэш (File + Redis) через `CacheInterface` |
| `Auth/` | Единая авторизация (admin + reseller), RBAC, brute-force защита |
| `Http/` | Абстракция запросов, роутинг, middleware pipeline |
| `Process/` | Управление PID, потоками (Multithread, Thread) |
| `Logging/` | Унифицированное логирование (File + Database) |
| `Events/` | Event bus для модульных хуков |
| `Container/` | DI-контейнер (composition root only — §1.2) |
| `Error/` | Обработчик ошибок, коды ошибок |
| `GeoIP/` | Гео-определение по IP |
| `Util/` | Утилиты без состояния (Encryption, Network, Time, Image, Stream) |
| `Validation/` | Валидация входных данных |
| `Module/` | Загрузчик модулей, `ModuleInterface` |

### 3.2. `domain/` — Бизнес-логика

Сервисы и репозитории, организованные по контекстам.

**Правила:**
1. **Service** = вся бизнес-логика контекста (валидация, транзакции, side-effects)
2. **Repository** = только SQL. Принимает массивы, возвращает массивы
3. Если Repository < 5 методов и < 150 строк — живёт в одном файле с Service (§1.3)

> **Текущее состояние:** Часть domain-классов уже использует constructor injection (как показано ниже). Другая часть (StreamService, StreamRepository и др.) ещё работает через `global $db` и статические методы — миграция в процессе.

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
// ═══ LEGACY-ПАТТЕРН (ещё не мигрировано) ═══
// StreamService, StreamRepository — статические методы + global $db
// Миграция на constructor injection запланирована (см. MIGRATION.md)

class StreamRepository {
    public static function getById($rID) {
        global $db;
        $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
        return $db->fetchRecord();
    }
}
```

### 3.3. `streaming/` — Стриминг-движок

Весь hot path доставки видео. Выделен отдельно от `domain/` по причинам:
- Критичен к производительности (нельзя загружать всю бизнес-логику)
- Имеет собственный лёгкий bootstrap (`StreamingBootstrap.php`)
- Работает на уровне байтов/сегментов, а не CRUD

**Изоляция:**

```
streaming/ зависит от:
  ✅ core/ (Database, Redis, Logging, GeoIP, Encryption, NetworkUtils)
  ✅ domain/ (Repository — только SELECT-запросы)

  ❌ НЕ зависит от:
     - public/ (контроллеры)
     - modules/
     - domain/*Service.php (бизнес-мутации)
```

Streaming вызывает **только read-методы** Repository. Запись — через собственные классы (`ConnectionTracker`).

### 3.4. `public/` — HTTP точки входа

Получение запроса → вызов Service → формирование ответа. Никакой бизнес-логики.

- Один front controller `public/index.php` + `Router`
- Контроллеры вызывают **Service** для мутаций, **Repository** для чтения
- Шаблоны (`Views/`) — HTML с минимумом PHP-вставок
- Admin и Reseller — разные контроллеры, общие шаблоны

### 3.5. `cli/` — CLI точки входа

- Каждый демон/крон — отдельный Command/CronJob класс
- Все вызовы через `console.php` (например `php console.php cron:streams`)
- Общая инициализация через `bootstrap.php` + `ServiceContainer`

### 3.6. `modules/` — Опциональные модули

Каждый модуль — самодостаточная директория с `module.json` + класс, реализующий `ModuleInterface`.

| Модуль | Назначение |
|--------|-----------|
| `ministra/` | Ministra/Stalker Portal middleware |
| `plex/` | Plex integration |
| `tmdb/` | TMDB metadata fetching |
| `watch/` | Watch/DVR recording |
| `fingerprint/` | Watermarking |
| `theft-detection/` | Anti-theft detection |
| `magscan/` | MAG device scanning |

### 3.7. `includes/` — Legacy-код (в процессе удаления)

Оставшийся legacy-код, который ещё не полностью мигрирован:
- `admin.php` — legacy bootstrap (proxy к `domain/` и `core/`)
- `reseller_api.php` — legacy API-обработчик
- `libs/` — сторонние библиотеки (TMDb, Translator, XmlStringStreamer)

**Статус:** Удаление запланировано в Phase 15 (см. MIGRATION.md).

### 3.8. Bootstrap — контексты инициализации

`bootstrap.php` предоставляет `XC_Bootstrap` с четырьмя контекстами:

| Контекст | Что загружает | Для чего |
|----------|--------------|----------|
| `CONTEXT_MINIMAL` | autoload + constants + config + Logger | Скрипты, которым нужны только пути и конфиг |
| `CONTEXT_CLI` | + Database + LegacyInitializer (+ Redis опционально) | Cron-задачи, CLI-скрипты |
| `CONTEXT_STREAM` | + Database (lightweight, cached) | Стриминг-эндпоинты (hot path) |
| `CONTEXT_ADMIN` | + Database + LegacyInitializer + Redis + API + ResellerAPI + Translator + MobileDetect + session | Админ/реселлер-панель |

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

### 4.3. Правила изоляции

```
✅ Модуль МОЖЕТ:
   - Использовать сервисы из core/ через constructor injection
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
| **MAIN** | Основной сервер (admin + streaming) | Всё содержимое `src/` | Ничего |
| **LoadBalancer (LB)** | Стриминг-сервер без управления | Только стриминг + инфраструктура | Админ-панель, модули, player |

**Принцип:** LB — подмножество MAIN. Код не форкается, а фильтруется при сборке.

### 5.2. Конфигурация сборки

LB собирается из следующих директорий и файлов (определены в `Makefile`):

**Директории (`LB_DIRS`):** `bin`, `cli`, `config`, `content`, `core`, `domain`, `includes`, `infrastructure`, `resources`, `signals`, `streaming`, `tmp`, `www`

**Root-файлы (`LB_ROOT_FILES`):** `autoload.php`, `bootstrap.php`, `console.php`, `service`, `update`

**Исключения (`LB_DIRS_TO_REMOVE`):** `bin/install`, `bin/redis`, `bin/nginx/conf/codes`, `includes/api`, `includes/libs/resources`, `domain/User`, `domain/Device`, `domain/Auth`, `resources/langs`, `resources/libs`

**Отдельные файлы, удаляемые из LB (`LB_FILES_TO_REMOVE`):** `includes/admin.php`, `includes/reseller_api.php`, `www/probe.php`, `config/rclone.conf`, ряд CLI-команд и CronJob'ов (admin-only), `domain/Epg/EPG.php`

**Полностью исключены из LB:** `public/`, `modules/`, `ministra/`

### 5.3. Правила для разработки с учётом LB

1. Код в `domain/`, используемый через streaming, **не должен** тянуть admin-only зависимости
2. При добавлении новых root-директорий — обновить `LB_DIRS` в Makefile
3. `domain/` частично нужен LB — нельзя целиком исключать, только admin-specific поддомены
4. Все модули — это admin-функциональность, в LB не попадают
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

---

## 6. Транзакции и производительность

### 6.1. Кто управляет транзакциями

**Правило:** Транзакцией управляет **Service**. Контроллер и Repository не открывают транзакции.

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
