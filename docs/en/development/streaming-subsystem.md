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
| `/thauth/{token}` | `thumb.php` | Thumbnail delivery |

`/thauth/` and `/subauth/` accept only a token that carries `expires` and has not passed it; any other token is answered `TOKEN_EXPIRED`.

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
│   ├── SegmentReader.php     # fanout off: TS chase-read segment picking
│   ├── SignalSender.php      # fanout off: send-message overlay
│   └── StreamRedirector.php
├── Fanout/
│   ├── FanoutClient.php
│   ├── FanoutMode.php        # the fanout_enabled master switch
│   └── IngestFeeder.php
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

1. Read the token with `Encryption::readToken()` under `live_streaming_pass`.
2. Check expiration: `$rTokenData['expires'] < time() - $rServers[SERVER_ID]['time_offset']`.
3. Return parsed token data or trigger error.

### Token format

Stream-link tokens are made with `Encryption::mintToken()` and read with `Encryption::readToken()`; nothing else calls the legacy `encrypt()`/`decrypt()` for a token (`StreamTokenCallSitesTest` enforces it).

| `secure_stream_tokens` | Tokens minted | Legacy tokens read |
| --- | --- | --- |
| `1` (default on a new install) | Sealed: AES-256-GCM, random nonce, `base64url(nonce ‖ ciphertext ‖ tag)` | Only where the token carries credentials that are checked against the database again |
| `0` | Legacy AES-CBC | Everywhere |

The legacy format is AES-CBC with a fixed IV and no MAC: a modified token decrypts to modified bytes, and a padding error answers differently from a bad credential, which is enough to read a token or to forge one. Sealed tokens cannot be read or altered without the key, and keep the same URL-safe alphabet, so no route or pattern changes.

Where legacy tokens are still read with the setting on, and why:

- `auth.php` `/play/` links, `rtmp.php` tokens and `probe.php` `/play/` links carry a username and password that are looked up again, so a forged one gains nothing. Saved playlists and portal links hold the old format. Every token `auth.php` cannot read counts against the address through `BruteforceGuard`, which stops reading an old token through the error responses.
- Everything whose contents are trusted as they stand — the live/vod/timeshift JSON (`user_info`, `channel_info`), HLS segment and key tokens, thumbnail and subtitle tokens, the admin player's `uitoken`, the web player's proxy URL and the MAG portal's verify token — refuses the legacy format.

Servers on an older version cannot read sealed tokens. Migration `021_add_secure_stream_tokens.sql` therefore turns the setting off on a panel that has other servers; turn it on in **Settings → Tamper-proof Stream Tokens** once every server runs this version.

Response headers are set via `StreamAuthMiddleware::sendStreamHeaders()`:

```text
Access-Control-Allow-Origin: *
X-XSS-Protection: 0
X-Content-Type-Options: nosniff
Alt-Svc: h3-29, h3-T051, h3-Q050 (HTTP/3 hints)
```

### HMAC-signed links

A request that reaches `auth.php` with an `hmac` parameter is checked by `AuthService::validateHMAC()` instead of a line lookup. It is accepted for live, movie and series links only; any other type is answered `INVALID_TYPE_TOKEN`. The signature is an HMAC-SHA256, under one of the enabled HMAC keys, of

```text
<stream>##<extension>##<expiry>##<ip>##<identifier>##<max>
```

- `expiry` is optional. A link without it is signed with that field empty (`<stream>##<extension>####<ip>##<identifier>##<max>`); a link with it is answered `TOKEN_EXPIRED` once that time has passed.
- Each of `hmac`, `identifier`, `ip` and `expiry` must be a single value. A request that sends one of them as a list is answered `INVALID_CREDENTIALS`.

---

## Stream Delivery

### Live (live.php)

Main delivery endpoint (~650 lines):

