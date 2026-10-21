# LB serve-only provisioning gate (stop nginx-http + php-fpm) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a serve-only LB whose Go binary can be the sole front, stop provisioning/starting the HTTP nginx and the php-fpm HTTP daemon — the Go single binary (front + agent) becomes the whole HTTP surface — while a mode-1 (non-serve-only) LB stays byte-for-byte unchanged and any node on an older binary keeps its nginx/php-fpm (never goes dark).

**Architecture:** Three deployed-tree changes gated on the node's own markers, no MAIN behaviour change beyond provisioning. (1) `src/bin/xc_fanout/run.sh`: when the `serve_only` marker is set AND the binary advertises `-front-agent-sock`, launch the front as the **sole** listener — `-front-nginx ""` (nil backend, from #110) + `-front-agent-sock <agent.sock>` (routes /xfile, /stream/rtmp, /admin/* to the agent) — and write a `config/cluster/front_sole` marker; otherwise the current strangler config (`-front-nginx http://127.0.0.1:8080`) is unchanged. (2) `src/service`: when `serve_only` AND `front_sole`, skip starting http-nginx and `daemons.sh` (php-fpm); keep nginx_rtmp, the fanout/agent supervisors, and the Go front. (3) `src/Cli/Commands/LbInstallFlow.php::configureRuntime`: for `$rServeOnly`, do not ship the http-nginx confs or write the php-fpm pool configs (keep nginx_rtmp's `live.conf` and the Go-front config). certbot already uses `--webroot` on a :80 node (CertbotCommand.php:77) served by the front — **unchanged**.

**Tech Stack:** PHP 8.1 (LbInstallFlow, PHPUnit via `make test`), POSIX sh (`service`, `run.sh`). Executed Native/TDD.

**Spec:** `docs/superpowers/specs/2026-10-21-lb-install-cutover-design.md`

## Global Constraints

- Base: **`origin/main`** (the old `2.8.0` branch merged to main and was deleted). Branch `feat/lb-serve-only-provisioning-gate`. **The user merges PRs; no Claude attribution.** Commit with `-c core.hooksPath=/dev/null`.
- Gate on **`$rServeOnly`** (the `server:install --serve-only` flag, type-2 only) — the precise in-tree marker; a serve-only node is necessarily credential-free and a mode-1 node is never serve-only, so this is strictly narrower and safer than the design's `$rApiMode` wording. **Breaking a mode-1 node is the primary failure.**
- **Capability-gated for safety:** the `service` gate keys off the `front_sole` marker that `run.sh` writes only when it actually launched the sole front; a node on a binary without `-front-agent-sock` gets no `front_sole` marker → `service` keeps http-nginx + php-fpm → no outage. nginx_rtmp, the PHP CLI (console.php crons), and the fanout/agent supervisors stay in every case.
- `make gates` stays green (LB archive privileged-code-free). Supported distros unchanged (Ubuntu 20/22/24, Debian 12/13). Scope: FRESH-install provisioning + the shared-script gate; the in-place teardown of a node's *already-running* http-nginx/php-fpm on re-provision is a SEPARATE later slice.

## Review Focus

- **A serve-only node on an older binary (no `-front-agent-sock`)** — `run.sh` must fall back to the strangler config and write no `front_sole`, and `service` must still start http-nginx + php-fpm. No outage. Pinned in Task 1 + Task 2 (shell inspection + `bash -n`; no live binary in CI).
- **A mode-1 (non-serve-only) LB re-provisioned** — `configureRuntime` must issue the full http-nginx + php-fpm set byte-identically. Pinned by the Task 3 regression test.
- **`nginx_rtmp` on a serve-only node** — must still be configured (`live.conf`) and started; only the HTTP nginx + php-fpm are gated. Pinned in Task 2 (service) + Task 3 (configureRuntime keeps `live.conf`).
- **The `service` `start`/`reload` nginx-pid guards on a serve-only node** — `pgrep -u xc_vm nginx` still matches `nginx_rtmp`, so the "already running" guard holds; `reload` must not try to reload a non-running http-nginx. Pinned in Task 2.
- **The front's agent socket path** — `run.sh`'s `-front-agent-sock` must point at the agent's real listen socket (`$SCRIPT/config/cluster/agent.sock`, `AgentPaths::SOCKET`); a wrong path silently 404s /xfile, /stream/rtmp, /admin/*. Pinned in Task 1 (verified against `AgentPaths::SOCKET`).

---

### Task 1: `run.sh` — launch the sole front for a capable serve-only node

**Files:**
- Modify: `src/bin/xc_fanout/run.sh` (the `FRONT=()` block, ~89-95)

