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
on an LB is an **orchestrator and a composer**: it decides which streams to run,
turns each one's DB settings into the thing the runtime needs (a `puller.Source`
for a restream, an ffmpeg command for a transcode), and calls the fanout's
control API over a unix socket to make it happen. Phases 4–5 keep the two
runtimes the binary already has (puller and supervisor) and replace that PHP
orchestration/composition with a Go path fed by MAIN — see D3 on who composes.

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
  side — runs the ffmpeg command it is handed (`Spec.Sources[].Cmd`), detects
  audio loss / flap / give-up and restarts, falls back across sources, retries
  probes, adopts across a daemon restart, honours `OnDemand`. LBs encode and
  transcode, so an LB uses **both** runtimes: the puller for restreams, the
  supervisor for transcodes. Neither composes a command — they run what they
  are given (ADR 0002).
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

**D1 — The LB never composes; it delivers composed work to a runtime, not a new
start primitive.** The runtimes and their on-demand behaviour already exist in
the binary: a registered puller starts on the first viewer's `attach` and
idle-stops/restarts on its own; the supervisor runs an ffmpeg `Spec` it is
handed and manages its lifecycle. The missing piece on a serve-only LB is the
*hand-over* PHP does today, performed over the fanout's control socket (the
`fanout` and `agent` are separate processes): `PUT /streams/<id>` (→
`Manager.Register` + `setEnc`) for a restream, `PUT /monitor/<id>` (→
`supervisor.Supervise`) for a transcode. Phase 4 adds a Go **bridge** in the
agent that takes the composed work MAIN delivers (D3), resolves node
placeholders, and performs that hand-over to the right runtime, re-doing it on
change and tearing it down on removal. Then the
existing on-demand start serves every viewer with no PHP, and the gateway — on a
serve-only LB — serves a registered-but-idle stream (attaching/launching starts
it) instead of deferring. The judge stays a pure classifier (no start side
effect inside a verdict, so shadow mode still never starts anything); the
serve-not-defer choice is gated by a serve-only / no-PHP-backend flag, and the
defer-to-PHP path stays for nodes still running nginx+PHP during the transition.

**D2 — The LB runs only the loopback relay among the special modes;
`archive`/`record`/`delay`/`llod` stay MAIN-orchestrated or out of scope.** A
serve-only LB restreams; it does not own recording/archival business logic.
Loopback (receiving a relayed source from another node) is part of restreaming
and must work on an LB. The rest are MAIN responsibilities or deferred until a
concrete LB need appears. (Open — see risks — whether `proxy`-mode links are
served by the LB or always resolved at MAIN; today the gateway defers
"a proxied or adaptive link" to PHP, so proxy is out of the serve-only LB's
scope unless that changes.)

**D3 — MAIN is the single composer; the LB receives its output, cached at rest
and pushed live.** The ffmpeg-command composition (`StreamProcess::buildLive`,
~3 k lines: codecs, CPU/GPU, `-filter_complex` logo/overlay, subtitle maps,
source fallback, reconnect) stays on MAIN — the one proven implementation — and
is **not** ported to Go. Porting would duplicate that logic (MAIN still composes
for its own streams), forcing two composers to stay byte-identical forever, and
would make the daemon compose a shell command (against ADR 0002); its only
benefit, LB autonomy, is unusable because serve-only already needs MAIN at
viewer time, and is anyway obtained by caching MAIN's output. So: MAIN composes
the `puller.Source` / ffmpeg `Spec` per (stream, LB) and **delivers** it two
ways — at rest in the replicated stream record (`replica/streams/<id>.json`, so
the LB can cold-start through a brief MAIN blip) and pushed live to the LB's
control socket when it changes or should start (event-driven freshness). The
delivered command carries a **closed set of node placeholders**
(`{ffmpeg_bin}`, `{gpu}`, `{streams}/{id}_.pid`, `server_id`, …) that the daemon
substitutes from its own config — substitution, not composition, so the daemon
runs no logic and MAIN need not track each LB's environment. Concretely the
record carries a ready **runtime directive** —
`{kind: "proxy"|"transcode"|"skip", source|spec: …}` — that the agent applies
verbatim; `kind` is MAIN's existing restream-vs-transcode determination (the
same one behind `channel_info["proxy"]`, which is computed at mint, not a raw
column), so **the agent never re-derives which runtime a stream uses or how its
source/command is built.** That directive's shape is the one cross-repo contract
Phase 4 owns, fixed as its first task with both repos open.

## Approach & phasing

**Phase 4a — the restream directive (the beachhead, cross-repo).** MAIN: in the
stream record it signs (`StreamRecords::data`), emit a ready `runtime` directive
for the assigned node — `{kind: "proxy"|"transcode"|"skip", source: {urls, ua,
proxy, cookie}}` — computing `kind` from its existing restream-vs-transcode
determination and `source` from `FanoutClient::buildSource`, so the agent
derives nothing. Go agent: on each applied record read `data.runtime`; for
`kind:"proxy"` resolve the node ffmpeg path and `PUT /streams/<id>` to the fanout
control socket; re-apply on the sync's deltas, `DELETE` on removal or
`kind:"skip"`; a `kind:"transcode"` is left for 4b. This proves the chain
record → directive → hand-over → on-demand start. (The gateway serve-not-defer
change — so a serve-only LB *serves* a registered idle stream instead of
deferring — is a sibling slice for which this registration is the prerequisite;
it needs a separate trace of the clusteragent admission/connection path.) First
step: fix the `runtime` directive shape with both repos open. Tests — Go: a
`proxy` directive registers the puller with the resolved source; a delta
re-registers; a removal / `skip` unregisters; a `transcode` directive is
ignored. PHP: `StreamRecords::data` emits the right directive for a proxy stream
and `kind:"transcode"` for a transcoding one.

