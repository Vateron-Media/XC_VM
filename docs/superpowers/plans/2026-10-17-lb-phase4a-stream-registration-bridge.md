# LB Phase 4a — Stream Registration Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a serve-only LB, the Go **cluster agent** registers each assigned restream with the local fanout from the replicated stream record — no PHP — so the fanout's puller starts it on demand.

**Architecture:** `xc_node` runs `fanout` and `agent` as two processes that already talk over the fanout's control socket. Today PHP (`FanoutClient`/`seedLocal`) turns a stream's DB row into a pull config and `PUT /streams/<id>`s it to the fanout on every request. This plan ports that one step into the Go agent: as the streams-sync applies each MAIN-signed record, derive the pull config (a faithful port of `FanoutClient::buildSource`) and `PUT`/`DELETE /streams/<id>` over the existing control-socket client. Opt-in and dormant until a flag turns it on.

**Tech Stack:** Go (stdlib only — the module has **zero** external dependencies by design), in repo `XC_VM_Fanout` (local worktree `/root/work/XC_VM_Fanout-2.8.0`, branch off `master`). The PHP it mirrors is in repo `XC_VM`.

**Spec:** `docs/superpowers/specs/2026-10-17-lb-phase4-5-lifecycle-design.md` (Phase 4a; decisions D1 deliver-not-compose, D3 MAIN is the single composer).

**Scope note (what this plan is NOT):** This is the *registration* half of the spec's Phase 4a. The gateway change that makes a serve-only LB *serve* a not-running stream instead of deferring to PHP (and the new-viewer connection-creation it entails) is a sibling slice that needs its own trace of the clusteragent admission/connection path; it is out of scope here. This slice is a prerequisite for it and is independently testable: with the bridge on, the fanout holds the right pull config for every assigned restream, which a transition node (still has PHP) treats as a harmless, idempotent duplicate of PHP's own registration.

## Global Constraints

