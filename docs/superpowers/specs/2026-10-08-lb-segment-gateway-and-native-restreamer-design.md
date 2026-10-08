# LB segment gateway and native restreamer — plan

Oct 8, 2026 · draft for review

This plan builds on the MAIN ↔ LB API plan (`2026-09-21-main-lb-api-communication-design.md`, "the cluster plan"). That plan stops at Phase 10 and leaves out two things:

- PHP-FPM still answers every HLS playlist refresh, every segment and every key request on a node.
- ffmpeg still runs for each stream that only copies its source.

This plan adds two Go phases for that work, and lists what Phase 10 still owes. Go is the owner's preferred language for this work. Both new pieces are built in the `XC_VM_Fanout` repository, beside `xc_fanout` and `xc_agent`, so they can reuse its MPEG-TS, HLS and crypto packages.

| Phase | What | Binary | Effort (pw) |
| --- | --- | --- | --- |
| 10 (rest) | Remove the legacy LB link: `/api`, `configureRedisLb`, the daemons' DB branches | — (PHP) | ~1.5 |
| 12 | **Segment gateway:** `/hls/`, `/key/` and HLS playlist refreshes answered in Go | inside `xc_fanout` (new `internal/gateway`) | ~5 |
| 13 | **Native restreamer:** a Go replacement for `ffmpeg -c copy` | new `xc_restream` (separate binary) | ~7–9 |

The phases are independent of each other. Phase 12's third step needs the cluster plan's CONNECTIONS flow (Phase 6, built). Phase 13 needs nothing from the cluster plan.

---

## 1. Where the cluster plan stands

Phases 2–8 are built and accepted on test panels with the reference crypto. Phase 9 (cutover, lease, lockdown, secret rotation) and Phase 10 are gated on the `xcvm_core` cluster API release. The Rust extension now has the `cluster_*` functions (`cluster_token_issue`, `cluster_lease_issue`/`verify`/`store`, `cluster_sign`/`verify`, `cluster_open_sealed`, …), but the last released version is 2.3.3, and its changelog does not list them as released. **Confirm the release status before scheduling Phase 9.** Until it ships, every production node stays in mode 0.

### What Phase 10 still owes

Already built: the dashboard's *Cluster API* row and the Servers list's node hints; `server:diagnose` reporting the agent's standing; `docs/en/development/cluster-api.md`; `connection_sync_timer` dropped (migration 049); `/api` closing per node through `api_legacy.conf`, with each node in mode ≥ 1 reporting the calls its `/api` still answers (`legacy_api`).

Still to do:

1. **Remove** the LB `/api` and `/api.php` locations, `InternalApiController`, `configureRedisLb`, and the MAIN-DB branches of the LB daemons (`signals`, `watchdog`, `queue`, `cron:users`, `fanout_sync`, `cron:cache`'s DB pull, `cron:root_signals`'s `signals` read).
2. **The release that carries the removal refuses to update an LB below mode 2.** `UpdateCommand` on MAIN refuses to send it, and the LB's updater refuses to apply it. The Servers page names each node that blocks the update.
3. **Run the final `verify-lb-archive.sh`** after the removal, and add the removed files to its SENSITIVE list so they cannot come back.
4. **Rewrite `server:diagnose`** around the API link only: `health`, a signed round trip, token expiry, clock skew and outbox lag.
5. **Docs:** `cluster-api.md` gets a "Legacy removal" section, followed by `make docs-build`.
6. **External callers** of an LB's `/api` (XC_VM_Proxy's old builds) break here. The release notes must name the XC_VM_Proxy version they need.

**Recommendation:** keep Phase 10 as the cleanup it is, and ship it in the major release *after* Phase 12, not before it. Phase 12 takes a large share of the LB's remaining PHP traffic. Measuring it on a fleet that still has the legacy branches lets Phase 10 remove code without guessing what still depends on it.

---

## 2. How load balancers serve viewers today

This is the model XC_VM inherited from Xtream Codes, where an LB is nginx + PHP-FPM + ffmpeg, plus the fanout daemon added in ADRs 0002/0003.

### The request chain

