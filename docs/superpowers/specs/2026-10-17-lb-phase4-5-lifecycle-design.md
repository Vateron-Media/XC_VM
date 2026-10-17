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

- **The puller + its on-demand lifecycle** (`internal/server`): a registered
  Stream starts its puller on the first viewer's `attach()` (`startLocked`,
  `server.go:738-746`), idle-stops it via the reaper after the grace window
  (`idleStopLocked`), and restarts it when a viewer returns
  (`server.go:632-639`) — the on-demand start/stop cycle, already in-process and
  PHP-free, *for a stream that is registered*.
- **The supervisor** (`internal/supervisor/`, `supervisor.go` ~58 KB plus
  `adopt.go`, `health.go`, `launch_unix.go`, `sources.go`): the *encoder*
  side — launches ffmpeg, detects audio loss / flap / give-up and restarts,
  falls back across sources, retries probes, adopts across a daemon restart.
  Relevant only to a node that encodes/transcodes; a pure-restream LB uses the
  puller, not this.
- **The reaper**: idle-stop of streams with no viewers (grace-window sweep).
- **The control API** (`Manager.ControlHandler()`, `internal/server/server.go`):
  `/streams`, `/streams/`, `/monitor/`, `/monitors`, `/monitors/state`,
  `/signal/`, `/connections`, `/connections/`, `/events`. These are the
  endpoints PHP drives to start/stop/monitor a stream — labelled in-code "the
  PHP-only control surface."
- **The gateway already holds the Manager.** It is constructed as
  `gateway.NewServer(policy, mgr.ClientHandler(), mgr)` — the serve path can
  reach the Manager in-process without a socket hop.

## What PHP still orchestrates on an LB today (the gap)

1. **Stream registration (the real trigger).** The *start* itself is already
   in-process: a Stream registered with its pull config (`Manager.Register`,
   `internal/server/server.go:1393`) starts its puller on the first viewer's
   `attach()` (`startLocked`), the reaper idle-stops it, and a returning viewer
   restarts it — no PHP (`server.go:632-639,738-746`). What PHP supplies is the
   **registration**: it composes the `puller.Source` (URLs, UA, proxy, ffmpeg
   bin, key/IV) from the DB and pushes it to the node on *every request* (`PUT
   /streams/<id>`; "it re-registers on every request", `server.go:1892`). The
   gateway therefore defers a not-running stream to PHP (`JudgeLive`,
   `internal/gateway/live.go:95-96`) because only PHP guarantees the stream is
   registered. With no php-fpm, nothing re-registers — so the config must reach
   the node another way (see D1).
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

**D1 — On-demand start is a replica→registry bridge in Go, not a new start
primitive.** The start already works in-process (puller `attach→startLocked`);
the missing piece on a serve-only LB is the *registration* PHP supplies today.
MAIN already delivers it: the cluster agent replicates each stream's
MAIN-signed record to the node (ADR 0004 R2 streams section,
`internal/clusteragent/streams.go` → `replica/streams/<id>.json`, source
fields included — the header notes "stream sources may carry an upstream's
credentials"). Today PHP's `seedLocal` (XC_VM #318) reads that replica and
registers with the daemon. The bridge moves that step into the Go binary: the
agent parses each replicated record's source fields and calls
`Manager.Register(id, puller.Source{…})` + `setEnc(key, iv)`, re-registering on
the sync's deltas. Then the puller's existing on-demand start/restart serves
every viewer with no PHP, and the gateway — on a serve-only LB — serves a
registered-but-idle stream (attaching starts it) instead of deferring. The
judge stays a pure classifier (no start side effect inside a verdict, so shadow
mode still never starts anything); the serve-not-defer choice is gated by a
serve-only / no-PHP-backend flag, and the defer-to-PHP path stays for nodes
still running nginx+PHP during the transition. The `streamDoc.Data` payload is
MAIN-defined (PHP), so the bridge pins to that exact record shape — the one
cross-repo contract this phase introduces.

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

**Phase 4a — the replica→registry bridge (the core).** In the Go binary, parse
each replicated stream record (`replica/streams/<id>.json`, the MAIN-signed
`data`) into a `puller.Source` + key/IV and call `Manager.Register` + `setEnc`,
driven off the streams-sync deltas the agent already produces, and unregister
on removal. Then gate the gateway serve path so a serve-only LB serves a
registered-but-idle stream instead of deferring. First step of this phase is to
pin MAIN's `streamDoc.data` shape (read what PHP signs and what `seedLocal`
maps). Tests: a replicated record registers the puller with the right source;
a delta re-registers; a removal unregisters; a serve-only node serves a
registered idle stream (puller starts on attach); a shadow-mode node still
never starts; a node with a PHP backend still defers.

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

- **`streamDoc.data` is a MAIN-defined (PHP) contract.** The bridge parses
  fields PHP signs into the record; a shape change on MAIN silently breaks
  registration on the LB. Pin it explicitly (version/validate the parse), read
  both sides when writing the bridge, and treat it as the one cross-repo
  coupling this phase owns. First task of 4a.
- **Does a serve-only LB ever encode/transcode?** If a line with a transcode
  profile can be assigned to an LB, that stream needs the *supervisor* Spec
  (encoder), not just a puller source — and the Spec is likewise PHP-composed,
  so the bridge must cover it too (or MAIN keeps encoded streams off serve-only
  LBs). Confirm before scoping; the pull-only case is assumed in 4a.
- **On-demand start latency.** With the config already resident (register from
  replica), first-viewer start is just the puller spin-up — no MAIN round-trip,
  so the current defer-then-retry latency is not worsened. The serve path must
  still handle "registered, puller starting, no segment yet" gracefully
  (attach, then the normal not-ready path) rather than erroring.
- **Replica freshness vs. a cold stream.** A stream newly created on MAIN may
  not be replicated yet when its first viewer hits the LB. Decide the fallback:
  serve 404/retry until the next sync, or keep a narrow defer-to-MAIN path for
  an unregistered id. Default to retry; a never-replicated id is a 404.
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
task-by-task implementation plan for **Phase 4a** (the replica→registry bridge)
— the smallest shippable slice and the one that unblocks the rest — whose first
task pins MAIN's `streamDoc.data` record shape. The plan is written once this
revised design is confirmed and the two open questions above (encode-on-LB,
replica freshness) are settled.
