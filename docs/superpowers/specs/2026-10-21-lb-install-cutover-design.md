# LB install cutover — stop provisioning http-nginx + php-fpm on a serve-only LB (design)

**Status:** design (awaiting approval). The final phase of "LB as a single Go
binary" ([[lb-single-binary-2-8-0]]): the **PHP/provisioning half** of the
nginx-removal cutover. Builds on the approved Go-front design
`2026-10-18-lb-nginx-removal-go-listener-design.md` (the Go front already owns the
ports and serves every route in-process on `feat/lb-transcode-serve`); this phase
makes `LbInstallFlow` stop setting up the HTTP nginx + php-fpm, and migrates
already-provisioned serve-only nodes in place.

## Intent / why

A serve-only LB's whole data+control surface is now Go (xfile, /stream/rtmp,
node crons, the full `/admin/*` port, recording→Go, and the Go front as sole
listener). The one thing still provisioning the HTTP nginx + php-fpm is the
installer, `src/Cli/Commands/LbInstallFlow.php` (run by `ServerInstallCommand`
over SSH). Until it stops, every serve-only node still boots two HTTP daemons it
no longer uses. This phase removes them from provisioning **and** from nodes
already in the field.

The install/re-enrol flow is already mode-aware: a **type-2 LB in API mode**
(`LbInstallFlow::installsInApiMode` → `$rApiMode`: credential-free, no local DB,
replica-only) is the serve-only node this cutover targets. A **mode-1 LB**
(non-api, holds MAIN's DB credentials / a local MySQL) is *not* serve-only and is
explicitly out of scope — its nginx + php-fpm stay.

## Goals

- For an **API-mode (serve-only) LB**, `LbInstallFlow` no longer installs,
  configures, enables, or starts the **HTTP nginx** or **php-fpm**. It provisions
  the Go single binary as the whole HTTP surface: the front run-config with
  `-front` (sole listener on the node's port set) + `-front-agent-sock` (/xfile +
  /stream/rtmp to the agent), certbot via a **webroot** authenticator (no nginx
  plugin), and the native `/status` + `/progress` the installer/panel poll.
- **In-place migration:** re-provisioning (an update over SSH, the same
  `ServerInstallCommand` path) an **already-provisioned API-mode LB** tears down
  its now-dead HTTP nginx + php-fpm — remove their configs and any serve-only
  crontab entries the agent now owns — and brings up the Go-only run-config.
  **(Slice-2 correction, 2026-10-22:** `server:install` is a hard stop+restart of
  the whole node — it opens with `systemctl stop xc_vm` + `killall -9 -u xc_vm`
  and ends with the same — so a re-provision already drops every stream on that
  node, as every LB update does. The "no dropped streams / front re-binds
  gracefully" goal is therefore unachievable via re-provision and is not a
  regression: the running nginx/php-fpm are torn down by the slice-1 `serve_only`
  marker + that restart (`boot()` skips them), so slice 2 only removes the
  leftover config files and the dead demote code.**)
- **`nginx_rtmp` stays** installed and running (the RTMP byte server); only its
  HTTP auth-notify moved to Go (already done).
- A node is correctly classified every run, so a re-provision never strips a
  **mode-1** node's nginx/php-fpm or a node that still needs them.

## Non-goals / anti-goals

- **Mode-1 LBs are untouched.** The cutover branches on `$rApiMode`; a non-api
  node keeps its nginx + php-fpm + local DB exactly as today. Breaking a mode-1
  node is the primary failure.
- Not replacing the **nginx_rtmp** server, nor the **non-HTTP PHP** follow-on
  (the php-removal track — several CLI pieces have no Go replacement yet).
- Not redoing the Go-front listener/route work (done in the prior design) — this
  phase only changes what the installer provisions and what an update strips.
- ~~Dropping a live stream during the in-place migration (an update must be
  additive/graceful, like `nginx -s reload`).~~ **(SUPERSEDED — see the slice-2
  correction above: `server:install` is a hard restart, so this cannot be met via
  re-provision and was never met by any LB update.)**

## The migration — the risky half (design carefully)

Re-provisioning is the vehicle: `ServerInstallCommand` re-runs
`runPostExtractSteps` → `provisionConfig` → `configureRuntime` → `runStartup`
over SSH against a live node. For an API-mode node the new flow must, in order
that never leaves the node unable to serve:
1. Bring up the Go front as sole listener on the full port set first (it already
   can; ensure the run-config/services are in place and started).
2. Only once the front is listening, **stop + disable** the HTTP nginx and
   php-fpm services and remove their configs — so there is no window with no
   listener on the public ports.
3. Remove the serve-only crontab entries the agent now owns (idempotent — the
   node-cron cutover already moved them).
4. Leave `nginx_rtmp` and the fanout/agent running throughout.
A second re-provision is a no-op (idempotent teardown). A mode-1 node skips all
of this.

**Recovery of a cutover node (migration-slice requirement).** The provisioning
gate commits a serve-only node to the single Go binary: `service` skips
http-nginx + php-fpm on the `serve_only` marker alone, with **no nginx/php-fpm
fallback** — a serve-only node MUST run a front-capable `xc_node`, which is why
the cutover ships with that Fanout release (the hard prerequisite). Two cases the
migration slice still owns, inert until that release:
- **Binary not front-capable / front fails to bind.** `service` has already
  skipped nginx+php-fpm, so a serve-only node whose `xc_node` lacks the front
  (an un-upgraded or *downgraded* binary — `fanout_binary` self-heals without a
  `service` restart) or whose front crash-loops (bad cert, port in use) stays
  dark. **(Slice-2 correction, 2026-10-22:** the "reconcile that restores
  nginx+php-fpm" is a pre-rethink remnant — the rethink dropped the nginx
  fallback, so a serve-only node has no nginx/php-fpm configs to restore (a fresh
  one never got them). The accepted posture is that a serve-only node MUST run a
  front-capable `xc_node`, shipped as the hard prerequisite with the Fanout
  release; the reactive safety net that applies already exists and is unchanged:
  the fanout keepalive supervisor + `fanout_binary` self-heal in
  `RootSignalsCronJob`. No install-time binary probe is added — the LB archive is
  built from the same release, so the deployed binary is front-capable by
  construction.**)
- **Downgrading a node OUT of serve-only.** Clearing the `serve_only` marker must
  be paired with re-provisioning nginx+php-fpm and a `service restart`, since the
  gate left them unconfigured/stopped.
This is scoped to the migration slice, not the fresh-install provisioning gate.

## Constraints

- Faithful where it's a port; `no Claude attribution`. XC_VM 2.8.0 (PHP side:
  `LbInstallFlow`, `ServerInstallCommand`, `install/`, `lb_configs/`, the
  `ReplicaBuilder` crontab, certbot) + XC_VM_Fanout `feat/lb-transcode-serve`
  (the front run-config flags + native `/status`/`/progress`, already present).
