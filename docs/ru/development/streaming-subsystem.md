# Подсистема потоковой передачи

Подсистема потоковой передачи обрабатывает доставку в реальном времени, VOD и timeshift.
Это быстрый путь (~10-100 тыс. запросов в минуту, <50 мс p99), и он использует отдельный облегченный bootstrap, чтобы избежать загрузки всего стека администратора.

---

## Поток запросов

```text
client request
      |
nginx rewrite (/auth/{token} -> /stream/live.php?token={token})
      |
StreamingRequestBootstrap::init()
      |
StreamingBootstrap::bootstrap()
      |
LegacyInitializer::initStreaming()
      |
endpoint logic (live.php / vod.php / timeshift.php)
      |
ShutdownHandler::handle()
```

nginx переписывает все URL-адреса потоковой передачи на PHP точки входа в соответствии с `Public/stream/`:

|Шаблон URL-адреса|Точка входа|Цель|
| --- | --- | --- |
| `/auth/{token}` | `live.php` |Прямая трансляция|
| `/vauth/{token}` | `vod.php` |Доставка видео по запросу|
| `/tsauth/{token}` | `timeshift.php` |Архив/timeshift воспроизведение|
| `/hls/{token}` | `segment.php` |HLS сегментная доставка|
| `/key/{token}` | `key.php` |Ключ шифрования AES-128|
| `/subauth/{token}` | `subtitle.php` |Передача субтитров|

---

## Расположение каталога

```
src/Streaming/
├── StreamingBootstrap.php
├── AsyncFileOperations.php
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
│   └── StreamRedirector.php
├── Fanout/
│   └── FanoutClient.php
├── Health/
│   └── ProcessChecker.php
├── Lifecycle/
│   └── ShutdownHandler.php
└── Protection/
    └── ConnectionLimiter.php

src/Public/stream/
├── index.php         # Entry router for the stream endpoints
├── auth.php          # Token validation gateway
├── live.php          # Live streaming delivery
├── vod.php           # VOD delivery
├── timeshift.php     # Archive/timeshift playback
├── segment.php       # HLS segment delivery
├── key.php           # Encryption key delivery
├── subtitle.php      # Subtitle delivery
├── thumb.php         # Thumbnail delivery
├── probe.php         # Stream probe / off-air status
└── rtmp.php          # RTMP publishing endpoint
```

---

## Конвейер начальной загрузки

### 1. StreamingRequestBootstrap::init()

Файл: `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php`

Действия в порядке:

1. Загружайте коды ошибок, обработчик, пути, конфигурацию, двоичные файлы.
2. Защита от наводнений (только HTTP): проверьте наличие `FLOOD_TMP_PATH . 'block_' . $rIP`.
3. Загрузите настройки из файлового кэша (`CACHE_TMP_PATH . 'settings'`).
4. Проверка хоста (только HTTP): проверка на соответствие `allowed_domains`.
5. Инициализируйте регистратор.
6. Аварийно закрытый шлюз: возвращает 404, если настройки отсутствуют (кроме `/status`).
7. Вызовите `StreamingBootstrap::bootstrap()`.

### 2. Потоковый загрузчик::bootstrap()

Файл: `src/Streaming/StreamingBootstrap.php`

```php
public static function bootstrap($rFilename, $rSettings)
```

Классифицирует конечную точку:

- **Конечные точки зондирования:** `probe`, `player_api` ( небольшая нагрузка)
- **Конечные точки по умолчанию:** `live`, `thumb`, `subtitle`, `timeshift`, `vod`, `status`
- **Привилегированные конечные точки:** `rtmp`, `portal`

Загружает `AsyncFileOperations.php` и `DatabaseHandler.php`, сохраняет настройки в `$GLOBALS['rSettings']` и получает доступ к данным в `$GLOBALS['rAccess']`, затем вызывает `LegacyInitializer::initStreaming()`.

Возвращает экземпляр базы данных `$db` (используемый устаревшими точками входа).

### 3. LegacyInitializer::Инициализация потока()

