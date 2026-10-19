# LB nginx removal — the Go front becomes the sole listener (design)

**Status:** draft for review. Part of the "LB as a single Go binary" initiative
(roadmap `2026-10-16-lb-single-binary-roadmap-design.md`, strategy step 2 +
the HTTP half of step 4). Implementation is **gated on Phase 5 (loopback)
merging** — XC_VM_Fanout #94 and XC_VM #448 — because the Go agent must already
run the full stream lifecycle before the PHP that currently serves it is removed.

## Intent and why

A serve-only load-balancer node today runs three HTTP-ish daemons: nginx (the
TLS edge + reverse proxy + static + sendfile), php-fpm (the per-request stream
front controllers), and the Go `xc_node` fanout/agent/gateway. The viewer-facing
request path has already moved into Go (the segment gateway, Phases 2–3: segment,
key, live incl. HLS-first, thumb, subtitle, VOD, timeshift), and with the
serve-only decision the LB authenticates nothing — MAIN mints a per-node token,
the LB verifies and serves. The Go `internal/front` already terminates TLS on one
port and proxies the rest to nginx as a strangler (XC_VM_Fanout #82, release
0.15.5).

What remains to **delete the HTTP nginx entirely** is to make the Go front the
*only* thing binding the public ports and to serve, in-process, the handful of
endpoints nginx still fronts. Once the front owns the ports and every route, nginx
has no HTTP job left, and php-fpm's HTTP surface disappears with it.

The user's framing of the end state (verbatim intent): *"nginx completely removed
and also the php; Go should behave like nginx — accept new port, listen to
multiple ports, change ports from MAIN, etc."* The port behaviour is the heart of
this phase: nginx on an LB does not listen on one fixed port, it listens on a
**set** of ports that MAIN controls and can change at runtime, and the Go front
must reproduce that faithfully.

## Goals

- The Go front is the **sole** public listener on a serve-only LB. nginx (HTTP)
  is not running and is not installed by `LbInstallFlow`.
- The front binds the node's **full port set** — the primary `http_broadcast_port`
  and `https_broadcast_port` plus every extra in `http_ports_add` /
  `https_ports_add` — plain HTTP on the http ports, TLS on the https ports.
- A **MAIN-driven port change takes effect at runtime** without a process restart
  and without dropping active viewer connections: new ports start listening, removed
  ports stop, the way `nginx -s reload` behaves today.
- Every route nginx serves today is answered by the front: the gateway serve
  routes (made authoritative), the HLS sendfile (`/xc_hls`), the fanout routes
  (`/xc_fanout*`), and static assets (`/images`, `/portal/assets`) served
  **in-process**; the edge endpoints `/status` / `/progress` kept **local-only**,
  `probe` **token-gated**, and `/xfile` **proxied to the agent** (which holds the
  node signing key) — see Architecture §3 and "Decisions settled".
- Rate-limiting, real-client-IP resolution, and gzip are applied by the front on
  all routes (nginx no longer backs them).
- TLS certs and ACME continue to work exactly as today (certbot-issued files,
  hot-reloaded; `/.well-known/acme-challenge/` from the webroot).
- The RTMP ingest auth-notify (`/stream/rtmp`, called by the still-running
  `nginx_rtmp`) is answered by Go, deferring authorization to MAIN, so RTMP ingest
  keeps working with no PHP behind it.

## Non-goals

- **Replacing the nginx-rtmp *server*** (the RTMP listener binary `nginx_rtmp`) is
  out of scope — that is a separate track (a Go RTMP server). `nginx_rtmp` stays
  installed and running. **But its HTTP auth-notify (`/stream/rtmp`) is in scope**
  (serve-only LBs ingest RTMP — confirmed), because removing the HTTP/PHP surface
  would otherwise break it; see "RTMP ingest auth-notify".
