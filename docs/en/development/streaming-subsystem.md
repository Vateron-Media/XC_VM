# Streaming Subsystem

The streaming subsystem handles live, VOD, and timeshift delivery.
It is the hot path (~10K-100K req/min, <50ms p99) and uses a separate lightweight bootstrap to avoid loading the full admin stack.

---

## Request Flow

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

nginx rewrites all streaming URLs to PHP entry points under `Public/stream/`:

| URL pattern | Entry point | Purpose |
| --- | --- | --- |
| `/auth/{token}` | `live.php` | Live stream delivery |
| `/vauth/{token}` | `vod.php` | Video-on-demand delivery |
| `/tsauth/{token}` | `timeshift.php` | Archive/timeshift playback |
| `/hls/{token}` | `segment.php` | HLS segment delivery |
| `/key/{token}` | `key.php` | AES-128 encryption key |
| `/subauth/{token}` | `subtitle.php` | Subtitle delivery |

---

## Directory Layout

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

## Bootstrap Pipeline

### 1. StreamingRequestBootstrap::init()

File: `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php`

Actions in order:

1. Load error codes, handler, paths, config, binaries.
2. Flood protection (HTTP only): check for `FLOOD_TMP_PATH . 'block_' . $rIP`.
3. Load settings from file cache (`CACHE_TMP_PATH . 'settings'`).
4. Host verification (HTTP only): validate against `allowed_domains`.
5. Initialize logger.
6. Fail-closed gate: return 404 if settings missing (except `/status`).
7. Call `StreamingBootstrap::bootstrap()`.

### 2. StreamingBootstrap::bootstrap()

File: `src/Streaming/StreamingBootstrap.php`

```php
public static function bootstrap($rFilename, $rSettings)
```

Classifies the endpoint:

- **Probe endpoints:** `probe`, `player_api` (light load)
- **Default endpoints:** `live`, `thumb`, `subtitle`, `timeshift`, `vod`, `status`
- **Privileged endpoints:** `rtmp`, `portal`

Loads `AsyncFileOperations.php` and `DatabaseHandler.php`, stores settings in `$GLOBALS['rSettings']` and access data in `$GLOBALS['rAccess']`, then calls `LegacyInitializer::initStreaming()`.

Returns the `$db` database instance (used by legacy entry points).

### 3. LegacyInitializer::initStreaming()

File: `src/Core/Init/LegacyInitializer.php`

Populates global variables from cache:

- `$GLOBALS['rSettings']`, `$GLOBALS['rServers']`, `$GLOBALS['rBouquets']`
- `$GLOBALS['rBlockedUA']`, `$GLOBALS['rBlockedISP']`, `$GLOBALS['rBlockedIPs']`
- `$GLOBALS['rAllowedIPs']`, `$GLOBALS['rProxies']`, `$GLOBALS['rSegmentSettings']`
- `$GLOBALS['rFFMPEG_CPU']`, `$GLOBALS['rFFMPEG_GPU']`, `$GLOBALS['rFFPROBE']`

Connects to database/Redis based on `$rSettings['redis_handler']`.

> **Important:** The streaming path reads exclusively from file cache. It does not query the database for settings or user lookups during normal operation.

---

## Token Authentication

File: `src/Streaming/Auth/StreamAuthMiddleware.php`

```php
StreamAuthMiddleware::decryptToken($rToken, $rSettings, $rServers, $rIP): array
```

Token contents:

| Field | Description |
| --- | --- |
| `username` | Line username |
| `password` | Line password |
| `stream_id` | Target stream ID |
| `expires` | Token expiration timestamp |
| `channel_info` | Stream metadata (on_demand, proxy, pid) |
| `user_info` | User permissions (max_connections, is_restreamer) |
| `country_code` | GeoIP country code |
| `video_codec` | Requested video codec |

Validation:

1. Decrypt token using `live_streaming_pass`.
2. Check expiration: `$rTokenData['expires'] < time() - $rServers[SERVER_ID]['time_offset']`.
3. Return parsed token data or trigger error.

Response headers are set via `StreamAuthMiddleware::sendStreamHeaders()`:

```text
Access-Control-Allow-Origin: *
X-XSS-Protection: 0
X-Content-Type-Options: nosniff
Alt-Svc: h3-29, h3-T051, h3-Q050 (HTTP/3 hints)
```

---

## Stream Delivery

### Live (live.php)

Main delivery endpoint (~650 lines):

