# Подсистема потоковой передачи

Подсистема потоковой передачи обрабатывает передачу в режиме реального времени, VOD и timeshift.
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

nginx переписывает все URL-адреса потоковой передачи на PHP точки входа под `www/stream/`:

|Шаблон URL-адреса|Точка входа|Цель|
| --- | --- | --- |
| `/auth/{token}` | `live.php` |Прямая трансляция|
| `/vauth/{token}` | `vod.php` |Доставка видео по запросу|
| `/tsauth/{token}` | `timeshift.php` |Архив/timeshift воспроизведение|
| `/hls/{token}` | `segment.php` |HLS сегментная поставка|
| `/key/{token}` | `key.php` |Ключ шифрования AES-128|
| `/subauth/{token}` | `subtitle.php` |Передача субтитров|

---

## Расположение каталога

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
│   └── StreamRedirector.php
├── Health/
│   └── ProcessChecker.php
├── Lifecycle/
│   └── ShutdownHandler.php
└── Protection/
    └── ConnectionLimiter.php

src/www/stream/
├── init.php          # Legacy bootstrap shim (deprecated)
├── auth.php          # Token validation gateway
├── live.php          # Live streaming delivery
├── vod.php           # VOD delivery
├── timeshift.php     # Archive/timeshift playback
├── segment.php       # HLS segment delivery
├── key.php           # Encryption key delivery
├── subtitle.php      # Subtitle delivery
├── thumb.php         # Thumbnail delivery
└── rtmp.php          # RTMP publishing endpoint
```

---

## Конвейер начальной загрузки

### 1. StreamingRequestBootstrap::init()

Файл: `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php`

Действия в порядке:

1. Загружайте коды ошибок, обработчик, пути, конфигурацию, двоичные файлы.
2. Защита от наводнений (только по протоколу HTTP): проверьте наличие `FLOOD_TMP_PATH . 'block_' . $rIP`.
3. Загрузите настройки из файлового кэша (`CACHE_TMP_PATH . 'settings'`).
4. Проверка хоста (только HTTP): проверка на соответствие `allowed_domains`.
5. Инициализируйте регистратор.
6. Аварийно закрытый шлюз: возвращает 404, если настройки отсутствуют (кроме `/status`).
7. Позвоните `StreamingBootstrap::bootstrap()`.

### 2. Потоковый загрузчик::bootstrap()

Файл: `src/Streaming/StreamingBootstrap.php`

```php
public static function bootstrap($rFilename, $rSettings)
```

Классифицирует конечную точку:

- **Конечные точки зондирования:** `probe`, `player_api` (небольшая нагрузка)
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

> **Важно:** Путь к потоковой передаче считывается исключительно из файлового кэша. При обычной работе программа не запрашивает настройки в базе данных или запросы пользователей.

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
2. Проверьте срок действия: `$rTokenData['expires'] < time() - $rServers[SERVER_ID]['time_offset']`.
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
2. Разрешить работу сервера/прокси-сервера: `StreamAuth::checkAccess()` + `ProxySelector::availableProxy()`.
3. Установите ограничения на подключение: `StreamAuth::validateConnections()`.
4. Создайте запись о подключении: `ConnectionTracker::createConnection()`.
5. Передача вручную демону **`xc_fanout` (смотрите ниже): PHP выдает сообщение
`X-Accel-Redirect` и завершает байтовый путь — nginx передает байты в потоковом режиме.
   - **ТС:** `X-Accel-Redirect: /xc_fanout/<id>?c=<uuid>&prebuffer=N` (nginx
перезаписывается в файл демона `/live/<id>`).
   - **HLS:** список воспроизведения указывает на выделенные сегменты; `segment.php` транслируется в прямом эфире
сегментирует только через демон (`/xc_fanout_hls/<id>_<seq>`), иначе `404`.
6. При выходе: `ShutdownHandler::handle()` → закрыть запись подключения.

### VOD (vod.php)

Тот же процесс аутентификации, что и в live. Считывается из `VOD_PATH` вместо `STREAMS_PATH`.

### Временной сдвиг (timeshift.php)

Обслуживает архивные сегменты. Использует `TimeshiftClient` для разрешения архивного файла.

### Демон доставки — `xc_fanout`

Оперативная доставка клиентов (TS **и** HLS) доступна ** только для демонов **: PHP авторизует
средство просмотра, а затем полностью покидает байтовый путь, так что средство просмотра больше не закрепляет
PHP-FPM работник, отвечающий за жизнь потока.

- **Разветвление.** `xc_fanout` (встроенный демон Go) извлекает каждый источник ** один раз** и
он доступен каждому пользователю через сокет unix с помощью встроенного в оперативную память сегментатора HLS.
PHP отсутствует в байтовом пути для каждого зрителя; старый цикл поиска и чтения
(`AsyncFileOperations::awaitFileExists()`) и `HLSGenerator::generateHLS()`
сервировочные дорожки были удалены при разделке.
- **Два сокета.** Клиентский сокет (ориентированный на nginx) обслуживает `/live/<id>` и
`/hls/...`; управляющий сокет, предназначенный только для PHP, регистрирует исходные коды
(`PUT /streams/<id>` / `/ingest/<id>`), отвечает на вопросы о статусе выхода в эфир
(`GET /streams/<id>`, `GET /probe/<id>`) и предоставляет доступ к телеметрии.
- **Телеметрия / сверка.** `fanout_sync` опросов `GET /rates` (для каждого uuid
КБИТ/с → `lines_divergence`) и `GET /connections` (согласовывает `lines_live`
строк, поскольку PHP не может видеть разъединение в `X-Accel`).
- **Отключен.** Если демон сообщает об отсутствии данных (`has_data=false` / устаревшие), PHP
показывает страницу "не в эфире" вместо того, чтобы позволить зрителю зависнуть.
- **Сохранено на диске HLS** только для timeshift / миниатюр / `.analyse` /
`MonitorCommand` — не для доставки клиенту.

#### Наложение отправленного сообщения

Действие администратора "Отправить сообщение" отображает текстовый баннер на видео **одного** зрителя.
PHP отправляет его в сокет управления демоном
(`FanoutClient::sendSignal` → `POST /signal/<uuid>`), и демон применяет
ffmpeg `drawtext` наложение на следующий сегмент этого просмотра HLS (или короткий фрагмент ~5 секунд).
окно), однократный запуск, максимальное усилие - сигнал никогда не прерывает воспроизведение. Демон должен
запускается с помощью ffmpeg, который на самом деле имеет фильтр `drawtext`, так что
`service` launcher выбирает сборку с поддержкой drawtext.

---

## Управление подключениями

### Средство отслеживания подключений

Управляет текущим состоянием соединения. Серверная часть выбрана с помощью `$rSettings['redis_handler']`:

**Redis (предпочтительно для масштаба):**

- Соединения, хранящиеся в отсортированных наборах:
  - `LINE#{identity}` — подключения для пользователя
  - `STREAM#{stream_id}` — соединения для потоковой передачи
  - `SERVER#{server_id}` — соединения на сервере

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

