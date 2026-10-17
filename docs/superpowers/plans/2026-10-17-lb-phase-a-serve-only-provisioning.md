# LB Phase A — serve_only provisioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.
>
> **EXECUTION GATE:** Tasks 4–6 provision and launch a load-balancer node and can only be validated on a **real LB** (the LXD rig cannot install an LB without a user activation key — `fresh-panel-needs-activation-for-lb`; the test server 216.106.179.99 is MAIN). Part A is **atomic**: a node with `serve_only=1` but still running nginx+php-fpm and an agent without `-seed-fanout` is a broken half-config, so no subset of these tasks may merge on its own. Do not execute until a real LB (or a rig LB + activation key) is available.

**Goal:** Install a load-balancer node as a single-binary **serve-only** node — set `servers.serve_only=1`, skip nginx + php-fpm, and launch `xc_node` with the flags the merged serve path needs — so the (already-merged, inert) MAIN→agent→gateway transcode path fires end to end.

**Architecture:** A new opt-in node kind threaded through the existing install flow. `server:install` gains a `--serve-only` option (panel passes it for the single-binary type); on success the node's `servers.serve_only` is set, a `serve_only` marker is written into the node's `config.enc`, the nginx/php-fpm install steps are skipped, and the shipped `run.sh` launchers read the marker to thread the agent's `-seed-fanout` + token flags and the fanout's `-front`. Nothing selects the kind until the panel exposes it, so existing nodes are unaffected.

**Tech Stack:** PHP 8.1 (install flow), POSIX sh (`run.sh`), Go (`xc_node`, already built — flags exist). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-17-lb-activation-serve-only-and-emission-design.md` (Part A).

## Global Constraints

- PHP 8.1; `make gates` + `make phpstan` must pass; `vendor/` untouched; CI `failOnWarning`/`failOnRisky`.
- The serve-only kind is **opt-in and dormant**: no existing install path selects it; existing (legacy nginx+php-fpm) LB installs are byte-identical.
- The agent flags are **fail-closed dependencies** (the 4a/4b-ii class): `xc_agent run` needs `-seed-fanout` **and** `-server-id` (+ `-ffmpeg` absolute, `-fanout-binary`, `-logs`, `-ffmpeg-gpu`, `-ffprobe`) or every transcode directive the node receives fails closed. `xc_fanout` needs `-front :443` (+ `-front-cert`/`-front-key`) or it serves nothing (nginx is gone).
- The `run.sh` launchers are **static shipped files relaunched by `RootSignalsCronJob` keepalive** — the flags must be read from the `config.enc` marker on every launch, not set once.

## D-A1 (resolved)

The serve-only indicator is a **`--serve-only` option on `server:install`** (the command already splits options via `InstallCredentials::splitOptions`; `$rType` stays 1/2 for proxy/LB). The panel's add-server flow passes it when the operator picks "single-binary LB". `serve_only` is written to the `servers` row in the **final success UPDATE** (`ServerInstallCommand.php:297-301`), i.e. only once the node provisioned successfully — never prematurely at row creation. Rationale: `server:install` is the single choke point for LB provisioning and already owns the skip-nginx/php-fpm and launch steps, so the whole node-kind decision lives in one place.

## Review Focus

- A legacy (non-serve-only) LB install must stay byte-identical — the new branch is reached only with `--serve-only`. → Task 1/4 tests assert the legacy path is unchanged.
- `serve_only` must be set only after a **successful** provision — a failed install leaves it 0 (the node is not half-marked). → Task 1 test: a failure path does not set `serve_only`.
- The agent/fanout flags must survive a `RootSignalsCronJob` keepalive relaunch (read from `config.enc` each start), or transcode silently fails closed after the first keepalive. → Task 5 (validated on a real LB).
- A serve-only node must not start nginx/php-fpm (the `service` script's nginx/fpm bits are skipped), or two HTTP servers fight for :443. → Task 4 (validated on a real LB).
- `serve_only` toggled on a node that already holds streams must re-emit promptly (B2), not wait ~5 min for resync. → Task 3 test.

---

### Task 1 [CI-testable]: `--serve-only` option sets `servers.serve_only`

**Files:** Modify `src/Cli/Commands/ServerInstallCommand.php`; Test `tests/Unit/ServerInstallCommand*Test.php` (follow the existing install-command test).

**Interfaces:** Produces the parsed `$rServeOnly` bool (from the `--serve-only` option via `InstallCredentials::splitOptions`) and, for `$rType` 2 with `$rServeOnly`, adds `serve_only = 1` to the final success UPDATE.

- [ ] Write the failing test: `server:install` with `--serve-only=1` on a type-2 node → after success, `SELECT serve_only FROM servers WHERE id = ?` is `1`; without the option → `0`; a failure before the final UPDATE leaves it `0`.
- [ ] Run it — FAIL (option unparsed / column unset).
- [ ] Implement: parse `--serve-only` alongside the existing options; extend the type-1/2 final UPDATE (`:298`) to `SET status=1, http_broadcast_port=?, https_broadcast_port=?, total_services=?, serve_only=?` with the bool. (`serve_only=0` for the legacy path keeps it byte-identical in effect.)
- [ ] Run it — PASS. Commit.

### Task 2 [CI-testable]: `config.enc` serve_only marker

**Files:** Modify `src/Cli/Commands/LbInstallFlow.php` (`configPackParams`); Test the existing LbInstallFlow/config test.

**Interfaces:** `configPackParams(array $rServers, int $rServerID, bool $rServeOnly = false)` adds `'serve_only' => 1` to the returned config params when serve-only (beside the existing `is_lb => 1`). Consumes Task 1's `$rServeOnly`.

- [ ] Write the failing test: `configPackParams(..., true)` includes `serve_only => 1`; `(..., false)` does not. Confirm `serve_only` is in the LB settings allowlist (`check-lb-settings-keys`) if the node reads it from config.
- [ ] Run — FAIL. Implement. Run — PASS. Commit.

### Task 3 [CI-testable]: B2 — bump held streams on a serve_only change

**Files:** Modify `src/Cli/Commands/ServerInstallCommand.php` (dispatch after the UPDATE) + a small helper; Test `tests/Unit/ClusterApiTest.php` or a focused StreamVersions test (DB-backed).

**Interfaces:** When `serve_only` is set/changed for a server, bump `StreamVersions` for `StreamRecords::held($serverID)` so the next delta re-emits their directives (the directive's presence depends on `serve_only`; see B1). Consumes `StreamRecords::held()` and `StreamVersions::bump()`.

- [ ] Write the failing test (DB): with streams held by server 5, flipping `serve_only` bumps exactly server 5's held streams' `cluster_stream_ver` rows; a server holding no streams bumps nothing.
- [ ] Run — FAIL. Implement: after Task 1's UPDATE (and any future serve_only edit path), call the bump for the held set. Run — PASS. Commit.

> Note: at first install the streams are assigned *after* the row exists and assignment already bumps via `StreamsChangedEvent`; B2 is the toggle-on-existing-node case. It is dormant until Task 1 provides the write site, so it ships only with Part A.

### REVISED node-side design (strangler, not removal) — 2026-10-17

Reading the install flow + the launchers corrected the node side. A truly
PHP-less node (removing nginx + php-fpm) collides with the LB's PHP
keepalive/policy crons (`RootSignalsCronJob` relaunches `run.sh` and generates
`gateway.conf`/`policy.json`); porting those to Go is the Phase 4c/4d/5
lifecycle work, NOT Part A. So Part A activates serve-only **behaviour** with a
**strangler front**, keeping nginx + php-fpm for now (removal is Phase 5):

- The Fanout binary already supports it (verified): `xc_fanout -front :443
  -front-cert <certbot crt> -front-key <key> -front-nginx http://127.0.0.1:<port>`
  — the Go front takes :443/TLS and reverse-proxies non-serve requests to
  nginx on a **plaintext backend port** during the transition. `xc_agent run
  -seed-fanout -server-id <id> -fanout-binary <path>` is also present.
