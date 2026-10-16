# LB as a single Go binary — roadmap (no nginx, no php-fpm)

**Goal.** A load-balancer node runs exactly one process — the Go `xc_node`
binary — with **no nginx and no php-fpm** installed. It binds the public
ports, terminates TLS, answers every request a viewer or the cluster sends an
LB, runs the stream lifecycle, the agent, the remux, and the LB's crons. MAIN
stays the PHP panel; this is about the LB only (the long-standing "LB as one
binary" line).

**Non-negotiable:** the transition keeps strict parity with the PHP/nginx
behaviour and rolls out shadow-first (judge-and-compare before serve), exactly
as the segment gateway already does. A wrong answer in auth is an access-control
failure, so correctness gates everything.

## Where we are

- **Phase 1 — one binary.** `xc_node` merges fanout + agent + remux, dispatching
  by argv0/subcommand. Merged both repos; dormant until a Fanout release ships it.
- **Phase 2 — Go serves the streaming request path (the worker-holding
  endpoints).** Done and in review (XC_VM_Fanout #81, XC_VM #431): segment, key,
  live (+ HLS first request), thumb, subtitle, VOD, timeshift (archive + m3u8).
  Each is a `Judge*` that serves the proven happy path and defers the rest to PHP;
  rolled out via the `gateway_mode` ladder, shadow-first.

## What still stands between us and "no php-fpm, no nginx" on the LB

### A. The rest of the request path, in Go (to reach defer-0)

Everything an LB's php-fpm still answers must move into the Go binary:

1. **`StreamAuth` minting (`auth.php`, ~860 lines) — THE KEYSTONE.** `/stream/auth`
   is the entry point every `/movie`, `/live`, `/series`, bare `/user/pass/stream`
   funnels through. It is the whole viewer-authorization system: credential/token
   validation, line status (expired / banned / disabled), geo / IP / ASN / ISP /
   User-Agent allow- and block-lists, hosting & proxy detection, device limits,
   bouquet access, server selection / redirect target, and finally minting the
   per-request token the serve endpoints (already in Go) consume. Nothing streams
   without it, and it is the single largest and most security-critical port in the
   whole initiative. It also needs the LB to read, in Go, the data it decides on:
   the user/line records (agent/replica), GeoLite2 + ASN (the MaxMind DBs), the
   bouquet and block-rule settings. **This is its own sub-project with its own
   design (next).**
2. **`probe`, `rtmp`, `xfile`, `status`/`progress`, cluster `ctl`/`ingest`.** Smaller
   but each real: `xfile` is cluster file-ticket crypto; `rtmp` is the nginx-rtmp
   auth-notify (ties to replacing nginx-rtmp, below); the cluster lanes are partly
   in Go already (the agent).

### B. nginx's jobs, absorbed by the Go binary (to delete nginx)

- TLS termination + ACME (`/.well-known/acme-challenge/`), multi-port listen.
- HTTP/1.1 + HTTP/2 (today) and HTTP/3 (the Alt-Svc headers already advertise it).
- The ~30 server-level rewrites (`/play`, `/movie`, `/hls`, `/vauth`, …).
- The `limit_req` rate-limit zones; trusted-proxy real-IP (Cloudflare/CDN ranges).
- Static (`/images`, `/portal/assets`), gzip.
- `X-Accel-Redirect` interpretation — already in-process for the fanout hand-offs.
- **nginx-rtmp** (the RTMP *server*, not just the auth notify) is a separate
  sub-system; replacing it is its own effort, and that is where RTMP auth lands.

### C. Lifecycle & crons in Go (what php-fpm/cron did)

Stream start/stop/monitor, the reaper, EPG/maintenance loops, etc. — Phases 4–5.

## Strategy — port to defer-0, then flip (no permanent bridge)

The LB's surface is narrow, so we finish it in Go and flip, rather than keep a
long-lived Go→php-fpm FastCGI bridge:

1. **Finish the request path in Go, shadow-first.** Keystone `StreamAuth` minting
   first, then probe / xfile / status / cluster. Defer-rate → 0 on the LB.
2. **The Go binary becomes the listener.** It binds the ports, terminates TLS +
   ACME, does the rewrites / rate-limit / real-IP / static, routes everything
   in-process. nginx removed.
3. **Lifecycle + crons in Go** (Phases 4–5) — the last of php-fpm's work.
4. **`LbInstallFlow` stops provisioning nginx and php-fpm.** One binary.

A Go→php-fpm FastCGI client is kept available only as transition insurance (to
flip nginx before step 1 is fully complete if ever needed); it is not the
destination.

## Risks / open questions

- **Auth parity is the whole game.** The block/allow chain, GeoIP/ASN, bouquets
  and line status must match PHP exactly; shadow comparison must cover auth
  verdicts (serve-target + error) before any LB serves auth from Go.
- **Data access on the LB.** Go needs the user/line/geo/bouquet data an API-mode
  LB has only via the agent/replica (no MySQL). Mapping that is part of the auth
  design.
- **TLS/ACME lifecycle** must match however certs are issued/renewed today.
- **HTTP/3** needs a QUIC implementation (quic-go) — deferred until the rest is stable.

## Next

The gating sub-project is the **`StreamAuth` minting design**: map `auth.php` /
`StreamAuth` end to end — every check, every data source, the mint, the redirect
— and decide the Go port boundary (what it owns vs. defers first) and how the LB
reads the data in Go. No auth code before that design.
