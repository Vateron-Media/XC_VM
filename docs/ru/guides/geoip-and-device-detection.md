# GeoIP и определение устройства

XC_VM использует базы MaxMind GeoIP2/GeoLite2 для геолокации и определения ISP, а также библиотеку Mobile_Detect для разбора user agent.
Эти системы интегрированы в аутентификацию стриминга для контроля доступа, географической маршрутизации и логирования активности.

---

## Поиск по GeoIP

Два класса предоставляют GeoIP-поиск:

### GeoIP (утилита)

Файл: `src/Core/Util/GeoIP.php`

```php
GeoIP::getCountry($ip): array|false    // GeoLite2-City lookup
GeoIP::getISP($ip): array|false        // GeoIP2-ISP lookup
GeoIP::isISPBlocked($ispName, $blockedISPs): int
GeoIP::isASNBlocked($asn, $blockedServers): bool
```

Результаты кэшируются как файлы по путям `CONS_TMP_PATH/{md5(ip)}_geo2` и `CONS_TMP_PATH/{md5(ip)}_isp`.

### GeoIPService (высокоуровневый)

Файл: `src/Core/GeoIP/GeoIPService.php`

```php
GeoIPService::getIPInfo($rIP): array|false      // поиск на уровне города
GeoIPService::getISP($rIP): array|false         // данные ISP + ASN
GeoIPService::matchCIDR($rASN, $rIP): array|null  // определение hosting/proxy
```

`matchCIDR()` проверяет IP по CIDR-блокам, сохранённым по ASN в `CIDR_TMP_PATH/{asn}`. Возвращает флаги для определения hosting и proxy.

---

## Определение ISP и ASN

Когда включена настройка `show_isps`, каждый стриминговый запрос определяет ISP клиента:

```php
$rISPLock = GeoIPService::getISP($rIP);
// возвращает: ['isp' => 'Comcast', 'autonomous_system_number' => 7922, ...]
```

Данные ISP хранятся в записи пользователя:

| Поле | Описание |
| --- | --- |
| `isp_desc` | заблокированное имя ISP |
| `as_number` | заблокированный ASN |
| `con_isp_name` | текущий ISP подключения (временное значение) |
| `is_isplock` | включить привязку к ISP (0/1) |
| `isp_violate` | флаг нарушения ISP — блокирует доступ |

Нарушение привязки к ISP возникает, когда:

```text
con_isp_name != isp_desc
AND is_isplock = 1
AND is_stalker = 0
AND enable_isp_lock = 1
```

---

## Определение устройства

### Mobile_Detect

Файл: `src/Core/Device/MobileDetect.php`

Библиотека (v2.8.45) для разбора user agent:

```php
$detect = new Mobile_Detect();
$detect->isMobile();    // телефоны
$detect->isTablet();    // планшеты
$detect->isAndroid();   // по бренду
```

Используется в `src/bootstrap.php` для определения мобильных устройств в адаптивном интерфейсе администратора.

### Set-Top Box устройства

**EnigmaService** (`src/Domain/Device/EnigmaService.php`):

Управляет аккаунтами STB Enigma2. Поля привязки: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

**MagService** (`src/Domain/Device/MagService.php`):

Управляет аккаунтами STB MAG. Поля привязки: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

Оба поддерживают:

- `lock_device` — привязка к железу
- `is_isplock` — привязка к ISP
- `forced_country` — принудительное закрепление пользователя за определённой страной

---

## Проверки контроля доступа

Все проверки происходят в `src/www/stream/auth.php` во время валидации токена:

### 1. Валидация страны

```text
если forced_country задано:
    country_code должен совпадать с forced_country
    ошибка: FORCED_COUNTRY_INVALID

если существует белый список allow_countries:
    country_code должен быть в белом списке
    ошибка: NOT_IN_ALLOWED_COUNTRY
```

### 2. Контроль привязки к ISP

```text
если is_isplock = 1 и is_stalker = 0:
    con_isp_name должен совпадать с isp_desc
    ошибка: ISP_BLOCKED
```

### 3. Блокировка по ASN

```text
если block_svp = 1:
    проверить ASN по списку заблокированных серверов
    ошибка: ASN_BLOCKED
```

### 4. Определение hosting и proxy

```text
GeoIPService::matchCIDR($asn, $ip)
    flag[3] = hosting → ошибка: HOSTING_DETECT
    flag[4] = proxy → ошибка: PROXY_DETECT
```

Также проверяется заголовок `X-XC_VM-DETECT` для детекции рестрима.

### 5. Блокировка User-Agent

```text
проверка через BlocklistService::checkBlockedUAs()
ошибка: BLOCKED_USER_AGENT

если у пользователя задан allowed_ua:
    user_agent должен совпадать с одной из записей
    ошибка: NOT_IN_ALLOWED_UAS
```

### 6. Валидация типа устройства

```text
флаг MAG-устройства должен совпадать с токеном
ошибка: DEVICE_NOT_ALLOWED или TOKEN_EXPIRED
```

---

## Географическая маршрутизация

Данные GeoIP управляют выбором серверов и прокси:

### Выбор сервера (StreamAuth::checkAccess)

Когда `enable_geoip == 1`:

- Точное совпадение по стране → выбрать этот сервер сразу.
- `geoip_type == 'strict'` → исключить несоответствующие серверы.
- Иначе → присвоить весовой приоритет (1 для low, 2 для normal).

### Выбор прокси (ProxySelector::availableProxy)

Та же логика применяется к списку прокси-серверов. Использует код страны и имя ISP для маршрутизации.

