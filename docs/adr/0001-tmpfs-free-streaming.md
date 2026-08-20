# ADR 0001 — Tmpfs-free streaming: PHP out of the byte path, native fan-out, in-RAM HLS

- **Status:** Proposed
- **Date:** 2026-08-16
- **Scope of this iteration:** MAIN node first; LB rollout is a later phase (same components, provisioned via `LbInstallFlow`/binaries release).
- **Decision drivers:** hard ceiling at ~400 concurrent connections; goal to remove the tmpfs mounts entirely and deliver live/HLS/MPEG-TS purely over pipes/sockets.

---

## 1. Context

### 1.1 Two delivery paths today (selected at runtime by `$rChannelInfo["proxy"]`)

| Mode | Producer | tmpfs artifacts | Consumer (byte path) |
|---|---|---|---|
| **HLS / live-TS (default)** | ffmpeg `-f hls` → `STREAMS_PATH/<id>_%d.ts` + `_.m3u8` (`Domain/Stream/StreamProcess.php:406`) | `.ts`, `.m3u8`, `.enc`, control files | **PHP** `readfile()` / chase-read (`Public/stream/live.php:457`, `segment.php:190`) |
| **Proxy / restream** | ffmpeg `-f mpegts -` via `popen` (`Cli/Commands/ProxyCommand.php:111`) | none on disk — **AF_UNIX SOCK_DGRAM** sockets in `CONS_TMP_PATH/<id>/` | **PHP** `socket_read` loop (`Public/stream/live.php:352-389`) |

### 1.2 The real bottleneck is PHP in the byte path — not tmpfs itself

- **There is no `X-Accel-Redirect` anywhere.** nginx never serves `.ts`/`.m3u8`; every segment is streamed by a PHP-FPM worker doing `readfile()` from tmpfs.
- **Proxy mode holds one PHP-FPM worker per viewer for the entire session** (`live.php:352-389` is a `socket_read`→`echo`→`flush` loop). 400 viewers = 400 permanently-occupied workers. `pm.max_children` (typically 400–512) is the ceiling — this matches the observed 400 wall almost exactly.
- **HLS mode** does not pin a worker per session (each `.ts` is a short request), but pays a full PHP bootstrap + `readfile` per segment (~100 req/s at 400 clients on 4 s segments) and copies every byte kernel→PHP→kernel with no zero-copy.

**Conclusion:** replacing tmpfs with sockets while PHP stays the fan-out engine will *not* raise the ceiling — the userland copy loop and worker-per-viewer model are the limits. The redesign must get **PHP out of the byte path** and do fan-out in a zero-copy, event-driven layer. tmpfs removal then follows for free.

### 1.3 Full tmpfs coupling inventory (everything that must move)

1. `STREAMS_PATH` = `/home/xc_vm/content/streams` — **tmpfs 90% RAM**. HLS `.ts`/`.m3u8`/`.enc` + per-stream control files (`_.pid`, `_.monitor`, `_.dur`, `_.key`, `_.iv`, `_.stream_info`, `.errors`, `.analyse`). Mount: `install:2232`, `LbInstallFlow.php:84`. Toggle: `RootSignalsCronJob.php:500-527`.
2. `CONS_TMP_PATH` = `/home/xc_vm/tmp/opened_cons/` — connection registry touch-files (`<uuid>`) **and** proxy datagram sockets (`<id>/<uuid>`). Writers: `live.php:296/350/362`, `segment.php:101`, `vod.php:194`, `timeshift.php:199/211/307`, `LlodCommand.php:135`, `ProxyCommand.php`. Reapers: `ConnectionTracker.php:934-956`, `StreamsCronJob.php:257-265`, `UsersCronJob.php:206`, `SignalsCommand.php:123`.
3. `DIVERGENCE_TMP_PATH` = `/home/xc_vm/tmp/divergence/` — per-connection transfer-rate counters (anti-fraud/bitrate). Writers: `live.php:398`, `vod.php:197`, `timeshift.php:309`. Readers: `UsersCronJob.php:499/613`.
4. `TMP_PATH` = `/home/xc_vm/tmp` — **tmpfs 6G**. General scratch: playlist cache, `STREAMS_TMP_PATH`, signals, floods, etc.
5. fstab mounts (2× `install`, LB variant) + ramdisk enable/disable toggle.