**Interfaces:**
- Produces: when `serve_only` + the binary has `-front-agent-sock`, the daemon is launched with `-front "$FRONT_ADDR" -front-cert … -front-key … -front-nginx "" -front-agent-sock "$SCRIPT/config/cluster/agent.sock"`, and `config/cluster/front_sole` is written (consumed by `service`, Task 2). Otherwise unchanged (strangler `-front-nginx http://127.0.0.1:8080`, and `front_sole` removed).

- [ ] **Step 1: Confirm the agent socket path**

Read `src/Core/Cluster/AgentPaths.php`: `SOCKET = DIR . 'agent.sock'`, `DIR = 'cluster/'`, and the agent state lives under `CONFIG_PATH` (`AGENT_STATE = CONFIG_PATH . AgentPaths::STATE`). So the node-absolute path is `/home/xc_vm/config/cluster/agent.sock` → in `run.sh`, `"$SCRIPT/config/cluster/agent.sock"`. Verify the agent actually listens there (the dataplane socket the front's `/xfile` already targets).

- [ ] **Step 2: Edit the `FRONT=()` block**

In the `if [ -f "$SCRIPT/config/cluster/serve_only" ] && … grep -q -- '-front'` branch: probe additionally for `-front-agent-sock` (`"$FANOUT_DIR/xc_fanout" -h 2>&1 | grep -q -- '-front-agent-sock'`). When present, build `FRONT=(-front "$FRONT_ADDR" -front-cert "…server.crt" -front-key "…server.key" -front-nginx "" -front-agent-sock "$SCRIPT/config/cluster/agent.sock")` and `touch "$SCRIPT/config/cluster/front_sole"`. When the binary has `-front` but not `-front-agent-sock`, keep today's strangler line (`-front-nginx http://127.0.0.1:8080`) and `rm -f …/front_sole`. In the no-`-front` else-branch, also `rm -f …/front_sole` (alongside the existing `front_capable` removal).

- [ ] **Step 3: Syntax check**

Run: `bash -n src/bin/xc_fanout/run.sh`
Expected: no output (valid). Also re-read the block to confirm the sole-front array and both fallbacks (no-`-front-agent-sock`, no-`-front`) clear `front_sole`.

- [ ] **Step 4: Commit**

```bash
git -c core.hooksPath=/dev/null add src/bin/xc_fanout/run.sh
git -c core.hooksPath=/dev/null commit -m "feat(lb): run.sh launches the sole Go front for a capable serve-only node (no nginx backend, agent sock)"
```

### Task 2: `src/service` — gate http-nginx + php-fpm off for a sole-front serve-only node

**Files:**
- Modify: `src/service` (`boot()` ~72-74; `reload()` ~156)

**Interfaces:**
- Consumes: `config/cluster/front_sole` (Task 1) and `config/cluster/serve_only`.
- Produces: on a `serve_only` + `front_sole` node, http-nginx (`bin/nginx/sbin/nginx`) and `bin/daemons.sh` are not started; nginx_rtmp, the fanout/agent `run.sh` supervisors, redis, and the PHP CLI startup all run as before.

- [ ] **Step 1: Edit `boot()`**

Guard the http-nginx start (`sudo -u xc_vm $SCRIPT/bin/nginx/sbin/nginx …`) and the `daemons.sh` start (`sudo -u xc_vm $SCRIPT/bin/daemons.sh`) behind `if ! { [ -f $SCRIPT/config/cluster/serve_only ] && [ -f $SCRIPT/config/cluster/front_sole ]; }; then … fi` (i.e. skip both only for a sole-front serve-only node). Leave the `nginx_rtmp` start, the `run.sh` supervisors, redis, `cluster:apply`, `console.php startup`, and the signals/watchdog/queue lines untouched. Add a one-line comment naming the markers.

- [ ] **Step 2: Edit `reload()`**

`reload()` runs `nginx -s reload` (http) then `nginx_rtmp -s reload`. On a sole-front serve-only node http-nginx is not running, so skip the http-nginx reload behind the same `serve_only` + `front_sole` guard; always reload nginx_rtmp. The `start()`/`reload()` `pgrep -u xc_vm nginx` liveness count still matches the running `nginx_rtmp`, so leave those guards unchanged (document why in a comment).

- [ ] **Step 3: Syntax check**

Run: `bash -n src/service`
Expected: no output. Re-read `boot()` and `reload()` to confirm the guards skip only http-nginx + `daemons.sh`, and only when both markers are present.

- [ ] **Step 4: Commit**

```bash
git -c core.hooksPath=/dev/null add src/service
git -c core.hooksPath=/dev/null commit -m "feat(lb): service skips http-nginx + php-fpm on a sole-front serve-only node (keeps nginx_rtmp, supervisors, CLI)"
```

### Task 3: `LbInstallFlow::configureRuntime` — don't provision http-nginx/php-fpm for a serve-only install

**Files:**
- Modify: `src/Cli/Commands/LbInstallFlow.php` (`configureRuntime`, ~456-504)
- Test: `tests/Unit/ConfigureRuntimeServeOnlyTest.php` (new; copy the fake-`$rRun`/`$rSend` + `$this->servers()`/`self::SID` harness from `tests/Unit/LbProvisionClusterTest.php`, and check `tests/Unit/ServeOnlyInstallTest.php` first — extend it instead if its harness already drives `configureRuntime`)

**Interfaces:**
- Consumes: `configureRuntime($rConn, callable $rSendFileSSH, callable $rRunSSH, array $rServers, int $rServerID, bool $rServeOnly = false): int`.
- Produces: for `$rServeOnly`, the http-nginx conf sends (`custom.conf`, `realip_*.conf`, the empty `limit.conf`/`limit_queue.conf`) and the php-fpm pool writes (`rm bin/php/etc/*.conf` + `PhpFpmPools::files()` loop) are skipped; the nginx_rtmp `live.conf`, the serve-only front block (`front_addr` + loopback real-IP), and the `daemons.sh`/`bin` chmods are kept. For a non-serve-only node every call is issued exactly as today. Still returns `$rServices` (the CPU count) in both modes.

- [ ] **Step 1: Write the failing test**

Fake `$rRunSSH`/`$rSendFileSSH` to append every command string / sent-file dest to arrays. Assert, for `$rServeOnly = true`: NO command matches the php-fpm pool path (`bin/php/etc`) and NO sent file is one of `custom.conf`/`realip_cdn.conf`/`realip_cloudflare.conf`/`realip_xc_vm.conf`, while a command DOES write `bin/nginx_rtmp/conf/live.conf` and `config/cluster/front_addr`. For `$rServeOnly = false` (regression guard): the php-fpm pool writes AND the http-nginx conf sends ARE all present (the full set, unchanged).

```php
public function testServeOnlySkipsHttpNginxAndPhpFpm(): void {
    [$runLog, $sendLog, $run, $send] = $this->fakeSSH();
    LbInstallFlow::configureRuntime(null, $send, $run, $this->servers(), 5, true);
    $this->assertNotContains('custom.conf', $this->dests($sendLog));        // http-nginx conf not shipped
    $this->assertEmpty(preg_grep('~bin/php/etc~', $runLog));                 // no php-fpm pool writes
    $this->assertNotEmpty(preg_grep('~nginx_rtmp/conf/live\.conf~', $runLog)); // rtmp kept
    $this->assertNotEmpty(preg_grep('~cluster/front_addr~', $runLog));       // front config kept
}
public function testModeOneUnchanged(): void {
    [$runLog, $sendLog, $run, $send] = $this->fakeSSH();
    LbInstallFlow::configureRuntime(null, $send, $run, $this->servers(), 5, false);
    $this->assertContains('custom.conf', $this->dests($sendLog));
    $this->assertNotEmpty(preg_grep('~bin/php/etc~', $runLog));
}
```
(The exact helper names follow the nearest existing `LbInstallFlow` test; `PhpFpmPools::files()` reads `bin/php/etc/template` — provide a stub via the test's MAIN_HOME fixture or assert on the `rm bin/php/etc/*.conf` + send calls rather than the template read.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ConfigureRuntimeServeOnly`
Expected: FAIL — serve-only currently ships the nginx confs + php-fpm pools.

- [ ] **Step 3: Implement the gate**

Wrap the http-nginx conf sends (`custom.conf`, the three `realip_*.conf`, the empty `limit.conf`/`limit_queue.conf` writes) and the php-fpm block (`rm … bin/php/etc/*.conf` + the `PhpFpmPools::files()` foreach) in `if (!$rServeOnly) { … }`. Keep the nginx_rtmp `live.conf` write, the `$rServeOnly` front block, and the `daemons.sh`/`bin` chmods outside the gate. (Note: `daemons.sh` is still made executable + `bin` chmodded even for serve-only — harmless; `service` won't run `daemons.sh` for a sole-front node.)

- [ ] **Step 4: Run it to verify it passes**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ConfigureRuntimeServeOnly`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git -c core.hooksPath=/dev/null add src/Cli/Commands/LbInstallFlow.php tests/Unit/ConfigureRuntimeServeOnlyTest.php
git -c core.hooksPath=/dev/null commit -m "feat(lb): configureRuntime skips http-nginx confs + php-fpm pools for a serve-only install"
```

### Task 4: Gates + full suite

**Files:** none (verification; fold into the PR).

- [ ] **Step 1: Gates**

Run: `make gates`
Expected: all five pass (the LB archive stays privileged-code-free; the serve-only replica keys unchanged).

- [ ] **Step 2: Full suite**

Run: `make test`
Expected: green (the new LbInstallFlow test included; no other suite references the changed symbols). `php -l src/Cli/Commands/LbInstallFlow.php` clean.
