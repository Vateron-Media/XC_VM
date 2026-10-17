# LB Phase 4b — transcode directive (design)

Extends Phase 4a (the restream directive) in the single-binary-LB roadmap
(`2026-10-17-lb-phase4-5-lifecycle-design.md`, decision D3). 4a let a serve-only
LB run a **restream** from the replica with no PHP. 4b does the same for a
**transcode** — a stream the node encodes with ffmpeg — which is the gating
piece for an encoding/transcoding serve-only LB and for dropping php-fpm.

## Intent / why

A serve-only LB has no php-fpm. Today a node that transcodes runs
`StreamProcess::superviseStream()` → `buildSupervisorSpec()` → `PUT /monitor/<id>`
to hand the fanout supervisor an ffmpeg command. That PHP must not exist on the
LB. 4a's directive mechanism (MAIN composes, the agent applies) is the model —
but transcode is harder, for a concrete reason below.

## Key finding: `buildSupervisorSpec` is node-local-stateful, not a pure composer

Unlike `buildSource` (4a, pure field-mapping), `buildSupervisorSpec`
(`src/Domain/Stream/StreamProcess.php`) does node-local work **at supervise time**,
on the node that will run the encoder:

- `FanoutClient::registerIngest($id, key, iv)` — registers an **ingest socket
  with the local fanout** (the encoder's `-f tee unix:<sock>` output); returns a
  node-local socket path.
- `writeStreamKeyIv($id)` — writes the stream's HLS key/IV **files on that node**.
- bakes the node's ffmpeg binary, `STREAMS_PATH`, pid/errors/log paths and
  `server_id` into the command (`buildLive`).

So **MAIN cannot pre-compose a transcode Spec for a remote LB**: the ingest
socket is the LB's, created at supervise-time by the LB's own fanout, and the
key/IV are the LB's files. The clean "MAIN composes the whole Spec, the LB
applies it verbatim" model that worked for restream does not hold.

## Design (decided)

MAIN composes the ffmpeg **logic**; the LB resolves its own **node state**. The
`runtime` directive gains `kind:"transcode"` carrying a composed `spec` whose
node-local values are **placeholders**; the Go agent resolves them against its
own node — including one *dynamic* placeholder it fills by talking to its local
fanout.

**MAIN side.** Produce a *portable* supervisor Spec: `buildSupervisorSpec` /
`buildLive` emit the node-local inputs as a closed set of placeholders instead
of MAIN's own values:
- `{ffmpeg_bin}` / `{ffmpeg_gpu}` / `{ffprobe}` — the node binaries
- `{streams}` — `STREAMS_PATH` (and the `{streams}/<id>_.pid|.errors|.m3u8|.key`
  paths derived from it)
- `{logs}` — the stream log path
- `{server_id}`
- `{ingest_sock}` — the encoder's tee target (dynamic; see LB side)
- `{key}` / `{iv}` — the HLS key/IV (when `encrypt_hls`)
The Spec's `sources[].cmd`, `policy`, `health`, and the `pid_path`/`errors_path`/
`log_path`/`adopt_match` fields are MAIN's composition with those tokens in them.
MAIN composes for the **target LB's capabilities** (GPU vs CPU is a `buildLive`
branch): it reads the LB's ffmpeg-feature list (exchanged at the agent's hello)
and composes accordingly — never for MAIN's own hardware.

**LB side (Go agent).** On a `kind:"transcode"` directive, the agent does
`buildSupervisorSpec`'s *node-local steps only*, then applies:
1. register an ingest socket with its **own** fanout (the Go `Manager.RegisterIngest`
   / `/ingest/` control endpoint already exists) → the `{ingest_sock}` value;
2. write the stream's key/IV files locally (when `encrypt_hls`);
3. substitute every placeholder from its own config + (1)/(2);
4. `PUT /monitor/<id>` with the resolved `supervisor.Spec`; re-apply on change,
   `DELETE /monitor/<id>` on removal or a flip away from transcode.
The supervisor then runs/monitors/adopts the encoder exactly as today.