- So nginx moves from :443/TLS to a local plaintext backend port (e.g. 8080);
  php-fpm stays; the Go front owns :443. `serve_only` policy (#436, merged) +
  the agent seeding make the gateway serve its assigned streams.

This is tractable but still needs a **real LB node** to wire the nginx
backend-port change and validate (a wrong listen directive or a wrong front
backend breaks serving, and a seam test cannot catch that).

### Task 4 [needs real LB]: demote nginx to a backend port on a serve-only node

**Files:** `src/Cli/Commands/LbInstallFlow.php` (`configureRuntime`).

For `$rServeOnly`, generate the node's nginx config to listen on a local
plaintext backend port instead of :443/TLS (the Go front terminates TLS and
proxies to it), and `set_real_ip_from` the front. php-fpm and the nginx confs
otherwise stay. Seam-test the generated conf (listen port, realip); validate
on a real LB that nginx serves the backend and the front answers :443.

### Task 5 [needs real LB]: `run.sh` launches the strangler front + agent seeding

**Files:** `src/bin/xc_fanout/run.sh`, `src/bin/xc_agent/run.sh`.

Gated on a `serve_only` marker the install writes (a plaintext marker file —
the node still has PHP in this phase, but a shell-readable marker is simplest
and survives the removal of PHP later), append:
- `xc_fanout`: `-front :443 -front-cert … -front-key … -front-nginx http://127.0.0.1:<port>` (and `-front-realip`/`-front-images` as the front needs).
- `xc_agent run`: `-seed-fanout -server-id <id> -fanout-binary <path> -ffmpeg <abs> -logs <dir>` (and gpu/ffprobe/streams as the transcode directive needs). Confirm whether the agent reads `server_id` from `agent.json` (its `-state`) or needs `-server-id` — pin against the node.

Must survive a `RootSignalsCronJob` keepalive relaunch (both scripts re-read the marker each launch). Shell-testable for the flag threading; the live launch is validated on the node.

### Task 6 [needs real LB]: end-to-end validation

- [ ] On a real serve-only LB (nginx demoted, Go front on :443, agent seeding): MAIN assigns a transcodable stream → the node receives a `kind:"transcode"` directive, the agent applies it (ingest + key/iv + `/monitor/`), and the gateway serves it. Removing nginx/php-fpm entirely is Phase 5, not here.

---

## Not in this plan

- The panel add-server UI control that passes `--serve-only` (small admin-UI task; can follow once the CLI path is proven).
- Retiring the LB's PHP lifecycle crons and the loopback relay — the Phase 4c/4d/5 lifecycle spec (`2026-10-17-lb-phase4-5-lifecycle-design.md`).
- A node-rtmp-port placeholder token (B1 currently refuses `rtmp_output` portably; add a token here only if a serve-only LB must re-publish).
