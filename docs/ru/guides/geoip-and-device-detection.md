# GeoIP и обнаружение устройства

XC_VM использует базы данных MaxMind GeoIP2/GeoLite2 для определения геолокации и интернет-провайдера, а также библиотеку Mobile_Detect для анализа пользовательского агента.
Эти системы интегрированы в потоковую аутентификацию для контроля доступа, географической маршрутизации и ведения журнала действий.

---

## GeoIP Поиск

Два класса обеспечивают поиск GeoIP:

### GeoIP (полезность)

Файл: `src/Core/Util/GeoIP.php`

```php
GeoIP::getCountry($ip): array|false    // GeoLite2-City lookup
GeoIP::getISP($ip): array|false        // GeoIP2-ISP lookup
GeoIP::isISPBlocked($ispName, $blockedISPs): int
GeoIP::isASNBlocked($asn, $blockedServers): bool
```

Результаты кэшируются в виде файлов по адресам `CONS_TMP_PATH/{md5(ip)}_geo2` и `CONS_TMP_PATH/{md5(ip)}_isp`.

### Геоипсервис (высокого уровня)

Файл: `src/Core/GeoIP/GeoIPService.php`

```php
GeoIPService::getIPInfo($rIP): array|false      // city-level lookup
GeoIPService::getISP($rIP): array|false         // ISP + ASN data
GeoIPService::matchCIDR($rASN, $rIP): array|null  // hosting/proxy detection
```

`matchCIDR()` проверяет соответствие IP-адреса блокам CIDR, хранящимся в ASN по адресу `CIDR_TMP_PATH/{asn}`. Возвращает флаги для определения хостинга и прокси-сервера.

---

## Обнаружение интернет-провайдеров и ASN

Когда `show_isps` включен, каждый потоковый запрос разрешает клиентский провайдер:

```php
$rISPLock = GeoIPService::getISP($rIP);
// returns: ['isp' => 'Comcast', 'autonomous_system_number' => 7922, ...]
```

Данные интернет-провайдера хранятся в записи пользователя:

|Поле|Описание|
| --- | --- |
| `isp_desc` |имя заблокированного интернет-провайдера|
| `as_number` |заблокированный ASN|
| `con_isp_name` |текущее подключение провайдера (временное)|
| `is_isplock` |включить привязку к интернет-провайдеру (0/1)|
| `isp_violate` |Флаг нарушения со стороны провайдера — блокирует доступ|

Нарушение блокировки интернет-провайдера происходит, когда:

```text
con_isp_name != isp_desc
AND is_isplock = 1
AND is_stalker = 0
AND enable_isp_lock = 1
```

---

## Обнаружение устройств

### Мобильное обнаружение

Файл: `src/Core/Device/MobileDetect.php`

Библиотека (версия 2.8.45) для анализа пользовательского агента:

```php
$detect = new Mobile_Detect();
$detect->isMobile();    // phones
$detect->isTablet();    // tablets
$detect->isAndroid();   // brand-specific
```

Используется в `src/bootstrap.php` для определения мобильных устройств по адаптивному интерфейсу администратора.

### Телевизионные приставки

**Энигмасервис** (`src/Domain/Device/EnigmaService.php`):

Управляет учетными записями Enigma2 STB. Блокирует поля: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

**Магсервис** (`src/Domain/Device/MagService.php`):

Управляет учетными записями MAG STB. Блокирует поля: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

Обе поддержки:

- `lock_device` — аппаратная блокировка
- `is_isplock` — Привязка к провайдеру
- `forced_country` — принудительный перевод пользователя в определенную страну

---

## Проверки контроля доступа

Все проверки выполняются в `src/www/stream/auth.php` во время проверки токена:

### 1. Валидация в стране

```text
if forced_country is set:
    country_code must match forced_country
    error: FORCED_COUNTRY_INVALID

if allow_countries whitelist exists:
    country_code must be in whitelist
    error: NOT_IN_ALLOWED_COUNTRY
```

### 2. Принудительное применение блокировки интернет-провайдером

```text
if is_isplock = 1 and is_stalker = 0:
    con_isp_name must match isp_desc
    error: ISP_BLOCKED
```

### 3. Блокировка ASN

```text
if block_svp = 1:
    check ASN against blocked servers
    error: ASN_BLOCKED
```

### 4. Обнаружение хостинга и прокси-серверов

```text
GeoIPService::matchCIDR($asn, $ip)
    flag[3] = hosting → error: HOSTING_DETECT
    flag[4] = proxy → error: PROXY_DETECT
```

Также проверяет заголовок `X-XC_VM-DETECT` на предмет обнаружения повторного потока.

### 5. Блокировка пользовательского агента

```text
check against BlocklistService::checkBlockedUAs()
error: BLOCKED_USER_AGENT

if user has allowed_ua set:
    user_agent must match one entry
    error: NOT_IN_ALLOWED_UAS
```

### 6. Проверка типа устройства

```text
MAG device flag must match token
error: DEVICE_NOT_ALLOWED or TOKEN_EXPIRED
```

---

## Географический маршрут

GeoIP выбор сервера для передачи данных и прокси-сервера:

### Выбор сервера (StreamAuth::checkAccess)

Когда `enable_geoip == 1`:

- Точное соответствие стране → немедленно выберите этот сервер.
- `geoip_type == 'strict'` → исключить несоответствующие серверы.
- В противном случае → назначьте приоритетный вес (1 для низкого, 2 для нормального).

### Выбор прокси-сервера (ProxySelector::Доступный прокси)

