# LB Phase 4a — Stream Registration Bridge (runtime directive) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A serve-only LB registers each assigned **direct-proxy restream** with its local fanout — with no PHP on the LB — by having MAIN emit a ready `runtime` directive into the replicated stream record and the Go cluster agent apply it verbatim.

**Architecture:** `xc_node` runs `fanout` and `agent` as two processes that talk over the fanout's control socket. MAIN already replicates a signed per-stream record to each node (`clusteragent` streams-sync → `replica/streams/<id>.json`). This plan (1) has MAIN embed a `runtime` directive `{kind, source}` in that record — computed with MAIN's own logic, so the LB derives nothing (spec D3) — and (2) has the Go agent, as each record applies, turn a `kind:"proxy"` directive into a `PUT /streams/<id>` on the control socket (and `DELETE` on removal). The puller then starts the stream on the first viewer with no PHP. Two repos, two PRs.

**Tech Stack:** PHP 8.1 in `XC_VM` (MAIN side, Task 1); Go (stdlib only — the module has **zero** external deps) in `XC_VM_Fanout` (agent side, Tasks 2-6; local worktree `/root/work/XC_VM_Fanout-2.8.0`, branch `feat/lb-stream-registration-bridge` off `origin/master`).

**Spec:** `docs/superpowers/specs/2026-10-17-lb-phase4-5-lifecycle-design.md` (Phase 4a; decisions D1 deliver-not-compose, D3 MAIN is the single composer / emits a runtime directive the agent applies verbatim).

**Scope note:** This is the *registration* half of Phase 4a, for **direct-proxy** streams only (`kind:"proxy"`). `kind:"transcode"`/other is emitted coarsely and ignored by the agent here; Phase 4b carries the encoder `spec` and the `/monitor/` hand-over. The gateway change that makes a serve-only LB *serve* a registered idle stream instead of deferring to PHP is a sibling slice (needs a clusteragent admission/connection trace); this registration is its prerequisite.

## The runtime directive (the cross-repo contract)

A JSON object under the record's `data.runtime`, emitted by MAIN, applied by the agent:

```json
{ "kind": "proxy", "source": { "urls": ["http://a/1"], "ua": "UA/1", "proxy": "1.2.3.4:8080", "cookie": "c=1" } }
```

- `kind`: `"proxy"` when the stream is a direct-proxy restream (`streams.direct_proxy == 1`), else `"other"` (coarse in 4a; 4b refines to `"transcode"` + `spec`).
- `source`: present only for `"proxy"`. The node-agnostic pull config — `FanoutClient::buildSource` **minus** the `ffmpeg` path (that is node-local; the agent fills it). `key`/`iv`/`headers` are omitted, matching `buildSource`.
- Canonical fixture lives in both repos' testdata and is the contract both sides test against.

## Global Constraints

- Two repos. Task 1 is PHP in `XC_VM`; Tasks 2-6 are Go in `XC_VM_Fanout`. Each repo gets its own branch/PR.
- `XC_VM_Fanout`: **stdlib only** — do not add a module dependency. New Go files carry the repo SPDX header (copy from `internal/server/monitor.go`).
- `XC_VM`: follow `make gates` rules — any procedural/view file `use`-imports what it references; no LB-build privileged leakage. The directive computation reuses `FanoutClient::buildSource`; it does **not** add a new composer.
- **The agent never composes** (spec D3): it applies `data.runtime` verbatim and fills only its own node ffmpeg path. It never reads `direct_proxy`, `stream_source`, or arguments to decide `kind` or build `source` — those are MAIN's, in the directive.
- **Opt-in / dormant** on the agent: gated behind a new agent flag defaulting off (the pattern `-front` set). Nothing changes on any node until enabled. MAIN emitting the directive is inert until an agent reads it.
- **Idempotent:** `PUT /streams/<id>` is idempotent and the fanout no-ops an unchanged config (`sameSource`); re-applying on every record apply is safe, mirroring PHP's per-request re-register.
- **Never log record data or sources** — only stream ids (the streams-sync contract: "No record, its data or a diff of it is ever logged: only stream ids"; sources carry credentials).
- Reuse existing agent infra: `fanoutClient(sock)` (`internal/clusteragent/fanout.go`), `Agent.FanoutCtl`, the `streamSync.apply` loop (`internal/clusteragent/streams.go:541`).