---

## 2. Decision — target architecture

> **Produce once → fan out in a native daemon / nginx → PHP only authenticates. No tmpfs.**

### 2.1 Component: `xc_fanout` (new native daemon, Go static binary)

Shipped in the **binaries release repo** next to ffmpeg/nginx/redis; installed on MAIN (and later LB) like the other bundled binaries. **One process, many streams** (never process-per-stream — that reintroduces the overhead we are removing). Managed by the existing supervisor path (`StreamProcess`/`MonitorCommand` start/stop, PID/health via Redis).

Responsibilities per active stream:
- **Ingest one mpegts feed** from ffmpeg (`-f mpegts -`) over a Unix socket / pipe — a single producer connection.
- **Live TS fan-out:** hold a bounded, 188-byte-aligned ring buffer (config, e.g. 2–10 MB) with PAT/PMT tracking. New subscribers join at a PAT/PMT + keyframe boundary, then follow the live tail. Slow clients get bounded per-connection buffering, then are dropped (configurable) — never block the producer.
- **In-RAM HLS:** segment the same feed at keyframe boundaries into in-memory byte slices, maintain the `.m3u8`, keep a sliding window of N segments (matches current `hls_list_size`/`delete_threshold`). Optional AES-128 (`.enc`) done in-daemon using the existing key/iv.
- **Serve nginx** over a Unix socket (nginx `proxy_pass http://unix:/run/xc_fanout.sock`):
  - `GET /live/<id>` → chunked mpegts (fan-out from ring)
  - `GET /hls/<id>.m3u8` → playlist from memory
  - `GET /hls/<id>_<seq>.ts` → segment bytes from ring
- **Emit connection telemetry** (connect/disconnect/bytes) — it owns the sockets, so it is the source of truth — to **Redis** (see §2.4).

Fan-out uses the Go netpoller (epoll) + `writev`/`sendfile`-class writes; 400+ subscribers per stream is comfortably within range, with headroom far beyond the current PHP ceiling.

### 2.2 nginx — the only thing in front of clients

- `/stream/*` client locations `proxy_pass` to `xc_fanout` over the Unix socket for `live`/`hls`, replacing the PHP `readfile`/`socket_read` handlers in the byte path.
- **auth via `auth_request`**: nginx issues a subrequest to a lightweight internal PHP endpoint that validates token/user/connection-limit and returns `204`/`403` (+ headers: resolved stream id, AES key hint, etc.). PHP no longer touches bytes.
- Keep `proxy_max_temp_file_size 0` / `fastcgi_max_temp_file_size 0` (already set) so nginx never spills to disk.

### 2.3 ffmpeg — one feed drives both live and HLS

Replace the two separate ffmpeg invocations (HLS segmenter writing to tmpfs; proxy `popen` mpegts) with **one `-f mpegts -` feed per stream into `xc_fanout`**. The daemon derives both live-TS fan-out and HLS segments from it. This removes `-hls_segment_filename` (the tmpfs writer) from the default path and unifies the two modes.

### 2.4 Connection telemetry → Redis (replaces `opened_cons` + `divergence`)

Redis is already bundled (`Infrastructure/Redis/RedisManager`, `Core/Cache/RedisCache`). Move the tmpfs touch-file registry into Redis, written by `xc_fanout` (authoritative on connect/disconnect) and read by the panel/crons:

- `conn:<uuid>` (hash: stream_id, user_id, ip, started_at, bytes, bitrate) with TTL heartbeat — replaces `CONS_TMP_PATH/<uuid>` and `DIVERGENCE_TMP_PATH/<uuid>`.
- `stream:<id>:conns` (set of uuids) — replaces `glob(CONS_TMP_PATH/<id>/*)` used for capacity/fan-out counts.
- Consumers to repoint: `ConnectionTracker::getCapacity`, `UsersCronJob` (bitrate/anti-fraud at `:499/:613`), `StreamsCronJob` reaping (`:257-265`), `SignalsCommand` (`:123`), admin process monitor.

### 2.5 HLS sub-decision — stage B before A