Та же логика применима к списку прокси-серверов. Для маршрутизации используется как код страны, так и имя провайдера.

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
$updater->update();  // downloads and extracts all configured editions
```

Поддерживаемые выпуски:

- `GeoLite2-Country`, `GeoLite2-City`, `GeoLite2-ASN` ( бесплатно)
- `GeoIP2-Country`, `GeoIP2-City`, `GeoIP2-ISP`, `GeoIP2-Anonymous-IP` ( оплаченный)

Загружается через MaxMind API с использованием `maxmind_account_id` и `maxmind_license_key`.
Извлекает файлы `.mmdb` из tar.gz архивов в `BIN_PATH/maxmind/`.

Пути к файлам базы данных (определены в `src/Core/Config/Binaries.php`):

```text
GEOLITE2_BIN  = BIN_PATH/maxmind/GeoLite2-Country.mmdb
GEOLITE2C_BIN = BIN_PATH/maxmind/GeoLite2-City.mmdb
GEOISP_BIN    = BIN_PATH/maxmind/GeoIP2-ISP.mmdb
```

### Автоматическое обновление

Базы данных обновляются с помощью задания `cron:maxmind` cron (`src/Cli/CronJobs/MaxMindCronJob.php`).
Он запускается ** только по вторникам** — в день, когда MaxMind публикует новые версии. Логика разветвляется на настройки панели:

- если `maxmind_account_id` + `maxmind_license_key` + `maxmind_editions` задано, базы данных извлекаются непосредственно из MaxMind API (`MaxMindUpdater`, загружаются только настроенные версии).;
- если учетные данные MaxMind не заданы, они возвращаются к версиям GitHub GeoLite2 (бесплатные базы данных).

### Ручное (принудительное) обновление

Чтобы немедленно обновить базы данных `.mmdb` на рабочей панели, запустите задание cron вручную **от имени пользователя root** с флагом `--force` (это снимает ограничение "Только по вторникам").:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:maxmind --force
```

Выходные статусы:

- `[OK]` — база данных обновлена;
- `[SKIP]` — уже обновлено;
- `[WARN]` / `[ERROR]` — с подробной информацией (неверные учетные данные, ошибка HTTP, сеть недоступна).

> ➡️ Путь к API MaxMind отправляет заголовок `If-Modified-Since`, поэтому уже обновленная база данных возвращает HTTP 304 и статус `[SKIP]`. Чтобы принудительно выполнить повторную загрузку, сначала удалите (или переименуйте) соответствующий `.mmdb` в `BIN_PATH/maxmind/`, чтобы заголовок не отправлялся. Резервный вариант GitHub не имеет такого поведения — он сравнивает md5 и повторно загружает при несоответствии.

---

## Ведение журнала действий

Все сеансы потоковой передачи записываются в журнал GeoIP, а данные устройства - в журнал `lines_live`:

|Колонка|Источник|
| --- | --- |
| `geoip_country_code` | `GeoIPService::getIPInfo()` |
| `isp` |`con_isp_name` из `GeoIPService::getISP()`|
| `external_device` |идентификатор типа устройства|
| `user_agent` |Заголовок HTTP User-Agent|
| `user_ip` |IP-адрес клиента|

Вошел в систему `live.php`, `vod.php`, `timeshift.php`, и `rtmp.php`.

Периодически архивируется с `lines_live` на `lines_activity` с помощью `ActivityCronJob`.

---

## Конфигурация

### Настройки

|Установка|Тип|Описание|
| --- | --- | --- |
| `show_isps` | `0/1` |включить обнаружение интернет-провайдера|
| `enable_isp_lock` | `0/1` |включить принудительную привязку к провайдеру|
| `block_svp` | `0/1` |заблокировать VPN/прокси/сервер (проверка ASN)|
| `block_streaming_servers` | `0/1` |блокировать IP-адреса центров обработки данных|
| `block_proxies` | `0/1` |блокировать IP-адреса прокси-провайдеров|
| `county_override_1st` | `0/1` |автоматическое назначение forced_country при первом подключении|
| `allow_countries` | `array` |белый список разрешенных кодов стран|
| `detect_restream_block_user` | `0/1` |автоматическое отключение пользователя при обнаружении повторного потока|
| `disallow_empty_user_agents` | `0/1` |отклонять запросы без использования User-Agent|
| `maxmind_account_id` | `string` |Учетная запись MaxMind API|
| `maxmind_license_key` | `string` |API-ключ MaxMind|
| `maxmind_editions` | `JSON` |множество загруженных изданий|

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Util/GeoIP.php` |низкоуровневый поиск GeoIP с кэшированием файлов|
| `src/Core/GeoIP/GeoIPService.php` |соответствие высокого уровня GeoIP + CIDR|
| `src/Core/GeoIP/MaxMindUpdater.php` |Загрузчик баз данных MaxMind|
| `src/Core/Config/Binaries.php` |GeoIP константы пути к файлу базы данных|
| `src/Core/Device/MobileDetect.php` |Библиотека Mobile_Detect|
| `src/Domain/Device/EnigmaService.php` |Управление STB Enigma2|
| `src/Domain/Device/MagService.php` |Управление MAG STB|
| `src/Domain/User/UserRepository.php` |GeoIP обогащение записей пользователей|
| `src/www/stream/auth.php` |потоковая авторизация со всеми проверками местоположения / устройства|
| `src/Streaming/Auth/StreamAuth.php` |Выбор сервера с поддержкой GeoIP|
| `src/Streaming/Balancer/ProxySelector.php` |Выбор прокси-сервера с поддержкой GeoIP|