```
player ─▶ MAIN /live/u/p/123.m3u8 ──(auth.php: line, bouquet, max_connections,
                                      server pick, ConnectionAdmission reserve)
       ◀─ 302 http://LB/auth/<live token>      (sealed with K_n or live_streaming_pass)
player ─▶ LB /auth/<token>  → nginx rewrite → /stream/live → PHP-FPM live.php
            ├─ m3u8: open store, lookup/createLive (hls key = md5(identity|stream|ip|UA)),
            │        fetch daemon playlist, mint ONE sealed token PER SEGMENT LINE,
            │        rebase MEDIA-SEQUENCE (HlsSequence), prepend #EXT-X-KEY → body
            └─ ts:   register with xc_fanout, X-Accel-Redirect /xc_fanout/<id> (FPM freed)
player ─▶ LB /hls/<seg token>  → nginx rewrite → /stream/segment → PHP-FPM segment.php
            unserialize settings + servers caches, Encryption::readToken (up to 4 key tries),
            stat CONS_TMP_PATH/<uuid>, IP match, X-Accel-Redirect /xc_fanout_hls/<id>_<seq>
player ─▶ LB /key/<key token>  → PHP-FPM key.php  → returns <id>_.key
```

When the player refreshes its playlist (every `seg_time`, often 2–10 s), it hits `live.php` again, which runs the store lookup, the `updateLive` touch, the playlist fetch, and N GCM seals.

### Cost per HLS viewer

| Request | Frequency per viewer | Handled by | Work per request |
| --- | --- | --- | --- |
| Playlist refresh | 1 / `seg_time` | PHP-FPM `live.php` | bootstrap, 2 igbinary cache reads, token open, store lookup + update (agent socket, or MAIN Redis/MySQL over the WAN in mode 0), N × AES-GCM seal |
| Segment | 1 / `seg_time` | PHP-FPM `segment.php` | 2 igbinary cache reads, 1–4 GCM opens, stat, X-Accel |
| Key (encrypted HLS) | about 1 per playlist | PHP-FPM `key.php` | same as a segment |

For 10,000 HLS viewers at `seg_time` 4 that is about 5,000 FPM requests/s on one node, from four FPM sockets, and none of them carry video. TS viewers already avoid this: PHP authenticates once and hands off.

### The legacy and pre-fanout paths that still exist

- **Mode 0 LB:** the same chain, but the stores are MAIN's Redis and MySQL over the WAN. Settings and servers come from MAIN's DB every minute. The commands come over the plaintext `/api` with `live_streaming_pass`.
- **`fanout_enabled = 0`, or unlicensed (ADR 0003 amendment):** `segment.php` serves on-disk `<id>_N.ts` from `STREAMS_PATH` through `/xc_hls/`, and AES-encrypts on first read into `.enc`. TS viewers pin one FPM worker each (`SegmentReader` chase-read). **This must stay as the fallback.**
- **ffmpeg per stream:** every non-proxy stream runs one ffmpeg, even when the profile is pure `-c copy`. It writes on-disk HLS for timeshift, thumbnails, `.analyse` and the PHP monitor, and tees MPEG-TS into the daemon's ingest socket (ADR 0003 A2). Proxy streams are pulled by the daemon itself (`nativesrc`, in-process, ffmpeg only as fallback).

---

## 3. Phase 12 — segment gateway (Go)

### Goal

Answer `/hls/<token>`, `/key/<token>`, and a known viewer's HLS playlist refresh without PHP-FPM, with no change to any URL, token format or player-visible behaviour. Anything the gateway does not fully own goes to the existing PHP endpoint through an internal redirect, so **every step can be undone** by removing one nginx include.

### Where it runs: inside `xc_fanout`

| Option | For | Against |
| --- | --- | --- |
| **A. Package `internal/gateway` in `xc_fanout`, own socket `gw.sock` (recommended)** | The bytes are already there: a segment is served from the hub's in-RAM HLS with no X-Accel hop. Runs on every node with fanout on, MAIN and mode-0 LBs included. It follows the existing `fanout_enabled` switch and licence gate. | Fanout gets viewer-key material (it runs as `xc_vm`, the same user as PHP-FPM, which already reads those keys). |
| B. In `xc_agent` | The agent already has the replica, the connection registry and the lease. | Not on mode-0 nodes or in MAIN's role. Adds a viewer-facing listener to the process that holds the MAIN link. Needs an X-Accel hop to reach the bytes. |
| C. New `xc_gateway` binary | Isolation | A fourth process to package, update and supervise, for code that exists to reach fanout's memory. |

