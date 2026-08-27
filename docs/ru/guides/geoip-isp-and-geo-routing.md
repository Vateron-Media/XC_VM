# GeoIP, Обнаружение интернет-провайдера и гео-маршрутизация

XC_VM использует базы данных MaxMind GeoIP2/GeoLite2 для определения геолокации и провайдера / ASN. Они обеспечивают потоковую аутентификацию (контроль доступа к стране/провайдеру/ASN/прокси-серверу), выбор географического сервера + прокси-сервера и ведение журнала активности.

> Для анализа с помощью User-Agent и аппаратной блокировки телеприставки смотрите сопутствующую страницу [Обнаружение устройств и блокировка STB](device-detection-and-stb-locking.md). И эта страница, и предыдущая сходятся в `src/Public/stream/auth.php`.

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

Результаты кэшируются в виде файлов с параметрами `CONS_TMP_PATH/{md5(ip)}_geo2` и `CONS_TMP_PATH/{md5(ip)}_isp`.

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

Когда `show_isps` включено, каждый потоковый запрос разрешает клиентский провайдер:

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

## Проверки контроля доступа (geo)

Они выполняются в режиме `src/Public/stream/auth.php` во время проверки токена. Проверки 5-6 (Пользовательский агент, тип устройства) отображаются на странице [Обнаружение устройства и блокировка STB](device-detection-and-stb-locking.md).

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

---

## Географический маршрут

GeoIP выбор сервера для хранения данных и прокси-сервера:

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
Извлекает `.mmdb` файлов из tar.gz архивов в `BIN_PATH/maxmind/`.

Пути к файлам базы данных (определены в `src/Core/Config/Binaries.php`):

```text
GEOLITE2_BIN  = BIN_PATH/maxmind/GeoLite2-Country.mmdb
GEOLITE2C_BIN = BIN_PATH/maxmind/GeoLite2-City.mmdb
GEOISP_BIN    = BIN_PATH/maxmind/GeoIP2-ISP.mmdb
```

### Автоматическое обновление

Базы данных обновляются с помощью задания cron `cron:maxmind` (`src/Cli/CronJobs/MaxMindCronJob.php`).
Он запускается **только по вторникам** — в день, когда MaxMind публикует новые версии. Логика меняется в настройках панели:

- если `maxmind_account_id` + `maxmind_license_key` + `maxmind_editions` задано, базы данных извлекаются непосредственно из MaxMind API (`MaxMindUpdater`, загружаются только настроенные версии).;
- если для учетных данных MaxMind задано значение **нет**, оно возвращается к версиям GitHub GeoLite2 (бесплатные базы данных).

### Ручное (принудительное) обновление

Чтобы немедленно обновить базы данных `.mmdb` на рабочей панели, запустите задание cron вручную **как корень** с флагом `--force` (это снимает ограничение "Только по вторникам").:

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:maxmind --force
```

Выходные статусы:

- `[OK]` — база данных обновлена;
- `[SKIP]` — уже обновлено;
- `[WARN]` / `[ERROR]` — с подробной информацией (неверные учетные данные, ошибка HTTP, сеть недоступна).

> ➡️ Путь к API MaxMind отправляет заголовок `If-Modified-Since`, поэтому уже обновленная база данных возвращает HTTP 304 и статус `[SKIP]`. Чтобы принудительно выполнить повторную загрузку, сначала удалите (или переименуйте) соответствующий `.mmdb` в `BIN_PATH/maxmind/`, чтобы заголовок не отправлялся. Резервный вариант GitHub не имеет такого поведения — он сравнивает md5 и повторные загрузки при несоответствии.

---

## Ведение журнала действий

Сеансы потоковой передачи записывают данные GeoIP (и устройства) в журнал `lines_live`:

|Колонка|Источник|
| --- | --- |
| `geoip_country_code` | `GeoIPService::getIPInfo()` |
| `isp` |`con_isp_name` из `GeoIPService::getISP()`|
| `external_device` |идентификатор типа устройства|
| `user_agent` |Заголовок HTTP-агента пользователя|
| `user_ip` |IP-адрес клиента|

Вошел в систему `live.php`, `vod.php`, `timeshift.php`, и `rtmp.php`.
Периодически архивируется с `lines_live` по `lines_activity` с помощью `ActivityCronJob`.

---

## Конфигурация (географические настройки)

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
| `maxmind_account_id` | `string` |Учетная запись MaxMind API|
| `maxmind_license_key` | `string` |API-ключ MaxMind|
| `maxmind_editions` | `JSON` |множество загруженных изданий|

(Настройки агента пользователя, такие как `disallow_empty_user_agents`, отображаются на странице устройства.)

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Util/GeoIP.php` |низкоуровневый поиск GeoIP с кэшированием файлов|
| `src/Core/GeoIP/GeoIPService.php` |соответствие высокого уровня GeoIP + CIDR|
| `src/Core/GeoIP/MaxMindUpdater.php` |Загрузчик баз данных MaxMind|
| `src/Cli/CronJobs/MaxMindCronJob.php` |Вторник / `--force` cron обновления базы данных|
| `src/Core/Config/Binaries.php` |GeoIP константы пути к файлу базы данных|
| `src/Domain/User/UserRepository.php` |GeoIP обогащение пользовательских записей|
| `src/Public/stream/auth.php` |потоковая авторизация с проверкой географического местоположения (1-4)|
| `src/Streaming/Auth/StreamAuth.php` |Выбор сервера с поддержкой GeoIP|
| `src/Streaming/Balancer/ProxySelector.php` |Выбор прокси-сервера с поддержкой GeoIP|
