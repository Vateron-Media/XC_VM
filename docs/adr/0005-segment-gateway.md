# ADR 0005 — Segment gateway: HLS segments, keys and playlist refreshes answered in Go

- **Status:** Accepted, built (Phase 12, steps 12.1–12.5).
- **Date:** 2026-10-08
- **Builds on:** ADR 0002/0003 (`xc_fanout`), ADR 0004 (cluster API: the node replica, the agent's connection registry and spool). Plan: `docs/superpowers/specs/2026-10-08-lb-segment-gateway-and-native-restreamer-design.md`, section 3.

## Context

With fanout delivering, PHP-FPM still answered every HLS request a viewer makes on a node: each playlist refresh (`/auth/<token>`, `live.php`), each segment (`/hls/<token>`, `segment.php`) and each key (`/key/<token>`, `key.php`). None of them carries video: PHP checks a token and hands the bytes to the daemon (`X-Accel-Redirect`). For 10,000 HLS viewers at a 4-second segment that is about 5,000 FPM requests a second on one node.

## Decision

An HTTP handler inside `xc_fanout` (`internal/gateway`, its own socket `bin/xc_fanout/sockets/gw.sock`, flags `-gw` and `-gw-policy`) answers those requests as PHP does, and hands PHP everything it does not fully own. The recommended options of the plan's open questions were taken:

- **G1** — in `xc_fanout`, not the agent or a new binary: the segments are in its memory, it runs on every node with fanout on, and it follows the fanout switch and licence.
- **G2** — the viewer keys reach it in a 0600 file of the panel's user (who runs both PHP-FPM and fanout).
- **G3** — playlist refreshes only where the viewers are in the node's agent (the CONNECTIONS flow); a node whose viewers are in MAIN's Redis or MySQL sends them to PHP.

### The rule: anything not proven equivalent goes to PHP

The gateway answers with bytes, PHP's 404, PHP's redirect, or `X-Accel-Redirect: @gw_segment_php` (`@gw_key_php`, `@gw_live_php`): nginx then runs the original request through PHP exactly as before. It does so for whatever it is not sure of — a token in a form PHP's lenient base64 might read otherwise, a stream id or offset PHP reads with `intval()`, a non-ASCII user agent (`htmlentities`' table), an unknown server, a stopped agent — and before any side effect, so PHP never acts twice. A gateway that does not answer (nginx 502/504) sends the request to PHP too.

### Contracts between PHP and Go

