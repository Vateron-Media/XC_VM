# ADR 0002 — `xc_fanout`: native live fan-out daemon (P2)

- **Status:** Proposed
- **Date:** 2026-08-16
- **Depends on:** ADR 0001 (tmpfs-free streaming). This is phase **P2**.
- **Locked decisions (from Danil):** language **Go**; **daemon owns the puller** (starts on first viewer, stops on last); scope covers **both live TS sub-modes** (proxy + non-proxy).

---

## 1. Problem P2 solves

Live TS delivery in `Public/stream/live.php` (default case) has two sub-modes and **both pin one PHP-FPM worker per viewer for the whole session**:

- **proxy-mode** (`$rChannelInfo["proxy"]`, live.php:352-389): client binds an `AF_UNIX SOCK_DGRAM` socket file and loops `socket_read`→`echo`→`flush`. Producer is `Cli/Commands/ProxyCommand.php` (one process per stream) fanning out to those socket files.
- **non-proxy TS feed** (live.php:392+): client chase-reads the stream's HLS `.ts` segments from tmpfs and echoes them as a continuous MPEG-TS.

P0 measured the cost: **~1 PHP-FPM worker + ~25 MB per viewer**; the ~400 ceiling is that linear RAM law (not `pm.max_children`, which is 4000). P2 removes PHP from the byte path so a viewer becomes a cheap nginx connection instead of a 25 MB blocked worker.

---

## 2. Target: one producer → nginx → many viewers

```
                         ┌──────────────── xc_fanout (one process, many streams) ─────────────┐
 source ──pull──────────▶│ per-stream: source puller → ring buffer (PAT/PMT+keyframe aware)   │
 (proxy-mode)            │                                   │                                  │
 stream ffmpeg ──tee────▶│ ingest unix socket ──────────────┘        subscriber set            │
 mpegts (non-proxy)      └───────────────────────────────────────────────│──────────────────────┘
                                                                          │ (one HTTP conn per viewer)
 viewer ──HTTP──▶ nginx ──auth_request──▶ PHP (204/403)                   │
 viewer ◀──chunked mpegts── nginx ──proxy_pass unix:/run/xc_fanout/http.sock ◀┘
```

- **PHP is only in `auth_request`** (short subrequest), never in the byte path.
- **nginx** terminates every client, opens one upstream connection per client to the daemon; the daemon holds the single ingest and fans out from the ring.

### 2.1 Ingest per sub-mode (how "daemon owns the puller" applies)

- **proxy-mode:** the daemon **fully owns the puller** — it reimplements `ProxyCommand::getActiveStream` semantics in Go: connect to a source URL, use it directly if `Content-Type: video/mp2t`, otherwise spawn `ffmpeg … -f mpegts -` (HLS/remux sources) and read its stdout. Replaces `ProxyCommand.php` entirely.
- **non-proxy:** the stream's HLS ffmpeg (owned by `StreamProcess`) must stay — it feeds HLS delivery (P1). We do **not** add a second source pull. Instead that ffmpeg gets a **second tee'd output** `-f mpegts unix:/run/xc_fanout/ingest/<id>.sock` alongside its `-f hls …`. The daemon owns the **ingest socket + fan-out**; the producer is the existing ffmpeg. (Rejected alternative: daemon tails the `.ts` segments — re-reads tmpfs and adds a segment of latency.)

> One ffmpeg per stream, at most one source pull. This is also the on-ramp to P3 (in-RAM HLS): once the daemon receives the mpegts feed it can later segment it itself and drop the tmpfs HLS output.

### 2.2 Ring buffer & clean join

Per stream: a bounded, 188-byte-aligned ring (size ≈ current `MAX_PREBUFFER` 10.5 MB, tunable). The daemon parses TS enough to track the **last PAT/PMT** and the **last keyframe (PUSI+random-access)** offsets — the same signals `ProxyCommand` uses to build its prebuffer (lines 141-162). A new subscriber is served: `PAT/PMT + from last keyframe` snapshot, then the live tail. Slow clients get a bounded per-subscriber queue; on overflow they are dropped (never block the producer or other viewers).

### 2.3 Lifecycle (on-demand)

- **Start on first viewer:** the first `/live/<id>` connection (post-auth) with no active producer triggers the daemon to start the puller. For on-demand streams this *is* the stream start. The daemon needs the stream's source/args — provided by the auth endpoint at signal time (passed as headers/JSON on a control call) or read from the DB by the daemon.
- **Stop on last viewer:** when the subscriber count for a stream stays 0 for a grace window, the daemon stops the puller (mirrors `CLOSE_EMPTY` in ProxyCommand).
- **Coordination with `StreamProcess`/DB:** the daemon updates `streams_servers` (pid/status/started) for the streams it owns, replacing the equivalent writes in ProxyCommand. Exact ownership boundary settled in slice S5.

### 2.4 Auth & hand-off — `X-Accel-Redirect` (chosen for S3, refines the original `auth_request` sketch)

Rather than a separate `auth_request` subrequest + a slim `live_auth` endpoint, S3 reuses the **existing `live.php` auth path unchanged** and hands the byte path to nginx via `X-Accel-Redirect` — the same mechanism P1 already ships for HLS segments (`segment.php` → `/xc_hls/`). `live.php` runs its full pre-loop logic (token decode/expiry, `ConnectionTracker` create/lookup/update, `StreamAuth::validateConnections`, IP match, limits); then, in the proxy-mode arm, instead of the `socket_read`→`echo` relay it:

