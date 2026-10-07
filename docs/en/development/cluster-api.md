# Cluster API (MAIN ↔ LB)

A load balancer used to reach MAIN's MariaDB and Redis directly: its crons, daemons and
streaming endpoints held the panel's database credentials and wrote MAIN's tables. The
cluster API replaces that with one signed, encrypted HTTP channel the node opens to MAIN.
This page is the protocol and the operator's view of it; the design record with every
decision and its history is [`docs/adr/0004-cluster-api.md`](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0004-cluster-api.md),
and the code seams each call goes through are in [Cluster seams](cluster-seams.md).

!!! info "Plain HTTP is the default"
    The channel does not need TLS. Every request is signed and its body encrypted with keys
    derived from the node's token, so a panel with no certificate loses nothing by talking
    over `http://`. HTTPS is optional and, when a certificate verifies, preferred — see
    [Transport](#transport-and-endpoints).

## What travels

The node always dials MAIN; MAIN never connects to a node (its instructions wait for the
node to ask for them). Every call is `POST /cluster/v1/<op>` on MAIN's HTTP broadcast port,
or on `cluster_api_port` when one is set.

| Direction | Carried as | Examples |
| --- | --- | --- |
| Node → MAIN, needs an answer | an op on the control lane | `hello`, `heartbeat`, `token_refresh`, `conn_admit`, `commands`, `ack` |
| Node → MAIN, a report | an op on an ingest lane | `events`, `streams`, `conn_snapshot`, `queue_claim`, `queue_update`, `queue_enqueue`, `config`, `recording_complete`, `vod_analysis`, `artefact` |
| MAIN → node | a signed command the node's next `commands` call collects | `node.rpc`, `node.root`, `node.cache`, `conn.kill_worker`, `conn.drop`, `conn.close`, `config.changed`, `artefact.fetch` |

Two ops the plan lists are deliberately never served: `rpc_result` (a command's result comes
back inline with its `ack`, which takes 64 KiB) and `stream_bundle` (the replica's `streams`
section carries a stream's whole record, and a cache miss reads it from disk). They stay in
the op list because nginx's ingest lane is rendered from it, and the API refuses an op it
does not serve.

### Lanes

Events are spooled by priority and drained by the agent:

| Lane | What | Pace |
| --- | --- | --- |
| P0 | a viewer opening or closing, a stream's state | at once |
| P1 | logs, the node's inventory, its audit | batched |
| P2 | HLS touches | batched, only while MAIN takes them |

MAIN serves the control ops on the `cluster_ctl` FPM pool and the ingest ops on
`cluster_ingest`, sized by `cluster_ingest_concurrency`; half its permits are reserved for
P0 so a flood of logs can never delay a viewer. A node that arrives while MAIN is starting
is refused with `STARTING` and a back-off, never a 500.

## Authentication

There is no shared secret in a file and no bearer credential on the wire.

1. **Enrolment.** MAIN gives the node an identity (`node_uuid`, an Ed25519 key pair the node
   generates itself, MAIN's pinned public key) over SSH at install, or through a one-time
   code the operator reads out (`cluster:enrol-code`, approved with `cluster:enrol-approve`).
2. **The token** is minted inside the `xcvm_core` extension from the panel's root secret and
   the licence, bound to the node's uuid, generation and epoch. It never exists in PHP as
   plaintext: the node receives it sealed to its own key.
3. **Every request** carries a timestamp, a nonce and an HMAC over a canonical
   representation of the request, with the body encrypted (AES-256-GCM). MAIN replies the
   same way. A replayed request is refused by the nonce store; a request whose clock is off
   by more than the allowed skew is refused.
4. **Rotation.** The token's lifetime is `lb_token_rotation_min` (5–1440, default 60). The
   agent refreshes at half its life; a refused token re-keys rather than stopping the node.
   Revoking the licence stops new tokens being minted at all, which is how a fleet is
   fenced.

## Transport and endpoints

`cluster_transport`:

| Value | Meaning |
| --- | --- |
| `auto` (default) | HTTPS first when MAIN's own certificate verifies, else plain HTTP |
| `http` | plain HTTP only |
| `https_preferred` | HTTPS first, plain HTTP as the fallback |
| `https_required` | HTTPS only; refused unless MAIN's certificate verifies **and** every active node has already reached MAIN over HTTPS (its agent reports the `https` feature) |

The URLs a node dials, their order and the transport are MAIN's *policy*, versioned by
`cluster_policy_ver`. A node adopts a policy that is not older than the one it holds, and
remembers the plain-HTTP URLs of every policy it has seen: under `https_required` with a
broken certificate, the signed challenge over plain HTTP is its way back.

Changing MAIN's address, its HTTPS port or `cluster_api_port` keeps the old endpoint served
for seven days so no node is lost; `cluster:endpoint list` shows what is kept and
`cron:cluster` releases a port once every node has moved off it.

The policy also carries the fleet's heartbeat (`lb_telemetry_interval_sec`, 1–3 s), so
changing it reaches every node with the next policy and no agent restart.

## Flows and modes

What a node does through the API is switched per node, on the *Cluster Nodes* page. A flow
moves one part of its work to the agent and stands the legacy path down.

| Bit | Flow | Moves |
| --- | --- | --- |
| 1 | TELEMETRY | the server's stats: MAIN writes the `servers` row from the heartbeats |
| 2 | COMMANDS | kills, cache jobs and root actions arrive as signed commands |
| 4 | LOGS | client, stream, error, activity and on-demand records become `log.*` events |
| 8 | STREAMS | the node's streams come from its replica, its runtime state from its own store |
| 16 | CONTENT | recordings, VOD analysis and the encoding queue go through MAIN |
| 32 | CONFIG | the node's caches are built from the replica, not MAIN's database |
| 64 | CONNECTIONS | the node's viewers live in its agent's registry |
| 128 | DATAPLANE | relays and other servers' files go through the agent's loopback proxy, with tickets instead of the stream secret |

`mode` is the node's independence:

| Mode | Name | Meaning |
| --- | --- | --- |
| 0 | legacy | reads MAIN's database as it always did |
| 1 | hybrid | boots from its replica, may still reach MAIN's database |
| 2 | api | every connect to MAIN's MariaDB or Redis is refused in code (`LbDatabaseAccessException`) |

Moving a node up is gated (`ClusterAdmin::modeGate()`): mode 1 needs CONFIG; mode 2 needs
every flow, the data plane included, root's pin, and a node that can run that way now: state
`active`, heard within `NodeHealth::SUSPECT_AFTER_MS`, and `streams_local` true in its last
audit report. The node says that itself (`SettingsAudit::publish()`): it boots from its replica
(`ReplicaBoot::ready()`), the replica owns its stream definitions, and its own store of their
state is seeded. It says it again at once whenever that stops being true (`StreamRuntime::lapse()`,
`ReplicaApply`), because in mode 2 it can neither seed the store nor read from MAIN. `act()` also
refuses the move while `redis_handler` is on: the node's stream entry opens MAIN's Redis at every
viewer, which mode 2 refuses.

A node in mode 2 keeps every flow. `act()` refuses to switch one off there
(`cluster_flow_mode_two`): without it the node has only MAIN's database for that work, which it may
not reach. The operator moves the node one mode down first.

An enrolment that would start a node in mode 2 (a node MAIN keeps credential-free, see
[Limits](#limits)) meets the Redis handler too. `EnrolmentService::begin()` refuses it while
`redis_handler` is on, before the node's row is touched: `ClusterRefusedException` with the reason
`REDIS_HANDLER`, which is MAIN's own refusal and not the extension's. Every path says so before it
acts:

- the page's approval of a code and `cluster:enrol-approve` leave the request pending;
- `server:install` refuses before the node is contacted, and marks the server's install as failed;
- `server:enrol` and `cluster:reenrol` refuse before the node's agent is stopped;
- the panel's install and reinstall refuse before the server is marked as being installed
  (`ServerService::modeTwoInstallRefused()`), so a running load balancer stays in rotation. The
  install form's failed answer (`post.php?action=server_install`) carries the reason, translated,
  in `message`; for any other failure `message` is null.

In the other direction, the Cache page does not switch the handler on while a node is in mode 2
(`ClusterAdmin::anyInModeTwo()`). That counts a node still enrolling in mode 2 until its
`enrol_deadline` (30 minutes), but neither an enrolment that was never completed nor a revoked
node.

The connect audit is **not** part of the gate. A node in mode 1 reads MAIN's database by design
(its crons, its signals daemon, viewer authentication), so the zero the gate once waited seven
days for could only come in mode 2. The move takes nothing from the node, so `mode_down` undoes
it, and it is always allowed. The seven days (`ClusterAdmin::CUTOVER_CLEAN_DAYS`) stand before the
step with no way back: `DbCredentials::strip()` refuses until the node has been in mode 2 that
long (MAIN notes when in `cluster_meta`, `mode2_at.<server id>`). It also asks what the gate asks,
at that moment: every flow (`ClusterAdmin::MODE2_FLOWS`), a node heard within
`NodeHealth::SUSPECT_AFTER_MS`, and `streams_local` true in its last report. A node that says
nothing of its streams is refused.

The strip is judged again after it is queued:

- its command lives `DbCredentials::STRIP_TTL` (600 s), not the day another `node.root` command
  lives: a node that is away when it is asked is asked again. A strip the node was handed keeps
  its row a day past its `exp` (`CommandBus::prune()`), so a late ack still revokes the grant;
  past `exp` it is not handed out;
- `CommandBus::pending()` hands a strip out only while the node's row says mode 2;
- `mode_down` from 2 ends every strip the node has not acked (`DbCredentials::cancelStrip()` sets
  its `exp` to now), so it is handed out no more, also if the node returns to mode 2; one the node
  was not handed goes at the next `cron:cluster`, one it was handed keeps its row for its ack,
  which says what the node's config holds;
- root on the node runs it only while the node is in mode 2: `NodeCredentials::run()` refuses
  `strip_db_credentials` unless `NodeRole::refusesConnects()`. The command then fails with
  `strip_db_credentials: refused: this node is not in mode 2 ...`, and MAIN revokes nothing.

On the node, a move into or out of mode 2 is followed within a pass: the signals, queue and
fanout_sync daemons (`DaemonTrait::refreshOrBreak()`), the scanner and the on-demand daemon leave
their loop when `NodeRole::refusesConnects()` is no longer what it was at their start, so none
keeps the connection it opened. The connect audit's window starts again when the node begins to
refuse (`ConnectAudit::follow()`): what the Cluster Nodes page shows after the move is what mode 2
turned away.
A crontab row whose role is `legacy` (today `cron:users`) is not sent to a node in
mode 2 at all.

## The node replica

While CONFIG is on, the node's caches are built from what the agent stored, not from a
query. MAIN serves the sections; `cluster:apply` turns them into the caches the node's
readers use (a shadow diff before the flow is on, so an operator sees what would change):

| Section | Carries |
| --- | --- |
| `settings` | the settings a node reads, never a secret |
| `servers` | every server's row, without liveness or telemetry |
| `node` | the node's own row and its node settings |
| `crontab` | the enabled rows whose role fits the node's mode |
| `cluster` | MAIN's URLs, the transport, the heartbeat, the panel keys, `min_proto` |
| `secrets` | the viewer-token secret and `OPENSSL_EXTRA`, each with the value it replaced and how long that is still accepted |
| `bouquets`, `categories` | as `cron:cache` builds them |
| `streams` (R2) | one record per stream the node holds, kept by deltas and a resync; with DATAPLANE on, its relay and file tickets too |

## Settings reference

| Setting | Bounds | What reads it |
| --- | --- | --- |
| `cluster_api_enabled` | 0/1 | the whole API; needs `cluster:init` and the extension |
| `cluster_api_port` | 0 or 1024–65535 | 0 serves the API on MAIN's HTTP broadcast port |
| `cluster_main_host` | hostname | MAIN's DNS name in the URLs nodes dial |
| `cluster_transport` | see above | the policy |
| `lb_token_rotation_min` | 5–1440 (60) | the token's lifetime; grace is `clamp(L/4, 5, 60)` |
| `lb_revocation_mode` | graceful \| hard | how fast a fleet stops after a licence revocation |
| `lb_telemetry_interval_sec` | 1–3 (2) | the fleet's heartbeat, carried by the policy |
| `cluster_offline_after_sec` | 10–300 (30) | silence before MAIN marks a node offline |
| `cluster_orphan_conn_ttl_sec` | 30–3600 (120) | silence before MAIN purges a node's viewers |
| `lb_offline_admission` | local \| allow \| deny | admitting viewers while MAIN is unreachable |
| `cluster_kill_on_line_disable` | 0/1 (1) | a disabled, locked or expired line loses its sessions; a reseller's disable (panel or Reseller API) counts too |
| `cluster_conn_binding` | observe \| enforce (observe) | a node's record of a viewer, and its `conn_admit`, must prove MAIN minted the viewer's token; `observe` counts those that do not (`conn.unproven` in the audit), `enforce` refuses such a record from a node whose stream secret MAIN withholds (mode 2, locked down, on its own viewer key), and reserves and cuts nothing for such a `conn_admit` (ADR 0004, "The line a node names") |
| `cluster_ingest_concurrency` | 1–64 (6) | MAIN's ingest permits; half reserved for P0 |
| `lb_new_node_mode` | legacy \| api | the mode a newly installed LB enrols at |
| `servers_stats_retention_days` | 1–365 (30) | `cron:cleanup` prunes `servers_stats` |
| `cluster_audit_retention_days` | 1–365 (30) | `cron:cleanup` prunes `cluster_audit` |
| `cluster_db_allowlist` (+`_extra`) | 0/1 | firewalls 3306/6379 on MAIN to the fleet |
| `lb_scan_roots` | paths | the directories the node's scan RPC may list |
| `lb_partition_tolerance_h`, `lb_fence_drain_min` | 0–24 (12), 0–60 (10) | the lease's window past token expiry, and the drain after it |
| `lb_lease_fence` | 0/1 (0) | a node stops serving when its lease runs out |

## Operating it

```bash
# MAIN, once: create the cluster root and record the panel keys
console.php cluster:init

# Enrol a load balancer over SSH (or at install time, automatically)
console.php server:enrol <serverID>

# Without SSH: a one-time code the operator reads out, then approves by its SAS
console.php cluster:enrol-code <serverID>
console.php cluster:enrol-approve <serverID> <SAS>

# The node's own side, run by its agent after every change
console.php cluster:apply            # --from-disk at boot
console.php cluster:exec             # one signed command

# MAIN's endpoint and pools
console.php cluster:nginx            # render and reload the API's nginx config
console.php cluster:pools            # start or resize the API's FPM pools
console.php cluster:endpoint list    # old ports and URLs still served
console.php cluster:maintain-stats   # servers_stats and log table indexes, built online (cron:cleanup starts it)

# Before switching CONNECTIONS on: load the node's viewers into its agent (on the node;
# MAIN's guided cutover asks it with the `seed_connections` root action, cluster:cutover)
console.php cluster:seed-connections

# MAIN: a load balancer's flows on one at a time, watched, up to mode 1; the load
# balancers updated to MAIN's release one at a time
console.php cluster:cutover <serverID> [admin user id]
console.php cluster:rolling-update

# Every node (MAIN too, any mode) keeps the agent, the fanout daemon and xcvm_core
# current from GitHub itself, hourly from cron:root_signals; by hand, as root:
console.php fanout_binary [fanout|agent] [force]
console.php xcvm_core [force]

# Firewall MariaDB and Redis to the fleet (check first)
console.php cluster:db-allowlist status | apply | undo

# Phase 9: a mode-2 node gives up MAIN's credentials (asks first; --wait=<s> waits for the revoke)
console.php cluster:strip-credentials <serverID> [--yes] [--wait=<seconds>]

# MAIN reads other servers' files and relays through its own agent (on | off | rekey | status)
console.php cluster:main-dataplane on

# Rotate the panel's DB password (MAIN), and set it on a node MAIN cannot reach (node, root)
console.php cluster:rotate-db-password [--yes] [--password-stdin]
echo "$NEW_PASSWORD" | console.php cluster:set-db-password

# Disaster recovery of the cluster root
console.php cluster:export-keys   /path/bundle
console.php cluster:import-keys   /path/bundle
```

The *Cluster Nodes* page is where a node is approved, its flows switched, its mode moved and
its state read (epoch, token expiry, the fence window that follows it — token expiry plus
`lb_partition_tolerance_h`, then `lb_fence_drain_min` — its queued commands, last seen, agent
version and architecture, settings misses, connect audit). It warns when the licence is
suspended (and, with `lb_lease_fence` on, by when the fleet stops at the latest) and when
MAIN's certificate expires within 14 days while the nodes may dial HTTPS. It shows the figures
MAIN records: command delivery and ack latency (p50/p99 over the last hour), queue depth, the
ingest permits in use per lane, the `cluster_ctl` pool's listen queue, and the recent audit.
*Rotate all tokens now* sends `token.rotate_now` to every active node that takes commands.
Saving a different activation key on the dashboard does the same, once the extension accepts it.
The *Servers* list's row menu carries the same per-node actions (mode up/down, rotate,
enrolment code, a link to the node's flows) and shows the `cluster:reenrol` command to run
for a re-enrolment over SSH. Every decision is written to `cluster_audit`, which
`cron:cluster` prunes.

### A cutover, in order

1. `cluster:init` on MAIN, `cluster_api_enabled` on, `cluster:nginx`, `cluster:pools`.
2. Enrol the node; approve it if it came by code.
3. Switch TELEMETRY, then COMMANDS, then LOGS — each is reversible and visible on the page.
4. Switch STREAMS and CONTENT; watch the node's settings misses and its audit.
5. Switch CONFIG, then `mode_up` to 1: the node now boots from its replica.
6. `cluster:seed-connections`, then CONNECTIONS.
7. DATAPLANE, once the node's parents and the servers whose files it reads are MAIN or
   active nodes, and its agent runs the relay proxy (the page refuses the switch
   otherwise): relays and file reads go through its agent from each stream's next start.
8. `mode_up` to 2 once the node says `streams_local`, a minute or two after its flows are on.
   Watch its *MAIN DB connects* box (counted afresh from the move) and its streams afterwards.
9. `cluster:db-allowlist apply` once every node is in mode 2.
10. *Drop DB credentials* on the node (or `cluster:strip-credentials`): the node's
    `config.enc` loses MAIN's DB and Redis credentials, then MAIN revokes its grant. There is
    no undo from the page: rolling back needs a config with credentials and a new grant.
    MAIN sends it only after seven days in mode 2, to a node that has every flow on, was heard
    in the last ten seconds and reports `streams_local`. The command waits ten minutes for the
    node, and root on the node runs it only while the node is in mode 2. A drop that was
    refused or that expired changed nothing: send it again.

## Limits

- **The data plane (Phase 8) covers what a node pulls from another server**, and only
  while its DATAPLANE flow is on. A child relaying a stream, a VOD or subtitle fetch and a
  created channel's items go through the agent's loopback proxy (`127.0.0.1:31290`), which
  signs each upstream connect with a panel-signed ticket from the R2 streams section (a relay
  ticket valid 24 h, a file ticket 6 h, both minted anew every 3 h and never moving a
  record's ETag or version) and checks each 4 MiB chunk of a file against its owner's
  signed digest. What it does not cover:
  - A parent or owner that cannot check a ticket (a legacy server, not enrolled or not
    active) is still reached with the legacy URL and its password, even with the flow on.
  - Streams started before the flow was switched keep their URL until they restart.
  - Relay bytes and file chunks are AEAD-framed (D11) where the parent or owner seals
    (`servers.relay_seal`): AES-256-GCM under a key the child's agent picks per connect and
    SEALs to the parent's box key. Viewer bytes and `/images` stay direct, and there is no
    forward secrecy: a parent's box key taken later opens a recorded relay's key.
  - The secret still appears on the node's own loopback: the local RTMP output
    (`rtmp://127.0.0.1/live/<id>?password=`) and the recorder's pull from its own
    `/admin/live` and `/admin/timeshift`.
  - MAIN pulls through it only once an operator runs `cluster:main-dataplane on`: MAIN then
    gets a data-plane key of its own and an entry in the signed node list, and its source
    probe, a node's certbot log, and the relays and files of the streams it runs go through
    `xc_agent run -role main`. Off (the default), MAIN keeps the legacy URLs. MAIN's key sits
    in `config/cluster/main_agent.json` (0600), outside `xcvm_core`, as a node's does;
    `cluster:main-dataplane rekey` replaces it and raises its generation.
  - A file ticket names the owner's box key as it was when minted: an owner re-enrolled
    since cannot open it until the next epoch's ticket (at most 3 h).
  - The node's own legacy `/api` stays served with the flow on. `api_legacy.conf` (Phase
    8's second increment) retires it only once nothing reads the node's files with the
    legacy `getFile` URL any more: its own flow on, and every server of the cluster, MAIN
    included, an active node with its data plane on. MAIN counts once
    `cluster:main-dataplane on` is set. A proxy is never a node, so a cluster with a proxy
    keeps every node's `/api`.
  - The flow can be switched on only for a node whose agent runs the loopback relay proxy
    (it says `relay` at hello): update `xc_agent` first. The agent publishes the loopback
    key (`relay.key`) only while it holds `127.0.0.1:31290`, and the node's PHP checks
    that the listener belongs to the key's owner. While the port is not the agent's (held
    by another user, or the agent is stopped), the node's relays and file reads fail and
    are retried: they never fall back to the stream secret. An agent that cannot bind the
    port tells MAIN in every heartbeat: the Cluster Nodes page shows *relay port down* with
    the error, and `server:diagnose` names it. The port is not freed for it: an operator
    finds the holder on the node.
  - `/xfile` has its own rate limit (50 requests/s per server, burst 100, answered with a
    429 the agent retries), apart from the viewers' 20 requests/s.
  - `cluster:rotate-stream-secret` rotates the stream password (the viewer-token secret) and
    re-encrypts what is stored under it (`hmac_keys`, the image cache's names); it refuses
    while an enrolled node still has its DATAPLANE flow off, whose URLs would carry the new
    value too (`--force` overrides). A run cut short is finished by running it again.
- **The licence lease is issued, checked, and enforced only behind a switch** (Phase 9).
  Every token MAIN hands a node (enrolment over SSH or by code, `token_refresh`,
  `token_rekey`) carries a lease signed by `xcvm_core`, capped at `min(token_exp +
  lb_partition_tolerance_h, iat + 26 h)`; when the extension refuses one (no licence, its
  clock gate, a revoked generation) the token goes out without it, and a refusal for the
  licence dispatches `ClusterLicenceLapsedEvent`. `xc_agent` verifies each lease it receives
  (panel signature, its node, server and generation, the window on its estimate of MAIN's
  time), keeps the newest in its state file and prints it with `xc_agent lease`.
  The fence that acts on it is built in the node's PHP (`Core\Cluster\NodeLease`) behind
  `lb_lease_fence`, which is **off by default**: with it on, past the lease's `exp` no new
  viewer starts on the node (`stream/auth.php`), and past `lb_fence_drain_min` more the
  sessions still running stop (`segment.php`, `key.php`, `live.php`), and the agent drops
  every viewer the fanout serves, judging the same lease, clock and settings itself. It judges the lease state the
  agent writes to `config/cluster/lease_state.json` at every heartbeat interval, whether or
  not MAIN answers: the lease's `exp`, and MAIN's clock carried forward on the node's
  monotonic clock from MAIN's last authenticated statement, so moving the node's wall clock
  does not move MAIN's time as the fence reckons it, and restarting the agent resumes it. Every
  uncertainty serves: the switch off, a legacy node, no file or a file the agent stopped
  refreshing, no lease, no anchor on MAIN's clock. The switch must be on *before* a licence
  lapses — it reaches a node in the replica's `settings` section, which a panel without a
  licence cannot sign. Where the node's `xcvm_core` offers it (`cluster_lease_state`), the
  extension judges the lease itself — against its own pin of the panel key and an anchor on
  MAIN's clock that runs on the monotonic clock and never moves back — and the agent's file
  is the fallback. The same verdict makes `license_valid()` true on the node, so
  `LicenseGate` lets it use fanout. The extension's verdict needs the node's `core.pin`:
  the SSH install pins it over its own session, and every other node that takes root
  commands is pinned by `cron:cluster` with a signed `node.root pin_core` (the *core* badge
  on the Cluster Nodes page, or *Pin core* to send it now).
- **The credential lockdown is partly built** (Phase 9). A node in mode 2 gives up MAIN's
  credentials with a signed `node.root strip_db_credentials` (or a credential-free
  `node.root install_config`), run by `xcvm_core` as root; when its ack reports a config
  without credentials, MAIN revokes the node's grant (`XC_VM::db_revoke`) and records
  `cluster_nodes.db_revoked_at` (`Domain\Cluster\DbCredentials`). Root runs the strip only
  while the node is in mode 2 (`NodeCredentials::run()`; `install_config` is not gated by
  the mode). Two limits remain there. The node learns of a `mode_down` at its agent's next
  heartbeat, so a strip root runs before that still runs. And when root's runner is more
  than `ClusterExecCommand::ROOT_WAIT` (5 s) behind, the command is acked
  `{"queued": true}` and root's later outcome does not reach MAIN, which then revokes
  nothing. Only an operator sends
  the strip (*Drop DB credentials*, `cluster:strip-credentials`), and
  `lb_new_node_mode=api` (a new load balancer installed in mode 2, every flow on, with a
  credential-free config) is taken only with the Redis connection handler off and an
  extension that packs such a config; the Cache page then refuses the handler. A node MAIN already keeps credential-free (mode 2, or a revoked
  grant) stays so: a reinstall over SSH packs it a credential-free config, re-enrols it in
  mode 2 and grants it nothing, an enrolment by code re-enrols it in mode 2 as well, with
  every flow on and its `db_revoked_at` kept (`EnrolCodeService::approve()`), and no grant
  path (*Re-authorise MySQL*, `tools mysql`) reaches its host.
  `cluster:rotate-db-password` rotates the panel's DB password
  through `XC_VM::db_set_password` and sends each node below mode 2 that takes root
  commands a signed `node.root rotate_db` with the new password SEALed to its box key,
  which its root side opens and hands to `XC_VM::config_set_db` (only `db.pass` changes).
  Every other load balancer that still uses its grant keeps the old password until an
  operator runs `cluster:set-db-password` on it. The password never rides a command in the
  clear. `cluster:rotate-credentials` rotates the Redis password the same way, and the
  manual `cluster:lockdown` exists too (ADR 0004, Phase 9's fifth and seventh increments).
- The viewer-token secret can be *replaced* gracefully (the value it replaces stays readable
  for ten minutes, fleet-wide), and rotated in full after a leak with
  `cluster:rotate-stream-secret` (above).
- A node's `whitelist_ips` is the admin's: a node no longer publishes its own addresses,
  because that column grants the legacy `/api` allowlist.
- A quarantine ends every command queued for the node that grants something (handed out
  or not, not yet acked): MAIN no longer knows who holds the node's keys, so what was
  decided before is decided again after *Trust again*, not replayed. The quarantine's audit
  line names each ended command, and the page says how many; the operator sends again what
  is still wanted. A quarantined node is handed only the commands that restrict it (kills,
  stops, a fence, the quarantine itself).
- A node's heartbeat sets `servers.status` to 1: at once, or with the next flush of the
  heartbeats the cluster bus holds (every 5 s). The status 5 (updating) a node reports
  through its `node.state` event holds against its heartbeats for 60 seconds after MAIN took
  it (`cluster_meta`, `updating.<server id>`), so the node stays out of routing while its
  update stops the service. Its "back" (1) ends the hold at once. A node that still sends
  heartbeats after the 60 seconds is taken back into routing by itself. A 5 a node wrote to
  its own row (TELEMETRY off), and an install state MAIN set (3 or 4), last only until the
  node's agent is next heard.
