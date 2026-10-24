# Serve-only LB as literally one binary — design

**Status:** design / decision support. Reverses the "PHP CLI stays" decision recorded in
`lb-serve-only-provisioning-gate`. North star set by the user: a serve-only load-balancer
node runs **one XC_VM-authored binary** (`xc_node`), with no **nginx** and no **PHP** anywhere
on it. Third-party OS binaries stay.

## Scope boundary (set by the user)

Remove exactly two things from a serve-only node: **nginx** (every nginx, including
**nginx_rtmp** — it is nginx) and **PHP** (the CLI and the whole PHP tree). **Keep** every other
OS binary the node needs as a separate process: **redis, ffmpeg, ffprobe, certbot**, and anything
else that is not nginx or PHP. So "one binary" means *one XC_VM-authored binary* — `xc_node` is
the only program we build and ship — while redis runs as today and ffmpeg/ffprobe/certbot are run
as child processes by `xc_node`, in the same category as libc or the kernel. This cleanly settles
the earlier open questions: ffmpeg/ffprobe stay (they are the transcoder/prober — reimplementing
is infeasible and libav needs cgo, which is banned), certbot stays as an exec'd binary (no Go ACME
client needed — the agent only orchestrates it), and redis stays (no in-process-state rewrite).

## Intent & why

Today a serve-only LB is a *single binary for the HTTP/data surface* (the Go `xc_node` =
`xc_fanout` front + `xc_agent`, with no http-nginx and no php-fpm), but it still carries a full
**PHP CLI management plane** and **nginx_rtmp** for RTMP ingest. The user wants both gone: the node
should depend on no nginx and no PHP at all. The win is a node whose entire XC_VM surface is one
static Go executable to ship, update, audit and reason about, with only stock third-party tools
beside it.

## Goals

- A serve-only node's steady state, self-maintenance, and privileged operations run entirely
  inside the Go `xc_node` binary — no `console.php`, no PHP interpreter on the node.
- RTMP ingest is served by the Go binary; nginx_rtmp (and any nginx) removed.
- The install provisions a serve-only node without ever invoking `console.php` on it, and ships
  no PHP tree and no nginx to it. redis, ffmpeg, ffprobe and certbot are still installed/run.
- Every port is faithful to the PHP/nginx behaviour it replaces (the project's standing rule).

## Non-goals / anti-goals

- **Not** MAIN. MAIN stays PHP + MySQL; this is only the serve-only LB node.
- **Not** mode-1 (credentialed) LBs, and **not** proxy nodes — untouched.
- **Anti-goal:** reimplementing ffmpeg, or a half-ported node that needs PHP for one rare path
  (the `lb-single-binary-parity` rule: never leave a path behind as "rare").
- **Anti-goal:** a privileged Go executor that widens the root attack surface beyond what
  `cluster:exec` already allows.

## Current PHP-CLI surface on a serve-only node (disposition)

Enumerated from today's gap analysis + crontab role `all` + the boot script:

- **Replica apply** — `service` boot runs `console.php cluster:apply --from-disk`; `cron:cache`
  re-applies each minute; `ReplicaBoot::now()` is a PHP probe. → **PORT to Go (P1) — SMALLER than
  it looks.** The agent's `internal/clusteragent/replica.go` ALREADY fetches, opens (XCVM-SEAL-v1,
  stdlib), verifies (Ed25519 panel sig) and materializes every section it reads
  (`servers/settings/secrets/blocklist/streams .json`). `console.php cluster:apply` only fills PHP
  `FileCache` for the PHP streaming stack — absent on a Go node. The one output the agent does NOT
  yet produce is `tmp/gateway/policy.json` (written by `GatewayPolicy::write()` from `cron:cache`).
  So P1 = port `GatewayPolicy::build` to Go + make the agent the sole `policy.json` writer on
  serve-only + stop calling `cluster:apply` there. See `docs/superpowers/plans/2026-10-22-lb-p1-replica-apply-go.md`.
- **cron:root_signals** (hourly) — binary self-heal (`fanout_binary`: resolve release + canary/
  pin, download, verify SHA, stage, trial, roll back), port reconcile, fanout keepalive. The
  keepalive is already Go (run.sh supervisor + `-seed-fanout`); the self-heal and port reconcile
  are PHP. → **PORT the self-heal to Go (P2)**, drop the rest (keepalive already Go; php-fpm/
  limiter watchdogs already gated off for serve-only).
- **cron:certbot** (daily) — cert issue/renew. → **PORT orchestration to Go (P3)**: the agent
  resolves the node's domains from the replica, execs the kept `certbot` binary with `--webroot`,
  and syncs `server.crt`/`server.key` (which the Go front already hot-reloads). No ACME client —
  certbot stays.
- **cron:servers** (per-min) — network/`bin/network` + daily ffmpeg refresh. Already early-returns
  on `refusesConnects()` for most of its body. → ffmpeg refresh **PORT to Go (P4)**; network setup
  is install-time, **drop** from the node's steady state.