1. Decrypt token via `StreamAuthMiddleware::decryptToken()`.
2. Resolve server/proxy: `StreamAuth::checkAccess()` + `ProxySelector::availableProxy()`.
3. Enforce connection limits: `StreamAuth::validateConnections()`.
4. Create connection record: `ConnectionTracker::createConnection()`.
5. Hand delivery to the **`xc_fanout` daemon** (see below): PHP emits an
   `X-Accel-Redirect` and exits the byte path — nginx streams the bytes.
   - **TS:** `X-Accel-Redirect: /xc_fanout/<id>?c=<uuid>&prebuffer=N` (nginx
     rewrites to the daemon's `/live/<id>`).
   - **HLS:** the playlist points at tokenized segments; `segment.php` serves live
     segments only through the daemon (`/xc_fanout_hls/<id>_<seq>`), else `404`.
6. On exit: `ShutdownHandler::handle()` → close connection record.

### VOD (vod.php)

Same auth flow as live. Reads from `VOD_PATH` instead of `STREAMS_PATH`.

### Timeshift (timeshift.php)

Serves archived segments (timeshift / catch-up) from the archive path.

### Daemon delivery — `xc_fanout`

Live client delivery (TS **and** HLS) is **daemon-only**: PHP authorizes the
viewer and then leaves the byte path entirely, so a viewer no longer pins a
PHP-FPM worker for the life of the stream.

- **Fan-out.** `xc_fanout` (a bundled Go daemon) pulls each source **once** and
  fans it out to every viewer over a unix socket, with an in-RAM HLS segmenter.
  PHP is out of the per-viewer byte path: the worker-per-viewer chase-read
  serving loop and the `HLSGenerator::generateHLS()` client-serving path are no
  longer used for live delivery (`generateHLS()` is retained in the class but has
  no callers). `AsyncFileOperations::awaitFileExists()` is **not** removed — it is
  still used for stream-startup waits and the VOD/timeshift byte path (see the
  Performance table).
- **Two sockets.** A client socket (nginx-facing) serves `/live/<id>` and
  `/hls/...`; a PHP-only control socket registers sources
  (`PUT /streams/<id>` / `/ingest/<id>`), answers off-air status
  (`GET /streams/<id>`, `GET /probe/<id>`) and exposes telemetry.
- **Telemetry / reconciliation.** `fanout_sync` polls `GET /rates` (per-uuid
  KB/s → `lines_divergence`) and `GET /connections` (reconciles `lines_live`
  rows, since PHP cannot see a disconnect under `X-Accel`).
- **Off-air.** If the daemon reports no data (`has_data=false` / stale), PHP
  shows a "not on air" page instead of letting the viewer hang.
- **On-disk HLS retained** only for timeshift / thumbnails / `.analyse` /
  `MonitorCommand` — not for client delivery.

#### Send-message overlay

The admin "Send Message" action burns a text banner onto **one** viewer's video.
PHP posts it to the daemon control socket
(`FanoutClient::sendSignal` → `POST /signal/<uuid>`), and the daemon applies an
ffmpeg `drawtext` overlay to that viewer's next HLS segment (or a short ~5s TS
window), one-shot, best-effort — a signal never breaks playback. The daemon must
be launched with an ffmpeg that actually has the `drawtext` filter, so the
`service` launcher picks a drawtext-capable build.

---

## Connection Management

### ConnectionTracker

Manages live connection state. Backend is selected by `$rSettings['redis_handler']`:

**Redis (preferred for scale):**

- Connections stored in sorted sets:
  - `LINE#{identity}` — connections for user
  - `STREAM#{stream_id}` — connections for stream
  - `SERVER#{server_id}` — connections on server

**MySQL (fallback):**

- Table: `lines_live` with fields: `activity_id`, `user_id`, `stream_id`, `server_id`, `uuid`, `pid`, `hls_end`

Key methods:

```php
ConnectionTracker::createConnection($data)
ConnectionTracker::updateConnection($connection, $changes, 'open'|'close')
ConnectionTracker::getConnection($uuid)
ConnectionTracker::getLineConnections($user_id)
ConnectionTracker::getCapacity()
```

### ConnectionLimiter

File: `src/Streaming/Protection/ConnectionLimiter.php`

Enforces per-user connection limits when `max_connections` is exceeded:

| Priority | Criteria | Action |
| --- | --- | --- |
| 2 | Same IP + same User-Agent | Kill first |
| 1 | Same IP (any UA) | Kill next |
| 0 | Any connection | Kill as fallback |

Settings:

- `disallow_2nd_ip_con` — enforce single IP per user
- `ip_subnet_match` — match by /24 subnet instead of exact IP
- `restrict_same_ip` — return error on IP mismatch instead of killing

### ShutdownHandler

File: `src/Streaming/Lifecycle/ShutdownHandler.php`

Registered via `register_shutdown_function()`. On PHP process exit:

1. Close connection record in `lines_live` or Redis.
2. Delete tmp files at `CONS_TMP_PATH . $uuid`.
3. Remove on-demand stream from queue if applicable.

---

## Load Balancing

### Server Selection (StreamAuth::checkAccess)

File: `src/Streaming/Auth/StreamAuth.php`

```php
public static function checkAccess($rUserInfo, $rUserIP, $rCountryCode, $rUserISP = ''): int|false
```

Algorithm:

1. Get available servers: `server_online == true`, `server_type == 0`, `online_clients < total_clients`.
2. Sort by capacity (ascending) — least loaded first.
3. Apply GeoIP routing (if `enable_geoip == 1`):
   - Exact country match → select immediately.
   - `geoip_type == 'strict'` → exclude non-matching.
   - Otherwise → assign priority weight.
