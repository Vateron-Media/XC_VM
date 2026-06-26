# Подсистема стриминга

Подсистема стриминга обрабатывает доставку live, VOD и timeshift.
Это горячий путь (~10K-100K запросов/мин, <50 мс p99), и она использует отдельный лёгкий bootstrap, чтобы не загружать полный административный стек.

---

## Поток запроса

```text
запрос клиента
      |
nginx rewrite (/auth/{token} -> /stream/live.php?token={token})
      |
StreamingRequestBootstrap::init()
      |
StreamingBootstrap::bootstrap()
      |
LegacyInitializer::initStreaming()
      |
логика endpoint (live.php / vod.php / timeshift.php)
      |
ShutdownHandler::handle()
```

nginx переписывает все стриминговые URL на PHP-точки входа в `www/stream/`:

| Шаблон URL | Точка входа | Назначение |
| --- | --- | --- |
| `/auth/{token}` | `live.php` | Доставка live-потока |
| `/vauth/{token}` | `vod.php` | Доставка видео по запросу |
| `/tsauth/{token}` | `timeshift.php` | Воспроизведение архива/timeshift |
| `/hls/{token}` | `segment.php` | Доставка HLS-сегментов |
| `/key/{token}` | `key.php` | Ключ шифрования AES-128 |
| `/subauth/{token}` | `subtitle.php` | Доставка субтитров |

---

## Структура каталогов

```
src/Streaming/
├── StreamingBootstrap.php
├── AsyncFileOperations.php
├── TimeshiftClient.php
├── Auth/
│   ├── StreamAuth.php
│   └── StreamAuthMiddleware.php
├── Balancer/
│   └── ProxySelector.php
├── Codec/
│   ├── FFmpegCommand.php
│   ├── FfmpegPaths.php
│   └── FFprobeRunner.php
├── Delivery/
│   ├── HLSGenerator.php
│   ├── OffAirHandler.php
│   ├── SegmentReader.php
│   ├── SignalSender.php
│   └── StreamRedirector.php
├── Health/
│   └── ProcessChecker.php
├── Lifecycle/
│   └── ShutdownHandler.php
└── Protection/
    └── ConnectionLimiter.php

src/www/stream/
├── init.php          # Прослойка legacy bootstrap (устарела)
├── auth.php          # Шлюз валидации токена
├── live.php          # Доставка live-стриминга
├── vod.php           # Доставка VOD
├── timeshift.php     # Воспроизведение архива/timeshift
├── segment.php       # Доставка HLS-сегментов
├── key.php           # Доставка ключа шифрования
├── subtitle.php      # Доставка субтитров
├── thumb.php         # Доставка превью
└── rtmp.php          # Endpoint публикации RTMP
```

---

## Bootstrap-конвейер

### 1. StreamingRequestBootstrap::init()

Файл: `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php`

Действия по порядку:

1. Загрузить коды ошибок, обработчик, пути, конфигурацию, бинарники.
2. Защита от флуда (только HTTP): проверить `FLOOD_TMP_PATH . 'block_' . $rIP`.
3. Загрузить настройки из файлового кэша (`CACHE_TMP_PATH . 'settings'`).
4. Верификация хоста (только HTTP): проверить по `allowed_domains`.
5. Инициализировать логгер.
6. Fail-closed гейт: вернуть 404, если настройки отсутствуют (кроме `/status`).
7. Вызвать `StreamingBootstrap::bootstrap()`.

### 2. StreamingBootstrap::bootstrap()

Файл: `src/Streaming/StreamingBootstrap.php`

```php
public static function bootstrap($rFilename, $rSettings)
```

Классифицирует endpoint:

- **Probe endpoints:** `probe`, `player_api` (лёгкая нагрузка)
- **Default endpoints:** `live`, `thumb`, `subtitle`, `timeshift`, `vod`, `status`
- **Privileged endpoints:** `rtmp`, `portal`

Загружает `AsyncFileOperations.php` и `DatabaseHandler.php`, сохраняет настройки в `$GLOBALS['rSettings']` и данные доступа в `$GLOBALS['rAccess']`, затем вызывает `LegacyInitializer::initStreaming()`.

Возвращает экземпляр базы данных `$db` (используется legacy-точками входа).

### 3. LegacyInitializer::initStreaming()

Файл: `src/Core/Init/LegacyInitializer.php`

Заполняет глобальные переменные из кэша:

- `$GLOBALS['rSettings']`, `$GLOBALS['rServers']`, `$GLOBALS['rBouquets']`
- `$GLOBALS['rBlockedUA']`, `$GLOBALS['rBlockedISP']`, `$GLOBALS['rBlockedIPs']`
- `$GLOBALS['rAllowedIPs']`, `$GLOBALS['rProxies']`, `$GLOBALS['rSegmentSettings']`
- `$GLOBALS['rFFMPEG_CPU']`, `$GLOBALS['rFFMPEG_GPU']`, `$GLOBALS['rFFPROBE']`

Подключается к базе данных/Redis в зависимости от `$rSettings['redis_handler']`.

