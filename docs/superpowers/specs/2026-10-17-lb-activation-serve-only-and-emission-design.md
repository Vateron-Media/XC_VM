# LB activation — serve_only provisioning + portable-Spec emission (design)

The MAIN→agent→gateway LB-transcode **serve path** is built and merged
(XC_VM #436/#437, Fanout #85/#86/#87). It is **inert**: nothing marks a node
`serve_only`, and MAIN never composes the portable supervisor Spec it is
supposed to emit. This design covers the two pieces that turn the merged serve
path on:

- **Part A — serve_only provisioning.** How a load-balancer node becomes
  `serve_only=1` and launches `xc_node` with the flags the serve path needs.
  Deployment work; needs a real LB to build and validate.
- **Part B — portable-Spec emission.** How MAIN composes the portable Spec
  into the replica `stream` records it signs — page-bound, snapshot-fed,
  `serve_only`-gated. DB-only and CI-unit-testable; inert in production until
  a `serve_only` node exists.

It sits **before** the Phase 4–5 lifecycle spec
(`2026-10-17-lb-phase4-5-lifecycle-design.md`): that doc assumes a serve-only
LB already exists and moves its stream *lifecycle* into Go; this doc is what
first makes a node serve-only and makes MAIN direct it. It expands strategy
step 4 of the single-binary roadmap (the gate before `LbInstallFlow` drops
nginx + php-fpm).

## What is already in place (verified, do not rebuild)

- Column `servers.serve_only TINYINT(1) NOT NULL DEFAULT 0` — migration
  `src/migrations/database/up/098_add_server_serve_only.sql`; in
  `ReplicaSections::NODE_FIELDS` (`src/Core/Cluster/ReplicaSections.php:127`,
  "survives mode-2 materialization"); documented in ADR 0004.
- `GatewayPolicy` emits it into `policy.json` as `serve_only`
  (`src/Core/Gateway/GatewayPolicy.php:153`); the Go gateway/agent honour
  `Policy.ServeOnly` (`internal/gateway/policy.go:52`, `live.go`, `server.go`,
  `internal/clusteragent/*`). Every node reads it as `0` today because nothing
  writes `1`.
- The single binary exists: `cmd/xc_node/main.go` dispatches to fanout or
  agent by basename (`xc_fanout`/`xc_agent`) or leading subcommand.
- The agent's serve-only switch is `xc_agent run -seed-fanout` ("register
  assigned restreams on the fanout from the replica; replaces PHP seedLocal");
  the fanout's no-nginx switch is `xc_fanout -front :443` ("single-binary LB
  front, replaces nginx; empty = nginx serves").
- `StreamProcess::buildSupervisorSpec($id, ?$rPortable)` composes the portable
  Spec (tokens, `encrypt` bool, no key/iv) and is side-effect-free in portable
  mode; `StreamRuntimeDirective::build($row, $argsByKey, ?$spec)` is the pure
  classifier that turns it into `runtime = {kind, source?, spec?}`.

## Part A — serve_only provisioning

### Key finding: serve_only is an explicit, independent axis

`serve_only` is **not** derivable from any existing flag. Node "mode" today is
the credential axis (`lb_new_node_mode` ∈ {legacy, api}; api == mode 2),
recorded in `cluster_nodes`, not on `servers`. `ServerInstallCommand` knows
only `$rType` (1 = proxy, 2 = LB). There is **no single-binary / no-nginx
notion anywhere in the PHP install flow**. So activation must introduce a new
"single-binary serve-only LB" node kind and set `serve_only=1` explicitly for
it. (Confirmed by the column's own "survives mode-2 materialization" comment
and the serve-not-defer SDD ruling: "LbInstallFlow sets it when it models the
serve-only node type.")

### The serve-only node kind is one decision with three effects

Installing a node as single-binary serve-only must do three things together —
they are one node kind, not three independent toggles:

1. **Set `servers.serve_only = 1`.** Natural write-site: the CLI's final
   success UPDATE, `ServerInstallCommand.php:298` (`SET status=1,
   http_broadcast_port=?, https_broadcast_port=?, total_services=?`), extended
   to also set `serve_only` when the serve-only kind was chosen. A new
   indicator must enter `server:install` (today only `$rType`); see D-A1.
2. **Skip the nginx + php-fpm install.** The serve-only path must NOT run:
   `installDistributionBinaries` loadbalancer-bundle fetch of PHP/nginx/
   nginx_rtmp (`LbInstallFlow.php:554`, `cp`+chmod at :667-669);
   `configureRuntime` nginx confs + php-fpm pools
   (`LbInstallFlow.php:451`, `PhpFpmPools::files()` at :464-468); and the
   nginx/php-fpm portions of `installSystemdService`
   (`ServerInstallCommand.php:359`, which writes `xc_vm.service` →
   `/home/xc_vm/service start`). It must still install `xc_node` (the fanout
   binary already shipped) and the agent.
3. **Launch `xc_node` with the serve-only flags** (see next section).

### Launch wiring — the run.sh gate (the subtle part)

The launchers are **static shipped files**, not generated per install:
`src/bin/xc_agent/run.sh` launches `xc_agent run -state …` and passes **none**
of the serve-only flags; `src/bin/xc_fanout/run.sh` passes **no** `-front*`.
`RootSignalsCronJob` (`src/Cli/CronJobs/RootSignalsCronJob.php:947-958`)
relaunches both on keepalive. So the flags cannot be set once at install — they
must be carried by something the run.sh scripts read on **every** relaunch.

**D-A2 — gate the flags on a config.enc marker the run.sh scripts read.**
`LbInstallFlow::configPackParams()` already writes `is_lb => 1` into the node's
`config.enc` (`:418`). Add a serve-only marker there (e.g. `serve_only => 1`),
and have both run.sh scripts read it and conditionally append the serve-only
flags. This survives keepalive relaunches (the scripts re-read config.enc each
start) with no generated-file drift. Alternative rejected: writing the flags
into a generated unit file — keepalive relaunches via run.sh, not the unit, so
it would be bypassed.

### Fail-closed flag requirements (hard — the 4a-class dependency)

On `xc_fanout`: `-front :443` (+ `-front-cert`, `-front-key`, optional
`-front-images`, `-front-realip`). Empty `-front` means nginx serves — wrong
for a node with no nginx.

On `xc_agent run`: `-seed-fanout` (the serve-only switch) **and** the token
resolvers, or every transcode directive the node receives **fails closed**
(exactly the class of the 4a `-ffmpeg` dependency):

- `-server-id <id>` — **mandatory**; resolves `{server_id}`. Without it the
  agent cannot apply any transcode Spec.
- `-ffmpeg <abs>` — the node's real ffmpeg path (absolute, not `"ffmpeg"`);
  resolves `{ffmpeg_cpu}`. Also stamped into a seeded restream's pull config.
- `-fanout-binary <abs>` — resolves `{binary}` for a transcode directive.
- `-logs <dir>` — resolves `{logs}` (default `/home/xc_vm/logs/tmp/`).
- `-ffmpeg-gpu`, `-ffprobe`, `-streams` — resolve `{ffmpeg_gpu}`,
  `{ffprobe}`, `{streams}` when a Spec uses them.

`LbInstallFlow` knows every one of these node-local values at provision time;
it must thread them into the run.sh gate alongside the marker.

## Part B — portable-Spec emission

### Key finding: the directive is inside the ETag

`runtime` is part of the canonical record data MAIN signs, so it **feeds the
record's ETag** (`ReplicaBuilder::etag`, `ReplicaBuilder.php:705`). Only
`tickets` is excluded (set null in `StreamRecords::data()`, filled after the
ETag in `StreamReplica::page()`). Therefore a Spec placed in `runtime.spec`
**cannot** be injected late like tickets — it must exist at/before the
`ReplicaBuilder::etag()` call (`StreamReplica.php:204`).

### Why the reverted emission was wrong

`StreamReplica::delta()`/`resync()` call `StreamRecords::data()` for the
**whole** held batch (up to ~1000 streams, `StreamReplica.php:133/:170`); the
200-record / 4 MiB cap is applied **later**, inside `StreamReplica::page()`
(`:209/:234`). Composing the Spec inside `data()` paid for all ~1000 streams,
then `page()` discarded all but ≤200. (Plus the reverted version also had an
undefined-variable `?:` bug and no `serve_only` invalidation.)

### Design decisions (resolved)

**D-B1 — compose the Spec inside `StreamReplica::page()`, per surviving
record, before its ETag.** `page()` is where the cap is known. Compose the
directive for a record only once it is within `MAX_RECORDS`, at/before
`ReplicaBuilder::etag()` (`:204`), feed it as `build()`'s 3rd arg, and seal.
Only the ≤200 survivors pay for a Spec. (`StreamRecords::data()` keeps emitting
the classifier-only directive with no Spec for every other caller —
`MainDataPlane`, `TicketService`, the node-side shadow — exactly as today.)

**D-B2 — gate composition on the target node's `serve_only`, read once per
page.** `page()` builds one specific server's replica, so it knows the target
`serverID`. Read `SELECT serve_only FROM servers WHERE id = ?` **once** per
page. If `0` → no Spec, directive stays `proxy`/`other`, emission is
byte-identical to today (this is why Part B is safely inert until Part A sets a
node serve-only). If `1` → compose a Spec per survivor.

**D-B3 — feed `buildSupervisorSpec` from a dedicated batched snapshot, via an
exception-safe `StreamSource::useLoader` bracket.** `buildSupervisorSpec` in
portable mode reads only through `StreamSource::streamRow/serverRow/arguments`
(never MAIN's DB directly), and those need more than `data()` holds:
`streamRow` is the full `streams ⋈ streams_types(live=1) ⋈ profiles` row
(`type_key`, profile columns, every `streams` column), whereas `data()` reads
only the `STREAM_FIELDS` subset. So the snapshot is **one batched read each**,
over the held set, of (a) that full stream join, (b) this server's
`streams_servers` rows, (c) the options⋈arguments join — not a reuse of
`data()`'s rows (a correction to the first draft of this decision). Install it
as `StreamSource::useLoader($fn)` around the `page()` loop, where
`$fn($kind,$id,$opts)` answers `stream`/`server`/`arguments` from those three
maps, then `useLoader(null)` in a `finally`. Zero per-stream queries — fixes
the N×3 queries and the TOCTOU re-read (the snapshot is a single consistent
read). The loader is process-global static, so the reset must be
exception-safe (pattern already used in `BuildSupervisorSpecPortableTest`).
Call `buildSupervisorSpec($id, ['features' => $features, 'server_id' =>
$serverID])`; `features` from the node's capabilities (default `[]`). Note: the
frequent delta path composes only for the ≤`MAX_RECORDS` survivors; a resync
must compose for every examined held stream (the ETag depends on the Spec),
which is bounded (`MAX_EXAMINE`) and runs every ~5 min — acceptable, with
per-ETag caching a later optimization if measured.

**D-B4 — ETag divergence is automatic; a `serve_only` *flip* still needs a
version bump.** Because the Spec is present only when the target is
`serve_only`, a serve-only node's record ETag already differs from a normal
node's for the same stream — no need to inject `serve_only` into the record
itself. But the per-(server, stream) version (`cluster_stream_ver.ver`) is
bumped only by stream-change events, and **no event fires when
`servers.serve_only` flips**, so a flip would not surface through `delta` (only
the ~5-min `resync` safety-net would catch the ETag change). At *install*,
streams are assigned after the row exists and assignment bumps via
`StreamsChangedEvent`, so the fresh node is covered. The gap is **toggling
`serve_only` on a node that already holds streams**: dispatch a bump of
`StreamRecords::held($serverID)` when `serve_only` changes (the
`ServerService` edit path / the UPDATE site). Required for correctness on
toggle; lower urgency than the install path.

## Approach & phasing

**Part B first, then Part A.** B is buildable and CI-unit-testable here and,
behind D-B2's `serve_only` read, is a provable no-op until a serve-only node
exists — so it can land without hardware and without risk. A needs a real LB
to build and validate, and once it marks the first node serve-only the merged
serve path plus B light up together, giving B its live validation.

- **Phase B1 — page-bound, snapshot-fed emission.** Implement D-B1/B2/B3 in
  `StreamReplica::page()`. Unit tests (no real LB): a `serve_only=1` server +
  a transcodable stream → record `runtime.kind == "transcode"` with a Spec
  carrying only placeholder tokens; a `serve_only=0` server → `proxy`/`other`,
  no Spec, byte-identical to pre-change; the `useLoader` bracket resets on an
  exception; a 300-stream batch composes ≤200 Specs (cap honoured). Reuse the
  `StreamSource::useLoader` + serve_only fixture patterns from
  `BuildSupervisorSpecPortableTest` and `GatewayPolicyTest`.
- **Phase B2 — flip invalidation.** Implement D-B4's bump-on-toggle with a
  test: flipping `serve_only` on a server holding streams bumps exactly that
  server's held streams' versions.
- **Phase A1 — the serve-only node kind (needs a real LB).** Introduce the
  indicator (D-A1), set `serve_only=1` (effect 1), skip nginx/php-fpm
  (effect 2), thread the run.sh marker + flags (D-A2, effect 3), install
  `xc_node` + agent. Validate end-to-end on a real LB: the node comes up with
  a Go front and no php-fpm, receives a transcode directive, applies it, and
  serves.

After Part A, `LbInstallFlow` has modelled a node that runs no PHP for the
serve path — the prerequisite the Phase 4–5 lifecycle spec builds on.

## Open question for provisioning (D-A1)

**Where the serve-only indicator enters the install flow.** Two clean
candidates, to settle when Part A is built against a real LB:

- **CLI** — a new arg/flag on `server:install` (today only `$rType`), set by
  whatever UI launches a single-binary LB; the final UPDATE
  (`ServerInstallCommand.php:298`) writes `serve_only`.
- **Panel** — a `serve_only` form field flowing into `ServerService::install`'s
  INSERT (`src/Domain/Server/ServerService.php:417`); the reinstall/edit branch
  (`:375-393`, which today only UPDATEs status + parent_id) must also handle a
  toggle, which ties into D-B4.

Deferred because the choice depends on how the single-binary LB is surfaced to
the operator (a new "node type" control), which is itself part of building
Part A on real hardware.

## Out of scope

- The LB stream *lifecycle* in Go (EnsureRunning, retiring LB crons, loopback)
  — the Phase 4–5 lifecycle spec.
- LB-side SSRF of admin-configured `stream_source` — an accepted known risk;
  any hardening is an opt-in egress policy blocking only cloud-metadata IPs,
  never a blanket private/loopback block (it would break cluster relay).
- Viewer authorization on the LB — already removed (serve-only, option B):
  MAIN mints per-node-keyed tokens, the LB verifies and serves.

## Risks & open questions

- **run.sh conditional complexity.** Threading flags through two shipped shell
  scripts gated on a config.enc marker is the fiddliest piece; a missing flag
  fails transcode closed silently. The B1 tests prove MAIN *emits* a correct
  Spec; only a real LB proves the agent *applies* it — so Phase A must include
  a live "directive received → applied → served" check, not just install
  success.
- **`features` source.** `buildSupervisorSpec` gates native remux on
  `features` containing `remux`. Where MAIN learns a node's features (from its
  policy/capabilities report?) must be pinned in the B1 plan; default `[]`
  (no remux) is safe but forces ffmpeg for renditions that could be remuxed.
- **Toggle races (D-B4).** Flipping `serve_only` on a busy node mid-delta: the
  bump must land so the next delta re-emits; worst case the resync safety-net
  reconciles within ~5 min. Acceptable, but the toggle UI should set
  expectations.
- **Loader leak.** D-B3's global loader must never leak into unrelated reads on
  the same request; the `finally` reset and a test that forces an exception
  mid-loop guard this.

## Next

This design resolves the shape and the decisions (D-A1 open pending hardware).
The next step is a task-by-task implementation plan for **Phase B1** — the
page-bound, snapshot-fed, serve_only-gated emission — the slice that is
buildable and CI-verifiable now and that lights up the moment Part A marks a
node serve-only.