- **HLS-B (fast, low-risk):** keep ffmpeg's proven HLS muxer, but serve segments via **nginx `X-Accel-Redirect`/`try_files` + `sendfile`** instead of PHP `readfile`, and move `STREAMS_PATH` off tmpfs onto **real NVMe** (page cache keeps hot segments in RAM). This *alone* removes the PHP HLS bottleneck and the 90%-RAM tmpfs mount, at minimal risk. Segments still exist as files, but there is **no tmpfs mount**.
- **HLS-A (pure, higher-effort):** `xc_fanout` segments in-memory (§2.1), zero files at all. Requires codec-aware TS demux (H264/H265 access units, ADTS AAC, PTS) — real work and risk.

**Recommendation:** ship B first (kills the ceiling + removes the mount), then move to A once the daemon's live path is proven, if literally-zero-files is still wanted.

---

## 3. Phased migration plan (MAIN first)

Each phase is independently shippable and guarded by a feature flag (`settings` table) so we can canary and roll back per node.

- **P0 — Baseline & instrumentation.** Load-test current MAIN to reproduce the 400 wall; capture FPM worker saturation, syscalls, RAM. Establishes the before-numbers. *No code change.*
- **P1 — HLS off PHP (HLS-B).** Add `X-Accel-Redirect` in `segment.php`/`live.php` HLS path → nginx `internal` location over `STREAMS_PATH`; PHP does auth only. No runtime flag — X-Accel is the sole path (a PHP/nginx toggle was deliberately dropped to keep the code simple); rollback via `git revert` + redeploy. **Biggest win / smallest change.**
- **P2 — `xc_fanout` live fan-out.** New daemon ingests `-f mpegts -`, serves `GET /live/<id>`; nginx `proxy_pass` + `auth_request` replaces the `socket_read` loop in `live.php`. Retire proxy-mode datagram sockets. Flag: `live_fanout`. **Removes the worker-per-viewer ceiling.**
- **P3 — `xc_fanout` in-RAM HLS (HLS-A).** Daemon segments the same feed in memory; nginx serves `/hls/*` from the daemon. Drop `-hls_segment_filename`. Flag: `hls_inmem`.
- **P4 — Telemetry → Redis.** Daemon emits connect/disconnect/bytes to Redis; repoint `ConnectionTracker`, `UsersCronJob`, `StreamsCronJob`, `SignalsCommand`, admin monitor. Remove `opened_cons`/`divergence` writers.
- **P5 — Drop tmpfs.** Remove the two fstab mounts (`install`, `LbInstallFlow`) and the ramdisk toggle (`RootSignalsCronJob`); repoint any residual `TMP_PATH` scratch to real disk/Redis. Verify no code path assumes a tmpfs mount.
- **P6 — LB rollout.** Package `xc_fanout` in the binaries release; extend `LbInstallFlow` to install/supervise it and ship the new nginx config; canary a subset of LB nodes.

---

## 4. Rollback, risks, consequences

**Rollback:** the daemon phases (P2–P4) are flag-gated — flipping a `settings` flag reverts to the current PHP path without redeploy, and `xc_fanout` failure ⇒ nginx falls back to the PHP handler while the flag is off. P1 ships without a runtime toggle (to avoid dual-path complexity); it rolls back via `git revert` + redeploy. P5 is reversible via fstab + config.

**Risks:**
- HLS-A codec-aware segmentation is the hardest, riskiest piece → deferred behind HLS-B.
- `auth_request` must preserve every current auth/limit/geo check exactly → port, don't rewrite; cover with regression tests before flipping the flag.
- Telemetry migration touches anti-fraud/limits (`UsersCronJob`) → dual-write (tmpfs + Redis) during P4, compare, then cut over.
- Live ring join semantics (PAT/PMT + keyframe) must be correct or players get artifacts on join → validate against the current proxy prebuffer behavior.
- New native binary in the trusted binaries release → security review + reproducible build; LB archive stays privilege-free.

**Consequences (positive):** PHP-FPM no longer sized to viewer count; zero-copy fan-out; no 90%-RAM mount (RAM freed for page cache/app); unified single-ffmpeg-per-stream pipeline; connection state observable in Redis instead of scattered tmpfs files.

**Consequences (cost):** a new native service to build, ship, supervise, and secure; nginx and installer changes on MAIN then LB; a telemetry subsystem rewrite. Net new operational surface, justified by removing the scaling ceiling and the tmpfs foot-gun.
