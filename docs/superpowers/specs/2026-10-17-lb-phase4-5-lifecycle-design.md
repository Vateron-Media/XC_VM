# LB Phases 4–5 — stream lifecycle in Go (design)

Expands **section C** of the single-binary roadmap
(`2026-10-16-lb-single-binary-roadmap-design.md`). That doc removed viewer
auth from the LB (serve-only, option B) and built the Go HTTP front (Phase 3).
This is the last gate before `LbInstallFlow` can stop installing **php-fpm**:
once the stream lifecycle runs in the Go binary, a load-balancer node has no PHP
left to run.

**Goal.** On a serve-only LB, the Go `xc_node` binary starts, monitors, reaps,
and stops the streams it serves — with no php-fpm and no PHP cron loop.

## Key finding: the lifecycle *execution* is already in Go

A load-balancer node does not run streams in PHP today. The Go fanout does. PHP
on an LB is an **orchestrator**: it decides which streams to start and when, and
calls the fanout's control API over a unix socket to make it happen. So Phases
4–5 are about moving that orchestration in-process, not porting an encoder
supervisor that does not exist yet.

What the Go binary already owns (verified in `XC_VM_Fanout`, branch
`feat/front-static-images`):

- **The supervisor** (`internal/supervisor/`, `supervisor.go` ~58 KB plus
  `adopt.go`, `health.go`, `launch_unix.go`, `sources.go`): launches the encoder
  process, detects audio loss / flap / give-up and restarts, falls back across
  sources, retries probes, adopts running encoders across a daemon restart, and
  honours start windows. This *is* the stream lifecycle execution.
- **The reaper** (`Manager.StartReaper`): idle-stop of streams with no viewers.
- **The control API** (`Manager.ControlHandler()`, `internal/server/server.go`):
  `/streams`, `/streams/`, `/monitor/`, `/monitors`, `/monitors/state`,
  `/signal/`, `/connections`, `/connections/`, `/events`. These are the
  endpoints PHP drives to start/stop/monitor a stream — labelled in-code "the
  PHP-only control surface."
- **The gateway already holds the Manager.** It is constructed as
  `gateway.NewServer(policy, mgr.ClientHandler(), mgr)` — the serve path can
  reach the Manager in-process without a socket hop.

## What PHP still orchestrates on an LB today (the gap)

1. **On-demand start trigger.** The gateway deliberately defers a
   not-running stream to PHP. `JudgeLive` (`internal/gateway/live.go:95-96`)
   lists "a stream that is not running" among the cases that "go to PHP before
   anything is written." PHP's `live.php` then starts the stream (via the
   fanout control `/monitor/`) and the viewer retries. With no php-fpm there is
   nothing to defer to — the serve path must trigger the start itself.
2. **MAIN-driven start/stop.** MAIN tells an LB to run or stop a specific
   stream (cluster orchestration). Today this reaches the fanout control API;
   confirm it does so entirely through the Go agent already in the binary, with
   no PHP command in the path.
3. **The LB's lifecycle crons.** The PHP cron loop (`cron:streams` health /
   on-demand / rogue-pid sweep, `cron:stats`, and the stream maintenance jobs)
   runs under php-fpm's sibling CLI today. Much of it duplicates what the Go
   supervisor and reaper already do.
4. **Special stream modes.** `proxy`, `loopback` (relay receive),
   `archive`/`record`/`delay`/`llod`. Not all of these run on a serve-only LB.

> Verified: items under "already in Go," and that the gateway defers a
> not-running stream (live.go:95-96) with `Node` exposing only `HandOverFile`
> today (`internal/gateway/server.go:60`). To confirm during planning: the
> exact PHP call path for each of items 1–4 on an LB (named functionally here;
> the implementation plan enumerates the precise commands/crons from the
> `make lb` tree).

## Design decisions (resolved)