- **Tokens** (`internal/gateway/token.go` ↔ `Encryption`): the sealed format, the legacy CBC format and `readToken`'s try order (viewer keys, stream secret, the replaced context, the replaced secret, then CBC where accepted). `tests/Support/gateway_token_vectors.json` is generated from the panel's own code and passed by both sides (`GatewayTokenVectorsTest`, `token_test.go`).
- **Playlists** (`playlist.go`, `live.go` ↔ `live.php`, `HLSGenerator`, `HlsSequence`): the HLS connection id, `rawurlencode`, the tokenized playlist and the sequence re-anchoring, fixed in `tests/Support/gateway_live_vectors.json` (`GatewayLiveVectorsTest`, `live_test.go`). The sequence state file (`SIGNALS_TMP_PATH/hlsseq_<id>`) is shared under the same `flock`.
- **Policy** (`Core/Gateway/GatewayPolicy` → `tmp/gateway/policy.json`, 0600): written every minute by `cron:cache` from the settings and servers caches the stream endpoints read (the replica's on an API-mode node). It carries the keys with their windows (hex of the exact bytes), the address and host rules, the lease's serving deadline on this host's clock, the paths, the connection store and `live.php`'s settings. Missing, unreadable or older than ten minutes: everything goes to PHP.
- **nginx** (`Core/Gateway/GatewayNginxConfig` → `bin/nginx/conf/gateway.conf`): written by the root cron from `gateway_mode`, included by both `nginx.conf` files; exact-match locations for `/stream/segment`, `/stream/key`, `/stream/live` (what the server-level rewrites make of `/hls/`, `/key/`, `/auth/`), so they win over the shared stream location. Rendered only while the gateway's socket exists.
- **The agent** (`/v1/conn` on its socket, `config/cluster/spool/`): the same calls PHP makes — the record read, touched (catch-up) or replaced (refresh), and the line's `conn.limit` spooled with the panel's file naming (`CLOCK_MONOTONIC`, PHP's `hrtime`), so the agent sends it in order with PHP's.

### What it answers (`gateway_mode`)

- `off` (default): nothing; `gateway.conf` is a comment.
- `shadow`: PHP serves; nginx mirrors each segment, key and playlist request to the gateway, which judges it and counts the verdict (`GET /stats` on `gw.sock`).
- `segments`: live daemon segments (from fanout's memory, in-process), catch-up minutes (heard through the agent, served from their offset with ranges by fanout's file server) and keys.
- `segments+playlist`: and a known viewer's playlist refresh — the first request of each viewer (admission, the connection's creation) stays PHP's.

StreamingRequestBootstrap's checks (a flood block marker, `verify_host`) apply first: a request either would refuse goes to PHP.

## Measured (test LB: one vCPU, Skylake)

- **Decision cost** (benchmarks on the LB): a segment 9 µs, a key 2.3 µs, a playlist refresh's checks 92 µs plus 65 µs to tokenize a six-segment playlist.
- **Under load** (1,500 req/s sustained, generator on the same core): 99% of decisions in ≤ 500 µs; `xc_fanout` used about 200 µs of CPU per request, HTTP handling included — about 5,000 req/s on one dedicated core.
- **Against PHP** (the same requests through nginx): p50 2.4 ms / p99 5.1 ms with the gateway, 8.3 ms / 23 ms with PHP; PHP-FPM used about 5.5 ms of CPU a request, the gateway a fraction of a millisecond.
- **Equivalence**: a refresh answered by the gateway and by PHP gave the same headers, the same 426 bytes and tokens opening to the same payloads, with the same media sequence.
- **Failure**: `xc_fanout` killed under load — every key request still answered 200 (PHP while the daemon restarted, ~2 s).

## Consequences

- An HLS viewer on a CONNECTIONS node in `segments+playlist` costs PHP-FPM one request per session, plus re-auths.
- The token formats, `live.php`'s refresh and `HlsSequence` now have a second implementation; the shared vectors keep them in step — a change on either side that breaks one fails both suites.
- Rollback is `gateway_mode = off`: the root cron renders an empty include and reloads nginx within a minute.

## Rollout: shadow, compared with PHP

In shadow, nginx gives PHP and the mirrored copy the same request id (`$request_id`). PHP tells the gateway what it answered once the viewer has the answer (`Core/Gateway/GatewayShadow::watch`, from the stream router: serve, deny, redirect, blocked, status-<code>), and the gateway's `ShadowBook` pairs it with its own verdict, whichever comes first: agree, disagree (with a sample: kind, both answers, stream — never a token), deferred (the gateway would have handed it to PHP) or unmatched. The comparison is kept in `bin/xc_fanout/gateway_shadow.json` across restarts.

The node reports it with its audit (`gateway` in `audit.json`: mode, verdict counts, comparison), refreshed every five minutes while the gateway is on; MAIN keeps it with the node (`NodeAudit`), shows it on Cluster Nodes (the Segment Gateway column) and in `/metrics` (`xcvm_gateway_requests`, `xcvm_gateway_shadow_requests`, `xcvm_gateway_ready`). A node is **ready to serve** once it has compared for seven days, and a hundred requests at least, since its last disagreement (`GatewayShadow::readiness`). nginx keeps its connections to the gateway open (`upstream xc_gateway`, keepalive).

## Limits

- Switching a node from shadow to serving stays the operator's decision, read off Cluster Nodes: nothing switches it on its own.
- The gateway's counts restart with the daemon (the comparison does not).
- A playlist behind an XC_VM_Proxy route (`/<route>/hls/…`) stays PHP's.
- After a panel update the fanout supervisor can still run the old `run.sh` until the next restart of the service (the root cron's keepalive may restart it during the update): the gateway's flags then appear only after that restart. PHP serves meanwhile, and nginx renders nothing for a node without the socket.