- `make gates` must stay green (the LB-archive build must still contain no
  privileged code; the serve-only replica keys unchanged).
- Supported distros unchanged (Ubuntu 20/22/24, Debian 12/13).

## Validation gate (big deployment risk — acceptance up front)

- A **fresh** API-mode LB install provisions no HTTP nginx and no php-fpm, and
  serves + records + relays exactly as a pre-cutover node did (viewer `/live`,
  HLS, VOD, timeshift, `/admin/*`, `/xfile`, `/stream/rtmp`, recording).
- An **already-provisioned** API-mode LB, re-provisioned, ends in the same state
  with **no dropped streams** across the update, and http-nginx + php-fpm stopped
  + disabled + gone from config.
- A **mode-1 LB** re-provisioned is byte-for-byte unchanged (still has nginx +
  php-fpm + its local DB).
- ACME cert issue/renew works via the certbot webroot with no nginx.
- End-to-end needs a real LB (the LXD rig / the test LB); PHP-side unit coverage
  validates the mode branch + the teardown step list without a live node.

## Builder calls made

- Gate the whole cutover on the existing `$rApiMode` classification — no new
  mode enum/setting (mirrors the Go-front design's reuse of `serve_only`).
- Migration via re-provision (no separate migrate command): the update path a
  node already takes carries the teardown, gated idempotent.
- certbot **webroot** (not the nginx plugin), served by the Go front's
  `/.well-known/acme-challenge/` from the webroot (per the prior design).

## Left to the plan

The exact `LbInstallFlow`/`ServerInstallCommand` seams (which of
`provisionConfig`/`configureRuntime`/`runStartup`/the `lb_configs` templates set
up nginx-http vs php-fpm vs nginx_rtmp, so only the first two are gated off); the
front run-config the installer writes (service unit + flags) and how
`configureRuntime` starts it; the certbot-webroot wiring; the ordered,
idempotent teardown step list (stop/disable/remove) and how it detects "already
migrated"; the native `/status`/`/progress` the installer/panel poll against;
the PHPUnit coverage for the mode branch + teardown; and the real-LB acceptance
run. Deliver in reviewable slices (provisioning gate for fresh API-mode installs;
the in-place teardown/migration; certbot-webroot + status/progress), each its own
PR.