Choose A. Fanout's control and client sockets stay as they are. The gateway listens on a third socket, `bin/xc_fanout/sockets/gw.sock`, so the internal `/hls/<id>/<seq>.ts` surface is never exposed to viewers.

### Inputs: `gateway.json`, written by PHP

The gateway reads PHP's igbinary caches only through a small policy file that PHP writes. That keeps one source of truth (PHP's settings loader, the replica on API nodes, the DB on MAIN and mode 0) and works in every mode.

`tmp/gateway/policy.json`, mode 0600, owner `xc_vm`, written atomically by `Core/Gateway/GatewayPolicy::write()`:

```json
{
  "v": 1, "server_id": 3, "written_at": 1759900000,
  "enabled": true,
  "keys": {
    "viewer": [{"key": "<hex K_n>", "until": null}, {"key": "<hex prev>", "until": 1759903600}],
    "shared": [{"key": "<live_streaming_pass>", "until": null}, {"key": "<old>", "until": 1759903600}],
    "context": [{"v": "<OPENSSL_EXTRA>", "until": null}, {"v": "<prev>", "until": 1759903600}],
    "accept_legacy_cbc": false
  },
  "restrict_same_ip": true, "ip_subnet_match": false, "encrypt_hls": true,
  "headers": {"server": "", "protection": true, "altsvc_port": 0},
  "lease_refuse_after": 1759990000,
  "redirect": {"5": "http://lb5.example:8080", "7": ["http", ["a.example","b.example"], 8080]},
  "playlist": {"enabled": true, "conn_store": "agent"}
}
```

- **Writers:** `cron:cache` (every minute, all modes), `cluster:apply` (when the replica changes), `SettingsChangedEvent`/`ServerSavedEvent` on MAIN, `ViewerKey::adopt`, `cluster:rotate-stream-secret`, `NodeLease` state changes. The gateway re-reads the file when its mtime changes (checked once a second) and never caches past `until`.
- **After lockdown** (cluster plan, the per-node-keys third increment), `shared` is empty and only `viewer` keys remain. The gateway handles that exactly as `Encryption::readToken` does: no shared key means no shared-key open.
- **A missing, unreadable or stale file** (older than 10 minutes, or a different `server_id`) makes the gateway redirect every request to PHP. It never fails a viewer for lack of policy.

### Token compatibility (the critical contract)

Go must open what PHP mints and mint what PHP opens, byte for byte, because PHP stays the fallback for every request:

- **Sealed (v2):** `base64url(nonce12 ‖ AES-256-GCM(ct) ‖ tag16)`, key = `HMAC-SHA256(secret, "xc_vm stream token v2|" + context)`.
- **Legacy CBC** (only when `accept_legacy_cbc`, i.e. `secure_stream_tokens` off): AES-256-CBC, key = `md5hex(sha1hex(context) + secret)` as 32 ASCII bytes, IV = first 16 chars of `md5hex(sha1hex(secret))`.
- **Try order**, the same as `readToken`: viewer keys (current, then previous inside its window) → shared current → shared under the previous `OPENSSL_EXTRA` → previous shared secret → legacy CBC in the same order.
- **Shared vectors:** a PHP script (`tools/ci/gateway-vectors.php`) emits `testdata/gateway/tokens.json`: segment, archive, key and live tokens, each sealed under every key slot, plus tampered, truncated and wrong-context cases. Go's `internal/gateway/token_test.go` must open, mint and refuse exactly the same set, and CI runs both sides. This is the same pattern `clustercrypto/testdata` already uses.

### Endpoints and behaviour

nginx (rendered as `gateway.conf` by a new `GatewayNginxConfig`, like `api_legacy.conf`; empty when the gateway is off):

```nginx
# replaces the server-level rewrites for ^/hls/ and ^/key/ while the gateway is on
location ~ ^(/[^/]+)?/(hls|key)/[^/]+$ {
    limit_req zone=one burst=8;
    proxy_pass http://unix:/home/xc_vm/bin/xc_fanout/sockets/gw.sock;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header X-XC-Client-IP $remote_addr;        # after real_ip, as PHP's REMOTE_ADDR
    proxy_set_header X-XC-Peer      $realip_remote_addr;
    proxy_buffering off;
    error_page 502 504 = @segment_php;                   # gateway down → PHP, never the viewer
}
location @segment_php { ... fastcgi_pass php; SCRIPT_FILENAME Public/stream/index.php; XC_STREAM segment|key ... }
location @live_php    { ... XC_STREAM live ... }
```