- Repo `XC_VM_Fanout`; **stdlib only** — do not add a module dependency.
- New Go files start with the repo SPDX header (copy from `internal/server/monitor.go`): `// SPDX-License-Identifier: AGPL-3.0-or-later` + the two project lines.
- **The agent never composes an ffmpeg command** (spec D3). This slice only field-maps a restream's source and resolves the node-local ffmpeg path; nothing here builds a command line.
- **Opt-in / dormant:** gated behind a new agent flag defaulting off (the pattern the HTTP front's `-front` set). Nothing changes on any node until it is enabled.
- **Idempotent:** `PUT /streams/<id>` is idempotent and the fanout no-ops an unchanged config (`sameSource`), so re-registering on every apply is safe — mirror PHP, which re-registers per request. No delta-diffing.
- **Never log record data or sources** — only stream ids. The streams-sync contract (`internal/clusteragent/streams.go` header) is explicit: "No record, its data or a diff of it is ever logged: only stream ids." Sources carry upstream credentials.
- Reuse existing infra: `fanoutClient(sock)` (`internal/clusteragent/fanout.go`), the agent's `FanoutCtl` socket path, and the `streamSync.apply` loop (`internal/clusteragent/streams.go`). Do not open a second client or re-walk the sync.

## Review Focus

- **Non-pull record** (no `stream_source`: a push/ingest stream, or a transcode whose command this slice does not handle) → the bridge must skip it, never `PUT` a body with empty `urls` (the fanout answers `400 bad config`). Pinned in Task 2 (`buildPullConfig` returns ok=false) and Task 4 (apply skips).
- **Credentials in `urls`/`proxy`/`cookie`** → never logged; log only the id on every path. Pinned in Task 3 and Task 4 tests that assert the log line carries no source text.
- **Fanout down or `FanoutCtl` empty** → non-fatal: the call returns an error the apply loop logs (id only) and retries on the next sync; the agent never crashes and the sync still advances. Pinned in Task 3 (empty socket → no-op) and Task 4 (error does not fail apply).
- **Teardown / removal** → a removed record issues `DELETE /streams/<id>`; no stale registration is left on the fanout. Pinned in Task 4.
- **Fanout restart** (its registry is in-memory, in another process) → with no PHP to re-register per request, the agent must re-seed held streams when the control connection comes back, or a running LB silently stops honouring viewers. Pinned in Task 5.

---

### Task 1: Pin the restream record shape

**Files:**
- Create: `internal/clusteragent/streamregister.go` (types only in this task)
- Create: `internal/clusteragent/testdata/stream_record_restream.json` (fixture)
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: the stored record at `replica/streams/<id>.json` = `{"etag","ver","data":<raw>}` (`streamDoc`, `streams.go:600`). `data` is MAIN-signed and MAIN-defined; its canonical shape is built by `XC_VM` `src/Core/Cluster/StreamRecords.php::data()` as `{"stream":{…STREAM_FIELDS…},"type",​"profile","options","server","children","recordings","tickets"}`.
- Produces: `type streamRecordData struct{ … }` — the subset the bridge reads, with JSON tags matching the record.

- [ ] **Step 1: Read both sides and pin the field nesting.** In `XC_VM`: `src/Core/Cluster/ReplicaSections.php` (the `STREAM_FIELDS`, `OPTION_FIELDS`, `ARGUMENT_FIELDS` key lists) and `src/Core/Cluster/StreamRecords.php` (how `data()` assembles them); and `src/Streaming/Fanout/FanoutClient.php::buildSource` (lines 58-97) — its inputs are `$rStreamRow['stream_source']` and `$rStreamArguments['user_agent'|'proxy'|'cookie']['value'|'argument_default_value']`. Determine where in `data` `stream_source` sits (`data.stream.stream_source`) and where the `user_agent`/`proxy`/`cookie` arguments sit (under `options`/arguments — pin the exact key).

- [ ] **Step 2: Capture a real fixture.** From the test node (or crafted to match the pinned shape), save one restream record's `data` object to `testdata/stream_record_restream.json`. Redact any real credential to a placeholder; keep the structure exact.

- [ ] **Step 3: Write the failing test.**

```go
func TestParseStreamRecordData(t *testing.T) {
    raw, _ := os.ReadFile("testdata/stream_record_restream.json")
    rec, err := parseStreamRecordData(raw)
    if err != nil { t.Fatal(err) }
    if got := rec.streamSourceURLs(); len(got) == 0 { t.Fatal("no source urls parsed") }
    if rec.argument("user_agent") == "" { t.Fatal("user_agent not parsed") }
}
```

- [ ] **Step 4: Run it, see it fail** (`go test ./internal/clusteragent/ -run TestParseStreamRecordData`) — undefined `parseStreamRecordData`.

- [ ] **Step 5: Define the types + accessors** in `streamregister.go`: `streamRecordData` with the pinned JSON tags; `func parseStreamRecordData(raw []byte) (*streamRecordData, error)`; helper accessors `streamSourceURLs() []string` (json-decodes `stream_source`) and `argument(name string) string` (reads the argument's value, else its default). No registration logic yet.

- [ ] **Step 6: Run the test, see it pass.**

- [ ] **Step 7: Commit** (`feat(agent): parse replica stream record for the registration bridge`).

### Task 2: Port `buildSource` to Go

**Files:**
- Modify: `internal/clusteragent/streamregister.go`
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `*streamRecordData` (Task 1).
- Produces: `type pullConfig struct` with JSON tags **exactly** matching the fanout's `streamConfig` (`internal/server/server.go:1812`): `urls []string`, `ua string`, `proxy string`, `cookie string`, `ffmpeg string`, `chunk int` (plus `key`/`iv`/`headers` omitted — see note). `func buildPullConfig(rec *streamRecordData, ffmpegBin string) (pullConfig, bool)` — ok=false when there are no non-empty urls.

- [ ] **Step 1: Write the failing tests** mirroring `FanoutClient::buildSource` exactly:

```go
func TestBuildPullConfig(t *testing.T) {
    rec := mustRecord(t, `{"stream":{"stream_source":"[\"http://a/1\",\"  \",\"http://b/2\"]"},
        "options":{"user_agent":{"value":"UA/1"},"proxy":{"value":"http://1.2.3.4:8080/"},"cookie":{"value":"c=1"}}}`)
    c, ok := buildPullConfig(rec, "/opt/ffmpeg")
    if !ok { t.Fatal("want ok") }
    want := pullConfig{URLs: []string{"http://a/1", "http://b/2"}, UA: "UA/1", Proxy: "1.2.3.4:8080", Cookie: "c=1", Ffmpeg: "/opt/ffmpeg"}
    if !reflect.DeepEqual(c, want) { t.Fatalf("got %+v want %+v", c, want) }
}
func TestBuildPullConfigNoSourceSkips(t *testing.T) {
    rec := mustRecord(t, `{"stream":{"stream_source":"[]"},"options":{}}`)
    if _, ok := buildPullConfig(rec, "/opt/ffmpeg"); ok { t.Fatal("empty source must not register") }
}
func TestBuildPullConfigDefaultUA(t *testing.T) {
    rec := mustRecord(t, `{"stream":{"stream_source":"[\"http://a/1\"]"},"options":{}}`)
    c, _ := buildPullConfig(rec, "/opt/ffmpeg")
    if c.UA != defaultUA { t.Fatalf("want default UA, got %q", c.UA) }
}
```

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Implement `buildPullConfig`** mirroring the PHP: urls = trimmed non-empty entries of the json-decoded `stream_source` (ok=false when none); ua = `argument("user_agent")` else `defaultUA` (copy the exact default string from `FanoutClient::DEFAULT_UA`); proxy = `argument("proxy")` with any `^https?://` prefix stripped and a trailing `/` trimmed; cookie = `argument("cookie")`; ffmpeg = `ffmpegBin`. Define `const defaultUA = "…"`.

- [ ] **Step 4: Run the tests, see them pass.**

- [ ] **Step 5: Commit** (`feat(agent): derive a fanout pull config from a stream record (buildSource port)`).

> Note: `key`/`iv` (encrypted-HLS) and `headers` are not set by `FanoutClient::buildSource` today, so they stay out of this slice; encrypted-HLS/ingest is a separate path. Keep `pullConfig` to the buildSource fields plus `chunk` (left 0 → the fanout applies its configured default).

### Task 3: Register / unregister over the control socket

**Files:**
- Modify: `internal/clusteragent/streamregister.go`
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `pullConfig` (Task 2); the agent's `FanoutCtl` socket path and `fanoutClient(sock) *http.Client` (`internal/clusteragent/fanout.go`).
- Produces: `func (a *Agent) registerStream(ctx context.Context, id int64, c pullConfig) error` (PUT), `func (a *Agent) unregisterStream(ctx context.Context, id int64) error` (DELETE), and a sentinel `var errFanoutTransient = errors.New(...)` returned on the fanout's `503` (a concurrent teardown; the caller retries next sync). Both no-op (`nil`) when `FanoutCtl == ""`.

- [ ] **Step 1: Write the failing tests** against an `httptest` unix-socket stub that records method/path/body:

```go
func TestRegisterStreamPUTsConfig(t *testing.T) {
    a, rec := agentWithFanoutStub(t) // stub answers 204, records the request
    err := a.registerStream(context.Background(), 42, pullConfig{URLs: []string{"http://a/1"}, UA: "UA/1", Ffmpeg: "/f"})
    if err != nil { t.Fatal(err) }
    if rec.method != "PUT" || rec.path != "/streams/42" { t.Fatalf("got %s %s", rec.method, rec.path) }
    var body pullConfig; _ = json.Unmarshal(rec.body, &body)
    if body.URLs[0] != "http://a/1" { t.Fatalf("body not sent: %s", rec.body) }
}
func TestRegisterStream503IsTransient(t *testing.T) { /* stub 503 → errors.Is(err, errFanoutTransient) */ }
func TestUnregisterStreamDELETEs(t *testing.T)      { /* DELETE /streams/42 */ }
func TestRegisterStreamNoSocketIsNoop(t *testing.T) { /* FanoutCtl=="" → nil, no call */ }
```

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Implement** `registerStream`/`unregisterStream`: build the request to `http://fanout/streams/<id>` using `fanoutClient(a.FanoutCtl)` (the `http://fanout` host is ignored by the unix dialer, matching `fanoutConnections`); JSON-encode the body for PUT; map `204` → nil, `503` → `errFanoutTransient`, other non-2xx → a plain error naming the status and the id only.

- [ ] **Step 4: Run the tests, see them pass.**

- [ ] **Step 5: Commit** (`feat(agent): register/unregister a stream on the fanout control socket`).

### Task 4: Drive it from the streams-sync apply + opt-in flag

**Files:**
- Modify: `internal/clusteragent/streams.go` (`streamSync.apply`)
- Modify: `internal/clusteragent/agent.go` (a `SeedFanout bool` config field)
- Modify: `internal/agentcmd/agentcmd.go` (the `-seed-fanout` flag → config)
- Test: `internal/clusteragent/streams_test.go` (or `streamregister_test.go`)

**Interfaces:**
- Consumes: `registerStream`/`unregisterStream`/`buildPullConfig`/`parseStreamRecordData`; `streamSync{ a *Agent }` (`streams.go:311`); the agent's local ffmpeg path (confirm the field the agent already holds for its cpu ffmpeg; otherwise add a `FfmpegBin` config fed the same way as `FanoutCtl`).
- Produces: registration as a side effect of `apply`; `Agent.SeedFanout`.

- [ ] **Step 1: Write the failing test.** Drive `apply` with a stored record and a removal; assert the fanout stub saw `PUT /streams/<id>` with the derived config, then `DELETE /streams/<id>`; and that with `SeedFanout=false` neither fires.

```go
func TestApplySeedsFanoutWhenEnabled(t *testing.T) { /* SeedFanout=true: stored record → PUT; removal → DELETE */ }
func TestApplyDoesNotSeedWhenDisabled(t *testing.T){ /* SeedFanout=false: no calls */ }
func TestApplySkipsNonPullRecord(t *testing.T)     { /* empty stream_source → no PUT */ }
func TestApplyTransientDoesNotFailApply(t *testing.T){ /* stub 503 → apply still returns nil, record still stored */ }
```

- [ ] **Step 2: Run them, see them fail.**

- [ ] **Step 3: Add the flag + config.** `-seed-fanout` (default false) in `agentcmd.go` wired to `Agent.SeedFanout`, described as "serve-only LB: register assigned streams on the fanout from the replica (replaces PHP)".

- [ ] **Step 4: Implement the hook in `apply`.** After a record is stored (its `.rep`/`.json` written), if `a.SeedFanout`: `parseStreamRecordData` → `buildPullConfig(rec, a.ffmpegBin())` → if ok `registerStream`; if not ok, skip. After a removal is applied: `unregisterStream`. A register/unregister error is logged with the id only and otherwise swallowed (so a down or busy fanout never fails the sync or loses the stored record); `errFanoutTransient` is logged at most as debug. Registration runs after the store so a crash between the two re-registers on the next sync, never the reverse.

- [ ] **Step 5: Run the tests, see them pass. Then run the whole package** (`go test ./internal/clusteragent/`).

- [ ] **Step 6: Commit** (`feat(agent): seed the fanout from the replica as records apply (opt-in)`).

### Task 5: Re-seed held streams when the fanout comes back

**Files:**
- Modify: `internal/clusteragent/streamregister.go` (a `seedAllHeld`)
- Modify: `internal/clusteragent/fanout.go` or the `/events` follow loop (call it on (re)connect)
- Test: `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `heldStreams(dir)` (`streams.go` — the ids with a stored `.json`), `parseStreamRecordData`, `buildPullConfig`, `registerStream`.
- Produces: `func (a *Agent) seedAllHeld(ctx context.Context, dir string)` — register every held restream; called once at agent startup (when `SeedFanout`) and again whenever the control-socket `/events` stream (re)connects, since a fanout restart drops its in-memory registry.

- [ ] **Step 1: Write the failing test.** Two held records on disk + `SeedFanout=true` → `seedAllHeld` issues a `PUT` for each; a held record with no source is skipped.

```go
func TestSeedAllHeldRegistersEveryHeldPull(t *testing.T) { /* 2 PUTs for 2 held pull records, 0 for a sourceless one */ }
```

- [ ] **Step 2: Run it, see it fail.**

- [ ] **Step 3: Implement `seedAllHeld`** (iterate `heldStreams`, read `<id>.json`, parse, build, register; log id-only on error) and call it from agent startup and on `/events` (re)connect, guarded by `SeedFanout`. Reuse the existing `/events` follow loop's connect point — do not add a second poller.

- [ ] **Step 4: Run the test, see it pass.**

- [ ] **Step 5: Run the full agent package with the race detector** (`go test -race ./internal/clusteragent/`) — the seed runs on the events goroutine beside apply.

- [ ] **Step 6: Commit** (`feat(agent): re-seed the fanout after it restarts`).

## Self-Review

- **Spec coverage:** this plan implements the registration half of Phase 4a (D1 hand-over for a restream, D3 — the agent field-maps and resolves the node ffmpeg path, never composes a command). The gateway serve-not-defer half is explicitly out of scope (scope note) and tracked as a sibling.
- **Review Focus:** non-pull skip (Task 2/4), no-credential logging (Task 3/4), fanout-down non-fatal (Task 3/4), teardown DELETE (Task 4), fanout-restart re-seed (Task 5) — each has a named test.
- **Type consistency:** `pullConfig` JSON tags match the fanout's `streamConfig` (`server.go:1812`); `parseStreamRecordData`/`buildPullConfig`/`registerStream`/`unregisterStream`/`seedAllHeld` names are used consistently across tasks.
- **Open item for Task 1:** the exact `data` key for the arguments nesting is pinned by reading `ReplicaSections.php`; it is the one shape the implementer must read both repos to fix, which is why it is Task 1.