Зарегистрирован через `register_shutdown_function()`. На выходе из PHP процесса:

1. Закройте запись о подключении с помощью `lines_live` или Redis.
2. Удалите tmp-файлы по адресу `CONS_TMP_PATH . $uuid`.
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
   - `geoip_type == 'strict'` → исключить несоответствие.
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

Файл: `src/Streaming/Delivery/HLSGenerator.php`

```php
public static function generateHLS($rSettings, $rM3U8, $rUsername, $rPassword,
    $rStreamID, $rUUID, $rIP, ...): string|false
```

Когда `encrypt_hls == true`:

1. Сгенерируйте ключевой токен AES-128 из IP + StreamID + salt.
2. Замените IV содержимым из `STREAMS_PATH . $rStreamID . '_.iv'`.
3. Зашифруйте ссылку на каждый сегмент: `IP/StreamID/Segment/UUID/SERVER_ID/VideoCodec/OnDemand`.
4. Замените названия сегментов на `/hls/{encrypted_token}`.

Доставка ключей происходит через `key.php` с использованием того же механизма токенов.

---

## Представление

Ключевые проектные решения, касающиеся пропускной способности и задержки:

|Особенность|Механизм|
| --- | --- |
|Ожидание неблокирующего файла|`AsyncFileOperations::awaitFileExists()` использует inotify (Linux) или оптимизированный опрос|
|Нулевой режим работы процессора|`time_nanosleep()` через `AsyncFileOperations::efficientSleep()`|
|nginx буферизация|128 буферов по 32 КБАЙТ на запрос|
|Объединение подключений в пул|Redis (предпочтительно) или постоянный MySQL|
|Чтение только из кэша|Настройки и пользовательские данные считываются из файлового кэша без запросов к базе данных|
|Ранний выход|Отслеживает `connection_status()` каждые 5 секунд, чтобы обнаружить отключение клиента|
|Обновление настроек|Каждые 5 минут (300 секунд) для отслеживания изменений конфигурации без перезапуска|

---

## Пути к файловой системе

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

## Диагностика и оснастка

Два инструмента проверяют правильность доставки потока — что сегменты поступают по порядку
и очередь на доставку не прерывается.