## Review Focus

- **Non-proxy record** (`kind:"other"`, or `kind:"proxy"` with empty `source.urls`) → the agent must skip it, never `PUT` an empty-`urls` body (the fanout answers `400`). Pinned in Task 3 (build returns ok=false) and Task 5 (apply skips).
- **Credentials in `urls`/`proxy`/`cookie`** → never logged; only ids. Pinned in Task 4/5 tests asserting the log line carries no source text.
- **Fanout down or `FanoutCtl` empty** → non-fatal: the error is logged (id only), retried next sync; the agent never crashes and the sync still advances. Pinned in Task 4 (empty socket → no-op) and Task 5 (error does not fail apply).
- **Removal / `kind` flips proxy→other** → `DELETE /streams/<id>`; no stale registration. Pinned in Task 5.
- **Fanout restart** (in-memory registry lost, separate process) → with no PHP to re-register per request, the agent must re-seed held proxy streams when the control connection returns. Pinned in Task 6.
- **MAIN directive on a non-direct-proxy stream** → `kind:"other"`, no `source`; a transcode stream is never emitted as `"proxy"`. Pinned in Task 1.

---

### Task 1 (XC_VM / PHP): MAIN emits the `runtime` directive

**Files:**
- Modify: `src/Core/Cluster/StreamRecords.php` (`data()` — add `runtime` to each record)
- Create: `src/Core/Cluster/StreamRuntimeDirective.php` (the pure builder)
- Test: `tests/Unit/Cluster/StreamRuntimeDirectiveTest.php`
- Create: `tests/Unit/Cluster/fixtures/runtime_directive_proxy.json` (canonical fixture)

**Interfaces:**
- Consumes: a `streams` row (with `direct_proxy`, `stream_source`) and its arguments keyed by `argument_key` (`user_agent`/`proxy`/`cookie`), as `StreamRecords::data()` already assembles (`stream`, `options`).
- Produces: `StreamRuntimeDirective::for(array $rStreamRow, array $rArgsByKey): array` returning `['kind' => 'proxy'|'other', 'source' => array|null]`; and a `runtime` key on each record in `StreamRecords::data()`.

- [ ] **Step 1: Write the failing test.** `StreamRuntimeDirectiveTest`: a row with `direct_proxy=1` + a `stream_source` + args → `kind=proxy` and `source` = `buildSource(...)` **without** the `ffmpeg` key; a row with `direct_proxy=0` → `kind=other`, `source=null`; a `direct_proxy=1` row with empty `stream_source` → `kind=proxy`, `source.urls=[]` (the agent will skip it). Assert the proxy case equals the committed fixture.

- [ ] **Step 2: Run it, see it fail** (`php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamRuntimeDirective`) — class not found.

- [ ] **Step 3: Implement `StreamRuntimeDirective::for`.** `kind = ((int)($rStreamRow['direct_proxy'] ?? 0) === 1) ? 'proxy' : 'other'`. For `proxy`, `source = FanoutClient::buildSource($rStreamRow, $rArgsByKey)` with the `ffmpeg` key removed (node-local; the agent fills it). For `other`, `source = null`.

- [ ] **Step 4: Run the test, see it pass.**

- [ ] **Step 5: Wire it into `StreamRecords::data()`** — add `'runtime' => StreamRuntimeDirective::for($rStream, $rArgsByKey)` to each record's composed array (the arguments keyed by `argument_key` from the record's `options`). Add a `data()`-level test (or extend the existing StreamRecords test) that a proxy stream's record carries the directive. Confirm the directive rides the record's signature/etag (it is part of `data`, which is signed whole).

- [ ] **Step 6: Run `make gates` and the cluster unit tests; commit** (`feat(cluster): emit a runtime directive in the stream record for serve-only LBs`). This is the XC_VM-side PR.

### Task 2 (XC_VM_Fanout / Go): Parse the directive

