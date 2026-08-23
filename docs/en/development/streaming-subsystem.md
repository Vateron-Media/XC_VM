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

nginx rewrites all streaming URLs to PHP entry points under `www/stream/`:

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

Serves archived segments. Uses `TimeshiftClient` for archive file resolution.

### Daemon delivery — `xc_fanout`

Live client delivery (TS **and** HLS) is **daemon-only**: PHP authorizes the
viewer and then leaves the byte path entirely, so a viewer no longer pins a
PHP-FPM worker for the life of the stream.

- **Fan-out.** `xc_fanout` (a bundled Go daemon) pulls each source **once** and
  fans it out to every viewer over a unix socket, with an in-RAM HLS segmenter.
  PHP is out of the per-viewer byte path; the old chase-read loop
  (`AsyncFileOperations::awaitFileExists()`) and `HLSGenerator::generateHLS()`
  serving path were removed in the cutover.
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

File: `src/Streaming/Delivery/HLSGenerator.php`

```php
public static function generateHLS($rSettings, $rM3U8, $rUsername, $rPassword,
    $rStreamID, $rUUID, $rIP, ...): string|false
```

When `encrypt_hls == true`:

1. Generate AES-128 key token from IP + StreamID + salt.
2. Replace IV with content from `STREAMS_PATH . $rStreamID . '_.iv'`.
3. Encrypt each segment reference: `IP/StreamID/Segment/UUID/SERVER_ID/VideoCodec/OnDemand`.
4. Replace segment names with `/hls/{encrypted_token}`.

Key delivery happens via `key.php` using the same token mechanism.

---

## Performance

Key design decisions for throughput and latency:

| Feature | Mechanism |
| --- | --- |
| Non-blocking file wait | `AsyncFileOperations::awaitFileExists()` uses inotify (Linux) or optimized polling |
| Zero-CPU sleep | `time_nanosleep()` via `AsyncFileOperations::efficientSleep()` |
| nginx buffering | 128 x 32KB buffers per request |
| Connection pooling | Redis (preferred) or persistent MySQL |
| Cache-only reads | Settings and user data read from file cache, no DB queries |
| Early exit | Monitors `connection_status()` every 5 seconds to detect client disconnect |
| Settings refresh | Every 5 minutes (300s) to catch config changes without restart |

---

## File System Paths

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

## Diagnostics & Tooling

Two tools verify that a stream delivers correctly — that segments arrive in order
and the delivery queue does not break.

### `tools/stream-check/stream_queue_check.py` (Python, stdlib only)

Standalone monitor for **segment/packet queue integrity** with an optional **live
buffer dashboard**. Auto-detects HLS vs MPEG-TS.

```bash
python3 tools/stream-check/stream_queue_check.py "<url>" --duration 30        # batch check
python3 tools/stream-check/stream_queue_check.py "<url>" --json               # cron / monitoring
python3 tools/stream-check/stream_queue_check.py "<url>" --live --duration 0  # live dashboard
```

What "queue intact" means per stream type:

| Stream | Queue check |
| --- | --- |
| HLS (`.m3u8`) | `EXT-X-MEDIA-SEQUENCE` monotonic and contiguous (no dropped or rewound segments), no `EXT-X-DISCONTINUITY`, every newly appearing segment downloadable. Master playlists are resolved to their first variant. |
| MPEG-TS (`.ts`, `/play/<token>/ts`) | per-PID `continuity_counter` (lost / duplicated / reordered packets = queue break), sync-byte loss, transport-error indicator, and delivery stalls. |

Key options:

| Flag | Purpose |
| --- | --- |
| `--duration N` | seconds to observe (`0` = until Ctrl-C in `--live`) |
| `--tolerance N` | allow N transient queue breaks before reporting `BROKEN` (ignores rare source glitches relayed by `-c copy`) |
| `--stall-timeout S` | delivery gap counted as a stall; keep it above the segment duration (default 15) |
| `--live` | colored TUI dashboard (below) |
| `--prebuffer S` / `--buffer-target S` | live: virtual-player prebuffer and buffer-graph scale |
| `--json` / `--no-color` | machine output / disable ANSI |

Exit code: `0` healthy, `2` queue problem or stall, `1` usage.

#### Live dashboard (`--live`)

Models a virtual player: the playhead advances at wall-clock rate while content is
"received". For **TS** the received timeline comes from **PCR** (the stream clock);
for **HLS** from the segments' `EXTINF` durations. Buffered playtime ("cache") =
received − played; if it reaches zero the playhead freezes (a rebuffer event).

```text
  STREAM QUEUE / BUFFER MONITOR   TS   up 00:22
  cache buffer (s), last 60s:
  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▄▄▄▇▇▇▆▆▆▅▅▅▄▄▇▇▇▆▆▆▅   <- burst-then-drain = delivery sawtooth
  IN CACHE : [█████████████████░░░░░░░░░░░░░]  11.6s / 20s
  PLAYING  : PLAYING     head 00:18   received 00:29
  rate 1000 kbit/s   received 4.1 MB   last data 7.0s ago
  QUEUE OK   cc:0 sync:0 gaps:0 disc:0   rebuffers:0
```

The buffer graph and gauge are colored green (healthy) / yellow (low) / red
(starving). For HLS a row of blocks shows the segments still in cache ahead of the
playhead.

### `console.php stream:check` (PHP, companion)

Probes a source URL and, with `--decode`, pulls and decodes the media to catch
broken/corrupt segments. HLS is checked segment-by-segment; a single-socket TS
endpoint is captured with cURL and decoded offline (ffmpeg's live `-i` hangs on
it). Source: `src/Cli/Commands/StreamCheckCommand.php`.

```bash
console.php stream:check "<url>"                  # metadata probe (type, codecs)
console.php stream:check "<url>" --decode=30 --json
```

> **Note — delivery pacing.** The live TS delivery loop in `live.php` drains
> available data without throttling and only pauses when caught up to ffmpeg's
> write head. An earlier version slept one second after every read, capping
> throughput at `read_buffer_size` per second and starving clients;
> `stream_queue_check.py --live` visualises the resulting buffer behaviour.

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
| `src/Cli/Commands/StreamCheckCommand.php` | `stream:check` — probe/decode a stream for broken segments |
| `tools/stream-check/stream_queue_check.py` | queue-integrity monitor + live buffer dashboard |
