# LB Phase 4b-i — MAIN portable transcode Spec Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** MAIN emits, in each transcode stream's replica record, a *portable* `supervisor.Spec` (node-local values as placeholders) so a serve-only LB can later run the encoder from it with no PHP.

**Architecture:** Add a side-effect-free **portable mode** to `StreamProcess::buildSupervisorSpec` that composes the same ffmpeg command as the on-node path but (1) passes placeholder tokens for the values `buildLive` takes as parameters (ingest socket, ffmpeg binaries, ffprobe), (2) skips the node-local side effects (registerIngest, writeStreamKeyIv), (3) post-processes the node-local path *constants* (`STREAMS_PATH`, `LOGS_TMP_PATH`, `SERVER_ID`) in the returned Spec to tokens, and (4) composes for the target LB's ffmpeg capabilities. `buildLive` itself is **not** modified. `StreamRuntimeDirective` then emits `kind:"transcode"` carrying that portable Spec.

**Tech Stack:** PHP 8.1 in `XC_VM` (`src/Domain/Stream/StreamProcess.php`, `src/Core/Cluster/StreamRuntimeDirective.php`). No Go in this plan (the LB agent that applies the Spec is Phase 4b-ii).

**Spec:** `docs/superpowers/specs/2026-10-17-lb-phase4b-transcode-directive-design.md` (and the parent `2026-10-17-lb-phase4-5-lifecycle-design.md`, D3).

## Global Constraints

- MAIN is the single composer (D3); `buildLive` is **not** edited — portable mode wraps it.
- **The on-node Spec must stay byte-for-byte unchanged.** Portable mode is a new, explicitly-selected path; the default path's output is pinned by a characterization test (Task 1).
- Portable mode is **side-effect-free**: it must not `registerIngest`, `writeStreamKeyIv`, or touch the local fanout/filesystem — those are the LB agent's at apply time (4b-ii).
- Closed placeholder set, tokens exactly: `{ingest_sock}`, `{ffmpeg_cpu}`, `{ffmpeg_gpu}`, `{ffprobe}`, `{binary}`, `{streams}`, `{logs}`, `{server_id}`, `{key}`, `{iv}`.
- `make gates` / phpstan clean; follow the repo's PHP style (CI runs phpcs/Slevomat — the local `make cs` cannot).
- Scope (design D-b): only direct (non-loopback) transcode streams `buildSupervisorSpec` can already supervise. `delay_minutes`, `created_live`, platform-resolver sources (it returns null for these) stay out — emit no transcode directive for them.

## Review Focus

- **On-node output regression**: portable mode must not change the Spec the on-node path produces — the characterization test is the guard (Task 1/2).
- **A side effect leaking into portable mode**: registerIngest / writeStreamKeyIv / file writes must not run when composing a record on MAIN (Task 2 test asserts none fire).
- **A non-supervisable kind** (delay/created_live/resolver, or a stream whose Spec is null): emit `kind:"other"`, never a half-built transcode directive (Task 3).
- **A MAIN path literal leaking into the portable Spec** (an unreplaced `STREAMS_PATH`/`LOGS_TMP_PATH`): the portable Spec must contain the token, never MAIN's absolute path (Task 2 test scans for the path prefix).
- **GPU command on a CPU-only LB**: composing for the wrong capability yields an unrunnable command — compose from the target LB's feature list (Task 4).

---

### Task 1: Characterize the on-node Spec + pin the node-local literals

**Files:**
- Test: `tests/Unit/BuildSupervisorSpecCharacterizationTest.php`

**Interfaces:**
- Produces: a golden-master of `StreamProcess::buildSupervisorSpec($id)` for a representative CPU transcode stream, and the enumerated set of node-local substrings its output contains (`STREAMS_PATH`, `LOGS_TMP_PATH`, `SERVER_ID`, the ffmpeg/ffprobe bins, the ingest socket) — the inputs portable mode must tokenize.