**Files:**
- Create: `internal/clusteragent/streamregister.go`
- Create: `internal/clusteragent/testdata/runtime_directive_proxy.json` (copy of the canonical fixture)
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: the record's `data` (`streamDoc.Data`, raw JSON) — specifically `data.runtime`.
- Produces: `type runtimeDirective struct { Kind string; Source *directiveSource }`, `type directiveSource struct { URLs []string; UA, Proxy, Cookie string }` (JSON tags `kind`/`source`/`urls`/`ua`/`proxy`/`cookie`); `func parseRuntimeDirective(recordData []byte) (runtimeDirective, bool)` — ok=false when there is no `runtime` object.

- [ ] **Step 1: Write the failing test.** Unmarshal the fixture's `data` → `kind=="proxy"`, `Source.URLs[0]=="http://a/1"`, `UA=="UA/1"`; a `data` with no `runtime` → ok=false; a `{"kind":"other"}` → kind=="other", Source=nil.

- [ ] **Step 2: Run it, see it fail.**

- [ ] **Step 3: Implement the types + `parseRuntimeDirective`** (a struct with a `Runtime *runtimeDirective` field, unmarshalled from the record data).

- [ ] **Step 4: Run the test, see it pass.**

- [ ] **Step 5: Commit** (`feat(agent): parse the runtime directive from a stream record`).

### Task 3 (Go): Build the control-socket body from the directive

**Files:** Modify `internal/clusteragent/streamregister.go`; Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `runtimeDirective` (Task 2); the node ffmpeg path (a string — see Task 5 for where it comes from).
- Produces: `type streamRegisterBody struct` with JSON tags matching the fanout's `streamConfig` (`internal/server/server.go:1812`): `urls`, `ua`, `proxy`, `cookie`, `ffmpeg`, `chunk`. `func (d runtimeDirective) registerBody(ffmpegBin string) (streamRegisterBody, bool)` — ok=false unless `Kind=="proxy"` with at least one non-empty url.

- [ ] **Step 1: Write the failing tests.** A `proxy` directive with urls → body carries urls/ua/proxy/cookie and `ffmpeg==ffmpegBin`; a `proxy` directive with empty urls → ok=false; a `other` directive → ok=false.

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Implement `registerBody`** — copy `Source` fields into `streamRegisterBody`, set `Ffmpeg=ffmpegBin`, leave `Chunk=0` (fanout applies its default); ok only for a `proxy` directive with a non-empty url list.

- [ ] **Step 4: Run the tests, see them pass.**

- [ ] **Step 5: Commit** (`feat(agent): build the fanout register body from a proxy directive`).

### Task 4 (Go): Register / unregister over the control socket

**Files:** Modify `internal/clusteragent/streamregister.go`; Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `streamRegisterBody` (Task 3); `fanoutClient(sock)` and `Agent.FanoutCtl` (`internal/clusteragent/fanout.go`).
- Produces: `func (a *Agent) registerStream(ctx context.Context, id int64, b streamRegisterBody) error`, `func (a *Agent) unregisterStream(ctx context.Context, id int64) error`, sentinel `errFanoutTransient` (returned on the fanout `503`). Both no-op `nil` when `FanoutCtl==""`.

- [ ] **Step 1: Write the failing tests** against an `httptest` unix-socket stub recording method/path/body: PUT `/streams/42` carries the body; `503` → `errors.Is(err, errFanoutTransient)`; DELETE `/streams/42`; `FanoutCtl==""` → nil, no call.

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Implement** both calls via `fanoutClient(a.FanoutCtl)` to `http://fanout/streams/<id>` (the unix dialer ignores the host, as `fanoutConnections` does); `204`→nil, `503`→`errFanoutTransient`, other non-2xx→error naming status + id only.

- [ ] **Step 4: Run the tests, see them pass.**

- [ ] **Step 5: Commit** (`feat(agent): register/unregister a stream on the fanout control socket`).

### Task 5 (Go): Drive it from `apply` + opt-in flag

**Files:** Modify `internal/clusteragent/streams.go` (`streamSync.apply`), `internal/clusteragent/agent.go` (config), `internal/agentcmd/agentcmd.go` (flag); Test `internal/clusteragent/streams_test.go`