**Phase 4b — the transcode directive (the big one, cross-repo).** MAIN: extend
the `runtime` directive to `kind:"transcode"` carrying the composed ffmpeg `spec`
— `buildLive` output with node values emitted as the D3 placeholders — so MAIN
composes per (stream, LB) without hand-coding each LB's environment. Go agent:
parse the `spec`, resolve the placeholders from the daemon's own config, and
`PUT /monitor/<id>` (the supervisor hand-over); re-apply on change, release on
removal. First step: fix the `spec`/placeholder shape with both repos open. Tests: a transcode record supervises with the right resolved
command; a placeholder resolves to the node's `ffmpeg_bin`/`gpu`/paths; a change
re-supervises; an unsupervise on removal; a malformed/unknown placeholder is
rejected, never shell-expanded.

**Phase 4c — live push + MAIN start/stop via the Go agent.** Add the
control-socket delivery (D3's push): MAIN sends a start/stop/update for an LB
stream and the Go agent applies it to the bridge, so a newly-created or changed
stream is fresh without waiting for the next replica sync. Verify MAIN's
explicit run/stop reaches the Manager entirely through the Go agent with no PHP
command in the path, and settle MAIN↔LB authority (see risks). Tests: a pushed
start supervises/registers at once; a pushed stop hard-stops and the serve path
does not immediately restart it on the next viewer.

**Phase 4d — retire the LB's PHP lifecycle crons.** Map each LB cron
(`cron:streams` health/on-demand/rogue-pid, `cron:stats`, stream maintenance)
to the Go supervisor/reaper. Where the Go side already covers it (health,
idle-stop), drop the cron on the LB. Where a genuine gap exists, port that
specific job into the binary's own scheduler. Inventory each job's side effects
before deleting it, not just its name. Deliverable: the `make lb` tree has no
stream-lifecycle PHP cron, and the Go binary runs the equivalents.

**Phase 5 — loopback relay.** Ensure the supervisor can run a loopback-source
stream on an LB (receive a relayed source from another node) end to end without
PHP. Scope limited to loopback per D2.

After 4a–4d and 5, php-fpm has no remaining job on a serve-only LB, and
`LbInstallFlow` drops it (roadmap strategy step 4).

## Out of scope / stays in PHP (on MAIN)

- All viewer authorization (already removed from the LB — option B).
- **ffmpeg-command / `puller.Source` composition** — stays on MAIN per D3. The
  LB *runs* encoders and pullers but never composes what they run; it only
  resolves node placeholders. This is the line between "lifecycle in Go" (in
  scope) and "composition in Go" (explicitly rejected).
- Recording / archive / timeshift-write business logic and `llod`, `delay`,
  `record`, `archive` orchestration — MAIN owns these.
- The admin/reseller/player panel and every MAIN-only cron.
- `/xfile`, `/status`, `/progress`, `probe`, nginx-rtmp — roadmap sections A/B,
  tracked separately from lifecycle.

## Risks & open questions

- **`streamDoc.data` is a MAIN-defined (PHP) contract.** The bridge parses what
  PHP signs — the restream fields (4a) and the composed `Spec` (4b); a shape
  change on MAIN silently breaks hand-over on the LB. Pin and version it, read
  both sides when writing the bridge, validate on parse, and treat it as the one
  cross-repo coupling Phase 4 owns.
- **Placeholder set completeness.** `buildLive` embeds node-specific values
  (ffmpeg bin, GPU index, `<streams>`/`<logs>` paths, pid/errors/progress
  files, server id). Every one must map to a daemon-resolved placeholder, or a
  real MAIN path leaks into the LB's command or the command fails to run.
  Enumerate them against `buildLive` when pinning the 4b shape; reject an
  unknown placeholder rather than shell-expand it.
- **MAIN composes per LB, so it needs the LB's capabilities, not just paths.**
  Placeholders resolve a GPU's *index*, but whether to transcode on GPU at all
  is a *composition* branch in `buildLive` that depends on the target LB having
  a GPU, its codec support, its ffmpeg build. MAIN must learn those facts (the
  agent already exchanges an ffmpeg-feature list at hello) and compose for the
  assigned LB — not for MAIN's own hardware. Define the capability inputs 4b's
  composition reads before building it.
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

This design covers the shape and the three resolved decisions (D1 deliver-not-
compose, D2 loopback-only, D3 MAIN is the single composer). The next step is a
task-by-task implementation plan for **Phase 4a** (the restream directive) — the
smallest shippable slice, cross-repo (MAIN emits the `runtime` directive; the Go
agent applies it), proving the whole chain end to end — whose first task fixes
the `runtime` directive shape with both repos open. Phase 4b (transcode) extends
the same directive to carry the composed `spec` and gets its own plan once 4a
lands.