The gateway answers with one of three things:

1. **Bytes:** it serves the response itself.
2. **A denial** (404, as PHP does today).
3. **`X-Accel-Redirect: @segment_php`** (or `@live_php`): "PHP, take this one." nginx re-runs the original request against FPM, so PHP sees the same URI and arguments as today.

Rule: *anything not proven equivalent goes to PHP.*

| Request | Gateway does | Goes to PHP |
| --- | --- | --- |
| `/hls/<t>` live daemon segment `<id>_d<seq>.ts` | Open, `server_id == own` (else 302 to the owner's base, as `segment.php`), lease, `CONS_TMP_PATH/<uuid>` exists, IP / subnet match, then serve `<seq>` from the hub in-process, with the send-message overlay (`c`, `vc`) and AES as today | Fanout in legacy delivery, or stream not fed |
| `/hls/<t>` on-disk live segment (fanout off) | — | Always (fanout off means the gateway is off) |
| `/hls/<t>` archive segment (`TS/…`, 9 fields) | Step 12.3: validate the name, `uuid`, file; touch via the agent's `POST /v1/conn/{uuid}/touch` and refuse when `hls_end`; serve the file with `offset` through fanout's file server | Before 12.3, or with no agent store (mode 0) |
| `/key/<t>` | Open, IP match, return the stream's key from the hub's memory (given at `registerIngest`), else `STREAMS_PATH/<id>_.key` | Never needed |
| `/auth/<t>` m3u8 refresh | Step 12.4 (below) | First request, any TS, any refusal path |

**Response headers parity:** CORS `*`, `nosniff`, `Server`, the protection headers, `Alt-Svc`, and `Content-Type` (`video/mp2t`, `video/iso.segment`, `application/octet-stream`), all from the policy.

**Proxy routes:** an XC_VM_Proxy in front prefixes `/<route>/hls/…` (`ProxyRoute::segment`). The location matches the optional prefix, and the gateway sends prefixed requests to PHP until the proxy's route check is ported.

### The playlist refresh (step 12.4)

This is where most of the remaining FPM load is. A player's playlist URL on the LB is `/auth/<live token>`. Its first request must stay in PHP: admission, `disallow_2nd_ip_con`, off-air, on-demand start, `createLive`. Later refreshes only need four things: the connection still exists and has not ended, the IP rule, `hls_last_read`, and a freshly tokenised playlist. The gateway can do that when the node's connection store is the agent (CONNECTIONS on):

1. Open the live token (sealed JSON from `StreamAuthMiddleware`). If it is not `m3u8`, or is `video_path`/`off_air`/adaptive/proxied, send it to `@live_php`.
2. Compute `uuid = md5("hls#" + identity + "#" + stream + "#" + ip + "#" + htmlentities(trim(UA)))`, exactly as `ConnectionTracker::hlsConnectionKey`. The shared vectors cover this, including `htmlentities` on non-ASCII user agents.
3. Ask the agent (`GET /v1/conn/{uuid}` on `agent.sock`, which already exists). If there is no record or `hls_end`, send it to `@live_php`, which recreates or refuses exactly as now. If the IP rule fails, return `IP_MISMATCH`.
4. Touch: `POST /v1/conn/{uuid}/touch` on the agent (already there; it feeds the agent's HLS reaper and the P2 `conn.touch` lane to MAIN).
5. Build the body from the hub's playlist in-process: one sealed segment token per line (same payload as `tokenizeDaemonPlaylist`), the MEDIA-SEQUENCE rebased with the **same persisted offset file** `HlsSequence` uses (ported, with that file's locking), and `#EXT-X-KEY` with a key token and the IV.

On mode 0/1 nodes without CONNECTIONS, every refresh goes to `@live_php`. The step is still safe there; it just does not offload anything.

### Rollout

- `settings.gateway_mode`: `off` (default) | `segments` | `segments+playlist`. Per node through the policy file; the Servers page shows each node's mode and the gateway's verdict counters.
- **Shadow first:** in `shadow`, nginx `mirror`s each `/hls/` request to the gateway, which computes its verdict and logs it next to the token's fields, without serving. PHP still serves. A `cron:cluster` step on MAIN compares the gateway's refusals with PHP's `clientLog` for the same uuid. The gateway goes live per node only after 7 days with zero disagreements.
- **Rollback:** set `gateway_mode=off`. `GatewayNginxConfig` renders an empty include and reloads nginx, and the server-level rewrites take over again.

### Steps

| Step | Content | pw |
| --- | --- | --- |
| 12.1 | Token package (`internal/gateway/token`), shared vectors, policy writer and reader, `gateway.conf` renderer, shadow mirror | 1.5 |
| 12.2 | Live daemon segments and keys served in-process; headers parity; metrics (`/metrics`: verdicts, p50/p99, PHP handoffs by reason) | 1 |
| 12.3 | Archive segments: the agent's existing `GET /v1/conn/{uuid}` and touch, file serving with offset | 0.5 |
| 12.4 | Playlist refresh on CONNECTIONS nodes; `HlsSequence` port | 1.5 |
| 12.5 | Load test and canary; ADR 0005 "Segment gateway" | 0.5 |

**Acceptance:**

- With `segments+playlist` on a CONNECTIONS node, an HLS viewer's FPM requests drop to one per session, plus re-auths.
- Gateway auth adds ≤ 1 ms p99 at 5,000 req/s on one core.
- Killing `xc_fanout` mid-stream degrades to PHP within one request (nginx 502 → `@segment_php`) with no viewer error beyond what fanout-down already causes.
- The shared vectors pass on both sides.
- A tampered or wrong-node token is refused by both sides with the same status.

**Tests:**

- Go: `token_test.go` (vectors), `gateway_test.go` (each row of the table above, the redirect to the owner, lease refusal, a stale policy going to PHP), `playlist_test.go` (golden playlists against PHP output).
- PHP: `GatewayPolicyTest` (no secret beyond the allowlist, 0600, atomic), `GatewayNginxConfigTest`.
- E2E: `lb-hls-gateway.spec.ts`: plays HLS clear and encrypted through MAIN → LB, then checks the FPM access log shows one `/stream/live` per session.

---

## 4. Phase 13 — native restreamer `xc_restream` (Go, separate binary)

### Goal

Replace ffmpeg for streams that only **copy** their source (no transcode, logo, filter or custom command), as a separate per-stream process that keeps ffmpeg's output contract. PHP's process model stays unchanged: pid files, the monitor, `ProcessManager`, kill and restart. ffmpeg stays for everything else and remains the automatic fallback.

### Why a separate binary, and why one process per stream

- `xc_fanout` already pulls **proxy** streams in-process (`nativesrc`). Non-proxy streams are different: they must write on-disk HLS for timeshift, `ArchiveCommand`, thumbnails, `.analyse` and the PHP monitor, and they are started, killed and supervised as processes by PHP (`StreamProcess`, `MonitorCommand`) and by the fanout supervisor. A separate binary drops into the place where `ffmpeg` sits today.
- One process per stream isolates a bad source (a parser panic or a memory blow-up kills one channel, not the node's whole fan-out), and keeps `kill <pid>` semantics. A multi-stream server mode can come later if per-process overhead (~5–8 MB RSS) matters.
- It lives in `XC_VM_Fanout/cmd/xc_restream` so it can import the module's `internal/` packages, which already do most of the work: `nativesrc` (HTTP-TS, HLS incl. AES/byte-range/fMP4/packed AAC, UDP/RTP), `tsjoin`, `tsseg`, `tsmux`, `tspes`, `tsmeta` (SPS: resolution, profile), `fmp4`, `hlscrypt` and `redact`. It ships as its own release asset, `xc_restream-linux-<arch>`, with a version sidecar.

### Output contract (what ffmpeg does today, which `xc_restream` must match)

| Output | ffmpeg today (`StreamProcess::buildLive`) | `xc_restream` |
| --- | --- | --- |
| On-disk HLS | `-f hls -hls_time T -hls_list_size N -hls_flags delete_segments…` → `<id>_%d.ts`, `<id>_.m3u8` | `--hls-dir STREAMS_PATH --hls-time T --hls-list-size N`. Cuts on keyframes (`tsseg`), writes a segment then renames it into place, then rewrites the playlist (the `ArchiveCommand` "N+1 exists ⇒ N complete" rule holds), and deletes past the window |
| Fanout feed | `tee … [f=mpegts:onfail=ignore]unix:<ingest.sock>` | `--ingest <sock>`, non-blocking; a dead socket never stalls the on-disk HLS; reconnects |
| Progress | `-progress <file>` key=value blocks | Same keys (`frame`, `fps`, `bitrate`, `total_size`, `out_time_us`/`_ms`, `speed`, `progress=continue/end`) to the same file, once a second |
| Probe | `ffprobe -show_streams -show_format -of json` (`FFprobeRunner`) | `xc_restream probe <url> --json`, an ffprobe-compatible subset: `streams[].{index,codec_type,codec_name,profile,width,height,r_frame_rate,sample_rate,channels,bit_rate}`, `format.{format_name,bit_rate}` |
| Stream selection | `-map 0:v:0? -map 0:a? …`, `resolveOutputMap` | The default map: first video, every audio, DVB subtitles and teletext passed through; a custom map → ffmpeg |
| Timestamps | `-fflags +genpts`, `-copyts` variants, `-re` (`{READ_NATIVE}`) | Continuity counters rewritten, PAT/PMT regenerated, discontinuity → `#EXT-X-DISCONTINUITY`, PTS wrap handled; **real-time pacing on PCR** for files and VOD playlists (the known bug in ADR 0003's LB validation: the native remuxer raced a VOD playlist at about 300×) |
| Source options | `-user_agent`, `-headers`, `-http_proxy`, cookies, `-rw_timeout` | Same knobs (`nativesrc.Options`), credentials redacted in logs and in `/proc/*/cmdline` (URL and headers passed by fd or a 0600 spec file, never argv) |
| Exit | Non-zero → the monitor restarts / rotates the source | Exit codes: `0` end of finite source, `1` source error (retryable), `3` **unsupported format** (fall back to ffmpeg), `4` bad arguments |
| Process identity | `ProcessManager::isRunning($pid, 'ffmpeg')` | `isRunning` accepts `xc_restream` too (and the fanout supervisor's process match). **Every `'ffmpeg'` name check in PHP must be found and widened** |

### Selection: `RestreamEngine`

A new `Streaming/Codec/RestreamEngine::choose(array $rStream, array $rSettings): string` returns `native` or `ffmpeg`, with a reason:

- **Native only when** the global `restream_engine` setting is `auto` (default `ffmpeg` until 13.4), the stream's `restream_engine` override is not `ffmpeg`, the profile is copy for audio and video (`applyDefaultCopyCodecs` result with no extra flags), and there is no logo, `custom_ffmpeg`, custom map, subtitle import, GPU, delay or LLOD v1/v2 with transcode. The source scheme must be in the native set, and the binary must be installed and licence-allowed.
- **Fallback:** exit code `3`, or two crashes within 60 s, writes `STREAMS_PATH/<id>_.engine` (`ffmpeg`, the reason, and an expiry of 1 h). `buildLive` respects it, and the stream log records it.
- The admin stream page shows the engine in use and the reason ("native: copy profile", "ffmpeg: logo overlay").

### Protocol coverage, by step

| Step | Sources | Notes |
| --- | --- | --- |
| 13.1 | HTTP(S) MPEG-TS, HLS (all `nativesrc` cases), UDP/RTP (multicast with `localaddr`/interface), local file | Covers the common IPTV restream |
| 13.3 | RTMP pull (FLV H.264/HEVC-enhanced, AAC → TS), RTSP (`gortsplib`, MIT), SRT (`datarhei/gosrt`, MIT) | Each behind its own allow-list bit; licences checked against AGPL |
| 13.5 | Optional: RTMP **push** output (`buildFlvOutput`), and LLOD v3: replace `LlodCommand`'s PHP segmenter with `xc_restream --ingest` | Removes the last PHP byte loop in the producer |

### Steps

| Step | Content | pw |
| --- | --- | --- |
| 13.0 | Inventory of every ffmpeg assumption in PHP (process-name checks, the progress-file reader, `FFprobeRunner` consumers, `ArchiveCommand`'s segment rule, thumbnails, `.analyse`) | 0.5 |
| 13.1 | `cmd/xc_restream`: pull → remux → on-disk HLS + ingest feed + progress; PCR pacing; spec file; exit codes | 3 |
| 13.2 | `probe --json`; `RestreamEngine`, `buildLive` branch, fallback file, admin display; `fanout_binary` installs and self-heals `xc_restream` with the same hourly GitHub-release rule (ADR 0004, "Binaries from GitHub on every node") | 1.5 |
| 13.3 | RTMP/RTSP/SRT sources | 2 |
| 13.4 | Canary: `restream_engine=auto` on one LB, 7-day comparison with ffmpeg on the same sources; then default `auto` | 1 |
| 13.5 | Optional: RTMP push, LLOD v3 | 1–2 |

**Acceptance:**

- On a node with 200 copy channels, CPU and RSS per channel fall by at least 50% against ffmpeg -c copy.
- Timeshift, catch-up, thumbnails and the PHP monitor work unchanged against native-written HLS (`ArchiveCommand` records every minute).
- A VOD-playlist "channel" plays in real time.
- An unsupported source falls back to ffmpeg within one restart.
- No credential appears in `/proc/*/cmdline`.

**Tests:**

- Go: golden TS and HLS against ffmpeg output on the `tsfixture` corpus (PID, CC and PTS continuity; segment durations within ±1 GOP); a hostile-input fuzz test of the demux path; pacing tests; progress-file format tests.
- PHP: `RestreamEngineTest` (each eligibility rule), `ProcessNameTest` (the widened checks).
- E2E: `lb-native-restream.spec.ts`: one copy channel each over HTTP-TS, HLS and UDP; timeshift playback from it.

---

## 5. How it fits together on a node

```mermaid
flowchart LR
  P[player] -->|/auth first req| N[nginx]
  P -->|/auth refresh, /hls, /key| N
  N -->|first request, fallback| FPM[PHP-FPM live/segment/key]
  N -->|gw.sock| GW
  subgraph FAN [xc_fanout]
    GW[internal/gateway] --> HUB[hub: ring + in-RAM HLS]
  end
  GW -->|conn get / touch| AG[xc_agent]
  RS[xc_restream per stream] -->|ingest.sock| HUB
  RS -->|<id>_N.ts, _.m3u8, progress| DISK[(STREAMS_PATH)]
  SRC[source] --> RS
  FPM -. policy.json .-> GW
  AG -->|cluster API| MAIN[(MAIN)]
```

## 6. Decisions needed

| # | Question | Recommended |
| --- | --- | --- |
| G1 | Gateway inside `xc_fanout` (A), in the agent (B), or a new binary (C)? | A |
| G2 | Is the viewer key in a 0600 file read by fanout acceptable (same user as PHP-FPM)? | Yes |
| G3 | Ship the playlist refresh (12.4) only on CONNECTIONS nodes, or also read MAIN's Redis from Go in mode 0? | CONNECTIONS only; mode 0 goes to PHP |
| G4 | Phase 10's removal release: after Phase 12 has run a release cycle? | Yes |
| R1 | `xc_restream` licence-gated like fanout (`LicenseGate`)? | Same gate as fanout |
| R2 | Per-stream process (drop-in) now, a multi-stream server later only if measured? | Yes |
| R3 | RTMP/RTSP/SRT (13.3) in scope for the first release? | No; HTTP/HLS/UDP first |
| R4 | Default `restream_engine` after the canary: `auto`? | Yes, with the per-stream override |

## 7. Risks

- **Token drift between PHP and Go.** Mitigated by shared vectors in CI on both repos, and by "unknown means PHP".
- **`hlsConnectionKey` mismatch** (UA `htmlentities`, IP form): the uuid would differ, so refreshes would go to PHP and recreate connections. That is safe (PHP still works) but would double-count. The vectors cover it, and the shadow compares uuids.
- **A segment cut that differs from ffmpeg's** breaks `ArchiveCommand` or thumbnails. Covered by the golden comparison and the canary, and the per-stream override reverts at once.
- **ffmpeg name checks missed** make a native stream look dead, so the monitor restart-loops it. 13.0's inventory and `ProcessNameTest` cover this.
- **New attack surface in fanout** (token parsing on a viewer-facing socket). Bounded input sizes, fuzzing of the token and URL parser, and no MAIN credentials in the policy file (only viewer keys, which nodes already hold).
