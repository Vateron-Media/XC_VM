# Stop needing PHP crons on a serve-only LB — port the node-local crons to the Go agent (design)

**Status:** draft for review. Cutover **sub-project 2 of ~4** (the LbInstallFlow
cutover, follow-on #3, split into a sequence). SP1 (the RTMP gates) is done
(Fanout PR #98). This one moves the node-local crons a serve-only LB runs via
`console.php cron:*` into the Go agent, so those crons no longer need PHP-FPM/CLI
— shrinking the eventual flip (SP3/4). **Scope was deliberately narrowed** (user
decision): only the cleanly-separable, node-local crons; `cluster:apply`,
`cluster:exec`, and the `cron:root_signals`/`cluster:root` privileged-root bundle
are **deferred into the flip**, because they can't be dropped until nothing reads
PHP's caches (no php-fpm) and the Go binary owns iptables + nginx.

## Intent and why

The endgame is an LB that runs **no php-fpm and no nginx** — one Go binary. Today
a serve-only LB still runs a PHP crontab (the `role='all'` rows). Several of those
crons are purely node-local — they read a file the Go supervisor already writes,
or sweep the filesystem, or download a database — and have a file/spool path that
needs no MySQL. Those are portable to the agent **now**, independently of the
flip, and moving them:
- removes that many `console.php` invocations from the node (progress toward "no
  PHP-CLI"),
- lets the Go ports bake and be validated while PHP is still present as the
  comparison (for the idempotent ones) or behind a reversible capability gate (for
  the drains),
- makes the flip smaller and less risky by the size of this slice.

The exploration (two cross-repo passes) established exactly which crons qualify
and how each leaves the node.

## Goals

Port these node-local crons into the Go agent's own loop and stop scheduling them
on a serve-only node, each faithful to its PHP behaviour:

- **The four log drains** — `cron:streams_logs`, `cron:lines_logs`, `cron:activity`,
  `cron:errors`. Each reads a node-local log file under `LOGS_TMP_PATH`, parses it,
  **redacts**, batches, and emits the **same events PHP's `LogSink::spool` emits on
  the `p1` event lane** (`log.stream` / `log.client` / `log.activity` /
  `log.stream_error` / `log.panel_error`), which MAIN's `EventIngest` already
  ingests into `streams_logs` / `lines_logs` / activity / error tables. Then the
  file is consumed (truncate/unlink, or the `activity` rename-to-`.import`
  at-least-once claim) exactly as PHP does. **MAIN needs no change** — the agent
  speaks the identical event protocol PHP already speaks.
- **`cron:tmp`** — sweep the node's temp dirs (TMP/CRONS/DIVERGENCE/FLOOD/MINISTRA/
  SIGNALS/LOGS_TMP), deleting files older than the max age, honouring the KEEP list
  and live cron locks, and pruning the playlist cache by the `cache_playlists`
  setting. Pure filesystem; no DB.
- **`cron:maxmind`** — download the GeoLite2 `.mmdb` files into the node's
  `bin/maxmind/` on the weekly cadence (self-heal if missing), using the MaxMind
  license key from the replica settings. Pure download; no DB.

- **The cutover is capability-gated and reversible.** The agent advertises (at
  hello, via the existing `features()` mechanism) that it owns this cron set; MAIN
  drops exactly those rows from that node's generated crontab while the agent
  advertises it. If the agent is absent, stopped, or an older version that doesn't
  advertise it, the PHP crons stay scheduled — the node never ends up with *neither*
  owner draining a log.

## Non-goals

- `cluster:apply` (the signed-replica → PHP FileCache materialiser), `cluster:exec`
  (typed-command enactment: `stream.start/stop`, `vod.*`, `conn.*`, `node.*`), and
  the `cron:root_signals` + `cluster:root` privileged-root bundle (iptables/ipset,
  nginx conf + port rewrites, the serve-only front demote, sysctl, crontab
  validation, binary self-heal, the fanout/agent watchdog, root-action enactment).
  These are **the flip's work** (they can't be dropped until nginx/iptables are
  Go-owned and php-fpm is gone) and are out of scope here.
- `cron:streams`, `cron:vod`, `cron:cleanup`, `cron:cache`, `cron:servers`,
  `cron:certbot` — node-runtime / DB-or-replica / supervisor / cert work that the
  agent+fanout already overlap or that belongs to later sub-projects. Not ported
  here. (`cron:servers`' stats half is already covered by the agent's TELEMETRY
  heartbeat; its process-supervisor half and the others are flip/supervisor
  territory.)
- Any MAIN-side ingest change. The drains reuse MAIN's existing `EventIngest` →
  `LogSink::insert` path unchanged.
- Changing the log formats, the event types, the row shapes, or the redaction — the
  port is byte-faithful so MAIN can't tell the agent from PHP.

## Anti-goals (failure even if it "works")

- **Two owners draining one log file.** The Go drain and the PHP cron must never
  both read-and-consume the same `LOGS_TMP_PATH` file — they would split or lose
  lines. The capability gate must hand each drain to exactly one owner at a time.
- **A gap with no owner.** If the gate drops the PHP cron but the agent isn't
  actually draining (crashed, old, misconfigured), logs pile up unshipped. The gate
  must key on the agent *currently advertising* the capability, and PHP must resume
  when it stops.
- **Losing a redaction.** A row that PHP would redact before spooling must be
  redacted identically by the Go port (same fields, same rules) — a serve-only LB
  must not ship to MAIN, over the event lane, a secret PHP would have stripped.
- **Duplicating or reordering** ingested rows beyond what PHP's own at-least-once
  spool already does (the drains are best-effort/at-least-once today; match that, no
  worse).
- Sweeping a file in `cron:tmp` that is still live — a current cron lock, a
  binary-check stamp, an in-flight `.update.tar.gz`, or (note) a **fresh flood
  counter/marker** the just-shipped Gate 3 writes under `FLOOD_TMP_PATH`. (The PHP
  `cron:tmp` already sweeps `FLOOD_TMP_PATH` by age — the Go port must reproduce the
  same age rule so it neither clears live flood state early nor leaves it forever.)

## Current behaviour (the contract to reproduce)

Verified in both repos (see the exploration). Key facts:

- **Role gate:** `ReplicaSections::cronRoles()` returns `['all']` in every mode, so
  a serve-only LB runs exactly the `role='all'` crontab rows. The node's crontab is
  generated by `ReplicaApply::crontabText()` (from the `CRON_CACHE` the signed
  replica built via `ReplicaBuilder::crontabData()`, or MAIN's `crontab` table), and
  installed by `LegacyInitializer::generateCron()` (re-validated each minute by
  `cron:root_signals`).
- **The drains** (`LogSink::write` → `LogSink::spool`): with the `LOGS` NodeFlow on
  (the serve-only posture), each row is redacted (`Redactor`), chunked
  (CHUNK=1000 / ~1 MiB), emitted as `['type'=>'log.<kind>','d'=>['rows'=>…]]`, and
  `EventSpool::append('p1', …)`. The Go agent already owns the `p0`/`p1` spool lanes
  (SP1 used `spoolP0`). `streams_logs`/`lines_logs` read+`unlink`; `activity`
  rename-claims to `.import` (at-least-once) then batches; `errors` drains two files
  (stream errors + `error_log.log`).
- **`cron:tmp`** (`TmpCronJob`): `$db->close_mysql()` immediately — no DB. Sweeps the
  temp dirs by `maxAge()` (default 600s), a KEEP list, live-lock check, and prunes
  `PLAYLIST_PATH` by `cache_playlists`.
- **`cron:maxmind`** (`MaxMindCronJob`): weekly (`0 4 * * 2` / `--force`), downloads
  GeoLite2 `.mmdb` to `bin/maxmind/`, self-heals a missing `GeoLite2-Country.mmdb`,
  reads only the license-key setting.
- The agent's `features()` already advertises capabilities at hello and MAIN gates
  behaviour on them (e.g. `typed_starts`, `artefact`), and already degrades
  gracefully when a PHP hook is nil — the model this cutover's gate follows.

## Architecture (decisions made)

All agent code is in `internal/clusteragent` (Fanout); the XC_VM change is the
crontab gate + the feature definition.

### The agent owns a periodic "node chores" loop
The agent runs these on its own cadence (its existing `Interval` ticker drives a
scheduler, or a small dedicated loop per chore with its own period). The drains run
frequently (as the PHP crons did — per minute); `tmp` on its sweep cadence;
`maxmind` weekly. Each chore is a plain function the loop calls; failures are logged
and retried next tick, never fatal to the agent.

### Drains → the `p1` event lane, byte-faithful
Each drain is a Go port of its `CronJob` + `LogSink::spool`: read the node-local
file, parse (the per-line `base64(json)` or the cron's format), **redact**, batch to
the same CHUNK/byte caps, and append the **same `log.<kind>` events** to `p1`.
Consume the file with the same discipline as the PHP cron (`unlink`, or the
`activity` `.import` rename-claim). The event `type` strings, the `d.rows` row field
sets, and the redaction are the **faithfulness contract** — pinned by the plan
against `LogSink`/`EventIngest` and by an interop vector per drain. The `errors`
drain additionally honours the `stream_logs_save` setting (from the replica
settings), as `ErrorsCronJob` does.

**Redaction is a fresh, PHP-`Redactor`-faithful port — not a reuse of the repo's
`internal/redact`.** The two are structurally different (`internal/redact` masks
with `xxxxx`, URL-parses, and strips fields PHP does not; PHP's `Redactor` runs its
regex over *every* string column and masks with its own token). "Byte-faithful so
MAIN can't tell the agent from PHP" means reproducing PHP's `Redactor` output
exactly, so the plan writes a dedicated port and pins it against PHP's regex.

### Single consumer per log file — the shared `CronTrait` lock
The contested resource is the node-local file. The Go drain acquires the **same
per-cron lock the PHP `CronTrait` uses** (the lock file under `CRONS_TMP_PATH`,
same name/format: pid + timestamp, with the same stale-holder reclaim rule) before
it reads-and-consumes, and releases it after. So at any instant exactly one
process — the agent or a still-scheduled PHP cron — holds the lock and drains that
file; the other finds it locked and no-ops that tick. This makes **every**
transition window safe (flip-on, flip-off, a crontab rewrite mid-pass) with no new
handshake: it reuses the mutual exclusion the PHP crons already rely on. A drain
that crashes mid-lock leaves a stale lock the other owner reclaims by the existing
pid/age rule. This alone satisfies the "never both consume" anti-goal, independent
of crontab timing.

### The cutover gate — node-side, liveness-gated, self-healing
The ported cron rows are dropped from the **node's own** generated crontab
(`ReplicaApply::crontabText` / `cronJobs`, installed by `generateCron` and
re-validated every minute by `cron:root_signals`), **not** by MAIN's per-mode
`crontabData`. Rationale (from the builder check): `crontabData(int $rMode)` is
per-mode and ETag-cached, and MAIN-side feature gates key on the stored
`features` + `state`, which a crashed/hung agent keeps stale — so a MAIN-side drop
would keep removing the PHP rows while nothing drains (the no-owner anti-goal) on
the most common failure. The node-side drop is liveness-sensitive by construction:
the node omits the ported rows **only while its local agent's liveness marker is
fresh** (the agent touches a marker each tick — reusing its existing per-interval
writes, e.g. `lease_state.json`'s freshness, or a dedicated `node_crons` marker —
and the node treats a marker older than a small multiple of the agent interval as
absent). If the agent crashes, hangs, is stopped, or is an old build that never
writes the marker, the node sees it stale within seconds and the next
`cron:root_signals` crontab regeneration (≤1 min) restores the PHP rows. The signed
replica/`CRON_CACHE` is left intact; only the node's installed crontab is filtered.
Layered with the shared lock above: the lock guarantees correctness in every
window; the liveness-gated drop stops PHP from pointlessly racing once the agent is
reliably draining, and falls back to PHP whenever the agent isn't.

### `cron:tmp` and `cron:maxmind` → native, idempotent
Direct Go ports. `tmp`: walk the configured dirs, delete by mtime age, honour the
KEEP list + live-lock rule + the playlist-cache prune. `maxmind`: a weekly download
of the `.mmdb` files using the license key from the replica settings, with the
missing-file self-heal. Both are idempotent, so they may **overlap** the PHP cron
during any transition without harm (no single-owner gate needed for these two —
only the drains need it).

### The capability gate (reversible cutover)
A new agent capability — advertised in `features()` at hello (a new feature string,
e.g. `node_crons`, carried the same way `typed_starts`/`artefact` are) — tells MAIN
the node's agent owns this cron set. MAIN's crontab generation
(`ReplicaBuilder::crontabData()` / `ReplicaApply::crontabText()`) **drops exactly
the ported rows** (`streams_logs`, `lines_logs`, `activity`, `errors`, `tmp`,
`maxmind`) for a node advertising it, and schedules them otherwise. The set of
dropped names is defined in one place on the XC_VM side. Because the gate keys on a
*live* hello advertisement, an agent that stops or is rolled back automatically
restores the PHP crons — no no-owner gap, matching the flip's "keep the old surface
unless the new one proves itself" safety rule.

## Constraints
- Go, stdlib + in-repo packages only; zero new external deps. `GOWORK=off`.
- Faithful to each cron's PHP behaviour and to `LogSink`/`EventIngest`'s event
  protocol. MAIN ingest unchanged.
- The drains are single-owner (gated); `tmp`/`maxmind` idempotent.
- Conventional Commits, English, **no Claude attribution**. The user merges PRs.
- Base: XC_VM_Fanout `feat/lb-transcode-serve` (will include SP1 #98 once merged);
  XC_VM `2.8.0`. Execution after approval + plan, Native + one Opus whole-branch
  review (the established method).

## What is left to the builder
- The exact per-drain **row field sets, event `type` strings, CHUNK/byte caps, and
  file-consume discipline**, pinned against `LogSink`/the four CronJobs and MAIN's
  `EventIngest`; an adversarial interop vector per drain proving an agent-spooled
  batch ingests identically to a PHP-spooled one.
- The **redaction** port: a fresh port of PHP's `Redactor` (regex, mask token, and
  the "every string column" application), pinned against PHP output — **not** a
  reuse of `internal/redact`, which differs. (A redaction gap is an anti-goal.)
- The Go **`p1` spool API** (sibling of SP1's `spoolP0` at `registry.go:567`; the
  `p0`/`p1` lanes already exist in `events.go`) and the agent loop/cadence that
  drives each chore.
- The **shared `CronTrait` lock** format/location under `CRONS_TMP_PATH` (pid +
  timestamp + stale-reclaim rule), ported exactly so a Go-held lock and a PHP-held
  lock are mutually intelligible (each skips when the other holds it).
- The `cron:tmp` **dir set, max-age, KEEP list, live-lock rule, and playlist-cache
  prune** as exact ports; confirm the age rule's interaction with Gate 3's
  `FLOOD_TMP_PATH` files is the same one PHP already applies.
- The `errors` drain honouring the `stream_logs_save` setting; the exact per-drain
  files (`stream_log.log`, `client_request.log`, `activity`, `error_log.log` +
  `*.errors`) and consume discipline.
- `cron:maxmind`: the download URLs/filenames, the license-key setting name in the
  replica, and the weekly/self-heal cadence.
- The agent **liveness marker** the node reads to gate the crontab drop (reuse an
  existing per-interval write, or a dedicated `node_crons` marker) and its freshness
  window; the **node-side filter** in `ReplicaApply::crontabText`/`cronJobs` that
  omits the ported rows only while the marker is fresh, re-validated by
  `cron:root_signals`; the one-place definition of the dropped-cron set.
- Whether `maxmind` is worth porting now or better left as the single remaining
  node cron / a MAIN-served artefact — the lowest-value item in the slice; the
  builder may recommend deferring it (settle during planning).

## Review focus (what the tests must pin)
- **Drain faithfulness:** an agent-spooled `log.stream`/`log.client`/`log.activity`/
  `log.stream_error`/`log.panel_error` batch ingests on MAIN identically to a
  PHP-spooled one (same rows, same order-tolerance) — one interop vector each.
- **Redaction parity:** a row carrying a secret PHP strips is stripped identically
  by the Go drain before it reaches `p1`.
- **Single consumer under contention:** when both a PHP cron and the agent would
  drain the same file, the shared `CronTrait` lock lets exactly one consume per
  pass — never a split/lost file — and the loser no-ops.
- **Liveness-gated, self-healing drop:** while the agent's marker is fresh the
  node's crontab omits the ported rows; when the marker goes stale (agent crashed/
  hung/stopped/old build) the next `cron:root_signals` regeneration restores them —
  never a no-owner gap. Tests must cover the crash/stale case, not just the clean
  advertised/not-advertised case.
- **`cron:tmp` safety:** a live cron lock, a KEEP-listed stamp, an in-flight
  `.update.tar.gz`, and a fresh Gate-3 flood counter/marker are not swept early; a
  genuinely stale temp file is.
- **`cron:maxmind`:** a missing `GeoLite2-Country.mmdb` self-heals; a present, fresh
  DB is not re-downloaded off-cadence.
- **At-least-once, no worse:** a crash between read and consume re-drains (dup
  tolerated as today), never silently drops a file's contents.
