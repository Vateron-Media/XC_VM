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

Same auth flow as live. Reads from `VOD_PATH` instead of `STREAMS_PATH`. Byte ranges (seeking) are resolved by `Streaming\Delivery\HttpRange` (RFC 7233 single ranges, suffix ranges included). A direct-proxy movie is relayed with cURL, asking the source for exactly the requested range.

### Timeshift (timeshift.php)

Serves archived segments (timeshift / catch-up) from the archive path. A TS request streams the minute files back to back; a byte range (a seek) is mapped onto them — files before the start are skipped, the first is entered at the right offset and delivery stops at the range end.

### Daemon delivery — `xc_fanout`

Live client delivery (TS **and** HLS) is **daemon-only**: PHP authorizes the
viewer and then leaves the byte path entirely, so a viewer no longer pins a
PHP-FPM worker for the life of the stream.

- **Fan-out.** `xc_fanout` (a bundled Go daemon) pulls each source **once** and
  fans it out to every viewer over a unix socket, with an in-RAM HLS segmenter.
  PHP is out of the per-viewer byte path: the worker-per-viewer chase-read
  serving loop and the on-disk `generateHLS()` client path are gone.
  `AsyncFileOperations::awaitFileExists()` is still used for stream-startup
  waits and the VOD/timeshift byte path (see the Performance table).
- **Who feeds the daemon.** Since the daemon is the only client path, every live
  producer must feed it, or the channel cannot be watched:
  the stream's ffmpeg tees into its ingest socket (`buildLive()`; loopback
  children included), the daemon supervisor's producers do the same, the PHP
  producers — the LLOD segmenter (`LlodCommand`) and the loopback relay
  (`LoopbackCommand`) — push through `Streaming\Fanout\IngestFeeder`, and a
  **delayed** stream is fed by `DelayCommand`, which pushes each delayed segment
  as it publishes it, paced over the segment's duration (its encoder output is
  the undelayed one, so the tee is not used for it). `IngestFeeder` buffers what
  a non-blocking write could not send (a short write no longer tears packets),
  re-registers and redials after a daemon restart, and carries the HLS key.
- **Two sockets.** A client socket (nginx-facing) serves `/live/<id>` and
  `/hls/...`; a PHP-only control socket registers sources
  (`PUT /streams/<id>` / `/ingest/<id>`), answers off-air status
  (`GET /streams/<id>`, `GET /probe/<id>`) and exposes telemetry.
- **Telemetry / reconciliation.** `fanout_sync` polls `GET /rates` (per-uuid
  KB/s → `lines_divergence`) and reconciles `GET /connections` against the
  `lines_live` rows in both directions: a row whose viewer left the daemon is
  closed (PHP cannot see a disconnect under `X-Accel`), and a daemon viewer whose
  row is gone — reaped, expired or banned line — is dropped after a 20 s grace
  (`DELETE /connections/<uuid>`).
- **Kicks and connection limits.** A daemon-served TS viewer's row has `pid = 0`:
  there is no worker to kill. `ConnectionLimiter` / `ConnectionTracker::closeConnection()`
  end it with `ConnectionTracker::dropDaemonViewer()` — `FanoutClient::dropConnection()`
  on this node, or a `drop_con` signal that the viewer's node turns into the
  same call. The limiter never evicts the requesting connection itself (it is
  identified by uuid, since every daemon row shares pid 0).
- **Off-air.** If the daemon reports no data (`has_data=false` / stale), PHP
  shows a "not on air" page instead of letting the viewer hang.
- **On-disk HLS retained** only for timeshift / thumbnails / `.analyse` /
  loopback children / the on-demand start checks — not for client delivery.

#### Stream supervision and the native remuxer

With **Fanout Encoder Supervision** on (`fanout_supervise`, migration 018, on by default), a
live stream gets no PHP watchdog. `StreamProcess::startMonitor()` builds its commands and hands
them to the daemon's supervisor (`FanoutClient::supervise` → `PUT /monitor/<id>`), which starts,
watches and restarts them — failover, priority backup, forced source, stalled output, audio loss,
frame-rate drop and scheduled restart included. PHP keeps building every command and making every
database write; the daemon runs what it is handed.

- **Hand-over** — `StreamProcess::superviseStream()` asks the daemon first
  (`GET /monitors/state`: reachable, `accepting`), builds the spec
  (`StreamProcess::buildSupervisorSpec()`: one command per source, policy and health mapped from
  the settings `MonitorCommand` obeyed), records the daemon's pid as `monitor_pid`, then hands it
  over. Without a restart a running encoder is **adopted**, not replaced; `cron:streams` moves
  PHP-monitored streams over this way on its next pass.