## Decisions (resolved — "choose best")

- **D-a: the composition/resolution split above.** MAIN emits a placeholder
  command template; the LB agent resolves node-local state. *Not* a Go port of
  `buildSupervisorSpec`/`buildLive` (D3: one composer, on MAIN).
- **D-b: scope.** `buildSupervisorSpec` already returns null (not Spec-
  supervisable) for `delay_minutes` streams, `created_live` channels, and
  platform-resolver source URLs (`StreamUtils::needsResolver`). With no PHP
  fallback on a serve-only LB, those **cannot run on a serve-only LB** — MAIN
  keeps them on itself / non-serve-only nodes. Out of scope here.
- **D-c: per-LB-capability composition.** MAIN composes using the target LB's
  ffmpeg-feature list (hello), so a GPU command is only emitted for a GPU LB.
- **D-d: loopback transcode** (`parent_id > 0`, source = a relay URL via the
  DataPlane) rides the same Spec path but needs the relay-URL/ticket machinery;
  it is **Phase 5's** loopback slice, not 4b. 4b covers the direct (non-loopback)
  transcode.

## The directive contract (extends 4a)

`runtime` becomes `{kind: "proxy"|"transcode"|"other", source?, spec?}`:
- `kind:"proxy"` → `source` (4a, restream).
- `kind:"transcode"` → `spec`: the portable `supervisor.Spec`
  (`sources[].cmd` with placeholders, `policy`, `health`, `pid_path`,
  `errors_path`, `log_path`, `server_id`, `adopt_match`, `enc:{key,iv}?`).
- `kind:"other"` → neither (unsupported-on-LB kinds, or a stream MAIN keeps).
The Go agent ignores a `kind` it does not handle, so 4b is additive over 4a.

## Non-goals / out of scope

- Loopback transcode (Phase 5), and the delay/created_live/platform-resolver
  kinds (D-b).
- Porting any composition (`buildLive`) to Go.
- Changing the on-node PHP supervise path for non-serve-only nodes — it stays;
  the portable-Spec mode is a new path beside it.

## Constraints

- `XC_VM_Fanout` stdlib-only; `GOWORK=off`.
- MAIN stays the single composer (D3). The daemon resolves placeholders, never
  composes a command.
- The on-node PHP path (`buildSupervisorSpec` as it runs today) must stay
  byte-for-byte unchanged; the placeholder mode is opt-in/parameterised beside it.

## Central risk

Making the Spec portable means parameterising `buildSupervisorSpec`/`buildLive`'s
node-local inputs (ffmpeg bin, `STREAMS_PATH`, ingest socket, key/IV, server_id)
so they can be emitted as placeholders — in a ~3 k-line, battle-tested composer.
The plan must isolate this: a placeholder-emitting mode selected explicitly, with
a test that the **non-placeholder** (on-node) output is unchanged, so no existing
transcode regresses. This is the riskiest change in the LB roadmap and the plan's
first, most carefully-tested task.

## What's left to the builder (settle in the plan)

- The exact placeholder token syntax and the closed set (enumerated against
  `buildSupervisorSpec`/`buildLive`).
- How MAIN selects placeholder mode (a flag/param on `buildSupervisorSpec`, or a
  dedicated `buildPortableSupervisorSpec`) without duplicating the composer.
- Where in the agent the transcode branch lives (beside the restream apply in
  `seedFanoutRecord`/`reconcileFanout`), and the ingest-register + key/IV-write
  Go code (mirror `FanoutClient::registerIngest`/`writeStreamKeyIv`).
- How the LB's ffmpeg capabilities reach MAIN's composition (the hello feature
  list → the record build).
- The `adopt_match` value in placeholder form (it names the node's pid/paths).

## Size

Architectural, cross-repo, the largest LB slice. One design doc (this), one plan.
Likely phased within itself: (4b-i) MAIN portable Spec + placeholders; (4b-ii)
the LB agent transcode apply (ingest/key-IV/substitute/`/monitor/`); (4b-iii)
the serve path for a supervised transcode on a serve-only LB.
