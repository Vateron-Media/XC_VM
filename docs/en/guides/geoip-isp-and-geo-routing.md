# GeoIP, ISP Detection & Geo Routing

XC_VM uses MaxMind GeoIP2/GeoLite2 databases for geolocation and ISP/ASN detection. These feed streaming authentication (country/ISP/ASN/proxy access control), geographic server + proxy selection, and activity logging.

> For User-Agent parsing and set-top-box hardware locking, see the companion page [Device Detection & STB Locking](device-detection-and-stb-locking.md). Both this page and that one converge in `src/Public/stream/auth.php`.

---

## GeoIP Lookup

Two classes provide GeoIP lookups:

### GeoIP (utility)

File: `src/Core/Util/GeoIP.php`

```php
GeoIP::getCountry($ip): array|false    // GeoLite2-City lookup
GeoIP::getISP($ip): array|false        // GeoIP2-ISP lookup
GeoIP::isISPBlocked($ispName, $blockedISPs): int
GeoIP::isASNBlocked($asn, $blockedServers): bool
```

Results are cached as files at `CONS_TMP_PATH/{md5(ip)}_geo2` and `CONS_TMP_PATH/{md5(ip)}_isp`.

### GeoIPService (high-level)

File: `src/Core/GeoIP/GeoIPService.php`

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

## Access Control Checks (geo)

These run in `src/Public/stream/auth.php` during token validation. Checks 5-6 (User-Agent, device type) live on the [Device Detection & STB Locking](device-detection-and-stb-locking.md) page.

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

Also checks the `X-XC_VM-DETECT` header for restream detection.

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

File: `src/Core/GeoIP/MaxMindUpdater.php`

```php
$updater = MaxMindUpdater::fromSettings($settings);
$updater->update();  // downloads and extracts all configured editions
```

Supported editions:

- `GeoLite2-Country`, `GeoLite2-City`, `GeoLite2-ASN` (free)
- `GeoIP2-Country`, `GeoIP2-City`, `GeoIP2-ISP`, `GeoIP2-Anonymous-IP` (paid)

Downloads via MaxMind API using `maxmind_account_id` and `maxmind_license_key`.
Extracts `.mmdb` files from tar.gz archives to `BIN_PATH/maxmind/`.

Database file paths (defined in `src/Core/Config/Binaries.php`):

```text
GEOLITE2_BIN  = BIN_PATH/maxmind/GeoLite2-Country.mmdb
GEOLITE2C_BIN = BIN_PATH/maxmind/GeoLite2-City.mmdb
GEOISP_BIN    = BIN_PATH/maxmind/GeoIP2-ISP.mmdb
```

### Automatic update

The databases are updated by the `cron:maxmind` cron job (`src/Cli/CronJobs/MaxMindCronJob.php`).
It runs **only on Tuesdays** — the day MaxMind publishes new releases. The logic branches on panel settings:

- if `maxmind_account_id` + `maxmind_license_key` + `maxmind_editions` are set, databases are pulled straight from the MaxMind API (`MaxMindUpdater`, only the configured editions are downloaded);
- if MaxMind credentials are **not** set, it falls back to the GitHub GeoLite2 releases (free databases).

### Manual (forced) update

To refresh the `.mmdb` databases immediately on a running panel, run the cron job by hand **as root** with the `--force` flag (it lifts the "Tuesday only" restriction):

```bash
/home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:maxmind --force
```

Output statuses:

- `[OK]` — database updated;
- `[SKIP]` — already up to date;
- `[WARN]` / `[ERROR]` — with details (bad credentials, HTTP error, network unavailable).

> ⚠️ The MaxMind API path sends an `If-Modified-Since` header, so an already-fresh database returns HTTP 304 and a `[SKIP]` status. To force a re-download, first delete (or rename) the corresponding `.mmdb` in `BIN_PATH/maxmind/` so the header is not sent. The GitHub fallback has no such behavior — it compares md5 and re-downloads on mismatch.

---

## Activity Logging

Streaming sessions log GeoIP (and device) data to `lines_live`:

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

## Configuration (geo settings)

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
| `maxmind_account_id` | `string` | MaxMind API account |
| `maxmind_license_key` | `string` | MaxMind API key |
| `maxmind_editions` | `JSON` | array of downloaded editions |

(User-Agent settings such as `disallow_empty_user_agents` live on the device page.)

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Util/GeoIP.php` | low-level GeoIP lookups with file caching |
| `src/Core/GeoIP/GeoIPService.php` | high-level GeoIP + CIDR matching |
| `src/Core/GeoIP/MaxMindUpdater.php` | MaxMind database downloader |
| `src/Cli/CronJobs/MaxMindCronJob.php` | Tuesday / `--force` database update cron |
| `src/Core/Config/Binaries.php` | GeoIP database file path constants |
| `src/Domain/User/UserRepository.php` | GeoIP enrichment on user records |
| `src/Public/stream/auth.php` | streaming auth with the geo checks (1-4) |
| `src/Streaming/Auth/StreamAuth.php` | GeoIP-aware server selection |
| `src/Streaming/Balancer/ProxySelector.php` | GeoIP-aware proxy selection |
