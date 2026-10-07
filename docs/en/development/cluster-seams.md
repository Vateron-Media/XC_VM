# Cluster seams

The MAIN ↔ LB API plan (`docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md`)
replaces the node's direct access to MAIN's MySQL and Redis with a signed API. Phase 0
gets the code ready without changing any behaviour. Every place a node reaches MAIN's
state, and every place MAIN reaches a node, goes through one class. Each class has one
backend today, the legacy one, which does exactly what the call sites used to do.
Later phases swap that backend and leave the call sites alone.

Each seam has a hook for tests and for the future transport (`useSink()`,
`useLoader()` or `useTransport()`). Passing `null` restores the legacy backend.

## Node → MAIN

| Seam | Wraps | Legacy backend | API backend (phase) |
| --- | --- | --- | --- |
| `Core\Cluster\SignalDispatcher` | the 47 `INSERT INTO signals` sites (kill, cache jobs, root actions) | `LegacySqlSignalSink` | commands and events (4/5) |
| `Domain\Stream\StreamStateWriter` | a node's runtime state in `streams_servers`; refuses any column outside `STATE_FIELDS` | `StreamRowMerge::apply()` | `stream.state` event (5), kept in the node's own store (`Core\Cluster\StreamRuntime`, 7) |
| `Domain\Stream\StreamSource` | the stream row, this node's `streams_servers` row, the stream options and a recording, read before running a stream or a recording; the stream with this node's row and its runtime state (`nodeRow()`, `workerRow()`, `plainRow()`, `createdRow()`, `builtServerRow()`, `channelRow()`, `movieRow()`) | SQL | the node's stream caches, built by `cluster:apply` from the R2 `streams` section once the STREAMS flow is on (`ReplicaStreamCache`, 7), with the runtime state from the node's own store once it is seeded (`StreamRuntime`, 7); `stream_bundle` on a miss (not built) |
| `Domain\Stream\NodeStreams` | the lists of this node's streams its crons and daemons select with their runtime state (`cron:streams`, `cron:vod`, `cron:cleanup`, the on-demand daemon) | SQL; `fileStreams()`, `archives()`, `createdIDs()`, `liveChecks()` and `onDemandIDs()` answer `null`, never an empty list, when MAIN's database did not answer the read | the stream caches and the node's own store, or the R2 section whole (`ReplicaStreams`) for the lists `cron:cleanup` prunes files by (7): `null` when the section is not whole |
| `Core\Cluster\LogSink` | client, stream, stream-error, panel-error and restream-detection records; root's system log lines (`syslog()`) | one multi-row INSERT per batch (chunks of 1000); the caller's own `mysql_syslog` INSERT | `log.*` events, redacted first (5); `log.syslog` (7) |

## MAIN side

| Seam | Role |
| --- | --- |
| `Domain\Stream\StreamRowMerge` | Merges a node's runtime state into that node's row only. `eventFields()` keeps only runtime columns and redacts the source URL. |
| `Domain\Stream\StreamCacheBuilder` | The `stream_<id>` cache entry: columns, per-server rows, and the rule that a stream's source URLs stay out of the cache unless it is a direct source. `cron:cache_engine`, which only MAIN runs, builds it with this class. |
| `Core\Cluster\Redactor` | Strips `password=`, `token=` and `username=` values, the `/user/pass/` segments of Xtream URLs, and `user:pass@` from text before it is journaled. |

## MAIN → node

| Seam | Wraps | Catalogue | API form (phase 4) |
| --- | --- | --- | --- |
| `Core\Cluster\NodeRpc` | `ApiClient::systemRequest()` / `asyncRequest()`: request/response calls to a node's `/api` | `NodeRpc::ACTIONS` | `node.rpc{action}`, answered via `ack` / `rpc_result` |
| `Core\Cluster\NodeActions` | root actions for `RootSignalsCronJob`: reboot, services, update/rollback, ports, sysctl, certbot, modules, blocklist flush, `OPENSSL_EXTRA`, and the credential, key and pin actions that run over the cluster API only | `NodeActions::ROOT_ACTIONS` | `node.root{action}` for `cluster:root`, with an artefact grant when the action needs a file of MAIN's |

Both seams refuse an action that is not in their catalogue, so a new call is a
deliberate change. `NodeRpcActionsTest` checks that every call site uses a
catalogued RPC action and that `RootSignalsCronJob` handles every catalogued root
action.

## Rules for new code

- Do not write `INSERT INTO signals`, `UPDATE streams_servers` (for runtime state),
  or an INSERT into a log table directly; use the seam.
- Do not call `ApiClient::systemRequest()` / `asyncRequest()` or
  `SignalDispatcher::rootAction()` outside `Core\Cluster`; add the action to the
  catalogue and use `NodeRpc` / `NodeActions`.
