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
9. [Транзакции и производительность](#9-транзакции-и-производительность)
10. [Правила для контрибьюторов](#10-правила-для-контрибьюторов)

> **План миграции** (фазы 0–15, стратегия рисков, порядок выполнения) вынесен в [MIGRATION.md](MIGRATION.md).

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
reseller/*.php ───┤──→ includes/admin.php ──→ CoreUtilities (4847)
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
│   │   ├── EPG.php                  # XML-парсер EPG (XmlStringStreamer), MAIN-only
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
- `crons/*.php` → `cli/CronJobs/`, каждый крон — один файл (**crons/ удалён**, Phase 12.8)
- `includes/cli/*.php` → `cli/Commands/` (**includes/cli/ удалён**, Phase 12.6)

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
- `includes/langs/*.ini` → `langs/` ✅ ВЫПОЛНЕНО
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

### 8.2. Проблема LB-сборки (РЕШЕНО ✅ — Phase 9.1)

Старый `Makefile` собирал LB из фиксированного списка `LB_FILES`, который не включал новые архитектурные директории. После фаз 0–8 это означало, что LB-сборка не содержала `core/`, `domain/`, `streaming/`, `infrastructure/`, `resources/`, а также `autoload.php` и `bootstrap.php`.

**Решение (Phase 9.1):** `LB_FILES` разделён на `LB_DIRS` + `LB_ROOT_FILES`, добавлены все необходимые директории и admin-only исключения. Подробности — §8.3.

| Директория / файл | Нужен LB? | Статус |
|----|---|---|
| `autoload.php` | ✅ ДА | ✅ Добавлен в `LB_ROOT_FILES` |
| `bootstrap.php` | ✅ ДА | ✅ Добавлен в `LB_ROOT_FILES` |
| `core/` | ✅ ДА | ✅ Добавлен в `LB_DIRS` |
| `domain/` | ⚠ ЧАСТИЧНО | ✅ В `LB_DIRS` + исключения `User/`, `Device/`, `Auth/` |
| `streaming/` | ✅ ДА | ✅ Добавлен в `LB_DIRS` |
| `infrastructure/` | ✅ ДА | ✅ Добавлен в `LB_DIRS` |
| `resources/` | ⚠ ЧАСТИЧНО | ✅ В `LB_DIRS` + исключения `langs/`, `libs/` |
| `public/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `modules/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `admin/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `ministra/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `player/` | ❌ НЕТ | ✅ Корректно отсутствует |
| `reseller/` | ❌ НЕТ | ✅ Корректно отсутствует |

### 8.3. Реализованная конфигурация Makefile ✅

#### `LB_DIRS` + `LB_ROOT_FILES` (вместо единого `LB_FILES`)

```makefile
# Директории (копируются через git ls-files | grep "^src/$dir/")
LB_DIRS := bin config content core crons domain includes \
    infrastructure resources signals streaming tmp www

# Root-файлы (копируются через git ls-files --error-unmatch)
LB_ROOT_FILES := autoload.php bootstrap.php service status update
```

> **Почему раздельно:** grep `"^src/$item/"` с trailing `/` никогда не матчит файлы (`autoload.php`, `service`, `status`, `update`). Поэтому root-файлы копируются отдельным циклом через `git ls-files --error-unmatch`.

#### `LB_DIRS_TO_REMOVE` (admin-only директории)

```makefile
LB_DIRS_TO_REMOVE := \
    bin/install \
    bin/redis \
    bin/nginx/conf/codes \
    includes/api \
    includes/libs/resources \
    domain/User \
    domain/Device \
    domain/Auth \
    resources/langs \
    resources/libs
```

#### `lb_copy_files` — два цикла

1. **Директории:** `for lb_item in $(LB_DIRS)` → `git ls-files | grep "^src/$$lb_item/"` → copy
2. **Root-файлы:** `for root_file in $(LB_ROOT_FILES)` → `git ls-files --error-unmatch "src/$$root_file"` → copy

#### `lb_update_copy_files` — каскадная проверка

```bash
# Для каждого changed file:
for lb_item in $(LB_DIRS); do    # 1. проверка по директории
    grep -q "^$$lb_item/"
done
for root_file in $(LB_ROOT_FILES); do  # 2. проверка по root-файлу
    [ "$$rel_path" = "$$root_file" ]
done
```

#### `set_permissions` — новые директории

```makefile
# core/ domain/ streaming/ infrastructure/ resources/ → dirs:755, files:644
@for arch_dir in core domain streaming infrastructure resources; do \
    if [ -d "$(TEMP_DIR)/$$arch_dir" ]; then \
        find "$(TEMP_DIR)/$$arch_dir" -type d -exec chmod 755 {} +; \
        find "$(TEMP_DIR)/$$arch_dir" -type f -exec chmod 644 {} +; \
    fi; \
done

# autoload.php, bootstrap.php → 644
chmod 0644 $(TEMP_DIR)/autoload.php 2>/dev/null || true
chmod 0644 $(TEMP_DIR)/bootstrap.php 2>/dev/null || true
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

console.php cron:* ──→ bootstrap.php (CONTEXT_CLI)
                         └──→ те же зависимости + domain/Stream/*

> **Примечание:** `crons/*.php` удалены (Phase 12.8). Все вызовы идут через `console.php cron:{name}`.
```

### 8.5. Правила для разработки с учётом LB

1. ~~**Proxy-методы в `CoreUtilities`/`StreamingUtilities`** должны быть безопасны для LB~~ — **РЕШЕНО:** god-объекты удалены (Phase 8), все зависимости через прямые вызовы.

2. **Makefile обновлён синхронно с миграцией** ✅ — `LB_DIRS` включает все новые директории. При добавлении новых root-директорий — обновлять `LB_DIRS`.

3. **Тестирование LB-сборки** — после каждой фазы миграции нужно проверять:
   ```bash
   make new && make lb
   # Убедиться, что все нужные файлы присутствуют
   # Убедиться, что admin-only файлы отсутствуют
   ```

4. **domain/ частично нужен LB** — нельзя целиком исключать `domain/`. Исключаются только admin-specific поддомены (`User/`, `Ticket/`, `Device/`).

5. **modules/ полностью исключается из LB** — все текущие модули (ministra, plex, tmdb, watch, fingerprint, theft-detection, magscan) — это admin-функциональность. В будущем, если появится LB-specific модуль, его нужно будет явно добавить.

### 8.6. Makefile обновлён ✅ (Phase 9.1)

Все 5 изменений применены:
1. `LB_FILES` → `LB_DIRS` (14 директорий) + `LB_ROOT_FILES` (5 файлов)
2. `lb_copy_files` — добавлен второй цикл для root-файлов
3. `lb_update_copy_files` — каскадная проверка (dirs → root files)
4. `LB_DIRS_TO_REMOVE` — 6 новых admin-only исключений
5. `set_permissions` — корректные права на `core/`, `domain/`, `streaming/`, `infrastructure/`, `resources/`, `autoload.php`, `bootstrap.php`

---

## 9. Транзакции и производительность

### 9.1. Кто управляет транзакциями

**Правило:** Транзакцией управляет **Service**. Контроллер и Repository не открывают транзакции.

```
Controller ──→ Service ──→ Repository + Infrastructure
                  │
                  ├── beginTransaction()
                  ├── ... бизнес-операции ...
                  ├── commit()
                  └── (rollback при исключении)
```


### 9.2. Паттерн транзакции

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

### 9.3. Границы по контексту

| Контекст | Транзакция | Кто управляет | Пример |
|----------|-----------|---------------|--------|
| **Admin CRUD** | Одна операция = одна транзакция | Service | `StreamService::create()` |
| **Mass edit** | Весь batch = одна транзакция | Service | `StreamService::massEdit()` |
| **Import** | Chunk по 100 записей | Service | `StreamService::importM3U()` |
| **Cron** | Каждая итерация = отдельная транзакция | CronJob | `ServersCron::processServer()` |
| **Streaming** | ❌ Нет транзакций | — | Hot path не мутирует через транзакции |

### 9.4. Внешние процессы

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

### 9.5. Два режима работы системы

| Режим | Путь | Частота | Допустимая latency | Загрузка |
|-------|------|---------|-------------------|----------|
| **Hot path** (streaming) | `www/stream/*.php` | ~10K–100K req/min | < 50ms p99 | Минимальный bootstrap, никаких модулей |
| **Cold path** (admin) | `admin/*.php`, API | ~1–100 req/min | < 500ms p99 | Полный bootstrap, все модули |

### 9.6. Бюджет hot path

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

### 9.7. Async / Queue (будущее)

Длинные операции (ffprobe, tmdb, mass import) — кандидаты на async:

```
HTTP → Service → Queue Job (Redis LPUSH) → Worker (cron) → Result
```

Без RabbitMQ/Kafka — простая Redis-очередь через `LPUSH`/`BRPOP`.

---

## 10. Правила для контрибьюторов

> Этот раздел — краткая выжимка для тех, кто не читал весь документ. Подробности — в CONTRIBUTING.md.

### 10.1. Как добавить новый эндпоинт (admin)

1. Найти контекст в `domain/` (например `domain/Stream/`)
2. Добавить метод в `StreamService.php` (бизнес-логика)
3. Добавить SQL-метод в `StreamRepository.php` (если нужен новый запрос)
4. Добавить action в Controller или `admin_api.php` → вызов Service
5. `php -l` на изменённые файлы

### 10.2. Как добавить новую таблицу

1. Добавить миграцию в `infrastructure/install/`
2. Создать Repository в `domain/{Context}/` с CRUD-методами
3. Зарегистрировать в `autoload.php`

### 10.3. Как НЕ сломать streaming

- **Никогда** не менять `www/stream/*.php` без явного ревью
- **Никогда** не добавлять `require` тяжёлых классов в streaming bootstrap
- Не использовать EventDispatcher / Router / ServiceContainer в hot path
- Проверять latency: `< 50ms p99`

### 10.4. Правила PR

1. Один PR = одна фича/багфикс. Не смешивать рефакторинг с фичами.
2. `php -l` на все изменённые файлы перед пушем.
3. Если трогаете `core/` или `streaming/` — обязательный ревью.
4. proxy-методы помечать `// @legacy-proxy — убрать в Фазе 8`.
5. Новые классы регистрировать в `autoload.php`.

### 10.5. Простые правила (cheat sheet)

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
