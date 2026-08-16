# `xc_fanout` — the Go live-delivery daemon (why it exists)

`xc_fanout` is a small, native Go daemon that XC_VM uses to deliver **live**
streams to viewers. This page explains *why* it exists and *how the panel talks
to it*. The decision records are ADR 0001/0002/0003; this is the plain-language
overview.

The daemon's source and binaries live in the separate **`XC_VM_Binaries`** repo
(`xc_fanout/`), not in the panel tree, and it is never bundled into the panel/LB
archive. It is a single static binary per architecture.

---

## The problem it solves

Historically PHP was **in the byte path** of every live viewer:

- **Proxy streams** — `Public/stream/live.php` ran a `socket_read → echo → flush`
  loop for the whole session, one PHP-FPM worker pinned per viewer.
- **Non-proxy streams** — `live.php` chase-read the HLS `.ts` segments off tmpfs
  and echoed them as a continuous MPEG-TS, again one worker per viewer.

We measured it (ADR 0001, P0): **~1 PHP-FPM worker + ~25 MB of RAM per viewer.**
That linear RAM law — not `pm.max_children` — is the real ~400-concurrent
ceiling. A 4 GB box walls at ~55 viewers on RAM alone. Every viewer is a blocked,
expensive worker instead of a cheap connection.

The whole redesign is about **getting PHP out of the byte path** so a viewer
becomes a cheap nginx→daemon connection. The daemon is what holds those
connections.

---

## What the daemon does

One process serves many streams. Per stream it holds **one** ingest and fans it
out to many viewers, plus segments the same feed into HLS **entirely in RAM**
(no tmpfs, no files):

- **Live TS fan-out** — `GET /live/<id>`: PAT/PMT + keyframe-aware ring buffer,
  each new viewer gets a clean-join snapshot then the live tail; slow viewers are
  dropped, never blocking the producer or others.
- **In-RAM HLS** — `GET /hls/<id>/index.m3u8` and `/hls/<id>/<seq>.ts`: cuts
  segments at video keyframes with PTS-accurate `EXTINF`, sliding window; can
  AES-128-CBC **encrypt** segments (matching the panel's `encrypt_hls`).
- **Two ways to be fed**:
  - **pull** (proxy streams) — the daemon connects to the source itself
    (direct `video/mp2t`, or spawns ffmpeg to remux other inputs). Replaces
    `Cli/Commands/ProxyCommand.php`.
  - **push / ingest** (non-proxy & LLOD) — the stream's existing producer writes
    MPEG-TS into a per-stream unix socket the daemon listens on. The producer's
    real work (transcode, logo, profiles) stays where it was.
- **On-demand lifecycle** — starts a puller on the first viewer, stops it after a
  grace once the last leaves; HLS requests keep it alive too.

Two unix-socket HTTP surfaces: a **client** surface nginx proxies viewers to, and
a **control** surface only the panel (PHP) talks to.

---

## What the daemon does NOT do

- **It does not replace ffmpeg.** For non-proxy streams the per-stream ffmpeg
  keeps doing the real work (transcode, logo overlay, profiles, timeshift
  recording). The daemon only takes over *delivery*.
- **It does not do auth.** PHP still authenticates every request (tokens, line
  limits, IP match, connection tracking). The daemon only moves bytes.
- **It does not touch the database.** All DB writes stay in PHP — this keeps LB
  nodes privilege-free.
- **Live only.** VOD, series, timeshift/archive are random-access (seek /
  byte-range) over files, not a shared live tail — they keep their own path.

---

## How the panel talks to it

The switch is the **daemon's reachability**, not a settings flag: `live.php`
routes a stream to the daemon when its control socket is present and the stream
is being served; otherwise it runs the legacy path. **Stopping the daemon is the
rollback** — every stream falls back automatically, per node.

Delivery uses the same `X-Accel-Redirect` pattern as P1 HLS: PHP authenticates,
then hands the byte path to nginx, which proxies to the daemon. The FPM worker is
freed the instant PHP returns.

**Client surface** (nginx → daemon, via internal `X-Accel` locations):

| Request | Daemon route |
|---|---|
| live TS | `X-Accel-Redirect: /xc_fanout/<id>?c=<uuid>` → `/live/<id>` |
| HLS segment | `X-Accel-Redirect: /xc_fanout_hls/<id>_<seq>` → `/hls/<id>/<seq>.ts` |
| HLS playlist | PHP fetches `/hls/<id>/index.m3u8`, tokenizes the segment URLs |

**Control surface** (PHP → daemon, `FanoutClient` over the control unix socket):

| Call | Purpose |
|---|---|
| `PUT /streams/<id>` | register a proxy source (urls, ua, proxy, cookie, ffmpeg, key/iv) |
| `PUT /ingest/<id>` | create the push ingest socket for a non-proxy/LLOD producer (+ key/iv) |
| `POST /probe/<id>?wait=` | prewarm + wait for first data → off-air detection |
| `GET /streams/<id>` | status (`running`, `has_data`, `since_data_ms`) |
| `GET /connections` | active live-TS viewer uuids (for `fanout_sync` reconcile) |
| `DELETE /streams/<id>` | stop / drop the stream |

`FanoutClient` (`src/Streaming/Fanout/FanoutClient.php`) is the PHP client.
The **`fanout_sync`** daemon (`console.php fanout_sync`) reconciles connection
records against `GET /connections` so a viewer's disconnect frees their line's
slot (under X-Accel, PHP never sees the disconnect itself).

---

## Where it runs

Supervised from `src/service` (a keepalive loop restarts it on crash). Its
sockets live next to the binary in the app bin tree
(`bin/xc_fanout/sockets/{http,control}.sock`), mirroring the php-fpm sockets
layout nginx already reaches over `unix:`. Installed on MAIN first; LB rollout is
a later phase.

See `docs/adr/0001-*`, `0002-*`, `0003-*` for the full design and the phased
cutover plan.
