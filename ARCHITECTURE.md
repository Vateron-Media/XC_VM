# XC_VM — Архитектурный план реорганизации `/src`

## Содержание

0. [Стратегические цели](#0-стратегические-цели)
1. [Диагноз текущего состояния](#1-диагноз-текущего-состояния)
2. [Архитектурный стиль и принципы](#2-архитектурный-стиль-и-принципы)
3. [Целевая структура `/src`](#3-целевая-структура-src)
4. [Описание компонентов](#4-описание-компонентов)
5. [Карта миграции: откуда → куда](#5-карта-миграции-откуда--куда)
6. [Система модулей](#6-система-модулей)
7. [Границы ядра и модулей](#7-границы-ядра-и-модулей)
8. [Варианты сборки: MAIN vs LoadBalancer](#8-варианты-сборки-main-vs-loadbalancer)
9. [Порядок миграции](#9-порядок-миграции)
10. [Транзакции и производительность](#10-транзакции-и-производительность)
11. [Стратегия миграции по рискам](#11-стратегия-миграции-по-рискам)
12. [Правила для контрибьюторов](#12-правила-для-контрибьюторов)

---

## 0. Стратегические цели

### 0.1. Зачем это всё

| # | Цель | Приоритет | Метрика успеха |
|---|------|-----------|----------------|
| 1 | **Контрибьюторская доступность** | 🔴 Критический | PHP-разработчик среднего уровня делает первый PR за 2 часа, не зная DDD |
| 2 | **Поддерживаемость** | 🔴 Критический | Типичное изменение (добавить поток, изменить лимиты) < 1 час без риска сайд-эффектов |
| 3 | **Изоляция отказов** | 🔴 Критический | Баг в admin-панели не ломает стриминг. Баг в модуле не ломает ядро |
| 4 | **Multi-node** | 🟡 Важный | LB-сервер собирается из подмножества koda. Streaming-путь не загружает admin-логику |
| 5 | **Open-core коммерция** | 🟡 Важный | Коммерческие модули подключаются без модификации ядра. Удаление модуля не ломает систему |
| 6 | **Тестируемость** | 🟢 Поддерживающий | Любой сервис можно тестировать, подставив mock зависимости |

### 0.2. Чем это НЕ является

- **Не переписывание с нуля.** Итеративный рефакторинг. Каждый шаг обратимо совместим.
- **Не DDD.** Нет Event Sourcing, CQRS, Aggregate Root, Domain Events. Простой паттерн: Controller → Service → Repository.
- **Не академический проект.** Архитектура оптимизирована под понятность и предсказуемость, а не под теоретическую чистоту.

### 0.3. Принцип принятия решений

Каждое архитектурное решение проходит три фильтра:

1. **Контрибьютор поймёт за 5 минут?** → если нет — упростить
2. **Не ломает streaming hot path?** → если ломает — отклонить
3. **Можно изолировать в модуль?** → если нет — обосновать

Если решение улучшает «красоту кода», но повышает барьер входа — **отклонить**.

---

## 1. Диагноз текущего состояния

### Масштаб: 382 PHP-файла, ~199 000 строк

### Критические проблемы

| # | Проблема | Где проявляется | Влияние |
|---|----------|-----------------|---------|
| 1 | **God-объекты** | `CoreUtilities` (4847 стр.), `admin_api.php` (6981 стр.), `admin.php` (4448 стр.) | Невозможно изменить одну подсистему без риска сломать другую |
| 2 | **Дублирование bootstrap** | `www/constants.php` vs `www/stream/init.php` — одни и те же ~70 `define()`, функции ошибок, flood-check | Каждое изменение нужно делать в двух местах |
| 3 | **Fork-дублирование** | `CoreUtilities` vs `StreamingUtilities` — идентичные свойства, `init()`, `cleanGlobals()` | Баги исправляются в одном классе и остаются в другом |
| 4 | **Глобальные переменные как шина данных** | `global $db`, `$rSettings`, `$rServers`, `$rUserInfo` в каждом файле | Невозможно тестировать, невозможно изолировать |
| 5 | **SQL в presentation-слое** | Каждая admin-страница делает `$db->query()` прямо в HTML | Бизнес-логика неразделима от отображения |
| 6 | **Один include = побочные эффекты** | `require admin.php` запускает сессию, создаёт БД, init 3 классов, определяет 50 констант | Нет возможности подключить часть системы без всей |
| 7 | **Admin = Reseller copy-paste** | `reseller/` — упрощённый клон `admin/` с тем же header/footer/session | Правки в одном не попадают в другой |
| 8 | **Goto-лейблы** | `includes/cli/monitor.php` содержит `goto label235`, `label592` | Следы обфускации, нечитаемый control flow |
| 9 | **Inline data** | Массивы стран/MAC-типов/разрешений по 150+ строк прямо в `admin.php` | Данные переплетены с логикой инициализации |
| 10 | **God-cron** | `crons/servers.php` — мониторинг, перезапуск демонов, статистика, очистка | Разные по частоте и природе задачи в одном файле |

### Граф зависимостей (текущий)

```
admin/*.php ──────┐
reseller/*.php ───┤
crons/*.php ──────┤──→ includes/admin.php ──→ CoreUtilities (4847)
includes/api/ ────┘         │                     ├── Database
                            │                     ├── Redis
                            ├──→ admin_api.php (6981)
                            ├──→ reseller_api.php (1204)
                            └──→ constants.php (~70 define())

www/stream/*.php ──→ www/stream/init.php ──→ StreamingUtilities (1992)
                          │                     ├── Database (тот же)
                          │                     └── Redis (тот же)
                          └── constants (ДУБЛИКАТ)
```

**Центральная точка отказа: `includes/admin.php`** — каждый контекст исполнения (admin, reseller, crons, API) проходит через этот один файл 4448 строк.

---

## 2. Архитектурный стиль и принципы

### 2.0. Формализация архитектурного стиля

**Это: структурированный монолит с предсказуемой организацией по контексту.**

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

Вот и всё. Три файла на контекст. Контрибьютор видит `/Stream/` — и знает где менять.

#### Правила зависимостей (просто)

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

### 2.1. Инверсия зависимостей

Код верхнего уровня (UI, CLI, HTTP endpoints) зависит от абстракций ядра, а не от конкретных реализаций. Ядро не знает о существовании модулей.

### 2.2. Единая точка bootstrap — множественные контексты

Вместо дублированных `init.php` / `constants.php` / `stream/init.php` — один bootstrap с конфигурируемым набором загружаемых сервисов.

### 2.3. Strict Constructor Injection — запрет Service Locator

**Правило:** `ServiceContainer` используется **ТОЛЬКО** на этапе bootstrap (composition root).
После bootstrap все зависимости передаются **через конструктор**. Ни один сервис не вызывает `$container->get()` внутри своих методов.

```php
// ✅ ПРАВИЛЬНО — constructor injection:
class StreamService {
    public function __construct(
        private StreamRepository $repository,
        private EventDispatcher $events,
        private FileLogger $logger
    ) {}
    
    public function create(array $data): int {
        // Использует $this->repository, $this->events, $this->logger
        // НЕ вызывает ServiceContainer
    }
}

// ✅ ПРАВИЛЬНО — composition root (bootstrap.php):
$container->register('stream.service', function($c) {
    return new StreamService(
        $c->get('stream.repository'),
        $c->get('event.dispatcher'),
        $c->get('logger.file')
    );
});

// ❌ ЗАПРЕЩЕНО — Service Locator внутри сервиса:
class StreamService {
    public function create(array $data): int {
        $db = ServiceContainer::getInstance()->get('db');  // ← АНТИПАТТЕРН
        $logger = ServiceContainer::getInstance()->get('logger');  // ← АНТИПАТТЕРН
    }
}
```

**Исключения (временные, фаза миграции):**
- Legacy-код в `includes/` может обращаться к контейнеру напрямую через proxy-методы
- Каждое такое обращение помечается `// @legacy-container — убрать в Фазе 8`
- В Фазе 8 все legacy-обращения к контейнеру заменяются на constructor injection

### 2.4. Консолидация мелких классов — запрет «один класс = один файл» вслепую

**Правило:** Не создавать отдельный файл/класс, если в нём < 150 строк **и** < 5 публичных методов. Маленькие классы живут в одном файле с родственным классом.

**Зачем:** Избежать взрыва файлов с классами по 1-2 функции. 44 файла по 40 строк — хуже одного бога на 4000, но не намного лучше для навигации.

**Конкретные правила:**

| Ситуация | Что делать | Пример |
|----------|-----------|--------|
| Repository < 5 методов | Объединить Service + Repository в один файл | `EpgService.php` содержит и бизнес-логику, и SQL |
| Контекст = 1 Service с 1-2 методами | Влить в ближайший родственный контекст | `TicketService::submit()` → `UserService::submitTicket()` |
| Отдельный «Validator» с 1 методом | Сделать private-методом в Service | `HMACValidator::validate()` → `HMACService::validate()` |
| Отдельный «Sync» с 1 методом | Влить в Service того же контекста | `DeviceSync::sync()` → `MagService::syncLineDevices()` |
| Service + Repository > 300 строк суммарно | Разделить в отдельные файлы | `StreamService` (700 стр.) + `StreamRepository` (400 стр.) = раздельно |

**Когда МОЖНО создавать отдельный файл:**
- Класс > 150 строк
- Класс имеет ≥ 5 публичных методов
- Класс используется из 3+ разных контекстов (например `ConnectionTracker`)
- Интерфейс для DI (`CacheInterface`, `LoggerInterface`)

```php
// ✅ ПРАВИЛЬНО — маленькие Service+Repository в одном файле:
// domain/Epg/EpgService.php
class EpgRepository {
    public function __construct(private Database $db) {}
    public function findById(int $id): ?array { ... }
    public function getStreamEpg(int $streamId): array { ... }
}

class EpgService {
    public function __construct(private EpgRepository $repo) {}
    public function process(array $data): int { ... }
    public function getChannelEpg(int $channelId): array { ... }
}

// ❌ НЕПРАВИЛЬНО — два файла по 40 строк:
// domain/Epg/EpgRepository.php  (40 строк, 3 метода)
// domain/Epg/EpgService.php     (50 строк, 2 метода)
```

### 2.5. Границы через интерфейсы, а не через `global`

Каждый сервис получает зависимости через конструктор. Ни один компонент не использует `global`.

### 2.6. Модуль = изолированная директория

Модуль — это директория с известным контрактом. Его можно удалить, и система продолжит работать (деградируя в функциональности, но не падая).

### 2.7. Open-core без лицензионных ограничений в ядре

Ядро (`core/`) полностью свободно. Модули (`modules/`) могут быть как open-source, так и коммерческими. Ядро не содержит проверок лицензий, шифрования, или скрытых ограничений. Лицензирование — это задача отдельного опционального модуля расширений.

---

## 3. Целевая структура `/src`

```
src/
├── bootstrap.php                    # Единый bootstrap: require_once подключения, DI container, config
├── constants.php                    # Все path/version/status константы (один файл)
│
├── core/                            # ═══ ЯДРО (стабильный, свободный, минимальный) ═══
│   ├── Config/
│   │   ├── ConfigLoader.php         # .ini → массив, кэширование, env-override
│   │   └── PathResolver.php         # Все пути системы (заменяет 70 define())
│   │
│   ├── Database/
│   │   ├── Database.php              # Базовая PDO-обёртка (перемещён из includes/)
│   │   ├── DatabaseHandler.php      # Менеджер БД — расширенная обёртка с reconnect, bulk ops
│   │   ├── QueryBuilder.php         # Конструктор запросов вместо raw SQL в UI
│   │   └── Migration.php            # Миграции (извлечено из `status`)
│   │
│   ├── Cache/
│   │   ├── CacheInterface.php       # Контракт для любого кэш-драйвера
│   │   ├── FileCache.php            # Текущий igbinary file cache
│   │   └── RedisCache.php           # Redis-обёртка
│   │
│   ├── Auth/
│   │   ├── SessionManager.php       # Единый менеджер сессий (admin + reseller)
│   │   ├── Authenticator.php        # Логин/пароль/api_key
│   │   └── Authorization.php        # Проверка прав ($rPermissions → RBAC)
│   │
│   ├── Http/
│   │   ├── Request.php              # Обёртка над $_GET/$_POST (заменяет cleanGlobals)
│   │   ├── Response.php             # JSON/HTML ответ
│   │   ├── Router.php               # Маршрутизация вместо switch($rAction)
│   │   └── Middleware/
│   │       ├── FloodProtection.php  # Rate limiting (извлечено из constants.php)
│   │       ├── IpWhitelist.php      # IP-фильтрация
│   │       └── CorsMiddleware.php
│   │
│   ├── Process/
│   │   ├── ProcessManager.php       # Управление процессами (kill, ps, isRunning)
│   │   ├── DaemonRunner.php         # Запуск PHP-демонов с PID-файлами
│   │   └── CronLock.php             # Файловые блокировки кронов (checkCron)
│   │
│   ├── Logging/
│   │   ├── LoggerInterface.php       # Контракт
│   │   ├── FileLogger.php           # Файловое логирование
│   │   └── DatabaseLogger.php       # panel_logs / login_logs / activity
│   │
│   ├── Events/
│   │   ├── EventDispatcher.php      # Простой event bus
│   │   └── EventInterface.php       # Контракт события
│   │
│   ├── Container/
│   │   └── ServiceContainer.php     # Минимальный DI-контейнер
│   │
│   └── Util/
│       ├── GeoIP.php                # Извлечено из CoreUtilities
│       ├── NetworkUtils.php         # IP-операции, CIDR, subnet matching
│       ├── TimeUtils.php            # secondsToTime(), timezone helper
│       ├── Encryption.php           # AES, token generation
│       └── ImageUtils.php           # Resize, thumbnail, upload
│
├── domain/                          # ═══ БИЗНЕС-ЛОГИКА (сервисы и репозитории) ═══
│   ├── Stream/
│   │   ├── StreamService.php        # Бизнес-логика + оркестрация
│   │   ├── StreamRepository.php    # SQL-запросы (из admin_api.php)
│   │   ├── ChannelService.php       # Каналы: create, massEdit, order
│   │   ├── CategoryService.php      # Категории + CategoryRepository (< 150 стр. = один файл)
│   │   ├── StreamProcess.php       # FFmpeg, kill, restart
│   │   ├── StreamMonitor.php        # Мониторинг потока (из cli/monitor.php)
│   │   ├── ConnectionTracker.php    # Redis: подключения, heartbeat
│   │   ├── StreamSorter.php         # Сортировки и форматирование
│   │   ├── PlaylistGenerator.php    # Генерация M3U/EPG плейлистов
│   │   ├── CronGenerator.php        # Генерация кронов
│   │   └── M3UParser.php            # Парсинг M3U
│   │
│   ├── Vod/
│   │   ├── MovieService.php         # + MovieRepository (< 300 стр. суммарно = один файл)
│   │   ├── SeriesService.php        # + SeriesRepository (< 300 стр. суммарно = один файл)
│   │   └── EpisodeService.php       # process, massEdit, massDelete
│   │
│   ├── Line/
│   │   ├── LineService.php          # + LineRepository (раздельно — > 300 стр. суммарно)
│   │   ├── LineRepository.php
│   │   └── PackageService.php       # + PackageRepository (один файл, < 150 стр.)
│   │
│   ├── Device/
│   │   ├── MagService.php           # + DeviceSync::syncLineDevices (влито)
│   │   └── EnigmaService.php
│   │
│   ├── User/
│   │   ├── UserService.php          # + ProfileService::editAdminProfile (влито)
│   │   │                            # + TicketService::submit (влито)
│   │   ├── UserRepository.php       # getUserInfo, getUser, getRegisteredUsers...
│   │   └── GroupService.php         # + GroupRepository (один файл, < 150 стр.)
│   │
│   ├── Server/
│   │   ├── ServerRepository.php
│   │   ├── ServerService.php        # Мониторинг, health-check
│   │   └── SettingsService.php      # edit, editBackup, editCacheCron (влито из domain/Settings/)
│   │
│   ├── Bouquet/
│   │   └── BouquetService.php       # + BouquetRepository + BouquetMapper (один файл, < 300 стр.)
│   │
│   ├── Epg/
│   │   └── EpgService.php           # + EpgRepository (один файл, < 300 стр.)
│   │
│   ├── Auth/
│   │   ├── AuthService.php          # CodeService + HMACService + HMACValidator (объединены)
│   │   └── AuthRepository.php       # CodeRepository + HMACRepository (объединены)
│   │
│   └── Security/
│       └── BlocklistService.php     # + BlocklistRepository (один файл, < 300 стр.)
│
├── streaming/                       # ═══ СТРИМИНГ-ДВИЖОК (hot path) ═══
│   ├── StreamingBootstrap.php       # Лёгкий init для стриминг-контекста
│   ├── Auth/
│   │   ├── TokenAuth.php            # HMAC/token парсинг (из auth.php)
│   │   ├── StreamAuth.php           # Проверка доступа к потоку
│   │   └── DeviceLock.php           # Привязка к устройству
│   │
│   ├── Delivery/
│   │   ├── LiveDelivery.php         # Раздача live (из live.php)
│   │   ├── VodDelivery.php          # Раздача VOD (из stream/vod.php)
│   │   ├── TimeshiftDelivery.php    # Timeshift
│   │   └── SegmentReader.php        # Чтение TS-сегментов
│   │
│   ├── Balancer/
│   │   └── LoadBalancer.php         # Распределение + RedirectStrategy (один файл, < 150 стр.)
│   │
│   ├── Protection/
│   │   ├── RestreamDetector.php     # Антипиратская защита
│   │   ├── ConnectionLimiter.php    # Лимит подключений
│   │   └── GeoBlock.php            # Блокировка по стране/ISP/IP/UA
│   │
│   ├── Codec/
│   │   ├── FFmpegCommand.php        # Построение FFmpeg-команд (из CoreUtilities)
│   │   ├── TranscodeProfile.php     # Профили транскодирования
│   │   └── TsParser.php            # Парсер MPEG-TS (текущий ts.php)
│   │
│   └── Health/
│       ├── DivergenceDetector.php   # Мониторинг качества
│       └── BitrateTracker.php       # Отслеживание bitrate/FPS
│
├── public/                          # ═══ ТОЧКИ ВХОДА — HTTP (UI, API) ═══
│   ├── index.php                    # Единая точка входа (front controller)
│   │
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── BaseAdminController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── StreamController.php
│   │   │   ├── LineController.php
│   │   │   ├── VodController.php
│   │   │   ├── ServerController.php
│   │   │   ├── SettingsController.php
│   │   │   ├── UserController.php
│   │   │   ├── BouquetController.php
│   │   │   ├── EpgController.php
│   │   │   └── ... (111 контроллеров, по одному на страницу)
│   │   │
│   │   ├── Reseller/
│   │   │   ├── BaseResellerController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── LineController.php
│   │   │   └── ... (22 контроллера)
│   │   │
│   │   └── Api/                         # (planned)
│   │       ├── AdminApiController.php
│   │       ├── ResellerApiController.php
│   │       ├── PlayerApiController.php   # Публичный player API
│   │       └── InternalApiController.php  # Межсерверный API (текущий www/api.php)
│   │
│   ├── Views/
│   │   ├── layouts/
│   │   │   ├── admin.php            # Header + footer шаблон для admin
│   │   │   └── footer.php           # Единый footer
│   │   │
│   │   ├── admin/
│   │   │   ├── dashboard.php
│   │   │   ├── streams/
│   │   │   │   ├── list.php
│   │   │   │   ├── edit.php
│   │   │   │   └── view.php
│   │   │   ├── lines/
│   │   │   ├── vod/
│   │   │   ├── servers/
│   │   │   └── settings/
│   │   │
│   │   ├── reseller/
│   │   │   ├── dashboard.php
│   │   │   └── ...
│   │   │
│   │   └── partials/
│   │       ├── modals.php
│   │       ├── topbar.php
│   │       └── table.php
│   │
│   └── routes/
│       ├── admin.php
│       ├── reseller.php
│       └── api.php
│
├── player/                          # Встроенный web-плеер
│   └── ... (PHP + JS + CSS)
│
├── cli/                             # ═══ (PLANNED) CLI ТОЧКИ ВХОДА ═══
│   ├── Commands/
│   │   ├── StartupCommand.php       # cli/startup.php
│   │   ├── WatchdogCommand.php      # cli/watchdog.php
│   │   ├── MonitorCommand.php       # cli/monitor.php
│   │   ├── CacheHandlerCommand.php  # cli/cache_handler.php
│   │   ├── QueueCommand.php         # cli/queue.php
│   │   ├── SignalsCommand.php       # cli/signals.php
│   │   ├── ScannerCommand.php       # cli/scanner.php
│   │   ├── MigrateCommand.php       # Из status
│   │   └── ToolsCommand.php         # Из tools (rescue, access, ports и т.д.)
│   └── CronJobs/
│       ├── StreamsCron.php
│       ├── ServersCron.php
│       ├── CacheCron.php
│       ├── EpgCron.php
│       ├── CleanupCron.php
│       ├── BackupsCron.php
│       ├── StatsCron.php
│       ├── LogsCron.php            # lines_logs + streams_logs
│       ├── VodCron.php
│       └── TmdbCron.php
│
├── modules/                         # ═══ ОПЦИОНАЛЬНЫЕ МОДУЛИ ═══
│   ├── ministra/                    # Ministra/Stalker middleware (текущий ministra/)
│   │   ├── module.json              # Manifest: name, version, dependencies
│   │   ├── MinistraModule.php       # Точка входа модуля (implements ModuleInterface)
│   │   ├── PortalHandler.php        # Диспетчер type+action (15 обработчиков)
│   │   ├── PortalHelpers.php        # Хелперы портала (19 статических методов)
│   │   └── assets/
│   │
│   ├── plex/                        # Plex integration (текущий settings_plex, plex_add)
│   │   ├── module.json
│   │   ├── PlexModule.php
│   │   ├── PlexService.php
│   │   └── config/
│   │
│   ├── tmdb/                        # TMDB Integration
│   │   ├── module.json
│   │   ├── TmdbModule.php
│   │   ├── TmdbService.php
│   │   ├── TmdbCron.php
│   │   ├── TmdbPopularCron.php
│   │   └── lib.php                  # proxy → includes/libs/tmdb.php
│   │
│   ├── watch/                       # Watch/Recording (текущие watch, record файлы)
│   │   ├── module.json
│   │   ├── WatchModule.php
│   │   ├── WatchService.php
│   │   ├── RecordingService.php
│   │   ├── WatchController.php
│   │   ├── WatchCron.php
│   │   ├── WatchItem.php
│   │   └── views/
│   │       ├── watch.php
│   │       ├── watch_scripts.php
│   │       ├── watch_add.php
│   │       ├── watch_add_scripts.php
│   │       ├── settings_watch.php
│   │       ├── settings_watch_scripts.php
│   │       ├── watch_output.php
│   │       ├── watch_output_scripts.php
│   │       ├── record.php
│   │       └── record_scripts.php
│   │
│   ├── fingerprint/                 # Fingerprint watermarking
│   │   ├── module.json
│   │   └── FingerprintModule.php
│   │
│   ├── theft-detection/             # Anti-theft/restream detection
│   │   ├── module.json
│   │   └── TheftDetectionModule.php
│   │
│   └── magscan/                     # MAG device scanning
│       ├── module.json
│       └── MagscanModule.php
│
├── infrastructure/                  # ═══ СИСТЕМНАЯ ИНФРАСТРУКТУРА ═══
│   ├── nginx/
│   │   ├── NginxConfigGenerator.php # Генерация nginx.conf (из CoreUtilities)
│   │   ├── templates/
│   │   │   ├── main.conf.tpl
│   │   │   ├── rtmp.conf.tpl
│   │   │   └── vhost.conf.tpl
│   │   └── NginxReloader.php
│   │
│   ├── redis/
│   │   └── RedisManager.php         # Подключение, pipeline, pub/sub
│   │
│   ├── service/
│   │   ├── ServiceManager.sh        # Текущий файл `service` (bash)
│   │   └── daemons.sh               # Список демонов
│   │
│   ├── install/
│   │   ├── database.sql             # Начальная схема
│   │   └── proxy.tar.gz
│   │
│   └── bin/                         # Внешние бинарники (FFmpeg, certbot, yt-dlp и т.д.)
│       ├── ffmpeg_bin/
│       ├── certbot/
│       ├── maxmind/
│       ├── guess
│       ├── yt-dlp
│       └── network.py
│
├── data/                            # ═══ ДАННЫЕ ВРЕМЕНИ ВЫПОЛНЕНИЯ ═══
│   ├── cache/                       # Файловый кэш (igbinary)
│   ├── logs/                        # Логи приложения
│   ├── tmp/                         # Временные файлы (текущий tmp/)
│   ├── content/                     # Медиа-контент (текущий content/)
│   │   ├── archive/
│   │   ├── epg/
│   │   ├── playlists/
│   │   ├── streams/
│   │   ├── video/
│   │   └── vod/
│   ├── backups/                     # Резервные копии
│   └── signals/                     # Сигнальные файлы (.gitkeep)
│
├── config/                          # ═══ КОНФИГУРАЦИЯ ═══
│   ├── config.ini                   # Основной конфиг (DB, Redis, пути)
│   ├── modules.php                  # Список включённых модулей
│   ├── routes.php                   # Таблица маршрутов
│   ├── plex/
│   └── rclone.conf
│
└── resources/                       # ═══ РЕСУРСЫ ═══
    ├── langs/                       # Переводы (текущие .ini файлы)
    │   ├── en.ini
    │   ├── ru.ini
    │   ├── de.ini
    │   ├── es.ini
    │   ├── fr.ini
    │   ├── bg.ini
    │   └── pt.ini
    ├── data/
    │   ├── countries.php            # Массивы стран (извлечены из admin.php)
    │   ├── timezones.php
    │   ├── mac_types.php
    │   └── error_codes.php          # $rErrorCodes (извлечены из constants.php)
    └── libs/                        # Сторонние PHP-библиотеки
        ├── MobileDetect.php
        ├── XmlStringStreamer.php
        ├── m3u/
        └── Dropbox.php
```

---

## 4. Описание компонентов

### 4.1. `core/` — Ядро системы

**Ответственность:** Инфраструктурные сервисы, которые нужны любому контексту исполнения. Не содержит бизнес-логики.

| Подкаталог | Что даёт | Что заменяет |
|------------|----------|--------------|
| `Config/` | Загрузка конфигурации, резолв путей | 70 `define()` из `constants.php` и `stream/init.php` |
| `Database/` | PDO-обёртка (Database + DatabaseHandler), query builder, миграции | `Database.php` + SQL-запросы из `status` |
| `Cache/` | Унифицированный кэш с двумя драйверами | Разбросанные `igbinary_serialize` + ad-hoc Redis |
| `Auth/` | Единая авторизация для admin и reseller | `session.php` × 2, `API::processLogin()`, `ResellerAPI::processLogin()` |
| `Http/` | Абстракция запросов, роутинг, middleware | `cleanGlobals()` × 2, `switch($rAction)`, flood-check в constants.php |
| `Process/` | Управление PID, демонами, блокировками | `shell_exec('ps aux')`, `posix_kill()`, `checkCron()` разбросанные по файлам |
| `Logging/` | Унифицированное логирование | `CoreUtilities::saveLog()`, `StreamingUtilities::clientLog()`, `Logger::init()` |
| `Events/` | Event bus для связи без жёстких зависимостей | Прямые вызовы между компонентами |
| `Container/` | DI-контейнер (composition root only — см. §2.3) | `global $db`, статические `CoreUtilities::$rSettings` |
| `Util/` | Утилиты без состояния | Функции разбросанные по CoreUtilities, admin.php |

**Ключевое правило:** `core/` не знает о существовании `domain/`, `streaming/`, `modules/`, `public/`. Зависимости направлены только внутрь ядра.

### 4.2. `domain/` — Бизнес-логика

**Ответственность:** Сервисы и репозитории, организованные по контекстам. Каждый контекст (Stream, Vod, Line, User...) — отдельная директория.

**Паттерн для каждого контекста:**

```
domain/Stream/
  ├── StreamService.php       # Бизнес-логика + оркестрация (валидация, транзакции, side-effects)
  ├── StreamRepository.php    # SQL-запросы (SELECT/INSERT/UPDATE/DELETE)
  └── StreamProcess.php       # Специфичные системные операции (ffmpeg, kill, PID)
```

**Правила:**

1. **Service** = вся бизнес-логика контекста. Валидация, транзакции, вызов Infrastructure (nginx, ffmpeg), логирование. Один Service на контекст, при росте можно разбить.
2. **Repository** = только SQL. Принимает массивы, возвращает массивы. Никакой логики. Никаких side-effects.
3. **Нет Entity-классов.** Данные передаются как `array`. Это PHP — массивы проще и понятнее, чем аномичные DTO-объекты.
4. **Консолидация мелких классов (§2.4).** Если Repository < 5 методов и < 150 строк — он живёт в одном файле с Service. Мелкие контексты (Ticket, Settings) вливаются в родственный контекст, а не создают отдельную директорию.

```php
// ✅ ПРАВИЛЬНО — Service делает всё:
class StreamService {
    public function __construct(
        private StreamRepository $repository,
        private ProcessManager $processManager,
        private FileLogger $logger,
        private Database $db
    ) {}
    
    public function create(array $data): int {
        // Валидация
        if (empty($data['stream_source'])) {
            throw new \InvalidArgumentException('Source required');
        }
        
        // Транзакция
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

// ✅ ПРАВИЛЬНО — Repository = только SQL:
class StreamRepository {
    public function __construct(private Database $db) {}
    
    public function findById(int $id): ?array {
        return $this->db->row("SELECT * FROM streams WHERE id = ?", [$id]);
    }
    
    public function insert(array $data): int {
        $this->db->query("INSERT INTO streams ...", $data);
        return $this->db->lastInsertId();
    }
}
```

**Откуда берётся код:**
- `admin_api.php` (6981 строк) → разбивается на ~10 Service + ~6 Repository (§2.4 — мелкие Repository влиты в Service)
- `admin.php` → процедурные `getUserInfo()`, `getSeriesList()` → в Repository
- Оркестрация (validate + save + nginx + cache + log) → в Service

**Зависимости:** `domain/` зависит от `core/` (Database, Cache, Events). Не зависит от `public/` или `modules/`.

### 4.3. `streaming/` — Стриминг-движок

**Ответственность:** Весь hot path доставки видео. Выделен отдельно от `domain/` потому что:
- Критичен к производительности (нельзя загружать всю бизнес-логику)
- Имеет собственный лёгкий bootstrap
- Работает на уровне байтов/сегментов, а не на уровне CRUD

#### Изоляция streaming

Streaming — **отдельный контекст исполнения** с минимальными зависимостями:

```
streaming/ зависит от:
  ✅ core/ (Database, Redis, Logging, GeoIP, Encryption, NetworkUtils)
  ✅ domain/ (Repository — только SELECT-запросы: потоки, серверы, букеты)
  
  ❌ НЕ зависит от:
     - public/ (контроллеры)
     - modules/ (всё)
     - domain/*Service.php (бизнес-мутации)
```

**Главное правило:** streaming вызывает **только read-методы** Repository. Запись данных (PID, stats, connections) — через собственные классы (`ConnectionTracker`, `BitrateTracker`), а не через Domain Services.

**Откуда берётся код:**
- `StreamingUtilities.php` (1992 стр.) → распределяется по подкаталогам
- `www/stream/auth.php` (800 стр.) → `Auth/TokenAuth.php` + `Auth/StreamAuth.php` + `Protection/`
- `www/stream/live.php` (708 стр.) → `Delivery/LiveDelivery.php`
- `includes/cli/monitor.php` (565 стр.) → `domain/Stream/StreamMonitor.php` (с рефакторингом goto)
- `CoreUtilities` методы FFmpeg → `Codec/FFmpegCommand.php`
- `ts.php` → `Codec/TsParser.php`

### 4.4. `public/` — Точки входа

**Ответственность:** Получение запроса, вызов Service, формирование ответа. Никакой бизнес-логики.

**HTTP:**
- Один front controller `public/index.php` + `Router`
- Контроллеры вызывают **Service** из `domain/` для мутаций и **Repository** для чтения
- Шаблоны (`Views/`) — чистый HTML с минимумом PHP-вставок
- Admin и Reseller — разные контроллеры, общие шаблоны

```php
// ✅ ПРАВИЛЬНО — контроллер вызывает Service:
class StreamController {
    public function __construct(
        private StreamService $streamService,
        private StreamRepository $streamRepo
    ) {}
    
    public function create(Request $request): void {
        $id = $this->streamService->create($request->all());
        Response::json(['id' => $id]);
    }
    
    public function list(): void {
        $streams = $this->streamRepo->getAll();
        include VIEW_PATH . '/admin/streams/list.php';
    }
}

// ❌ ЗАПРЕЩЕНО — контроллер содержит бизнес-логику:
class StreamController {
    public function create(Request $request): void {
        // Валидация, транзакции, nginx — это задача Service
        $this->db->beginTransaction();
        $this->streamRepo->insert(...);  // ← fat controller
        $this->nginx->reload();
        $this->db->commit();
    }
}
```

**CLI:**
- Каждый демон/крон — отдельный Command-класс
- Общая инициализация через `bootstrap.php` + `ServiceContainer`
- Файл `service` (bash) → `infrastructure/service/ServiceManager.sh`

**Откуда берётся код:**
- Каждый `admin/*.php` (100+ файлов) → Controller + View. Пример: `admin/streams.php` → `Controllers/Admin/StreamController.php` + `Views/admin/streams/list.php`
- `admin/header.php` (675 стр.) + `admin/footer.php` (805 стр.) → `Views/layouts/admin.php` + выделение JS в файлы `assets/`
- `admin/post.php` (1946 стр.) → обработчики форм распределяются по контроллерам
- `crons/*.php` → `Cli/CronJobs/`, каждый крон — один файл
- `includes/cli/*.php` → `Cli/Commands/`

### 4.5. `modules/` — Опциональные модули

**Ответственность:** Дополнительные функции, которые не являются частью ядра. Каждый модуль — самодостаточная директория.

**Контракт модуля:**

```php
interface ModuleInterface {
    public function getName(): string;
    public function getVersion(): string;
    public function boot(ServiceContainer $container): void;   // Регистрация сервисов
    public function registerRoutes(Router $router): void;      // Свои маршруты
    public function registerCrons(): array;                     // Свои кроны
    public function getEventSubscribers(): array;              // Подписки на события
}
```

```json
// module.json
{
    "name": "ministra",
    "version": "1.0.0",
    "description": "Ministra/Stalker Portal middleware",
    "requires_core": ">=2.0",
    "dependencies": []
}
```

**Текущий код → модули:**

| Модуль | Источник | Почему модуль, а не ядро |
|--------|----------|-------------------------|
| `ministra/` | `src/ministra/` (2155+ строк, десятки JS-файлов) | Сторонний портал, не всем нужен |
| `plex/` | `settings_plex.php`, `plex_add.php`, `crons/plex.php`, `config/plex/` | Интеграция с внешним сервисом |
| `tmdb/` | `includes/libs/TMDb/`, `crons/tmdb.php`, `crons/tmdb_popular.php` | Внешний API для метаданных |
| `watch/` | `watch.php`, `watch_add.php`, `watch_output.php`, `settings_watch.php`, `crons/watch.php` | Запись/DVR — расширенная функция |
| `fingerprint/` | `admin/fingerprint.php` | Водяные знаки — enterprise-функция |
| `theft-detection/` | `admin/theft_detection.php` | Антипиратство — enterprise-функция |
| `magscan/` | `admin/magscan_settings.php` | Сканирование устройств |

### 4.6. `infrastructure/` — Системный слой

**Ответственность:** Взаимодействие с ОС: nginx, redis, bash-скрипты, внешние бинарники.

**Откуда берётся код:**
- `CoreUtilities` → генерация nginx-конфигурации → `nginx/NginxConfigGenerator.php`
- `bin/nginx/`, `bin/php/`, `bin/redis/` → `bin/` (бинарники как есть)
- `bin/daemons.sh` → `service/daemons.sh`
- `service` → `service/ServiceManager.sh`
- `bin/install/database.sql` → `install/database.sql`

### 4.7. `data/` — Runtime-данные

**Ответственность:** Всё, что генерируется при эксплуатации и не является кодом.

Заменяет текущие: `tmp/`, `content/`, `backups/`, `signals/`.

### 4.8. `config/` — Конфигурация

**Ответственность:** Всё, что администратор может изменить без правки кода.

### 4.9. `resources/` — Статические ресурсы и данные

**Ответственность:** Переводы, данные-справочники (страны, таймзоны), сторонние PHP-библиотеки.

**Откуда берётся код:**
- `includes/langs/*.ini` → `langs/`
- Inline-массивы стран из `admin.php` → `data/countries.php`
- `$rErrorCodes` из `constants.php` → `data/error_codes.php`
- `includes/libs/*` → `libs/`

---

## 5. Карта миграции: откуда → куда

### 5.1. God-объект `CoreUtilities.php` (4847 строк)

| Метод/блок | Целевой файл |
|------------|-------------|
| `init()`, config loading | `core/Config/ConfigLoader.php` |
| `$db`, `getDatabase()` | `core/Database/DatabaseHandler.php` |
| `getCache()`, `setCache()` | `core/Cache/FileCache.php` |
| Redis operations | `core/Cache/RedisCache.php`, `infrastructure/redis/RedisManager.php` |
| `cleanGlobals()`, `parseIncomingRecursively()` | `core/Http/Request.php` |
| `startMonitor()`, `stopStream()`, `startMovie()` | `domain/Stream/StreamService.php` |
| `isStreamRunning()`, `isMonitorRunning()` | `core/Process/ProcessManager.php` |
| FFmpeg command building | `streaming/Codec/FFmpegCommand.php` |
| GeoIP lookup | `core/Util/GeoIP.php` |
| Nginx config generation | `infrastructure/nginx/NginxConfigGenerator.php` |
| Image resize/upload | `core/Util/ImageUtils.php` |
| Encryption (AES/token) | `core/Util/Encryption.php` |
| `saveLog()` | `core/Logging/FileLogger.php` |

### 5.2. God-объект `admin_api.php` (6981 строк)

| Метод/блок | Целевой файл |
|------------|-------------|
| `processStream()` | `domain/Stream/StreamService.php` |
| `processMovie()` | `domain/Vod/MovieService.php` |
| `processSerie()` | `domain/Vod/SeriesService.php` |
| `processEpisode()` | `domain/Vod/EpisodeService.php` |
| `processLine()` | `domain/Line/LineService.php` |
| `processMAG()` | `domain/Device/MagService.php` |
| `processEnigma()` | `domain/Device/EnigmaService.php` |
| `processServer()` | `domain/Server/ServerService.php` |
| `processBouquet()` | `domain/Bouquet/BouquetService.php` (+ Repository + Mapper — один файл) |
| `processUser()` | `domain/User/UserService.php` (+ submit ticket, editProfile) |
| `processGroup()` | `domain/User/GroupService.php` (+ GroupRepository — один файл) |
| `processCode()` | `domain/Auth/AuthService.php` (+ Code + HMAC — объединены) |
| `processLogin()` | `core/Auth/Authenticator.php` |
| `processSettings()` | `domain/Server/SettingsService.php` (влито из domain/Settings/) |
| `massEditStreams()` | `domain/Stream/StreamService::massEdit()` |
| `checkMinimumRequirements()` | Валидация в каждом Service |

### 5.3. God-bootstrap `admin.php` (4448 строк)

| Блок | Целевой файл |
|------|-------------|
| `session_start()`, session config | `core/Auth/SessionManager.php` |
| 50+ `define()` статусов | `constants.php` |
| `Database` creation | `core/Container/ServiceContainer.php` |
| `CoreUtilities::init()` | `bootstrap.php` |
| `$rCountryCodes`, `$rCountries` | `resources/data/admin_constants.php` |
| `$rPermissions` | `resources/data/admin_constants.php` → `core/Auth/Authorization.php` |
| `getUserInfo()` | `domain/User/UserRepository.php` |
| `getSeriesList()`, `updateSeries()` | `domain/Vod/SeriesService.php` (Repository влит — §2.4) |
| `secondsToTime()` | `core/Util/TimeUtils.php` |
| `hasPermissions()` | `core/Auth/Authorization.php` |
| Mobile detect init | `core/Http/Middleware/` (если нужно) |
| Translator init | `bootstrap.php` → `ServiceContainer` |

### 5.4. Страницы admin/ (100+ файлов)

**Паттерн трансформации:**

```
БЫЛО:
  admin/streams.php (один файл = SQL + HTML + JS)

СТАЛО:
  public/Controllers/Admin/StreamController.php            — маршрутизация
  domain/Stream/StreamRepository.php                       — данные
  public/Views/admin/streams/list.php                      — шаблон
```

### 5.5. Дублирование admin/ ↔ reseller/

```
БЫЛО:
  admin/header.php (675 строк)    +  reseller/header.php (284 строки)
  admin/footer.php (805 строк)    +  reseller/footer.php
  admin/session.php               +  reseller/session.php
  admin/functions.php             +  reseller/functions.php

СТАЛО:
  public/Views/layouts/admin.php               — единый layout
  public/Views/layouts/footer.php              — единый footer
  core/Auth/SessionManager.php                 — единый менеджер сессий
  core/Auth/Authorization.php                  — RBAC определяет, что видит пользователь
```

### 5.6. Стриминг-путь

```
БЫЛО:
  www/stream/init.php (дублированный bootstrap)
  www/stream/auth.php (800 строк: auth + block + token + balance)
  www/stream/live.php (708 строк: token + ondemand + delivery + heartbeat)
  StreamingUtilities.php (1992 строки: форк CoreUtilities)

СТАЛО:
  streaming/StreamingBootstrap.php   — лёгкий init без дублирования
  streaming/Auth/TokenAuth.php       — парсинг токенов
  streaming/Auth/StreamAuth.php      — проверка доступа
  streaming/Protection/GeoBlock.php  — блокировки (IP/ISP/UA/Country)
  streaming/Delivery/LiveDelivery.php — раздача контента
  streaming/Health/BitrateTracker.php — мониторинг качества
  core/Cache/*                       — общий кэш (не дублируется)
  core/Database/DatabaseHandler.php   — общая БД (не дублируется)
```

---

## 6. Система модулей

### 6.1. Жизненный цикл модуля

```
1. Модуль размещается в modules/{name}/
2. Добавляется в config/modules.php
3. При bootstrap: ServiceContainer сканирует modules.php
4. Для каждого модуля: require module.json → проверка зависимостей → boot()
5. Модуль регистрирует: сервисы, маршруты, кроны, event-подписки
6. Удаление: убрать из modules.php → (опционально) удалить папку
```

### 6.2. Правила изоляции

```
✅ Модуль МОЖЕТ:
   - Использовать сервисы из core/ через constructor injection
   - Вызывать Service из domain/ для бизнес-операций
   - Использовать Repository из domain/ для чтения данных
   - Регистрировать свои маршруты, команды, кроны
   - Подписываться на события-хуки ядра
   - Иметь свои assets, views, конфиги

❌ Модуль НЕ МОЖЕТ:
   - Модифицировать файлы core/ или domain/
   - Напрямую обращаться к базе данных мимо Repository
   - Зависеть от другого модуля без явной декларации в module.json
   - Переопределять маршруты или сервисы ядра
   - Добавлять ограничения лицензирования в ядро
```

### 6.3. Расширяемость через события-хуки

События используются **только для модульных хуков**, а не внутри обычного CRUD.

**Когда использовать события:**
- Модуль хочет отреагировать на действие ядра (например, запись DVR при запуске потока)
- Ядро не должно знать о модуле

**Когда НЕ использовать:**
- Внутри CRUD-операций (создал поток → обновил nginx) — это прямой вызов в Service
- Между ядерными компонентами (это магия, которую сложно отладить)

```php
// ✅ ПРАВИЛЬНО — события для модульных хуков:
// Ядро публикует:
$dispatcher->dispatch(new StreamStartedEvent($streamId));

// Модуль watch подписывается:
class WatchModule implements ModuleInterface {
    public function getEventSubscribers(): array {
        return [
            StreamStartedEvent::class => [WatchRecorder::class, 'onStreamStarted'],
        ];
    }
}

// ❌ НЕПРАВИЛЬНО — события внутри CRUD:
class StreamService {
    public function create(array $data): int {
        $id = $this->repo->insert($data);
        // НЕ нужно dispatch StreamCreatedEvent для обновления nginx.
        // Просто вызвать $this->nginx->reload() напрямую.
        $this->nginx->reload();
        return $id;
    }
}
```

### 6.4. Коммерческие модули (будущее)

Коммерческие модули — обычные модули в `modules/`, но:
- Доставляются отдельно (не в основном репозитории)
- Могут содержать собственную проверку лицензии **внутри себя**
- Ядро **не содержит** кода проверки лицензии — если модуль удалён, система работает

```
modules/
├── ministra/          ← open-source, в основном репо
├── plex/              ← open-source, в основном репо
├── enterprise-analytics/  ← коммерческий, отдельный репо
│   ├── module.json
│   ├── License.php        ← проверка лицензии ВНУТРИ модуля
│   └── AnalyticsModule.php
└── advanced-security/     ← коммерческий, отдельный репо
```

---

## 7. Границы ядра и модулей

### 7.1. Пакетная диаграмма зависимостей

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
             │ depends on │ depends on │ depends on
             ▼            ▼            ▼
        ┌──────────────────────────────────┐
        │             core/                │   ← Config, DB, Cache, Auth, Process,
        │                                  │     Logging, Events, Container, Util
        └──────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────────┐
        │        infrastructure/           │   ← nginx, redis, bin, service
        └──────────────────────────────────┘
```

**Стрелки всегда направлены вниз.** Ни один нижний слой не знает о верхних.

**Исключение:** `streaming/` — горизонтальный слой на уровне `domain/`, но с read-only доступом к domain/Repository. У него свой лёгкий entry path (см. §4.3).

### 7.2. Что входит в ядро, а что нет

| В ядро (`core/` + `domain/`) | В модули (`modules/`) |
|-------------------------------|----------------------|
| Управление потоками (CRUD, запуск, остановка) | Ministra portal |
| Управление подписками (линии, пакеты) | Plex integration |
| Управление VOD (фильмы, сериалы) | TMDb metadata fetching |
| Авторизация и RBAC | Fingerprint watermarking |
| Серверная инфраструктура | Theft detection |
| EPG (базовый импорт) | MAG device scanning |
| Bouquet management | Watch/DVR recording |
| Стриминг-движок | Advanced analytics (будущее) |
| API (admin, reseller, player) | White-label theming (будущее) |

---

### 5.1 includes/ — Устаревшие файлы

```
includes/
├── admin.php               # ~150 глобальных функций -> proxy к domain/*/Service.php
├── admin_api.php            # ~80 методов класса AdminAPI -> proxy к domain/*/Service.php
├── reseller_api.php         # ~9 методов класса ResellerAPI
├── CoreUtilities.php        # ~135 статических методов -> proxy к core/ и domain/
├── StreamingUtilities.php   # ~66 статических методов -> proxy к streaming/
├── ts.php                   # Timeshift утилиты
├── bootstrap/               # Старые файлы инициализации admin
│   ├── admin_bootstrap.php
│   ├── admin_runtime.php
│   └── admin_session.php
├── api/                     # API-обработчики
│   ├── admin/
│   └── reseller/
├── cli/                     # CLI-скрипты (monitor, balancer, watchdog и т.д.)
├── data/                    # Статические данные (permissions)
├── langs/                   # Файлы локализации (en.ini, ru.ini, de.ini, ...)
├── libs/                    # Сторонние библиотеки
│   ├── Logger.php
│   ├── AsyncFileOperations.php
│   ├── Dropbox.php
│   ├── TMDb/
│   └── ...
└── python/                  # Python-скрипты
```

**Статус**: Все 150 функций `admin.php` и 135 методов `CoreUtilities` уже являются proxy-обёртками, делегирующими в `domain/` и `core/`. После завершения миграции файлы будут удалены.

### 5.2 admin/ — Легаси admin-страницы

~120 PHP-файлов — старые admin-страницы, которые содержали SQL+HTML в одном файле. **Заменены** на `public/Controllers/Admin/` + `public/Views/admin/`.

### 5.3 reseller/ — Легаси reseller-страницы

~35 PHP-файлов — аналогично admin/, но для реселлерской панели. **Заменены** на `public/Controllers/Reseller/` + `public/Views/reseller/`.

### 5.4 ministra/ — Stalker Portal JS

JavaScript-файлы для поддержки Stalker Portal (MAG-устройства). Не PHP — отдаются nginx напрямую. Сохраняется как есть.

### 5.5 player/ — Встроенный web-плеер

Мини-приложение (PHP + JS + CSS) для веб-отображения потоков. Имеет собственные `header.php`, `footer.php`, `functions.php`. Сохраняется как есть до отдельного рефакторинга.

---

## 8. Варианты сборки: MAIN vs LoadBalancer

### 8.1. Два артефакта из одной кодовой базы

Система собирается в два варианта из одного `src/`:

| Артефакт | Назначение | Что включает | Что исключает |
|----------|-----------|--------------|---------------|
| **MAIN** | Основной сервер (admin + streaming) | Всё содержимое `src/` | Ничего |
| **LoadBalancer (LB)** | Стриминг-сервер без управления | Только стриминг + инфраструктура | Админ-панель, реселлер, player, ministra, admin-only модули |

**Ключевой принцип:** LB — это подмножество MAIN. Код не форкается, а фильтруется при сборке.

### 8.2. Текущая проблема (до миграции Makefile)

`Makefile` собирает LB из фиксированного списка директорий (`LB_FILES`):

```makefile
# ТЕКУЩИЙ (устаревший) список:
LB_FILES := bin config content crons includes signals tmp www status update service
```

**Эти новые директории НЕ входят в LB_FILES и НЕ копируются в LB:**

| Директория / файл | Нужен LB? | Статус |
|----|---|---|
| `autoload.php` | ✅ ДА — без него новые классы не загружаются | ⚠ Отсутствует в LB |
| `bootstrap.php` | ✅ ДА — единая точка инициализации (CONTEXT_STREAM) | ⚠ Отсутствует в LB |
| `core/` | ✅ ДА — Database, Cache, Auth, Config, Process, Http, Logging, Container, Util | ⚠ Отсутствует в LB |
| `domain/` | ⚠ ЧАСТИЧНО — Stream/, Server/, Bouquet/ нужны; User/, Ticket/, Device/ — нет | ⚠ Отсутствует в LB |
| `streaming/` | ✅ ДА — весь стриминг-движок | ⚠ Отсутствует в LB |
| `infrastructure/` | ✅ ДА — redis/, nginx/ | ⚠ Отсутствует в LB |
| `resources/` | ⚠ ЧАСТИЧНО — data/error_codes.php нужен; langs/ — нет | ⚠ Отсутствует в LB |
| `data/` | ⚠ ЧАСТИЧНО — runtime-данные создаются динамически | ⚠ Отсутствует в LB |
| `public/` | ❌ НЕТ — admin UI, не нужен на LB | ⚠ Отсутствует в LB |
| `modules/` | ❌ НЕТ — все текущие модули admin-only | ⚠ Отсутствует в LB |
| `admin/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `ministra/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `player/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `reseller/` | ❌ НЕТ | ✅ Корректно отсутствует |

**Последствие:** Proxy-методы в `CoreUtilities.php` (который IS копируется в LB через `includes/`) вызывают классы из `core/` и `domain/`, но эти директории отсутствуют → **LB-сборка ломается при вызове мигрированного кода**.

### 8.3. Целевая конфигурация Makefile

#### Обновлённый `LB_FILES`

```makefile
# ЦЕЛЕВОЙ (обновлённый) список:
LB_FILES := autoload.php bootstrap.php \
    bin config content core crons data domain includes infrastructure \
    public resources signals streaming tmp www status update service
```

#### Обновлённый `LB_DIRS_TO_REMOVE`

```makefile
LB_DIRS_TO_REMOVE := \
    # Существующие исключения:
    bin/install \
    bin/redis \
    includes/langs \
    includes/api \
    includes/libs/resources \
    bin/nginx/conf/codes \
    # Новые исключения для целевой архитектуры:
    admin \
    ministra \
    player \
    reseller \
    domain/User \
    domain/Ticket \
    domain/Device \
    public/Controllers/Admin \
    public/Controllers/Reseller \
    public/Views \
    player \
    modules/fingerprint \
    modules/magscan \
    modules/ministra \
    modules/plex \
    modules/theft-detection \
    modules/tmdb \
    modules/watch \
    resources/langs
```

#### Обновлённый `LB_FILES_TO_REMOVE`

```makefile
LB_FILES_TO_REMOVE := \
    # Существующие (пока admin.php/admin_api.php живут в includes/):
    includes/admin_api.php \
    includes/admin.php \
    includes/reseller_api.php \
    # ... остальные существующие исключения ...
    # Новые:
    includes/cli/migrate.php \
    includes/cli/cache_handler.php \
    includes/cli/balancer.php \
    crons/backups.php \
    crons/cache_engine.php \
    crons/epg.php \
    crons/update.php \
    crons/providers.php \
    crons/root_mysql.php \
    crons/series.php \
    crons/tmdb.php \
    crons/tmdb_popular.php
```

### 8.4. Что LB использует — карта зависимостей

```
www/stream/*.php ──→ bootstrap.php (CONTEXT_STREAM)
                         │
                         ├──→ autoload.php (class map)
                         ├──→ core/Config/         (пути, конфигурация)
                         ├──→ core/Database/       (PDO-обёртка)
                         ├──→ core/Cache/          (Redis + File кэш)
                         ├──→ core/Auth/           (BruteforceGuard)
                         ├──→ core/Http/           (Request, RequestGuard)
                         ├──→ core/Process/        (ProcessManager)
                         ├──→ core/Logging/        (FileLogger, DatabaseLogger)
                         ├──→ core/Util/           (GeoIP, NetworkUtils, Encryption)
                         ├──→ core/Error/          (ErrorHandler, ErrorCodes)
                         │
                         ├──→ streaming/Auth/      (TokenAuth, StreamAuth, DeviceLock)
                         ├──→ streaming/Delivery/  (LiveDelivery, VodDelivery, SegmentReader)
                         ├──→ streaming/Balancer/  (LoadBalancer, RedirectStrategy)
                         ├──→ streaming/Protection/(RestreamDetector, ConnectionLimiter, GeoBlock)
                         ├──→ streaming/Codec/     (FFmpegCommand, TsParser)
                         │
                         ├──→ domain/Stream/       (StreamProcess, ConnectionTracker, StreamSorter)
                         ├──→ domain/Server/       (ServerRepository)
                         ├──→ domain/Bouquet/      (BouquetMapper — для плейлистов)
                         ├──→ domain/Vod/          (для VOD-доставки)
                         │
                         ├──→ infrastructure/redis/(RedisManager)
                         └──→ resources/data/      (error_codes.php)

crons/*.php (на LB) ──→ bootstrap.php (CONTEXT_CLI)
                         └──→ те же зависимости + domain/Stream/*
```

### 8.5. Правила для разработки с учётом LB

1. **Proxy-методы в `CoreUtilities`/`StreamingUtilities`** должны быть безопасны для LB. Пока `autoload.php` и `core/`/`domain/` не добавлены в LB-сборку, proxy-вызовы сломают LB.

2. **Makefile должен обновляться синхронно с миграцией**, конкретно при добавлении новых `LB_FILES` каждый раз, когда код из `includes/` мигрирует в новые директории.

3. **Тестирование LB-сборки** — после каждой фазы миграции нужно проверять:
   ```bash
   make new && make lb
   # Убедиться, что все нужные файлы присутствуют
   # Убедиться, что admin-only файлы отсутствуют
   ```

4. **domain/ частично нужен LB** — нельзя целиком исключать `domain/`. Исключаются только admin-specific поддомены (`User/`, `Ticket/`, `Device/`).

5. **modules/ полностью исключается из LB** — все текущие модули (ministra, plex, tmdb, watch, fingerprint, theft-detection, magscan) — это admin-функциональность. В будущем, если появится LB-specific модуль, его нужно будет явно добавить.

### 8.6. Переходный период (сейчас)

Пока миграция идёт итеративно (фазы 1-3 в процессе), Makefile нужно обновить **немедленно**:

**Минимально необходимое изменение (`LB_FILES`):**
```makefile
LB_FILES := bin config content core crons domain includes \
    infrastructure resources signals streaming tmp www status update service
```

**Плюс два root-файла** — `autoload.php` и `bootstrap.php` — нужно копировать отдельно, т.к. `LB_FILES` работает только с директориями:
```makefile
lb_copy_files:
    # ... существующий код ...
    @echo "==> [LB] Copying root files"
    @for root_file in autoload.php bootstrap.php; do \
        if [ -f "$(MAIN_DIR)/$$root_file" ]; then \
            cp "$(MAIN_DIR)/$$root_file" "$(TEMP_DIR)/$$root_file"; \
        fi; \
    done
```

---

## 9. Порядок миграции

### Принцип: извлечение → делегирование → замена

Каждый шаг миграции следует одному паттерну:

```
1. Создать новый класс в целевой директории
2. Перенести в него методы из god-объекта
3. В старом файле оставить proxy-метод:
     public static function oldMethod(...$args) {
         return NewClass::method(...$args);
     }
4. Зарегистрировать класс в autoloader
5. Проверить: система работает как раньше
6. (позже) Обновить вызывающий код → удалить proxy
```

Так каждый шаг безопасен и обратимо совместим.

---

### Фаза 0: Подготовка ✅

1. ✅ Автозагрузчик `src/autoload.php` — карта классов + fallback spl_autoload
2. ✅ Скелет директорий (98 каталогов)
3. ✅ `bootstrap.php` — XC_Bootstrap с 4 контекстами
4. ✅ `core/Container/ServiceContainer.php` — DI-контейнер
5. ✅ Разбиение `www/constants.php` → 7 core-файлов + тонкий фасад (74 строки)
   - ✅ `core/Config/Paths.php`, `AppConfig.php`, `Binaries.php`, `ConfigLoader.php`
   - ✅ `core/Error/ErrorCodes.php`, `ErrorHandler.php`
   - ✅ `core/Http/RequestGuard.php`
   - ✅ `www/stream/init.php` → подключён autoload.php

---

### Фаза 1: Извлечение core/ (базовые компоненты)

#### 1.1 ✅ Database
- ✅ `core/Database/DatabaseHandler.php` (530 стр.) ← `includes/Database.php`
- ✅ `core/Database/Database.php` (299 стр.) — перемещён из `includes/`
- ✅ Все 20 файлов переключены на DatabaseHandler

#### 1.2 ✅ Cache
- ✅ `core/Cache/CacheInterface.php` (84 стр.)
- ✅ `core/Cache/FileCache.php` (229 стр.) ← `CoreUtilities::getCache/setCache`
- ✅ `core/Cache/RedisCache.php` (262 стр.)

#### 1.3 ✅ Http / Request
- ✅ `core/Http/Request.php` (449 стр.) ← `cleanGlobals()` + `parseIncomingRecursively()`
- ✅ `core/Http/Response.php` (139 стр.)

#### 1.4 ✅ Auth
- ✅ `core/Auth/SessionManager.php` (305 стр.) ← из двух `session.php`

#### 1.5 ✅ Process
- ✅ `core/Process/ProcessManager.php` (366 стр.) ← shell_exec/posix_kill

#### 1.6 ✅ Util
- ✅ `core/Util/GeoIP.php` (159 стр.) ← `CoreUtilities`
- ✅ `core/Util/Encryption.php` (137 стр.) ← `CoreUtilities`
- ✅ `core/Util/TimeUtils.php` (114 стр.) ← `CoreUtilities` + `admin.php::secondsToTime()`
- ✅ `core/Util/NetworkUtils.php` (129 стр.) ← IP/CIDR/subnet из `CoreUtilities`

---

### Фаза 1.7: Оставшиеся извлечения core/

**Из `CoreUtilities.php` (4847 строк) → новые классы:**

#### Шаг 1.7.1 — Логирование ✅
- ✅ Извлечь `saveLog()` (стр. 489–504) → `FileLogger::log()`
- ✅ Извлечь `clientLog()` (StUtil стр. 1169–1177) → `DatabaseLogger::log()`
- ✅ Написать `LoggerInterface` с методом `log($type, $message, $extra)`
- ✅ Proxy: `CoreUtilities::saveLog()` → `FileLogger::log()`
- ✅ Proxy: `StreamingUtilities::clientLog()` → `DatabaseLogger::log()`

#### Шаг 1.7.2 — Системная информация ✅
- ✅ Извлечь 9 методов (стр. 534–671, 827–852, 1338–1344, 4621–4665) → `SystemInfo`
- ✅ Proxy: каждый вызов `CoreUtilities::getStats()` → `SystemInfo::getStats()`

#### Шаг 1.7.3 — Защита от брутфорса (Flood/Bruteforce) ✅
- ✅ Извлечь 4 метода из CoreUtilities (стр. 709–826) → `BruteforceGuard`
- ✅ 4 дублированных метода в StreamingUtilities (стр. 215–394) → proxy на тот же `BruteforceGuard`
- ✅ Первая дедупликация CoreUtilities ↔ StreamingUtilities

#### Шаг 1.7.4 — HTTP-клиент (cURL) ✅
- ✅ Извлечь 3 метода (стр. 373–428, 1408–1442, 3568–3578) → `CurlClient`

#### Шаг 1.7.5 — Событийная система ✅
- ✅ Написать простой EventDispatcher (publish/subscribe)
- ✅ Пока без подписчиков — подготовка к модульной системе

#### Шаг 1.7.6 — RBAC / Авторизация ✅
- ✅ Извлечь `hasPermissions()` (стр. 582–619) и `hasResellerPermissions()` (стр. 575–581)
- ✅ Массив `$rAdvPermissions` → `resources/data/admin_constants.php`
- ✅ Proxy: глобальные функции в `admin.php` → `Authorization::check()`

#### Шаг 1.7.7 — Аутентификация ✅
- ✅ Извлечь `processLogin()` из `admin_api.php` (стр. 1399–1478) → `Authenticator::login()`
- ✅ Извлечь `processLogin()` из `reseller_api.php` → `Authenticator::resellerLogin()`
- ✅ Proxy: `API::processLogin()` → `Authenticator::login()`

#### Шаг 1.7.8 — Утилиты изображений ✅
- ✅ Извлечь методы работы с изображениями (resize, thumbnail, URL-валидация)
- ✅ `StreamingUtilities::validateImage()` (стр. 1355) → `ImageUtils::validateURL()`
- ✅ `admin.php::getAdminImage()` (стр. 871–883) → `ImageUtils::resize()`

---

### Фаза 2: Дедупликация CoreUtilities ↔ StreamingUtilities ✅

Форк устранён. 53 дублированных метода дедуплицированы.

#### Шаг 2.1 — Инвентаризация дубликатов ✅

Инвентаризация выполнена: **53 общих метода** между CoreUtilities и StreamingUtilities.
Все методы распределены по шагам 2.2–2.5 и успешно дедуплицированы.

#### Шаг 2.2 — Redis и сигналы ✅
- ✅ Извлечь Redis-подключение в `RedisManager` (единый для обоих)
- ✅ Proxy: оба класса вызывают `RedisManager::connect()`
- ✅ `getDomainName()` → `DomainResolver::resolve()` (чистая утилита конфигурации)

#### Шаг 2.3 — Трекинг подключений (Redis) ✅
- ✅ ~17 методов вынесены в `ConnectionTracker` (работа с Redis-ключами подключений)
- ✅ Proxy: оба god-объекта делегируют в `ConnectionTracker`

#### Шаг 2.4 — Справочные данные и сортировки ✅
- ✅ Сортировки и форматирование вынесены в `StreamSorter`
- ✅ Данные-справочники вынесены в `BouquetMapper` / `CategoryRepository` / `BouquetRepository` / `ServerRepository` / `SettingsRepository`

#### Шаг 2.5 — Дедупликация init() ✅
- ✅ Оба `init()` стали тонкими обёртками
- ✅ Общая логика вынесена в `core/Init/LegacyInitializer.php`
- ✅ Состояние init синхронизируется через `ServiceContainer`

---

### Фаза 3: Извлечение domain/ — бизнес-логика ✅

Все entity/repository/service извлечены из god-объектов. Proxy-методы оставлены в исходных файлах.

#### Шаг 3.1 — domain/Stream/ ✅
- `StreamService.php` — processStream, massEdit, massDelete, move, replaceDNS ← admin_api.php
- `ChannelService.php` — processChannel, massEdit, setOrder ← admin_api.php
- `CategoryService.php` — processCategory, orderCategories ← admin_api.php
- `StreamRepository.php` — getStream, getStreamStats, getStreamErrors, getStreamPIDs, getStreamOptions, getStreamSys, getNextOrder ← admin.php
- `CategoryRepository.php` — getCategories ← admin.php
- `M3UParser.php` — parseM3U ← admin.php
- `StreamProcess.php` — startMonitor, stopStream, startProxy, startThumbnail, queueChannel, createChannel, updateStream(s), createChannelItem, startMovie, stopMovie, startLoopback, queueMovie(s), refreshMovies, startStream, startLLOD ← CoreUtilities

#### Шаг 3.2 — domain/Vod/ ✅
- `MovieService.php` — process, massEdit, massDelete, import ← admin_api.php
- `SeriesService.php` — process, massEdit, massDelete, import ← admin_api.php
- `EpisodeService.php` — process, massEdit, massDelete ← admin_api.php
- `SeriesRepository.php` — getList, updateFromTMDB, queueRefresh, getSimilar, generatePlaylist ← admin.php
- `MovieRepository.php` — getSimilar, deleteFile ← admin.php

#### Шаг 3.3 — domain/Line/ ✅
- `LineService.php` — process, massEdit, massDelete, delete, update (одиночные и массовые) ← admin_api.php + CoreUtilities
- `LineRepository.php` — deleteMany ← admin.php

#### Шаг 3.4 — domain/User/ ✅
- `UserService.php` — process, massEdit, massDelete ← admin_api.php
- `GroupService.php` — process ← admin_api.php
- `ProfileService.php` — editAdminProfile ← admin_api.php
- `UserRepository.php` — getUserInfo, getUser, getRegisteredUser(s), getResellers, getDirectReports, getSubUsers, getParent, getStreamingUserInfo ← admin.php + StreamingUtilities
- `GroupRepository.php` — getAll, getById, deleteById ← admin.php

#### Шаг 3.5 — domain/Device/ ✅
- `MagService.php` — process, massEdit, massDelete ← admin_api.php
- `EnigmaService.php` — process, massEdit, massDelete ← admin_api.php
- `DeviceSync.php` — syncLineDevices ← admin.php

#### Шаг 3.6 — domain/Server/ ✅
- `ServerService.php` — process, processProxy, install, reorder ← admin_api.php
- `ServerRepository.php` — getAllSimple, getStreamingSimple, getProxySimple, getFreeSpace, getStreamsRamdisk, killPID, getRTMPStats, deleteById, probeSource, getSSLLog, checkSource, freeTemp, freeStreams ← admin.php

#### Шаг 3.7 — domain/Bouquet/ ✅
- `BouquetService.php` — process, reorder, sort, scan, scanOne ← admin_api.php + admin.php
- `BouquetRepository.php` — getAllSimple, getOrder, getUserBouquets ← admin.php

#### Шаг 3.8 — domain/Epg/ ✅
- `EpgService.php` — process, getChannelEpg ← admin_api.php + admin.php
- `EpgRepository.php` — getById, findByName, getStreamEpg, getStreamsEpg, getProgramme, search ← admin.php + CoreUtilities

#### Шаг 3.9 — domain/Settings/ + domain/Ticket/ ✅
- `SettingsService.php` — edit, editBackup, editCacheCron ← admin_api.php
- `TicketService.php` — submit ← admin_api.php

#### Шаг 3.10 — domain/Security/ ✅
- `BlocklistService.php` — processISP, processUA, blockIP, processRTMPIP, checkBlockedUAs, checkISP, checkServer ← admin_api.php + StreamingUtilities
- `BlocklistRepository.php` — getBlockedIPsSimple, getRTMPIPsSimple, getBlockedUA, getBlockedIPs, getBlockedISP, getBlockedServers, getProxyIPs ← admin.php + CoreUtilities

#### Шаг 3.11 — domain/Auth/ ✅
- `CodeService.php` — process ← admin_api.php
- `HMACService.php` — process ← admin_api.php
- `PackageService.php` — process ← admin_api.php (в domain/Line/)
- `CodeRepository.php` — getActiveCodes, updateCodes, getCurrentCode ← admin.php
- `HMACRepository.php` — getAll, getById ← admin.php
- `PackageRepository.php` — deleteById ← admin.php (в domain/Line/)
- `HMACValidator.php` — validate ← StreamingUtilities

#### Шаг 3.12 — Playlist-генератор ✅
- `PlaylistGenerator.php` — generate ← CoreUtilities (самый длинный метод, live/vod/series/radio)
- `CronGenerator.php` — generate ← CoreUtilities

---

### Фаза 4: Извлечение streaming/ (hot path) ✅

#### Шаг 4.1 — streaming/Auth/ ✅
- `StreamAuth.php` — checkAccess, validateConnections ← auth.php + StreamingUtilities
- `TokenAuth.php` — парсинг токенов
- `DeviceLock.php` — привязка к устройству
- `ConnectionLimiter.php` — closeConnections, closeRTMP → Protection/

#### Шаг 4.2 — streaming/Delivery/ ✅
- `LiveDelivery.php` ← www/stream/live.php
- `StreamRedirector.php` — redirectStream, showVideoServer
- `OffAirHandler.php` — getOffAirVideo
- `HLSGenerator.php` — generateHLS
- `SegmentReader.php` — getLLODSegments
- `SignalSender.php` — sendSignal
- `ProxySelector.php` → Balancer/

#### Шаг 4.3 — streaming/Codec/ ✅
- `FFprobeRunner.php` — probeStream, parseFFProbe
- `FFmpegCommand.php` — сборка FFmpeg-команд
- `SubtitleExtractor.php` — extractSubtitle

#### Шаг 4.4 — streaming/Protection/ + Health/ ✅
- `ConnectionLimiter.php` — closeConnections
- `HealthChecker.php` — isRunning
- `ProcessChecker.php` — isPIDRunning, isPIDsRunning, checkPID
- `WatchdogMonitor.php` — getWatchdog

#### Шаг 4.5 — streaming/StreamingBootstrap.php ✅
- Лёгкий bootstrap заменяет `www/stream/init.php`
- Загружает: autoload → constants → Database → Redis

---

### Фаза 5: Вынесение модулей

Каждый модуль извлекается атомарно — система продолжает работать без него.

#### Шаг 5.1 — modules/plex/ ⚡ (в процессе)

**Завершено:**
- ✅ `PlexAuth.php` — аутентификация (getPlexToken, checkPlexToken, cachePlexToken и др.)
- ✅ `PlexRepository.php` — данные (getPlexServers, getPlexSections)
- ✅ `PlexService.php` — бизнес-логика (editPlexSettings, processPlexSync, forcePlex)
- ✅ `PlexCron.php` — крон синхронизации (Thread, Multithread, PlexCron::run())
- ✅ `PlexItem.php` — CLI обработка элементов (PlexItem::run())
- ✅ `crons/plex.php` — тонкая обёртка → PlexCron::run()
- ✅ `includes/cli/plex_item.php` — тонкая обёртка → PlexItem::run()
- ✅ Все 5 классов зарегистрированы в autoload.php
- ✅ `admin/settings_plex.php` → modules/plex/views/ (контроллер + вынесенный view/scripts)
- ✅ `admin/plex_add.php` → modules/plex/views/ (контроллер + общие view/scripts)
- ✅ `admin/plex.php` → modules/plex/views/ (контроллер + список вынесен в view)
- ✅ `config/plex/` → modules/plex/config/

**Осталось:** —

#### Шаг 5.2 — modules/watch/
- ✅ `API::editWatchSettings()` → modules/watch/WatchService.php
- ✅ `API::processWatchFolder()` → modules/watch/WatchService.php
- ✅ `API::scheduleRecording()` → modules/watch/RecordingService.php
- ✅ `admin.php::getWatchFolders()` → modules/watch/WatchController.php
- ✅ `admin.php::getWatchCategories()` → modules/watch/WatchController.php
- ✅ `admin.php::forceWatch()` → modules/watch/WatchController.php
- ✅ `admin.php::getRecordings()` → modules/watch/WatchController.php
- ✅ `admin.php::deleteRecording()` → modules/watch/WatchController.php
- ✅ `admin/settings_watch.php` → modules/watch/views/ (thin wrapper + view/scripts)
- ✅ `admin/watch.php` → modules/watch/views/ (thin wrapper + view/scripts)
- ✅ `admin/watch_add.php` → modules/watch/views/ (thin wrapper + view/scripts)
- ✅ `admin/watch_output.php` → modules/watch/views/ (thin wrapper + view/scripts)
- ✅ `admin/record.php` → modules/watch/views/ (thin wrapper + view/scripts)
- ✅ `crons/watch.php` → modules/watch/WatchCron.php (entry point + extracted class)
- ✅ `includes/cli/watch_item.php` → modules/watch/WatchItem.php (entry point + extracted class)
- ✅ `WatchModule::registerCrons()` wired to WatchCron

**Осталось:** —

#### Шаг 5.3 — modules/tmdb/
- ✅ `admin/api.php::tmdb_search` → modules/tmdb/TmdbService.php (search)
- ✅ `admin/api.php::tmdb` → modules/tmdb/TmdbService.php (getDetails)
- ✅ `crons/tmdb.php` → modules/tmdb/TmdbCron.php (entry point + extracted class)
- ✅ `crons/tmdb_popular.php` → modules/tmdb/TmdbPopularCron.php (entry point + extracted class)
- ✅ `TmdbModule.php` — registerCrons() wired (TmdbCron + TmdbPopularCron)
- ✅ `includes/libs/TMDb/` → modules/tmdb/lib.php (proxy loader, lib stays in place)

**Осталось:** —

#### Шаг 5.4 — modules/ministra/
- ✅ `ministra/portal.php` (2156 строк) → PortalHandler.php (15 статических обработчиков, ~1345 строк)
- ✅ `ministra/portal.php` helper functions → PortalHelpers.php (19 статических методов, ~860 строк)
- ✅ `MinistraModule.php` — точка входа модуля (standalone endpoint, без cron/routes)
- ✅ `ministra/portal.php` → тонкая обёртка: init/auth → PortalHandler (269 строк + хелперы + комментированный оригинал)
- 🔲 `ministra/*.js` → modules/ministra/assets/ (JS-файлы портала — отложено)

**Осталось:** JS-ассеты (отложено до Фазы 6)

#### Шаг 5.5 — modules/fingerprint/ + modules/theft-detection/ + modules/magscan/
- ✅ `FingerprintModule.php` — модуль Fingerprint Stream (выбор потока + наложение текста + таблица активности)
- ✅ `TheftDetectionModule.php` — модуль обнаружения кражи VOD (таблица просмотров, данные из cache_engine)
- ✅ `MagscanModule.php` — модуль настроек MAGSCAN (белые/чёрные списки MAC + IP)
- ✅ `module.json` × 3 — метаданные модулей
- ✅ `config/modules.php` — все 3 модуля раскомментированы и включены

**Источники:**
```
admin/fingerprint.php (330 стр.) — UI-страница, API: fingerprint + line_activity
admin/theft_detection.php (239 стр.) — UI-страница, данные: CACHE_TMP_PATH/theft_detection
admin/magscan_settings.php (301 стр.) — UI-страница, POST: submit_magscan
```

#### Шаг 5.6 — ModuleInterface + загрузчик модулей
- ✅ `core/Module/ModuleInterface.php` — контракт модуля (111 строк): getName, getVersion, boot, registerRoutes, registerCrons, getEventSubscribers
- ✅ `core/Module/ModuleLoader.php` — загрузчик модулей (243 строки): loadAll, load, checkDependencies, isLoaded, getModule
- ✅ `config/modules.php` — конфигурация модулей: plex, watch, fingerprint, theft-detection, magscan (enabled), tmdb, ministra (commented)

---

#### Аудит Фазы 5 ✅
Проведена полная проверка всех файлов Фазы 5. Найдено и исправлено:
- **КРИТИЧЕСКОЕ**: `Thread`/`Multithread` дублировались в 3 файлах (PlexCron, WatchCron, cache_engine) → вынесены в `core/Process/Thread.php` + `core/Process/Multithread.php`, все 3 файла переведены на `require_once`
- **autoload.php**: добавлены 14 отсутствующих записей (WatchCron, WatchItem, Tmdb*, Ministra*, Fingerprint*, TheftDetection*, Magscan*, Thread, Multithread)
- **module.json**: созданы для `tmdb` и `ministra` (отсутствовали)
- **config/modules.php**: `tmdb` и `ministra` раскомментированы и включены (были `enabled => false`)

---

### Фаза 6: Контроллеры и Views (admin/reseller) ⚡ (в процессе)

Выполняется ПОСЛЕ извлечения domain/ — контроллеры вызывают сервисы.

#### Шаг 6.1 — Единый layout
```
admin/header.php (675 стр.) + reseller/header.php (284 стр.)
                            → public/Views/layouts/admin.php

admin/footer.php (804 стр.) + reseller/footer.php (719 стр.)
                            → public/Views/layouts/footer.php
                            + assets/admin/js/*.js (вынесенный inline JS)
```

**Сделано (старт шага 6.1):**
- ✅ Создан `public/Views/layouts/admin.php` — unified header wrapper (`renderUnifiedLayoutHeader`)
- ✅ Создан `public/Views/layouts/footer.php` — unified footer wrapper (`renderUnifiedLayoutFooter`)
- ✅ Удалён `public/Views/layouts/.gitkeep`
- ✅ Переведены на unified wrappers (пилот):
    - `admin/fingerprint.php`
    - `admin/magscan_settings.php`
    - `admin/theft_detection.php`
- ✅ Переведены на unified wrappers (группа F — настройки):
    - `admin/settings.php`
    - `admin/profile.php`
    - `admin/edit_profile.php`
- ✅ Переведены на unified wrappers (группа E — букеты):
    - `admin/bouquets.php`
    - `admin/bouquet.php`
    - `admin/bouquet_order.php`
    - `admin/bouquet_sort.php`
- ✅ Переведены на unified wrappers (группа D — серверы):
    - `admin/servers.php`
    - `admin/server.php`
    - `admin/server_view.php`
    - `admin/server_install.php`
- ✅ Переведены на unified wrappers (группа C — линии/устройства):
    - `admin/lines.php`
    - `admin/line.php`
    - `admin/mags.php`
    - `admin/mag.php`
    - `admin/enigmas.php`
    - `admin/enigma.php`
- ✅ Переведены на unified wrappers (группа B — VOD):
    - `admin/movies.php`
    - `admin/movie.php`
    - `admin/series.php`
    - `admin/serie.php`
    - `admin/episodes.php`
    - `admin/episode.php`
- ✅ Переведены на unified wrappers (группа A — потоки):
    - `admin/streams.php`
    - `admin/stream.php`
    - `admin/stream_view.php`
    - `admin/created_channel.php`
    - `admin/created_channels.php`
- ✅ Переведены на unified wrappers (группа G — остальное, пакет 1):
    - `admin/archive.php`
    - `admin/dashboard.php`
    - `admin/epg.php`
    - `admin/epgs.php`
    - `admin/group.php`
    - `admin/groups.php`
- ✅ Переведены на unified wrappers (группа G — остальное, пакет 2):
    - `admin/providers.php`
    - `admin/provider.php`
    - `admin/packages.php`
    - `admin/package.php`
    - `admin/profiles.php`
    - `admin/proxies.php`

**Дальше в 6.1:**
- 🔄 Перенос общих CSS/JS-блоков из `admin|reseller` в единые partials
- 🔄 Подключение `partials/header.php` + `partials/footer.php` к layout system (сейчас не используются)
- ✅ ~~Постраничное переключение include `header.php/footer.php` → unified layout wrappers~~ — завершено

**Reseller миграция на unified wrappers:**
- ✅ Переведены (ранее): `dashboard.php`, `streams.php`, `live_connections.php`, `user.php`, `users.php`, `user_logs.php`
- ✅ Переведены (batch 2): `movies.php`, `enigma.php` (footer fix), `epg_view.php`, `enigmas.php`, `tickets.php`, `ticket_view.php`, `ticket.php`, `radios.php`, `mags.php`, `mag.php`, `line_activity.php`, `lines.php`, `line.php`, `episodes.php`, `created_channels.php`, `edit_profile.php`
- ℹ️ Не требуют миграции (utility/support): `header.php`, `footer.php`, `functions.php`, `session.php`, `api.php`, `table.php`, `post.php`, `topbar.php`, `modals.php`, `resize.php`, `index.php`, `logout.php`
- ℹ️ Отдельная страница (свой HTML): `login.php`
- **Admin: 112/112 page-файлов — 100% мигрированы**
- **Reseller: 22/22 page-файлов — 100% мигрированы**

#### Шаг 6.2 — Router + Front Controller
```
Новый → core/Http/Router.php
Новый → public/index.php          (front controller)
Новый → public/routes/admin.php  (admin route definitions)
Новый → public/routes/api.php    (API route definitions)
```

**Статус 6.2:** ✅ Завершён
- ✅ `core/Http/Router.php` — полная реализация (450 стр.): GET/POST/API routes, group(), middleware, permissions, dispatch(), dispatchApi(), singleton
  - **Fix: normalizePage()** — `buildRoute()` теперь вызывает `normalizePage()` на результате, чтобы ключи регистрации совпадали с ключами dispatch (rtmp_ips → rtmp/ips)
  - **Fix: ServiceContainer DI** — `callHandler()` теперь использует `ServiceContainer::getInstance()->get($class)` с fallback на `new $class()` при ошибке
- ✅ `core/Http/Request.php` — HTTP request wrapper (450 стр.): capture(), input access, sanitization, backward compat
- ✅ `core/Http/Response.php` — HTTP response helper: json(), redirect(), notFound(), cors(), noCache()
- ✅ `core/Http/RequestGuard.php` — flood protection, host verification, Logger init
- ✅ `public/index.php` — Front Controller:
    - Трёхрежимный парсинг URL → scope + pageName + accessCode:
      - **Режим A** (Access Code + XC_SCOPE): nginx передаёт scope через `fastcgi_param XC_SCOPE`
      - **Режим B** (Direct URL): URL содержит `/admin/...` или `/reseller/...`
      - **Режим C** (Access Code без XC_SCOPE): fallback через `PHP_SELF` (как `CodeRepository::getCurrentCode`)
    - Маппинг `#TYPE#` → scope: admin, reseller, ministra, includes/api/admin → admin, и т.д.
    - Bootstrap через legacy chain (session.php → functions.php)
    - Загрузка маршрутов из routes/{scope}.php + routes/api.php
    - Dispatch через Router → fallback в legacy include
    - Поддержка API action dispatch (/admin/api?action=xxx)
- ✅ `public/routes/admin.php` — шаблон маршрутов admin (заглушки для Step 6.3)
- ✅ `public/routes/api.php` — шаблон API маршрутов (заглушки для Step 6.3)

**Access Codes (`bin/nginx/conf/codes/`):**

Панель доступна не по `/admin/`, а по `/RANDOMCODE/`. Коды генерируются из шаблона:
- Шаблон: `bin/nginx/conf/codes/template`
- Генератор: `domain/Auth/CodeRepository.php` → `updateCodes()`
- URL: `http://host/MYCODE/dashboard` → nginx alias → `/home/xc_vm/admin/dashboard.php`
- Типы кодов (field `type` в таблице):

| type | #TYPE# (alias path)      | scope (Router) |
|------|--------------------------|----------------|
| 0    | admin                    | admin          |
| 1    | reseller                 | reseller       |
| 2    | ministra                 | ministra       |
| 3    | includes/api/admin       | admin          |
| 4    | includes/api/reseller    | reseller       |
| 5    | ministra/new             | ministra       |
| 6    | player                   | player         |

**Активация — два варианта:**

1) **Прямой доступ** (dev/test, без access codes):
```nginx
location ~ ^/(admin|reseller)(/.*)?$ {
    try_files $uri /public/index.php?$args;
}
```

2) **Access codes** (production) — обновлённый шаблон `bin/nginx/conf/codes/template`:
```nginx
location ^~ /#CODE# {
    alias /home/xc_vm/#TYPE#;
    index index.php;
    # Статика и legacy PHP отдаются напрямую
    try_files $uri $uri.html $uri/ @fc_#CODE#;

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }
}

# Front Controller fallback для access code #CODE#
location @fc_#CODE# {
    fastcgi_param XC_SCOPE #TYPE#;
    fastcgi_param XC_CODE  #CODE#;
    fastcgi_param SCRIPT_FILENAME /home/xc_vm/public/index.php;
    fastcgi_pass php;
    include fastcgi_params;
}
```

Пока nginx шаблон не обновлён — legacy URL через access codes (`/MYCODE/dashboard` → `admin/dashboard.php`) работают как раньше. Front Controller активируется только после обновления template.

#### Шаг 6.3 — Конвертация admin-страниц (Controller/View)

**Паттерн: Thin Controller + View файл**

Каждая legacy admin-страница разделяется на:
- **Controller** (`public/Controllers/Admin/XxxController.php`) — подготовка данных, редиректы, проверка прав
- **View** (`public/Views/admin/xxx.php`) — только HTML-контент (между header и footer)
- **Scripts** (`public/Views/admin/xxx.scripts.php`) — page-specific JS (DataTables, api(), etc.)

**✅ `public/Controllers/Admin/BaseAdminController.php` — render(), redirect(), json(), setTitle(), requirePermission(), requireAdvPermission(), input(), getStatus()
- ✅ `public/Views/admin/_scripts_init.php` — общий JS-бойлерплейт (ResizeObserver, Switchery, DataTable errMode, bindHref, inputFilter, js_navigate и т.д.)
- ✅ `public/Views/layout.php` — мёртвый код, помечен `@deprecated` (актуальный layout: `Views/layouts/admin.php` + `footer.php`)
- ✅ `public/Views/partials/header.php` + `footer.php` — мёртвый код, помечены `@deprecated`

**Render flow:** `renderUnifiedLayoutHeader('admin')` → `view.php` → `renderUnifiedLayoutFooter('admin')` → `scripts.php` (включает `_scripts_init.php`) → `</body></html>`

**Инфраструктурные исправления перед Phase 6.3:**
- ✅ Router `buildRoute()` — нормализация ключей при регистрации (rtmp_ips → rtmp/ips)
- ✅ Router `callHandler()` — DI через ServiceContainer с fallback
- ✅ Autoloader — раскомментированы `domain/`, `streaming/`, `modules/`, добавлен `public/`
- ✅ Autoloader — `MAIN_HOME` fallback: `__DIR__ . '/'` вместо `/home/xc_vm/`
- ✅ Мёртвый Layout код (layout.php + partials/header.php + partials/footer.php) помечен `@deprecated`

**Пилотная группа (простые листинги, no POST, inline DataTables):**
- ✅ `IpController.php` + `Views/admin/ips.php` + `ips.scripts.php` — Blocked IPs, flush action, getBlockedIPs()
- ✅ `IspController.php` + `Views/admin/isps.php` + `isps.scripts.php` — Blocked ISPs, getISPs()
- ✅ `HmacController.php` + `Views/admin/hmacs.php` + `hmacs.scripts.php` — HMAC Keys, getHMACTokens()
- ✅ `GroupController.php` + `Views/admin/groups.php` + `groups.scripts.php` — Member Groups, getMemberGroups(), adv:edit_group
- ✅ `CodeController.php` + `Views/admin/codes.php` + `codes.scripts.php` — Access Codes, getcodes(), type mapping
- ✅ `PackageController.php` + `Views/admin/packages.php` + `packages.scripts.php` — Packages (filtered !is_addon), getPackages()
- ✅ `RtmpIpController.php` + `Views/admin/rtmp_ips.php` + `rtmp_ips.scripts.php` — RTMP IPs, getRTMPIPs(), push/pull icons
- ✅ `ProfileController.php` + `Views/admin/profiles.php` + `profiles.scripts.php` — Transcode Profiles, getTranscodeProfiles(), JSON profile_options
- ✅ `ProviderController.php` + `Views/admin/providers.php` + `providers.scripts.php` — Stream Providers, getStreamProviders(), reload action, JSON data
- ✅ `TheftDetectionController.php` + `Views/admin/theft_detection.php` + `theft_detection.scripts.php` — VOD Theft Detection, igbinary cache, range filter, custom search
- ✅ Маршруты в `public/routes/admin.php` — 10 GET-маршрутов зарегистрированы

**Статус 6.3:** ✅ Завершён — **111/111 admin-страниц мигрировано**

| Артефакт | Кол-во | Статус |
|---|---|---|
| Контроллеры (`Controllers/Admin/`) | 111 + BaseAdminController | ✅ |
| View-прокси (`Views/admin/`) | 111 | ✅ |
| Маршруты (`routes/admin.php`) | 111 | ✅ |
| Legacy-обёртки (`$__viewMode`) | 111 (108 std + 1 settings variant + 2 module bypass) | ✅ |

**Группы миграции (все завершены):**
- ✅ **Pilot** (10): ips, isps, hmacs, groups, codes, packages, rtmp_ips, profiles, providers, theft_detection
- ✅ **A** (19): streams, stream, stream_view, stream_mass, stream_categories, stream_category, stream_errors, stream_rank, stream_review, stream_tools, channel_order, created_channel, created_channels, created_channel_mass, live_connections, rtmp_monitor, radio, radios, radio_mass
- ✅ **B** (10): movies, movie, movie_mass, series, serie, series_mass, episodes, episode, episodes_mass, ondemand
- ✅ **C** (6): lines, line, line_mass, line_activity, line_ips, mass_delete
- ✅ **D** (4): server_order, server_install, server, server_view
- ✅ **E** (4): bouquets, bouquet, bouquet_order, bouquet_sort
- ✅ **F** (4): settings, settings_plex, settings_watch, quick_tools
- ✅ **G** (6): login_logs, mysql_syslog, mag_events, restream_logs, panel_logs, epgs
- ✅ **H** (9): dashboard, cache, queue, process_monitor, backups, client_logs, user_logs, useragent, useragents
- ✅ **I** (6): mags, mag, mag_mass, enigmas, enigma, enigma_mass
- ✅ **J** (6): epg, epg_view, code, hmac, ip, isp
- ✅ **K** (5): group, package, profile, provider, rtmp_ip
- ✅ **L** (5): users, user, user_mass, plex, plex_add
- ✅ **M** (8): tickets, ticket, ticket_view, serie, watch, watch_add, watch_output, magscan_settings
- ✅ **N** (9): credit_logs, edit_profile, fingerprint, proxies, proxy, record, review, archive, asns

**16 non-page файлов** (миграция не требуется): header.php, footer.php, topbar.php, modals.php, functions.php, session.php, post.php, login.php, logout.php, setup.php, database.php, index.php, resize.php, player.php, api.php, table.php

#### Шаг 6.4 — Объединение admin/reseller ✅

**Статус:** Завершён — 22 reseller-страницы мигрированы.

**Артефакты:**
- ✅ `BaseResellerController` — расширяет BaseAdminController, `$scope = 'reseller'`, переопределяет `requirePermission()` → `checkResellerPermissions()`
- ✅ 22 контроллера (`Reseller*Controller`) в `public/Controllers/Reseller/`
- ✅ 22 view proxy в `public/Views/reseller/`
- ✅ 22 legacy файла обёрнуты `$__viewMode` гардами
- ✅ `public/routes/reseller.php` — 22 маршрута

**Reseller-страницы (22):**
Dashboard & Profile: dashboard, edit_profile
Lines: lines, line, line_activity, live_connections
Devices: mags, mag, enigmas, enigma
Content: streams, movies, radios, episodes, created_channels, epg_view
Tickets: tickets, ticket, ticket_view
Users: users, user, user_logs

**Ключевые отличия от admin:**
- Контроллеры с префиксом `Reseller` (ResellerDashboardController и т.д.) для избежания конфликтов с admin-контроллерами в безнеймспейсном автозагрузчике
- `checkResellerPermissions()` вместо `checkPermissions()`
- `$_SESSION['reseller']` вместо `$_SESSION['hash']`

```
reseller/table.php (1836 стр.) → переиспользует admin-контроллеры с RBAC-фильтром
reseller/api.php (997 стр.)    → ResellerApiController (ограниченный AdminApiController)
```

#### Шаг 6.5 — Стабилизация Controller/View контракта ✅

**Статус:** Завершён (первый стабилизационный проход после 6.3/6.4).

**Сделано:**
- ✅ Расширен базовый контракт `BaseAdminController::$viewGlobals`:
    - добавлены `rTMDBLanguages`, `rGeoCountries`, `rMAGs`, `rTimezones`
    - ранее добавлен `allowedLangs`
- ✅ Исправлен рендер update-уведомления/changelog в `admin/settings.php`:
    - защита `foreach` по `changelog` через `is_array`
    - защита вложенного `changes` через безопасный fallback на `[]`
- ✅ Исправлен `modules/watch/views/watch_add.php`:
    - защита `foreach` по `$rTMDBLanguages` при отсутствии/некорректном типе
- ✅ Второй стабилизационный проход (расширенный, 14 файлов):
    - контроллеры `watch/plex/settings_*` теперь нормализуют массивы (`[]` fallback)
    - в `watch/plex/profile` view добавлены безопасные `foreach` и `in_array/count` с `(array)`/`is_array` guard
    - закрыты риски `foreach(null)` и `Undefined variable` для страниц `watch_output`, `watch_add`, `settings_watch`, `record`, `plex/index`, `plex/settings`, `plex/library_edit`, `admin/edit_profile`

**Цель шага:**
- убрать class-runtime регрессий «Undefined variable / foreach() argument must be array|object»
- зафиксировать единый контракт данных для legacy view в controller-режиме

**Дальше (следующий проход 6.5):**
- 🔄 точечный аудит `Reseller`-view (особенно echo-сгенерированные файлы) на `foreach/in_array` с nullable-данными
- 🔄 выборочно перенести оставшиеся high-risk случаи в контроллерный data-contract вместо локальных view-guard

---

### Фаза 7: Миграция admin.php bootstrap

`admin.php` (4448 стр.) — после извлечения всех функций в domain/.
#### Шаг 7.1 — Вынос inline-данных ✅
- ✅ Данные bootstrap (`rMAGs`, `rCountryCodes`, `rCountries`, `rAdvPermissions`) консолидированы в `resources/data/admin_constants.php`
- ✅ Отдельный `resources/data/permissions.php` удалён

#### Шаг 7.2 — Замена процедурного bootstrap 🔄
```
admin.php::initDatabase()        → ServiceContainer::get('db')
admin.php::CoreUtilities::init() → bootstrap.php
admin.php::session_start()       → SessionManager::start()
admin.php::$rPermissions         → Authorization::getPermissions()
admin.php::Translator init       → ServiceContainer::get('translator')
```

- ✅ Первый инкремент: инициализация runtime в `includes/admin.php` вынесена в `bootstrapAdminRuntime()` (поведение сохранено)
- ✅ Второй инкремент: session/runtime-логика вынесена в `includes/bootstrap/admin_session.php` и `includes/bootstrap/admin_runtime.php` (без изменения поведения)
- ✅ Третий инкремент: `includes/bootstrap/admin_runtime.php` переключён на `XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN)` с legacy fallback
- ✅ Четвёртый инкремент: прямые `require_once` зависимостей (`DatabaseHandler/CoreUtilities/API/Translator/MobileDetect`) удалены из `includes/admin.php` и централизованы в `includes/bootstrap/admin_runtime.php`
- ✅ Пятый инкремент: `includes/admin.php` больше не подключает `www/constants.php` напрямую — инициализация идёт через bootstrap-поток
- ✅ Шестой инкремент: убрана двойная `shutdown`-регистрация — в bootstrap-ветке используется `XC_Bootstrap`, в legacy fallback регистрируется локальный close-db shutdown callback
- ✅ Седьмой инкремент: `STATUS_*` константы удалены из `includes/admin.php` и централизованы через `bootstrapAdminStatusConstants()` (`XC_Bootstrap::defineStatusConstants()` + fallback)
- ✅ Восьмой инкремент: bootstrap-цепочка в `includes/admin.php` сведена к фасаду `includes/bootstrap/admin_bootstrap.php` (`bootstrapAdminInclude()`)

#### Шаг 7.3 — Удаление proxy-обёрток из admin.php ✅
- Все функции уже в domain/ (proxy-вызовы)
- Все данные в resources/data/
- Bootstrap через `bootstrap.php`
- `admin.php` — сокращён с ~4448 до ~3070 строк (удалено 40 proxy-определений)

**Статус (7.3 завершён):**
- ✅ Первый инкремент: пакет простых proxy-обёрток вынесен из `includes/admin.php` в `includes/bootstrap/admin_proxies.php`
- ✅ Второй инкремент: вынесены proxy/report/permission-обёртки (`getStreamArguments`, `getTranscodeProfiles`, `getResellers`, `getDirectReports`, `hasPermissions` и связанный блок) в `includes/bootstrap/admin_proxies.php`
- ✅ Третий инкремент: начат прямой отказ от proxy-call-site — HMAC-ветка переведена на прямые вызовы `AuthRepository::*`, proxy `getHMACTokens/getHMACToken` удалены
- ✅ Четвёртый инкремент: массовая замена всех внешних call-site (560+) для proxy-функций:
  - `getBouquets()` → `BouquetService::getAllSimple()` (47 call-sites)
  - `getStreamingServers()` → `ServerRepository::getStreamingSimple($rPermissions, $type)` (29 call-sites)
  - `getStream()` → `StreamRepository::getById($rID)` (~30 call-sites)
  - `getUser()` → `UserRepository::getLineById($rID)` (~34 call-sites)
  - `getRegisteredUser()` → `UserRepository::getRegisteredUserById($rID)` (~48 call-sites)
  - `getBouquetOrder()` → `BouquetService::getOrder()` (6 call-sites)
  - `getMemberGroup()` → `GroupService::getById($rID)` (4 call-sites)
  - `getBlockedIPs()` → `BlocklistService::getBlockedIPsSimple()` (2 call-sites)
  - `getRTMPIPs()` → `BlocklistService::getRTMPIPsSimple()` (2 call-sites)
  - `getSSLLog()` → `ServerRepository::getSSLLog(...)` (1 call-site)
  - `getWatchdog()` → `WatchdogMonitor::getWatchdog(...)` (1 call-site)
  - `getIP()` → `CoreUtilities::getUserIP()` (2 call-sites)
  - `getFPMStatus()` → `systemapirequest(...)` inline (1 call-site)
  - И ещё ~20 proxy-функций с 0 внешних вызовов
- ✅ Пятый инкремент: все 40 proxy-определений удалены из `admin.php`
- ✅ Рефакторинг: `PackageService::process()` — удалены callback-параметры `getBouquetOrder`/`sortArrayByArray`, заменены прямыми вызовами `BouquetService::getOrder()`
- ⚠️ Оставлены: `getCategories()` (~15 внешних вызовов, не чистый proxy — трансформирует ключи), `getOutputs()` (3 внешних вызова, собственная SQL-логика)

---

### Шаг 7.4 — Устранение параметра `$db` из Domain-классов (global $db)

**Суть:** Все 28 Domain-классов (Service/Repository) принимали `$db` как параметр метода. Рефакторинг: убрать `$db` из сигнатур, внутри каждого метода объявить `global $db;`.

**Затронутые классы (100 методов):**
- `StreamRepository` (7), `StreamService` (4), `StreamProcess` (13), `ChannelService` (3)
- `SeriesService` (3), `MovieService` (3), `EpisodeService` (2), `PlaylistGenerator` (1)
- `CronGenerator` (1), `ConnectionTracker` (2), `ConnectionLimiter` (3), `CategoryService` (3)
- `UserService` (2), `UserRepository` (5), `GroupService` (2), `PackageService` (2)
- `LineRepository` (1), `LineService` (4), `SettingsService` (3), `ServerRepository` (5)
- `ServerService` (4), `BlocklistService` (10), `EpgService` (3), `AuthService` (3)
- `BouquetService` (8), `TokenAuth` (1), `FFmpegCommand` (1), `WatchdogMonitor` (1)

**Обновлено ~357 call-sites** по всей кодовой базе:
- `src/includes/CoreUtilities.php` — 28 вызовов
- `src/includes/admin_api.php` — 60 вызовов
- `src/includes/reseller_api.php` — 15 вызовов
- `src/includes/admin.php` — 16 вызовов
- `src/includes/StreamingUtilities.php` — 6 вызовов
- `src/includes/bootstrap/admin_runtime.php` — 3 вызова
- `src/includes/api/admin/` — 20 вызовов
- `src/includes/api/reseller/` — 11 вызовов
- `src/admin/*.php` — ~100 вызовов
- `src/reseller/*.php` — 18 вызовов
- `src/public/Controllers/Admin/` — 37 вызовов
- `src/domain/` (кросс-вызовы) — 15 вызовов
- `src/modules/` + `src/crons/` — 11 вызовов

**Паттерн (до/после):**
```php
// Было:
public static function getById($db, $rID) { ... }
StreamRepository::getById($db, $rStreamID);

// Стало:
public static function getById($rID) {
    global $db;
    ...
}
StreamRepository::getById($rStreamID);
```

**Статус:** ✅ Завершено

---

### Фаза 8: Ликвидация god-объектов

**Цель:** Удалить три файла-монолита (`CoreUtilities.php`, `StreamingUtilities.php`, `admin_api.php`), заменив все ~7 100 внешних вызовов на прямые обращения к целевым классам в `domain/`, `core/`, `streaming/`.

#### Аудит перед началом (28.02.2026)

| Файл | Строк | Методов | PROXY | OWN | Внешних вызовов | Файлов-потребителей |
|------|--------|---------|-------|-----|-----------------|---------------------|
| `CoreUtilities.php` | 1 971 | 152 | 81 | 69 | 5 755 | 303 |
| `StreamingUtilities.php` | 659 | 78 | 42 | 33 | 1 344 | 20 |
| `admin_api.php` | 3 686 | 79 | 60 | 18 | ~300 | ~40 |
| **Итого** | **6 316** | **309** | **183** | **120** | **~7 400** | — |

**Распределение вызовов CoreUtilities по типу:**
- `$rRequest` — 3 863 обращений (67%) — глобальный request-контейнер
- `$rSettings` — 769 обращений — настройки панели
- `$rServers` — 201 обращение — массив серверов
- Методы-вызовы — ~920 обращений

**Распределение StreamingUtilities по директориям:**
- `www/` — 769 | `ministra/` — 266 | `modules/` — 229 | `core/` — 76 | `streaming/` — 4

**Дублирование:** ~25 методов идентичны в обоих классах (`encryptData`, `base64url_*`, `cleanGlobals`, `getUserIP`, `getISP`, `getIPInfo`, `getPublicURL`, `isMonitorRunning`, `isStreamRunning`, `isProcessRunning`, `validateImage`, `generateString`, `getDiffTimezone`, `getAdultCategories`, `writeOfflineActivity`, `getPlaylistSegments`, `checkISP`, `checkServer`, `setSignal`, `matchCIDR` и др.)

---

#### Шаг 8.1 — admin_api.php (LOW RISK)

**Объём:** 3 686 строк, 79 методов (60 PROXY + 18 OWN + 1 INIT), ~300 внешних вызовов в ~40 файлах.

**8.1.1 — Удаление PROXY-методов** (60 шт.)
Каждый PROXY-метод просто делегирует в сервис:
```php
// Было (admin_api.php):
public static function processBouquet() { return BouquetService::process(self::$db, ...); }
// Вызов:
ipTV_lib::processBouquet();

// Стало (прямой вызов):
BouquetService::process($db, ...);
```

Целевые классы-делегаты (все уже существуют в `domain/`):
`BouquetService`(3), `AuthService`(2), `UserService`(4), `BlocklistService`(3), `ChannelService`(3),
`EpgService`(1), `EpisodeService`(3), `GroupService`(1), `StreamService`(4), `MovieService`(3),
`SeriesService`(3), `LineService`(3), `MagService`(3), `EnigmaService`(3), `PackageService`(1),
`ServerService`(3), `SettingsService`(3), `CategoryService`(2), `PlexService`(2), `WatchService`(2),
`RecordingService`(1), `Authenticator`(1)

**8.1.2 — Извлечение OWN-методов** (18 шт.)
Legacy-методы с прямым SQL, которые не были перенесены в сервисы:

| Метод | Строк | Целевой класс |
|-------|-------|--------------|
| `processProvider()` | ~55 | → `domain/Stream/ProviderService.php` (новый) |
| `processGroupLegacy()` | ~90 | → `domain/User/GroupService.php` |
| `processMovieLegacy()` | ~465 | → `domain/Vod/MovieService.php` |
| `processMAGLegacy()` | ~195 | → `domain/Device/MagService.php` |
| `processEnigmaLegacy()` | ~193 | → `domain/Device/EnigmaService.php` |
| `processProfile()` | ~320 | → `domain/Stream/ProfileService.php` (новый) |
| `processRadio()` | ~248 | → `domain/Stream/RadioService.php` (новый) |
| `massEditRadios()` | ~185 | → `domain/Stream/RadioService.php` |
| `processUserLegacy()` | ~92 | → `domain/User/UserService.php` |
| `processLineLegacy()` | ~108 | → `domain/Line/LineService.php` |
| `processSeriesLegacy()` | ~100 | → `domain/Vod/SeriesService.php` |
| `importSeriesLegacy()` | ~205 | → `domain/Vod/SeriesService.php` |
| `importMoviesLegacy()` | ~200 | → `domain/Vod/MovieService.php` |
| `massEditLinesLegacy()` | ~120 | → `domain/Line/LineService.php` |
| `massEditMagsLegacy()` | ~187 | → `domain/Device/MagService.php` |
| `massEditEnigmasLegacy()` | ~156 | → `domain/Device/EnigmaService.php` |
| `massEditUsersLegacy()` | ~86 | → `domain/User/UserService.php` |
| `massDeleteStations()` | ~16 | → `domain/Stream/StreamService.php` |

**8.1.3 — Удаление `init()` и статических свойств**
- `$db`, `$rSettings`, `$rServers`, `$rProxyServers`, `$rUserInfo` — уже доступны через `CoreUtilities` или `$GLOBALS`
- `checkMinimumRequirements()` (приватный валидатор) → `core/Validation/InputValidator.php`
- Удалить файл `includes/admin_api.php`

**Статус 8.1:** ✅ Завершён
- `admin_api.php` (класс `API`) — файл удалён, все proxy/OWN методы мигрированы в domain-сервисы
- Осиротевшие ссылки `API::processUser()` в `src/tools` → заменены на `UserService::process()`
- `API::$db`/`API::init()` в `src/tools` → удалены
- Запись `'API'` в `autoload.php` → удалена
- `reseller_api.php` (класс `ResellerAPI`): `self::$db` → `global $db` (42 вхождения)
- Удалено свойство `public static $db = null` из `ResellerAPI`
- Удалены внешние присвоения `ResellerAPI::$db` из `bootstrap.php`, `admin_runtime.php`, `api/reseller/index.php`

---

#### Шаг 8.2 — StreamingUtilities.php (MEDIUM RISK)

**Объём:** 659 строк, 78 методов (42 PROXY + 33 OWN + 1 INIT + 2 PROPERTY), 1 344 внешних вызовов в 20 файлах.

**8.2.1 — Удаление PROXY-методов** (42 шт.)
Все прямые делегаты → заменить call sites на целевой класс:
`ConnectionTracker`(8), `BruteforceGuard`(4), `StreamSorter`(4), `BlocklistService`(3),
`ConnectionLimiter`(3), `StreamRedirector`(2), `OffAirHandler`(2), `RedisManager`(2),
`StreamAuth`(2), `ProxySelector`(1), `SignalSender`(1), `AuthService`(1), `ImageUtils`(1),
`HealthChecker`(1), `CategoryService`(1), `SegmentReader`(1), `HLSGenerator`(1),
`BouquetService`(1), `DomainResolver`(1), `UserRepository`(1), `LegacyInitializer`(1)

**8.2.2 — Извлечение OWN-методов** (33 шт.)
Группировка по целевому модулю:

| Группа | Методы | Целевой класс |
|--------|--------|--------------|
| Кеш | `getCache()` | → `core/Cache/FileCache.php` (объединить с `CoreUtilities::getCache/setCache`) |
| Криптография | `encryptData()`, `decryptData()`, `base64url_encode/decode()`, `mc_decrypt()` | → `core/Crypto/DataEncryptor.php` (новый, общий) |
| HTTP/Input | `cleanGlobals()`, `parseIncomingRecursively()`, `parseCleanKey()`, `parseCleanValue()` | → `core/Http/InputSanitizer.php` (новый, общий) |
| Networking | `getUserIP()`, `getISP()`, `getIPInfo()`, `matchCIDR()`, `getDomainName()` | → `core/Network/IpUtils.php` (новый) |
| Process | `isMonitorRunning()`, `isStreamRunning()`, `isProcessRunning()`, `startMonitor()`, `startProxy()` | → `streaming/Process/ProcessManager.php` (объединить из обоих) |
| Streaming | `getPublicURL()`, `getStreamData()`, `getPlaylistSegments()`, `getAllowedRTMP()`, `canWatch()`, `writeOfflineActivity()`, `setSignal()`, `generateString()` | → `streaming/` соответствующие классы |
| UI | `getDiffTimezone()`, `getAdultCategories()` | → `core/Util/DateUtils.php`, `domain/Stream/CategoryService.php` |
| DB | `connectDatabase()`, `closeDatabase()` | → оставить в `LegacyInitializer` или `core/Database/` |
| Init | `isCacheEnabledAndComplete()` | → `core/Cache/CacheStatus.php` |

**8.2.3 — Замена статических свойств**
`StreamingUtilities::$rRequest` (369), `$rSettings` (366), `$rServers` (105), `$db` (83) — всего **923 обращения** в 20 файлах.
→ Заменить на `$GLOBALS['rRequest']` / `$GLOBALS['rSettings']` / `$GLOBALS['db']` (промежуточный шаг)
→ Или инжектировать через параметры функций (целевой вариант)

**8.2.4 — Удаление файла**
После переноса всех методов и свойств — удалить `includes/StreamingUtilities.php`.

**Статус 8.2:** ✅ **ЗАВЕРШЕНО** (2026-03-03)

**Итого:** ~314 внешних ссылок заменены в 10 батчах (Batch 1–10d).
Файл `includes/StreamingUtilities.php` удалён (676 строк мёртвого кода).

**Ключевые результаты:**
- 8.2.3 — все 18 статических свойств удалены → `global $var` / `$GLOBALS['var']`
- 8.2.1 — все 42 PROXY-метода мигрированы → прямые вызовы сервисов
- 8.2.2 — все 33 OWN-метода перенесены в целевые классы
- 8.2.4 — файл удалён, require/autoload записи убраны, bootstrap.php очищен

**Батчи миграции внешних вызовов:**

| Батч | Методы | Замен | Файлов |
|------|--------|-------|--------|
| 1 | Crypto (encrypt/decrypt/base64url) | 42 | 5 |
| 2 | validateImage | 22 | 3 |
| 3–5 | Простые proxy (sort, categories, health, signal, cache, etc.) | ~60 | 12 |
| 6 | DB lifecycle (connect/close) | 26 | 8 |
| 7 | Redis + Connection (get/create/updateConnection, redisSignal, etc.) | 57 | 6 |
| 8 | Process + Proxy + Queue | 17 | 5 |
| 9 | Process + GeoIP (isMonitorRunning, getIPInfo, etc.) | 24 | 5 |
| 10a | getUserInfo | 18 | 6 |
| 10b | showVideoServer + availableProxy | 26 | 2 |
| 10c | redirectStream, validateConnections, validateHMAC, generateHLS, etc. | 20 | 8 |
| 10d | getAllowedRTMP + getPlaylistSegments | 2 | 2 |

**Рефакторинг callback-цепочек (Batch 10):**
9 сервисных классов избавлены от callback-параметров → прямые service-to-service вызовы:
`UserRepository`, `OffAirHandler`, `StreamAuth`, `ProxySelector`, `StreamRedirector`,
`ConnectionLimiter`, `HLSGenerator`, `AuthService`, `DomainResolver`

**Новые методы/классы добавленные при миграции:**
- `GeoIPService::getIPInfo()`, `getISP()`, `matchCIDR()`
- `BlocklistService::getAllowedRTMP()`
- `SegmentReader::getPlaylistSegments()`
- `ConnectionLimiter::writeOfflineActivity()`
- `StreamRedirector::getStreamData()` (private)
- `ProcessManager::isStreamAlive()`, `isMonitorAlive()`, `startMonitor()`, `startProxy()`
- `RedisManager::instance()` (singleton для прямого доступа)

---

#### Шаг 8.3 — CoreUtilities.php (HIGH RISK, поэтапно)

**Объём:** 1 971 строк, 152 метода (81 PROXY + 69 OWN + 1 INIT + 1 PROPERTY), 5 755 внешних вызовов в 303 файлах.

**Главная сложность:** `CoreUtilities::$rRequest` — 3 863 обращения в 303 файлах. Это глобальный массив POST/GET-данных, используемый как `CoreUtilities::$rRequest['page']`, `CoreUtilities::$rRequest['id']` и т.д. Аналогично `$rSettings` (769 обращений) и `$rServers` (201).

**8.3.1 — Удаление PROXY-методов** (81 шт.)
Прямая замена вызовов на целевые классы. Топ делегатов:
`StreamProcess`(16), `ConnectionTracker`(14), `PlexAuth`(6), `BlocklistService`(5),
`BruteforceGuard`(4), `StreamSorter`(4), `LineService`(4), `EpgService`(4),
`ProcessChecker`(3), `CurlClient`(3), `FFprobeRunner`(2), `BouquetService`(2),
`ImageUtils`(2), `RedisManager`(2), остальные по 1

**Топ-10 вызываемых PROXY-методов** (количество call sites):

| Метод | Вызовов | Делегат |
|-------|---------|---------|
| `closeConnection()` | 48 | → `ConnectionLimiter::closeConnection()` |
| `validateImage()` | 40 | → `ImageUtils::validateURL()` |
| `downloadImage()` | 32 | → `ImageUtils::download()` (новый целевой) |
| `getUserIP()` | 30 | → `core/Network/IpUtils::getUserIP()` |
| `updateStream()` | 29 | → `StreamProcess::updateStream()` |
| `getUserConnections()` | 29 | → `ConnectionTracker::getUserConnections()` |
| `updateLine()` | 26 | → `LineService::updateLineSignal()` |
| `checkCron()` | 22 | → метод остаётся (OWN) |
| `getStreamConnections()` | 20 | → `ConnectionTracker::getStreamConnections()` |
| `getRedisConnections()` | 20 | → `ConnectionTracker::getRedisConnections()` |

**8.3.2 — Извлечение OWN-методов** (69 шт.)

| Группа | Методы (шт.) | Целевой класс |
|--------|-------------|--------------|
| User/Auth | `getUserInfo()`(170 стр.), `getMAGInfo()`, `getE2Info()`, `getAllowedIPs()`, `getAllowedDomains()` | → `domain/Line/UserInfoResolver.php` (новый) |
| Connection | `closeConnection()`(96 стр.), `writeOfflineActivity()`, `streamLog()` | → `streaming/Connection/ConnectionManager.php` |
| Process | `isMonitorRunning()`, `isThumbnailRunning()`, `isArchiveRunning()`, `isDelayRunning()`, `isStreamRunning()`, `isProcessRunning()`, `isRunning()` | → `streaming/Process/ProcessManager.php` |
| Streaming | `getPlaylistSegments()`, `generateAdminHLS()`, `getStreamBitrate()`, `isValidStream()`, `findKeyframe()`, `parseStreamURL()`, `detectXC_VM()`, `parseTranscode()`, `getArguments()`, `customOrder()`, `getRTMPStats()` | → распределить по `streaming/` классам |
| Cache | `setCache()`, `getCache()` | → `core/Cache/FileCache.php` |
| Crypto | `encryptData()`, `decryptData()`, `base64url_encode/decode()` | → `core/Crypto/DataEncryptor.php` |
| Network | `getUserIP()`, `getISP()`, `checkISP()`, `checkServer()`, `getIPInfo()`, `getCertificateInfo()`, `getApiIP()` | → `core/Network/IpUtils.php` |
| HTTP/Input | `cleanGlobals()`, `parseIncoming*()`, `parseCleanKey/Value()` | → `core/Http/InputSanitizer.php` |
| Image | `downloadImage()`, `getImageSizeKeepAspectRatio()`, `isAbsoluteUrl()` | → `core/Media/ImageUtils.php` (расширить) |
| URL | `getPublicURL()`(45 стр.), `getProxyFor()` | → `core/Network/UrlBuilder.php` |
| Download | `startDownload()`, `stopDownload()` | → `streaming/Download/DownloadManager.php` |
| Backup | `createBackup()`, `restoreBackup()`, `grantPrivileges()`, `revokePrivileges()` | → `domain/Server/BackupService.php` (новый) |
| System | `checkCompatibility()`, `confirmIDs()`, `downloadPanelLogs()`, `submitPanelLogs()` | → `core/System/SystemCheck.php` (новый) |
| Misc | `generateString()`, `secondsToTime()`, `mergeRecursive()`, `checkCron()`, `fixCookie()`, `unserialize_php()`, `getTSInfo()`, `getDiffTimezone()` | → `core/Util/Helpers.php` (новый) |

**8.3.3 — Замена статических свойств** (наибольшая работа)

| Свойство | Обращений | Стратегия замены |
|----------|-----------|-----------------|
| `$rRequest` | 3 863 | **Этап A:** глобальная переменная `$rRequest` (через `$GLOBALS['rRequest']` или `global $rRequest`). **Этап B:** `Request::input()` в контроллерах, прямой `$_POST/$_GET` в legacy. |
| `$rSettings` | 769 | **Этап A:** `$GLOBALS['rSettings']`. **Этап B:** `SettingsRepository::getCached()` статический singleton. |
| `$rServers` | 201 | **Этап A:** `$GLOBALS['rServers']`. **Этап B:** `ServerRepository::getCached()`. |
| `$db` | 20 | `$GLOBALS['db']` или `global $db` (уже используется в большинстве мест) |
| `$redis` | 60 | `RedisManager::getInstance()` |
| Остальные | ~40 | Через соответствующие Repository/Service с кешем |

**Порядок замены `$rRequest` (3 863 вызова):**
1. `admin/` (2 307 из них — уже обёрнуты `$__viewMode` guard, контроллеры используют `$this->input()`)
2. `includes/` (1 455 — через `global $rRequest`)
3. `reseller/` (678), `www/` (373), `crons/` (260), `player/` (170)
4. `core/` (164), `public/` (161), `modules/` (112), `domain/` (76)

**8.3.4 — Удаление `init()` и `LegacyInitializer`**
- `CoreUtilities::init()` → `LegacyInitializer::initCore()` → вызывается внешними `www/init.php`, `bootstrap.php`
- Заменить инициализацию на `XC_Bootstrap::boot()` (уже существует)
- Удалить `LegacyInitializer.php` после полного перехода

**8.3.5 — Удаление файла**
После переноса ВСЕХ методов и свойств — удалить `includes/CoreUtilities.php`.

**Статус 8.3:** ⏳ Не начат

---

#### Шаг 8.4 — Ревизия архитектуры

- Убедиться: `core/` не содержит бизнес-логики (только инфраструктура)
- Убедиться: `domain/` не знает об `public/` (однонаправленные зависимости)
- Убедиться: удаление любого модуля не ломает систему
- Рефакторинг `cli/monitor.php` — убрать goto-лейблы, вынести в `cli/Commands/MonitorCommand.php`

**Статус 8.4:** ⏳ Не начат

---

#### Новые целевые файлы (создаются в Фазе 8)

| Файл | Откуда | Назначение |
|------|--------|-----------|
| `core/Cache/FileCache.php` | CU::getCache/setCache + SU::getCache | Файловый кеш с igbinary |
| `core/Crypto/DataEncryptor.php` | CU+SU::encryptData/decryptData/base64url_* | Шифрование/кодирование |
| `core/Http/InputSanitizer.php` | CU+SU::cleanGlobals/parseIncoming*/parseClean* | Очистка входных данных |
| `core/Network/IpUtils.php` | CU+SU::getUserIP/getISP/getIPInfo/checkISP/matchCIDR | IP-утилиты |
| `core/Network/UrlBuilder.php` | CU+SU::getPublicURL/getProxyFor | Построение URL |
| `core/Media/ImageUtils.php` | CU::downloadImage/getImageSize* (расширить имеющийся) | Работа с изображениями |
| `core/Util/Helpers.php` | CU::generateString/secondsToTime/mergeRecursive/etc. | Общие утилиты |
| `core/Util/DateUtils.php` | CU+SU::getDiffTimezone | Дата/время |
| `core/System/SystemCheck.php` | CU::checkCompatibility/downloadPanelLogs/submitPanelLogs | Системные проверки |
| `core/Validation/InputValidator.php` | admin_api::checkMinimumRequirements | Валидация входных данных |
| `streaming/Process/ProcessManager.php` | CU+SU::isMonitor/isStream/isProcess/isRunning | Управление процессами |
| `streaming/Connection/ConnectionManager.php` | CU::closeConnection/writeOfflineActivity/streamLog | Управление соединениями |
| `streaming/Download/DownloadManager.php` | CU::startDownload/stopDownload | Управление скачиваниями |
| `domain/Line/UserInfoResolver.php` | CU::getUserInfo/getMAGInfo/getE2Info | Получение инфо о юзере/устройстве |
| `domain/Server/BackupService.php` | CU::createBackup/restoreBackup/grant/revokePrivileges | Управление бэкапами |
| `domain/Stream/ProviderService.php` | admin_api::processProvider | CRUD провайдеров |
| `domain/Stream/ProfileService.php` | admin_api::processProfile | CRUD профилей транскодирования |
| `domain/Stream/RadioService.php` | admin_api::processRadio/massEditRadios | CRUD радиостанций |

---

### Рекомендуемый порядок выполнения Фазы 8

```
8.1 admin_api.php    ──→  8.2 StreamingUtilities  ──→  8.3 CoreUtilities
(~300 вызовов, ~40 файлов)  (1 344 вызовов, 20 файлов)  (5 755 вызовов, 303 файла)
      LOW RISK                  MEDIUM RISK                  HIGH RISK

Внутри каждого шага:
  .1 Удалить PROXY (поиск-замена call sites)
  .2 Извлечь OWN (создать целевые классы + перенести логику)
  .3 Заменить свойства (static → global/DI)
  .4 Удалить файл
```

Каждый подшаг (8.1.1, 8.1.2, ...) — одна рабочая сессия.
После каждого подшага система полностью работоспособна (proxy и legacy продолжают работать).

---

## 10. Транзакции и производительность

### 10.1. Кто управляет транзакциями

**Правило:** Транзакцией управляет **Service**. Контроллер и Repository не открывают транзакции.

```
Controller ──→ Service ──→ Repository + Infrastructure
                  │
                  ├── beginTransaction()
                  ├── ... бизнес-операции ...
                  ├── commit()
                  └── (rollback при исключении)
```

### 10.2. Паттерн транзакции

```php
class StreamService {
    public function massEdit(array $streamIds, array $changes): int {
        $this->db->beginTransaction();
        try {
            $affected = 0;
            foreach ($streamIds as $id) {
                $this->repository->update($id, $changes);
                $affected++;
            }
            $this->db->commit();
            return $affected;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->log('error', "Mass edit failed: " . $e->getMessage());
            throw $e;
        }
    }
}
```

### 10.3. Границы по контексту

| Контекст | Транзакция | Кто управляет | Пример |
|----------|-----------|---------------|--------|
| **Admin CRUD** | Одна операция = одна транзакция | Service | `StreamService::create()` |
| **Mass edit** | Весь batch = одна транзакция | Service | `StreamService::massEdit()` |
| **Import** | Chunk по 100 записей | Service | `StreamService::importM3U()` |
| **Cron** | Каждая итерация = отдельная транзакция | CronJob | `ServersCron::processServer()` |
| **Streaming** | ❌ Нет транзакций | — | Hot path не мутирует через транзакции |

### 10.4. Внешние процессы

Операция «создать поток + запустить ffmpeg + обновить nginx» — не атомарна. FFmpeg/nginx — внешние процессы, откатить нельзя.

**Паттерн:**
1. DB-операции — в транзакции
2. Внешние процессы — после commit, с обработкой ошибок
3. При сбое внешнего процесса — обновить статус в БД (`status = 'error'`)

```php
class StreamService {
    public function start(int $streamId): void {
        // 1. DB: обновить статус
        $this->repository->updateStatus($streamId, 'starting');
        
        // 2. Внешние процессы (вне транзакции)
        try {
            $pid = $this->processManager->startFFmpeg($streamId);
            $this->repository->updatePid($streamId, $pid);
            $this->repository->updateStatus($streamId, 'running');
        } catch (\Throwable $e) {
            $this->repository->updateStatus($streamId, 'error');
            $this->logger->log('error', "Start failed: {$e->getMessage()}");
            throw $e;
        }
    }
}
```

### 10.5. Два режима работы системы

| Режим | Путь | Частота | Допустимая latency | Загрузка |
|-------|------|---------|-------------------|----------|
| **Hot path** (streaming) | `www/stream/*.php` | ~10K–100K req/min | < 50ms p99 | Минимальный bootstrap, никаких модулей |
| **Cold path** (admin) | `admin/*.php`, API | ~1–100 req/min | < 500ms p99 | Полный bootstrap, все модули |

### 10.6. Бюджет hot path

Streaming-запрос (`auth.php` → `live.php`) должен уложиться в:

```
Bootstrap:     < 5ms  (autoload + constants + DB)
Auth:          < 10ms (token + Redis + bruteforce)
Stream lookup: < 5ms  (Redis cache) | < 15ms (DB fallback)
Delivery:      < 10ms (redirect + headers)
─────────────────────────────────────
Total:         < 30ms (target) | < 50ms (max)
```

**НЕЛЬЗЯ загружать в hot path:** Router, EventDispatcher с подписчиками, ServiceContainer полный boot.
**МОЖНО:** Database (persistent), Redis (single connection), GeoIP (mmap), streaming/*.

### 10.7. Async / Queue (будущее)

Длинные операции (ffprobe, tmdb, mass import) — кандидаты на async:

```
HTTP → Service → Queue Job (Redis LPUSH) → Worker (cron) → Result
```

Без RabbitMQ/Kafka — простая Redis-очередь через `LPUSH`/`BRPOP`.

---

## 11. Стратегия миграции по рискам

### 11.1. Принцип: каждый шаг обратим

Каждое изменение следует паттерну **extract → delegate → verify → replace**:

```
1. Extract: создать новый класс
2. Delegate: старый код вызывает новый через proxy
3. Verify: система работает как раньше + новый код работает
4. Replace: обновить вызывающий код на прямые вызовы (отдельный шаг)
```

Если шаг 3 провалился — откат = удалить новый класс + убрать proxy. Система возвращается в предыдущее состояние.

### 11.2. Регрессионная стратегия

| Уровень | Что проверяется | Как проверяется | Когда |
|---------|----------------|-----------------|-------|
| **Syntax** | PHP-файлы компилируются | `php -l` на каждый изменённый файл | После каждого коммита |
| **Smoke** | Система запускается | `make new && make lb` + проверка HTTP 200 | После каждой фазы |
| **Functional** | Основные сценарии работают | Ручной checklist (создать поток, запустить, остановить) | После каждой фазы |
| **Integration** | LB-сборка работает | Деплой на тестовый LB-сервер + стриминг-тест | После фаз 1–4 |
| **Backward compat** | API не сломан | Проверка ответов API (формат JSON, коды ошибок) | После фазы 6 |

### 11.3. Dual bootstrap на переходном этапе

**Текущее состояние (фазы 1–6):** Dual bootstrap работает параллельно.

```
bootstrap.php (новый)          includes/admin.php (старый legacy)
      │                                │
      ├── autoload.php                 ├── require Database.php
      ├── ServiceContainer             ├── CoreUtilities::init()
      ├── ConfigLoader                 ├── API/ResellerAPI init
      └── новые core/ классы           └── 50 define() + global $db
```

**Правила dual bootstrap:**
1. `bootstrap.php` загружается ПЕРВЫМ в каждой точке входа
2. `includes/admin.php` загружается ПОСЛЕ — для legacy-кода, который ещё не мигрирован
3. Новые классы (`core/`, `domain/`) инициализируются через `ServiceContainer`
4. Legacy-код (`CoreUtilities`, `admin_api.php`) продолжает работать через proxy-методы
5. После полной миграции (Фаза 8) — `includes/admin.php` удаляется

### 11.4. API backward compatibility

**Правило:** Внешний API (`player.api`, `xmltv.php`, межсерверный API) не меняет формат ответов до Фазы 8.

```
Фазы 1–7: Внутренняя рефакторизация, внешний API неизменен
Фаза 8:   API v2 (опционально) с новой маршрутизацией
           API v1 продолжает работать через compatibility layer
```

### 11.5. Разделение релизов

| Релиз | Содержит | Риск |
|-------|----------|------|
| **v1.8** | Фазы 0–2 (core/ extraction, dedup) | 🟢 Низкий — proxy-методы, обратная совместимость |
| **v1.9** | Фазы 3–4 (domain/ + streaming/ extraction) | 🟡 Средний — больше перемещений, proxy покрывает |
| **v2.0** | Фазы 5–6 (modules + controllers) | 🟡 Средний — новая маршрутизация, dual bootstrap |
| **v2.1** | Фазы 7–8 (cleanup, удаление legacy) | 🔴 Высокий — удаление god-объектов, нет fallback |

### 11.6. Rollback plan

```
Если v2.0 ломает production:
1. git revert последний merge в main
2. Пересобрать: make new && make lb
3. Задеплоить предыдущую сборку
4. Post-mortem: что сломалось, почему не поймали на smoke test
```

Для v2.1 (удаление legacy) — **feature flag:**
```php
// config.ini
[migration]
use_legacy_bootstrap = false    ; true = откат на admin.php
use_legacy_api = false          ; true = откат на admin_api.php switch
```

---

## 12. Правила для контрибьюторов

> Этот раздел — краткая выжимка для тех, кто не читал весь документ. Подробности — в CONTRIBUTING.md.

### 12.1. Как добавить новый эндпоинт (admin)

1. Найти контекст в `domain/` (например `domain/Stream/`)
2. Добавить метод в `StreamService.php` (бизнес-логика)
3. Добавить SQL-метод в `StreamRepository.php` (если нужен новый запрос)
4. Добавить action в Controller или `admin_api.php` → вызов Service
5. `php -l` на изменённые файлы

### 12.2. Как добавить новую таблицу

1. Добавить миграцию в `infrastructure/install/`
2. Создать Repository в `domain/{Context}/` с CRUD-методами
3. Зарегистрировать в `autoload.php`

### 12.3. Как НЕ сломать streaming

- **Никогда** не менять `www/stream/*.php` без явного ревью
- **Никогда** не добавлять `require` тяжёлых классов в streaming bootstrap
- Не использовать EventDispatcher / Router / ServiceContainer в hot path
- Проверять latency: `< 50ms p99`

### 12.4. Правила PR

1. Один PR = одна фича/багфикс. Не смешивать рефакторинг с фичами.
2. `php -l` на все изменённые файлы перед пушем.
3. Если трогаете `core/` или `streaming/` — обязательный ревью.
4. proxy-методы помечать `// @legacy-proxy — убрать в Фазе 8`.
5. Новые классы регистрировать в `autoload.php`.

### 12.5. Простые правила (cheat sheet)

```
✗  Не пиши SQL в Controller          →  Пиши в Repository
✗  Не пиши логику в Controller       →  Пиши в Service
✗  Не добавляй global               →  Используй constructor injection
✗  Не трогай streaming без ревью   →  hot path = священная территория
✗  Не модифицируй core/ из модулей  →  Модуль вызывает, не меняет
```

---

## Приложение: Один bootstrap вместо трёх

`bootstrap.php` предоставляет класс `XC_Bootstrap` с четырьмя контекстами инициализации:

| Контекст | Что загружает | Для чего |
|----------|--------------|----------|
| `CONTEXT_MINIMAL` | autoload + constants + Logger | Скрипты, которым нужны только пути |
| `CONTEXT_CLI` | + Database + CoreUtilities | Cron-задачи, CLI-скрипты |
| `CONTEXT_STREAM` | + Database + StreamingUtilities (кэш) | Стриминг-эндпоинты (hot path) |
| `CONTEXT_ADMIN` | + Database + CoreUtilities + API + ResellerAPI + Translator + session + Redis | Админ/реselлер-панель |

```php
// public/index.php — admin/reseller entry point
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::defineStatusConstants();
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
// $db, CoreUtilities, API, ResellerAPI, Translator — всё готово
```

```php
// public/stream.php — streaming entry point (lightweight)
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM);
// Только Database + StreamingUtilities, без admin_api, без Translator
```

```php
// cli/CronJobs/StreamsCron.php — cron
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, [
    'cached'  => true,
    'redis'   => true,
    'process' => 'XC_VM[Streams]'
]);
// Database + CoreUtilities + Redis, без session и admin API
```

Один автозагрузчик. Один набор констант. Каждая точка входа вызывает `boot()` с нужным контекстом.
Новые классы миграции находятся автоматически через `XC_Autoloader` — без ручного `require_once`.