- **cron:cache** (per-min) — partly the replica re-apply (folds into P1); the rest early-returns on
  `refusesConnects()`. → **folds into P1 / drop.**
- **cron:cleanup** (hourly) — prune old content/recordings; already `refusesConnects()`-gated. The
  agent already prunes archives (`pruneArchive`). → **PORT the residue to Go (P4).**
- **cron:maxmind** (weekly) — GeoLite2 refresh. → **PORT to Go (P4) IF** the Go gateway uses GeoIP
  for geo-restriction; builder to confirm. Else drop and let MAIN push the DB via the replica/
  artefact channel.
- **cron:streams** (per-min) — reconciles `StreamRuntime` from progress files; the agent now serves
  streams natively. → **drop on serve-only** (agent is authoritative); builder to confirm nothing
  downstream needs it.
- **streams_logs / lines_logs / activity / errors / tmp** — **already Go** (agent `ownedCrons()`
  under `FlowLogs`); PHP versions skipped via `touchNodeCrons()`. Done.
- **cluster:exec / node.root** — the generic signed privileged-command channel; `artefact.go` hands
  `node.root` to `console.php cluster:exec`, gated by a root pin (seq monotonic). → **PORT to Go
  (P5).** The hard, security-sensitive one.

## The giant (beyond PHP): nginx_rtmp → Go RTMP ingest (R1)

Reimplement the RTMP server protocol in Go (handshake, chunk stream, AMF0/3, FLV demux), the
`live` application, the `/stat` surface the node watchdog reads, and run the auth-notify logic
*inline* (the decision is already Go — `serveRtmpNotify`) instead of over an HTTP callback. Hand
ingested frames to the existing remux/HLS pipeline. Large, from-scratch, stdlib-only; the single
biggest effort, and the only thing standing between "PHP-free" and "nginx-free".

redis is **kept** (user scope), so there is no in-process-state rewrite — the agent/gateway keep
using redis exactly as today.

## Install without console.php (P6)

Provisioning a serve-only node currently SSHes and runs `console.php startup`, `cron:servers`,
`ffmpeg`, `fanout_binary agent`, and the `service` boot runs `cluster:apply`. With no PHP on the
node, the serve-only branch of `ServerInstallCommand`/`LbInstallFlow` must: ship the Go binary +
configs + the encrypted replica over SSH, write a serve-only `service`/unit that starts only the
Go binary (which then applies the replica itself, P1), and ship **no PHP tree and no nginx** in the
archive for a serve-only node (redis, ffmpeg, ffprobe and certbot are still installed). MAIN stays
PHP and drives the SSH install as today.

## Recommended path (slices, in dependency/risk order)

Bounded first, giants last. Each slice is independently shippable and keeps the node working.

1. **P1 — replica apply in Go.** Unblocks removing PHP from the boot path. Medium.
2. **P2 — binary self-heal in Go.** Chicken-and-egg (the agent updating its own binary); the
   stage/trial/rollback primitives already exist (`.xc_agent.new/.prev/.trial` routing). Medium-high.
3. **P4 — node maintenance in Go** (cleanup residue, ffmpeg refresh, maxmind-if-needed; delete
   streams/servers/cache residue). Low-medium.
4. **P3 — certbot/ACME in Go.** Medium (hinges on the ACME-client decision above).
5. **P6 — install without console.php** + a PHP-free serve-only archive. Medium; depends on P1-P4
   so the node can live without PHP.
6. **P5 — node.root / cluster:exec in Go.** HIGH RISK (privileged execution, root boundary,
   security). Needs its own threat model and an enumeration of the exact `node.root` command set a
   serve-only node actually receives (many may be PHP-specific and simply drop).
7. **R1 — Go RTMP ingest**, removing nginx_rtmp (the last nginx). HIGHEST EFFORT (from-scratch RTMP
   server).

After P1-P6 the node has no PHP; after R1 it also has no nginx — one XC_VM binary beside redis,
ffmpeg, ffprobe and certbot.

## Scale & honesty

This is a large, multi-month, cross-repo program with two giants — a Go RTMP server (R1) and a Go
privileged executor (P5) — carrying the real effort/security risk, not a quick follow-on. The
bounded slices (P1-P4) deliver most of the PHP-elimination value early. Recommend landing **P1-P6
as milestone 1** (a PHP-free node that still runs nginx_rtmp) and deciding **R1 as milestone 2**,
since R1 alone likely outweighs everything else and nginx_rtmp is a well-understood, low-churn
dependency — so "kill PHP" ships and proves out before the big RTMP rewrite starts.

## What's left to the builder

- Enumerate the `node.root`/`cluster:exec` command set a serve-only node receives (sizes P5).
- Confirm whether the Go gateway needs GeoIP (sizes the maxmind decision in P4).
- Per-slice: write the plan (`superpowers:writing-plans`) and port faithfully with a parity vector
  per behaviour, XC_VM off `main`, XC_VM_Fanout off `master`.