**D1 — On-demand start is an in-process side-call from the serve path to the
Manager, not a new gateway verdict.** The gateway's judgement stays a pure,
side-effect-free classifier (serve / redirect / defer). Starting a stream is a
side effect with retries and process launches; folding it into a verdict would
couple the judge to the supervisor and muddy shadow mode (which must never
start anything). Instead: add one primitive to the gateway's `Node` interface —
`EnsureRunning(id) (ready bool)` — implemented by the Manager driving the same
supervisor path that `/monitor/` drives today. On a serve-only LB, when the
serve path would otherwise defer a not-running stream, it calls `EnsureRunning`
and serves once ready (or returns a short retry/503, mirroring the viewer
retry that follows PHP's on-demand start today). The defer-to-PHP path stays
for nodes still running nginx+PHP during the transition.

**D2 — The LB runs only the loopback relay among the special modes;
`archive`/`record`/`delay`/`llod` stay MAIN-orchestrated or out of scope.** A
serve-only LB restreams; it does not own recording/archival business logic.
Loopback (receiving a relayed source from another node) is part of restreaming
and must work on an LB. The rest are MAIN responsibilities or deferred until a
concrete LB need appears. (Open — see risks — whether `proxy`-mode links are
served by the LB or always resolved at MAIN; today the gateway defers
"a proxied or adaptive link" to PHP, so proxy is out of the serve-only LB's
scope unless that changes.)

## Approach & phasing

**Phase 4a — in-process on-demand start (the core).** Add `EnsureRunning` to
`Node`; implement it on the Manager as the start path `/monitor/` already uses;
wire the serve path to call it instead of deferring, gated so only a serve-only
LB (no PHP backend) takes that branch. Tests: a not-running stream on a
serve-only node starts and serves; a shadow-mode node still never starts; a
node with a PHP backend still defers.

**Phase 4b — MAIN start/stop via the Go agent.** Verify MAIN's explicit
run/stop of an LB stream reaches the Manager entirely through the Go agent, and
wire any step that still routes through a PHP command. No new mechanism if the
agent already covers it — just close gaps and prove it with a test.

**Phase 4c — retire the LB's PHP lifecycle crons.** Map each LB cron
(`cron:streams` health/on-demand/rogue-pid, `cron:stats`, stream maintenance)
to the Go supervisor/reaper. Where the Go side already covers it (health,
idle-stop), drop the cron on the LB. Where a genuine gap exists, port that
specific job into the binary's own scheduler. Deliverable: the `make lb` tree
has no stream-lifecycle PHP cron, and the Go binary runs the equivalents.

**Phase 5 — loopback relay.** Ensure the supervisor can run a loopback-source
stream on an LB (receive a relayed source from another node) end to end without
PHP. Scope limited to loopback per D2.

After 4a–4c and 5, php-fpm has no remaining job on a serve-only LB, and
`LbInstallFlow` drops it (roadmap strategy step 4).

## Out of scope / stays in PHP (on MAIN)

- All viewer authorization (already removed from the LB — option B).
- Recording / archive / timeshift-write business logic and `llod`, `delay`,
  `record`, `archive` orchestration — MAIN owns these.
- The admin/reseller/player panel and every MAIN-only cron.
- `/xfile`, `/status`, `/progress`, `probe`, nginx-rtmp — roadmap sections A/B,
  tracked separately from lifecycle.

## Risks & open questions

- **On-demand start latency.** PHP's defer-then-retry tolerates a slow start
  because the viewer re-requests. An in-process `EnsureRunning` must pick a
  bound: block briefly for the ready signal, or return a fast retry/503. Needs
  the real start-to-first-segment timing to choose; default to a short
  bounded wait then 503-retry.
- **Double-start / races.** The serve path is concurrent; `EnsureRunning` must
  be idempotent and collapse concurrent callers for the same id onto one start
  (the supervisor likely already single-flights launches — confirm, don't
  reimplement).
- **`proxy`/adaptive links.** The gateway defers these to PHP today. Confirm a
  serve-only LB never needs to serve them, or D2 widens.
- **MAIN↔LB orchestration ownership.** With the LB starting streams on demand
  in-process *and* MAIN able to start/stop them, the two must not fight (e.g.
  MAIN stops a stream the LB immediately restarts on the next viewer). Define
  which signals are authoritative — likely: MAIN stop = hard stop with a
  cooldown; viewer demand = start only if not administratively stopped.
- **Cron parity.** Retiring a PHP cron risks dropping a side effect that was
  not obviously lifecycle (a stat flush, a cleanup). 4c must inventory each
  job's effects before deleting it, not just its name.

## Next

This design covers the shape and the two resolved decisions. The next step is a
task-by-task implementation plan for **Phase 4a** (the in-process on-demand
start) — the smallest shippable slice and the one that unblocks the rest —
written once this design is approved.
