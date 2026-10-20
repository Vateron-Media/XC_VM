# RTMP auth-notify faithfulness gates — Go port (design)

**Status:** draft for review. Cutover sub-project **1 of ~4** (the LbInstallFlow
cutover, follow-on #3, split into a sequence). This one closes the three RTMP
gates left by the `/stream/rtmp` port (Fanout #97) so the Go RTMP path is fully
faithful before the flip (sub-project 4) turns `-front-agent-sock` on for real.
**Fanout-only** — the exploration confirmed all three are agent-side; MAIN
already ships every input.

## Intent and why

The merged `/stream/rtmp` Go handler (Fanout #97) deliberately left three gaps,
ruled "cutover gates": (1) it ignores the per-IP allowed-RTMP list, (2) it starts
a held-but-idle stream on any `play` regardless of `on_demand`, and (3) its
brute-force guard counts/logs but never bans. Each is an allow/deny divergence
from rtmp.php's LB arm. The flip (sub-project 4) makes the Go handler
authoritative for RTMP on a live LB; these must be faithful first. All the data
MAIN needs to ship is already on the LB's disk — this is agent read + enforce.

## Goals

- The Go `/stream/rtmp` handler matches rtmp.php's LB-arm allow/deny on all three
  gates, pinned by tests.
- **Gate 1 — allowed-RTMP:** a publish from an IP in the list with `push` (and its
  per-entry password, if set) is admitted; a relay-play from an IP with `pull` is
  admitted — even without the stream password, as rtmp.php does.
- **Gate 2 — on_demand:** a held stream that is not running is brought up on
  demand only when `on_demand == 1`; a non-on-demand held-but-idle stream is
  refused (404), as rtmp.php does (its producer/monitor owns always-on streams).
- **Gate 3 — brute-force ban:** an IP already carrying a `block_<ip>` flood
  marker is refused at the top of the handler; repeated `AUTH_FAILED` from an IP
  writes that marker and spools MAIN a `security.block_ip` event once over the
  configured limit.
- Node keys / MAIN-ask channel stay in the agent; wire stays `200`/`404`.

## Non-goals

- Any MAIN/XC_VM change — all three inputs (the `rtmp` rows inside `blocklist`,
  `on_demand` in the signed stream record, `flood_*` in the replica settings) are
  already shipped; this is the agent reading and enforcing them.
- Re-deriving the allowlist a new way — read the `rtmp` section MAIN already puts
  in `blocklist.json` (the agent passes it through verbatim today).
- The flip itself, the crons, the PHP-CLI removal (later sub-projects).
- MAIN's own arm (`RtmpViewerAuth`) — unchanged.

## Anti-goals (failure even if it "works")

- Admitting an RTMP connect from a `block_<ip>`-marked IP (the ban must be
  enforced on the RTMP path, as the gateway already enforces it on HTTP).
- Denying a valid allowed-RTMP push/relay (the Gate-1 regression this fixes).
- Starting a non-on-demand stream on a viewer's `play` (Gate 2), or conversely
  denying an on-demand one that should start.
- A per-request DNS storm resolving the allowlist on every RTMP connect.
- Moving any of this off the faithful rtmp.php semantics.

## Current behaviour (the contract to reproduce)

Verified in both repos.

- **Gate 1** — `BlocklistService::getAllowedRTMP()` returns a map keyed by the
  **DNS-resolved** IP (`gethostbyname(row.ip)`) → `{password:string, push:bool,
  pull:bool}`. rtmp.php applies it: publish (`rtmp.php:183`) admits when
  `live_streaming_pass` matches OR the source IP is in the list with `push` (and
  the entry's password if set); play relay-bypass (`rtmp.php:69`) admits when the
  password matches OR the IP is in the list with `pull` (+ entry password). The
  rows ride inside the `blocklist` section (`BlocklistDelta` `'rtmp' =>
  ['rtmp_ips','id',['id','ip','password','push','pull']]`, snapshot-only); the Go
  agent's `materialise` (`replica.go:698`) passes the whole `rtmp` section through
  verbatim into `blocklist.json` (only `ip` is specially handled). So the raw rows
  are on disk; **no Go reader exists**.
- **Gate 2** — rtmp.php: `if (!isStreamAlive) { if (on_demand != 1) 404; else
  startMonitor }`. `on_demand` is in the signed per-node stream record
  (`ReplicaSections::STREAM_SERVER_FIELDS` includes `on_demand`), landing in the
  agent's `replica/streams/<id>.json` at `data.server.on_demand`. The agent reads
  only `.data.runtime` (`parseRuntimeDirective`), so it misses it; `serveRtmpNotify`
  calls `fanoutEnsure` on any held-not-running stream. Held non-on-demand streams
  do exist on a serve-only LB (`heldStreams` has no on_demand filter).
- **Gate 3** — `BruteforceGuard::checkFlood` reads `flood_limit`/`flood_seconds`/
  `flood_ips_exclude` (in the LB settings replica, `lb_settings_keys.php:97-99`),
  keeps a per-IP `{requests,last_request}` file in `FLOOD_TMP_PATH`, and on
  over-limit `blockIP` → on a CONFIG-on LB spools MAIN a P0 `security.block_ip`
  event, and always `touch`es `FLOOD_TMP_PATH/block_<ip>`. Enforcement is the
  bootstrap `FloodProtectionStage` (403 when `block_<ip>` exists), which the Go
  gateway already mirrors for HTTP (`judge.go` `BootstrapRefuses`, `Paths.Flood +
  "block_"+ip`). The RTMP path neither consults nor writes the marker.

## Architecture (all in `internal/clusteragent`; the gateway's block-marker check is mirrored, not imported)

### Gate 1 — allowed-RTMP reader
A small cached allowlist: parse the `rtmp` **JSON array** out of the
agent-materialised `blocklist.json` — each entry `{id:int, ip:string,
password:string, push:int(0|1), pull:int(0|1)}` (`push`/`pull` are 0/1 ints, not
bools) — cached and refreshed on `blocklist.json` mod-time change (the section
re-materialises with a new mtime when its ETag changes). A lookup
`allowedRTMP(ip) -> (entry, ok)`. Wire it into `serveRtmpNotify`:
- publish arm: admit when the stream password matches **or** `allowedRTMP(addr)`
  has `push == 1` and its entry password passes.
- play relay-bypass: admit (relay, no line) when the stream password matches
  **or** `allowedRTMP(addr)` has `pull == 1` and its entry password passes.
Mirrors `rtmp.php:69,183`. **Entry-password rule** (`AuthService::secretMatches`,
*not* `passMatches`): an empty entry password admits on IP+flag alone; a non-empty
one is a constant-time equal against the given. The stream-password compare stays
`passMatches(secret("live_streaming_pass"), given)`. `rtmp_ips.ip` is
IP-validated at write time, so it is always a literal IP and DNS is an identity
no-op — build the cache, but resolution is effectively dead code (if a host ever
appears, resolve to the single A-record it would key on, not all `LookupHost`
results).

### Gate 2 — on_demand reader + gate
Add a reader for `replica/streams/<id>.json` → `.data.server.on_demand` (an int;
`data.server` may be **null** for archive/thumbnail-only holds → treat as
not-on-demand), a mirror of `parseRuntimeDirective`. In the play arm, when the
stream is held but **not** running, call `fanoutEnsure` only if `on_demand == 1`,
else `404`. This gate applies to **both** the relay-bypass arm and the line arm
(so the relay arm, which today 200s unconditionally after ensure, is
restructured to gate on on_demand too); only the *not-held* case still differs
(line → 404, relay → 200, `rtmp.php:117`). Loose `!= 1` match, as rtmp.php.

### Gate 3 — flood ban (enforce + write)
- **Enforce:** at the very top of `serveRtmpNotify`, refuse when the peer IP
  carries a `block_<ip>` marker. The marker convention is shared with the gateway
  (`judge.go` / `FloodProtectionStage`), but `clusteragent` does not import
  `gateway`, so re-implement the trivial check here: `exists(floodDir +
  "block_" + ip)`. The agent is given the flood dir (new config, default
  `/home/xc_vm/tmp/flood/`; confirm whether the single `xc_node` binary can
  source it from the gateway policy it already loads vs. a new flag). Refuse with
  `404` (handler parity; PHP's bootstrap uses 403 — settle + pin).
- **Count the full deny set, not just AUTH_FAILED:** rtmp.php bans on every
  `$rDeny` exit (`shutdown()` → `checkFlood`): a **failed publish gate**, a line
  with **no tcurl/app**, a **cred-array**, and a line auth **`AUTH_FAILED`**
  (`REFUSED`/`NO_ANSWER` do **not** count; the **parse-failure** deny does not
  either — it registers no shutdown). Port this with a deferred
  "denied-and-counts" flag the handler sets on those paths, ticking the flood on
  exit — not the single `AUTH_FAILED` branch #97 has today.
- **Write (file-backed counter, coexisting with PHP):** read
  `flood_limit`/`flood_seconds`/`flood_ips_exclude` from `replicaSettings()`
  (reuse `settingInt`); the per-IP counter is `floodDir + <ip>` holding
  `{requests,last_request}` JSON written via **atomic write-temp-rename**, the
  **same shape and discipline PHP uses** — during the transition php-fpm is still
  present and writes that same shared counter, so the formats must match (both
  best-effort; PHP's own cross-worker writes race the same way). On over-limit:
  `touch` `block_<ip>` **unconditionally**, and spool a P0 event
  `{"type":"security.block_ip","d":{"ip":<ip>,"reason":"FLOOD ATTACK"}}` via
  `spoolP0(a.SpoolDir, …)` — the reason string must be exactly `"FLOOD ATTACK"`
  (MAIN's `REASON_PATTERN`), and the spool is **skipped when the IP is already in
  the materialised blocklist ip set** (PHP's `getBlockedIPs()` guard); the marker
  touch happens regardless. `flood_limit == 0`/unset → off (no-op).
- **Exclusions:** skip `flood_ips_exclude` **and** `getAllowedIPs()` — `127.0.0.1`,
  the node's own `server_ip`, and every server's `whitelist_ips` (from the replica
  `servers` section, `SERVER_FIELDS`) — so the guard cannot ban the node itself
  or a whitelisted ops IP. Invalid IPs skipped.

## Improvements folded in (user: "follow suggestions")
- The existing per-reason decision logging + metrics (`rtmpMeter`) gain the new
  outcomes (allowlist admit, on_demand refuse, flood block) — no wire change.

## Constraints
- Go, stdlib + in-repo packages only; zero new external deps. `GOWORK=off`.
- Agent-side only; no MAIN/XC_VM change. Faithful to rtmp.php's LB arm.
- Conventional Commits, English, **no Claude attribution**.
- Base: XC_VM_Fanout `feat/lb-transcode-serve` (will include #97 once merged);
  XC_VM `2.8.0` (docs-only — this design). Execution after approval + plan.

## What is left to the builder
- The allowlist cache shape (parse + resolve + mod-time refresh) and the exact
  wiring into the publish/relay arms.
- The `.data.server.on_demand` reader and the held-not-running gate.
- The flood-marker dir config for the agent (default path) and the file-backed
  counter + `block_<ip>` write + the P0 `security.block_ip` spool (confirm the
  agent's event-spool API and the event's exact shape against `EventSpool`).
- Whether to refuse a blocked IP with 404 (handler parity) vs 403 (PHP bootstrap)
  — settle and pin.
- Interop vectors against rtmp.php's LB arm for each gate.

## Review focus (what the tests must pin)
- **Gate 1:** a push-list IP with `push` (±entry password) → publish 200 without
  the stream password; a pull-list IP with `pull` → relay-play 200; an IP not in
  the list and no password → 404; a hostname entry resolves and matches.
- **Gate 2:** held + running → serve; held + not running + `on_demand==1` →
  `fanoutEnsure` + proceed; held + not running + `on_demand!=1` → 404 **for both
  the line and the relay arm**; `data.server` null → treated not-on-demand;
  not-held → unchanged (line 404 / relay 200).
- **Gate 3 (enforce):** an IP with a `block_<ip>` marker → refused at the top (no
  auth attempted).
- **Gate 3 (count set):** N failed-publish-gate, N no-tcurl/app, N cred-array, and
  N `AUTH_FAILED` within the window each reach the limit → marker written + P0
  `security.block_ip` (reason exactly `"FLOOD ATTACK"`) spooled; a `REFUSED`/
  `NO_ANSWER` line and a **parse-failure** deny do **not** count.
- **Gate 3 (safety):** the node's own `server_ip`, `127.0.0.1`, a `whitelist_ips`
  IP, a `flood_ips_exclude` IP, or `flood_limit==0` → never banned; an
  already-blocked IP → marker re-touched but the P0 spool skipped.
- **No regression:** the existing #97 matrix (loopback publish, password gate,
  line auth, relay, play_done) still holds.
