# LB nginx removal — Go front sole listener (core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Go front (`XC_VM_Fanout/internal/front`, built into `xc_node`) bind the serve-only LB's full MAIN-controlled port set and serve, in-process, every route nginx fronts today **except** `/xfile` and `/stream/rtmp` — so only those two still reverse-proxy to nginx after this plan.

**Architecture:** Replace the front's single-address `ListenAndServe` with a listener manager that owns a *set* of listeners derived from the node's port config and reconciles them on change (nginx-parity, graceful). The front's one `http.Handler` gains in-process handlers for the HLS sendfile, the fanout routes, static assets, gzip, uniform rate-limit/real-IP, and local-only/token-gated edge reads; serving goes authoritative off the existing `serve_only` policy flag, which MAIN now emits. `/xfile` and `/stream/rtmp` keep deferring to nginx (their own sub-projects).

**Tech Stack:** Go (stdlib only — `net`, `net/http`, `crypto/tls`, `compress/gzip`; **zero external deps**). PHP 8.1 for the one MAIN-side task.

**Spec:** `docs/superpowers/specs/2026-10-18-lb-nginx-removal-go-listener-design.md`

## Global Constraints

- Go module is **stdlib only, zero external dependencies**. No `golang.org/x/...`, no fsnotify. Listener management, graceful drain, file-watch, and gzip are hand-rolled from stdlib.
- The front lives in `XC_VM_Fanout/internal/front`, built into `xc_node`; MAIN stays PHP.
- A serve-only LB has **no MySQL and (end state) no PHP**; it authenticates nothing — MAIN mints/authorizes.
- TLS reuses certbot's `bin/nginx/conf/server.{crt,key}` via the existing `CertReloader`; one SAN cert for all listeners. No ACME client reimpl.
- Conventional Commits, English; **no Claude attribution in commits or PRs**.
- Base branch (Fanout): `feat/lb-transcode-serve`. Base branch (XC_VM): `2.8.0`.
- **Execution is gated on XC_VM_Fanout #94 and XC_VM #448 (Phase 5 loopback) merging.** Do not start Task 1 until both are merged.
- Out of this plan (own sub-projects, do not build here): the `/xfile` agent-side handler, the `/stream/rtmp` auth-notify handler, and the final `LbInstallFlow` cutover that stops provisioning nginx.

## Review Focus

- **Port removed while it has active streams** — a reconcile that drops a port must drain in-flight responses to a bounded deadline, not cut them instantly nor hang forever (Task 1).
- **A new port fails to bind (already in use)** — logged and skipped; the serving listeners are untouched (Task 1).
- **Empty / malformed port set from the source** — keep the last-good set; never unbind everything (Tasks 1, 2).
- **Cert renewal spans N listeners** — the single `tls.Config`/`GetCertificate` must cover every https listener; none caches a stale cert (Task 3).
- **Spoofed `X-Forwarded-For` at the true edge** — with no nginx in front, an untrusted peer's XFF must not set the client IP on any route, including the new in-process ones (Task 7).

---

## File Structure

New (Fanout `internal/front/`):
- `listeners.go` — the listener manager: a set of listeners + `Reconcile`.
- `ports.go` — parse the node's listen set into desired specs + the change signal.
- `static.go` — static `/portal/assets` (and the shared ranged-file helper reused by `/xc_hls`).
- `edge.go` — `/status`, `/progress` (local-only), `probe` (token-gated) handlers.

Modified:
- `internal/front/server.go` — route the new in-process paths; gzip + uniform rate-limit/real-IP; authoritative-under-`serve_only` (no nginx reproxy).
- `internal/fanoutcmd/front.go` — wire the listener manager (bind the set, not one `-front` addr).
- XC_VM `src/Core/Gateway/GatewayPolicy.php` — emit `serve_only` in the node policy.

---

## Task 1: Listener manager

**Files:**
- Create: `internal/front/listeners.go`
- Test: `internal/front/listeners_test.go`

**Interfaces:**
- Consumes: nothing (leaf).
- Produces: `type ListenSpec struct { Port int; TLS bool }`; `type Manager struct{…}`; `func NewManager(h http.Handler, tlsCfg *tls.Config, drain time.Duration) *Manager`; `func (m *Manager) Reconcile(desired []ListenSpec) error` (add new, drain+close removed, skip bind errors, no-op on empty/duplicate); `func (m *Manager) Ports() []ListenSpec` (live set); `func (m *Manager) Shutdown(ctx context.Context) error`.

- [ ] **Step 1: Write the failing tests**