- Node-side code reads stream definitions through `StreamSource`, not with its
  own queries on `streams`, `streams_options` or `recordings`. Once the node's
  replica owns the streams (STREAMS flow on), its answers come from the stream
  caches `cluster:apply` built, never from MAIN's database. A stream read with
  this node's runtime state (a joined `streams ⨝ streams_servers` row, or a
  list filtered on `pid` or `stream_status`) goes through `StreamSource` or
  `NodeStreams` too: with `StreamSource::local()` (the replica owns the
  streams and the node's own store `StreamRuntime` is seeded) the runtime
  columns come from that store, which the writers keep with STREAMS on, and
  the seam keeps MAIN's statement for everywhere else. Do not reconnect
  MAIN's database in a daemon where `StreamSource::local()` holds. A
  runtime column MAIN writes itself for this node reaches that store only
  where node code keeps it too (the recorder keeps the VOD MAIN attaches to
  the node for a finished recording).
- Node-side code reads settings through `SettingsManager`'s getters and servers
  through `ServerRepository`. A node in mode 2, or in mode 1 with the CONFIG
  flow on, boots from its replica (`ReplicaStage`) once an apply built its
  caches: those come from the replica's caches, and any other query opens
  MAIN's database lazily, on first use. Mode 1 opens it, counted at the
  query's site; mode 2 refuses it. The streaming entry points take the same
  lazy handle there (`LegacyInitializer::initStreaming`). On a node in mode 1
  or 2, a settings key outside `lb_settings_keys.php` is counted as a miss
  (`SettingsAudit`) and shown on *Servers → Cluster Nodes*.
- Do not open MAIN's database before a query needs it. A connect at boot or at
  the top of an entry point counts against a mode 1 node's seven-day zero even
  when the request ends without a query. Where the replica may not answer
  (`ReplicaBoot::hybrid()`: mode 1, never where connects are refused), read
  MAIN's database on the lazy handle, never on a new one. To learn whether
  MAIN's database answers, query it: a lazy handle's `connected` stays false
  until its first query (`StatusCommand::mainDatabaseAnswers()`).
- Do not write `new DatabaseHandler()`. Take the process's handle
  (`DatabaseAware`, `DatabaseFactory::get()`), or `DatabaseFactory::connect()`,
  `connectLazy()` or `open()`. Every connect to MAIN's MySQL or Redis passes
  `ConnectAudit::guard()`: on a node in mode 1 or 2 it is counted with its
  caller and shown on *Servers → Cluster Nodes*, and in mode 2 it throws
  `LbDatabaseAccessException`. Do not open PDO, `\Redis` or mysqli connections
  of your own in code a load balancer runs.
- A node in mode 2 (`NodeRole::refusesConnects()`) never falls back to MAIN's
  database. A write with an agent path goes through its seam first
  (`LogSink`, `LogSink::syslog()` for root's system log lines, `NodeStateSink`),
  and a root action runs after its log line whatever became of the line
  (a line the agent did not take stays in the panel's error log). An action
  that still needs MAIN's database is refused up front in mode 2
  (`RootSignalsCronJob::updatesHere()` for `update` and `rollback`).
  Work that needs MAIN's data no replica section carries yet is skipped in
  mode 2 behind a named seam (`CleanupCronJob::streamChecks()`, which holds
  once the replica and the node's own store answer), never run against an
  empty or partial answer. A stream-state write the agent did not take stays
  in the node's own store in mode 2 (`StreamStateWriter::resend()` sends it
  later) instead of falling back to MAIN's row; in mode 0 and 1 it falls back,
  and the store lapses once MAIN's row has it (`StreamRuntime::lapse()`).
  A viewer's connection record has only the node's agent in mode 2: its calls
  wait `AgentConnections::SOLE_TIMEOUT` (3 s, not the 1 s that mode 0 and 1
  wait before MAIN's store stands in), and one the agent does not answer reads
  as no record or a failed write in `ConnectionTracker`, so the endpoint
  answers `LINE_CREATE_FAIL` instead of an HTTP 500.
  A node in mode 2 never lapses its store on a write
  (`StreamStateWriter::write()`, `ContentSink::workerPid()`): MAIN's database
  refused that write, and the store cannot be seeded again.
- A list whose caller deletes or stops what the list does not name is `null`,
  never empty, when it could not be read (`NodeStreams`, above). The caller
  does nothing by a `null` list: `cron:cleanup` skips that check, and
  `cron:streams` ends its pass where it reads the list: before its per-stream
  work when `liveChecks()` is `null`, and after that work, before it stops any
  process its lists do not name, when `onDemandIDs()` is `null`.
- A file a node needs from MAIN (a custom off-air video, a module's archive)
  is an artefact: MAIN names it in
  `Domain\Cluster\ArtefactRegistry` and grants it with a signed command
  (`ArtefactGrants`), and the node uses it only once `Core\Cluster\ArtefactStage`
  checked its size and SHA-256 against the grant, as it copies it to where it
  is used (root's own stage for a root action). Do not add a pull from MAIN by
  path, URL or password.
- MAIN's work for a node goes through `SignalDispatcher` and `NodeActions`, never
  a `signals` row written by hand: a node in mode 2 reads none. There MAIN
  sends cache jobs as `node.cache` commands in `CacheJobs::job()`'s form, the
  node runs the jobs it queues for itself at once, and a value the node read
  back from its own row in MAIN's database comes from the copy
  `NodeStateSink` keeps of what it reported (`NodeStateSink::reported()`).
- The node's audit files (`storage/cluster/`, `config/cluster/audit.json`) sit
  where xc_vm can write. A root process writes them only inside
  `SettingsAudit::asAgentUser()`, which does the work as xc_vm, never with
  root's own rights.

Tests that pin these rules: `SignalDispatcherParityTest`, `StreamStateWriterTest`,
`StreamRowMergeTest`, `StreamCacheBuilderSourceTest`, `LogSinkTest`,
`NodeRpcActionsTest`, `ArchitectureTest`, `DbConnectRefusalTest`,
`ReplicaBootTest`, `ModeTwoPathsTest`, `ArtefactHashRefusalTest`,
`StreamRuntimeTest`, `StreamRuntimeReadersTest`, `AuditCronStreamsTest` and
`AuditClusterFlowsModeTwoTest`.
