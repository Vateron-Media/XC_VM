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
 viewer ◀──chunked mpegts── nginx ──proxy_pass unix:/home/xc_vm/bin/xc_fanout/sockets/http.sock ◀┘
```

- **PHP is only in the auth step** (short-lived), never in the byte path. _(This sketch drew that step as an nginx `auth_request` subrequest; the realized design keeps the full `live.php` auth inline and hands off with `X-Accel-Redirect` — see §2.4. The "PHP out of the byte path" property is identical either way.)_
- **nginx** terminates every client, opens one upstream connection per client to the daemon; the daemon holds the single ingest and fans out from the ring.

### 2.1 Ingest per sub-mode (how "daemon owns the puller" applies)

- **proxy-mode:** the daemon **fully owns the puller** — it reimplements `ProxyCommand::getActiveStream` semantics in Go: connect to a source URL, use it directly if `Content-Type: video/mp2t`, otherwise spawn `ffmpeg … -f mpegts -` (HLS/remux sources) and read its stdout. Replaces `ProxyCommand.php` entirely.
- **non-proxy:** the stream's HLS ffmpeg (owned by `StreamProcess`) must stay — it feeds HLS delivery (P1). We do **not** add a second source pull. Instead that ffmpeg gets a **second tee'd output** `-f mpegts unix:/home/xc_vm/bin/xc_fanout/sockets/ingest/<id>.sock` alongside its `-f hls …`. The daemon owns the **ingest socket + fan-out**; the producer is the existing ffmpeg. (Rejected alternative: daemon tails the `.ts` segments — re-reads tmpfs and adds a segment of latency.)

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
3. emits `header('X-Accel-Redirect: /xc_fanout/<id>')` and returns.

nginx then proxies the viewer to the daemon's client socket (`location ^~ /xc_fanout/` → `proxy_pass http://unix:/home/xc_vm/bin/xc_fanout/sockets/http.sock`), and **PHP-FPM is freed the instant `live.php` returns** — PHP is out of the byte path, the same win auth_request would give.