```go
func TestReconcileAddsAndServes(t *testing.T)      // reconcile {8081,8082} → both accept HTTP, handler runs
func TestReconcileDropsRemovedPort(t *testing.T)   // {8081,8082} then {8081} → 8082 stops listening, 8081 still serves
func TestReconcileEmptyKeepsLastGood(t *testing.T) // {8081} then {} → returns error, 8081 still serves
func TestReconcileBindErrorSkipsOne(t *testing.T)  // one port already held by a probe listener → that port skipped, the other serves, err names the bad port
func TestDrainLetsInflightFinish(t *testing.T)     // a slow handler on a dropped port completes within the drain deadline
```

Assertions use real `net.Listener`s on `127.0.0.1:0`-style ephemeral ports (bind a probe listener to force the collision case); no mocks.

- [ ] **Step 2: Run the tests — verify they fail** (`go test ./internal/front/ -run TestReconcile -v`; expect undefined `Manager`).
- [ ] **Step 3: Implement `listeners.go`.** Each spec → an `http.Server` on its own `net.Listener` (`tls.NewListener` with `tlsCfg` when `TLS`), all sharing the one handler; `reuseport` is the default socket behaviour on Linux via a `net.ListenConfig` with `SO_REUSEPORT` set in `Control` (stdlib `syscall`, no deps). Drop = `Server.Shutdown(ctx)` with `drain`. A bind error is collected (`errors.Join`) and the port skipped; an empty desired set returns an error and changes nothing.
- [ ] **Step 4: Run the tests — verify they pass.**
- [ ] **Step 5: Commit** (`feat(front): listener manager with graceful multi-port reconcile`).

## Task 2: Desired port set + change signal

**Files:**
- Create: `internal/front/ports.go`
- Test: `internal/front/ports_test.go`

**Interfaces:**
- Consumes: `Manager.Reconcile` (Task 1).
- Produces: `func ParseListenSet(httpConf, httpsConf string) ([]ListenSpec, error)` (parse `listen <port> [ssl] …;` directives — the same files `ClusterNginxConfig::servedPorts()` reads); `func WatchPorts(httpPath, httpsPath string, poll time.Duration, apply func([]ListenSpec)) (stop func())` (poll the two files' mtime+content; on a parse that yields ≥1 port, call `apply`).

- [ ] **Step 1: Write the failing tests**

```go
func TestParseListenSetPrimaryAndAdds(t *testing.T) // "listen 80 reuseport;\nlisten 8080 reuseport;" + "listen 443 ssl reuseport;" → [{80,false},{8080,false},{443,true}]
func TestParseListenSetIgnoresNoise(t *testing.T)   // comments / blank lines / unrelated directives ignored
func TestParseListenSetEmptyIsError(t *testing.T)   // no listen directives → error (so the watcher keeps last-good)
func TestWatchPortsFiresOnChange(t *testing.T)      // rewrite the https file with a new port → apply called with the new set within a few polls
```

- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement `ports.go`.** Regex/scan for `^\s*listen\s+(\d+)(\s+ssl)?`. The watcher compares a cheap fingerprint (mtime + size, or the parsed set) and only calls `apply` on a real change; an empty/error parse is dropped (last-good stands). **Confirm during implementation** that on an LB the node writes the full set (primary + `*_ports_add`) into these two files; if the adds live in a separate include, parse it too — the decision is "read the node's authoritative listen files," wherever they are.
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): parse and watch the node listen set`).

## Task 3: Wire the manager into the daemon

**Files:**
- Modify: `internal/fanoutcmd/front.go`
- Test: `internal/fanoutcmd/front_test.go` (extend)

**Interfaces:**
- Consumes: `NewManager`, `Reconcile`, `WatchPorts` (Tasks 1–2), `CertReloader.TLSConfig()`, `NewServer(...)`.
- Produces: the front now binds the full set; retire the single `-front :addr` bind in favour of the watched set (keep `-front` as the opt-in on/off switch; the bound ports come from the port files, not a flag).

- [ ] **Step 1: Write the failing tests** — `TestFrontBindsWatchedSet`: start the daemon front pointed at temp port files declaring two ports; assert both accept TLS/HTTP and route to the shared handler; rewrite the files to add a port; assert the new port serves. Missing-cert stays non-fatal (existing behaviour). `TestCertRenewalAcrossAllListeners`: with ≥2 https listeners up, swap the cert file; a fresh handshake on **each** listener presents the new cert (one shared `CertReloader`/`GetCertificate`, no stale per-listener cache).
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** Build the `Manager` with the shared `*front.Server` handler and the `CertReloader`'s `*tls.Config`; seed it from `ParseListenSet`, then `WatchPorts(..., mgr.Reconcile-adapter)`. Front stays opt-in and non-fatal (bad files logged, daemon lives); drains the manager on shutdown.
- [ ] **Step 4: Run — verify pass; `go build ./... && go vet ./...`.**
- [ ] **Step 5: Commit** (`feat(front): daemon binds the watched port set`).

## Task 4: In-process HLS sendfile `/xc_hls`

**Files:**
- Create: `internal/front/static.go` (shared `serveFileRange`)
- Modify: `internal/front/server.go` (route `/xc_hls/`)
- Test: `internal/front/static_test.go`

**Interfaces:**
- Produces: `func serveFileRange(w http.ResponseWriter, r *http.Request, path, contentType string)` (ranged, `404` on missing, no directory traversal above the root).

- [ ] **Step 1: Write the failing tests** — `TestXcHlsServesFileFromStreams` (full body + content type), `TestXcHlsRange` (a `Range:` request → 206 + correct slice), `TestXcHlsMissing404`, `TestXcHlsNoTraversal` (`/xc_hls/../../etc/...` → 404/400, never escapes `STREAMS_PATH`).
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** `serveFileRange` via `http.ServeContent` on an opened `*os.File` (it does ranges + conditional requests); `/xc_hls/<name>` maps to `STREAMS_PATH + cleaned-name`, rejecting any cleaned path that escapes the root. Replaces nginx's `X-Accel-Redirect` sendfile.
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): serve /xc_hls in-process`).

