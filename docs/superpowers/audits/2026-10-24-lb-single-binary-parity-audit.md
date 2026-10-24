# Serve-only single-binary LB — parity & working-status audit (2026-10-24)

Audits the serve-only load-balancer (`xc_node` = `xc_fanout` front + `xc_agent`) against the
old PHP+nginx LB, per the north star in the one-binary design spec
(`docs/superpowers/specs/2026-10-22-lb-one-binary-design.md`, a local design note) and the parity
rule "the Go LB must contain everything the PHP+nginx LB did — never leave a path behind as rare".
Method: 6 parallel per-surface reviews reading both repos across all slice branches; every claim
grounded in code. Target release: 2.7.2 (the LB work merges to `main`; the `2.8.0` branch is shelved).

## Verdict

- **A single binary? No.** Not nginx-free (R1 Go RTMP server unbuilt → `nginx_rtmp` + a dormant
  http-nginx remain) and not PHP-absent (the install still ships the PHP tree + nginx and runs
  `console.php` on the node during provisioning). Best achieved today: *PHP-free steady-state serving*.
- **Everything working / full parity? No.** The common serving path is live-verified PHP-free, but a
  tail of data-plane settings hard-502s where the PHP LB served content, management slices P3/P4/P5
  are unintegrated, and a cross-repo merge skew currently breaks node.root + bans on a build from
  merged code.

## Current merge state (verified 2026-10-24)

- **XC_VM `main` @ `66e100d9`** (merged, ships as 2.7.2): #463 provisioning-gate, #464
  migration-cleanup, #465 certbot-webroot, #467 P1-policy, **#470** serve-only installs/runs without
  PHP, **#471** serve-only new-node mode, **#473** node.root helper-only sudoers (helper file + the
  single NOPASSWD grant; raw grants removed).
- **Fanout `master` @ `~f948c03` (v0.15.4)** = only up to PR #79 — the serve-only program is **not**
  on master. P1 + P2 + flow-logs reached master via #112/#113→#115 per one reviewer; the rest is on
  branches.