Why this over `auth_request`: the switch stays **in PHP** (originally the `live_fanout` settings flag, now the daemon's reachability — see §3 Rollback), so flipping delivery needs **no nginx reload** and no map/generated-include machinery; there is no second subrequest; and it is byte-for-byte the P1 pattern already proven in production. **Port, do not rewrite** the auth logic — it is literally the same code path, only the delivery tail changes; auth parity is covered by `FanoutClientTest` + box validation.

Trade-off vs `auth_request`: the daemon, not PHP, now observes real connect/disconnect on the proxied upstream, so precise connection-liveness accounting (today driven by the FPM worker's lifetime + reaper) shifts toward the daemon. For S3 the `createLive` record is still written at auth time and the existing stale-connection reaper covers teardown; the exact ownership split is settled in **S5** (and telemetry→Redis in **P4**).

### 2.5 Connection telemetry

P2 keeps the existing `ConnectionTracker` write at auth time plus a reaper for stale connections. The daemon owns the truth of who is connected (it sees real connect/disconnect via the `?c=<uuid>` refcount) and exposes it over the control socket (`GET /connections`); `fanout_sync` reconciles that against the `lines_live` rows written at auth time — this is the **light P4** that shipped. What is *not* yet done is **per-viewer transfer telemetry** (`divergence` / bitrate): legacy `live.php` measured each viewer's byte rate in its chase-read loop and wrote it to `DIVERGENCE_TMP_PATH`; a daemon-served viewer never enters that loop, so `lines_divergence` / `lines_live.divergence` stay blank for them and the anti-fraud rate checks are blind. Closing that (daemon exposes per-connection bytes → PHP writes `divergence`) is the remaining **P4** work — see ADR 0003 §"Remaining". Moving the whole connection registry off tmpfs into Redis is the larger P4 vision in ADR 0001 §4 and is not required for the divergence fix.

---

## 3. Packaging & placement

- The Go module lives in the **separate binaries repo** `XC_VM_Binaries/xc_fanout/` (not in this repo, and never bundled into the panel/LB archive). It builds with `XC_VM_Binaries/build_xc_fanout.sh`: runs `go test`, then cross-compiles a fully static (`CGO_ENABLED=0`) binary per arch (linux amd64/arm64/armv7/386) into a per-version store `bin/xc_fanout/<version>/xc_fanout-linux-<arch>` + `SHA256SUMS`. Installed on **MAIN** first (LB is P6). One static binary per arch runs on any distro, so no per-distro Docker build is needed (unlike nginx/php/ffmpeg here).
- Supervised as a long-lived service (systemd unit or the existing process supervisor). Sockets live in the app bin tree next to the daemon binary — `bin/xc_fanout/sockets/{control,http}.sock` — mirroring the php-fpm sockets layout (`bin/php/sockets/`), which nginx already reaches over `unix:`. (A unix socket carries no stored stream bytes — it is pure IPC — so its location is orthogonal to the tmpfs-free byte-path goal; the point is only to keep it out of the streaming content/tmpfs mount.)
- **Rollback (superseded by ADR 0003):** S3 originally shipped behind a `settings` flag `live_fanout`. **That flag was removed** — for a permanent cutover the switch is the daemon's reachability instead: `live.php` uses the daemon iff its control socket is present *and* registration succeeds, else the full legacy path (producer included) runs. Stopping the daemon is the rollback; no DB flag, no cache rebuild. See ADR 0003 §3 Phase 0.

---

## 4. Build slices (each independently testable)

- **S1 — Core engine (no panel).** Ring buffer + TS PAT/PMT/keyframe parser + HTTP-over-unix fan-out server; ingest from stdin/file. **Test:** feed a captured `.ts`, attach N `curl` clients, assert every client gets a byte-identical stream and a clean keyframe join. Pure isolation, no XC_VM involvement.
- **S2 — Source puller (proxy-mode).** Port `getActiveStream` (direct mp2t / ffmpeg spawn); per-stream config. **Test:** pull a real source on the box, one client, compare to today's ProxyCommand output.
- **S3 — Wire proxy-mode into the panel. _(implemented — see §2.4)_** Reuses `live.php`'s existing auth, then hands proxy-mode delivery to nginx→daemon via `X-Accel-Redirect: /xc_fanout/<id>` (not `auth_request`), behind the `live_fanout` settings flag; `FanoutClient` registers the source over the control socket. nginx internal `location ^~ /xc_fanout/` proxies to `unix:/home/xc_vm/bin/xc_fanout/sockets/http.sock`. Unit-tested: `FanoutClientTest`. **Box-validated (test node, real player):** flag-off vs flag-on A/B at 8 concurrent viewers on a real proxy stream — legacy pinned ~1 php-fpm worker + ~24 MB per viewer (9 workers / 242 MB), fanout served all 8 via the daemon (daemon_conns=8) with php-fpm flat at baseline (1 worker / 45 MB). GOTCHA fixed: the X-Accel target must be two path segments (`/xc_fanout/<id>`) — a `/xc_fanout/live/<id>` form is hijacked by the server-level 3-segment `^/(user)/(pass)/(stream)` rewrite (rewrites run on internal redirects too), same reason P1's `/xc_hls/<file>` is two segments.
- **S4 — Non-proxy sub-mode.** Add the tee'd `-f mpegts` output in `StreamProcess`; route non-proxy TS feed through the daemon. **Test:** real player on a non-proxy stream; HLS still served by P1 path.
- **S5 — Lifecycle & supervision.** On-demand start/stop owned by the daemon; systemd/supervisor; DB status ownership. **Test:** cold-start a on-demand stream via first viewer; idle stop.
- **S6 — Scale validation. _(done)_** Re-ran the P0 harness against the daemon path: legacy pins ~1 php-fpm worker + ~24 MB per viewer (O(N) — the origin of the ~400 ceiling), while the daemon serves N viewers off a single pulled source at O(1) fan-out cost (25 viewers = 1 worker / ~13 MB measured; a connect-storm briefly touches ~6 workers for 2–4 s, then settles). The ceiling is gone: it was worker-per-viewer, not tmpfs. Before/after recorded in the cutover report.

Each slice is canary-able and reversible by the daemon's reachability (stop the daemon → full legacy path runs); the original `live_fanout` settings flag was removed once the cutover became permanent (see §3 Rollback and ADR 0003 §3 Phase 0).

---

## 5. Risks

- **Clean-join correctness** (PAT/PMT + keyframe) — wrong join = player artifacts on connect. Mirror ProxyCommand's proven logic; verify against real players in S1/S3.
- **Slow-client isolation** — one slow viewer must never stall the producer or others; bounded per-subscriber queue + drop policy, load-tested in S6.
- **Auth parity** — auth still runs in full inside `live.php` exactly as before; the daemon hand-off happens *after* every check passes, via `X-Accel-Redirect` (the `auth_request` sub-request design in earlier drafts was dropped for the same reason as P1's HLS — a two-segment internal `location` — see §2.4). So there is no separate auth endpoint to keep in parity; the risk reduces to "don't hand off before auth completes", covered by `FanoutClientTest` + box validation.
- **Lifecycle races** — start/stop ownership split between daemon and `StreamProcess`; settle the boundary in S5, guard on-demand cold start.
- **New trusted binary** — security review + reproducible build; LB archive stays privilege-free (P6).
