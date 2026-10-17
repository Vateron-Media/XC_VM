# LB gateway serve-not-defer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a serve-only LB, the gateway serves a registered-but-idle stream (letting the puller's `attach` start it) instead of deferring `not-on-air` to a PHP backend that does not exist, and the agent reconciles the fanout's registered set to exactly the held proxies.

**Architecture:** The gateway already creates the viewer connection and runs admission in-process for a new viewer; the only defer left for an otherwise-servable **registered** stream is `not-on-air` (its puller is idle). A new `serve_only` policy flag turns that one defer into a *Serve* verdict for a stream the node holds config for (`Node.Registered(id)`), so the client-handler `attach` starts the puller. Separately, the agent runs a two-way reconcile (register every held proxy, clear everything else) so a failed/stale registration self-heals. Fanout-centred; one small MAIN policy-field addition.

**Tech Stack:** Go (stdlib only) in `XC_VM_Fanout` (`/root/work/XC_VM_Fanout-2.8.0`, branch off `feat/lb-stream-registration-bridge` so it builds on Phase 4a, or off `master` after #84 merges — see Global Constraints; all `go` with `GOWORK=off`); PHP 8.1 in `XC_VM` for the one policy-field task.

**Spec:** `docs/superpowers/specs/2026-10-17-lb-gateway-serve-not-defer-design.md`.

## Global Constraints

- `XC_VM_Fanout` is **stdlib-only**; build/test with `GOWORK=off`. New Go files carry the repo SPDX header.
- Base the Fanout branch on `feat/lb-stream-registration-bridge` (Phase 4a, PR #84) — Tasks 4/5 consume `seedHeldProxies`/`heldStreams`/`registerStream` from it. If #84 has merged to `master`, branch off `master`.
- The judge (`JudgeLive`) stays a **pure classifier** — no start side effect; the start happens on the serve path's `attach`.
- `serve_only` defaults **off**; a transition node (nginx+PHP) is byte-for-byte unchanged. The feature is dormant until a node is marked serve-only.
- `XC_VM`: `make gates` rules; the policy-field change reuses the existing policy.json generation, adds no composer.

## Review Focus

- **Unknown/unregistered stream on a serve-only node** → must still fail closed (`not-on-air`/404), never serve-and-start something the node holds no config for. (Task 2)
- **`serve_only` off** → every path identical to today, including `php("not-on-air")`. (Task 2)
- **Reconcile removing a live stream** → a stream that IS a held proxy must never be unregistered by the reconcile (only fanout-held ids with no held-proxy record go). (Task 4)
- **Reconcile vs. a concurrent sync** → the reconcile must not race `apply` (same `streamsMu` discipline as `seedHeldProxies`). (Task 4)
- **HLS first request with no playlist yet** on a serve-only node → the not-ready response the player retries, not a dead defer. (Task 2)

---

### Task 1: `serve_only` policy field + `Registered` plumbing (Fanout)

**Files:**
- Modify: `internal/gateway/policy.go` (add `ServeOnly bool json:"serve_only"` to `Policy`)
- Modify: `internal/server/server.go` (`Manager.Registered`; add `Registered` to the gateway `Node` interface at :60 and to whatever `LiveEnv` the server builds)
- Modify: `internal/gateway/live.go` (the `LiveEnv` interface — add `Registered(id int) bool` beside `Fed`)
- Test: `internal/server/server_test.go`, `internal/gateway/live_test.go` (a fake env)

**Interfaces:**
- Produces: `Policy.ServeOnly bool`; `func (m *Manager) Registered(id string) bool` (true iff `m.Get(id) != nil` and that stream has a pull config, `s.cfg != nil`); `Node.Registered(id string) bool`; `LiveEnv.Registered(id int) bool`.

- [ ] **Step 1: Write the failing test** for `Manager.Registered`: a registered stream (after `Register`) → true; an unknown id → false; a `GetOrCreate`d-but-never-`Register`ed id → false.
- [ ] **Step 2: Run it, see it fail** (`GOWORK=off go test ./internal/server/ -run TestManagerRegistered`).
- [ ] **Step 3: Implement `Manager.Registered`** (read `s.cfg != nil` under the stream lock; add a small `func (s *Stream) hasConfig() bool` if a lock helper is cleaner), add `Registered` to the `Node` interface and `LiveEnv`, and wire `env.Registered` through to `m.Registered` where the server builds the env.
- [ ] **Step 4: Run tests, see them pass; `GOWORK=off go build ./...`.**
- [ ] **Step 5: Add `ServeOnly` to `Policy`** (field only; no behaviour yet) and **commit** (`feat(gateway): serve_only policy flag + Node.Registered plumbing`).

### Task 2: Serve-and-start a registered idle stream under `serve_only` (Fanout)

**Files:**
- Modify: `internal/gateway/live.go` (`JudgeLive`, the `not-on-air` branches at ~:287 and ~:305)
- Test: `internal/gateway/live_test.go`

**Interfaces:**
- Consumes: `p.ServeOnly` (Task 1), `env.Registered(id)` (Task 1), the existing `env.Fed`/`env.Playlist`.
- Produces: a `Serve` verdict (reason `ts-start` / `playlist-start`) where a serve-only node would otherwise defer `not-on-air` for a registered stream.

- [ ] **Step 1: Write the failing tests** in `live_test.go` with a fake `LiveEnv` (Fed=false):
  - serve-only + registered + TS, not fed → `Verdict.Action == Serve` (reason `ts-start`), not `php`.
  - serve-only + registered + HLS, no playlist → `Serve` (reason `playlist-start`).
  - serve-only + **not** registered → `php("not-on-air")` (unchanged — fail closed).
  - `serve_only` **off** + registered, not fed → `php("not-on-air")` (unchanged).
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Implement the branch:** at each `not-on-air` return, if `p.ServeOnly && env.Registered(r.Stream)` return a `Serve` verdict instead (TS: `Stream/UUID/Codec` as the fed path does; HLS: a serve verdict the serve path turns into the normal not-ready/retry when `env.Playlist` is still empty). Keep the pure-judge contract: no start call here.
- [ ] **Step 4: Run tests, see them pass.**
- [ ] **Step 5: Confirm the serve path** routes this verdict to the client handler so `attach` starts the puller, and that a TS reader on a freshly-attached stream waits for the puller's first bytes (read `serve()` in `internal/gateway/server.go` and the client handler; add an integration test if the seam allows, else a ledger note). **Commit** (`feat(gateway): serve a registered idle stream on a serve-only node`).

### Task 3: `GET /streams` lists registered ids (Fanout)

**Files:**
- Modify: `internal/server/server.go` (`serveControlAll` at :1857 — answer GET with the registered id list; keep DELETE as teardown)
- Modify: `internal/server/server.go` (`func (m *Manager) registeredIDs() []int` — ids whose stream has a pull config)
- Test: `internal/server/server_test.go`

**Interfaces:**
- Produces: `GET /streams` → `{"streams":[<id>,...]}`; `func (m *Manager) registeredIDs() []string` (or `[]int` — match the id type used on the wire by `/streams/<id>`).

- [ ] **Step 1: Write the failing test:** register two streams, `GET /streams` → both ids; DELETE still tears down (unchanged).
- [ ] **Step 2: Run it, see it fail.**
- [ ] **Step 3: Implement** `registeredIDs` (iterate the registry, include ids with `s.cfg != nil`) and the GET arm of `serveControlAll`; leave the DELETE arm exactly as is.
- [ ] **Step 4: Run tests, see them pass.**
- [ ] **Step 5: Commit** (`feat(fanout): GET /streams lists registered ids for the reconcile`).

### Task 4: Two-way reconcile in the agent (Fanout)

**Files:**
- Modify: `internal/clusteragent/streamregister.go` (a `reconcileFanout`)
- Modify: `internal/clusteragent/fanout.go` (call it on the `/events` poll loop, throttled; it already re-seeds on boot-change)
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `heldStreams(a.ReplicaDir)`, `parseRuntimeDirective`, `registerBody`, `registerStream`, `unregisterStream` (all Phase 4a); a new `GET /streams` (Task 3).
- Produces: `func (a *Agent) reconcileFanout(ctx context.Context)` — register every held proxy missing from the fanout's set; unregister every id the fanout holds that is not a held proxy; leave matches alone. No-op unless `SeedFanout`; holds `streamsMu` (like `seedHeldProxies`).

- [ ] **Step 1: Write the failing test** against the fanout stub (extend it to answer `GET /streams`): fanout holds `{7, 9}`, held proxies are `{7, 8}` → reconcile `PUT`s 8 (missing), `DELETE`s 9 (stale), leaves 7. With `SeedFanout` off → no calls.
- [ ] **Step 2: Run it, see it fail.**
- [ ] **Step 3: Implement `reconcileFanout`:** `GET /streams` → fanout set; `heldStreams`→ held-proxy set (parse each, keep `kind:"proxy"` with usable urls); `registerStream` the held-not-in-fanout, `unregisterStream` the fanout-not-in-held; under `streamsMu`; id-only logging. Wire a throttled call (e.g. every `reconcileEvery`, default a couple of minutes) into the `/events` loop beside the boot-change re-seed.
- [ ] **Step 4: Run tests; `GOWORK=off go test -race ./internal/clusteragent/`.**
- [ ] **Step 5: Commit** (`feat(agent): two-way reconcile of the fanout's registered set`).

### Task 5: MAIN emits `serve_only` in policy.json (XC_VM)

**Files:**
- Modify: the policy.json generator (find it: `grep -rn "serve_until\|conn_store" src` → the `GatewayPolicy`/signals generator that writes the gateway policy #431 added)
- Test: the generator's unit test (extend it)

**Interfaces:**
- Produces: `serve_only` in the generated policy.json, from a node attribute (a server/settings flag) that **defaults false**.

- [ ] **Step 1: Locate the generator** (where `serve_until`/`conn_store` are written) and **its test**; read how a per-node boolean is sourced today.
- [ ] **Step 2: Write the failing test:** a node marked serve-only → policy.json has `serve_only: true`; a normal node → absent/false.
- [ ] **Step 3: Run it, see it fail.**
- [ ] **Step 4: Implement** `serve_only` emission from the node attribute (default false). **Ruling to settle here:** which attribute marks a node serve-only — if the single-binary-LB node type is not yet modelled on MAIN (it lands with `LbInstallFlow`), gate on a safe default-false setting and leave a `// serve-only node type: LbInstallFlow follow-up` note, so the field is plumbed but inert until deployment marks a node.
- [ ] **Step 5: Run `make gates` + the generator test; commit** (`feat(cluster): emit serve_only in the gateway policy`).

## Self-Review

- **Spec coverage:** serve-and-start (Tasks 1-2), registered-vs-unknown (Task 1 `Registered` + Task 2 fail-closed test), serve-only signal (Tasks 1 + 5), two-way reconcile (Tasks 3-4). Non-goals (transcode, known-viewer refresh) untouched.
- **Review Focus:** unknown-stream fail-closed (Task 2), serve_only-off unchanged (Task 2), reconcile never drops a held proxy + no race (Task 4), HLS not-ready retry (Task 2 step 5) — each has a test.
- **Type consistency:** `Registered`/`registeredIDs`/`reconcileFanout`/`ServeOnly` used consistently; id wire-type matched to `/streams/<id>` in Task 3.
- **Proportion:** signatures + test names, no transcribed bodies; one genuinely open item (the serve_only source attribute) is a flagged ruling in Task 5, not a silent gap.
