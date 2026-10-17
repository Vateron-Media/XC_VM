# LB Phase 4b-ii — agent transcode-apply Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a serve-only LB, the Go cluster agent applies a `kind:"transcode"` runtime directive — resolve its placeholders against the node, register an ingest socket with the local fanout, and hand the encoder Spec to the supervisor (`PUT /monitor/<id>`) — with no PHP.

**Architecture:** 4b-i (MAIN, separate plan) emits a portable `supervisor.Spec` with placeholder tokens (`{ingest_sock}`, `{streams}`, `{ffmpeg_cpu}`, `{ffmpeg_gpu}`, `{ffprobe}`, `{binary}`, `{logs}`, `{server_id}`, `{key}`, `{iv}`). This plan is the LB side: parse the directive's `spec`, register an ingest socket (`PUT /ingest/<id>`, which also sets the HLS key/IV), build the token→value map from the agent's node config plus the socket and a freshly-generated key/IV, substitute every token in the spec JSON, and `PUT /monitor/<id>`. It extends 4a's `seedFanoutRecord`/apply path in `internal/clusteragent`. Go-only, locally testable (no DB; `GOWORK=off`).

**Tech Stack:** Go (stdlib only) in `XC_VM_Fanout`, branch off `feat/lb-stream-registration-bridge` (Phase 4a — this plan extends `seedFanoutRecord`, `fanoutClient`, `runtimeDirective`).

**Spec:** `docs/superpowers/specs/2026-10-17-lb-phase4b-transcode-directive-design.md`.

## Global Constraints