Файл: `src/Core/Init/LegacyInitializer.php`

Заполняет глобальные переменные из кэша:

- `$GLOBALS['rSettings']`, `$GLOBALS['rServers']`, `$GLOBALS['rBouquets']`
- `$GLOBALS['rBlockedUA']`, `$GLOBALS['rBlockedISP']`, `$GLOBALS['rBlockedIPs']`
- `$GLOBALS['rAllowedIPs']`, `$GLOBALS['rProxies']`, `$GLOBALS['rSegmentSettings']`
- `$GLOBALS['rFFMPEG_CPU']`, `$GLOBALS['rFFMPEG_GPU']`, `$GLOBALS['rFFPROBE']`

Подключается к базе данных/Redis на основе `$rSettings['redis_handler']`.

> **Важный:** Путь к потоковой передаче считывается исключительно из файлового кэша. При обычной работе программа не запрашивает настройки в базе данных или запросы пользователей.

---

## Аутентификация по токену

Файл: `src/Streaming/Auth/StreamAuthMiddleware.php`

```php
StreamAuthMiddleware::decryptToken($rToken, $rSettings, $rServers, $rIP): array
```

Содержимое токена:

|Поле|Описание|
| --- | --- |
| `username` |Имя пользователя строки|
| `password` |Пароль к строке|
| `stream_id` |Идентификатор целевого потока|
| `expires` |Временная метка истечения срока действия токена|
| `channel_info` |Потоковые метаданные (on_demand, прокси, pid)|
| `user_info` |Разрешения пользователя (max_connections, is_restreamer)|
| `country_code` |GeoIP код страны|
| `video_codec` |Запрашиваемый видеокодек|

Утверждение:

1. Расшифруйте токен, используя `live_streaming_pass`.
2. Проверьте истечение срока действия: `$rTokenData['expires'] < time() - $rServers[SERVER_ID]['time_offset']`.
3. Возвращает проанализированные данные токена или вызывает ошибку.

Заголовки ответов задаются через `StreamAuthMiddleware::sendStreamHeaders()`:

```text
Access-Control-Allow-Origin: *
X-XSS-Protection: 0
X-Content-Type-Options: nosniff
Alt-Svc: h3-29, h3-T051, h3-Q050 (HTTP/3 hints)
```

---

## Потоковая доставка

### Жить (live.php)

Основная конечная точка доставки (~650 строк):

1. Расшифруйте токен с помощью `StreamAuthMiddleware::decryptToken()`.
2. Разрешить использование сервера/прокси-сервера: `StreamAuth::checkAccess()` + `ProxySelector::availableProxy()`.
3. Установите ограничения на подключение: `StreamAuth::validateConnections()`.
4. Создайте запись о подключении: `ConnectionTracker::createConnection()`.
5. Hand delivery to the **`xc_fanout` daemon** (see below): PHP emits an
`X-Accel-Redirect` и завершает байтовый путь — nginx передает байты в потоковом режиме.
   - **тс:** `X-Accel-Redirect: /xc_fanout/<id>?c=<uuid>&prebuffer=N` (nginx
перезаписывается в файл демона `/live/<id>`).
   - **HLS:** список воспроизведения указывает на выделенные сегменты; `segment.php` показы в прямом эфире
сегментирует только через демон (`/xc_fanout_hls/<id>_<seq>`), иначе `404`.
6. При выходе: `ShutdownHandler::handle()` → закрыть запись о подключении.

### VOD (vod.php)

Тот же процесс аутентификации, что и в live. Считывается из `VOD_PATH` вместо `STREAMS_PATH`.

### Временной сдвиг (timeshift.php)

Обслуживает архивированные сегменты (timeshift / catch-up) из пути к архиву.

### Доставка демона — `xc_fanout`

Live client delivery (TS **and** HLS) is **daemon-only**: PHP authorizes the
средство просмотра, а затем полностью покидает байтовый путь, так что средство просмотра больше не закрепляет
PHP-FPM работник, отвечающий за жизнедеятельность потока.