1. Decrypt token via `StreamAuthMiddleware::decryptToken()`.
2. Resolve server/proxy: `StreamAuth::checkAccess()` + `ProxySelector::availableProxy()`.
3. Enforce connection limits: `StreamAuth::validateConnections()`.
4. Create connection record: `ConnectionTracker::createConnection()`.
5. Hand delivery to the **`xc_fanout` daemon** (see below; with fanout
   switched off, see [Switching fanout off](#switching-fanout-off)): PHP emits an
   `X-Accel-Redirect` and exits the byte path — nginx streams the bytes.
   - **TS:** `X-Accel-Redirect: /xc_fanout/<id>?c=<uuid>&prebuffer=N` (nginx
     rewrites to the daemon's `/live/<id>`).
   - **HLS:** the playlist points at tokenized segments; `segment.php` serves live
     segments only through the daemon (`/xc_fanout_hls/<id>_<seq>`), else `404`.
6. On exit: `ShutdownHandler::handle()` → close connection record.

Before it issues a live link, `auth.php` may show the expiring notice (**Expiring Video**, `show_expiring_video`) in place of the stream: once a day to a line that is not a trial, from seven days before it expires. The notice is shown to non-restreamer lines only, and it is a notice, not a refusal: when no clip is configured or found, or the server that would show it has no free proxy, the line gets its stream instead of an error.

### VOD (vod.php)

Same auth flow as live. Reads from `VOD_PATH` instead of `STREAMS_PATH`. Byte ranges (seeking) are resolved by `Streaming\Delivery\HttpRange` (RFC 7233 single ranges, suffix ranges included). A direct-proxy movie is relayed with cURL, asking the source for exactly the requested range.

### Timeshift (timeshift.php)

Serves archived segments (timeshift / catch-up) from the archive path. A TS request streams the minute files back to back; a byte range (a seek) is mapped onto them — files before the start are skipped, the first is entered at the right offset and delivery stops at the range end.

A catch-up link (`/timeshift/…`, or a live link with `?utc=`) is checked by `auth.php` before `/tsauth/{token}` is issued:

- `start` is a Unix time (up to 10 digits), `YYYYMMDD-HH` or `YYYY-MM-DD:HH-MM`. The last form is also read with seconds after it (`:SS` or `-SS`), which are ignored: the archive is read from that minute. Anything else is answered `NO_TIMESTAMP`, by `auth.php` and again by `timeshift.php` when it reads the token.
- `duration` is in minutes and is served up to 21600 (15 days). `timeshift.php` looks for one recorded minute per minute of it, and never past the present minute. A live link with `?utc=` names no duration: it asks for 360 (six hours from its start), and a `duration` sent with `utc` is ignored. A viewer who keeps watching past those six hours reaches the end of the stream and needs a new link with a later `utc`.
- In the HLS playlist, each catch-up segment link (`/hls/{token}`) has nine `/`-separated fields: `TS`, username, password, viewer IP, duration, start, `<stream>_<file>_<offset>`, connection uuid and server id. The start is written as a Unix time and the username and password are URL-encoded; `segment.php` answers 404 for a catch-up link with any other number of fields.

### RTMP (rtmp.php)

nginx-rtmp calls `rtmp.php` on `on_play`, `on_publish` and `on_play_done`. What nginx-rtmp itself says — `addr`, `clientid`, `call` and `name` — is read from the callback's query string by `StreamAuth::notifyArguments()`. A callback that gives one of the four two different values is answered 404. The stream's own arguments (`username`, `password`, `token`) come from the parsed request, as on the other endpoints, so an RTMP URL must not carry arguments named `addr`, `clientid`, `call` or `name`.

### Radio in the second web player

`GET /<code>/radio?stream=<id>` answers `302` to `<domain>/<username>/<password>/<id>.m3u8` (`DomainResolver`, the same form as the live channels) for a station in the signed-in line's `radio_ids`, and `404` otherwise; it never names `stream_source`. The station entries of `radio?ajax=1` and of the page's `initialStations` are `id, name, logo, category_id, direct, url`, where `url` is that play answer, so the page and the script's `localStorage` copy hold neither a source nor the line's credentials. `player-radio.js` plays `url` with hls.js unless the entry says `direct`, then with the audio element. On a fatal hls.js network error it asks the play answer again (2 s apart, at most 3 in a row, counted anew once a fragment is buffered) while the station shows as playing; otherwise it shows the station as paused.

A station therefore plays through the panel like a live channel: it has to be started or set on demand, the line needs the HLS output and HLS must be enabled in settings (otherwise the Radio page is not offered), and a listener shows in connections and counts toward the line's maximum. A station marked *Direct Source* is authorised by the panel and then redirected to its source, so it is not counted and its source reaches the listener. That holds for every client of a Direct Source stream, live channels included: the panel has no relay a browser could play for it. A station or channel that should play through the panel (counted, its source kept from the viewer) must not be marked *Direct Source*; set it on demand instead, and it is started when its first viewer arrives.

### Probe (probe.php)

`/probe/{data}` tells a restreamer whether a channel is up and with which codecs (`codecs`, `container`, `bitrate` as JSON) without opening a connection; `data` is the base64 of a stream link's path. It answers only for a restreamer line that is not expired, banned or disabled, and only for a stream in that line's bouquets; every other request gets a 404. A probe whose username and password match no line counts that username, and the password for it, toward the address's bruteforce limit (`bruteforce_username_attempts` different names, or different passwords for one name, within `bruteforce_frequency`), the same count a refused `/live/` request adds to. It is not counted when **Ignore Invalid Credentials** is on together with the cache.

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

#### Switching fanout off

`settings.fanout_enabled` (the **Fanout Delivery** toggle beside the other fanout settings, default
on; migration `027`) is the master switch, read through
`Streaming\Fanout\FanoutMode`. Switched off:

- **The daemon stops on every node.** `FanoutMode::applyToNode()` writes the flag
  file `bin/xc_fanout/disabled`, then kills `run.sh` first (so it cannot respawn
  the daemon) and `xc_fanout` second. It runs on MAIN when the settings are
  saved and on every node from `RootSignalsCronJob` within a minute. The
  RootSignals keepalive and the hourly `fanout_binary` self-heal skip the daemon
  while the flag is there. So do `service boot` and `run.sh`, which cannot read
  settings and check the flag file instead. Switched back on, the flag goes
  and the keepalive starts `run.sh` again.
- **Every daemon call behaves as "daemon down".** `LicenseGate::fanoutUsable()`
  and every `FanoutClient` control-socket call check the switch. `IngestFeeder`
  becomes a no-op, so the PHP producers (LLOD, loopback, delay) keep only their
  on-disk HLS. Supervision falls back to `MonitorCommand`. `fanout_sync` stops
  writing the daemon config and closes the daemon-served (`pid = 0`) connection
  rows the stopped daemon left behind.
- **Viewers take the pre-fanout paths** (`FanoutMode::legacyDelivery()`, which
  is also true when the licence denies fanout):
  - **HLS:** `live.php` serves `HLSGenerator::generateHLS()` over the on-disk
    `<id>_.m3u8`. `segment.php` serves `<id>_<n>.ts` from `STREAMS_PATH` through
    `X-Accel-Redirect: /xc_hls/…`, AES-128-encrypting on first read when
    `encrypt_hls` is on.
  - **TS:** `live.php` chase-reads the on-disk segments (`SegmentReader`) in the
    FPM worker, for the life of the connection.
  - **Proxy streams:** `StreamProcess::startProxy()` runs `ProxyCommand`
    (`XC_VMProxy[<id>]`). It pulls the source once and sends datagrams to each
    viewer's unix socket under `CONS_TMP_PATH/<id>/`, which `live.php` relays to
    the client. It exits a few seconds after its last viewer.
  - **Send message:** the signal file under `SIGNALS_PATH` is burned onto the
    viewer's next segment by `SignalSender`.

With fanout **on**, a daemon that is merely down still gives not-on-air until the
keepalive restarts it (about 2 s). Delivery never falls back per request.

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

**Closing viewers** (`cron:users`). In Redis mode MAIN sweeps every server's viewers; in MySQL mode
each server sweeps its own, and MAIN also closes those of a deleted server, or a viewer that checks
in and has been silent for `UsersCronJob::ORPHAN_AFTER`.

- Times (`date_start`, `hls_last_read`, the activity row's `date_end`) are MAIN's clock: a node
  stamps `time() - time_offset`, and a node's sweep compares on that clock too.
- A viewer closed for silence ends when it was last heard: an ended worker's own stamp, the last
  HLS read, or a TS/VOD worker's last check-in (every 300 s) plus one period.
- The sweep holds its activity rows and writes a batch's only once its records are removed; a
  sweep killed, or one that could not remove them, leaves them to the next sweep, which logs them
  once.
- An RTMP viewer's uuid is `ConnectionTracker::rtmpUuid()`: nginx-rtmp's client id and the server.
- A Redis-mode kill signal (`SIGNAL#…`) expires after `ConnectionTracker::SIGNAL_TTL` unread.

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

The connection that has just been admitted is never the one closed: `StreamAuth::validateConnections()` passes its uuid, and only connections older than it are candidates. An RTMP viewer is treated the same way (its uuid is `ConnectionTracker::rtmpUuid()`): admitted on a full line, it stays and an older connection of the line is closed, chosen by the priority above (the RTMP callback passes no user agent, so: one from the same IP first, otherwise the oldest). On a node whose CONNECTIONS flow is on, the check goes to MAIN as a `conn.limit` event carrying the connection's uuid and the viewer's address — for RTMP the address nginx-rtmp reported, since the callback itself comes from the server. The node makes the check itself only when the event cannot be queued.

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

An access code's sign-in and image resizer (`/CODE/login`, `/CODE/resize`, which carry no `.php`) are limited in a zone of the panel's own, `panel` (`limit_req_zone $binary_remote_addr zone=panel:10m rate=20r/s;`), with the code type's burst (500 for admin, reseller and player codes) and `nodelay`; a refused request answers 503. Being a separate zone, a list of thumbnails never holds back the same address's streams or client API requests. The other pages of a code are not limited by it; its `.php` location keeps its own rule in zone `one`. `AuthRepository::updateCodes()` writes the files from `codes/template`; it names zone `one` when the installed `nginx.conf` does not declare `panel`, adds the location to a template kept from an older release, and post-update runs it whenever at least one code is enabled.

### 2. StreamingRequestBootstrap (IP block)

```php
if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
    http_response_code(403);
    exit();
}
```

File-based IP blocking. Block files are created by `BruteforceGuard::checkFlood()` and `checkBruteforce()` (see [Authentication and Sessions](../guides/authentication-and-sessions.md#bruteforceguard)) when an address passes a limit, and for an address put on the blocklist in the panel. The guard is fed by the refused requests of the stream endpoints and of the client APIs, `player_api.php` included.

The flood count is of refused requests in a row, each within `flood_seconds` of the one before; a longer gap starts it again. It is a limit on tight loops. Different usernames or MACs, and different passwords for one username, tried over a longer time are what `checkBruteforce()` counts, within `bruteforce_frequency`.

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

## Stream Argument Templates

The source options of the stream form (User Agent, HTTP Proxy, Cookie, Headers, Force Input Audio Codec, Skip FFProbe) are the `fetch` rows of `streams_arguments`. The table also holds `transcode` rows (bitrates, scaling and the rest), which the stream form does not show: a transcoding profile builds those options itself in `ProfileService`. `argument_cmd` is the template `StreamUtils::getArguments()` turns into a piece of the ffmpeg command line, for each row a stream has an option for:

- A text template holds one `%s`, placed bare (`-acodec %s`), inside double quotes (`-user_agent "%s"`) or inside single quotes (`-headers '%s'`). `getArguments()` quotes the value for that place, so the shell hands it to ffmpeg as one argument. Two keys are rewritten before they are quoted: `cookie` by `fixCookie()` (gives a value typed without its last `;` that `;`, blanks after it aside, then appends `path=/;` and `domain=;` when the value has none) and `proxy` by `proxyURL()` (puts `http://` in front of a value without a scheme); every other value is quoted as stored. A template must not expect a value that is already escaped.
- ffmpeg's `-cookies` takes Set-Cookie text and sends a cookie only when its path and domain fit the request; ffmpeg 4.0 sends nothing for a cookie without a path, hence the appended `path=/;`. A `path` or `domain` typed after a space (`; path=/`) is deliberately not recognised: the two are appended behind it and the later, empty `domain=` wins. That is required, because ffmpeg compares the cookie domain with `host:port`, so a real domain never matches a source addressed with a port. The node's `probe` action (`InternalApiController::probeStream`) completes the cookie with `fixCookie()` too, so the probe button of the stream form and a stream start hand ffprobe and ffmpeg the same text. The LLOD fetcher, the proxy command and the fan-out daemon's puller send the stored cookie as a `Cookie` header as typed.
- Numeric options use `%d` (`-b:v %dk`).
- `StreamUtils::parseTranscode()` merges the `-filter_complex "…"` clauses of the transcode options into one. A clause runs to its closing double quote, and a backslash-escaped quote (`\"`) is part of the clause.
- A profile that deinterlaces and scales without a logo stores the chain as the command of its scaling option, and ffmpeg gets it as one `-vf "yadif,scale=…"` option. Only a profile with a logo stores a logo entry: the logo is then a second input, and the filters run in `-filter_complex`.

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
