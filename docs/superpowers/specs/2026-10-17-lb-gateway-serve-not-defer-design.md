# LB gateway serve-not-defer (design)

A sibling slice of Phase 4a in the single-binary-LB roadmap
(`2026-10-17-lb-phase4-5-lifecycle-design.md`). Phase 4a made a serve-only LB
*register* its assigned restreams with the fanout from the replica (no PHP).
This slice makes the gateway actually *serve* such a stream when it is idle,
instead of deferring to PHP — closing the last viewer-path defer that a
serve-only LB (no nginx, no php-fpm) has nothing to defer to.

## Intent / why

On a serve-only LB there is no PHP and no nginx behind the Go front. The front
re-proxies a gateway "defer to PHP" verdict (`X-Accel-Redirect`) to nginx
(`front.interceptor.toPHP`). On a true serve-only node that backend does not
exist, so any defer is a dead end. The gateway must therefore answer every
viewer request a serve-only LB can legitimately answer — including the one case
it still punts today: a **registered but not-yet-fed** stream (its puller is
idle, so `env.Fed(id)` is false), which it defers as `not-on-air`
(`internal/gateway/live.go:287,305`).

Crucially, the gateway **already** does the hard parts in-process: for a new
viewer it creates the connection (`newTSRecord`/`newHLSRecord`, live.go:232-244)
and runs admission (`admissionHeader`, live.go:277-283). It is not the
connection/admission that forces the defer — only the stream being idle. And the
puller already starts on a viewer's `attach` (`server.go:740-745` →
`startLocked`). So the slice is small: on a serve-only node, serve a registered
idle stream (letting the attach start its puller) rather than deferring.

## Goals

- A serve-only LB serves a viewer for a **registered** stream whose puller is
  idle: the first request starts the puller and the viewer gets the stream (TS)
  or retries into a playlist that appears a beat later (HLS) — no PHP.
- A genuinely **unknown / unregistered** stream still fails closed (404 /
  not-on-air), never a serve-and-start of something the node holds no config for.
- On a **transition node** (nginx+PHP present) nothing changes: the existing
  defer-to-PHP path stays.
- Fold in the two-way fanout **reconcile** that Phase 4a's review deferred here:
  on a serve-only LB, the set the fanout has registered converges to exactly the
  held proxies — missing ones get registered, stale ones removed.

## Non-goals

- Transcode/encoder serving (that is Phase 4b's `/monitor/` path; this slice is
  the restream/puller serve path only).
- Changing how a **known** viewer's refresh is served (already in-process).
- Porting any further PHP block-chain logic to the LB — auth stays on MAIN
  (option B); this slice only removes the `not-on-air` punt.
- HLS first-request semantics for a non-serve-only node (unchanged).

## Anti-goals (a failure even if it "works")

- Serving a stream the node has no registration for because the judge guessed —
  a stream must be registered before the gateway will start it.
- Putting a start **side effect inside the pure judge** — the judge classifies;
  the start happens on the serve path's `attach`, as it does today.
- Making a serve-only node and a transition node diverge in any path other than
  the `not-on-air`-for-a-registered-stream decision.

## Constraints

- `XC_VM_Fanout` module is **stdlib-only**; all Go built with `GOWORK=off`.
- The judge (`JudgeLive`) stays a pure, side-effect-free classifier.
- Auth is MAIN's; the LB only verifies the minted token and serves.
- The serve-only signal must not change a transition node's behaviour.

## The design (decided)

**Mechanism — serve-and-start, not a judge side effect.** On a serve-only node,
where `JudgeLive` today returns `php("not-on-air")` for a stream that is
*registered* but not yet fed, it instead returns a **Serve** verdict. The serve
path routes to the client handler, whose `attach` starts the puller
(`startLocked`); a TS reader then gets bytes as the puller spins up, and an HLS
request that finds no playlist yet returns the normal not-ready response so the
player retries (the puller is starting). No new `EnsureRunning` call; the judge
gains only a branch, not a side effect.

**Registered-vs-unknown — a read-only `Node` signal.** The gateway's `Node`
interface (`internal/gateway/server.go:60`) exposes `Fed(id)` but cannot tell an
idle-registered stream from an unknown one. Add one read-only method —
`Registered(id string) bool` (the Manager answers from its stream registry) — so
the serve-and-start branch fires only for a stream the node actually holds config
for. An unknown id keeps today's `not-on-air`/404.

**Serve-only signal — a new policy field.** No serve-only/no-PHP signal exists
today (only `ServeUntil`, `ConnStore`). Add a policy field (working name
`serve_only`, `internal/gateway/policy.go` + MAIN's policy.json / gateway.conf
generation) that MAIN sets true for a serve-only LB. The judge consults it to
decide whether a `not-on-air`-for-registered becomes Serve (serve-only) or stays
`php("not-on-air")` (transition node). This is the one small MAIN-side addition;
everything else is Fanout.

**Which defers convert.** Only the `not-on-air`-for-a-**registered** stream
converts to serve-and-start under `serve_only`. Every other PHP-defer reason
(token/ip/limit/proxy/second-ip/record/worker/…) is unchanged: on a serve-only
node those are terminal (the front has no PHP to re-proxy to), so they become
the appropriate error to the viewer rather than a dead `X-Accel-Redirect`. The
plan enumerates each reason's serve-only terminal mapping; the common path is the
`not-on-air` conversion.

**Two-way reconcile (folded in from 4a's deferred review findings).** On a
serve-only LB, reconcile the fanout's registered set to exactly the held
proxies: periodically (and on fanout boot-change, already wired) register every
held proxy that is missing and unregister anything the fanout holds that is no
longer a held proxy. This closes 4a's two Important findings (a failed
register/unregister, or a bulk drain without a process restart, is no longer
stranded) — they matter here because, once the gateway serves a registered idle
stream, a stale/missing registration becomes serving-visible rather than inert.

## What's left to the builder (settle in the plan)

- The exact policy field name and MAIN-side generation (where `serve_only` is
  written into policy.json / gateway.conf, and from which node attribute).
- Whether the reconcile learns the fanout's current set via a GET on the control
  socket (`GET /streams`? confirm it exists, else add a list endpoint) or by
  tracking locally what the agent registered; and the reconcile cadence.
- The precise serve-only terminal mapping for each non-`not-on-air` defer reason
  (what the viewer gets when there is no PHP to defer to).
- Confirm the TS serve path tolerates a freshly-attached, not-yet-fed stream
  (the reader waits for the puller's first bytes) and pick the HLS not-ready
  response/retry shape.
- Whether this slice ships behind the same opt-in as 4a's `-seed-fanout` (likely
  yes — serve-and-start without registration seeding is meaningless).

## Testing shape

- Judge: a registered idle stream on a serve-only node → Serve (not `php`); an
  unknown id on a serve-only node → `not-on-air`/404; both on a transition node →
  `php("not-on-air")` (unchanged).
- `Node.Registered` true for a registered stream, false for an unknown one.
- Reconcile: held proxies missing from the fanout get registered; a fanout-held
  id that is no longer a held proxy gets unregistered; a held proxy already
  registered is left alone (idempotent).
- End-to-end (where the env allows): first TS request to a registered idle
  stream starts the puller and streams; HLS retries into a playlist.

## Size

Bounded-architectural, Fanout-centred with one small MAIN policy-field addition.
One design doc (this), one plan.