- **Commands** — a copy-only live stream runs the daemon's native remuxer, `xc_fanout remux`,
  built by `StreamProcess::buildNativeLive()` beside `buildLive()`: it reads the source natively
  (MPEG-TS over http(s), HLS with TS segments, udp/rtp) and writes the same on-disk HLS and daemon
  feed as ffmpeg's `-f tee` line, with no ffmpeg. Which streams qualify is
  `StreamProcess::nativeRefusal()` / `isNativeSource()`; `fanout_source_backend` decides:
  `auto` = remuxer with the ffmpeg command as `fallback_cmd` (used when the remuxer exits 3,
  "cannot serve this source"), `native` = remuxer only, `ffmpeg` = ffmpeg only. The panel only
  writes a remuxer command when the node's daemon advertises it (`features` in
  `GET /monitors/state`, `FanoutClient::supportsRemux()`) — an older binary would misparse it.
- **Which producer ran, and why** — the command handed over is recorded beside the stream's
  files like the self-launched path's `<id>_.ffmpeg`: `<id>_.fanout` for the remuxer,
  `<id>_.ffmpeg` for ffmpeg (in `auto`, both). When the native backend is on and a stream runs
  ffmpeg anyway, `StreamProcess::nativeRefusal()`'s reason is appended to `<id>.errors`
  (`[panel] ffmpeg runs this stream: transcoding is enabled`), the same file the producer's
  stderr goes to. The qualifying type is `streams_types.type_key` = `live`; `gen_timestamps` and
  `read_native` are deliberately not refusals (both default to 1, so they say nothing about the
  channel — see the daemon runbook).
- **Reconcile** — the daemon cannot write the database, so `StreamProcess::reconcileSupervised()`
  copies its state into `streams_servers` (status, pid, current source, codecs, resolution,
  measured bitrate): every `cron:streams` pass, and every 5 s from the `signals` daemon. A
  supervised stream whose row is gone or marked stopped is released. The codecs and picture size
  are written to the `stream_info` JSON as well as the flat columns — that JSON is what the
  streams list renders, what the adaptive master playlist takes `BANDWIDTH`/`RESOLUTION` from and
  where `stream/auth.php` reads the viewer's video codec, and a supervised stream never runs
  ffprobe to fill it.
- **Stop** — `StreamProcess::stopStream()` releases first (`DELETE /monitor/<id>`, which kills the
  producer); killing the producer first is what the supervisor restarts.
- **Fallback to PHP** — a daemon that is down or not accepting, and the stream kinds it does not
  take (delay, created channels, `yt-dlp` platform sources), run `MonitorCommand` as before;
  `MonitorCommand` stands down for a stream the daemon supervises.
- **"Is it watched?"** — for a supervised stream `monitor_pid` is the daemon's pid, so callers use
  `StreamProcess::isWatched()` (PHP monitor alive, or supervised) rather than
  `ProcessManager::isMonitorAlive()` alone.

The daemon-side runbook — enabling, verifying, rollback, the remuxer's exit codes — is
`docs/en/09-encoder-supervision.md` in the `XC_VM_Fanout` repository.

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

Enforced after token validation. Limits concurrent streams per user based on `max_connections`, closing the oldest connections first (the requesting device's own older ones before others). Daemon-served viewers are disconnected through the daemon — see [Daemon delivery](#daemon-delivery-xc_fanout).

### 4. Proxy-only servers

A server with `enable_proxy` only accepts requests that arrive through one of its proxies. `auth.php` checks the TCP peer nginx saw — `XC_PEER_ADDR`, set to `$realip_remote_addr` in the stream location of `nginx.conf` — not a request header, which the client controls.

---

## HLS Encryption

Client HLS is served by the `xc_fanout` daemon (see [Daemon delivery](#daemon-delivery-xc_fanout)), so encryption happens **daemon-side**:

1. `StreamProcess` writes the stream's AES-128 key/IV to `content/streams/<id>_.key` / `_.iv` — before it spawns a PHP producer, which registers with the daemon moments after starting.
2. At ingest registration (`FanoutClient::registerIngest`), when `encrypt_hls` is on, the key/IV are handed to the daemon, which encrypts the HLS segments it serves. Every producer passes them — ffmpeg streams (loopback children included), supervised streams, and the PHP producers via `IngestFeeder::forStream()` — because the playlist always declares the key: a daemon fed without it served plain segments no player could decrypt.
3. `HLSGenerator::tokenizeDaemonPlaylist()` rewrites the daemon playlist's segment URLs into per-segment auth'd `/hls/<token>` links that `segment.php` proxies from the daemon, and adds the `#EXT-X-KEY` line.
4. The AES key is delivered to players by `key.php` (`src/Public/stream/key.php`) using the same token mechanism.

The live playlist's `#EXT-X-MEDIA-SEQUENCE` is re-anchored by `HlsSequence` so it never steps back across an off-air ↔ live transition, without renumbering a stream that is playing (its state lives in `tmp/signals/hlsseq_<id>`, so it survives a stream restart).

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
| `src/Domain/Stream/StreamProcess.php` | command building (`buildLive` / `buildNativeLive`), supervision hand-over and reconcile |
| `src/Streaming/Fanout/FanoutClient.php` | daemon control API (ingest, supervision, force source) |
| `src/Core/Init/LegacyInitializer.php` | global variable setup for streaming |
| `tools/stream-check/stream_check.py` | queue-integrity checker + playlist batch + live buffer dashboard + SVG grapher |
