# GeoIP and Device Detection

XC_VM uses MaxMind GeoIP2/GeoLite2 databases for geolocation and ISP detection, and the Mobile_Detect library for user agent parsing.
These systems are integrated into streaming authentication for access control, geographic routing, and activity logging.

---

## Navigation

- [GeoIP Lookup](#geoip-lookup)
- [ISP and ASN Detection](#isp-and-asn-detection)
- [Device Detection](#device-detection)
- [Access Control Checks](#access-control-checks)
- [Geographic Routing](#geographic-routing)
- [Database Updates](#database-updates)
- [Activity Logging](#activity-logging)
- [Configuration](#configuration)
- [Related Files](#related-files)

---

## GeoIP Lookup

Two classes provide GeoIP lookups:

### GeoIP (utility)

File: `src/core/Util/GeoIP.php`

```php
GeoIP::getCountry($ip): array|false    // GeoLite2-City lookup
GeoIP::getISP($ip): array|false        // GeoIP2-ISP lookup
GeoIP::isISPBlocked($ispName, $blockedISPs): int
GeoIP::isASNBlocked($asn, $blockedServers): bool
```

Results are cached as files at `CONS_TMP_PATH/{md5(ip)}_geo2` and `CONS_TMP_PATH/{md5(ip)}_isp`.

### GeoIPService (high-level)

File: `src/core/GeoIP/GeoIPService.php`

```php
GeoIPService::getIPInfo($rIP): array|false      // city-level lookup
GeoIPService::getISP($rIP): array|false         // ISP + ASN data
GeoIPService::matchCIDR($rASN, $rIP): array|null  // hosting/proxy detection
```

`matchCIDR()` checks IP against CIDR blocks stored per ASN at `CIDR_TMP_PATH/{asn}`. Returns flags for hosting and proxy detection.

---

## ISP and ASN Detection

When `show_isps` is enabled, each streaming request resolves the client ISP:

```php
$rISPLock = GeoIPService::getISP($rIP);
// returns: ['isp' => 'Comcast', 'autonomous_system_number' => 7922, ...]
```

ISP data is stored on the user record:

| Field | Description |
| --- | --- |
| `isp_desc` | locked ISP name |
| `as_number` | locked ASN |
| `con_isp_name` | current connection ISP (transient) |
| `is_isplock` | enable ISP binding (0/1) |
| `isp_violate` | ISP violation flag — blocks access |

ISP lock violation occurs when:

```text
con_isp_name != isp_desc
AND is_isplock = 1
AND is_stalker = 0
AND enable_isp_lock = 1
```

---

## Device Detection

### Mobile_Detect

File: `src/core/Device/MobileDetect.php`

Library (v2.8.45) for user agent parsing:

```php
$detect = new Mobile_Detect();
$detect->isMobile();    // phones
$detect->isTablet();    // tablets
$detect->isAndroid();   // brand-specific
```

Used in `src/bootstrap.php` to detect mobile devices for responsive admin UI.

### Set-Top Box Devices

**EnigmaService** (`src/domain/Device/EnigmaService.php`):

Manages Enigma2 STB accounts. Lock fields: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

**MagService** (`src/domain/Device/MagService.php`):

Manages MAG STB accounts. Lock fields: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

Both support:

- `lock_device` — hardware lock
- `is_isplock` — ISP binding
- `forced_country` — force user to specific country

---

## Access Control Checks

All checks happen in `src/www/stream/auth.php` during token validation:

### 1. Country validation

```text
if forced_country is set:
    country_code must match forced_country
    error: FORCED_COUNTRY_INVALID

if allow_countries whitelist exists:
    country_code must be in whitelist
    error: NOT_IN_ALLOWED_COUNTRY
```

### 2. ISP lock enforcement

```text
if is_isplock = 1 and is_stalker = 0:
    con_isp_name must match isp_desc
    error: ISP_BLOCKED
```

### 3. ASN blocking

```text
if block_svp = 1:
    check ASN against blocked servers
    error: ASN_BLOCKED
```

### 4. Hosting and proxy detection

```text
GeoIPService::matchCIDR($asn, $ip)
    flag[3] = hosting → error: HOSTING_DETECT
    flag[4] = proxy → error: PROXY_DETECT
```

Also checks `X-XC_VM-DETECT` header for restream detection.

### 5. User-Agent blocking

```text
check against BlocklistService::checkBlockedUAs()
error: BLOCKED_USER_AGENT

if user has allowed_ua set:
    user_agent must match one entry
    error: NOT_IN_ALLOWED_UAS
```

### 6. Device type validation

```text
MAG device flag must match token
error: DEVICE_NOT_ALLOWED or TOKEN_EXPIRED
```

---

## Geographic Routing

GeoIP data drives server and proxy selection:

### Server selection (StreamAuth::checkAccess)

When `enable_geoip == 1`:

- Exact country match → select that server immediately.
- `geoip_type == 'strict'` → exclude non-matching servers.
- Otherwise → assign priority weight (1 for low, 2 for normal).

### Proxy selection (ProxySelector::availableProxy)

Same logic applied to proxy server list. Uses both country code and ISP name for routing.

```php
ProxySelector::availableProxy(
    array_keys($rProxies),
    $rCountryCode,
    $rUserInfo['con_isp_name']
);
```

---

## Database Updates

File: `src/core/GeoIP/MaxMindUpdater.php`

```php
$updater = MaxMindUpdater::fromSettings($settings);
$updater->update();  // downloads and extracts all configured editions
```

Supported editions:

- `GeoLite2-Country`, `GeoLite2-City`, `GeoLite2-ASN` (free)
- `GeoIP2-Country`, `GeoIP2-City`, `GeoIP2-ISP`, `GeoIP2-Anonymous-IP` (paid)

Downloads via MaxMind API using `maxmind_account_id` and `maxmind_license_key`.
Extracts `.mmdb` files from tar.gz archives to `BIN_PATH/maxmind/`.

Database file paths (defined in `src/core/Config/Binaries.php`):

```text
GEOLITE2_BIN  = BIN_PATH/maxmind/GeoLite2-Country.mmdb
GEOLITE2C_BIN = BIN_PATH/maxmind/GeoLite2-City.mmdb
GEOISP_BIN    = BIN_PATH/maxmind/GeoIP2-ISP.mmdb
```

---

## Activity Logging

All streaming sessions log GeoIP and device data to `lines_live`:

| Column | Source |
| --- | --- |
| `geoip_country_code` | `GeoIPService::getIPInfo()` |
| `isp` | `con_isp_name` from `GeoIPService::getISP()` |
| `external_device` | device type identifier |
| `user_agent` | HTTP User-Agent header |
| `user_ip` | client IP |

Logged in `live.php`, `vod.php`, `timeshift.php`, and `rtmp.php`.

Periodically archived from `lines_live` to `lines_activity` by `ActivityCronJob`.

---

## Configuration

### Settings

| Setting | Type | Description |
| --- | --- | --- |
| `show_isps` | `0/1` | enable ISP detection |
| `enable_isp_lock` | `0/1` | enable ISP binding enforcement |
| `block_svp` | `0/1` | block VPN/proxy/server (ASN check) |
| `block_streaming_servers` | `0/1` | block datacenter IPs |
| `block_proxies` | `0/1` | block proxy provider IPs |
| `county_override_1st` | `0/1` | auto-assign forced_country on first connection |
| `allow_countries` | `array` | whitelist of allowed country codes |
| `detect_restream_block_user` | `0/1` | auto-disable user on restream detection |
| `disallow_empty_user_agents` | `0/1` | reject requests without User-Agent |
| `maxmind_account_id` | `string` | MaxMind API account |
| `maxmind_license_key` | `string` | MaxMind API key |
| `maxmind_editions` | `JSON` | array of downloaded editions |

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Util/GeoIP.php` | low-level GeoIP lookups with file caching |
| `src/core/GeoIP/GeoIPService.php` | high-level GeoIP + CIDR matching |
| `src/core/GeoIP/MaxMindUpdater.php` | MaxMind database downloader |
| `src/core/Config/Binaries.php` | GeoIP database file path constants |
| `src/core/Device/MobileDetect.php` | Mobile_Detect library |
| `src/domain/Device/EnigmaService.php` | Enigma2 STB management |
| `src/domain/Device/MagService.php` | MAG STB management |
| `src/domain/User/UserRepository.php` | GeoIP enrichment on user records |
| `src/www/stream/auth.php` | streaming auth with all geo/device checks |
| `src/streaming/Auth/StreamAuth.php` | GeoIP-aware server selection |
| `src/streaming/Balancer/ProxySelector.php` | GeoIP-aware proxy selection |