- **The non-HTTP PHP** is out of scope and is a named follow-on (see "PHP after
  this phase"). Several pieces have no Go replacement yet.
- **HTTP/3 / QUIC** is deferred (roadmap open question; the front keeps advertising
  Alt-Svc as today but need not serve h3).
- No change to how MAIN mints tokens or to the gateway's verification/serving
  logic — this phase changes who *listens* and what is served in-process, not the
  auth model.

## Anti-goals (what makes this a failure even if it "works")

- Dropping or resetting active viewer streams when MAIN changes a port, when a cert
  renews, or when the front re-binds. Re-binding must be additive/graceful.
- A port change that requires restarting `xc_node` to take effect.
- Unbinding **all** listeners because a replica delivered an empty or malformed
  port set — that is a self-inflicted outage. The front keeps the last-good set.
- A bind failure on one new port taking down the listeners that are already
  serving. One bad port is reported and skipped; the rest keep serving.
- Serving any route without the token/limit checks the gateway already enforces.

## Current state (verified in-tree)

Port model, MAIN side:
- `servers` row carries `http_broadcast_port`, `https_broadcast_port` (primary),
  `http_ports_add`, `https_ports_add` (comma-lists of extra ports), `rtmp_port`
  (RTMP, out of scope). `ServerService` manages these from the admin's
  `http_broadcast_ports` / `https_broadcast_ports` arrays (primary = first, the
  rest serialised into `*_ports_add`).
- The port fields are in the replica **server section** already
  (`src/Core/Cluster/ReplicaSections.php:111,120-121`), so the node receives a port
  change without new plumbing.

Port model, node side (what the front replaces):
- nginx renders its listen set from `src/bin/nginx/conf/ports/http.conf`
  (`listen 80 reuseport;`) and `ports/https.conf` (`listen 443 ssl reuseport;`),
  regenerated on the node from the server row and reloaded on change.
  `ClusterNginxConfig::servedPorts()` reads those two files to learn the set.

The Go front today (`XC_VM_Fanout/internal/front`, on `feat/lb-transcode-serve`):
- `cert.go` `CertReloader` reuses certbot's `server.crt`/`server.key`, hot-reloads
  via `tls.Config.GetCertificate`, keeps last-good, fatal only if missing at start.
- `server.go` terminates TLS on one `-front` address, serves ACME from the webroot,
  routes the serve paths to the in-process gateway, and reverse-proxies everything
  else to nginx (`-front-nginx http://127.0.0.1:8080`); an interceptor turns a
  gateway `X-Accel-Redirect` PHP-defer into a re-proxy to nginx.
- `realip.go` `TrustedProxies` + `RealIP(r)` resolve the viewer IP from the same
  `realip_*.conf` CIDRs nginx uses. `ratelimit.go` is a stdlib token bucket matching
  nginx zone `one` (20 r/s, burst 8) on the serve routes.
- It is opt-in (`-front`) and today sits **in front of** nginx; `run.sh` launches it
  only on a serve-only node that reports `-front` capability, and
  `RootSignalsCronJob` demotes nginx `:443→:8080` once the front is capable.

Constraint that shapes everything below: the Go module has **zero external
dependencies by design** — stdlib only (`net`, `net/http`, `crypto/tls`). No
`golang.org/x/...`. Listener management, graceful drain, and gzip are hand-rolled
from stdlib.

## Architecture

### 1. A listener manager (the nginx-parity port owner)

Introduce a listener manager in `internal/front` that owns a *set* of listeners
rather than the single `-front` address:

- **Desired set:** a list of `{port, tls bool}` derived from the node's port
  config — the primary http/https ports plus the `*_ports_add` extras. http ports
  get a plain `net.Listener`; https ports get a `tls.Listener` using the existing
  `CertReloader` config. All use `SO_REUSEPORT` to match nginx and to allow a
  zero-drop handover from nginx during the transition.
- **Reconcile(desired):** diff desired against the live set. Start a listener for
  each added port (each serving the same `http.Handler`); stop each removed one by
  closing its listener and letting its `http.Server` drain in-flight requests
  (graceful `Shutdown` with a bounded deadline; long-lived stream responses are
  allowed to finish or hit the deadline — tunable). A bind error on one port is
  logged and that port is skipped; it never aborts the reconcile or disturbs the
  serving listeners.
- **Fail-safe:** an empty or unparseable desired set is rejected — the manager
  keeps the current set and logs. The front never ends up listening on nothing.

### 2. The port-change signal (node-local, cross-process)

The replica (with the new port set) is applied by the agent process; the front runs
in the fanout process. The front learns the desired set **without MAIN talking to
it directly**, via a node-local file the agent already-or-newly writes:

- **Author's call (open to the builder):** the agent writes the resolved port set
  to a small node-local file (reuse/replace the role `ports/*.conf` plays today, or
  a dedicated `config/cluster/front_ports`), and the front **watches** that file
  (fsnotify is an external dep — instead poll on a short interval, or read on
  SIGHUP, or accept a one-line message on the existing agent↔fanout control
  channel). On change it calls `Reconcile`. Polling a tiny file every few seconds is
  the stdlib-only default; the builder may choose the control-channel path if one
  already exists between agent and fanout. This mirrors the existing "agent writes
  config, node component reacts" pattern and keeps MAIN→node on the one replica
  channel it already uses.

### 3. One handler, every route in-process

The front builds a single `http.Handler` (shared by every listener) that routes:
- **Serve routes** (`/hls`, `/key`, `/auth`, `/vauth`, `/tsauth`, `/thauth`,
  `/subauth`, `/stream/*`) → the in-process gateway, **authoritative** (see
  rollout). The X-Accel-defer-to-nginx interceptor is removed once the serve path
  is authoritative and nginx is gone.
- **Sendfile** `/xc_hls/<file>` → serve the file from `STREAMS_PATH` directly
  (Go `http.ServeContent`/ranged file serve), replacing nginx `X-Accel-Redirect`
  sendfile.
- **Fanout** `/xc_fanout*`, `/xc_fanout_hls`, `/xc_fanout_file` → already the Go
  fanout; route to the in-process handler instead of the unix socket + nginx.
- **Static** `/images`, `/portal/assets` → serve from disk with correct content
  types.
- **Edge endpoints** (trust boundaries preserved, not widened):
  - `/status` stays **local-only** — today it is `allow 127.0.0.1; deny all`; the
    front enforces loopback on the TCP peer, never exposing node internals at the
    edge. (Correction: `/progress` is NOT local-only — it is a public route with
    `limit_req zone=one`, like the serve routes; the cutover serves it natively with
    that rate limit, not a loopback check.)
  - `probe` (ffprobe-backed, MAIN-triggered) is **token-gated**: the front verifies
    the same MAIN-minted proof `probe.php` checks before running ffprobe. This is an
    SSRF / resource-abuse surface at the true edge; it keeps the project's agreed
    LB-SSRF posture (no new egress policy beyond the one already accepted), and adds
    no capability the token does not already authorise.
  - **`/xfile`** — the cross-node file read (ticket + node-key proof + per-chunk
    signed digest). The owner-side proof needs the node's Ed25519 signing key and
    replay-nonce window, which live **only in the agent** (`agent.json`;
    `DataPlaneTrust::signDigest`/`spendNonce` already delegate to the agent's local
    HTTP). The key is NOT duplicated into fanout. So the front **routes `/xfile` to
    an owner-side handler added to the agent** over the existing fanout↔agent local
    socket (the gateway already uses `agent_sock`). "Served by the node, fronted by
    the listener" — not re-implemented in fanout. `/xfile` is the security-sensitive
    piece and the largest single port; see "Review focus".
  - **`/stream/rtmp`** — the nginx_rtmp auth-notify. Routed to an agent-side handler
    (MAIN-deferred authorization), same reasoning as `/xfile`. See "RTMP ingest
    auth-notify" for the behaviour and sequencing.
- **Cross-cutting middleware** applied by the front on all routes: real-IP
  resolution (`realip.go`, trusted-proxy CIDRs from the `realip_*.conf` includes),
  rate-limit (nginx had two zones: `one` = per-viewer on serve routes, and
  `xfile`/`cluster` = per-peer on node-to-node — reproduce both keyed appropriately),
  and gzip (stdlib `compress/gzip` middleware on the content types nginx gzipped).
- **ACME** `/.well-known/acme-challenge/` served from the webroot on the http
  listeners (validated single-token `ServeFile`, as the front already does).

### 4. MAIN side

- The port set already flows to the node (replica server section). The only MAIN
  change for this phase is **`LbInstallFlow` stops provisioning the HTTP nginx** for
  a serve-only node (binary, pools-equivalent, confs, and the systemd/daemons wiring
  that starts it) and launches `xc_node` with the front owning the ports directly
  (no `-front-nginx` backend). This is the final step and lands only after the front
  serves everything.
- Authoritative serving rides the **existing `serve_only` policy boolean** — the Go
  gateway already honours `Policy.ServeOnly` (`internal/gateway/policy.go`,
  `live.go`). No new `gateway_mode` enum rung, migration, settings dropdown,
  `lb_settings_keys`, or i18n is needed. The only MAIN change is that PHP's
  `GatewayPolicy` must **emit `serve_only: true` in the node's policy** for a
  serve-only node (serve_only-gated, built on the Part A marker); confirm/complete
  this emission as part of wiring.

## Rollout (staged, not big-bang) — author's call

1. Build the listener manager + in-process routes + edge-endpoint ports behind the
   existing opt-in front, still in front of nginx (strangler). Shadow/serve via the
   `gateway_mode` ladder as today.
2. Advance the serve path to **authoritative** (the front answers, no nginx
   fallback) by MAIN emitting `serve_only: true` in the node's policy, once the
   shadow window is clean per the established readiness gate.
3. Flip the front to own the **real** ports (bind the full set directly) with nginx
   demoted/stopped but **still installed** as a one-release safety net.
4. `LbInstallFlow` stops installing the HTTP nginx. One binary on the HTTP path.

Each step is independently shippable and reversible; a problem at any step falls
back to the prior one.

## Decisions settled during builder-check (author's calls)

A fresh reviewer read this design against both repos. The calls made in response,
each now reflected above:

- **`/xfile` stays in the agent.** The node's Ed25519 signing key and replay-nonce
  window live only in the agent, so the owner-side `/xfile` proof is produced there;
  the front proxies `/xfile` over the existing fanout↔agent socket. The key is never
  copied into fanout (that would spread the node's private key to a second process).
- **No new `gateway_mode` rung.** Authoritative serving uses the `serve_only` policy
  boolean the Go gateway already honours; MAIN only needs to emit it for a serve-only
  node. This avoids a migration + settings dropdown + i18n that would never be acted
  on separately.
- **`/status` and `/progress` stay local-only; `probe` stays token-gated.** Removing
  nginx must not widen these trust boundaries.
- **Single SAN cert; certbot webroot authenticator** once the front owns `:80`.

## RTMP ingest auth-notify (confirmed in scope)

Serve-only LBs **do** ingest RTMP (user-confirmed). `nginx_rtmp` is provisioned on
every LB, and its `on_publish`/`on_play`/`on_play_done` callbacks hit
`http://127.0.0.1:<http_broadcast_port>/stream/rtmp` — today `Public/stream/rtmp.php`
under php-fpm. Removing the HTTP/PHP surface deletes that answerer, so a Go handler
must take over, or RTMP ingest breaks.

**It is a faithful behavioural port, not a redesign, and it fits serve-only:**
`rtmp.php` already carries the LB path and **defers authorization to MAIN** — a
serve-only LB has no DB, so it is "not shipped the line lookup." Specifically the LB
arm: parses the nginx_rtmp notify args (`StreamAuth::notifyArguments`); accepts the
stream password or an allowed-RTMP address (`live_streaming_pass` /
`BlocklistService::getAllowedRTMP`, replica-shipped); otherwise asks MAIN for its
last yes (`RtmpOffline::ask`); resolves the stream/this-node row from the replica
(`StreamSource::local`/`joined`, not a DB query); and opens the connection record
with line-limit admission via **the agent asking MAIN** (`ConnectionTracker::
openRecord` → `conn_admit`). All of that is MAIN-deferred or replica-backed — no LB
DB.

**Where it runs:** like `/xfile`, the machinery it needs (the MAIN-ask lanes,
`conn_admit`, the connection records) lives **agent-side**, so the front routes
`/stream/rtmp` to a handler in the agent and relays nginx_rtmp's allow/deny. The
front itself only listens and routes.

**Scope note:** this is a distinct, substantial sub-piece that overlaps the
connection-lifecycle work (Phases 4–5). It gets its **own design pass + adversarial
interop vectors** against `rtmp.php` (like `/xfile`), and it must land **before** the
final removal step — nginx_rtmp's callback target cannot disappear until the Go
handler answers it. It is sequenced within this phase, not folded into another
route's work.

## PHP after this phase (named follow-on, not built here)

Removing the HTTP nginx removes php-fpm's HTTP surface on the LB. What still needs
PHP afterwards — the php-removal follow-on — and why it is not in this phase:
- Lifecycle crons retained on a serve-only LB (`cron:streams` incl. **Thumbnail /
  TVArchive** workers — no Go replacement; `cron:servers` daemon keepalive;
  `cron:cleanup`, `cron:tmp`; `cron:activity`, `cron:streams_logs` drain).
- The Go→PHP-CLI trio `cluster:exec` / `cluster:apply` / `cluster:exec --types`
  (`internal/agentcmd/agentcmd.go:249-252`).
- The root-privileged `cron:root_signals` + `cluster:root` (iptables/ipset/systemd/
  fstab actions — no Go replacement; the nginx-reload parts of them *do* retire with
  this phase).

## Constraints

- Go: stdlib only, zero external deps (module policy). Listener management, graceful
  drain, file-watch/poll, and gzip are stdlib.
- The front is in `XC_VM_Fanout/internal/front`, built into `xc_node`; MAIN stays
  PHP.
- Serve-only LB: no MySQL, no PHP (end state); it authenticates nothing (MAIN mints).
- TLS: reuse certbot's `bin/nginx/conf/server.{crt,key}`; webroot
  `/home/xc_vm/certbot-webroot`. No ACME client reimplementation (certbot stays).
  One SAN cert covers all of the LB's domains today, so all https listeners share
  the single `CertReloader` config — confirmed assumption, not multi-cert SNI. Once
  the front owns `:80`, certbot must run as the **webroot** authenticator (not the
  standalone or nginx plugin); this lands with the LbInstallFlow follow-up.
- AGPL-3.0; no Git LFS; Conventional Commits; English; no Claude attribution in
  commits/PRs.

## What is left to the builder

- The listener-manager implementation and the graceful add/drop of `net.Listener` /
  `http.Server` (drain deadline tuning).
- The port-change signal mechanism (the author's-call default is a node-local file
  the front polls/reads on signal; confirm or pick the agent↔fanout control channel).
- The **`/xfile` owner-side handler in the agent** (the agent holds the key +
  nonce window; the front proxies to it — see Architecture §3 and "Decisions
  settled") — faithful to the PHP (ticket + node-key proof + per-chunk signed
  digest). Security-sensitive and cluster-internal; warrants its own short design
  pass and adversarial interop vectors against the PHP before coding.
- The **`/stream/rtmp` auth-notify handler in the agent** — a faithful port of
  `rtmp.php`'s LB arm (notify-arg parse, `live_streaming_pass` / allowed-RTMP,
  `RtmpOffline` MAIN-ask, `StreamSource::local`, `conn_admit` admission). Its own
  design pass + adversarial interop vectors against `rtmp.php`; lands before the
  removal step.
- `status` / `progress` / `probe` handlers and static serving (paths, content types).
- The gzip middleware's content-type set and the two rate-limit zones' exact keys.
- MAIN-side: `LbInstallFlow` serve-only path stops provisioning the HTTP nginx; the
  `gateway_mode` authoritative-without-nginx rung if absent.

## Review focus (spec-silent inputs most likely to bite)

- A MAIN port change that **removes a port with active streams** — graceful drain vs
  hard close; the deadline must not strand a live viewer indefinitely nor kill them
  instantly.
- A new port that **fails to bind** (already in use) — must be reported and skipped;
  serving listeners untouched.
- **Cert renewal** mid-serve now spans N listeners — the single `tls.Config` /
  `GetCertificate` covers all, but verify no listener caches a stale cert.
- An **empty / malformed port set** from the replica — keep last-good, never unbind
  everything.
- **`/xfile` cluster-crypto parity** — a mismatch either breaks cross-node file reads
  or opens a hole; adversarial parity vectors against the PHP implementation.
- **Real-IP / rate-limit at the true edge** — with no nginx in front, the front is
  the edge; trusted-proxy CIDRs must still come from the `realip_*.conf` includes,
  and a spoofed `X-Forwarded-For` from an untrusted peer must not set the client IP.
- **RTMP ingest with no PHP/DB** — the Go `/stream/rtmp` handler must reproduce
  `rtmp.php`'s LB arm exactly (MAIN-deferred auth via `RtmpOffline`/`conn_admit`,
  allowed-RTMP addresses, stream password); a divergence either lets an unauthorized
  publisher in or rejects a valid one, and an ingest wrongly admitted also skews
  line-limit accounting on MAIN.