- **Fanout `feat/lb-transcode-serve` @ `4050106` (#112)** = serve-only integration tip: data plane,
  `/admin/*`, recording, flow-log crons, P1 replica, RTMP auth-notify. **Absent here:** `selfheal.go`
  (P2), `certbot.go` (P3), `noderoot.go`/`bansync.go` (P5), the cold-start fix.
- **Fanout `fix/serveonly-live-ondemand-and-noderoot-sudo` @ `5667904`** (unmerged, ~28 ahead):
  cold-start fix + Go node.root (raw-sudo form) + bans.
- **Fanout `feat/serveonly-noderoot-helper-agent` @ `76a13f4`** → **PR #123** (open): node.root/ban
  agent rewired to call the merged #473 helper.
- **Fanout `feat/lb-p2-binary-self-heal`** (P2), a P3 certbot branch — separate.
- **R1 (Go RTMP server): does not exist on any branch.**

There is **no single branch that is the complete serve-only LB.** The .220 live validation was of a
hand-assembled mix, not a merged tip.

## Parity by surface

- **Streaming data plane** — *partial, split, happy-path live-verified.* Five output types
  (HLS/m3u8, MPEG-TS, VOD, timeshift, key/thumb/subtitle) served in-process for a running stream
  with default settings; token auth, agent connection limits, flood-bans (`BootstrapRefuses`,
  judge.go:200-211), restrict-same-IP (ipMatch /24+/64, judge.go:400-425) at parity. Heavy
  credentialed auth was never on the LB (mint-time on MAIN; `auth.php` is in `LB_FILES_TO_REMOVE`).
- **Admin `/admin/*` + `/status` + `/xfile`** — *partial, integrated, tested-only.* All relay
  handlers present with CORS parity; minor divergences below.
- **Recording** — *partial, integrated, tested-only.* Writer/DVR/triggers present; cleanup &
  robustness gaps below.
- **Management plane / crons** — *partial, split, tested-only.* P1+P2+flow-logs on master; P3/P4/P5
  on branches; node.root action coverage ~9 of 25.
- **RTMP ingest (R1)** — *gap.* Only the auth-notify callback is ported; `nginx_rtmp` is still the
  server. Not one-binary.
- **Install / provisioning (P6)** — *partial, merged, tested-only.* Runs the Go path, but still ships
  + runs PHP and keeps nginx.

## Gaps (prioritized; file:line)

### P0 — correctness / breaks a real deployment
- **node.root + bans broken on a merged build (cross-repo skew).** XC_VM #473 (merged) grants
  `xc_vm` sudo to *only* `/usr/local/sbin/xcvm-noderoot`, but no *merged* Fanout branch calls the
  helper (the integration tip has no `noderoot.go`; the fix branch issues raw `sudo` the new sudoers
  rejects). Fix = land **Fanout PR #123** + the node.root stack, coordinated with #473.
- **`send_unique_header` on → everything 502s.** live.go:117-118, live.go:401-404, judge.go:266/315
  all defer to PHP; no PHP backend on a serve-only node → `front.serveStream`→`proxyOrError`→502
  (server.go:135-141). The per-response anti-restream cookie is unported.
- **Cold-start on-demand live 502 on the integration tip.** JudgeLive returns `php(stream-down)`/
  `php(no-playlist)` (live.go:183-188) *before* the serve-only warmup branch; the `warmable`
  relaxation exists only on `fix/serveonly-live-ondemand-and-noderoot-sudo`, not on the tip.
- **Off-air placeholder video / adaptive multi-bitrate → 502.** live.go:129-130 (`php(off-air)`);
  `OffAirHandler::showVideoServer` unported.
- **`disallow_2nd_ip_con` → 502** (live.go:189-190); **`on_demand_instant_off` → 502**
  (live.go:180-181); **proxied channel → 502** (live.go:155-157/172-174, 424-427, 639-641).
- **Operational footguns:** `conn_store` must be `agent` (else GatewayPolicy.php:181 sets `php` →
  live.go:111-113 → 502) and `gateway_mode` must be the full ladder (GatewayPolicy.php:138 not forced
  for serve_only; server.go:257-288 only serves the top rung) or un-served kinds 502.

### P1 — "single binary" goal not met
- **R1 unbuilt:** no Go RTMP server on any branch; `nginx_rtmp` still provisioned
  (LbInstallFlow.php:692/:938), started + reloaded (src/service:107/:207), and shipped
  (Makefile:430/:537). The Go side is only the on_play/on_publish/on_play_done callback
  (rtmpnotify.go).
- **PHP still present:** install runs the full PHP+nginx bundle and `console.php` during provisioning
  (ServerInstallCommand runPostExtractSteps ~:281); no separate PHP-free archive — `make lb` ships
  `console.php` (Makefile:36), `bin/php`, `bin/nginx`. A dormant http-nginx is kept because the Go
  front reads its listen ports from `bin/nginx/conf/ports` (ServerInstallCommand:401-405).

### P2 — management / recording parity
- **P3 (certbot), P4 (node maintenance), P5 (node.root) not integrated** into the serve-only line.
- **~16 of 25 node.root actions dropped** (acked `{result:true,detail:deferred}` without running —
  noderoot.go:86-88/:366-368): `certbot_generate`, `rotate_sign_key`, `rotate_redis`,
  `rotate_db_password`, OpensslExtra signal, etc.
- **Node→MAIN telemetry reduced:** `certbot_ssl` cert expiry, network stats not reported
  (vs `NodeStateSink::state`).
- **ipset-less ban fallback dropped** (bansync.go:25-26) — PHP fell back to per-address iptables.
- **Recording:** archive-dir cleanup missing (vs CleanupCronJob.php:133-137); time-prune of stopped
  streams missing (:139-149); prune algorithm differs (count-by-mtime vs filename-minute-age); DVR
  cover-art not saved (RecordCommand.php:172/215-240); no loopback-pass fallback (:273-275); no 5×
  broken-pipe reconnect (:98-149); recorded VOD not analyzed on a PHP-free node.

### P3 — minor / cosmetic
- VOD `Accept-Ranges`/range deltas vs vod.php (admin.go:400); unconditional `X-Accel-Buffering: no`
  (admin_live.go:184, inert without nginx); `/status` skips `StreamingRequestBootstrap::init`
  side-effects (wire-identical for the loopback probe); certbot stale-lock pre-sweep omitted;
  archive worker PID not registered to MAIN; no archive heartbeat log.

## To call it done

1. **Land Fanout PR #123 + the node.root stack** (unbreaks node.root/bans vs merged #473). *Urgent.*
2. **Fold P3/P4/P5 + the cold-start fix into one integration line** and merge the serve-only program
   to master as a coherent build.
3. **Port the data-plane defer tail** so no supported setting (send_unique_header, off-air, adaptive,
   second-IP, instant-off, proxy, cold-start) 502s — the parity rule. *Urgent (correctness).*
4. **R1 + stop shipping/running PHP + drop nginx** for true single-binary (Milestone 2).

(1) and (3) are the correctness-urgent items; (2) removes the "scattered across 8 branches" risk;
(4) is the remaining scope for the stated north star.