```php
ProxySelector::availableProxy(
    array_keys($rProxies),
    $rCountryCode,
    $rUserInfo['con_isp_name']
);
```

---

## Обновления базы данных

Файл: `src/Core/GeoIP/MaxMindUpdater.php`

```php
$updater = MaxMindUpdater::fromSettings($settings);
$updater->update();  // загружает и распаковывает все настроенные издания
```

Поддерживаемые издания:

- `GeoLite2-Country`, `GeoLite2-City`, `GeoLite2-ASN` (бесплатные)
- `GeoIP2-Country`, `GeoIP2-City`, `GeoIP2-ISP`, `GeoIP2-Anonymous-IP` (платные)

Загрузка через MaxMind API с использованием `maxmind_account_id` и `maxmind_license_key`.
Распаковывает `.mmdb` файлы из tar.gz архивов в `BIN_PATH/maxmind/`.

Пути файлов баз данных (заданы в `src/Core/Config/Binaries.php`):

```text
GEOLITE2_BIN  = BIN_PATH/maxmind/GeoLite2-Country.mmdb
GEOLITE2C_BIN = BIN_PATH/maxmind/GeoLite2-City.mmdb
GEOISP_BIN    = BIN_PATH/maxmind/GeoIP2-ISP.mmdb
```

### Автоматическое обновление

Обновление баз выполняет крон-задача `cron:maxmind` (`src/Cli/CronJobs/MaxMindCronJob.php`).
Она запускается **только по вторникам** — в день, когда MaxMind публикует новые релизы. Логика ветвится по настройкам панели:

- если заданы `maxmind_account_id` + `maxmind_license_key` + `maxmind_editions` — базы тянутся напрямую из MaxMind API (`MaxMindUpdater`, скачиваются только настроенные издания);
- если кредлы MaxMind **не** заданы — используется фолбэк на GitHub-релизы GeoLite2 (бесплатные базы).

### Ручное (принудительное) обновление

Чтобы обновить базы `.mmdb` немедленно на работающей панели, запустите крон-задачу вручную **от root** с флагом `--force` (он снимает ограничение «только по вторникам»):

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:maxmind --force
```

Статусы в выводе:

- `[OK]` — база обновлена;
- `[SKIP]` — уже актуальна;
- `[WARN]` / `[ERROR]` — с деталями (неверные кредлы, HTTP-ошибка, недоступность сети).

> ⚠️ Через MaxMind API отправляется заголовок `If-Modified-Since`, поэтому уже свежая база вернёт HTTP 304 и статус `[SKIP]`. Чтобы гарантированно перекачать файл, предварительно удалите (или переименуйте) соответствующий `.mmdb` в `BIN_PATH/maxmind/` — тогда заголовок не отправится. У GitHub-фолбэка такого поведения нет: там сверяется md5 и файл перекачивается при несовпадении.

---

## Логирование активности

Все стриминговые сессии логируют данные GeoIP и устройства в `lines_live`:

| Столбец | Источник |
| --- | --- |
| `geoip_country_code` | `GeoIPService::getIPInfo()` |
| `isp` | `con_isp_name` из `GeoIPService::getISP()` |
| `external_device` | идентификатор типа устройства |
| `user_agent` | HTTP-заголовок User-Agent |
| `user_ip` | IP клиента |

Логируется в `live.php`, `vod.php`, `timeshift.php` и `rtmp.php`.

Периодически архивируется из `lines_live` в `lines_activity` через `ActivityCronJob`.

---

## Конфигурация

### Настройки

| Настройка | Тип | Описание |
| --- | --- | --- |
| `show_isps` | `0/1` | включить определение ISP |
| `enable_isp_lock` | `0/1` | включить применение привязки к ISP |
| `block_svp` | `0/1` | блокировать VPN/proxy/сервер (проверка ASN) |
| `block_streaming_servers` | `0/1` | блокировать IP датацентров |
| `block_proxies` | `0/1` | блокировать IP прокси-провайдеров |
| `county_override_1st` | `0/1` | автоматически назначать forced_country при первом подключении |
| `allow_countries` | `array` | белый список разрешённых кодов стран |
| `detect_restream_block_user` | `0/1` | автоматически отключать пользователя при определении рестрима |
| `disallow_empty_user_agents` | `0/1` | отклонять запросы без User-Agent |
| `maxmind_account_id` | `string` | аккаунт MaxMind API |
| `maxmind_license_key` | `string` | ключ MaxMind API |
| `maxmind_editions` | `JSON` | массив загружаемых изданий |

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Core/Util/GeoIP.php` | низкоуровневые GeoIP-поиски с файловым кэшем |
| `src/Core/GeoIP/GeoIPService.php` | высокоуровневый GeoIP + сопоставление CIDR |
| `src/Core/GeoIP/MaxMindUpdater.php` | загрузчик баз данных MaxMind |
| `src/Core/Config/Binaries.php` | константы путей файлов баз GeoIP |
| `src/Core/Device/MobileDetect.php` | библиотека Mobile_Detect |
| `src/Domain/Device/EnigmaService.php` | управление STB Enigma2 |
| `src/Domain/Device/MagService.php` | управление STB MAG |
| `src/Domain/User/UserRepository.php` | обогащение записей пользователей данными GeoIP |
| `src/www/stream/auth.php` | аутентификация стриминга со всеми гео/устройство проверками |
| `src/Streaming/Auth/StreamAuth.php` | выбор сервера с учётом GeoIP |
| `src/Streaming/Balancer/ProxySelector.php` | выбор прокси с учётом GeoIP |