## Execution corrections (2026-10-18)

Implementing the plan surfaced that several routes the draft listed for the
front are not front routes on a serve-only LB. Verified against the LB nginx
(`lb_configs/nginx.conf`), the Go gateway, and the `make lb` build:

- **`/xc_hls` and `/xc_fanout*` are nginx `internal;` X-Accel targets**, reachable
  only via an `X-Accel-Redirect`, never by an external client. The Go gateway
  already serves that content **in-process** (its `segments` handler, "without the
  hop" — `internal/gateway/server.go`). Exposing them as public front routes would
  hand out segments/files with no token check. **The front 404s them** (matching
  `internal;`); it does not serve them. Segment/live delivery stays the gateway's
  in-process job, reached through the public serve routes the front already hands
  to the gateway.
- **`/portal/assets` is MAIN-only** (the Subscriber Activation Portal,
  `src/bin/nginx/conf/nginx.conf`; `Public/assets` is stripped from the LB build).
  It is not served on a serve-only LB. (`/images` is a real LB static route and is
  already served in-process by the pre-existing `WithImages`.)
- **`probe` is not a public LB route**: `Public/stream/probe.php` is stripped from
  the `make lb` build; probing is the Go agent's control-lane job. Dropped.
- **gzip is `off` on the LB** (`bin/nginx/conf/gzip.conf`) and `limit_req` applies
  only to the serve routes (`zone=one`, already enforced in the front's
  `serveStream`) and `/xfile` (`zone=xfile`, a follow-on). The front does **not**
  add gzip or rate-limit the sendfile/fanout/static routes — nginx does not, and
  doing so would throttle legitimate segment/fanout traffic.
- **`serve_only` policy emission already shipped** with Phase 5 (XC_VM #448):
  `GatewayPolicy::build()` emits it and `GatewayPolicyTest::testServeOnlyFromThe
  NodeAttribute` pins it. No new MAIN change was needed.

Net: the front's in-phase deliverable is the **multi-port dynamic listener**
(the nginx-parity core), plus a **404 guard on the internal X-Accel paths**, a
**no-nginx-backend safety guard**, and a **local-only guard on `/status`**. The
`/status`/`/progress` native bodies, `/xfile`, `/stream/rtmp`, and the nginx/
php-fpm de-provisioning remain the follow-on sub-projects.