## Task 5: In-process fanout routes `/xc_fanout*`

**Files:**
- Modify: `internal/front/server.go`
- Test: `internal/front/server_test.go` (extend)

**Interfaces:**
- Consumes: the daemon's in-process fanout client handler (the `http.Handler` the daemon already serves on `http.sock`, e.g. `mgr.ClientHandler()`), passed into `NewServer` (add a field/option rather than a new positional arg).

- [ ] **Step 1: Write the failing test** — `TestXcFanoutRoutedInProcess`: a request to `/xc_fanout/…`, `/xc_fanout_hls/…`, `/xc_fanout_file/…` reaches the injected fanout handler, **not** the nginx reverse-proxy backend (assert the nginx stub is never called).
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** In `ServeHTTP`, route the three `/xc_fanout*` prefixes to the injected fanout handler; everything still unported falls through to the nginx backend. Add the fanout handler via a `WithFanout(h http.Handler) *Server` option to avoid churning `NewServer`'s signature.
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): route /xc_fanout* to the in-process daemon`).

## Task 6: Static `/portal/assets`

**Files:**
- Modify: `internal/front/server.go` / `internal/front/static.go`
- Test: `internal/front/static_test.go` (extend)

**Interfaces:**
- Consumes: `serveFileRange` (Task 4), the existing `WithImages`/`serveImages` pattern.
- Produces: `func (s *Server) WithPortalAssets(dir string) *Server`.

- [ ] **Step 1: Write the failing test** — `TestPortalAssetsServed` (a known asset → 200 + correct content type) and `TestPortalAssetsNoTraversal`.
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement** mirroring `WithImages`/`serveImages` (content type by extension; no traversal).
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): serve /portal/assets in-process`).

## Task 7: gzip + uniform rate-limit + real-IP on all routes

**Files:**
- Modify: `internal/front/server.go`; new `internal/front/gzip.go`
- Test: `internal/front/gzip_test.go`, `internal/front/server_test.go` (extend)

**Interfaces:**
- Consumes: `newRateLimiter`/`allow` (existing), `TrustedProxies.RealIP` (existing).
- Produces: `func gzipMiddleware(next http.Handler) http.Handler`; a request pipeline in `ServeHTTP` that resolves real-IP once and applies the right limiter zone to **every** route.