- [ ] **Step 1: Seed a representative transcode stream** (DB fixture: a `streams` row with `direct_proxy=0`, a real `stream_source`, a transcode profile; `streams_servers` for the node) and capture `buildSupervisorSpec($id)` to a golden file; assert the captured Spec's `sources[0].cmd` contains `STREAMS_PATH`, the ffmpeg bin, and the ingest socket, and `pid_path`/`errors_path`/`adopt_match` start with `STREAMS_PATH`, `server_id === SERVER_ID`.
- [ ] **Step 2: Run it, see it pass** (`php tests/phpunit.phar -c tests/phpunit.xml.dist --filter BuildSupervisorSpecCharacterization`) — this is the baseline; it must stay green through Task 2.
- [ ] **Step 3: Record the enumerated node-local substrings** in the test as named constants, so Task 2's placeholder mapping is exhaustive. Commit (`test(stream): characterize buildSupervisorSpec's on-node output`).

### Task 2: Portable mode on `buildSupervisorSpec`

**Files:**
- Modify: `src/Domain/Stream/StreamProcess.php` (`buildSupervisorSpec`)
- Test: `tests/Unit/BuildSupervisorSpecPortableTest.php`

**Interfaces:**
- Consumes: the node-local substring set (Task 1).
- Produces: `buildSupervisorSpec(int $rStreamID, ?array $rPortable = null): ?array`. When `$rPortable` is null the behaviour and output are **exactly** today's (on-node). When `$rPortable` is an array (`['ffmpeg_features' => [...]]`, the target LB's capabilities), it returns the portable Spec: `{ingest_sock}`/`{ffmpeg_cpu}`/`{ffmpeg_gpu}`/`{ffprobe}`/`{binary}` passed into the composition instead of resolved values, no `registerIngest`/`writeStreamKeyIv` side effects, `supportsRemux`/`supportsLive` read from `$rPortable['ffmpeg_features']` not the local daemon, and `STREAMS_PATH`/`LOGS_TMP_PATH`/`SERVER_ID` replaced by `{streams}`/`{logs}`/`{server_id}` in the returned Spec. `enc` carries `{key}`/`{iv}` when `encrypt_hls`.

- [ ] **Step 1: Write the failing tests.**
  - `testPortableSpecCarriesTokensNotNodePaths`: with a `$rPortable` arg, the returned Spec's `cmd` contains `{ingest_sock}`, `{ffmpeg_cpu}`, `{streams}` and contains **no** `STREAMS_PATH`/`LOGS_TMP_PATH` absolute prefix; `pid_path === '{streams}' . $id . '_.pid'`, `server_id === '{server_id}'`.
  - `testPortableModeHasNoSideEffects`: composing portable does not create the key/IV files and does not call the fanout (assert via a fake `FanoutClient`/no socket + the files' absence).
  - `testNonPortableOutputUnchanged`: `buildSupervisorSpec($id)` (no arg) equals the Task 1 golden master.
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Implement portable mode.** Branch at the side-effect points: when `$rPortable`, set `$rIngestSock = '{ingest_sock}'` (skip `registerIngest`), `[$rEncKey,$rEncIV] = ['{key}','{iv}']` when `encrypt_hls` (skip `writeStreamKeyIv`/`streamKey`), `$rFFMPEGCpu/$rFFMPEGGpu/$rFFProbeBin = '{ffmpeg_cpu}'/'{ffmpeg_gpu}'/'{ffprobe}'`, `FanoutClient::binaryPath()` → `'{binary}'`, and `supportsRemux()/supportsLive()` from `$rPortable['ffmpeg_features']`. Build the Spec array with `'{streams}'`/`'{logs}'`/`'{server_id}'` in place of the constants. `buildLive` is called unchanged (it emits the placeholder params verbatim). Do not post-process by string-replacing the composed cmd unless a node path reaches it other than through these inputs — Task 1's enumeration says whether any does; if one does, pass it as a token the same way.
- [ ] **Step 4: Run the tests, see them pass; re-run Task 1's characterization (still green).**
- [ ] **Step 5: `make gates` + phpstan; commit** (`feat(stream): portable (placeholder) mode for buildSupervisorSpec`).

### Task 3: Emit the transcode directive

**Files:**
- Modify: `src/Core/Cluster/StreamRuntimeDirective.php` (`build`)
- Test: `tests/Unit/StreamRuntimeDirectiveTest.php` (extend)

**Interfaces:**
- Consumes: `buildSupervisorSpec($id, $rPortable)` (Task 2).
- Produces: `StreamRuntimeDirective::build` now returns `kind:"transcode"` with `spec` (the portable Spec) for a supervisable transcode stream, `kind:"proxy"` with `source` for a direct-proxy restream (4a, unchanged), `kind:"other"` otherwise (incl. a stream whose portable Spec is null — delay/created_live/resolver).

- [ ] **Step 1: Write the failing tests.** A `direct_proxy=0` transcode stream whose portable Spec is non-null → `kind==="transcode"`, `spec` present (its `cmd` carries `{ingest_sock}`); a stream whose `buildSupervisorSpec` returns null → `kind==="other"`, no spec; a `direct_proxy=1` stream → still `kind==="proxy"` (4a).
- [ ] **Step 2: Run them, see them fail.**
- [ ] **Step 3: Implement.** In `build`, after the `direct_proxy` check: for a non-proxy stream, call `buildSupervisorSpec($id, $portable)`; non-null → `['kind'=>'transcode','spec'=>$spec]`; null → `['kind'=>'other','spec'=>null]`. Thread the target node's `$portable` capabilities (Task 4). Keep the proxy branch first and unchanged.
- [ ] **Step 4: Run tests, see them pass.**
- [ ] **Step 5: Wire into `StreamRecords::data()`** — it already calls `StreamRuntimeDirective::build($rStream, $args)`; pass the node's capabilities through (Task 4). Commit (`feat(cluster): emit the transcode runtime directive`).

### Task 4: The target LB's ffmpeg capabilities reach the composition

**Files:**
- Modify: `src/Core/Cluster/StreamRecords.php` (`data`) and/or the record-build path; `StreamRuntimeDirective::build`
- Test: `tests/Unit/StreamRuntimeDirectiveTest.php` (extend)

**Interfaces:**
- Produces: the target server's ffmpeg feature list passed to `buildSupervisorSpec`'s `$rPortable`, so a GPU command is only composed for a GPU-capable LB.

- [ ] **Step 1: Pin where the LB's ffmpeg capabilities live on MAIN.** Read how the agent's hello feature list is stored (`cluster_nodes`/`servers` — `grep` the enrolment/hello handler); document the column/shape. This is the one investigation this plan carries.
- [ ] **Step 2: Write the failing test.** A node whose capabilities lack GPU → the composed transcode `spec` uses the CPU path (no `{ffmpeg_gpu}`); a GPU-capable node → the GPU path is allowed.
- [ ] **Step 3: Run it, see it fail.**
- [ ] **Step 4: Implement** — read the target server's capabilities in the record build and pass them as `$rPortable['ffmpeg_features']`. Default (unknown/absent) to CPU-only, never GPU.
- [ ] **Step 5: `make gates` + phpstan + the StreamRuntimeDirective suite; commit** (`feat(cluster): compose the transcode directive for the target LB's capabilities`).

## Self-Review

- **Spec coverage:** portable Spec (Tasks 1-2), emit transcode directive (Task 3), per-LB capability (Task 4); on-node-unchanged guard (Task 1). 4b-ii (agent apply) and 4b-iii (serve path) are separate plans, noted in the design.
- **Review Focus:** on-node regression (Task 1/2 characterization), no side effects in portable mode (Task 2), non-supervisable → other (Task 3), no path leak (Task 2), GPU-on-CPU (Task 4) — each has a named test.
- **Type consistency:** `buildSupervisorSpec($id, ?$rPortable)`, the token set, and `kind` values (`proxy`/`transcode`/`other`) are used consistently; `StreamRuntimeDirective::build` return shape extends 4a's.
- **Open item (Task 4 step 1):** the MAIN storage of the LB's ffmpeg capabilities is the one thing to pin by reading the hello/enrolment path; flagged as the plan's single investigation, not a silent gap.