- **Расходимся веером.** `xc_fanout` (встроенный демон Go) извлекает каждый источник **однажды** и
предоставляет его каждому пользователю через сокет unix с помощью встроенного в оперативную память сегментатора HLS.
PHP не соответствует байтовому пути для каждого зрителя: рабочий процесс чтения для каждого зрителя
цикл обслуживания и путь `HLSGenerator::generateHLS()` для обслуживания клиентов не являются
больше не используется для оперативной доставки (`generateHLS()` сохраняется в классе, но имеет
абонентов нет). `AsyncFileOperations::awaitFileExists()` — это **нет** удалено - это
все еще используется для ожидания запуска потока и пути в байтах VOD/timeshift (см.
Таблица показателей).
- **Две розетки.** Клиентский сокет (ориентированный на nginx) обслуживает `/live/<id>` и
`/hls/...`; управляющий сокет, предназначенный только для PHP, регистрирует источники
(`PUT /streams/<id>` / `/ingest/<id>`), отвечает на вопросы о статусе выхода в эфир
(`GET /streams/<id>`, `GET /probe/<id>`) и предоставляет доступ к телеметрии.
- **Телеметрия / согласование данных.** `fanout_sync` опросы `GET /rates` (для каждого uuid
КБИТ/с → `lines_divergence`) и `GET /connections` (согласовывает `lines_live`
строк, поскольку PHP не может видеть разъединение в `X-Accel`).
- **Вне эфира.** Если демон сообщает об отсутствии данных (`has_data=false` / устаревшие), PHP
показывает страницу "не в эфире" вместо того, чтобы позволить зрителю зависнуть.
- **Сохранено на диске HLS** только для timeshift / миниатюр / `.analyse` /
`MonitorCommand` — не для доставки клиенту.

#### Наложение отправленного сообщения

Действие администратора "Отправить сообщение" отображает текстовый баннер на видео, которое просматривает **один** зритель.
PHP отправляет его в сокет управления демоном
(`FanoutClient::sendSignal` → `POST /signal/<uuid>`), и демон применяет
ffmpeg `drawtext` наложение на следующий HLS сегмент этого просмотра (или короткий ~5-секундный фрагмент
окно), однократный запуск, максимальное усилие - сигнал никогда не прерывает воспроизведение. Демон должен
быть запущенным с помощью ffmpeg, который на самом деле имеет фильтр `drawtext`, так что
`service` программа запуска выбирает сборку с поддержкой drawtext.

---

## Управление подключениями

### Средство отслеживания подключений

Управляет текущим состоянием соединения. Серверная часть выбрана с помощью `$rSettings['redis_handler']`:

**Redis (preferred for scale):**

- Соединения, хранящиеся в отсортированных наборах:
  - `LINE#{identity}` — подключения для пользователя
  - `STREAM#{stream_id}` — соединения для потока
  - `SERVER#{server_id}` — соединения на сервере

**MySQL (fallback):**

- Таблица: `lines_live` с полями: `activity_id`, `user_id`, `stream_id`, `server_id`, `uuid`, `pid`, `hls_end`

Ключевые методы:

```php
ConnectionTracker::createConnection($data)
ConnectionTracker::updateConnection($connection, $changes, 'open'|'close')
ConnectionTracker::getConnection($uuid)
ConnectionTracker::getLineConnections($user_id)
ConnectionTracker::getCapacity()
```

### Ограничитель подключения

Файл: `src/Streaming/Protection/ConnectionLimiter.php`

Устанавливает ограничения на подключение для каждого пользователя при превышении значения `max_connections`:

|Приоритет|Критерий|Действие|
| --- | --- | --- |
|2|Тот же IP + тот же пользовательский агент|Убей первым|
|1|Тот же IP-адрес (любой UA)|Убей следующего|
|0|Какая-либо связь|Убить в качестве запасного варианта|

Настройки:

- `disallow_2nd_ip_con` — принудительно использовать один IP-адрес для каждого пользователя
- `ip_subnet_match` — соответствует подсети /24 вместо точного IP-адреса
- `restrict_same_ip` — возвращает ошибку при несоответствии IP-адресов вместо уничтожения

### Устройство для выключения

Файл: `src/Streaming/Lifecycle/ShutdownHandler.php`

Зарегистрирован с помощью `register_shutdown_function()`. При завершении процесса PHP:

1. Закройте запись о соединении в `lines_live` или Redis.
2. Удалите tmp-файлы со значением `CONS_TMP_PATH . $uuid`.
3. Удалите поток по требованию из очереди, если это применимо.

---

## балансировка нагрузки

### Выбор сервера (StreamAuth::checkAccess)

Файл: `src/Streaming/Auth/StreamAuth.php`

```php
public static function checkAccess($rUserInfo, $rUserIP, $rCountryCode, $rUserISP = ''): int|false
```

Алгоритм:

1. Получите доступные серверы: `server_online == true`, `server_type == 0`, `online_clients < total_clients`.
2. Сортировка по вместимости (по возрастанию) — сначала загружается наименее загруженный.
3. Применить маршрутизацию GeoIP (если `enable_geoip == 1`):
   - Точное соответствие стране → выберите немедленно.
   - `geoip_type == 'strict'` → исключить несоответствия.
   - В противном случае → присвоить приоритетный вес.
4. Примените маршрутизацию через интернет-провайдера (если `enable_isp == 1`): та же логика, что и GeoIP.
5. Верните сервер с наименьшей пропускной способностью из группы с наивысшим приоритетом.

### Выбор прокси-сервера (ProxySelector::Доступный прокси)

Файл: `src/Streaming/Balancer/ProxySelector.php`

```php
public static function availableProxy($rProxies, $rCountryCode, $rUserISP = ''): int|null
```

Тот же алгоритм, что и `StreamAuth::checkAccess()`, но примененный к списку прокси-серверов.

---

## Ограничение скорости и защита от наводнений

Три слоя:

### 1. nginx (уровень подключения)

```nginx
limit_req_zone $binary_remote_addr zone=one:30m rate=20r/s;
limit_req zone=one burst=8;
```

20 запросов в секунду на IP-адрес с пакетом из 8 запросов. 30-минутное скользящее окно.

### 2. StreamingRequestBootstrap (IP-блокировка)

```php
if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
    http_response_code(403);
    exit();
}
```

IP-блокировка на основе файлов. Файлы блоков создаются с помощью вышестоящей логики обнаружения наводнений.

### 3. Ограничитель подключений (для каждого пользователя)

Применяется после проверки токена. Ограничивает одновременные потоки для каждого пользователя на основе `max_connections`.

---

## HLS Шифрование