**Interfaces:**
- Consumes: `parseRuntimeDirective`/`registerBody`/`registerStream`/`unregisterStream`; `streamSync{a *Agent}` (`streams.go:311`); a node ffmpeg path and a `SeedFanout bool` on `Agent` (both new config, fed like `FanoutCtl` in `agentcmd.go`).
- Produces: registration as a side effect of `apply`; `Agent.SeedFanout`, `Agent.FfmpegBin`.

- [ ] **Step 1: Write the failing tests.** `SeedFanout=true`: applying a stored `proxy` record → PUT with the right body; applying a removal → DELETE; a `kind:"other"` record → no PUT (and DELETE, to clear a prior proxy). `SeedFanout=false`: no calls. A `503` from the stub → `apply` still returns nil and the record is still stored.

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Add the flag + config** — `-seed-fanout` (default false) → `Agent.SeedFanout`; `-ffmpeg` (or reuse an existing node ffmpeg path the agent holds; confirm, else add) → `Agent.FfmpegBin`.

- [ ] **Step 4: Implement the hook in `apply`.** After a record is stored, if `a.SeedFanout`: `parseRuntimeDirective(data)` → `registerBody(a.FfmpegBin)` → ok ? `registerStream` : `unregisterStream` (so a stream that stops being proxy is cleared). After a removal: `unregisterStream`. Register/unregister errors are logged with the id only and swallowed (a down/busy fanout never fails the sync or loses the stored record). Registration runs after the store, so a crash between them re-registers next sync, never the reverse.

- [ ] **Step 5: Run the tests; then `go test ./internal/clusteragent/`. Commit** (`feat(agent): apply the runtime directive to the fanout as records sync (opt-in)`).

### Task 6 (Go): Re-seed on fanout restart

**Files:** Modify `internal/clusteragent/streamregister.go`, the `/events` follow loop in `internal/clusteragent/fanout.go`; Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `heldStreams(dir)` (`streams.go`), the stored `<id>.json` records, `parseRuntimeDirective`/`registerBody`/`registerStream`.
- Produces: `func (a *Agent) seedHeldProxies(ctx context.Context, dir string)` — register every held `proxy` stream; called at agent startup (when `SeedFanout`) and on `/events` (re)connect (a fanout restart drops its in-memory registry).

- [ ] **Step 1: Write the failing test.** Two held records (one `proxy`, one `other`) + `SeedFanout=true` → `seedHeldProxies` issues exactly one PUT (the proxy).

- [ ] **Step 2: Run it, see it fail.**

- [ ] **Step 3: Implement `seedHeldProxies`** (iterate `heldStreams`, read `<id>.json`, parse, build, register the proxies; id-only logging on error) and call it at startup and on the `/events` (re)connect, guarded by `SeedFanout`. Reuse the existing `/events` loop's connect point — no second poller.

- [ ] **Step 4: Run the test; then `go test -race ./internal/clusteragent/` (the seed runs on the events goroutine beside apply). Commit** (`feat(agent): re-seed proxy streams after the fanout restarts`).

## Self-Review

- **Spec coverage:** implements the registration half of Phase 4a via the `runtime` directive — MAIN emits (Task 1), agent applies (Tasks 2-6); D3 holds (agent derives nothing, fills only node ffmpeg). Gateway serve-not-defer is out of scope (scope note).
- **Review Focus:** non-proxy skip (Task 3/5), no-credential logging (Task 4/5), fanout-down non-fatal (Task 4/5), removal/flip DELETE (Task 5), fanout-restart re-seed (Task 6), MAIN never emits proxy for transcode (Task 1) — each has a named test.
- **Type consistency:** the `runtime` directive JSON is the shared contract (one fixture both repos test against); `streamRegisterBody` tags match the fanout `streamConfig` (`server.go:1812`); `kind` values `proxy`/`other` used consistently both sides.
- **Cross-repo contract:** Task 1 (PHP emit) and Task 2 (Go parse) both assert against the same canonical fixture, so the shape cannot drift silently.
