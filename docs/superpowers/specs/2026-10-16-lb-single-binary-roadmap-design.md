# LB as a single Go binary — roadmap (no nginx, no php-fpm)

**Goal.** A load-balancer node runs exactly one process — the Go `xc_node`
binary — with **no nginx and no php-fpm** installed. It binds the public ports,
terminates TLS, answers every request a viewer or the cluster sends an LB, runs
the stream lifecycle, the agent, the remux, and the LB's crons. MAIN stays the
PHP panel; this is about the LB only.

## Decision (2026-10-16): the LB is SERVE-ONLY

The LB **does not authenticate viewers.** MAIN (or whichever panel node owns the
line) runs `auth.php`, applies the full block chain (expired/banned/disabled,
geo/IP/ASN/ISP/UA, device limits, bouquets, line limits), and **mints a token
for the chosen LB using that LB's per-node viewer key** (the per-node-viewer-keys
design), then redirects the viewer to `lb-domain/auth|vauth|hls|…/<token>`. The
LB only **verifies the token with its own key and serves** — which the gateway
already does.

**This eliminates the `auth.php` keystone** (the whole viewer-authorization
system) from the LB. It was the single largest, most security-sensitive port;
serve-only removes it instead of reimplementing it at the edge.

**Security rationale (not weaker — stronger):**
- A stolen **stream ID** is useless: the LB serves only a token sealed with its
  secret key; it cannot be forged, so a bare ID / hand-built path is rejected.
- Stolen **credentials** still have to clear MAIN's full auth to obtain a token;
  the LB has no credential path to bypass. Same exposure credential theft always
  has — not widened.
- A leaked **minted token** replays only until its short expiry, IP-bound under
  `restrict_same_ip` — identical to today's serve behaviour.
- Auth is enforced in exactly one place (MAIN); there is no edge auth surface to
  bypass and no risk of a reimplemented block-chain drifting out of sync.

Operational change: a client pointed **directly at an LB domain for auth** no
longer authenticates there; it must reach the stream through MAIN (which already
mints per-LB). The stream still plays from the LB.

## Where we are

- **Phase 1 — one binary.** `xc_node` merges fanout + agent + remux. Merged in
  both repos; dormant until a Fanout release ships it.
- **Phase 2 — Go serves the viewer-facing streaming path.** Done, in review
  (XC_VM_Fanout #81, XC_VM #431): segment, key, live (+ HLS first request),
  thumb, subtitle, VOD, timeshift (archive + m3u8). Shadow-first via the
  `gateway_mode` ladder. **With serve-only, this is the LB's entire
  viewer-facing surface.**

## What remains for a serve-only single-binary LB

### A. The non-viewer, cluster/edge endpoints (Go)
Not viewer auth, so small, but needed on the LB:
- **`/xfile`** — another server's agent reads a file this LB owns, via a
  file-ticket (`FileTicketServer`: ticket + node-key proof + per-chunk signed
  digest). Cluster crypto; port with the agent's existing cluster crypto.
- **`/status`, `/progress`** — the node's health/progress reads (fast).
- **`probe`** — stream probing (ffprobe-backed), MAIN-triggered.
- **`rtmp`** — the nginx-rtmp auth-notify, which belongs with replacing
  **nginx-rtmp** (a separate sub-system: the RTMP *server* the LB runs for
  ingest/restream). This is where RTMP auth lands.
- The cluster `ctl`/`ingest` lanes are already the Go agent.

### B. nginx's jobs, absorbed by the Go binary (to delete nginx)
Smaller now, because a serve-only LB needs only the minted-token routes:
- TLS termination + ACME (`/.well-known/acme-challenge/`), multi-port listen.
- HTTP/1.1 + HTTP/2 (today), HTTP/3 (Alt-Svc already advertises it) later.
- The **serve** rewrites only (`/hls`, `/auth`, `/vauth`, `/subauth`, `/tsauth`,
  `/thauth`, `/key` → `/stream/*`). The `/user/pass/stream` → `/stream/auth`
  rewrites are **not needed on a serve-only LB.**
- `limit_req` rate zones; trusted-proxy real-IP; static (`/images`,
  `/portal/assets`); gzip; `X-Accel-Redirect` (already in-process for fanout).

### C. Lifecycle & crons in Go
Stream start/stop/monitor, the reaper, the LB's maintenance loops — Phases 4–5.

## Strategy

1. **Request path in Go — essentially done** (serve path). Add the small
   non-auth endpoints (xfile/status/probe) as needed; rtmp follows nginx-rtmp.
2. **The Go binary becomes the listener** — binds ports, terminates TLS + ACME,
   the (small) serve rewrites, rate-limit, real-IP, static, in-process routing.
   nginx removed.
3. **Lifecycle + crons in Go** (Phases 4–5).
4. **`LbInstallFlow` stops provisioning nginx and php-fpm.** One binary.

## Risks / open questions
- **TLS/ACME lifecycle** must match however certs are issued/renewed today.
- **HTTP/3** needs a QUIC implementation (quic-go) — deferred.
- **`/xfile` cluster crypto** (`FileTicketServer`) must be reproduced faithfully
  in Go (it's security-sensitive, but cluster-internal and low-volume).
- **nginx-rtmp replacement** is its own sub-system and effort.

## Next
With auth off the table, the gating piece is now **replacing nginx** (Phase 3,
section B): start with the TLS front + the small serve-only routing/rewrites.
The `/xfile`, `status`, `probe` ports and nginx-rtmp are parallel tracks.