> **Важно:** Стриминговый путь читает исключительно из файлового кэша. Он не обращается к базе данных за настройками или поиском пользователей в нормальной работе.

---

## Аутентификация по токену

Файл: `src/Streaming/Auth/StreamAuthMiddleware.php`

```php
StreamAuthMiddleware::decryptToken($rToken, $rSettings, $rServers, $rIP): array
```

Содержимое токена:

| Поле | Описание |
| --- | --- |
| `username` | Имя пользователя линии |
| `password` | Пароль линии |
| `stream_id` | ID целевого потока |
| `expires` | Временная метка истечения токена |
| `channel_info` | Метаданные потока (on_demand, proxy, pid) |
| `user_info` | Права пользователя (max_connections, is_restreamer) |
| `country_code` | Код страны GeoIP |
| `video_codec` | Запрошенный видеокодек |

Валидация:

1. Расшифровать токен с помощью `live_streaming_pass`.
2. Проверить срок: `$rTokenData['expires'] < time() - $rServers[SERVER_ID]['time_offset']`.
3. Вернуть разобранные данные токена или сгенерировать ошибку.

Заголовки ответа устанавливаются через `StreamAuthMiddleware::sendStreamHeaders()`:

```text
Access-Control-Allow-Origin: *
X-XSS-Protection: 0
X-Content-Type-Options: nosniff
Alt-Svc: h3-29, h3-T051, h3-Q050 (подсказки HTTP/3)
```

---

## Доставка потока

### Live (live.php)

Основная точка доставки (~650 строк):

1. Расшифровать токен через `StreamAuthMiddleware::decryptToken()`.
2. Определить сервер/прокси: `StreamAuth::checkAccess()` + `ProxySelector::availableProxy()`.
3. Применить ограничения подключений: `StreamAuth::validateConnections()`.
4. Создать запись о подключении: `ConnectionTracker::createConnection()`.
5. Доставить контент:
   - **M3U8:** `HLSGenerator::generateHLS()` → клиент получает сегменты через `segment.php`.
   - **TS:** Зацикленные сегменты с использованием `AsyncFileOperations::awaitFileExists()`.
6. Каждые 5 минут: обновить настройки, обновить `hls_last_read`, проверить жив ли процесс.
7. При выходе: `ShutdownHandler::handle()` → закрыть запись подключения.

### VOD (vod.php)

Та же логика аутентификации, что и у live. Читает из `VOD_PATH` вместо `STREAMS_PATH`.

### Timeshift (timeshift.php)

Отдаёт архивные сегменты. Использует `TimeshiftClient` для определения архивного файла.

---

## Управление подключениями

### ConnectionTracker

Управляет состоянием активных подключений. Бэкенд выбирается через `$rSettings['redis_handler']`:

**Redis (предпочтительно для масштаба):**

- Подключения хранятся в sorted sets:
  - `LINE#{identity}` — подключения пользователя
  - `STREAM#{stream_id}` — подключения к потоку
  - `SERVER#{server_id}` — подключения на сервере

**MySQL (резервный вариант):**

- Таблица: `lines_live` с полями: `activity_id`, `user_id`, `stream_id`, `server_id`, `uuid`, `pid`, `hls_end`

Ключевые методы:

```php
ConnectionTracker::createConnection($data)
ConnectionTracker::updateConnection($connection, $changes, 'open'|'close')
ConnectionTracker::getConnection($uuid)
ConnectionTracker::getLineConnections($user_id)
ConnectionTracker::getCapacity()
```

### ConnectionLimiter

Файл: `src/Streaming/Protection/ConnectionLimiter.php`

Применяет ограничения подключений на пользователя при превышении `max_connections`:

| Приоритет | Критерий | Действие |
| --- | --- | --- |
| 2 | Тот же IP + тот же User-Agent | Убить первым |
| 1 | Тот же IP (любой UA) | Убить следующим |
| 0 | Любое подключение | Убить как fallback |

Настройки:

- `disallow_2nd_ip_con` — требовать один IP на пользователя
- `ip_subnet_match` — сопоставлять по подсети /24 вместо точного IP
- `restrict_same_ip` — возвращать ошибку при несовпадении IP вместо убийства

### ShutdownHandler

Файл: `src/Streaming/Lifecycle/ShutdownHandler.php`

Зарегистрирован через `register_shutdown_function()`. При завершении процесса PHP:

1. Закрыть запись подключения в `lines_live` или Redis.
2. Удалить tmp-файлы по пути `CONS_TMP_PATH . $uuid`.
3. Убрать on-demand поток из очереди, если применимо.

---

## Балансировка нагрузки

### Выбор сервера (StreamAuth::checkAccess)

Файл: `src/Streaming/Auth/StreamAuth.php`

```php
public static function checkAccess($rUserInfo, $rUserIP, $rCountryCode, $rUserISP = ''): int|false
```

Алгоритм:

1. Получить доступные серверы: `server_online == true`, `server_type == 0`, `online_clients < total_clients`.
2. Отсортировать по загрузке (по возрастанию) — наименее загруженные первыми.
3. Применить GeoIP-маршрутизацию (если `enable_geoip == 1`):
   - Точное совпадение по стране → выбрать сразу.
   - `geoip_type == 'strict'` → исключить несоответствующие.
   - Иначе → присвоить весовой приоритет.
4. Применить ISP-маршрутизацию (если `enable_isp == 1`): та же логика, что и GeoIP.
5. Вернуть сервер с наименьшей загрузкой из группы с наивысшим приоритетом.

### Выбор прокси (ProxySelector::availableProxy)

Файл: `src/Streaming/Balancer/ProxySelector.php`

```php
public static function availableProxy($rProxies, $rCountryCode, $rUserISP = ''): int|null
```

Тот же алгоритм, что и `StreamAuth::checkAccess()`, применённый к списку прокси-серверов.

---

## Rate limiting и защита от флуда

Три уровня:

### 1. nginx (уровень подключения)

```nginx
limit_req_zone $binary_remote_addr zone=one:30m rate=20r/s;
limit_req zone=one burst=8;
```

20 запросов/секунду на IP с burst-окном на 8 запросов. Скользящее окно 30 минут.

### 2. StreamingRequestBootstrap (блокировка IP)

```php
if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
    http_response_code(403);
    exit();
}
```

Файловая блокировка IP. Блокировочные файлы создаёт вышестоящая логика детекции флуда.

### 3. ConnectionLimiter (на пользователя)

Применяется после валидации токена. Ограничивает одновременные потоки на пользователя на основе `max_connections`.

---

## Шифрование HLS

Файл: `src/Streaming/Delivery/HLSGenerator.php`

```php
public static function generateHLS($rSettings, $rM3U8, $rUsername, $rPassword,
    $rStreamID, $rUUID, $rIP, ...): string|false
```

Когда `encrypt_hls == true`:

1. Сгенерировать токен AES-128 ключа из IP + StreamID + соль.
2. Заменить IV содержимым `STREAMS_PATH . $rStreamID . '_.iv'`.
3. Зашифровать ссылку на каждый сегмент: `IP/StreamID/Segment/UUID/SERVER_ID/VideoCodec/OnDemand`.
4. Заменить имена сегментов на `/hls/{encrypted_token}`.

Доставка ключа происходит через `key.php` с использованием того же механизма токенов.

---

## Производительность

Ключевые проектные решения для пропускной способности и задержки:

| Особенность | Механизм |
| --- | --- |
| Неблокирующее ожидание файла | `AsyncFileOperations::awaitFileExists()` использует inotify (Linux) или оптимизированный polling |
| Нулевая CPU-нагрузка при ожидании | `time_nanosleep()` через `AsyncFileOperations::efficientSleep()` |
| Буферизация nginx | 128 буферов по 32 КБ на запрос |
| Пул подключений | Redis (предпочтительно) или persistent MySQL |
| Чтения только из кэша | Настройки и данные пользователей читаются из файлового кэша, без запросов к БД |
| Ранний выход | Мониторинг `connection_status()` каждые 5 секунд для определения отключения клиента |
| Обновление настроек | Каждые 5 минут (300 с), чтобы подхватывать изменения конфигурации без перезапуска |

---

## Пути файловой системы

```text
STREAMS_PATH        = /home/xc_vm/www/stream/
CONS_TMP_PATH       = /home/xc_vm/tmp/
CACHE_TMP_PATH      = /home/xc_vm/tmp/cache/
FLOOD_TMP_PATH      = /home/xc_vm/tmp/flood/
SIGNALS_PATH        = /home/xc_vm/tmp/signals/
VIDEO_PATH          = /home/xc_vm/www/video/
ARCHIVE_PATH        = /home/xc_vm/www/archive/
VOD_PATH            = /home/xc_vm/www/vod/
```

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Streaming/StreamingBootstrap.php` | основной bootstrap стриминга |
| `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` | инициализация на HTTP-уровне |
| `src/Streaming/Auth/StreamAuth.php` | выбор сервера и валидация подключений |
| `src/Streaming/Auth/StreamAuthMiddleware.php` | расшифровка токена и заголовки ответа |
| `src/Streaming/Balancer/ProxySelector.php` | выбор прокси-сервера |
| `src/Streaming/Protection/ConnectionLimiter.php` | ограничения подключений на пользователя |
| `src/Streaming/Delivery/HLSGenerator.php` | генерация плейлиста M3U8 |
| `src/Streaming/Delivery/SegmentReader.php` | извлечение сегментов из плейлистов |
| `src/Streaming/Delivery/StreamRedirector.php` | доступность потока и маршрутизация серверов |
| `src/Streaming/AsyncFileOperations.php` | неблокирующие утилиты файловой системы |
| `src/Streaming/Lifecycle/ShutdownHandler.php` | очистка подключения при выходе |
| `src/Domain/Stream/ConnectionTracker.php` | состояние подключений в Redis/MySQL |
| `src/Core/Init/LegacyInitializer.php` | настройка глобальных переменных для стриминга |