1. reads `stream_source` + `streams_arguments` (user_agent/proxy/cookie), builds the daemon source config (`FanoutClient::buildSource`, a pure mirror of `ProxyCommand`'s extraction),
2. registers the stream with the daemon over the **control unix socket** (`FanoutClient::register` → `PUT /streams/<id>`),
3. emits `header('X-Accel-Redirect: /xc_fanout/live/<id>')` and returns.

nginx then proxies the viewer to the daemon's client socket (`location ^~ /xc_fanout/live/` → `proxy_pass http://unix:/run/xc_fanout/http.sock`), and **PHP-FPM is freed the instant `live.php` returns** — PHP is out of the byte path, the same win auth_request would give.

Why this over `auth_request`: the `live_fanout` flag stays **in PHP** (read from the settings cache), so flipping it needs **no nginx reload** and no map/generated-include machinery; there is no second subrequest; and it is byte-for-byte the P1 pattern already proven in production. **Port, do not rewrite** the auth logic — it is literally the same code path, only the delivery tail changes; regression-test auth parity before enabling the flag.

Trade-off vs `auth_request`: the daemon, not PHP, now observes real connect/disconnect on the proxied upstream, so precise connection-liveness accounting (today driven by the FPM worker's lifetime + reaper) shifts toward the daemon. For S3 the `createLive` record is still written at auth time and the existing stale-connection reaper covers teardown; the exact ownership split is settled in **S5** (and telemetry→Redis in **P4**).

### 2.5 Connection telemetry

P2 keeps the existing `ConnectionTracker` write at auth time plus a reaper for stale connections. Moving the registry to Redis (owned by the daemon, which sees real connect/disconnect) is **P4** and out of scope here.

---

## 3. Packaging & placement

- The Go module lives in the **separate binaries repo** `XC_VM_Binaries/xc_fanout/` (not in this repo, and never bundled into the panel/LB archive). It builds with `XC_VM_Binaries/build_xc_fanout.sh`: runs `go test`, then cross-compiles a fully static (`CGO_ENABLED=0`) binary per arch (linux amd64/arm64/armv7/386) into a per-version store `bin/xc_fanout/<version>/xc_fanout-linux-<arch>` + `SHA256SUMS`. Installed on **MAIN** first (LB is P6). One static binary per arch runs on any distro, so no per-distro Docker build is needed (unlike nginx/php/ffmpeg here).
- Supervised as a long-lived service (systemd unit or the existing process supervisor). Control/ingest sockets under `/run/xc_fanout/` (real tmpfs-free runtime dir, not `STREAMS_PATH`).
- **Rollback flag:** unlike P1 (which shipped without a toggle), P2 is a large behavioural change, so it **is** gated — the `settings` flag `live_fanout`, read in PHP from the settings cache (missing/`0` = off, the fail-safe default). Off ⇒ `live.php` runs today's socket relay unchanged; on ⇒ it registers with the daemon and `X-Accel-Redirect`s the viewer. If registration fails at request time, `live.php` **falls through to the legacy relay** in the same request, so a down daemon degrades gracefully rather than erroring. No nginx reload is needed to flip the flag.

---

## 4. Build slices (each independently testable)

- **S1 — Core engine (no panel).** Ring buffer + TS PAT/PMT/keyframe parser + HTTP-over-unix fan-out server; ingest from stdin/file. **Test:** feed a captured `.ts`, attach N `curl` clients, assert every client gets a byte-identical stream and a clean keyframe join. Pure isolation, no XC_VM involvement.
- **S2 — Source puller (proxy-mode).** Port `getActiveStream` (direct mp2t / ffmpeg spawn); per-stream config. **Test:** pull a real source on the box, one client, compare to today's ProxyCommand output.
- **S3 — Wire proxy-mode into the panel. _(implemented — see §2.4)_** Reuses `live.php`'s existing auth, then hands proxy-mode delivery to nginx→daemon via `X-Accel-Redirect: /xc_fanout/live/<id>` (not `auth_request`), behind the `live_fanout` settings flag; `FanoutClient` registers the source over the control socket. nginx internal `location ^~ /xc_fanout/live/` proxies to `unix:/run/xc_fanout/http.sock`. Unit-tested: `FanoutClientTest` (source-config builder parity + empty-urls short-circuit). **Still to validate:** real player end-to-end + auth/limits regression on the test box; flag off = old socket relay (verified by code path).
- **S4 — Non-proxy sub-mode.** Add the tee'd `-f mpegts` output in `StreamProcess`; route non-proxy TS feed through the daemon. **Test:** real player on a non-proxy stream; HLS still served by P1 path.
- **S5 — Lifecycle & supervision.** On-demand start/stop owned by the daemon; systemd/supervisor; DB status ownership. **Test:** cold-start a on-demand stream via first viewer; idle stop.
- **S6 — Scale validation.** Re-run the P0 harness against the daemon path: confirm viewers no longer pin workers/RAM and the box holds ≫400. Record before/after.

Slices land behind the `live_fanout` flag so each is canary-able and reversible.

---

## 5. Risks

- **Clean-join correctness** (PAT/PMT + keyframe) — wrong join = player artifacts on connect. Mirror ProxyCommand's proven logic; verify against real players in S1/S3.
- **Slow-client isolation** — one slow viewer must never stall the producer or others; bounded per-subscriber queue + drop policy, load-tested in S6.
- **Auth parity** — the `auth_request` endpoint must preserve every current check; regression tests before flipping `live_fanout`.
- **Lifecycle races** — start/stop ownership split between daemon and `StreamProcess`; settle the boundary in S5, guard on-demand cold start.
- **New trusted binary** — security review + reproducible build; LB archive stays privilege-free (P6).
