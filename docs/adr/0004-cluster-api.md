# ADR 0004 — Cluster API between MAIN and load balancers: the panel's contract

- **Status:** Accepted. Phases 0-7 are implemented: the seams and gates, the crypto contract and schema, MAIN's API with the cluster bus and pools, enrolment (SSH for new and existing LBs, by code, and `token_rekey`), the *Servers → Cluster Nodes* page and `cron:cluster`, authoritative telemetry and the 1 s liveness loop, the signed command channel with root commands and artefacts, logs, stream state, content and the fanout's monitor feed as events, all ten connection increments (admission, the agent's HLS reaper, limits on MAIN, digest and seed, `conn.divergence` and the P2 lane), and the authoritative config replica with mode-2 boot and the connect audit. Of Phase 8 (the data plane without bearer credentials) four increments are in: the viewer-token secret replaced without an outage, the legacy `/api`'s own switch, the two helpers a node's PHP asks its agent for (the relay nonce window and the file digest), and its relay half as one change — relay and file tickets minted into the R2 stream record and refreshed on the delta path without moving a record's ETag or version, the parents' `RelayGuard`, `/xfile` with a signed digest per chunk, the agent's loopback proxy and the URL builders, behind each node's DATAPLANE flow, which mode 2 now requires. Its acceptance on a running fleet (48 h without an encoder restart at L = 5) is still to be measured, and `cluster:rotate-stream-secret` waits with Phase 9. Of Phase 9 (the licence lease, cutover and lockdown) six increments are in: an operator promotes and demotes a node's `mode` from the Cluster Nodes page, behind the flows, the connect audit and seven clean days; every token MAIN hands a node carries the lease it may serve on without MAIN; the node's agent verifies and keeps that lease and anchors MAIN's clock; past the lease's window a node refuses new viewers and, past the drain, the sessions still running — behind `lb_lease_fence`, off until an operator turns it on; the restrictive commands, the stream-secret and credential rotations, `rotate_sign_key` and the manual `cluster:lockdown`; and the extension's compiled lease verdict with the credential actions (`strip_db_credentials`, `install_config`, MAIN's revoke). A seventh pins MAIN's panel key in every node's `xcvm_core` (`core.pin`: at the SSH install, else `node.root pin_core`, with the node's install_id in `cluster_nodes.install_id`), gives the operator the credential strip (the Cluster Nodes page's *Drop DB credentials* and `cluster:strip-credentials`), and rotates the panel's DB password (`cluster:rotate-db-password` on MAIN, sealed to each node's box key, and `cluster:set-db-password` on a node). An eighth keeps a credential-free node so through reinstalls and grants, tells MAIN when an agent cannot bind its relay port, drops the fanout's viewers under a lease fence, and gives MAIN a data-plane client of its own (`cluster:main-dataplane`, off by default). `api_mode_allowed` is still false, so promotion is the only path to mode 2 and a new node still enrols below it, at mode 1: flipping that flag is the cutover decision, and it stays with the operator.
- **Date:** 2026-09-25
- **Plan:** `docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md` (MAIN ↔ LB API communication, revision 3 plus corrections).
- **Extension side:** `xcvm_core` ADR-002, "Cluster API: the extension's half of MAIN ↔ LB communication", cluster API version 1.

## Context

Load balancers today reach MAIN's MariaDB and Redis directly (the `db_grant` model) and MAIN reaches them through `/api` with the live-streaming password. The plan replaces both with a signed, encrypted HTTP API started by each LB's Go `xc_agent`. The cryptographic root lives in `xcvm_core`. PHP runs the protocol, and the agent is its other end. Three codebases must therefore produce the same bytes.

## Decisions

### Who owns which part of the wire

| Part | Owner | Where it is defined |
| --- | --- | --- |
| XCVM-SEAL-v1, XCVM-BOX-v1, token derivation, panel signature domain, tag registry and record classes, lease, pin, DR bundle | `xcvm_core` | ADR-002; vectors `tests/Support/cluster_vectors.json` and the command registry `tests/Support/cluster_commands.json`, both generated from the extension's Rust and byte-identical to its `tests/conformance/fixtures/` copies (every repo pins their SHA-256) |
| Canonical request/response serialisation and the request/response MAC | panel | `Core\Cluster\Crypto\Canonical`; vectors `tests/Support/cluster_canonical_vectors.json` |
| Node signatures (`X-XCVM-Node-Sig`, relay auth, file digests from an LB) | panel | `Core\Cluster\Crypto\NodeSig` (domain `xcvm-node-sig-v1`, never the panel's `xcvm-sig-v1`) |
| Relay and file tickets, `X-XCVM-Relay-Auth`, `X-XCVM-File-Digest` | panel | `Ticket`, `RelayAuth`, `FileDigest` |

The Go agent's `internal/clustercrypto` (XC_VM_Fanout) passes both vector files.

### Canonical form

```text
req_ctx = "xcvm-req-v1" ‖ u32(proto) ‖ lp(agent) ‖ lp(METHOD) ‖ lp(path) ‖ lp(query)
          ‖ lp(content_type) ‖ lp(content_encoding) ‖ lp(node) ‖ u64(epoch) ‖ u64(ts_ms) ‖ nonce[16]
res_ctx = "xcvm-res-v1" ‖ SHA-256(req_ctx) ‖ u32(status) ‖ lp(content_type) ‖ u64(ts_ms) ‖ nonce[16]
mac     = HMAC-SHA256(K_mac_up | K_mac_down, "xcvm-mac-v1" ‖ lp(ctx) ‖ SHA-256(body))
```

The rules that make the form canonical:

- The query string is percent-decoded (`+` becomes a space), re-encoded per RFC 3986, and sorted by key then value.
- The method is upper-cased. The content type and encoding are lower-cased and trimmed.
- `node` is a uuid, or `sid:<n>` before enrolment finishes.
- The request context is the BOX context in both directions, and the response context hashes the request in, so a reply cannot be moved onto another request.
- The window is ±90 s.

### Fail closed

`ClusterCryptoFactory::create()` throws `ClusterUnavailableException` when:

- `xcvm_core` or `XC_VM::cluster_session` is missing;
- `cluster_info()['api']` is outside `API_MIN..API_MAX` (currently 1..1);
- sodium or AES-GCM is unavailable.

The node then stays legacy and `db_grant` remains the gate. No PHP implementation of the extension's secret parts ships: the binding, token MAC, session keys and panel signing with a seed live in `tests/Support/ClusterReference.php`. `ClusterCryptoFailClosedTest` checks that nothing under `Core/Cluster` signs as the panel or computes tokens.

### Schema numbering

The plan numbers its Phase 1 migrations 026–032. 026 and 027 were already taken (`ssh_hostkey_sha1`, `fanout_enabled`), so they ship as 028–034. The contents are unchanged. The plan's Phase 10 `033_drop_connection_sync_timer` will get the next free number.

| Migration | Contents |
| --- | --- |
| 028 | the 19 settings |
| 029 | `cluster_nodes`, `cluster_node_epochs`, `cluster_meta` |
| 030 | `cluster_commands` |
| 031 | enrolment codes and requests |
| 032 | audit, nonces, reservations |
| 033 | `crontab.role` plus a disabled `cluster` row |
| 034 | `cluster_changes`, `cluster_stream_ver` |

### Settings

`Core\Cluster\ClusterSettings` holds the defaults and bounds. It lives in `Core/Cluster`, which ships to LBs, not in `Domain/Cluster`, which the plan strips from LB builds, so nodes can apply the same bounds. It clamps silently and refuses only where clamping would change intent:

- a taken or out-of-range port;
- a host name that is an IP;
- `https_required` without working HTTPS;
- enabling the API without the extension;
- `api` mode before the cutover.

**Reading an enum setting.** Every reader of `cluster_transport`, `lb_new_node_mode` and `lb_revocation_mode` goes through `ClusterSettings::enum()`. It returns the stored value only when it is exactly one of `ENUMS`, and the `ENUMS` default otherwise.

- **Before.** `ClusterPolicy::current` published a stored `cluster_transport` it did not know (`bogus`, `HTTPS_REQUIRED`, ` auto`) raw as the policy's `transport`, and listed only HTTP URLs for it.
- **Now.** It publishes such a value as the default, `auto`, with `auto`'s URLs: HTTPS first once the self-probe feeds the policy and MAIN lists HTTPS.
- `normalize()` never stores such a value, so only a direct SQL edit can produce one. Every other reader already treated it as its default.

### MAIN's API (Phase 2)

`Domain\Cluster\ClusterApi` serves `/cluster/v1/<op>` behind `Public/cluster/index.php`. It is transport-free and tested without a web server. `ClusterApiTest` runs every flow against a PHP fake of the extension's token half, and opt-in against a real test-hooks `xcvm_core` (`XCVM_CLUSTER_API_REAL=1`). All of it is MAIN only: the LB build strips `Domain/Cluster`, `Public/cluster` and `cluster:init`, and the LB nginx has no `/cluster/` route.

| Op | Method | Auth | Node state | Reply |
| --- | --- | --- | --- | --- |
| `health` | GET | none; works without the DB and with the API disabled | any | panel-signed (`hlt`): time, proto range, panel keys |
| `challenge?cn=` | GET | none | any | panel-signed (`hlt`): a single-use 32-byte challenge, licence state, policy |
| `enrol_complete` | POST | session + node signature, epoch 1 only | `enrolling`, before `enrol_deadline` (30 min); the activating `UPDATE` holds only for the row it authenticated against, still `enrolling` (same `gen` and uuid), so a revocation (403 `NODE_REVOKED`) or a re-enrolment's new row (409 `NOT_ACTIVE`) that lands meanwhile keeps its state | BOX: state, mode, flows, gen, policy |
| `token_refresh` | POST | session + node signature | `active`, `quarantined` | BOX: the next epoch's sealed token |
| `enrol_code` | POST | `sid:<n>`, epoch 0: code MAC (K_req) + signature by the key being enrolled; body SEALed to the panel box key | none yet | MAC'd (K_res): `pending_approval` |
| `enrol_code_status` | POST | `sid:<n>`, epoch 0: code MAC (K_req) | the request's | MAC'd (K_res): the state; once approved, epoch 1 panel-signed (`pre`) |
| `token_rekey` | POST | node signature (epoch 0, no MAC), body SEALed to the panel box key, a challenge | `active`, once a minute | panel-signed (`pre`): a new epoch's sealed token |
| `hello` | POST | session | `active`, `quarantined` | BOX: state, mode, flows, proto, policy |
| `heartbeat` | POST | session | `active`, `quarantined` | BOX: state, mode, flows, `pending` |
| `commands` | POST | session | `active` | BOX: signed commands after `after_seq`, held up to `wait_ms` (≤ 20 s) |
| `ack` | POST | session | `active`, `quarantined` | BOX: `ok` (own commands only) |

A session request is checked in this order. Nothing is written, not even the nonce, before the MAC and, for token operations, the node signature have verified:

1. header syntax and the 8 MB body cap;
2. protocol range (426 `PROTO` carries min and max);
3. the ±90 s window;
4. the node (`sid:` identities are refused: they are only valid on the two code ops) and its revocation;
5. the extension's session for the named epoch (its refusals map to `NODE_REVOKED`, `LICENCE_INVALID`, `CLOCK` or `TOKEN_EXPIRED`), whose server id and epoch must be the node row's `server_id` and the header's epoch (else 401 `TOKEN_EXPIRED`, detail `RECORD`, as the extension's own refusal of another node's record). Steps 4 and 5 read the node's row and the epoch's record from the cluster bus while it holds them (fifth cluster bus increment);
6. the request MAC;
7. the node signature, verified with the key from the extension-sealed epoch record, never the DB row;
8. the nonce claim (401 `REPLAY`, with `retry_after_ms` when MAIN only cannot vouch for the nonce yet: see the second cluster bus increment);
9. the node state;
10. a bus permit, for `hello`, `config` and `conn_snapshot` (503 `RATE_LIMITED`);
11. opening the BOX;
12. an ingest permit, for the ingest ops, P0 `events` batches from their reserve (503 `RATE_LIMITED` with `lane`: see the fourth cluster bus increment).

Refusals are panel-signed (`den`) and name the node and the request nonce. Two replies are unsigned, and agents treat both as a transport error: `STARTING` without the extension, and 503 `ERROR` (`{"v":1,"reason":"ERROR"}`) for a request MAIN failed to answer, whatever the handler or the entry point threw (the extension refusing to sign even a denial, a `TypeError` from it, a lost connection). `ClusterApi::serve()` and `Public/cluster/index.php` answer it through `ClusterApi::failed()`, which logs the path, the refusal reason or the exception's class and message, and its file and line to the panel's error log (`FileLogger`, type `cluster`), and names none of it in the reply. While MAIN's cluster pools are starting, `STARTING` is panel-signed (see "The cluster pools").

Token epochs:

- Enrolment (`EnrolmentService::issueFirst`, called by the SSH install flow in a later increment) mints epoch 1.
- `token_refresh` mints the next epoch for the agent's per-epoch key. A retry with the same key gets the same unused token back. A retry with another key replaces the unused epoch under the same number. A node therefore never holds more than two valid epochs. Migration 035 adds `cluster_node_epochs.agent_eph_pub` for this.
- Re-enrolment and revocation raise the extension's generation floor before touching the database, so a restored row cannot open a session.

Re-key (`token_rekey`), for a node whose tokens have all expired while its keys are intact:

- The agent fetches `GET challenge?cn=<uuid>`, then sends `POST token_rekey` with `X-XCVM-Epoch: 0` and no `X-XCVM-Sig`, as there is no session. The body is XCVM-SEAL-v1 to the panel box key, purpose `rekey`, with the request context as the SEAL context. It carries the challenge, a fresh per-epoch X25519 key, and `instance_id`, `boot_id` and `agent_version`. The request is node-signed like `token_refresh`, with the enrolled key from `cluster_nodes`, as no epoch record is left.
- MAIN checks, in order:
  1. headers, protocol and window;
  2. the node exists and is not revoked;
  3. the node signature;
  4. the nonce claim;
  5. the node is `active`;
  6. a bus permit (503 `RATE_LIMITED` with `retry_after_ms` and `op`), so a busy MAIN spends neither the minute nor the challenge;
  7. the once-a-minute limit (429 `RATE_LIMITED` with `retry_after_ms`), charged per authenticated attempt;
  8. the SEAL opens (`cluster_open_sealed`);
  9. the challenge is live (180 s) and unused (401 `CHALLENGE`). Using it is a claim on `used:chal:<uuid>` (`NonceStore`), so two concurrent uses cannot both win;
  10. the attestation: an `instance_id` other than the enrolled one quarantines the node (409 `NOT_ACTIVE`, state `quarantined`);
  11. the mint. A licence refusal returns 403 `LICENCE_INVALID` and leaves the node's rows as they were.
- The new epoch follows the node's current epoch and any epoch row still held. Every other epoch row is dropped, so a re-key whose reply was lost leaves nothing behind.
- The reply is panel-signed with tag `pre`, a granting record the extension signs only under a valid licence. It names the node and the request nonce, and carries the token sealed to the agent's new key.
- Nodes installed from this release get `panel_box_pub` in their install data. Nodes enrolled earlier take it once from the signed `health` document.
- The agent re-keys when a session op is refused with `TOKEN_EXPIRED` (or `LICENCE_INVALID`, the hard revocation mode), or when it holds no epoch. While the challenge says `licence_ok: false`, or the node is quarantined, it asks again every 60 s. Otherwise it backs off with jitter. Only `NODE_REVOKED`, `UNKNOWN_NODE` and `ENROL_EXPIRED` stop it. A node whose first token expires before `enrol_complete` still stops, because MAIN re-keys active nodes only.

Other behaviour:

- `hello` from an active node with a different `instance_id` quarantines it. That is authenticated evidence of a clone.
- The first authenticated heartbeat sets `servers.status = 1`. Heartbeat telemetry is kept in shadow in `tmp/cluster/tel_<id>.json`, or on the cluster bus in `cl:tel:<id>` (third bus increment).
- `cluster:init`, or enabling the API in Settings, creates the extension root and records the panel keys and `ready_at` in `cluster_meta`. Enabling it from Settings runs as php-fpm, so the files belong to the user that serves the API. Liveness counts silence from `max(last_seen_at, ready_at)`.
- Under `cluster_transport = auto`, HTTPS URLs appear in the policy only once the self-probe result is recorded. Until then, `auto` publishes HTTP URLs.

### Enrolling a new LB at install (SSH)

`LbInstallFlow::provisionCluster` runs at the end of an LB install, over the install's verified SSH session, when `cluster_api_enabled` is on and the extension is available. Otherwise the node stays legacy (mode 0), exactly as before.

1. MAIN pushes `xc_agent` from its cache. `console.php agent_binary` keeps one SHA-256-verified copy per arch in `bin/xc_agent/cache/`, taken from the XC_VM_Fanout release (`xc_agent-linux-<arch>`, the same tag as `xc_fanout`). LBs never download the agent themselves.
2. `xc_agent keygen` makes the node's Ed25519 and X25519 keys and the first per-epoch key on the node, and prints only the public halves and the SAS. MAIN recomputes the SAS and refuses keys that do not match it.
3. `xc_agent probe` checks MAIN's signed `/cluster/v1/health` from the node, with the panel key it received over SSH, at the URLs of the node's policy. If this fails, the install stops (status 4) before any token exists.
4. MAIN mints epoch 1 (`EnrolmentService::issueFirst`). A licence refusal stops the install with `CLUSTER_LICENCE_REQUIRED`.
5. `xc_agent install` opens the token with the per-epoch key and checks the panel's signature, node and server before saving `config/cluster/agent.json` (0600).
6. The agent starts (`bin/xc_agent/run.sh`) and finishes with `enrol_complete`.

A missing agent binary, for example when GitHub is unreachable and there is no cached copy, leaves the node legacy and does not fail the install.

`run.sh` is a flock-guarded respawn loop. `service` boot and the RootSignals cron keep it alive on enrolled nodes. The agent exits 3 when MAIN has stopped the node: it was revoked or is unknown, or its enrolment was never completed. An expired token re-keys instead. `run.sh` then writes `bin/xc_agent/stopped` and nothing restarts it until the node is enrolled again. The `/etc/xc_vm/cluster` root pin, which the root executor needs, arrives with Phase 4.

### Enrolling an existing LB (SSH)

`console.php server:enrol <id> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]` runs the same `provisionCluster` on a node that is already serving, without reinstalling it. The credentials travel as they do for `server:install`: a 0600 file, read and then deleted. The command differs from the install flow in three ways:

- **No trust on first use.** The SSH host key must match `--expect-hostkey` or the fingerprint stored at install (`ssh_hostkey_sha1`). The admin reads the key on the node with `ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub`.
- **The node must already run this release.** Its `bin/xc_agent/run.sh` must exist; update the node through the legacy `update` signal first.
- **A failure never marks the node failed.** It keeps serving the legacy way.

Re-enrolling stops the node's running agent before replacing its identity. The generation goes up, so every token of the previous identity stops working.

`cluster:reenrol` runs this path for many nodes in one go; see *Re-enrolling the fleet*.

### Enrolling by code (break-glass)

For a node MAIN cannot reach over SSH (NAT, lost keys). `Domain\Cluster\EnrolCodeService` owns it:

```text
code   = base32(u8 1 ‖ u32 sid ‖ u8 len ‖ main_url ‖ SHA-256(panel_sign_pub)[0:16] ‖ secret[16]), dash-grouped
K_req  = HKDF-SHA256(secret, salt = u32 sid, info = "xcvm/enrol-code/v1/req")
K_res  = HKDF-SHA256(secret, salt = u32 sid, info = "xcvm/enrol-code/v1/res")
lookup = SHA-256(secret)
```

1. `console.php cluster:enrol-code <id> [--url=http://host:port]` issues a code. MAIN keeps K_req and K_res sealed with `cluster_seal_local`, under a row MAC keyed by K_res, and never keeps the secret. There is one live code per server: a new one supersedes unused codes and pending requests. A code lives 30 minutes.
2. On the node, `xc_agent enrol <code>`:
   - fetches the signed `health` at the code's URL;
   - pins the panel key whose hash the code carries;
   - makes the node's keys;
   - sends `enrol_code` (MAC'd with K_req, signed by the new node key, body SEALed to the panel box key);
   - prints the SAS.

   The request's per-epoch key waits in `cluster_enrol_requests.agent_eph_pub` (migration 036).
3. `console.php cluster:enrol-approve <id> <SAS>` approves the request. Only at approval does MAIN run `startEnrolment` (raising the generation) and mint epoch 1. The approval is panel-signed (`pre`) and stored.
   - Five wrong SAS entries reject the request.
   - A licence refusal leaves it pending.
   - `--reject` rejects it outright.
4. The node polls `enrol_code_status` every 10 s. The approved reply is MAC'd with K_res and carries the panel signature. The node checks it, opens the token with its per-epoch key, and writes its state as the SSH install does. The agent then finishes with `enrol_complete`.

Other rules:

- A wrong code MAC records nothing: no nonce and no attempt. The attempts count only the admin's wrong SAS entries, so a code cannot be burned without its secret.
- Under a used code, other keys get a 409 `ENROL_CONFLICT` and an audit entry, never a silent replacement. The same keys again (a lost reply) are accepted as they stand.
- `xc_agent enrol` refuses to replace an identity that holds tokens unless given `-force`.
- The pin blob (`cluster_pin`) waits for Phase 4, as it does on the SSH path.

### Cluster Nodes page and housekeeping

*Servers → Cluster Nodes* (`cluster_nodes`, permission `servers`) is the admin side of the CLI commands, through `Domain\Cluster\ClusterAdmin`. It:

- lists enrolled nodes with state, liveness (`NodeHealth`), mode, epoch, token expiry, last heartbeat and agent version;
- shows code enrolments waiting for a SAS, with *Approve* (SAS field) and *Reject*;
- issues enrolment codes (optional MAIN URL; the code is shown once);
- revokes nodes.

Actions POST back to the page; admin sessions are `SameSite=Strict`. The deciding admin is recorded in `decided_by` and in the audit log.

`cron:cluster` runs every minute (migration 037 enables its crontab row). It deletes expired epochs, which erases their `z`, the replay cache and used challenges, unused expired codes, and decided requests after a day. It returns at once on load balancers, which get the crontab verbatim but not `Domain/Cluster`, and while the API is disabled.

### Telemetry (Phase 3)

Every agent samples its host each second (`clusteragent.Sampler`) and sends the latest sample in each heartbeat. The sample covers:

- CPU (user + system over user, nice, system and idle, as the watchdog measured it), cores and model, load;
- memory, disk of the deploy root, kernel and uptime;
- stream producers (ffmpeg, `xc_fanout remux`) and the xc_vm PHP-FPM worker pids;
- per-interface rates, totals and link speed.

What only PHP knows, nginx requests per second, the fanout daemon's status and the devices (next section), the LB's watchdog writes to `config/cluster/local.json`. The agent forwards that file while it is under 10 s old and at most 64 KiB, parsed as a JSON object and re-encoded.

For a node with the TELEMETRY flow on (mode ≥ 1, toggled per node on the Cluster Nodes page), `HeartbeatService` makes the sample authoritative:

- **Every 5 s:** `servers.watchdog_data`, `last_check_ago`, `requests_per_second` and `php_pids`; without the Redis handler, also `connections` and `users`, counted as the watchdog counted them. `toWatchdogData()` keeps the legacy `SystemInfo::getStats()` keys and order, plus `cpu_average_array` and `fanout`. `ClusterTelemetryTest` pins that against `getStats()`'s source. `network_interface` selects interfaces as before.
- **Every minute:** the `servers_stats` row the LB's `cron:servers` wrote.
- **Devices, GPUs and disk I/O:** from the node's `local.json` (next section).

The node learns its mode and flows from MAIN's authenticated replies. The agent writes them to `config/cluster/flows.json`, and `Core\Cluster\NodeFlows` reads them. With TELEMETRY on:

- the LB's watchdog stops writing its `servers` row and only refreshes `local.json`;
- `cron:servers` skips its `servers_stats` row;
- `network.py` is stopped.

A stopped agent removes `flows.json`, so the node falls back to the legacy paths.

### Telemetry (Phase 3, second increment): devices, GPUs and disk I/O

**Before.** `toWatchdogData()` reported `audio_devices`, `video_devices`, `gpu_info` and `iostat_info` empty. A TELEMETRY node showed 0 % I/O wait on the dashboard and its server page, and its `servers_stats` rows had no GPU or iostat history.

**The node.** Only PHP probes these, so the watchdog's `local.json` now carries them. The agent is unchanged: it already parses the file as a JSON object (at most 64 KiB, under 10 s old) and re-encodes it as `telemetry.local`. Key order and number formatting are not kept (object keys come out sorted, `7.0` arrives as `7`); MAIN reads keys, not order, and relies on no int/float distinction.

This differs from the plan, whose "What moves to Go" table moves the `SystemInfo` forks into the agent in Phase 3. These four probes stay in the node's PHP, every 30 s: the agent already forwards `local.json`, so no agent release is needed, and `getStats()` and the node keep one probe.

- **Probe.** `SystemInfo::getDevices()`: each section is `[]` unless its tool is installed (`iostat`, `nvidia-smi`, `v4l2-ctl`, `arecord`), as `getStats()` decided. `getStats()` now takes its four sections from it, so the two cannot drift apart. For `local.json` each tool runs under `timeout -k 1 5` (`LocalTelemetry::PROBE_TIMEOUT`), so a hung `nvidia-smi` cannot stall the watchdog while the agent keeps the node looking healthy. A tool cut short reports `[]` (`nvidia-smi`, `iostat`) or the devices it listed by then (`v4l2-ctl`, `arecord`). `getStats()` waits for its tools, as before.
- **Every 30 s.** The probes shell out, so `Core\Cluster\LocalTelemetry::refresh()` reuses a probe for 30 s. The watchdog runs one pass per process and re-execs, so the last probe is kept in `tmp/watchdog_devices.json` (`{"t": unix time, "devices": {…}}`), not in a variable. `local.json` is still rewritten every pass (about 3 s). When a probe is due, the file is written first with the last probe's sections and again after the probe, so a slow probe never ages it past the agent's 10 s. With no probe under a minute old (none, an unreadable cache, a clock that went back) the probe runs first, so an old probe is never reported as current.
- **Size.** The agent drops a `local.json` over 64 KiB, and with it the requests per second and the fanout status. `LocalTelemetry::encode()` keeps the file at most 60 KiB (61 440 bytes). Over that, it empties every GPU's `processes` list first, then the largest device section, one at a time. If that still does not fit, only `requests_per_second` and the four sections as `[]` are kept; `fanout` is dropped, so MAIN keeps its last value.

**The contract: `telemetry.local`.** Same heartbeat, same op, no new lane or event. The keys, all optional:

| Key | Type | Probe (legacy shape) |
| --- | --- | --- |
| `requests_per_second` | int | nginx `stub_status` |
| `fanout` | object or null | `FanoutClient::status()` |
| `audio_devices` | list of strings (`hw:CARD=…` names) | `SystemInfo::getAudioDevices()`, `arecord -L` |
| `video_devices` | list of `{name, video_device}` | `getVideoDevices()`, `v4l2-ctl --list-devices` |
| `gpu_info` | `{attached_gpus, driver_version, cuda_version, gpus: [{name, power_readings, utilisation, memory_usage, fan_speed, temperature, clocks, uuid, id, processes: [{pid, memory}]}]}` or `[]` | `getGPUInfo()`, `nvidia-smi -x -q` |
| `iostat_info` | `{"avg-cpu": {user, nice, system, iowait, steal, idle}, disk: [{disk_device, …}]}` or `[]` | `getIO()`, `iostat -o JSON -m` |

A device section is `[]` when its tool is absent, timed out, or a trim dropped it. An older node's PHP does not write the four keys. The whole object is at most 64 KiB encoded (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`); MAIN enforces that too (below).

**MAIN.** `toWatchdogData()` takes each section from `telemetry.local` when it is an array, else `[]`.

- **No `local`** (the file is stale, missing or too big, or the watchdog is down): every section is `[]`, not the last value, since stale figures would look live. `fanout` keeps its last value, as before.
- **Over 64 KiB.** MAIN does not trust the agent's cap alone, since `gpu_info` and `iostat_info` are kept in `servers_stats` for `servers_stats_retention_days`. A `local` whose encoding is over `HeartbeatService::MAX_LOCAL` (65 536 bytes) is treated as absent: the four sections `[]`, `requests_per_second` 0, `fanout` its last value. The heartbeat itself is never refused for it.
- **Shape.** Only what the panel reads is checked: `audio_devices` keeps its strings and `video_devices` its objects, both as lists; `gpu_info.gpus` keeps its objects; `iostat_info["avg-cpu"]` keeps its numeric figures, because the dashboard rounds `iowait`.
- **Where it lands.** Through the existing path: `watchdog_data` every 5 s, and `gpu_info` and `iostat_info` in the minute's `servers_stats` row. The dashboard's and the server page's I/O wait (`StatsAjaxController`, `server_view.php`, `ServerViewController`) now work for TELEMETRY nodes. The `servers` columns `gpu_info`, `video_devices` and `audio_devices` (GPU cards, profile editor, capture streams) already came from `node.inventory`.
- **Shadow copy.** `tmp/cluster/tel_<id>.json` now takes up to 128 KiB (`HeartbeatService::MAX_TELEMETRY`), because `local.json` alone may be 64 KiB.

**Cost.** Each heartbeat carries the sections although they change at most every 30 s: a few KiB as a rule, 60 KiB every 2 s per node at worst. An agent that sent `local` only when it changed would save that, but MAIN would then need to keep the last one; nothing does that yet.

`ClusterTelemetryTest` pins the mapping, the shape checks, the `servers_stats` columns, `refresh()` (the 30 s reuse across passes, the write before a due probe, the probe first without a recent one), the timeout wrapper, the node's size cap and its boundary, MAIN's 64 KiB cap and the shadow copy's room for it, and `local.json` as `LocalTelemetry` writes it, re-encoded as the agent does, read back by `toWatchdogData()`.

### Liveness (Phase 3)

`LivenessService::tick()` runs every second in MAIN's signals daemon, with `cron:cluster` as the per-minute fallback. It judges the active nodes whose TELEMETRY flow is on by their silence (`NodeHealth`):

| State | When | Effect on routing |
| --- | --- | --- |
| `ok` | heard within 10 s | online, whatever the legacy `last_check_ago` says |
| `suspect` | silent over 10 s | still online; capacity weight doubled |
| `offline` | silent over `cluster_offline_after_sec` (30 s) | offline |

Other rules:

- A node never heard from counts as offline only once the loop has been up for that long.
- The result goes to `tmp/cluster/health.json`. `Core\Cluster\ClusterHealth` is how `ServerRepository::getAll()` (`server_online`, `cluster_health`) and `ConnectionTracker::getCapacity()` read it.
- Each transition rewrites the servers cache at once and is audited (`node.health`).
- **Hysteresis** (`NodeHealth::settle`): a published state gets worse at once, but better only after 30 s of steady health (`NodeHealth::RECOVER_MS`). An offline node that speaks again is `suspect` at once, so routing resumes at half weight, and `ok` after the steady period. Without this, a node whose heartbeats straddle the 10 s threshold flips on every gap: 10 flips a minute at 11.5 s gaps, one with it (`NodeHealthHysteresisTest`). The steady period is longer than the suspect threshold on purpose; at 10 s such a node would recover between gaps and flap as often. Since when each node has been ok is kept in `health.json` (`ok_since`), so the period survives passes that publish nothing.
- **Fleet silence guard:** when over half of those nodes, and at least two, are silent together, MAIN suspects itself. It holds every node at its last published state instead of marking any offline. It audits `cluster.fleet_silence`, and the Cluster Nodes page shows an alert until the silence clears. A `cluster_ctl` listen queue lasting over 5 s raises the same guard, with a reason, an audit and an alert of its own. It holds only the nodes it may have silenced, for 4 offline windows at most, and for one offline window after it ends (Phase 2, fifth increment).
- Nodes without the flow keep the legacy 90 s rule. The Phase 6 orphan purge at `cluster_orphan_conn_ttl_sec` is not part of this loop yet.

### MAIN endpoint changes (Phase 3)

The case: MAIN's HTTP broadcast port changes on its server page, and the cluster API has no port of its own (`cluster_api_port` = 0). `ClusterEndpoint::recordChange()` (since the second increment, `recordMainChange()`) then runs before the new ports are applied:

1. It bumps `cluster_policy_ver`. Heartbeat replies carry that version, and an agent that sees a newer one says hello again and gets the new URLs within about 2 s.
2. It keeps the old port for 7 days in `cluster_legacy_ports` (migration 038, with `cluster_policy_ver`).

While a port is kept:

- The policy lists it after the new URLs, so a node that was offline during the change still finds MAIN.
- The root-side `set_port` handler renders `bin/nginx/conf/cluster_legacy.conf` on MAIN: one server block per kept port, serving `/cluster/v1/` and a 404 for everything else. `nginx.conf` includes it by glob, so a missing file is no error. Since the third Phase 2 increment this is `cluster.d/old_port.conf`, rendered as xc_vm (see "The rendered nginx config").

When the 7 days are up, `cron:cluster` drops the port, bumps the policy again and re-applies MAIN's ports so nginx releases it. It now renders the nginx config again instead. Since the sixth Phase 2 increment it drops a port sooner, once every node uses the new URL.

This was checked with nginx 1.24:

- `nginx -t` passes with and without the file.
- On the old port, only `/cluster/v1/` reaches PHP.

A change of MAIN's HTTPS broadcast port, `server_ip` or `private_ip` was not announced and kept no old URL. The second increment, below, adds them.

### MAIN endpoint changes (Phase 3, second increment): the HTTPS port and MAIN's addresses

**Before.** Only a change of MAIN's HTTP broadcast port or `cluster_api_port` kept anything, and only those two, `cluster_transport` and `cluster_main_host` raised `cluster_policy_ver`. A new HTTPS broadcast port, `server_ip` or `private_ip` reached a node only at its next hello, and the old URL left the policy at once. That held whether the change came from MAIN's server page or from `cron:root_signals`, which rewrites MAIN's `server_ip` from its interface every minute.

**Now.** `ClusterEndpoint::recordMainChange($rOld, $rNew, $rSettings, $rActor)` handles every change of MAIN's `servers` row. It replaces `recordChange()` and runs:

- from `ServerService::process()` (MAIN's server page, and the admin API's server edit) through `announceMainEndpoints()`. It runs once the row is stored and before the new ports are applied, so nginx gets a kept old port with the new ones, as in the first increment;
- from `cron:root_signals` when it rewrites MAIN's `server_ip` (`RootSignalsCronJob::rewriteServerIP()`, actor `system`). The helper gets MAIN's row as it was and returns it with the new address, which the cron assigns. The cron therefore cannot pass it a row that already carries the new address, which would announce nothing.

It compares the policy's URLs (`ClusterPolicy::current`, without the kept entries) for the row before and after:

- **The same list:** nothing happens and the version stays. Examples: a save that changes the server name, or an HTTPS port no node is sent to.
- **A different list:** it is announced, but only while a node may use the URLs. The API must be on, and a `cluster_nodes` row must have `mode` ≥ 1 and not be `revoked`. An `enrolling` node counts, since it dials the URLs in its `cluster.json`. When that query fails, a node is assumed: a needless announcement costs each node one hello, and a missed one can strand them. Then:
  1. an old HTTP broadcast port is kept in `cluster_legacy_ports`, as before (while `cluster_api_port` = 0);
  2. every URL the policy listed before and does not list now (counting the kept ports) is kept for 7 days in `cluster_legacy_urls`. An `https://` URL is kept only on the HTTPS broadcast port the old row stored (see "The HTTPS port"). Migration 044 adds this `settings` column as `mediumtext`, holding `{"<url>": <unix expiry>}`. A URL the policy lists again leaves the column. At most 8 URLs (`MAX_URLS`) are kept, the latest change first;
  3. `cluster_policy_ver` goes up in the same `UPDATE`. The audit event is `cluster.endpoint_change`, with `urls_from`, `urls_to`, `kept_urls` and `kept_ports`.
- The kept lists are read again from the database before they are merged (`ClusterEndpoint::stored()`). A process that loaded its settings earlier (the root cron, a panel worker) then cannot drop what another process kept. `prune()`, `ClusterNginxConfig`'s render and a `cluster_api_port` save read them the same way: `stageApiPort()` renders from the kept ports and URLs as stored, and `recordApiPortChange()` merges into the kept ports as stored.

**The HTTPS port.** A change is announced only when the policy lists HTTPS: `https_preferred` or `https_required`, with `enable_https` 1 or 2 and a TLS name. `auto` lists no HTTPS until the self-probe feeds the policy. The old HTTPS URL (`https://<tls name>:<old port>/cluster/v1/`) is kept, and nginx keeps serving the old port:

- `cluster.d/old_port.conf` gets a server with `listen <port> ssl;`, `include ssl.conf;` (the public server's certificate and TLS settings), `include cluster_locations.conf;` and a 404 for everything else. It sits beside the old plain-HTTP ports.
- Only the HTTPS broadcast port the old row stored is kept. A row with no HTTPS port (`https_broadcast_port` NULL, an empty `ports/https.conf`) still gets `https://<tls name>:443/cluster/v1/` in the policy, from its default. nginx never held that port, and another program, a reverse proxy say, may hold it. That URL is not kept when an HTTPS port is added.
- So a kept HTTPS port is one nginx served over TLS until the change. nginx keeps the socket across the reload, so the port is not checked for being free, as with an old HTTP port. The check could not work there anyway. When `set_port` renders, it has already rewritten `ports/https.conf`, and the running nginx still holds the old port, so a bind test fails on nginx's own socket.
- A port that another server here already serves gets no TLS server: the public server, the dedicated port, or a kept plain-HTTP port (listed first).
- `ssl.conf` is already in the public server, so the extra server adds no new way for `nginx -t` to fail. Certbot's files are owned by xc_vm, so the `nginx -t` that runs as xc_vm can read the key.

**Addresses.** nginx listens on every address, so an old `server_ip` or `private_ip` needs nothing from nginx. Its URL answers while the old address still reaches MAIN, for example a second address, a NAT or a floating IP. Otherwise the agent moves on to the next URL.

**Listing.** `ClusterPolicy::current` lists the kept URLs last, after the current URLs and the old ports' URLs. The latest change comes first, and duplicates are dropped. Each URL is listed only while MAIN serves its port with its scheme:

- an `http://` URL, on a port where MAIN answers the API over plain HTTP: the API's own port, the public server's HTTP ports, or a kept old port;
- an `https://` URL, on any other port.

The transport's rules hold for kept URLs too. A kept `https://` URL is listed only while the policy lists HTTPS: `https_preferred` or `https_required`, or `auto` once MAIN's certificate verifies, with `enable_https` 1 or 2. So none is listed under `http`, or under `auto` without a verified certificate. A kept `http://` URL is never listed under `https_required`. A kept URL that is current again is listed once, where it is current. nginx keeps serving an old HTTPS port while its URL is not listed, until it expires.

**Expiry.** `cron:cluster` (`prune()`) drops expired URLs along with expired ports, raises the version, and renders nginx again, which releases an old HTTPS port.

**Wire.** There is no new op, field or header. `policy.main_urls` appears in the replies to hello and enrol_complete, in the signed challenge, and in `cluster.json`. For up to 7 days after the current URLs and the old ports' URLs, it may now also carry the kept old URLs:

- `http://<old address>:<port>/cluster/v1/`
- `https://<tls name>:<old HTTPS port>/cluster/v1/`

Each change raises `policy_ver`, which the heartbeat reply carries, as before.

**What today's agent does.** It needs nothing new:

- A heartbeat reply with a higher `policy_ver` makes it say hello, and it adopts the policy from that reply.
- It tries `main_urls` in order, and a URL it could not reach goes last for 10 minutes. The kept URLs, listed last, are only dialled when the current ones fail.
- A node that was offline during a change comes back on its stored URLs. If one of them still reaches MAIN (the old address, or a kept port), the node gets the new policy at hello.
- It remembers the `http://` URLs of every policy it held (up to 8). It uses them only for the challenge over HTTP under `https_required`.

**The agent's contract.** For the Go half, built in xc_vm_fanout #31 (plan §3: "Agents keep their last 3 known-good URL sets"):

1. **Known-good sets.**
   - A URL set is a policy's `main_urls` as the agent adopted it, in its order, with its `policy_ver`.
   - An authenticated answer is one of two things. The first is a reply whose MAC verifies and whose BOX opens. The second is a denial whose panel signature verifies and that names this node (`node`) and this request's nonce (`req_nonce`), as `Client.denial()` checks today. A denial that carries `node` and `req_nonce` as null is not one, since it can be replayed. Examples are `503 STARTING`, `503 DISABLED`, and the `503 DB` sent when MAIN cannot reach its database before it reads the request.
   - Only the current policy's set is recorded. It becomes known-good when a request to one of the current policy's `main_urls` gets an authenticated answer. A `403 HTTPS_REQUIRED` denial does not count: the URL reached MAIN, but MAIN refuses ops over it. An answer through a fallback URL records nothing. Adopting a policy does not make its set known-good.
   - The agent keeps the last 3 known-good sets, newest first, in its state file as `known_good_urls` (Go tag `json:"known_good_urls,omitempty"`). It is a list of `{"policy_ver": int, "main_urls": [string]}`.
   - Two sets are the same when their `main_urls` are equal element by element, order included. `policy_ver` is not compared. A set that is the same as a stored one replaces it, taking the new `policy_ver`, and moves to the front. Any other set goes in front, and the fourth is dropped.
   - The state file is written only when `known_good_urls` changes. Confirming the front set again under the same `policy_ver` writes nothing, so a heartbeat every 2 s does not rewrite the file.
   - `Install` empties `known_good_urls`. It is the step that stores an enrolment's or re-enrolment's `main_urls` and first token. `Keygen` of a new identity already starts from an empty state. So the sets of a previous enrolment, or of a previous MAIN, are never dialled.
2. **Dialing.**
   - The fallback URLs are the URLs of the known-good sets that the current policy does not list. They go newest set first, each set in its own order, and each URL once.
   - While the current policy's `transport` is `https_required`, only the `https://` fallback URLs are dialled. Plain HTTP stays for the challenge alone, as today (item 3). MAIN would refuse the MAC'd ops over it with `403 HTTPS_REQUIRED`.
   - The order has three groups. First come the current policy's URLs that are not in backoff, in the policy's order. Next come the fallback URLs not in backoff, in the order above. Last comes every URL in backoff: the current ones, then the fallback ones, each in the same order. This is today's `urls()` applied to the current URLs followed by the fallback URLs.
   - A URL that could not be reached gets the same 10-minute backoff as today. A fallback URL gets it too.
   - The request itself is unchanged, since the MAC context holds the path, not the host.
   - A TLS URL is verified against its own host name, as today.
3. **Nothing is adopted from where MAIN was reached.**
   - A fallback URL is only a dial target. When a request succeeds only through one, the agent says hello, as for a newer `policy_ver`. It then adopts the policy from the MAC'd reply by the existing rule: never a lower `policy_ver`.
   - The challenge over plain HTTP under `https_required` (`PolicyOverHTTP`) is unchanged. It dials the `http://` URLs of the policies the node held (`http_urls`, at most 8), never the known-good sets.
4. **Kept URLs need no special case.** They are ordinary entries of `main_urls`, tried last. They leave the policy after 7 days. They leave it sooner once MAIN no longer serves their port with their scheme, or once the transport no longer lists their scheme.
5. **Nothing else changes on the wire:** no new op, field, header or setting. The only new state is `known_good_urls` in the agent's state file.

**Differs from the plan.**

- **Announced, not pushed.** The plan's MAC'd `policy.update` command is not used. As in the first increment, the heartbeat reply's `policy_ver` makes each agent fetch the policy at hello, within about 2 s. That policy carries the new URLs first and the old ones after them.
- **Announced after the change, not before it.** An admin's save stores the row first, then announces. The old URL stays listed and served, so a node that has not refetched yet keeps working. The automatic rewrite reacts to a change that has already happened on MAIN's interface. There, the old URL helps only while the old address still reaches MAIN.
- **Only while a node is in mode ≥ 1 and not revoked**, as the plan says. The HTTP broadcast port change now follows this rule too; before, it was announced whenever the API was on. A `cluster_api_port` change (a settings save) is still announced whenever the API is on.
- **An HTTPS port no node uses is not announced.** Today the policy lists HTTPS only under `https_preferred` or `https_required`.
- **The old URL is kept as a URL, not as a port:** either an old address on the current port, or the old HTTPS port under the TLS name.
- **Migration 044, not 042.** 042 and 043 are the Phase 7 crontab-role migrations on a parallel branch. The runner applies migrations by file name, so the gap is harmless.
- **`MAX_URLS` is 8.** The plan gives no bound.

**Compatibility.**

- Today's agent needs nothing (above).
- Before migration 044 has run, the URL list cannot be stored. The ports and the version still are, so the change is still announced.
- A rollback drops the column. `MigrationRunner::rollback()` runs `down/044_add_cluster_legacy_urls.sql` for a target release without migration 044. The kept URLs are lost, which is harmless: older code never reads them and lists no kept URL.
- LB builds strip `Domain/Cluster`. `ServerService` and `RootSignalsCronJob` reach `ClusterEndpoint` only behind `class_exists`, and the rewrite runs only on MAIN.

**Limits.**

- Nodes that knew only an old address that no longer reaches MAIN are stranded. They need `cluster_main_host` or a private route (plan D21), or re-enrolment by code.
- An old port or URL is released after 7 days, not once every node uses the new URL: which URL a node uses is not recorded (Phase 2). Since the sixth Phase 2 increment, a kept port and the kept URLs on it go sooner once every node uses the new URLs, for an agent that says which policy it dials (none does yet).
- A settings-side change (`cluster_transport`, `cluster_main_host`) still raises `cluster_policy_ver` without keeping the old URL. Since the third increment it keeps them, where the listing rules allow it.
- `setup.php` writes MAIN's `server_ip` at first setup, before any node exists, and announces nothing.
- An admin's save and the root cron's rewrite in the same moment can race on the lists. The later write wins, and the version goes up twice. Since the third increment, each writes over the lists as it read them and merges what the other kept.
- nginx holds an old port for its full 7 days: the HTTP broadcast port since the first increment, and now an old HTTPS port. An admin who moves MAIN off a port to free it for another program must wait that long. Until then, the program cannot bind the port. If the program binds the port first after a restart, nginx fails to start. Clearing the port from `cluster_legacy_ports`, or its URL from `cluster_legacy_urls`, releases it at the next `cron:cluster` render. Since the third increment, `cluster:endpoint drop` drops a kept URL, with the version raised. Since the sixth Phase 2 increment, nginx closes the port sooner once every node has moved off it and says which policy it dials.

Tests:

- `ClusterEndpointTest`:
  - the HTTPS port: announced, the old URL kept and listed last (also under `https_required`), an old-port TLS server rendered, and moving back;
  - a row with no HTTPS port gets one: announced, and the policy's 443 not kept nor rendered;
  - an HTTPS port no node uses (`auto`, `http`, no TLS name, HTTPS off): nothing;
  - `server_ip` and `private_ip` through `ServerService::announceMainEndpoints()`, the latest change first, a URL back in use leaving the list, and a save that changes nothing, or a server that is not MAIN;
  - an address and the port changing in one save, and with the API on its own port (no broadcast port kept);
  - an IPv6 address kept, bracketed;
  - `RootSignalsCronJob::rewriteServerIP()`, with and without a node, and the row it returns;
  - nothing with no node, a revoked node, a node in mode 0, or the API off, and an `enrolling` node counting;
  - the fail-safe paths: a node check that fails assumes a node, and without the `cluster_legacy_urls` column the ports and the version are still stored;
  - expiry through `prune()`, the `MAX_URLS` cap, the scheme and port rule when listing, malformed entries;
  - the transport rules when listing: no kept `http://` URL under `https_required`, no kept `https://` URL under `http`, under `auto` without a verified certificate or with HTTPS off, and a kept URL that is current listed once;
  - the call sites (the root cron assigns the row the helper returns) and the schema;
  - the HTTP port and `cluster_api_port` paths, as before.
- `ClusterNginxConfigTest`: an old HTTPS port rendered from the stored settings, and released by `cron:cluster`; a `cluster_api_port` save from stale settings keeps serving, and keeps recorded, a port kept meanwhile; `testRealNginx` passes `nginx -t` with an old HTTPS port and the shipped `ssl.conf`.
- `HttpsRequiredRecoveryTest`: neither the settings form nor the backup settings form (`SettingsService::editBackup()`) can set `cluster_policy_ver`, `cluster_legacy_ports` or `cluster_legacy_urls`.
- By hand with nginx 1.24, on a running master:
  - moving the public HTTPS port and reloading kept the old port answering over TLS with the panel's certificate;
  - on the old port, `/cluster/v1/` reached the cluster upstream, and `/get.php` got 404 where the public server passed it to PHP;
  - plain HTTP on the old port got 400;
  - once the URL expired and nginx reloaded, the port was closed.

### MAIN endpoint changes (Phase 3, third increment): the transport and MAIN's DNS name

**Before.** A settings save that changed `cluster_transport` or `cluster_main_host` raised `cluster_policy_ver` in its own `UPDATE` (`SettingsService::edit()`) and kept nothing. Both decide URLs of the policy besides MAIN's row (`ClusterPolicy::current`):

- `cluster_main_host` is one of the hosts of the plain-HTTP URLs. When it is set, it is also the TLS name of the HTTPS URL, in place of the first host name in `servers.domain_name`.
- The transport decides which schemes are listed.

So a new name dropped the old name's URLs from the policy at once. A node that adopted the new policy then had nothing but MAIN's addresses and the new name. A name that did not resolve yet, or a certificate that did not cover it yet, could strand it: under `https_required` the HTTPS URL is all it has.

**Now.** `SettingsService::edit()` stores every save that posts either setting through `ClusterEndpoint::storeSettings($rSet, $rData, $rSave, $rMain)`. The settings form posts both with every save. That runs on MAIN only: `Domain/Cluster` is not in the LB build, so the call sits behind `class_exists`. Every other save is stored as before.

`storeSettings()` compares the save with the settings row as stored, not with the request's settings cache (`changesClusterPolicy()`). That cache is loaded when the request starts. It may predate another admin's save, which the form's self-probe or the port staging can leave seconds behind. Compared with it, a save that posts the name its page showed could move the stored name back with no version bump and nothing kept. Agents that had adopted that version would then hold a URL set that differs from what MAIN serves under it. The cost is one `SELECT` of the settings row per save. Without `ClusterEndpoint` (the LB build), a save that changes either setting against the cache raises the version, as before.

1. It reads the settings row and MAIN's `servers` row (`is_main = 1`) from the database. The servers cache the form reads may be 10 s old, and a URL listed for a stale row would count as current. When MAIN's row cannot be read, the form's row is used.
2. When the stored transport and name already equal the save's, the save is stored without raising the version, keeping nothing and with no audit event. Either the form posted them unchanged, or another save stored them and announced it. This holds before migration 044 too.
3. Otherwise it compares the policy's URLs, without the kept lists, for the stored settings and for the save's values over them. Both lists are taken on the `cluster_api_port` the save stores. A new API port is announced once it is stored, by `recordApiPortChange()`, as before.
   - **Nothing is kept** when the lists are the same, when the API is off once the save is stored, or when no node may use the URLs. A node may use them when it is in mode ≥ 1 and not revoked. An `enrolling` node counts, and a check that fails assumes one (`nodesListening()`, as in the second increment). The version still goes up, as before: the policy's `transport` field or its URLs changed.
   - **Otherwise** every URL the old settings listed and the new do not is kept for 7 days in `cluster_legacy_urls`, where the listing rules list it. An `https://` URL is kept only on the HTTPS broadcast port the row stores, and only while the new transport lists HTTPS. The policy's default 443 for a row with no HTTPS port is never kept, as in the second increment. An `http://` URL is never kept under `https_required`. A kept URL the new policy lists again leaves the list. The rest stay, the latest change first, at most 8 (`MAX_URLS`).
   - The audit event is `cluster.endpoint_change`, with `urls_from`, `urls_to`, `kept_urls`, `kept_ports` (unchanged), and `settings_from` and `settings_to`: the changed settings, with their old and new values.
4. One `UPDATE` stores the save's columns, the kept URLs and `cluster_policy_ver + 1`. It applies only while `cluster_policy_ver`, `cluster_legacy_ports` and `cluster_legacy_urls` are as read. It counts as stored only when it changed the row: the affected-row count (`num_rows()`, PDO's `rowCount()`) is not 0. An `UPDATE` that matches always changes the row, since the version goes up. When none matched, another process stored the state in between, and the save starts again from step 1 with no audit event for the lost try. A read-back cannot tell this: another save of the same transport and name leaves the row holding them, but none of this save's other columns. The next try then finds them stored (step 2) and stores the rest.
5. After 3 tries, the save is stored as before: its columns and the version, nothing kept. The same happens at once when a save that changes either setting finds no `cluster_legacy_urls` column (before migration 044), and when the settings row cannot be read. In the last case the save may change nothing, and the version still goes up: a needless bump costs each node a hello, a missed one can strand them.

So no policy version lacks the kept URLs: the first version that lists the new name also lists the old one.

**What a save keeps.** `a` and `b` are DNS names. The HTTPS entries apply only while MAIN lists HTTPS: `enable_https` 1 or 2, and a TLS name.

| Save | `https_preferred` | `https_required` | `auto`, `http` |
| --- | --- | --- | --- |
| Name `a` → `b` | `https://a`, `http://a` | `https://a` | `http://a` |
| Name set where there was none | `https://<domain_name>` (the TLS name it replaces) | the same | nothing (a URL is added) |
| Name `a` cleared | `https://a`, `http://a` | `https://a` | `http://a` |
| Transport alone | nothing | nothing | nothing |

Each kept `http://` URL is on the API's HTTP port, and each `https://` one on the HTTPS broadcast port. A save that changes both settings keeps the old name's URLs of the schemes the new transport lists.

**Why a transport change alone keeps nothing.** It moves no host and no port. What it drops is a whole scheme: `http://` for a switch to `https_required`, `https://` for a switch to `auto` or `http`. A kept URL is listed only with a scheme the transport lists, so none of the dropped URLs could be listed:

- `auto` lists HTTPS only once the self-probe feeds the policy, which no caller does yet. So no `https://` URL is kept under `auto`.
- Under `https_required` plain HTTP stays reachable for the signed challenge through the agent's own `http_urls`, as before.
- The URLs kept before stay stored, whatever their scheme. They are listed again once the transport lists their scheme, until they expire or are released.

**Concurrent changes.** Every writer of the kept URL list now writes over the lists as it read them:

- `storeSettings()`, above.
- `recordMainChange()` writes through `save()` with the lists as read: `WHERE COALESCE(…) = ?` on both, then a read-back, as `release()` does. When they changed, it reads them again and computes again:
  - `stored()` now reads `cluster_transport` and `cluster_main_host` again with the lists. The root cron's settings may predate a settings save, and with the old name a URL that save kept looked current and left the list.
  - What the policy lists now comes from MAIN's row as stored, or from the caller's new row when that cannot be read. Both callers store the row before they announce. A change of another column that another process stored in between then counts: an admin's save and the root cron's rewrite at the same moment keep both old addresses.
  - The third try writes without the condition, as before, so the change is still announced. It writes without the condition at once before migration 044.
- `prune()` writes over the lists as read. On a conflict it writes nothing and returns false, and the next minute prunes. Before migration 044 it writes the ports as before.
- `release()` already did (sixth Phase 2 increment).
- `drop()` (below) writes over the lists as read, 3 tries, then writes nothing.
- `recordApiPortChange()` writes the ports list alone, as before.

**The backup settings form.** `SettingsService::editBackup()` stores any settings column posted to it. It already dropped MAIN's own state (`cluster_policy_ver` and the kept lists). It now also drops every cluster setting (`ClusterSettings::keys()`, `cluster_transport` and `cluster_main_host` among them). Only the settings form checks them (`ClusterSettings::normalize()`) and announces them. The backup form posts none of them.

**nginx.** Nothing new. A URL this increment keeps is on a port MAIN serves already: the API's HTTP port, or the HTTPS broadcast port the row stores, which the public server serves over TLS. `ClusterNginxConfig` renders no server for it.

**Expiry and early release.** These kept URLs go as the others do:

- `cron:cluster` drops them after 7 days (`prune()`).
- `release()` drops one sooner once every node dials the current policy and none reaches MAIN on its port. A kept name's URL is on a port the nodes use, so it goes early only when no node reaches MAIN on that port. An example is the old name's `http://` URL once every node dials HTTPS. The port cannot tell which name a node dialled.
- The admin drops one at once with `cluster:endpoint drop` (below).

**Dropping a kept URL.** `console.php cluster:endpoint` (`ClusterEndpointCommand`, MAIN only, stripped from LB builds) handles the kept URLs of every increment:

- `list`, the default, prints the kept ports and URLs with their expiry (UTC).
- `drop <url|host>` drops the kept URL given, as listed, or every kept URL whose host is the one given: a DNS name or an address, compared case-insensitively, an IPv6 address with or without brackets.
- `ClusterEndpoint::drop()` writes through `save()` over both lists as read and raises `cluster_policy_ver`. It reads again and retries up to 3 times, then writes nothing. The kept ports stay, and expired entries go with the write.
- The audit event is `cluster.endpoint_dropped`, with `urls` (dropped) and `kept_urls`, actor `admin`.
- nginx closes an old HTTPS port it served only for a dropped URL at the next `cron:cluster` render, within a minute.
- Exit code 0 when something was dropped. It is 1 when nothing matched, when the write lost 3 races, or on a usage error.

The agent learns of it as of any policy change: the heartbeat reply's higher `policy_ver`, then a hello. A dropped `http://` URL can stay in its `http_urls`, which it asks only for the signed challenge while HTTPS fails under `https_required`, and adopts nothing from an answer the panel did not sign.

Drop an old DNS name or address as soon as MAIN gives it up (the domain is dropped, transferred or compromised, or the address goes to someone else). Whoever holds it next answers the nodes that dial it (below).

**Wire.** There is no new op, field, header or setting. `policy.main_urls` may carry, last and for up to 7 days after a save:

- `http://<old cluster_main_host>:<HTTP port>/cluster/v1/`, where the HTTP port is `cluster_api_port`, or `http_broadcast_port` when that is 0;
- `https://<old TLS name>:<https_broadcast_port>/cluster/v1/`. The old TLS name is the old `cluster_main_host`, or, for a name set where there was none, the first host name in `servers.domain_name`.

`policy.main_urls` is in the replies to hello and enrol_complete, in the signed challenge, in `cluster.json` at install, and in the replica's `cluster` section. Each save that changes the transport or the name raises `policy_ver`, as before, and the heartbeat reply carries it.

**What today's agent does.** It needs nothing new:

- A heartbeat reply with a higher `policy_ver` makes it say hello. It adopts the policy from the MAC'd reply, never at a lower version.
- It tries `main_urls` in order, but a URL it could not reach (connect, TLS or timeout) goes last for 10 minutes (`URLRetry`). The kept URLs come last, so a request reaches them only after the current ones fail. After such a request the current URLs are backed off, and for up to 10 minutes each request dials the kept URLs first.
- It resolves a host name at each dial, as it does for the current `cluster_main_host` URL today. It verifies an `https://` URL against that URL's own host name.
- Its `http_urls` (the plain-HTTP URLs of every policy it held, at most 8) take a kept `http://` URL like any other.

**A kept URL whose host now answers for someone else.** A kept name may come to point at another host, and a kept address may go to someone else. Requests to it stay MAC'd and sealed, so that host can neither read them nor forge an answer the agent acts on. It can still keep today's agent from MAIN for a while:

- In `Client.callOnce`, a reply with status `200` and `Content-Type: application/octet-stream` goes to `openReply`, which returns `ErrTransport` when the `X-XCVM-Ts`, `X-XCVM-Nonce` or `X-XCVM-Sig` header does not parse, the MAC does not verify, or the BOX does not open. `callOnce` returns that error at once, without trying the next URL.
- `reached(ctx, base, nil)` has already counted the URL as reached, which clears its back-off.
- So after one failed request (MAIN, or the node's path to it, is down) the current URLs are backed off and the kept URL is dialled first. If its host answers that way, every request (hello, heartbeat, commands, token refresh) ends with `ErrTransport` there. That lasts until the current URLs' back-off lapses: up to 10 minutes after MAIN is back, after each outage, for as long as the URL is kept (up to 7 days).
- Any other unauthenticated answer (another status, a refusal that does not verify, a connect or TLS failure) moves on to the next URL, so it costs only time. The re-key loops (`Challenge`, `PanelBoxPub`, `Rekey`) move on as well.

This increment adds the kept URL most exposed to it: an old `cluster_main_host`, often given up because the domain is dropped, transferred or compromised. Whoever holds the name next can also get a certificate for it, so its `https://` URL answers too. The panel's remedy is to drop such a URL (`cluster:endpoint drop`, above). The agent's is item 3 below.

**The agent's contract.** For the Go half:

1. **No new wire.** No op, field, header, agent state or setting. The contracts of the second endpoint increment (known-good URL sets) and of the sixth Phase 2 increment (`policy_ver` in hello and heartbeat) are unchanged. A dropped URL reaches the agent as any policy change does: a higher `policy_ver`, then a `main_urls` without it.
2. **Kept URLs are ordinary entries of `main_urls`.** A URL this increment keeps belongs to the URL set of the policy that lists it. Known-good sets compare `main_urls` element by element, order included, as before. The agent must not treat a host name differently from an address: it resolves the name at each dial and verifies TLS against it. A kept URL can leave `main_urls` before its 7 days: released early, or dropped by the admin.
3. **Required: an answer that does not authenticate is that URL's failure.** This is agent behaviour only, with no wire change, and it applies to every URL, current or kept. It must ship before the fleet relies on kept DNS names to reach MAIN.
   - In `callOnce`, when a `200` `application/octet-stream` reply fails `openReply` with `ErrTransport` (a header that does not parse, a MAC that does not verify, a BOX that does not open), record the URL in `failed` until now + `URLRetry` (10 minutes), as for a connect, TLS or timeout failure. Keep `ErrTransport` as the last error and go on to the next URL in the same request.
   - Do not count such a URL as reached: its back-off must not be cleared by `reached(ctx, base, nil)` before `openReply` has judged the reply. An authenticated reply or a verified refusal marks it reached; every other answer keeps today's handling.
   - A reply that authenticates stays final, even when its JSON does not decode into the caller's `out`. So does a verified refusal (`*Denial`).
   - The request fails with `ErrTransport` only when no URL gave an authenticated reply or a verified refusal.
   - `Rekey` follows the same rule: a `200` whose panel signature or document does not check backs the URL off instead of clearing it.
   - A back-off only reorders `urls()`: a backed-off URL is still tried, last, so this can never leave the agent with no URL to try.
4. **Today's agent** works with this increment as it is, with the exposure above. While a kept URL's host answers for someone else, the admin drops it with `cluster:endpoint drop`. The agent stops dialling it once it adopts the next policy, at most `URLRetry` after MAIN is reachable again.

**Differs from the plan.**

- **More changes are announced.** Plan §3 announces changes of MAIN's ports and addresses, and has `cluster_main_host` "survive IP changes". A change of the name itself, or of the transport, is now handled the same way: announced, not pushed, in the same `UPDATE` that stores it, and kept as URLs.
- **A transport change keeps nothing**, as explained above. It drops no URL the transport could still list.
- **The version goes up on any change of the transport or the name**, even when nothing is kept (no node, the API off, the same URLs), as before this increment.
- **Concurrent writes merge.** The plan has no rule for two changes at the same moment.
- **The backup settings form stores no cluster setting.**
- **The admin can drop a kept URL** before its 7 days (`cluster:endpoint drop`). The plan has no way to.

**Compatibility.**

- There is no migration. `cluster_legacy_urls` is migration 044's.
- Today's agent needs nothing to work. A kept URL whose host answers for someone else can hold it off MAIN for up to 10 minutes after an outage (above), until item 3 of the contract ships. Drop such a URL.
- Older code, from migration 044 on, lists and expires the kept URLs of this increment by the same rules. A rollback before migration 044 drops the column, as before.
- LB builds strip `Domain/Cluster`. `SettingsService` reaches `ClusterEndpoint` only behind `class_exists`, and stores the save as before without it.

**Limits.**

- Only a save through the settings form is announced this way: `SettingsService::edit()`, which the admin API's settings edit also calls. A direct SQL edit of `cluster_main_host` or `cluster_transport` announces nothing, as before.
- Every settings save reads the settings row once more, since the form posts both settings (above).
- A save that changes `cluster_api_port` and the name at once keeps the old name on the new port. `recordApiPortChange()` keeps the old port for the current addresses and name. The old name on the old port is not kept.
- Under `auto`, no `https://` URL is kept (above).
- A kept name's URL on a port the nodes use stays for its 7 days while they use that port, as an old address on the current port does.
- After 3 lost races, a settings save is stored without keeping anything, as before this increment. A database handler that counted no row for an `UPDATE` that went through would make the next try find the save stored (step 2): the columns are stored again without raising the version, and the save's audit event is missing. MySQL's `rowCount()` counts the row, since the version changes.
- A kept URL whose host answers for someone else can hold today's agent off MAIN after an outage (above). Nothing tells the admin: drop the URL when the name or address is given up.
- `recordMainChange()`'s last try and `recordApiPortChange()` still write without the condition.

**Tests.**

- `ClusterEndpointSettingsTest` (new), through `SettingsService::edit()`:
  - a new name under `https_preferred`: both of the old name's URLs kept and listed last, no nginx server, the audit event, and moving back;
  - a name set (nothing kept under `auto`; under `https_preferred` the replaced TLS name's `https://` URL kept), a name cleared (its URLs kept), and a kept URL current again leaving the list;
  - a row with no HTTPS port: the old name's `https://` URL on 443 not kept;
  - transport changes (`https_preferred`, `https_required`, `auto`, `http`): announced, nothing kept, the URLs kept before untouched, and `auto` → `http` raising the version alone;
  - a new name under `https_required` keeping the `https://` URL alone, and the transport and the name changed at once;
  - nothing kept with no node, a revoked node, a node in mode 0, the API off, or a save that turns it off; an `enrolling` node counting; a save that changes nothing;
  - the forms: posted kept lists and versions ignored, and the backup form storing no cluster setting;
  - the early release (the node on the current policy and the HTTPS port: the old name's `http://` URL released, the `https://` one kept) and the 7-day expiry;
  - a save that also moves `cluster_api_port`;
  - a race with `cron:root_signals` either way, three lost races, and the fail-safe paths (no settings row, no `cluster_legacy_urls` column, and an unchanged save before migration 044 keeping the version);
  - another admin's save of the same name between the read and the `UPDATE` (the save's other columns stored, the version raised once, one audit event), and a transport change in between (what is kept follows the stored transport; with no node the name is stored all the same);
  - a save compared with the stored row, not the cache: the stored name posted with a cache that lacks it (no bump, no audit), and a stale cache's name moving the stored one back (announced);
  - at most `MAX_URLS` kept after six renames, the latest first;
  - `cluster:endpoint`: the list, a host dropped (case-insensitive) and a URL dropped (the version raised, `cluster.endpoint_dropped`), no match and a usage error (exit 1), the LB strip lists; a drop that loses 3 races writing nothing, then succeeding.
- `ClusterEndpointTest`: an admin's save and the root cron's rewrite at the same moment keeping both old addresses, a prune racing a settings save, and `recordMainChange()`'s retry limit (two conditional writes, then one without the condition; the fail-safe test fails instead of hanging should the tries become unbounded). A kept IPv6 URL is dropped by its address. Its `live()` helper now stores the transport a test sets, since `stored()` reads it again. `ClusterEndpointReleaseTest` has the same helper change.
- `TestDb::num_rows()` now counts the rows a write changed, as `Database::num_rows()` does (PDO's `rowCount()`).
- `HttpsRequiredRecoveryTest` is unchanged and still passes. Its recovery drill now runs through `storeSettings()`, including saves that change nothing.

### Commands (Phase 4, first increment)

`CommandBus` queues MAIN → node commands in `cluster_commands`, FIFO per node by `seq`. Each command is a typed JSON record signed by the panel with tag `cmd`:

```text
{"v":1, "type", ["action",] "exp", "iat", "cmd_id", "seq", "node_uuid", "gen", "dedupe_key", "args"}
```

The extension derives the class from `type` (and, for `node.root`, its action: `fence` is restrictive). Kills and stops are restrictive and sign without a licence; the rest need it. The registry is the extension's, and it refuses what it does not list: a type it does not know, an envelope key it does not know, an argument a restrictive type does not take, and a `node.rpc` or `node.root` without a top-level `action`. So `action` is an envelope field, only for those two, never among the `args`: callers hand `CommandBus::enqueue()` their `{action, …}` payload as before, and it lifts the action out. `cluster_commands.class` is the extension's answer (`recordClass`), not a list of MAIN's. `tests/Support/cluster_commands.json` is the registry as the extension generates it (per type: class, whether it needs `action`, a restrictive type's `args` keys, `node.root`'s restrictive actions); `CommandBusRegistryTest` builds every type MAIN sends as `CommandBus` and `ClusterRoute` build it and checks it against the file, and `FakeClusterCrypto` classes and refuses by it as the extension does. A `dedupe_key` replaces a not-yet-acked command for the same desired state; an acked one keeps its outcome but gives up the key, so `UNIQUE(server_id, dedupe_key)` never refuses the next command with it (until the third Phase 4 increment's review, a node's second `config.changed`, or a second drop of the same viewer, within a day of the first one's ack failed to queue on MariaDB). Commands expire (`conn.*` 5 min, `artefact.*` 1 h, `node.root` 24 h, `node.cache` 24 h since the fourteenth Phase 7 increment, default 10 min), and `cron:cluster` prunes them. **Superseded** in part by [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05): a `node.root strip_db_credentials` lives 600 s.

The flow, for a node whose COMMANDS flow is on (toggled per node on the Cluster Nodes page):

1. **Poll.** The agent holds a `commands` long-poll. Each poll holds a PHP worker on MAIN; the plan's bus replaces that later.
2. **Checks.** The agent checks each command: the panel signature under its pinned key, its own uuid and generation, `seq` above its persisted high-water `cmd_seq`, and `exp` on MAIN's clock.
3. **Run.** It runs the command through `console.php cluster:exec`. `cluster:exec` checks the signature again and runs `node.rpc{action}` with the legacy `/api` handlers (`InternalApiController::runCommand`, actions limited to `NodeRpc::ACTIONS`) and `conn.kill_worker`.
4. **Ack.** The agent `ack`s the command with the result (≤ 64 KB) and raises its high-water. MAIN raises `cluster_nodes.cmd_seq`. It also raises it, never lowering it, to the highest `seq` a `commands` poll hands out, and to a poll's `after_seq` capped at the highest `seq` of the node's rows in `cluster_commands`: an ack lost for longer than the command lives leaves its row pruned, and the next command must still not take a `seq` at or below the agent's high-water, which the long-poll (asking for `seq` above `after_seq`) would never hand out. An `after_seq` above that cap raises `cmd_seq` only to the cap, never past it.
5. **Refusals.** A refused command is acked as refused, with the reason.

On MAIN, `Domain\Cluster\ClusterRoute` sits behind the Phase 0 seams:

- `NodeRpc::request()` becomes a signed `node.rpc`, and MAIN waits for its ack up to the caller's timeout.
- `NodeRpc::broadcast()` and `SignalDispatcher::kill()` queue without waiting.
- Nodes without the flow, and every LB, keep the legacy transport.
- A command the channel does not take is not sent the legacy way either: routing follows the node's COMMANDS flow, not an enqueue's outcome. That is a granting command the extension will not sign without a licence (an unsigned legacy call would skip that gate), or a row the database refused. `CommandBus::enqueue()` throws when no row was stored after `CommandBus::ATTEMPTS` tries (the panel's `Database::query()` answers a refused write with false, not an exception), `ClusterRoute` answers routed and not queued, and the reason goes to the panel's error log (`FileLogger`, type `cluster`).

### Root commands (Phase 4, second increment)

`NodeActions::send()` (reboot, update, service restarts and the other `NodeActions::ROOT_ACTIONS`) becomes a signed `node.root` command when the node takes it. Otherwise it stays on the signals table. The agent runs as `xc_vm`, and so does everything that can write its files, so root does not trust the agent's copy of the panel key.

- **Pin.** Root keeps its own copy in `/etc/xc_vm/cluster/`, owned by `root:root` and writable by nobody else:
  - `main_sign.pub` holds the panel signing key (hex).
  - `node` holds the node's uuid.
  - `root.seq` holds root's own high-water.
  - `LbInstallFlow` writes the pin over SSH right after enrolment. On a node enrolled by code, root runs `console.php cluster:pin-root <panel_fp>` with the fingerprint shown on the Cluster Nodes page. The command checks it against the key the agent received.
  - A new pin clears `root.seq`.
- **Hand-off.** `cluster:exec` does not run a `node.root` command itself. It writes `<seq>.json` (command and signature) into `config/cluster/root-inbox/` (xc_vm, 0700) and waits up to 5 s for `<seq>.done`. On a timeout it acks `{"queued":true}`.
- **Root side.** `cluster:root` runs from root's crontab every minute and watches the inbox for 58 s under a lock. For each file in `seq` order it:
  1. verifies the signature under the pin, `type` `node.root`, the node's uuid, `exp` (5 min grace), `seq` above `root.seq`, and the action against `NodeActions::ROOT_ACTIONS`;
  2. raises `root.seq` before running, so a crash cannot replay;
  3. runs the action through `RootSignalsCronJob::executeAction()`, the same code the signals path uses;
  4. writes the result exclusively (`fopen 'x'`, after removing anything planted at the path), so root never follows a symlink.
- **Readiness.** The agent reports `root_ready` in every heartbeat: the pin exists and matches its own panel key and uuid. MAIN stores it in `cluster_nodes.root_ready` (migration 039). It routes `node.root` only to nodes with the COMMANDS flow and `root_ready`. The Cluster Nodes page shows it.

### Artefacts (Phase 4, third increment)

The plan's `artefact` op (section 7: "off-air videos, pinned binaries, ≤ 4 MB chunks; rare / bulk; token; granted by a signed command"), its root artefacts ("staged in root-owned `/etc/xc_vm/cluster/stage/` and checked for size and SHA-256 before exec; a mismatch is refused and audited"), its custom off-air videos "via `artefact`" (the legacy mapping) and section 14's "tampered artefacts refused before extraction". Until now a node pulled a custom module's archive from MAIN's `/api?action=getFile&password=<live_streaming_pass>`, checking only its `PK` magic, and a custom off-air video reached a node only if someone copied it there by hand.

**What MAIN hands out** (`Domain/Cluster/ArtefactRegistry`): only what its own configuration names, by an id, never a path.

| Id | File on MAIN | Largest | Granted by |
| --- | --- | --- | --- |
| `offair/<name>`, `<name>` a key of `ReplicaSections::OFF_AIR` (`connected`, `not_on_air`, `banned`, `expired`, `expiring`) | the admin's `<name>_video_path`: an absolute local path whose extension is `ts`, `m2ts`, `mts`, `mpegts` or `mp4`, the file the `cluster` section names by its file name | 256 MiB | `artefact.fetch` |
| `module/<name>/<version>`, `<name>` `[a-z0-9][a-z0-9-]{0,63}`, `<version>` `[0-9A-Za-z][0-9A-Za-z._-]{0,31}` without `..` | the custom module's archive MAIN keeps (`ModuleManager::archivePathFor`, `modules_archives/<name>_<version>.zip`) | 64 MiB | `node.root install_module` |
| `agent/<arch>` (removed 2026-10-01, [Binaries from GitHub on every node](#binaries-from-github-on-every-node)), `<arch>` one of `amd64`, `arm64`, `armv7`, `386` | the xc_agent binary `console.php agent_binary` verified into `bin/xc_agent/cache/` (`xc_agent-linux-<arch>`), with its `.version` | 128 MiB | `node.root agent_binary` |

- `ArtefactStage::validId()` (Core, so both ends share it) checks the id's shape first. A module archive or an agent binary must be a regular file inside its own directory once links are resolved; an off-air video a regular file whose file name matches `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$` (no path, no dot file), at least one byte. Anything else is no artefact: an off-air setting that is a URL, a relative path or no video keeps playing as before on every node.
- An artefact's SHA-256 is computed when it is granted and kept in `cluster_meta.artefact_hashes` by the file's path, device, inode, size, mtime and ctime, so a file is hashed again only after it changed. ctime moves on every write and every touch, so a video rewritten in place with its size and mtime kept (`cp -p`, `rsync -t`, `touch -r`) is hashed again. The hash of a file changed within the last two seconds of MAIN's clock is not kept at all: a second write in that same second would leave its stat as it was.

**Grants.** A grant is a `cmd`-signed command (`CommandBus`) whose `args.artefact` names what the node may fetch:

```text
{"id": "<id>", "name": "<file name>", "size": <bytes ≥ 1>, "sha256": "<64 lowercase hex>", "mtime": <MAIN's, seconds>, "ctime": <MAIN's, seconds>, "exp": <the command's exp>}
```

- **`artefact.fetch {artefact}`** (a new command type, TTL 1 h, granting: the extension signs it only under a licence) grants an off-air video. `cron:cluster` offers them each minute (`ArtefactGrants::offerOffAir`): to every active node whose agent takes artefacts (COMMANDS on and `artefact` in its hello `features`), one grant per custom video it was not granted yet, that changed since, or whose last grant failed or went unfetched. That last one is offered again an hour after the first try (`RETRY_AFTER`), then twice as long after each further try of the same bytes, up to a day (`RETRY_MAX`, `ArtefactGrants::retryAfter`), so a node that keeps failing a video (its video directory not writable, its disk full, a node PHP older than its agent) does not download it every hour. Two off-air settings whose files share a file name but not their bytes are granted to no node (`withoutCollisions`): a node finds its copy by the file name alone, and would play one for the other. What each node was offered, how many tries and how its ack went, is kept per generation in `cluster_meta.artefact_offair.<sid>`, so a re-enrolled node is offered everything again. Without a licence nothing is signed and nothing is recorded, so the next pass after the licence is back offers them; what a pass queued before a licence refusal or an error is recorded, so it is never queued twice. The extension is asked for only when there is a grant to sign.
- **`node.root {action, …, artefact}`** grants the file a root action needs (`ArtefactGrants::forRoot`, from `ClusterRoute::root`, which drops any `artefact` a caller put there): `install_module` with `source: local` (the module's archive), and the new root action `agent_binary {arch, version}` (the pinned agent, the plan's section 5 `node.root agent_binary{version, sha256}`). `install_module` goes without a grant, as before, to a node whose agent does not take artefacts or when MAIN has no such archive; the node then pulls it the legacy way. `agent_binary` goes only with its grant (`NodeActions::agentBinary`), and never as a `signals` row (`NodeActions::CLUSTER_ONLY`).
- A grant expires with its command: `CommandBus::enqueue` writes the command's `exp` into it.

**The `artefact` op** (`ClusterApi`, `ArtefactGrants::serve`): an ingest op on the bulk lane (`ClusterPool::INGEST_OPS` already listed it, so its FPM pool and nginx lane were in place), under an ingest permit (`ClusterSemaphore::runIngest`), reading no `servers` row of MAIN's, for an `active` node. The node names a grant (its command's `cmd_id`), an offset and a length; MAIN serves a chunk of the file its registry resolves the grant's id to, inside the BOX:

- A **live grant** is a command of this node's (`server_id`), of type `artefact.fetch` or `node.root`, `queued` or `delivered` (not yet acked), neither it nor its grant expired, for the node's uuid and current generation, whose grant is whole and whose id is the registry's. Anything else, another node's grant included, is `403 GRANT_INVALID`.
- **Ranges.** `offset` at or past the grant's size, or `length` above 4 MiB (`ArtefactStage::MAX_CHUNK`), is `416 BAD_RANGE {size, max_chunk}`; a length past the end is cut at the end.
- **The file as granted.** When the registry no longer resolves the id to a file of the grant's name, size, mtime and ctime (a new video under the same path, one rewritten in place, another path in the setting, a deleted archive, a short read), `409 ARTEFACT_CHANGED`, and the node never assembles half of each. MAIN offers an off-air video again once the new file's SHA-256 differs from the one it granted, or once the retry wait after the failed ack is over. A root grant is not sent again: the admin sends the action again.
- The node's first `ack` of a command that carried a grant is audited on MAIN when it failed: `artefact.refused` when its result says `artefact refused` (the node refused what it fetched), `artefact.failed` otherwise, with the command, the id, its SHA-256 and the result's first 300 characters (`ArtefactGrants::acked`, from `CommandBus::ack`'s new `$rFirst`, so a repeated ack is not audited twice, and only for the types that may carry a grant: `CommandBus::ack` hands back the type it read, so no other ack reads more). An off-air grant's ack is recorded for the next offer.

**On the node** (`Core/Cluster/ArtefactStage`): whatever the agent checked, the bytes the node uses are checked against the signed grant as they are copied to where they are used, reading at most one byte past its size, and anything else is refused and audited.

- **An off-air video** (`cluster:exec`, as xc_vm, for `artefact.fetch`): the agent's download `config/cluster/artefacts/<cmd_id>` is copied into `content/video/cluster/.<name>.<cmd_id>.tmp` (0644) while it is hashed, then renamed to `content/video/cluster/<name>`, so a player never reads half a video and a refused one leaves the video placed before in place. The download goes once placed or refused. The node's off-air code plays it: `live.php` plays `OffAirHandler::localVideo($video_path)`, the node's own file at the token's path when it has one (the defaults, as before), else `content/video/cluster/<the path's file name>` when a grant placed it, else the path as before.
- **A root artefact** (`cluster:root`, as root, for a `node.root` whose args carry a grant): after the command's checks and after `root.seq` is raised, root opens the agent's download with the agent's rights (`SettingsAudit::asAgentUser`: never with its own, and never through a link planted there, `lstat` then `fstat` of the same inode), copies it into `/etc/xc_vm/cluster/stage/<cmd_id>` (the stage created `root:root` 0700, closed to others when root finds it open, and trusted only while it is a directory, not a link, that is root's alone; the copy 0600, created exclusively) while it is hashed, removes the download, and checks size and SHA-256 there. Only then does the action run, with that copy (`ArtefactStage::current()`, set by the drain alone: a `signals` row or a payload naming a path never gets one), and the copy goes after it. A download that cannot be read with the agent's rights (a hard link to a file only root may read) is refused (`the download cannot be read with the agent's rights`). The download also goes when root refuses the command before staging it: a stage that is not root's alone, and a command `RootPin::verify` refuses (a replay, an expired command, a bad signature: `ArtefactStage::spendRefused`, which removes only `<cmd_id>`, 32 hex, in the agent's own directory, as the agent's user). `cluster:root` removes copies a crash left for over an hour at its start.
  - `install_module` with a staged archive takes only a grant of `module/<name>/<version>` of its payload (anything else is refused as an artefact, `not the archive of module/<name>/<version>`, before `module:install` runs). It runs `module:install` at once with `archive` = the staged copy and `artefact` = the checked grant, with its command's id (`RootSignalsCronJob::moduleInstallArgv`, run with no shell since the fourth increment). `module:install` takes the archive only from root's stage and only when it is still the grant's bytes (`ArtefactStage::stagedArchive`), refuses anything else as an artefact (`ArtefactStage::refuseGrant`: audited, and its output reads `artefact refused`), and pulls nothing from MAIN. Its exit status is the command's: a refusal or a failed deploy is `.done` `{"ok": false, "result": <its output>}`. Without a staged archive it runs the legacy way, as before: to its end, its exit status and output not the command's (the old line ended in `2>&1 &`, but `exec()` read its output until `module:install` closed it, so it never ran in the background); `archive` and `artefact` in a payload are dropped.
  - `agent_binary` takes only a grant of `agent/<arch>` whose `<arch>` is this machine's (`ReleaseAsset::arch(php_uname('m'))`, the mapping `LbInstallFlow` applies to `uname -m`) and the payload's `arch`; anything else is refused as an artefact (`not an xc_agent binary`, `not this node's arch (<uname -m>)`). It writes the staged binary as the agent's user to `bin/xc_agent/.xc_agent.new` (0755, checked again as it is written), runs it once there as that user (`[sudo, -n, -u, #<uid>, timeout, 10, <it>, version]`, an argv list with no shell since the fourth increment, which must exit 0 with a first line on stdout that is not blank; `it does not run on this node (…)` otherwise, and the file goes), then renames it to `bin/xc_agent/xc_agent` and has `run.sh` restart the agent 10 s later (`pkill -u xc_vm -x xc_agent`), so the agent acks first. So a binary that cannot start on the node never replaces the running one, which is the node's only way to MAIN. Without a staged artefact (a `signals` row, a forged payload) it only prints `agent_binary: refused: …` and changes nothing.
- **Refusals** read `artefact refused: <id> (<name>): <why>`, `<why>` one of `sha256 mismatch`, `size mismatch (…)`, `not downloaded`, `the download is not a file`, `not an artefact MAIN serves`, `not a file name`, `a malformed grant`, `not an off-air video`, … Each is audited on the node (`ArtefactStage::refuse`): a system log line of the new type `ARTEFACT` (`LogSink::SYSLOG_TYPES`, which MAIN's `EventIngest` takes as root's on the node), `Refused artefact <id> (<name>) for command <cmd_id>: <why>`, as a `log.syslog` event with LOGS on, in mode 2 the panel's error log, and otherwise the panel's error log as the agent's user, never MAIN's database. The refusal also goes back in the command's result, which MAIN audits (above), when it reaches the ack. For a `node.root`, that is when root answers within `cluster:exec`'s wait, 45 s for a command that carries a grant (`ClusterExecCommand::ROOT_WAIT_ARTEFACT`: root stages up to 128 MiB, and `module:install` runs before it answers) and 5 s otherwise; `cluster:exec` then exits 1 with root's result on stdout and `cluster:exec: <root's result>` on stderr, since today's agent acks a non-zero exit as `cluster:exec: exit status 1: <stderr>` and drops stdout. When root answers later, the ack says `{"queued":true}` and MAIN learns of the refusal only from the `ARTEFACT` line, with LOGS on. Root's refusal consumes the command's `seq`: `.done` is `{"ok": false, "result": "refused by root: artefact refused: …"}`.

- **What the node's PHP runs.** `console.php cluster:exec --types` prints the command types it runs (`ClusterExecCommand::TYPES`, a JSON array, `artefact.fetch` among them) and reads nothing. The agent says `artefact` at hello only while it does (its contract below), so a new agent on a node whose PHP predates this increment is granted nothing.

**How it differs from the plan.**
- The plan says only "granted by a signed command". Off-air videos get a command of their own (`artefact.fetch`), which the agent hands to `cluster:exec`, and root artefacts ride the `node.root` command that needs them, so root checks the signed size and SHA-256 under its own pin, never the agent's word.
- The grant also carries MAIN's `mtime` and `ctime`, which let MAIN refuse chunks of a file that changed since the grant (`ARTEFACT_CHANGED`) rather than let the node assemble a file its hash check would then refuse.
- Section 7's root handoff checks `seq > root.seq` "and the `cmd_id` set". Root keeps only its high-water `root.seq`, which never goes down, so it refuses a lower `seq` handed over after a higher one as a replay. A root command whose artefact is still downloading must not be overtaken: the agent hands root commands over in `seq` order, a later one waiting behind a download (its contract below), rather than root accepting a lower unseen `seq`.
- Section 7 says module install and delete are not offered to API-mode nodes until a module API exists. Root commands already carried `install_module` and `delete_module` to nodes with COMMANDS (second increment); this increment lets `install_module` carry the custom module's archive as a grant, so such a node no longer pulls it with `live_streaming_pass`. A module API (MAIN knowing which modules each node holds) is still not built.
- Section 7's "large transfers in ≤ 4 MiB parts, staged in `tmp/cluster_xfer/`" is for uploads. The artefact op is a download: MAIN stages nothing, and the node assembles the chunks.
- The viewer token still carries MAIN's off-air path (section 7's legacy mapping wants replica basenames there): a node plays its granted copy by that path's file name, which is the file name the `cluster` section names. Built later: the token names the video (`off_air`: `connected`, `not_on_air`, `banned`, `expired` or `expiring`, `ReplicaSections::OFF_AIR`), and the node that plays it finds it from its own settings, the replica's on a CONFIG node (`OffAirHandler::tokenVideo`): its own file, or the copy MAIN granted it. The token keeps `video_path` for nodes from before, which read only that; a token from an older MAIN, which has only `video_path`, still plays. `ArtefactHashRefusalTest::testAnOffAirVideoIsPlacedOnceItsSizeAndHashAreTheGrants`.
- `update_binaries` gets no artefact: the binaries bundle is per distribution and MAIN caches none, so a node still downloads it from the binaries release itself. The pinned binary the registry serves is the agent (section 5); `xc_fanout` and `xcvm_core` "follow the same path" in the plan, not built. Kept so on review: every node already downloads its panel update, the binaries and `xc_fanout` from GitHub, each checked by SHA-256 (`FanoutBinaryCommand`), so serving `xc_fanout` from MAIN would help only a node without GitHub access, which the updater does not serve either. It would take a cache on MAIN, a registry kind, a root action, a node-side install and a rollout, as `agent_binary` has. `xcvm_core` is the owner's distribution. Add them when a fleet without GitHub access is a target. Built since, on request: [the fanout daemon and xcvm_core from MAIN](#the-fanout-daemon-and-xcvm_core-from-main).
- Section 7's sha256/size check of the module zip for legacy nodes (today only the `PK` magic) is not built: a legacy node still pulls the archive the old way. Built later: MAIN's `install_module` payload for a custom module carries its archive's `size` and `sha256` (`ModuleManager::lbInstallPayload`), and a node that pulls the archive with `getFile` (a legacy node, or one whose agent takes no artefacts) installs it only when the download matches both (`ModuleInstallCommand::announced`, through the same check as a staged archive, `ArtefactStage::matches`; else `size or SHA-256 mismatch`, and nothing is installed). A payload from an older MAIN announces neither, and the zip magic is then the only check, as before. The pull itself still uses `live_streaming_pass`. `LegacyModuleArchiveTest`.

**Known limits.**
- Today's agent never says `artefact` at hello, so MAIN grants it nothing: `install_module` keeps the `getFile` path with `live_streaming_pass`, off-air videos are not delivered, and `agent_binary` is not sent (`NodeActions::agentBinary` answers false).
- MAIN never sends `agent_binary` on its own: nothing records a node's arch, and the plan's staged rollout (a canary, `cluster_agent_upgrade_parallel`, `agent_version_expected` in hello) is not built. `NodeActions::agentBinary($sid, $arch)` is the seam it will call.
- MAIN does not know what a node holds: a placed video deleted by hand comes back only when the video changes or the node re-enrols. Videos no longer named stay in `content/video/cluster/`. A node whose own file exists at MAIN's path plays that one.
- A grant signed while licensed is still served after the licence lapses, until its command expires (an hour for a video, a day for a root command), as any command queued before the lapse is delivered.
- Chunks are read from disk for each request; a fleet fetching a new 256 MiB video at once takes that many bulk ingest permits for as long.
- Two off-air settings whose files share a file name but not their bytes are granted to no node, silently: give them different file names. A copy one of them placed before stays, and plays for both.
- A root command whose artefact is downloading holds back the root commands after it on that node (not kills, RPCs or videos) until it is handed over, fails or expires (a day).
- A root grant refused with `ARTEFACT_CHANGED` (the archive or the pinned agent changed meanwhile) is not sent again; the admin sends the action again.
- `agent_binary` keeps no previous binary: one that starts (`version`) but fails at `run` leaves `run.sh` restarting it, and the node is then reached over SSH. The plan's staged rollout with a canary, where a rollback belongs, is not built.

**Tests.**
- `ClusterArtefactTest` (MAIN, with a test agent doing what the Go agent does): the registry naming only its own files (traversing, malformed and unknown ids, URLs, relative paths, dot files, directories and links out of MAIN's directories refused, an agent binary without its verified version not pinned); off-air grants only to nodes that take them (the feature, COMMANDS, not quarantined), once, again when the video changes, afresh for a re-enrolled node, and the extension asked for only when there is a grant to sign; a whole off-air video fetched in chunks on the bulk lane, the grant spent once acked; a whole 4 MiB chunk, the last one cut at the end, and `416 BAD_RANGE` and `400 BAD_REQUEST` for bad ranges and types; `403 GRANT_INVALID` without a grant, for another node's, for a command that grants nothing, past its expiry (the session still working), and for a grant row whose id is not the registry's (nothing read); what a node names besides the grant never read; `409 ARTEFACT_CHANGED` for a changed file, another path and a deleted file; `install_module` and `agent_binary` carrying their grants (a binary fetched whole), none for a store module or another action, none sent without a pinned binary, and today's agent keeping the legacy paths; no grant signed without a licence and one signed before still served, then offered once licensed again; a refused artefact audited once as `artefact.refused` and offered again an hour later, a failed one as `artefact.failed`, a command without a grant not at all; MAIN keeping a node's `ARTEFACT` line as root's. Since the review: a grant dead for another generation and for another node's uuid; a placed video never offered again, however long after; a grant whose file's ctime moved refused (`409`); a kept hash trusted only for the same device, inode, size, mtime and ctime, and none kept for a file changed within the last seconds; videos that share a file name granted only with the same bytes; a video a node keeps failing (or never fetches) offered after 1 h, 2 h, 4 h, 8 h, … up to a day; what a pass queued before a licence refusal or another error not queued again; `agent_binary` for a node the cluster API does not route answering false with no `signals` row.
- `ArtefactHashRefusalTest` (the node): an off-air video placed through `cluster:exec` once its size and SHA-256 match, as the agent's user, and played by `OffAirHandler::localVideo` only where the node has no file of its own; a tampered, longer or shorter one refused, the video placed before kept, no partial file left, audited as `ARTEFACT` lines (LOGS on) or in the panel's error log (LOGS off); no grant, a traversing id or name, a dot file, a malformed grant and a root artefact placing nothing, nor a link planted as the download; a binary staged in root's own stage (0700 / 0600), checked and handed to its action, which installs it (0755, the agent's user), the copy removed after; a tampered binary refused before its action runs, audited, `root.seq` spent, nothing staged or installed; root never following a link the agent planted, and refusing a command without its download; root's stage closed to others when found open, and refused when it is a link; `agent_binary` refused without a staged artefact, a path in its payload ignored; module archives taken only from root's stage with the grant's bytes; a crash's leftovers pruned. Since the review: a binary that does not run never installed; `agent_binary` refusing another arch, a payload for another arch and a module's archive, audited, and installing this node's arch with the agent's restart after it; root refusing a stage the agent's user owns and a hard link to a file only root may read, the download spent; a root command handed over after a later one refused as a replay (`seq not above N`), its action not run and its download spent; `install_module` taking only its own module's staged archive, running `module:install` at once with the staged copy and the checked grant, failing with its refusal, and a `signals` row's `archive` dropped; `module:install` refusing (and auditing) an archive outside the stage, one not the grant's bytes and one without a grant; a root refusal reaching the ack today's agent builds from `cluster:exec`'s exit status and stderr (`cluster:exec` run as a process, as the agent runs it).
- `NodeRpcActionsTest`, `ModeTwoPathsTest` and `ClusterRootCommandTest` still pass: `agent_binary` has its handler, and its system log line goes to the agent first. `ClusterExecCommandTest` checks `cluster:exec --types` (reading nothing, `artefact.fetch` among them).

**The agent's contract (XC_VM_Fanout, not built yet).**
- **Feature.** Say `"artefact"` in hello's `features` only once all of the following is implemented, and only while the node's PHP runs its commands: `console.php cluster:exec --types`, run as you run a command (same user, same PHP, stdin closed, same timeout), exits 0 and prints a JSON array of strings that contains `"artefact.fetch"`. A node PHP from before this increment reads the empty stdin as a command and exits 2 (`bad signature`): say nothing then. Ask at start and whenever you say hello, so a node whose PHP is updated later gets the feature at its next hello. MAIN grants nothing to an agent that does not say it.
- **Which commands carry a grant.** `artefact.fetch` (`args: {"artefact": GRANT}`), and `node.root` whose `args` has an `artefact` object (today `action` `install_module` with `args` `{"source": "local", "name", "version", "artefact": GRANT}`, and `action` `agent_binary` with `{"arch", "version", "artefact": GRANT}`). GRANT is `{"id", "name", "size", "sha256", "mtime", "ctime", "exp"}` as above; ignore `mtime`, `ctime` and any key you do not know.
- **Before running such a command**, after today's checks (signature, uuid, generation, seq, `exp`): check the grant's shape (an id of the three kinds above, a `name` matching `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`, `size` an integer ≥ 1, `sha256` 64 lowercase hex, `exp` an integer); a malformed one is not fetched: ack `ok: false`, `result: "artefact refused: <id>: a malformed grant"`, and do not run the command. Otherwise download it (below), then run the command exactly as today: `console.php cluster:exec` with `{doc, sig}` on stdin, whose outcome you ack as today.
- **Never behind a download.** A download can take minutes; the commands after it (kills above all) must not wait. Download beside the command loop, one grant at a time. Keep each command whose artefact you download (its `doc` and `sig`) in `state.json` until you acked it, as the sealed commands are kept, so it survives a restart, and raise the long-poll high-water past it (and past each command kept waiting behind it, below) at once: MAIN answers a `commands` poll at once while an unacked command lies above `after_seq`, so a high-water left below it spins the poll. A kept command is not checked against that high-water again when you hand it on. A command MAIN hands out again (a redelivery) whose download is in progress is not downloaded twice.
- **Root commands in `seq` order.** Root refuses a `node.root` whose `seq` is not above the highest it ran (`refused by root: seq not above N`), so hand `node.root` commands to `cluster:exec` one at a time, in increasing `seq`, each only once `cluster:exec` returned for the one before. A `node.root` after a `node.root` whose artefact is downloading waits, kept in `state.json` with it; every other type (`conn.*`, `node.rpc`, `config.changed`, `artefact.fetch`) goes on. When that download fails or its grant expires, ack it failed (below) and hand over the ones behind it. Hand a downloaded `node.root` over beside the command loop too: `cluster:exec` waits up to 45 s for root's result for a command that carries a grant (5 s otherwise), within your minute's timeout.
- **Download.** `POST /cluster/v1/artefact`, session-authenticated, bulk lane, one request in flight: `{"grant": "<cmd_id>", "offset": <int ≥ 0>, "length": <int 1..4194304>}`, `length` = min(4194304, size − offset). The reply (BOX): `{"grant", "offset", "length", "size", "sha256", "data": "<base64 std>", "eof": <bool>, "main_time_ms"}`, `length` being what `data` holds. Check that `grant` and `offset` are what you asked, `data` decodes to exactly `length` ≥ 1 bytes, `size` and `sha256` are the grant's, and `eof` is true exactly when `offset + length` = `size`; anything else is a bad reply (below). Write each chunk at its offset into `config/cluster/artefacts/.<cmd_id>.part` (the directory 0700, the file 0600, both the agent's) and ask for the next from `offset + length`. Give this op a timeout of its own of at least 60 s, not the 10 s of the other ops: a 4 MiB chunk is a reply of about 5.6 MB, which takes over 10 s below about 4.5 Mbit/s. After a timeout, ask for the same `offset` again with `length` halved, never below 262144, and keep the smaller length for the rest of this download. You may resume a `.part` after a restart while the grant lives: from its size when it is shorter than `size`; a part already `size` bytes long goes straight to the check below (never ask for `offset` = `size`, which is `416 BAD_RANGE`), and a longer one is deleted and started over.
- **Your own hash refusal.** Once `size` bytes are in, check the file's size and SHA-256 against the grant. On a mismatch delete the part, log `artefact <id>: sha256 mismatch` (never the bytes), and ack `ok: false`, `result: "artefact refused: <id> (<name>): sha256 mismatch"` (or `size mismatch`); do not run the command. MAIN audits it. On a match, fsync and rename the part to `config/cluster/artefacts/<cmd_id>` (0600), then hand the command on.
- **Refusals and retries.** `503 RATE_LIMITED` (`op: "artefact"`, `lane: "bulk"`), `503 STARTING`, and a `401 REPLAY` with `retry_after_ms`: send the same chunk again after the busy wait, as for every bulk op, without counting a failure. A transport error, an unsigned nginx 429, 502 or 504, and `503 DB`: send the same chunk again after 1 s, doubling to 30 s. `403 GRANT_INVALID` (the grant is spent, expired, or not this node's), `409 ARTEFACT_CHANGED` (MAIN's file changed since the grant: MAIN offers an off-air video again once its SHA-256 changed, or when the retry wait after this failed ack is over, 1 h doubling up to a day; a root grant is not sent again), `416 BAD_RANGE {size, max_chunk}`, `400 BAD_REQUEST`, `404 UNKNOWN_OP` (a MAIN rolled back below this increment), a bad reply, and the grant's `exp` passing on MAIN's clock: stop, delete the part, ack `ok: false`, `result: "artefact <id>: <REASON>"` (`expired`, `bad reply`), and do not run the command. `409 NOT_ACTIVE` (the node is quarantined) and the session refusals (`LICENCE_INVALID`, `NODE_REVOKED`, `TOKEN_EXPIRED`, `CLOCK`) as for any op: keep the part, and resume once the node is active and its session works again, while the grant lives.
- **The hand-over.** Your download is `config/cluster/artefacts/<cmd_id>`. An `artefact.fetch` runs in `cluster:exec` while you wait for it, which removes the download once it placed or refused the video; once `cluster:exec` returned for it, whatever its exit status, remove the download yourself if it is still there (a node PHP that refused the command before reading it, `unknown command type` or `bad signature`, leaves it). A `node.root`'s download belongs to root from the hand-over: `cluster:root` removes it once it staged or refused the command, a refusal before staging (a replay, an expired command) included, and that can be after `cluster:exec` acked `{"queued":true}`. Do not remove a `node.root`'s download yourself before 25 hours have passed; remove anything left in `config/cluster/artefacts/` after that. What PHP reports:
  - `artefact.fetch`: exit 0 and `{"placed": "<name>", "size": <n>, "sha256": "<hex>"}` on stdout; exit 1 and `cluster:exec: artefact refused: <id> (<name>): <why>` on stderr. Ack as today (`ok` from the exit status, the output or the error as `result`), so a refusal's result contains `artefact refused`.
  - `node.root`: as today: exit 0 with root's result on stdout, or `{"queued":true}` when root has not answered in time. A root failure exits 1 with root's result on stdout and `cluster:exec: <root's result>` on stderr, so today's ack (`cluster:exec: exit status 1: <stderr>`) carries it: a root refusal reads `cluster:exec: exit status 1: cluster:exec: refused by root: artefact refused: <id> (<name>): <why>`, which MAIN audits as `artefact.refused`. You may ack stdout instead on a non-zero exit; keep `artefact refused` in the result either way. `agent_binary` answers `xc_agent installed; it restarts in 10 s`, and root has `run.sh` restart the agent 10 s after it wrote its result: raise the high-water and ack within that time (a redelivered `agent_binary` is refused by root as a replay).
- **Never logged:** a chunk, its data or a file's content. Name the command and the id.
- **Compatibility.** Today's agent never says `artefact`, so nothing changes for it; a root failure's stderr line is the only change it sees, and it carries it in its ack. A command with a grant that reaches an agent without the feature (none should) goes to PHP as today and is refused there (`not downloaded`). A MAIN rolled back below this increment answers the op `404 UNKNOWN_OP` and sends no grant; `artefact.fetch` then never comes. A node PHP older than this increment answers `--types` with exit 2, so the agent does not say the feature and MAIN grants that node nothing.

### Artefacts (Phase 4, fourth increment): root's commands without a shell

Upstream's Semgrep scan blocks new High findings on a pull request. It flagged the two command lines the third increment added (`php.lang.security.exec-use.exec-use`, "Executing non-constant commands"): `ArtefactStage::runs()`, `exec("sudo -n -u '#<uid>' timeout 10 '<binary>' version 2>/dev/null")`, and `RootSignalsCronJob::shell()`, `exec($rLine)` for `sudo <PHP_BIN> <MAIN_HOME>console.php module:install "<base64>" 2>&1` (with a trailing `&` without a staged archive) and for the agent's delayed restart. Every dynamic value in them was already quoted (`escapeshellarg`) or base64, so nothing could be injected; this increment removes the shell rather than only annotating the lines.

**What runs now.**

- `ArtefactStage::runs()`: `proc_open` with the argv list `[sudo, -n, -u, #<uid>,] timeout, 10, <binary>, version` (the `sudo` part only when root), stdin and stderr `/dev/null`, stdout read to its end with its first 64 KiB kept (`VERSION_OUTPUT`, the rest dropped so the binary never blocks on a full pipe), the exit status from `proc_close`. The test is unchanged: exit 0 and a first line that is not blank, within 10 s, as the directory's owner.
- `RootSignalsCronJob::moduleInstallArgv()` (was `moduleInstallLine()`): `[sudo, <PHP_BIN>, <MAIN_HOME>console.php, module:install, <base64 of the JSON payload>]`, the payload one argument whatever it holds. `RootSignalsCronJob::run()` (was `shell()`) runs it through `proc_open` with that argv list: stdin `/dev/null`, stdout and stderr read as they come into one output until both close (the old `2>&1`; `exec()` read its pipe until it closed too), returned as `exec()` returned it (each line without its trailing whitespace), with the exit status.
- The agent's restart after `agent_binary`: `run()` with `[/bin/sh, -c, RootSignalsCronJob::AGENT_RESTART]`, the constant `(sleep 10; pkill -u xc_vm -x xc_agent) > /dev/null 2>&1 &`. This is the only shell left, and nothing of a command's is in it: `sh` returns at once and the restart runs in the background 10 s later, as before.
- Each remaining `proc_open` has a one-line justification above it (an argv list; what each element is) and, on the line directly before the call, `// nosemgrep: php.lang.security.exec-use.exec-use`, the registry's rule id upstream's CI reports (its convention for a triaged finding).

**What stays as it was.** The legacy install (no staged archive) runs to its end before the action returns, its exit status and output ignored: its old line ended in `&`, but `exec()` read its output until `module:install` closed it, so it never ran in the background, whatever the third increment's note said (corrected above). The same holds for `certbot_generate`, `update_binaries` and `delete_module`, unchanged here: `shell_exec('… 2>&1 &')` also reads the console command's output until it closes it, so the action waits for its end, and `ok` is true whatever its result (the tenth Phase 7 increment's note, which said they start it in the background, is corrected too). The staged install's exit status and output are the command's; stdout and stderr, one pipe each now, join in the order they arrive. `sudo` as root, `PHP_BIN`, `MAIN_HOME`, the `PATH` lookup, the environment and the working directory are as before, on MAIN and in mode 0 as on a mode-1 or mode-2 node.

**How it differs from the plan.** Nothing: the plan does not say how root runs an action, and section 7's "checked for size and SHA-256 before exec" holds as before.

**Tests.** `ArtefactHashRefusalTest`:

- `testTheTrialRunPassesTheBinarysPathAsOneArgument`: a directory whose name holds `;`, `$(…)`, backticks, quotes, spaces, a newline, `|` and `&`. The binary runs alone as the directory's owner, its path one argument, with `version`, and none of the commands in the name runs.
- `testRootsCommandsRunWithNoShell`: `run()` hands each argument (the same shell syntax, and an empty one) to the program as it is, joins stderr to stdout, returns the program's exit status, and nothing else runs. An `install_module` payload full of shell syntax is `module:install`'s one argument.
- `testTheTrialRunNeedsExitZeroAndAFirstLine`: a binary that prints its version but exits 1, one whose first line is blank, and one that prints it only on stderr are each refused, and the running agent stays. One that writes 1 MiB to stderr, then 1 MiB to stdout after its version line, installs: stderr goes to `/dev/null` and stdout is read to its end.
- `testRootsCommandsNeverBlockOnAFullPipe`: `run()` reads both pipes as they come, so a command writing 1 MiB to stderr, then 1 MiB to stdout, exits 0 with all 2 MiB returned (under `timeout 10`, so a regression fails instead of hanging the suite); and its output is what `exec()` returned (`a \t\r\nb  \n\n` gives `a\nb\n`).
- `testInstallModuleTakesOnlyItsOwnStagedArchive` and `testAgentBinaryInstallsOnlyThisNodesArch` now check argv lists, since the tests' seam `RootSignalsCronJob::useRunner()` (was `useShell()`) takes one. The first also checks that the legacy install's failure is not the action's.

**The agent's contract.** Nothing changes on the wire, nor in what the agent sees. `agent_binary` still answers `xc_agent installed; it restarts in 10 s`, and root still has `run.sh` restart the agent 10 s after it wrote its result, so ack within that time, as the third increment says. An `install_module` with a staged archive still fails with `module:install`'s output. Today's agent and XC_VM_Fanout need no change.

### Logs and stream state (Phase 5, first increment)

A node whose LOGS or STREAMS flow is on stops writing its logs and stream runtime state into MAIN's database. The Phase 0 seams send them as events instead:

- **LB PHP.** `LogSink::write()` and `StreamStateWriter` redact first (`Redactor`), then hand the events to `Core\Cluster\EventSpool`. The spool holds one file per write under `config/cluster/spool/<lane>/`, written aside and renamed in. The lanes are:
  - `p0`: `stream.state`, never dropped;
  - `p1`: `log.<type>`, one event per `LogSink::CHUNK` rows.

  When the agent has not touched `flows.json` for 120 s (it does on every heartbeat), the spool refuses and the write falls back to SQL, so a stopped agent loses nothing.
- **Agent.** One loop per lane (P0 every 200 ms, P1 every 5 s) sends the oldest files to MAIN's `events` op, numbered from the lane's cursor (`useq`). Before each send it writes the batch's first number and file list to `<lane>.inflight`, so a crash or a lost reply resends the same files under the same numbers. Past 64 MB, P1 drops its oldest files and reports the count as a `skip` event, which is spooled so it survives a restart. `p0_reset` is not in yet.
- **MAIN.** `events` (`EventIngest`) applies a batch and the new cursor together:
  - P0 is gap-checked: a first number other than `useq_p0 + 1` gets a signed `409 USEQ_GAP {expected_useq}` and the agent renumbers.
  - P1 skips numbers at or below `useq_p1`.
  - A batch at or below the cursor is a repeat and applies nothing.
  - Every event applies as the sending node. `stream.state` merges only runtime-state columns into that node's own `streams_servers` row (`StreamRowMerge`), log rows get its `server_id`, and both are redacted again.
  - An event whose flow is off is dropped and counted.
  - `hello` returns the cursors.
- **Admin.** The Cluster Nodes page switches LOGS and STREAMS per node.

### Content (Phase 5, second increment)

The rest of what a node writes about its own content goes the same way, as P0 events through `Domain\Stream\ContentSink`. MAIN applies each event only where the node is the owner:

| Event | Flow | Written by | MAIN applies it when |
| --- | --- | --- | --- |
| `recording.state {id, status}` | CONTENT | `RecordCommand` | `recordings.source_id` is the node |
| `stream.worker {stream_id, worker, pid}` | STREAMS | `ArchiveCommand`, `ThumbnailCommand` (`tv_archive`, `vframes`) | `streams.<worker>_server_id` is the node |
| `vod.analysis {stream_id, props}` | CONTENT | `VodCronJob`, `CleanupCronJob` | the node holds the movie |

`vod.analysis` carries only the ffprobe keys (`duration_secs`, `duration`, `video`, `audio`, `subtitle`, `bitrate`), and MAIN merges them into its own `movie_properties`.

When an event changes a stream's routing state, MAIN writes the cache signal (`StreamProcess::updateStream`) itself. A node with STREAMS on no longer writes that signal into MAIN's database.

**Recordings.** A finished recording becomes one VOD through `Domain\Stream\RecordingFinalizer`. It works in two steps around the node's conversion to `VOD_PATH/<id>.mp4`:

1. `create()` makes the VOD row and its bouquets, and records it as `created_id`. It is idempotent.
2. `finish()` attaches the VOD to the node (pid 1, `to_analyze` 1) and sets status 2.

A legacy node runs both in-process, as before. A CONTENT node needs the id before it can convert, so it asks MAIN synchronously:

- **Agent socket.** The node calls the `recording_complete` op through the agent's local socket. The socket is `config/cluster/agent.sock`, mode 0660, and PHP reaches it through `Core\Cluster\AgentClient`. It serves `POST /v1/main/{op}` for an allowlist of ops, which is only `recording_complete` today. The plan puts the socket under `bin/xc_agent/sockets/`; it lives beside the agent's state instead, with the spool and `flows.json`.
- **Completion.** After converting, the node reports `recording.state` 2, and MAIN runs `finish()`.
- **Checks.** `recording_complete` needs the CONTENT flow, or MAIN answers `409 FLOW_OFF`. It answers only for the node's own recordings, and only takes an icon from the node's own image store.

The Cluster Nodes page switches CONTENT.

### Fanout monitor feed and P0 compaction (Phase 5, third increment)

**Fanout feed.** xc_fanout publishes its supervised streams' monitor transitions on `GET /events?boot=&since=&wait=` on its control socket:

- A watcher compares states every 250 ms. It ignores the counters that move on their own (uptime, the sampled bitrate).
- The last 4096 transitions are kept in a ring.
- A request is held up to 25 s when there is nothing new.
- A consumer that is new, behind the ring, or on another daemon life (`boot`) gets `reset` and a full snapshot.
- Viewer open and close join the feed in Phase 6.

**Agent side.** While STREAMS is on, the agent follows the feed (`-fanout-ctl`) and spools each transition as a P0 `stream.monitor {stream_id, state}` event.

- Before spooling, it redacts `source` and drops `last_error`.
- It names spool files on `CLOCK_MONOTONIC`, the clock of PHP's `hrtime()`, so the agent's files and PHP's sort together.
- Once the feed answers, the agent adds `"features": ["fanout_events"]` to `flows.json`. `NodeFlows::agentHas()` reads it, and the node's `reconcileSupervised` then stops writing that state. It still releases streams that nothing should produce.

**MAIN side.** MAIN derives the row from `stream.monitor` with the same pure rule PHP uses (`StreamProcess::supervisedRowUpdate`), against its own copy. It leaves a row the panel has stopped untouched and refreshes the stream cache on a change. Transitions reach MAIN within a poll step, not the reconcile's cadence.

**P0 compaction.** P0 is never dropped. Past 128 MB, the agent collapses the backlog instead, keeping the latest state per key:

- `stream.state` per row, with its fields merged;
- `stream.monitor` per stream;
- `stream.worker` per stream and worker;
- `recording.state` per recording;
- `vod.analysis` per movie, with its props merged.

Other event types are kept as they are, in order. The result replaces the oldest file, so it still goes first. MAIN applies it like any batch, so the plan's separate `p0_reset` event is not needed.

### Security blocks (Phase 5, fourth increment)

**Node side.** An LB's flood and bruteforce guard (`Core/Auth/BruteforceGuard`) still blocks the IP locally at once, with its `block_<ip>` file. Where it wrote the block to MAIN's `blocked_ips`, it now spools a P0 `security.block_ip {ip, reason}` once the node's CONFIG flow is on. CONFIG is the flow that makes the blocklist MAIN's. With the flow off, the agent stopped, or on MAIN itself, the guard writes the row as before.

**MAIN side.** `EventIngest` records the block in `blocked_ips` with the guard's reason, as the node used to, and audits it (`security.block_ip`). Every node picks it up with the blocklist. MAIN refuses and audits (`security.block_ip_refused`):

- a reason that is not one of the guard's own (`BruteforceGuard::REASON_PATTERN`), or an address that is not an IP;
- an address the guard never blocks: a cluster server's `server_ip`, `private_ip` or `whitelist_ips`, the admin allowlist (`allowed_ips_admin`), or loopback. A node therefore cannot lock the cluster out.

An address that is already blocked is accepted and left as it is.

**Not built:** the blocklist delta in `cluster_changes`. No block or unblock path writes it yet; it comes with the R1 replica (`ReplicaBuilder`, Phase 7), which must cover every path at once, MAIN's auto-unban included.

### Node state and inventory (Phase 5, fifth increment)

**Node side.** What a node writes about itself in its own `servers` row goes through `Core/Cluster/NodeStateSink`. With the TELEMETRY flow on it becomes an event; otherwise the row is written as before.

- `node.state {fields}`, on P0, when it changes: `certbot_ssl` (certbot command and cron), `governor` and `sysctl` (`cron:root_signals`).
- `node.inventory {fields}`, on P1, once a minute from `cron:servers`: `remote_status`, `xc_vm_version`, `server_hardware`, `governors`, `sysctl`, the devices, `gpu_info`, `interfaces` and `ping`. A newer inventory replaces an older one, so P1's drop-oldest cap costs nothing. P1 serves in place of the plan's P2; the P2 lane, built in the tenth Phase 6 increment, takes only `conn.touch` so far.

The plan names the second event `inventory`; it is `node.inventory` here, next to `node.state`.

**Never the node's.** Columns that grant or route stay with MAIN and the admin, and MAIN refuses them in either event:

- `whitelist_ips`, which feeds the allowed IPs (`/api`, the internal endpoints, the flood exemptions). The legacy cron wrote the node's interface addresses there; with TELEMETRY on the cron stops, and the column keeps what the admin or the last legacy write left.
- `server_ip`: `cron:root_signals` still auto-updates it with a direct write, and only while the node reaches MAIN's database. That rewrite turned out to run only on MAIN, for MAIN's own row (tenth Phase 7 increment).
- `status`, which the heartbeat owns.

**MAIN side.** `EventIngest` writes only the event type's columns of the sending node's own row. Each value must be a scalar of at most 256 KB. An inventory also sets `time_offset` from the node's heartbeat clock offset (`cluster_nodes.clock_offset_ms`, read when the inventory is applied: fifth cluster bus increment), which is what the legacy cron measured against the database clock.

**Root.** `cron:root_signals` and the certbot cron run as root. `EventSpool` hands a lane or file that root creates to the owner of the agent's state directory, so the agent can still read, compact and delete it.

**`stream.progress`.** No separate event is built. `progress_info` already reaches MAIN in `stream.state`: `cron:streams` sends it at most once a minute per stream, and MAIN treats it as cache-neutral. A created channel's encoding progress, every 10 s while it encodes, is the one faster writer. P0 compaction keeps one state per row, so none of it can grow the backlog. It stays in `stream.state` now that the P2 lane exists (tenth Phase 6 increment); it can move there as a type of its own.

### Connections (Phase 6, first increment): kills as commands

Every kill MAIN sends to another node's viewers now travels as a signed command when that node takes commands. Before this, only `SignalDispatcher::kill` in MySQL mode did.

- **Kills from `ConnectionTracker::redisSignal`.** In Redis mode, a worker pid becomes `conn.kill_worker`, and so does an RTMP client (`rtmp: true`). A daemon viewer's `drop_con` becomes `conn.drop {uuid}`. This covers every caller: `ConnectionTracker::closeConnection` and `ConnectionLimiter`'s kicks. Before, all of these went through `SIGNALS#<sid>` in MAIN's Redis.
- **Drops from `dropDaemonViewer`.** In MySQL mode, the drop of a viewer on another node also becomes `conn.drop`, instead of a `drop_con` row in `signals`.

**On the node.** `conn.drop` is restrictive, so it is signed without a licence. Repeat drops for the same viewer supersede each other through `dedupe_key`.

The agent runs `conn.drop` in its own process: a `DELETE /connections/<uuid>` on the fanout's control socket. A 404 is acked as "not connected here". When the agent cannot reach the fanout, `cluster:exec` does the same through `FanoutClient::dropConnection`. A kill reaches the node within a poll step of the `commands` long-poll.

### Connections (Phase 6, second increment): the connection store seam

The stream endpoints (`live.php`, `vod.php`, `timeshift.php`, `rtmp.php`) now reach the connection store only through `ConnectionTracker`. Before, each of them read and wrote `lines_live` or Redis inline. The seam's operations:

| Operation | What it does |
| --- | --- |
| `openRecord($settings, $record, $dbRow)` | Records a viewer. `$record` is the Redis record; `$dbRow` holds exactly the `lines_live` columns each caller wrote before. VOD still leaves `external_device` NULL, and RTMP still writes the node's own `date_start` on the table path. `createLive` goes through it too. |
| `findByUuid($settings, $uuid, $columns, $fallback)` | Finds a viewer by uuid. On the table path, an HTTP Range request without the uuid falls back to matching line (or HMAC key), container, agent and stream. **Superseded** by [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05): only `vod.php` passes the fallback, and it takes the address into account. |
| `updateLive` | Refreshes and re-opens a viewer (unchanged). |
| `heartbeat($settings, $uuid, $lastRead)` | The long-running viewers' five-minute check-in. |
| `acceptedIP($settings, $lineID)` | The IP the first open connection of a line came from (`disallow_2nd_ip_con`). |

It is a refactor: no store changes behaviour, as `ConnectionStoreTest` pins on both `lines_live` and a real Redis. The seam resolves the database through the current handle (`DatabaseFactory::get()`), the one the endpoints' global `$db` holds between `connectLazy()` and `close()`. A handle injected at boot may already be closed. Here, the next increment swaps in the node's agent as the store for nodes with CONNECTIONS on.

### Connections (Phase 6, third increment): the agent's connection registry

On a node whose CONNECTIONS flow is on, the agent holds the node's viewers, and the connection store seam reads and writes them there. CONNECTIONS needs COMMANDS and STREAMS, and the Cluster Nodes page refuses it without them.

**Seam.** The seam's methods go to the agent: `openRecord`, `findByUuid` (with the Range fallback), `lookupLive`, `updateLive`, `heartbeat` and `acceptedIP`. They use `Core\Cluster\AgentConnections` over the local socket, with a 1 s timeout. The stream endpoints make no WAN call for their viewers any more. When the agent does not answer, the call falls back to MAIN's store, so a viewer is never held up by the agent. `lookupLive` applies the same owner, server, container, stream and open checks the table path's query does.

**Agent.** The agent's `Registry` holds the records, in ConnectionTracker's Redis record shape, and keeps a snapshot in `config/cluster/registry.snap`. It serves `/v1/conn/...` on the local socket. It mirrors every change to MAIN as P0 events, spooling the event before changing the record:

- `conn.upsert {record}` for a new or changed connection. A change of `hls_last_read` alone goes at most every 10 s, inside the 30 s after which MAIN's reaper closes an HLS viewer.
- `conn.remove {uuid}` when the node removes a connection.

**MAIN.** MAIN keeps its store current from these events, in Redis or `lines_live` as `redis_handler` says (`Domain\Cluster\ConnectionIngest`). The reaper, the limits and the admin read what they always read. A node writes only its own connections: `server_id` is the sender, the line identity is recomputed from the record's owner, and a uuid another node holds is refused.

**Closes.**

- **Decided on MAIN** (a kick, a limit, MAIN's reaper): the close reaches the node as `conn.close {uuid, remove}`, which is restrictive and deduplicated per viewer. The agent applies it to its registry in-process. Without this, the player's next playlist request would resume a kicked HLS viewer from the node's registry.
- **Made by the node itself** (its reaper, in MySQL mode): the node still writes MAIN's store directly, and tells its registry with `POST /v1/conn/{uuid}/close`, which sends no event.

**Known gap.** An upsert already in flight when MAIN closes the same viewer can re-open it in MAIN's store. The node's registry holds the viewer as ended, so its next request starts a new connection, with the token's checks. Admission, snapshots with digests and the agent's HLS reaper are the next increments.

### Connections (Phase 6, fourth increment): limits on MAIN

A node whose CONNECTIONS flow is on does not run `ConnectionLimiter` against MAIN's store any more. That would be a WAN round trip on every viewer's open, and it could evict the viewer that just opened.

**Node.** When a viewer opens with a limit, `StreamAuth::validateConnections` spools a P0 `conn.limit` event after the viewer's `conn.upsert`. For a line it carries `{uuid, ip, user_agent, user_id}`. For an HMAC identity it carries `{uuid, ip, user_agent, hmac_id, hmac_identifier, max_connections}`. If the spool refuses (for example, a stale `flows.json`), the node enforces the limit itself, as before.

**MAIN.** `EventIngest` accepts `conn.limit` only from a node with CONNECTIONS. It queues the check as a file in `TMP_PATH/cluster_limits/` (`Domain\Cluster\ConnectionLimits`), so the events op returns at once. `cron:signals` drains the queue on its 1 s loop, and runs each check with these rules:

- The viewer must be in MAIN's store under the sending node, with the same owner. Otherwise the check is dropped: the viewer is gone, or it is not that node's.
- A line's limit is read from `lines`, never from the event. An HMAC identity's limit is the node's, because MAIN has no row for it.
- `StreamAuth::validateConnections` then runs on MAIN, with the viewer's IP. It closes the owner's oldest connections, preferring the requesting device, as it does on a legacy node.

A viewer over its limit is closed within about 1–1.5 s of opening on another node. The viewer that just opened is not the one evicted.

**Closes reach the node.** `ConnectionLimiter::closeConnection` also sends `conn.close {uuid, remove}` when the viewer is another node's and that node has CONNECTIONS. This covers any close made by the limiter, on MAIN or from another path. An ended HLS viewer is kept as ended (`remove: false`); anything else is removed. Without this, the node's registry would resume a kicked HLS viewer on the next playlist request.

Admission when the token is minted (reservations) is not in this increment.

### Connections (Phase 6, fifth increment): digest, snapshot and seed

MAIN's store for a CONNECTIONS node can drift from the node's registry. Causes include an event lost to a bug, an upsert that re-opens a viewer MAIN closed, or a registry restored from an older `registry.snap`. The digest finds a drift, and a snapshot repairs it.

**Digest.** Both sides compute it over the open connections (`hls_end` not set), in `Domain\Cluster\ConnectionDigest` and the agent's `Registry.Digest`. Both pin one test vector. The digest is `{count, users, xor64}`:

- `count`: the number of open connections.
- `users`: the number of distinct owners. An owner is `u:<user_id>`, or `h:<hmac_id>:<hmac_identifier>` for an HMAC identity.
- `xor64`: the XOR of the first 8 bytes of SHA-256(`uuid` "\n" owner), as 16 hex digits.

**Heartbeat.** An agent whose CONNECTIONS flow is on adds `conn_digest` to every heartbeat. MAIN compares it with its store for that node at most every 4 s. It answers `want_conn_snapshot` only when two checks in a row disagree, because events in flight catch up well within that. It asks one node at most once every 30 s. The check's state is a small file per node in `TMP_PATH/cluster_digest/`.

**Snapshot.** The agent sends its whole registry with the `conn_snapshot` op, `{snap_id, seq, last, records}`. The registry goes in chunks of 1000 records, numbered from 0, up to 50 chunks. MAIN keeps the chunks in `TMP_PATH/cluster_snapshots/<sid>/` and applies nothing until the last one arrives. Then:

- every record is upserted as `conn.upsert` would be;
- every open connection MAIN holds for the node that the snapshot lacks is removed;
- records that are not the node's are dropped, as at ingest.

A chunk 0 starts over. Any other chunk out of order gets `409 SNAP_GAP {expected_seq}`, and the agent drops that snapshot; MAIN asks again if the drift stays. The op needs CONNECTIONS (`409 FLOW_OFF`), and each snapshot applied is audited as `conn.snapshot`.

"Applied as a whole" means MAIN changes nothing before the last chunk. The apply itself is a sequence of store writes, not a transaction. Events the node spooled before the snapshot can land after it; they carry older or equal state, and a drift they leave is caught by the next check.

**Seed.** `console.php cluster:seed-connections` runs on the node while it still reaches MAIN's store, before the CONNECTIONS flow is switched on. It loads the node's connections from MAIN's store (Redis `SERVER#<sid>`, or `lines_live`) into the agent through `POST /v1/conn/seed`. The first chunk empties the registry, and loading sends no event. Only the registry record's keys are sent (`AgentConnections::RECORD_KEYS`), never the line's other columns. After the switch, the first digest agrees and no snapshot is needed.

Still to come in Phase 6: rebuilding the registry from the fanout and the HLS markers after an agent restart. Until then, a restarted agent has only `registry.snap`. A snapshot makes MAIN's store match the registry, not the other way round, so viewers missing from an older `registry.snap` drop out of MAIN's store. An HLS viewer is recorded again on its next playlist request. A TS viewer the fanout serves is not counted toward its line's limit until the registry is rebuilt from the fanout.

### Connections (Phase 6, sixth increment): the agent's HLS reaper

**The problem.** An HLS viewer has no worker to watch, only its playlist requests. The legacy reaper (`UsersCronJob`) ends one 30 s after its `hls_last_read`. On a CONNECTIONS node, that time reaches MAIN only in the agent's upserts, at most every 10 s. A slow or cut link to MAIN would therefore end viewers who are still watching.

**On the node.** The agent's `Registry.Reap` runs every 5 s while CONNECTIONS is on:

- It ends an open HLS viewer that has made no playlist request for 30 s: `hls_end` 1, sent as a P0 `conn.upsert`, spooled before the registry changes.
- The time is the node's own: when a request last changed the record's `hls_last_read`. A clock step does not end anyone, and neither does MAIN being out of reach.
- After a restart, every viewer loaded from `registry.snap` gets a full 30 s window.
- A request after the end re-opens the viewer, as before.

**Telling MAIN.** The agent says `features: ["hls_reaper"]` at hello, and MAIN keeps it in `cluster_nodes.features` (migration 041). An older agent says nothing, so MAIN keeps doing everything itself. The agent also writes `hls_reaper` into `flows.json`.

**MAIN's reaper.** `UsersCronJob` asks `Core\Cluster\HlsReaping`. For an active node in mode ≥ 1, with CONNECTIONS on and the feature:

- the 30 s rule is off;
- only what the node ended (`hls_end` 1) is closed, with the usual activity row and a `conn.close` back to the node.

An LB that reaps its own rows (MySQL mode) asks its own agent through `NodeFlows` instead.

**Orphans.** A node that falls silent would keep its viewers counted against their lines forever, so it is orphaned once both of these hold:

- it has been silent for `cluster_orphan_conn_ttl_sec`, counted from `max(last_seen_at, cluster_ready_at)`;
- MAIN's reaper has itself watched it stay silent that long (`TMP_PATH/cluster_orphans.json`).

A gap of more than 3 minutes between reaper passes restarts the watch, and so does the fleet silence guard, so MAIN's own downtime never orphans a node (see "Acceptance tests that found gaps").

**The orphan purge.** Every CONNECTIONS node is watched this way, whether or not its agent reaps. An orphaned node's rows, HLS and TS alike, are purged from MAIN's store only (`ConnectionIngest::purgeNode`, audited as `conn.orphan_purge`), so they stop counting toward their lines' limits. The purge sends no kill and no command: the node's registry still holds its viewers. If the node comes back, its digest disagrees and a snapshot restores them. Before this, a dead node's TS rows stayed for ever, because the reaper kept trusting the node's last `php_pids` list, and it skips daemon-served rows (pid 0) altogether.

**Touches.** Touches still reach MAIN every 10 s, because a panel that predates this reaps by the 30 s rule. Moving them to the bus (`conn.touch`, every 60 s) waits for the bus. `conn.divergence` is not built: divergence still reaches `lines_divergence` the legacy way. (Both were built in the tenth increment.)

### Connections (Phase 6, seventh increment): admission when the token is minted

MAIN now applies a line's limit when it mints the stream token, before the viewer reaches a node (`Domain\Cluster\ConnectionAdmission`, called at the six viewer mint sites in `Public/stream/auth.php`). Thumbnails and subtitles are not admitted.

**When it applies.** All of these must hold:
- the cluster API is on;
- the line or HMAC identity has a limit;
- the node that will record the viewer is active, in mode ≥ 1, with CONNECTIONS on. Behind a proxy, that node is the originator.

Other targets are unchanged: a legacy node limits at open, as before.

**What it does.**
1. **Reserve.** The viewer's uuid is reserved for the identity for the token's life (`create_expiration`) plus 10 s, and the identity's other reservations still in flight are counted.
   - **The cluster bus**, when MAIN runs it: a Lua script on `RESV#<identity>`, in either store mode.
   - **Without the bus, Redis mode:** the same script on the shared Redis.
   - **Without the bus, MySQL mode:** `cluster_reservations`, the table migration 032 created for this.
   - **No lock:** insert-then-count needs none, because of two concurrent mints at least one sees the other.
2. **Evict.** `ConnectionLimiter::closeConnections` cuts the identity's open connections, and the pair's, to leave room for this viewer and the ones in flight. The order is the limiter's: the requesting device first, then the oldest. The new viewer is never evicted, because it is not open yet. Closes on CONNECTIONS nodes go out as commands, as every close MAIN makes does.
3. **Release.** When the node reports the connection (`ConnectionIngest::upsert`), the reservation is released.

**The node's `conn.limit` stays.** It is the re-check that settles a race between two nodes. After admission it normally finds nothing to do.

**Failures.** Admission never refuses a viewer and never fails a request. When the store or the registry cannot be read, it does nothing, and `conn.limit` enforces the limit once the viewer opens.

**Not built:**
- the `adm` claim in the token;
- the `conn_admit` op, with `lb_offline_admission`, for tokens minted without admission.

A CONNECTIONS node already makes no WAN call for limits: it spools `conn.limit`. So these matter only when the cluster bus replaces MAIN's store. The ninth increment builds both on the panel.

### Connections (Phase 6, eighth increment): TS closes from the fanout

**Before.** Under X-Accel, no PHP worker sees a daemon-served TS viewer leave. On a CONNECTIONS node, that close waited for `FanoutSyncCommand`, which reads MAIN's store over the WAN and closes rows the fanout no longer holds.

**The fanout.** It now publishes `conn_close {stream, uuid}` on `GET /events` when a uuid's last connection leaves a stream, whether the client went or the panel dropped it.

**The agent.** While CONNECTIONS is on, it follows that feed:
- **Check.** It confirms against `GET /connections` that the uuid is really gone, since a viewer may have reconnected with the same uuid.
- **Close.** For each open, non-HLS registry record with pid 0, it spools a P0 `conn.close {uuid}` and then drops the record. A spool that refuses leaves the feed where it was, so the events come again.
- **Scope.** PHP-served viewers have a worker to watch, and HLS viewers have the reaper, so neither is touched.

**MAIN.** `ConnectionIngest::close` closes the node's own row as `fanout_sync` would, with its activity row. It sends no kill and no `conn.close` back, because the viewer is already gone and the registry has already dropped it. A row that is already gone is accepted.

**Compatibility and safety net.**
- An older panel drops the unknown event, so its lane still advances.
- An older fanout sends nothing.
- `FanoutSyncCommand` keeps running as the safety net, for closes a feed reset loses and for when the fanout cannot answer.

The close reaches MAIN within about a second, with no WAN read from the node.

### Connections (Phase 6, ninth increment): the `adm` claim, `conn_admit` and the offline policy

This increment builds the panel half of plan section 8, "Global `max_connections` and kills", steps 4 to 6. The agent half is specified under **The agent's contract** below and is built in xc_vm_fanout #30.

**The claim.** A token minted after admission applied carries `adm: {exp, sid}`. `ConnectionAdmission::admitToken` adds it at the six viewer mint sites in `Public/stream/auth.php`.
- `exp` is when the reservation expires: MAIN's unix seconds, `create_expiration` + 10 s after the mint.
- `sid` is the node the reservation was made for: the originator behind a proxy, else the redirect target.
- The reservation's id is the token's own `uuid`.

The token is sealed with MAIN's stream secret, so a node trusts the claim once the token opens. With `secure_stream_tokens` off, the token is the legacy AES-CBC format with no MAC, and the claim is exactly as forgeable as the credentials beside it. The node's `conn.limit` re-check follows every open either way.

A token without a claim was minted before this, or where admission did not apply: an unlimited line, a target without CONNECTIONS, or a store that could not be reached.

**The node's PHP.** A new viewer is registered through `ConnectionTracker::openRecord`. live.php, vod.php and timeshift.php now pass it the token and the node's `time_offset`. On a CONNECTIONS node, `AgentConnections::register` adds an `X-XCVM-Admission` header to `PUT /v1/conn/{uuid}`, built by `AgentConnections::admission`.
- **Why a header.** An agent that predates it ignores the header, and the record it stores stays the record.
- **When.** Only a new viewer with a limited token (`max_connections` > 0) sends it, and only while `flows.json` says the node is `active`. Refreshes (`updateLive`) and endpoints without a token do not; RTMP does since a later change (below, **Not built**). A quarantined node sends none: MAIN mints it no claim and answers its `conn_admit` with `NOT_ACTIVE`, so its viewers are admitted without asking, as before this increment.
- **Timeout.** Such a register waits 2.5 s (`ADMIT_TIMEOUT`) instead of 1 s, since the agent may ask MAIN for up to 1.5 s.

PHP reads the agent's answer as follows:
- **200:** admitted. This is what every older agent answers.
- **403 `{admit: false, reason}`:** refused. Nothing is recorded, in the registry or in MAIN's store. A reason that is not `[A-Z_]{1,32}` reads as `REFUSED`. live.php (HLS and TS), vod.php and timeshift.php then refuse the viewer through `StreamAuth::refuseAdmission`, the way auth.php refuses the same condition at mint (`StreamAuth::admissionRefusal`). The client log's data is `admission: <reason>`.
  - `EXPIRED`: `USER_EXPIRED` and the expired video.
  - `BANNED`: `USER_BAN` and the banned video. `DISABLED`: `USER_DISABLED` and the banned video.
  - `UNKNOWN_LINE`, `UNKNOWN_HMAC`: `AUTH_FAILED` and `INVALID_CREDENTIALS`.
  - `LIMIT`, `OFFLINE`, `REFUSED` and any other reason: `USER_ALREADY_CONNECTED` and the "connected" video, as live.php refuses a line already connected elsewhere.
  - Without the video, each falls back as `OffAirHandler::showVideoServer` does: `EXPIRED`, `BANNED` or a 404.
- **Anything else, or no answer:** the viewer goes to MAIN's store, as when the agent is down.

**HLS.** live.php records an HLS viewer under its playlist key, not the token's uuid. So the reservation made at mint was never released, and it counted against the line until it expired. When the token has a claim and the two uuids differ, the record now names the reserved uuid as `adm_uuid`. `ConnectionIngest::upsert` releases it with the viewer. `adm_uuid` is not a store column; the agent stores and mirrors it like any other record key.

**MAIN: `conn_admit`.** Admission for a viewer whose token has no claim, or an expired one (`ConnectionAdmission::forNode`).
- **Transport.** `POST`, ctl lane, session auth (MAC and BOX, no node signature). The node must be `active` (409 `NOT_ACTIVE`) with CONNECTIONS on (409 `FLOW_OFF {flow: "connections"}`). A malformed request gets 400 `BAD_REQUEST`, and a database MAIN cannot read gets 503 `DB`.
- **Request.** `{uuid, line_id | hmac_id + identifier, stream_id, ip, ua}`, with exactly one identity; the exact fields are under **Wire** below. MAIN reads the line itself and ignores any limit the node sends.
- **Refused:** a line auth.php would refuse before it mints, checked in auth.php's order: `UNKNOWN_LINE`, then `EXPIRED` (`exp_date` not after now), `BANNED` (`admin_enabled` 0), `DISABLED` (`enabled` 0). An HMAC key that is unknown or disabled gets `UNKNOWN_HMAC`. Bouquets, allowed IPs and agents, countries and ISPs are not checked again: auth.php checked them before it minted the token.
- **Admitted:** everything else, since admission never refuses a valid viewer. A limited line is reserved for the authenticated node, as at mint. The cut that makes room for the viewer is queued (below), and the viewer is never cut. An unlimited line needs no reservation. An HMAC identity is reserved but not cut, because its limit is signed into the client's request and stored nowhere. The node's `conn.limit`, which carries that limit, enforces it. A reservation store that cannot be reached reserves nothing and still admits.
- **Idempotent.** A repeated uuid refreshes its own reservation and gets the same answer.

**Wire.** The request is the BOX'd JSON object:
- `uuid`: string, `[A-Za-z0-9_-]{1,64}`, the PUT's uuid. In MySQL store mode without the cluster bus, a uuid longer than 32 characters is admitted but not reserved (`cluster_reservations.id` is `char(32)`, the size auth.php mints).
- Exactly one identity:
  - `line_id`: int > 0; or
  - `hmac_id`: int > 0 with `identifier`: string. MAIN keeps the identifier's first 255 bytes, as `conn.limit`'s queue does.
- The other identity's id is absent, `null` or `0`, so a Go struct without `omitempty` works. `identifier` is ignored beside `line_id`. A string where an int belongs (`"42"`), both ids > 0, or neither is 400 `BAD_REQUEST`.
- `stream_id`: int ≥ 0, optional (0).
- `ip`: string, optional (""). MAIN keeps its first 64 bytes.
- `ua`: string, optional (""). MAIN keeps its first 512 bytes.
- Any other key, `max_connections` included, is ignored.

The reply is BOX'd with the session keys, status 200:
- `admit`: bool;
- `exp`: int, MAIN's unix seconds until which the reservation holds, 0 when refused;
- `reason`: string, only when refused;
- `main_time_ms`: int.

The denials are signed and name the node and the request's nonce: 409 `NOT_ACTIVE`, 409 `FLOW_OFF {flow: "connections"}`, 400 `BAD_REQUEST` and 503 `DB`. The session denials of every ctl op, such as 401 `BAD_MAC` or `TOKEN_EXPIRED`, also apply.

**The cut is queued.** The cluster endpoint has none of the legacy globals `ConnectionLimiter` needs (`SERVER_ID`, `$rServers`), and the ctl lane must answer within 1.5 s. So `conn_admit` queues the cut in `conn.limit`'s queue (`ConnectionLimits::queueAdmission`, written by MAIN only), and `cron:signals` runs it within about a second (`ConnectionAdmission::cut`).
- The limit and pair are read from `lines` again when the cut runs.
- The room is the limit, less the line's other reservations still in flight when the cut runs (`ConnectionAdmission::inFlight`, in the store `reserve` wrote to), less one for the viewer. A viewer that has opened by then is already counted among the open connections, so it takes no extra room. An ended record under its uuid, such as a closed HLS key, is not open.
- The reservations are counted when the cut runs, not at admission. A reservation counted at admission may open before the cut runs: ingest releases it, and it is then one of the open connections. A count kept from admission would take it twice and evict a viewer within the limit.
- A store that cannot be read cuts nothing, as at mint. `conn.limit` follows the open.
- A uuid that MAIN's store holds for another node drops the cut.
- The queued check is marked `admission: true`. `ConnectionLimits::queue`, which takes a node's `conn.limit`, rebuilds the check from its own keys, so a node cannot queue an admission cut, which skips the owner check.

**Delivering the policy.** The hello and heartbeat replies carry `offline_admission` (`local`, `allow` or `deny`) at the top level, normalised from `lb_offline_admission`. A change reaches every node within one heartbeat, without the CONFIG flow or a replica. It is not in `policy`: that object is versioned by `policy_ver`, which only endpoint changes raise.

**The agent's contract.** For the Go half, built in xc_vm_fanout #30:
1. **The register.** `PUT /v1/conn/{uuid}` keeps its body (the record) and its 200 answer with the record. The new request header `X-XCVM-Admission` is a compact JSON object, ASCII-only (non-ASCII is `\u`-escaped), with no CR or LF:
   - `adm` (optional): `{exp: int, sid: int}`. PHP passes it only when `exp` is not past on the node's clock corrected by `time_offset`, and when `sid` is the record's `server_id`.
   - `line_id: int`, or `hmac_id: int` and `identifier: string` (as the record's, uncapped).
   - `stream_id: int`, `max_connections: int` (≥ 1), `ip: string`, `ua: string`.
   - A missing header, or one that is not a JSON object, means a plain register, as today.
   - So does an object without exactly one valid identity (`line_id` an int > 0, or `hmac_id` an int > 0 with a string `identifier`), or whose `max_connections` is missing, not an int, or < 1.
   - An `adm` that is not an object with int `exp` and int `sid` is ignored, as if absent.
   - The agent does not compare `adm.sid` with its own server id: PHP passes the claim only when `sid` is the record's `server_id`.
2. **With `adm`.** When `adm.exp × 1000` is not before MAIN's time as the agent keeps it (`MainNowMs`), the viewer is admitted with no WAN call: store it and answer 200.
3. **Without it.** When the agent's last hello or heartbeat reply has `state` other than `active`, or CONNECTIONS off, it does not call `conn_admit` and admits. Otherwise, call `conn_admit` with `{uuid, line_id | hmac_id + identifier, stream_id, ip, ua}`, as under **Wire** above: the other identity's keys left out, never `max_connections`. The call has 1.5 s from the PUT's arrival, waiting for the ctl lane included.
   - A MAC'd 200 with `admit: true` admits.
   - A MAC'd 200 with `admit: false` refuses with MAIN's `reason`.
   - A verified denial (`*Denial`: panel-signed, naming this node and this request's nonce) with reason `NOT_ACTIVE`, `FLOW_OFF` or `BAD_REQUEST` admits. MAIN has answered, and admission does not apply to this node or this request. Its `conn.limit` still follows the open.
   - Anything else applies the offline policy: a transport error, a timeout, nginx's unsigned errors or `STARTING`, a reply that does not verify (`ErrTransport`), and any other verified denial (`DB`, `TOKEN_EXPIRED`, `RATE_LIMITED`, and so on). An older MAIN's `UNKNOWN_OP` names no node or nonce, so it reaches the agent as `ErrTransport`.
4. **The offline policy.** The agent uses the last `offline_admission` from a hello or heartbeat reply. It keeps it across restarts in its state file, and uses `local` until it has one. A value other than `local`, `allow` or `deny` is ignored, and the value it holds is kept.
   - `allow`: admit.
   - `deny`: refuse with `OFFLINE`.
   - `local`: count the registry's open records (`hls_end` not set) with the viewer's owner, leaving out this uuid and the same device's records (the same `user_ip` and `user_agent` as the PUT's record). The owner is the digest's, taken from the record: `u:<line_id>` or `h:<hmac_id>:<identifier>`. Refuse with `LIMIT` when the count is at least `max_connections`; otherwise admit. It never ends a record.
5. **Answers.** Admit: store and answer 200 with the record, as today. Refuse: answer 403 with `{"admit": false, "reason": "<REASON>"}`, store nothing and spool no event. A refused uuid already in the registry, such as an ended HLS record, stays as it was.
6. **Caching.** The agent may treat an admitting `conn_admit` answer as an `adm` for that uuid until its `exp`.
7. **Reasons.** From MAIN: `UNKNOWN_LINE`, `EXPIRED`, `BANNED`, `DISABLED`, `UNKNOWN_HMAC`. From the agent: `LIMIT`, `OFFLINE`.

**Differs from the plan.**
- **`local` refuses the newcomer.** Legacy enforcement, and MAIN's own cuts, admit the newest viewer and close the oldest. While MAIN is out of reach, `local` refuses a newcomer from another device instead of ending an open viewer. The plan's DEGRADED state keeps existing sessions, and Phase 6's acceptance drops no viewer while MAIN is stopped for 5 minutes. Evicting from the node would break both. The same device's records are left out of the count, so a channel switch is admitted while the old record ends. For HLS that takes up to 30 s, the reaper's wait. Once MAIN answers again, the spooled `conn.limit` applies newest-wins.
- **An answer that admission does not apply is not an outage.** The plan applies `lb_offline_admission` only when MAIN is unreachable. A verified `NOT_ACTIVE`, `FLOW_OFF` or `BAD_REQUEST` is MAIN's answer, so the agent admits. Otherwise a quarantined node, or one in a CONNECTIONS rollback, would ask on every limited viewer and get the offline policy every time. Under `deny`, all of those viewers would be refused.
- **Quarantined nodes are admitted without asking.** The agent's socket stays on for a quarantined node (`NodeFlows::on` accepts it), but MAIN mints no claim for one, and `conn_admit` accepts only `active` nodes. Its viewers are admitted as before this increment: PHP sends no header, and the agent skips `conn_admit`.

**Compatibility.**
- An older agent ignores the header and answers 200: every viewer is admitted, as before, and `conn.limit` still enforces the limit on MAIN.
- An older agent also ignores `offline_admission`.
- An older MAIN mints no claim. It answers `conn_admit` with an `UNKNOWN_OP` that names no node or nonce, which the contract treats as MAIN out of reach. The node's PHP ships in the same release as MAIN, so that happens only while a fleet is being upgraded.

**Not built:**
- the agent's half, above;
- admission for RTMP viewers (`rtmp.php` has no stream token). Built later: `rtmp.php` passes `openRecord` the line's `max_connections` as a token without a claim, so a limited line's RTMP viewer is admitted as a viewer whose token has none: the agent asks MAIN's `conn_admit`, with an empty `ua`. A refusal is logged with the event `StreamAuth::admissionRefusal` gives its reason (`admission: <reason>`), and the play gets a 404, since RTMP has no off-air video to show. `AgentAdmissionTest::testAnRtmpViewerIsAdmittedWithoutAClaim` covers the register and the wiring;
- an audit row for refusals. A refusal is only in the client log, so a flood of expired tokens cannot fill `cluster_audit`.

Tests:
- `ConnectionAdmissionTest`: the claim; `conn_admit`'s refusals and their order; the HMAC case; idempotency; the wire's tolerance (an id of 0, a long identifier); the queued cut, which counts in flight when it runs, never twice, and cuts nothing when the store cannot be read; the HLS release.
- `ConnectionLimitsTest`: a node's `conn.limit` cannot queue an admission cut.
- `ClusterApiTest`: the op (MAC, flow, state, MAIN's own line, the node's reservation, 503 `DB`) and the policy in hello and heartbeat.
- `AgentAdmissionTest`, against a stand-in agent on a unix socket: the header (ASCII whatever the user agent; `adm` judged on MAIN's clock through `time_offset`; none on a quarantined node); live.php's `createLive` passing the token on; the unchanged body; the 403 refusal recorded nowhere and cleared by the next viewer; how each reason is shown; an older agent; the 2.5 s wait; and the endpoints' wiring.

### Connections (Phase 6, tenth increment): `conn.divergence`, and the P2 lane with `conn.touch`

This increment builds the panel half of two rows of the plan's section 7 events table, `conn.divergence` (P1) and `conn.touch` (P2), and the P2 lane of section 8, "Ordering and backpressure". The agent needs no change for the first. The second is specified under **The agent's contract** below and is not built in the agent yet.

**Divergence before.** Two writers on every node, MAIN included, put a viewer's divergence into MAIN's database:
- `cron:users` reads the speed files (KiB/s, one per uuid in `DIVERGENCE_TMP_PATH`) that live.php's pre-fanout TS loop, vod.php and timeshift.php write.
- `fanout_sync` reads the fanout's `GET /rates` for the node's daemon-served TS viewers.

Each compared the rate with the stream's bitrate on that node and wrote `lines_divergence`, and `lines_live.divergence` in MySQL store mode. MAIN's `cron:users` deletes the `lines_divergence` rows of connections its store no longer holds.

**Divergence now.** On a node whose CONNECTIONS flow is on, both writers hand the rates to `Core\Cluster\DivergenceSink::spool()`, which spools a P1 event through the `EventSpool`. When the spool refuses (flow off, the agent stopped), they write MAIN's tables as before. MAIN never has flows, so it always writes its own.
- **Event:** `conn.divergence {rows: [{uuid, rate}, …]}`. `uuid` is a string matching `[A-Za-z0-9_-]{1,32}`, the width of `lines_divergence.uuid`. `rate` is an int ≥ 0, in KiB/s. There are at most 1000 rows per event (`DivergenceSink::CHUNK`), and the events of one write go in one spool file. Rows that do not qualify are left out on the node.
- **MAIN** (`EventIngest`, `ConnectionIngest::divergence`) takes the event on P1 only, from a node with CONNECTIONS on:
  - It keeps the rows whose uuid MAIN's store (Redis, or `lines_live`) holds for the sending node. Rows with a bad uuid or a rate that is not an int ≥ 0 are skipped.
  - It works out each row's divergence with the node cron's formula, now shared: expected = `bitrate / 8 · 0.92` from the node's `streams_servers` row, and divergence = the percentage by which the rate falls short of it (0 when faster, or when no bitrate is known).
  - It writes them with one `REPLACE INTO lines_divergence`, and in MySQL store mode one `UPDATE lines_live … CASE activity_id`.
  - An event with none of the node's viewers, no valid row, more than 1000 rows or `rows` that is not a list is dropped and counted.
  - A store that cannot be read also drops the event instead of failing the batch. The next report comes within a minute, and the lane's logs are not held up for it.
- **Cleanup.** MAIN's cleanup of `lines_divergence` is unchanged, since the rows it writes are keyed by connections its store holds.
- **Agent.** The agent ships the event like any P1 spool file and needs no change.
- **No backlog.** Only a writer's latest report counts, and P1 is shared with the node's logs:
  - `fanout_sync` reports at most once a minute (`FanoutSyncCommand::DIVERGENCE_EVERY`), as often as `cron:users`. At its 10 s pass, 20k daemon viewers (about 56 bytes a row) would put about 6.6 MB a minute on P1, over half of what the agent's P1 loop ships (one batch of at most 1 MiB every 5 s). Without CONNECTIONS it still writes MAIN's tables every pass, as before.
  - A writer skips a report while its previous one is still in `spool/p1`, not yet sent (MAIN out of reach, the lane behind). `EventSpool::append` tags the file (`<hrtime>-<pid>-<rand>-divergence_cron.ndjson`, `…-divergence_fanout.ndjson`) and `EventSpool::pending()` finds it. During a MAIN outage each writer therefore leaves one report in the spool, and the lane's cap drops no logs for stale divergence. A skipped report writes nothing; with the agent stopped (`EventSpool::agentAlive()` false), the writer writes MAIN's tables as before.
  - The agent treats spool file names as opaque: it ships every `*.ndjson` in name order, so the tag needs no agent change.

**The P2 lane.** The `events` op takes `lane: "p2"`, for state of which only the newest value per key counts:
- **No number.** `first_useq` is not read and may be left out. The lane has no cursor and no `cluster_nodes` column, and `hello`'s `cursors` stays `{p0, p1}`.
- **Never a 409 `USEQ_GAP`.** Only the 400 `BAD_REQUEST` of any malformed batch (for example `events` not a list, or more than 5000 events), 503 `DB`, and the session denials of every op apply. Among those is 409 `NOT_ACTIVE`: the `events` op takes only an active node, while heartbeat, which a quarantined node still sends, keeps listing `p2_types`.
- **Reply:** BOX'd 200 `{ok: true, useq: 0, applied, dropped, main_time_ms}`.
- **Latest wins.** A batch is folded to the latest event per key by the event's `t`; of two with the same `t`, the later in the batch wins. Across batches, whatever holds the value keeps the latest too, so a repeated or late batch changes nothing newer.
- **Refusals.** An event of a type MAIN does not take on P2, or whose flow is off, is dropped and counted.

**`conn.touch`.** `{type: "conn.touch", t, d: {uuid, hls_last_read}}` is P2's first type: when a viewer last asked for its playlist, keyed by uuid.
- `t`: int ≥ 0, unix milliseconds on the agent's clock, as in every spool line. Only the order of one viewer's touches matters (see the contract, item 4).
- `uuid`: string matching `[A-Za-z0-9_-]{1,64}`.
- `hls_last_read`: int ≥ 0, the registry record's own value (the node's clock corrected by `time_offset`, as PHP writes it).
- It needs CONNECTIONS. Anything else is dropped.

Where MAIN keeps it (`ConnectionIngest::touch`):
- **The cluster bus only**, for a node whose agent ends its own idle HLS viewers (`HlsReaping::capable`: mode ≥ 1, CONNECTIONS on, `hls_reaper` said at hello). The key is `touch:<sid>:<uuid>` and holds `<t>:<hls_last_read>`. A Lua script replaces it only with a `t` that is not earlier, and it expires 5 minutes after its last write (`ClusterBus::TOUCH_TTL_MS`). Two bounds keep the bus safe from a node:
  - **Only the node's own viewers.** MAIN reads its store once per batch (one `MGET` on Redis, one `SELECT … uuid IN` on `lines_live`) and keeps the touches of the uuids it holds for the node. A node can neither create keys for made-up uuids nor touch another node's, and the bus holds at most one key per connection of a reaping node.
  - **At most half the bus.** Past `ClusterBus::TOUCH_MEMORY_SHARE` (0.5) of the bus's `maxmemory` (256 MB), read with `INFO memory` per batch, the touches go to MAIN's store instead. The bus evicts the keys closest to expiry first (`volatile-ttl`), so touches, which live longest, would otherwise push out the admission reservations (about 15 s) and the wake-ups (60 s) first.

  Nothing on MAIN reads the bus's touches back yet. `ClusterBus::lastReads()` is the reader for when something does, and the tests use it.
- **MAIN's store**, without the bus, with the bus past its share, or for a node that does not reap, since MAIN's 30 s rule reads that node's `hls_last_read`. It writes the value as the P0 upsert did, with these limits:
  - only the node's own connections;
  - never back to an earlier read;
  - never re-opening, creating or moving a connection between Redis sets;
  - one `UPDATE … CASE uuid` per 1000 on `lines_live`;
  - on Redis, `WATCH` per record, so a record an upsert or a close wrote meanwhile stays as that write left it.
- **`applied`** counts every well-formed touch taken. One for a viewer the store does not hold for the node changes nothing, on the bus as in the store. A store that cannot be read or written fails the batch with 503 `DB`, so the node sends its newer values again.

**Nothing on MAIN overlays the bus.** The plan's sink is "bus only". While a node reaps for itself, no MAIN reader decides by a touch-only change of `hls_last_read`:

| Reader | What it reads | With touches on the bus |
| --- | --- | --- |
| `UsersCronJob::hlsEnded` (the 30 s rule) | `hls_last_read` | Off for a reaping node, which sends its end as a P0 `conn.upsert` with `hls_end` 1 |
| `UsersCronJob::isRemoteWorkerRunning` (Redis mode) | `max(date_start, hls_last_read)`, as when the worker pid was set | A pid change is a P0 upsert carrying `hls_last_read`; a touch only ever made the time later, which is more lenient |
| `cron:users`' ENDED rule and 300 s rule for ended rows | the `hls_last_read` written with the end | Written by the P0 upsert of the end |
| `fanout_sync`'s 20 s connect grace | pid-0 TS rows | Never touched after they open |
| `ConnectionLimiter`, admission | `date_start`, `activity_id` | Not `hls_last_read` |
| `ConnectionDigest`, `ConnectionSnapshot` | uuid, owner, `hls_end`; a snapshot writes the registry's current record | Unaffected |
| Admin and reseller live connections, stats, the admin API | — | None shows `hls_last_read` |
| `UsersCronJob::hlsEnded`, once the node stops reaping for itself | `hls_last_read` | Covered by the leave grace below |

The gaps were in `HlsReaping` itself. In each, the store's `hls_last_read` for a reaping node's viewers can be minutes old, because their reads reached only the bus, and the 30 s rule would end live viewers:
- **A failed read.** When `cluster_nodes` could not be read, every node fell back to the 30 s rule. `HlsReaping::begin` now keeps the reapers of the last pass that read the table (`reaps` in `cluster_orphans.json`) and orphans nothing on a failed read. With no such pass, it falls back to the 30 s rule as before.
- **Leaving the reaper set.** A node stops reaping for itself when an admin turns CONNECTIONS off, its mode drops to 0, it is revoked or leaves `active`, or it says hello without `hls_reaper`. It then keeps counting as reaping for `HlsReaping::LEAVE_GRACE` (120 s, two reaper passes) from the first pass that finds it so (`leaving` in `cluster_orphans.json`), unless it is orphaned, whose rows are purged anyway. In that time the node hears of the change at its next heartbeat, and its viewers' next playlist requests put fresh reads into the store: written by the node's PHP once CONNECTIONS is off, or as an older agent's P0 upserts. Only then does the 30 s rule judge its rows.
- **An LB in MySQL mode** judges its own rows in `lines_live` by `localReaps()`, from `flows.json`. Its `cron:users` now calls `HlsReaping::beginLocal()` once per pass, which keeps the same grace once its agent stops reaping (`local` and `local_left` in its own `cluster_orphans.json`).

MAIN does not copy the bus's touches into the store on the way out: with touches sent at most once a minute (the contract, item 3), they can be older than the 30 s rule allows anyway.

**Telling the agent.** The hello reply and every heartbeat reply carry `p2_types`, a list of strings: the event types MAIN takes on P2, now `["conn.touch"]`. An older MAIN leaves the key out. It is in every heartbeat so that a MAIN rolled back to a release without P2 is noticed within one heartbeat.

**The agent's contract.** For the Go half, built in xc_vm_fanout #30:
1. **When.** Touches go on P2 only while all of these hold:
   - the latest hello or heartbeat reply's `p2_types` contains `"conn.touch"`;
   - CONNECTIONS is on;
   - the agent runs its HLS reaper and says `hls_reaper` at hello.

   Otherwise a change of `hls_last_read` alone goes, as today, as a P0 `conn.upsert` at most every 10 s (`TouchEvery`). A panel that reaps by the 30 s rule needs that.
2. **What moves.** Only a `Put` whose sole change is `hls_last_read` (`Touch`, or a `PUT` that `sameBut(…, "hls_last_read")` finds unchanged otherwise). It no longer emits the throttled P0 upsert: the registry keeps the latest value per uuid as a pending touch. Every other change still goes at once as a P0 `conn.upsert` of the whole record, its current `hls_last_read` included: a new record, any other key, `pid`, `hls_end` either way (the reaper's end, a re-open).
3. **Cadence.** A uuid's pending touch is sent at most once every 60 s, and only when its value differs from the last one MAIN got for that uuid on either lane. A third events loop, next to the `p0` and `p1` spool loops and sharing their client, runs every 10 s with one request in flight and never delays `p0`. It sends the pending touches whose 60 s have passed, at most 2000 events and 1 MiB per request, as the other lanes.
4. **Wire.** `POST events` with `{lane: "p2", events: [{type: "conn.touch", t: <unix ms>, d: {uuid: <string>, hls_last_read: <int>}}, …]}`. Leave out `first_useq`: it is not read on P2, and nothing is journalled or numbered.
   - `t` and `hls_last_read` must be JSON integers: a record seeded from `lines_live` may hold `hls_last_read` as a numeric string, which the agent converts (`intOf`). MAIN drops a string.
   - `t` orders one viewer's touches on the bus, where a lower `t` never replaces a higher one. Take it from a clock that does not step back while the agent runs: the wall time read at start plus the monotonic time since (Go's `start.Add(time.Since(start))`). After a backward step across a restart, the bus keeps a higher `t` until the new one passes it or the key expires (5 min). Nothing on MAIN decides by the bus's value, and the store fallback orders by `hls_last_read`, so this costs nothing today.
5. **Replies.**
   - A MAC'd 200 `{ok, useq: 0, applied, dropped, main_time_ms}`: the values sent are done, and a newer value for the same uuid stays pending. `dropped` > 0 is logged and nothing is resent. `applied` also counts a touch MAIN ignored because its store does not hold the uuid for the node.
   - A verified 400 `BAD_REQUEST`, which is what a MAIN without P2 answers: stop P2 until a reply lists `conn.touch` again, and handle the pending touches as in item 6.
   - A 503 (`DB`), a 429, a transport error or a reply that does not verify: keep the pending touches (newer values replace them) and retry with the lane's backoff.
   - A session denial, as on the `p0` and `p1` loops: 409 `NOT_ACTIVE` (the node is quarantined: `events` takes only an active node, though heartbeat still lists `p2_types`), or a token or re-key denial. Keep the pending touches, back off and retry; the heartbeat loop re-keys. `NODE_REVOKED`, `UNKNOWN_NODE` and `ENROL_EXPIRED` stop the agent, as on every op.
   - There is never a 409 `USEQ_GAP`.
6. **Losing it.** When a condition of item 1 stops holding, stop sending P2 at once. This MAIN does not rely on the agent to make the switch safe: it keeps a node that stops reaping for itself out of the 30 s rule for 120 s (`HlsReaping::LEAVE_GRACE`), and an LB in MySQL mode does the same for its own rows. An older MAIN has no such grace, but it leaves a node that says `hls_reaper` to its own reaper.
   - **CONNECTIONS off.** Send nothing: MAIN refuses every `conn.upsert` once the flow is off, and the node's PHP writes MAIN's store itself again.
   - **MAIN rolled back** (a reply without `conn.touch` in `p2_types`, or a 400 to a P2 batch), **or the reaper off**, with CONNECTIONS still on. Send at once, as a P0 `conn.upsert` of the uuid's current record, every open record whose current `hls_last_read` differs from the last value sent for it on P0. Track that apart from the last value sent on P2: a value that went on P2 reached the bus, not the store. Then go back to the 10 s P0 touches.
7. **Forgetting one.** Drop a uuid's pending touch when the record leaves the registry (`Delete`, a MAIN `conn.close`, `FanoutClosed`, a seed that resets). A P0 upsert of the uuid may drop it too, because the upsert carried the newer value.
8. **State.** Pending touches may live in memory only. A restart loses at most a minute of them, which nothing on MAIN decides by, and the reaper gives every viewer restored from `registry.snap` a full window anyway.
9. **Unchanged.** The digest, the snapshot, the reaper and `conn.divergence`.

**Differs from the plan.**
- **`conn.divergence` carries the rate, not the divergence.** MAIN holds the node's connections and its streams' bitrates, so the node needs no WAN read of `streams_servers` or of MAIN's store to report it, and the formula lives in one place.
- **`conn.divergence` keeps one report per writer in the spool.** The plan puts it on P1, which replays everything. A writer skips its report while the last one is unsent, and `fanout_sync` reports once a minute, so stale reports never take the lane or its cap from the logs.
- **`conn.touch` is bus-only only for reaping nodes.** Without the bus, past half of it, or for a node that does not reap, the touch goes into MAIN's store, because the 30 s rule reads it there.
- **The bus takes a touch only for a viewer MAIN's store holds for the node.** That costs one store read per batch, which the plan's "bus only" sink does not have. It keeps a node to its own viewers and bounds the bus by the reaping nodes' connections.
- **Leaving the reaper set has a grace.** The plan does not say what happens when a node stops reaping for itself; here it keeps counting as reaping for 120 s, so a store the bus left minutes behind is refreshed before the 30 s rule reads it.
- **P2 has no cursor.** The plan orders P2 "latest per key by timestamp"; here that is the event's `t` on the bus. The store fallback keeps the greatest `hls_last_read`, which orders a viewer's reads the same way.
- **`stream.progress` and `node.inventory` stay where they were:** in `stream.state` on P0, and on P1. They can move to P2 as types of their own, each listed in `p2_types`.

**Compatibility.**
- An older agent keeps sending touches as P0 `conn.upsert`, which is unchanged, and ignores `p2_types`. It ships the tagged divergence spool files like any other.
- An older MAIN lists no `p2_types`, and answers a P2 batch with 400 `BAD_REQUEST`.
- An older panel drops an unknown `conn.divergence` on P1, so the lane still advances. The node's PHP ships with MAIN in the same release anyway.

Tests:
- `ConnectionDivergenceTest`: the node's spool and its fallback; one report per writer while the last is unsent; both writers through their call sites (`UsersCronJob::writeDivergence`, `FanoutSyncCommand::writeDivergence`), which either spool or write MAIN's tables, never both, and `fanout_sync`'s minute; the shared formula; MAIN's own-rows write on `lines_live` and on Redis; and the refusals.
- `ConnectionTouchTest`: no cursor and no gap; the latest per viewer by `t` within a batch and across batches on the bus; the refusals; the store fallback on both stores (own rows, never back, never re-opening); bus-only for a reaping node, and only for the viewers its store holds; a mode-0 node and a bus past its share, both to the store; a store that cannot be read or reached failing the batch; and an older agent's P0 upsert.
- `ClusterApiTest`: the P2 op and `p2_types` in hello and heartbeat; 503 `DB` with the store down; 409 `NOT_ACTIVE` for a quarantined node.
- `HlsReapingTest`: the last reapers stand on a failed read; the leave grace for CONNECTIONS off, mode 0, a hello without `hls_reaper`, revoked and deleted nodes; none for an orphaned node; and the same on an LB (`beginLocal`).

### Acceptance tests that found gaps (Phases 2, 4 and 6)

Five tests the plan lists (§13) now run against the real code. Each found MAIN doing something other than what the plan says, and each was fixed together with its test. The large-snapshot gap, and the bounds on the hard-mode denial and the fleet silence guard, came from a review of the first four fixes.

**`https_required` over plain HTTP (Phase 2, `HttpsRequiredRecoveryTest`).**

- MAIN did not know which transport a request came over, so under `https_required` it served every op over plain HTTP. `Public/cluster/index.php` now passes nginx's `HTTPS` flag (`fastcgi_params`). Over plain HTTP, `ClusterApi` answers every op but `challenge` with a panel-signed `403 HTTPS_REQUIRED`, bound to the node and request nonce when the headers name them.
- `health` is answered before the settings are read, so it stays on plain HTTP. The plan names only `GET /challenge`; `health` is signed and carries nothing secret.
- A settings save that changed `cluster_transport` left `cluster_policy_ver` as it was. Nodes never saw the new policy in their heartbeats, and a signed policy recorded before the switch had the same version as the new one, so an agent would adopt it again. A save that changes `cluster_transport` or `cluster_main_host` now raises the version in its own `UPDATE`.
- A settings form could set `cluster_policy_ver` itself, back to 1. `SettingsService` now drops `cluster_policy_ver` and `cluster_legacy_ports`, which are MAIN's own state, from a POST.
- The drill runs through `SettingsService::edit()`, with the HTTPS self-probe faked (`ClusterSettings::useHttpsProbe()`). A node enrolled under `https_required` loses HTTPS, is refused over HTTP, and polls the signed challenge over HTTP. It does not adopt a replayed policy of a lower version, and it is back on plain HTTP once the admin picks `auto`, with no SSH.
- The agent must answer `HTTPS_REQUIRED` by fetching the challenge over HTTP. That is XC_VM_Fanout's part.

**Kills in the hard revocation mode (Phase 4, `HardModeKillChannelTest`).**

- With `lb_revocation_mode=hard` and no licence, the extension refuses the node's session, so the long-poll and every MAC'd reply stop. Kills were still signed, being restrictive, but stayed queued until they expired.
- A `LICENCE_INVALID` from the session check now carries `commands_sealed`: the node's pending restrictive commands (`CommandBus::restrictive()`), oldest first, in the long-poll's shape (`doc`, `sig`, `seq`), as a JSON list SEALed to the node's box key (`cluster_nodes.node_box_pub`, purpose `commands`, the node uuid as context) and base64-encoded. Only a node that takes commands (active, mode ≥ 1, COMMANDS on) gets them.
- The request is not authenticated: without a session there is no MAC to check, and the denial goes to whoever names the node, whose uuid travels in every request's headers. Hence the seal: in clear, the list would show anyone the viewers' connection uuids and the workers' pids, which a BOXed reply hides. With it, a sniffer or a forger learns only the list's size, as the plan's attacker view allows. The review of this increment also proposed checking a node signature first; heartbeats carry none (only token ops do), so the agent would have to sign every request in case MAIN turns out unlicensed, and the seal already keeps the content to the node.
- Nothing is marked delivered. The agent checks each command's signature, uuid, generation, `seq` above its high-water and expiry, as on the long-poll, but does not raise its long-poll high-water for them: it keeps their `cmd_id`s until they expire instead. Otherwise running a kill with `seq` N would skip every granting command queued below N (an RPC, a root command), which the long-poll, asking for `seq` above the high-water, would never hand out once the licence is back; each would sit queued until it expired, and its caller would get no result. Now the long-poll hands them out, and a kill it hands out again is acked with its result, not run twice.
- The class comes from `cluster_commands.class`, which `CommandBus` sets from the extension's own answer (`recordClass`). A granting command signed before the lapse is not handed out.
- `FakeClusterCrypto` now does what the extension does: it refuses a hard session without a licence, and it classes a `cmd` record by the extension's registry (`tests/Support/cluster_commands.json`), refusing a type, key or argument it does not list. `CommandBus::RESTRICTIVE` and its own list are informational, and `CommandBusRegistryTest` fails when either drifts from the registry.
- The tests also cover a forged request (no MAC, no node signature: it gets only the sealed list), another node's kill, and expired and acked kills (never carried), and the licence coming back (the RPC queued before the lapse comes on the long-poll).
- The agent must open and run the commands a denial carries, and keep their `cmd_id`s. That is XC_VM_Fanout's part.

**Apply once (Phase 6, `ConnectionIngestIdempotencyTest`).**

- `EventIngest` checked a batch against the cursor in the node row that the request read when it authenticated. A node whose `events` request times out sends the batch again, while MAIN may still be applying the first copy. Both copies passed the P0 gap check, so a closed viewer was re-opened and its close wrote a second activity row.
- MAIN now applies one batch per node and lane at a time, and reads the cursor under that lock. Only the P0 and P1 lanes take it: P2 keeps no cursor (tenth increment). The lock is a file, `TMP_PATH/cluster_ingest/<sid>_<lane>.lock`, waited for up to 10 s (then `503 DB`). It is not the node's database row: every heartbeat writes that row, and holding it for a whole batch would delay them.
- A cursor `UPDATE` that failed was ignored. When the database connection dropped mid-batch, taking the transaction with it, the node was still told the batch was applied, and moved on past events MAIN never kept. Such a batch now fails with `503 DB`, and the node sends it again.
- The same event under a new number already applied once: an upsert updates in place, and a remove or close of a viewer already gone is accepted and changes nothing.
- **Known gap:** a close's activity row goes to a file, outside the transaction. A batch that fails after one of its closes was applied keeps that activity row, and the resend writes it again, in either store. In MySQL mode the connection's removal rolls back with the batch; Redis has no transaction, so there the batch's writes stay, and the resend opens and closes the viewer again. `ConnectionIngestIdempotencyTest` pins both, with the second activity row.
- The test pins the lock too: a query hook checks that the lane's lock is held when the cursor is read and when it is moved. The lock directory has a test seam (`EventIngest::useLockDir()`); the test bootstrap points it at a directory of the test process's own under `tests/.tmp`, so no suite run shares lock files with another.

**MAIN's downtime and the orphan purge (Phase 6, `MainOutageNoPurgeTest`).**

- `HlsReaping` measured a node's silence from `last_seen_at` alone, and kept its watch across reaper gaps of up to 3 minutes. A watch that began while MAIN's nginx was stopping survived a short restart. The first pass after it then purged every CONNECTIONS node's viewers before the nodes could reconnect.
- Silence now counts from `max(last_seen_at, cluster_ready_at)`, as `NodeHealth` counts it (`cluster_meta.ready_at`, or 0 when it cannot be read).
- While the fleet silence guard is up (`ClusterHealth`), the nodes it holds are not watched, and their watch starts over when it clears, so the time MAIN suspected itself never counts. The plan says the guard suspends purges; it does not say whether the watch restarts.
- The guard does not hold a node that was offline before it came up. The liveness loop keeps such a node offline under the guard, and its silence began before MAIN suspected itself, so its watch goes on and it is purged a TTL after the watch began.
- The guard holds the watch for at most 4 × `cluster_orphan_conn_ttl_sec` (8 minutes at the default), counted from the reaper pass that first saw it (`guard` in `cluster_orphans.json`, beside the tenth increment's `reaps` and `leaving`). The plan triggers the guard when most nodes "go silent within 10 s"; the liveness loop raises it whenever more than half of the TELEMETRY nodes (at least two) are not ok, with no time limit. A lasting loss of most of the fleet, such as both LBs of two, never clears it. Without the bound, those nodes' viewers would count against their lines until an admin acted, and on a `max_connections=1` line they could not reconnect elsewhere. With it, such a node is purged one TTL after the hold ends. A purge during a longer cut of MAIN's own network is undone when the nodes come back: their digests disagree, and their snapshots restore the rows. The guard's hold on offline marking (routing) is unchanged.
- `MainOutageNoPurgeTest` also covers a node never heard (its silence counts from `cluster_ready_at`), a five-minute outage, a node offline before the guard, and a lasting loss of two nodes out of three.

**Large snapshots (Phase 6, `LargeSnapshotChunkingTest`).**

- Twenty chunks of 1000 records, against `lines_live` as the install creates it, change the store only with the last chunk. An oversized, malformed, out-of-order or unreadable staged chunk leaves the store as it was.
- The staging was atomic, but the apply was not: 20 000 separate autocommitted writes. The staged chunks were deleted before it, and a write that failed only counted as dropped, so a connection lost part-way left a half-applied store and was still answered `ok`. Readers could also see the store half-changed while it ran.
- In MySQL mode the apply is now one transaction. A commit that fails (the connection was lost) rolls it back and answers `503 DB`, and the staged chunks are kept until an apply succeeds, so the last chunk sent again applies the whole snapshot.
- The removal pass removed every connection whose upsert had not succeeded, so a write MAIN failed to make deleted a viewer the node still had. It now spares every uuid the snapshot names.
- The review proposed failing the snapshot on any write that fails. A single record MAIN refuses or cannot write is still counted as dropped instead: a record the database rejects would otherwise fail every snapshot the node sends, and the node would never get back in step. The next heartbeat's digest check (`ConnectionDigest`) finds such a difference and asks for another snapshot.
- Redis has no transaction across 20 000 records. A Redis error part-way answers `503 DB` and leaves the part already applied; applying the whole again converges.

### The settings section (Phase 7, fourth increment)

**The allowlist.** `src/Core/Cluster/lb_settings_keys.php` lists the settings a node's replica may carry. It is generated by `tools/ci/lb-settings-keys.sh --write`, and `make gates` fails when it is stale. The script builds the LB file manifest the way `verify-lb-archive.sh` does, then scans every shipped PHP file for settings reads:

- `SettingsManager::get('key')`, and its `getBool`/`getInt`/`getString` forms;
- any `['key']` index whose key is a settings column. This is over-inclusive on purpose: a server column that shares a settings name costs one extra key, never a missing one.
- a dynamic read (`SettingsManager::get($x)`, `getAll()[$x]`, `$rSettings[$x]`). It must carry an `lb-settings: key, …` note on its line or one of the two before it, or the gate fails.

The settings columns come from the install schema and the migrations. Secrets are withheld whatever reads them, and listed as such: `api_pass`, `license`, `live_streaming_pass`, `redis_password`, the third-party API keys and the reCAPTCHA secret. The node needs `live_streaming_pass`, which will come in the sealed `secrets` section. `ReplicaBuilderSecretsTest` fails on an allowlisted key that looks secret and has not been vetted.

**Serving.** `ReplicaBuilder::whole()` sends a section whole, as a `rep` record without a `seq`, whenever its ETag differs from the one the node names in `have`. The `settings` section is the raw `settings` row, allowlisted keys only, with each value as a string. It goes only to an agent that names it in `have`, so an older agent is not sent a section it cannot store.

**The node.** The agent stores `replica/settings.rep` and writes `replica/settings.json` (`{etag, data}`) from the verified record, then runs `cluster:apply`.

**Applying.** `ReplicaApply` decodes the section as the panel does, through `SettingsRepository::decode()`, now shared. It reports the keys whose value differs from the settings cache. It stays in shadow even with CONFIG on: the section withholds secrets that the node still reads, so it becomes authoritative together with the `secrets` section (sixth Phase 7 increment).

### The whole sections servers, node, crontab and cluster (Phase 7, fifth increment)

**What they hold.** `Core/Cluster/ReplicaSections` names the fields. MAIN builds the sections from it (`ReplicaBuilder`) and the node applies them from it (`ReplicaApply`); it lives in Core because it ships to nodes. Each section is a `rep` record, signed, sealed and served exactly as the `settings` section.

| Section | `data` |
| --- | --- |
| `servers` | `{servers: [row], nodes: [{sid, gen, state, ed_pub}]}`. One row per `servers` row, ordered by id, with the routing and relay fields `SERVER_FIELDS`. `nodes` is every `cluster_nodes` row by `server_id`: its generation, its state and its Ed25519 key (`node_sign_pub`, base64) |
| `node` | The node's own row: `NODE_FIELDS` (ports, `limit_requests`/`limit_burst`, `total_services`, `use_disk`, `enable_https`, `domain_name`, `network_interface`, `governor`, `sysctl`, `time_offset`) and the settings `cloudflare` and `mag_legacy_redirect`. `[]` when the row is gone |
| `crontab` | `{jobs: [{filename, time}]}`: the enabled rows whose role fits the node's mode, in the table's order. A row that is not a job as the node takes it (below) is left out, and audited once as `replica.crontab_skipped` |
| `cluster` | `{main_urls, urls_ver, policy_ver, transport, panel_sign_pub, panel_box_pub, min_proto, off_air}`. The policy is the one `hello` sends (`ClusterPolicy::current`). The keys are base64. `off_air` maps `connected`, `not_on_air`, `banned`, `expired` and `expiring` to the file name of the admin's video, or null for the node's default |

Integer columns travel as JSON integers and text as strings, whichever driver read them; a missing column or NULL is null. Every level of a section has its keys sorted (the canonical form of the ETag), so a row's fields arrive in name order.

**Nothing else.** Each `servers` column is in exactly one of `SERVER_FIELDS`, `NODE_FIELDS` and `SERVER_LOCAL`, and `ReplicaSectionsTest` fails on a new column that is in none. `SERVER_LOCAL` is what no replica carries: the liveness and telemetry columns (`status`, `last_check_ago`, `watchdog_data`, `connections`, `users`, `requests_per_second`, `ping`, the hardware and device reports), `php_pids`, `certbot_*`, `uuid` and `ssh_hostkey_sha1`. A node builds the `api_url*` itself. No section carries a setting the allowlist withholds.

**How it differs from the plan.**

- `servers` also carries `whitelist_ips`, which the node's `allowed_ips` cache is built from, and `xc_vm_version`, which the node's update reads.
- `node` also carries `time_offset`: the node checks token expiry against it. It is MAIN's measure of the node's clock, set from the node's inventory in whole seconds, so it rarely moves the ETag.
- `urls_ver` equals `policy_ver`: one counter versions both the URLs and the policy.
- A `legacy` crontab row fits modes 0 and 1, as migration 033 defines it (nodes that still have MAIN's database). `main` rows never leave MAIN. The plan's "`users` only while CONNECTIONS is off" stays `UsersCronJob`'s own check.
- Migration 042 makes `cleanup`'s role `all`, as the plan's cron table has it (its Phase 0 text and migration 033 made it `main`). `cron:cleanup` prunes each node's own stream files, TV archive and created channels, and only its table rotation is MAIN's. While nodes copied the crontab whole the role changed nothing; with the crontab section, a `main` row would have stopped that pruning on every node. `ReplicaSectionsTest` checks the install's own crontab.
- Migration 043 gives the rest of the plan's `main` crons that role: `epg`, `series`, `backups`, `cache_engine` and `providers` (the LB build strips their classes), `stats` (it exits on an LB), `proxy` (it fetches the archive MAIN's installer ships) and `watch` and `plex` (module commands; modules are MAIN-only). An install had them all as `all`, so the crontab section would have sent every node jobs that fail as unknown commands. `maxmind` stays `all`, unlike the plan's table: each node keeps its own GeoIP databases current. `ReplicaSectionsTest` fails on a job whose class the Makefile strips from the LB build.
- The whole section is panel-signed, so the node list inside it is signed.
- A `rep` record is signed on each request, not once per content hash: a signature costs microseconds, and the plan's cache holds the data.
- `config.changed` also goes out when a node completes its enrolment or is quarantined, so the others learn a new active key, or stop trusting a cloned one, at once.
- `config.changed` goes only to an agent that says `config_changed` at hello (below). Today's agent would hand it to `cluster:exec`, which an LB's older PHP fails as an unknown type.

**Change detection on MAIN.** The ETag is the SHA-256 of the canonical data. `ReplicaEtagCache` keeps a section and its ETag for 10 s on MAIN's clock, one file per key in `TMP_PATH/cluster_replica/`: `servers`, `settings`, `cluster`, `node.<sid>`, and `crontab.legacy` or `crontab.api`. Each entry records the cache's generation (`.gen`) from before the database was read. A bump replaces the generation with a random token and deletes the entries, so a request that read the old rows and writes them after the bump is never served. A random token, not a counter: two bumps at once never write the same one. Without that, a node pushed by `config.changed` could get the old list as `unchanged` and wait for its next poll.

- `SettingsChangedEvent`, `ServerSavedEvent` and `CrontabChangedEvent` drop the cache, so the next `config` call reads the database. `SettingsService` now dispatches `SettingsChangedEvent` on the settings, backup and cache saves. `ServerService` and `ServerRepository` dispatch `ServerSavedEvent` on a server or proxy save, an install, a reorder and a delete. The cache-engine schedule save dispatches `CrontabChangedEvent`.
- The listener is Core's own, registered by `ContainerPopulateStage`, because only modules had a subscriber registry.
- Other writers of these tables dispatch nothing. Their change is seen within the 10 s, then at the node's next minute's poll. Among them: the admin's node actions (ports, services, governor, sysctl), which the node carries out and writes back to its own row itself, and the enrolment and liveness writers of `cluster_nodes`.

**`config.changed`.** `ReplicaBuilder::nodesChanged` runs when a node is revoked, re-enrolled (`startEnrolment` over an existing row), completes its enrolment, or is quarantined on evidence of a clone (`hello` or `token_rekey` from another instance). It drops the cache, then queues `config.changed` for every other `active` node with COMMANDS on whose agent said `config_changed` at hello (`cluster_nodes.features`).

- The command is restrictive, so it signs without a licence. Its `dedupe_key` is `config.changed`, so a newer one supersedes one not yet acked.
- A failure to queue never fails the change. Each node is queued on its own, so one that cannot be told (its row gone meanwhile) is skipped and the others still are; it sees the change at its next poll.
- Nodes without COMMANDS or without the feature fetch it at their next poll too.

**The node.** `cluster:apply` reads `replica/<name>.json` and reports each section in `replica/apply.json`:

- **`servers` + `node`.**
  - **Shadow:** `missing`, `extra` (server ids) and `differ` (`<id>.<field>`, at most 100), compared with the servers cache `cron:cache` built from MAIN's database.
  - **CONFIG on:** the `servers` cache, keyed by id, in `ServerRepository::getAll`'s shape. It is built through `ServerRepository::decorate` (split out of `getAll`), with the node section over the node's own row. `api_url*` are built with the node's own `live_streaming_pass`. Every column a section does not carry is null, and `server_online` is `enabled` (MAIN judges liveness; the node tries every enabled server).
  - Both sections must be MAIN's for this node: the node section's `id` is `SERVER_ID`, the list holds that row, the ids are unique and the node list is well-formed. Otherwise the report says `incomplete` (one of the two is missing) or `refused`, and nothing is written.
- **`crontab`.**
  - **Shadow:** the jobs MAIN's table has that the section leaves out (`missing`, normally the `main` rows) and the reverse (`extra`).
  - **CONFIG on:** the `cron_jobs` cache.
  - A job must be a `cron:` name (`[a-z0-9_]{1,64}`) and five schedule fields of `[0-9*/,-]`, with nothing after them, not even a newline; otherwise the whole section is `refused`: the node writes the jobs into its crontab.
- **`cluster`.** Compared with the agent's `agent.json` (`main_urls`, `panel_sign_pub`, `policy_ver`) and reported as `shadow` whatever the flow. No PHP on the node reads it; the agent does.

**Who owns a cache.** With CONFIG on, the replica owns the servers cache or the crontab's jobs (`ReplicaApply::owns`) only once the agent has stored the sections (`servers.json` and `node.json`, or `crontab.json`) and an authoritative apply has built the cache from them. The apply records that in the `replica_owned` cache, beside the caches in `tmp/cache/`, so it goes with them at a reboot. Until then the readers keep MAIN's database and `cron:cache` keeps refreshing it. A database copy is therefore never taken for the replica's, frozen with the liveness it had.

- A section that is refused or incomplete hands its cache back: the readers take MAIN's database again, and `cron:cache` refreshes the servers cache from it.
- With CONFIG off, `cluster:apply` (in shadow) and `cron:cache` drop the record and the `cron_jobs` cache (`ReplicaApply::disown`), so turning CONFIG back on waits for an apply instead of reusing old jobs.
- `cron:cache` applies the replica from disk itself every minute while CONFIG is on (`ReplicaApply::run(true)`), so the caches follow the copy on disk within a minute of a flow change even when the agent does not run `cluster:apply` then.

Once owned:

- `ServerRepository::getAll()` returns the servers cache however old it is, even when forced, so every reader (including `cron:root_signals`' ports, limits and services) reads the replica. Should the cache be gone, it rebuilds it from the replica on disk. It never writes the database's rows over a cache the replica owns.
- `cron:cache` does not write the servers cache from the database.
- `LegacyInitializer::generateCron` and `cron:root_signals`' crontab check take the crontab from `ReplicaApply::crontabText`: the replica's jobs, or MAIN's table while the replica does not own them. Null (the owned jobs are gone, or there is no database) leaves the crontab as it is; an empty string is a crontab with no job.
- `cron:certbot` reads its own certificate record from its row in MAIN's database, not from the servers cache, which carries no `certbot_ssl` (`SERVER_LOCAL`). In mode 2 it reads the copy it keeps of what it reported (fourteenth Phase 7 increment).
- `src/service` runs `cluster:apply --from-disk` as xc_vm before `daemons.sh` when `config/cluster/flows.json` has mode 1 or 2 and the CONFIG bit, with a 15 s `timeout`. A shadow node gains nothing from it. The flag changed nothing then: `cluster:apply` always read the disk. Since the seventh Phase 7 increment it verifies the stored records itself.
- `NodeFlows` ignores the agent's file on MAIN, and asks `NodeRole`, which reads the servers. On a node whose replica owns them that asked `NodeFlows` again, without end. The inner call now gets what the file says (`ReplicaApplyTest`).

**Known limits.**

- A node with CONFIG on and TELEMETRY off loses what its legacy telemetry path read from its own row: `watchdog_data`'s CPU history, and `users`/`connections` in Redis mode. The rollout turns TELEMETRY (Phase 3) on before CONFIG.
- The settings, including `cloudflare` and `mag_legacy_redirect`, stay MAIN's until the `secrets` section exists (sixth Phase 7 increment); the `node` section's copies are not read yet.
- No reader uses the node list before Phase 8's ticket checks.
- Until the seventh Phase 7 increment, `cluster:apply` and `cron:cache` booted through the CLI profile, which connects to MAIN's database (`ReplicaStage` did not exist). With MAIN's database unreachable at boot, `cluster:apply` exited before it applied anything, bounded by `service`'s 15 s `timeout`, and a node rebooted while MAIN was unreachable rebuilt its caches only once MAIN's database answered again. Since then `cluster:apply` boots from the replica in every mode, and `cron:cache` does on a mode 2 node once an apply built the caches.

**Tests.**

- `ReplicaSectionsTest`: content per section, the classification, no liveness or secret, rows a node would refuse left out of the crontab section, and no job the LB build strips. Also the ETag's stability, the cache, bumps from each event and from a delete or reorder, and a bump in the middle of a read (through `QueryLogDb`'s before-statement hook). And `config.changed`: who is told on a revoke and a re-enrolment, never the node itself, and a node that cannot be told is skipped.
- `ReplicaApplyTest`: shadow and authoritative per section, and `getAll`'s shape (a disabled server offline, the node's own row online and from the node section). Also missing, foreign, malformed and duplicate sections, and the crontab patterns. Ownership: only after an apply, handed back on a refusal and after CONFIG was off, an owned cache that is gone rebuilt from disk, and never overwritten by a database read an apply overtook. And `ReplicaApply::crontabText`, which both crontab readers write; the readers themselves only pass its null on.
- `ClusterApiTest`: sections served by `have`, never to an agent that does not name them. Also `config.changed` on a completed enrolment and on both quarantines, and a sign refusal other than LICENCE that still denies the call (`FakeClusterCrypto::$rRefuseSign`).
- `BootStageTest` (the three events have Core's listener after `ContainerPopulateStage`), `ClusterExecCommandTest` (`config.changed` acked as deferred), and `ClusterSchemaTest` (migrations 042 and 043).

The suite runs with the ETag cache off (`tests/bootstrap.php`): some tests define `TMP_PATH` as a shared path and fix the clock at one instant.

**The agent's contract (XC_VM_Fanout, built in xc_vm_fanout #31).**

- **Request.** `config`'s `have` may name `settings`, `servers`, `node`, `crontab` and `cluster`, each with the ETag the agent holds (64 lowercase hex, or `""` for none). MAIN answers only the whole sections named. It leaves out a name it does not serve, and refuses a malformed ETag with `400 BAD_REQUEST`.
- **Reply.** Per named section, under the same name: `{"unchanged": true}`, or `{"etag": "<64 hex>", "sealed": "<base64 std>"}`. A missing field means "not served": keep what is held. That includes a changed whole section while MAIN has no licence: a `rep` record grants, so MAIN leaves it out instead of refusing the whole call, and the blocklist's bans in the same reply still arrive. Before this, one changed `settings` section made every `config` call a `LICENCE_INVALID` until the licence came back.
- **Record.** `sealed` is base64 of XCVM-SEAL-v1 to the node's box key (purpose `replica`, context the node uuid). It opens to `u32(len) ‖ payload ‖ sig`, where `payload` is the JSON `{v: 1, section, node, gen, etag, iat, data}` (no `seq`) and `sig` the panel's signature over it under tag `rep`. Store it only if the signature verifies against the pinned panel key and `section`, `node` and `etag` match the name, this node and the announced ETag; checking `gen` against the token's generation is recommended.
- **Files**, written atomically under `config/cluster/replica/`: `<name>.rep` (the sealed record as received) and `<name>.json` (`{"etag": "<etag>", "data": <data exactly as signed>}`). `state.json` keeps the ETag held per section (for example `whole_etags: {name: etag}`, beside today's `settings_etag`).
- **Apply.** After storing any section, run `console.php cluster:apply` (debounced 1 s, as today). Also run it once after the first sync when the agent starts, since `tmp/cache/` does not survive a reboot. Also run it when the CONFIG bit (32) of the `flows` it writes to `flows.json` changes, either way: that is when the caches change hands. `cron:cache` applies every minute while CONFIG is on, so an agent that does not do this only delays the switch by up to a minute.
- **Crontab jobs.** In every job MAIN sends, `filename` is 1 to 64 characters of `[a-z0-9_]`, and `time` is five fields of `[0-9*/,-]+` separated by single spaces, with nothing before or after (no newline). MAIN leaves any other row out. The agent stores the section as signed and need not check the jobs: PHP refuses a section with any other job.
- **`config.changed`.** MAIN sends it only to an agent that lists `"config_changed"` in hello's `features` (today's agent sends `["hls_reaper"]`, so it gets none). It is a command of type `config.changed` (class R), `args: {"sections": ["servers"]}`, `dedupe_key: "config.changed"`, `exp = iat + 600`. It goes out when another node is revoked, re-enrolled, completes its enrolment or is quarantined. Verify it like any command. Then start a replica sync at once, coalesced with one already running, and ack `ok` with `{"result": true}` without waiting for the sync. Should it reach `cluster:exec` anyway, this PHP acks `{"deferred": true}` with exit 0, and the next minute's poll fetches the change.
- **Use.** The `cluster` section is panel-signed like a challenge's policy. An agent may adopt its `main_urls` and `transport` when its `policy_ver` is above the one it holds; that is how a node rebooted without MAIN keeps a current URL list. The `servers.nodes` list is for Phase 8's relay-ticket checks.

### The secrets section, and the settings made authoritative (Phase 7, sixth increment)

**What it holds.** `secrets` is the one section that carries secrets, and only two (`ReplicaSections::SECRET_KEYS`). Each is an entry `{kid, current, previous, previous_valid_until}`:

| Key | `current` | `previous`, `previous_valid_until` |
| --- | --- | --- |
| `live_streaming_pass` | The `settings` row's value | Both null: nothing rotates it yet (plan section 10, step 4) |
| `openssl_extra` | The OPENSSL_EXTRA MAIN's php-fpm mints with: its `config/openssl_extra`, or the built-in value | MAIN's `config/openssl_extra.prev` while it is open on MAIN's clock (`OpensslExtra::previousEntry`), else both null |

The `kid` names the value without revealing it: the first 16 hex digits of HMAC-SHA256 keyed by the value over `xc_vm <key> fingerprint v1` (`ReplicaSections::kid`). For OPENSSL_EXTRA that is the fingerprint every node already publishes in `server_hardware` (`OpensslExtra::fingerprint`).

**Serving.** Like the other whole sections: a `rep` record, signed and sealed to the node, sent whole whenever the ETag the agent names in `have` differs. Three things differ:

- It goes only to an `active` node in mode 1 or 2 (`ReplicaBuilder::serves`). For a legacy node (mode 0), which reads MAIN's database, it is left out of the reply, like a name MAIN does not serve.
- It is never cached on MAIN. `ReplicaEtagCache` keeps plain JSON in `TMP_PATH`, so the section is read from the database and the constant for each request that names it, and the cache refuses the key `secrets` outright. `secrets` is not in `ReplicaSections::WHOLE`; the `config` op adds it to that list with the mode check.
- Nothing on MAIN logs it: the op audits nothing of a `config` reply, and a failure answers `503 DB` without a message.

It grants like every whole section: without a licence the extension refuses to sign a changed one, which is left out of the reply while the blocklist's bans still arrive. An unchanged one needs no signature and is answered `unchanged`.

**Never from a failed read.** `Database::query` answers a failed statement with `false`, and `get_row()` then answers `false` or the previous statement's row. A section built from that would be signed as MAIN's word. Before this, a failed settings read gave an empty `settings` section, which a CONFIG node took as its whole settings with every flag unset (`secure_stream_tokens`, `verify_host`, the `disable_*` and flood settings). It also gave a `secrets` entry with an empty `current`, and a crontab with no job. Now every read of every section throws when it fails: the blocklist (`BlocklistDelta`), `settings`, `secrets`, `servers`, `node` and `crontab`. So does a missing settings row (`settings`, `secrets`, `node`) and an unset secret: an empty `live_streaming_pass`, which `cron:root_signals` sets on MAIN within the minute, or an empty OPENSSL_EXTRA. The `config` op then answers `503 DB`, nothing is kept in the ETag cache, and the node keeps what it holds and asks again at its next poll. `ReplicaBuilderSecretsTest` fails each read as `Database::query` does, with the previous row still in `get_row()`.

**Nothing else carries a secret.** `ReplicaBuilderSecretsTest` checks that the section's keys are exactly those two, each with exactly those four fields, as the node takes them. It checks that no other section (the blocklist, `settings`, `servers`, `node`, `crontab`, `cluster`) carries or names OPENSSL_EXTRA or any of the nine secrets the allowlist withholds. It also checks that no file of the ETag cache holds one, and that a changed value is served at once, not 10 s later.

**Applying the secrets.** `cluster:apply` reads `replica/secrets.json`:

- **Shadow** (CONFIG off): nothing is written. The report is `{mode: shadow, differ}`, where `differ` names each secret whose `current` differs from what the node uses: the settings cache's `live_streaming_pass`, and OPENSSL_EXTRA as the node reads it (`OpensslExtra::inUse`: its `config/openssl_extra`, else the built-in value).
- **CONFIG on:** OPENSSL_EXTRA goes where the node reads it (`OpensslExtra::adopt`), `config/openssl_extra`, and `live_streaming_pass` into the settings cache (below). The previous value kept in `config/openssl_extra.prev`, which `Encryption::readToken` still opens tokens with, is MAIN's `previous` until its `previous_valid_until` while that is open. Otherwise, when the value changes, it is the value replaced here, for `PREVIOUS_WINDOW` (600 s), as `server:sync-openssl-extra`'s root signal keeps it. Both files are 0600 and owned like their directory, and `.prev` is written first, so the value is never replaced while the one it replaces cannot be kept. A `previous` that is already closed on the node's clock (clock skew, or an old `secrets.json` applied from disk) is not kept: the value replaced here is, for the usual window. It is open through its last second, as `OpensslExtra::previous` reads it. Once the node holds both nothing is written, so the minute's re-apply never extends a window. The report is `{mode: applied, differ}`, or `{mode: failed, differ}` when a write fails. `cluster:apply` then exits 3 after printing the report (below); `cron:cache`'s apply only writes it to `replica/apply.json`.
- **Refused:** a section with a key missing, an empty `current` or `kid`, or a `previous` without its `previous_valid_until` (or the reverse). Nothing is written, and the settings stay MAIN's. A key the node does not know is ignored, so a later MAIN can add one (the plan's ticket kids).
- **No secret in the report.** It never holds a value, a kid or a hash of one, not even the section's ETag: `cluster:apply` prints it, and the agent logs the output of a failed run, which a `failed` part makes one (exit 3). An exception while writing OPENSSL_EXTRA is caught, so no stack trace prints the value among its arguments.

**The settings become authoritative.** With CONFIG on and both sections usable, the settings cache is the `settings` section's raw row with the secrets' `live_streaming_pass`, decoded by `SettingsRepository::decode` as the settings loader decodes MAIN's row (report `applied`). Otherwise the report keeps the shadow diff with the mode `incomplete` (no usable secrets section), or says `refused`, and the cache stays MAIN's database's. `refused` is a section that is not a whole raw row: without a string `server_name` (so an empty section or a list), or with a value that is not a string or null. An empty or partial section would otherwise become the node's whole settings, every flag it lacks unset.

- Ownership works as for the servers cache: the apply records `settings` in `replica_owned`, and `ReplicaApply::owns('settings')` needs CONFIG, `settings.json`, `secrets.json` and that record. A section that goes bad after an apply (`refused`, `incomplete`) drops the record at once, and a missing `secrets.json` ends ownership even before the next apply: the settings are MAIN's database's again.
- Once owned, `SettingsRepository::getAll()` returns the cache however old, even when forced, and rebuilds it from the replica on disk when it is gone. It never writes MAIN's row over a cache the replica owns, and asks again after its read, since an apply may have landed meanwhile. `cron:cache` now just calls `getAll(true)`. `LegacyInitializer`, the forced reads of the watchdog and on-demand daemons, and the streaming entry points, which read the cache file directly, all get the replica's settings.
- The servers cache built by the same apply takes its URLs (`api_url*`, with `live_streaming_pass`) from those settings, not from the ones the process loaded.
- With CONFIG off, `disown()` drops the record. The cache is MAIN's database's again within 20 s (`getAll`'s age) or at the next `cron:cache`.
- **Boot order.** `LegacyInitializer` loads the settings before anything else, and `owns()` asks `NodeFlows`, which on a node with an agent reads the servers to rule out MAIN. So `getAll` asks `ReplicaApply::built('settings')` first: on MAIN, a legacy node or a node with CONFIG off, the record is absent and nothing reaches `NodeFlows` there. A servers read that still comes first, on a node whose replica owns the settings but not the servers, builds its URLs from `SettingsRepository::loaded()` (the settings cache while none are loaded) instead of empty settings. `ReplicaApply::servers` does the same.

**How it differs from the plan.**

- No ticket kids: relay and file tickets come with Phase 8. A node ignores keys it does not know, so they can be added.
- `live_streaming_pass` always has `previous` null until the stream-secret rotation (Phase 9) keeps one, and the node would not use it yet: `Encryption`'s multi-key support is Phase 8.
- The kid is a fingerprint of the value, not a counter. It needs no storage, and it changes with every change of the value, the admin's Settings page included.
- The section is read per request instead of R1's 10 s cache: at one `config` call a minute per node, that is one query each.
- The ETag is the SHA-256 of the canonical data, as for every section, so it is a hash of the secrets. It travels only inside the boxed session, the agent keeps it beside the plaintext, MAIN never stores it and the report never shows it.

**Known limits.**

- The allowlist withholds eight more secrets that the LB build reads: `api_pass`, `dropbox_token`, `license`, `maxmind_license_key`, `platform_api_key`, `recaptcha_v2_secret_key`, `redis_password` and `tmdb_api_key`. Once the replica owns the settings cache they are not in it. Only `maxmind_license_key` is used on a node, by `cron:maxmind` (role `all`): a CONFIG node keeps its GeoIP databases current from the free GeoLite2 release instead of MaxMind's paid editions. The other readers are MAIN's features that the LB build ships, and a node's Redis connection takes its password from the extension, not from the settings.
- A new `live_streaming_pass` reaches a CONFIG node at its next poll (within 60 s; no `config.changed`), as a legacy node's settings cache follows MAIN's database within `cron:cache`'s minute. Until then, tokens MAIN mints with the new value do not open there.
- A node has one previous OPENSSL_EXTRA. When MAIN sends an open `previous` to a node that ran yet another value (a legacy LB on the built-in value), MAIN's wins, and tokens the node minted itself just before stop opening.
- Until the seventh Phase 7 increment, `cluster:apply` booted through the CLI profile, which needs MAIN's database (`ReplicaStage` did not exist).
- On the node the section is plaintext in `secrets.json`, as the settings cache and `config/openssl_extra` already hold those values.

**Tests.**

- `ReplicaBuilderSecretsTest`: above, and no section (the blocklist included) built from a failed read, a missing settings row or an unset secret. Its previous OPENSSL_EXTRA is read from the test's own directory, never the deploy root's `config/`.
- `ClusterApiTest`: served only to an agent that names it and only in mode 1 or 2, `unchanged` by ETag, a malformed ETag refused, a changed section left out without a licence, and `503 DB` without a settings row or with an unset stream secret.
- `ReplicaApplyTest`: the shadow report names differences only, never a value, kid or ETag. It also covers the authoritative settings cache; `getAll` owned, forced and rebuilt; refused sections and an unknown key; OPENSSL_EXTRA's file, modes, idempotence and MAIN's previous value; a failed OPENSSL_EXTRA write reported `failed`, with `.prev` first and `cluster:apply` exiting 3; CONFIG off handing the settings back; a section going bad after an apply handing the settings back, an empty or partial settings section refused; a settings read racing an apply never writing MAIN's row over the replica's; the servers' URLs from the applied settings; loading the settings at boot without one query to MAIN's database; without an apply record, loading the settings reads only the settings; and a servers read, or a servers cache rebuilt, before the settings are loaded.
- `OpensslExtraTest`: `previousEntry` and `adopt`, a `previous` from MAIN already closed not kept, and one open through its last second.

**The agent's contract (XC_VM_Fanout, built in xc_vm_fanout #31).** The same generic whole-section storage as the fifth increment, with these rules for `secrets`:

- **Request.** `config`'s `have` may name `secrets` with the ETag the agent holds (64 lowercase hex, or `""`). Name it only once the agent stores it as below. MAIN answers it only to an `active` node in mode 1 or 2. For a node in mode 0 it is left out of the reply ("not served": keep what is held).
- **Reply and record.** As for every whole section: `{"unchanged": true}`, or `{"etag": "<64 hex>", "sealed": "<base64 std>"}`, whose record opens to a `rep` payload `{v: 1, section: "secrets", node, gen, etag, iat, data}`. Check it as the others: the panel signature under tag `rep`, then `section`, `node` and the announced `etag`, and `gen` if possible. A changed section while MAIN has no licence is left out of the reply.
- **Data.** `{"live_streaming_pass": E, "openssl_extra": E}`, keys sorted, where E is `{"current": "<non-empty string>", "kid": "<16 lowercase hex>", "previous": "<non-empty string>" | null, "previous_valid_until": <unix seconds> | null}`; `previous` and `previous_valid_until` are both null or both set. A later MAIN may add keys. The agent stores the data exactly as signed and need not parse it: PHP checks it.
- **Files.** `replica/secrets.rep` (the sealed record) and `replica/secrets.json` (`{"etag": "<etag>", "data": <data as signed>}`), each written atomically with mode 0600: only xc_vm, which runs `cluster:apply`, reads them. `state.json` keeps its ETag under `whole_etags.secrets`.
- **Never logged.** The agent never logs the section: not its data, record, sealed bytes, ETag or a diff of them. An error names the section only. It may keep logging a failed `cluster:apply`'s output: PHP never prints a secret there.
- **Apply.** Run `console.php cluster:apply` after storing it, as for the other sections. The settings become the node's only with both `settings.json` and `secrets.json` stored, so an agent that names one names both.
- **Mode 0.** When MAIN leaves the section out, keep the files: with CONFIG off PHP only compares them.
- **`503 DB`.** MAIN answers the whole `config` call with a signed `503 DB` denial when it cannot read a section it was asked for: a failed read, no settings row, or an unset secret. It never sends an empty section in their place. Keep every file and ETag held, apply nothing, and ask again at the next poll, as for any `503` (today's agent does).
- **Exit codes.** `cluster:apply` exits 0 when it applied or compared the replica, 2 when there is nothing to apply (stderr `cluster:apply: no replica to apply`), and 3 when a part of the report has the mode `failed` (today only `secrets`, when `config/openssl_extra` cannot be written). With 3 the report is on stdout, one JSON line with no secret in it. Log that output as for any failed run, keep the stored files and ETags, and fetch nothing again because of it: `cron:cache` applies again every minute while CONFIG is on, and the next change runs `cluster:apply` again. Today's agent already logs any non-zero exit with its output (`cluster: replica: apply: …`) and carries on. `service` ignores the exit code at boot.

### Booting from the replica, and the settings misses (Phase 7, seventh increment)

**ReplicaStage.** `Core/Bootstrap/Stage/ReplicaStage` boots a process from the node replica's caches instead of MAIN's database (plan, section 10, step 2). It takes the place of `DatabaseStage` and `LegacyCoreStage` and leaves what they leave, so later code finds the same state:

| Left by the boot | From |
| --- | --- |
| global `$db`, `DatabaseFactory`, the domain wiring, container `db` | a `LazyDatabaseHandler`: nothing is opened until a query needs it |
| `SERVER_ID`, container `core.config` | `config.ini`, as before |
| `SettingsManager`, `$rSettings`, `core.settings`, `settings` | the settings cache the replica owns; else the cache however old; else nothing |
| `$rServers`, `core.servers`, `servers` | the servers cache, the same way |
| `$rRequest`, `core.request`, the time zone, `on_demand_wait_time`, `$rFFMPEG_*`, `$rFFPROBE` | as `LegacyInitializer::initCore` sets them, unchanged |
| `core.bouquets`, `core.categories`, `bouquets`, `categories` | those caches as they are, or `[]`: no section carries them yet (R2) |
| the xc_vm crontab (once per boot) | only the jobs the replica owns (`ReplicaApply::crontabText(null)`); otherwise left as it is |

Once a process booted this way (`ReplicaBoot::active`), `SettingsRepository::getAll` and `ServerRepository::getAll` never read MAIN's database, forced or not. Any other query opens it lazily, on first use: that is the connect `ConnectAudit` counts, and since the eighth Phase 7 increment mode 2's refusal refuses it. Since the eleventh Phase 7 increment a mode 1 process reads them from MAIN's database once an apply handed them back (`ReplicaBoot::hybrid`).

**Who boots from the replica.** `BootKernel::resolve` decides for the CLI profile, `WebApiBootstrap::coreStages` for the web API endpoints:

- **A node in mode 2**, active or quarantined, by the agent's `flows.json` (`NodeFlows::declared`: the file alone, read before the settings, the servers or a database handle exist). Only once an apply built the replica's settings and servers caches since the reboot (`ReplicaBoot::ready`: both in `replica_owned`). Until then `ReplicaStage` runs `DatabaseStage` and `LegacyCoreStage` itself, so a mode 2 node without a replica boots as before; since the eighth Phase 7 increment the refusal makes that boot fail closed.
- **`cluster:apply`**, in every mode (`console.php` passes `ReplicaBoot::forArgv`, which answers `always` for it): its work is to build those caches. With CONFIG on it needs no database at all, so the agent's applies go on with MAIN's MariaDB stopped. In shadow, its comparison with MAIN's crontab and RTMP publishers reads MAIN's database on first use, as before.
- **Everything else boots exactly as before:** nodes in mode 0 and 1, MAIN, the admin UI (`BootContext::Admin`), the streaming entry points (`BootContext::Stream`, `StreamingRequestBootstrap`, `initStreaming`), and a caller that passes `replica => false`. A stale `flows.json` on MAIN cannot move it off its database: no apply built replica caches there. Since the eleventh Phase 7 increment a node in mode 1 with CONFIG on boots as mode 2 does, and on both the streaming entry points take a lazy handle once an apply built the caches.

**`cluster:apply --from-disk`.** `service` runs it at boot on a node with mode 1 or 2 and CONFIG on, before `daemons.sh` (fifth increment). The agent may not run yet then, and the `<name>.json` files it writes for PHP carry no signature. So with `--from-disk` each section comes from the record behind it (`Core/Cluster/ReplicaRecords`), checked as the agent checked it when it stored it:

- The node's keys come from the agent's `config/cluster/agent.json`: `node_uuid`, `node_box_sk` and `panel_sign_pub`, the last two as Go writes `[]byte` (standard base64, 32 bytes each).
- A record opens with XCVM-SEAL-v1 (purpose `replica`, context the node uuid) to `u32(len) ‖ payload ‖ sig`. `sig` must verify under the panel key with tag `rep` (`blk` for the blocklist's deltas), and the payload must name this node and the section, with an `etag` of 64 lowercase hex digits (the blocklist's too) and a `data` that is a JSON object or array; each section's own checks follow at the apply.
- A whole section is `<name>.rep`. The blocklist is `blocklist.rep` (with its `seq`) and `blocklist.d/*.blk` applied in name order, each `seq` above the last, removals before additions, the addresses sorted, as the agent's `materialise` does.
- A section is read only where the agent stored its `.json` (what `ReplicaApply::owns` and the readers go by); its data then comes from the record. A record that is missing, does not open or verify, names another node or section, or a delta out of order, makes the section unreadable: it writes no cache and hands back a cache the replica owned, as a malformed `.json` does.
- The report gains `from_disk: {verified: [names], unverified: [names]}`, names only: no content, ETag or key.
- Every apply, from disk or not, applies the whole sections (secrets, settings, servers, crontab, cluster) before the blocklist: they are what `ReplicaBoot::ready` needs, and the blocklist's RTMP publishers are keyed by their resolved address (`gethostbyname`). From disk they are not resolved: DNS may not answer during the MAIN outage the boot is for, and `service`'s 15 s `timeout` would stop the apply. An address is kept as it is (the same key), and a name as given, which matches no client until the next apply without `--from-disk` resolves it.

Without `--from-disk` nothing changes: the agent runs `cluster:apply` right after verifying what it stored.

**`audit.settings_misses` on the node.** `Core/Cluster/SettingsAudit`, called by `SettingsManager`'s getters (`get`, `getBool`, `getInt`, `getString`, `getArray`, `has`):

- It counts on a node in mode 1 or 2, by `flows.json` alone (decided once per process; MAIN and legacy nodes have no file and count nothing). A read of a key outside `lb_settings_keys.php` is a miss; the allowlist's `withheld` keys are known reads, not misses. A read of a known key costs one array lookup.
- A process keeps its counts in memory, at most 64 names and the rest under `*`. It merges them at exit, and at most every 60 s while it runs, into `STORAGE_PATH/cluster/settings_misses/YYYYMMDD.json` (UTC day, `{key: count}`, 64 names and `*`) under the file's lock. A miss thus costs one locked merge per process: per request under PHP-FPM, which keeps no state between requests. The merge rewrites `config/cluster/audit.json` only when it added a key to the day or the file is at least 60 s old, so requests do not queue on it; the counts in it may lag until the next such merge or `cron:cleanup`.
- `audit.json` is `{"settings_misses": {key: count}}` over the last seven UTC days, today included: most missed first, then by name, at most 64 names, the rest (and every key that is not `[a-z0-9_]{1,64}`) under `*`, last. `{}` when nothing was missed. It is written only where the agent's directory exists, atomically, and removed in mode 0. `cron:cleanup` prunes day files older than eight days and rewrites it every hour, so days that leave the window drop out.
- Nothing reaches a database; a failed write is dropped (the next merge tries again). A root process (`cron:root_signals` runs root-only commands that read settings) makes the day directory one level at a time, 0750, and hands each level and file it makes, `audit.json` included, to the owner of the agent's directory (xc_vm). Since the eighth Phase 7 increment root does this work as that user instead (`SettingsAudit::asAgentUser`), and no longer writes or chowns with its own rights. `service` also makes `storage/cluster/` xc_vm's at boot, since an LB build ships no `storage/`. A process that cannot read the days (a level it may not search, a day file it may not read) writes no report and leaves `audit.json` as it is.
- The allowlist's CI scan (`tools/ci/lb_settings_keys.php`) now reads every `ADD` of an `ALTER TABLE` on `settings`, not only the first. Migration 016 adds three columns in one statement, so `update_channel_bin` and `update_channel_fanout`, which `UpdateChannels` reads on a node, were missing: they are in the settings section now, and no miss.

**`audit.settings_misses` on MAIN.** `Domain/Cluster/NodeAudit`: the heartbeat's `audit` is kept in `cluster_nodes.audit` (migration 045, mirrored in `database.sql`) as `{"settings_misses": {…}}`:

- Only an object whose `settings_misses` is an object, at most 16 KiB (16384 bytes) in its shortest JSON encoding (slashes and non-ASCII unescaped), which is never longer than the `audit.json` the agent sent it from. `sites`' `path:line` strings, whose slashes PHP's default encoding would escape, are thus measured as the agent measured them. Entries that are not a name (or `*`) with an integer count of at least 1 are dropped. Past 64 names the least missed fold into `*`. Other members are not kept yet.
- It is written only when it differs from what the row holds. The row is read for every request already, so an unchanged report costs no query, on the cluster bus or not. A heartbeat without `audit` (today's agent), or with a malformed one, changes nothing, and neither does any heartbeat before migration 045.
- The Cluster Nodes page shows, for a node in mode 1 or 2, `—` (nothing reported), `0`, or the number of keys with the keys and counts.

**How it differs from the plan.**

- `ReplicaStage` replaces the two stages only once an apply built the caches, not outright: a mode 2 node rebooted before its first apply would otherwise boot with no settings.
- `cluster:apply` boots from the replica in every mode, not only mode 2: the plan's boot from disk is needed from mode 1 on, and so is its apply with MAIN's MariaDB stopped (Phase 7's acceptance).
- The stream and admin boots keep their stages; the plan names only the CLI profile and `WebApiBootstrap`.
- `--from-disk` verifies the stored records with the agent's keys. The plan had PHP trust the agent's verified files, which holds while the agent runs. It verifies under the agent's pinned panel key, not root's pin (`RootPin`): whoever could plant a record in xc_vm's files could as well write the caches the apply builds.
- The misses are counted through `SettingsManager`'s getters only. Reads of `SettingsManager::getAll()[…]` or `$rSettings[…]` cannot be seen at run time; they are what the allowlist's CI scan covers.
- The report is a seven-day window, like the connect audit's cutover gate, and MAIN keeps the node's last report. The plan says neither.
- Migration 045: 044 is taken by a concurrent change on another branch.

**Known limits.**

- Mode 2 is not switched on yet, and its refusal is not built: in a process booted from the replica, a query outside the settings and servers still opens MAIN's database. Since the eighth Phase 7 increment such a query is refused.
- No section carries the bouquets, categories, proxies or allowed-IPs caches (R2). A mode 2 node's `cron:cache` still builds them from MAIN's database, and during a MAIN outage it stops at the first such read. The agent's `cluster:apply` still applies the replica then. Since the twelfth Phase 7 increment the proxies and allowed IPs come from the `servers` and `settings` sections, the bouquets and categories have sections of their own, and a mode 2 node's `cron:cache` reads no database.
- After a reboot the streaming endpoints have no stream definitions until MAIN answers: they live in `tmp/`, a tmpfs, and no section carries them (R2 `streams`, not built). The same holds in mode 2. Since the twelfth Phase 7 increment `--from-disk` builds the node's stream caches from the R2 section, once an agent stores it, on a node whose CONFIG flow is on (`service` runs it only then). A node with STREAMS on and CONFIG off boots through MAIN's database, and `startup`'s `cron:cache` builds them. In mode 1 the daemons and crons also still boot through MAIN's database (the watchdog waits for it), as the plan has it for hybrid mode. Since the eleventh Phase 7 increment they boot from the replica there too once CONFIG is on and an apply built the caches.
- A mode 2 process that boots before an apply built the caches after a reboot (a cron in the first minute, before `service`'s apply) boots through MAIN's database. Since the eighth Phase 7 increment that boot is refused: the process fails closed.
- After a re-enrolment (new node keys) or a new panel root, the stored records no longer verify, and `--from-disk` applies none of them until the agent fetches them again. Today's agent keeps its ETags and never does while the data is unchanged (contract below).
- The misses reach MAIN only once the agent sends `audit.json`, which an agent before xc_vm_fanout #31 does not (the page then shows `—` for its node). The heartbeat's other audit counters (`audit.sql_connects`, `audit.redis_connects`, `audit.sites`) are not reported yet; the same `audit` object is meant to carry them. It does since the eighth Phase 7 increment.

**Tests.**

- `ReplicaBootTest`: the CLI profile and `WebApiBootstrap::coreStages` per mode and state (mode 0 and 1, MAIN, a mode 2 node that is enrolling or revoked: unchanged; the web API's stage waits for an apply), `cluster:apply` in every mode, and the fallback until an apply. In a child PHP, it runs the real `console.php` and bootstrap in a throwaway deploy root, with an `xcvm_core` stand-in whose database never answers and logs each connect, and a `crontab` stand-in first on the `PATH` that logs what would be installed. `cluster:apply --from-disk` and the agent's `cluster:apply` build every cache without one connect, and leave the crontab alone (the replica did not own it at boot); a mode 1 node still connects at boot (since the eleventh Phase 7 increment only before an apply, or with CONFIG off); a mode 2 node boots its CLI and its web API through its database before an apply, and without one connect after, its CLI and a cached web API endpoint leaving the globals and container entries above and its CLI writing the crontab from the replica's jobs. A miss in that process reaches the day file and `audit.json` at its exit, and the boot itself reads only allowlisted settings.
- `ReplicaRecordsTest`, with records built by `ReplicaBuilder::record` (`tests/Support/ReplicaFixture`): the records win over a planted or stale `.json`; a corrupt record, one signed by another panel key, one for another node, another section's record (a settings record with a `seq` in place of the blocklist's included), an ETag that is not 64 hex digits and a missing record are refused and hand back their caches; no or a broken `agent.json` verifies nothing; the blocklist's deltas in and out of order, a removal and re-addition in one delta, the addresses sorted; RTMP publishers' names unresolved from disk and resolved after, the whole sections before the blocklist; nothing stored exits 2; the report holds no content.
- `SettingsAuditTest`: counts per getter, `withheld` not counted, mode 0 and MAIN counting nothing, the merge across processes, the caps, the seven-day window and the pruning (an eight-day-old file kept), the empty report, the minute's merge of a long-running process, and `audit.json` rewritten for a new key or once a minute. As root: a flush with every level missing hands each level and file to the agent directory's owner, a publish as that user (nobody, in a child PHP) keeps the counts, and a level or day file it cannot read leaves the report as it is.
- `NodeRoleTest`: `cron:cleanup` prunes the audits and publishes `audit.json` before its MAIN-only part. `ReplicaBuilderSecretsTest`: `update_channel_bin` and `update_channel_fanout` are allowlisted.
- `ClusterApiTest`: a heartbeat's `audit` stored once, normalised; an unchanged, missing or malformed one writing nothing; an empty one clearing the page (`ClusterAdmin::nodes`); the cap; the 16 KiB bound on the shortest encoding (`sites` full of slashes fits); no column before migration 045. `ClusterSchemaTest`: migration 045.

**The agent's contract (XC_VM_Fanout, built in xc_vm_fanout #31).**

- **`audit`.** At every heartbeat, read `audit.json` beside `flows.json` and `local.json` (`filepath.Join(filepath.Dir(statePath), "audit.json")`). When it exists, the file is at most 16384 bytes, and it parses to a JSON object, send it as the heartbeat payload's `audit`, parsed and re-encoded like `telemetry.local`. Otherwise send no `audit`: MAIN keeps what it has. The limit is on the file's bytes: MAIN measures what it receives in its shortest encoding, which is never longer for the names, strings and integer counts PHP writes, so it drops no audit the agent sends for its size. There is no age limit: PHP rewrites the file at least every hour on a node in mode 1 or 2 and removes it in mode 0. The agent need not interpret it: MAIN checks it and ignores members it does not know, so a later PHP can add `sql_connects`, `redis_connects` and `sites` without an agent change. The reply is unchanged.
- **`agent.json`.** `cluster:apply --from-disk` reads `node_uuid` (a lowercase UUID string), `node_box_sk` and `panel_sign_pub` (each 32 bytes, as `encoding/json` writes `[]byte`: standard base64 with padding) from the agent's state file. Keep those names and that encoding, the file readable by xc_vm (it is written 0600 by xc_vm), and the panel key the one the stored records verify under.
- **Stored records.** Keep writing each record as received: `<name>.rep` (sealed bytes, not base64), `blocklist.rep`, and the deltas as `blocklist.d/<seq, 19 digits>.blk`, since PHP verifies them at boot. Write `.rep` before `.json`: PHP reads a section only where its `.json` exists, and then takes the `.rep`.
- **Records that no longer verify.** When the agent starts, and after an enrolment or re-key that changed its box key or pinned panel key, open and verify every stored record with the current keys. For each whole section whose record fails, set its held ETag to `""` (`whole_etags`, `settings_etag`). For a blocklist that fails, set `blocklist_etag` to `""` and `blocklist_seq` to 0, so the next `config` call fetches it again. Otherwise MAIN answers `unchanged` while the data is unchanged, and `--from-disk` keeps refusing the old records.
- **Nothing else changes for the boot.** `service` runs `cluster:apply --from-disk` itself; the agent keeps running plain `cluster:apply` after it stores something. The output gains `from_disk` only with `--from-disk`. The exit codes are the sixth increment's.

### The mode-2 refusal and the connect audit (Phase 7, eighth increment)

**The guard.** Every connect a node opens to MAIN's MySQL or Redis passes `Core/Cluster/ConnectAudit::guard()` first (plan, section 10, step 1): in `Database::db_connect()` and `db_explicit_connect()`, `RedisManager::connect()` and `RedisCache::connect()`. It asks `NodeRole`, from the files alone and at each connect, so a mode switch takes effect at the next one:

| Node | A connect is |
| --- | --- |
| MAIN, no `flows.json`, mode 0 | opened as before, not counted |
| mode 1 (any state) | counted, then opened |
| mode 2, `active` or `quarantined` | counted, then refused with `LbDatabaseAccessException`; nothing is opened and `\XC_VM` is never asked |
| mode 2, any other state | counted, then opened |
| mode 2 on MAIN's build | counted, then opened |

- **Mode 2** is `ReplicaBoot::wanted()`: the agent's `flows.json` says mode 2 and a state MAIN counts as active. It is the node that boots from its replica (seventh increment). Since the eleventh Phase 7 increment `wanted()` also takes in mode 1 with CONFIG on, and the refusal asks `ReplicaBoot::apiMode()`, which is this line's mode 2.
- **Never on MAIN.** A stray `flows.json` on MAIN would otherwise lock it out of its own database, so `NodeRole::mainBuild()` answers from the files: MAIN's build ships `Public/cluster/index.php`, and the load balancer build strips it (`verify-lb-archive.sh` fails the build otherwise). No database or servers cache is needed for the answer.
- **Graceful or not.** `db_connect(false, true)` and `DatabaseHandler::reconnect()` are refused too, rather than answering `false`: a refusal is not an outage to wait out, and the watchdog's wait loop would otherwise spin without end. The exception extends `XcVmException` (a `RuntimeException`) and carries `rKind` (`sql` or `redis`) and `rSite`; its message is `MySQL: refused on a node in cluster API mode (mode 2), at <site>` (`Redis:` for Redis).
- **The boot.** A mode 2 node rebooted before its first apply falls back to `DatabaseStage` (seventh increment), whose connect is now refused: the process ends at its boot, the refusal in the panel's error log and in the audit. `cluster:apply` boots from the replica and is not affected, so `service`'s `cluster:apply --from-disk` builds the caches and the next processes boot from them.
- **A manual trace** (`XCVM_CONNECT_AUDIT=1`, or `STORAGE_PATH/cluster/sql_audit/enabled`) still counts in mode 0; nothing wrote that file, so until now nothing was counted.

**Direct connects.** Fifteen places built `new DatabaseHandler()`, each an eager connect. The eight a load balancer runs now take `DatabaseFactory::open()`, which builds the same handle and keeps it as the process's (`DatabaseFactory::set` was called at each): `LegacyCoreStage`'s reconnect, `Public/admin/{live,thumb,timeshift,vod}.php` and the enigma2, xplugin and playlist API controllers. `DatabaseFactory` and `DatabaseStage` keep theirs; the other five (`Public/admin/api.php`, `proxy_api.php`, `ActiveCodeApiController`, the setup view and `migration_logic.php`) are MAIN-only. `ArchitectureTest` enforces it, reading the Makefile's `LB_DIRS_TO_REMOVE` and `LB_FILES_TO_REMOVE` for what is MAIN-only and skipping comments (and, for the second rule, string literals):

- no `new Database(`/`new DatabaseHandler(` outside `DatabaseFactory`, `DatabaseStage`, migrations and MAIN-only files;
- in load balancer code, `XC_VM::db_connect(` and `new PDO` (or `PDO::connect(`) only in `Core/Database/Database.php`, `XC_VM::redis_connect(` only in `RedisManager`, `new Redis` only in `RedisCache`, and each of those three calls `ConnectAudit::guard(`; `new mysqli`, `mysqli_connect(`, `mysqli_real_connect(` and `mysqli_init(` nowhere. PHP's names are case-insensitive and a global-namespace file needs no leading backslash, so each pattern matches any case, with or without it.

**The audit.** `STORAGE_PATH/cluster/sql_audit/` (the plan's `var/cluster/sql_audit/`; `STORAGE_PATH` survives reboots):

```text
YYYYMMDD.json    the UTC day's counts, exact, under the file's lock:
                 {"sql": n, "redis": n, "sites": {"<kind> <path>:<line>": n}}
YYYYMMDD.ndjson  one line per connect, up to 1 MiB a day, then counted only:
                 {"t": unix, "k": "sql"|"redis", "s": "<path>:<line>", "p": pid[, "r": 1 when refused]}
since            unix seconds: when this node's audit began
```

- **A site** is the first caller outside the connect machinery (`Database`, `DatabaseHandler`, `LazyDatabaseHandler`, `DatabaseFactory`, `RedisManager`, `RedisCache`): `<path>:<line>`, relative to `MAIN_HOME`. Its key is `<kind> <path>:<line>`, every byte outside printable ASCII replaced by `?`, at most 160 bytes: a longer path keeps its end after `...`. A boot's connect names `Core/Bootstrap/Stage/DatabaseStage.php:<line>`.
- **Bounded.** At most 32 sites a day, the rest under `*`. Eight days are kept (`cron:cleanup`, both files, before its first query, so in mode 2 too), so the directory stays under 9 MiB. A broken day file starts over. The counts never throw: an audit must not break the connect it audits.
- **Reads.** A count rewrites its day file in place under the file's exclusive lock (truncated, then written). The report reads each day file under its shared lock (`AuditDays::readDay`, through `AuditDays::window`, which the settings misses use too), so a report built while another process counts never sees a day emptied, and its counts never go down.
- **Root.** A root process (`cron:root_signals`, `startup` and `cluster:root` boot through MAIN's database in mode 1; since the eleventh Phase 7 increment only before an apply built the caches, or with CONFIG off) counts as the owner of `config/cluster/` (xc_vm), and merges its settings misses and writes `audit.json` the same way: `SettingsAudit::asAgentUser` switches its effective gid, its groups (`initgroups`) and its uid to that user's around the file work, and back after. The kernel then applies xc_vm's permissions, so a link xc_vm planted where it can write leads root nowhere xc_vm could not go, and what root makes (levels 0750, files) is xc_vm's without a `chown`. Root writes nothing when `config/cluster/` is missing, a link or root's, when its owner has no passwd entry or root's group, or when a switch fails. Until this increment's review, root wrote as root and handed what it made over with `chown`, following links in directories xc_vm can write.

**The report.** The last seven UTC days, today included, go into the `audit.json` the agent sends as the heartbeat's `audit` (seventh increment), beside `settings_misses`:

```json
{"settings_misses": {}, "sql_connects": 1440, "redis_connects": 0,
 "sites": {"sql Core/Bootstrap/Stage/DatabaseStage.php:27": 1440}, "connects_since": 1790380800}
```

- `sql_connects`, `redis_connects`: integers ≥ 0, attempts, refused ones included.
- `sites`: most first, then by name, at most 32 names, the rest (and `*`) last under `*`. `{}` when there were none.
- `connects_since`: when the node's audit began. It is written at the first report in mode 1 or 2. Mode 0 removes it with `audit.json` and deletes the days it counted (a manual trace's days, counted without it, stay), so a node back in mode 1 starts a new window from nothing. The counts start at its UTC day: an older day file is left out of the report. The seven-day gate needs both: zero connects, and a `connects_since` at least seven days old.
- **When.** A connect rewrites `audit.json` when it adds a site to its day or the file is at least 60 s old; `cron:cleanup` rewrites it every hour, before its first query (so in mode 2 too), and a node without connects reports zeros. A process that cannot read the days (a level it may not search, a day file it may not read) leaves the report as it is. The file is written with unescaped slashes. Its largest form (64 settings keys and 32 sites of 160 bytes, with ten-digit counts) stays under 11 KiB, within the 16 KiB the agent sends and MAIN takes.

**On MAIN.** `Domain/Cluster/NodeAudit` keeps the connect members in `cluster_nodes.audit` with the misses, as the seventh increment keeps those: only with an object `settings_misses`, and only when `sql_connects` and `redis_connects` are integers ≥ 0 and `sites` an object, all three or none. Sites that are not `(sql|redis) <printable ASCII>` of at most 160 bytes (or `*`) with an integer count ≥ 1 are dropped; past 32 the least counted fold into `*`. A `connects_since` that is not an integer ≥ 1 is dropped alone. The row is written only when the report changed. The Cluster Nodes page adds a column for nodes in mode 1 or 2: `—` without a report, else `SQL n · Redis n` (green at zero), when the count began, and the sites.

**The root flush.** `NodeActions::flushBlocklist()` already reached a node with the COMMANDS flow and root's pin as a signed `node.root {action: "flush"}` (Phase 4), but `RootSignalsCronJob::executeAction()`, which `cluster:root` runs, had no `flush` case: the command was acked and nothing flushed. Only the `signals` row, matched by its exact payload at the top of `cron:root_signals`, flushed iptables.

- `executeAction()` now handles `flush` as the row did: `iptables -F`, `ip6tables -F`, the flood guard's block files, `iptables-save`, a `FLUSH` line in `mysql_syslog`. **Superseded** by [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05): the flush removes the panel's own blocks and no other rule.
- `cron:root_signals` stops polling the `signals` table for the flush row on a node that takes MAIN's root commands (`RootSignalsCronJob::rootCommandsFromMain()`: COMMANDS on and root's pin in place). A row queued before, or while MAIN lacked `root_ready`, still runs through the same case from the signals loop. Legacy nodes are unchanged.
- With CONFIG on, the minute's iptables sync follows the replica's `blocked_ips` cache, which drops the flushed addresses at the agent's next `config` pull (the flush logs a `reset`), so an address may be blocked again for up to a minute, as with the row.

**How it differs from the plan.**

- The plan names a log; this keeps exact per-day counts beside it and bounds both, since a mode 1 node logs every boot and stream request. The report's window and `connects_since` are not in the plan: the gate needs to know a count covers seven days.
- Refusing graceful callers, the MAIN build check, and `DatabaseFactory::open()` for the direct sites are not in the plan. It counts 14 direct sites; there were 15, five of them MAIN-only.
- `ArchitectureTest` also covers the lower-level connects (`\XC_VM`, PDO, `\Redis`, mysqli), which the plan leaves out.
- The plan does not say who writes the audit. Root counts as xc_vm, so it never writes where xc_vm can plant a link.
- The plan's `blocklist_sync` root action is not built. The replica's minute sync already applies the blocklist; the flush, a `node.root` action since Phase 4, now runs.
- Moving the flush off the row is only feasible where MAIN sends root commands (COMMANDS and the pin). Elsewhere the row stays.

**Known limits.**

- Mode 2 cannot be switched on yet (`lb_new_node_mode = api` waits for the cutover phase), and a node in mode 2 still has paths that need MAIN's database. The refusal stops them, counted:
  - `cron:root_signals` reads MAIN's `signals` table. Without COMMANDS or root's pin its first read is the flush-row poll at the top, which stops it before the iptables sync and the fanout and agent keepalives. With both (the intended mode 2 setup) it runs the iptables sync (from the replica's cache with CONFIG on) and the keepalives, and stops at the signals loop: the signal actions, the ramdisk and ports reconciliation, the crontab and sysctl checks and `close_mysql` are lost. A server IP that differs from the replica's, which it writes to `servers`, stops it before the keepalives too. Since the tenth Phase 7 increment it reads nothing of MAIN's database in mode 2, and the server IP rewrite turned out to run only on MAIN.
  - `cron:cleanup`'s stream, archive and VOD checks read `streams` from MAIN's database whenever `cleanup` (on by default) or `check_vod` is on. Its audit pruning and hourly `audit.json` run before them. Since the tenth Phase 7 increment a node in mode 2 skips them until R2.
  - Root actions log to `mysql_syslog` through MAIN's database, several before acting (reboot, restarting or stopping the services), so `cluster:root` reports them refused and those never act. Since the tenth Phase 7 increment the lines go through the agent and the actions act, but for `update` and `rollback`, refused before they run.
  - `cron:cache` builds the bouquets, categories, proxies and allowed-IPs caches from MAIN's database (R2). Not since the twelfth Phase 7 increment.
  - The watchdog waits for MAIN's database. Since the tenth Phase 7 increment it neither waits nor uses Redis in mode 2.
  - `cluster:apply`'s shadow comparison (CONFIG off) reads MAIN's crontab and RTMP publishers. Since the tenth Phase 7 increment mode 2 compares neither and says so.
- Mode 1 still boots through MAIN's database (seventh increment), so a mode 1 node's `sql_connects` is never zero. The boot's site shows it apart from the rest, but the plan's seven-day zero cannot be reached in mode 1 until mode 1 boots from its replica too. It does since the eleventh Phase 7 increment, with CONFIG on.
- A tree that holds MAIN's `Public/cluster/index.php` (a node installed from MAIN's archive) never refuses; it still counts.
- A CLI process refused at its boot ends through the panel's exception handler, with exit status 0.
- The counters reach MAIN only once the agent sends `audit.json`, which an agent before xc_vm_fanout #31 does not (the page then shows `—` for its node).

**Tests.**

- `DbConnectRefusalTest`: who refuses and who counts, per mode and state, and a switch taking effect at the next connect; MAIN's build never refusing a stray `flows.json`. In this process, on a fixed clock, every path is refused before `\XC_VM` is asked, the first three and `RedisCache` naming their caller: `new DatabaseHandler()`, `DatabaseFactory::open()` (keeping no handle), a lazy handle's first query, a graceful `db_connect`, `reconnect`, `db_explicit_connect`, `RedisManager::connect` and `instance`, and `RedisCache`. Each is counted and logged with `r`, even with the audit forced off. In a child PHP, the real bootstrap in a throwaway deploy root with an `\XC_VM` that logs each connect: a mode 2 node's boot and Redis are refused without one connect, the boot's site counted; MAIN, mode 0 and mode 1 connect as before, mode 1 counted; `DatabaseFactory::open()` keeps the handle it built (an SQLite one there) as the process's.
- `ConnectAuditTest`: MAIN and mode 0 write nothing, a manual trace still counts; the caller as the site through the lazy handle; the caps (sites, site length and bytes, the log); a broken day file; the seven-day window and the eight-day pruning; `audit.json` with both audits, rewritten at once for a new site however fresh, else once a minute, `connects_since` kept and reset through mode 0; the counts starting at `connects_since`'s day (mode 1, then 0, then 1 again reports none of the first); zeros in a report without connects; the ranking across days; the largest report within 16 KiB; the hooks in both connect paths; a report read while another process counts 2000 times never going down (`SettingsAuditTest` the same for the misses). As root: every level and file made as the agent's user, root's rights back after; links planted in the day directories and in `config/cluster/` (a dangling day file, the log, a misses day file, the report itself, which the rename replaces) leaving a root-only directory as it was; nothing written when `config/cluster/` is root's; a publish as that user reading them, and a day file it cannot read leaving the report as it is. A suite run as root hands each test's tree to nobody (`Tests\Support\AgentUser`), as a node's is xc_vm's.
- `ArchitectureTest`: the two rules above, and the connect patterns fed each form they must catch (any case, with or without the backslash, `new Redis;`, `Pdo\Mysql`, mysqli) and a few they must not (`new RedisCache(`, `RedisManager::connect()`). `ReplicaBootTest`: a mode 2 node before its first apply is refused at boot, the CLI and the web API alike. `NodeRoleTest`: `cron:cleanup` prunes the audits and publishes `audit.json` before its first query.
- `ClusterApiTest`: a heartbeat's connect counters stored with the misses and shown on the page; malformed counters dropping the connect report and keeping the misses; a bad `connects_since` (not an integer, or 0) dropped alone; the sites cap.
- `ClusterRootCommandTest`: `rootCommandsFromMain()` per flows, and with COMMANDS on, with and without a pin root trusts; the `flush` action run through `executeAction()` with iptables stubbed and a database that refuses every other statement: flushed and saved once each, one `FLUSH` line, nothing of the `reboot` case below it; the row read only where MAIN still sends it. `NodeRpcActionsTest`: every root action, `flush` included, is a case of `executeAction()`.

**The agent's contract (XC_VM_Fanout).** No change is needed:

- **`audit`.** The seventh increment's contract is unchanged: read `audit.json` beside `flows.json`, and when it is at most 16384 bytes and a JSON object, send it as the heartbeat payload's `audit`, parsed and re-encoded. It now also holds `sql_connects`, `redis_connects` (integers), `sites` (an object of `"<kind> <path>:<line>"` to integer counts, printable ASCII keys of at most 160 bytes) and `connects_since` (an integer). Pass them through as they are. Go's HTML escaping of `<`, `>` and `&` does not matter: MAIN measures what it decodes, in PHP's shortest encoding. MAIN ignores members it does not know, so a heartbeat without them keeps what MAIN has.
- **The refusal.** It is PHP's alone and needs nothing from the agent: `cluster:apply` boots from the replica in every mode and opens no connect with CONFIG on. With CONFIG off, its shadow comparison reads MAIN's database, which mode 2 refuses. An agent that switches a node to mode 2 must not rely on that report. (Since the tenth Phase 7 increment mode 2 does not read it, and names what it left out under `unchecked`.)
- **The flush.** It arrives as the Phase 4 `node.root` command it already was: `action` `flush`, handed to root's inbox by `cluster:exec`. Root's result is `{ok, result}` as for every root action. `ok` is true once iptables was flushed, unless the `mysql_syslog` line was refused (mode 2): then `ok` is false with the refusal's message. (Since the tenth Phase 7 increment that line goes through the agent in mode 2, and `ok` is true.)

### The R2 streams section on MAIN (Phase 7, ninth increment)

**What a node holds.** A node holds a stream when it is assigned it (`streams_servers`), records its TV archive or thumbnails (`streams.tv_archive_server_id`, `vframes_server_id`), or has a recording of it scheduled (`recordings.source_id`), and the stream's row exists. The R2 `streams` section is one `stream` record per stream the node holds (`Domain/Cluster/StreamReplica`, fields in `Core/Cluster/ReplicaSections`), never sent whole:

| `data` | Content |
| --- | --- |
| `stream` | `STREAM_FIELDS` of the `streams` row |
| `type` | its `streams_types` row (`STREAM_TYPE_FIELDS`), or null |
| `profile` | its transcoding profile (`PROFILE_FIELDS`), or null |
| `options` | its `streams_options`, each with its argument's definition (`OPTION_FIELDS` and `ARGUMENT_FIELDS`), by `argument_id`, then the option's id |
| `server` | the node's own `streams_servers` row (`STREAM_SERVER_FIELDS`: `server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`), or null when the node only records its archive, its thumbnails or a recording of it |
| `children` | the servers whose row for the stream has this node as `parent_id`, ascending |
| `recordings` | its recordings scheduled on this node (`RECORDING_FIELDS`), by id; `status` as MAIN last heard it |
| `tickets` | null: the slot for Phase 8's relay and file tickets. Since Phase 8's fourth increment, for a node whose DATAPLANE flow is on, `{files, relay}` (below); the ETag is taken with it null |

Values are typed as in the whole sections (integers as JSON integers, text as strings, a missing column or NULL as null), and every level has its keys sorted. Every column of `streams` and `streams_servers` is in exactly one of the carried and local lists, and `ReplicaSectionsTest` fails on a new one:

- **Local, `streams`** (`STREAM_LOCAL`): the workers' pids nodes report (`tv_archive_pid`, `vframes_pid`), `updated`, and MAIN's catalogue metadata: `order`, `notes`, `year`, `rating`, `similar`, `tmdb_id`, `tmdb_language`, `plex_uuid`, `uuid`, `epg_offset`, `title_sync`.
- **Local, `streams_servers`** (`STREAM_SERVER_LOCAL`): every runtime column nodes write (`StreamStateWriter::STATE_FIELDS`, which `StreamVersionsTest` checks), `updated`, and the created channel's build state (`pids_create_channel`, `cchannel_rsources`).
- An option's own id and `stream_id`, and an argument's `id` and `argument_description`, are not carried either.

**Versions.** `cluster_stream_ver` (migration 034) holds a row per server and stream the server holds or held, with the version at which MAIN last changed what the server needs of the stream. `Core/Cluster/StreamVersions::bump()` stamps a stream anew for every server that holds it now and every server that already has a row for it, so a stream taken off a node reaches it as a removal:

- One bump takes as many versions as it has streams from a counter, `cluster_meta.stream_ver` (1 before any change), one per stream in id order. It runs in a transaction that holds the counter's row, so bumps commit in the order of their versions, and a node that has read a version has read every version below it. A node's cursor is one number: the version of the last row it applied.
- A change to every stream at once (`StreamsChangedEvent::all()`: a bulk rewrite of the source URLs; `reset()` after a panel migration) raises the floor, `cluster_meta.stream_ver_floor`, to a new version instead of stamping every row. A node whose cursor is below it checks every stream again.
- Migration 047 keys the table by stream, seeds a version-0 row for every server that holds a stream at upgrade, and inserts the counter at 1, or at the highest version a row already holds. A stream a node took before the upgrade therefore still reaches it as a removal. `database.sql` has the key.
- **The counter never restarts below a row.** A version below a node's cursor never reaches it by a delta. The down migration therefore empties the table (only this increment's code writes it) and removes the counter, the floors and the pruning's place, so a later upgrade seeds it afresh. A counter found missing starts past the highest row's version (`advance()`), as the migration starts it.
- A panel migration's import (`migration_logic.php`) adds holders without naming them. It gives each its version-0 row the way migration 047 does (`StreamVersions::seedHolders()`), then resets.
- **Recording never fails the change.** A bump runs in a transaction of its own: any statement that fails rolls it back and returns 0, and the change then reaches nodes by the resync. The exception is a bump that finds a transaction already open (a writer that dispatches inside its own). It runs inside it and throws to the caller instead of returning 0, as does a counter that cannot be created there (`advance()`): a failed statement may be a deadlock that rolled back the caller's whole transaction, which the caller must learn of.

**Who bumps.** MAIN's writers of a stream's desired configuration dispatch a typed event, and `StreamVersions` listens with `#[ListensTo]`. `EventDispatcher::subscribe()` (new) registers a class's attributed static methods, as `ModuleLoader` does for modules; `ContainerPopulateStage` registers `StreamVersions` at every boot through the kernel (the admin UI and REST API, the CLI and crons), and `Public/cluster/index.php` does for the ops that finish recordings.

| Event | Dispatched by | Bumps |
| --- | --- | --- |
| `StreamsChangedEvent` | stream, radio, created channel, movie and episode saves and mass edits; a stream taken off a server; `move` to another server; a server deleted; a profile deleted; an EPG source deleted; the EPG auto-assignment; a category deleted; the stream review; the movie title tool (`post.php`, `replace_movie_years`); a recording scheduled (`post.php`) and finalised (`RecordingFinalizer`); the series cron's sources | the streams named |
| `StreamsChangedEvent::all()` | the DNS replace | every node's floor |
| `StreamsDeletedEvent` (existing) | stream deletes | the streams named |
| `TranscodeProfileSavedEvent` | `ProfileService` | the streams that transcode with it |
| `StreamArgumentsChangedEvent` | the settings save, when an argument's default changed | the streams with an option of it |

- **A recording finalised through a node's `recording.state` event** is dispatched only once the event batch has committed (`EventIngest::afterCommit()`, `RecordingFinalizer::finish()`'s dispatcher), never inside the batch's transaction. Its version therefore commits after the batch and its cursor, in a transaction of its own; if that bump fails, the batch stays applied and the change reaches nodes by the resync. A batch that fails dispatches nothing. Other callers of `finish()` dispatch at once.
- **Never bumped:** what nodes write back (`StreamRowMerge`, `StreamStateWriter`, `ContentSink`, `EventIngest`: runtime state, workers' pids, recordings' status, VOD analysis), and MAIN's TMDb and provider metadata crons. A changed carried column among those (`movie_properties`, `stream_display_name`, a recording's `status`) reaches a node by the resync.
- `StreamVersionsTest` checks every writer of `streams`, `streams_servers`, `streams_options`, `streams_arguments`, `streams_types`, `profiles` or `recordings` function by function. A named function or method that writes one dispatches one of the four events (a `use` import is not a dispatch). Top-level code (views, scripts) dispatches after each such write, before its next `exit`. An `UPDATE` that sets only local columns needs none. The exceptions are listed with their reasons: what nodes report, the metadata crons, `RadioService`'s private helpers (their callers dispatch), `post.php`'s cleanup of assignments whose server or stream is gone, and the import, which seeds and resets at its end.

**The `streams` op.** The plan's R2 op (section 7), on the bulk ingest lane with a per-op semaphore of 4 (`ClusterSemaphore::OPS`). It reads no `servers` row of MAIN's. It is served only to an `active` node whose STREAMS flow (8) is on and whose agent says `streams` at hello (`cluster_nodes.features`); otherwise it answers a signed `409 FLOW_OFF` with `flow: "streams"`, plus `feature: "streams"` when only the feature is missing.

- **Delta** (`{since}`): the node's version rows past `since`, in version order, 1000 at most per call. A row whose stream the node holds is its record; any other is a removal. The reply's `ver` is the version of the last row served. `more` is set when rows remain, or when a reply could not hold them all: at most 200 records or 4 MiB of them, at least one.
- **`full`.** A `since` of 0 (nothing held), below the node's floor, or above MAIN's head (its versions went back: a restore) is answered `full: true` and nothing else. The node then checks every stream.
- **Resync** (`{since, resync: {from, to, hashes}}`): the plan's section hashes. MAIN reads the streams the node holds in `from..to`, at most 1000 per call, and answers the records whose ETag differs from the one the node names (or that it does not name), and removals for the ids it names that it does not hold. `next` says where to go on from when the reply could not cover the range. The node's cursor is left as it was.
- **Records** are `rep`-signed and sealed to the node like the whole sections, and name the node, its generation, the stream and its version. The ETag is the SHA-256 of the record's canonical data.
- **Without a licence** a record is not signed. It is left out and counted in `withheld`, and the removals still go. A delta's cursor stops before the first such record, so it comes again once the licence is back. Any other refusal to sign denies the call, as for the whole sections.
- **Never from a failed read.** Every read throws when it fails, and the op answers `503 DB` rather than signing a partial section.
- **Pruning.** `cron:cluster` drops the rows of streams their server no longer holds once they are seven days old, even with the API off. A run looks at 10,000 rows in key order (`server_id`, `stream_id`), from where the last run stopped (`cluster_meta.stream_ver_prune`, `"<sid>:<stream id>"`), and starts over once it reached the end. The rows of held streams, which stay, therefore never keep it from the others. It first raises each server's own floor (`cluster_meta.stream_ver_floor.<sid>`) to the newest version that server loses, so a node whose cursor is below it checks every stream again. A row stamped again since it was read stays.

**On the node, for PHP.** `Core/Cluster/ReplicaStreams` reads the section as the agent stores it (contract below). It returns the whole section or null: null without a completed pass (`streams.json` with a `since` above 0), without a readable `streams/` directory, and when any file does not read as the record of its own stream. A caller that prunes by the list, such as `cron:cleanup`'s checks, thus never takes a partial list for the node's streams. `assigned($types)` gives what `cron:cleanup`'s stream and created-channel checks select from MAIN's database today, and `archives($sid)` its TV-archive check. Its VOD check can take its carried columns from `assigned([2, 5])` (`stream.target_container`, `stream.movie_properties`, `stream.direct_source`, `server.server_stream_id`), but not the `pid > 0` and `stream_status` it filters on: those are the node's runtime state, which it keeps itself (`StreamStateWriter`) and no record carries. Nothing calls it yet: moving `cron:cleanup` off MAIN's database is the mode-2 work. Since the thirteenth Phase 7 increment `cron:cleanup` reads it (`NodeStreams`), and its VOD check takes the pid and status from the node's own store (`StreamRuntime`).

**How it differs from the plan.**

- The plan names the version table, not how a node asks. Every bump takes one version per stream from a counter shared by all nodes, and bumps commit in version order, so a node's delta cursor is one number.
- The section hashes are per stream (one ETag per record) and sent by id range, at most 2000 per call, not one hash per section: a node with thousands of streams would otherwise send them all every 5 minutes.
- "Signed once per content hash (cached)": a record names the node and its generation, as every `rep` record has since the fifth increment, so a signature cannot be shared between nodes. Each is signed per request, as the whole sections are.
- Removals travel in the MAC'd BOX reply, not as signed records: a removal only restricts, and the node stores nothing for it.
- A node also holds the streams whose archive or thumbnails it records and those with a recording scheduled on it. The plan lists assigned streams and the recordings.
- `children` lists the servers configured to relay the stream from this node, not a count of active relays. The count a node checks today (`attachedRestreamCounts`) is runtime state (children with a running feed), which R2 does not carry; Phase 8's relay tickets need the configured list.
- Metadata crons (TMDb, the providers' title sync) dispatch nothing, as the plan has only the desired-config writers bump: the resync carries their changes.
- No wake-up on change: a change reaches a node at its next delta, within a minute. The plan's "on change" needs a push, like `config.changed`, not built.
- The table existed (migration 034); migration 047 adds the stream key, the holders' rows and the counter.
- The plan has R2 ignore what nodes write. Two carried columns are written from what nodes report as well as by MAIN: a recording's `status` (`recording.state`; MAIN sets it when it schedules one) and a VOD's `movie_properties` (its analysis; MAIN's TMDb data). They stay in the record. Such a node write bumps nothing, but it moves the record's ETag, so the next resync resends the record once to each node that holds it. The plan's local `recording.state` override is the node's half: its own state wins over the record's `status`.
- The section is served whatever the node's mode. The `secrets` section needs mode 1 or 2 for its secrets; stream sources are what a legacy node reads from MAIN's database anyway.

**Known limits.**

- The node's half is not built: no agent stores the section yet, and no PHP applies it (the stream caches, `StreamSource`, `stream_bundle` on a start miss). The node keeps reading its streams from MAIN's database. Since the twelfth Phase 7 increment PHP applies it; the agent's half and `stream_bundle` are still not built.
- A holder added by a path that dispatches nothing (a module, an SQL edit) has no version row. When the stream is later taken off it, the node learns it only from the resync, within 5 minutes.
- The delta cursor's correctness rests on the bump's transaction (InnoDB). A connection that cannot begin one (none in production) could commit the counter before the rows; the resync repairs what that misses.
- The stream cache entry a node builds today also holds the stream's `bouquets`, which the section does not carry. The twelfth Phase 7 increment found that no node builds that entry (`cron:cache_engine` is MAIN's); the bouquets reach a node as a section of their own.
- The resync reads a node's whole set every 5 minutes, 1000 streams per call.
- The pruning walks the table 10,000 rows a minute: on a panel with a million rows, a row no node holds may wait up to 100 minutes past its seven days.
- Without a licence, a delta stops at the first changed record: removals past it wait for the licence or the resync.

**Tests.**

- `StreamVersionsTest`: a version per stream in id order for every kind of holder; a past holder stamped too; the floor raised by a reset and never lowered; the counter kept where migration 047 started it; migration 047 down then up, and a missing counter, never starting below a row; each bump in its own transaction, which a statement refused anywhere (a holder's read, the second of two `REPLACE`s) rolls back with the counter, never throwing when the bump opened the transaction itself; a reset whose floor cannot be written keeping the old one; the import's seeding, so a later removal reaches the node; each event bumping its streams; MAIN's writers dispatching (removal from a server, `move`, a mass edit, a profile or EPG source deleted, a recording finalised); what nodes write back never bumping, and never a carried column; the writer scan, function by function, and the scan itself on a sample. `TestDb` runs transactions over its PDO, and `QueryLogDb` hands them to it.
- `RecordingFinalizerVersionsTest`: a recording finalised by a node's event batch bumped only after the batch and its cursor committed, a bump that deadlocks then leaving the batch applied, and a batch that fails bumping nothing; a bump inside a caller's open transaction throwing when a statement or the counter's creation fails; `finish()` outside a batch dispatching at once, a failed bump returning 0.
- `StreamReplicaTest`: the streams a node holds by id and by range, a limited read missing none, and the pruning: each node's floor raised first to the newest version it loses, going on past more held rows than a run looks at, and a row stamped again since it was read kept.
- `ClusterApiTest`: the op only with the flow and the feature, on the bulk lane, without MAIN's row, holding its semaphore; a new node's full pass, then a delta, an edit, runtime writes that move nothing, and a removal; a resync answering only what differs, in pages, and a delta past a reply's worth; a resync past what one call examines going on where it stopped, never reporting the ids past it as removals; a delta past what one call reads saying `more`; `resync: null` taken as a delta and null `hashes` as none; a node never getting another node's stream, recording or row, nor a secret or a local column, each record opening for it alone; without a licence, changed records withheld and removals still arriving, then sent once licensed; `503 DB` for every failed read; malformed requests refused; `full` below the floor, after a reset and above the head; MAIN's cluster entry point subscribing `StreamVersions` before it serves an op.
- `ReplicaStreamsTest`: the whole section or nothing, a missing or unreadable `streams/` included. `ReplicaSectionsTest`: every column classified. `ClusterSchemaTest`: migration 047, up and down, and the key. `BootStageTest`: the listeners after `ContainerPopulateStage`. `EventDispatcherTest`: `subscribe()`. `SettingsServiceClusterPortTest`: a settings save announcing only the argument defaults it changed.

**The agent's contract (XC_VM_Fanout, built in xc_vm_fanout #32).**

- **Feature.** List `"streams"` in hello's `features` only once the agent implements all of the following. MAIN serves the op only to such an agent, on a node whose STREAMS flow (8) is on. Otherwise it answers a signed `409 FLOW_OFF` with `flow: "streams"`, plus `feature: "streams"` when only the feature is missing. Keep what is held, say hello again if the feature was not recorded, and ask at the next poll.
- **Op.** `POST /cluster/v1/streams`, session-authenticated, on the bulk lane, one call at a time. Refusals are those of every ingest op: `503 RATE_LIMITED` with `op: "streams"` (the per-op semaphore, no `lane`, or the bulk lane, `lane: "bulk"`): send the same request again after the busy wait. `503 DB`: keep every file and the cursor, ask again at the next poll. `409 NOT_ACTIVE` (the node is quarantined): keep every file and the cursor, ask again once the node is active. `404 UNKNOWN_OP` (a MAIN rolled back below this increment): keep every file and the cursor, ask again at the next poll. The session refusals (`LICENCE_INVALID`, `NODE_REVOKED`, `TOKEN_EXPIRED`, `CLOCK`) as for any op.
- **Delta request:** `{"since": <int ≥ 0>}`, the cursor held (0 when nothing is held). Omit `resync`; MAIN also takes `"resync": null` as a delta.
- **Delta reply:** `{"ver": <int>, "head": <int>, "more": <bool>, "streams": [ENTRY…], "removed": [<stream id>…], "main_time_ms": <int>}`, with `"full": true` or `"withheld": <int>` when they apply.
  - `full: true` comes with no record and no removal: start a full pass (below).
  - Otherwise store every ENTRY, delete every removed stream, then set the cursor to `ver`. While `more` is true, ask again at once.
  - `withheld`: records MAIN could not sign without a licence. `ver` stops before the first of them and `more` is false: ask again at the next poll.
- **Resync request:** `{"since": <cursor>, "resync": {"from": <int ≥ 0>, "to": <int>, "hashes": {"<stream id>": "<ETag>", …}}}`, with `from ≤ to ≤ 2147483647`. `hashes` names the ETag (64 lowercase hex) of every stream held with an id in `from..to` and of no other, at most 2000. It is a JSON object whose keys are decimal stream ids (≥ 1), `{}` when none is held there; MAIN also takes `null` or a missing `hashes` as none. Each ETag is the `etag` field of `streams/<id>.json` as stored: never recompute it from `data`, whose re-encoding (a Go encoder escapes `<`, `>` and `&`) would differ from what MAIN hashed and resend every stream. Anything else (a JSON array for `hashes`, an id outside `from..to`, a malformed ETag) is `400 BAD_REQUEST`.
- **Resync reply:** `{"ver": <since as sent>, "head": <int>, "next": <int> | null, "streams": [ENTRY…], "removed": [<stream id>…], "main_time_ms": <int>}`, with `"withheld": <int>` when some were. `streams` holds the records of the streams held in the range whose ETag differs or is not named; `removed` the named ids no longer held. `next` set: the reply covers `from..next−1` only. Null: the range is done.
- **ENTRY:** `{"id": <stream id>, "ver": <int ≥ 0>, "etag": "<64 hex>", "sealed": "<base64 std>"}`. `sealed` is XCVM-SEAL-v1 to the node's box key, purpose `replica`, context the node uuid. It opens to `u32(len) ‖ payload ‖ sig`, where `sig` is the panel's signature over `payload` under tag `rep`, and `payload` is `{"v": 1, "section": "stream", "node": <uuid>, "gen": <int>, "stream_id": <int>, "ver": <int>, "etag": "<64 hex>", "iat": <unix seconds>, "data": DATA}`.
  - Store a record only if the signature verifies under the pinned panel key and `section` is `stream`, `node` this node, and `stream_id` and `etag` those of the ENTRY. Checking `gen` against the token's generation is recommended.
  - When one record of a reply fails, apply nothing of the reply and keep the cursor: log it and ask again at the next poll.
- **DATA:** `{"children": [<sid>…], "options": [OPTION…], "profile": {…} | null, "recordings": [{…}…], "server": {…} | null, "stream": {…}, "tickets": null, "type": {…} | null}`, fields as the table above. Store it exactly as signed; the agent need not parse it, as PHP does. A later MAIN may fill `tickets` (Phase 8) or add keys. Phase 8's fourth increment fills `tickets` and adds a delta's ticket refresh: its contract is in that increment.
- **Files** under `config/cluster/replica/`, mode 0600, since stream sources may carry an upstream's credentials. Each is written to a temporary file whose name starts with a dot, in the same directory (`streams/.<id>.rep.tmp`, `streams/.<id>.json.tmp`, `.streams.json.tmp`), then renamed; the readers skip dot files. Nothing else named `*.json` may be in `streams/`: a name that is not a stream id makes PHP read no section at all.
  - `streams/<id>.rep`: the sealed record as received (bytes, not base64).
  - `streams/<id>.json`: `{"etag": "<etag>", "ver": <ver>, "data": <data as signed>}`. Write `.rep` before `.json`.
  - A removal deletes `streams/<id>.json`, then `streams/<id>.rep`.
  - `streams/`, mode 0700: created before `streams.json` first holds a cursor above 0, and kept, empty, while the node holds nothing. PHP takes a missing or unreadable one as a section lost, never as one that holds nothing.
  - `streams.json`: `{"since": <cursor>}`, rewritten whenever the cursor changes. It holds 0 until the node's first full pass completes: `Core/Cluster/ReplicaStreams` reads the section only when it is above 0.
  - `state.json` keeps the cursor (`streams_since`), a full pass in progress (`streams_pass`: its head and the next `from`) and the last resync's time (`streams_resync_at`).
- **The range walk.** Each call sends the ETags of the held streams with an id of at least `from`, at most 2000 in ascending order. `to` is the last of those ids when more held ids remain above it, else 2147483647. After the reply, `from` becomes `next` when set, else `to + 1`. The walk ends after a call with `to` 2147483647 answers `next` null. Take each call's hashes from the files as they are stored at that moment, so the records just stored count.
- **Cadence.**
  - **Delta:** after every `config` sync (every 60 s), and at once while `more`.
  - **Full pass:** on `full`, or when the cursor is 0. Walk from 0 with the hashes held (none on a new node) and no deltas meanwhile. Once the walk ends with no `withheld` in any reply, set the cursor to the `head` of the pass's first reply and write it to `streams.json`. With a `withheld` reply, keep the cursor as it was: a new node stays at 0 and walks again at the next poll.
  - **Resync:** every 5 minutes, jittered by ±10 %, the same walk with the cursor unchanged.
- **Apply.** After storing any record or removal, run `console.php cluster:apply`, debounced 1 s as today. Nothing in PHP applies the section yet; its readers read the files. Since the twelfth Phase 7 increment `cluster:apply` builds the node's stream caches from them (its contract adds to this one).
- **Never logged:** a record, its data or a diff of it. Name the stream id only.
- **Compatibility.** Today's agent never lists `streams`, so it never calls the op and MAIN never serves it; nothing else changes on the wire. A rollback below migration 047 empties `cluster_stream_ver` and removes the `cluster_meta` keys `stream_ver`, `stream_ver_floor`, `stream_ver_floor.<sid>` and `stream_ver_prune`; MAIN then answers the op `404 UNKNOWN_OP`. An upgrade seeds them afresh with the counter at 1, never below a row, so a node whose cursor is above MAIN's head is answered `full` and walks again.

### The paths left to MAIN's database (Phase 7, tenth increment)

The eighth increment's refusal stopped the paths a node in mode 2 still took to MAIN's database. This increment (the tenth: the ninth is the R2 `streams` section on MAIN, built beside it) moves those it listed off it (plan, section 10: mode 2 has no database and no Redis to MAIN; everything goes through the agent). Each check below is `NodeRole::refusesConnects()`, the refusal's own test (mode 2, active or quarantined, by `flows.json`, never on MAIN's build), so MAIN, mode 0 and mode 1 run exactly as before.

**`cron:root_signals`.** `RootSignalsCronJob::readsMainDatabase()` is false on a node in mode 2. There the cron reads:

| What | Mode 0, 1, MAIN (unchanged) | Mode 2 |
| --- | --- | --- |
| the flush row (top of the cron) | polled unless root commands come from MAIN | never |
| the signals loop | `signals` rows, then each run through `executeAction()` | no row: MAIN sends root's actions as the Phase 4 `node.root` commands `cluster:root` runs |
| ramdisk, ports and services checks | when a `*_ramdisk`, `set_port` or `set_services` row is queued | when the replica's servers cache changes (below) |
| crontab check | MAIN's `crontab` table, or the replica's jobs once it owns them | the replica's jobs once it owns them, else the crontab is left as it is (`ReplicaApply::crontabText(null)`) |
| iptables sync with CONFIG off | MAIN's `blocked_ips` | nothing: iptables left as they are |
| sysctl check, iptables sync with CONFIG on, keepalives, nginx files | unchanged, no database | unchanged, no database |

- **The checks from the replica.** A `set_services`, `set_port` or `*_ramdisk` row made the cron check the node's own row (`total_services`, the ports, `use_disk`) and queue what differs. In mode 2 the replica says when that row changed: `replica_owned`'s servers entry (`<servers ETag>/<node ETag>`, what the servers cache was built from) differs from `tmp/crons/replica_servers_checked`. The cron reads that value before it loads the servers cache it checks (an apply writes the cache, then the value, so the rows are at least as new as the value; one recorded in between runs the checks again the next minute). It then writes the value there, before acting (at most once, as a signal row was deleted before it ran), and runs the three checks against the servers cache. That also happens once after a reboot, since `tmp/` is cleared, and never while the replica does not own the servers cache (CONFIG off). MAIN still sends each change as its `node.root` command; the checks make good one that expired while the node was away.
- **`close_mysql()`** of a handle the process never opened does nothing.
- **The server IP rewrite** needs nothing: it sits in the cron's `is_main` branch and only ever rewrites MAIN's own `server_ip`. The eighth increment's list, and the fifth Phase 5 increment's note on `server_ip`, said a node ran it; none does. A node's `server_ip` stays the admin's, and MAIN refuses it in `node.state` anyway.

**Root's system log lines.** Fifteen `mysql_syslog` writes in `RootSignalsCronJob` (twelve root actions in `executeAction()`, the flush row, two PHP-FPM restarts) now ask `LogSink::syslog($type, $error)` first:

| Node | The line |
| --- | --- |
| MAIN, mode 0 | the row, by the statement as it was |
| mode 1, LOGS on, agent alive | a `log.syslog` event on P1 (below) |
| mode 1 otherwise | the row, by the statement as it was |
| mode 2, LOGS on, agent alive | a `log.syslog` event on P1 |
| mode 2 otherwise | the panel's error log, not MAIN's system log (below) |

- The action runs after its line in every case. A line that is spooled, kept in the error log or refused never stops it. The spool file is renamed in before the action, so a reboot's line survives it (`config/cluster/spool/` is on disk).
- **The error log.** In mode 2 a line the spool refuses (the agent stopped, or LOGS off) goes to the panel's error log (`FileLogger`, `LOGS_TMP_PATH/error_log.log`) as `{"type": "syslog", "message": "Not in MAIN's system log (mode 2, and the agent took no event): <type>: <error>"}`, the error redacted. `cron:errors` sends that file on as `panel_logs` rows (`log.panel_error` with LOGS on) once the agent takes events again; `panel_logs` merges identical lines, so the same line twice is one row. Root writes it as the owner of the agent's directory (`SettingsAudit::asAgentUser`, the directory above the spool): the logs directory is xc_vm's, where root must not create a file of its own or follow a link. Only when root cannot switch does the line go to PHP's `error_log`, a cron's stderr.
- **What `cluster:root` reports in mode 2.** Until this increment every root action with a system log line was refused at that line: `ok` false with the refusal's message, before acting (`reboot`, `restart_services`, `stop_services`, `reload_nginx`, `certbot_generate`, `update_binaries`, `install_module`, `delete_module`, `update`, `rollback`) or after it (`flush`, `set_openssl_extra`); `set_governor` and `set_sysctl` too, after acting, when the spool did not take their `node.state`. Now all of them act and report `ok` true, except `update` and `rollback`. `certbot_generate`, `update_binaries`, `install_module` and `delete_module` run a console command to its end: the `&` that ends their lines never detached it, since `shell_exec()` and `exec()` read its output until it closed it (`install_module` runs it from an argv list since the fourth Phase 4 increment). `ok` does not reflect that command's result, except for `install_module` with a staged archive, whose `ok` and `result` are `module:install`'s (third Phase 4 increment). This note first said they start the command in the background and `ok` means only that it started; corrected. `binaries`, `module:install` and `module:delete` work on files and need no database, `certbot` reads MAIN's database only when certbot fails or says the certificate is not due (below; not since the fourteenth Phase 7 increment).
- **`update` and `rollback`** are refused in mode 2 before anything runs (`RootSignalsCronJob::updatesHere()`): the update command writes MAIN's `servers` row (`status` 5 before it starts the updater, the version after), which mode 2 refuses, so the node would download the archive and stop there, reported started. `ok` is false and `result` is `update: refused on a node in cluster API mode (mode 2): the updater still writes MAIN's servers row` (`rollback: …` for a rollback). No system log line is written.
- `NodeStateSink::state()` (`set_governor`, `set_sysctl`, the certbot command's `certbot_ssl`) no longer writes the servers row in mode 2: it answers false when the spool did not take the event.
- `ClusterRootCommand::runAction()` is the drain's runner, extracted, and closes its output buffer when an action throws.

**`log.syslog` on MAIN.** `LogSink::TYPES` gains `syslog` (`mysql_syslog`: `server_id`, `type`, `error`, `username`, `ip`, `database`, `date`). `EventIngest` takes it on P1 with the LOGS flow, as every `log.<type>`, row by row:

- `type` must be one of `LogSink::SYSLOG_TYPES` (`FLUSH`, `REBOOT`, `OPENSSL_EXTRA`, `RESTART`, `STOP`, `RELOAD`, `CERTBOT`, `BINARIES`, `MODULE`, `UPDATE`, `PHP-FPM`) and `error` a string; otherwise the whole event is refused (dropped and counted). `AUTH` never passes: no node writes it. (Until 2026-10 `cron:root_mysql` blocked the addresses of `AUTH` rows, so a node must not have been able to have MAIN block one; that block is gone, the refusal stays.)
- `server_id` is the sender's; `username` is `root`, `ip` `localhost` and `database` NULL, whatever the node sent.
- `date` is the node's when it is an integer of at least 1 and not after MAIN's clock (`ClusterClock::now()`), else MAIN's clock. The newest `date` is `cron:root_mysql`'s watermark for MySQL's own log, which a date in the future would hold back.
- The row is redacted, as every `log.*` row.

**The watchdog.** In mode 2 it sets up no Redis, checks none (`checkRedisHealth`), never pings MAIN's database or waits for it (`waitForDatabase`), and reads no capacities (`ConnectionTracker::getCapacity`: MAIN's Redis or `lines_live`). It still refreshes the servers and settings (the replica's caches) and checks nginx and its own file, then writes `config/cluster/local.json` and ends the pass, whatever TELEMETRY says: it never writes the servers row. Each pass is a new process, so a mode switch takes effect at the next one.

**`cron:cleanup`.** `CleanupCronJob::streamChecks()` is false in mode 2, and the cron returns after its audit pruning and `audit.json`. The stream, archive and VOD checks read this node's streams, which no section carries yet; run against an empty list, they would delete every file. Skipped with them: the TV archive retention (segments older than `tv_archive_duration`), the VOD analysis (`check_vod`) and the created-channel checks. This is the seam R2 fills: once the `streams` section is applied, it answers true in mode 2 and the checks read the replica's streams. Since the thirteenth Phase 7 increment it does, once the node's own store of its runtime state is seeded too.

**`cluster:apply`'s shadow comparison.** Decided: mode 2 needs CONFIG, and the node does not read MAIN's database to report without it.

- Without CONFIG no apply builds the caches `ReplicaBoot::ready()` needs (a shadow apply hands them back), so every process but `cluster:apply` fails closed at its boot. The switch to mode 2 (Phase 9) must therefore require every flow, CONFIG included, as the plan's section 10 has it.
- `cluster:apply` in mode 2 with CONFIG off still reports, without MAIN's database: the crontab part is `{"etag", "mode": "shadow", "jobs"}`, without `missing` and `extra`; `diff` has no `rtmp_ips`; the report gains `"unchecked": ["crontab", "rtmp_ips"]` (those of the two it had to compare). The node's own caches (settings, servers, the other blocklist caches) are compared as before.

**How it differs from the plan.**

- The plan's `log.*` has seven types; there are six (`client`, `stream`, `stream_error`, `panel_error`, `restream`, `syslog`). `log.syslog` is new, and MAIN rewrites its fixed columns and bounds its date.
- The plan has root's actions arrive as commands and says nothing of the checks a `signals` row triggered; following the replica's ETags for them is this increment's.
- The plan does not say what a mode 2 node without CONFIG does. It cannot boot, so this increment only keeps `cluster:apply` off MAIN's database there.
- Refusing `update` and `rollback` in mode 2 is not in the plan: the update command still needs MAIN's database. Built later: see [A node in mode 2 updates, and its status and inventory](#a-node-in-mode-2-updates-and-its-status-and-inventory).

**Known limits.**

- Mode 2 still cannot be switched on (Phase 9).
- In mode 2 a system log line the spool refuses (the agent stopped for over two minutes, or LOGS off) never reaches MAIN's system log. The panel's error log keeps it, and it reaches MAIN's panel logs once the agent takes events again; where root cannot switch to the agent's user it goes to the cron's stderr and is lost.
- `update` and `rollback` cannot run in mode 2 until the update command stops writing MAIN's `servers` row. Built later: see [A node in mode 2 updates, and its status and inventory](#a-node-in-mode-2-updates-and-its-status-and-inventory).
- A root action MAIN still queues as a `signals` row for a node in mode 2 never runs: MAIN does so when it lacks `root_ready` or the node is quarantined (`CommandBus::acceptsRoot` takes active nodes only). MAIN's own cron purges the row after a day. The checks from the replica make good ports, services and the ramdisk; a reboot, restart, update or module action is lost.
- In mode 2, until R2 fills `streamChecks()`, the files of streams deleted on MAIN stay, TV archive segments are kept past their retention, and neither the VOD analysis nor the created-channel checks run. Since the thirteenth Phase 7 increment they run from the replica and the node's own store once both answer.
- Other paths still reach MAIN's database on a node in mode 2, and the refusal stops them:
  - the signals daemon (`signals`: kills and cache jobs from MAIN's `signals` table). Since the fourteenth Phase 7 increment it reads no row and no Redis in mode 2: MAIN sends the cache jobs as `node.cache` commands;
  - `cron:certbot` (this node's `servers.certbot_ssl`, and its renewal and nginx reloads queued as `signals` rows through `NodeActions`), and the `certbot` command `certbot_generate` starts, which reads `servers.certbot_ssl` when certbot fails or says the certificate is not due. Since the fourteenth Phase 7 increment neither reads MAIN's database in mode 2: the node keeps a copy of the `certbot_ssl` it reported and reloads its nginx itself, and MAIN sends the renewal as a `node.root`;
  - `cron:servers`, every minute: it counts this node's running streams in `streams_servers` (R2), and without `redis_handler` its connections in `lines_live` first. It stops there, before `node.inventory`, so a node in mode 2 sends no inventory (and writes no `servers_stats` row, which TELEMETRY leaves to MAIN anyway). Built later: see [A node in mode 2 updates, and its status and inventory](#a-node-in-mode-2-updates-and-its-status-and-inventory);
  - `cron:vod` and `cron:streams`, which read this node's stream rows (R2);
  - `cron:cache`'s bouquets, categories, proxies and allowed-IPs caches and the stream endpoints (R2).

  The eighth increment's list left out the first four; the connect audit's sites on the Cluster Nodes page show what remains.
- Mode 1 is unchanged: it still boots through MAIN's database, so its `sql_connects` is never zero. Since the eleventh Phase 7 increment it boots from its replica with CONFIG on.

**Tests.**

- `ModeTwoPathsTest`: each path in a child PHP booted for real from the replica (a mode 2 node, every flow on, the replica applied from disk), in a throwaway deploy root, with an `xcvm_core` stand-in that logs each connect and `sudo`, `crontab` and `ip` stand-ins first on the child's `PATH` (the child checks they answer before it boots; `sudo` also logs how many P1 spool files there were when it was asked). None opens or even attempts a connect (the audit counts every attempt): `cron:root_signals`' minute (the iptables sync from the replica, the three checks once and not again, and again once a new `node` section is applied, the crontab against the replica's jobs, or left as it is when the replica refused its crontab section, `ip` never asked), a PHP-FPM restart (its line spooled, the services restarted), `reboot`, `restart_services`, `stop_services` and `flush` through `cluster:root`'s drain (each acts, logs in order through the spool, the first three before acting, reported `ok`), `update` and `rollback` refused before anything runs, a watchdog pass with Redis on and one without TELEMETRY (the sample written, no wait, no row), `cron:cleanup` with `cleanup` and `check_vod` on (no file deleted), and a shadow `cluster:apply` (`unchecked`). In this process: `readsMainDatabase()` per mode and state, and on MAIN's build; `streamChecks()` per mode, before the cron's first query; every `mysql_syslog` write guarded by `LogSink::syslog()` with its own type.
- `ClusterEventsTest`: the line spooled with LOGS on; LOGS off, a stopped agent and MAIN leaving the row to the caller; mode 2 keeping it, redacted, in the panel's error log, written as the agent's user. `NodeStateSink::state()` in mode 2 writing no row with the agent stopped or TELEMETRY off, and mode 1 writing it. On MAIN: `log.syslog` rows as root's on the sender, `AUTH` and a malformed row refused, a future or missing date taken as MAIN's clock, redacted, and LOGS off refusing it. `ClusterRootCommandTest`: the flush row read only where the node reads MAIN's database and root commands do not come from MAIN.

**The agent's contract (XC_VM_Fanout).** No change is needed:

- **`log.syslog`** is one more `log.<type>` in the P1 spool, one row per file today: `{"type": "log.syslog", "t": <ms>, "d": {"rows": [{"server_id": <int>, "type": "<one of SYSLOG_TYPES>", "error": "<text>", "username": "root", "ip": "localhost", "database": null, "date": <unix seconds>}]}}`. The agent sends P1 lines as they are and counts them in P1's cap like every log; it need not know the type. MAIN answers as for every P1 batch. A MAIN before this increment drops it (unknown type, counted).
- **Root's results.** `cluster:root`'s `.done` (`{ok, result}`) changes for a node in mode 2 only. `reboot`, `restart_services`, `stop_services`, `reload_nginx`, `certbot_generate`, `update_binaries`, `install_module`, `delete_module`, `flush` and `set_openssl_extra`, and `set_governor` and `set_sysctl` when the spool does not take their `node.state`, now act and report `ok` true where they reported `ok` false with the refusal's message (`MySQL: refused on a node in cluster API mode (mode 2), at <site>`). For the four that run a console command (`certbot_generate`, `update_binaries`, `install_module`, `delete_module`), root waits for its end (not only its start, as this note first said), and `ok` does not reflect its result; `install_module` with a staged archive is the exception, its `ok` and `result` being `module:install`'s (third and fourth Phase 4 increments). `update` and `rollback` still report `ok` false, now before anything runs, with `result` `update: refused on a node in cluster API mode (mode 2): the updater still writes MAIN's servers row` (`rollback: …`). The agent passes `.done` on as it does today.
- **Mode 2.** An agent (or MAIN, Phase 9) that puts a node in mode 2 must have every flow on (CONFIG, COMMANDS, LOGS and TELEMETRY at least, as the plan's section 10 has it, DATAPLANE once Phase 8 builds it) and root's pin in place (`root_ready`): without CONFIG no process boots, without COMMANDS and the pin no root action reaches it, without LOGS root's system log lines stay in the node's error log, and without TELEMETRY nothing writes the node's servers row (the watchdog leaves it to MAIN, which writes it from the heartbeats only with TELEMETRY on) and `node.state` is dropped.

### Mode 1 boots from the replica too (Phase 7, eleventh increment)

**Before.** Only a node in mode 2 booted from its replica (seventh increment). A node in mode 1 booted every CLI process and web API endpoint through `DatabaseStage` and `LegacyCoreStage`, and every streaming request connected in `LegacyInitializer::initStreaming()`, so the connect audit (eighth increment) counted every boot and its `sql_connects` never reached zero. The plan (section 10, step 2) has the LB run DB-free in audit (mode 1) from Phase 7, and the seven-day count start there.

**Who boots from the replica.** By the agent's `flows.json` alone, read before the settings, the servers or a database handle exist:

| Node | CLI profile, web API | Streaming entry points | A connect to MAIN |
| --- | --- | --- | --- |
| MAIN, no `flows.json`, mode 0 | as before | as before: connected at once | opened, not counted |
| mode 1, CONFIG off | as before | as before | counted, opened |
| mode 1, CONFIG on, `active` or `quarantined` | `ReplicaStage` once an apply built the caches; until then as before | a lazy handle once an apply built the caches; until then as before | counted, opened |
| mode 2, `active` or `quarantined` | `ReplicaStage` once an apply built the caches (seventh increment) | a lazy handle once an apply built the caches; until then connected at once, which is refused | counted, refused |
| mode 1 or 2, any other state | as before | as before | counted, opened |

- `ReplicaBoot::wanted()` is now mode 2, or mode 1 with the CONFIG bit (32), in a state MAIN counts as active. `BootKernel::resolve` (the CLI profile) and `WebApiBootstrap::coreStages` ask it, as before. Mode 1 needs CONFIG: only then does the replica own the settings and servers. With CONFIG off a mode 1 node boots through MAIN's database at once, even before an apply hands the caches back (`ReplicaApply::disown`).
- `ReplicaBoot::apiMode()` is mode 2 in an active state, whatever the flows: what `wanted()` was. `NodeRole::refusesConnects()` asks it, so the refusal is exactly the eighth increment's, and mode 1 never refuses.
- "Once an apply built the caches" is `ReplicaBoot::ready()`, as for mode 2: `replica_owned` holds `settings` and `servers`. Only an authoritative apply (CONFIG on) records them, and the record goes with `tmp/` at a reboot. `service` already runs `cluster:apply --from-disk` before `daemons.sh` on a node in mode 1 with CONFIG on (fifth increment), so the daemons boot from the replica.
- `cluster:apply` boots from the replica in every mode, as before.

**What a mode 1 process booted from the replica still reads from MAIN.** Its boot opens nothing: `ReplicaStage` leaves what it leaves in mode 2, from the caches, with a lazy handle. After the boot:

- Any other query opens MAIN's database on the handle's first use. `ConnectAudit` counts it at its own site, the caller that needed it, not `DatabaseStage.php`, and mode 1 opens it. So a mode 1 node's crons, daemons and endpoints keep using MAIN for what no section carries (the streams, `signals`, `lines`), and the Cluster Nodes page lists those paths instead of every boot.
- The crontab. `LegacyInitializer::generateCron` writes the replica's jobs once the replica owns them, as in mode 2. While it does not (the agent stored no `crontab` section, or it was refused), mode 1 reads MAIN's `crontab` table as it did before, on the lazy handle (`ReplicaBoot::hybrid()`): one counted connect after each reboot (`generateCron`'s marker is in `tmp/`), at `Core/Cluster/ReplicaApply.php`. Without it the crontab would stay as the reboot left it, and `cron:root_signals` checks it only once it was written. `cluster:apply` (booted `ALWAYS`) and a node whose connects are refused (mode 2) never read the table: they leave the crontab as it is.
- The settings and servers. While the replica owns them, a process booted from it reads the caches, forced or not, as in mode 2. Once an apply hands them back (CONFIG off, a refused section, `replica_owned` gone), a mode 1 process reads MAIN's database for them as before, on the lazy handle (`hybrid()`), so a daemon that booted from the replica follows CONFIG going off at its next forced read. `cluster:apply` and a node whose connects are refused (`NodeRole::refusesConnects()`: mode 2, active or quarantined, never on MAIN's build) keep the caches however old (seventh increment). `hybrid()` asks the refusal at each read, as each connect does, so a daemon that booted in mode 1 keeps the caches once the node is switched to mode 2 instead of meeting the refusal, and reads MAIN's database again once it is back in mode 1. A node in mode 2 installed from MAIN's archive never refuses (eighth increment), so it reads them from MAIN's database as mode 1 does, counted.
- A cached web API endpoint with `enable_cache` off no longer reconnects at boot (`LegacyCoreStage`'s reopen): its queries use the lazy handle.
- `status`. `startup` runs `status 1` at every boot (after `service`'s `cluster:apply --from-disk`), and the update runs `status` after it. On a node it reads MAIN's `servers` and the Redis password, and writes its version to its `servers` row. Until this increment's review it checked the handle's `connected`, which a lazy handle leaves false until its first query, so on a node booted from its replica it printed `Couldn't connect to database` and exited 1 before its work: the permissions and nginx fixes, root's crontab (`cron:root_signals`, the modules' crons), the file limits, the init script and `config.enc`'s Redis host and password. `startup` ignores its exit code, so nothing showed it. It now asks MAIN's database with a query (`StatusCommand::mainDatabaseAnswers()`: `SELECT 1` on the process's handle), so a lazy handle opens there. In mode 1 that is status's connect, counted at `Cli/Commands/StatusCommand.php`, once per boot and per update. In mode 2 it is refused there, counted, and status prints the refusal's message and exits 1; it stopped at the same point, uncounted, since the seventh increment. MAIN, mode 0 and a node that boots through MAIN's database keep the handle their boot opened: no new connect.

**The streaming entry points.** Decided: `LegacyInitializer::initStreaming()` takes a lazy handle (`DatabaseFactory::connectLazy()`) where `ReplicaBoot::now()` holds (`wanted()` and `ready()`), in mode 1 and mode 2 alike, and connects at once elsewhere, as before. It boots `StreamingRequestBootstrap`'s streaming endpoints (`live`, `vod`, `timeshift`, `thumb`, `subtitle`, `rtmp`, `probe`, `status`), `player_api` and the Ministra portal, and `/stream/auth`, which calls it itself. `/stream/key` and `/stream/segment` never called it.

- They choose no boot stage: their settings, servers and blocklists always came from the caches (`CacheReader`), so the connect was their only use of MAIN's database at boot.
- A request that needs no query opens nothing. One that does opens MAIN's database at its first query: counted at that query's site in mode 1, refused there in mode 2. Which requests still query (the stream rows, viewer authentication, connection tracking without the agent) is what the counted sites show.
- In mode 2 a request that queries still fails, now at its query instead of its boot, and one that needs no query is served, where before every streaming request was refused at its boot. That is the node the plan's section 3 describes once DATAPLANE removes the viewer-authentication endpoints from nodes.
- `BootContext::Stream` has no caller and keeps its `DatabaseStage`. The relay endpoints `/admin/(live|thumb|timeshift|vod)` boot through `WebApiBootstrap` (above), and open the database at once for their stream rows (`DatabaseFactory::open()`), as do the Enigma2, XPlugin and playlist controllers for viewer authentication: each at its own site.

**What a zero means now.** On a mode 1 node with CONFIG on, `sql_connects` and `redis_connects` count what its processes still ask of MAIN, each at its site, and no boot from the replica. A boot through MAIN's database, before an apply built the caches after a reboot, still counts at `Core/Bootstrap/Stage/DatabaseStage.php` (a streaming request at `Core/Init/LegacyInitializer.php`). So a zero over the report's seven days, with a `connects_since` at least seven days old, means none of the node's processes needed MAIN's MySQL or Redis in that week: the plan's gate for mode 2 (section 10). A mode 1 node with CONFIG off still boots through MAIN's database and cannot reach it.

**How it differs from the plan.**

- The plan has mode 1 run DB-free from Phase 7. Here a mode 1 node boots from its replica only with CONFIG on, and still reaches MAIN's database, counted, for what no section carries: DB-free in mode 1 is what the count measures, not what the boot enforces. Mode 2 is where it is enforced.
- The plan names the CLI profile and `WebApiBootstrap`. The streaming entry points' lazy handle, in mode 2 too, is this increment's.
- Mode 1's fallback to MAIN's database for what the replica does not own (the settings, the servers, the crontab's jobs) is not in the plan.

**Known limits.**

- A real mode 1 node does not reach the zero yet: its crons (`cron:servers`, `cron:streams`, `cron:vod`, `cron:cache`'s bouquets, categories, proxies and allowed IPs), the signals daemon, `cron:root_signals` (its `signals` rows), `status` (at every boot and update), the relay endpoints (stream rows), viewer authentication (`auth.php`, `player_api`, the Enigma2, XPlugin and playlist controllers), and in Redis mode the connection tracking and the watchdog still use MAIN's database or Redis. That is R2, Phase 8 and the tenth increment's list for mode 2; the sites on the Cluster Nodes page show what is left.
- A process that boots before an apply built the caches after a reboot boots through MAIN's database, counted. After a re-enrolment `service`'s `--from-disk` apply refuses the stored records (seventh increment), so that lasts until the agent's own first apply.
- The counts reach MAIN only once the agent sends `audit.json` (seventh increment's contract; since xc_vm_fanout #31).
- In mode 2 `status` does none of its node work (above): it is one more path the refusal stops, until it takes the servers and the Redis settings from the replica. Built later: see [A node in mode 2 updates, and its status and inventory](#a-node-in-mode-2-updates-and-its-status-and-inventory).

**Tests.** `ReplicaBootTest`:

- In this process: mode 0 whatever its flows, MAIN, mode 1 without CONFIG, and nodes in other states boot as before; mode 1 with CONFIG (active or quarantined) and mode 2 (whatever its flows) take `ReplicaStage` in the CLI profile and the web API. `apiMode()` and `refusesConnects()` hold in mode 2 only; `hybrid()` holds for mode 1's `WHEN_READY` boot only, never for `cluster:apply` or mode 2, follows a switch to mode 2 and back while the process runs, and holds for mode 2 on MAIN's build, which never refuses.
- In a child PHP, the real `console.php`, bootstrap and streaming entry point in a throwaway deploy root: a mode 1 node before its first apply connects at boot, counted at `DatabaseStage.php`, the CLI and the web API alike. After `cluster:apply --from-disk`, `--list`, a CLI boot and a cached web API endpoint make no connect and leave the lazy handle unopened; the crontab is the replica's jobs; the report the agent sends says `sql_connects` 0. With CONFIG off it connects at boot again at once.
- A query in a mode 1 process booted from the replica connects on first use (MAIN's database is an SQLite file there), answers, and is counted at the query's own site, not refused.
- Once an apply handed the caches back, a mode 1 process booted from the replica reads its settings and servers from MAIN's database (one connect); a mode 2 process keeps the caches without one.
- With no `crontab` section stored, `cluster:apply` reads no table; a mode 1 boot reads MAIN's `crontab` table once, counted at `ReplicaApply.php`, and installs its jobs; mode 2 leaves the crontab alone without a connect.
- `initStreaming()`: mode 0, MAIN, mode 1 without CONFIG and mode 1 before an apply connect at once, in `initStreaming()` before the request runs (mode 1 counted at `Core/Init/LegacyInitializer.php`); mode 1 with CONFIG after an apply opens nothing, and the request's query connects, counted at its site; mode 2 opens nothing, and the query is refused, counted.
- `status`, as `console.php status 1` boots and runs up to its database check (the command itself needs root): on a mode 1 node booted from its replica the handle is lazy and not `connected`, and the check connects on first use, answers and is counted at `Cli/Commands/StatusCommand.php`; in mode 2 it is refused there, counted.

`DbConnectRefusalTest` is unchanged: mode 1 counts and never refuses, and its boot before an apply connects as before.

**The agent's contract (XC_VM_Fanout).** No change is needed, and today's agent is unaffected:

- No new op, lane, field, header, refusal, file, setting or exit code.
- PHP reads `flows.json` as today's agent writes it: `{"mode": <0-2>, "flows": <0-255>, "state": "<state>"}` (and `features`), compact and replaced atomically. On a node in mode 1, processes boot from the replica while `flows` has bit 32 (CONFIG) and `state` is `active` or `quarantined`; in mode 2, in those states whatever `flows` says. A missing or unreadable file boots through MAIN's database, as before.
- `cluster:apply` (with or without `--from-disk`), its output and its exit codes are the sixth and seventh increments'. `service` already runs `cluster:apply --from-disk` at boot on a node in mode 1 or 2 with CONFIG on.
- The fifth increment's rule is the one this relies on: run `cluster:apply` once after the first sync when the agent starts, and whenever the CONFIG bit it writes to `flows.json` changes. A mode 1 node boots from its replica only once an apply built the caches since the reboot; until then it boots through MAIN's database, counted.
- `audit.json` keeps the eighth increment's members (`sql_connects`, `redis_connects`, `sites`, `connects_since`); on a mode 1 node with CONFIG on its counts no longer include the boots from the replica. `sites` may now name `sql Cli/Commands/StatusCommand.php:<line>` (`status`, in mode 1 and 2), a key like any other. Send it as the heartbeat's `audit` as before.

### The R2 streams section on the node, and the catalogue sections (Phase 7, twelfth increment)

**The node's stream caches.** `cluster:apply` turns the `stream` records the agent stores (`replica/streams.json` and `replica/streams/<id>.json`, ninth increment) into the node's stream caches (plan, section 9, "Storage and boot"): one entry per stream the node holds, `tmp/cache/replica_streams/<id>` (`Core/Cluster/ReplicaStreamCache`), in the shapes the node's readers took from MAIN's database. The directory is 0700: an entry holds the stream's sources, which may carry an upstream's credentials.

| Entry | Shape | What no record carries (null) |
| --- | --- | --- |
| `stream` | the `streams` row (`SELECT *`) | `STREAM_LOCAL`: the workers' pids, `updated`, MAIN's catalogue metadata |
| `type` | its `streams_types` row, or null | |
| `profile` | its `profiles` row, or null | |
| `server` | the node's `streams_servers` row, or null | `STREAM_SERVER_LOCAL`: the node's runtime state (pids, status, current source, probe results, the created channel's build state) |
| `arguments` | `SELECT t1.*, t2.*` of `streams_options` ⨝ `streams_arguments`: the argument's id wins the join as `id` | `argument_description` |
| `recordings` | its `recordings` rows scheduled on the node, every column; `status` as MAIN last heard it | |
| `children` | the servers that relay it from the node | |
| `etag`, `ver` | the record's | |

`replica_streams/index` is `{streams: {id: {etag, ver, rec: [recording ids]}}, unreadable: [ids]}`: what the last apply built, and the streams whose record did not read.

**Who reads them.** `Domain/Stream/StreamSource`, the seam Phase 0 made for this, answers from the entries once the replica owns the streams (`ReplicaStreamCache::owned()`), and reads MAIN's database otherwise, as before:

| `StreamSource::` | From the entry | Its callers |
| --- | --- | --- |
| `streamRow($id, $live)` | `stream` + `type` + `profile` (null columns without one); null when not held, without a type, of the other kind, or a direct source, as the SQL join answers | `StreamProcess::startStream`, `startMovie`, `buildSupervisorSpec` |
| `serverRow($id)` | `server`, for this node only; another server's row still comes from MAIN's database | the same |
| `arguments($id, $keyed)` | `arguments`, a list or keyed by `argument_key` | the same, `MonitorCommand`, `ProxyCommand`, `ScannerCommand`, `live.php` |
| `sourceRow($id)` | `{stream_source}`, or `[]` | `live.php`'s fanout hand-off |
| `recording($id)` (new) | a `recordings` row scheduled on this node, or null: the index's streams first, then those the agent stored since the last apply | `RecordCommand`, which read it with its own query |

Once owned, none of them reads MAIN's database for this node. A stream without an entry is one the node does not hold, unless the agent stored its record after the last apply: the entry is then built from `replica/streams/<id>.json` at the first read. A stream whose record did not read at the last apply (from disk: did not verify) is not built from its file at a read: it keeps the entry it had, or has none, until the next apply reads its record. That apply, without `--from-disk` (the agent's, or `cron:cache`'s minute), trusts the agent's `.json`, as it does for every section: the boot's verification holds only until then, within a minute.

**The flow.** The section follows STREAMS (8), not CONFIG:

- **STREAMS on:** the apply writes the entry of every stream whose ETag or version changed since the last apply (every one from disk), deletes the entries of the streams whose file the agent removed, and records `streams` in `replica_owned` (the cursor). `ReplicaApply::owns('streams')` needs STREAMS, `streams.json` and that record; `ReplicaStreamCache::owned()` asks `built()` first, so MAIN and legacy nodes never read `flows.json` for it.
- **A record that does not read** (a `.json` that does not parse, another stream's record, a `server` row or a recording naming another server, a part that is not what MAIN sends, or from disk a record that does not verify) writes nothing and deletes nothing: its stream keeps its entry. Only a stream whose `.json` is gone is a removal.
- **STREAMS off (shadow):** nothing is written, ownership is dropped, and the report compares the section with MAIN's database in the record's own shape, through MAIN's own reads (`held()` and `data()` moved from `Domain/Cluster/StreamReplica` to `Core/Cluster/StreamRecords`, which the node can run). Ids and names only: `missing` (MAIN's database says the node holds them, the section lacks them), `extra`, `unreadable`, and `differ` (`<id>.<part>` or `<id>.<part>.<field>` for `stream` and `server`; never `tickets`), at most 100 ids and 100 differences. When MAIN's database does not answer: `compared: false`. It walks the ids in steps: the next 1000 streams MAIN's database says the node holds (`held()` with a range and a limit), their records' data (`data()`), and the section's records in that range, read one at a time; only the ids and names the report keeps are held, so a node holding tens of thousands of streams stays within the CLI's 512 MiB. MAIN serves the section only while STREAMS is on, so with it off the files can only age: the report measures how far they lag behind MAIN's database. An agent that follows the contract below sets its cursor to 0 while the flow is off, and the report is then `incomplete`.
- **No whole section** (a cursor of 0, a missing or unreadable `streams/`) is `incomplete`, and a file in `streams/` that names no stream is `refused`: no entry is touched and the readers take MAIN's database again.
- CONFIG's `disown()` leaves `streams` in `replica_owned`. `cron:cache` applies the section every minute while STREAMS is on, and hands it back while STREAMS is off, through `ReplicaApply::minute()`: with CONFIG on, `run(true, …, minute: true)`; with it off, `disown()` and then `streamsMinute()`, which writes no report. The minute never runs the shadow comparison (it reads MAIN's database); `apply.json` keeps the agent's last one.

**From disk.** `cluster:apply --from-disk` takes each record from `streams/<id>.rep` (`ReplicaRecords::stream`): it must open with the agent's box key, verify under its pinned panel key with tag `rep`, and name section `stream`, this node, the stream of its file name, an integer `ver`, an `etag` of 64 lowercase hex digits and a `data` object. The report's `from_disk` names `streams` among `verified` when every record did, else among `unverified`.

**Order.** Every apply takes the whole sections first, then the blocklist, then the streams. From disk each record costs about 0.4 ms (the box opened, the Ed25519 check, the entry written), so a node holding about 40,000 streams reaches `service`'s 15 s timeout. The timeout then stops the apply in the streams part: the whole sections and the bans are in place, `streams` is not recorded in `replica_owned`, and `startup`'s `cron:cache` builds the entries from the agent's files at boot (the minute's apply, which does not verify them). A shadow comparison that fails cannot keep the bans out either. `service` runs `--from-disk` only on a node whose CONFIG flow is on: a node with STREAMS on and CONFIG off boots through MAIN's database, as it does for its settings and servers, and `startup`'s `cron:cache` builds its stream caches then.

**The report.**

```text
streams  {since, streams, mode: applied, written, removed, unreadable: [ids]}
         {since, streams, mode: shadow, missing, extra, unreadable, differ}
         {since, streams, mode: shadow, compared: false, unreadable}
         {since, mode: incomplete | refused}
```

**The caches `cron:cache` builds on a node.** Each one's source, now that the replica carries them all:

| Cache | Plan | On a node, from |
| --- | --- | --- |
| `settings`, `servers`, `blocked_*` | R1, blocklist | their sections (earlier increments) |
| `proxy_servers` | blocklist section | the `servers` a process booted with: the proxies (`server_type` 1) by `server_ip` and `private_ip`. The R1 `servers` section carries them; no database read |
| `allowed_ips` | blocklist section | the `servers` and settings a process booted with: `server_ip`, `private_ip`, `whitelist_ips`, domain names that are addresses, `allowed_ips_admin`. The R1 `servers` and `settings` sections carry them; no database read |
| `allowed_domains` | blocklist section | the R1 `servers` section: every enabled server's names and addresses, and its `reseller_dns` (see *verify_host on a node that reads no database*) |
| `bouquets` | none | the new R1 `bouquets` section |
| `categories` | none | the new R1 `categories` section |
| `stream_<id>` (`STREAMS_TMP_PATH`) | R2 | nothing: `cron:cache_engine` builds it, and it is MAIN's (migration 043; the LB build strips it). Its node-side readers (player_api, the Ministra portal) take it only with `cache_complete`, which no node writes |

**The `bouquets` and `categories` sections.** Two new whole sections (`ReplicaSections::WHOLE`), served like `settings` to an agent that names them in `have`, sent whole when the ETag differs, `rep`-signed and sealed to the node, reused for 10 s on MAIN (`ReplicaEtagCache`; no event drops them: a bouquet or category save is seen within 10 s, then at the node's next poll):

- `bouquets`: `{bouquets: [row]}`, every column of every `bouquets` row (`BOUQUET_FIELDS`: the lists stay the JSON text the row holds), in `BouquetService::getAll`'s order (`bouquet_order`, 0 last, then id).
- `categories`: `{categories: [row]}`, every column of every `streams_categories` row (`CATEGORY_FIELDS`), by `cat_order`, then id.
- Typed as every section (integers as JSON integers), keys sorted. `ReplicaSectionsTest` fails on a new column of either table. A failed read throws (`503 DB`), as for every section.
- With CONFIG on, `cluster:apply` builds the `bouquets` cache through `BouquetService::fromRows` (split out of `getAll`) and the `categories` cache keyed by id, and records them in `replica_owned`. Once owned, `BouquetService::getAll` and `CategoryService::getFromDatabase` (typed or not) answer from the caches however old, even when forced, rebuilding one from the section on disk when it is gone; `cron:cache` stops writing them. In shadow the report names the bouquets and categories whose row differs from the cache `cron:cache` built (`missing`, `extra`, `differ`: ids). A section that is not a list of rows with distinct integer ids is `refused` and hands the cache back; a cache that cannot be written is `failed` (exit 3).
- In a process booted from the replica (`ReplicaBoot::active`), both readers answer the caches as they are, `[]` without one, owned or not: they never read MAIN's database there. So a mode 2 node's `cron:cache` no longer stops at the bouquets when its agent does not keep these sections.

**Size bounds on the `config` reply.** The agent reads at most 8 MiB of a reply (`MaxReply`), and one `config` reply carries every section it names. Two bounds keep a reply within it:

- **A section too large.** A whole section whose sealed record passes `ReplicaBuilder::MAX_WHOLE_BYTES` (4 MiB of base64) is answered `{"too_large": true, "etag": "<its ETag>"}` instead of the record, and audited once per ETag (`replica.section_too_large`, `{section, bytes, max}`). The agent then deletes the copy it holds (contract below): with no `.json` the replica no longer owns that cache, and the node's readers go back to MAIN's database in mode 0 and 1 rather than answering from an old copy with no end. Only an agent that names `bouquets` or `categories` in `have` gets `too_large`, for any section it names. For an older agent the section is left out, as before: it keeps what it holds.
- **The reply as a whole.** The sections sent whole are added in `ReplicaBuilder::REPLY_ORDER` (settings, servers, node, crontab, cluster, secrets, then the catalogue) while the reply's JSON stays within `ReplicaBuilder::MAX_REPLY`, 8 MiB less 64 KiB (the plan's boxed plaintext limit), counting the blocklist part first. A section that does not fit is left out of that reply. The agent keeps what it holds and names the same ETag at its next poll. The sections this reply carried are then `unchanged`, so it fits.

Without them, the bouquets of a panel with many large packages would have stopped every section from reaching the node, and two sections near the bound would have passed `MaxReply` together.

**How it differs from the plan.**

- The plan's step 3 rewrites the `tmp/cache/` files `CacheReader` and `StreamingRequestBootstrap` read. For streams, those are MAIN's `stream_<id>` routing entries, which no node builds or reads without `cache_complete`. The node's stream caches are new files in the shapes `StreamSource` returned from MAIN's database, since that is how every node-side reader gets a stream's definition.
- The stream caches follow the STREAMS flow, not CONFIG: STREAMS (Phase 5) switches before CONFIG, and it is the flow that serves the section (ninth increment).
- `stream_bundle` on a start miss is not built. An entry missing is built from the agent's file instead, which covers a record stored since the last apply.
- The plan's local `recording.state` override is not built: `StreamSource::recording` answers MAIN's `status`. The thirteenth Phase 7 increment builds it.
- Section 9 lists neither the bouquets nor the categories, and does not replicate the viewer accounts that their readers serve. Two new R1 sections carry them, so that `cron:cache` needs no database; they are small beside the tmpfs the plan protects (they were already cached there).
- The plan puts `allowed_ips`, `proxy_servers` and `allowed_domains` in the blocklist section. The first two are built from the `servers` and `settings` sections, which already carry every field they need (`whitelist_ips` since the fifth increment), so the blocklist section does not repeat them. The third comes from the `servers` section too, which also carries the resellers' DNS names for it (*verify_host on a node that reads no database*).
- The size bounds and `too_large` are not in the plan. The plan's way for large transfers (parts of at most 4 MiB, section 7) was not built for whole sections; it is now, see [Sections in parts](#sections-in-parts).

**Known limits.**

- An agent before xc_vm_fanout #32 stores neither the streams nor the `bouquets` and `categories` sections, so its node keeps reading MAIN's database for all three.
- Readers that still read MAIN's database for a stream on a node, because they also read its runtime state, which no record carries and the node does not keep locally yet (the plan's local store, and the other half of the mode-2 work): the monitor's, proxy producer's, delay's, TV archive's and thumbnails' joined `streams ⨝ streams_servers` row (`MonitorCommand`, `ProxyCommand`, `DelayCommand`, `ArchiveCommand`, `ThumbnailCommand`); the created channel's (`CreatedCommand`, `StreamProcess::createChannelItem`, `cron:vod`'s created channels); `StreamProcess::startLoopback`; `cron:streams`, `cron:vod`'s analysis queue and `QueueCommand`; the scanner's selection; `cron:cleanup`'s checks (the other mode-2 work). `ReplicaStreams::assigned()` and `archives()` give it the streams it selects, from the agent's files. They do not check ownership: a caller checks `ReplicaStreamCache::owned()` first. Since the thirteenth Phase 7 increment these readers take the node's own store (`StreamRuntime`) and the stream caches (`StreamSource`, `NodeStreams`), all but `QueueCommand`'s queue rows and the scanner's selection.
- `StreamSource`'s answers from the replica carry no runtime state: a stream starts from its first source (`current_source` null), and a created channel restarted at a position finds no `cc_info`. Since the thirteenth Phase 7 increment they carry the node's own store's once it is seeded.
- The shadow comparison reads every stream the node holds from MAIN's database at each agent `cluster:apply` while STREAMS is off, 1000 streams a step (at most ten statements); `cron:cache`'s minute never runs it. It compares files MAIN no longer updates, so it is a staleness report.
- A bouquets or categories section larger than 4 MiB sealed is not replicated (to an agent from before [Sections in parts](#sections-in-parts); a newer agent fetches it in parts). Once the agent has dropped its copy (`too_large`), `cron:cache` builds that node's cache from MAIN's database in mode 0 and 1. In mode 2, with no database, a process booted from the replica keeps the cache the last apply built. Today's agent names neither section, so nothing changes for it.
- The blocklist part is not bounded: a blocklist whose whole section alone passes 8 MiB still stops the reply, as before this increment.
- From disk, a node holding more than about 40,000 streams has its boot apply stopped by `service`'s timeout in the streams part (**Order**, above); its stream caches then come from the agent's unverified files at `startup`'s `cron:cache`.
- A record that did not verify at boot is kept out only until the next apply without `--from-disk` (the agent's, or `cron:cache`'s minute), which trusts the agent's `.json`.
- The boot rebuild of the stream caches (`--from-disk` in `service`) needs CONFIG. A node with STREAMS on and CONFIG off builds them at `startup`'s `cron:cache`, once MAIN's database answers.
- The bouquets' and categories' readers are the viewer APIs a load balancer still serves (player_api, the Ministra portal, the playlists), which also read the viewer's line from MAIN's database. In mode 2 they have no line until the data plane (Phase 8) moves those routes.

**Tests.**

- `ReplicaStreamCacheTest`: every answer `StreamSource` gives from the entries against its SQL answer for the same catalogue (same columns, same values, null only where no record carries the column), with MAIN's database gone; a stream or recording not held answers nothing; shadow with STREAMS off (missing, extra, the differing field and part, no value or ticket in the report, nothing written, MAIN's database unreachable), and walked in steps across more than two steps of held streams; the flow deciding and handing back, CONFIG's `disown()` leaving the streams, the minute's apply and the last comparison kept; `ReplicaApply::minute()` under each flow (CONFIG off and STREAMS on: applied and owned, no report; STREAMS off: handed back until the next minute; CONFIG on and STREAMS off: MAIN's database never read, the agent's comparison kept); a torn, foreign or other server's record, a `server` row of another stream, a recording scheduled elsewhere, a type, profile or children that are not MAIN's, and a record missing from disk never deleting its entry, and a removal deleting it, an entry its file built since the apply included; no cursor, a stray file and a lost directory touching nothing; from disk, the record winning over a planted `.json`, every entry rewritten from its record, records signed by another panel, for another node or stream, or corrupt refused and not built from their `.json` until the next apply; from disk, the bans in place when the streams part stops; an entry gone built from the agent's file, and written again by the next apply; a recording of a stream stored since the apply found; the directory 0700; mode 0 unchanged.
- `ReplicaCatalogTest`: both caches in the shapes and order `BouquetService::getAll` and `CategoryService::getFromDatabase` build; the readers owned (forced, typed, rebuilt from disk, MAIN's database gone) and handed back with CONFIG off; shadow naming ids; malformed sections (an id of 0 included) refused and handing back; a section the agent dropped for its size handing the cache back to MAIN's database, and taken again once sent; from disk; a process booted from the replica; mode 0.
- `ReplicaSectionsTest`: every column of `bouquets` and `streams_categories` carried, their order and types. `ReplicaBuilderSecretsTest`: neither section carries a secret, nor is built from a failed read. `ClusterApiTest`: both served by `have` and ETag, never to today's agent; a section past 4 MiB answered `too_large` with its ETag and audited once, the rest of the reply still served, the ETag then answered `unchanged`, and the section sent again once it fits; for an agent that names neither catalogue section, such a section left out; each section under the bound but together past 8 MiB with a blocklist section: the reply within `MAX_REPLY`, the section that did not fit sent at the next poll; every section placed in `REPLY_ORDER`, the catalogue last. `ReplicaRecordsTest`: a `stream` record refused when it names another stream (its own or copied under another name), section or node, or has a version that is not an integer, an ETag that is not MAIN's, or no data; none without its `.json`.
- `ReplicaBootTest`: `cluster:apply --from-disk` builds the stream caches without a connect; a mode 2 node's `cron:cache`, in a child PHP with the real bootstrap, makes not one connect (none refused either) once the replica holds every section, and writes every cache from it: settings, servers, proxies, allowed IPs, blocklist, bouquets, categories, streams.

**The agent's contract (XC_VM_Fanout, built in xc_vm_fanout #32).**

- **`have`.** `config`'s `have` may name `bouquets` and `categories`, each with the ETag held (64 lowercase hex, or `""`). Name them only once the agent stores them as below. The reply, record and files are the fifth increment's whole-section contract: `{"unchanged": true}` or `{"etag", "sealed"}`, a `rep` record `{v: 1, section, node, gen, etag, iat, data}` checked as every whole section, stored as `replica/bouquets.rep` and `replica/bouquets.json` (`{"etag", "data"}`), the same for `categories`, `.rep` written before `.json`, their ETags in `state.json`'s `whole_etags`. Today's agent names neither and gets neither.
- **Data.** `bouquets`: `{"bouquets": [{"bouquet_channels": <string|null>, "bouquet_movies": <string|null>, "bouquet_name": <string|null>, "bouquet_order": <int|null>, "bouquet_radios": <string|null>, "bouquet_series": <string|null>, "id": <int ≥ 1>}, …]}`. `categories`: `{"categories": [{"cat_order": <int|null>, "category_name": <string|null>, "category_type": <string|null>, "id": <int ≥ 1>, "is_adult": <int|null>, "parent_id": <int|null>}, …]}`. Keys sorted, rows in MAIN's order. Store as signed; PHP checks them. A later MAIN may add keys.
- **Too large.** Only to an agent that names `bouquets` or `categories` in `have`: any whole section it names may be answered `{"too_large": true, "etag": "<64 lowercase hex>"}` instead of `{"etag", "sealed"}` or `{"unchanged": true}`. Its sealed record would pass 4194304 bytes of base64, and MAIN will not send it. Then:
  - Delete `replica/<name>.rep`, then `replica/<name>.json`, if held.
  - Keep the reply's `etag` as that section's ETag in `state.json`'s `whole_etags`. MAIN then answers `{"unchanged": true}` (nothing held, nothing to do) until the section changes, and seals it again only then. A changed section comes as a record, or as `too_large` again.
  - Run `console.php cluster:apply` afterwards, as after storing (debounced). With no `.json` the replica no longer owns that cache, and the node's readers take MAIN's database again in mode 0 and 1.
  - The start-up check of the stored records (seventh increment) finds no `.rep` for such a section. Keeping the ETag and asking with `""` both work; MAIN answers `too_large` again to `""`.
  - A later MAIN may raise the bound: a section kept this way arrives at its next change.
  - An agent that names neither section never gets `too_large`: MAIN leaves such a section out, as before.
- **Left out.** A missing field for a named section still means "not served": keep what is held and name the same ETag at the next poll. That covers a whole section MAIN cannot sign without a licence, and now also one that did not fit in this reply. MAIN adds the sections sent whole in the order settings, servers, node, crontab, cluster, secrets, bouquets, categories, while the reply's JSON stays within 8323072 bytes (8 MiB less 64 KiB), counting the blocklist part first. The next poll carries the section left out, since the sections this reply carried are then `unchanged`. Asking again at once, rather than at the next poll, is allowed but not needed. Keep `MaxReply` at 8 MiB or more.
- **Streams: apply.** The ninth increment's contract stands; PHP now applies what it stores. Run `console.php cluster:apply` after storing any record or removal, debounced 1 s, and also when the STREAMS bit (8) of the `flows` written to `flows.json` changes, either way. `cron:cache` applies the section every minute while STREAMS is on, so an agent that does not delays the switch by up to a minute.
- **Streams: while the flow is off.** MAIN serves the op only while STREAMS is on, so while it is off the stored files can only age. Whenever the `flows` the agent writes to `flows.json` have the STREAMS bit off, also rewrite `streams.json` as `{"since": 0}`, before or with `flows.json`, and set `streams_since` to 0 in `state.json`. Keep every file. PHP then reads no section (`incomplete`): it neither compares an aged copy with MAIN's database nor takes it the moment the flow is on again. Once the bit is on again, the cursor 0 starts a full pass with the hashes of the files held, so MAIN resends only what changed. The pass's end writes a cursor above 0, and the `cluster:apply` after it builds the stream caches from what is current.
- **Streams: files PHP reads.** At each apply, `streams.json` (`{"since": <int>}`, above 0 once a pass completed), the names in `streams/` (only `<id>.json` besides dot files and `.rep`), and each `streams/<id>.json` (`{"etag": "<64 hex>", "ver": <int>, "data": <object>}`); at boot, each `streams/<id>.rep`. A `.json` PHP cannot read keeps its stream's last entry, so write each atomically (dot-named temporary file, then rename). A removal deletes both files. Keep `streams/` 0700 and the files 0600: PHP builds entries readable by xc_vm alone.
- **Streams: records that no longer verify.** As for the whole sections (seventh increment): when the agent starts, and after an enrolment or re-key that changed its box key or pinned panel key, open and verify every `streams/<id>.rep` with the current keys. Leave out of the next resync's `hashes` every stream whose record failed, so MAIN resends it; or set the cursor to 0 (`streams.json` included) and walk a full pass with no hashes. Otherwise MAIN answers nothing for an unchanged stream, and `--from-disk` keeps refusing the old record.
- **Report and exit codes.** `apply.json` gains `streams`, `bouquets` and `categories` parts (above); names and ids only. The exit codes are the sixth increment's: a `streams` part is never `failed`; a `bouquets` or `categories` part is `failed` (exit 3) when its cache could not be written. Log the output as for any failed run.
- **Compatibility.** Today's agent never lists `streams`, names neither new section, and runs `cluster:apply` as before: nothing changes for it. It never gets `too_large`. The reply bound only leaves out, as a licence refusal does, a section it names, and its `settings` section is far below it. MAIN's older builds do not serve the new sections, and never answer `too_large`: an agent that names them gets no field and keeps what it holds.

### The node's own store of its streams' runtime state (Phase 7, thirteenth increment)

**Before.** A node with STREAMS on reported its streams' runtime state to MAIN (`stream.state`, `stream.worker`, `recording.state`: Phase 5) and kept no copy, and the R2 stream caches (twelfth increment) carry only what MAIN decides. So every reader that takes a stream's definition and its runtime state together, from one joined row, still read MAIN's database, and `cron:cleanup` skipped its stream checks in mode 2 (tenth increment). The plan has the node own the `stream.state` fields "with a local copy" (section 8), its recordings "plus local `recording.state` override" (section 9, R2), and read no MAIN database in mode 2 (section 10).

**The store.** `Core/Cluster/StreamRuntime`, files on disk. Decided: not Redis. A load balancer runs no Redis of its own (the LB build strips `bin/redis`; in Redis mode it reaches MAIN's), and files need no server, survive the agent, PHP and a reboot alike, and are written under the same lock as the writer's event.

```text
config/cluster/runtime/          0700, the owner of config/cluster/ (xc_vm)
  .lock                          flock: the writers, the seed, the lapse, the pruning and resend() take it
  seeded                         {"at": <unix>, "server_id": <sid>, "streams": <int>}: the store is whole
  generation                     <int>: every kept write and every lapse bumps it (the seed compares it)
  unsent                         {"at": <unix>, "token": <hex>}: an entry holds columns the agent did not take
  streams/<id>.json              {"id": <id>, "ssid": <server_stream_id>|null,
                                  "fields": {<column>: <value>}, "unsent": [<column>…]}, 0600
  recordings/<id>.json           {"id": <id>, "status": <int>}, 0600
  .<name>.<pid>.<rand>.tmp       a write in progress (readers skip dot files)
```

- **What it keeps.** Per stream, the columns the node writes: `StreamStateWriter::STATE_FIELDS` (`pid`, `monitor_pid`, `delay_pid`, `stream_status`, `stream_started`, `to_analyze`, `delay_available_at`, `stream_info`, `progress_info`, `current_source`, `bitrate`, `audio_codec`, `video_codec`, `resolution`, `compatible`, `cc_info`, `cchannel_rsources`, `ondemand_check`) and its workers' pids on the `streams` row (`tv_archive_pid`, `vframes_pid`). Per recording, the status the node set last. The current source is redacted (`Redactor`), as the `stream.state` event carries it and MAIN's row holds it with STREAMS on.
- **On disk, not `tmp/`** (a tmpfs). A reboot keeps what MAIN's row kept: `cron:streams` restarts the streams that ran, and a created channel keeps what it built. The pids are stale after a reboot, as they were in MAIN's row; every reader checks a pid against the process it names.
- **Crash-safe.** Each file is written aside, flushed (`fdatasync`) and renamed in, so a reader sees the old file or the new one. A file that does not read is no entry. The write goes through `Core\Util\AtomicFile`: a hidden temp name unique per writer, created exclusively, removed when the write fails.
- **Bounded.** An entry only for the streams and recordings the node holds: `cron:cleanup` prunes the others from its whole section, never one written in the last 10 minutes (`PRUNE_GRACE`, a stream assigned since the list was read). At most 100,000 entries of each kind (`MAX_STREAMS`, `MAX_RECORDINGS`), each value at most 16 MiB − 1 (`MAX_VALUE`, MAIN's `mediumtext`). A write past a bound is not kept, and a node whose rows on MAIN hold more streams with state than `MAX_STREAMS` is not seeded (logged): its readers keep MAIN's database.
- **The node's user.** Written as the owner of the agent's directory. A root process does the file work as that user (`SettingsAudit::asAgentUser`) and keeps nothing when it cannot switch; no root process writes stream state today.

**Who writes it.** While the STREAMS flow is on, each writer keeps what it reports, under the store's lock together with its event, and bumps the store's `generation`, so a seed that read MAIN's rows before it copies nothing (below):

| Writer | Kept | Its event (unchanged) |
| --- | --- | --- |
| `StreamStateWriter::update()`, `updateRow()` | the row's columns | `stream.state` |
| `ContentSink::workerPid()` | `tv_archive_pid` or `vframes_pid` | `stream.worker` |
| `ContentSink::recordingState()`, `recordingDone()` | the recording's status, whatever CONTENT says | `recording.state` (CONTENT) |
| `ContentSink::recordingDone()` from `RecordCommand`, once the VOD's file is converted | the VOD's row as MAIN attaches it to the node (`RecordingFinalizer::finish`: `pid` 1, `to_analyze` 1) | none: MAIN inserts that row itself, from the `recording.state` (or the node's direct call without CONTENT) |
| `StreamProcess::reconcileSupervised()`, on a node whose agent follows the fanout's feed (`fanout_events`) and whose readers take the store | the supervised streams' state | none: the agent's `stream.monitor` carries it |

- An update by `server_stream_id` names its stream through the rows this process read (the readers remember each) or the stream caches' index, which now records each stream's `ssid`.
- Another server's row is never kept. A write for this node that is not kept (an unknown `server_stream_id`, a bound, the store out of reach) is logged, and lapses the store (below) on a node that may still seed it.
- With STREAMS off, a write goes to MAIN's row alone and lapses the store once it landed (**superseded** for mode 2 by [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05): a node in mode 2 does not lapse its store on a write); a recording's status written then drops the node's own (under the store's lock). MAIN and mode 0 write exactly the statements they wrote; the writers only look for the store's directory (or the recording's file) first, and a node without one takes no lock.
- **Mode 2 without the agent.** A write the spool refuses (the agent silent for over two minutes) fell back to MAIN's row, which mode 2 refuses with an exception in the writer's process. In mode 2 the writer now returns false instead: the store keeps it, its columns marked `unsent` (the `unsent` marker written before the entry). `StreamStateWriter::resend()`, which `cron:streams` runs first every minute whether or not the store answers the readers (a seed waits for it), sends each entry's unsent columns once the agent takes events again: a `stream.state` keyed `{stream_id, server_id}` with the redacted columns, and a `stream.worker` per worker pid. The marker goes only after a walk that left nothing unsent and that no write marked again since it began (its `token`). A recording's status in mode 2 is kept but not resent. A movie's analysis (`vod.analysis`) the spool refuses in mode 2 is not written anywhere: `ContentSink::movieProperties()` returns false, and `cron:vod` and `cron:cleanup` leave the movie due, to analyse it again once the agent is back.
- **Mode 0 and 1 without the agent** still fall back to MAIN's row, and mark nothing unsent: MAIN's row has it, and resending it later would replay it over whatever MAIN wrote to that row since. The store keeps the write but lapses once MAIN's row has it (MAIN's row alone is then whole): the readers take MAIN's database until the agent is back and a seed runs.

**Whole before it is read.** A node that switches STREAMS on has run its streams with MAIN's row, and the store holds only what was written since. Readers take the store only once `seeded` names this server (`StreamRuntime::ready()`):

- **The seed.** A CLI process of a node that may reach MAIN's database (mode 1: never mode 2, never a php-fpm request) seeds the store the first time a reader asks. Only while the agent takes events (`flows.json` touched in the last 120 s), the P0 spool is empty (every event the node spooled before is applied on MAIN) and nothing is `unsent` (MAIN has not heard it), it reads from MAIN's database this node's `streams_servers` runtime columns and the pids of the workers it runs, outside the store's lock, so a slow or unreachable MAIN database holds up no writer. Then, under the writers' lock (waited for at most 2 s), and only if the store's `generation` has not moved since before the read (no write kept, no lapse), nothing is pending or unsent again, and MAIN's rows hold at most `MAX_STREAMS` streams with state, it writes an entry for each row with state (a row whose columns all hold their default needs none), removes the entries MAIN's rows lack, flushes the directory (`sync -f`, from an argv list with no shell through `Core/Process/ProcessRunner`, its errors to `/dev/null` as before) and writes `seeded`. One counted connect, at `Core/Cluster/StreamRuntime.php`. A process whose seed failed does not try again for 30 s (`SEED_RETRY`), so a reader never connects on each call. Recordings are not copied: the record carries MAIN's status.
- **A lapse.** A write the store did not follow (STREAMS off, a fallback to MAIN's row in mode 0 or 1, or a write it missed) removes `seeded` and bumps the `generation`, under the store's lock and once that write landed on MAIN: a seed that read MAIN's rows before it either sees the generation move or loses its marker here. The next CLI reader in mode 1 seeds again; until then the readers take MAIN's database.
- A node in mode 2 cannot seed: a store that lapsed there, or was lost, leaves its readers on MAIN's database, which refuses them, as before this increment.

**Who reads it.** `StreamSource::local()`: the replica owns the streams (`ReplicaStreamCache::owned()`) and the store is ready. Then each reader below answers from the stream caches (the definition) and the store (the runtime state), in the shape MAIN's row had, and reads none of MAIN's database. Otherwise it runs the statement it ran, moved verbatim into its seam (or kept in place in the three streaming endpoints), so MAIN and mode 0 ask MAIN's database exactly what they asked.

| Reader | From the replica and the store | What it read from MAIN's database |
| --- | --- | --- |
| `MonitorCommand`, `ProxyCommand`, `DelayCommand` | `StreamSource::nodeRow()` | `streams ⨝ streams_servers` for this node |
| `ArchiveCommand`, `ThumbnailCommand` | `workerRow('tv_archive')`, `workerRow('vframes')` | the same row, where this node records the archive (with a duration) or the thumbnails |
| `CreatedCommand` | `createdRow()`, `builtServerRow()` | `streams ⟕ profiles`; this node's row without a parent |
| `StreamProcess::createChannelItem` | `channelRow()` and this node's row | `streams ⨝ streams_types` (type 3) `⟕ profiles` |
| `StreamProcess::startLoopback` | `plainRow()` and this node's row | the `streams` row |
| `StreamProcess::stopStream` (the pids without a pid file), `killPhpMonitor`, `reconcileSupervised` | the store | `pid`, `monitor_pid`, the supervised state columns |
| `cron:streams` | `NodeStreams::liveChecks()`, `proxied()`, `onDemandIDs()` | its live checks' join, the direct proxies with a pid, the on-demand streams |
| `cron:vod` | `NodeStreams::createdChannels()`, `recordingsDue()`, `analysisCount()`, `analysis()` | the created channels to build, the recordings due, the analysis queue (1000 a page) |
| `cron:cleanup` | `NodeStreams::fileStreams()`, `archives()`, `createdIDs()`, `vodChecks()`, `builtChannels()` | its five lists |
| the on-demand daemon (`ondemand`) | `NodeStreams::activeOnDemand()`, `attached()`, `viewers()` | `ConnectionTracker::activeOnDemandStreamIDs`, `attachedRestreamCounts`, the viewer counts |
| `RecordCommand` | `StreamSource::recording()`: the status the node set last wins over the record's | the `recordings` row |
| the RTMP callback (`rtmp.php`), `/admin/live`, `/admin/timeshift` | `StreamSource::joined()` | their joined row, kept in place for mode 0 |
| `/admin/vod` | `StreamSource::movieRow()` | its movie or episode with a pid |

- A stream with no entry holds MAIN's column defaults: 0 for `stream_status`, `to_analyze` and `compatible`, `[]` for a created channel's `cchannel_rsources` and `pids_create_channel` (as MAIN inserts its row; no writer sets `pids_create_channel` to anything else), null otherwise. `updated` and `aes_pid` are null, as MAIN's catalogue metadata is (twelfth increment).
- `streamRow()` and `serverRow()` carry the store's runtime state once it is ready: a created channel restarted at a position finds its `cc_info` (the twelfth increment's limit), and a stream restarts from its current source when that source carries no credentials. The current source is kept redacted, as MAIN's row holds it with STREAMS on, so one whose credentials the `Redactor` masks is not found in the stream's source list, and the stream starts from its first source, as it did from MAIN's row.
- **The lists filter by the index first.** `liveChecks()`, `proxied()`, `onDemandIDs()`, `activeOnDemand()`, `createdChannels()` and the analysis walk every stream the node holds or keeps state for. The stream caches' index now carries, per stream, what they filter on (`ReplicaStreamCache::meta()`: `od` its `on_demand` on the node, `type`, `live` its type's flag, `ds` and `dp` its `direct_source` and `direct_proxy`, the last apply's, as the entries are), so a list reads a stream's cache entry and its state only when the index does not rule it out; a stream the index lacks (its record stored since the apply) is read in full. `analysisCount()` and `analysis()` take the store's `to_analyze` first, and `cron:vod` finds a run's streams due once, at its first page. Measured on 20,000 movies: the on-demand daemon's pass (every 0.8 s) 0.04 s instead of 0.7 s, `liveChecks()` 0.03 s instead of 0.7 s.
- **What the node cannot know stays conservative.** The servers relaying a stream with a running feed (`attached`) are the servers configured to relay it from this node (its record's `children`): an on-demand stream they relay is never stopped for want of viewers. A stream's viewers, with CONNECTIONS on, come from the agent's registry: one open viewer is enough, as the callers only ask whether there is any, and a stream the agent does not answer for counts as watched. `cron:streams` and the on-demand daemon then leave MAIN's Redis alone in Redis mode, and a direct proxy's viewer sockets are checked against the registry. Without CONNECTIONS the counts come from MAIN's `lines_live`, or its Redis, as before.
- The daemons (`monitor`, `proxy`, `created`, `record`) reconnect MAIN's database after a long wait only where the replica does not answer; the relay endpoints no longer open it at once there (and `/admin/live` no longer reconnects before it starts a monitor); `cron:vod` queues no channel on MAIN's `queue` in mode 2; the on-demand daemon sends MAIN no `update_stream` cache signal where the store answers, and `StreamProcess::updateStreams()` sends none with STREAMS on, as `updateStream()` already did: MAIN refreshes a stream's cache from the node's events.

**`cron:cleanup` in mode 1 and 2.** `CleanupCronJob::streamChecks()` holds wherever `StreamSource::local()` does, mode 2 included (the tenth increment's seam). The checks then read the whole R2 section as the agent stored it (`ReplicaStreams`): the files of the streams it does not hold go, its TV archives keep their retention, and the files of created channels it does not hold go; the VOD check takes the carried columns from the record and the pid and status from the store; the created-channel check compares the store's `cchannel_rsources` with the record's sources. A check whose list the section cannot give whole (no cursor, a file that does not read) is skipped, never run against a partial list. The cron then prunes the store to the streams and recordings the section holds.

**How it differs from the plan.**

- The plan puts the copy in `var/agent/streams/<id>.json`. It is PHP's, in the agent's directory (`config/cluster/`, the plan's `var/agent/`) under `runtime/`, one file per stream and per recording; the agent never reads it.
- The plan has the node own the state from Phase 5 and says nothing of the switch. The seed from MAIN's rows in mode 1, the lapse, and a node in mode 2 that cannot seed are this increment's.
- The local `recording.state` override the twelfth increment left out is built.
- `attached` from the configured children, and the viewers from the registry's `find`, are not in the plan: the relay tickets (Phase 8) would tell a parent its relays, and the plan's `GET /v1/conn/counts` exact counts; neither is built. The counts are built later, as `POST /v1/conn/counts {"stream_ids": [...]}` (a POST, as `find` and `oldest` are, and so that an older agent answers it 405 rather than as a connection named `counts`): the open viewers (`hls_end` 0) of up to 10,000 streams in one call, `{"counts": {"<id>": <n>}}`. `NodeStreams::viewers()` asks it first (`AgentConnections::counts`, in parts of 10,000), and asks per stream as before when the agent does not answer it. The callers now get exact counts where they got 0 or 1; they only compare with 0. `StreamRuntimeReadersTest::testViewersComeFromTheAgentsRegistryWithConnectionsOn`, and on the agent's side `TestRegistryCountsEachStreamsOpenViewers` and `TestInteropConnections` (PHP's call against the real agent).
- The plan's journal keeps what the node could not send in the agent. In mode 2 the store keeps what the spool refused, and `resend()` sends it.

**Known limits.**

- With an agent before xc_vm_fanout #32, which does not store the R2 section, no node's replica owns its streams and the readers keep MAIN's database everywhere. With STREAMS on the writers keep the store anyway, one entry per stream written, which nothing reads or prunes until then.
- Still MAIN's database with STREAMS on: the encoding queue's rows (`QueueCommand`, `StreamProcess::queueMovie()` and `queueChannel()`: MAIN's `queue`, which the plan's `queue_claim`, `queue_update` and `queue_enqueue` ops will carry; none is built, and in mode 2 the queue daemon is refused and `cron:vod` adds nothing to it); the scanner's selection and its `ondemand_check` rows (MAIN's table); `cron:servers`' count of running streams (after its `lines_live` counts); `stopMovie()`'s `delete_vod` cache signal; the viewer counts without CONNECTIONS; `/admin/thumb`, which reads no runtime state but redirects to the server recording the thumbnails, which the replica cannot name for a stream the node does not hold; and viewer routing (`StreamRedirector`, Phase 8).
- MAIN's own writes to a node's runtime columns do not reach its store: a channel saved with re-encode (its `cchannel_rsources` reset), the *Recreate channels* and *symlink all movies/episodes* tools' resets, and the *Rescan VOD* tool (`rescan_vod`: `to_analyze` 1, and a `pid`, on every movie's and episode's row), which does nothing on a node whose store answers. The node then rebuilds only the sources it lacks, a movie is not queued again, and no movie is analysed again. That needs a MAIN → node command (the plan's `stream.*` and `queue.poke`), not built. The one such write the node itself follows is a finished recording's VOD, which MAIN attaches to the node (`RecordingFinalizer::finish`): the recorder keeps that row's state as it reports the recording done, so `cron:vod` analyses the VOD and the VOD relay serves it.
- The seed needs the agent alive, the P0 spool empty and nothing unsent; until then the readers keep MAIN's database. A php-fpm request never seeds: the relay endpoints read MAIN's database (mode 1) until a CLI process has. A mode 1 node whose agent stops has its store lapse at its first write (it writes MAIN's row), and its readers take MAIN's database until the agent is back.
- Past `MAX_STREAMS`, a write for a stream without an entry is not kept: in mode 1 the store lapses and the seed then refuses; in mode 2 it is only logged, and the readers miss that write.
- The index-first lists still read the store's directory and the index on each call: the on-demand daemon's pass costs about 0.04 s at 20,000 movies (one indexed query before).
- A mode 2 node whose store lapsed (STREAMS off and on again in mode 2) or was lost has its readers refused until it is back in mode 1 for a seed.
- An on-demand stream that other servers relay is never stopped for want of viewers once the store answers.
- One `fdatasync` per write, under one lock: `cron:streams` writes each running stream once a minute. The seed writes every entry, then flushes once.
- A process reads the flows at most 5 s old (`NodeFlows`): a write in the seconds after STREAMS goes off can still be kept rather than lapse the store; the next write with STREAMS off lapses it. A write in the seconds after STREAMS goes on can still go to MAIN's row alone: it lapses the store once it landed, so a seed running meanwhile is not left marked whole.

**Tests.**

- `StreamRuntimeTest`: the writers keep what they send, the current source redacted, by stream id, by a `server_stream_id` a row read named and by one the stream caches' index names, with the workers' pids, a recording's status, and a finished recording's status and VOD row without an event; another server's row never kept, an unknown row lapsing the store; every runtime column with MAIN's default; atomic writes, a leftover temporary file and a file that does not read never an entry; the bounds (entries, a value, recordings, and a seed past `MAX_STREAMS`) and the pruning, never an entry written in the last minutes; 0700 and 0600, the agent directory's owner; the seed only once the agent delivered everything, nothing is unsent, and while it takes events, copying MAIN's rows (redacted, the workers this node runs, not another server's), dropping entries MAIN lacks, and seeding once; a seed during which a write was kept or the store lapsed copying nothing; a process whose seed failed not trying again at once; `ready()` never seeding in mode 2 or without STREAMS; a write with STREAMS off going to MAIN's row and lapsing the store only once it landed, a recording's status dropping the node's own; mode 1 without the agent writing MAIN's row (answering what it answered), lapsing the store after, and leaving nothing to resend; MAIN and mode 0 writing exactly as before and keeping nothing; mode 2 keeping what the agent did not take without a statement, and `resend()` sending it once, only once the agent is back, its marker outliving a walk that left something unsent or that a write marked again; mode 2 writing no movie analysis without the agent; the seed's one flush (`[sync, -f, <the store>]`, quiet) before its marker, every entry written by then, and a failed flush not stopping it.
- `StreamRuntimeReadersTest`: every reader above against its SQL answer for the same catalogue, with a row for each filter a reader applies (a live stream due for analysis without a pid, an archive with no days kept, a movie without a producer, a channel half built, a channel relayed from a parent, a direct source not proxied), with a database that refuses every connect and counts the attempts: the same keys and values (the current source sanitised, catalogue metadata null) and no connect, in mode 1 and mode 2, and again with an index that lacks the lists' filter columns; the readers following what the node writes since (a stream started, by a `server_stream_id` only the index names, a worker's pid, a recording the node started not due again); a recording finished here analysed and served once MAIN attached its VOD; `StreamProcess`'s pid and monitor lookups from the store (a stand-in monitor stopped) and no cache signal with STREAMS on; the supervisor reconcile keeping the store without an event; without a seeded store (events pending; mode 2) the readers keep MAIN's database; a stand-in agent answering the registry's `find` for the viewers, and no agent counting as watched; mode 0's statements byte for byte; the converted readers' files no longer holding the joined query.
- `ModeTwoPathsTest`, each in a child PHP booted for real from the replica in mode 2 with every flow on: `cron:cleanup` pruning the files of the streams it does not hold and its archive past retention, marking a movie whose file is gone and a channel whose list is gone in the store and the spool, and pruning the store but for what was kept in the last minutes; `cron:cleanup` with a record that does not read deleting nothing and pruning nothing; `cron:streams` (with a `ps` stand-in) sending what the store kept unsent and removing the marker; `cron:vod` analysing a movie, not queueing a channel, and not starting again a recording the node started; `/admin/vod` serving a movie with a producer and nothing else; none of them making a connect.
- The suite points `StreamRuntime` at a per-run directory (`tests/bootstrap.php`), so a test that writes stream state without its own never touches an install's `config/cluster/runtime/` on the machine.

**The agent's contract (XC_VM_Fanout).** No change is needed, and today's agent is unaffected:

- No new op, lane, event type, field or exit code. `stream.state`, `stream.worker` and `recording.state` keep their shapes. A node may now send, once the agent takes events again (`flows.json` touched in the last 120 s), a `stream.state` keyed `{"stream_id": <int>, "server_id": <sid>}` with the redacted columns of a stream it kept while it was in mode 2 and the agent took no event (the node may be in mode 1 by then), and a `stream.worker` `{"stream_id", "worker", "pid"}` per worker pid kept then, on P0 like any; MAIN merges them as any. A node in mode 0 or 1 resends nothing: it wrote MAIN's row itself.
- A finished recording's VOD row the node now keeps travels in no event: MAIN still attaches it (`RecordingFinalizer::finish`) from the unchanged `recording.state` `{"id", "status": 2}`.
- `config/cluster/runtime/` is PHP's, `generation` and the `unsent` marker's `token` included: the agent must neither read, move nor delete it (a reinstall that wipes it makes a mode 1 node seed again from MAIN, a mode 2 node's readers refused until then).
- The seed waits while `config/cluster/spool/p0/` holds any `*.ndjson` file, and while `flows.json` is older than 120 s: an agent that kept P0 files after MAIN applied them would delay it (today's agent deletes them). It checks P0 again under the store's lock after reading MAIN's rows.
- With CONNECTIONS on, `cron:streams` and the on-demand daemon ask the registry `POST /v1/conn/counts` for the on-demand streams they may stop, and, when an agent does not answer it, `POST /v1/conn/find` `{"match": {"stream_id": <int>, "hls_end": 0}}` per stream (200: watched; 404: none; no answer: watched), and `cron:streams` `GET /v1/conn/<uuid>` for each viewer socket of a direct proxy (404: gone). Both endpoints exist (sixth Phase 6 increment); `find` matches every column by its printed value.
- The twelfth increment's streams contract stands: once an agent stores the section and PHP applies it, the node's readers take the store.

### The signals daemon and cron:certbot in mode 2 (Phase 7, fourteenth increment)

The tenth increment left two paths a node in mode 2 took to MAIN's database: the signals daemon and the certificate work. This increment (the fourteenth: the twelfth and thirteenth, the R2 `streams` section on the node and the node's own store of its streams' runtime state, are built beside it) moves both off it (plan, section 10). Each check on the node is `NodeRole::refusesConnects()` (mode 2, active or quarantined, by `flows.json`, never on MAIN's build), so MAIN's own paths, mode 0 and mode 1 run as before.

**The signals daemon.** `SignalsCommand::readsMainDatabase()` is false on a node in mode 2. A pass there:

| What | MAIN, mode 0, mode 1 (unchanged) | Mode 2 |
| --- | --- | --- |
| the pass's condition | MAIN's database answers a ping | none |
| Redis (`redis_handler`) | set up, and checked each pass | neither |
| kills (`signals` rows with a `pid`, Redis `SIGNALS#<sid>`) | read, run, deleted | none read: MAIN sends `conn.kill_worker` and `conn.drop` (Phases 4 and 6), which the agent and `cluster:exec` run |
| cache jobs (`signals` rows with `cache` 1) | read, run, deleted | none read: MAIN sends `node.cache` (below), which `cluster:exec` runs, and the node runs its own where they are queued |
| the settings and servers refresh, the fanout reconcile, MAIN's liveness loop | as before | as before |

The pass ends as before, 250 ms after its work, and the next process runs the next one, so a mode switch takes effect at the next pass.

**The cache jobs.** What a `signals` row with `cache` 1 asks of a node. `Core/Cluster/CacheJobs::run()` runs them as the daemon's loop did (its body, moved there unchanged but for the shell, below):

| Job | Queued by | On the node |
| --- | --- | --- |
| `delete_con {uuid}` | MAIN's `cron:users`, for connections it closed on another server (Redis mode, and the `max_connections` cut) | removes `tmp/opened_cons/<uuid>`: the viewer's HLS segments and token reuse end there |
| `drop_con {uuid}` | `ConnectionTracker::dropDaemonViewer`, for a node that takes no `conn.drop` | the fanout drops the viewer |
| `delete_vod {id}`, `delete_vods {id: [ids]}` | a movie or episode removed from a server, a stream delete, `tools`; a node for itself when it stops a movie (`StreamProcess::stopMovie`) | removes `content/vod/<id>.*` |
| `update_stream(s)`, `update_line(s)` | MAIN for itself; a node for MAIN (the on-demand daemon) or for itself (the Ministra portal) | `cron:cache_engine`, which only MAIN's build has |

- **MAIN's jobs for a node in mode 2.** `SignalDispatcher::cache()` and `cacheBatch()` ask `ClusterRoute::cache()` first, on MAIN's build, as kills ask `ClusterRoute::kill()`. For a node whose `cluster_nodes.mode` is 2 and that takes commands (`CommandBus::accepts`: `active`, COMMANDS on), the jobs go as `node.cache {jobs}` commands, and no row is written. Only jobs in `CacheJobs::job()`'s form are sent; a call left with none sends nothing and answers false. A command names at most 500 targets (`CacheJobs::MAX`; `CacheJobs::targets()`: an `id` or a `uuid` counts one, a list of ids counts each), and `CacheJobs::commands()` fills commands in order: a `cron:users` batch may be several, and a list of ids longer than what is left of a command goes on in the next one as a job of the same type. So one `cluster:exec`, which the agent gives a minute, never runs more than 500 deletes (each an `rm` of about 2.6 ms when this bound was set: a mass delete from one server was one `delete_vods` job with every id, and past about 21,000 of them the minute ran out, the rest of the files stayed and nothing retried), and a command stays tens of kilobytes, so a `commands` reply (50 commands at most) stays under the agent's 8 MiB cap. MAIN's own jobs, and nodes in mode 0 or 1 or without COMMANDS, keep the row their daemon reads (a node in mode 1 still reads rows, and its PHP may predate `node.cache`).
- **The form.** `CacheJobs::job()`: `type` one of the eight above, then `id` (an integer ≥ 1, or for the plural types a non-empty list of them) or `uuid` (1 to 64 of `[A-Za-z0-9_-]`), nothing else. Ids given as digits become integers, and a list keeps those of its ids that are; anything else is no job.
- **A node's own jobs.** On a node in mode 2 its own row would be refused, and its daemon reads none. `SignalDispatcher` runs a job queued for `SERVER_ID` there at once (`CacheJobs::onNode`, the form above) and answers true, as the daemon would have a moment later: `StreamProcess::stopMovie` now deletes the movie's files instead of meeting the refusal. MAIN's cache rebuilds (`update_*`) are left out: no node's build runs `cron:cache_engine`. Jobs a node in mode 2 queues for another server are rows, refused as before (below).
- **`cluster:exec`.** It runs a `node.cache` only when `args.jobs` is a list of 1 to 500 jobs, each exactly in `CacheJobs::job()`'s form (keys in any order), naming at most 500 targets in all, and refuses the whole command otherwise, before a job runs (exit 2, `cluster:exec: bad cache jobs`). It runs them with `CacheJobs::run()` and prints `{"result": true, "jobs": <n>}` (exit 0). `cluster:exec --types` lists `node.cache`.
- The command lives 24 h (`CommandBus::TTL`), as long as MAIN's `cron:servers` keeps a `signals` row, and has no dedupe key. The extension classes a command by its type, and lists `node.cache` as granting (`class` G).
- **No shell** (upstream's Semgrep blocks a new `php.lang.security.exec-use.exec-use` finding, which these calls raised). A delete starts no process: `glob()` of `content/vod/<id>.*` and `unlink()` of each match but a directory, what `rm` without `-r` removed (`1.*` never matches `10.mp4`), silently. The rebuilds, `cron:certbot`'s reloads (below) and the store's flush (thirteenth increment) start from argv lists through `Core/Process/ProcessRunner::run()`: `proc_open` with no shell, stdin `/dev/null`, stdout read to its end and dropped, stderr where it went before, waited for as `shell_exec()` did; a rebuild is `[PHP_BIN, <MAIN_HOME>console.php, cron:cache_engine, streams_update | lines_update, <ids joined by commas>]`, only ids in `CacheJobs::job()`'s form, as integers (none left, nothing starts). Its one `proc_open` carries `// nosemgrep: php.lang.security.exec-use.exec-use`, the reason on the line above.

**The certificate.** `cron:certbot` on a node in mode 2:

| What | MAIN, mode 0, mode 1 (unchanged) | Mode 2 |
| --- | --- | --- |
| MAIN's panel logs (`panel_logs`, for the log service) | read, sent, marked (`DiagnosticsService::submitPanelLogs`) | not read: MAIN's own run sends them |
| its certificate due within 7 days | `certbot_generate` for its names, a `signals` row for its root (`NodeActions`) | nothing queued: MAIN sends the renewal (below) |
| the record nginx's certificate is compared with | its `servers.certbot_ssl` | its copy of what it reported (`NodeStateSink::reported`) |
| a certificate that differs from it, or nginx back on `server.crt` | `node.state` (TELEMETRY) or the row; `reload_nginx` as a `signals` row | `node.state`; nginx reloaded here |
| the same certificate, at the daily run | nothing | `node.state` again (below); no reload |

- **The node's copy.** `NodeStateSink::state()` on a node in mode 2 keeps the fields in `NodeStateSink::KEPT` (`certbot_ssl`) once the spool took their `node.state`, in `config/cluster/node_state.json` (`{"certbot_ssl": "<the value sent>"}`). P0 is never dropped, so MAIN got that value, but it may have cleared it since: the admin's regenerate sets `certbot_ssl` to NULL before it sends `certbot_generate` (`post.php`), and that command may never run on the node (a quarantined node gets a `signals` row it never reads, an unlicensed MAIN sends nothing, and a node offline for more than a day lets it expire). MAIN renews only from its record and skips a node with none, so the node's copy alone would keep a node that reports only on a change silent for good, and its certificate would expire unrenewed. So the daily run (not `cron:certbot 1`) in mode 2 sends nginx's certificate as `node.state` even when the copy matches it, and reloads nginx only when it changed (`Reported ssl configuration to MAIN`): MAIN has its record back within a day. `NodeStateSink::forget()` drops the copy, in mode 2 only: the certbot command does at its start, for the admin's regenerate. It does for MAIN's renewal too, which keeps its record: when certbot then writes no certificate or says it is not due (`error` 0 or 1), the node points nginx at the newest certificate it holds for the names, reports it and reloads its services, where mode 0 and 1 leave it as it is (a harmless repair; the `cron:certbot 1` the command starts reports and reloads in every mode). The file is written aside and renamed in, and read, as the agent's user (`SettingsAudit::asAgentUser`), since root's certbot command writes it too. A `node.state` the spool refuses (the agent stopped, TELEMETRY off) keeps nothing, so the next run reports again; a node new to mode 2 has no copy, so its first run reports its certificate and reloads nginx once.
- **The reload.** No `signals` row reaches root in mode 2, and nginx runs as xc_vm (`service`), so `cron:certbot` reloads it itself, as the `reload_nginx` RPC does (`nginx_rtmp -s reload`, then `nginx -s reload`, no `sudo`), after root's system log line (`RELOAD`, `NGINX services reloaded on request.`) through `LogSink::syslog()`. It does the same as root, in the `cron:certbot 1` the certbot command starts, as root's `reload_nginx` action does.
- **The renewal.** MAIN's daily `cron:certbot`, with `cluster_api_enabled`, also runs `Domain/Cluster/NodeCertbot::renewDue()`. For every `active` node in mode 2 whose row has `enable_https`, a `certbot_ssl` (what it reported) whose `expiration` is less than 7 days away, or missing as the node's own rule has it, and a name in `domain_name` (addresses and blanks are left out), it sends `node.root {action: "certbot_generate", domain: [names]}` through `ClusterRoute::root` (COMMANDS on and root's pin, `root_ready`). A node that does not take it gets nothing, not even a row. Root runs it as any `node.root`: the certbot command, in the background. The node itself prints `Certificate due for renewal.` and `MAIN sends the renewal.`, and queues nothing.
- **The `certbot` command.** When certbot writes no certificate or says it is not due (`error` 0 or 1), the command read MAIN's `servers.certbot_ssl`; with none there, it pointed nginx at the newest certificate it holds for the names and reported it. In mode 2 it reads the node's copy, which it forgot at its start, so it repairs MAIN's record as it would after the admin's regenerate (and after MAIN's renewal, above). The rest needed no database: `NodeStateSink` (no row in mode 2 since the tenth increment), `service reload` and `cron:certbot 1`.

**How it differs from the plan.**

- The plan has kills arrive as commands (Phase 6) and says nothing of the cache jobs a `signals` row carried. `node.cache`, and a node running its own jobs where they are queued, are this increment's.
- The plan's cron table has `certbot` do local work and write through `node.state`. For a node in mode 2 the renewal is decided on MAIN, on the node's rule and names, and the node keeps a copy of what it reported: neither is in the plan.
- The reload runs on the node without root, since its nginx is xc_vm's.

**Known limits.**

- Mode 2 still cannot be switched on (Phase 9).
- `node.cache` is granting to today's extension, whose restrictive types are fixed. An unlicensed MAIN sends a node in mode 2 no cache job (and writes no row), so the files of movies deleted meanwhile and the connection files of viewers MAIN closed stay there. Listing `node.cache` among the extension's restrictive types (`xcvm_core`) would let it sign them without a licence.
- A node in mode 2 that takes no command (quarantined, COMMANDS off) gets its kills and cache jobs as rows it never reads; MAIN's `cron:servers` purges them after a day. So are the rows a node in mode 0 or 1 writes for it (its viewer authentication kicking a viewer on the mode 2 node). A node in mode 2 cannot write such rows for another server, nor MAIN's cache rebuilds (the on-demand daemon's `update_stream`): refused, until viewer authentication moves to MAIN (Phase 8) and R2's node half rebuilds MAIN's caches from the node's stream events.
- A renewal needs COMMANDS, root's pin and, `node.root` being granting, a licence; without them a node in mode 2 is not renewed and its certificate expires. MAIN judges by the record the node reported, so a `node.state` that has not reached MAIN (the agent stopped) leaves MAIN with the older one, and a record MAIN cleared (the admin's regenerate, its `certbot_generate` never run on the node) is back only at the node's next daily run: a renewal due meanwhile waits for MAIN's next daily run, a day at most within the week's margin.
- Each `SignalDispatcher::cache()` call is a `node.cache` command of its own, and the agent runs a node's commands one at a time, each `node.cache` in a fresh `cluster:exec` (a CLI boot from the replica). A caller that queues one job per item (a series delete: `StreamRepository::deleteStream` per episode, one `delete_vod` per server holding it) queues as many commands on each mode 2 node, and a kill, drop or awaited `node.rpc` queued after them waits until they ran, where the legacy daemon ran up to 1,000 rows a pass with kills first. Nothing is lost or reordered: they run in order within their day. Coalescing them was left out: buffering them to the end of a request would reorder them after MAIN's later commands to the node (and lose them to a request that dies first), and merging into a command not yet delivered races with its delivery. Callers that batch (`cacheBatch`, `delete_vods`) send one command per 500 targets.
- The daemon's reconcile of the fanout's supervised streams reads this node's `streams_servers` rows (`StreamProcess::reconcileSupervised`, each pass). That is R2's node half, where the thirteenth increment's store answers it; where it does not, a pass in mode 2 with streams under the fanout is refused there, and `cron:servers` starts the daemon again each minute.

**Tests.**

- `ModeTwoSignalsCertbotTest`, as `ModeTwoPathsTest` does: each path in a child PHP booted for real from the replica (a mode 2 node, every flow on), in a throwaway deploy root, with an `xcvm_core` stand-in that logs each connect, and `sudo`, `openssl`, `chown` and `crontab` stand-ins first on the child's `PATH` (the child checks they answer before it boots), as are the deploy root's nginx and PHP binaries. None opens or attempts a connect: a signals daemon pass with Redis on (one pass, which refreshes its settings and servers, ends and starts the next); `cluster:exec` running a signed `node.cache` (a connection file and a movie's files gone, the rest kept, a job's keys in any order) and refusing one with a bad job or more than 500 targets whole (the good job before it not run); a node's own jobs run where they are queued (a path given as a uuid left out, no cache rebuild started, a lone `update_stream` included); `cron:certbot` reporting nginx's certificate through the spool, keeping its copy and reloading nginx itself with root's line spooled, then at the next daily run reporting it again without a reload; a certificate due, left to MAIN; nginx back on `server.crt` restored from the copy; the certbot command's not-due path pointing nginx at the certificate it holds and reporting it, again after an earlier report. A job the node queues for another server is not run against its own files (its row's write is refused, `xcvm_core` never asked). In this process: `SignalsCommand::readsMainDatabase()` per mode and state and on MAIN's build; `NodeStateSink` keeping `certbot_ssl` in mode 2 only, only once spooled, and no other field, and forgetting it in mode 2 only.
- `ClusterApiTest`, run alone with MAIN's `SERVER_ID` 1: cache jobs for a node in mode 2 become `node.cache` commands (the form, the order, 500 targets a command, a list of ids split across two, 24 h, class G) and stay rows for a node in mode 1, with the cluster API off, for one without COMMANDS and for MAIN itself, even with a node row of its own in mode 2; `NodeCertbot::renewDue()` sends `certbot_generate` for the trimmed names of a node in mode 2 whose certificate is due within the week (a week left is not due, a second less is) and whose root takes commands, and nothing, never a row, otherwise (mode 1, no pin, not due, HTTPS off, no record, no name); MAIN's daily `cron:certbot` sends it after its own certificate's check, and its `cron:certbot 1`, a run with the cluster API off and a node's run send none.
- `CacheJobsRunTest`, `ProcessRunnerTest`: a delete removing exactly the `<id>.*` entries but a directory (not `10.mp4` for 1), silently, and starting nothing; shell syntax in a job's values (`1;touch <tmp>/pwned`, `$(…)`, backticks) running nothing, a non-integer id never an argument; the rebuilds' argv lists, and in `ModeTwoSignalsCertbotTest` the reloads', through the `ProcessRunner::useRunner()` seam; each argv element one literal argument, the program waited for with its exit status, 1 MiB of stdout read and dropped, stdin `/dev/null`, stderr kept or `/dev/null`, a missing program 127 without a warning.
- `ClusterExecCommandTest`: `--types` lists `node.cache`; malformed jobs (no list, empty, a path for a uuid, an id as text or with text, an unknown type, an extra field, 501 jobs, 501 ids in one job or across two) are refused whole, and nothing runs.

**The agent's contract (XC_VM_Fanout).** No change is needed, and today's agent is unaffected:

- **`node.cache`** is a Phase 4 command like any other, on the `commands` long-poll and acked with `ack`: `{"v": 1, "type": "node.cache", "exp", "iat", "cmd_id", "seq", "node_uuid", "gen", "dedupe_key": null, "args": {"jobs": [job, …]}}`, signed with tag `cmd`, `exp = iat + 86400`, class G (not in the extension's restrictive list, so not in a `LICENCE_INVALID` denial's `commands_sealed`). `jobs` holds 1 to 500 jobs, in the order to run them, naming at most 500 targets in all (an `id` or a `uuid` counts one, a list counts each of its ids; MAIN splits a longer list into jobs of the same type across commands), each exactly one of:
  - `{"type": "delete_con" | "drop_con", "uuid": "<1 to 64 of [A-Za-z0-9_-]>"}`
  - `{"type": "delete_vod" | "update_stream" | "update_line", "id": <integer ≥ 1>}`
  - `{"type": "delete_vods" | "update_streams" | "update_lines", "id": [<integer ≥ 1>, …]}` (not empty)

  MAIN sends it only to a node in mode 2 (`cluster_nodes.mode` 2) that is `active` with COMMANDS on. The agent verifies it as every command and hands the signed document to `console.php cluster:exec` unchanged, as it does every type it does not run itself (today's `localExec` falls through to `ExecViaPHP`). It must not run it in process: the files are PHP's. `cluster:exec` prints `{"result": true, "jobs": <n>}` and exits 0 (ack `ok` true, that output as `result`), or exits 2 with `cluster:exec: bad cache jobs` on stderr (ack `ok` false) for a job outside these forms or more than 500 targets. The 500-target bound keeps one run within the minute today's agent gives `cluster:exec` (`ExecViaPHP(…, time.Minute)`); an agent must not give `node.cache` less. `cluster:exec --types` now lists `node.cache`; nothing needs saying at hello.
- **The renewal** is the Phase 4 `node.root` it already was: `action` `certbot_generate`, `args` `{"domain": ["<name>", …]}`, handed to root's inbox by `cluster:exec`, root's result `{ok, result}`, where `ok` means the certbot command started.
- **`node.state {certbot_ssl}`** (P0) and **`log.syslog`** (`RELOAD`, P1) are the fifth Phase 5 and tenth Phase 7 increments' events; `cron:certbot` now also writes them as xc_vm, and in mode 2 its daily run sends `node.state {certbot_ssl}` even when unchanged (one P0 event a day, the same value MAIN may already hold). The agent sends them as it does today.
- **Mode 2** still needs every flow and root's pin (tenth increment): without COMMANDS no kill or cache job reaches the node, and without root's pin its certificate is not renewed.

### The cluster bus (Phase 2, first increment): wake-ups

**What it is.** The cluster bus is MAIN's own Redis instance for the cluster API (`Domain\Cluster\ClusterBus`). It runs the bundled `redis-server` with `bin/cluster_bus/cluster.conf`, and is separate from the shared Redis that the panel and legacy LBs use.
- **Access:** only a unix socket, `bin/cluster_bus/cluster.sock`, mode 0700, owned by xc_vm. There is no TCP port, and the admin commands are renamed away.
- **Persistence:** none, because nothing in it has to survive a restart.
- **Where it runs:** MAIN only. `service` and `ServiceCommand` start it, and `ServersCronJob` revives it. LB builds strip `bin/cluster_bus`.
- **Liveness checks:** it runs the same binary as the shared Redis, so `ServersCronJob` tells the two apart by process title: `redis-server unixsocket:…` for the bus, `redis-server *:6379` for the shared one. Before this, a running bus would have hidden a dead shared Redis.

**What it carries.** Wake-ups, the admission reservations (`ConnectionAdmission`, seventh Phase 6 increment), and the touches of nodes that reap their own HLS viewers (`touch:<sid>:<uuid>`, tenth Phase 6 increment):
- **`wake:<sid>`:** `CommandBus::enqueue` pushes it, and the `commands` long-poll waits on it. The long-poll used to re-read `cluster_commands` every 250 ms for up to 20 s per node. It now reads once, blocks on the bus, and reads again when woken. While it blocks it holds no MySQL connection: it closes the handle first (`waitNodeReleasing`), and `DatabaseHandler` reconnects on the next read.
- **`ack:<cmd_id>`:** `CommandBus::ack` pushes it, and `CommandBus::await` (an RPC waiting for its answer) waits on it instead of polling every 100 ms.

**How a wake works.** A wake is a one-element list with a 60 s TTL, taken with `BLPOP`:
- a wake pushed just before the waiter blocks is not lost;
- repeated wakes collapse into one;
- a stale wake costs one extra query.

**Without the bus.** If the bus is not running, or this is an LB or a test, `waitNode`/`waitAck` return null and the callers poll as before.

Nonces and the per-op semaphores came in the second increment, heartbeats with their telemetry (`cl:tel:<sid>`) in the third, the ingest permits in the fourth, and what authentication reads in the fifth, below.

### The cluster bus (Phase 2, second increment): nonces and per-op semaphores

**Nonces before.** Every authenticated request inserted its `(node, nonce)` into `cluster_nonces`, so each heartbeat, event batch and long-poll cost a MySQL write.

**Nonces now.** While the bus runs, `NonceStore::claim()` checks and adds the nonce in one Lua script:
- `nonce:<node>` is a sorted set. Each member is a nonce in hex, scored by its expiry: MAIN ms, 180 s after the claim. The script prunes expired members first. `purge()` (`cron:cluster`, every minute) prunes the sets of nodes gone quiet, and an empty set disappears.
- `nonces_since` holds the MAIN ms of the first claim this bus took. The bus holds every claim made since then.
- Neither key has a TTL. The bus's `volatile-ttl` policy evicts only keys that have one, so a nonce is never evicted while it can still be replayed.
- Nor is a claim refused at `maxmemory`. The script's first write (the prune) lets the rest of it through, and past `maxmemory` the bus evicts keys that have a TTL instead: wake-ups, reservations and touches, nearest expiry first. Only authenticated traffic adds nonces, which bounds them: about 150 bytes a nonce, so 1000 requests a second hold about 27 MB. (A fresh bus at `maxmemory` fails the script on `SET nonces_since`, and the claim is handled as without the bus.)

MySQL stays the store while the bus is out of reach: when it is not running, its socket is gone, a connect or script fails, or during the 5 s pause `ClusterBus` takes after a failed connect.

**Replay protection never gets weaker.** The bus is not persisted, and one worker can lose it while others still reach it. With LEAD = 250 ms (`NonceStore::LEAD_MS`) and TTL = 180 s:

| Case | Rule |
| --- | --- |
| A bus restarted or flushed, and lost the claims it held | A request stamped (`X-XCVM-Ts`) at or before `nonces_since` + LEAD is refused. Its nonce may have been claimed on the lost bus, since each such claim was stamped no later than its claim time + LEAD (next row). |
| A request stamped more than LEAD ahead of MAIN's clock | Also claimed in MySQL, so the rule above can leave it there. |
| A bus younger than TTL | Also claims in MySQL, where the claims from before it are. |
| MySQL took claims while a bus socket existed | It marks the second in `bin/cluster_bus/nonces.sql`, a file's mtime. While that mark is under TTL + 1 s old, the bus also claims in MySQL. |
| The bus is out of reach | A worker marks the second in `bin/cluster_bus/nonces.bus` before each claim it runs on the bus. Without the bus, a request stamped before the end of the marked second + LEAD is refused, since the bus may hold its nonce. A mark from the current or the previous second means the bus may be taking claims right now, some not marked yet, so the refusal then runs to the end of the current second + LEAD. Workers that still reach the bus keep the mark current, so a worker that cannot reach it refuses until it can. |

- **The marks** are written at most once a second, on disk beside the socket, so they survive a reboot. A mark only moves forward: a worker that read the clock in an earlier second and writes late leaves it as it is. A mark more than 1 s ahead of the clock (it stepped back) is rewritten. A claim whose mark cannot be written is refused.
- **No socket.** Without a bus socket (the bus stopped cleanly, or never ran), MySQL takes claims without the SQL mark: the next bus to start is young, and looks in MySQL anyway.
- **Clock steps.** A `nonces_since` more than 1 s ahead of MAIN's clock (the clock stepped back) starts a new history. That costs a short refusal instead of refusing everything until the clock catches up.
- **The refusal** is the usual 401 `REPLAY`: MAIN cannot tell such a request from a replay. It does know when a request stamped anew will pass, so this refusal carries `retry_after_ms` (below). A replay proper, a nonce the store already holds, carries none.
- **What it costs.** After a bus (re)start, requests stamped within 250 ms of its first claim are refused, and every claim for the next 180 s also goes to MySQL. After the bus is lost, requests are refused until the end of the second after the last one it took a claim in (up to 2 s). A worker that cannot reach a running bus refuses its requests, for up to 5 s at a time (the connect pause).
- **Unstamped values.** The re-key minute (`rekey:<uuid>`) and a used challenge (`used:chal:<uuid>`) are MAIN's own values, and the stamp rules do not apply to them. `NonceStore::claim()` always also claims them in MySQL (`cluster_nonces`, whose primary key refuses the second claim), even while the bus runs, so a bus restart or flush lets neither a used challenge nor a spent re-key minute pass again.

**Challenges.** `challenge` issues its value with `NonceStore::issue()`:
- The value goes into MySQL, as before, even while the bus runs. `GET challenge` is unauthenticated, and on the bus a flood of values would push out the reservations and wake-ups (`volatile-ttl` evicts the nearest expiry first). Challenges are rare: a re-key, or a policy fetch while fenced.
- Without the bus, the value is also marked like a claim (`nonces.sql`), so a bus that comes back records its use in MySQL too, where a worker without the bus looks.
- `consume()` finds the value in MySQL and takes it with a claim on `used:<node>`, on the bus or in MySQL, so a value is used once.

**Semaphores.** Plan section 8 gives `hello`, `conn_snapshot`, `token_rekey`, `config` and `streams` a bus semaphore of 4. `streams` has no op yet, so the other four get one (`Domain\Cluster\ClusterSemaphore`). Since the ninth Phase 7 increment `streams` holds one too (90 s):
- **The permit** is a member of `sem:<op>`, a sorted set without a TTL (never evicted), scored by the permit's expiry.
- **When.** A session op takes it after the node state and before the BOX is opened (step 10 of the order under "MAIN's API"). It gives it back in `finally` when the handler ends, however it ends. `token_rekey` takes it after its node signature, nonce and node state, and before its once-a-minute slot and the challenge, so a busy MAIN spends neither.
- **Crashes.** The permit of a holder that died expires after its lane's pool timeout: 60 s for `hello` and `token_rekey` (ctl), 90 s for `config` and `conn_snapshot` (ingest), and for `streams` since the ninth Phase 7 increment.
- **Time** is the bus's own clock (`TIME`, read inside the script). Scripts run one at a time, so each sees a time no earlier than the permits it finds. A worker's own clock, read before its script reached the bus, could lag another worker's and drop that worker's live permits as taken in the future.
- **Clock steps.** A permit expiring more than 1 s (`ClusterSemaphore::STEP_MS`) past now plus its lifetime was taken before the clock stepped back, and is dropped.
- **Without the bus,** or when the bus call fails, no permit is taken, as before.

**Wire: the busy refusal.** When all 4 of an op's permits are held, its handler does not run, and the node gets a denial like every other one: panel-signed (`den`), naming the node and the request nonce. Its fields:
- status 503;
- `reason`: `RATE_LIMITED`;
- `retry_after_ms`: int, from 1000 to 3000, drawn at random per refusal to spread a fleet out;
- `op`: string, the op refused: `hello`, `token_rekey`, `config` or `conn_snapshot`, and `streams` since the ninth Phase 7 increment.

The re-key minute keeps its 429 `RATE_LIMITED` with `retry_after_ms`. The status tells the two apart.

**Wire: `REPLAY` with a wait.** The 401 `REPLAY` denial (panel-signed `den`, naming the node and the request nonce, with `main_time_ms` as every denial has) gains one optional field:
- `retry_after_ms`: int, at least 50. It is present only when MAIN refused because it cannot vouch for the nonce yet, never for a nonce it holds. A request stamped anew (`X-XCVM-Ts`) at or after the denial's `main_time_ms` + `retry_after_ms` is past the refused range. Today it is at most about 1.4 s.

It is sent on every op that claims a nonce: the session ops, `token_rekey`, `enrol_code` and `enrol_code_status`. It has three causes, each a range of stamps MAIN refuses:
- a bus started, restarted or was flushed: stamps up to 250 ms past its first claim (`nonces_since`);
- the bus was lost: stamps before the end of the last second it took a claim in, + 250 ms;
- the MAIN worker cannot reach a bus the others still use: stamps before the end of the current second, + 250 ms.

The last two are one rule (the `nonces.bus` mark) as a worker sees it. The wait is the time from MAIN's clock to the end of the range, plus 50 ms (`NonceStore::RETRY_MARGIN_MS`).

**What the agent did before xc_vm_fanout #30.**
- `token_rekey`: `recover()` already waits `max(1 s, retry_after_ms)`, ±10 %, on `RATE_LIMITED`, without raising its backoff.
- `hello`: `Start` is called in three places. At start, `Run` backs off 2 s, doubling up to 1 minute, as on any error. After a re-key, the heartbeat loop calls `Start` once and drops a non-fatal error, so that hello is lost until the next re-key or policy change. On a newer `policy_ver`, a failed `Start` is only logged, and the next heartbeat (2 s) tries again.
- `config`: `SyncReplica` logs the error, and the next poll comes a minute later.
- `conn_snapshot`: the snapshot is abandoned. MAIN asks again if the digest still disagrees.
- `REPLAY`: `retry_after_ms` is ignored. A heartbeat is retried at the next tick (2 s), an event batch after the lane's backoff, a long-poll after 1 s. The clock offset is not updated from a denial, so an agent with no offset yet (the first hello after it starts) lags MAIN by its clock error: after a bus start its requests are refused until that lag has passed, up to the 90 s window. (xc_vm_fanout #30 takes the offset from a `REPLAY`, item 2 of the contract below, and xc_vm_fanout #39 from a `CLOCK_SKEW`, item 3.)

All of this is safe, only slower than the contract below.

**The agent's contract.** For the Go half, built in xc_vm_fanout #30:
1. **503 `RATE_LIMITED`**, a verified denial with status 503 to `hello`, `token_rekey`, `config` or `conn_snapshot`, means MAIN is busy, not failing. Wait `retry_after_ms` with ±10 % jitter, clamped to 1–60 s. Then send the same op again with a fresh nonce and stamp. Do not raise the op's backoff or count it as a failure.
   - `hello`, at every call site: in `Run`'s start loop, wait `retry_after_ms` instead of the doubling start backoff. After a re-key, retry the hello after `retry_after_ms` until it succeeds or fails fatally; do not drop it. After a newer `policy_ver`, retry after `retry_after_ms` too (the heartbeat loop keeps running meanwhile).
   - `config`: retry after `retry_after_ms` instead of at the next minute's poll.
   - `conn_snapshot`: resend the refused chunk, with the same `snap_id` and `seq`. MAIN keeps the chunks it took, and a chunk it no longer expects gets 409 `SNAP_GAP`, which ends the snapshot as today.
   - `token_rekey`: as today. The challenge was not consumed, so it may be sent again while under 180 s old, or a new one fetched.
   - A 429 `RATE_LIMITED` (the re-key minute) is handled as today.
2. **401 `REPLAY` with `retry_after_ms`**, a verified denial to a request the agent sent once, on any op, means MAIN cannot vouch for its nonce yet (the three causes above). First set the clock offset from the denial's `main_time_ms`, as from a MAC'd reply: the denial is panel-signed and names this request. Then wait `retry_after_ms`, never less (jitter may only add, up to 10 %), clamped to at most 10 s. Then send the op once more with a fresh nonce, a fresh `MainNowMs()` stamp and a fresh MAC, BOX or SEAL. A second `REPLAY` in a row, or a `REPLAY` without `retry_after_ms`, takes the op's usual backoff.
3. **Stamps.** `X-XCVM-Ts` stays `MainNowMs()`: local time plus the offset from the last authenticated `main_time_ms` (a MAC'd reply, a `REPLAY` as in item 2, or a `CLOCK_SKEW` as below), never pushed ahead by an RTT estimate. A request stamped more than 250 ms ahead of MAIN's clock is accepted, but costs MAIN a MySQL write.
   - **401 `CLOCK_SKEW`** (amended 2026-09-28, xc_vm_fanout #39, a bug fix): MAIN refused the request's stamp as outside the ±90 s window (`Canonical::withinWindow`, step 3 of the order). The denial is verified like every other (panel-signed, naming this node and this request's nonce) and carries `main_time_ms`, as every denial does. Set the clock offset from it, then send the op once more at once, with a fresh nonce, a fresh `MainNowMs()` stamp and a fresh MAC, BOX or SEAL. No wait: the refusal was the stamp, not the nonce. A second refusal takes the op's usual backoff. This covers every op sent through `withReplay` (`internal/clusteragent/retry.go`): the session ops, `token_rekey` and the code ops. Without it, a node whose clock was more than 90 s off never got a MAC'd reply to learn MAIN's time from, and every op was refused until its token looked expired and a re-key's challenge carried the time.
4. **Nothing else changes:** no new op, header or setting. The one new field is `retry_after_ms` on `REPLAY`; `CLOCK_SKEW` already carried `main_time_ms`.

**Differs from the plan.**
- **No `streams` semaphore.** The op does not exist yet (Phase 7, R2).
- **A sorted set per node, not `SET NX` with a 180 s TTL.** A key with a TTL is evictable under the bus's `volatile-ttl` policy, which would reopen that nonce's replay window under memory pressure.
- **The nonce floor, defined.** The plan names a nonce floor against replay after a bus restart (section 3 and the security checks) without defining it. Here it is `nonces_since` + LEAD, and a young bus also claims in MySQL for 180 s, since the bus is not persisted. The marks, for a bus out of reach, are not in the plan.
- **MySQL writes remain** while the bus is young or out of reach, and for requests stamped ahead: the cases the bus alone cannot vouch for.
- **The busy refusal reuses `RATE_LIMITED`.** The plan names no reason, and today's agent honours `retry_after_ms` only on `RATE_LIMITED`, so it re-keys promptly.
- **Challenge values stay in MySQL.** They are issued unauthenticated, and on the bus a flood of them would evict what must stay.
- **`REPLAY` can carry `retry_after_ms`.** The plan gives a replay refusal no wait. When MAIN only cannot vouch for a nonce yet, the refusal tells the agent when a request stamped anew will pass.

**Compatibility.**
- Older agents keep working. The only wire changes are the 503 on four ops and `REPLAY` in the cases above, both refusals they already handle, and `retry_after_ms` on such a `REPLAY`, which they ignore.
- On upgrade, the running bus has no `nonces_since`, so the first claim sets it. The first 250 ms of requests are refused, and for 180 s every claim also goes to MySQL, which holds the claims from before the upgrade.
- LB builds have neither `Domain/Cluster` nor `bin/cluster_bus`.

**Limits.**
- The marks are file mtimes, and a power cut can lose the last few seconds of metadata (ext4 commits every 5 s). If MAIN then serves requests before its bus starts, and within 90 s of the cut, a request from those last seconds could be replayed once. A bus started at boot is young and covers this.
- The rules assume MAIN's clock does not step back by more than a second.
- Two copies of one request, one at a worker with the bus and one at a worker without it, can both pass if they arrive together after a second in which the bus took no claim. The worker without the bus must pause between reading the bus mark and writing the SQL mark while the other claims.
- A bus that loses some nonce keys but keeps `nonces_since` goes unnoticed: a manual `DEL`, or an `allkeys-*` eviction policy. The shipped `cluster.conf` uses `volatile-ttl`.

Tests:
- `ClusterNonceStoreTest`, against a real redis-server on a unix socket as `ClusterBusTest` does:
  - MySQL without the bus;
  - on a settled bus, one write there, no TTL, expiry and purge;
  - the lead: a request stamped more than 250 ms ahead also goes to MySQL;
  - a fresh bus's floor with its wait, and its MySQL writes for exactly its first TTL;
  - a clock step back that restarts the history, and one of a second that does not;
  - a nonce MySQL took while the bus was out of reach, refused once it is back, and the SQL mark's TTL + 1 s;
  - a nonce the bus took, refused while it is out of reach, with the previous-second rule, the lead and another worker's mark;
  - a bus mark ahead of the clock, the mark written before the claim runs, a mark that cannot be written, and marks that only move forward;
  - a killed and restarted bus, and a flushed one;
  - challenges issued in MySQL, with the bus or without it, used once.
- `ClusterSemaphoreTest`:
  - no limit without the bus;
  - 4 permits per op, and each op's worst case;
  - the signed 503 with `retry_after_ms` and `op`;
  - release after the handler returns or throws;
  - expiry after the op's worst case, by the bus's clock;
  - workers' clocks that disagree drop no permit;
  - a permit taken before a clock step back, and a step of under 1 s.
- `ClusterApiTest`:
  - no MySQL row per request on the bus, with replays still refused;
  - a busy `hello` refused while `heartbeat` goes on, and a served one returning its permit;
  - `NOT_ACTIVE`, `BAD_MAC` and `REPLAY` before any permit;
  - `config` and `conn_snapshot` refused when busy;
  - a busy `token_rekey` that spends neither the minute nor the challenge;
  - on a fresh bus, a `heartbeat` and a `token_rekey` stamped at its floor, refused with `retry_after_ms`, then served.
- `ClusterEnrolCodeTest`: on a fresh bus, and after a restart, `enrol_code` and `enrol_code_status` stamped at its floor, refused with `retry_after_ms`, then served.

### The cluster pools (Phase 2, second increment)

**What they are.** MAIN serves the cluster API from two PHP-FPM pools of its own (`Domain\Cluster\ClusterPool`). A fleet's long-polls and ingest then never hold the workers that serve the panel and viewers, and a busy panel never delays a heartbeat.

| Pool | Ops (agent lanes) | `pm.max_children` | Timeout |
| --- | --- | --- | --- |
| `cluster_ctl` | `health`, `challenge`, enrolment, token ops, `hello`, `heartbeat`, `conn_admit`, `commands`, `ack` (poll, ctl) | 2·nodes + 24 | 60 s |
| `cluster_ingest` | `events`, `config`, `streams`, `conn_snapshot`, `stream_bundle`, `rpc_result`, `recording_complete`, `vod_analysis`, the three queue ops, `artefact` (p0, bulk) | min(2·`cluster_ingest_concurrency` + 8, floor(0.25 · MariaDB `max_connections`)), at least 2 | 90 s |

- **Nodes** are the streaming servers other than MAIN, enrolled or not, so a pool is sized before a node's first long-poll. Proxies do not count.
- **The floor of 2** keeps one P0 and one bulk request running when MariaDB's limit is tiny. The plan gives no floor.
- **Unknown ops** go to `cluster_ctl`, which refuses them.
- **Files.** Configs go in `bin/php/etc/cluster/<pool>.conf`, because `set_services` deletes and rewrites `bin/php/etc/*.conf` for the panel's pools. Sockets go in `bin/php/sockets/<pool>.sock`. Pid files go in `bin/php/var/run/`, because `restart_php_fpm` counts `sockets/*.pid` as panel pools.
- **Workers** are titled `php-fpm: pool cluster_ctl` (or `cluster_ingest`), so they never pass for a viewer's worker in `php_pids`.
- **Always there on MAIN.** The pools exist whether or not the API is enabled. With `pm = ondemand`, an idle pool is just its master.

**Routing.** nginx's `location ^~ /cluster/v1/` (fixed in `nginx.conf` at first, rendered since the third increment) passes to the upstream `cluster_ctl`. A nested regex location sends the ingest ops to `cluster_ingest`. Both upstreams list the panel pool `1.sock` as `backup`, so the API still answers while a pool is down or not yet created. The pool's own socket has `max_fails=0`: only a request that cannot reach the pool goes to the backup, and one failed request (a long-poll cut by a reload, say) never sends the pool's traffic to the panel pool for `fail_timeout`. `ClusterPoolTest` fails when the location's list differs from `ClusterPool::INGEST_OPS`, or when an op the API serves has no lane.

**Bringing them up.** `ClusterPool::ensure()`:

1. writes a pool's config when its size changed;
2. starts a pool whose master is not running, as xc_vm;
3. reloads (`SIGUSR2`) a running pool whose config changed. The reload lets requests finish for 5 s (`process_control_timeout`); a held long-poll is cut, and the agent polls again. The socket stays open meanwhile, and new requests queue on it;
4. writes the marker `tmp/cluster_ready`, when it is missing, once both pools answer FPM's own ping (`ping.path`, one FastCGI request over the socket). It removes the marker only when a pool's master is gone, and writes it again once the restarted pool answers.

A reload, or a pool too busy to answer, therefore keeps the marker: a resize from `cron:servers` never turns the fleet `STARTING` while held long-polls stretch the reload to 5 s. A pool whose master runs but has stopped answering keeps it too; its requests wait on the socket.

`status` runs it while XC_VM runs: at every boot, after the migrations, and after an update. `cron:servers` runs it every minute as a watchdog, which also resizes the pools as servers come and go. A lock keeps the two from starting a pool twice. A master is found by its title, which names its config, not by its pid file.

**As xc_vm only.** The configs, the lock and the marker live in directories xc_vm owns, and root would follow a link planted there, then hand its target to xc_vm or overwrite it. So `ensure()` does nothing unless it runs as the pool user, and `status` (root) runs it through `sudo -u xc_vm console.php cluster:pools`, which prints whether both pools answer.

**STARTING.** Until the marker exists, `Public/cluster/index.php` answers every op but `health` with a panel-signed `503 STARTING`. The denial names the node and request nonce when the request carries them, and asks for `retry_after_ms` 5000. `health` needs neither the database nor the pools, so it is answered throughout.

- **When it applies.** The service removes the marker whenever it starts, and tmp/ is a tmpfs. So a boot, a restart and an update each answer `STARTING` until the migrations have run and both pools answer.
- **Silence clock.** Creating the marker runs `ClusterMeta::markReady()`, so the time the API was `STARTING` never counts against a node's silence.

The ingest permits on the bus came with the fourth cluster bus increment, and the plan's `cluster_ctl` listen-queue check with the fifth increment.

The old-port servers, which still passed to the panel pool, reach the pools since the rendered nginx config.

### The rendered nginx config (Phase 2, third increment)

**What it is.** MAIN's nginx route for the cluster API is rendered by `Domain\Cluster\ClusterNginxConfig` instead of fixed in `nginx.conf`. It writes three files under `bin/nginx/conf/`:

| File | Included by | Holds |
| --- | --- | --- |
| `cluster_locations.conf` | the public `server{}`, and each server below | `location ^~ /cluster/v1/`, its ingest lane built from `ClusterPool::INGEST_OPS` |
| `cluster.d/listen.conf` | `http{}`, by the glob `cluster.d/*.conf` | a plain-HTTP server on `cluster_api_port`, only when it is not 0 |
| `cluster.d/old_port.conf` | the same glob | a server per old port `ClusterEndpoint` keeps, until its 7 days are up (an old HTTPS port over TLS, since the second endpoint increment) |

Both servers include the same location and answer 404 to everything else. The old ports therefore reach the cluster pools now, not a panel pool.

**Transport limits.** The location applies §3's numbers:

- `limit_req` zone `cluster`: 100 r/s keyed by `$realip_remote_addr`, the TCP peer, whatever `X-Forwarded-For` says; burst 400, `nodelay`, status 429. Before, the API shared the viewers' zone `one` (20 r/s per client, burst 40, status 503).
- `client_max_body_size 8m` and `gzip off`.

The zone is declared in `nginx.conf` itself, because the location cannot load without it.

**Default install.** With `cluster_api_port` = 0, `cluster.d/` stays empty and the location is the one `nginx.conf` used to hold, with those limits. The release ships `cluster_locations.conf` as rendered, so an update's `nginx -t` and the first boot see the same file. `ClusterNginxConfigTest` fails when the two differ.

**The public server keeps the location**, whatever `cluster_api_port` says. The plan does not say whether it should. It does, because the policy's HTTPS URLs use it, and the broadcast port's URL keeps working for a node that has not moved to the dedicated port yet.

**Port changes.** `ClusterEndpoint` used to handle the broadcast port only. A `cluster_api_port` change, saved in Settings, now goes the same way: the policy version goes up and the old port is kept for 7 days.

- While the API is on the broadcast port, that port is its URL. So 0 → N keeps the broadcast port listed in the policy (the public server serves it anyway), and N → 0 keeps N.
- With the API off, nothing is announced, as for the broadcast port.
- A kept port that MAIN serves again, on the public server or as the dedicated port, gets no server of its own.
- The policy lists kept ports whatever `cluster_api_port` is.

**Applying.** `apply()`:

1. renders from the settings and the ports the public server listens on (`ports/http.conf`, `ports/https.conf`). Called without settings, it reads `cluster_api_port` and `cluster_legacy_ports` from the database, not from the settings the process loaded;
2. checks that a new dedicated port is free: one nginx does not listen on yet by its files (`ports/`, `cluster.d/`) must bind. When it does not, nothing is written;
3. writes each file that differs, atomically (a temporary file, then a rename);
4. runs `nginx -t`. When it fails, it puts every file back as it was, audits `cluster.nginx` with nginx's message and reports the failure;
5. otherwise reloads nginx, unless the caller reloads it itself. After the reload a new dedicated port must accept connections within 3 s. When it does not, the previous files go back, nginx reloads again and the failure is reported.

When nothing differs there is no test and no reload. A lock serialises callers. A refusal that repeats the last one is not audited again (`cluster.d/.refused`), since `cron:cluster` retries every minute.

Steps 2 and 5 exist because neither `nginx -t` nor the reload's exit code says whether nginx can take a port. In test mode nginx binds the listen sockets but ignores `EADDRINUSE`, so `nginx -t` passes when another program holds the port. `nginx -s reload` exits 0 once the signal is sent. The master then fails to bind and keeps its previous config, and the next start (a reboot, `service xc_vm restart`, an update) fails with "still could not bind()", taking the panel and streaming down. Reproduced with nginx 1.24. The check in step 5 is a TCP connect, which a program that took the port between steps 2 and 5 would also pass.

A kept old port is not checked: nginx served it until the change, so nginx holds it. Any port in nginx's config can still be taken while XC_VM is stopped, which stops nginx from starting, as for the broadcast ports.

**Who runs it.**

- `status`, at boot and after an update, runs `sudo -u xc_vm console.php cluster:nginx`, reloading only while XC_VM runs.
- The root `set_port` handler runs `cluster:nginx --no-reload` after it writes the HTTP or HTTPS ports, before its own reload.
- `cron:cluster` runs it every minute, with the API on or off, after it drops expired old ports. It used to re-send MAIN's ports through `set_port`, only when a port expired. A render that matches the files is a no-op, so the minute retries a render that failed and undoes one that raced a settings save.
- A settings save that changes `cluster_api_port` (`ClusterNginxConfig::stageApiPort()`) runs it before the value is stored, with the new port and the kept one. The save is refused, and nothing changes, when another program listens on the port (`cluster_error_port_busy`), or when `nginx -t` fails or nginx does not serve the port after the reload (`cluster_error_nginx`, with nginx's message). After the `UPDATE`, `commitApiPort()` bumps the policy (`ClusterEndpoint::recordApiPortChange()`) only if the value was stored. Either way it renders again from what is stored: that undoes a render from the old value that ran in between (`status`, `set_port`, `cron:cluster`), and a failed `UPDATE` puts nginx back on the stored port. So no node is sent a URL that nginx refused or did not serve after the reload.

**As xc_vm only**, like the pools, because the files live in a directory xc_vm owns. Root no longer writes the old-port file.

**The old file.** The first render removes `cluster_legacy.conf`, but only once `nginx.conf` includes `cluster.d/`. An update whose `nginx.conf` was rolled back still reads the old file, so it stays.

That older `nginx.conf` reads neither `cluster.d/` file. While it is in place, `apply()` refuses any render that needs one (a dedicated port, or a kept old port), and a new `cluster_api_port` cannot be saved. Otherwise `nginx -t` would pass and the port would be stored and announced but never served. The API stays on the broadcast ports, through that `nginx.conf`'s fixed location. Its `cluster_legacy.conf` is not rendered again, so ports kept in it stay open until the next update installs an `nginx.conf` that passes `nginx -t`.

**Checked** with nginx 1.24:

- `testRealNginx` (opt-in, `XCVM_TEST_NGINX`): the rendered files pass `nginx -t`, and a broken include elsewhere restores the previous files.
- `ClusterNginxConfigTest` and `SettingsServiceClusterPortTest` fake nginx and the port checks. They cover the save path (`stageApiPort()`, `commitApiPort()`, the refusals in `SettingsService::edit()`), the rollback after a failed write or reload, the `nginx.conf` that predates `cluster.d/`, `cluster:nginx` and `cron:cluster`. `testTheFreeCheckBindsThePort` runs the real bind check against a port the test holds. The lock and the atomic write are not tested.
- By hand, with the real code and a running nginx master: a port a Python socket held was refused before anything was written. Once the port was free, it was served after the reload, the move to another port kept the old one served, and nginx restarted cleanly. With nginx stopped, a new port was rolled back. On the dedicated and the old ports, `/cluster/v1/` reached the pools and every other path got 404. Past the burst, nginx answered 429. There is no test for these checks.

**Not built:**

- releasing an old port before its 7 days once every node uses the new URL. Neither the policy version a node last fetched nor the URL it used is recorded (built in the sixth Phase 2 increment);
- IPv6 listeners: the dedicated and old ports listened as `ports/http.conf` does, on IPv4 only, though MAIN's URL may be an IPv6 address (`ClusterEndpoint` keeps it bracketed). Built later: each of their servers also listens on `[::]` (IPv6 only, as nginx makes it) where nginx has it already, or where a v6-only bind of the port succeeds (`probe('free6')`). A listen the master cannot bind at a reload leaves it on its previous config and stops its next start, so a port another program holds on IPv6, or a machine without IPv6, gets none. `ClusterNginxConfigTest::testIpv6IsListenedOnWhereItCanBeBound` and `ClusterEndpointTest` (an old HTTPS port) cover it; it was not run against a real nginx. The public server's ports stay IPv4, so with `cluster_api_port` at 0 a node that reaches MAIN over IPv6 is still not served.

### Re-enrolling the fleet (Phase 2, fourth increment)

**What it is.** `console.php cluster:reenrol (--all [--state=…] | <id>…) --cred-file=<path> [--dry-run]` (`ClusterReenrolCommand`) is the plan's `cluster:reenrol --all`. After `cluster:init` on a MAIN replaced without a DR bundle, it re-enrols the fleet over SSH, one node after the other. Each node goes through `ServerEnrolCommand::enrol()`, the path `server:enrol` takes, so each gets a new identity and generation.

**Which nodes.**

- `--all` takes the enrolled nodes in state `enrolling` or `active`; `--state=` names others.
- Revoked and quarantined nodes are skipped unless asked for, because both states record an admin's decision.
- Nodes named by id are taken whatever their state. A server that is not an enrolled load balancer is reported and left alone; `server:enrol` enrols a legacy LB.
- The states are read when the run starts, and each node's row is read again just before its turn. A run is sequential and can last tens of minutes, and the plan revokes a node on suspected compromise. So with `--all`, a node that an admin revoked, or the API quarantined, since the start is skipped, and a node no longer enrolled is left alone however it was chosen. The window left is the node's own enrolment, a few seconds.

**Credentials.** The plan names the command but not where its SSH credentials come from, and each node needs its own. One file carries them all:

```json
{"u": "root", "p": "…", "port": 22,
 "nodes": {"7": {"p": "…", "port": 2222, "hostkey": "SHA1:…"}}}
```

- The top level is every node's default. It has the shape of `server:install`'s credential file, so a fleet that shares one root password needs only that. `nodes` overrides it per server id.
- It must be a `.cred` file directly in `bin/install/`, like `server:enrol`'s, and owner-only (0600). Unknown keys, bad ports and bad host keys refuse the whole file.
- A run without `--dry-run` reads and deletes it before any other check, as `server:enrol` does. So a run refused for its arguments, a disabled API or a missing extension leaves no passwords on disk. A file that its group or others can read is refused and, on such a run, deleted as well, since its secrets are exposed already.
- The SSH port is the node's entry, else the file's default, else 22. The panel keeps no node's SSH port. `server:install` writes one to `bin/install/<id>.json` but deletes that file once the install succeeds, and a replaced MAIN does not have the old disk anyway. Keeping it in `servers` would need a core migration, so a node on another port gets `port` in the file instead.
- There is no `--expect-hostkey`: each node's `hostkey` goes in its entry.

**The same checks as `server:enrol`.**

- The SSH host key must match the node's `hostkey` in the file, else the one stored at its install (`ssh_hostkey_sha1`). A node with neither is never contacted: no trust on first use.
- The node must run this release, and its new keys must match their SAS.
- A failure never marks a live node failed.

**Failures.**

- A node that fails is reported with its reason, and the run goes on. The reason is `enrol()`'s own, or what `provisionCluster` printed when it stopped. `enrol()` passes that output through as it streams and keeps a copy. An exception, such as an SSH channel error, fails only its node.
- A node counts as enrolled only when it has a new `node_uuid` afterwards. `provisionCluster` returns true without enrolling when there is no `xc_agent` for the node's architecture, or when the API is off. `enrol()` reports that as a failure with the flow's own words, and the node keeps its previous identity (a legacy LB stays legacy). An earlier version compared the row's `created_at` with the start of the flow, which has one-second resolution.
- A licence refusal stops the run. Every later node would have its agent stopped only to be refused the same way. An extension that reports no licence (`info()['licensed']`) refuses before any node is touched.
- The exit code is 0 only when every chosen node was re-enrolled. A real run is audited as `cluster.reenrol`, with the ids that succeeded and those that failed. Each node's `node.enrol_start` is audited as before.

**One at a time.** `cluster:reenrol` holds `TMP_PATH/cluster_reenrol.lock` (non-blocking, as `cluster:root` does), so a second run refuses. `ServerEnrolCommand::enrol()` holds `TMP_PATH/cluster_enrol_<id>.lock` for its node. So `server:enrol` and a fleet run cannot interleave one node's `keygen` and `startEnrolment`.

**Dry run.** `--dry-run` contacts no node and changes nothing. It keeps the credential file for the real run, and it runs without one too. For each node it shows the user, the address and port, and the host key with where it comes from. It lists the nodes that cannot be attempted and why, for example no host key or no credentials.

**The SSH seam.** `SshSession` wraps the ssh2 session: connect, host key, password login, `SshChannel`'s run and send, close. `server:enrol` now runs through it too, and the tests replace it with `FakeSshFleet`. `SshSession` and `ClusterReenrolCommand` are stripped from the LB archive.

**Mode and flows.** A re-enrolled node starts over as a new node does: in the mode `lb_new_node_mode` gives, with every flow off (`NodeRegistry::startEnrolment`), as after `server:enrol`. The admin switches its flows on again.

**A failed node may need another run.** `provisionCluster` stops the node's agent and runs `keygen` before the probe. A licence refusal comes after `startEnrolment` has already replaced the node's row. So a node that fails at the probe or later is left with its agent stopped and new keys made, and should be re-enrolled again once the cause is fixed. `server:enrol` behaves the same. Re-enrol one node by id before `--all`: a cause that affects the whole fleet, such as MAIN's cluster port closed to the LBs, then stops at one node.

**Not built:**

- Nodes are re-enrolled one at a time, never in parallel. Kept so on review: the plan does not ask for it, the command serves only a MAIN replaced without a DR bundle, and a parallel run would need a process per node with its own database connection and its credentials passed to it. Add it when a fleet's re-enrolment time is measured as a problem.
- `--all` did not skip nodes already re-enrolled under the current root, so after a canary node, or a partial failure, the rest were best named by id. Built later as `--pending` (with `--all` only): `ClusterMeta::init()` now records `root_at` in `cluster_meta` when the panel fingerprint changes (a new root, or the first), and `--pending` leaves out the active nodes whose row was created at or after it. A node still `enrolling` is taken, since its enrolment never completed. It stays opt-in, because a fleet-wide re-enrolment without a root change (new identities after a suspected compromise) must still take every node. A root from before `root_at` has no record, and `--pending` then refuses before any node is touched: name the nodes by id. `ClusterReenrolCommandTest::testPendingLeavesOutTheNodesOnTheCurrentRoot` and `ClusterApiTest::testInitRecordsThePanelKeysAndReadiness` cover it.

**Tests:**

- `ServerEnrolCommandTest`: one node's path. It covers no trust on first use, a changed key that runs nothing, the refusals before the flow, the flow's reason, a flow that enrols nothing (in the same second as a previous enrolment), and the node's lock.
- `ClusterReenrolCommandTest`: selection, including nodes revoked, quarantined or removed during the run; continuing past failures and exceptions; each node's port and password; the licence stop; the dry run; the credential file; the arguments; and `main()`, the command from its arguments on (`execute()` adds only the user check). `main()` shows that a dry run stays dry, that a refused real run still deletes the file, and that a second run refuses.

### The `cluster_ctl` listen queue (Phase 2, fifth increment)

**Before.** The fleet guard (Liveness, Phase 3) had one input: the silence of most nodes at once. Plan section 8 gives it a second: a `cluster_ctl` listen queue lasting over 5 s. That pool takes every heartbeat, so a queue there makes live nodes look silent.

**FPM's count is always 0 here.** The pools have a status page (`pm.status_path = /status`) whose `listen queue` FPM measures only on TCP sockets. On the pools' unix sockets it reports 0, and `listen queue len` 0 too: php-fpm 8.3 with one worker busy and six requests waiting still says 0. FPM also serves the status page from a worker, so a status request to a pool without a free worker waits in that same queue.

**The probe.** `ClusterPool::listenQueueMs('cluster_ctl')` therefore times its own status request. It answers how long the queue has lasted, in ms; 0 when there is none; null when the pool cannot tell.

- It sends `GET /status?json` (`SCRIPT_NAME` `/status`, `QUERY_STRING` `json`) over the pool's socket, through the FastCGI client the ping now shares, and waits up to 250 ms (`QUEUE_PROBE_WAIT`) for the answer.
- Answered within 250 ms, with FPM counting no queue: 0.
- Not answered: the request waits for a worker. The probe keeps it waiting, and later calls read it without blocking. The queue has lasted since the first request that waited.
- A late answer says only that the requests ahead of it were served, so a new request goes out in the same call. The queue ends only when a new request is answered within 250 ms.
- A connect refused with `EAGAIN` (the listen backlog is full) is a queue as well, and so is a `listen queue` above 0 (a pool on TCP).
- Null: no socket, nobody listening, an answer cut before `END_REQUEST`, no status page (FPM's `File not found.`), or another pool's status.
- A call blocks for 250 ms at most and never leaves two requests waiting. While the pool keeps up, it costs one FastCGI round trip and keeps one `cluster_ctl` worker from idling out.

**The guard.** `LivenessService::tick()` reads the probe once a pass: every second in the signals daemon, each minute from `cron:cluster`.

- The probe comes first in the pass, before `HeartbeatService::flush()` and the `cluster_nodes` read. A heartbeat that waited in the queue ahead of the probe's answered request is then in the same pass.
- A run lasts from `now − age`. The guard's `ctl_queue` reason is up while the run is over 5 s old (`QUEUE_GUARD_MS`).
- A pass that sees no queue, or cannot tell, ends the run. A pool that is down or has no status page never raises the guard. While it is down, nginx sends its requests to the panel pool.
- A pass within 5 s (`QUEUE_GAP_MS`) of the last one that saw the run joins it, before or after it. So `cron:cluster`, whose own request has waited only 250 ms, keeps the signals daemon's run instead of restarting it. This holds even when the daemon writes `health.json` between cron's clock read and its read of the file, which leaves cron's pass a few ms behind the run's `at`. A joined run keeps the earlier `since` and the later `at`. Passes further apart start a new run, because the queue may have drained between them, and so does a clock that stepped back more than 5 s.
- The run is kept in `tmp/cluster/health.json` as `ctl_queue: {since, at}` (MAIN's ms), so both processes see it.

**What the queue holds.** The silence reason holds every node. The queue reason holds only the nodes it may have silenced, for a bounded time, and keeps holding them for a while after it ends. `health.json` keeps this hold as `ctl_queue_hold: {from, until}` (MAIN's ms, `LivenessService::queueHold()`). A node is not newly marked offline while `from ≤ now ≤ until`, if its silence counts from `from` or later. Its silence counts from `max(last heard, ready_at)`, as in `NodeHealth`.

- `from` is the run's `since` minus 10 s (`NodeHealth::SUSPECT_AFTER_MS`). A node silent since before the queue began was not silenced by it. A live node is heard every 1 to 3 s, and the probe may see the queue a pass late. Such a node is marked offline as usual, with or without the guard.
- `until` is `since` plus 4 offline windows (`QUEUE_HOLD_WINDOWS` × `cluster_offline_after_sec`, 2 min at the default). A queue whose requests each wait about a second can last while every live node is heard. Without the cap, it would keep a node that died meanwhile suspect, and routed viewers at half weight, for as long.
- When the reason clears (`drained`, or `unknown`: the pool can no longer tell, or the run broke), `until` becomes `min(until, now + cluster_offline_after_sec)`. Heartbeats that waited in the queue are heard late or not at all: refused as `CLOCK_SKEW` once older than 90 s, or lost with a pool that died. The pass that sees the queue end may also judge heard times from before the end. So each node gets one full offline window after the queue to be heard again, as `ready_at` gives it after MAIN's own downtime. A node that is silent only while the queue lasts is never marked offline. The cap still applies.
- A node already published offline stays offline. The hold never brings one back.

**One guard, two reasons.** `health.json` keeps `guard` (either reason) and `reasons` (`silence`, `ctl_queue`, in that order).

- With either reason the orphan purge waits (`HlsReaping` reads `guard`), as before. Offline marking waits as above: for every node under the silence reason, and for the nodes the queue holds under the queue reason, which also holds them for a window after it clears. `guard` itself clears with the reason.
- Each reason is audited on its own. `cluster.fleet_silence` and `cluster.fleet_silence_clear` (`{silent, nodes}`) now follow the silence reason rather than the guard. `cluster.ctl_queue` carries `{queued_ms}`, and `cluster.ctl_queue_clear` carries `{lasted_ms, queue}`, where `queue` is `drained` (a new request was answered at once) or `unknown` (the pool could not tell, or the run broke).
- The Cluster Nodes page shows one alert per reason (`cluster_ctl_queue`, `cluster_fleet_silence`).
- A `health.json` written before this increment has `guard` and no `reasons`, and reads as the silence.

**The agent's contract.** Nothing changes on the wire: no new op, lane, field, header, refusal, section or file. The guard changes what MAIN does about silence (no offline marking, no orphan purge), never what the API answers. `health.json` (`ctl_queue`, `ctl_queue_hold`) is MAIN's own file under `tmp/cluster/`, read by MAIN's routing and liveness only. Today's agent is unaffected, and its Go half has nothing to build.

**Differs from the plan.**

- **The queue is measured by the probe's own wait.** The plan reads FPM's listen queue, which FPM counts only on TCP. FPM's count still counts when it is above 0.
- **"Lasting over 5 s"** is the time from the first status request that waited until one is answered within 250 ms. A pool that serves every request within 250 ms has no queue, whatever it holds for a moment.
- **An alert and an audit per reason**, where the plan names one alert.
- **`cron:cluster` alone** (the signals daemon down) never raises the queue reason: its passes are a minute apart, too far apart to make one run. A reason the daemon raised before it stopped clears at the next pass.
- **The queue does not suspend all offline marking.** The plan suspends offline marking while MAIN suspects itself. For the queue, that is limited to the nodes it may have silenced, and to 4 offline windows. In return the suspension lasts one offline window past the queue's end, so the queue's time never counts against a node, as MAIN's own downtime never does.

**Limits.**

- A worker that takes over 250 ms to fork counts as a queue for that moment; the 5 s run absorbs such blips.
- A queue that outlasts its cap (4 offline windows) no longer holds a node. On a fleet where the silence reason cannot hold (one TELEMETRY node, or a queue that silences only a minority), a live node whose heartbeats stay stuck that long is marked offline, and is suspect again once heard. On a fleet where most nodes fall silent, the silence reason still holds them with no limit.
- A node that dies within 10 s before a queue begins, or while it lasts, is held too. It is marked offline at the cap, or one offline window after the drain, whichever comes first.
- `cron:cluster` leaves a request that waited when it exits. FPM then serves one status page to a closed connection.
- `EAGAIN` is Linux's errno 11; the pools run on Linux only.

**Tests.**

- `ClusterLivenessTest`: a queue of exactly 5 s raises nothing. One over 5 s raises `ctl_queue` and holds a lone silent node at suspect instead of offline. It clears when it drains, with each audit, and the node is offline only one offline window after that. A single TELEMETRY node silent only while the queue lasts is never marked offline, whether the queue drains or the pool can no longer tell, with the drain and the hold read from `health.json` as another process would. A node silent since before the queue is not held. A node silent since MAIN's `ready_at` is held. The cap holds, whether the queue goes on or drains within a window of it. A pool that cannot tell never raises the guard, a pass that cannot tell ends a run, and so does a reader that throws. Both reasons together, each clearing on its own in either order. Another process's pass joins the run through `health.json`, including one a few ms behind the run's `at` and one exactly 5 s after it. A 20 s gap starts a new run, and so does a clock stepped back. A `health.json` without reasons. The Cluster Nodes page's alert per reason (source).
- `ClusterHeartbeatBusTest`: a heartbeat served while the probe waits reaches the same pass through the bus. The tests that run the liveness loop without faking the probe (`MainOutageNoPurgeTest`, `ClusterHeartbeatBusTest`, `NodeHealthHysteresisTest`, `ClusterEnrolCodeTest`) now fake a pool that cannot tell, so they never probe a socket under `MAIN_HOME`.
- `ClusterPoolTest`: the probe against a FastCGI responder (answered at once, a request left waiting and read without blocking, a late answer and a new request, no status page, FPM's count, another pool's status, a cut answer, nobody listening, no socket). A late answer followed by a request that also waits keeps the queue's start, and a drain forgets it. A request left waiting is dropped when the probe is asked about another pool. An answer past 64 KiB without `END_REQUEST` is no answer, for the probe and the ping. Also a full backlog, and the ping on the shared client. Opt-in against a real php-fpm (`XCVM_TEST_FPM`): FPM's JSON status, and a one-worker pool held by a slow request.

### Releasing an old port early (Phase 2, sixth increment)

**Before.** A kept old port (`cluster_legacy_ports`) or old URL (`cluster_legacy_urls`) stayed for its full 7 days. Plan §3 keeps it "until every node uses the new URL, or 7 days", but MAIN recorded neither the policy a node dials nor the URL it used. `cluster_nodes.policy_ver` has existed since migration 029, and nothing wrote it. The agent sends neither, at master or on the newer agent branch. Its hello carries `instance_id`, `boot_id`, `agent_version` and `features`, and its heartbeat `root_ready`, `telemetry` and `conn_digest`.

**What MAIN records.** `ClusterEndpoint::nodeUses()` returns the `cluster_nodes` fields that changed. The hello writes them with its other fields, in its one `UPDATE`. The heartbeat writes them only when one changed, so a heartbeat on the bus still writes nothing to MySQL unless the node's version or port changed. Both are recorded in every mode and state the heartbeat is served in.

- `policy_ver` is the payload's `policy_ver` when that is a JSON integer from 0 to 4294967295, and 0 (unknown) otherwise. That covers an agent that does not send it, including one downgraded from an agent that did.
- `main_port` (migration 046, `smallint(5) unsigned`, NULL by default, after `policy_ver`) is the MAIN port nginx took the request on. `Public/cluster/index.php` passes it to `ClusterApi` as `port`, from the `SERVER_PORT` that nginx's `fastcgi_params` set to `$server_port`. The address is not used: nginx listens on every address, and behind NAT the local address says nothing of the one the node dialled. In MAIN's nginx each port is one listener, plain HTTP or TLS, so the port alone tells a kept port from a current one. Before migration 046 the row has no `main_port`, and only `policy_ver` is written.

**When a port goes.** `ClusterEndpoint::release()` runs from `cron:cluster` every minute, with the API on or off: in `ClusterCronJob::endpoint()`, after `prune()` and before `ClusterNginxConfig::apply()`. It reads the kept lists, `cluster_policy_ver` and `cluster_transport` again from the database (`stored()`), not from the settings cache, which may be 20 s old. Until the third endpoint increment made the transport part of the state `stored()` reads, it was an extra column. It then reads every `cluster_nodes` row that is not revoked and whose server still exists (`EXISTS` on `servers`). `ServerRepository::deleteById()` leaves the row, and a deleted LB is no node of MAIN's. It releases nothing when:

- the rows cannot be read;
- an enrolment by code may still dial an old URL: a `cluster_enrol_codes` row has not expired (`exp` > now, used or not), or a `cluster_enrol_requests` row is `pending_approval` and less than `EnrolCodeService::TTL` (30 min) old. The code carries one MAIN URL, the policy's first when `cluster:enrol-code` ran. The agent sends `enrol_code` to that URL alone, then polls `enrol_code_status` on it every 10 s for up to 30 minutes (`EnrolWait`). Once the admin approves, the node's `enrolling` row holds everything until its enrolment completes or expires (below). Either table that cannot be read also releases nothing;
- a node is offline or unknown by `NodeHealth::state()` with `cluster_offline_after_sec`: silent past that window, or never heard. Silence counts from `last_seen_at` alone, not from `ready_at` as for liveness: a node MAIN has not heard since its own restart has said nothing since about the URL it uses. A suspect node (silent from 10 s to the window) counts as heard;
- a node's `policy_ver` is not the current `cluster_policy_ver`. It may be behind, 0 (an agent that does not say), or above (a MAIN restored from an older backup never announced that version);
- a node has no `main_port`: before migration 046, or an nginx that passes no `SERVER_PORT`.

The last three apply to rows in mode ≥ 1. A node in mode 0 is not waited for: no change is announced for it (`nodesListening()`), and its version is not asked. Its agent still heartbeats MAIN's URLs in every mode, though, and a switch back to mode 1 reaches it only in a heartbeat reply. So while it is heard (not offline or unknown), the port it last reached MAIN on is in use.

An `enrolling` row past its `enrol_deadline` is skipped. `enrol_complete` refuses it with `ENROL_EXPIRED`, and a new enrolment writes the current URLs into `cluster.json`. A row still within its deadline, the last second included (`enrol_complete` refuses only once now > `enrol_deadline`), has never been heard, so it holds everything.

Otherwise each kept port that no such node's `main_port` names is released. It leaves `cluster_legacy_ports`, and every kept URL on that port leaves `cluster_legacy_urls`. A port a node still reaches MAIN on stays, with the kept URLs on it: that node has the new policy, but its new URL fails, so it dials the kept one. With no such node at all (every one revoked, deleted, or in mode 0 and not heard), everything kept goes.

**Under `https_required`** a kept plain-HTTP port (`cluster_legacy_ports`) or `http://` URL is never released early. It keeps its 7 days. The nodes reach MAIN over HTTPS alone, so none reports a plain port, and the policy lists neither. Yet a node's way back when HTTPS fails is the signed challenge over the plain-HTTP URLs it has known (the agent's `HTTPURLs`), which an admin's switch back to `auto` relies on. Only the ports of kept `https://` URLs may go, and only a kept `https://` URL leaves the list.

- The write is one `UPDATE` that raises `cluster_policy_ver`, as `prune()`'s does. It applies only over the two lists as read (`WHERE COALESCE(…, '') = ?` on both), and a read-back checks that it did. A change stored between the read and the write keeps what it kept. `release()` then writes nothing, and the next minute tries again.
- The audit event is `cluster.endpoint_released`, with `ports` (released), `kept`, `kept_urls` and `policy_ver` (the version every node had adopted).
- The same pass renders nginx, which closes a released port in `cluster.d/old_port.conf` at its reload.
- The raised version makes each agent fetch the policy without the port within a heartbeat.

In a fleet of agents that send `policy_ver`, an old port therefore goes a minute or two after the change. That is a heartbeat to learn the version, a hello to fetch the policy, a heartbeat to report it, and the next minute's pass.

**A kept URL on a port MAIN serves anyway**, such as an old `server_ip` or `private_ip` on the current port, goes by the same rule when no node reaches MAIN on that port. While nodes use that port, it stays for its 7 days, since the port cannot tell which address a node dialled. nginx has nothing to close for it.

**The agent's contract.** For the Go half, built in xc_vm_fanout #31:

1. **The field.** The hello payload and every heartbeat payload (the JSON inside the BOX) carry `"policy_ver": <int>`. Its value is the `policy_ver` of the policy whose `main_urls` the agent dials when it builds the request: the version stored with `main_urls` in its state file (`State.PolicyVer`, read under the state's lock). On the newer agent branch, `Start` sets `hello["policy_ver"] = a.Client.State.policyVer()` and `Heartbeat` sets `payload["policy_ver"] = a.Client.State.policyVer()`; master has no `policyVer()` helper and reads the field under `st.mu`. `enrol_complete` does not need it, since the hello follows at once.
2. **Adopted, never merely seen.** A heartbeat reply with a higher `policy_ver` does not change the value. Only adopting a policy does, by the existing rule, from:
   - the reply to hello or `enrol_complete`;
   - the signed challenge over HTTP;
   - the install's `cluster.json`.

   Adopting never goes to a lower version. The first request built after an adoption carries the new value.
3. **The encoding.** A JSON integer. 0 means unknown, the same as leaving the field out. MAIN reads any other JSON type (string, float, bool, array, null), or a value outside 0 to 4294967295, as 0.
4. **Nothing else.**
   - There is no new op, header, reply field or agent state. The agent does not send the URL it dialled, since MAIN reads the port from nginx.
   - The known-good URL sets of the second endpoint increment's contract are unchanged.
   - A request that reached MAIN through a fallback or a kept URL reports the current policy's version like any other. Its port tells MAIN that the node still needs that port.
5. **Compatibility.**
   - MAIN before this increment ignores the field: hello and heartbeat read only the fields they know.
   - An agent before xc_vm_fanout #31 does not send it. MAIN records 0 for its node, and every old port and URL stays for its 7 days while that node is in mode ≥ 1.
   - A fleet releases early only once every node in mode ≥ 1 runs an agent that sends the field.

**Differs from the plan.**

- **Not only nodes hold a port.** An enrolment code that has not expired, or a request made with one that waits for approval, holds every kept port, and so does `https_required` for the plain-HTTP ones. The plan names only the nodes.
- **A node in mode 0 holds the port it is heard on**, though the plan's announcements count only nodes in mode ≥ 1.
- **The current version, not the announcing one.** It is enough for every node to dial a `policy_ver` at or above the one that announced the change. MAIN keeps no version per kept entry, so it compares with the current `cluster_policy_ver`, which is at or above every announcing version. That is never less strict. Nodes reach the current version within about 2 s of a bump. A bump in the same pass (`prune()`, or the release itself) delays the next release by a minute.
- **"Uses the new URL"** means heard, dialling the current policy, and last seen on another port. Which URL a node dialled is not recorded. The port is what a release closes.
- **Released by `cron:cluster`**, up to a minute after the fleet has moved, not at the moment the last node moves.
- **Migration 046, not 045.** 045 belongs to the Phase 7 work on a parallel branch, and the runner applies migrations by file name. `ClusterSchemaTest` now scans every core migration from 028 on for columns added to cluster tables, so 046 needs no entry in its list.

**Limits.**

- No agent sends `policy_ver` yet, neither master nor the newer agent branch. Until the Go half ships, every kept port and URL keeps its 7 days. After that, a node on an agent that does not send it holds them for their 7 days. A node that is offline holds them until it is heard on the current policy, and one still enrolling until its enrolment completes or its deadline passes.
- An enrolment code holds every kept port and URL while it lives (30 minutes), and a request made with it for up to 30 minutes more while it waits for approval. Without that, a code issued before a change would lose MAIN a minute or two after it.
- Under `https_required`, kept plain-HTTP ports and URLs keep their 7 days (above).
- A node in mode 0 holds a port only while it is heard. One that is offline at the release and comes back only on the old port finds it closed. With no node in mode ≥ 1, nothing is kept for it in the first place (`nodesListening()`).
- A deleted LB's `cluster_nodes` row stays, since nothing removes it, but holds nothing. An agent still running on that LB loses MAIN when its port goes. Built later: deleting a server revokes its node (`NodeRegistry::serverDeleted`, from `ServerRepository::deleteById`), so an agent still running there is refused with `NODE_REVOKED` instead of being handed tokens, leases and commands; `ClusterApiTest::testADeletedServersNodeIsRevoked`.
- `main_port` is the port of the node's latest hello or heartbeat. The other lanes (commands, events) are not observed; today they dial in the same order, with the same backoff.
- A node that goes back to a kept port after its release finds it closed, as after the 7 days. It still holds the current URLs of the policy it adopted.
- A kept address URL on the current port stays for its 7 days while nodes use that port (above).
- The Cluster Nodes page shows neither `policy_ver` nor `main_port`. An early release shows only as the `cluster.endpoint_released` audit event.

**Compatibility.**

- Migration 046 adds `cluster_nodes.main_port`, mirrored in `database.sql`. A rollback runs `down/046_add_cluster_node_main_port.sql`. Older code reads neither column, and the kept lists keep their format, so older code still expires them after 7 days.
- LB builds strip `Domain/Cluster` and `Public/cluster`. `ClusterCronJob` returns on an LB before touching either.

**Tests.**

- `ClusterEndpointReleaseTest`:
  - a port released once the only node has adopted the version and moved to the new port, with a node in mode 0 never heard and a revoked one ignored, and the audit;
  - a node behind, or ahead of MAIN's version, holding the port, and a later version still releasing;
  - an offline node, one never heard, one with no recorded port and one still enrolling (its last second included) each holding the port. An enrolment past its deadline does not hold it, a suspect node counts as heard, and the offline window follows `cluster_offline_after_sec`, bounded to 10–300 s;
  - a quarantined node waited for like any other;
  - a node in mode 0 holding the port it is heard on, and not once offline;
  - a row whose server was deleted holding nothing;
  - an enrolment code that has not expired, then a request made with one that waits for approval, holding everything until 30 minutes after the request;
  - a node still on the old port keeping it while an old HTTPS port no node uses goes, then going once it moves and every node has fetched the new version;
  - the reverse: an old HTTP port going while the kept `https://` URL on the port a node still uses stays;
  - under `https_required`, a kept plain port and `http://` URL staying while a kept `https://` URL goes, then going after a switch back to `auto`;
  - the port read from nginx's `SERVER_PORT` (`$server_port` in `fastcgi_params`), not the node's source port;
  - an old address on the current port staying while nodes use the port;
  - an agent that says nothing: nothing released early, and `prune()` still releasing after 7 days;
  - no node left: everything released;
  - a node read that fails or throws: nothing released;
  - a change stored between the read and the write: kept, with no release;
  - `nodeUses()`: the fields that changed, 0 for a missing or malformed version, 4294967295 accepted, no port without one from nginx or without the column;
  - migration 046 and `database.sql`.
- `ClusterApiTest`:
  - hello and heartbeat record the version and the port;
  - a heartbeat that changes neither writes neither;
  - a heartbeat without the field records 0;
  - before migration 046, the hello records the version alone.
- `ClusterNginxConfigTest`: `cron:cluster` keeps serving the old port while the node lags, then releases it, raises the version and removes it from `cluster.d/old_port.conf` with a test and a reload.
- `ClusterSchemaTest`: `database.sql` matches the migrations, now including 046.

### The cluster bus (Phase 2, third increment): heartbeats

**Before.** Every heartbeat (every 2 s per node) wrote MySQL: `cluster_nodes` (`last_seen_at`, `clock_offset_ms`, `root_ready`, `updated_at`), `servers.status`, and the `used` flag of its epoch. For a TELEMETRY node it also read the `servers` row, and wrote it every 5 s. The plan (section 8) has heartbeats hold no DB connection, and the health loop copy them from `cl:tel:<sid>` into MySQL every 5 s.

**Now.** While the bus runs and a flusher is working (below), `HeartbeatService::record()` asks MySQL nothing. One Lua script keeps:
- `cl:hb`, a hash with one field per server id: `<heard ms>:<clock offset ms>:<root_ready 0|1|->:<telemetry heard ms>:<authoritative 0|1>:<gen>`. `heard` is MAIN's clock when it handled the heartbeat, as `last_seen_at` was. `gen` is the enrolment the heartbeat was authenticated for. A heartbeat without `root_ready` or `telemetry` keeps the last ones of the same `gen` (`-`: never sent). No TTL.
- `cl:tel:<sid>`, the telemetry document: `{"at": heard ms, "auth": 0|1, "telemetry": {…}}`, with a 10 min TTL. `auth` says whether the node was in mode ≥ 1 with TELEMETRY on when MAIN heard it. It is encoded with `JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`, so the flush reads back exactly what the heartbeat carried.

Authentication still reads the node and its epoch (two SELECTs); since the fifth bus increment it reads them from the bus. `TokenService::markUsed()` no longer writes for an epoch that is already the node's current one: every newer epoch is minted above the current one, and an epoch becomes current only once its `used` flag is written, so that epoch was marked. So a heartbeat on the bus writes nothing to MySQL. Since the sixth Phase 2 increment it writes `cluster_nodes` when the node's `policy_ver` or MAIN port changed.

**The flusher.** `HeartbeatService::flush()` runs at the start of every `LivenessService::tick()`: every second in MAIN's signals daemon, and each minute from `cron:cluster`. One flusher at a time holds `cl:flush_lock` (`SET NX`, 10 s); another one only reads. Per node:
- **`cluster_nodes`** gets `last_seen_at`, `clock_offset_ms`, `root_ready` (once ever sent) and `updated_at` (heard, in seconds). This happens when the heartbeat differs from the one last flushed and one of these holds:
  - the node was never flushed on this bus;
  - its last flush is 5 s old (`FLUSH_EVERY_MS`), or ahead of MAIN's clock;
  - its `root_ready` is not the one MySQL had at the last flush, so `CommandBus::acceptsRoot()` learns it within a second. After a change the flush reads `root_ready` back: `hello` may have written a newer `last_seen_at` meanwhile and kept the UPDATE from matching, and the next heartbeat must then still count as a change.
- **The guard.** The UPDATE never takes `last_seen_at` back: `hello`, `enrol_complete` and `token_rekey` still write it directly. The exception is a `last_seen_at` more than 1 s ahead of MAIN's clock (the clock stepped back), which a heartbeat overwrote before too. With it goes `servers.status = 1 WHERE status <> 1`, as each heartbeat did. Both write only while the row's `gen` is the heartbeat's: a heartbeat of an enrolment that has since ended never reaches the new row or marks the server up again (an installer may just have set status 4).
- **Telemetry.** An authoritative document goes through the direct path's own code (`authoritative()`), judged on the time MAIN heard it. The `servers` row is written when that time is 5 s past its `last_check_ago`, and `servers_stats` once a minute; `last_check_ago` and the stats row's `time` are the heard time. The flusher runs every second and heartbeats come every 2 s, so it writes the same documents the heartbeats wrote.
- **Bookkeeping.** What it wrote is recorded in `cl:hb_flushed`: `<heard>:<root_ready MySQL has>:<flushed at ms>:<servers written as of ms>`. What MySQL refused is not recorded, and goes again at the next pass.
- **Forgetting.** A node silent for 10 min leaves `cl:hb`, `cl:hb_flushed` and `cl:tel:<sid>` once flushed, unless a heartbeat came meanwhile.
- **Enrolments.** What the bus holds of a node belongs to the enrolment that sent it. `NodeRegistry::startEnrolment()` (re-enrolment) and `revoke()` drop the node's `cl:hb`, `cl:hb_flushed` and `cl:tel:<sid>` (`HeartbeatService::forget()`); a heartbeat of the old enrolment still in flight may land after that, and the `gen` guard keeps it out of the new row. Its telemetry, authoritative when MAIN heard it, may still be written to `servers` once, as the direct path wrote an in-flight heartbeat's.

**Only with a working flusher.** A pass in which MySQL took every write it had due stamps `cl:flusher` with the bus's clock. A heartbeat stays on the bus only while that stamp is under 5 s old (`FLUSHER_STALE_MS`), either way (a stamp ahead of the bus's clock is stale too); otherwise it writes MySQL as before. So MySQL never lags for want of a working flusher:
- the signals daemon is down, and `cron:cluster` flushes only once a minute;
- MySQL refuses the flush;
- a new bus has had no pass yet.

**Liveness.** `LivenessService::tick()` judges each node by the later of `last_seen_at` and the bus's heard time (`HeartbeatService::freshest()`).
- **Restarts and the guard.** Silence still counts from `max(last seen, ready_at)`, and the fleet silence guard works on those states as before.
- **The Cluster Nodes page** shows the same freshest time.
- **The orphan purge.** `HlsReaping` lives in `Core`, which ships to LBs and cannot read the bus, so it still reads MySQL. Its `last_seen_at` is at most about 15 s behind for a live node: a 5 s flush, the 5 s a stopped flusher's stamp stays fresh, a heartbeat interval of up to 3 s and the 1 s loop. That is well under the 30 s minimum of `cluster_orphan_conn_ttl_sec`, so no live node is orphaned.

**Without the bus.** In these cases `record()` writes MySQL itself, per heartbeat, as before, including the shadow file:
- no bus;
- a failed script (a lost connection, `maxmemory`);
- a document over 128 KiB (`MAX_TELEMETRY`);
- no working flusher.

A bus lost between a heartbeat and its flush loses at most one flush's worth of heartbeats:
- MySQL keeps what it had; nothing is rolled back.
- The next heartbeat writes MySQL itself while the bus is out of reach, or goes to a new bus once that bus's flusher has run a pass.
- Liveness. At the loss, MySQL's `last_seen_at` can be a flush (5 s), the 1 s loop and a heartbeat interval old, and it ages one more interval until the node's next heartbeat writes MySQL: up to 12 s at 3 s, over the 10 s suspect threshold. So when a pass reads nothing from the bus (lost, or restarted empty), `LivenessService` uses the heard times it last read from that bus, for up to 5 s (`FLUSH_EVERY_MS`) after reading them. A live node is then never judged more than 5 s plus one interval (8 s) silent, and by the end of the window its next heartbeat, one interval after the loss, is in MySQL. The Cluster Nodes page has no such memory, and may show MySQL's older time until then.

**The shadow copy.** Nothing in `src/` reads `tmp/cluster/tel_<sid>.json`. The direct path still writes it; a heartbeat on the bus does not. `HeartbeatService::telemetry()` returns the newer of `cl:tel:<sid>` and the file, for any reader to come.

**The agent's contract.** Nothing changes on the wire: no new op, lane, field, header, refusal or setting. The `heartbeat` request and its reply are as they were, and older agents are unaffected. The agent must keep to what MAIN now relies on:
1. **Cadence.** `heartbeat` every `lb_telemetry_interval_sec` (1–3 s, default 2), never more than 3 s apart: MAIN's liveness bounds when the bus is lost assume at most 3 s. MAIN's MySQL copy (`cluster_nodes.last_seen_at`, `servers`) follows the bus by up to 5 s plus the 1 s loop, and a node is suspect after 10 s of silence.
2. **`root_ready`** (bool) in every heartbeat. MAIN keeps the last value it got from the node's current enrolment: a heartbeat without the field keeps it, and a re-enrolment starts again from 0. A changed value reaches MySQL (`cluster_nodes.root_ready`, which `CommandBus::acceptsRoot()` reads) at the next flush, within about 1 s; if `hello` wrote the node's row between that heartbeat and the flush, with the next heartbeat instead. A heartbeat with an unchanged value waits for the 5 s flush.
3. **`telemetry`** (object). It stays on the bus while MAIN's encoding of `{"at":<ms>,"auth":0|1,"telemetry":<object>}` is at most 131072 bytes. That encoding is PHP `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`, so every non-ASCII character counts as its `\uXXXX` escape (6 or 12 bytes), a whole-number float keeps its `.0`, and the wrapper adds 42 bytes. Keep the object under about 120 KiB as encoded that way (its `local` is already capped at 64 KiB). A bigger document costs MAIN a direct MySQL write per heartbeat, as before.
4. **No faster MySQL.** Nothing the agent does may depend on MAIN's MySQL copy (the server page, `servers.watchdog_data`) changing within about 6 s of a heartbeat.

**Differs from the plan.**
- **Only with a working flusher.** The plan has the health loop copy heartbeats, and says nothing of the loop being down. Here heartbeats use the bus only while a flusher has finished a clean pass within 5 s.
- **`cl:hb` besides `cl:tel:<sid>`.** Liveness needs every node's last heartbeat each second: one small hash serves that in one read, and a document is read only when it is due.
- **`servers` keeps the direct path's cadence** (5 s past `last_check_ago`, per document), not a flat 5 s copy. A flat copy of the latest document every 5 s would write every 6–10 s instead of every 6 s.
- **Authentication still reads MySQL**, the node row and its epoch, so a heartbeat still uses a DB connection for those two reads; only its writes are gone. Caching those rows on the bus needs revocation and re-enrolment to reach the cache first. The fifth bus increment does that: every writer drops the bus's copy after its MySQL write.
- **`servers.status`** is set with each `cluster_nodes` flush (every 5 s), not with each heartbeat.

**Compatibility.**
- On upgrade the bus has no `cl:flusher`, so heartbeats write MySQL until the signals daemon's first pass.
- LB builds have neither `Domain/Cluster` nor the bus.
- A rollback leaves `cl:*` keys that nothing reads. `cl:tel:*` expire, and the rest go with the next bus restart.

**Limits.**
- While the bus is in use, MySQL's `last_seen_at` and `servers` lag it by up to 5 s plus the 1 s loop. Everything that judges liveness also reads the bus, except the orphan purge (above).
- The stamp uses the bus's `TIME`, the flush cadence MAIN's clock. A bus clock step of over 5 s sends heartbeats to MySQL until the next pass.
- `cl:tel:*` have a 10 min TTL, longer than the wake-ups (60 s) and admission reservations, so under `volatile-ttl` those are evicted first. Unlike touches, heartbeats do not leave the bus past `TOUCH_MEMORY_SHARE`: the documents take at most 128 KiB per node (usually a few KiB), bounded by the node count, so they are never what fills the bus, and a share check would send every heartbeat to MySQL whenever touches hold their half of it. An evicted document is not written, and the next heartbeat's is.
- Memory: at most about 128 KiB per node, usually a few KiB.

Tests:
- `ClusterHeartbeatBusTest`, against a real redis-server on a unix socket:
  - no query at all on a heartbeat on the bus, then the flush's rows;
  - 130 s of heartbeats both ways, compared every second: the same `servers` and `servers_stats` rows, `root_ready` at once, `cluster_nodes` at most every 5 s and at most 5 s behind, the freshest equal to what each heartbeat wrote, and the last heartbeat flushed without another after it;
  - MySQL per heartbeat without the bus, without a flusher, with a stale one (also a stamp ahead of the bus's clock), with one MySQL refuses (`cluster_nodes`, or the `servers` telemetry write, retried at the next pass), and for a document over the cap;
  - an older bus value that never overwrites a newer `last_seen_at`, and a clock step back;
  - telemetry judged on the time MAIN heard it, never written over a newer direct write;
  - no `servers` write for a node that is not authoritative (no TELEMETRY, or mode 0);
  - a heartbeat without `root_ready` or `telemetry` keeping the last ones; a `root_ready` change that `hello` overtook, flushed with the next heartbeat;
  - two flushers, one writing, and each giving back only its own lock;
  - a node forgotten after 10 min with its record and document, only once flushed, and not when a heartbeat came during the pass;
  - a re-enrolment and a revocation dropping the node's heartbeats, and an in-flight heartbeat of the old enrolment kept out of the new row;
  - the newer of the bus copy and the shadow file;
  - liveness on bus-only freshness (ok, suspect, offline, and MySQL winning when newer), the fleet guard, silence from `ready_at`, and no node suspect when the bus is lost just before a flush;
  - the bus killed, and the bus restarted empty between a heartbeat and its flush;
  - the Cluster Nodes page.
- `ClusterApiTest`: a heartbeat end to end on the bus, with only the two authentication reads in MySQL, then the flush. Since the fifth bus increment those are the first request's after `enrol_complete`; the next ones send no query of their own.

### The cluster bus (Phase 2, fourth increment): ingest permits

**Before.** The `cluster_ingest` pool bounded how many ingest requests ran at once, but nothing kept a P0 batch from waiting behind bulk: a burst of snapshot chunks, `config` polls and log batches could hold every worker, and every MySQL connection the pool may open. The plan (section 8) keeps `ceil(cluster_ingest_concurrency / 2)` permits for P0, and has MAIN answer 503 with `retry_after_ms` at once when no permit is free.

**Now.** Every ingest op the API serves holds an ingest permit on the cluster bus while its handler runs (`ClusterSemaphore::runIngest`):

| Lane | Requests | Permits it may take |
| --- | --- | --- |
| `p0` | `events` with `lane: "p0"` | its reserve, ceil(n / 2), always; any other free one but bulk's reserve |
| `bulk` | every other ingest op: `events` with `lane` `p1`, `p2` or none, `config`, `conn_snapshot`, `recording_complete`, `streams` (since the ninth Phase 7 increment), and the ingest ops still to come (`ClusterPool::INGEST_OPS`) | its reserve, 1, always; up to its share, n − ceil(n / 2) and at least 1, while P0's reserve stays free |

- **n** is `cluster_ingest_concurrency` (1–64, default 6), from the settings the request is served with. The total is n, or 2 at n = 1: one P0 and one bulk, as the ingest pool's floor (`ClusterSemaphore::ingestPermits`). At the default, 3 are kept for P0, 1 for bulk (`BULK_RESERVE`), and 2 are shared.
- **The sets** are `sem:ingest:p0` and `sem:ingest:bulk`: sorted sets without a TTL, scored by each permit's expiry, as the per-op semaphores. One script prunes and counts both, then takes the permit or refuses it:
  - a lane under its reserve (P0 ceil(n / 2), bulk 1) always gets a permit;
  - past its reserve, a lane is refused when the permits held, plus those of the other lane's reserve it does not hold yet, reach the total. So P0 never takes bulk's last permit, and bulk never takes P0's reserve;
  - bulk is also refused once it holds its share;
  - so a lowered n never shuts either lane out while the other still holds permits taken before.
- **When.** After the nonce claim, the node state and any per-op permit (`config`, `conn_snapshot`), and after the BOX is opened, since only the BOX says a batch's lane. Opening it touches no database (Phase 1's bench: 64 KB under 1 ms, 8 MB under 40 ms), and a BOX that does not open is 400 `BAD_REQUEST` without a permit. Then the epoch is marked used and the handler runs. The permit is given back in `finally`, however the handler ends.
- **Crashes.** The permit of a holder that died expires after the `cluster_ingest` pool's timeout, 90 s (`INGEST_LIFE`), by the bus's clock. One expiring past now + 90 s + 1 s was taken before the clock stepped back, and is dropped. Both as for the per-op semaphores.
- **Control ops** (`hello`, `heartbeat`, `commands`, `ack`, `conn_admit`, the token ops) take none.
- **Without the bus,** or when the script fails, no permit is taken, as before.

**Wire: the busy refusal on an ingest op.** When the request's lane has no free permit, its handler does not run, and the node gets the per-op semaphores' denial: panel-signed (`den`), naming the node and the request nonce, with `main_time_ms`. Its fields:
- status 503;
- `reason`: `RATE_LIMITED`;
- `retry_after_ms`: int, drawn at random per refusal: 250–750 for lane `p0`, 1000–3000 for lane `bulk`;
- `op`: string, the op refused: `events`, `config`, `conn_snapshot` or `recording_complete`, and `streams` since the ninth Phase 7 increment;
- `lane`: string, `p0` or `bulk`: the permit's lane, named as plan section 3's transport lanes. A per-op semaphore's refusal has no `lane`.

A `config`, `conn_snapshot` or (since the ninth Phase 7 increment) `streams` request can be refused by either: by its op's semaphore (no `lane`), or past it by the bulk lane (`lane: "bulk"`). Nothing is applied either way.

**What today's agent does.** It already handles a 503 `RATE_LIMITED` on every ingest op (`busyWait`: `retry_after_ms` ±10 %, clamped to 1–60 s):
- `events` P0 and P1: the lane's in-flight batch (`<lane>.inflight`) is kept and sent again, with the same `first_useq`, after the longer of the wait and twice the lane's interval, at most 30 s (`RunEvents`): P0 (200 ms) after about 1 s, P1 (5 s) after 10 s. The refusal is logged as an error.
- `events` P2 (`touch.go`): the touches stay due and go after the longer of the wait and 20 s (twice its 10 s loop).
- `config`: `RunReplica` asks again after the wait.
- `conn_snapshot`: the chunk goes again with the same `snap_id` and `seq`, up to 20 times.
- `recording_complete` comes from the node's PHP (`RecordCommand`) through the agent's socket, which hands any refusal back as a bare 409. Until now the node's PHP then marked the recording failed and deleted its `.ts`; it now asks again while no answer comes (`AgentClient::mainRetrying`: after 1, 2, 4, 8, 15, 30, 30 and 30 s, two minutes of waits; with each try's own timeout of up to 15 s, about four minutes at most). It also asks again after a timeout, while MAIN may still be running the first request: the agent's MAIN client gives up after 10 s and answers the socket with a 502, and MAIN's worker may run for 90 s. Every call gets the same VOD, overlapping or not: `RecordingFinalizer::create` records its new row as the recording's `created_id` only while none is recorded, then reads it back. A call that lost deletes its row, before any bouquet has it, and returns the winner's. Before, two overlapping calls each made a VOD.

All of this is safe. P0 is slower than the plan wants: a refused P0 batch waits about 1 s, and P0 shares the agent's keep-alive connections with bulk.

**The agent's contract.** For the Go half, built in xc_vm_fanout #31:
1. **503 `RATE_LIMITED` with `lane`**, a verified denial to an ingest op, means MAIN's permits for that lane are all held: busy, not failing. Do not count it as a failure or log it as an error (a counter is enough), and wait only as below. "The busy wait" is today's `busyWait`: `retry_after_ms` ±10 % jitter, clamped to 1–60 s. Every resend has a fresh nonce, stamp, MAC and BOX.
   - **Lane `p0`** (an `events` batch with `lane: "p0"`): wait `retry_after_ms` (250–750), jitter only adding, up to 10 %, and no 1 s floor; clamp to 100 ms–5 s. Then resend the same in-flight batch (same `first_useq`, same events). No later P0 batch goes first. The lane's 200 ms interval does not change.
   - **Lane `bulk`, `events`** with `lane` `p1` or `p2`: the lane keeps a current interval, which starts at its normal one (P1 5 s, P2 10 s). On each such refusal, double it, up to 60 s, then send again after the longer of the busy wait and the current interval. P1 sends the same in-flight batch (same `first_useq`, same events). P2 has no in-flight batch: it sends the touches due at that time, as `touch.go` gathers them on every send. After each batch MAIN serves (200) on the lane, halve the current interval, down to the normal one, and send the lane's next batch after it. Any other failure keeps today's backoff and leaves the current interval as it is. The batch limits (2000 events, 1 MiB) stay.
   - **Lane `bulk`, `config`:** ask again after the busy wait, not at the next minute's poll, as for the per-op refusal.
   - **Lane `bulk`, `conn_snapshot`:** resend the refused chunk with the same `snap_id` and `seq` after the busy wait, as for the per-op refusal: up to 20 times per chunk (`SnapshotBusyRetries`), as today.
   - **Lane `bulk`, `recording_complete`** (a socket op): the agent answers the socket within 12 s of the request's arrival, since the PHP caller waits 15 s. It may send the op again after the busy wait only when that wait plus a whole try (10 s, its MAIN client's timeout) still ends by then; otherwise it hands the refusal back at once. A refusal it hands back stays a 409, and the node's PHP asks again on its own (above). Any call, even one overlapping a request MAIN is still running, gets the same VOD.
   - **Lane `bulk`, any other ingest op** (`streams` since the ninth Phase 7 increment, `artefact` since the third Phase 4 increment, and those still to come: `stream_bundle`, `rpc_result`, `vod_analysis`, the queue ops): send the same request again after the busy wait, without raising the op's backoff or counting a failure.
2. **P0 has its own connection** (plan section 8): send P0 `events` over a keep-alive connection of their own (their own `http.Transport`, one request in flight), so a bulk upload never queues them.
3. **Nothing else changes:** no new op, header, file or setting. The one new field is `lane` on the 503 `RATE_LIMITED`. A 503 `RATE_LIMITED` without `lane` is a per-op semaphore's, handled as in the second bus increment.

**Differs from the plan.**
- **Bulk keeps one permit.** Without one, P0 could hold every permit, and `config`, snapshots, logs and recordings would not be served while it does, which the plan's 200 event POSTs a second at 50 LBs can reach at peak. So one permit is kept for bulk, as ceil(n / 2) are for P0, and n = 1 gives 2 permits, one P0 and one bulk, as the ingest pool's floor of 2.
- **P0 may take the shared permits** as well as its reserve; the plan names only the reserve. Bulk is then refused past its reserve while P0 holds them, which is the priority the plan asks for.
- **The permit is taken once the BOX is open**, not before it as the per-op semaphores are, since only the BOX says a batch's lane. A refused request costs MAIN one decryption more than a per-op refusal does, and writes nothing to MySQL.
- **`retry_after_ms` is 250–750 ms for P0** and 1–3 s for bulk. The plan gives no range.

**Compatibility.**
- Older agents keep working: they already handle a 503 `RATE_LIMITED` on every ingest op (above), and ignore `lane`. The node's PHP retries `recording_complete` with any agent.
- A rollback leaves the two sets, empty once their requests end; nothing reads them.
- LB builds have neither `Domain/Cluster` nor the bus. `AgentClient` and `RecordCommand` ship to LBs, and reference no `Domain\Cluster` class.

**Limits.**
- **Authentication still reads MySQL** (the node and its epoch) before any permit, so the plan's "MySQL opens only inside ingest or ctl permits" holds for everything but those two reads (third bus increment). Since the fifth bus increment it reads them from the bus, and MySQL only on a miss.
- **The pool size is not counted.** The permits are n (at least 2), and the `cluster_ingest` pool has min(2n + 8, floor(0.25 · `max_connections`)) workers, at least 2. When MariaDB's `max_connections` leaves the pool with no more workers than bulk's share (`max_connections` under 16 at the default n), bulk can hold every worker, and a P0 batch waits on the socket although a permit is free.
- A worker that cannot reach the bus runs ingest without a permit, as the per-op semaphores do.
- A changed n applies to the requests that read it; permits already held count against the new limits until their requests end.

Tests:
- `ClusterSemaphoreTest`:
  - the split: ceil(n / 2) for P0, the rest for bulk, at least 1, and the setting's range (both ends) and default;
  - the lane: only `events` with `lane: "p0"` is P0, every other ingest op is bulk, and control ops take none;
  - no permit without the bus;
  - P0 getting its reserve while bulk holds its share, and bulk refused when only reserved permits are free;
  - P0 taking the shared permits but never bulk's reserve, with bulk refused past it meanwhile;
  - each lane's reserve at n = 1, 2, 5, 6 and 64, and after n is lowered;
  - the signed 503 with `retry_after_ms` (each lane's range), `op` and `lane`;
  - release after the handler returns or throws, for both lanes;
  - expiry after 90 s by the bus's clock, both sets pruned by either lane, and a permit from before a clock step.
- `ClusterApiTest`:
  - with bulk's share held, `events` P1, `config`, `conn_snapshot` and `recording_complete` refused with `lane: "bulk"`, their per-op permits given back and the P1 batch not applied;
  - a P0 batch served from the reserve, its permit given back, and `hello` untouched;
  - P0 refused with `lane: "p0"` and P0's range once every permit is held;
  - the refused batch resent and applied once;
  - `cluster_ingest_concurrency` 2 sizing the permits;
  - `BAD_MAC`, `REPLAY` and `NOT_ACTIVE` before any permit, and a refused request writing nothing to MySQL;
  - a new epoch's first request refused a permit: the epoch not marked used, and marked once a request is served;
  - no permit without the bus (none at the checkout's default socket either).
- `AgentClientRetryTest`: `recording_complete` asked again after each wait until answered, at once when answered, with no agent at all, and given up after the last wait, one try after each; `RecordCommand::vodFor` asking MAIN this way on a node whose CONTENT flow is on.
- `ClusterContentTest`: two overlapping `RecordingFinalizer::create` calls making one VOD, added to its bouquets once.

### The cluster bus (Phase 2, fifth increment): authentication

**Before.** Every authenticated request read the node's `cluster_nodes` row (`NodeRegistry::byUuid`) and the epoch it names (`TokenService::epoch`): two SELECTs, before its MAC was checked. A heartbeat on the bus wrote nothing to MySQL (third increment) but still read it twice, and the entry point read MAIN's `servers` row for every request too. The plan (section 8, "MAIN capacity") has heartbeats and long-polls hold no DB connection.

**Now.** While the bus runs, `Domain\Cluster\NodeAuthCache::load()` serves what those two reads returned, and reads MySQL only on a miss:
- `cl:auth:<uuid>`: the node's row, as `<sid>:<version>:<filled at, MAIN ms>:<json>`. Every column but `row_mac` and `attest`, which no request reads. Binary values (the node's keys) are `{"b64": …}`, so they come back byte for byte.
- `cl:auth:<uuid>:<epoch>`: that epoch's `record` (base64) and `exp`, in the same form. The record is what MySQL keeps for this purpose: the extension's epoch record, sealed to MAIN's machine with the node uuid as context. The sealed token and the agent's per-epoch key are not kept.
- Both live 30 s (`TTL_MS`). A held epoch whose `exp` has passed is not served, as MySQL's `exp > now` does not return it.
- An unknown node, and an epoch MySQL does not hold live, are never kept. Each such request reads MySQL, as before, so nobody fills the bus by naming nodes or epochs that do not exist. A revoked node's epoch is not read, as before.
- With the row held and the epoch not (the first request of a new epoch), the epoch alone is read.

So a heartbeat on the bus sends MySQL no query of its own, and since a later change opens no connection at all (see Limits). That holds with the settings file cache on (the default; without it the entry point reads `settings` for every request) and a heartbeat flusher running (third increment; without one a heartbeat writes `cluster_nodes`). A `commands` long-poll reads only `cluster_commands`, and writes nothing while it hands out no command and its `after_seq` is not above the held row's `cmd_seq`. A poll whose `after_seq` is above it also reads `MAX(seq)` from `cluster_commands` (the cap, see Commands) and sends one conditional `UPDATE` of `cluster_nodes.cmd_seq` when the cap is above the held row's `cmd_seq`; a poll that hands out commands sends one more for the highest `seq` it hands out. So a poll sends at most two conditional `UPDATE`s, which may repeat on each poll while the held row's `cmd_seq` lags (up to `NodeAuthCache::TTL_MS`). Every other op saves the two reads and reads what its handler needs.

**Writers.** An entry counts only while it carries its node's version: `cl:auth_ver`, a hash with a field per server id and no TTL. Every writer of what authentication reads calls `NodeAuthCache::forget()` right after its MySQL write:

| Writer | Callers |
| --- | --- |
| `NodeRegistry::update()` | `enrol_complete`; `hello` (the clone quarantine, the instance and endpoint columns); `token_rekey` (the attestation's quarantine, the boot fields); a heartbeat's endpoint columns; `TokenService::markUsed()`; the Cluster Nodes page's flow switches; and any later writer that uses it |
| `NodeRegistry::startEnrolment()` | re-enrolment (install, `server:enrol`, `cluster:reenrol`) and code approval |
| `NodeRegistry::revoke()` | through `update()`, after its epochs are dropped |
| `TokenService::issue()` | every mint: a refresh retried with another key mints the same number again, with a new record |
| `TokenService::rekey()` | after it drops the node's other epochs |

`forget()` raises the node's version, so its next request reads MySQL, and `cl:auth_seq`:
- **The fill guard.** `load()` reads `cl:auth_seq` in the script that reads the entries, before MySQL. It keeps what it then read from MySQL only while the sequence is unchanged, checked in the script that writes it. A request that read a row before a write and fills after it therefore fills nothing. Every write raises the sequence, so a fill racing any node's write is dropped, which costs that node one more miss.
- **A lost bus.** The sequence starts at a random value below 2^52 the first time a script finds it missing. A request that read it from a bus that has since restarted or been flushed never matches the new one.
- **Columns that lag.** The heartbeat flush (`last_seen_at`, `clock_offset_ms`, `root_ready`, `updated_at`), the event cursors (`useq_p0`, `useq_p1`) and the command high-water (`cmd_seq`) are written without the registry (`NodeAuthCache::LAGGING`). They may be up to 30 s behind in an entry, and a registry write of those columns alone (a heartbeat without a flusher) keeps it. What needs them current reads MySQL: `hello` reads its row again for the `cursors` it returns, `EventIngest` reads its cursor under its lock and, for `node.inventory`'s `time_offset`, the node's clock offset (the held row's may be the previous run's, or none, for up to 30 s after the agent restarts: `hello` drops the entry, and the first heartbeat fills it again before its own offset is flushed), and `CommandBus::enqueue()` reads the row itself.
- **A writer that cannot reach the bus.** The bus socket exists, but the script fails: a worker in its 5 s pause after a failed connect, a lost connection, or a full bus that refuses the script's first write. `forget()` then marks the second in `bin/cluster_bus/auth.stale` (`ClusterBus::mark()`, the nonce marks' rules, shared since this increment). A mark that root creates goes to the bus directory's owner, so the workers can move it on. Nothing filled before the end of the second after the mark counts, and nothing is filled until then: those requests read MySQL. Without a socket there is nothing to drop, and a bus that starts is empty.

**Without the bus.** Not started, an LB, a test, or a failed script: `load()` reads MySQL, the same two SELECTs in the same order, exactly as before.

**The entry point.** `Public/cluster/index.php` reads MAIN's `servers` row only for the ops that use it (`ClusterApi::readsMain()`): `challenge`, `enrol_complete`, `hello` and `config`, and any op not listed as not reading it, such as an op still to come. It still connects to MySQL first, so a MAIN whose database is down still answers every op with the signed `503 DB` at once.

**The agent's contract.** No wire change: no new op, field, header, refusal, file or setting, and nothing for the agent to build. MAIN keeps these, which the agent may rely on, as before this increment:
1. A change MAIN stores for a node (revoked, quarantined, re-enrolled, a new mode or flows, an epoch minted again under its number, the epochs a re-key drops) applies from the node's first request that arrives after the change is stored. A request already past authentication (a held long-poll) finishes, as before.
2. `UNKNOWN_NODE`, which stops the agent, still comes only from MySQL: the bus never holds a node MySQL does not.
3. A `hello`'s `cursors` are MySQL's when the hello is served.
4. The heartbeat reply's `state`, `mode` and `flows` are as current as before.

**Differs from the plan.**
- **A cache the plan does not name.** Section 8 has heartbeats and long-polls hold no DB connection, which authentication's two reads broke (third and fourth increments). This increment removes the reads; the connection stays (Limits).
- **Versions, not deletes.** A writer knows the server id and a request the uuid. Raising the node's version drops every entry of the node without knowing their keys, and they expire.
- **One fill sequence for all nodes.** A fill guarded by its node's own version would need the server id before the MySQL read, and only that read gives it.

**Compatibility.**
- Older agents are unaffected: nothing on the wire changes.
- On upgrade the bus holds no `cl:auth*` keys, so each node's first request reads MySQL.
- A rollback leaves `cl:auth:*`, gone within 30 s, `cl:auth_ver` and `cl:auth_seq`, which nothing reads until the next bus restart drops them, and `auth.stale`, harmless.
- LB builds have neither `Domain/Cluster` nor the bus.

**Limits.**
- A write that bypasses the registry and `TokenService` is seen within 30 s: a backup restored into `cluster_nodes`, a manual SQL edit, or a new writer that does not call `forget()`. A restored row still cannot re-activate a revoked node: the extension's generation floor refuses its record, with or without the bus.
- So is a write whose request dies between its MySQL write and its `forget()`, or whose writer can reach neither the bus nor the mark.
- A manual `DEL` of `cl:auth_ver` while entries remain can make an entry filled before a write count again, until it expires. The shipped `cluster.conf` never evicts it (`volatile-ttl`, and it has no TTL).
- The rules assume MAIN's clock does not step back by more than a second, as the nonce marks do.
- The entry point opened a MySQL connection for every request (`SET NAMES` and the session timeouts ran on it), so a heartbeat held one while it was served. Built later: it takes a graceful `LazyDatabaseHandler`, which connects at the first query. A connect that fails throws `DatabaseUnavailableException`, and so does every later use of the handle, without the reconnect loop a query would otherwise run. The entry point's own reads (the settings without the file cache, MAIN's row) answer it with `503 DB` as before, and `ClusterApi::serve()` answers one thrown inside an op with the same signed `503 DB`, where a handler does not answer it itself. The difference an agent can see is that MySQL down is found by the first query, not before the op starts, so an op served from the bus alone (a heartbeat) is answered while MySQL is down. `LazyDatabaseHandlerTest::testAGracefulHandleThrowsWhenTheDatabaseIsDown` and `ClusterApiTest::testADatabaseDownAtTheFirstQueryIsASigned503Db` cover it.
- Memory: about 1 KiB per node for the row, and the record's size (at most 2 KiB, base64-encoded) per epoch in use, each for 30 s.

Tests:
- `ClusterAuthCacheTest`, against a real redis-server on a unix socket:
  - MySQL for every request without the bus, and nothing marked;
  - on the bus, the second request asking MySQL nothing, with the row and record byte for byte, a TTL of at most 30 s, no token, and no key for an unknown node or epoch;
  - a registry write read at the next request while other nodes stay held, and a write of lagging columns alone keeping the entry;
  - each registry writer (mode, flows, epoch, revocation, re-enrolment) dropping the entry;
  - each writer (`update()`, `issue()`, `startEnrolment()`) raising the version after its MySQL write: a row or record a request read just before the write, and kept, not served afterwards;
  - the epoch read alone while the row is held, an expired epoch not served, a revoked node's epoch never read;
  - a write between a request's MySQL read and its fill, for the row and for the epoch alone, and a bus flushed in between: nothing filled;
  - a bus restarted empty between a request's read and its fill, a writer that found no socket meanwhile, and another request starting the new bus's sequence: the fill does not match it;
  - a request filling epoch 1 while a re-key mints epoch 2: epoch 1 not served afterwards;
  - a writer out of reach marking the second, entries held before it not counted, nothing filled or counted within the next second, and entries counted again after it;
  - a killed bus: MySQL, and its writers marking.
- `ClusterApiTest`:
  - a held heartbeat sending MySQL no query of its own, and a long-poll only `cluster_commands` queries;
  - a heartbeat reporting a new `policy_ver` and MAIN port: written once, the row read again once, then no query;
  - the next request seeing `enrol_complete`, a revocation (`revoked_gen` of the new generation), a clone quarantine from `hello` and from `token_rekey`, an admin's flow switch, an epoch minted again under its number, the epochs a re-key drops, a re-enrolment, and a new epoch marked once;
  - `hello`'s cursors after a batch, with the row held;
  - `readsMain()`, no dispatch arm of the listed ops passing MAIN's row, and a heartbeat and a long-poll served without it.
- `ClusterBusTest`: a mark root creates handed to the owner of the bus's directory, and moved on later (root only).
- `ClusterEventsTest`: `node.inventory`'s `time_offset` from MySQL's clock offset, not the request row's.

### Blocklist delta (Phase 7, first increment)

**The log.** Every path that blocks or unblocks something appends to `cluster_changes` (section `blocklist`) through `Core/Cluster/BlocklistChanges`. No triggers are used. Each row names the kind and the key that changed:

| Kind | Table | Key |
| --- | --- | --- |
| `ip` | `blocked_ips` | the address |
| `ua` | `blocked_uas` | `id` |
| `isp` | `blocked_isps` | `id` |
| `asn` | `blocked_asns` | `id` |
| `rtmp` | `rtmp_ips` | `id` |

A row does not say what the key became; MAIN reads that when it serves the change. Bulk changes record `reset` for their kind instead of keys: a flush, a whole ASN type, a migration from another panel, or more than 500 keys at once. Recording never fails the block itself.

**The paths.** The admin's block, edit and delete of each kind (`BlocklistService`, the ASN and MySQL-syslog actions, the ASN bulk buttons), the flushes (admin, API, `tools`), the flood guard on MAIN and on legacy LBs, the Ministra portal's bans, `cache_handler`'s signals, `security.block_ip`, MAIN's auto-unban in `cron:root_signals` (which now selects what it removes), and the migration. The ASN catalog sync is exempt: it only upserts reference columns and prunes unblocked ASNs, so no blocked ASN changes. `BlocklistDeltaTest` fails when a new file writes a blocklist table without logging it.

**The read.** `Domain/Cluster/BlocklistDelta::since($id)` gives a node everything past the last id it applied:

- Blocked IPs, the kind the flood guard changes all the time, come as `add` and `remove` lists. Each changed address is added if it is blocked now and removed otherwise, so the order of two changes to one address never matters.
- Every other kind changes rarely and by hand. A change to it, or a bulk `reset`, names the kind in `reload`, and the node takes the whole section again.
- `full` means there is no starting point: `since` is 0, the log was pruned past it, or the log went backwards (a restore).

`snapshot()` is the whole list. It holds each blocked address and ASN, and the user-agent, ISP and RTMP rows. The node needs the RTMP password to check publishers, so it is in the section; the section is sealed to the node.

**Pruning.** `cron:cluster` keeps seven days of the log, and always keeps its newest row, so a quiet week does not send every node into a full reload. It prunes even while the cluster API is off, because the log is written either way.

`allowed_ips`, `proxy_servers` and `allowed_domains` are not in this section. They come from `servers` (with its `reseller_dns`) and the settings, so they travel with those sections.

### The replica transport (Phase 7, second increment)

**Records.** `Domain/Cluster/ReplicaBuilder` builds what a node keeps. Each record is panel-signed and sealed to the node's X25519 key, with purpose `replica` and the node uuid as context:

```text
record = SEAL(node_box_pub, "replica", node_uuid, u32(len) ‖ payload ‖ sig(tag, payload))
rep  {v, section, node, gen, etag, seq, iat, data}   a whole section (granting)
blk  {v, seq, iat, add, remove}                      a blocklist delta
```

A `rep` record names the node and its generation. The ETag is the SHA-256 of the section's canonical data: keys sorted, numbers typed the same whichever driver read them.

`blk` is the extension's record, and its strict keys admit only plain strings. So only blocked IPs travel as deltas. Additions only restrict, so they sign without a licence and bans reach nodes even then. A removal, or a whole section, grants and needs the licence; without one, MAIN answers `LICENCE_INVALID` and the node keeps what it has.

**The `config` op.** The node sends `{blocklist_since, have: {blocklist: etag}}`. MAIN answers with one of four outcomes:

- a `blk` delta, when only IPs changed;
- the whole section, when there is no starting point or another kind changed;
- `unchanged`, when the node already holds the section's current ETag;
- nothing, when nothing changed.

A section carries the log head read before the snapshot, so a change made in between comes again as a delta. The op needs an active node but no flow, because the node fetches its replica in shadow before its CONFIG flow is switched on.

**The agent.** `internal/clusteragent/replica.go` calls `config` every minute, and again at once while `more` is set. It stores only records that open for this node and verify against the pinned panel key, and a `rep` must name this node and match what the reply announced. It keeps them as they came, under `config/cluster/replica/`: `blocklist.rep`, the deltas since it in `blocklist.d/<seq>.blk`, and `state.json`. A new section removes the deltas. Once a day, or past 1000 deltas, it asks from 0 with the ETag it holds, which is the plan's daily safety net. The plan puts the replica under `var/cluster/replica/`; it lives beside the agent's other state instead.

### Applying the blocklist (Phase 7, third increment)

**Materialising.** After a change, the agent writes `replica/blocklist.json`: the stored section with its deltas applied in seq order. It opens and verifies each record again first, and PHP holds no key to open them. It then runs `console.php cluster:apply` (`ClusterApplyCommand`, with the work in `Core/Cluster/ReplicaApply`).

**The caches.** `cluster:apply` turns the file into the four caches `cron:cache` builds from MAIN's database, in the same shapes, so every reader keeps its contract:

| Cache | Source |
| --- | --- |
| `blocked_ips` | the addresses |
| `blocked_servers` | the blocked ASNs |
| `blocked_ua` | `[id => {id, exact_match, blocked_ua}]`, lower-cased |
| `blocked_isp` | `[{id, isp, blocked}]` |
| `rtmp_ips` | `[resolved ip => {password, push, pull}]`, as MAIN's `cron:cache` builds it; an LB used to read the database for this |

What it does depends on the node's CONFIG flow:

- **CONFIG off (shadow).** Nothing is written. `replica/apply.json` counts, per cache, entries the database has that the replica lacks (`missing`) and the reverse (`extra`). Rows are compared by value, whichever driver typed them. Zeros there are the evidence for switching CONFIG on.
- **CONFIG on.** The replica writes the caches. `cron:cache` stops writing them, and `BlocklistService::getBlocked*` read the cache instead of refreshing it from the database. Without that, the next reader would overwrite the replica within 20 s.

The shadow diff for `rtmp_ips` compares against the database, because an LB never cached it. With CONFIG on, three more readers switch to the replica:

- `getAllowedRTMP`, which `rtmp.php` calls, reads the cache.
- `cron:root_signals` syncs iptables from the replica's `blocked_ips` cache (`RootSignalsCronJob::blockedIPs`).
- When that cache is not there yet, the sync leaves iptables as it is rather than unblocking everything.

**Not built:** the other R1 sections (`settings` with its allowlist, `secrets`, `servers`, `node`, `crontab`, `cluster`), `ReplicaStage`, and the mode-2 refusal. On the blocklist path, the root flush still arrives as a `signals` row, until `node.root blocklist_sync` replaces it. The eighth Phase 7 increment built the refusal, and runs the flush from the `node.root` command MAIN already sent where it sends one.

### Switching the CONFIG flow (Phase 7, fifteenth increment)

Every Phase 7 reader keys off `NodeRegistry::FLOW_CONFIG` (`ReplicaBoot`, `ReplicaApply`, `BlocklistService`, `CacheCronJob`, `RootSignalsCronJob`, `BruteforceGuard`, `cluster:apply`), but `ClusterAdmin::FLOW_BITS` — the list the *Cluster Nodes* page renders a button from, and the only writer of `cluster_nodes.flows` — did not carry it. The replica was built, tested and switchable by nobody.

`config` joins the list between `content` and `connections`, with the two actions the page posts (`config_on`, `config_off`) and its own column. `NodeRegistry::validFlows()` needed no new rule: CONFIG has no prerequisite, and the existing ones (CONNECTIONS needs COMMANDS and STREAMS, DATAPLANE needs STREAMS and CONTENT) already refuse the combinations that matter. `ClusterFlowSwitchTest` pins the header row against `FLOW_BITS`, because the page renders a fixed header and one cell per entry, and pins that each flow has its four strings.

The new strings are English-only until `make lang-translate` runs (it needs the docs venv and a provider key); the Translator copies English for a missing key meanwhile.

**Not built:** promoting a node's `mode`. `mode` is written once at enrolment from `lb_new_node_mode`, and `api_mode_allowed` is still false (`Domain\Server\SettingsService`), so CONFIG can be switched on a node in mode 0 or 1 — where it is read — but no node runs mode 2 yet. That, and the gate that reads the connect audit before promoting, is Phase 9.

### Promoting a node's mode (Phase 9, first increment)

`cluster_nodes.mode` was written once, at enrolment, from `lb_new_node_mode`. Nothing moved a node afterwards, so the modes every reader honours (mode ≥ 1 boots from the replica, mode 2 refuses MAIN's database through `LbDatabaseAccessException`) were unreachable.

The Cluster Nodes page gets `mode_up` and `mode_down` beside the mode, and `ClusterAdmin::modeGate()` decides:

- **Down is always allowed.** It is the way back when a node misbehaves.
- **Up to 1** needs the CONFIG flow: that is what the node boots from.
- **Up to 2** needs every flow but the data plane (`ClusterAdmin::MODE2_FLOWS`, Phase 8 is not built), and the node's own connect audit must report `sql_connects` and `redis_connects` at zero with `connects_since` at least `CUTOVER_CLEAN_DAYS` (7) old. That is the plan's cutover gate, read from the report the node already sends in its heartbeat (`NodeAudit`), which the page has been showing all along.

The gate is pure and tested (`ClusterModeGateTest`) rather than reached through a request. The agent learns its new mode from the `mode` its next `hello` or `heartbeat` answer carries, and `NodeAuthCache` drops its copy of the row because `mode` is not a lagging column.

**Not built:** `api_mode_allowed` is still false (`Domain\Server\SettingsService`), so `lb_new_node_mode` cannot be set to `api` and a *new* node still enrols at mode 1: `legacy`, the default, gives an enrolled mode-1 node, as the plan's settings table has it (`EnrolmentService::issueFirst()` and `EnrolCodeService`'s approval both map it so). Promotion is the supported path to mode 2 for now. Flipping that flag is the cutover decision itself, and it stays with the operator.

### The fleet-wide jobs nobody came back for (Phase 0, second increment)

Six places still treated every node as MAIN, or MAIN as every node:

- **Root's crontab had two writers.** `startup` installed `cron:root_signals`, `cluster:root`, `cron:root_mysql` and the module licences; `status` then rewrote the same crontab from its own older list, filtering every `# XC_VM` line — so it deleted `cluster:root` and `cron:module_licenses`. A node stopped draining MAIN's signed root commands until its unit was next started, and the dashboard tells admins to run `status` by hand. `status` now calls `StartupCommand::installRootCrontab()`; one writer, one list.
- **`update_binaries` went to every server**, and the DELETE that clears the old signals ran once per server inside the loop. `binaries` needs `bin/install/update_binaries.sh`, which the LB build strips, so every LB answered "Updater script not found" and wrote a syslog row saying it was updating. MAIN alone is queued now. LB runtimes come from the install flow, and `fanout_binary`/`xcvm_core` keep themselves current on every node.
- **The LB update broadcast tested nothing.** `($rServer['enabled'] && status == 1 && fresh) || !$rServer['is_main']` is true for every non-MAIN row, so a disabled or long-dead LB was queued an update anyway. It skips MAIN (which is updating itself) and requires the liveness the condition always meant to.
- **`update_data` is one row for the cluster.** Whichever server finished an update cleared it, so an LB threw away MAIN's pending update record. MAIN clears it.
- **The panel-log upload was gated on mode 2** rather than on MAIN, so a mode 0 or 1 LB uploaded the cluster-wide `panel_logs` and marked the rows sent — MAIN then had nothing to send.
- **`cron:cache_engine` was started on every boot of every node.** The LB build strips `CacheEngineCronJob`, and an LB never writes `cache_complete`, so the exec answered "Unknown command" every time. It runs where the job exists, which asks no database (a mode 2 node has none).

`api_probe` also asked a parent for codecs over `/probe/` without looking at where that parent was: for a loopback parent the node asked itself, mid-analysis, over an unauthenticated endpoint. It reuses `NetworkUtils::probeTargetAllowed()` and falls through to ffprobe.

### The node system API's own inputs (Phase 4, fifth increment)

`/api` is the legacy control plane, and the cluster command channel routes four of its actions to a node unchanged. Three took an input from MAIN and used it as given, which made MAIN's cluster-wide secret the only thing between a caller and the node's filesystem:

- **Paths.** `scandir`, `scandir_recursive` and `getFile` took any absolute path. `getFile` asked only for an extension from its allowlist and `is_readable`. `ClusterSettings::pathAllowed()` now confines them to `lb_scan_roots` (default `/home/xc_vm/content`, `/mnt`, `/media`), which existed as a setting but had no reader. `getFile` passes `MAIN_HOME` as an extra root: its callers want VOD sources (scan roots) and panel files (certbot logs at `BIN_PATH`, subtitles, module archives). Both sides go through `realpath()`, so `..` and a symlink pointing out of a root are refused rather than string-matched, and a path that does not exist is refused.
- **Probe targets.** `probe` handed its URL to ffprobe, which follows what it is given: the node's own loopback services, `169.254.169.254`, and `file:`/`concat:` for local reads. `NetworkUtils::probeTargetAllowed()` requires `http`/`https` and refuses a host that is — or resolves to — loopback, link-local, unspecified or multicast. Private LAN ranges stay allowed, because parents and proxies sit on them. The host is resolved here and again by ffprobe, so a hostile resolver can still answer differently between the two; the endpoint is reachable only with MAIN's secret from an allowlisted address, and the guard is about MAIN's own inputs, not about a DNS attacker.
- **Process lines.** `get_pids` returned whole `ps` command lines. A producer's argv carries the source URL, and a relay's carries a viewer token, so provider credentials reached MAIN's admin UI and were stored in `cluster_commands.result`. It now runs `Redactor::redact()` over every line, as the docblock already claimed and as `stream.state` and the log sinks do.

`NetworkUtils::ipInCIDR()` compared addresses with `ip2long()`, which returns `false` for IPv6: `false & mask` made **every** IPv6 address match **every** IPv6 range. It compares packed bytes now and refuses a mismatched family. It had no callers before this increment.

**Not built:** `/xfile` (Phase 8) will inherit `serveFile`'s confinement, and the ticket that replaces `password=` in its URL is still Phase 8 work.

### The encoding queue (Phase 5, sixth increment)

`queue_enqueue`, `queue_claim` and `queue_update` were named in `ClusterPool::INGEST_OPS` (which renders nginx's ingest lane) and nowhere else: no handler on MAIN, no caller on a node. The `queue` table is MAIN's, so on a node booted from its replica the queue daemon looped on `$db->ping()` against a database it must not dial, and `cron:vod` skipped queueing entirely (an explicit `NodeRole::refusesConnects()` guard). A mode 2 node encoded nothing and built no created channel.

- **`QueueSink`** (Core, so it ships to LBs) is the node's half: `enqueue`, `claim`, `update`. With CONTENT on it asks MAIN through the agent; otherwise it runs the SQL the callers ran, over the node's own connection; in mode 2 without the flow it returns `false`/`null` rather than reaching for a database. Only the node's *own* rows go through the agent — MAIN keys every op to the calling node, so admin code queueing onto another server stays a database write.
- **`NodeQueue`** (Domain, MAIN only) serves the three ops, every statement keyed to the authenticated node's `server_id`. A node therefore sees and changes its own work whatever its payload says, and `claim`'s limit is capped at `QueueSink::MAX_CLAIM` (200). The handler denies the ops with `FLOW_OFF` unless the node's CONTENT flow is on.
- **`StreamProcess::queueChannel/queueMovie/queueMovies`** are now three calls into the sink, which is also where their rules live (a movie replaces what is queued for that stream, a channel already queued is left alone). The two single-stream writers report whether the work was queued.
- **The daemon** claims each kind once a pass and reports what it started in one `update`, instead of a `SELECT`/`UPDATE` per row. Its pass also stopped falling out of the loop: the whole body sat inside the first `if ($db->query(...))` with a `break` at the end, so every pass re-executed `console.php queue` through `restartDaemon()`. The channel build's pid adoption is unchanged.
- **The agent** allows the three ops on its local socket (`SocketOps`, `xc_agent`); everything else it still refuses.

`ClusterQueueTest` pins the SQL, the flow routing (with the flow on, a failed agent call must not fall back to the table), the mode 2 refusal, and that no writer of `queue` is left outside the seam except the three admin surfaces that queue or cancel work on any server.

`queue_update` is retried while MAIN does not answer (a pid MAIN never records is a row the daemon claims again, encoding the same stream twice); `queue_enqueue` is one try, because its callers are crons and admin actions that come round again and a cron must not sit out two minutes of retry waits for an agent that is down.

**Not built:** MAIN does not push a node's queue to it; the node asks each pass, as it always has.

### What a node in mode 2 still could not do (Phase 5, seventh increment)

Three jobs still assumed the node had MAIN's database:

- **Viewer activity.** `cron:activity` drains the node's `activity` spool into `lines_activity` and points each line at its newest row. It built that INSERT itself, so on a node whose LOGS flow is on nothing was sent, and in mode 2 the import died on the connect. `activity` is a `LogSink` type now, which makes it a redacted `log.activity` event on P1 that MAIN's ingest writes with the same insert. The `lines` update (`last_ip`, `last_activity`, `last_activity_array`) moved into `LogSink::insert()` beside the rows, because the rows and that update belong together wherever they are written from — the cron's own copy used `escape()` and hand-built SQL, the sink binds every value.
  An event is also bounded in bytes now (`LogSink::SPOOL_BYTES`, 1 MiB of values) and not only in rows: a caller's batch is bounded in rows, while a row is as long as its source made it (a user agent, a query string, an ffprobe error), so a thousand oversized rows were one event of tens of megabytes to redact, serialise and post — more than MAIN's ingest takes, and more than the import survived. A single row over the budget still goes as an event of its own.
- **`console.php status`.** The database section returned 1 on a mode 2 node, which skipped everything status does *locally*: the permissions, nginx's config, root's crontab, the file limits, the init-script cleanup. The dashboard tells admins to run `status`, and a node that never finished it is a node that does not boot right. It now says which mode it is in and carries on; `configureRedisLb` (which points the extension at MAIN's Redis) and the closing `xc_vm_version` UPDATE are skipped — the version reaches MAIN with the next inventory event, within the minute.
- **`fanout_sync`.** Its candidate rows came from MAIN's Redis or `lines_live` through `DatabaseFactory::connect()`, so in mode 2 the daemon threw on every pass. On a node whose CONNECTIONS flow is on, the agent owns the registry and reconciles it against the fanout itself, so there is nothing here to close or drop: the pass only reports the rates it measures, and every uuid the fanout reports is this node's own — the rows it used to filter them against were MAIN's copy of the same thing. In mode 2 with CONNECTIONS off the pass is skipped, which is the "could not be read" answer the loop has always had.

`admin/thumb` (which ships to LBs: the panel redirects the admin's browser to the node that holds a stream's thumbnails) opened MAIN's database for one `streams` join. It asks `StreamSource::streamRow()` instead — the same join, from the replica where that owns the streams — and opens nothing when it does.

### The on-demand source scanner (Phase 5, eighth increment)

`scanner` probes each of the node's on-demand sources and records what it found in `ondemand_check`, which the *On-Demand Source Scanner* page shows. It selected its candidates with a join over `streams`, `streams_servers` and `ondemand_check`, inserted the row itself, and pointed `streams_servers.ondemand_check` at `last_insert_id()` — so a node booted from its replica scanned nothing.

- **The check** is `log.ondemand_check`, the log type the plan names, with `ondemand_check`'s twelve columns. The node's spool redacts `source_url` and the ffprobe `errors` before they leave (both carry the source's credentials); the SQL path keeps writing them as before.
- **The pointer** is MAIN's to set in the API path, because only MAIN knows the row's id: `EventIngest` points each of the node's `streams_servers` rows at the check it just inserted, the same `last_insert_id()` arithmetic the scanner does when it writes the row itself.
- **The candidates** come from `NodeStreams::onDemandDue()`: the same join over MAIN's database, or from the replica (an on-demand stream of type 1, no direct source, no parent, no pid). The "not checked for `on_demand_scan_time`" half of the filter reads `ondemand_check`, which is MAIN's, so from the replica the node dates its own last scan by a marker it touches beside the scan's error file — it is the only server that scans its streams. A deleted stream leaves its marker behind; it is a few bytes in a directory `cron:cleanup` prunes.
- **The daemon** does a pass a minute in one process now, instead of scanning once and re-executing itself through `restartDaemon()` (the loop ended in an unconditional `break`, as the queue daemon's did).
- `api_probe` here asked a parent for codecs over `/probe/` without checking where that parent was, as `StreamProcess` did before this phase's fourth increment; it goes through `NetworkUtils::probeTargetAllowed()` too.

`LogSinkTest` now also checks every log type's columns against the install schema, which is what would catch a typo in a table the unit suite cannot reach.

### The viewer's own close (Phase 6, later increment)

A viewer's request ending writes `hls_end = 1` and `hls_last_read` for its connection — the close the HLS reaper and the limits read. `ShutdownHandler` wrote it to Redis through `ConnectionTracker::getConnection()` (Redis only, so on a node whose CONNECTIONS flow is on the record was not there and *nothing* was written) or, in MySQL mode, straight into MAIN's `lines_live`.

It asks the node's own registry first, as every other connection writer does: the record, then a `put` with the close, which the agent mirrors to MAIN as a P0 event. When the agent does not answer, or the record is another process's, the close goes to MAIN's store exactly as before — the fallback matters more than the fast path, and that is what `ShutdownCloseTest` pins. In mode 2 with CONNECTIONS off there is nothing to write and the `lines_live` UPDATE is skipped rather than refused.

### Keeping the fleet's agent current (Phase 4, sixth increment)

> **Superseded** by [Binaries from GitHub on every node](#binaries-from-github-on-every-node) (2026-10-01): MAIN no longer hands out the agent, the fanout daemon or xcvm_core.

MAIN pins the agent: it keeps one SHA-256-verified `xc_agent` per architecture (`console.php agent_binary`) and the install flow pushes it over SSH, "so every node runs the version MAIN pinned". A node did run it — and then ran it for ever. `NodeActions::agentBinary()`, the signed `node.root agent_binary` command with its artefact grant, had no caller at all: root's half, the staging, the checks and the restart were all built, and nothing ever asked for them. The only way to move an agent was to provision the node again.

- **The node's architecture.** MAIN could not choose a binary, because it did not know what the machine was (the install flow read `uname -m` over SSH and did not keep it). The agent reports it at hello as the release assets name it (`amd64`, `arm64`, `armv7`, `386` — `runtime.GOARCH`, with `arm` as `armv7`), and MAIN stores it in `cluster_nodes.arch` when it changes, as it does the MAIN port. MAIN never guesses: without an arch, nothing is offered.
- **The decision** is `AgentUpgrades::push()`, a `cron:cluster` step: an active node whose reported `agent_version` is not the cached one for its arch, and whose agent takes artefacts, is sent the command. Each push is recorded in `cluster_meta` (`agent_push:<sid>`, with the node's generation) so the same version is not queued every minute; a node that does not come back on it — a failed install, a stopped agent — is offered it again after `RETRY_SEC` (15 min), and a re-enrolled node (a new `gen`) is offered it afresh. Every push is audited as `node.agent_push`.
- The *Cluster Nodes* page shows the arch beside the agent version, which is also how an operator sees why a node is not being upgraded.

`AgentUpgradeTest` pins the decision (older version, retry window, generation, no arch, no cached binary, an agent that does not take artefacts) with the send injected, because what matters is which nodes are asked, not how the command travels.

### Moving a running fleet to HTTPS (Phase 3, later increment)

Plain HTTP is the default and `https_required` is the operator's choice, but it could not be made on a cluster that had any node at all: the guard read *"until telemetry reports each node's HTTPS (Phase 3), any active node blocks it"* and refused whenever an active node existed. Telemetry arrived; the guard did not.

The node knows the answer, because it is the one dialling: the agent remembers whether MAIN has ever given it an authenticated answer over an `https://` URL (`Client.answered`, beside the known-good URL sets) and reports it as the `https` feature at hello. MAIN's guard now requires that feature on every active node — `NodeRegistry::allActiveHaveFeature('https')`, true with no nodes at all — in addition to its own HTTPS self-probe. A node that has never reached MAIN over HTTPS would be left talking to nobody, which is exactly what the refusal is for; an operator who sees the refusal can tell from the nodes page which node is missing it.

The feature says nothing about plain HTTP, which always works: it is only ever the permission to require HTTPS.

### Settings with a form field and no reader (Phase 0, third increment)

Five of the cluster settings were stored, clamped, shown in the settings form with a description — and read by nothing. Three of them now do what their description says:

- **`servers_stats_retention_days`** and **`cluster_audit_retention_days`** (both 1-365, default 30). `cron:cleanup` prunes the log tables by the `keep_*` settings (seconds); these two are days, so they get their own pass beside it, on MAIN only, as those DELETEs already are. `servers_stats` grows with every node every minute and `cluster_audit` with every cluster decision, and neither was ever pruned.
- **`cluster_agent_upgrade_parallel`** (1-50, default 1) now stages the agent rollout above: a node offered the binary and not yet back on the new version holds a slot until `RETRY_SEC`, so with the default one node upgrades at a time, lowest server id first.

**Still inert:** `lb_partition_tolerance_h` (how long a node keeps serving after its token expires while MAIN is unreachable) and `lb_fence_drain_min` (how long existing sessions drain after a node is fenced). Both are the node's own behaviour under a partition and belong to Phase 9's fencing, which is not built; the settings are kept because the plan names them.

### Two gates the protocol needed (Phase 0, fourth increment)

- **The crypto vectors could drift apart.** `tests/Support/cluster_vectors.json` and `cluster_canonical_vectors.json` (and, since, the command registry `cluster_commands.json`) are the contract between the panel, the extension and the Go agent, and the agent keeps its own copies under `internal/clustercrypto/testdata/`. The extension generates `cluster_vectors.json` and `cluster_commands.json` into its `tests/conformance/fixtures/`, byte-identical (its SaaS envelope vectors live in a file of their own there), and its `vectors.rs` pins their digests too. Each side tested itself against the copy it holds, so a regenerated file that was not copied over left the two speaking different protocols with both suites green. Both tests now assert the files' SHA-256, and each records the other's digests: whichever side changes first fails until both are updated, and the failure message says to copy the file over.
- **Core must not reach MAIN's cluster domain unguarded.** `Core/` ships to load balancers and `Domain/Cluster` does not, so a Core class calling it fatals on a node the moment that line runs. The pattern was already there (`NodeActions`, `SignalDispatcher`, `NodeRpc` all go through `class_exists(ClusterRoute::class)`), but nothing held it: `ClusterSettings::normalize()` called `DbAllowlist::parseExtra()` outright, reachable only from MAIN's settings form — a fatal waiting for the day someone validated a setting on a node. It is guarded (and an unvalidatable value is refused rather than stored), and `make gates` runs `check-core-cluster-refs`, which fails on any reference from `src/Core/` that is not inside a `class_exists()` of the same class. 13 references, all guarded.

The gate is deliberately limited to `src/Core/`. Across the whole tree there are about 90 references from shipped files into stripped ones (admin controllers reach `Domain\User`, `Domain\Device`…), and nearly all are in files a node never executes; a file-level gate over all of them would be noise with an allowlist longer than the rule. Core is the one tree that by definition runs on both sides, and the runtime tests (`ModeTwoPathsTest`) cover the rest by actually running a node's code paths.

### Daemons that re-executed themselves every pass (Phase 0, fifth increment)

Four daemons — `queue`, `scanner`, `signals`, `watchdog` — had their whole pass inside a `while` whose last statement was an unconditional `break`. Every pass therefore fell out of the loop and `restartDaemon()` re-executed `console.php`: a fresh bootstrap, settings read and database connect per pass, per node, four times a second in the signals daemon's case. The plan's acceptance asks for 24 h RSS and a per-LB queries/s figure, neither of which can be measured on a process that never lives a second.

The loops stay up. Every `break` that means something — a code change, nginx stopped, MAIN's database gone — is untouched, and `restartDaemon()` still re-execs when one of those fires, which is how a deploy is picked up. Two things fell out of the change:

- The signals daemon paced itself *inside* the branch that read `signals`, so a node whose first query failed spun as fast as MariaDB would answer. The `usleep` is the loop's own now (`PASS_USEC`), and mode 2 — which has no rows to read — `continue`s through it instead of breaking.
- The watchdog's CPU sample is a delta between two reads of `/proc/stat`. A fresh process had no previous read, so it took one, slept 2 s and compared: every pass's first (and only) sample was over its own sleep. Now the delta is between passes, which is what a load average wants.

`DaemonLoopTest` refuses an unconditional `break` at the end of any daemon's loop, because this is a pattern that was copied four times.

### Leftovers: one boot sequence, one dead setting, two ops that stay unserved (Phase 0, sixth increment)

- **`console.php service` was a second boot sequence.** The script systemd runs (`MAIN_HOME/service`) starts the fanout, the agent, `fanout_sync`, the replica's `cluster:apply`, takes `storage/cluster` for xc_vm and clears the `cluster_ready` marker; the PHP command — documented for operators in the FAQ and the CLI guide — did none of that, so a panel booted through the console ran a different set of services from one booted by systemd. The command delegates to the script now, `start` mapping to a new non-blocking `boot` entry (the command never held the terminal, and the script's `start` is systemd's foreground one).
- **`settings.connection_sync_timer`** was a Redis-tab field with no reader at all. The field, the column, its seed value and its two strings are gone, with migration 049 to drop it.
- **`rpc_result` and `stream_bundle`** stay in `ClusterPool::INGEST_OPS` (the plan's 24 ops, which `ClusterPoolTest` counts) although nothing will ever serve them: a command's result comes back inline with its `ack`, which takes 64 KiB, and the R2 `streams` section carries a stream's whole record with the replica as its miss path. The list is what nginx's ingest lane is rendered from, and an op the API does not serve it refuses itself, so routing them costs nothing — the reason they are there is now written where the list is.
- **A truncated command result says so.** `CommandBus::ack` cut a result at 64 KiB silently; an admin reading `cluster:exec` output could not tell a complete answer from a cut one. It ends in `[truncated: N bytes]`.

### A disabled line's sessions, and who may declare an allowed IP (Phase 6/2, later increments)

- **`cluster_kill_on_line_disable`** is on by default and says "drop live sessions when a line is disabled, banned or expires". Expiry was covered (`cron:users` closes a connection whose `exp_date` has passed), the rest was not: a line the admin disabled kept streaming until its HLS window ran out or its TS worker was reaped, and the setting itself had no reader. `LineService::dropDisabled()` hangs off the two signals every writer already sends after a line changes — the line form, a mass edit, the reseller API, an activation code's deactivation — so none of them has to remember it, and the close goes through `ConnectionTracker` exactly as a deleted line's does (to the owning node, or to the node's own registry with CONNECTIONS on). The lookup is on the primary key and usually matches nothing, since most saves enable rather than disable. The close block `deleteLineById` carried is now the shared `closeLineConnections()`.
- **`servers.whitelist_ips` grants the `/api` allowed IPs**, and every node wrote its own `ip -4 addr` into it once a minute — a node that declared an address was granting it, which is the trust hole `proxy_api` was fixed for with a different writer. The API path never carried the column (`NodeStateSink` excludes it), and now the legacy path does not either: MAIN keeps publishing its own addresses (it is the panel, already trusted, and a multi-homed MAIN reaches each node from whichever address the route picks), and elsewhere the column is the admin's. Existing values are left alone, so no fleet loses access on upgrade.
- The access-code name **`cluster`** joins the reserved list, so an admin cannot create a code whose nginx location sits over the cluster API's.
- **`cron:users` is the `legacy` role.** The role existed (`ReplicaSections::cronRoles`: a node in mode 0 or 1 runs `all` and `legacy`, one in mode 2 only `all`) and no row used it, so the one cron that walks MAIN's own `lines_live` and Redis was still handed to a node whose database access is refused. A mode-2 node's viewers are its agent's registry, which reaps them itself (`hls_reaper`), and MAIN's copy is reaped by MAIN's own row. Migration 050 moves it on an existing install.
- **A licence key replaced after a revocation** is audited (`cluster.licence_key`). The nodes need nothing from MAIN here — their next heartbeat mints a token again — but the operator's timeline should say when the key changed, which is the moment a fenced fleet starts coming back. (A different key now also rotates every token: see *A new licence key rotates the fleet's tokens*.)

### Replacing the viewer-token secret without an outage (Phase 8, first increment)

Every stream link, HLS key URL and admin preview token is minted under `live_streaming_pass`, and the links are already in players' hands when the secret changes: the token a viewer sends next was minted under the old value. Changing the secret — which the settings form has always allowed — therefore broke every live link at once, so a leaked secret could not be rotated without a visible outage. The replica's `secrets` section already carried `{kid, current, previous, previous_valid_until}` for exactly this, and the `previous` half was hard-coded to null with a comment that the rotation would fill it.

`StreamSecret` keeps the value replaced beside the config (`stream_secret.prev`, 0600, as OPENSSL_EXTRA keeps its own) and `Encryption::readToken()` tries it once the current secret fails — after the OPENSSL_EXTRA fallback, and for the sealed and legacy formats alike. MAIN writes it when a settings save changes the value; `ReplicaBuilder` publishes it; a node adopts what MAIN dated and never invents one of its own, so both sides accept the same two values for the same window (10 minutes, longer than any HLS window).

The file is stat-ed on each read rather than cached for the process's life: the php-fpm worker that reads a viewer's token is not the process that replaced the secret, and a value cached before the change would leave that worker refusing exactly the links the window exists for. Only a token the current secret already failed to open gets that far.

**Not built (the rest of Phase 8):** the relay and file tickets, the agent's loopback relay proxy, `/xfile` with its digest verification, `/v1/nonce` and `/v1/file_digest`, the loopback URL builders, and rendering `api_legacy.conf` as 404. Since then the second, third and fourth increments built all of them. Those are one change: tickets minted but unverified, or loopback URLs with no proxy behind them, would take a fleet's streams down, and the plan's acceptance for them (no encoder restarts over 48 h, a refused MITM body, replayed headers) can only be measured on a running cluster. `cluster:rotate-stream-secret` waits with them, because a full rotation also re-encrypts what is stored under the secret (the HMAC identities, the image cache's names) — that is the plan's Phase 9 step 5, and it is not a settings save.

### The fleet's heartbeat (Phase 3, later increment)

`lb_telemetry_interval_sec` (1-3 s, default 2) was stored, clamped and shown in the settings form, and reached nobody: the agent takes it as a `-interval` command-line flag, and `run.sh` — the supervisor that actually starts the agent — passes only `-state`. Every node in every fleet heartbeated at the agent's built-in 2 s, whatever the operator set.

It travels with the transport policy, which is how every other fleet-wide transport decision travels: `ClusterPolicy::current()` carries `heartbeat_sec` (the stored value in its bounds, or the setting's own default when unset — 0 would clamp to the floor and quietly make the fleet beat *faster* than it was asked to), and the replica's `cluster` section carries it too, so a node booted from its replica holds the pace before its first hello.

The agent keeps it in its state (a restart holds the pace), clamps what a policy asks for to the same 1-3 s and never past `MaxHeartbeatGap`, and re-tunes its ticker when the value changes — so an operator's change reaches the fleet with the next policy and no agent restart. A policy that says nothing leaves the pace alone, and the `-interval` flag still decides for a node run by hand.

### Rotating a node's token on request (Phase 4, seventh increment)

`token.rotate_now` was listed among the restrictive command types — the ones the extension signs even while MAIN's licence is refused — and had no producer and no executor. An operator who no longer trusted a node's token could revoke the node, which stops it, or wait out `lb_token_rotation_min`.

It is the one command the agent runs itself. Every other command goes to the node's PHP (`cluster:exec`), which verifies it again and runs it with the legacy handlers; this one cannot, because the token is the agent's and the node's PHP has no idea what it is — it would answer "unknown command type". The agent therefore handles the type before the executor, triggers the refresh it already has for the halfway point, and acks; a redelivery moves the high-water and rotates nothing twice. Since [rotate-now's outcome](#the-agents-refresh-backoff-rotate-nows-outcome-and-the-canarys-reach-check), the agent refreshes before it acks, and the ack says how it went.

MAIN's half is `ClusterRoute::rotateNow()` with a dedupe key (a double click queues one command), a *Rotate token* button beside *Revoke* on the Cluster Nodes page, and an audit line (`node.token_rotate`). A node that does not take commands yet is told to switch its COMMANDS flow on rather than being given a button that does nothing.

**Not built:** `stream.stop` and `vod.stop` are still listed as restrictive with no producer, and `node.root rotate_sign_key` — re-pinning MAIN's panel key without SSH — does not exist. Both are Phase 9's, where the fence and the credential lockdown need them. Both were built since: a stop RPC goes as the restrictive `stream.stop` / `vod.stop` (`ClusterRoute::stops()`), and `rotate_sign_key` is a root action.

### Seeing the cluster from outside its own page (Phase 10, first increment)

Everything the cluster knows about itself was on one page. The dashboard's *Service Status*
checklist — the place an operator looks first, and the one the panel itself points them to —
said nothing about it, and the *Servers* list gave no hint which of its rows was a node or
how far that node had moved, so knowing whether a server still held MAIN's credentials meant
correlating two pages by server id.

- **The checklist** gains a `Cluster API` row, from the same rows the Cluster Nodes page
  reads (their health is already settled there). A node MAIN has quarantined or revoked, or
  one gone silent, is a **failure** — it is serving viewers from a replica nobody is
  refreshing. A node waiting for a decision (a code enrolment, or one still enrolling) is a
  **warning**, because it is not serving anything yet. A suspect node (a missed heartbeat or
  two) is a warning. The API switched off, or on with no node enrolled, is neither, and the
  row says which. The check is a pure function of those rows, so `DashboardStatusChecksTest`
  covers every outcome without a database.
- **The Servers list** badges each node with its state and its mode, linked to the cluster
  page. Mode 2 is the one that says the server holds no credentials of MAIN's; mode 0 says it
  still does.
- Both read the cluster tables inside a `try`, and both treat the API being off as nothing to
  show: these are pages an operator opens before `cluster:init` has ever run.

### The legacy `/api` gets its own switch (Phase 8, second increment)

The plan makes `api_legacy.conf` a prerequisite of the data plane: the legacy server-to-server
endpoint — whose authentication is `password=<live_streaming_pass>` in a URL — must be able to
answer 404 once nothing needs it, and it could not, because its two locations were inline in
`nginx.conf` with no toggle.

It follows the pattern the Ministra legacy `/c` redirect already uses: an included file with
one `set`, written by the root cron when it changes, and an `if` in each location. The switch
is the node's **own** DATAPLANE flow, read from its `flows.json` — this is a node deciding
whether its own legacy endpoint still has callers, not a fleet-wide setting. No node has
DATAPLANE (the data plane itself is not built), so every node today writes `set $api_legacy
1;` and serves `/api` exactly as before; `ModeTwoPathsTest` pins both directions.

MAIN's own `/api` keeps no toggle: what may retire it is every node being in mode 2 with the
data plane on, which is a cluster-wide judgement and belongs with the rest of Phase 8.

Since the fourth increment the node's own flow is not enough: the switch follows
`DataPlane::legacyApiRetired()`, because other servers — MAIN first — still read the node's
files with `getFile` (see "The legacy `/api`" there).

### The data plane's two local helpers (Phase 8, third increment)

Two things a node's PHP cannot do for itself while it serves a relay or file request, and which
the plan puts on the agent's local socket:

- **`POST /v1/nonce`.** A parent must see each relay nonce once, or a sniffer's copied headers
  replay inside the signature's window. MAIN has the cluster bus for that; a load balancer
  serving as a parent has only its agent, so the window lives there: two buckets rotating on
  use over 180 s, no goroutine and no timer, and a full window (100 000 nonces) **refuses**
  rather than growing — a refused relay retries, an agent that ran out of memory does not.
- **`POST /v1/file_digest`.** The owner of a file vouches for what it served with its *node*
  key, which the agent holds and PHP does not. The agent signs `FileDigest`'s document — whose
  keys PHP sorts, so the Go struct declares them in that order and a byte of difference would
  fail the fetcher's verification — with the `digest` purpose, which the closed purpose set
  already had.

`Core\Cluster\AgentDataPlane` is PHP's half. Both calls answer null when the agent did not,
and the caller must read that as "I cannot prove this" and refuse: a parent that cannot spend a
nonce cannot tell a replay from a first attempt, and an owner that cannot have its digest
signed must serve nothing. Nothing calls either yet, and nothing here reaches MAIN.

**Why the rest of the relay half is still one change.** The tickets themselves belong in the R2
`streams` record (its `tickets` slot is still null), and a ticket that changes every 12 h would
change the record's hash — so a refresh would look like a stream change to every reader, resync
the section and, before the plan's M17 re-spec, restart the encoders daily. Making the refresh
invisible means excluding tickets from the record's version, teaching the delta path to carry
them, and having the agent's loopback proxy swap them into the header it sends — the same change
as the proxy and the loopback URL builders. It lands whole, and its acceptance (no encoder
restart over 48 h, a refused MITM body, replayed headers rejected) is measured on a running
fleet.

### The relay half: tickets, the parents' guard, `/xfile` and the loopback proxy (Phase 8, fourth increment)

One change, as the third increment said it had to be: tickets that nothing verified, or loopback URLs with no proxy behind them, would have taken a fleet's streams down. All of it follows the **child's** (or fetcher's) own DATAPLANE flow; with it off, nothing on the wire changes.

**The tickets.** MAIN mints them (`Domain/Cluster/TicketService`) for a node whose DATAPLANE flow is on, for the parents and owners that can check one: MAIN, or a node active in the node list (`Core/Cluster/DataPlane::ticketable`, which the node's URL builders use too, so both sides agree without asking each other).

| Ticket | When | Fields (with `v`, `typ`, `tid`, `iat`, `exp`) | Lifetime |
| --- | --- | --- | --- |
| `rly` | the node's own `streams_servers` row names a parent | `child_sid`, `child_gen`, `parent_sid`, `stream_id` | 24 h |
| `fil` | each `s:<sid>:<path>` source of the stream (a movie, an episode, a created channel's items) and each subtitle whose location is another server | `fetcher_sid`, `fetcher_gen`, `owner_sid`, `ref`, `file` | 6 h |

- Both are valid from the start of the 3 h epoch they are minted in (`DataPlane::EPOCH`), with deterministic ids (`r<epoch>-<sid>-<stream>`, `f<epoch>-<sid>-<ref>`); a node asks for the next epoch's as it begins, so a relay ticket always has 21 h to spare and a file ticket 3 h.
- `ref` is the file's stable name, the first 32 hex of SHA-256(`"xcvm-file-ref-v1"` ‖ u32(owner) ‖ lp(path)): the same in every ticket that names the file, so a URL an encoder holds outlives its ticket, and it says nothing about the path.
- `file` is the path sealed to the owner: `n.` + b64url(XCVM-SEAL-v1 to the owner node's box key, purpose `file`, context the ref), which the owner's PHP opens with the box key in `agent.json`; or `m.` + b64url(`sealLocal('xfile', path, ref)`) when MAIN owns it. The owner takes a path only if it hashes to the ticket's `ref`. The plan's "opaque `file_ref`" is these two fields.
- `rly` and `fil` grant: without a licence nothing is minted.

**Where they travel.** In the R2 stream record's `tickets` slot, `{"files": {"<ref>": "<fil wire>"} | null, "relay": "<rly wire>" | null}` or null, filled after the record's ETag was taken with the slot empty; and on the delta path. Tickets never bump a version (`StreamVersions` is not told), so a refresh changes nothing a node compares: no resync resends a record for one, and the node's stream cache entry, which its encoders start from, is the same with either ticket (`ClusterApiTest`). That is what keeps a refresh from restarting encoders.

**The agent's contract (XC_VM_Fanout).**

- **Delta request:** while its DATAPLANE flow is on, `{"since": <cursor>, "tickets": {"epoch": <held>, "from": <stream id>}}`. `epoch` is the epoch whose tickets the node holds in full (0: none); `from` is 0, or where a paged refresh goes on. A node that holds the current epoch's and is not part-way sends no `tickets`. A malformed one is `400 BAD_REQUEST`. With the flow off it sends none and forgets its epoch, so the flow switched on again refreshes every stream.
- **Delta reply:** when the node needs them, `"tickets": {"epoch": <minted>, "streams": {"<stream id>": TICKETS}, "next": <stream id> | null}`, plus `"withheld": true` without a licence (then `epoch` is the node's own and `next` null). MAIN examines 1000 held streams per call; a held stream that needs none is left out. While `next` is set, ask again at once with `from: next`; once it is null (and nothing was withheld), the node holds the lowest `epoch` of the pages. The refresh moves neither the cursor nor any record.
- **Storage:** `replica/tickets.json` (0600), which the proxy reads. A record's `tickets` slot also stays in its `data` as MAIN signed it, so `streams/<id>.json` (0600) carries the tickets of the record's last push; nothing reads them there, they are never refreshed there, and they are not in its ETag (taken with the slot empty), so neither PHP nor the resync sees a refresh. A record stored replaces its stream's tickets, a removal drops them, a refresh page replaces those of the streams it names. Each ticket is kept only if it verifies under the pinned panel key, now, and names this node (as `child_sid` or `fetcher_sid`) at the token's generation, a relay ticket also the stream it is filed under and a file ticket the ref. A ticket is never logged. Several streams reading one file each hold a ticket for its `ref`; a read uses the one that expires last.
- **`POST /v1/file_digest`** takes `offset` and `total` too (both or neither), and signs them into the document.

**The loopback proxy** (`relayproxy.go`, `127.0.0.1:31290`, `-relay-addr`). `k` is 32 random bytes, base64url, made once and kept in `.relay.key` beside the agent's state (0600), so an agent restart breaks no encoder's URL. The agent publishes it as `relay.key` (0600), which the node's PHP reads (`DataPlane::key`), only once its listener is bound, and removes it before it lets go of the port; a port it cannot bind (held by anyone) is retried with backoff from 1 s to 30 s and logged, and nothing is published meanwhile. A request without `k` is refused, and nothing is served with the flow off. The agent says `relay` in hello's `features` while it runs the proxy.

- `GET /relay/<k>/<stream>.ts[?prebuffer=1]`: the stream's relay ticket, the parent's address from the stored `servers` section (its private address when both it and this node have one, else its public one, on its HTTP broadcast port, as the legacy URLs chose), and `GET /admin/live?stream=<id>&extension=ts[&prebuffer=1]` with `X-XCVM-Relay` and `X-XCVM-Relay-Auth` (`RelayAuth`: the node key over the ticket, `GET`, that target, MAIN's time as the agent measured it, a fresh nonce). The body streams through unchecked (D11).
- `GET /xfile/<k>/<ref>[.<ext>]`, with the reader's `Range` (one range): chunk by chunk, `GET /xfile?o=<offset>&n=4194304` with `X-XCVM-File` and `X-XCVM-File-Auth` (the same proof over the file ticket). The ticket is read from the store and verified again for every chunk, so a transfer that outlives its ticket moves to the refreshed one and ends when there is none (expired, dropped, a revoked node). An owner answering 429 or 503 (its `/xfile` rate, a busy PHP) is asked for the chunk again, at most five times in all, waiting 250 ms doubling up to 4 s (or its `Retry-After` within that). Each chunk must carry an `X-XCVM-File-Digest` that verifies under the owner's key (the panel's, `dig`, for MAIN; else the owner's `ed_pub` in the node list, active), names the ticket, the owner and the offset asked, and matches the chunk's bytes, length and the file's total; every chunk but the last is whole. Since [the digest names its request](#binding-a-chunks-digest-to-its-request), it must also name the nonce of the request's `X-XCVM-File-Auth` (an N−1 owner's, without one, only while its `iat` is within ±90 s). A chunk that fails ends the read before a byte of it is passed on: before the headers, a 502; after them, a short body.
- `xc_agent run -role main` runs the listener alone on MAIN, which has no node identity, tickets or flows; MAIN's own relays and file reads keep the legacy URLs. Phase 9's eighth increment gives MAIN an identity to sign with (`cluster:main-dataplane on`).

**The parent** (`Core/Cluster/RelayGuard`, in `admin/{live,vod,timeshift,thumb}.php`). Tickets and proofs are judged on MAIN's clock (`DataPlaneTrust::nowMs`): MAIN's own, or on a load balancer MAIN's as its agent anchored it (`NodeLease::mainNowMs`, from `lease_state.json`; the host's clock when the agent wrote no anchor), because the child signs with MAIN's time as its agent measured it. A `password` that is not a string (`password[]=…`) is refused, not a 500. A request that carries `X-XCVM-Relay` or `X-XCVM-Relay-Auth` is judged by them alone, never by the password: the ticket verifies under the panel key and names this server as the parent and the stream asked for; the node list has the child active at the ticket's generation (a revoked or re-enrolled child holds nothing that works, and `config.changed` pushes the list at once); the proof is the child key's over this method and `REQUEST_URI`, within ±90 s; and the nonce is spent. A relay gets no playlist (`extension=m3u8` in `live` and `timeshift`), whose segment URLs carry the password. The legacy password is still admitted from a server's address, except from a server whose own DATAPLANE flow is on (the node list's new `dataplane`); `thumb` takes no password, as before.

**The owner** (`Core/Cluster/FileTicketServer`, `location = /xfile` in MAIN's and the load balancers' nginx, through the admin gateway to `Public/admin/xfile.php` and `FileTicketController`). `/xfile` has its own `limit_req` zone, `xfile`: 50 r/s per TCP peer (`$realip_remote_addr`), burst 100, `nodelay`, status 429. The viewers' zone `one` (20 r/s per client, burst 16, 503) would have throttled a VOD pull, which is one request per 4 MiB chunk. It judges on MAIN's clock as the parent does. The file ticket as the relay ticket (this server the owner, the fetcher active at its generation, a fresh proof over `/xfile?o=…&n=…`, the nonce spent); the path opens from `file` and hashes to `ref`; it passes `getFile`'s rule (its extensions, under `lb_scan_roots` or the panel's directory). It reads at most 4 MiB at the offset asked and signs `{tid, owner_sid, offset, size, total, sha256, iat}` (and, [since](#binding-a-chunks-digest-to-its-request), the proof's `nonce`): MAIN with `xcvm_core` (`dig`), a load balancer through its agent (`/v1/file_digest`, node key). One it cannot sign is a 503 with no body.

**Trust on either side** (`Core/Cluster/DataPlaneTrust`): on MAIN, `xcvm_core`'s panel key, `cluster_nodes` and the cluster bus's nonce store (`NonceStore`, node `relay:<sid>`); on a load balancer, the panel key its agent pinned (`agent.json`), the `servers` section's node list and its agent's nonce window (`/v1/nonce`). Each fails closed.

**The URL builders** (`DataPlane::relayUrl`, `fileUrl`): `StreamProcess` (the supervisor's spec, the relay start and the PHP monitor's, the movie's source, its subtitles, a created channel's items), `LoopbackCommand` and `MonitorCommand`'s on-demand start. With the flow on and a ticketable parent or owner, the loopback URL; `current_source` stores it. Otherwise the legacy URL, unchanged.

Port 31290 is unprivileged: while the agent does not hold it, any local user could bind it, read `k` from the URLs and feed the encoders what it likes. So `DataPlane::loopback()` hands out `k` only while every socket listening on the port (`/proc/net/tcp` and `tcp6`) belongs to the uid that owns `relay.key`, and one of them is on 127.0.0.1 or every address; the verdict is kept 5 s. Otherwise the URL is `DataPlane::UNAVAILABLE` (`http://127.0.0.1:1/…`, a privileged port no local user can listen on): the read fails at once and the monitor retries, and neither the stream secret nor an unauthenticated URL reaches the encoder.

**Mode 2 and the page.** `ClusterAdmin::MODE2_FLOWS` includes DATAPLANE, and the Cluster Nodes page switches it (`dataplane_on`/`off`; it needs STREAMS and CONTENT, and it is switched on only for a node whose agent says `relay` in hello's `features`, `ClusterAdmin::FEATURE_RELAY`: the flow points the node's encoders at the proxy, and without one there every relay and file read would fail). Switching it off needs nothing. **Superseded** by [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05): no flow is switched off while the node is in mode 2.

**The legacy `/api`** (the second increment's switch). The node's own flow alone no longer turns it off: other servers still read its files with `getFile` (MAIN's source probe, `ServerRepository::checkSource`, and its certbot log, `getSSLLog`; any server whose own flow is off), and each would get a 404. `api_legacy.conf` is `0` only when `DataPlane::legacyApiRetired()`: the node's own flow is on, and every server in the servers list (MAIN included) is a node the signed node list has active with its DATAPLANE flow on, so each reads through `/xfile`. MAIN had no data-plane client and no node entry, so it was `1` on every node; an unreadable servers list keeps it `1`. Since Phase 9's eighth increment MAIN counts once `cluster:main-dataplane on` is set.

**Vectors.** `tests/Support/cluster_dataplane_vectors.json`, panel-owned like the canonical vectors and generated from the PHP classes (`ClusterDataplaneVectorsTest` reproduces every byte of it): a relay and a file ticket under `cluster_vectors.json`'s panel seed, `X-XCVM-Relay-Auth` and `X-XCVM-File-Auth`, and a chunk's digest signed by a node and by the panel. The Go agent holds a byte-identical copy (`internal/clustercrypto/testdata`), and both pin its SHA-256. `xcvm_core` does not take part in these formats, so its fixtures are unchanged.

**How it differs from the plan.**

- The file ticket's opaque `file_ref` is two fields, a stable `ref` and the sealed `file`; the loopback URL names the `ref`, not a ticket id, so it survives a refresh.
- `X-XCVM-File-Auth` is `RelayAuth`'s proof over the file ticket (the ticket's tag keeps the two apart), and the plan's "digest or per-4 MiB chunk hashes" is a digest per 4 MiB chunk, each on its own response, so the fetcher checks a chunk before it passes a byte of it on and a reader can seek.
- Refreshes run every 3 h for both kinds (the plan: relays every 12 h, files at least every 3 h); a relay ticket is simply re-minted more often than it needs.
- The ticket kids the plan puts in the `secrets` section are not needed: tickets are signed with the panel key every node pins.
- The node list gains `dataplane`, for the parents' password rule.
- MAIN runs no data-plane client: `-role main` is the listener alone (until Phase 9's eighth increment).

**Not built / limits.** The acceptance, which was to be measured on a running fleet: 48 h without an encoder restart at L = 5, a MITM'd body, replayed headers from another host. Since then [the MITM harness](#the-mitm-harness) measures the MITM'd body and the replayed headers against MAIN's real guard and file server instead; only the 48 h is still a fleet measure, and it is not measured yet. A parent or owner that is a legacy server keeps the password URL. The legacy `/api` of a node is not retired while MAIN reads its files with `getFile` (above), which is every node until MAIN has a data-plane client. The agent only logs a port it cannot bind; nothing tells MAIN. The node's own loopback still carries the secret (the local RTMP output, the recorder's pull from its own `/admin/live` and `/admin/timeshift`). Since [the node's own loopback](#the-nodes-own-loopback), neither does. A file ticket sealed to an owner's box key before the owner re-enrolled does not open until the next epoch's. `cluster:rotate-stream-secret` (Phase 9).

**Tests.** PHP: `RelayAuthTest` (a relay admitted once and a replay refused; another stream, parent or panel, a tampered ticket; a revoked, re-enrolled or unlisted child; another key, a stale or retargeted proof; no nonce window or panel key; headers never falling back to the password; the password from a DATAPLANE child refused; a password that is not a string refused; a load balancer judging by MAIN's anchored clock; the four endpoints through the guard), `FileTicketTest` (chunks with their digests, a replay, another key or owner, a revoked or re-enrolled fetcher, a path sealed under another ref or key, getFile's rule, nothing unsigned, the routes and their own rate zone), `FileDigestTest` (a chunk's digest bound to its offset and size), `DataPlaneUrlsTest` (no URL carries the secret with the flow on; the builders use it; legacy servers and the flow off keep the legacy URL; the loopback only while the agent's uid holds the port, from fixture socket tables; `legacyApiRetired`), `LbNginxApiLegacyTest` and `ModeTwoPathsTest` (`api_legacy.conf` stays `1` while MAIN reads with `getFile`), `ClusterTelemetryTest` (DATAPLANE only for an agent saying `relay`), `ClusterDataplaneVectorsTest`, and `ClusterApiTest` (tickets in the record and on the delta, no ETag, version or cache entry moved, paging, the licence, a malformed ask, the flow off). Go: the vectors, the proxy (each connect signed afresh and a replay refused, the key, a stream without a ticket, the flow off, a ticket for another node, generation or stream; `relay.key` published only while the port is held, a held port retried), `/xfile` (whole, a range, a suffix, past the end; a tampered, moved or foreign-signed chunk refused, an inactive owner; the ticket re-read per chunk; 429 and 503 retried, a bounded number of times), the ticket store (paging, a restart, the flow off and on, a licence refusal, the latest ticket per ref) and `TestInteropDataPlane` against MAIN's real PHP (`XCVM_PANEL_DIR`): tickets from the streams op, a relay MAIN's guard admits once, a file read through MAIN's `FileTicketServer` and a tampered chunk refused.

### Three launches that were shell lines (Phase 0, seventh increment)

Semgrep blocked three lines these increments touched, and it was right to: each built a command
by concatenation (`php.lang.security.exec-use`). None was reachable with a value an attacker
supplies — a stream id typed `int`, a word from a four-entry allowlist, constant paths — but a
command assembled as text is a command someone will later interpolate into.

`Core\Process\ProcessRunner` already ran a program from an argv list with no shell in between,
and only waited for it. It gains the two shapes the callers needed:

- **`start()`** — what a line ending in `&` did. `proc_close()` waits, so a detached child needs
  one shell to background it and exit, leaving the child to init; the script is constant
  (`"$0" "$@" >/dev/null 2>&1 &`) and the argv is the shell's own arguments, never interpolated
  into it. It answers whether the launch was handed off, not whether the program exists — the
  callers learn that as they always did, from the pid file that never appears. The queue
  daemon's channel build and `startup`'s cache pass use it.
- **`passThrough()`** — an operator's own command: descriptors 1 and 2 are left out of the spec,
  which inherits them, so the boot script's output stays where `passthru()` put it, and the exit
  status comes back. `console.php service` uses it.

### The lease a token carries (Phase 9, second increment)

`xcvm_core` 2.2.2 ships the cluster API's lease (ADR-002, "Lease"), so the first thing Phase 9
waited for is here. `ClusterCrypto::leaseIssue()` had been a wrapper with no caller: MAIN minted
tokens and no node ever held a lease, which is the document that says how long it may keep serving
viewers once it can no longer reach MAIN.

Every path that hands a node a token now hands it a lease with it, from one seam —
`TokenService::issue()`, which enrolment over SSH, an approved enrol code, `token_refresh` and
`token_rekey` all mint through — plus the one path that does not go through it, a `token_refresh`
whose reply was lost and whose token is sent a second time. On the wire it is
`lease: {payload, sig, exp}`, base64 of the bytes the extension signed and never re-encoded JSON:
the node verifies the signature over exactly those bytes.

- **The extension holds the ceilings.** MAIN asks with `lb_partition_tolerance_h` (a setting that
  until now nothing read); the extension clamps it to 0-24 h and caps the lease at
  `min(token_exp + tolerance · 3600, iat + 26 h)`. It signs only under a valid licence and only
  above the node's revocation floor — that is what stops a revoked panel's fleet: MAIN hands out no
  further leases, and each node runs out of the one it holds.
- **MAIN stores none of them.** A lease is minted whenever a token goes out, a resent token
  included, so there is nothing to keep in step with `cluster_node_epochs` and nothing to expire.
  A resend therefore carries a lease issued at that moment, for the same window (the token's `exp`
  has not moved), which is also why a licence that lapsed between the two sends stops extending it.
- **A refusal is not the caller's error.** `LeaseService` catches it, audits `node.lease_refused`
  with the extension's reason, and the reply goes out with the token and no lease. The node keeps
  the lease it has until its own `exp`, which is the whole reason it holds one; a node without a
  lease is not a node without a session.

**Not built (the rest of Phase 9).** The node's half: `cluster_pin` of MAIN's `core.pin` blob
(`cluster_pack`, also a wrapper with no caller), `cluster_lease_verify` against a MAIN-time anchor,
and the state machine the plan gives it — DEGRADED, PARTITIONED to the lease's `exp`, FENCED with
`lb_fence_drain_min` of drain. Since XC_VM_Fanout #38, `xc_agent` checks each lease it is handed
(`internal/clusteragent/lease.go`): the panel signature under `lea`, `typ`, `v = 1`, its own node
uuid, server id and generation (the token's it came with), `exp − iat ≤ 26 h` and
`iat − 120 ≤ MAIN's time < exp`, MAIN's time being its clock-offset estimate. It keeps the newest
in its state file (`lease_refused` says why the last one was not kept) and prints it with
`xc_agent lease`. Nothing acts on it yet: no state reads it, and a node neither stops serving at
its `exp` nor is fenced.

One thing that half has to settle: a lease reaches a node with a token, and tokens refresh at half
the rotation (30 min at the default), while the plan wants a re-licensed fleet serving again in
about two minutes. Either a node whose lease is running out refreshes its token early, or a
heartbeat reply carries one when the node's is close to its `exp`. That belongs with the state
machine that reads the lease, not with MAIN's side of the wire, which is why it is not decided
here. (Settled in [Re-licensing a fleet](#re-licensing-a-fleet): neither is needed.) Then
the cutover itself: `strip_db_credentials`, `install_config`, the full
`cluster:rotate-stream-secret` and `cluster:lockdown`, and the flag `api_mode_allowed`.

`ClusterApiTest` covers the first token and both refresh replies (the document verifies under tag
`lea`, names the node and its generation, announces the `exp` it holds, and a resend's is issued
now for the same window), the rekey reply, and a refused lease (no `lease` key, a token all the
same, the audit row). `ClusterEnrolCodeTest` covers the signed approval, `LbProvisionClusterTest`
the install data, and `ClusterExtensionIntegrationTest` pins all of it against the real extension,
including that a token expiry far out with a 99 h tolerance still leaves 26 h.

### The lease the node keeps (Phase 9, third increment)

The agent now reads the lease MAIN sends beside a token, verifies it and keeps it. Nothing acts on
it yet, and that is the increment: a lease no node holds cannot be enforced, and what enforces it
turned out to need decisions this increment's own reading settled (below).

**The agent's half** (`internal/clusteragent/lease.go`). The lease is decoded at the four points a
token arrives — the SSH install data, an approved enrol code, a `token_refresh` reply and a
`token_rekey` reply — and checked with what the agent already holds: it has MAIN's signing key
(`State.PanelSignPub`) and `lea` is in its closed tag registry, so `cc.VerifyPanel` needs nothing
new. Besides the signature it checks the document itself: `typ`, `v`, its own node uuid and server
id, a window that is positive and no longer than the 26 h a lease may hold, and that the `exp`
announced beside the lease is the one inside it. What it keeps is the exact signed bytes and the
signature, because whatever judges the lease later — the node's PHP through the extension's
`cluster_lease_verify`, which resolves the key through its own pin — must verify what MAIN signed,
not a re-encoding of it.

- **The field is `json.RawMessage`.** The lease shares a reply with the token, and a lease whose
  base64 is broken must cost the node its lease, not its session: nothing in this path can fail the
  surrounding decode. A malformed one at install leaves the enrolment finished and the token in
  place.
- **Newer wins, and only newer.** A lease issued before the one held is a copy replayed at a later
  request and never replaces it; one issued now does, a shorter window included — an operator who
  lowers `lb_partition_tolerance_h` means the node to hold less, not to keep what it was handed
  before. A generation that went backwards is the same replay under another name. Keeping the
  longest lease ever issued would have made that setting unenforceable downwards.
- **A refusal is on record, not in a log.** The paths a lease arrives on do not log (the Client is
  silent by design, and the install's whole output is compared to `OK` by MAIN's flow), so the
  reason goes in `State.LeaseRefused` beside the lease, where it survives a restart and where the
  node's PHP can read it — `agent.json` is the file `ReplicaRecords::identity()` already parses.
  "MAIN sent none" is not a refusal and records nothing: MAIN sends the token without a lease
  whenever the extension signs none, and the node then keeps what it holds.
- **It judges no clock, and the clock a judgement needs is kept separately.** Nothing in the lease
  path compares the lease to a time. What a fence would need is an anchor on MAIN's clock that
  nothing unauthenticated can move, and `MainNowMs` was not it: it follows this machine's wall
  clock wherever it is moved, and it is also set from the pre-token challenge, which is signed
  (`hlt`) but answers no request of the node's, so an old copy replays — good enough for stamping a
  request MAIN checks the window of, no basis for deciding a node may stop serving viewers. The
  agent therefore also keeps a MAIN clock of its own (`internal/clusteragent/mainclock.go`; it
  landed with the fourth increment's file, below):
  - **Authenticated statements only** move it: a MAC'd, unboxed reply, a panel-signed denial naming
    the request, a panel-signed re-key document. The challenge sets the request stamp only.
  - **It re-anchors on MAIN's own number**, not on what it has extrapolated to, and only on a number
    higher than any it has seen, so a replayed older reply is ignored while a fresh statement always
    wins, even when the extrapolation has over-run. Between statements it advances on
    `CLOCK_MONOTONIC`, so moving the machine's wall clock in either direction gains nothing.
  - **A restart cannot start further back**: the highest MAIN time an authenticated statement carried
    (`main_seen_ms`) and the clock itself (`main_anchor`: MAIN's time at a `CLOCK_MONOTONIC` reading
    and the boot's id) are kept in the state file, written at most once a minute and when the agent
    stops. Within the same boot the anchor resumes with the whole time since that mark, which
    `CLOCK_MONOTONIC` went on counting while the agent was down; after a reboot it resumes from the
    mark and counts from the restart — undercounting the time the machine was down rather than over,
    which is the direction that serves viewers longer rather than shorter.
  - It answers 0 until MAIN has ever been heard on that node, and a caller with a judgement to make
    reads 0 as "no anchor", never as 1970.

  `xc_agent lease` reports the window twice: against this machine's clock, saying MAIN vouches for
  none of it, and against MAIN's clock as last heard — how much of the lease was certainly still
  unspent then, which is what a fence would go by.

**What the reading settled for the increment that enforces.** Four things were checked in the
extension and the panel rather than assumed, and each one rules out a design that looked obvious:

1. `license_valid()` is `branding_ok() && activation_key_valid()` over an activation key bound to
   `hwid == install_id`, with no lease and no pin anywhere in it, and the key never travels to a
   node — so it is permanently false on a load balancer. The verdict cannot ride on
   `LicenseGate::fanoutUsable()`; the plan names "the agent fence" for this reason.
2. `cluster_lease_verify` answers `EXPIRED` both when `main_time >= exp` and when
   `main_time < iat - 120`. A fence defined as "a verified lease and an anchor past its `exp`" is
   therefore unsatisfiable: verification and expiry have to be separate questions, the second asked
   of the stored document.
3. That call's refusals also cover local events: `RECORD:core.pin` after a re-image or a changed
   machine id, `IO` when the `cluster/` directory's mode drifts, and a bare `CRYPTO` for an XCVT pin
   blob read after its hard one-hour window. Fencing on those turns ordinary operations into
   blackouts; only a live expiry may fence.
4. No node holds a pin at all, fresh installs included: `ClusterCrypto::pack()` wraps
   `cluster_pack` and nothing calls it, so `core.pin` is never written anywhere.
   `lb_fence_drain_min` likewise does not reach a node: it is not in the LB settings allowlist, and
   the replica section that would carry it is signed under a granting tag, which is refused by the
   very licence failure a fence exists for. An off switch cannot arrive that way.

**Which key verifies a lease, and what each choice costs.** Two evaluators are possible, and they
differ in provisioning rather than in cryptography:

- **The agent**, with the copy of the panel key in its state file — what this increment does. It
  needs no pin at all. That copy is not a tamper anchor, for the reason `Core\Cluster\RootPin`
  already records about the same key: the file belongs to the node's own `xc_vm` user. The plan
  accepts that for this purpose — "the LB lease check is not the gate; root on an LB can patch
  `LicenseGate` and the agent fence" — because the gate is MAIN refusing to issue a lease. For a
  fence, the agent's key is enough.
- **The node's PHP**, through `cluster_lease_verify`, which resolves the key through the extension's
  own `core.pin`: sealed to the machine, outside anything `xc_vm` may write, and the only option if
  PHP is where a request is refused.

**What the pin needs, if PHP is the evaluator.** Three things, none of them built:

1. **MAIN must know the node's `install_id`**, because that is what `cluster_pack` targets. It
   already reads it over SSH while provisioning (`LbInstallFlow::provisionConfig`, for ADR-001's
   `config_pack`) and keeps it nowhere, so a later pack has nothing to aim at. Either the install
   stores it on the node's row, or the node reports it: its PHP has `\XC_VM::install_id()`, while the
   agent does not — the `instance_id` it sends is a random id of its own making, not the extension's.
2. **The blob must be pinned within the hour.** XCVT's age window is a hard 3600 s checked at the
   first read, not at the copy, so a blob left unread is dead and MAIN must pack again. At install
   the same SSH session can write and pin it, which is also the trust-on-first-use moment; for a node
   already in the field, a `node.root` command carries the blob and pins it at once, with the outcome
   in the command's ack.
3. **`$replace` only for a key change.** Trust on first use means a second blob for a *different*
   panel signing key is refused as `PIN_MISMATCH` unless the caller passes it — which is the
   replacement-MAIN case after `cluster:init`, and a root path by design.

Also found, for whoever places the enforcement: `StreamAuthMiddleware::decryptToken()` is not the
node's choke point (`Public/stream/segment.php`, `key.php` and `rtmp.php` read viewer tokens
themselves), and the agent's admission path is absent exactly when it would be wanted — `Unpublish()`
removes `flows.json` when MAIN stops the node, and the path is gated by the CONNECTIONS flow.

**Tests.** `lease_test.go` covers the accept and the refusal of each kind (a stranger's key, a
tampered signature, another node's or server's lease, a 26 h window accepted and 26 h + 1 s refused,
an `exp` that disagrees with itself, a replay, a generation that went back), that a malformed lease
leaves the enrolment and the token alone, and the report on a node whose enrolment never finished.
Two run against MAIN's real PHP under `php -S`: the install data's lease and both refresh replies'
in `TestInteropWithPanel`, and the approved enrol code's in
`TestInteropTheApprovedEnrolCarriesALease` — the one place the two languages meet over these bytes.
That harness had been broken since migration 048: its SQLite `cluster_nodes` had no `arch` column,
so `hello` raised a PDO error the agent saw as a bare `HTTP 500`. It now ALTERs the column in, and
the router records every request and the text of any throwable beside its database, which the test
prints when it fails.

### The fence a lease's end draws (Phase 9, fourth increment)

The lease now reaches a node, is verified there and is judged against a clock nothing
unauthenticated moves. This is what a node does when that window closes: past the lease's `exp` no
viewer starts on it, and past the drain the sessions that were running stop too. It is off until an
operator switches it on.

**Who judges, and with what.** `Core\Cluster\NodeLease` on the node, from two inputs: the file the
agent rewrites every heartbeat (`config/cluster/lease_state.json` — the lease's window, MAIN's clock
as the anchor last had it, and when that was written) and two settings. The agent publishes facts and
no verdict: the switch is a panel setting it does not read, and the same file therefore serves an
operator (`xc_agent lease`) and a later reader without either owning the policy.

**The agent's half landed after the panel's.** `NodeLease` shipped reading a file that no agent
wrote yet, so until XC_VM_Fanout's writer (`internal/clusteragent/lease.go`, with the MAIN clock of
`mainclock.go`) every node answered "the agent has written no lease state" and served, switch on or
off. The file, as the writer produces it:

| Field | Unit | What it is |
| --- | --- | --- |
| `exp`, `iat` | unix s, MAIN's clock | the held lease's window; both 0 without a lease (`exp` 0 reads as "no lease") |
| `gen`, `server_id` | | the held lease's generation and server; 0 without a lease |
| `anchor_ms` | unix ms, MAIN's clock | MAIN's time at the moment of writing, as the agent's MAIN clock has it: the last authenticated statement plus `CLOCK_MONOTONIC` since; 0 until MAIN has been heard ("no anchor") |
| `wrote_at_ms` | unix ms, the node's clock | the node's wall clock at that same moment, compared only with that clock |

- **When.** At every heartbeat interval (1-3 s, the policy's pace) from the moment the agent starts,
  whether or not MAIN answers — ahead of the first hello, which is not answered for as long as MAIN
  is gone — and at once when a heartbeat or hello is answered or a token brings a lease. A file that
  stopped being rewritten exactly when MAIN went away would read as stale, and serve.
- **How.** Atomically (a temporary file renamed in), 0640 less the umask like `flows.json`, as the
  agent's own user, which PHP-FPM runs as; without fsync, since it is rewritten from what the agent
  holds and a crash that loses it leaves no file or the one before, both of which serve. The agent
  removes it with `flows.json` when MAIN stops the node.
- **Clocks.** `NodeLease` reckons MAIN's time as `anchor_ms` plus the time since `wrote_at_ms`,
  never less than nothing. A wall clock moved back therefore adds nothing (and the anchor itself
  never read the wall clock), and one moved forward makes the file look stale until the next write,
  which serves. A restart resumes the anchor from the state file (above), so restarting the agent
  is no way out of the fence.
- **The report.** `xc_agent lease` gives the window twice: against the node's own clock, saying MAIN
  vouches for none of it, and against MAIN's clock as this file has it, reckoned as `NodeLease`
  reckons it, with a note when the file is old enough that the fence does not judge it.

Why not `cluster_lease_verify` here: it needs the extension's `core.pin`, which no node holds, and it
answers `EXPIRED` for both ends of the window — the one question this has to ask separately. The
agent verified the signature when it stored the lease, with the panel key it already holds; the plan
accepts that key for this purpose, since the gate is MAIN refusing to issue.

**Every uncertainty serves.** No file, a file the agent stopped refreshing (older than 60 s, so the
agent is not running), no lease, no anchor (MAIN never heard on this node), a legacy node, or the
switch off: all serve, and the verdict says which of them it was. A fleet must not go off the air
because an agent died or a file went stale. The clock cannot shorten the window either: a wall clock
moved forward makes the file look stale, and one moved back leaves the anchor where the agent put it.

**Where it refuses.** Three places, each where the node already refuses for its own reasons:

| State | `Public/stream/auth.php` (a viewer starts) | `segment.php`, `key.php` (a session continues) |
| --- | --- | --- |
| serving | serves | serves |
| draining (past `exp`, within `lb_fence_drain_min`) | `STREAM_OFFLINE` | serves |
| fenced (past the drain) | `STREAM_OFFLINE` | `404` |

The two continuation endpoints read their settings from the node's cache file and populate no
`SettingsManager`, so the verdict takes the caller's settings array and falls back to
`SettingsManager` for everything else (a cron, the CLI).

**The switch.** `lb_lease_fence`, `[0, 0, 1]` in `ClusterSettings::INTS`, migration 051, on the admin
form beside `lb_fence_drain_min`, and both keys now in the LB settings allowlist so they reach a
node's replica. It has to be switched on *before* the licence it guards lapses: the replica's
`settings` section is a granting record, so a panel that can no longer sign one cannot change this
either. That is also why it is a setting and not a command.

**What the drain actually drains.** A live HLS player refreshes its playlist at
`/live/<user>/<pass>/<id>.m3u8`, which is `auth.php` — the same request a new viewer makes, and
indistinguishable from one without looking up whether a connection record already exists for that
line, address and stream (the admission path, which is a flow bit away). So during the drain an HLS
session ends at its next playlist refresh, while the segments and keys it already has URLs for keep
being served. The drain therefore holds for a `.ts` viewer, whose one long request is past every check
above, and for a player still working through a playlist it holds; it does not extend an HLS session
that must re-ask for its playlist.

**Not built.** A viewer already inside a long `.ts` request is never dropped, drain or no drain: what
ends one is the node's signal protocol, which MAIN drives with `conn.drop` and nothing here writes.
So a fenced node starts nothing and serves no HLS, while a TS viewer already streaming stays until it
reconnects — at which point it is a new session and is refused.
Producers are not released either. Both want the same missing piece: something on the node writing
`SIGNALS_PATH` entries for the connections it holds while fenced, which is the agent's registry or a
node cron, and neither is wired to this yet. MAIN's own page shows no fence: it can derive the window
it issued (`token_exp` plus `lb_partition_tolerance_h`) without the node reporting anything, and that
belongs with the Cluster Nodes page rather than here.

Since then, three of these are built. The fifth increment ends a PHP-served `.ts` request at its
next segment and has the agent write the registry's `drop` entries. The eighth drops the fanout's
viewers under a lease fence. The Cluster Nodes page has a *Fence window* column, and
`server:diagnose` a *Fence window* line (`ClusterOverview::fenceWindows()`,
`ClusterDiagnosis::fenceWindow()`): lease end, drain end, and whether `lb_lease_fence` is on.
Producers are still not released.

`NodeLeaseTest` covers the three states and every way of serving (the switch off, no file, a stale
file, no lease, no anchor, a legacy node, a clock moved back), that the drain follows the `exp`, that
a drain of 0 makes the `exp` the end, and the wiring itself: the guard's place at the top of
`auth.php`, the two continuation endpoints asking `refusesEverything()` with their own settings, and
the switch shipping off, bounded, on the form, named in `en.ini` and in the allowlist. On the agent's
side `TestTheLeaseStateIsPublishedEveryTickEvenWithMainGone` pins the one property the node's PHP
depends on: the file keeps being refreshed while MAIN cannot be reached, because a file that has gone
stale reads as "serve"; `TestARestartedAgentWritesTheLeaseStateBeforeMainAnswers` that a restart
during the outage resumes the anchor and writes before any hello is answered, and the `mainclock`
tests that only a fresher authenticated number moves it and a moved wall clock never does. The
format is pinned from both sides: the agent writes `cluster_lease_state.json` from fixed inputs and
compares it byte for byte with its testdata, and `NodeLeaseTest` feeds the panel's copy
(`tests/Support/`) to `NodeLease::verdict()` through serving, draining, fenced, stale and a clock
moved back; both tests record the file's digest, as for the shared vectors. With
`XCVM_PANEL_DIR`, `TestInteropNodeLeaseJudgesTheAgentsFile` runs the real `NodeLease` against the
file the agent wrote for a lease MAIN's real `LeaseService` signed.

### Commands, rotations and lockdown (Phase 9, fifth increment)

The restrictive command types had no producer, the fence drew no line through a running `.ts`
request, and the cutover's last steps — rotating what legacy nodes sent in cleartext, closing
3306/6379 — did not exist. This increment builds them; `api_mode_allowed` stays false.

**Commands MAIN now sends** (types and arguments exactly as the extension's registry signs them):

| Type | Class | Producer | Runs |
| --- | --- | --- | --- |
| `stream.stop`, `vod.stop {stream_id}` | R | a `node.rpc` stream/vod *stop* becomes one per stream, deduped (`ClusterRoute::stops()`) | `cluster:exec` (`StreamProcess`) |
| `node.fence {reason, drain_min}` | R | *Fence* on the Cluster Nodes page; `cron:cluster` in `lb_revocation_mode=hard` without a licence binding (`ClusterRoute::licenceFences()`, reason `licence`, once while one waits) | agent |
| `node.unfence` | G | *Unfence* (shares the fence's dedupe key, so it supersedes one not yet taken) | agent |
| `node.quarantine {reason}` | R | *Quarantine* (queued, then the row goes `quarantined`); *Trust again* sets it `active` and queues `token.rotate_now` | agent |
| `resync {sections}` | R | *Resync* (`config`, `streams`, `connections`) | agent |
| `policy.update` | G | none: a newer `policy_ver` reaches the node with its heartbeat (`ClusterRoute::policyUpdate()` had no caller and is removed) | agent: a hello now |

- **A quarantined node's long-poll** hands out class R only (`CommandBus::pending(…, restrictive)`);
  the rest stays queued for *Trust again*. Its replica stays refused, and the agent itself runs only
  restrictive types and skips its replica sync until a reply says `active`. **Superseded** in part by
  [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05):
  a granting command the node's high-water has passed is not handed out after *Trust again*.
  **Superseded** by [design 3](#three-designs-for-approval-the-line-a-node-names-the-updating-status-commands-across-a-quarantine-2026-10-06) (built 2026-10-06): a quarantine ends every granting command still queued or handed out, and names them in the audit; none is handed out after *Trust again*.
- **The licence fence is queued by the cron**, not where the refusal is written: that path runs
  before anything is authenticated and changes no state. It rides the sealed `LICENCE_INVALID` with
  the other kills; the agent takes a `licence` fence only from a refused session and lifts it itself
  when MAIN accepts the session again.

**The fence on the node.** The agent keeps a commanded fence in its state (a restart keeps the
drain's start) and rewrites `config/cluster/fence.json` every second —
`{state: draining|fenced, reason, since_ms, drain_until_ms, wrote_at_ms}`. `NodeLease` reads it
before the lease and without the `lb_lease_fence` switch (it is MAIN's explicit word, verified by the
agent); a file older than `STALE_SEC` serves, as every uncertainty does. Past the drain the agent
writes one `SIGNALS_PATH/<uuid>` entry `{"type": "drop"}` per open viewer in its registry and drops
the fanout's own viewers over its control socket; `live.php`'s TS loop ends on a `drop` entry and,
independently, on `NodeLease::refusesEverything()` at each segment — so a PHP-served `.ts` request
ends under a lapsed lease too, without the registry. A fanout-served viewer under a *lease* fence
(not a commanded one) was not dropped, because the agent did not judge the lease: the eighth
increment has it do so. Producers are still not released.

**`cluster:rotate-stream-secret`** (`StreamSecretRotation`). Refused while an enrolled node has
DATAPLANE off (`--force` warns). Resumable: `cluster_meta` `stream_secret_rotation` holds the phase,
a cursor and both values until it ends. Switch (settings, `StreamSecret::replaced()`,
`config.changed {secrets}` to command nodes), then `hmac_keys.key` re-encrypted in id order
(`validateHMAC()` tries the previous secret in its window), then MAIN's image-cache files renamed
with their `s:<sid>:/images/` references in `streams` and `streams_series` moved first. A load
balancer's own image files keep their names.

**`cluster:rotate-credentials`** (`CredentialRotation`, root side `RootCredentials`). Targets: every
load balancer below mode 2. *Redis*: the new password is added with `ACL SETUSER default >new`
(`CONFIG` is renamed away), MAIN moves at once (config.enc through `config_set_redis`,
`settings.redis_password`, `requirepass`), a root-command node gets `node.root rotate_redis` with the
password SEALed to its box key (purpose `root.credentials`, its uuid as context), a legacy node a
`signals` row with no secret that it answers from MAIN's settings; `--finish` drops the old one by its
SHA-256 once every command node acked, or with `--force`. *DB*: at first `rotate-credentials db`,
refused until xcvm_core had `db_set_password`/`config_set_db`; since the seventh increment's
review the DB password has one command, `cluster:rotate-db-password` (below), which sends the
same sealed `node.root rotate_db`, and `rotate-credentials db` only points at it.

**`cluster:lockdown [--force] [--restart] | --undo | --status`** (`ClusterLockdown`), manual only.
Refused while a load balancer is below mode 2 or any proxy exists. MariaDB binds to 127.0.0.1 by a
drop-in sorted after the installer's `99-custom.cnf`, Redis to `127.0.0.1 -::1`; with
`cluster_db_allowlist_extra` set both keep their binds. The `XCVM_DB` chain is reused: while
`cluster_meta` holds `db_lockdown`, `DbAllowlist` keeps it on whatever the setting says and admits
loopback, MAIN and the extra list only. The last rotations stay separate commands.

**`node.root rotate_sign_key {new_pub, pin_blob?}`** (`cluster:rotate-sign-key`). Root re-pins MAIN's
panel key under a command signed with the key it pins today and restarts `root.seq`; the agent's copy
is left alone, so the node reports `root_ready` false until its agent re-enrols (by code, or
`cluster:reenrol`). `pin_blob` replaces the extension's `core.pin` through `cluster_pin($blob, true)`;
MAIN cannot produce one yet, since `cluster_pack` needs the node's `install_id`, which MAIN does not
keep.

**API-mode installs.** With `lb_new_node_mode = api` (still refused while `api_mode_allowed` is
false) a new load balancer gets no DB grant, a config.enc packed with
`config_pack(…, ['db_credentials' => false])` — found by `method_exists('XC_VM', 'install_config')`,
refused without it — and is enrolled in mode 2 with `ClusterAdmin::MODE2_FLOWS`.

Tests: `ClusterRestrictiveProducersTest`, `StreamSecretRotationTest`, `CredentialRotationTest`,
`ClusterLockdownTest`, `RotateSignKeyTest`, `ApiModeInstallTest`; on the agent's side `fence_test.go`,
including `TestInteropFence` against MAIN's real `CommandBus`.

### The compiled lease verdict and the credentials (Phase 9, sixth increment)

`xcvm_core` (its ADR-002, "Lease verdict on a node" and "Credential-free nodes") now judges a
node's lease itself and takes MAIN's credentials off a node. This increment wires both.

**The lease verdict.** The extension keeps the lease MAIN signed (`cluster_lease_store`,
the exact bytes), verified against its own pin of the panel key, and an anchor on MAIN's
clock that only a verified lease's `iat` moves, only forward, and that advances on
`CLOCK_BOOTTIME` within a boot (across a reboot only by the wall clock's forward movement).
`cluster_lease_state()` answers `live`, `expired`, or `none` with the reason; on a node
`license_valid()` is true while it is `live`, so `LicenseGate::fanoutUsable()` finally has a
compiled answer there — the plan's "`license_valid()` on the LB on MAIN time".

- **`NodeLease` asks the extension first**, when it has `cluster_lease_state` (a feature
  check, `method_exists`), at the same 2 s cadence as the agent's file. Before asking it
  hands the extension the agent's lease (`agent.json`'s `lease`, Go's base64) when that
  lease is newer by `iat` than the one the extension holds; a lease the extension refused
  (stale, expired, not for this node) is not offered again until a newer one arrives, and
  nothing is offered without a pin. A `live` or `expired` answer decides — `source:
  extension` in the verdict — with the anchor the extension reported plus the monotonic time
  since it was read (at most 2 s). `none`, an extension without the method, or one that
  throws falls back to the agent's file, which keeps every uncertainty serving
  (`source: agent`). The extension's own `license_valid()` fails closed on `none`; the fence
  does not, for the reasons the fourth increment gives.
- **Why the compiled verdict wins.** The agent re-anchors on MAIN's latest number, so a MAIN
  whose clock was set back moves the agent's anchor back too. The extension's anchor never
  moves back, and it refuses a lease older than the one it holds (`RECORD:stale`), so neither
  the node's clock nor MAIN's can lengthen the window it judges.
- **Still missing for it to engage:** the node's `core.pin`. `cluster_pack` still has no
  caller, so no node is pinned and the extension answers `NOT_PINNED`; until the install flow
  (or a `node.root` command) pins the node, every node takes the fallback. The fence's switch,
  `lb_lease_fence`, still ships off.

**The credentials** (plan section 10, step 3).

- **On the node:** `strip_db_credentials` and `install_config` join `NodeActions::ROOT_ACTIONS`
  and `CLUSTER_ONLY` — a signed `node.root` only, never a `signals` row. RootSignalsCronJob runs
  them through `Core\Cluster\NodeCredentials`, which calls `XC_VM::strip_db_credentials()`
  or `XC_VM::install_config($blob)` (the payload's `blob`, base64 of a `config_pack` XCVT blob,
  at most 64 KiB). An extension without the method refuses the action with a reason the ack
  carries back ("update xcvm_core first"); an extension refusal (`RECORD:is_lb` on MAIN,
  `RECORD:server_id`, `CRYPTO`) fails the command. The result's last line is
  `{"config": {server_id, is_lb, db_credentials, redis_auth, changed}}`; the system log type is
  `CONFIG` (added to `LogSink::SYSLOG_TYPES`).
- **On MAIN:** `Domain\Cluster\DbCredentials::strip()` sends the strip only to a node in mode
  2 and `active`. The command's first ack (ClusterApi `ack`) goes to `DbCredentials::acked()`:
  only a successful `strip_db_credentials` or `install_config` whose result reports
  `db_credentials: false` revokes — a result that says root only queued it, a rollback config
  that still holds credentials, or a failed command revokes nothing. The revoke is
  `XC_VM::db_revoke($server_ip)` (which now refuses loopback addresses and MAIN's own DB host),
  then `cluster_nodes.db_revoked_at` (migration 052, through `NodeRegistry::update()` so the
  auth cache is told), audited `node.db_revoked` or `node.db_revoke_failed`.
- **Not built here:** a caller of `DbCredentials::strip()` (the seventh increment adds both),
  and the credential-free SSH install, which the fifth increment had built for new nodes in API
  mode; the eighth keeps a reinstalled credential-free node that way. `api_mode_allowed` stays
  false.

**Tests.** `LeaseVerdictCacheTest` (the extension consulted once per 2 s window, a live or
expired answer deciding over the agent's file, `none` and a throwing extension falling back,
the agent's newer lease offered once with its exact bytes, a refused one not offered again),
`TimeAnchorMonotoneTest` (the fallback's anchor never below the agent's, a clock moved forward
reading as stale, the extension's anchor used as reported), `MainClockRollbackTest` (the
compiled verdict outranking an agent anchor pulled back, an older lease never offered, and the
fallback's documented limit), all with the extension absent and a fake in its place;
`NodeCredentialsTest` (the catalogue, an old extension refusing cleanly, the result line, the
blob as bytes, revoke only on a clean outcome, the mode-2 guard, the schema);
`ClusterExtensionIntegrationTest` runs both halves against a real test-hooks `xcvm_core`.

### Core pins, the credential strip and the DB password (Phase 9, seventh increment)

The fifth increment left the compiled lease verdict without the pin it judges against, and the
credential strip without a caller. This increment adds both, and the DB password rotation that
`xcvm_core` now offers (its `db_set_password` and `config_set_db`, ADR-002).

**The node's `core.pin`.** `cluster_pack($install_id)` is an XCVT blob that only that install
opens, for an hour, and licence-gated, so MAIN needs each node's install_id: migration 053 adds
`cluster_nodes.install_id` (NULL: not known; not a secret, and a wrong one only makes the node
refuse what MAIN packs for it).

- **At the SSH install** (`LbInstallFlow::pinCore`, after root's pin, before the agent starts):
  the node's install_id is read as root — only when its file exists, since `install_id()`
  creates one and a root-owned file would lock the panel's user out — recorded, packed for,
  and pinned with `cluster_pin($blob, true)`: the verified SSH session is the authorised
  re-pin path, so an earlier MAIN's pin is replaced. The node must answer with this panel's
  key's SHA-256. A failure is a line in the log, never a failed install.
- **Every other node** (enrolled by code, before this release, or whose MAIN's root changed)
  is pinned over the cluster API once it takes root commands: `cron:cluster` (`CorePins::offer`,
  at most 20 per pass, a node that did not end pinned again after an hour) or the page's *Pin
  core* (`CorePins::request`) sends `node.root pin_core` — with the blob when the install_id is
  known, else without, and root answers with its install_id, which the command's first ack
  records before MAIN sends the blob. A pin that did not open (`CRYPTO`: another machine, or a
  blob past its hour) forgets the install_id, so it is asked for again.
- **Root pins only root's key** (`Core\Cluster\NodeCorePin`): the pinned key must be the one
  root's own pin (`RootPin`) trusts, which also signed the command. A pin of another panel is
  replaced; a pin of root's panel is kept against a blob for any other key (`PIN_MISMATCH`);
  a blob that pinned another key is removed at once, so the node never trusts a key root does
  not. The result line is `{"core": {install_id?, pinned, verdict}}`.
- **MAIN's record** is `cluster_meta` `core_pin:<server_id>` = `{gen, fp, pinned_at,
  tried_at}`: a re-enrolled node (a new generation) and a new panel root (another `fp`) read as
  not pinned and are offered again. The page shows a *core* badge for a pinned node.

**The credential strip's triggers.** *Drop DB credentials* on the Cluster Nodes page (for an
active node in mode 2, behind a confirmation) and `cluster:strip-credentials <serverID>` (the
operator types the server id back, or passes `--yes`; `--wait=<s>` waits for the revoke) both
call `DbCredentials::strip()`, which now returns message keys and audits every attempt that
reached a node (`node.strip_credentials`). `DbCredentials::installConfig()` sends a config MAIN
packed for the node's install_id with `XC_VM::config_pack` — credential-free for a node in mode
2, with credentials for a rollback or a rotation — as `node.root install_config`, audited
`node.install_config`. Nothing strips on its own, and `api_mode_allowed` stays false.

**The DB password rotation** (`Domain\Cluster\DbPassword`, `cluster:rotate-db-password`).

- `XC_VM::db_set_password($new)` does the database half: MAIN's accounts (all or none), then its
  `config.enc`, then every load balancer's grant (`PARTIAL` when one kept the old password).
  The new password is generated (32 letters and digits) and never shown, or read from standard
  input with `--password-stdin`; it is never an argument, never logged or audited.
- **The password never rides a command in the clear.** Each active node below mode 2 that
  takes root commands and has a box key is sent `node.root rotate_db {auth_sealed}`: the new
  password SEALed to its box key (`RootCredentials::seal`, purpose `root.credentials`, its uuid
  as context), so MAIN's `cluster_commands` row and the node's root inbox hold ciphertext only.
  Root opens it with the key its agent holds and calls `XC_VM::config_set_db` (only `db.pass`
  changes). A node in mode 2 does not use MAIN's database and is left alone; a revoked node has
  no grant. `rotate_db` is `CLUSTER_ONLY`: never a `signals` row.
- **One design, not two.** This increment first sent each node a whole config
  (`installConfig` with credentials, a `config_pack` XCVT blob for its install_id), beside the
  fifth increment's `cluster:rotate-credentials db`, which sent the sealed `rotate_db`. The
  review kept one command and the sealed transport, and dropped the other two paths: the XCVT
  transport key derives from the install_id, which MAIN's own `cluster_nodes` holds, and a
  pepper every extension build carries, so a reader of MAIN's database could open the blob;
  and `install_config` replaces the node's whole `config.enc`, resetting the Redis section a
  mode-1 node still uses. `installConfig` stays for the rollback from mode 2.
- **Every other load balancer** (legacy, mode 0, no root commands, no box key) keeps
  the old password in its config and loses MAIN's database until an operator runs
  `cluster:set-db-password` on it as root, which reads the password from standard input and
  calls `XC_VM::config_set_db` (only `db.pass` changes; a node without a DB user is refused,
  `RECORD:db.user`). The command lists these nodes before it asks for confirmation (the operator
  types `rotate`, or passes `--yes`).
- Both commands check the extension with `method_exists` and refuse cleanly without it.
  `cluster:rotate-db-password` is MAIN-only (stripped from the LB build); `cluster:set-db-password`
  ships on nodes and uses nothing the LB build strips.

A new node in API mode was already installed credential-free (the fifth increment). The eighth
increment keeps a node MAIN already holds credential-free that way through a reinstall and every
grant path.

**Tests.** `CorePinTest` (root's two steps against a fake extension: the install_id read only
when it exists, only root's key pinned, another panel's pin replaced and root's kept, the
refusals; MAIN's offer, retry, generation and panel-key changes, the acks, `packFor`),
`LbProvisionClusterTest` (the pin over the SSH session, before the agent runs, and a failed
pin not failing the install), `ClusterCredentialsActionTest` (the page's and the CLI's guards,
confirmation and audit, the node list's new fields, the strings), `NodeCredentialsTest`
(message keys, the audit, `revokedAt`), `DbPasswordTest` (the password rules, the plan, a
rotation that sends the sealed password only where it can and never logs it, refusals, the
rollback config packed for the node's install, both commands, and which ships on a node),
`CredentialRotationTest::testTheDbPasswordTravelsSealed` (the real bus: ciphertext only in the
row, no `signals` row, the node opening it for `config_set_db`).

### What Phase 9 still owed (Phase 9, eighth increment)

Four pieces the earlier increments left open. None flips `api_mode_allowed`, and none removes the
LB's `/api`, `configureRedisLb`, its DB code paths or the viewer API.

**A node MAIN keeps credential-free stays so.** The fifth increment installs a *new* node in API
mode without a grant and with a credential-free `config_pack`. Two paths still handed MAIN's
credentials back to a node that had given them up:

- **A reinstall over SSH** (`server:install`, and `server:enrol` for an existing load balancer)
  decided API mode by `lb_new_node_mode` alone, and the enrolment replaces the node's row. So a node
  in mode 2, or one whose grant MAIN had revoked, was packed a config with credentials, granted
  again, re-enrolled in mode 1, and `db_revoked_at` was lost. `LbInstallFlow::installsInApiMode()`
  now also answers true for a node `DbCredentials::credentialFree()` (mode 2, or `db_revoked_at`
  set), asked once before the enrolment. `ServerInstallCommand` hands the same answer to
  `provisionConfig` (credential-free `config_pack`, or a refusal with an extension that cannot pack
  one), to the grant at the end of the install (none), and to `provisionCluster`, which re-enrols the
  node in mode 2 with `ClusterAdmin::MODE2_FLOWS`. `NodeRegistry::startEnrolment()` carries
  `db_revoked_at` over when the new row is in mode 2. A deliberate mode-1 enrolment starts clean.
- **The bulk grants** (the admin's *Re-authorise MySQL*, `tools mysql`, `tools migrate`, and the
  server form) all go through `BackupService::grantPrivileges()`, which now grants nothing to the
  host of such a node (`DbCredentials::credentialFreeHost()`: a grant is per host, so one such load
  balancer on it is enough) and returns false. `tools mysql` says so for each skipped host.

Tests: `ApiModeInstallTest` (the reinstall decision, the revoke across a re-enrolment, no grant to
such a host).

**MAIN hears when the relay proxy cannot bind its port.** The agent's loopback relay proxy
(`127.0.0.1:31290`, fourth Phase 8 increment) is an unprivileged port. While another process held
it, the node's relays and file reads failed and were retried, and only the node's own log said
why. Now every heartbeat carries the proxy's state:

```text
relay   {"bound": true}
        {"bound": false, "since_ms": <agent's unix ms of the first failed bind in a row>,
         "failures": <failed binds since>, "error": "<the last, printable ASCII, at most 200 bytes>"}
```

- **The agent** (`relayproxy.go`, `RelayReport`) sends it once the proxy has tried its port, and
  never while it is off (no `-relay`). `failures` is 0 when a listener it held stopped. The same
  object is `relay` in `GET /v1/status` (null while there is none).
- **MAIN** (`Domain\Cluster\NodeRelay`, from the `heartbeat` op) keeps it in
  `cluster_nodes.relay_down_since` (MAIN's clock: the agent's `since_ms` moved by the node's clock
  offset, never later than now) and `relay_error` (migration 054, and `database.sql`), written only
  when the state or the error changes. Each transition is audited: `node.relay_unbound {error}` and
  `node.relay_bound {down_since}`. A heartbeat without `relay`, or with one that is not an object
  with a boolean `bound`, changes nothing. A table from before 054 changes nothing either.
- **Shown** as a *relay port down* badge in the DATAPLANE column of the Cluster Nodes page, and as a
  *Relay proxy* line in `server:diagnose`: from MAIN's row on MAIN, from the agent's status on the
  node.

Tests: `NodeRelayTest`, `ClusterDiagnosisTest`; on the agent's side
`TestRelayReportTellsWhetherTheProxyHoldsItsPort`, `TestHeartbeatCarriesTheRelayReport`, the status
tests and `TestInteropWithPanel` against MAIN's real `ClusterApi`.

**The lease fence drops the fanout's viewers.** Past a lease's `exp` plus `lb_fence_drain_min`, on
MAIN's clock and with `lb_lease_fence` on, the node's PHP ends the sessions it serves at their next
segment. A viewer the fanout serves under X-Accel has no PHP worker left, and only a commanded fence
dropped it. The agent (`leasefence.go`) now judges the drain itself on `RunFence`'s one-second tick,
when no commanded fence stands:

- **From what `NodeLease`'s fallback reads:** the lease it holds (`exp`), its MAIN clock
  (`mainclock.go`, as in `lease_state.json`), and `lb_lease_fence` / `lb_fence_drain_min` from the
  replica's verified `settings` section (`replica/settings.json`; a drain outside 0–60 reads as the
  default 10, as `ClusterSettings` bounds it). Every uncertainty serves, as there: no fanout socket,
  no replica settings, the switch off, mode 0, no lease, or MAIN's time never seen.
- **What it drops:** every connection the fanout lists (`GET /connections` on its control socket,
  then `DELETE /connections/<uuid>`), each once, and one that attaches later at the next tick. It
  writes no `SIGNALS_PATH` entry: a PHP-served viewer ends on `NodeLease` at its next segment. New
  viewers are refused by `stream/auth.php` from `exp` on, as before.
- **The compiled verdict** does not reach Go. It anchors on the same MAIN time within seconds, and it
  outranks this judgement only where MAIN's clock went back. There the agent's anchor is the earlier
  one, so the agent drops later than PHP, never sooner.
- **The commanded fence** now drops the fanout's own viewers the same way as well as the registry's,
  so a node whose CONNECTIONS flow is off (an empty registry) loses its fanout viewers at the end of
  the drain too.

Tests (agent): `TestALeasePastItsDrainDropsTheFanoutsViewers`,
`TestALeaseFenceServesOnEveryUncertainty`, `TestTheLeaseFenceSettingsAreReadAsMAINKeepsThem`,
`TestACommandedFenceDropsTheFanoutsOwnViewersToo`.

**MAIN's data-plane client.** MAIN read other servers' files with `getFile` and the stream secret
(its source probe, `ServerRepository::checkSource`; a node's certbot log, `getSSLLog`; the movies
and created channels it runs) and pulled streams from load balancer parents with the password.
Because of that, `DataPlane::legacyApiRetired()` never held and no node's `/api` could be retired.
MAIN now gets a client of its own, and it is off until an operator runs `cluster:main-dataplane on`.

- **A key of MAIN's own, not the panel key.** `xc_agent keygen -state
  config/cluster/main_agent.json -uuid <uuid>` makes it, as a node's. The command runs it as
  `xc_vm` and records the public halves, uuid and generation in `cluster_meta` `main_dataplane`
  (`Domain\Cluster\MainDataPlane`). The panel key stays inside `xcvm_core`, which signs no
  per-request proofs. `rekey` makes a new uuid and key and raises the generation, and `off` keeps the
  key.
- **MAIN in the node list.** While on, `ReplicaBuilder::serversData()` adds `{sid: MAIN, gen,
  state: active, ed_pub, dataplane: true}` to the signed node list, and the nodes are sent
  `config.changed {servers}`. A parent's `RelayGuard` and an owner's `FileTicketServer` then check
  MAIN's proofs exactly as a node's (the child or fetcher active at the ticket's generation, its key,
  the nonce), so nothing changed on their side. The same entry makes a load balancer's parents refuse
  MAIN's password (the node list's `dataplane`), and it makes MAIN count for
  `legacyApiRetired()`.
- **MAIN's tickets.** `TicketService::relayTicket()` and `fileTicket()` (split out of `forRecord`)
  mint for MAIN as the child or fetcher, into `config/cluster/replica/tickets.json` under a lock. A
  pull is minted when its URL is built (`DataPlane::relayUrl` / `fileUrl` on MAIN, through
  `MainDataPlane::ensureRelay` / `ensureFile`, when no ticket with an hour left is held). The cron
  (`cron:cluster`, `MainDataPlane::refresh`) mints anew at each epoch for every stream MAIN holds,
  and for every file read on demand within a day (`cluster_meta` `main_dataplane_files`, at most 500).
  A pull MAIN cannot mint for (no licence, a legacy owner or parent) keeps the legacy URL, and so does
  MAIN's own file.
- **MAIN's agent.** `xc_agent run -role main -state main_agent.json` (XC_VM_Fanout,
  `mainrole.go`): the loopback proxy with MAIN's key, the server id and generation from
  `config/cluster/main.json` (which it re-reads every 2 s; a changed identity ends it and `run.sh`
  starts it again), the routes and owners' keys from `replica/servers.json`, and MAIN's own clock.
  `run.sh`, `service` and `cron:root_signals` start it whenever `main.json` exists. The binary is
  MAIN's cached one for its arch. `DataPlane::on()` on MAIN is `main.json`'s `dataplane`.
- **The readers.** `checkSource` and `getSSLLog` use `DataPlane::fileUrl` while MAIN's data plane
  is on, else `getFile` of the server's IP as before.

What it does not change: MAIN's own `/api`, the load balancers' `/api` code and `InternalApiController`,
the viewer API, and proxies. A proxy is never a node, so with one in the cluster `legacyApiRetired()`
stays false and every node keeps its `/api`.

Tests: `MainDataPlaneTest` (off by default; the key, `main.json`, MAIN's entry and a re-key; tickets
naming MAIN with the path only the owner opens; the cron's epochs; MAIN's URLs through the agent, and
the legacy ones for a legacy server, MAIN's own file and no licence); on the agent's side
`TestMainReadsANodesFileWithItsOwnKey`, `TestMainFollowsItsIdentity`,
`TestRunMainEndsWhenTheIdentityChanges`, `TestNewMainAgentNeedsAWholeIdentity` and
`TestInteropMainDataPlane`, which loads what MAIN's real `MainDataPlane` wrote.

### The MITM harness

A test harness, no runtime change: an attacker on the wire that holds no key, between an agent and
MAIN's cluster API, and between an agent's loopback relay proxy and a parent or owner. It is
`internal/mitm` in XC_VM_Fanout: a reverse proxy that records every exchange and can rewrite a
request or a reply, answer in the server's place (an old reply, a refusal), or send a recorded
request again byte for byte. The attacks are Go tests in `internal/clusteragent`, in two halves.

- **The agent's half** (`mitm_test.go`; runs in CI): an honest MAIN in Go behind the proxy.
  `TestMITMRepliesThatDoNotAuthenticateAreNeverTaken`: a BOX flipped, cut or extended, no body, the
  MAC flipped or dropped, the reply's stamp or nonce moved, another content type or status, an
  error page, an old reply played to a new request, another op's reply, an old commands batch —
  each is `ErrTransport`, nothing reaches the caller, and the node takes no MAIN time from it.
  `TestMITMDenialsMustBeSignedForThisRequest`: an old denial played to a new request, a byte of it
  flipped, its signature flipped or dropped, its reason rewritten. `TestMITMHealthMustBeSigned`:
  `health` and the install probe refuse a document or signature the wire changed.
- **MAIN's half** (`interop_mitm_test.go`; `XCVM_PANEL_DIR`, as the other interop tests):
  `TestInteropMITM` runs the agent through the proxy against MAIN's real PHP `ClusterApi`. Every
  change to a request is refused before anything about the node moves, with the reason the order of
  checks gives: the body flipped or cut, the path, query, content type, agent, stamp or nonce
  changed, the MAC flipped (401 `BAD_MAC`); no MAC (400 `BAD_REQUEST`); a stamp ten minutes old
  (401 `CLOCK_SKEW`); another node (401 `UNKNOWN_NODE`); another protocol (426 `PROTO`);
  `token_refresh` with its node signature dropped or flipped (401 `BAD_NODE_SIG`). The proxy then
  holds a genuine heartbeat back and sends tampered copies first: MAIN refuses them without spending
  the nonce, takes the genuine request once, and answers it with 401 `REPLAY` after that. MAIN's
  real replies changed on their way back, or an old one, are not taken. `TestInteropMITMDataPlane`
  puts the proxy between the agent's relay proxy and a parent running MAIN's real `RelayGuard` and
  `FileTicketServer`: a relay with another target, a query added, the ticket or proof flipped, no
  proof, an earlier proof, or the password added is refused, and a relay request sent again too; a
  file chunk flipped, cut, answered with another chunk's bytes or digest, or asked at another offset
  is never passed on, and whatever came before it is the file's own bytes; a chunk request sent
  again is refused.

That is the Phase 8 acceptance's MITM'd body and replayed headers, measured against the real code
rather than a fleet. The replays go out from the same host; MAIN refuses them by the spent nonce,
which does not depend on the sender.

**What it found.**

- **A denial's HTTP status is not signed**, its document is. Every branch the agent takes on a
  refusal pairs the status with the signed reason (`replayWait`, `skewClock`, `busyWait`,
  `laneRefusal`, `FLOW_OFF` and `BAD_REQUEST`), and `fatal()` reads the reason alone. So a status the
  wire changed only turns a refusal the agent handles into a plain one, which dropping the reply
  does anyway. `TestMITMADenialsStatusOnlyEverLosesItsHandling` pins that, and `TestInteropMITM`
  shows MAIN's `BAD_MAC` answered as a 200 is still that `BAD_MAC`.
- **A file chunk's digest was not bound to the request** (closed since, in
  [the next entry](#binding-a-chunks-digest-to-its-request)). It named the ticket, the offset, the
  size, the hash and the total, and its `iat` was not checked. So an old answer for the same chunk
  under the same ticket was taken. It was the file's own bytes at that offset, but a file rewritten
  in place during one ticket's life could be read as a mix of old and new chunks.
  `TestInteropMITMDataPlane` pinned that behaviour until the digest named the request's nonce; it
  now refuses the old answer.

Also fixed: `TestInteropEvents` checked the agent's spool the moment MAIN held the events, before the
agent had dropped the spool file after reading MAIN's answer. It now waits for the drain.

**Not covered.** The wire outside these two paths: the node's PHP to its own agent (a local socket),
SSH provisioning, and what a node reads over HTTPS from MAIN's nginx (the harness speaks plain HTTP
to the servers it fronts, as the interop tests do). A MITM that holds a key (a node's session
secret, a parent's box key) is out of scope; it is a compromised node.

### Binding a chunk's digest to its request

The limit the MITM harness found. A chunk's `X-XCVM-File-Digest` now names the request it answers:
`nonce`, the hex of the 16-byte nonce in that request's `X-XCVM-File-Auth`. The fetcher made the
nonce fresh, and the owner has just spent it. So an owner's earlier answer for the same chunk under
the same ticket names an earlier nonce, and the fetcher refuses it.

```text
doc = {"v":1, "typ":"xcvm-file-digest", "tid", "owner_sid", "size", "sha256", "iat",
       "offset", "total", "nonce"}   (sorted keys; nonce is a chunk's only, 32 lowercase hex)
```

- **MAIN as the owner** (`FileTicketServer`): passes the verified proof's nonce to
  `DataPlaneTrust::signDigest`, which signs it into `FileDigest::document`. A load balancer's PHP
  sends it to its agent as `nonce` in `POST /v1/file_digest` (`AgentDataPlane::fileDigest`).
- **A load balancer as the owner** (XC_VM_Fanout, `dataplane.go`): `/v1/file_digest` takes an
  optional `nonce` (a chunk's only, 32 lowercase hex, else `400`) and signs it in.
- **The fetcher** (`relayproxy.go`, `clustercrypto/filedigest.go`): `upstream` returns the nonce
  it signed, and `check` requires `FileDigest.Answers(nonce, MainNowMs())`: the digest names that
  nonce, compared in constant time. A digest that has a `nonce` with no `offset`, or one that is not
  32 lowercase hex, does not verify on either side.
- **Vectors.** `cluster_dataplane_vectors.json`'s `file_digest` names `file_auth`'s nonce (the two
  describe one request and its answer); its SHA-256 is now
  `7016c21b9600dedc1745f4644772ff53e899ae8f83a14c27357c95172e7498b0` in both repositories.
  `xcvm_core` does not take part in these formats.

**Mixed versions.** Nothing new is refused on the wire that an N−1 peer sends, so `proto` does not
move and the vector file's `version` stays 1.
- An owner from before this change signs no `nonce`. Its PHP leaves the field out, and an older
  agent ignores it in `/v1/file_digest`, which decodes leniently.
- The fetcher takes a digest without a `nonce` only while its `iat` is within the request window
  (±90 s, plus a second for `iat`'s rounding) on MAIN's clock, as the agent measures it. That clock
  is the one the owner stamped on.
- A fetcher from before this change ignores the field.
- The wire cannot turn a new owner's digest into an N−1 one, because the `nonce` is under the
  signature.

**Not built / limits.**
- **The N−1 fallback stays open.** A digest without a `nonce` is still taken inside the window, so
  against an owner from before this change an old answer can be replayed for about 90 s. Refusing
  every digest without a `nonce` waits until no such owner is left. Since
  [The N−1 digest report](#the-n1-digest-report), the fleet reports those owners and
  `lb_digest_nonce_required` refuses them.
- **A whole-file digest names no request.** It has no `offset` and nothing sends one over the wire
  today. It carries no `nonce`.
- **The fetcher is Go only.** PHP's `FileDigest::verify` checks the `nonce`'s shape, but PHP never
  fetches a chunk, so it has no counterpart to `Answers`.

**Tests.**
- PHP: `FileDigestTest` (the document with a `nonce`; a `nonce` on a whole file or malformed,
  refused at signing and at verification; MAIN signing the nonce it is given; the agent never asked
  with a malformed one), `FileTicketTest` (each served chunk's digest names its request's
  `X-XCVM-File-Auth` nonce), `ClusterDataplaneVectorsTest` and `ClusterVectorsTest` (the new pin).
- Go: `TestFileDigestVectors` (the digest answers `file_auth`'s nonce at any time and no other
  nonce), `TestFileDigestNonce` (without a `nonce`: inside the window only; malformed ones
  refused), `TestAChunksDigestNamesTheRequestsNonce` and `TestDataPlaneRefusesWhatItCannotSign`
  (`/v1/file_digest`), `TestXfileRefusesAnOldAnswerForTheSameChunk` (an owner's earlier answer
  refused; an N−1 owner taken fresh, refused two minutes old or ahead).
- Interop: `TestInteropMITMDataPlane` has "an old answer for the same chunk" among the attacks
  that are refused, against MAIN's real `FileTicketServer`.

### Re-licensing a fleet

The second Phase 9 increment left one question open: how a node gets a fresh lease within about
two minutes of MAIN's licence coming back, when tokens refresh only at half the rotation. The
options were an early token refresh, or a token in the heartbeat reply. Neither is built. A lapse
already puts every node on a path that asks MAIN again within a minute.

- **The token stops with the lease.** `xcvm_core` issues both under the same check, the licence
  binding, so a lapsed MAIN mints neither. The extension refuses before `TokenService` writes an
  epoch row, so a refused refresh leaves no row and no audit. A lease runs to its token's `exp`
  plus `lb_partition_tolerance_h`, never less, so a node cannot lose its lease while it still
  holds that lease's token.
- **Before the token expires.** From the token's `refresh_at` on, `token_refresh` is refused with
  `LICENCE_INVALID`. The agent asks again at every heartbeat tick, with no backoff. The first
  refresh after the licence returns brings a token and its lease. Since
  [the agent's refresh backoff](#the-agents-refresh-backoff-rotate-nows-outcome-and-the-canarys-reach-check),
  it waits 2 s after a refused refresh, doubling to 30 s.
- **After the token has expired.** The node re-keys. While MAIN's challenge answers
  `licence_ok: false`, the agent waits `RekeyPoll` (60 s, ±10 %) between tries, not the doubling
  backoff. The first re-key after the licence returns comes within about a minute, with a lease.
- **`hard` revocation mode** also refuses the heartbeats. A licence fence MAIN queued reaches the
  node with those refusals, and the node lifts it at the first heartbeat MAIN accepts again. Its
  token and lease come back by the same two paths.

**Not built / limits.**
- **Nothing rate-limits the refused refreshes.** Until the licence returns, each node asks for a
  refresh at every heartbeat tick (2 s at the default), from its `refresh_at` until its token
  expires. Each ask costs MAIN an epoch lookup and the extension's cached binding check, and the
  agent logs each refusal. Built since: [the agent's refresh backoff](#the-agents-refresh-backoff-rotate-nows-outcome-and-the-canarys-reach-check).
- **The ~1 minute is measured in tests only.** The agent's tests and a simulated fleet
  ([xc_cluster_sim](#xc_cluster_sim)) measure it. No real fleet has.

**Tests.** Agent: `TestARefreshRefusedForTheLicenceIsAskedAgainEachTick` (since replaced by
`TestARefusedRefreshBacksOffAndIsStillAskedAgain`) and
`TestAnUnlicensedRekeyIsAskedAgainEveryPoll`. Each fails if its path gains a backoff. Interop:
`TestInteropSimARelicensedFleetGetsItsLeasesBack`, three nodes against MAIN's real PHP.

### The N−1 digest report

A fetcher still takes a chunk digest without a `nonce` inside the ±90 s window, because an owner
from before [the nonce](#binding-a-chunks-digest-to-its-request) sends no other kind. Removing that
fallback waits until no such owner is left. The fleet now reports them, and an operator removes
the fallback with one setting.

- **Who reports it.** The fetcher, because only the fetcher knows. An owner's `nonce` needs both
  its PHP (which passes the nonce to `/v1/file_digest`) and its agent. The panel's version cannot
  tell either: `v2.5.3` was published before the nonce, and `main` still says 2.5.3.
- **The agent** (`relayproxy.go`, `DigestN1Report`). It notes each owner whose digest it took
  without a `nonce`. Every heartbeat carries `digest_n1`: those owners' server ids, sorted, from
  the last 24 h, and `[]` for none. It is sent even when empty, so MAIN can tell "none" from an
  agent that does not report.
- **MAIN** (`Domain\Cluster\NodeDigestN1`, from the `heartbeat` op). It keeps the list in
  `cluster_nodes.digest_n1` (migration 055, and `database.sql`), and writes only when the list
  changes. A heartbeat without the field changes nothing, so an older agent's row stays NULL.
- **Shown.** The Cluster Nodes page's figures have *Chunk digests without a nonce (24 h)*: the
  owners the active nodes name, or *none*, and how many active nodes do not report
  (`ClusterOverview::digestN1()`). `server:diagnose <id>` has a *Chunk digests* line for the node.
- **The switch.** `lb_digest_nonce_required` (a settings switch, off by default, migration 055)
  reaches the nodes in the replica's `settings` section. With it on, the agent refuses a digest
  without a `nonce` instead of taking it, and the node's own `server:diagnose` says so. Turn it on
  once the page names no owner and every active node reports.

**Not built / limits.**
- **MAIN's own reads.** This note first said they were not covered: MAIN's data-plane client
  (`xc_agent run -role main`) sends no heartbeat, and its replica directory had no `settings`
  section. They are now; see [MAIN's own reads in the digest report](#mains-own-reads-in-the-digest-report).
- **The list is capped at 32 owners per node** (the 255-character column). Past that, the page
  names only those 32.
- **"None" means none fetched.** An owner nobody read from in 24 h is not named, even if it is old.

**Tests.** PHP: `NodeDigestN1Test` (the report read strictly, written only on change, left alone by
an older agent and by a table from before 055, the page's summary, both `server:diagnose` lines),
`ClusterSettingsTest`. Agent: `TestXfileReportsAnOwnerWithoutTheNonceAndCanRefuseIt`, and
`TestHeartbeatCarriesTheRelayReport` (which now also checks `digest_n1`). Interop:
`TestInteropWithPanel` has MAIN's real `ClusterApi` keep `[]`, then the owner.

### Switching a cluster setting off

The settings form posts a checkbox only while it is checked, so a full save stores 0 only for the
boxes `SettingsService` lists. `lb_lease_fence` was not on that list: the Settings page could turn
the lease fence on and never off. `SettingsService::checkboxes()` now adds every on/off setting of
`ClusterSettings::INTS` (`ClusterSettings::switches()`), so a new cluster switch is covered
without editing the list. `SettingsCheckboxesTest` checks that every checkbox on the Settings page
is one the full save zeroes.

### xc_cluster_sim

`newClusterSim(t, n)` (XC_VM_Fanout, `internal/clusteragent/interop_sim_test.go`) runs MAIN's real
PHP `ClusterApi` under `php -S`, using the interop harness in `testdata/panel/`. It enrols n
agents by code on servers 7, 8, …, so a fleet's behaviour can be tested without a fleet. It needs
what the interop tests need: `XCVM_PANEL_DIR`, and `php` with sodium and pdo_sqlite. Without them
it skips.

- **More nodes.** The harness takes the extra servers from `XCVM_INTEROP_SERVERS`, and
  `enrol_code.php` and `approve.php` take a server id (7 by default). `interopNodeEnv` is now
  `interopMain` plus one `enrol`.
- **MAIN's licence.** `rig.licence(t, false)` creates `<db>.unlicensed`. While that file exists,
  `FakeClusterCrypto` issues no token and no lease, and sessions go on, as in graceful mode.
- **The first fleet test** is `TestInteropSimARelicensedFleetGetsItsLeasesBack`:
  1. Three nodes are past `refresh_at`.
  2. MAIN is unlicensed for a second, and no node gets a token.
  3. MAIN is licensed again, and every node holds a lease issued after that, within the test's
     20 s bound. It takes about one heartbeat tick.

**Not built / limits.**
- **A test harness, not a binary.** It runs under `go test`. There is no `xc_cluster_sim`
  command to run a fleet by hand.
- **One `php -S` serves the whole fleet**, one request at a time. It shows ordering and recovery,
  not MAIN's throughput.
- **Time was real.** A path measured in hours (a lease's `exp`, a token's expiry at the default
  rotation) needed the harness to move MAIN's clock. It does now: `rig.clock(t, d)` writes
  `<db>.clock`, and `common.php` fixes `ClusterClock` at the time plus `d` for each request, so
  tokens and leases are issued, and stamps checked, in that time.
  `TestInteropSimAFleetFollowsMainsClockPastItsTokens` moves it two hours ahead once the fleet is
  enrolled: each node takes MAIN's time from the `CLOCK_SKEW` refusal, gets `TOKEN_EXPIRED`,
  re-keys, and holds a lease issued at the new time. The agents' own clocks stay real, so the
  harness moves MAIN, not the fleet. A jump inside the enrolment window leaves `enrol_complete`
  refused, as the enrolment's token expired; such a node is enrolled again.
- **A flaky check fixed.** `TestInteropSimARelicensedFleetGetsItsLeasesBack` waited for a lease
  issued at most a second before the licence came back. The last node enrolled could still hold
  its enrolment lease inside that second (leases are stamped in whole seconds), so the test went on
  and failed on its epoch (once in two runs here). It now waits for a lease issued after.
- **The Phase 8 48-hour measurement** (no encoder restart at L = 5) needs real encoders and stays
  a fleet measure.

### Producers under a fence

The fourth Phase 9 increment's fence refused new viewers and, past the drain, ended the running
ones, but the node's encoders kept running. The plan's FENCED state releases them after the drain.

- **The release.** `cron:streams` asks `NodeLease::refusesEverything()` once per pass. While it
  holds, every stream still running a producer (supervised, a live monitor, or a producer pid) is
  released with `StreamProcess::stopStream()` without its stop: the processes end, and the
  stream's record keeps its state.
- **Nothing starts.** `StreamProcess::startMonitor()` returns `MONITOR_FENCED` and starts
  nothing while the fence stands, whoever asks: the cron, a viewer's on-demand start, an admin.
- **The way back.** The first pass after the fence lifts finds each stream selected, as for any
  producer that died, and starts it again. A supervised stream that goes down is written with
  status 1 or 2, never 0, so it stays selected.

**Not built / limits.** VOD transcodes, TV-archive recorders and thumbnail workers are not
released: they serve no viewer, and with the producer gone the archive records nothing. Since
[typed starts and what a fence stops](#typed-starts-and-what-a-fence-stops), a released stream's
thumbnail and archive workers go with it, and nothing new starts under the fence.

**Tests.** `FencedProducersTest` (`startMonitor` starts nothing when fenced), and
`ModeTwoPathsTest::testAFencedNodeReleasesItsProducersAndKeepsTheirRecords` (a child PHP on a
fenced node kills a stand-in producer, starts nothing, and keeps the record).

### MAIN's writes to a node's streams

Three things only reached a node at its next poll, or never. The plan's MAIN → LB commands carry
them now.

- **The R2 `streams` section changes.** `StreamVersions` hands the servers each change stamped
  to `StreamPush` (MAIN only, behind `class_exists`). When the request ends, each gets one
  `config.changed {sections: [streams]}`: an active node whose STREAMS flow is on and whose agent
  takes the command. The agent syncs its replica and takes a streams delta at once. A mass edit
  is still one command per node.
- **MAIN's own writes to runtime columns** (Rescan VOD, Recreate channels, the symlink tools, a
  channel saved with re-encode). A node whose STREAMS flow is on reads these from its own store,
  so it never saw them: it analysed no movie again and rebuilt no channel's sources.
  `StreamAssign::send()` gives such a node `stream.assign {stream_ids, set, fill?}` for the
  streams it runs, at most 500 a command. `cluster:exec` writes them into the store
  (`StreamRuntime::assign`) without sending them back: `set` as given, `fill` only where the
  node's value is empty (Rescan VOD's `pid = IF(pid, pid, 1)`). Only
  `StreamStateWriter::STATE_FIELDS` and scalar values are taken. `pids_create_channel` is not
  sent: the store answers its default for it.
- **Encoding work MAIN queues onto a node.** `QueueSink::enqueue()` notes the server, and
  `StreamPush` sends it `queue.poke` when the request ends, if its CONTENT flow is on. The node
  drops `QueueSink::POKE`, and the queue daemon's wait (`QueueSink::waitPoke`) ends at once
  instead of within `queue_loop`.

`stream.assign` and `queue.poke` are granting, so a MAIN without a licence sends neither.
`config.changed` is restrictive.

**Not built / limits.**
- **Older node PHP.** A node PHP from before this refuses `stream.assign` and `queue.poke`
  (`unknown command type`); the command is acked failed, and the node keeps its old behaviour.
- **Typed starts.** `stream.start`, `vod.start` and `recording.start` still have no producer:
  starts go as `node.rpc`, which works. Since [typed starts and what a fence stops](#typed-starts-and-what-a-fence-stops),
  `stream.start` and `vod.start` do, to a node whose PHP runs them.
- **Large rescans.** A Rescan VOD of a large catalogue is one command per 500 movies per node.

**Tests.** `StreamPushTest` (who is told, once; everyone on a reset; a bump's holders; the queue
poke and the daemon's wait), `StreamAssignTest` (only nodes that keep the columns, the streams
they run, the split; the node's store written without sending anything back; malformed
assignments refused), and `CommandBusRegistryTest` (both types signed as the registry has them).

### Cache jobs without a licence

A MAIN whose licence lapsed signs no granting command. So a node in mode 2 got none of its cache
jobs, and the files of movies deleted meanwhile, and the connection files of viewers MAIN
closed, stayed on it. `node.cache` could not simply become restrictive, since it also carries
cache rebuilds.

- **The extension** (XC_VM_CoreExtention, ADR-002) adds `node.purge {jobs}`. It is restrictive
  only as a whole: every job is an object of `type` with `id` or `uuid`, and its type one that
  removes something (`delete_con`, `drop_con`, `delete_vod`, `delete_vods`). Anything else is
  refused (`RECORD:args`). The registry fixture carries `job_types` and `job_keys`, and its digest
  moved in all three repos.
- **MAIN** (`ClusterRoute::cache`) sends the removals first as `node.purge`, and the rest as
  `node.cache`. An extension from before it refuses the type (`RECORD:type`), and the removals then
  go as `node.cache`, as before.
- **The node** runs `node.purge` like `node.cache`, with only the removal jobs
  (`CacheJobs::PURGES`; `cluster:exec` refuses anything else). The agent runs it on a quarantined
  node too, as a restrictive type.

**Not built / limits.** It needs an `xcvm_core` release: until then MAIN's extension refuses the
type, and every cache job stays granting.

**Tests.** Extension: `sign.rs` (every removal type, each refusal shape, the registry self-check),
`vectors.rs` (the fixture's digest). Panel: `CommandBusRegistryTest` (a removal routed as
`node.purge`, a rebuild as `node.cache`), `ClusterVectorsTest` (the digest). Agent:
`TestRestrictiveIsTheRegistrys` (its restrictive set is the registry's R types).

### A failing agent on a node, and a canary

> MAIN's half (the rollout and its hold) is **superseded** by [Binaries from GitHub on every node](#binaries-from-github-on-every-node) (2026-10-01). The node's half, the trial `run.sh` judges, stays.

The [agent rollout](#keeping-the-fleets-agent-current-phase-4-sixth-increment) had no way back.
`node.root agent_binary` replaced the binary and kept nothing. A new agent that started (it answers
`version`, which the install checks) but then failed at run left `run.sh` restarting it every two
seconds. The node then answered nothing, and only SSH reached it. And MAIN offered the same
version to the next node in order as soon as the first one's slot ended.

- **On the node** (`ArtefactStage::installAgent`, `bin/xc_agent/run.sh`). The install keeps the
  binary it replaces as `xc_agent.prev` and puts the new one on trial: `xc_agent.trial`,
  `<installed at> <failed starts>`. `run.sh` judges each run while the trial lasts. A run that
  exits within 60 s of its start counts as a failed start, and on the third within
  `TRIAL_SEC` (10 min) of the install `.prev` is moved back over the new binary and the log says
  so. A run that lasts, an exit 3 (MAIN stopped the node), or the end of the trial ends the trial.
  MAIN's own agent (`-role main`) is judged the same way.
- **On MAIN** (`AgentUpgrades::push`). A node offered a version that does not run it `RETRY_SEC`
  (15 min) later, whether its install failed or it rolled back, holds that version back from every
  node not yet offered it. The hold is audited once as `cluster.agent_rollout_held` and lasts until
  the node runs the version or MAIN pins another. With `cluster_agent_upgrade_parallel` at 1, the
  lowest server id is the canary. A node is offered the same version at most `MAX_TRIES` (3)
  times.

**Not built / limits.**
- **Only a failure at start is caught.** An agent that runs but misbehaves (it never reaches MAIN,
  or it serves wrongly) is not rolled back. MAIN sees it only as a node that stays on its old
  version, or goes offline. Since [the canary's reach check](#the-agents-refresh-backoff-rotate-nows-outcome-and-the-canarys-reach-check),
  one that never reaches MAIN is; one that reaches MAIN and serves wrongly still is not.
- **Nothing lifts a hold by hand.** An operator lifts it by fixing the canary node, or by pinning
  another binary (`console.php agent_binary`). Since then, `console.php agent_binary release <version>`.
- **One step back.** `.prev` is only the binary the last install replaced, so a second bad install
  on the same node keeps no good binary. Since then, `.prev` is the last binary that proved itself.

**Tests.** `AgentRunShTest` runs `run.sh` for real in a throwaway home. A new binary that fails
three times at start is replaced by the previous one; past its trial, a failing binary is left
alone. `AgentUpgradeTest` (a failed node holds the rest back until it runs the version; a node
is offered a version at most three times), `ArtefactHashRefusalTest` (the install keeps `.prev` and writes
`.trial`).

### MAIN's own reads in the digest report

[The N−1 digest report](#the-n1-digest-report) covered the nodes only. MAIN's data-plane agent
sends no heartbeat, and its replica directory had no `settings` section, so MAIN's own fetches
were neither reported nor refused.

- **The report.** MAIN's agent writes `main_digest_n1.json` beside its key state:
  `{"owners": [...], "at_ms": <unix ms>}`, the same list a node's heartbeat carries. It writes when
  the list changes, and every 10 minutes otherwise (`MainDigestN1Every`). `MainDataPlane::digestN1()`
  reads it while it is at most 30 minutes old (`DIGEST_N1_STALE`). The Cluster Nodes page's
  summary adds MAIN's owners to the nodes'.
- **The switch.** `MainDataPlane::refresh()` writes `replica/settings.json` for MAIN's agent, with
  only `lb_digest_nonce_required`, and rewrites it only when it changes. The agent reads it as a
  node reads its replica's `settings` section, so turning the switch on refuses MAIN's fetches too.

**Not built / limits.**
- **A stopped agent.** MAIN's report older than 30 minutes is ignored, not counted as silent: the
  page's *do not report* count is still nodes only.
- **Only the one setting.** MAIN's `settings.json` carries nothing else. The lease fence reads the
  same file and finds its switch absent, so it stays off on MAIN, as before.

**Tests.** `MainDataPlaneTest` (the switch in MAIN's `settings.json`, a fresh report read, a
stopped agent's ignored, MAIN's owners in the page's summary), and `TestMainReportsTheOwnersWhoseDigestNamedNoRequest` (XC_VM_Fanout:
written on change and on the interval, not otherwise).

### Sections in parts

A whole section whose sealed record passed 4 MiB was answered `too_large`, and the node dropped its
copy: the bouquets of a panel with many large packages never reached a node, and in mode 2, with
no database, its readers kept the cache the last apply built. The plan moves large transfers in
parts of at most 4 MiB, staged in `tmp/cluster_xfer/`.

- **Asked for.** The agent's `config` poll says `"parts": true`. To it, MAIN answers a section too
  large for one reply `{too_large, etag, parts}`, having staged the sealed record for this node
  (`ReplicaBuilder::whole`, `stage()`). An agent that does not say `parts` gets `too_large` alone,
  and MAIN stages nothing for it, as before.
- **Fetched.** `config {part: {section, etag, n}}` answers `{part: {section, etag, n, parts, data}}`,
  `data` being that 4 MiB of the record's base64 (`ClusterApi::configPart`, `ReplicaBuilder::part`).
  The agent fetches the parts in order and joins them (`fetchParts`, XC_VM_Fanout). It then opens
  the record as any section sent whole: MAIN's signature, sealed to this node, its section, ETag and
  generation. No part needs its own signature, since the record's covers the whole, and each reply
  is boxed and MAC'd in the session.
- **Staged once.** Sealing is not deterministic, so parts from two sealings would not join. A poll
  that finds the section already staged for the node under this ETag answers from the stage
  without sealing again, so parts fetched across polls fit together. Parts ride the `config` op,
  its lane and its semaphore: no new op.
- **Kept short.** `TMP_PATH` is tmpfs, and each node has its own stage: `<server id>.<section>.<etag>`,
  0600. Serving the last part removes it. Staging a section removes the node's older stages of it
  and every stage older than `STAGE_TTL` (15 minutes). A record past `MAX_PARTS` (32 parts, 128 MiB)
  is not staged, and gets `too_large` alone.
- **A failed fetch.** MAIN's `{gone: true}` (the stage expired, or the section changed), a part
  other than the one asked for, or a failed call drops the copy held, as `too_large` did. The
  agent keeps the ETag it held, so its next poll asks for the section, and its parts, anew.

**Not built / limits.**
- **Memory while a fleet fetches.** Each node's stage is the size of its sealed record, so a 20 MiB
  section staged for 50 nodes at once holds about 1 GiB of tmpfs until the parts are fetched or
  the stages expire.
- **The blocklist** is not sent in parts: a whole blocklist section alone past 8 MiB still stops
  the reply, as before.
- **Older agents** still drop the section: parts need this agent.

**Tests.** PHP: `ClusterApiTest::testASectionTooLargeForOneReplyIsFetchedInParts`. It covers an older
agent getting no stage, two parts that join into the section's record, a second poll reusing the
stage, the stage going with its last part, and the refusals. Agent:
`TestASectionTooLargeIsFetchedInParts` (the poll says `parts`, parts asked in order and joined;
`gone`, a part out of order and too many parts refused, the ETag kept). Interop:
`TestInteropWithPanel` adds a bouquet of 600,000 channels and takes it in parts from MAIN's real
PHP, whose stage is gone after the last part.

### A node in mode 2 updates, and its status and inventory

Three paths still took a node in mode 2 to MAIN's database, and the refusal stopped each of them.
A node that an operator had promoted to mode 2 could not be updated, never reported its version or
hardware, and never ran `status`'s own work.

- **The update.** `console.php update` wrote the node's `servers` row: `status` 5 before it starts
  the updater, then `status` 1 and the version from `post-update`. So `cluster:root` refused `update`
  and `rollback` in mode 2 (`RootSignalsCronJob::updatesHere`). The update now reports its status
  through `NodeStateSink::status()`: a `node.state {status}` event with TELEMETRY on, else the row
  as before. The version goes with the inventory, the same way. The refusal is gone, and
  `cluster:root` starts the updater in mode 2 as elsewhere.
- **A node's own status: a decision reversed.** `NodeStateSink` said `status` was never the node's
  to send. MAIN now takes a node's `status` only as 5 (updating, which takes it out of routing, as the
  legacy update always did) or 1 (back), and only over one of them (`EventIngest::nodeStatus`: `WHERE
  status IN (1, 5)`). So an install state MAIN set (3 installing, 4 failed) is never the node's to
  leave, and no other value is taken. A node can already take itself out of routing by going silent,
  and a legacy node writes the whole row. Without this, a mode 2 node cannot be updated at all.
- **`cron:servers`.** It counted this node's `lines_live` rows and running streams in
  `streams_servers` before it sent `node.inventory`. Those counts feed only the `servers_stats` row,
  which the agent's telemetry replaces: with TELEMETRY on they are not taken, and a node in mode 2
  never falls back to writing MAIN's row when the spool does not take the inventory.
- **MAIN's release, exactly** (plan §12, "an `update` signal carrying `{version}`"). `NodeActions::update`
  used to send `{action: update}` alone, and a load balancer then installed GitHub's newest release,
  which could be newer than MAIN's. The command now names MAIN's version (`XC_VM_VERSION`), and the node
  installs that release's `loadbalancer.tar.gz` exactly (`getVersionFile`, as a rollback does). A node
  already at that release or past it changes nothing; going back is a rollback's job. Only an `x.y.z`
  version is passed to the updater (`UpdateCommand::pinned`). An older node ignores the version and
  updates to the newest, as before. MAIN's own update still takes GitHub's newest.
- **`status`.** Its database check already stepped aside in mode 2, but its next read,
  `getServers()`, was a `SELECT * FROM servers` on MAIN's database. In mode 2 it now takes the
  replica's servers (`ServerRepository::getAll`), which is all its node work needs (its own
  `is_main`). So the permissions, nginx's config, root's crontab, the file limits and the init-script
  cleanup run at boot and after an update.

**Not built / limits.**
- **The Redis settings.** `status` still skips `configureRedisLb` in mode 2, since a node in mode 2
  reads no Redis of MAIN's.
- **The owner reviews the reversal.** Whether a node may report its own updating status is a trust
  decision. If it is refused, a mode 2 node goes back to being updated only over SSH or by demotion.
- **An agent that is down during `post-update`.** The "back" event waits in the spool. The node stays at
  5, out of routing, until the agent delivers it after the service restart.

**Tests.**
- `ClusterEventsTest::testANodeReportsItsUpdateInItsOwnStatusOnly`: 5 and back, its own row only, no
  other value, not a string, an install state kept, the spooled event, and `UpdateCommand` writing
  no `status` itself.
- `ModeTwoPathsTest`: `update` and `rollback` start the updater and log through the spool;
  `testTheServersMinuteSendsItsInventoryWithoutMainsDatabase`;
  `testStatusReadsTheServersFromTheReplica`. Each of the last two failed on the code before, with
  the refusal.

### Routing stops before a licence fence

The plan's first step when the licence is revoked (§4, "Licence revocation stops token generation") is
that MAIN stops routing new viewers to a node whose token expires within 10 minutes. Nothing did: the
Cluster Nodes page showed each node's fence window, and viewers kept landing on a node until it fenced
and sent them the not-on-air video.

- **The state.** MAIN's liveness loop (`LivenessService::tick`, every second) publishes `suspended`
  for an active node while MAIN holds no licence and the node's `token_exp` is less than
  `LICENCE_CUTOFF_SEC` (600) away. No token will follow the one it holds.
- **Routing.** `ServerRepository::getAll` treats `suspended` as it treats `offline`
  (`ClusterHealth::NO_ROUTING`). The node keeps serving the viewers it has until its own fence and
  drain. The HLS orphan purge still counts it as alive.
- **Back.** Once MAIN renews the node's token, or holds a licence again, the next pass publishes
  the node as judged, without the usual recovery wait (`NodeHealth::settle` does not rank
  `suspended`). An offline node stays offline.
- **The licence state** is the extension's `info()['licensed']`, read at most every 30 s
  (`LICENCE_CHECK_MS`). Without an extension to ask, nothing is suspended.

**Not built / limits.**
- **Up to 30 s late.** A licence lost less than 30 s ago is not seen yet.
- **One cutoff for both modes.** The hard mode fences sooner (about 12 min) through its own
  `node.fence`, so the cutoff there mostly adds the page badge.

**Tests.** `ClusterLivenessTest::testANodeWhoseTokenEndsSoonWithoutALicenceIsRoutedNothing` covers:
- licensed, nothing suspended;
- unlicensed, only the node whose token ends soon is suspended, and routing reads the state;
- a renewed token brings the node back at once;
- an offline node stays offline;
- without an extension to ask, nothing is suspended.

### A new licence key rotates the fleet's tokens

The plan (§4, "Licence change") has another valid key push `token.rotate_now`. The key is part of the
token chain's base (`B`, and so `CK_B`, in `xcvm_core`'s `cluster/binding.rs`). An epoch minted
under the old key keeps its stored `B` and runs to its `exp`, so nothing breaks. Without a push,
though, each node moves to the new key's chain only at its next scheduled refresh, up to
`lb_token_rotation_min` later.

- **The trigger.** `save_activation_key`, once the extension accepts the key
  (`LicenseGate::licensed()`), compares it with the key it replaced. If they differ, it calls
  `ClusterOverview::rotateAll`, the same as *Rotate all tokens now*: every active node that takes
  commands gets `token.rotate_now`, deduplicated and audited per node (`node.token_rotate`). The
  key's own audit line (`cluster.licence_key`) records how many were queued (`rotated`).
- **The same key saved again** rotates nothing.

**Not built / limits.**
- **No event.** The plan names an `ActivationKeyChangedEvent`. The dashboard's save is the only
  writer of the key, so the action calls `rotateAll` directly. An event is worth adding when a second
  writer appears.
- **A key written by hand** (`config/activation_key` edited on disk) rotates nothing. Its nodes move
  at their next refresh, as before.
- **A rejected key** rotates nothing: without a licence there is no new `B` to rotate to.

**Tests.** `ClusterOverviewTest::testANewLicenceKeyRotatesEveryToken` (the action, from its source;
it ends the request) beside `testRotateAllQueuesForEveryActiveNodeThatTakesCommands` (`rotateAll` itself).

### A P0 sequence that goes backwards quarantines the node

The plan names three kinds of authenticated evidence that quarantine a node (§4, "Quarantine"). Two
were built: a `hello` from another `instance_id`, and a re-key whose attestation names another
instance. The third, "a backwards P0 sequence", was not. `EventIngest` took any P0 batch at or
below the cursor as a repeat and applied none of it. So a second install sending as the node, for
example a snapshot of it with the same `instance_id`, had its events dropped without a trace.

- **What an agent sends.** It numbers P0 from the cursor `hello` gave it, and advances the number
  itself. It resends only the batch in flight (`<lane>.inflight`), whole, with the same events,
  until MAIN answers; after `USEQ_GAP` it renumbers forward. So below the cursor an honest agent
  sends one thing: an exact copy of a batch MAIN applied, when the reply was lost or a copy arrived
  late.
- **The record.** Each P0 batch MAIN goes on to apply leaves a fingerprint in the lane's lock
  file (`tmp/cluster_ingest/<server>_p0.lock`, held while the batch is applied): its first number,
  its count and the SHA-256 of its events. The newest stays however old, since the agent resends it
  for as long as it goes unanswered. The others are dropped 180 s later (`COPY_KEEP_MS`), because a
  copy signed before the agent moved on is refused past the 90 s request window. The fingerprint is
  written before the batch commits, so a copy that races the commit is still recognised.
- **Backwards.** A P0 batch that starts at or below the cursor and matches no fingerprint is not
  applied. That covers other events under an applied number, part of a batch, and a batch that
  overlaps the cursor. `events` then quarantines the node as `hello` does: state `quarantined`,
  reason `P0 sequence went backwards`, the audit line `node.quarantine` (`p0 backwards`, the first
  number, the count, the cursor), `config.changed` to the peers, and `409 NOT_ACTIVE`. The admin
  picks *Trust again* or *Revoke*.
- **No record, no quarantine.** Where the lane has no fingerprint (`tmp/` emptied by a reboot, a
  lock file that could not be opened or written), a batch at or below the cursor is a repeat, as
  before.

**Not built / limits.**
- **The genuine node is quarantined too.** MAIN cannot tell which of the two installs is the
  original. The admin decides, as for the other two kinds of evidence.
- **P1 is not checked.** Its numbers are a high-water mark, and gaps are allowed there.
- **After a reboot of MAIN**, the check starts again with the first batch applied.
- **The overlap was a `USEQ_GAP`.** A batch that overlapped the cursor used to be told the number
  MAIN expected, and it was then renumbered and applied. No agent sends one, so it now counts as
  backwards.

**Tests.**
- `ClusterEventsTest::testAP0BatchThatGoesBackIsNoRepeat`: other events under the same numbers,
  part of a batch, an overlap, a late copy, the newest however old, the 180 s bound, and no record.
- `ClusterApiTest::testAP0BatchThatGoesBackQuarantines`: through the `events` op, with its
  audit and the peers' `config.changed`.
- `ConnectionIngestIdempotencyTest`: its tail and overlap cases are now backwards.

### `servers_stats` pruned in batches, and its indexes

Plan §8 ("MAIN capacity") prunes `servers_stats` in batches, then builds `INDEX(time)` and
`INDEX(server_id, time)` online with `console.php cluster:maintain-stats`, outside
`MigrationRunner`. Neither was built. The table had its primary key alone, and `cron:cleanup` pruned
it, with `cluster_audit`, in one `DELETE … WHERE time < ?`. Every node adds a row a minute, so that
statement scanned the whole table and, on the first run over a long history, held it and its undo log
for as long as it ran. The per-server reads (`ProcessChecker::getWatchdog`, `proxy_api`) scanned it
too.

- **Batches.** `CleanupCronJob::prune` deletes 10,000 rows per statement (`PRUNE_BATCH`) until none
  are left or 20 s have passed (`PRUNE_SEC`, shared by both tables). The next hourly run goes on
  from there. Old rows sit at the start of the primary key, so each batch finds them quickly even
  before the index exists.
- **The indexes.** `cluster:maintain-stats` (MAIN only, one run at a time) reads `SHOW INDEX` and
  adds whichever of `time (time)` and `server_time (server_id, time)` is missing, with
  `ALGORITHM=INPLACE, LOCK=NONE`. An existing index whose leading columns are the same counts, so an
  operator's own index is not duplicated. A server that cannot build one in place refuses, and
  nothing falls back to a locking `ALTER`.
- **Who starts it.** `cron:cleanup` on MAIN, after pruning, starts the command detached
  (`ProcessRunner::start`) while an index is missing. A fresh install gets the indexes within the
  hour. The LB build strips the command.

**Not built / limits.**
- **`database.sql` is unchanged.** A new install gets the indexes from the first `cron:cleanup`.
- **A refused build is retried every hour**, and each attempt is written to the command's output
  only.

**Tests.** `ClusterMaintainStatsTest`: 25,003 old rows pruned in batches (one batch past the
deadline, the rest after), the rows retention keeps, the missing indexes by leading columns, the
`ALTER`'s exact text, and no answer from `SHOW INDEX`. Run once by hand on MariaDB 11.4: the prune took 25,003 rows, both
indexes were built in place, and `EXPLAIN` shows the prune and `getWatchdog` using them.

### A node's own clock, its badge, and the dashboard banner

Plan §4 ("Clock") shows a node whose clock is off MAIN's: a warning past 30 s, "degraded (clock)"
past 300 s, never a fence. Plan §11 puts the Cluster Nodes page's warnings on the dashboard too.
Neither was built, and the offset they read was wrong.

**The bug.** `HeartbeatService::record()` took `cluster_nodes.clock_offset_ms` as the heartbeat's
`X-XCVM-Ts` minus MAIN's clock. Since its first version the agent stamps every request with
`MainNowMs()` (local time plus the offset it learned from MAIN's last authenticated reply), so after
its first reply the offset read about 0 on every node, whatever its clock. An inventory copies that
offset into `servers.time_offset` (*Node state and inventory*, Phase 5), which the node's PHP subtracts from `time()`
to judge token expiry and admission. So a node whose clock was off judged both on its own wrong
clock. `server:diagnose` showed about 0 as well.

1. **The field.** Every heartbeat payload (the JSON inside the BOX) carries
   `"local_ms": <int>`, the node's own wall clock in unix milliseconds when it built the payload
   (`Agent.Heartbeat`, the xc_vm_fanout release after 0.14.0).
2. **MAIN.** `clock_offset_ms` is `local_ms` minus MAIN's clock when it handles the heartbeat,
   clamped to a signed 32-bit int, as before. A payload without an integer `local_ms` (an older
   agent) keeps the stamp, so it reads about 0 as it did. The offset includes the one-way latency,
   as the legacy cron's measure against the database clock did.
3. **The badge.** `ClusterOverview::clockBadge()` puts `clock ±Ns` (warning) past
   `ClusterDiagnosis::SKEW_WARN_MS` (30 s) and `degraded (clock) ±Ns` (danger) past
   `SKEW_DEGRADED_MS` (300 s) beside the node on the Cluster Nodes page and on the Servers list.
   Neither changes the node's state, its routing or its fence.
4. **The dashboard banner.** `ClusterOverview::dashboardBanners()` gives the dashboard the page's
   own `banners()` (licence and certificate), read from `cluster_nodes.state` and `token_exp`
   alone, so the dashboard walks no node. Nothing while the cluster API is off or has no extension.
   Whether MAIN is licensed is read as the liveness loop reads it (`LivenessService::licensed()`,
   cached for `LICENCE_CHECK_MS`); `ClusterNodesController` asks the same function now.

**Not built / limits.**
- **The other badges.** Divergence and the Settings Info-tab block came later (*The divergence
  badge and the Settings Info tab*). P0/P1 lag and "MAIN URL unreachable" are still missing.
- **An older agent** keeps reporting about 0 until it is updated.

**Tests.** PHP: `ClusterApiTest` (a heartbeat with `local_ms` ten minutes ahead records 600000; an
older one keeps the stamp's offset), `ClusterOverviewTest` (the badge's thresholds and a
non-numeric offset; the dashboard's banners against the page's, licensed, the API off, no
extension). Go: `TestAHeartbeatCarriesTheNodesOwnClock` (the payload carries the node's clock and
the request is still stamped with MAIN's).

### The divergence badge and the Settings Info tab

Plan §11 lists two more pieces of the admin UI. The first is the Servers list's
"connection-count divergence" badge, the digest mismatch of §8: "a badge flags digest mismatches".
The second is an Info-tab block of the cluster API's state.

- **The divergence badge.** `ConnectionDigest::check` already keeps each node's last check in
  `TMP_PATH/cluster_digest/<sid>.json` (`{at, miss, asked}`); `ConnectionDigest::state()` now reads
  it. `ClusterOverview::divergenceBadge()` shows `connections resynced Ns ago` for 5 minutes after
  MAIN asked the node for a snapshot (`RESYNC_SHOWN_MS`), and `connections differ` while the last
  check, less than a minute old, disagreed. A node that sends no digest (CONNECTIONS off) never has
  one.
- **One list of badges.** `ClusterOverview::nodeBadges()` gives the Servers list each badge with its
  help text's key: the clock and the divergence badges now.
- **The Info tab.** `ClusterOverview::infoRows()` adds a *Cluster* table under *Versions* when the
  extension is one the panel takes. Its rows are:
  - the API switch;
  - the licence gate and its `kid`;
  - the panel key's fingerprint (what `cluster:pin-root` takes), or why the root is not initialised;
  - the nodes by state;
  - the next token refresh (the soonest newest-epoch `refresh_at` of an active node);
  - the extension's version and API against the range the panel takes;
  - the extension's clock;
  - the stream secret's `kid` and its last rotation, or a rotation in progress.

  They come from the extension's `cluster_info()` (`ClusterCryptoFactory::info()`), `cluster_nodes`,
  `cluster_node_epochs` and `cluster_meta`.

**Not built / limits.**
- **P0/P1 lag and "MAIN URL unreachable"** needed the heartbeat to carry them, and came next
  (*Lane lag and unreachable MAIN URLs*).
- **The Info tab's labels are English**, as the *Versions* rows beside them are.

**Tests.** `ClusterOverviewTest`: the divergence badge's windows, and `nodeBadges()` reading the
digest's state file. The Info rows cover a licensed and an unlicensed extension, the nodes by
state, the next refresh (an enrolling node's does not count), the stream secret, and no extension.

### Lane lag and unreachable MAIN URLs

Plan §11's last two Servers-list badges are "P0/P1 lag" and "MAIN cluster URL unreachable from this
LB". MAIN knew neither. A lane's backlog was only in the agent's `/v1/status`, and the URLs the agent
failed to reach were only in its memory (`Client.failed`).

1. **The fields.** Every heartbeat payload now carries:
   - `"lanes": {"p0": {"files": n, "lag_ms": n}, "p1": {…}}`: each lane's spooled files, and the age
     of the oldest (0 when empty). It is sent only by an agent with a spool.
   - `"unreachable": [{"url": "…", "for_ms": n}]`: the MAIN URLs that failed (connect, TLS or timeout)
     and have not answered since, sorted by URL, with how long ago each first failed. The list is
     empty when none fail.

   Both are ages on the node's own clock, so no clock offset applies. They come from xc_vm_fanout,
   the release after 0.14.0: `Agent.laneLags`, `Client.Unreachable`. A MAIN that does not know them
   ignores them.
2. **MAIN** (`NodeLag`, migration 056: `cluster_nodes.p0_lag_since`, `p1_lag_since`,
   `unreachable_urls`) keeps transitions only:
   - A lane lags once its oldest event has waited past `ClusterDiagnosis::OUTBOX_LAG_SEC` (120 s), as
     `server:diagnose` judges it. `<lane>_lag_since` is then when that began, on MAIN's clock (unix
     seconds), and NULL again once the lane catches up.
   - `unreachable_urls` is the sorted, space-separated list, at most 1024 bytes, or NULL.
   - The row is written only on such a change. Each change is audited: `node.lane_lagging`,
     `node.lane_caught_up`, `node.urls_unreachable`, `node.urls_reachable`.
   - A heartbeat without the fields (an older agent), or a table before migration 056, changes
     nothing.
3. **The badges** (`ClusterOverview::nodeBadges()`):
   - `P0 lagging 5m` (danger, since P0 carries state) and `P1 lagging 5m` (warning). The age counts
     from when the lane crossed 120 s.
   - `MAIN URL unreachable` (warning), with the URLs in its tooltip.
   - `ClusterDiagnosis::span()`, the checks' duration format, is now public and formats both.
4. **The relay report's clock.** `NodeRelay` moved the agent's `since_ms` by the stamp's offset from
   MAIN's clock, which is about 0 since the agent stamps with MAIN's time (*A node's own clock*). It
   now takes the node's own clock (`HeartbeatService::nodeClockMs()`, from `local_ms`), as the
   clock offset does.

**Not built / limits.**
- **The cost.** The agent stats every spooled file at each heartbeat, as `/v1/status` does. Keeping
  each lane's oldest mtime is the upgrade, if a large backlog makes that costly.
- **A URL that an older policy kept** (a legacy port or URL) and that fails is reported like any
  other.
- **The interop suite** runs `NodeLag` on every real heartbeat (its harness has the columns), but
  asserts no badge: the unit tests on each side pin the contract.

**Tests.** PHP: `NodeLagTest` (strict reading of both reports, the transitions and their audit, a
table before the migration, the badges, the schema and the heartbeat's call); `NodeRelayTest` (the
node's own clock). Go: `TestAHeartbeatCarriesTheLanesLagAndTheURLsThatFail`.

### verify_host on a node that reads no database

`verify_host` refuses a request whose `Host` is not on the `allowed_domains` list: the enabled
servers' names and addresses, the active resellers' `reseller_dns`, and localhost. `cron:cache`
builds the list from MAIN's database. A node in mode 2 reads none, so it deleted the list, and there
`verify_host` checked nothing. Without the list, the streaming bootstrap also read a file that was
not there, so every viewer request logged a warning.

- **MAIN.** The `servers` section also carries `reseller_dns`: the non-empty `reseller_dns` of the
  users whose `status` is 1, once each, sorted, so the ETag moves only when they change. A
  reseller's new DNS reaches a node at its next poll (60 s): nothing announces it.
- **The node.** When the replica makes the servers cache its own (CONFIG on, both sections
  applied), `ReplicaApply` writes `allowed_domains` from the rows it applied and `reseller_dns`,
  through `ServerRepository::allowedDomains()`, the function `cron:cache` uses. `cron:cache` then
  leaves the list alone.
- **An older MAIN.** Its section has no `reseller_dns`. The node then keeps no list, as before: a
  list without the resellers would refuse their viewers.
- **The streaming bootstrap** reads the list only when the file exists, as `HostVerificationStage`
  does. With no list, hosts pass.

**Not built / limits.** In mode 1 with CONFIG off, `cron:cache` still builds the list from MAIN's
database, as before.

**Tests.** `AllowedDomainsTest` (the replica's rows and resellers give the database's list; once
each, never empty; `cron:cache` leaves the list to the replica), `ReplicaSectionsTest` (the section's
`reseller_dns`: active only, non-empty, once each, sorted), `ReplicaApplyTest` (with CONFIG on, the
applied replica writes the database's list; a section without `reseller_dns` leaves none).

### Members nothing used

An audit of the cluster code for unused members found six in the panel and four in the agent, and
removed them:
- **Panel:** `ClusterDiagnosis::LEASE_MAX_SEC` (the extension enforces the 26 h itself),
  `ReplicaApply::CACHES`, `DenialFactory::REASONS`, and `ReplicaBuilder::SECTION_SETTINGS` and
  `SECTION_SECRETS`. None had a reference.
- **`ClusterRoute::policyUpdate()`:** only its tests called it. The policy is announced by the
  heartbeat's `policy_ver` (see *Announced, not pushed*). The `policy.update` type stays in the
  registry, and the agent still takes it.
- **Agent:** `clustercrypto.WithinWindow` had no caller. `Agent.recheckReplica`, `Agent.sendTouches`
  and `ticketStore.drop` were wrappers that only tests still called, after the code moved to
  `recheckLocked`, `sendTouchBatch` and `setStreams`.

**Not built / limits.** Members that only tests use stay, where a test needs them (`ticketStore.held`).
The agent's vector-parity functions (`ValidNode`, `VerifyRelayAuth`, `JoinSigned`) stay: the
cross-language vectors pin them. `SessionKeys`' key id stays too, since it is part of the
extension's session record.

### Disaster recovery of MAIN's cluster keys

`cluster:export-keys <file>` and `cluster:import-keys <file>` wrap `xcvm_core`'s `cluster_export_keys()` and `cluster_import_keys()` (ADR-002, "Disaster recovery"):

- **What the bundle holds:** the root, the revocation floors and the clock high-water.
- **How it is protected:** Argon2id (1 GiB, 4 passes) of a passphrase, plus a pepper that only an extension holds. The extension enforces the passphrase strength.
- **Where the passphrase comes from:** typed twice without echo, `--passphrase-file`, or one line on standard input. It is never an argument, which any user could read in the process list.
- **Export:** writes the file 0600 and never overwrites.
- **Import:** records the panel keys as `cluster:init` does and audits `cluster.import_keys`. The extension refuses a different root already on the machine (`ROOT_EXISTS`); the same root again is a no-op. Nodes then recover with `token_rekey`, because their epoch records were sealed to the old machine.
- **No bundle:** `cluster:init` already covers the plan's `cluster:reinit` (a new root, audited `cluster.root_changed`). Then `cluster:reenrol --all` re-enrols the fleet from one credential file (*Re-enrolling the fleet*), or `server:enrol` re-enrols one node.
- **Tests:** `ClusterDrTest` covers the commands. Opt-in, with `XCVM_EXT_SO`, it runs the real extension, shrunk by `XCVM_TEST_DR_MEM_KIB`, across two config dirs.
- **Operator procedure:** `docs/en/administration/backup-strategy.md`.

### Shared MariaDB and Redis before lockdown (Phase 2)

Until lockdown, legacy and hybrid LBs still use MAIN's MariaDB (3306) and Redis (6379), which listen on every interface.

- **Redis commands.** `CONFIG`, `DEBUG`, `SHUTDOWN`, `SLAVEOF`, `REPLICAOF`, `MIGRATE` and `MODULE` are renamed to `""`, which removes them: with the password alone, an attacker can no longer write files through `CONFIG SET dir`, replicate from a hostile host or load a module.
  - `FLUSHALL`, `FLUSHDB` and `EVAL` stay, because the panel uses them.
  - The shipped `bin/redis/redis.conf` carries the lines. Updates never overwrite `bin/redis`, so `status` appends them once to an existing install's config (`RedisConfigHardening`). They take effect when Redis next restarts.
  - An operator who needs a command renames it to a secret name; any `rename-command` line for it is left alone.
- **Allowlist.** `cluster_db_allowlist` is off by default. When on, only these may connect to the two ports:
  - loopback and MAIN's own addresses;
  - every other `servers` row (LBs and proxies) except nodes in cluster mode 2;
  - `cluster_db_allowlist_extra`.
- **Proxies.** Every proxy stays on the allowlist, because which proxies still hold a `db_grant` is not recorded.
- **Hostnames.** A `server_ip` given as a name is resolved to its IPv4 addresses.
- **The chain.** `DbAllowlist` keeps the rules in the chain `XCVM_DB`, jumped to from INPUT for the two ports, for IPv4 and IPv6, and touches no other rule.
- **Reconciliation.** `RootSignalsCronJob` reconciles the chain every minute on MAIN. It compares the live chain with the wanted one and rewrites it with `iptables-restore --noflush` only when they differ, so a flush, a reboot or a server change is repaired within a minute. If the settings or the servers table cannot be read, the firewall is left as it is.
- **The command.** `cluster:db-allowlist status` lists the allowed sources, whether each family's chain is in sync, and the established connections from outside the list (`ss`). `apply` and `undo` set the setting and act at once.
- **Audit.** Changes are audited as `cluster.db_allowlist`.
- **No shell.** Tools run with literal argv through `proc_open`.
- **Password rotation** is Phase 9's (credentials and lockdown).

### Extension updates

`console.php xcvm_core` rolls back an update whose cluster API falls outside the panel's range when the installed one was inside it. `console.php xcvm_core status` reports what is loaded. Installing an exact pinned version needs versioned paths in the binaries repo; that prerequisite is still open.

## Consequences

- Changing any formula in Canonical changes `cluster_canonical_vectors.json`. That is a protocol change: raise `proto` and keep accepting N−1, per the plan's mixed-version rules.
- A new extension API version needs `API_MAX` raised, and new vectors copied in, in the same panel release.
- The crypto pipeline measures about 0.25 ms p99 for a 64 KB request against the plan's 1 ms budget. It measures about 41 ms for 8 MB in the CI container against the plan's 40 ms target, because the body is hashed twice and encrypted twice. `ClusterCryptoBenchTest` guards 8 MB at 2× the target. It is opt-in (`XCVM_BENCH=1`), because wall-clock timings depend on the machine and must not fail the unit suite on a slower one. The target itself needs a check on bundled PHP and production hardware.
- `ClusterExtensionIntegrationTest` runs the panel against a real test-hooks build of `xcvm_core` (opt-in, throwaway `XCVM_CONFIG_DIR`). It passed against the 2.2.2 build at the time of writing. Run again against the build with `node.purge` (XC_VM_CoreExtention `c598330`), two of its tests had gone stale, since CI never runs it: *every command MAIN sends is signed* compared `CommandBus::TYPES` with the commands it sent, and sent none of the types the Phase 9 producers added (`stream.stop`, `vod.stop`, `node.fence`, `node.unfence`, `node.quarantine`, `resync`, `policy.update`) nor this work's; and the registry walk classed `node.purge` without the jobs it must carry. Both now cover them, and the run passes (16 tests with `ClusterVectorsTest`).

### The agent's refresh backoff, rotate-now's outcome and the canary's reach check

Three agent gaps from the notes above, and the canary's two others.

- **A refused refresh backs off** (XC_VM_Fanout, `Agent.refresh`). A refresh MAIN refuses or
  that fails waits `RefreshRetryMin` (2 s) before the next scheduled one, doubling to
  `RefreshRetryMax` (30 s), and a refresh that works ends the wait. A fleet without a licence asks
  about once every 30 s, not every 2 s, and still gets its tokens and leases within 30 s of the
  licence's return: the plan's drill allows two minutes. The re-key path is unchanged
  (`RekeyPoll`).
- **`token.rotate_now` acks its outcome.** It used to ack `rotating` and start the background
  refresh: a refresh that failed was neither retried before `refresh_at` nor reported, and MAIN's
  row said the rotation happened. The agent now waits for a refresh already running, refreshes
  (whatever the backoff: an operator asked), and acks `rotated to epoch <n>`, or `ok: false` with
  `refresh failed: <why>`.
- **The canary's reach check.** An answered heartbeat touches `config/cluster/reached` (the first
  of a run at once, then at most every 10 s), and `xc_agent version` says so on a second line,
  `features: reached`. `ArtefactStage::installAgent` puts a binary that says it on trial with
  `reach` (`<installed at> <failed starts> reach`). `run.sh` then runs the agent with a watcher
  beside it: the trial ends once this run has touched the file since it started (the agent it
  replaced, still running for 10 s after the install, does not count), and if it has not within
  `REACH_SEC` (300 s) of the install, `.prev` is put back and the run ended. A run that merely
  lasts no longer ends a `reach` trial; failed starts still count. A binary that does not say it
  (one pinned from before) is judged by its starts alone, so it is never rolled back for a file it
  does not write.
- **`.prev` is the last proven binary.** An install over a binary still on trial keeps the `.prev`
  before it, unless there is none; one whose trial ended becomes `.prev` at the next install.
- **Releasing a held rollout** (`AgentUpgrades::release`, `console.php agent_binary release
  <version>`): each node that was offered the version at least `RETRY_SEC` ago and does not run it
  is forgotten, so none counts as a failure and each is offered it afresh (an offer still
  installing is left alone), and the hold's audit goes, so a new failure is audited again.
  Audited as `cluster.agent_rollout_released`.

**Not built / limits.**
- **MAIN unreachable during a trial** rolls the new agent back like a bad one: `.prev` cannot
  reach MAIN either, and MAIN, back, holds the rollout. The operator releases it.
- **Serving wrongly is not caught.** Reaching MAIN is the only thing the watcher judges.
- **MAIN's own agent** (`-role main`) sends no heartbeat, so it is judged by its starts alone.

**Tests.** Agent: `TestARefusedRefreshBacksOffAndIsStillAskedAgain`,
`TestRotateNowAcksTheRefreshsOutcome`, `TestAnAnsweredHeartbeatMarksTheNodeReached`; interop
`TestInteropRotateNowAcksTheNewEpoch` (MAIN's `CommandBus` queues it, the ack names the epoch MAIN
then holds). Panel: `AgentRunShTest` (a new agent that never reaches MAIN is rolled back; one that
does ends its trial, even with a reached file the agent before it wrote), `ArtefactHashRefusalTest`
(`reach` only for an agent that says it; `.prev` stays the last proven one), `AgentUpgradeTest`
(a released rollout is offered afresh; offers still installing are kept).

### A start miss, and the blocklist on change

Two Phase 7 gaps: a start that finds no entry for a stream MAIN assigned a moment before (the
plan's `stream_bundle` on a miss), and a blocklist change that waited for the next poll.

- **A start miss** (`StreamSource::streamRow`, `ReplicaStreamCache::syncMissing`). MAIN stamps a
  stream's version before it sends the command that starts it, so its record is already in the
  node's next delta; only the agent's sync may not have run yet. When a start finds no entry, the
  node's PHP asks its agent to sync the section now, `POST /v1/streams_sync` on the agent's socket
  (answered once the sync ends: 200, or 502 with why not), and reads again, where the entry is
  built from the record the agent stored. Only with an agent that says `streams_sync` in
  `flows.json`'s features; never for a stream the node has an entry for, or one whose record did
  not read at the last apply; once per stream a minute in a process. The plan's `stream_bundle`
  op, one stream's record fetched on its own, is not built: the delta MAIN already serves carries
  it, with no new record type to sign. `stream_bundle` stays in `ClusterPool`'s list of bulk ops,
  unused.
- **The blocklist on change** (`BlocklistChanges`, `StreamPush`). Every change the change log
  records (every block and unblock path) marks the request, and when it ends every active node
  whose CONFIG flow is on and whose agent takes `config.changed` is sent
  `config.changed {sections: [blocklist]}`: its agent syncs, stores the delta and runs
  `cluster:apply`, so the node's blocklist caches follow within seconds, and root's iptables at its
  minute sync. A node whose streams changed in the same request gets one command naming both
  sections: the command's type is its dedupe key, so a second would supersede the first. The
  plan's `node.root blocklist_sync` is not built: root's minute sync already applies the
  replica's blocklist, and nothing on the node could order a root action after the agent's sync.
- **The `node` section's nginx settings** (`cloudflare`, `mag_legacy_redirect`) need no applier of
  their own: root's `cron:root_signals` reads them through `SettingsManager`, which answers from
  the replica's `settings` section (both are in `lb_settings_keys.php`) wherever the replica owns
  the settings. The `node` section carries a copy no reader needs.

**Not built / limits.**
- **A start of a stream the node does not hold** still costs one sync a minute per process.
- **iptables** follows a block at root's next minute, not within seconds.

**Tests.** Agent: `TestAStreamsSyncOnRequestStoresWhatMainJustAssigned` (the stream MAIN assigned
since the last sync stored on request; a refusal answered 502; no replica, 404). Panel:
`ReplicaStreamCacheTest::testAStartThatMissesHasTheAgentSyncAndReadsAgain` (a stand-in agent
stores the record on the sync: the start finds it; no request without the feature; once a minute
for a stream the sync does not bring; none for a stream with an entry),
`StreamPushTest::testABlocklistChangeWakesTheConfigNodesInOneCommandWithTheStreams`.

### Typed starts, and what a fence stops

- **Typed starts** (`ClusterRoute::starts`, `ClusterRoute::start`). A start RPC (`{action:
  stream|vod, function: start, stream_ids[, force]}`) goes as one `stream.start {stream_id}` or
  `vod.start {stream_id, force}` per stream, deduped per stream (`<type>:<id>`), granting, to a
  node whose agent says `typed_starts` at hello: its `cluster:exec --types` lists `stream.start`.
  Any other node keeps `node.rpc`, which an older node PHP understands and a typed start would be
  refused by. `cluster:exec` runs them as `node.rpc`'s stream and vod starts ran them
  (`startMonitor` with a restart; `stopMovie`, then `startMovie` when forced, else the queue). The
  extension already classes both as granting. `recording.start` has no producer and needs none: a
  node starts the recordings due on it from its replica (`cron:vod`), and MAIN reaches it by
  scheduling them.
- **What a fence stops.** Past the drain, `cron:streams` releases a stream's thumbnail and TV
  archive workers with its producer: they read the stream, and the first pass after the fence
  lifts starts them again. Nothing new starts under the fence: `startThumbnail`, the `thumbnail`,
  `archive` and `record` commands, the queue daemon's movie and channel passes (their rows wait),
  and `cron:vod`'s due recordings.

**Not built / limits.**
- **A running VOD transcode or channel build finishes** under the fence: it serves no viewer,
  killing it would fail the movie and lose the work, and the queue has no way to hand a row back
  to pending (`QueueSink::update` takes pids above 0 only, and in CONTENT mode the queue is MAIN's).
- **A recording already running** runs to its end; with its stream's producer released it records
  nothing.

**Tests.** `ClusterRestrictiveProducersTest::testAStartIsTypedOnlyWhereTheNodesPhpRunsIt` and
`testClusterExecRunsTheStops` (the starts too); agent `TestTypedStartsFollowThePHP`;
`FencedProducersTest::testAFencedNodeStartsNoWorkerRecordingEncodeOrBuild`;
`ModeTwoPathsTest::testAFencedNodeReleasesItsProducersAndKeepsTheirRecords` (the thumbnail worker
goes with its producer).

### The node's own loopback

Phase 8's acceptance wants `live_streaming_pass` in no process's command line. Two of a node's
own pulls still carried it.

- **The local RTMP output** (`StreamProcess`, `rtmp_output`) published to
  `rtmp://127.0.0.1:<port>/live/<id>?password=<live_streaming_pass>`, in ffmpeg's command line.
  nginx-rtmp asks `rtmp.php` at `on_publish`, and `rtmp.php` takes a publish from 127.0.0.1
  without a password at all, so the output now names none.
- **The recorder** (`RecordCommand`) read its own `/admin/live` and `/admin/timeshift` with the
  fleet's secret, which pulls any stream from any server. It now presents a `LoopbackToken`:
  `lb1-<exp>-<hmac>`, HMAC-SHA256 under the node's own key (`config/cluster/loopback.key`, 32
  random bytes, made at first use, 0600) over the stream and a 24 h expiry. `RelayGuard` admits
  it as `LOOPBACK` for that stream, from 127.0.0.1 or ::1 only, on the endpoints that take a
  password; a token from any other address is refused and never tried as the password. When the
  key cannot be made, the recorder falls back to the secret, as before.

**Not built / limits.** A legacy relay (DATAPLANE off) still pulls its parent with the secret, by
design: that is what the data plane's tickets replace.

**Tests.** `LoopbackTokenTest` (a token opens its stream until it expires, no other stream, not
tampered, and no token verifies before the key exists; RelayGuard takes it from the node itself
only), `StreamProcessBuildLiveTest::testRtmpOutputAppendsFlvTarget` (no secret in the command).

### The viewer APIs on a node in mode 2 (D16)

D16 stops routing the viewer APIs on load balancers (`player_api`, `enigma2`, `xplugin`, `epg`,
`playlist`, `/stream/auth`, `/stream/probe`), and section 3 lists them among the routes the LB's
nginx drops. `/stream/auth`, `/stream/probe`, the Ministra portal and every other `.php` already
answer 404 on a load balancer. The five viewer APIs were still routed to its front controller,
which reads the viewer's line from MAIN's database: in mode 2 that read is refused, so they could
not answer there anyway.

- **A switch per node.** `viewer_api.conf` (`set $viewer_api 0|1;`, beside `api_legacy.conf`)
  is included by the LB's nginx, and both forms of the five routes (`/api/<name>` and
  `/<name>[.php]`) answer 404 while it is 0. `cron:root_signals` writes it each minute
  (`RootSignalsCronJob::viewerApiConf`): 0 on a node in mode 2 (`NodeRole::refusesConnects`), 1
  everywhere else, and nothing on MAIN, whose nginx has no such switch. The archive ships it at
  1, so an LB's nginx never misses the include.

**How it differs from the plan.** The plan removes the routes from the LB's nginx and strips the
viewer-API controllers from the LB build. One build serves every mode, and a node in mode 0 or 1
still serves its viewers' API calls as before, so the routes stay and answer 404 only where they
cannot work.

**Not built / limits.**
- **Clients that use a node's address** for these calls get a 404 once the node is in mode 2: they
  must use MAIN's address, as the plan's viewer-API routing assumes.
- **After an update** the archive's default (1) stands until root's next minute.

**Later: 404 on every load balancer.** The viewer-API controllers need `Domain/User`, which the
load balancer build strips as privileged (`verify-lb-archive.sh`), so on a node in mode 0 or 1 the
routed request ended in a fatal error (`Class "XcVm\Domain\User\UserRepository" not found`, in the
test panel's `panel_logs`). `viewerApiConf()` now writes 0 on every load balancer and the archive
ships 0: clients use MAIN's address for these calls in every mode. Nothing that worked stops
working. The switch stays, for a build that can serve them on a node.

**Tests.** `LbNginxApiLegacyTest::testTheViewerApisAreGuardedAndAnswer404OnEveryLoadBalancer` (the
include, the guard first in both locations, the shipped default, 0 in every mode and state, nothing
on MAIN), `testTheLbBuildShipsEveryFileItsNginxIncludes`;
`ModeTwoPathsTest::testTheRootSignalsMinuteReadsTheReplicaNotMainsDatabase` (a real mode 2 root
minute writes 0).

### The fanout daemon and xcvm_core from MAIN

> **Superseded** by [Binaries from GitHub on every node](#binaries-from-github-on-every-node) (2026-10-01): MAIN no longer hands out the agent, the fanout daemon or xcvm_core.

Section 5: `xc_fanout` and `xcvm_core` "follow the same path on nodes in mode 1 or 2" as the
agent. A review had kept them out (every node downloads them from GitHub itself, each checked by
SHA-256), until a fleet without GitHub access is a target. They are built now, on request, reusing
the agent's path.

- **MAIN's copies.** `xc_fanout-linux-<arch>` sits in the agent's cache
  (`AgentBinaryCommand::cached` with `FANOUT_PREFIX`), from the same release, checked against its
  `SHA256SUMS`: `console.php fanout_binary cache [arch…]`. `xcvm_core-<group>.tar.gz` (`php8.1`,
  `php8.4`) sits in `bin/xcvm_core/cache/`, checked against the binaries repo's `SHA256SUMS`:
  `console.php xcvm_core cache [group…]`. MAIN keeps its own arch's daemon and its own PHP's
  archive at each of its own installs. Each copy's `.version` pins it, as the agent's does.
- **Artefacts.** `fanout/<arch>` and `core/<group>` in `ArtefactRegistry`, granted with
  `node.root fanout_binary {arch}` and `node.root xcvm_core {group}` (cluster only, as
  `agent_binary`), each with its version.
- **On the node.** Root installs only its staged, checked copy, and only for this node's arch or
  PHP group (else refused and audited): the daemon through `FanoutBinaryCommand::install` (it
  must run here, then replaces the binary atomically, records its version and restarts), the
  extension through `XcvmCoreCommand::installStaged` (load-tested in a fresh PHP, kept only if it
  loads and keeps the cluster API this panel speaks, else the previous `.so` is put back).
- **What a node runs.** Its watchdog reports `versions` (`xc_fanout`, `xcvm_core`, its PHP
  group), which reaches `watchdog_data` whether MAIN takes it from the heartbeat (each value
  version-shaped or null) or the node writes it.
- **The rollout.** `AgentUpgrades::push(…, 'fanout')` and `'core'`, from `cron:cluster` beside the
  agent's, with its rules: `cluster_agent_upgrade_parallel` at a time, the canary hold,
  `MAX_TRIES`, and `console.php agent_binary release <version> fanout|core`. Only to an active
  node in mode 1 or 2 whose agent takes artefacts and that runs an older version than MAIN's
  copy for its arch or group. Never a downgrade: a node in mode 1 still updates them from GitHub
  itself, and may be ahead of MAIN.
- **Mode 2.** A node in mode 2 no longer updates them from GitHub (`cron:root_signals`' hourly
  checks): MAIN is its only source.
- **The agent's contract.** It takes the grants `fanout/<arch>` and `core/php<M>.<m>`, and says
  `artefact_binaries` at hello, beside `artefact`, while the node's `cluster:exec --types` lists
  `root:fanout_binary` and `root:xcvm_core` (`ClusterExecCommand::ROOT_BINARIES`). MAIN grants
  the two only to such a node (`ArtefactGrants::takesBinaries`): an older PHP's root would refuse
  them, and a refusal holds the version's rollout for the fleet.

**Not built / limits.**
- **MAIN holds what it was asked to cache**, and its own arch and PHP. A node in mode 2 of another
  arch or PHP gets no update until an operator caches that one.
- **Pinned in mode 2 only.** A node in mode 1 may run a newer version from GitHub.
- **An agent that takes no artefacts**, or does not say `artefact_binaries`, gets neither.

**Tests.** `AgentUpgradeTest::testTheFanoutDaemonAndXcvmCoreFollowTheAgentsPath` (behind, ahead,
no copy for its PHP, mode 0, nothing reported, no artefacts, each kind's own offers);
`ClusterArtefactTest::testTheRegistryServesOnlyWhatMainNamesItself` (both kinds, pinned by their
version, and the ids refused); `ArtefactHashRefusalTest::testActionsTakeOnlyAStagedArtefact`
(neither action takes anything but root's staged copy); `ClusterTelemetryTest::testTheNodesVersionsComeAlong`;
`ClusterExecCommandTest::testItSaysWhichCommandTypesItRuns`; agent `TestArtefactBinariesFollowThePHP`,
`TestArtefactGrantShape`.

### Binaries from GitHub on every node

Decided by the project owner on 2026-10-01. It supersedes [Keeping the fleet's agent current](#keeping-the-fleets-agent-current-phase-4-sixth-increment),
MAIN's half of [A failing agent on a node, and a canary](#a-failing-agent-on-a-node-and-a-canary)
and [The fanout daemon and xcvm_core from MAIN](#the-fanout-daemon-and-xcvm_core-from-main).
Every node, MAIN included and whatever its mode, takes `xc_agent`, `xc_fanout` and `xcvm_core`
from their GitHub releases itself. MAIN hands none of them out.

- **The hourly checks.** `cron:root_signals` runs `console.php fanout_binary` and `console.php
  xcvm_core` about once an hour, the first pass at once, in mode 2 too. `fanout_binary` installs
  the daemon and the agent from the same XC_VM_Fanout release (one tag, one `SHA256SUMS`). With
  fanout switched off it installs the agent alone (`fanout_binary agent`).
- **The agent's install.** The asset is downloaded to root's temp directory and checked against
  `SHA256SUMS`. `ArtefactStage::installAgent` then copies it in as the agent's user, checks it
  again, runs it once and renames it in.
  - **The trial.** Where an agent runs, the new binary is on trial as before (`.prev`, `.trial`,
    `run.sh`'s start and reach checks). Where none runs (a node not enrolled yet), it is not: a trial
    nobody judges would be judged at enrolment, long after the install, and rolled back.
  - **No retry loop.** The version tried is kept in `xc_agent.tried`. A release rolled back on a
    node is not fetched there again every hour, only a newer one (or `force`).
- **Enrolment.** `LbInstallFlow::provisionCluster` no longer uploads the agent. Over the same SSH
  session, the node runs `console.php fanout_binary agent` once its agent directory is xc_vm's. A
  node that cannot get one (GitHub unreachable, no asset for its arch) stays legacy, or fails the
  install in API mode, as before. `cluster:main-dataplane on` installs MAIN's own agent the same
  way when it is missing.
- **Removed:**
  - MAIN's cache (`AgentBinaryCommand`), and `fanout_binary cache` and `xcvm_core cache`;
  - the rollout (`AgentUpgrades`), with the canary hold and `agent_binary release`;
  - the root actions `agent_binary`, `fanout_binary` and `xcvm_core`, and their artefacts
    `agent/<arch>`, `fanout/<arch>` and `core/<group>`;
  - the `cluster_agent_upgrade_parallel` setting (its column stays, unread);
  - the watchdog's `versions`;
  - `ClusterExecCommand::ROOT_BINARIES`, so the agent no longer says `artefact_binaries`.

  A newer node's root refuses an older MAIN's `node.root agent_binary` as an unknown action.
  Artefacts remain for off-air videos and custom modules.

**Limits.**
- **A node needs GitHub.** One without outbound HTTPS to GitHub keeps the binaries it has, and a
  fresh one cannot be enrolled.
- **No fleet canary.** Each node takes a release at its next hourly check. A release that fails on
  one node is rolled back there by `run.sh` and not retried, but the other nodes are not held back.
- **No pinning to MAIN.** Each node runs the latest release of its channel
  (`UpdateChannels::fanout()`, `bin()`), whatever MAIN runs.

**Tests:**
- `ArtefactHashRefusalTest`:
  - `testAnAgentInstalledWhereNoneRunsIsNotOnTrial`;
  - `testTheAgentFromItsReleaseIsFetchedOnlyWhenNewAndNotTriedHere`;
  - `testActionsTakeOnlyAStagedArtefact`: the three actions install nothing.
- `LbProvisionClusterTest`:
  - `testEnrolsTheNodeOverSsh`: no upload; the node installs the agent after the chown and before
    keygen;
  - `testLegacyWhenDisabledOrWithoutAnAgent`.
- `ServerEnrolCommandTest` and `ClusterReenrolCommandTest`: a node that cannot install one.
- `ClusterArtefactTest`:
  - `testRootActionsCarryTheirArtefactsGrant`: the three actions are unknown;
  - `testTheRegistryServesOnlyWhatMainNamesItself`: their ids are refused.
- `ClusterExecCommandTest::testItSaysWhichCommandTypesItRuns`.

### Drills on the test panel

Section 14's scripted drills, run on the test panel (MAIN and one load balancer in mode 1, both on
this branch), with each change put back afterwards.

- **Licence drill** (`lb_token_rotation_min` 5, the activation key moved aside). MAIN was
  unlicensed at once. The node's next refresh was refused (`403 LICENCE_INVALID`), and a
  `conn.kill_worker` sent meanwhile still killed its target, since restrictive commands sign without
  a licence. With the key back, the node's next refresh (`rotate_now`) went through with no SSH,
  within about 1.5 minutes. No fence: `lb_lease_fence` is off by default.
- **`kill -9` of the agent during HLS** (COMMANDS, STREAMS and CONNECTIONS on, one HLS viewer).
  `run.sh` restarted the agent within about 3 s, and the viewer was never dropped. MAIN logged its
  session once. For 10 to 30 s after the kill, the node's playlists stalled: one refresh hung past
  8 s, or playlists came without segments. PHP's calls to the restarting agent wait out their
  timeouts (connect up to 2 s, then 1 s, or 2.5 s for admission), several per request. That is
  within a player's buffer of three segments.
- **Clock steps of ±2 h** on the load balancer, NTP off. Each step cost one immediate re-key (its
  token epoch read as unusable by the new clock). Heartbeats went on, the node stayed active, and
  MAIN's measured `time_offset` followed (7200, then 0).
- **`https_required` without a certificate** (the test panel is IP-only). MAIN's self-probe fails
  (`https_disabled`), and the guard refuses the switch (`cluster_error_https_probe`): the transport
  stays `auto`. The recovery through the HTTP challenge needs a certificate that lapses, and stays
  with `HttpsRequiredRecoveryTest`.

- **An LB reboot with MAIN unreachable** (2026-10-01): the LB in mode 1 with CONFIG on, its
  replica applied, and the LB's address dropped on MAIN.
  - **The boot:** the LB booted in about 40 s. `cluster:apply --from-disk` rebuilt the settings
    and servers caches from the replica. nginx, PHP-FPM, the agent, the daemon, the signals,
    watchdog and queue daemons and every cron came up.
  - **With MAIN still unreachable:**
    - A viewer request was refused in 6 ms, and `player_api` answered in 0.2 s, with no 5xx.
    - The crons ran and finished each minute.
    - Processes in mode 1 still tried MAIN's database, 11 times in the first minute. The
      connect audit counted those attempts without refusing them, as mode 1 does.
  - **Recovery:** once MAIN was reachable, the agent's health check passed within seconds, and
    the node stayed active in the same generation.
  - **One drawback of the drill:** MAIN rewrites its INPUT chain itself (`P2PTV_BLOCK`), and it
    removed the drop rule about a minute later. That was after the boot, but a longer outage
    drill needs a block MAIN does not manage.
- **MAIN's HTTP port change** (2026-10-01), with one node. The admin's save was replayed from
  the CLI (`ServerService::announceMainEndpoints`, `changePort`).
  - **8080 to 8082 and back, each way:**
    - The cluster API answered on both ports within seconds.
    - The admin moved to the new port at the next `cron:root_signals` pass, under 20 s.
    - The old port kept serving the cluster API alone (`cluster.d/old_port.conf`, 7 days or
      until every node has moved off).
    - The LB's agent took the new URL and kept its generation, active throughout.
  - **A first try at 8081 failed and was reverted:** another service on the test MAIN already
    held that port. See "Found on the way".

- **xcvm_core version skew** (2026-10-01), 2.3.1 against 2.3.3, cluster API 1 on both:
  - **MAIN 2.3.3, LB 2.3.1:** the LB stayed licensed on a live lease, and its agent's health
    check passed. A channel started and stopped on it through MAIN's signed commands, and its
    telemetry stayed fresh (`lb-streams`, `lb-telemetry`, 4/4).
  - **MAIN 2.3.1, LB 2.3.3:** that pair, plus all of `cluster-nodes` (a flow switched on and
    off, rotate-now, a quarantine lifted, a fence and its lifting), passed 9/9.
  - **Getting MAIN onto 2.3.1:** the first attempt restarted MAIN's service. Its `status`
    queued `update_binaries`, and MAIN installed the new XC_VM_Binaries `01.10.2026` (md5
    verified, staged, then swapped in) together with that bundle's xcvm_core 2.3.3. The
    attempt that counted swapped the `.so` and reloaded PHP-FPM instead.

**Not run.**
- **MAIN's port change with three nodes:** only one node.

**Found on the way.**
- **Two flaws in the binaries update:**
  - `update_binaries.sh` stopped the panel before it downloaded the bundle.
  - XC_VM_Binaries `01.10.2026` was published without assets, so each try got a 404, started the
    panel again, and that start's `status` queued the update once more. MAIN went down every
    minute.
  - Both are fixed: the script stops the service only once the bundle is staged, and
    `console.php binaries` first checks that the release has this distribution's bundle. The
    second fix also reaches panels whose `bin/install` an update never refreshes.
- **The test LB's link to the default E2E source** (a multi-variant HLS) was too slow for the
  monitor's 15 s probe. The LB specs ran with a single-variant source (`XC_E2E_STREAM_SOURCE`).
- **A MAIN port nginx cannot bind is stored and announced anyway.**
  - **What happened:** the first try of the port drill moved MAIN to 8081, which another service
    on the test MAIN already held. nginx's reload failed (`bind() ... Address already in use`)
    and nginx kept its old configuration, so the panel stayed up on 8080. Meanwhile, the
    servers row and the announcement to the nodes named 8081.
  - **Fixed since:** saving this machine's own server now refuses an HTTP, HTTPS or RTMP port
    another program already listens on (`ServerService::portsTaken`, `STATUS_PORT_IN_USE`); the
    server's own current ports don't count.
- **Every enrolled LB read as unlicensed, and served its live viewers without the daemon.**
  - **Why:** on a node, `xcvm_core`'s licence verdict (`XC_VM::license_valid()`) is a live
    cluster lease, since a node holds no activation key. Only `NodeLease::judge()` handed the
    extension the agent's lease, and only with `lb_lease_fence` on, which ships off. So the
    extension held none.
  - **What it did:** `LicenseGate` sent every live viewer down the legacy path. A viewer MAIN
    redirected to the test LB was served from the on-disk segments, while the daemon held the
    stream with no viewers (ADR 0003, C4).
  - **The fix:** `LicenseGate::licensed()` now hands the extension the lease first
    (`NodeLease::feedExtension()`), whatever the fence switch says. The switch still decides
    only whether the node fences. With the extension's lease file removed, the test LB stored
    the agent's lease again within 30 s, by itself, and the daemon then served the redirected
    viewer (`lb-delivery-kinds`, C4).
  - **Test:** `LeaseVerdictCacheTest::testTheLicenceGateFeedsTheLeaseWithTheSwitchOff`.

### Per-node viewer-token keys (H1, increments one to three)

The design is `docs/superpowers/specs/2026-10-01-per-node-viewer-keys-design.md`.

**The risk.** A node serves a viewer on the strength of the token MAIN minted at the redirect alone: user, line, channel and admission all come from inside it. Every token was sealed with the fleet's one secret (`live_streaming_pass`, keyed by `OPENSSL_EXTRA`), which every node holds. So whoever held one node could mint tokens that MAIN and every other node accepted.

**Built (`Core/Cluster/ViewerKey`).**
- **The key.** Node *n*'s key is `K_n = HMAC-SHA256(live_streaming_pass, "xc_vm viewer key v1|" ‖ n)`. MAIN derives it when it mints and stores nothing new. Rotating the stream secret rotates every `K_n`.
- **Delivery.** The replica's `secrets` section carries the node's own key as `viewer_key`, in the same `{kid, current, previous, previous_valid_until}` form. Inside the replaced secret's window, `previous` is `K_n` under that secret.
  - A node on older code leaves the key alone, and the agent stores the section opaquely.
- **The node keeps and reports it.** With CONFIG on, the node keeps the key in `config/viewer_key` (0600) and reports its kid as `servers.viewer_key_fp` (migration 059, a `node.state` field).
  - It reports at each change.
  - In mode 2 it also reports whenever its kept copy differs, for example after a report the spool didn't take.
  - A failed report is never an apply failure.
- **Minting on MAIN.** `ViewerKey::mint` seals with `K_n` for a node that reports the kid of `K_n` under the current secret, or under the replaced one inside its window (a node that hasn't applied the rotation yet). Any other node gets the shared secret's token, as before, and still reads it.
  - The reader is the server the redirect URL names: its originator when a proxy fronts it.
  - Callers: `auth.php`'s eight redirect tokens (live, adaptive, VOD, timeshift, thumbnails, subtitles) and the admin player's `uitoken` (`StreamViewController`, `player.php`).
- **Reading on the node.** `Encryption::readToken` tries the node's own keys first, for the viewer-token context only. MAIN holds none, so it pays nothing.
- **The node's own tokens (second increment).** The tokens a node mints for itself and reads back go through `ViewerKey::mintOwn`: HLS segment and key links (`HLSGenerator`) and timeshift HLS. Once the node has its own key they are sealed with it; until then they use the shared secret, as before.
- **The off-air redirect.** `OffAirHandler::showVideoServer` mints through `ViewerKey::mint` for the server it sends the viewer to (its originator when a proxy fronts it).

**The containment (third increment).**
- **What MAIN sends.** A locked-down MAIN (`ClusterLockdown::state()`) sends a node in mode 2 that reports a key MAIN mints its tokens with the stream secret's SHA-256 in place of the value (`streaming_pass_hash`, `ReplicaBuilder::withholdsStreamPass`).
  - A key reported under the replaced secret counts too, inside its window, so a rotation never hands the new value out.
  - A node that reports no such key, a node in mode 1, and every node before lockdown get the value, as before.
- **What the node does.** It keeps the hash (`config/stream_pass_hash`, 0600), keeps no value (its settings cache has an empty `live_streaming_pass`), and removes the value it replaced (`stream_secret.prev`). Once MAIN sends the value again, as after `cluster:lockdown --undo`, the hash goes.
  - A section that carries both the value and its hash is refused.
- **What still reads.**
  - With no shared secret, `Encryption::readToken` opens only with the node's own keys. Under an empty key, anyone who knows `OPENSSL_EXTRA` could seal a token.
  - `RelayGuard` and RTMP check a legacy password against the value or else the hash (`ViewerKey::passMatches`).
  - `key.php` and `segment.php` serve a node that has its own key.
  - A node sending a viewer to itself (an off-air redirect) mints with its own key.
- **Then rotate the stream secret** (`cluster:rotate-stream-secret`, D20). MAIN itself still reads tokens under the shared secret, and a value taken from a node before lockdown keeps working on MAIN until the rotation.

**Not built yet.**
- **The RTMP and Ministra tokens** still use the shared secret. They are minted on MAIN and read on MAIN, or on a node that still holds the value.
- **Legacy uses of the value on a node without it.**
  - The legacy-password fallback of `DataPlane::relayUrl` and `admin/live`'s password links, which only legacy children use; lockdown leaves none.
  - The recorder's fallback when its loopback token can't be issued.
  - The legacy cron lock path, which hashes no value.

  They get an empty password, which every check refuses.
- **A node that missed a rotation's window** (offline for more than 10 min) still reports the key it holds. MAIN mints its tokens with that key, derived from the replaced secret past its window (`StreamSecret::replacedValue`), and keeps its section hashed. That costs nothing, since the node accepts that key anyway until it applies the new one, which it does at its next apply. Only a node two rotations behind reports a key no one holds; it gets the value again, and with it the shared secret's tokens.
- **Holding the key in `xcvm_core`.** The design's optional fourth increment.

**Tests.**
- `ViewerKeyTest`:
  - each node's key is its own;
  - a token for a reporting node opens with its key alone, not with the shared secret or another node's key;
  - a node that reports nothing, a stale key or another node's key gets the shared secret's token;
  - the rotation window;
  - the node keeps its keys (0600) and reads with them first;
  - its own tokens use its own key once it has one, and read back.
- `ReplicaApplyTest::testTheNodesOwnViewerKeyIsKeptAndReported`, `testANodeSentTheSecretsHashKeepsItAndNoValue` and `testASecretsSectionWithBothTheValueAndItsHashIsRefused`.
- `ReplicaBuilderSecretsTest::testALockedDownMainSendsANodeHoldingItsKeyTheSecretsHashNotItsValue`.
- `ViewerKeyTest` also covers increment 3:
  - with no shared secret only the node's own key opens, and a token sealed under an empty key is refused;
  - a legacy password checks against the value or else its hash;
  - a node sending a viewer to itself mints with its own key.
- `ReplicaBuilderSecretsTest` and `ClusterApiTest` check that the section carries the node's own key, and no other node's.


### VOD and timeshift bytes in the daemon (Phase 11)

**Before:** a movie (`vod.php`) or a continuous timeshift (`timeshift.php`, TS output) was read and written by one PHP-FPM worker for the whole download, its throttle a sleep loop. The plan kept this for "only if FPM pressure is measured"; the owner chose to move it.

**Built.** The bytes go to the xc_fanout daemon, as a live viewer's do (the daemon's `files` feature, XC_VM_Fanout).
- **The hand-over.** PHP still authenticates the viewer and records its connection, now with pid 0 as a daemon-served live viewer's. It then writes a manifest naming the file parts into the daemon's files directory (`<sockets>/files`, 0600, a random 32-hex name) and hands the byte path to nginx: `X-Accel-Redirect: /xc_fanout_file/<id>?c=<uuid>&m=<name>` (`FanoutClient::handOverFile`), an internal location on MAIN's and the LB's nginx that proxies to the daemon's `/file/<id>`. The worker is freed at once. No path is in a URL.
- **What the daemon does.** It reads and removes the manifest and serves the parts back to back:
  - HTTP ranges (`http.ServeContent`), across parts, and the token's range when the request has none;
  - the panel's throttle: free for `vod_limit_perc` of the response, then the movie's (or recording's) bitrate plus `vod_bitrate_plus`, never for a restreamer;
  - each write under the write deadline, and the kill channel.

  The viewer is counted in `/connections`, `/rates` and the kick like a live one, so `fanout_sync` closes the row once it leaves, the panel's kick drops it, and the agent's registry and events carry it.
- **Parts.** A movie is one part, the whole file. A timeshift is its queued minute files, the first from its offset.
- **When.** The hand-over happens with fanout usable (`FanoutMode`, `LicenseGate::fanoutUsable()`) and a daemon that has the `files` feature (`FanoutClient::supportsFiles()`, the answer kept a minute in `TMP_PATH/fanout_features`). Otherwise, and for a direct-proxy VOD, the PHP loop serves as before.

- **A direct-proxy movie** goes to a daemon with `file_urls`: its manifest names the source URL, which the daemon fetches with the viewer's range and the panel's User-Agent, paced as the cURL relay was (past 512 MB, three times the movie's average rate). PHP still probes the source's size and type first.
- **HLS timeshift segments** (`segment.php`, an archive minute or a partial first one) go to a daemon that serves files, with no viewer to count: an HLS row is not tracked per segment.
- **The file roots.** A manifest's paths must lie under the daemon's file roots (`-fileroots`, `/home/xc_vm/content` by default), so a manifest names nothing else the daemon's user could read.

**Not built.**
- **The per-connection speed file** (`DIVERGENCE_TMP_PATH`) is not written for a daemon-served file; `fanout_sync` takes the rate from the daemon's `/rates`, as for live.

**Tests.** `FanoutFileHandOverTest` checks the manifest (its name, mode and content, the throttle clamped, the token's range) and that the feature answer is kept a minute. XC_VM_Fanout's `files_test.go` covers:
- a whole file;
- a range across parts;
- refused manifests (relative, unclean, expired, empty, a directory, past the end, a name that isn't one);
- a viewer listed, rated and dropped like a live one, its holder forgotten once it leaves;
- the throttle after its share;
- the sweep of unread manifests.

### AEAD-framed relays (D11)

**Before:** a relay was authenticated at connect (the relay ticket and the child's per-connect proof) and its bytes then passed in the clear. A passive observer read them, and an active one could change them undetected. The plan accepted that scope by default; the owner chose AEAD framing.

**Built.**
- **The key.** The child's agent picks a fresh 32-byte session key per connect and SEALs it to the parent's box key (purpose `relay`, context `relay|<parent>|<stream>`).
  - For a node parent, the box key comes from the signed node list, which now carries each node's `box_pub` beside its `ed_pub`.
  - For MAIN, it is the panel box key, which MAIN opens through the extension (`cluster_open_sealed` with the `relay` purpose, XC_VM_CoreExtention).
  - The sealed key goes in the target as `rk=<base64url>`, which the relay proof signs, so it can't be swapped.
- **The frames.** `admin/live.php` opens the key (`RelaySeal::openKey`; a key that does not open refuses the relay), answers `X-XCVM-Relay-Seal: v1`, and seals everything it writes through an output buffer: `u32 len ‖ AES-256-GCM(key, nonce 0⁴ ‖ u64 counter, aad "xcvm relay v1")`, at most 64 KiB of plaintext a frame. A partial frame is flushed before each wait for the next segment.
- **No downgrade.** Each server reports whether it opens relay keys (`servers.relay_seal`, migration 060, with its inventory each minute; `RelaySeal::supported()`). A node needs its box key; MAIN tries a key sealed to its own panel box key, so an extension without the `relay` purpose reports 0. The signed servers section carries it. A child pulls from a parent marked as sealing only sealed: an unsealed answer is refused, and a frame that does not open or comes out of order ends the read. A parent not marked (older code, or MAIN on an older extension) is pulled as before.

- **`/xfile` too.** A file chunk from an owner marked as sealing is asked for with a key sealed under `file|<owner>|<ticket id>` (`RelaySeal::fileContext`), so a relay's key and a file's cannot stand in for each other. `FileTicketServer` frames the chunk, and the agent opens the frames before it checks the owner's digest over the plaintext.

**Not built.**
- **Viewer bytes and `/images`** stay direct, as D11 scoped.
- **Forward secrecy:** a parent's box key taken later opens a recorded relay's key.

**Tests.**
- `RelaySealTest`:
  - the frame vector the agent's reader is tested against;
  - long writes split, and nothing altered, cut short, reordered or under another key opens;
  - a node opens the key a child sealed to it, for that stream only.
- `ReplicaSectionsTest` and `ClusterApiTest`: the node list's `box_pub`.
- XC_VM_Fanout `relayseal_test.go`:
  - the panel's vector;
  - a sealed relay read whole;
  - an unsealed answer and a changed frame refused;
  - no key for a parent that doesn't seal;
  - the section's `relay_seal` and `box_pub`.
- XC_VM_CoreExtention `ClusterApiTest`: a relay key opens with the panel box key, for its stream only.

### Proxies on a signed channel (D8)

**Before:** a proxy's cron (XC_VM_Proxy's `callback.php`) posted its stats to MAIN's `/admin/proxy_api` once a minute, unsigned. It then ran whatever signals came back: reboot, restart or stop the services, block or unblock an IP, flush the firewall, reload nginx. MAIN trusted the request's source address (the Phase 0 stop-gap), and the proxy trusted the answer. Lockdown counted every proxy as a blocker, since one might still use MAIN's database, and the DB allowlist kept every proxy on 3306/6379.

**Built (`Domain/Server/ProxyKey`).**
- **The key.** Each proxy's key is `HMAC-SHA256(MAIN's proxy secret, "xcvm proxy key v1|" ‖ id ‖ "|" ‖ gen)`.
  - The secret is MAIN's alone (`config/proxy_secret`, 0600, made once and never replaced).
  - `gen` is the proxy's `proxy_key_gen` (migration 061), raised at each install, so a reinstalled proxy's old key opens nothing. MAIN stores no key.
  - The install (`ProxyInstallFlow::provisionKey`) writes the key to the proxy as hex in `config/proxy.key` (0600, root).
- **The request** carries `X-XCVM-Proxy-Auth: <ts ms>.<nonce>.<mac>`, an HMAC over the server id, the stamp, the nonce and the body's SHA-256. MAIN takes it within 90 s of its clock, each nonce once (`NonceStore`, as `proxy-<id>`, claimed only after the MAC verified). It reads the proxy's generation from the database, not from the servers cache, which an install can be ahead of.
- **The answer** is `{"payload": <signals>, "mac": …}`, bound to the request's nonce. The proxy runs only signals whose MAC verifies.
- **After the first signed request.** Once a proxy has signed one (`proxy_signed`):
  - its unsigned requests are refused;
  - lockdown no longer counts it as a blocker;
  - the DB allowlist leaves it out, since it uses MAIN's API only.

  An install resets `proxy_signed`, so a proxy installed from an archive from before this keeps the legacy source-address rule until it signs.

**The proxy (XC_VM_Proxy).** `callback.php` signs when the key is installed and runs signals only from a verified answer. `includes/proxy_auth.php` holds the code, and `tests/proxy_auth_check.php` (`make check`, run before each release build) checks it against `ProxyKeyTest`'s vectors. A proxy without a key works as before. **Deploying it needs an XC_VM_Proxy release**, which the panel's `cron:proxy` then ships to new installs.

**The route segment (`Domain/Server/ProxyRoute`).** The URL segment that routes a viewer through a proxy to its parent picks the parent and authenticates nothing: the parent still checks the viewer's token, which per-node keys (H1) bind to the parent.
- A proxy installed by this panel has an HMAC-SHA256 segment (`ProxyRoute::current`). Its install writes it into the proxy's nginx, after the key generation is raised.
- An older proxy (`proxy_key_gen` 0) keeps the `md5(proxy_parent_OPENSSL_EXTRA)` its nginx holds until its next install.
- Every site that builds a proxied URL goes through `ProxyRoute::segment`: MAIN's redirects and the off-air redirect, and a node's proxied HLS and timeshift playlists. `proxy_key_gen` is therefore a replicated server field. It isn't secret: the key needs MAIN's secret.
- The dead `X-Token` header the proxy's nginx sent its parent is gone.

**Not built.**
- **Existing proxies** get their key, and the new route segment, at their next install (Reinstall in the servers list). Until then, their channel stays unsigned and their route segment md5.

**Tests.**
- `ProxyKeyTest`:
  - the vectors the proxy checks against;
  - each proxy's and install's own key;
  - only the proxy's own fresh request verifies (not another key, server, body or an old stamp);
  - admission needs a keyed proxy and a fresh nonce, with no nonce burnt before the MAC verifies;
  - the secret is made once and never replaced.
- `ProxyInstallKeyTest`: the install writes the key of a new generation and leaves no copy.
- `ClusterLockdownTest` and `DbAllowlistTest`: a signing proxy is no blocker and leaves the allowlist.

### RTMP viewers on a load balancer (2026-10-07)

A load balancer refused every RTMP viewer: `rtmp.php` checks the line with `UserRepository`,
which the LB build leaves out (Domain/User), and nginx-rtmp's `on_play` cannot send a viewer
to MAIN the way HTTP's `auth.php` redirect does. Of the two ways the operator was offered, a
MAIN-signed RTMP token in the playlist (checked on the node, but a playlist is fetched rarely, so
a disabled line would keep RTMP for the token's days) and MAIN asked at every connect, the
second was chosen: a line disabled on MAIN is refused at its next connect.

**Built.**
- `Domain\User\RtmpViewerAuth::check()` holds the checks `rtmp.php` made, in its order, with its
  client logs and the guard's count of credentials that name no line, for the server the viewer
  is on. `StreamRedirector::redirectStream()` takes that server (`$rHere`, this one by default),
  which keeps an RTMP viewer when it can serve the stream. `rtmp.php` on MAIN calls it directly.
- The `rtmp_auth` op (control lane, active nodes only): `{stream_id, ip, restream}` and the
  link's `token`, or `username` and `password`, as plain strings of at most 512 bytes; anything
  else is `400 BAD_REQUEST`, and a line MAIN cannot read is `503 DB`. The answer is
  `{ok: true, user: {id, max_connections, pair_id, con_isp_name, is_restreamer}, country_code}`
  or `{ok: false, reason}`, where `reason` is only `AUTH_FAILED` (credentials that name no
  line, which the node's flood guard counts) or `REFUSED`: why is in MAIN's client log.
- The viewer's address is the node's to say, as in its other reports, so a node could test
  line credentials under addresses it names, past the guard's count per address. Its failed
  checks have a budget, `ClusterApi::RTMP_FAIL_BUDGET` (300) a minute of MAIN's clock
  (`TMP_PATH/cluster_rtmp/<server id>`, under a lock): past it, MAIN answers `REFUSED`
  without checking until the minute ends, and audits `rtmp.auth_budget` once.
- A direct source has no RTMP server: `redirectStream()` answers false for `rtmp` rather
  than redirecting to the source and exiting, which in the cluster API would have cut its
  signed reply short.
- `rtmp.php` on a load balancer asks it through the agent's socket (`AgentClient::main`, 6 s).
  Then, as before, it reads its own stream row (its replica and store, or MAIN's database in
  mode 1), starts an on-demand stream, and records the viewer (`ConnectionTracker::openRecord`,
  whose limited line the agent still admits with `conn_admit`). The relay path (the stream
  password, an allowed address) shares that row read.
- The agent passes `rtmp_auth` from its socket (`SocketOps`, XC_VM_Fanout). Nothing else
  changes in it.
- `detect_restream_block_user` leaves the LB settings allowlist: only MAIN blocks a line now.

**Not built / limits.**
- **No answer refuses.** A node without an agent, an agent that predates the op, or MAIN
  unreachable: the viewer is refused. RTMP has no offline policy, unlike `conn_admit`.
- **A refusal's client log on MAIN** carries the agent's request, not nginx-rtmp's: no query
  string, the agent's user agent.
- **One round trip per connect** on MAIN's control lane, on top of `conn_admit` for a limited
  line. An RTMP connect is not a playlist refresh, so this is once per viewer.

### The move to mode 2 without the connect audit (2026-10-05)

**Problem.** The cutover gate asked for seven days with no connect to MAIN before a node could
move from mode 1 to mode 2. A node in mode 1 never shows that: its crons, its signals daemon,
`cron:root_signals` and viewer authentication read MAIN's database by design (the known limit
above: "a real mode 1 node does not reach the zero yet"). The zero could only come in mode 2, so
no node could be moved from the page.

**Decision.**
- **The gate asks what a node needs to run in mode 2, now.** `ClusterAdmin::modeGate()` keeps
  every flow (the data plane included; the "every flow but the data plane" wording of earlier
  increments is superseded) and root's pin, and adds: state `active`, heard within
  `NodeHealth::SUSPECT_AFTER_MS`, and the node's own word that it runs from its own copy. The
  connect counters and the seven days leave the gate.
- **The node says so in `audit.json`.** `streams_local` is true when it boots from its replica
  (`ReplicaBoot::ready()`), the replica owns its stream definitions and its store of their state
  is seeded (`StreamRuntime::seededFor()`). It publishes again at once when that stops being true
  (`StreamRuntime::lapse()`, `ReplicaApply::disown()` and `own()`), and MAIN forgets the member
  at every mode change. An older release sends none and is refused.
- **The seven days move to the step with no way back.** `DbCredentials::strip()` refuses until
  the node has been in mode 2 for `CUTOVER_CLEAN_DAYS`, counted by MAIN's own record
  (`cluster_meta`, `mode2_at.<server id>`), and while the node reports `streams_local` false. It
  does not ask for zero refused connects: a settled mode 2 node still shows some (requests to the
  viewer APIs it no longer serves boot through `DatabaseStage`). **Superseded** in part by
  [Mode 2 after the move, and the credential strip](#mode-2-after-the-move-and-the-credential-strip-2026-10-05) (2026-10-05):
  the strip asks all the gate asks, and its command lives ten minutes.
- **The node really stops.** New connects were refused already. The daemons that keep a
  connection (signals, queue, fanout_sync, scanner, on-demand) now leave their loop within a pass
  of the mode crossing 2, in either direction, so `mode_down` is a whole way back. `fanout_sync`
  opens no Redis of MAIN's on a node that refuses.
- **The count starts again on entering mode 2** (`ConnectAudit::follow()`), so the page shows
  what mode 2 turned away, not mode 1's history. Leaving mode 2 keeps the window.
- **Not with the Redis connection handler.** `act()` refuses the move while `redis_handler` is
  on: `live.php`, `vod.php`, `timeshift.php` and `rtmp.php` open MAIN's Redis for every viewer.

**Not built.**
- MAIN moving a node back to mode 1 by itself. The page shows a badge on a mode 2 node that
  reports `streams_local` false, and the operator presses mode down.
- Mode 2 with the Redis connection handler, and a fallback for a viewer request when the node's
  agent does not answer (mode 1 falls back to MAIN's store; mode 2 is refused there).
- New nodes born in mode 2 (`api_mode_allowed` stays false): such a node cannot seed its store.

**Tests.**
- `ClusterModeGateTest`, `ClusterModeActionTest`: the gate and the page's action, the record of
  when a node entered mode 2, the Redis handler refusal.
- `ClusterCredentialsActionTest`: the strip waits seven days and for local streams.
- `StreamRuntimeReadersTest`: what the node says, and that it says it again unasked.
- `ConnectAuditTest`: the window restarts on entering refusal, stays on leaving, and is adopted
  by a release that finds no note.
- `DaemonModeChangeTest`: each daemon asks every pass.

### Mode 2 after the move, and the credential strip (2026-10-05)

**Problem.** The section above decides once, when a node moves to mode 2. Afterwards nothing held
the node to what the gate had asked:
- a flow could be switched off under a node in mode 2, which then had only MAIN's database for
  that work;
- *Drop DB credentials* was judged only as it was queued, on less than the gate asks, and its
  command then waited a day for the node, through a mode down;
- an enrolment by code put a credential-free node back in mode 1 with no flow, and any enrolment
  could start a node in mode 2 beside the Redis connection handler;
- a write MAIN's database refused could unseed the store that a mode 2 node cannot seed again.

Three other statements of this record are corrected here: what the blocklist flush removes, who
passes `findByUuid` its Range fallback, and what a quarantine leaves queued.

**Decision.**
- **No flow goes off in mode 2.** `ClusterAdmin::act()` answers `cluster_flow_mode_two` to every
  `*_off` for a node in mode 2, the data plane's included: mode 2 runs on every flow
  (`MODE2_FLOWS`). The operator moves the node one mode down first. Switching a flow on is as
  before. "Switching it off needs nothing" (Phase 8, fourth increment) is superseded.
- **The strip asks what the gate asks.** `DbCredentials::strip()` keeps mode 2, state `active` and
  the seven days. It adds every `MODE2_FLOWS` bit (`cluster_mode_needs_flows`), a node heard within
  `NodeHealth::SUSPECT_AFTER_MS` (`cluster_strip_not_heard`) and `streams_local` true in its last
  report (`cluster_strip_not_local`). A node that says nothing of its streams is refused, where the
  section above refused only one that said false.
- **Its command lives ten minutes.** `ClusterRoute::root()` queues `strip_db_credentials` with
  `DbCredentials::STRIP_TTL` (600 s): the node was judged as the command was queued, so a node
  that is away is asked again. Every other `node.root` action keeps the 24 h of the first Phase 4
  increment.
  - *The move down.* A mode down from 2 ends every strip the node has not acked:
    `DbCredentials::cancelStrip()` sets its `exp` to now, so it is handed out no more, also to a
    node moved back to mode 2 within the ten minutes. One the node was not handed is dropped by
    the next `cron:cluster` (`CommandBus::prune()`). One it was handed, or was being handed as it
    was moved, keeps its row: the node may have run it, and its ack says what its config holds.
    The row is ended and not deleted so that a hand-out that read it just before the move still
    has a row to mark, and its ack a row to find.
  - *The hand-out.* `CommandBus::pending()` judges a strip once more as it hands it out, and
    hands one out only while the node's row (`cluster_nodes.mode`) says 2. This also holds back a
    strip stored in the instant of a move down. Every other command is handed out as before.
  - *The ack.* A strip the node was handed keeps its row a day past its `exp`
    (`CommandBus::prune()`), because root runs a command up to 300 s past `exp`
    (`RootPin::verify()`) and MAIN revokes the grant on that ack alone (`DbCredentials::acked()`).
    Past `exp` it is not handed out. A strip never handed out, and every other command handed out
    or not, is pruned at `exp`. The ack of a strip the node was handed is so accepted for no less
    than the day in which another `node.root` action's is.
- **The node judges the strip again.** When root runs it, `NodeCredentials::run()` refuses
  `strip_db_credentials` unless `NodeRole::refusesConnects()`: the agent's `flows.json` says mode
  2, state `active` or `quarantined`. The command fails with `strip_db_credentials: refused: this
  node is not in mode 2, and below it needs MAIN's credentials`, and MAIN revokes nothing.
  `install_config` is not gated by the mode: a config with credentials is the way back.
- **Enrolment by code keeps a credential-free node so.** `EnrolCodeService::approve()` asks
  `DbCredentials::credentialFree()` before the enrolment replaces the node's row, as the SSH path
  does (`LbInstallFlow::installsInApiMode()`, eighth Phase 9 increment). Such a node is enrolled
  again in mode 2 with `MODE2_FLOWS`, and `NodeRegistry::startEnrolment()` carries its
  `db_revoked_at` over. `EnrolmentService::begin()` called with `legacy` still starts a mode 1 row
  clean.
- **Not with the Redis connection handler, at enrolment either.** `EnrolmentService::begin()`
  refuses a mode 2 enrolment while `redis_handler` is on, before the node's row is touched. It
  throws `ClusterRefusedException` with the reason `REDIS_HANDLER`, which is MAIN's own refusal
  and not the extension's. Each path reports it before it acts:
  - the page's approval answers `cluster_mode_redis_handler`, `cluster:enrol-approve` says so in
    words, and the request stays pending;
  - `server:install` refuses before the node is contacted, and sets the server's status to 4;
  - `server:enrol` and `cluster:reenrol` refuse in `LbInstallFlow::provisionCluster()`, before the
    node's agent is stopped;
  - the panel's install and reinstall ask `ServerService::modeTwoInstallRefused()` before the
    server is marked as being installed, so it stays in rotation.

  The other order is the Cache page's. `ClusterAdmin::anyInModeTwo()` also counts a mode 2 node
  that is still `enrolling`, until its `enrol_deadline` (30 minutes). An enrolment that was never
  completed and a revoked row do not hold the handler off.
- **A mode 2 node's store does not lapse on a write.** `StreamStateWriter::write()` and
  `ContentSink::workerPid()` call `StreamRuntime::lapse()` after a write to MAIN's row alone only
  where `NodeRole::refusesConnects()` is false. In mode 2 MAIN's database refused that write and
  no seed can follow, so the store stays seeded. "With STREAMS off, a write goes to MAIN's row
  alone and lapses the store once it landed" (Phase 7, thirteenth increment) holds for modes 0
  and 1 only. Of its known limit "a mode 2 node whose store lapsed (STREAMS off and on again in
  mode 2) or was lost", the lost store remains.
- **The flush removes the panel's blocks only.** The root action `flush`, and the `signals` row
  where it is still read, remove the rules the panel added to INPUT (`-s <address> -j DROP`, IPv4
  and IPv6) through `iptables-restore --noflush`, one commit per family, and rule by rule where
  the tool refuses the list (`RootSignalsCronJob::unblockAll()`). The flood guard's block files,
  `iptables-save` and the `FLUSH` log line follow as before. Every other rule, chain and policy
  stays, the DB allowlist's `XCVM_DB` chain among them. "`iptables -F`, `ip6tables -F`" (Phase 7,
  eighth increment) is superseded. The allowlist's reconciliation every minute is unchanged.
- **The Range fallback is `vod.php`'s alone.** Only `vod.php` passes
  `ConnectionTracker::findByUuid()` a fallback; `timeshift.php` looks a viewer up by uuid alone.
  `vod.php` passes none where the daemon serves the file: the request keeps a row under its
  link's own uuid, the one the daemon is handed. The fallback matches line (or HMAC key),
  container, agent and stream, and the exact address when `ip_subnet_match` is off. With it on,
  the address is left out of the match for an IPv4 viewer only, and the row found is kept only
  when it is in the viewer's subnet. Otherwise the viewer gets a row of its own. This supersedes
  the `findByUuid` row of the seam's table (Phase 6, second increment).

**Not built.**
- **A strip in the heartbeat after a mode down.** The node knows its mode from the agent's
  `flows.json`, which follows MAIN at the agent's next heartbeat. A strip root runs before that
  still runs.
- **A strip stored just after a move down's withdrawal.** A strip stored just after a concurrent
  move down withdrew the node's strips stays `queued` for its ten minutes. It is not handed out
  below mode 2, but is if the node is back in mode 2 within that time.
- **A strip pruned while it is handed out.** A strip read by a hand-out, ended by a move down
  and pruned by `cron:cluster` before that hand-out marks it (three requests inside the gap
  between two statements) reaches the node with no row on MAIN. Its ack is refused and revokes
  nothing.
- **Root's late outcome.** When root's runner is more than `ClusterExecCommand::ROOT_WAIT` (5 s)
  behind, `cluster:exec` acks `{"queued": true}`. What root then does never reaches MAIN, and
  `DbCredentials::acked()` revokes nothing on that ack.
- **Binding the line a node names.** MAIN reads a line's limit itself and ignores any a node
  sends, but takes from the node which line a viewer belongs to (`conn_admit`'s `line_id`, a
  `conn.upsert` record's `user_id`). That is not bound to an admission MAIN minted for the node.
- **A status that outlasts a heartbeat.** `HeartbeatService` sets `servers.status` to 1 whatever
  it was (`WHERE status <> 1`), with each heartbeat or, on the cluster bus, with each flush. The 5
  a node reports as it starts an update, and an install state MAIN set (3, 4), last only until
  the node's agent is next heard. "An install state MAIN set (3 installing, 4 failed) is never
  the node's to leave" ("A node in mode 2 updates, and its status and inventory") holds for the
  `node.state` event only.
- **Granting commands across a quarantine.** "The rest stays queued for *Trust again*" (Phase 9,
  fifth increment) holds only for commands queued after the last restrictive one the node ran.
  The long-poll hands out `seq` above the node's high-water, which every command it runs raises,
  and the page's own `node.quarantine` is queued above whatever waited before it. A granting
  command passed that way expires unsent: `ClusterRoute::trust()` queues `token.rotate_now` and
  queues no earlier command again.

**Tests.**
- `AuditClusterFlowsModeTwoTest`: no flow goes off in mode 2, one still goes on there and off
  below it; a write refused in mode 2 leaves the store seeded, and below mode 2 it still lapses.
- `AuditClusterStripTest`: a settled node is asked and the command lives minutes; a node not
  heard, short of a flow or silent about its streams is not; a strip is handed out only while
  the node's row says mode 2; a mode down ends a strip, handed out or not, which then goes out
  neither below mode 2 nor after a return to it; one being handed out as the node is moved keeps
  its row and its ack is accepted; a strip that was handed out keeps its row a day past its life
  and a late ack still revokes, while any other command goes at its `exp`; the node gives the
  credentials up only in mode 2; the command line says why in words.
- `AuditClusterEnrolTest`: a credential-free node enrolled by code stays in mode 2, one whose
  grant was revoked too, any other enrols as the setting says; no enrolment enters mode 2 with
  the Redis handler on, and the handler stays off while a node enrols in mode 2.
- `AuditInstallFollowEnrolRedisTest`, `AuditAdminUsersInstallRefusalTest`: the SSH paths refuse
  before the node is touched, and the panel starts no install the command would refuse.
- `AuditRootCronFirewallFlushTest`: only rules of the panel's own form are its blocks; the root
  action removes them and no other rule, one by one where the list is refused.
- `AuditSegmentTokenTest`: a movie seek from another address, or from another subnet where a
  subnet is one viewer, is a connection of its own.

**Since.**
- **The install form says why.** `post.php?action=server_install` answers an install that
  `ServerService::modeTwoInstallRefused()` refused with `message`, the translated
  `cluster_mode_redis_handler` text; every other failure carries `message` null. The reinstall
  action already answered `{"result":false,"message":"…"}` (`AuditFormRulesMessagesTest`).
- **A reseller's switch reaches the sessions.** Enabling and disabling a line, a MAG or an
  Enigma2 device from the reseller panel (`ResellerApiDispatcher`) and the Reseller API
  (`ResellerAPIWrapper`) send the line's signal (`LineService::updateLineSignal()`).
  `cluster_kill_on_line_disable` so closes the sessions of a line a reseller switched off, and
  the line cache follows either switch (`AuditResellerRestLineSwitchSignalTest`).

### Three designs for approval: the line a node names, the updating status, commands across a quarantine (2026-10-06)

**Status.** Designs 2 (option A) and 3 (option c) approved and built; 1 (option B, both stages)
approved and built on the panel, its agent field waiting for XC_VM_Fanout 0.14.4. Nothing else here is built, and no earlier statement of this record changes until
the maintainer approves a design or changes it. The three subjects are the last three entries
under **Not built** in the section above. File and line are those of the tree on this date: the
panel under `src/`, the agent in XC_VM_Fanout 0.14.0 under `internal/clusteragent/`.

**1. The line a node names.**

*Today.*
- **`conn_admit`.** `ConnectionAdmission::forNode()` takes `line_id` from the request
  (`Domain/Cluster/ConnectionAdmission.php:238`), reads that line's state and limit itself
  (256-268), reserves the uuid for it (279) and queues a cut (285). The cut is dropped only when
  MAIN's store holds the uuid for another node (`ConnectionLimits.php:119-127`). A uuid the store
  does not hold passes, since the viewer may not be open yet.
- **`conn.upsert`.** `ConnectionIngest::write()` keeps the record's `user_id`, or its `hmac_id`
  and `hmac_identifier` (`ConnectionIngest.php:140-148`). It refuses only a uuid another node
  holds (157, 174), and an update writes the owner columns again (172-178).
- **`conn.limit`.** Its owner check (`ConnectionLimits.php:129-135`) compares the event with the
  row the same node's upsert wrote.
- **The node's PHP.** The line in the `X-XCVM-Admission` header is the record's `user_id`
  (`Core/Cluster/AgentConnections.php:92-97`), which the endpoint took from the token.
- **The agent.** It copies that line into `conn_admit` (`admission.go:212-217`). It asks only
  for a viewer with no live claim (201-206), on an `active` node with CONNECTIONS (207). It
  stores a record as it is given and mirrors it whole (`registry.go:136-161`).

On all three paths the line is the node's word. MAIN counts the viewer against that line and
closes the line's other connections, on other servers too, to make room for it.

*Two bindings that do not hold.*
- **The mint's reservation.** It ends with the claim, `create_expiration` + 10 s after the mint
  (`ConnectionAdmission.php:162`, 438-440). A node opens a new record while the token is valid
  (`live.php:356`, 431; `timeshift.php:164`, 237), and for a movie also later, when it still
  holds the viewer's marker (`vod.php:150`). So `conn_admit` is asked where the token has no claim,
  which is where MAIN reserved nothing, or where the reservation has run out. Bound to it, every
  `conn_admit` would be refused or left uncut, and the two events would be as before.
- **The token, handed on by the node.** A node's tokens are sealed with a key the node holds:
  its own `K_n` (`Core/Cluster/ViewerKey.php:57`, delivered in the replica's `secrets`), or the
  shared secret. A token the node hands MAIN is one the node could have sealed, and proves no
  mint.

*Options.*
- **A. MAIN keeps a record of each mint**: uuid to identity, node and time, written at the mint
  where reservations are kept (the cluster bus, the shared Redis or `cluster_reservations`) and
  read at `conn_admit` and where a uuid first enters MAIN's store.
  - *Needs.* Panel on MAIN: the write at the six mint sites, for an HLS viewer under its
    playlist key too (as `makeWay()` computes it, `ConnectionAdmission.php:193`), and the two
    reads. Node PHP and agent: nothing, so it works with every node from the day MAIN is updated.
  - *Its life.* A record is first reported within the token's life while all is well. But P0
    waits in the node's spool while MAIN is out of reach, and a snapshot brings a node's records
    back after an orphan purge, hours after their mint. Kept that long, it is one row for every
    connection opened in that time, written on the mint path.
  - *Its loss.* It is lost where reservations are lost: a bus restart, an eviction, a store out
    of reach at the mint, which is the very case in which a token has no claim. MAIN cannot tell
    "never minted" from "lost", so every doubt must admit, or a valid viewer is refused.
  - *HLS.* MAIN's key and the node's agree only where both see the same address and agent.
- **B. MAIN puts a proof of the mint in the token**, and the node hands the proof back.
  - *The proof.* `prf: {iat, p}` in every viewer token minted for an enrolled node. `p` is the
    first 16 bytes of HMAC-SHA256 over a domain label, the token's uuid, the identity
    (`StoredConnections::identity()`), the node and `iat`.
  - *The key* is `HMAC-SHA256(live_streaming_pass, "xc_vm mint proof v1")`, derived as
    `ViewerKey::derive()` derives a node's key. Nothing is stored. A stream-secret rotation
    replaces it, and a proof made under the replaced secret verifies inside that secret's window.
  - *Needs, panel on MAIN.* The proof at the six viewer mint sites (`admitToken()`), the check
    in `forNode()` and in `ConnectionIngest::write()`, and an update that no longer writes the
    owner columns.
  - *Needs, panel on the node.* `ConnectionTracker::openRecord()` copies the proof into the
    record as one key, `mint` (`<uuid>.<iat>.<p>`), and into the admission header. `adm_uuid`
    already travels this way (`ConnectionTracker.php:931-934`).
  - *Needs, agent.* For the events, nothing: it mirrors a record key it does not know, in
    `registry.snap` and in snapshots too, and the node's PHP keeps the key across updates
    (`ConnectionTracker.php:1179`). For `conn_admit`, the header's `mint` copied into the
    request: one field, in a new release.
- **C. No binding.** `forNode()` keeps its refusals and stops reserving and cutting. Panel only.
  A viewer without a claim is then cut for by `conn.limit` after it opens, as before the ninth
  Phase 6 increment. The two events stay as they are, so the effect stays, a second later.

*What MAIN does with a proof (B).*
- **`conn_admit`.** A proof that verifies for the named identity and the authenticated node, and
  is no older than the token's life plus `PAD_SEC`: reserved and cut, as today. Any other
  request gets the line's own refusals as today, and is otherwise admitted with no reservation
  and no cut. `conn.limit` follows the open.
- **A uuid that first enters MAIN's store**, by an event or a snapshot, is stored when its proof
  verifies for the record's identity and the sending node. Otherwise the record is *unproven*
  (below).
- **A uuid the store already holds for that node** keeps the identity it was stored with.
- **No bound on a proof's age at the events**, at first. The record carries its proof for its
  life, so one that waited in the spool, a movie opened again, and a record a snapshot brings
  back after an orphan purge still verify.
- **HLS.** The proof names the token's uuid, not the record's: an HLS viewer is recorded under
  the playlist key the node computes (`live.php:327`).

*An unproven record: two stages, one setting* (`cluster_conn_binding`: `observe`, the default,
or `enforce`).
- **`observe`.** Nothing changes for a viewer. MAIN counts, per node, the unproven first entries
  and the `conn_admit`s without a proof, and how old the proofs it verified were. It writes one
  audit line a minute per node (`conn.unproven`), and the Cluster Nodes page shows the count.
- **`enforce`.** An unproven record is not stored, and MAIN queues `conn.close {uuid, remove:
  true}`, so the node's registry drops it and the two digests agree again. MAIN sends no kill
  with it.
- **Per node, from its first proof.** Enforcement starts for a node with the first proof MAIN
  verified for its enrolment (`cluster_meta`, `conn_proven.<server id>`), and does not end with
  a record that carries none. A node on an older panel release has shown none and is treated as
  under `observe`. The page says which nodes prove.
- **Moving to `enforce` is a second approval**, on what `observe` showed over a release.

*Rollout (B).*
- **MAIN new, the node's PHP older.** It ignores the token's `prf`, and its records carry no
  proof. Nothing is enforced for it: as today.
- **MAIN new, the node's PHP new, its agent older.** Records carry the proof. Its `conn_admit`
  carries none: answered as today under `observe`, admitted with no reservation and no cut under
  `enforce`.
- **MAIN new, both new.** `conn_admit` carries the proof too.
- **MAIN older, the node newer** (a fleet half updated). Tokens carry no `prf`, so the node adds
  nothing and a newer agent has no `mint` to send. An older MAIN would ignore the key anyway
  (the ninth increment's **Wire**).

*Stored (B).*
- **MAIN:** nothing per viewer. Per node, whether it has proved, until its next enrolment. The
  counts of `observe`, a day, in `TMP_PATH`.
- **The node:** the proof in the registry record and in `registry.snap`, for the record's life.
  It is a MAC, not a secret.
- **The token:** about 60 bytes longer.

*Failure modes (B).*
- **It holds only where the stream secret is withheld.** A node that holds the stream secret can
  derive the key: every node below mode 2, and every node before lockdown
  (`ReplicaBuilder::withholdsStreamPass()`, `ReplicaBuilder.php:607`). Such a node needs no
  proof: it writes MAIN's store, or seals a token another node accepts. The binding starts where
  the containment of the per-node keys starts.
- **A node still speaks for the lines MAIN sent it.** One proof opens more than one record, and
  with no bound on its age a node can report viewers of any line it was sent since the last
  stream-secret rotation. Whether a bound can be set is what `observe` measures.
- **An HMAC identity's limit is still the node's** (`conn.limit`'s `max_connections`).
- **After a stream-secret rotation**, a record that returns in a snapshot past the window is
  unproven. Records MAIN's store still holds are not asked again.
- **Seeded records carry none**: `cluster:seed-connections` sends `RECORD_KEYS` only
  (`AgentConnections.php:188`, 204). They are in MAIN's store, and unproven only when purged and
  brought back.
- **A mint that carries no proof.** A token MAIN minted before its update, for seconds. Any
  mint outside `admitToken()` whose token opens a record (the admin player's, the off-air
  redirect a node mints for itself) has to show in `observe` before `enforce` is safe.
- **A wrongly unproven viewer under `enforce`** loses its record on the node. A TS viewer a PHP
  worker serves ends at its next check-in, within five minutes (`live.php:770-774`). An HLS
  viewer's next request finds no record and needs a token that is still valid. What the agent's
  reconciliation does with one the daemon serves has to be read before the second stage.
- **A node that misreports** is still the operator's to quarantine: `events` and `conn_admit`
  take an `active` node only (`ClusterApi.php:82`, 88).

*Tests that would prove it (B).*
- `ConnectionMintProofTest`, on the panel. Today's code reserves and cuts in its second case,
  stores in its third and writes the owner in its fourth:
  - a proof verifies for its uuid, identity and node alone, and under the replaced secret
    inside its window only;
  - a `conn_admit` with no proof, another line's, another node's or an old one reserves nothing
    and queues no cut under `enforce`, and is answered as today under `observe`;
  - a first entry without a proof is stored and counted under `observe`, and under `enforce` is
    not stored and has its close queued, but only once the node has proved;
  - an update cannot change a record's owner;
  - a record a snapshot brings back verifies however old its proof.
- `ReplicaBuilderSecretsTest`: no section carries the proof's key.
- `AgentAdmissionTest`, against the stand-in agent: the record and the header carry `mint`, and
  a token without `prf` adds nothing.
- The agent's `admission_test.go`: the header's `mint` is in `conn_admit`, none is sent without
  one, and an interop case runs against MAIN's real `forNode()`.

*Recommended: B, in its two stages.* It is the only one of the three that binds the op and the
events alike, it cannot refuse a valid viewer because a store was lost, and it asks the agent
for one copied field. A's record has no safe life, and C leaves the effect in place.

*Approved and built (B, 2026-10-06).* The maintainer approved B in its two stages, and answered
question 1: in the second stage MAIN refuses a record that carries no valid proof and has the
node's registry drop it.
- **The proof.** `ConnectionAdmission::admitToken()` puts `prf {iat, p}` in every viewer token
  while the cluster API is on (`mintProof()`), for every node: a node that does not record
  through its agent ignores it, and no read of `cluster_nodes` is added to the mint. `p` is
  `proof()`: HMAC-SHA256 under `HMAC-SHA256(live_streaming_pass, PROOF_LABEL)` over
  `uuid|node|iat|identity`, cut to 16 bytes as hex, the identity last so that no other split
  of the fields gives the same message. `verifyMint()` takes the stream secret and, inside its
  window, the replaced one (`StreamSecret::previousEntry()`).
- **The node.** `ConnectionTracker::openRecord()` keeps it in the registry record as `mint`
  (`<token uuid>.<iat>.<p>`, the token's uuid also for an HLS viewer, as `adm_uuid` names it),
  and `AgentConnections::admission()` puts it in the admission header. Neither reaches MAIN's
  store when the agent does not answer. The endpoints needed no change: live.php, vod.php and
  timeshift.php already pass their token.
- **`conn_admit`** (`forNode()`): a `mint` that verifies for the named identity and the
  authenticated node, no older than the token's life plus `PAD_SEC`, is reserved and cut as
  before. Any other is counted, and under `enforce` on a node whose stream secret MAIN withholds it gets
  the line's own refusals and is otherwise admitted with no reservation and no cut.
- **A first entry** (`ConnectionIngest::admitsFirst()`, events and snapshots alike): a record
  whose `mint` verifies, at any age, is stored and counted with its age. One that does not is
  stored and counted under `observe`; under `enforce` on a node whose stream secret MAIN withholds it is
  not stored (the event is refused, the snapshot's record dropped), and MAIN queues
  `conn.close {uuid, remove: true}` (`ClusterRoute::closeConnection`, no kill).
- **The owner.** An update of a uuid the store holds for the node never writes `user_id`,
  `hmac_id`, `hmac_identifier` or the Redis `identity`.
- **The gate.** `cluster_conn_binding` (migration 071, `observe` by default; any other value
  reads as `observe`). Under `enforce` a node is held to the proof when MAIN withholds its
  stream secret (mode 2, the cluster locked down, the node on its own viewer key:
  `ReplicaBuilder::withholdsStreamPass()`): only such a node cannot derive the proof's key, and
  whether it is one is MAIN's to say. (As first built, enforcement started with the first proof
  the node itself sent, so a node that stripped every proof was never held to it; a review
  found it, and the maintainer chose this gate on 2026-10-06.) Whether a node has sent proofs
  (`cluster_meta` `conn_proven.<server id>` = its `gen`) is recorded for the page and decides
  nothing; the operator switches to `enforce` once `observe` shows every node proving. Every
  agent release mirrors a record key it does not know (`registry.go` stores and sends the PUT
  body whole), so a node's records prove once its panel is new, whatever its agent. A conn_admit
  is never refused for the want of a proof, so an agent that does not copy the field loses only
  the reservation and the cut. A proof still opens a record at any age, so a node can report a
  viewer of a line MAIN once sent it (the replay limit above).
- **Outside the batch.** The mark, the closes, the counts and the audit line are noted during
  the request and written at its end (`flushBinding()`), never inside an events batch's
  transaction (EventIngest). The counts of the day are `TMP_PATH/cluster_binding/<server
  id>.json` (`bindingCounts()`); `conn.unproven` is written at most once a minute per node,
  when that minute saw a record or a conn_admit without a proof.
- **Waiting.** The agent's field is XC_VM_Fanout 0.14.4 (`admission.go`: `mint` from the
  header into `conn_admit`, a malformed one left out). Until a node runs it, its conn_admits
  carry no proof. Not built on the panel: the Cluster Nodes page's count and which nodes
  prove, the setting in the settings form and `ClusterSettings::ENUMS`, and the agent's
  interop case against MAIN's real `forNode()`.
- **Found while building.** rtmp.php records a viewer the node authenticated itself, with no
  MAIN mint: under `enforce` every RTMP viewer on a proving node is refused and dropped from
  its registry, and plays on uncounted. A node rolled back to a panel without the proof after
  it proved has every new viewer refused under `enforce` until it is enrolled again. Both have
  to be settled before `enforce` is switched on; `observe` shows the first as `unproven`.
- Tests: `AuditCluster1MintProofTest`, `AuditCluster1NodeMintTest`, and the agent's
  `TestAdmissionCopiesTheMintProofIntoConnAdmit`.

**2. The status a node reports as it updates.**

*Today.*
- **The node's own status.** `NodeStateSink::status()` (`Core/Cluster/NodeStateSink.php:88`)
  reports 5 (updating) and 1 (back): a P0 `node.state` event with TELEMETRY on, else the row.
  MAIN takes either over 1 or 5 only (`EventIngest.php:520-527`), and routing reads status 1
  alone (`ServerRepository.php:81`, 92, 97).
- **The heartbeat.** It sets the status to 1 whatever it was: `HeartbeatService.php:256` with
  each heartbeat, and 481 with each flush on the cluster bus.
- **The update.** `UpdateCommand.php:174` reports 5 and starts the updater at once (179-184; a
  rollback the same, 299). The updater's first step stops the service (`src/update:85-86`), and
  the agent with it (`src/service:48-50`, 103). `post-update` reports 1
  (`UpdateCommand.php:359`) before the service starts again (`src/update:135-139`).
- **The agent.** It sends P0 every 200 ms (`events.go:50`) and a heartbeat every 2 s
  (`agent.go:852-870`), which says nothing of an update. It says `hello` at every start
  (`agent.go:426-449`). The `boot_id` it sends is the machine's (249-255), which a service
  restart leaves as it was.
- **So** a 5 that reaches MAIN before the stop is undone by the next heartbeat, or the next
  flush, while the node is about to stop. A 5 that did not leave the node in time is delivered
  after the restart.
- **And 'back' can be lost.** PHP spools only while the agent's `flows.json` is younger than
  120 s (`EventSpool.php:33`, 102-108), and in mode 2 it has no other way
  (`NodeStateSink.php:95-97`). A partly copied update runs no `post-update`
  (`src/update:142-153`). The heartbeat is what brings such a node back today, which is why 5
  cannot simply be left out of its statement.

*Options.*
- **A. A 5 holds against the heartbeat for a bounded time.** Panel on MAIN only.
  - `EventIngest::nodeStatus()` notes when it took a 5 (`cluster_meta`, `updating.<server id>`,
    its `updated_at`), and drops the note when it takes a 1.
  - The two heartbeat statements leave a row at 5 alone while its note is younger than
    `UPDATING_HOLD_SEC` (60). Past that, or with no note, they set 1 as today.
  - The hold ends nothing by itself: after it, the next heartbeat sets 1, and a stopped agent
    sends none.
- **B. The agent says it.** The update tells the agent on its socket. The agent adds `updating:
  true` to every heartbeat until it stops, kept in memory with a bound of its own. MAIN sets 5
  on such a heartbeat and 1 on any other. An event and a heartbeat can then no longer disagree,
  and MAIN stores nothing.
  - Needs an agent release, the node's PHP and MAIN. With an older agent the node's PHP falls
    back to today's event, and MAIN to today's behaviour or to A.
- **C. Only a `hello` leaves 5.** Panel only. Not safe alone: a 5 delivered after the restart
  follows the `hello` and stays, and an updater that never stops the service says no `hello`.
  Both need A's bound, and with it the `hello` adds nothing.
- **D. 'Back' made certain in mode 2 a release ahead, then 5 left out of the heartbeat.** Not
  safe alone either: an update that ends before `post-update` (a partly copied one, a reboot)
  sends no 'back', and the node stays at 5 with no control on the page to leave it.

*Rollout (A).* Nothing changes on the wire or on a node. A MAIN on the new panel treats every
agent and every node release alike, through the event a TELEMETRY node already sends. A node
that writes its own row (TELEMETRY off, below mode 2) leaves no note and is as today.

*Stored (A).* One `cluster_meta` row per server that reported 5, by MAIN's clock. It means
nothing once it is a minute old, and the next 5 replaces it.

*Failure modes (A).*
- **'Back' lost.** The update took longer than the hold, so the first heartbeat after the
  restart sets 1, as today.
- **The updater never stopped the service.** The node is out of routing for the hold, then
  back.
- **A 5 delivered after the restart.** With 'back' behind it in the lane, it lasts
  milliseconds. With 'back' lost, the node is out of routing for the hold after it is back.
- **A partly copied installation** comes back with its first heartbeat after the hold. Today it
  does within seconds. Keeping it out needs a state the node may report and a control to leave
  it, and neither exists.
- **The note not written**, or one ahead of MAIN's clock: no hold, as today.
- **Install states 3 and 4 are not held.** A heartbeat of the enrolment that was current when
  MAIN set one still sets 1. Holding them needs the generation they were set under, and the
  first heartbeat of the next enrolment is by design what marks a new install up
  (`HeartbeatService.php:254-256`).

*Tests that would prove it (A).* `NodeUpdatingHoldTest`, on the direct path and on the cluster
bus:
- a heartbeat after a reported 5 leaves 5 (on today's code it sets 1), and one after the hold
  sets 1;
- 'back' sets 1 at once and drops the note;
- a 5 written to the row with no note, an install state, and a note ahead of the clock are as
  today;
- a heartbeat of an enrolment that has ended still marks nothing up.

*Recommended: A.* It needs no release of the agent or of a node, it cannot leave a node at 5
for good, and every case it does not cover is as today. B is the cleaner model, and can follow
when the agent's heartbeat next changes.

*Approved and built: A (2026-10-06).* The maintainer's answer to question 2: a node that said
it was updating and still sends heartbeats a minute later is taken back into routing by
itself. Built on MAIN's panel alone, with no migration (`cluster_meta` exists since 029):
- `EventIngest::nodeStatus()` writes the note `updating.<server id>` (`updated_at` by MAIN's
  clock) in the batch's transaction when it takes a 5, as one `INSERT … ON DUPLICATE KEY
  UPDATE`, and deletes it when it takes a 1. One statement each, so two nodes reporting at
  once do not deadlock on the gap's lock.
- `HeartbeatService::record()` (direct path) and `flushNode()` (cluster bus) add
  `NOT_HELD` to their `servers.status = 1`: a row at 5 whose note is younger than
  `HeartbeatService::UPDATING_HOLD_SEC` (60) and not ahead of MAIN's clock is left alone. The
  flush keeps its `gen` guard.
- `NodeStateSink`, `UpdateCommand`, `ServerRepository` and the agent are unchanged.
- Tests: `AuditCluster2UpdatingHoldTest` (the cases listed above, direct path and bus).

**3. Granting commands across a quarantine.**

*Today.*
- **MAIN.** A quarantined node's long-poll is handed class R only (`ClusterApi.php:770`,
  `CommandBus.php:196-217`), by `seq` above the `after_seq` it sends. MAIN raises `cmd_seq` to
  what it handed out (`ClusterApi.php:784`) and to that `after_seq` (766-768).
- **The agent.** It polls with its high-water (`commands.go:135`), raises it for every command
  it verified (216-219) and refuses a `seq` at or below it (123).
- **The four quarantines.** The one from the page queues `node.quarantine` and then sets the
  state (`ClusterRoute.php:279-286`). MAIN's own three set the state alone
  (`ClusterApi.php:532`, 649-658, 877).
- ***Trust again*** sets `active` and queues `token.rotate_now` (`ClusterRoute.php:292-300`).
- **What can wait.** Every producer of a granting command asks for an `active` node
  (`ClusterRoute.php:509-516`, `StreamAssign.php:48-56`, `ArtefactGrants.php:71-73`), so a
  granting row of a quarantined node was queued before the quarantine. It lives 10 minutes, an
  hour for an artefact or an unfence, a day for `node.root` and `node.cache`
  (`CommandBus.php:36`).
- **So** the first restrictive command the node runs while quarantined, the page's own
  `node.quarantine` among them, takes its high-water past every granting command that waited,
  and none of those is handed out after *Trust again*. One is handed out only where no
  restrictive command was run in between.

*Options.*
- **a. Queue them again at *Trust again*.** Panel only. `trust()` signs each granting command
  that was passed again under a new `seq`, keeping its `cmd_id`, which `ArtefactGrants` and a
  waiting caller hold (`ArtefactGrants.php:165`).
  - It needs a licence at that moment, and rules for what must not come back: a start whose
    stream was stopped since (their dedupe keys differ, `ClusterRoute.php:143`, 187, where a
    fence and its unfence share one, 219), and a root action decided up to a day earlier.
  - Every command type added later has to be placed in those rules.
- **b. The agent keeps `cmd_id`s while quarantined** and does not raise its high-water, as it
  does for the commands a `LICENCE_INVALID` carries (`sealed.go:101-143`). The long-poll then
  hands the waiting commands out after *Trust again* by itself.
  - Needs an agent release, and a feature word at `hello` so that MAIN knows which nodes do it.
    An older agent is as today, so MAIN still needs a or c for it.
  - The order is a's: a command queued before runs after a restrictive one delivered since.
    The `LICENCE_INVALID` path has that order today.
- **c. End them at the quarantine, and say so.** Panel only. Each of the four places that set
  `quarantined` ends every granting command of the node that is not acked: its `exp` becomes
  now, as a mode down ends a strip (`DbCredentials::cancelStrip()`, `DbCredentials.php:118`).
  - The quarantine's audit line names them (type, action, `cmd_id`, handed out or not), and the
    page's confirmation says how many there are.
  - After *Trust again* the operator sends again what is still wanted. What MAIN produces by
    itself comes again by itself: an artefact offer at its next retry, a stream's assignment at
    its next write.

*Rollout (c).* Nothing changes on the wire. An agent of any release is handed the same
restrictive commands as today.

*Stored (c).* Nothing new. An ended command is not handed out, gives no artefact
(`ArtefactGrants.php:208-217`) and is pruned by the next `cron:cluster`. A strip the node was
handed keeps its row a day, as now. An ack that arrives before the prune is recorded.

*Failure modes (c).*
- **A command the operator still wanted is gone.** It is in the audit line. Today the same
  command is lost wherever a restrictive one was run meanwhile, up to a day later and unsaid.
- **One that was handed out and is running** keeps running: MAIN cannot take it back. Its ack
  is taken until the prune and refused after it.
- **Quarantined and trusted again within seconds:** the commands are ended all the same.
- **The licence path is not changed.** A granting command queued before a `LICENCE_INVALID`
  still comes after the kills that rode it.

*Tests that would prove it (c).* `QuarantineCommandsTest`:
- a granting command queued before each of the four quarantines is ended and named in the
  audit, and a restrictive one is not;
- after *Trust again* the long-poll hands out none of them, whatever the node's high-water
  (today one comes when no restrictive command was run, and none otherwise);
- one that was handed out keeps its row for its ack until the prune, and a strip for a day;
- an artefact's grant ends with its command.

The agent's `TestInteropFence` passes unchanged.

*Recommended: c.* A quarantine is MAIN saying it does not know who holds the node's keys, so a
command decided before it should be decided again after it, not replayed. It is also the only
option with no order to get wrong and no release to wait for. When it is built, "the rest stays
queued for *Trust again*" (Phase 9, fifth increment) is superseded by this section.

*Approved and built: c (2026-10-06).* The maintainer's answer to question 3: "ended at the
quarantine, named in the audit, sent again by the operator" is the rule. `CommandBus::endGranting()`
sets `exp` to now on every granting command (class other than R) of the node that is queued or
handed out and not acked, as the quarantine is set (`ClusterRoute::quarantine()`, the admin's and
MAIN's own). Its artefact grant ends with it; an ack is still taken until the prune. The quarantine's
audit line carries `ended` and each command (`type`, `action`, `cmd_id`, `handed_out`), and the
page says how many were ended (`cluster_quarantine_ended`). Restrictive commands keep their life.
No migration and no agent change; `TestInteropFence` passes unchanged.

**For the maintainer.**
- **1.** In the second stage, may MAIN refuse a record that carries no valid proof and have the
  node's registry drop it, or is such a record only ever counted and audited?
- **2.** Is a node that said it was updating and still sends heartbeats a minute later taken
  back into routing by itself, or does it stay out until an operator acts?
- **3.** After *Trust again*, is "ended at the quarantine, named in the audit, sent again by
  the operator" the rule, or must what was queued before still run?

**Not built.** Of 1, the agent's field (XC_VM_Fanout 0.14.4) and the page and settings form
named in its *Approved and built*; 2 and 3 are built, above. The three are independent. 3 and 2
change MAIN's panel alone. 1 needs the panel release on every node, and one field in the agent,
before its second stage.