- stdlib only; `GOWORK=off`; SPDX header on new files.
- Tokens exactly as 4b-i emits: `{ingest_sock}` `{streams}` `{logs}` `{server_id}` `{ffmpeg_cpu}` `{ffmpeg_gpu}` `{ffprobe}` `{binary}` `{key}` `{iv}`.
- Opt-in: only under `-seed-fanout` (4a's flag). A `kind:"transcode"` directive on an agent without the node config set is skipped with an id-only log, never a half-resolved `/monitor/` PUT.
- The agent **resolves** placeholders (design); it does not compose. An unresolved token must never reach `/monitor/` — Task 3 fails closed if any `{...}` remains.
- Never log the spec or its command (it carries source credentials); log stream ids only.
- The shared fixture (a representative transcode directive) is the contract with 4b-i; both sides use identical token spellings.

## Review Focus

- **An unresolved token reaching the supervisor**: after substitution, any remaining `{...}` means the node config is incomplete → skip + log, never PUT. (Task 3)
- **Key/IV consistency**: the key/IV the agent generates must go to BOTH `/ingest/` (so the fanout serves `/key` + the playlist references it) AND the command's `{key}`/`{iv}` — a mismatch yields undecryptable segments. (Task 2/3)
- **Ingest-register failure / fanout down**: non-fatal, logged by id, retried next sync (like 4a's register). (Task 3)
- **A transcode directive with `encrypt_hls` off**: no key/IV generated, `{key}`/`{iv}` absent from the spec — must not emit empty tokens. (Task 2)
- **Removal / flip transcode→other**: `DELETE /monitor/<id>` (release the supervisor), not just `/streams/`. (Task 4)

---

### Task 1: Parse the transcode directive's spec

**Files:** Modify `internal/clusteragent/streamregister.go`; Test `internal/clusteragent/streamregister_test.go`; fixture `internal/clusteragent/testdata/runtime_directive_transcode.json`

**Interfaces:**
- Consumes: the record envelope (4a `parseRuntimeDirective`).
- Produces: `runtimeDirective` gains `Spec json.RawMessage \`json:"spec"\``; a `transcodeSpec() (json.RawMessage, bool)` accessor returning the raw spec when `Kind=="transcode"` and a spec is present.

- [ ] **Step 1: Create the fixture** — a record envelope whose `data.runtime` is `{kind:"transcode", spec:{sources:[{label,cmd:"{ffmpeg_cpu} -i … -f tee …unix:{ingest_sock}… {streams}<id>_.m3u8"}], policy:{…}, health:{…}, pid_path:"{streams}<id>_.pid", errors_path:"{streams}<id>.errors", log_path:"{logs}stream_log.log", server_id:"{server_id}", adopt_match:"{streams}<id>_.m3u8", enc:{key:"{key}", iv:"{iv}"}}}`.
- [ ] **Step 2: Write the failing test** — `parseRuntimeDirective(fixture)` → `Kind=="transcode"`; `transcodeSpec()` returns the raw spec containing `{ingest_sock}`.
- [ ] **Step 3: Run it, see it fail.**
- [ ] **Step 4: Implement** — add the `Spec` field + `transcodeSpec()`.
- [ ] **Step 5: Run it, see it pass. Commit** (`feat(agent): parse the transcode runtime directive`).

### Task 2: Resolve the placeholders

**Files:** Modify `internal/clusteragent/streamregister.go`; Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: the raw spec (Task 1); an `agentNode` value carrying the node's resolved paths/bins (`streams`, `logs`, `serverID`, `ffmpegCpu`, `ffmpegGpu`, `ffprobe`, `binary`).
- Produces: `func resolveSpec(spec json.RawMessage, n agentNode, ingestSock, keyHex, ivHex string) ([]byte, error)` — substitutes every token and returns the resolved JSON; errors if any `{<word>}` remains.

- [ ] **Step 1: Write the failing tests** — with a node + socket + key/IV, `resolveSpec` replaces `{ingest_sock}`/`{streams}`/`{ffmpeg_cpu}`/`{key}`/… and the result contains none of them; a spec with an unknown token (`{bogus}`) left after substitution → error; a spec with no `enc` and empty key/IV → still valid (no `{key}` present to resolve).
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Implement** with a `strings.NewReplacer` over the token set, then a regexp check `\{[a-z_]+\}` for any residual token → error. (Substituting the raw JSON string is safe: tokens are distinctive and appear only where 4b-i put them.)
- [ ] **Step 4: Run them, see them pass. Commit** (`feat(agent): resolve transcode spec placeholders`).

### Task 3: Hand the resolved spec to the supervisor

**Files:** Modify `internal/clusteragent/streamregister.go`; Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `resolveSpec` (Task 2); `fanoutClient(a.FanoutCtl)` (4a).
- Produces: `func (a *Agent) superviseStream(ctx, id int64, spec json.RawMessage) error` — generate key/IV if the spec carries `enc`, `PUT /ingest/<id>` `{key,iv}` → the ingest socket, `resolveSpec`, then `PUT /monitor/<id>` with the resolved spec; `func (a *Agent) unsuperviseStream(ctx, id int64) error` → `DELETE /monitor/<id>`. 503 → `errFanoutTransient` (reuse 4a's).

- [ ] **Step 1: Write the failing tests** against the 4a fanout stub extended to answer `PUT /ingest/<id>` with `{"sock":"/i/<id>.sock"}` and record the `/monitor/<id>` body: `superviseStream` registers ingest then PUTs `/monitor/<id>` with a body containing no `{...}` tokens and the stub's socket path; a spec whose node config is missing a value (leaves a token) → error, no `/monitor/` PUT; `unsuperviseStream` → `DELETE /monitor/<id>`.
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Implement.** Key/IV: when the spec has `enc`, generate 16 random bytes each, hex-encode, pass to `/ingest/` and as the `keyHex`/`ivHex` to `resolveSpec`; else pass "". Parse the `/ingest/` response for the socket. Fail closed on a residual token (never PUT `/monitor/`). Errors by id only.
- [ ] **Step 4: Run them, see them pass; `-race`. Commit** (`feat(agent): supervise a transcode stream from its directive`).

### Task 4: Route transcode in apply + the node config

**Files:** Modify `internal/clusteragent/streamregister.go` (`seedFanoutRecord`), `internal/clusteragent/agent.go` (node config fields), `internal/agentcmd/agentcmd.go` (flags); Test `internal/clusteragent/streamregister_test.go`

**Interfaces:**
- Consumes: `transcodeSpec`/`superviseStream`/`unsuperviseStream`; `Agent.SeedFanout`.
- Produces: `seedFanoutRecord` routes by kind — `proxy` → the 4a puller register; `transcode` → `superviseStream`; anything else → clear both (`unregisterStream` + `unsuperviseStream`). `Agent` gains `node agentNode` (streams/logs/serverID/ffmpegGpu/ffprobe/binary), fed by new `agentcmd` flags beside `-ffmpeg` (default off/empty → transcode directives skipped).

- [ ] **Step 1: Write the failing tests** — a stored `transcode` record under `SeedFanout` → `/ingest/` + `/monitor/` PUT; a `proxy` record → `/streams/` PUT (4a, unchanged); a record that flips transcode→other → `DELETE /monitor/`; `SeedFanout` off → no calls.
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Add the flags/config** (`-streams`, `-logs`, `-server-id`, `-ffmpeg-gpu`, `-ffprobe`, `-fanout-binary`), wire to `Agent.node`; route transcode in `seedFanoutRecord`; a transcode directive with an incomplete `node` is skipped + logged.
- [ ] **Step 4: Run them; then `GOWORK=off go test -race ./internal/clusteragent/` and `go build ./...`. Commit** (`feat(agent): apply transcode directives (opt-in)`).

## Self-Review

- **Spec coverage:** parse (T1), resolve (T2), ingest+supervise (T3), apply-routing+config (T4). The on-MAIN portable Spec is 4b-i; the serve path for a supervised transcode is 4b-iii — both separate.
- **Review Focus:** residual-token fail-closed (T2/T3), key/IV consistency (T3), ingest/fanout-down non-fatal (T3), encrypt-off no empty tokens (T2), removal DELETE /monitor (T4) — each has a named test.
- **Type consistency:** `transcodeSpec`/`resolveSpec`/`superviseStream`/`unsuperviseStream`/`agentNode` consistent; `runtimeDirective` extends 4a's with `Spec`; token set identical to 4b-i.
- **Contract:** the transcode fixture's token spellings must equal 4b-i's output; the shared design doc fixes them.