Клиент HLS обслуживается демоном `xc_fanout` (см. [Доставка демоном](#daemon-delivery-xc_fanout)), поэтому происходит шифрование **сторона демона**:

1. `StreamProcess` записывает ключ потока AES-128/IV в `content/streams/<id>_.key` / `_.iv`.
2. At ingest registration (`FanoutClient::registerIngest`), when `encrypt_hls` is on, the key/IV are handed to the daemon, which encrypts the HLS segments it serves and emits a matching `#EXT-X-KEY`.
3. `HLSGenerator::tokenizeDaemonPlaylist()` переписывает URL-адреса сегментов плейлиста демона в ссылки с авторизацией для каждого сегмента `/hls/<token>`, которые `segment.php` передаются через прокси-сервер демона.
4. Ключ AES доставляется игрокам с помощью `key.php` (`src/Public/stream/key.php`) с использованием того же механизма токенов.

> Устаревший `HLSGenerator::generateHLS()` (который создал и зашифровал плейлист на диске HLS для использования PHP) сохраняется в классе, но становится **больше не находится на пути к клиенту** после отключения демона.

---

## Представление

Ключевые проектные решения, касающиеся пропускной способности и задержки:

|Особенность|Механизм|
| --- | --- |
|Трансляция-онлайн-ожидание|`AsyncFileOperations::awaitFileExists()` ожидает `_.pid`/`_.monitor`/первого сегмента при появлении потока (и в пути длиной VOD/timeshift байт). Оперативная доставка клиента осуществляется демоном, а не считывается с помощью PHP.|
|Нулевой режим работы процессора|`time_nanosleep()` через `AsyncFileOperations::efficientSleep()`|
|nginx буферизация|128 буферов по 32 КБАЙТ на запрос|
|Объединение подключений в пул|Redis (предпочтительно) или постоянный MySQL|
|Чтение только из кэша|Настройки и пользовательские данные считываются из файлового кэша без запросов к базе данных|
|Досрочный выход (VOD/timeshift)|Эти байтовые циклы опрашивают `connection_status()` для остановки при отключении клиента. В Live нет байтового цикла для каждого пользователя PHP (обслуживается демоном).|
|Обновление настроек|Каждые 5 минут (300 секунд) для отслеживания изменений конфигурации без перезапуска|

---

## Пути к файловой системе

```text
STREAMS_PATH        = /home/xc_vm/content/streams/
VOD_PATH            = /home/xc_vm/content/vod/
ARCHIVE_PATH        = /home/xc_vm/content/archive/
VIDEO_PATH          = /home/xc_vm/content/video/
CONS_TMP_PATH       = /home/xc_vm/tmp/opened_cons/
CACHE_TMP_PATH      = /home/xc_vm/tmp/cache/
FLOOD_TMP_PATH      = /home/xc_vm/tmp/flood/
SIGNALS_TMP_PATH    = /home/xc_vm/tmp/signals/
SIGNALS_PATH        = /home/xc_vm/signals/
```

---

## Диагностика и оснастка

Автономный инструмент проверки целостности потока (`tools/stream-check/stream_queue_check.py`) теперь доступен на отдельной странице - см. [Диагностика и инструменты для потоковой передачи](streaming-diagnostics.md).

---

## Обоснование проекта (ADR)

Почему оперативная доставка переместилась с tmpfs на PHP байтовый путь — решения, стоящие за текущим
`xc_fanout` архитектура — записывается в отчетах об архитектурных решениях (repo-внутренние примечания,
не является частью опубликованного сайта):

- [ADR 0001 — Tmpfs-free streaming](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0001-tmpfs-free-streaming.md) — PHP out of the byte path, native fan-out, in-RAM HLS.
- [ADR 0002 — `xc_fanout` daemon](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0002-xc-fanout-daemon.md) — the native live fan-out daemon.
- [ADR 0003 — Полное отключение демона](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0003-full-daemon-cutover.md) — отмена устаревшего байтового пути для live.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Streaming/StreamingBootstrap.php` |основной загрузчик потоковой передачи|
| `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` |Инициализация на уровне HTTP|
| `src/Streaming/Auth/StreamAuth.php` |выбор сервера и проверка подключения|
| `src/Streaming/Auth/StreamAuthMiddleware.php` |расшифровка токенов и заголовки ответов|
| `src/Streaming/Balancer/ProxySelector.php` |выбор прокси-сервера|
| `src/Streaming/Protection/ConnectionLimiter.php` |ограничения на подключение для каждого пользователя|
| `src/Streaming/Delivery/HLSGenerator.php` |Генерация плейлиста M3U8|
| `src/Streaming/Delivery/StreamRedirector.php` |доступность потока и маршрутизация сервера|
| `src/Streaming/AsyncFileOperations.php` |неблокирующие утилиты для файловой системы|
| `src/Streaming/Lifecycle/ShutdownHandler.php` |очистка соединения при выходе|
| `src/Domain/Stream/ConnectionTracker.php` |состояние соединения в Redis/MySQL|
| `src/Core/Init/LegacyInitializer.php` |настройка глобальной переменной для потоковой передачи|
| `tools/stream-check/stream_queue_check.py` |мониторинг целостности очереди + панель мониторинга динамического буфера|
