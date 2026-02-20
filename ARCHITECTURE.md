# XC_VM — Архитектурный план реорганизации `/src`

## Содержание

1. [Диагноз текущего состояния](#1-диагноз-текущего-состояния)
2. [Принципы целевой архитектуры](#2-принципы-целевой-архитектуры)
3. [Целевая структура `/src`](#3-целевая-структура-src)
4. [Описание компонентов](#4-описание-компонентов)
5. [Карта миграции: откуда → куда](#5-карта-миграции-откуда--куда)
6. [Система модулей](#6-система-модулей)
7. [Границы ядра и модулей](#7-границы-ядра-и-модулей)
8. [Порядок миграции](#8-порядок-миграции)

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

## 2. Принципы целевой архитектуры

### 2.1. Инверсия зависимостей

Код верхнего уровня (UI, CLI, HTTP endpoints) зависит от абстракций ядра, а не от конкретных реализаций. Ядро не знает о существовании модулей.

### 2.2. Единая точка bootstrap — множественные контексты

Вместо дублированных `init.php` / `constants.php` / `stream/init.php` — один bootstrap с конфигурируемым набором загружаемых сервисов.

### 2.3. Границы через интерфейсы, а не через `global`

Каждый сервис получает зависимости через конструктор или контейнер. Ни один компонент не использует `global`.

### 2.4. Модуль = изолированная директория

Модуль — это директория с известным контрактом. Его можно удалить, и система продолжит работать (деградируя в функциональности, но не падая).

### 2.5. Open-core без лицензионных ограничений в ядре

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
├── domain/                          # ═══ БИЗНЕС-ЛОГИКА (сущности и сервисы) ═══
│   ├── Stream/
│   │   ├── StreamEntity.php         # Модель потока (поля, валидация)
│   │   ├── StreamRepository.php     # SQL-запросы для потоков (из admin_api.php)
│   │   ├── StreamService.php        # Бизнес-логика: create, update, reorder
│   │   ├── StreamMonitor.php        # Мониторинг потока (из cli/monitor.php)
│   │   ├── CategoryEntity.php
│   │   └── CategoryRepository.php
│   │
│   ├── Vod/
│   │   ├── MovieEntity.php
│   │   ├── MovieRepository.php
│   │   ├── MovieService.php
│   │   ├── SeriesEntity.php
│   │   ├── SeriesRepository.php
│   │   ├── SeriesService.php
│   │   ├── EpisodeEntity.php
│   │   └── EpisodeRepository.php
│   │
│   ├── Line/
│   │   ├── LineEntity.php           # Подписка/линия
│   │   ├── LineRepository.php
│   │   ├── LineService.php
│   │   ├── PackageEntity.php
│   │   └── PackageRepository.php
│   │
│   ├── Device/
│   │   ├── MagEntity.php
│   │   ├── MagRepository.php
│   │   ├── EnigmaEntity.php
│   │   └── EnigmaRepository.php
│   │
│   ├── User/
│   │   ├── UserEntity.php           # Админ/реselлер
│   │   ├── UserRepository.php
│   │   ├── GroupEntity.php
│   │   └── GroupRepository.php
│   │
│   ├── Server/
│   │   ├── ServerEntity.php
│   │   ├── ServerRepository.php
│   │   ├── ServerService.php        # Мониторинг, health-check
│   │   └── ServerStats.php
│   │
│   ├── Bouquet/
│   │   ├── BouquetEntity.php
│   │   ├── BouquetRepository.php
│   │   └── BouquetService.php       # Ordering, mapping
│   │
│   ├── Epg/
│   │   ├── EpgSource.php
│   │   ├── EpgRepository.php
│   │   └── EpgService.php
│   │
│   └── Ticket/
│       ├── TicketEntity.php
│       └── TicketRepository.php
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
│   │   ├── LoadBalancer.php         # Серверное распределение
│   │   └── RedirectStrategy.php
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
├── interfaces/                      # ═══ ТОЧКИ ВХОДА (UI, API, CLI) ═══
│   ├── Http/
│   │   ├── public/                  # Web root (nginx document root)
│   │   │   ├── index.php            # Единая точка входа (front controller)
│   │   │   ├── stream.php           # Точка входа для стриминга
│   │   │   └── assets/              # CSS/JS/images/fonts
│   │   │       ├── admin/
│   │   │       └── player/
│   │   │
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── StreamController.php
│   │   │   │   ├── LineController.php
│   │   │   │   ├── VodController.php
│   │   │   │   ├── ServerController.php
│   │   │   │   ├── SettingsController.php
│   │   │   │   ├── UserController.php
│   │   │   │   ├── BouquetController.php
│   │   │   │   ├── EpgController.php
│   │   │   │   └── ... (по одному на доменную область)
│   │   │   │
│   │   │   ├── Reseller/
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── LineController.php
│   │   │   │   └── ... (только разрешённые действия)
│   │   │   │
│   │   │   └── Api/
│   │   │       ├── AdminApiController.php
│   │   │       ├── ResellerApiController.php
│   │   │       ├── PlayerApiController.php   # Публичный player API
│   │   │       └── InternalApiController.php  # Межсерверный API (текущий www/api.php)
│   │   │
│   │   └── Views/
│   │       ├── layouts/
│   │       │   ├── admin.php        # Header + footer шаблон для admin
│   │       │   └── reseller.php     # Header + footer шаблон для reseller
│   │       │
│   │       ├── admin/
│   │       │   ├── dashboard.php
│   │       │   ├── streams/
│   │       │   │   ├── list.php
│   │       │   │   ├── edit.php
│   │       │   │   └── view.php
│   │       │   ├── lines/
│   │       │   ├── vod/
│   │       │   ├── servers/
│   │       │   └── settings/
│   │       │
│   │       ├── reseller/
│   │       │   ├── dashboard.php
│   │       │   └── ...
│   │       │
│   │       └── partials/
│   │           ├── modals.php
│   │           ├── topbar.php
│   │           └── table.php
│   │
│   ├── Cli/
│   │   ├── Commands/
│   │   │   ├── StartupCommand.php       # cli/startup.php
│   │   │   ├── WatchdogCommand.php      # cli/watchdog.php
│   │   │   ├── MonitorCommand.php       # cli/monitor.php
│   │   │   ├── CacheHandlerCommand.php  # cli/cache_handler.php
│   │   │   ├── QueueCommand.php         # cli/queue.php
│   │   │   ├── SignalsCommand.php       # cli/signals.php
│   │   │   ├── ScannerCommand.php       # cli/scanner.php
│   │   │   ├── MigrateCommand.php       # Из status
│   │   │   └── ToolsCommand.php         # Из tools (rescue, access, ports и т.д.)
│   │   └── CronJobs/
│   │       ├── StreamsCron.php
│   │       ├── ServersCron.php
│   │       ├── CacheCron.php
│   │       ├── EpgCron.php
│   │       ├── CleanupCron.php
│   │       ├── BackupsCron.php
│   │       ├── StatsCron.php
│   │       ├── LogsCron.php            # lines_logs + streams_logs
│   │       ├── VodCron.php
│   │       └── TmdbCron.php
│   │
│   └── Player/                         # Встроенный web-плеер
│       ├── PlayerController.php
│       └── views/
│           └── ... (текущий player/)
│
├── modules/                         # ═══ ОПЦИОНАЛЬНЫЕ МОДУЛИ ═══
│   ├── ministra/                    # Ministra/Stalker middleware (текущий ministra/)
│   │   ├── module.json              # Manifest: name, version, dependencies
│   │   ├── MinistraModule.php       # Точка входа модуля (implements ModuleInterface)
│   │   ├── PortalHandler.php
│   │   └── assets/
│   │
│   ├── plex/                        # Plex integration (текущий settings_plex, plex_add)
│   │   ├── module.json
│   │   ├── PlexModule.php
│   │   ├── PlexService.php
│   │   └── config/
│   │
│   ├── tmdb/                        # TMDb metadata (текущий libs/TMDb, crons/tmdb)
│   │   ├── module.json
│   │   ├── TmdbModule.php
│   │   ├── TmdbService.php
│   │   └── TmdbCron.php
│   │
│   ├── watch/                       # Watch/Recording (текущие watch, record файлы)
│   │   ├── module.json
│   │   ├── WatchModule.php
│   │   └── ...
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
| `Container/` | DI-контейнер | `global $db`, статические `CoreUtilities::$rSettings` |
| `Util/` | Утилиты без состояния | Функции разбросанные по CoreUtilities, admin.php |

**Ключевое правило:** `core/` не знает о существовании `domain/`, `streaming/`, `modules/`, `interfaces/`. Зависимости направлены только внутрь ядра.

### 4.2. `domain/` — Бизнес-логика

**Ответственность:** Сущности, репозитории, бизнес-сервисы. Каждый поддомен — отдельная директория.

**Паттерн для каждого поддомена:**

```
domain/Stream/
  ├── StreamEntity.php       # Данные сущности + валидация полей
  ├── StreamRepository.php   # SQL-запросы (SELECT/INSERT/UPDATE/DELETE)
  └── StreamService.php      # Бизнес-логика (create с проверками, mass-edit и т.д.)
```

**Откуда берётся код:**
- `admin_api.php` (6981 строк) → разбивается на ~10 Repository + 10 Service файлов
- `reseller_api.php` (1204 строк) → те же Repository, но ограниченные Service-методы
- `admin.php` → процедурные `getUserInfo()`, `getSeriesList()`, `updateSeries()` → в соответствующие Repository

**Зависимости:** `domain/` зависит от `core/` (Database, Cache, Events). Не зависит от `interfaces/` или `modules/`.

### 4.3. `streaming/` — Стриминг-движок

**Ответственность:** Весь hot path доставки видео. Выделен отдельно от `domain/` потому что:
- Критичен к производительности (нельзя загружать всю бизнес-логику)
- Имеет собственный лёгкий bootstrap
- Работает на уровне байтов/сегментов, а не сущностей

**Откуда берётся код:**
- `StreamingUtilities.php` (1992 стр.) → распределяется по подкаталогам
- `www/stream/auth.php` (800 стр.) → `Auth/TokenAuth.php` + `Auth/StreamAuth.php` + `Protection/`
- `www/stream/live.php` (708 стр.) → `Delivery/LiveDelivery.php`
- `includes/cli/monitor.php` (565 стр.) → `domain/Stream/StreamMonitor.php` (с рефакторингом goto)
- `CoreUtilities` методы FFmpeg → `Codec/FFmpegCommand.php`
- `ts.php` → `Codec/TsParser.php`

### 4.4. `interfaces/` — Точки входа

**Ответственность:** Получение запроса, вызов доменных сервисов, формирование ответа. Никакой бизнес-логики.

**HTTP:**
- Один front controller `public/index.php` + `Router`
- Контроллеры только маршрутизируют вызовы к `domain/` сервисам
- Шаблоны (`Views/`) — чистый HTML с минимумом PHP-вставок
- Admin и Reseller — разные контроллеры, общие шаблоны

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
| `processBouquet()` | `domain/Bouquet/BouquetService.php` |
| `processUser()` | `domain/User/UserService.php` |
| `processGroup()` | `domain/User/GroupService.php` |
| `processLogin()` | `core/Auth/Authenticator.php` |
| `massEditStreams()` | `domain/Stream/StreamService::massEdit()` |
| `checkMinimumRequirements()` | Валидация в каждом Entity |

### 5.3. God-bootstrap `admin.php` (4448 строк)

| Блок | Целевой файл |
|------|-------------|
| `session_start()`, session config | `core/Auth/SessionManager.php` |
| 50+ `define()` статусов | `constants.php` |
| `Database` creation | `core/Container/ServiceContainer.php` |
| `CoreUtilities::init()` | `bootstrap.php` |
| `$rCountryCodes`, `$rCountries` | `resources/data/countries.php` |
| `$rPermissions` | `resources/data/permissions.php` → `core/Auth/Authorization.php` |
| `getUserInfo()` | `domain/User/UserRepository.php` |
| `getSeriesList()`, `updateSeries()` | `domain/Vod/SeriesRepository.php` |
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
  interfaces/Http/Controllers/Admin/StreamController.php  — маршрутизация
  domain/Stream/StreamRepository.php                       — данные
  interfaces/Http/Views/admin/streams/list.php             — шаблон
```

### 5.5. Дублирование admin/ ↔ reseller/

```
БЫЛО:
  admin/header.php (675 строк)    +  reseller/header.php (284 строки)
  admin/footer.php (805 строк)    +  reseller/footer.php
  admin/session.php               +  reseller/session.php
  admin/functions.php             +  reseller/functions.php

СТАЛО:
  interfaces/Http/Views/layouts/admin.php      — единый layout
  interfaces/Http/Views/layouts/reseller.php   — наследует admin layout с ограничениями
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
   - Использовать любые сервисы из core/ через ServiceContainer
   - Использовать Repository/Service из domain/ (read-only или через интерфейсы)
   - Регистрировать свои маршруты, команды, кроны
   - Подписываться на события ядра
   - Иметь свои assets, views, конфиги

❌ Модуль НЕ МОЖЕТ:
   - Модифицировать файлы core/ или domain/
   - Напрямую обращаться к базе данных мимо Repository
   - Зависеть от другого модуля без явной декларации в module.json
   - Переопределять маршруты или сервисы ядра
   - Добавлять ограничения лицензирования в ядро
```

### 6.3. Расширяемость через события

```php
// Ядро публикует события:
$dispatcher->dispatch(new StreamStartedEvent($streamId));
$dispatcher->dispatch(new LineCreatedEvent($lineId));
$dispatcher->dispatch(new UserLoginEvent($userId));

// Модуль подписывается:
class FingerprintModule implements ModuleInterface {
    public function getEventSubscribers(): array {
        return [
            StreamStartedEvent::class => [FingerprintApplier::class, 'onStreamStarted'],
        ];
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
                    │  interfaces/ │   ← HTTP, CLI, Player
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

## 8. Порядок миграции

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
```
CoreUtilities::saveLog()           → core/Logging/FileLogger.php
StreamingUtilities::clientLog()    → core/Logging/DatabaseLogger.php
Контракт                          → core/Logging/LoggerInterface.php
```
- ✅ Извлечь `saveLog()` (стр. 489–504) → `FileLogger::log()`
- ✅ Извлечь `clientLog()` (StUtil стр. 1169–1177) → `DatabaseLogger::log()`
- ✅ Написать `LoggerInterface` с методом `log($type, $message, $extra)`
- ✅ Proxy: `CoreUtilities::saveLog()` → `FileLogger::log()`
- ✅ Proxy: `StreamingUtilities::clientLog()` → `DatabaseLogger::log()`

#### Шаг 1.7.2 — Системная информация ✅
```
CoreUtilities::getStats()              → core/Util/SystemInfo.php
CoreUtilities::getTotalCPU()           →
CoreUtilities::getMemory()             →
CoreUtilities::getUptime()             →
CoreUtilities::getNetworkInterfaces()  →
CoreUtilities::getVideoDevices()       →
CoreUtilities::getAudioDevices()       →
CoreUtilities::getIO()                 →
CoreUtilities::getGPUInfo()            →
```
- ✅ Извлечь 9 методов (стр. 534–671, 827–852, 1338–1344, 4621–4665) → `SystemInfo`
- ✅ Proxy: каждый вызов `CoreUtilities::getStats()` → `SystemInfo::getStats()`

#### Шаг 1.7.3 — Защита от брутфорса (Flood/Bruteforce) ✅
```
CoreUtilities::checkFlood()            → core/Auth/BruteforceGuard.php
CoreUtilities::checkBruteforce()       →
CoreUtilities::checkAuthFlood()        →
CoreUtilities::truncateAttempts()      →
StreamingUtilities::checkFlood()       → делегирует туда же
StreamingUtilities::checkBruteforce()  →
StreamingUtilities::checkAuthFlood()   →
StreamingUtilities::truncateAttempts() →
```
- ✅ Извлечь 4 метода из CoreUtilities (стр. 709–826) → `BruteforceGuard`
- ✅ 4 дублированных метода в StreamingUtilities (стр. 215–394) → proxy на тот же `BruteforceGuard`
- ✅ Первая дедупликация CoreUtilities ↔ StreamingUtilities

#### Шаг 1.7.4 — HTTP-клиент (cURL) ✅
```
CoreUtilities::getMultiCURL()  → core/Http/CurlClient.php
CoreUtilities::getURL()        →
CoreUtilities::serverRequest() →
```
- ✅ Извлечь 3 метода (стр. 373–428, 1408–1442, 3568–3578) → `CurlClient`

#### Шаг 1.7.5 — Событийная система ✅
```
Новый файл → core/Events/EventDispatcher.php
Новый файл → core/Events/EventInterface.php
```
- ✅ Написать простой EventDispatcher (publish/subscribe)
- ✅ Пока без подписчиков — подготовка к модульной системе

#### Шаг 1.7.6 — RBAC / Авторизация ✅
```
admin.php::hasPermissions()         → core/Auth/Authorization.php
admin.php::hasResellerPermissions() →
$rAdvPermissions (из admin.php)     → resources/data/permissions.php
```
- ✅ Извлечь `hasPermissions()` (стр. 582–619) и `hasResellerPermissions()` (стр. 575–581)
- ✅ Массив `$rAdvPermissions` → `resources/data/permissions.php`
- ✅ Proxy: глобальные функции в `admin.php` → `Authorization::check()`

#### Шаг 1.7.7 — Аутентификация ✅
```
API::processLogin()            → core/Auth/Authenticator.php
ResellerAPI::processLogin()    →
```
- ✅ Извлечь `processLogin()` из `admin_api.php` (стр. 1399–1478) → `Authenticator::login()`
- ✅ Извлечь `processLogin()` из `reseller_api.php` → `Authenticator::resellerLogin()`
- ✅ Proxy: `API::processLogin()` → `Authenticator::login()`

#### Шаг 1.7.8 — Утилиты изображений ✅
```
CoreUtilities → core/Util/ImageUtils.php
admin.php::getAdminImage() →
```
- ✅ Извлечь методы работы с изображениями (resize, thumbnail, URL-валидация)
- ✅ `StreamingUtilities::validateImage()` (стр. 1355) → `ImageUtils::validateURL()`
- ✅ `admin.php::getAdminImage()` (стр. 871–883) → `ImageUtils::resize()`

---

### Фаза 2: Дедупликация CoreUtilities ↔ StreamingUtilities

Перед извлечением domain/ нужно устранить форк. 60+ методов дублированы.

#### Шаг 2.1 — Инвентаризация дубликатов

Актуальная инвентаризация (20.02.2026): **53 общих метода**.

| Метод | CoreUtilities (стр.) | StreamingUtilities (стр.) | Целевой шаг |
|-------|---------------------|--------------------------|-------------|
| `addToQueue()` | 3419 | 1437 | 2.3 |
| `base64url_decode()` | 4089 | 1794 | 2.4 |
| `base64url_encode()` | 4077 | 1782 | 2.4 |
| `checkAuthFlood()` | 3200 | 224 | 2.4 |
| `checkBlockedUAs()` | 2718 | 1015 | 2.4 |
| `checkBruteforce()` | 507 | 220 | 2.4 |
| `checkFlood()` | 503 | 216 | 2.4 |
| `checkISP()` | 2972 | 1164 | 2.4 |
| `checkServer()` | 2980 | 1173 | 2.4 |
| `cleanGlobals()` | 376 | 155 | 2.4 |
| `closeConnection()` | 2567 | 711 | 2.3 |
| `connectRedis()` | 3508 | 1546 | 2.2 |
| `decryptData()` | 4113 | 1818 | 2.4 |
| `encryptData()` | 4101 | 1806 | 2.4 |
| `formatTitle()` | 3454 | 1480 | 2.4 |
| `generateString()` | 436 | 1469 | 2.4 |
| `getAdultCategories()` | 2490 | 1536 | 2.4 |
| `getBouquetMap()` | 3355 | 1402 | 2.4 |
| `getCache()` | 296 | 133 | 2.5 |
| `getCapacity()` | 3203 | 240 | 2.3 |
| `getCategories()` | 514 | 1248 | 2.4 |
| `getConnections()` | 3297 | 1650 | 2.3 |
| `getDiffTimezone()` | 115 | 1531 | 2.4 |
| `getDomainName()` | 3764 | 1683 | 2.2 |
| `getIPInfo()` | 2983 | 1176 | 2.4 |
| `getISP()` | 2957 | 1147 | 2.4 |
| `getMainID()` | 3411 | 1429 | 2.3 |
| `getNearest()` | 3844 | 1673 | 2.4 |
| `getPlaylistSegments()` | 2682 | 1305 | 2.4 |
| `getProxies()` | 4059 | 1729 | 2.3 |
| `getPublicURL()` | 3097 | 1207 | 2.2 |
| `getUserIP()` | 2915 | 1144 | 2.4 |
| `getUserInfo()` | 2320 | 816 | 2.4 |
| `init()` | 23 | 22 | 2.5 |
| `isMonitorRunning()` | 2745 | 1032 | 2.4 |
| `isProcessRunning()` | 2841 | 1100 | 2.4 |
| `isProxy()` | 174 | 230 | 2.4 |
| `isRunning()` | 2998 | 1195 | 2.4 |
| `isStreamRunning()` | 2812 | 1055 | 2.4 |
| `parseCleanKey()` | 414 | 193 | 2.4 |
| `parseCleanValue()` | 423 | 203 | 2.4 |
| `parseIncomingRecursively()` | 394 | 173 | 2.4 |
| `redisSignal()` | 3578 | 1664 | 2.3 |
| `removeFromQueue()` | 3437 | 1455 | 2.3 |
| `setSignal()` | 3505 | 986 | 2.2 |
| `sortChannels()` | 3467 | 1493 | 2.4 |
| `sortSeries()` | 3486 | 1512 | 2.4 |
| `startMonitor()` | 1320 | 1119 | 2.4 |
| `startProxy()` | 1324 | 1123 | 2.4 |
| `truncateAttempts()` | 511 | 237 | 2.4 |
| `updateConnection()` | 3522 | 1595 | 2.3 |
| `validateImage()` | 3094 | 1192 | 2.4 |
| `writeOfflineActivity()` | 2663 | 779 | 2.4 |

Инвентаризация выше — рабочий baseline для декомпозиции в шагах 2.2–2.5.

#### Шаг 2.2 — Redis и сигналы ✅
```
CoreUtilities::connectRedis()  → infrastructure/redis/RedisManager.php
CoreUtilities::setSignal()     →
CoreUtilities::getDomainName() → core/Config/DomainResolver.php
StreamingUtilities::closeRedis() →
```
- ✅ Извлечь Redis-подключение в `RedisManager` (единый для обоих)
- ✅ Proxy: оба класса вызывают `RedisManager::connect()`
- ✅ `getDomainName()` → `DomainResolver::resolve()` (чистая утилита конфигурации)

#### Шаг 2.3 — Трекинг подключений (Redis)
```
CoreUtilities::updateConnection()      → domain/Stream/ConnectionTracker.php
CoreUtilities::redisSignal()           →
CoreUtilities::getUserConnections()    →
CoreUtilities::getServerConnections()  →
CoreUtilities::getStreamConnections()  →
CoreUtilities::getRedisConnections()   →
CoreUtilities::getFirstConnection()    →
CoreUtilities::getConnections()        →
CoreUtilities::getMainID()             →
CoreUtilities::addToQueue()            →
CoreUtilities::removeFromQueue()       →
CoreUtilities::getProxies()            →
CoreUtilities::getCapacity()           →
StreamingUtilities::getConnection()    →
StreamingUtilities::createConnection() →
StreamingUtilities::updateConnection() →
StreamingUtilities::getConnections()   →
```
- ✅ ~17 методов вынесены в `ConnectionTracker` (работа с Redis-ключами подключений)
- ✅ Proxy: оба god-объекта делегируют в `ConnectionTracker`

#### Шаг 2.4 — Справочные данные и сортировки
```
CoreUtilities::sortChannels()    → domain/Stream/StreamSorter.php
CoreUtilities::sortSeries()      →
CoreUtilities::formatTitle()     →
CoreUtilities::getNearest()      →
CoreUtilities::getBouquetMap()   → domain/Bouquet/BouquetMapper.php
CoreUtilities::getCategories()   → domain/Stream/CategoryRepository.php
CoreUtilities::getBouquets()     → domain/Bouquet/BouquetRepository.php
CoreUtilities::getServers()      → domain/Server/ServerRepository.php
CoreUtilities::getSettings()     → вынести кэширование настроек
```
- ✅ Сортировки и форматирование вынесены в `StreamSorter`
- ✅ Данные-справочники вынесены в `BouquetMapper` / `CategoryRepository` / `BouquetRepository` / `ServerRepository` / `SettingsRepository`

#### Шаг 2.5 — Дедупликация init()
```
CoreUtilities::init()            → упрощение, делегирование в ServiceContainer
StreamingUtilities::init()       → упрощение, делегирование в ServiceContainer
```
- ✅ Оба `init()` стали тонкими обёртками
- ✅ Общая логика вынесена в `core/Init/LegacyInitializer.php`
- ✅ Состояние init синхронизируется через `ServiceContainer`

---

### Фаза 3: Извлечение domain/ — бизнес-логика

Теперь god-объекты уже частично разгружены. Извлекаем entity/repository/service по доменам.

#### Шаг 3.1 — domain/Stream/ (самый крупный домен)

**Из `admin_api.php`:**
```
API::processStream()     (стр. 5468–5933, 466 строк) → domain/Stream/StreamService.php
API::processChannel()    (стр. 522–730, 209 строк)   → domain/Stream/ChannelService.php
API::massEditStreams()    (стр. 4935–5234, 300 строк) → StreamService::massEdit()
API::massEditChannels()  (стр. 5235–5467, 233 строки) → ChannelService::massEdit()
API::processCategory()   (стр. 5965–5997, 33 строки) → domain/Stream/CategoryService.php
API::orderCategories()   (стр. 5934–5949)             → CategoryService::reorder()
API::setChannelOrder()   (стр. 499–521)               → ChannelService::setOrder()
API::moveStreams()        (стр. 5998–6045)             → StreamService::move()
API::replaceDNS()         (стр. 6046–6059)             → StreamService::replaceDNS()
API::massDeleteStreams()  (стр. 1479–1496)             → StreamService::massDelete()
```

**Из `admin.php`:**
```
getStream()              (стр. 4014) → domain/Stream/StreamRepository.php
getStreamStats()         (стр. 4082) →
getStreamErrors()        (стр. 884)  →
getStreamPIDs()          (стр. 3810) →
getStreamOptions()       (стр. 4210) →
getStreamSys()           (стр. 4228) →
getCategories()          (стр. 3505) → domain/Stream/CategoryRepository.php
getNextOrder()           (стр. 4356) →
parseM3U()               (стр. 968)  → domain/Stream/M3UParser.php
```

**Из `CoreUtilities`:**
```
CoreUtilities::startMonitor()    (стр. 1697) → domain/Stream/StreamProcess.php
CoreUtilities::stopStream()      (стр. 1640) →
CoreUtilities::startProxy()      (стр. 1701) →
CoreUtilities::startThumbnail()  (стр. 1705) →
CoreUtilities::queueChannel()    (стр. 1455) →
CoreUtilities::createChannel()   (стр. 1466) →
CoreUtilities::createChannelItem() (стр. 1470) →
CoreUtilities::updateStream()    (стр. 3833) →
CoreUtilities::updateStreams()   (стр. 3844) →
CoreUtilities::deleteCache()     (стр. 1443) →
```

**Паттерн:** По 3–5 методов за шаг. Proxy в старых файлах.

#### Шаг 3.2 — domain/Vod/ (фильмы + сериалы + эпизоды)

**Из `admin_api.php`:**
```
API::processMovie()      (стр. 1643–2110, 468 строк) → domain/Vod/MovieService.php
API::massEditMovies()    (стр. 2111–2369, 259 строк) → MovieService::massEdit()
API::massDeleteMovies()  (стр. 1497–1514)             → MovieService::massDelete()
API::importMovies()      (стр. 3903–4105, 203 строки) → MovieService::import()

API::processSeries()     (стр. 4106–4225, 120 строк) → domain/Vod/SeriesService.php
API::massEditSeries()    (стр. 4226–4347, 122 строки) → SeriesService::massEdit()
API::massDeleteSeries()  (стр. 1605–1622)             → SeriesService::massDelete()
API::importSeries()      (стр. 3719–3902, 184 строки) → SeriesService::import()

API::processEpisode()    (стр. 821–1074, 254 строки) → domain/Vod/EpisodeService.php
API::massEditEpisodes()  (стр. 1075–1259, 185 строк) → EpisodeService::massEdit()
API::massDeleteEpisodes()(стр. 1623–1642)             → EpisodeService::massDelete()
```

**Из `admin.php`:**
```
getSeriesList()          (стр. 135) → domain/Vod/SeriesRepository.php
updateSeries()           (стр. 164) →
updateSeriesAsync()      (стр. 207) →
getSimilarMovies()       (стр. 4104) → domain/Vod/MovieRepository.php
getSimilarSeries()       (стр. 4116) → domain/Vod/SeriesRepository.php
deleteMovieFile()        (стр. 3717) → MovieService::deleteFile()
generateSeriesPlaylist() (стр. 4367) → SeriesService::generatePlaylist()
```

**Из `CoreUtilities`:**
```
CoreUtilities::stopMovie()     (стр. 1709) → domain/Vod/MovieProcess.php
CoreUtilities::queueMovie()    (стр. 1719) →
CoreUtilities::queueMovies()   (стр. 1727) →
```

#### Шаг 3.3 — domain/Line/ (подписки/линии)
```
API::processLine()       (стр. 6812–6943, 132 строки) → domain/Line/LineService.php
API::massEditLines()     (стр. 6283–6399, 117 строк)  → LineService::massEdit()
API::massDeleteLines()   (стр. 1515–1532)              → LineService::massDelete()

admin.php::deleteLines() (стр. 980)                    → domain/Line/LineRepository.php
CoreUtilities::deleteLine()    (стр. 3855)             → LineService::delete()
CoreUtilities::deleteLines()   (стр. 3858)             →
CoreUtilities::updateLine()    (стр. 3861)             → LineService::update()
CoreUtilities::updateLines()   (стр. 3872)             →
```

#### Шаг 3.4 — domain/User/ (пользователи, группы, реселлеры)
```
API::processUser()       (стр. 3579–3669)  → domain/User/UserService.php
API::massEditUsers()     (стр. 6731–6811)  → UserService::massEdit()
API::massDeleteUsers()   (стр. 1533–1550)  → UserService::massDelete()
API::processGroup()      (стр. 1260–1350)  → domain/User/GroupService.php

admin.php::getUserInfo()           (стр. 122) → domain/User/UserRepository.php
admin.php::getUser()               (стр. 4024) →
admin.php::getRegisteredUser()     (стр. 4034) →
admin.php::getRegisteredUsers()    (стр. 4243) →
admin.php::getResellers()          (стр. 532)  →
admin.php::getDirectReports()      (стр. 545)  →
admin.php::getSubUsers()           (стр. 854)  →
admin.php::getParent()             (стр. 842)  →
admin.php::getMemberGroups()       (стр. 620)  → domain/User/GroupRepository.php
admin.php::getMemberGroup()        (стр. 3914) →
admin.php::deleteGroup()           (стр. 3548) →

StreamingUtilities::getUserInfo()  (стр. 970)  → domain/User/UserRepository (streaming-вариант)
```

#### Шаг 3.5 — domain/Device/ (MAG + Enigma)
```
API::processMAG()         (стр. 2433–2624) → domain/Device/MagService.php
API::massEditMags()       (стр. 6400–6589) → MagService::massEdit()
API::massDeleteMags()     (стр. 1569–1586) → MagService::massDelete()

API::processEnigma()      (стр. 2625–2812) → domain/Device/EnigmaService.php
API::massEditEnigmas()    (стр. 6590–6730) → EnigmaService::massEdit()
API::massDeleteEnigmas()  (стр. 1587–1604) → EnigmaService::massDelete()

admin.php::syncDevices()  (стр. 371)       → domain/Device/DeviceSync.php
```

#### Шаг 3.6 — domain/Server/
```
API::processServer()     (стр. 4348–4516) → domain/Server/ServerService.php
API::processProxy()      (стр. 4517–4587) → domain/Server/ProxyService.php
API::installServer()     (стр. 4588–4683) → domain/Server/ServerInstaller.php
API::orderServers()      (стр. 5950–5964) → ServerService::reorder()

admin.php::getAllServers()       (стр. 3745) → domain/Server/ServerRepository.php
admin.php::getStreamingServers() (стр. 3760) →
admin.php::getProxyServers()    (стр. 3784) →
admin.php::getFreeSpace()       (стр. 239)  →
admin.php::getStreamsRamdisk()   (стр. 259)  →
admin.php::killPID()            (стр. 275)  →
admin.php::getRTMPStats()       (стр. 281)  →
admin.php::deleteServer()       (стр. 3620) →
admin.php::probeSource()        (стр. 4181) →
admin.php::getSSLLog()          (стр. 3893) →
admin.php::checksource()        (стр. 3886) →
admin.php::freeTemp()           (стр. 4173) →
admin.php::freeStreams()         (стр. 4177) →
```

#### Шаг 3.7 — domain/Bouquet/
```
API::processBouquet()     (стр. 156–244)  → domain/Bouquet/BouquetService.php
API::reorderBouquet()     (стр. 379–395)  → BouquetService::reorder()
API::sortBouquets()       (стр. 456–498)  → BouquetService::sort()

admin.php::getBouquets()       (стр. 3954) → domain/Bouquet/BouquetRepository.php
admin.php::getBouquetOrder()   (стр. 3969) →
admin.php::getUserBouquets()   (стр. 3939) →
admin.php::scanBouquets()      (стр. 4269) → BouquetService::scan()
admin.php::scanBouquet()       (стр. 4279) → BouquetService::scanOne()
```

#### Шаг 3.8 — domain/Epg/
```
API::processEPG()    (стр. 731–764)  → domain/Epg/EpgService.php

admin.php::getchannelepg()   (стр. 4185) → domain/Epg/EpgRepository.php
admin.php::getEPG()          (стр. 4200) →
admin.php::findEPG()         (стр. 3525) →

CoreUtilities::getEPG()      (стр. 4502) →
CoreUtilities::getEPGs()     (стр. 4517) →
CoreUtilities::getProgramme()(стр. 4524) →
CoreUtilities::searchEPG()   (стр. 672)  →
```

#### Шаг 3.9 — domain/Settings/ + domain/Ticket/
```
API::editSettings()          (стр. 4684) → domain/Settings/SettingsService.php
API::editBackupSettings()    (стр. 4754) →
API::editCacheCron()         (стр. 4798) →
API::editAdminProfile()      (стр. 396)  → domain/User/ProfileService.php

API::submitTicket()          (стр. 6060) → domain/Ticket/TicketService.php
```

#### Шаг 3.10 — domain/Security/ (IP, ISP, UA, RTMP)
```
API::processISP()     (стр. 1351) → domain/Security/BlocklistService.php
API::processUA()      (стр. 6110) →
API::blockIP()        (стр. 428)  →
API::processRTMPIP()  (стр. 3670) →

admin.php::getBlockedIPs()  (стр. 3984) → domain/Security/BlocklistRepository.php
admin.php::getRTMPIPs()     (стр. 3999) →

StreamingUtilities::checkBlockedUAs()   (стр. 1178) →
StreamingUtilities::checkISP()          (стр. 1327) →
StreamingUtilities::checkServer()       (стр. 1336) →
CoreUtilities::getBlockedUA()           (стр. 180)  →
CoreUtilities::getBlockedIPs()          (стр. 194)  →
CoreUtilities::getBlockedISP()          (стр. 211)  →
CoreUtilities::getBlockedServers()      (стр. 225)  →
CoreUtilities::getProxyIPs()            (стр. 155)  →
```

#### Шаг 3.11 — domain/Auth/ (коды, HMAC, пакеты)
```
API::processCode()   (стр. 245–322)  → domain/Auth/CodeService.php
API::processHMAC()   (стр. 323–378)  → domain/Auth/HMACService.php
API::processPackage()(стр. 2370–2432) → domain/Line/PackageService.php

admin.php::getActiveCodes()    (стр. 663)  → domain/Auth/CodeRepository.php
admin.php::updateCodes()       (стр. 680)  →
admin.php::getCurrentCode()    (стр. 725)  →
admin.php::getHMACTokens()     (стр. 635)  → domain/Auth/HMACRepository.php
admin.php::getHMACToken()      (стр. 650)  →
admin.php::deletePackage()     (стр. 3577) → domain/Line/PackageRepository.php

StreamingUtilities::validateHMAC() (стр. 1143) → domain/Auth/HMACValidator.php
```

#### Шаг 3.12 — Playlist-генератор (большой метод)
```
CoreUtilities::generatePlaylist()  (стр. 892–1314, 423 строки!) → domain/Stream/PlaylistGenerator.php
CoreUtilities::generateCron()      (стр. 1315–1337)              → domain/Stream/CronGenerator.php
```
- `generatePlaylist()` — самый длинный метод в CoreUtilities
- Дробится внутри на: live playlist, vod playlist, series playlist, radio playlist

---

### Фаза 4: Извлечение streaming/ (hot path)

После Фазы 3 в StreamingUtilities останется только стриминг-специфичный код.

#### Шаг 4.1 — streaming/Auth/
```
www/stream/auth.php (799 строк) → streaming/Auth/StreamAuth.php
                                 → streaming/Auth/TokenAuth.php
                                 → streaming/Auth/DeviceLock.php

StreamingUtilities::validateHMAC()           → (уже в domain/Auth/HMACValidator)
StreamingUtilities::F4221e28760B623E()       → streaming/Auth/StreamAuth::checkAccess()
StreamingUtilities::validateConnections()    → streaming/Auth/StreamAuth::validateConnections()
StreamingUtilities::closeConnections()       → streaming/Protection/ConnectionLimiter.php
StreamingUtilities::closeConnection()        →
StreamingUtilities::closeRTMP()              →
```

#### Шаг 4.2 — streaming/Delivery/
```
www/stream/live.php (707 строк) → streaming/Delivery/LiveDelivery.php

StreamingUtilities::redirectStream()     (стр. 397–525)  → streaming/Delivery/StreamRedirector.php
StreamingUtilities::showVideoServer()    (стр. 559–622)  →
StreamingUtilities::getOffAirVideo()     (стр. 526–558)  → streaming/Delivery/OffAirHandler.php
StreamingUtilities::availableProxy()     (стр. 692–748)  → streaming/Balancer/ProxySelector.php
StreamingUtilities::getStreamingURL()    (стр. 1910–1947)→
StreamingUtilities::generateHLS()        (стр. 1516–1560)→ streaming/Delivery/HLSGenerator.php
StreamingUtilities::getLLODSegments()    (стр. 1441)     → streaming/Delivery/SegmentReader.php
StreamingUtilities::sendSignal()         (стр. 1290)     → streaming/Delivery/SignalSender.php
```

#### Шаг 4.3 — streaming/Codec/
```
CoreUtilities::probeStream()       (стр. 1588) → streaming/Codec/FFprobeRunner.php
CoreUtilities::parseFFProbe()      (стр. 1603) →
CoreUtilities::createChannelItem() (стр. 1470) → streaming/Codec/FFmpegCommand.php (сборка команд)
CoreUtilities::extractSubtitle()   (стр. 1575) → streaming/Codec/SubtitleExtractor.php
```

#### Шаг 4.4 — streaming/Protection/
```
StreamingUtilities::closeConnections() → streaming/Protection/ConnectionLimiter.php (из 4.1)
StreamingUtilities::isRunning()        → streaming/Health/HealthChecker.php

CoreUtilities::isPIDRunning()    (стр. 1399) → streaming/Health/ProcessChecker.php
CoreUtilities::isPIDsRunning()   (стр. 1375) →
CoreUtilities::checkPID()        (стр. 1680) →

admin.php::getWatchdog()         (стр. 3899) → streaming/Health/WatchdogMonitor.php
```

#### Шаг 4.5 — streaming/StreamingBootstrap.php
- Заменить `www/stream/init.php` лёгким bootstrap
- Загружает: autoload → constants → Database → Redis
- НЕ загружает: CoreUtilities, API, Translator, session

---

### Фаза 5: Вынесение модулей

Каждый модуль извлекается атомарно — система продолжает работать без него.

#### Шаг 5.1 — modules/plex/
```
API::editPlexSettings()          → modules/plex/PlexService.php
API::processPlexSync()           →
admin.php::getPlexServers()      →
admin.php::getPlexSections()     →
admin.php::forcePlex()           →
CoreUtilities::getPlexToken()    → modules/plex/PlexAuth.php
CoreUtilities::getPlexLogin()    →
CoreUtilities::checkPlexToken()  →
CoreUtilities::cachePlexToken()  →
CoreUtilities::getPlexServerCacheKey() →
CoreUtilities::getCachedPlexToken()    →
admin/settings_plex.php          → modules/plex/views/
admin/plex_add.php               →
admin/plex.php                   →
crons/plex.php                   → modules/plex/PlexCron.php
includes/cli/plex_item.php       →
config/plex/                     → modules/plex/config/
```

#### Шаг 5.2 — modules/watch/
```
API::editWatchSettings()      → modules/watch/WatchService.php
API::processWatchFolder()     →
API::scheduleRecording()      → modules/watch/RecordingService.php
admin.php::getWatchFolders()  →
admin.php::getWatchCategories() →
admin.php::forceWatch()       →
admin.php::getRecordings()    →
admin.php::deleteRecording()  →
admin/settings_watch.php      → modules/watch/views/
admin/watch.php               →
admin/watch_add.php           →
admin/watch_output.php        →
admin/record.php              →
crons/watch.php               → modules/watch/WatchCron.php
includes/cli/watch_item.php   →
```

#### Шаг 5.3 — modules/tmdb/
```
admin.php::getMovieTMDB()   → modules/tmdb/TmdbService.php
admin.php::getSeriesTMDB()  →
admin.php::getSeasonTMDB()  →
includes/libs/TMDb/         → modules/tmdb/lib/
crons/tmdb.php              → modules/tmdb/TmdbCron.php
crons/tmdb_popular.php      →
```

#### Шаг 5.4 — modules/ministra/
```
ministra/portal.php (2155 строк) → modules/ministra/PortalHandler.php
ministra/*.js                     → modules/ministra/assets/
```

#### Шаг 5.5 — modules/fingerprint/ + modules/theft-detection/ + modules/magscan/
```
admin/fingerprint.php      → modules/fingerprint/
admin/theft_detection.php  → modules/theft-detection/
admin/magscan_settings.php → modules/magscan/
```

#### Шаг 5.6 — ModuleInterface + загрузчик модулей
```
Новый файл → core/Module/ModuleInterface.php
Новый файл → core/Module/ModuleLoader.php
Новый файл → config/modules.php
```

---

### Фаза 6: Контроллеры и Views (admin/reseller)

Выполняется ПОСЛЕ извлечения domain/ — контроллеры вызывают сервисы.

#### Шаг 6.1 — Единый layout
```
admin/header.php (675 стр.) + reseller/header.php (284 стр.)
                            → interfaces/Http/Views/layouts/admin.php

admin/footer.php (804 стр.) + reseller/footer.php (719 стр.)
                            → interfaces/Http/Views/layouts/footer.php
                            + assets/admin/js/*.js (вынесенный inline JS)
```

#### Шаг 6.2 — Router + Front Controller
```
Новый → core/Http/Router.php
Новый → interfaces/Http/public/index.php (front controller)
```

#### Шаг 6.3 — Конвертация admin-страниц (группами по 5–10)
```
Группа A (потоки):    admin/streams.php, stream.php, stream_view.php, created_channel.php, created_channels.php
Группа B (VOD):       admin/movies.php, movie.php, series.php, serie.php, episodes.php, episode.php
Группа C (линии):     admin/lines.php, line.php, mags.php, mag.php, enigmas.php, enigma.php
Группа D (серверы):   admin/servers.php, server.php, server_view.php, server_install.php
Группа E (букеты):    admin/bouquets.php, bouquet.php, bouquet_order.php, bouquet_sort.php
Группа F (настройки): admin/settings.php, database.php, profile.php, edit_profile.php
Группа G (остальное): admin/dashboard.php, epgs.php, epg.php, groups.php, group.php ...
```
Каждая группа: SQL → Repository (уже есть), логика → Controller, HTML → View.

#### Шаг 6.4 — Объединение admin/reseller
```
reseller/table.php (1836 стр.) → переиспользует admin-контроллеры с RBAC-фильтром
reseller/api.php (997 стр.)    → ResellerApiController (ограниченный AdminApiController)
```

---

### Фаза 7: Миграция admin.php bootstrap

`admin.php` (4448 стр.) — после извлечения всех функций в domain/.

#### Шаг 7.1 — Вынос inline-данных
```
$rCountryCodes, $rCountries  → resources/data/countries.php
$rTimezones                  → resources/data/timezones.php
$rMacTypes                   → resources/data/mac_types.php
```

#### Шаг 7.2 — Замена процедурного bootstrap
```
admin.php::initDatabase()        → ServiceContainer::get('db')
admin.php::CoreUtilities::init() → bootstrap.php
admin.php::session_start()       → SessionManager::start()
admin.php::$rPermissions         → Authorization::getPermissions()
admin.php::Translator init       → ServiceContainer::get('translator')
```

#### Шаг 7.3 — Удаление admin.php
- Все функции уже в domain/ (proxy-вызовы)
- Все данные в resources/data/
- Bootstrap через `bootstrap.php`
- `admin.php` → тонкий require-файл (< 50 строк) или удаляется

---

### Фаза 8: Очистка и финализация

#### Шаг 8.1 — Удаление proxy-методов
- Обновить все вызывающие места на прямые вызовы новых классов
- Удалить proxy-методы из CoreUtilities, StreamingUtilities, admin_api.php

#### Шаг 8.2 — Удаление god-объектов
- `CoreUtilities.php` → удалить (все методы разнесены)
- `StreamingUtilities.php` → удалить (все методы разнесены)
- Если что-то осталось — вынести в утилитный класс

#### Шаг 8.3 — Ревизия core/
- Убедиться: `core/` не содержит бизнес-логики
- Убедиться: `domain/` не знает об `interfaces/`
- Убедиться: удаление любого модуля не ломает систему

#### Шаг 8.4 — Рефакторинг cli/monitor.php
- Удалить goto-лейблы (`label235`, `label592`)
- Переписать с нормальным control flow
- Вынести в `interfaces/Cli/Commands/MonitorCommand.php`

---

### Сводка: объём работы по файлам

| Исходный файл | Строк | Сколько целевых классов | Фазы |
|----------------|-------|------------------------|------|
| `CoreUtilities.php` | 4847 | ~20 классов | 1.7, 2, 3, 4, 5 |
| `admin_api.php` | 6981 | ~25 классов (Service) | 3.1–3.12, 5 |
| `admin.php` | 4448 | ~15 классов (Repository + данные) | 3, 5, 7 |
| `StreamingUtilities.php` | 1992 | ~10 классов | 2, 4 |
| `admin/post.php` | 1946 | распределяется по контроллерам | 6 |
| `admin/table.php` | 6003 | распределяется по контроллерам | 6 |
| `includes/api/admin/table.php` | 6868 | распределяется по контроллерам | 6 |
| `www/stream/auth.php` | 799 | 3 класса (Auth) | 4 |
| `www/stream/live.php` | 707 | 3 класса (Delivery) | 4 |
| Прочие admin/*.php | ~100 файлов | Controller + View | 6 |

### Рекомендуемый порядок работы

```
Фаза 1.7  →  Фаза 2  →  Фаза 3.1–3.4  →  Фаза 4.1–4.3  →  Фаза 3.5–3.12
                                                              ↓
Фаза 5.1–5.5  →  Фаза 5.6  →  Фаза 6.1–6.4  →  Фаза 7  →  Фаза 8
```

Каждый шаг (1.7.1, 1.7.2 ...) — это одна рабочая сессия (1–3 часа).
Каждая фаза (1.7, 2, 3...) — это неделя–две постепенной работы.
После каждого шага система полностью работоспособна.

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
// interfaces/Http/public/index.php — admin/reseller entry point
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::defineStatusConstants();
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
// $db, CoreUtilities, API, ResellerAPI, Translator — всё готово
```

```php
// interfaces/Http/public/stream.php — streaming entry point (lightweight)
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM);
// Только Database + StreamingUtilities, без admin_api, без Translator
```

```php
// interfaces/Cli/CronJobs/StreamsCron.php — cron
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