### `tools/stream_queue_check.py` (только для Python, stdlib)

Автономный монитор **целостности сегмента/очереди пакетов** с дополнительным ** функцией live
панель управления буфером**. Автоматически определяет HLS против MPEG-TS.

```bash
python3 tools/stream_queue_check.py "<url>" --duration 30        # batch check
python3 tools/stream_queue_check.py "<url>" --json               # cron / monitoring
python3 tools/stream_queue_check.py "<url>" --live --duration 0  # live dashboard
```

Что означает "неповрежденная очередь" для каждого типа потока:

|Течение|Проверка очереди|
| --- | --- |
|HLS (`.m3u8`)|`EXT-X-MEDIA-SEQUENCE` монотонный и непрерывный (без удаленных или перемотанных сегментов), без `EXT-X-DISCONTINUITY`, каждый вновь появляющийся сегмент доступен для загрузки. Основные плейлисты отображаются в их первом варианте.|
|MPEG-TS (`.ts`, `/play/<token>/ts`)|per-PID `continuity_counter` (потерянные / дублированные / переупорядоченные пакеты = разрыв очереди), потеря байта синхронизации, индикатор транспортной ошибки и остановка доставки.|

Основные параметры:

|Флаг|Цель|
| --- | --- |
| `--duration N` |секунды для наблюдения (`0` = до нажатия Ctrl-C в `--live`)|
| `--tolerance N` |разрешить N временных разрывов очереди, прежде чем сообщать о `BROKEN` (игнорирует редкие сбои в источнике, передаваемые `-c copy`)|
| `--stall-timeout S` |перерыв в доставке засчитывается как задержка; не превышайте продолжительность сегмента (по умолчанию 15).|
| `--live` |цветная приборная панель TUI (внизу)|
|`--prebuffer S` / `--buffer-target S`|live: предварительный буфер для виртуального игрока и масштаб буферного графика|
|`--json` / `--no-color`|машинный вывод / отключение ANSI|

Код выхода: `0` исправен, `2` проблема с очередью или задержка, `1` использование.

#### Оперативная панель мониторинга (`--live`)

Моделирует виртуального проигрывателя: проигрыватель перемещается со скоростью настенных часов, в то время как содержимое
"получено". Для **TS** полученная временная шкала берется из **PCR** (часы потока).;
для **HLS** из длительностей сегментов `EXTINF`. Буферизованное время воспроизведения ("кэш") =
получено − воспроизведено; если значение достигает нуля, то начало воспроизведения зависает (событие отмены буферизации).

```text
  STREAM QUEUE / BUFFER MONITOR   TS   up 00:22
  cache buffer (s), last 60s:
  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▄▄▄▇▇▇▆▆▆▅▅▅▄▄▇▇▇▆▆▆▅   <- burst-then-drain = delivery sawtooth
  IN CACHE : [█████████████████░░░░░░░░░░░░░]  11.6s / 20s
  PLAYING  : PLAYING     head 00:18   received 00:29
  rate 1000 kbit/s   received 4.1 MB   last data 7.0s ago
  QUEUE OK   cc:0 sync:0 gaps:0 disc:0   rebuffers:0
```

График буфера и индикатор окрашены в зеленый (исправный) / желтый (низкий) / красный цвета
(голодает). Для HLS строка блоков показывает сегменты, которые все еще находятся в кэше перед началом
плейхед.

### `console.php stream:check` (PHP, компаньон)

Проверяет URL-адрес источника и с помощью `--decode` извлекает и декодирует медиафайл для перехвата
неработающие/поврежденные сегменты. HLS проверяется по сегментам; значение TS для одного сокета
конечная точка фиксируется с помощью cURL и декодируется в автономном режиме (ffmpeg в режиме реального времени `-i` зависает
it). Источник: `src/Cli/Commands/StreamCheckCommand.php`.

```bash
console.php stream:check "<url>"                  # metadata probe (type, codecs)
console.php stream:check "<url>" --decode=30 --json
```

> **Примечание — темп доставки.** Цикл доставки TS в режиме реального времени в `live.php` истощается
> доступные данные без регулирования и приостанавливаются только при достижении ffmpeg's
> заголовок записи. Более ранняя версия отключалась на одну секунду после каждого чтения, ограничивая
> пропускная способность на уровне `read_buffer_size` в секунду и голодающие клиенты;
> `stream_queue_check.py --live` визуализирует результирующее поведение буфера.

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
| `src/Cli/Commands/StreamCheckCommand.php` |`stream:check` — проверка/декодирование потока на наличие фрагментарных сегментов|
| `tools/stream_queue_check.py` |мониторинг целостности очереди + панель мониторинга динамического буфера|