4. Apply ISP routing (if `enable_isp == 1`): same logic as GeoIP.
5. Return server with lowest capacity from highest-priority group.

### Proxy Selection (ProxySelector::availableProxy)

File: `src/Streaming/Balancer/ProxySelector.php`

```php
public static function availableProxy($rProxies, $rCountryCode, $rUserISP = ''): int|null
```

Same algorithm as `StreamAuth::checkAccess()` but applied to proxy server list.

---

## Rate Limiting and Flood Protection

Three layers:

### 1. nginx (connection level)

```nginx
limit_req_zone $binary_remote_addr zone=one:30m rate=20r/s;
limit_req zone=one burst=8;
```

20 requests/second per IP with 8-request burst. 30-minute sliding window.

### 2. StreamingRequestBootstrap (IP block)

```php
if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
    http_response_code(403);
    exit();
}
```

File-based IP blocking. Block files are created by upstream flood detection logic.

### 3. ConnectionLimiter (per-user)

Enforced after token validation. Limits concurrent streams per user based on `max_connections`.

---

## HLS Encryption

Client HLS is served by the `xc_fanout` daemon (see [Daemon delivery](#daemon-delivery-xc_fanout)), so encryption happens **daemon-side**:

1. `StreamProcess` writes the stream's AES-128 key/IV to `content/streams/<id>_.key` / `_.iv`.
2. At ingest registration (`FanoutClient::registerIngest`), when `encrypt_hls` is on, the key/IV are handed to the daemon, which encrypts the HLS segments it serves and emits a matching `#EXT-X-KEY`.
3. `HLSGenerator::tokenizeDaemonPlaylist()` rewrites the daemon playlist's segment URLs into per-segment auth'd `/hls/<token>` links that `segment.php` proxies from the daemon.
4. The AES key is delivered to players by `key.php` (`src/Public/stream/key.php`) using the same token mechanism.

> The legacy `HLSGenerator::generateHLS()` (which built and encrypted an on-disk HLS playlist for PHP to serve) is retained in the class but is **no longer on the client path** after the daemon cutover.

---

## Performance

Key design decisions for throughput and latency:

| Feature | Mechanism |
| --- | --- |
| Stream-online wait | `AsyncFileOperations::awaitFileExists()` waits for `_.pid`/`_.monitor`/first segment as a stream comes up (and in the VOD/timeshift byte path). Live client delivery is daemon-served — not chase-read by PHP. |
| Zero-CPU sleep | `time_nanosleep()` via `AsyncFileOperations::efficientSleep()` |
| nginx buffering | 128 x 32KB buffers per request |
| Connection pooling | Redis (preferred) or persistent MySQL |
| Cache-only reads | Settings and user data read from file cache, no DB queries |
| Early exit (VOD/timeshift) | Those byte loops poll `connection_status()` to stop when the client disconnects. Live has no per-viewer PHP byte loop (daemon-served). |
| Settings refresh | Every 5 minutes (300s) to catch config changes without restart |

---

## File System Paths

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

## Diagnostics & Tooling

The standalone stream-integrity tool (`tools/stream-check/stream_check.py`) now lives on its own page — see [Streaming Diagnostics & Tooling](streaming-diagnostics.md).

---

## Design rationale (ADRs)

Why live delivery moved off tmpfs and out of the PHP byte path — the decisions behind the current
`xc_fanout` architecture — is recorded in the Architecture Decision Records (repo-internal notes,
not part of the published site):

- [ADR 0001 — Tmpfs-free streaming](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0001-tmpfs-free-streaming.md) — PHP out of the byte path, native fan-out, in-RAM HLS.
- [ADR 0002 — `xc_fanout` daemon](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0002-xc-fanout-daemon.md) — the native live fan-out daemon.
- [ADR 0003 — Full daemon cutover](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0003-full-daemon-cutover.md) — retiring the legacy byte path for live.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Streaming/StreamingBootstrap.php` | core streaming bootstrap |
| `src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php` | HTTP-level init |
| `src/Streaming/Auth/StreamAuth.php` | server selection and connection validation |
| `src/Streaming/Auth/StreamAuthMiddleware.php` | token decryption and response headers |
| `src/Streaming/Balancer/ProxySelector.php` | proxy server selection |
| `src/Streaming/Protection/ConnectionLimiter.php` | per-user connection limits |
| `src/Streaming/Delivery/HLSGenerator.php` | M3U8 playlist generation |
| `src/Streaming/Delivery/StreamRedirector.php` | stream availability and server routing |
| `src/Streaming/AsyncFileOperations.php` | non-blocking filesystem utilities |
| `src/Streaming/Lifecycle/ShutdownHandler.php` | connection cleanup on exit |
| `src/Domain/Stream/ConnectionTracker.php` | connection state in Redis/MySQL |
| `src/Core/Init/LegacyInitializer.php` | global variable setup for streaming |
| `tools/stream-check/stream_check.py` | queue-integrity checker + playlist batch + live buffer dashboard + SVG grapher |