- [ ] **Step 1: Write the failing tests** — `TestGzipOnGzippableTypes` (m3u8/text/json gzipped when `Accept-Encoding: gzip`; already-compressed media not double-gzipped), `TestRateLimitZoneOnePerViewer` (serve routes keyed on resolved client IP, 503 past burst), `TestRateLimitNodeZonePerPeer` (node-to-node routes keyed on the TCP peer), `TestRealIPAppliedToInProcessRoutes` (a spoofed XFF from an untrusted peer does not change the keyed IP on `/xc_hls`).
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** `gzipMiddleware` wraps the response writer with `compress/gzip` for a fixed content-type allowlist (matching nginx's `gzip_types`), setting `Content-Encoding` and dropping `Content-Length`. In `ServeHTTP`, resolve `RealIP(r)` once; pick zone `one` (per resolved client IP) for serve/static/sendfile routes and the node zone (per TCP peer) for `/xc_fanout*` — the two zones nginx uses.
- [ ] **Step 4: Run — verify pass; `-race` on the limiter test.**
- [ ] **Step 5: Commit** (`feat(front): gzip, uniform rate-limit and real-IP across routes`).

## Task 8: Authoritative serving under `serve_only` (Go)

**Files:**
- Modify: `internal/front/server.go` (`interceptor` / `serveStream`)
- Test: `internal/front/server_test.go` (extend)

**Interfaces:**
- Consumes: the gateway's existing `Policy.ServeOnly` behaviour (the gateway already returns Serve/Deny, not a PHP-defer, under `serve_only`).

- [ ] **Step 1: Write the failing test** — `TestServeOnlyNoNginxReproxy`: with the front built for a serve-only node (no nginx backend configured), a gateway response that would otherwise defer must **not** attempt an nginx reproxy; a genuine serve/deny/404 streams through, and a defer with no backend returns `502`/logged rather than panicking on a nil backend.
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** When no nginx backend is configured (serve-only), `interceptor.toPHP()` has nowhere to go: treat a defer as an error response, not a reproxy. (Under `serve_only` the gateway should not defer for the ported routes; this guards the nil backend and makes the front safe to run with no nginx.)
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): authoritative serving with no nginx backend`).

## Task 9: MAIN emits `serve_only` in the node policy (PHP)

**Files:**
- Modify: `src/Core/Gateway/GatewayPolicy.php`
- Test: `tests/Unit/Gateway/GatewayPolicyTest.php` (extend)

**Interfaces:**
- Consumes: the server row's serve-only marker (`servers.serve_only`, Part A).
- Produces: `policy.json` carries `serve_only => true` for a serve-only node, `false`/absent otherwise (the Go `Policy.ServeOnly` json tag is `serve_only`).

- [ ] **Step 1: Write the failing test** — `testPolicyCarriesServeOnlyForServeOnlyNode` (a serve-only server row → `serve_only` true in the built policy) and `testPolicyServeOnlyFalseForNormalNode`.
- [ ] **Step 2: Run — verify fail** (`php tests/phpunit.phar -c tests/phpunit.xml.dist --filter GatewayPolicy`).
- [ ] **Step 3: Implement** — add `serve_only` to `GatewayPolicy::build()` from the server row, gated on the existing serve-only signal.
- [ ] **Step 4: Run — verify pass; `make gates`, `make phpstan`, `make cs`.**
- [ ] **Step 5: Commit** (`feat(cluster): emit serve_only in the node gateway policy`).

## Task 10: Edge endpoints — `/status`, `/progress` local-only; `probe` token-gated

**Files:**
- Create: `internal/front/edge.go`
- Modify: `internal/front/server.go` (routing)
- Test: `internal/front/edge_test.go`

**Interfaces:**
- Produces: handlers for `/status`, `/progress` (served only to loopback peers), `probe` (verifies the MAIN-minted token `probe.php` checks before running ffprobe).

- [ ] **Step 1: Write the failing tests** — `TestStatusProgressLoopbackOnly` (a non-loopback peer → 403; loopback → 200), `TestProbeRequiresToken` (no/invalid token → 401/403, never runs ffprobe), `TestProbeWithValidToken` (valid MAIN token → the probe runs). Use the real token-verify path the gateway already has; no mocks of the crypto.
- [ ] **Step 2: Run — verify fail.**
- [ ] **Step 3: Implement.** `/status`, `/progress`: serve only when the TCP peer is loopback (mirrors nginx `allow 127.0.0.1; deny all`); the node's existing health/progress reads back them. `probe`: verify the token, then run the existing probe path. No new capability beyond what the token authorises; keeps the accepted LB-SSRF posture.
- [ ] **Step 4: Run — verify pass.**
- [ ] **Step 5: Commit** (`feat(front): local-only status/progress and token-gated probe`).

---

## After this plan

Only `/xfile` and `/stream/rtmp` still reverse-proxy to nginx. The follow-on sub-projects (each its own brainstorm → spec → plan, per the design doc):
1. **`/xfile`** owner-side handler in the agent + front proxy (cluster crypto; adversarial interop vectors).
2. **`/stream/rtmp`** auth-notify handler in the agent (MAIN-deferred; a faithful `rtmp.php` LB-arm port; interop vectors).
3. **LbInstallFlow cutover** — stop provisioning the HTTP nginx for a serve-only node; the front owns the real ports; certbot runs webroot-authenticated; and the port source moves off nginx's `ports/*.conf` to a node-maintained file if nginx no longer writes it.
