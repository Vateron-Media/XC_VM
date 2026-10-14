# Phase 10 — Legacy MAIN↔LB `/api` Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire the legacy MAIN↔LB `/api` link and the sub-mode-2 fallback paths, gated by an update that refuses any panel still running a load balancer below cluster mode 2.

**Architecture:** Phases 0–9 built the cluster API (mode-2 nodes read their replica + runtime store, never MAIN's MySQL/Redis). The legacy `/api` and the mode-0/1 DB/Redis fallbacks are now dead weight on a fully-mode-2 fleet. Phase 10 deletes them in a **major release**, after a **pre-major release ships an update-refusal guard** so no panel with a sub-mode-2 LB self-breaks. The streaming/command path over `node.rpc` (`ClusterExecCommand` → `InternalApiController::runCommand`/`dispatch`) stays; only the legacy `/api` *entry* and the sub-mode-2 arms go.

**Tech Stack:** PHP 8.1 (PSR-4 `XcVm\` → `src/`), nginx conf templates (`lb_configs/`, `src/bin/nginx/conf/`), the `crontab` role system, PHPUnit 10.5.

**Spec:** `docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md` (Phase 10, line ~1045) and the authoritative **ADR 0004** `docs/adr/0004-cluster-api.md:6648-6695` ("Proxies and the legacy `/api` … retirement", step-4 list at :6674-6683).

## Global Constraints

- **Two releases.** The update-refusal guard (Task 1) ships in the **next regular 2.6.x release**, *before* the major. The removals (Tasks 2–8) ship in the **next major release** (`MAJOR`, e.g. `2.7.0` — the exact tag is the maintainer's call; the plan refers to it as `MAJOR`). A panel must be able to refuse the `MAJOR` update while still on 2.6.x, which only the earlier guard can do.
- **Breaking.** The removal strands any LB below mode 2 and breaks external `/api` callers (XC_VM_Proxy, third-party). This is intended and is why the guard is mandatory.
- **Prerequisite satisfied:** step 2 (new installs start on the cluster API) shipped — `install:2439-2440` runs `cluster:init --enable && cluster:main-dataplane on`.
- **Keep the `node.rpc` path.** `InternalApiController::runCommand()` + `dispatch()` + helpers and `ClusterExecCommand` stay on the LB build; `InternalApiController.php` stays out of the Makefile strip lists. Only the legacy `index()` entry, its password+IP gate, `LegacyApiAudit`, and the `$this->deny` flood machinery are removed.
- **MAIN keeps its DB arm.** The dual-path readers (`StreamSource`, `ReplicaStreamCache`, the sinks/daemons) are shared with the MAIN build; only the LB-only sub-mode-2 fallback arm is removed, never the class. `local()`/`owned()` are false on MAIN, which legitimately takes the DB path.
- **MAIN's own `/api`** (`src/bin/nginx/conf/nginx.conf`, no `$api_legacy` toggle) is a separate cluster-wide judgement, explicitly **out of scope** for Phase 10.
- Follow repo conventions: `php -l` every changed file; `make gates` (esp. `verify-lb-archive`) must pass; `make docs-build` (strict) after doc edits; migrations are single statements in `src/migrations/database/up/NNN_*.sql` + mirrored in `src/bin/install/database.sql`; never run `composer install` on a deploy path.

## Review Focus

- **The never-enrolled LB blind spot.** `NodeRegistry::enrolled()` only sees `cluster_nodes` rows; a legacy LB that was never enrolled (a `servers` row of type LB with no `cluster_nodes` row, or `mode 0`) reports nothing (ADR limit :6687). The guard must treat "LB `servers` row without a mode-≥2 `cluster_nodes` row" as below mode 2 and refuse — else such a node strands silently. Test: a `servers` LB row with no matching `cluster_nodes` row blocks the `MAJOR` update.
- **Guard version boundary.** The guard must refuse only when the *target* update is `>= MAJOR` (sub-mode-2 LBs must still be able to take ordinary 2.6.x updates). Test: target `2.6.x` allowed with a mode-1 LB present; target `MAJOR` refused.
- **`getFile`/`serveFile` dispatch arm.** MAIN pulls files from sub-mode-2 nodes through this arm (ADR :3393). It is dead only once the fleet is all mode-2 — i.e. only safe to delete in `MAJOR` behind the guard, not before. Test: after removal, the mode-2 file path (agent loopback / `/xfile`) is the only one exercised.
- **verify-lb-archive auto-adaptation.** The gate derives routed controllers by scanning `fastcgi_param XC_API …` out of `lb_configs/nginx.conf` (`tools/ci/verify-lb-archive.sh:184-216`). Deleting the `/api` locations removes `internal` from that scan, so the gate stops *requiring* `InternalApiController` via nginx while the file still ships for `node.rpc`. Test: `make verify-lb-archive` passes with the `/api` locations gone and `InternalApiController.php` still shipped.
- **cronRoles collapse.** After the `legacy` role is retired, `ReplicaSections::cronRoles()` must return `['all']` for every mode and the `users` job must still run on MAIN (it reaps MAIN's own `lines_live`/Redis). Test: MAIN crontab still includes `users`; no node receives a `legacy`-role job.

---

### Task 1: Update-refusal guard — refuse the `MAJOR` update while any LB is below mode 2 (ships in the next 2.6.x release)

**Files:**
- Modify: `src/Cli/Commands/UpdateCommand.php` (MAIN branch of `execute()`, ~:109-120, before download)
- Create: `src/Domain/Cluster/ClusterUpgradeGate.php` (the predicate, so it is unit-testable without the updater)
- Modify: `src/Domain/Cluster/NodeRegistry.php` only if a "LB servers rows lacking a mode-≥2 node" query is missing (reuse `enrolled()` + a `servers` scan)
- Test: `tests/Unit/ClusterUpgradeGateTest.php`

**Interfaces:**
- Consumes: `NodeRegistry::enrolled()` (`:55-56`, rows with `mode`), the `servers` table (type = LB), `GitHubReleases::isReleaseVersion`/`version_compare`.
- Produces: `ClusterUpgradeGate::blocksUpgradeTo(string $targetVersion, string $majorBoundary = self::MAJOR): ?string` — returns null when the update may proceed, else a human reason string naming the offending node(s).
- `const ClusterUpgradeGate::MAJOR = '2.7.0'` (the removal release; the one constant the major release and this guard share — set to the real tag when chosen).

- [ ] **Step 1: Write the failing test** — `ClusterUpgradeGateTest`: (a) target `2.6.9` with a mode-1 enrolled LB → returns null (allowed); (b) target `2.7.0` with all LBs mode 2 → null; (c) target `2.7.0` with a mode-1 enrolled LB → non-null reason; (d) target `2.7.0` with a `servers` LB row and no `cluster_nodes` row → non-null (blind-spot case). Stub the DB with the existing `TestDb` + minimal `servers`/`cluster_nodes` tables.
- [ ] **Step 2: Run it, confirm it fails** (`ClusterUpgradeGate` undefined).
- [ ] **Step 3: Implement `ClusterUpgradeGate`.** `blocksUpgradeTo`: if `version_compare($targetVersion, self::MAJOR, '<')` return null. Else collect every LB (`servers` where the row is an LB / not MAIN) and require a `cluster_nodes` row with `mode >= 2` and an active state; return a reason listing any that fail (mirror `NodeRegistry::allActiveHaveFeature()` at `:149-150`).
- [ ] **Step 4: Wire into `UpdateCommand::execute()`** — on the `$rIsMain` branch, after `getLatestVersion()` and before the download, `if ($reason = ClusterUpgradeGate::blocksUpgradeTo($latest)) { echo … ; return 1; }` with a message telling the admin to move the named LBs to mode 2 (or remove them) first.
- [ ] **Step 5: Run the test + `php -l`; commit.**

### Task 2: Remove the LB `/api` nginx locations + `api_legacy.conf` renderer

**Files:**
- Modify: `lb_configs/nginx.conf` — delete `location = /api` (`:340-357`), `location = /api.php` (`:359-376`), and `include api_legacy.conf;` (`:52`)
- Delete: `src/bin/nginx/conf/api_legacy.conf`
- Modify: `src/Cli/CronJobs/RootSignalsCronJob.php` — remove `apiLegacyConf()` (`:564-569`) and its writer (`:866-868`)
- Test: update `tests/Unit/LbNginxApiLegacyTest.php` (remove the `api_legacy`/`/api` assertions; keep MAIN-has-no-toggle only if MAIN `/api` stays)

- [ ] **Step 1:** Update `LbNginxApiLegacyTest` to assert the LB nginx has **no** `/api`/`/api.php` location and **no** `include api_legacy.conf;`. Run — fails against current conf.
- [ ] **Step 2:** Delete the two `location` blocks + the include from `lb_configs/nginx.conf`; delete `api_legacy.conf`; remove `apiLegacyConf()` + its writer call in `RootSignalsCronJob`.
- [ ] **Step 3:** Run the test; `make verify-lb-archive` (the `api_names` scan no longer finds `internal`). Commit.

### Task 3: Strip `InternalApiController::index()` (keep `runCommand`/`dispatch`)

**Files:**
- Modify: `src/Public/Controllers/Api/InternalApiController.php` — remove `index()` (`:48-69`) + the password/IP gate (`:53-61`), the `$this->deny` property (`:34`) and `shutdown()` flood hook (`:36-46`), and the `LegacyApiAudit::record()` call (`:66`). Keep `runCommand()` (`:79-82`), `dispatch()` (`:84-457`) and helpers (`:459-694`).
- Modify: `src/Public/index.php` — remove the `'internal' => [InternalApiController::class, 'web']` route (`:179`) and its `index()` call (`:201-204`).
- Delete: `src/Core/Cluster/LegacyApiAudit.php` and its `STORAGE_PATH/cluster/legacy_api/` reader; remove the heartbeat `legacy_api` field and the Cluster Nodes "Legacy /api calls" display (`ClusterOverview`, the nodes view).
- Remove the now-unreachable `dispatch()` arms not in `NodeRpc::ACTIONS` (`src/Core/Cluster/NodeRpc.php:24-29`): `view_log`, `reload_epg`, `pidsAreRunning`, `getFile`/`serveFile`, `redirect_connection`, `watch_force`, `plex_force`, `kill_watch`.
- Test: delete `tests/Unit/LegacyApiAuditTest.php`; update `tests/Unit/LbArchiveGateTest.php` (`:113`, `:126-129`); keep `NodeApiGuardsTest`/`AuditStreamCmdProbeArgsTest` (node.rpc).

- [ ] **Step 1:** Grep-confirm each dead `dispatch` arm is absent from `NodeRpc::ACTIONS` before deleting it (guard against removing a live action).
- [ ] **Step 2:** Remove `index()`, the gate, `$this->deny`/`shutdown()`, the audit call and `LegacyApiAudit`; prune the dead dispatch arms; drop the `internal` route in `index.php`.
- [ ] **Step 3:** Update/remove the listed tests; `php -l`; `make verify-lb-archive`; run `tests/Unit/NodeApiGuardsTest.php` (node.rpc still works). Commit.

### Task 4: Remove `StatusCommand::configureRedisLb`

**Files:**
- Modify: `src/Cli/Commands/StatusCommand.php` — remove `configureRedisLb()` (`:420-444`), its `elseif (!$rApi)` call (`:140-148`), and `mainRedisHost()` (`:454-468`) if unused elsewhere.
- Modify: `src/Core/Cluster/RootCredentials.php:18`, `RootSignalsCronJob.php:1013` — drop the shared-password plumbing only if it exists solely for `configureRedisLb`.
- Test: delete `tests/Unit/StatusCommandRedisHostTest.php`.

- [ ] **Step 1:** Confirm `mainRedisHost()`/the shared-password refs have no other caller (grep). **Step 2:** Remove the method + branch. **Step 3:** `php -l`; run the cluster/status tests; commit.

### Task 5: Retire the `users` cron `legacy` role

**Files:**
- Modify: `src/Core/Cluster/ReplicaSections.php:228-230` — `cronRoles()` returns `['all']` always.
- Create: `src/migrations/database/up/NNN_retire_crontab_legacy_role.sql` (next unused NNN) — `UPDATE crontab SET role='all' WHERE role='legacy';` (the `users` job keeps running on MAIN under `all`; mode-2 LBs already exclude it by server scope, not role). Mirror in `src/bin/install/database.sql`.
- Modify: `src/Domain/Cluster/ReplicaBuilder.php:348` — drop the `mode>=2 ? 'api' : 'legacy'` CRONTAB section-key split if it only distinguished the role.
- Test: update `tests/Unit/AuditCoreDataCrontabUserTest.php`, `ReplicaSectionsTest.php`, `ClusterSchemaTest.php:164`.

- [ ] **Step 1:** Write the migration + mirror; confirm `UsersCronJob` still runs on MAIN (role `all`). **Step 2:** Collapse `cronRoles()`. **Step 3:** Update tests; run them (needs test DB); commit.

### Task 6: Collapse the LB-only sub-mode-2 DB/Redis fallback arms

**Files (each: remove the `!refusesConnects()`/non-`local()`/non-`owned()` LB fallback arm, keep the MAIN arm):**
- `src/Domain/Stream/StreamSource.php` (`streamRow` et al. — the `else{ DatabaseFactory::get(); SELECT … }` arm is MAIN-only after this; the LB build always goes through `local()`).
- `src/Public/admin/thumb.php:44`, `vod.php:46`, `timeshift.php:60` (`owned() ? … : DatabaseFactory::open()` — the `else` is MAIN-only).
- Review the `refusesConnects()`-guarded sinks/daemons the map lists (`NodeStateSink`, `QueueSink`, `LogSink`, `ContentSink`, `DaemonTrait`, `OndemandCommand`, `MonitorCommand`) and delete only arms that are provably LB-sub-mode-2-only.
- Test: prune the sub-mode-2-fallback assertions in `ModeTwoPathsTest`, `StreamRuntimeReadersTest`, `ConnectAuditTest`, `DbConnectRefusalTest`; keep the mode-2 replica-path ones.

- [ ] **Step 1 (per file):** prove the arm is unreachable on both MAIN (takes the DB arm legitimately) and a mode-2 LB (takes `local()`), so only the mode-0/1 LB path dies; **do not** touch the class where MAIN needs the DB arm. **Step 2:** remove; `php -l`. **Step 3:** run the mode-2 test matrix; commit per file or small group.

> **Right-sizing note:** Task 6 is the riskiest. Split it per file during execution; each file is its own reviewable deliverable.

### Task 7: Final `verify-lb-archive` + Makefile check

**Files:** `tools/ci/verify-lb-archive.sh`, `Makefile` (`lb` target strip lists — expected **no change** for `InternalApiController`), `tests/Unit/LbArchiveGateTest.php`.

- [ ] **Step 1:** Run `make verify-lb-archive`; confirm it passes with `/api` gone and `InternalApiController.php` still shipped. **Step 2:** Update the hard-coded gate test rows (`:113`, `:126-129`). **Step 3:** `make gates`. Commit.

### Task 8: Rewrite `server:diagnose` + docs

**Files:**
- Modify: `src/Cli/Commands/ServerDiagnoseCommand.php` — replace the MAIN-path HTTP `/api` probe (`httpApi()` `:516`, called from `diagnoseFromMain()`) with the agent/cluster-API health round trip (`AgentClient::status` is already used in `diagnoseLocal()` at `:368`; reuse it from the MAIN path via the cluster API). Target behaviour per spec `:582`: "Tests `health`, a signed round trip, token expiry, skew, outbox lag."
- Modify: `docs/en/development/cluster-api.md` — "plain HTTP by default; HTTPS optional"; document the retirement as done; update ADR 0004 status.
- Test: `tests/Unit/` for `ServerDiagnoseCommand` (stub `AgentClient::status`); `make docs-build` (strict).

- [ ] **Step 1:** Rewrite the probe; **Step 2:** edit docs/en only (ru is generated); **Step 3:** `make docs-build`; run the diagnose test; commit.

---

## Self-Review notes (author)

- **Spec coverage:** every item in ADR 0004 step-4 (:6674-6683) maps to a task — `/api` locations (T2), `InternalApiController::index` (T3), `configureRedisLb` (T4), `users` legacy cron (T5), sub-mode-2 DB/Redis paths (T6); plus the mandated update refusal (T1), `verify-lb-archive`/`server:diagnose`/docs (T7, T8).
- **Ordering:** T1 ships first (earlier 2.6.x). T2–T8 are the `MAJOR` release and are mostly independent; T7 depends on T2/T3 landing; T8 depends on T2 (the `/api` probe target).
- **Type consistency:** `ClusterUpgradeGate::MAJOR` is the single shared boundary constant between the guard and the removal release.
- **Not in scope:** MAIN's own `/api`; XC_VM_Proxy enrolment (Phase 11 / D8); removing `InternalApiController` entirely (its node.rpc half is permanent).
