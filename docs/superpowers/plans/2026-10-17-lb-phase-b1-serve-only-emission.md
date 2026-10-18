# LB Phase B1 — serve_only portable-Spec emission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On MAIN, emit a portable transcode Spec in a serve-only node's replica `stream` records — composed page-bound (only for the records in the outgoing page), from a batched snapshot (no per-stream query), and gated so every non-serve-only node's emission stays byte-identical.

**Architecture:** `StreamRecords::data()` keeps emitting the classifier-only directive (no Spec) for every caller. `StreamReplica` — which signs the records a node fetches — upgrades the directive to `transcode` for a serve-only target, inside `page()`, per surviving record, before its ETag is taken. The Spec is composed by `StreamProcess::buildSupervisorSpec` in portable mode, fed from a `StreamSource::useLoader` snapshot built by one batched read each of the held streams' full rows (`streams ⋈ streams_types ⋈ profiles`), this server's `streams_servers` rows, and their arguments — so composition does zero per-stream queries. The loader is a process-global static, installed around `page()` and always reset in a `finally`.

**Tech Stack:** PHP 8.1, PHPUnit 10.5. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-17-lb-activation-serve-only-and-emission-design.md` (Part B). This plan covers Part B / Phase B1 only. Part A (serve_only provisioning) and B2 (version-bump on a serve_only flip) are separate.

## Global Constraints

- PHP 8.1; `short_open_tag=1` (irrelevant here — no view templates touched).
- No new Composer dependency; `vendor/` is untouched.
- `make gates` must pass. Critically **`check-core-cluster-refs`**: `src/Core/` may reference `XcVm\Domain\Cluster` only behind `class_exists()`. Therefore **all references to `StreamProcess` / `StreamSource` (Domain\Stream) live in `StreamReplica` (Domain\Cluster), never in `StreamRecords` (Core)**. `StreamRuntimeDirective` is Core\Cluster and must not reference Domain.
- CI runs PHPUnit with `failOnWarning="true"` and `failOnRisky="true"`: no undefined variables, no undefined array keys, no risky (assertion-less) tests.
- PHPStan clean at `--memory-limit=2G`.
- The directive must be final **before** `ReplicaBuilder::etag()` is taken for a record (the directive is inside the ETag; only `tickets` is excluded and filled later).
- A non-serve-only target's emission must be byte-identical to current `main` (same record, same ETag): the serve_only gate is the inert guarantee.
- DB-backed tests run on CI (this repo's convention; no local test DB). Pure/`useLoader` tests run anywhere.

## Review Focus

- **Loader global leak on a mid-page throw** — `page()` can throw `ClusterRefusedException`; the `useLoader` snapshot must be reset even then, or it leaks into later reads in the same request. → Task 4: the install/reset is a `try/finally`; the integration test drives a serve_only page and asserts a subsequent `StreamSource` read is unaffected.
- **Loopback child (`parent_id > 0`) on a serve_only target** — its source carries `live_streaming_pass`; `buildSupervisorSpec` returns null for it, so the directive must stay `other` and no relay secret may appear in the record. → Task 3 test: a loopback snapshot yields `kind == other`, record carries no spec.
- **Non-live held stream (movie/series) on a serve_only target** — `streamRow(live=true)` returns null, so the Spec is null and the directive stays `other`; no crash. → Task 2 test: the loader's `stream` read uses `streams_types.live = 1`, so a non-live id returns null.
- **serve_only delta past one page** — composition is paid per surviving record; with more composable streams than `MAX_RECORDS`, exactly `MAX_RECORDS` are emitted and `more`/`next` are unchanged. → Task 4 test: a serve_only delta/resync with the existing >`MAX_RECORDS` fixture still caps at `MAX_RECORDS`.
- **Every existing (non-serve-only) node** — emission unchanged, same ETag. → Task 4 test: `serve_only = 0` yields the same record ETag as before the change.

---

### Task 1: `StreamRuntimeDirective::upgrade()` — the pure classifier upgrade

**Files:**
- Modify: `src/Core/Cluster/StreamRuntimeDirective.php`
- Test: `tests/Unit/StreamRuntimeDirectiveTest.php`

**Interfaces:**
- Produces: `StreamRuntimeDirective::upgrade(array $rDirective, ?array $rTranscodeSpec): array` — returns a `transcode` directive (`['kind' => 'transcode', 'source' => null, 'spec' => $rTranscodeSpec]`) when `$rDirective['kind'] === 'other'` and `$rTranscodeSpec !== null`; otherwise returns `$rDirective` unchanged. `build()` is refactored to end with `return self::upgrade(['kind' => 'other', 'source' => null], $rTranscodeSpec);` so the `transcode` shape is defined in one place.

- [ ] **Step 1: Write the failing tests**

Add to `StreamRuntimeDirectiveTest`:

```php
public function testUpgradeOtherWithSpecBecomesTranscode(): void {
    $rSpec = ['sources' => [['cmd' => '{ffmpeg_cpu} -i x']], 'encrypt' => false];
    $this->assertSame(
        ['kind' => 'transcode', 'source' => null, 'spec' => $rSpec],
        StreamRuntimeDirective::upgrade(['kind' => 'other', 'source' => null], $rSpec)
    );
}

public function testUpgradeOtherWithNullSpecStaysOther(): void {
    $rDir = ['kind' => 'other', 'source' => null];
    $this->assertSame($rDir, StreamRuntimeDirective::upgrade($rDir, null));
}

public function testUpgradeLeavesProxyUntouched(): void {
    $rDir = ['kind' => 'proxy', 'source' => ['urls' => ['http://x']]];
    $this->assertSame($rDir, StreamRuntimeDirective::upgrade($rDir, ['sources' => []]));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamRuntimeDirectiveTest`
Expected: FAIL — `Call to undefined method …::upgrade()`.

- [ ] **Step 3: Implement `upgrade()` and refactor `build()`**

Add `upgrade()` with the signature above. Change `build()`'s final two lines (currently the `if ($rTranscodeSpec !== null) { return [transcode]; } return [other];`) to the single `return self::upgrade(['kind' => 'other', 'source' => null], $rTranscodeSpec);`. The proxy branch is unchanged.

- [ ] **Step 4: Run the tests (new + existing) to verify they pass**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamRuntimeDirectiveTest`
Expected: PASS (the 3 new cases and the existing `build()` cases).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Cluster/StreamRuntimeDirective.php tests/Unit/StreamRuntimeDirectiveTest.php
git commit -m "feat(cluster): add StreamRuntimeDirective::upgrade for portable-Spec directives"
```

---

### Task 2: `StreamReplica::runtimeLoader()` — the batched snapshot

**Files:**
- Modify: `src/Domain/Cluster/StreamReplica.php`
- Test: `tests/Unit/StreamReplicaRuntimeLoaderTest.php` (new, DB-backed → CI)

**Interfaces:**
- Produces: `StreamReplica::runtimeLoader(int $rServerID, array $rIDs): callable` — a `StreamSource` loader `fn(string $rWhat, int $rID, array $rOpts): mixed` backed by three batched reads over `$rIDs`, answering `'stream'` / `'server'` / `'arguments'` and `null` (or `[]` for `'source'`) otherwise. Public so it can be unit-tested; documented as a MAIN-only emission helper.
- Consumes: `StreamRecords::unescaped()` (already public) for the row cleaner without HTML escaping.

The three reads mirror `StreamSource`'s SQL exactly so the composed Spec matches an on-node read:
- `stream`: `SELECT t1.*, t2.*, t4.* FROM \`streams\` t1 INNER JOIN \`streams_types\` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN \`profiles\` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id IN (<ids>)` — map by `id`, each row through `StreamRecords::unescaped(get_raw_rows row)`. A non-live id is absent → loader returns null.
- `server`: `SELECT * FROM \`streams_servers\` WHERE \`server_id\` = ? AND \`stream_id\` IN (<ids>)` — map by `stream_id`, unescaped.
- `arguments`: `SELECT t1.*, t2.* FROM \`streams_options\` t1, \`streams_arguments\` t2 WHERE t1.argument_id = t2.id AND t1.stream_id IN (<ids>)` — group unescaped rows by `stream_id` into a list; the loader returns the list, or `array_column($list, null, 'argument_key')` when `$rOpts['keyed']` is true.

Use the existing private `self::read()` (StrictQuery) for each read, and build the id list with `implode(',', array_map('intval', $rIDs))`. Return `[]` early when `$rIDs === []`.

- [ ] **Step 1: Write the failing test**

New `StreamReplicaRuntimeLoaderTest` (model the DB setup on `ClusterApiTest`'s streams-section fixture — a MariaDB connection via `DatabaseFactory`, `CREATE TABLE` for `streams`, `streams_types`, `profiles`, `streams_servers`, `streams_options`, `streams_arguments`). Insert: one live stream id 7 (`type` matching a `streams_types` row with `live = 1`, `direct_source = 0`, `transcode_profile_id = 0`); its `streams_servers` row for server 5 (`parent_id` NULL, `on_demand` 1); one option joined to one argument (`argument_key = 'user_agent'`). Then:

```php
public function testRuntimeLoaderAnswersStreamServerArgumentsFromOneBatch(): void {
    $rLoader = StreamReplica::runtimeLoader(5, [7]);
    $this->assertSame(7, (int) $rLoader('stream', 7, ['live' => true])['id']);
    $this->assertSame(1, (int) $rLoader('server', 7, ['server_id' => 5])['on_demand']);
    $this->assertSame('user_agent', $rLoader('arguments', 7, ['keyed' => false])[0]['argument_key']);
    $this->assertArrayHasKey('user_agent', $rLoader('arguments', 7, ['keyed' => true]));
    $this->assertNull($rLoader('stream', 999, ['live' => true]), 'an id not in the batch is null');
    $this->assertSame([], $rLoader('source', 7, []), 'source is not answered from the snapshot');
}

public function testRuntimeLoaderExcludesNonLiveStreams(): void {
    // id 8: a movie (its streams_types row has live = 0) → absent from the live join.
    $this->assertNull(StreamReplica::runtimeLoader(5, [8])('stream', 8, ['live' => true]));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamReplicaRuntimeLoaderTest`
Expected: FAIL — `Call to undefined method …::runtimeLoader()`.

- [ ] **Step 3: Implement `runtimeLoader()`**

Three batched reads into id-keyed maps as specified in Interfaces, then return the closure (`use` the three maps) with a `match ($rWhat)` over `'stream' / 'server' / 'arguments' / 'source'` / default.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamReplicaRuntimeLoaderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Cluster/StreamReplica.php tests/Unit/StreamReplicaRuntimeLoaderTest.php
git commit -m "feat(cluster): batched StreamSource snapshot for serve-only Spec composition"
```

---

### Task 3: `StreamReplica::runtimeUpgrade()` — the composer closure

**Files:**
- Modify: `src/Domain/Cluster/StreamReplica.php`
- Test: `tests/Unit/StreamReplicaRuntimeUpgradeTest.php` (new, DB-free via `StreamSource::useLoader`)

**Interfaces:**
- Produces: `StreamReplica::runtimeUpgrade(int $rServerID, array $rFeatures): callable` — returns `fn(int $rID, array $rDirective): array` that composes the portable Spec for `$rID` and upgrades the directive:
  `StreamRuntimeDirective::upgrade($rDirective, StreamProcess::buildSupervisorSpec($rID, ['features' => $rFeatures, 'server_id' => $rServerID]))`.
  The closure reads the stream through the **currently installed `StreamSource` loader** (set by the caller / the test) — it does not install one itself.
- Consumes: Task 1 `StreamRuntimeDirective::upgrade()`; `StreamProcess::buildSupervisorSpec($id, ?$rPortable)` (portable mode: `$rPortable` keys `features` (list, default `[]`) and `server_id`). `use XcVm\Domain\Stream\StreamProcess;` / `use XcVm\Domain\Stream\StreamSource;` at the top of `StreamReplica` (Domain\Cluster → Domain\Stream is allowed; it is not Core).

- [ ] **Step 1: Write the failing test**

New `StreamReplicaRuntimeUpgradeTest`. Reuse the portable-mode `useLoader` snapshot pattern from `BuildSupervisorSpecPortableTest` (define the path constants, `$GLOBALS['rSettings']`/`rServers`/ffmpeg globals in `setUp`, a live `streamInfo()` for id 7, `useLoader(null)` in `tearDown`). Then:

```php
public function testServeOnlyComposerUpgradesOtherToTranscode(): void {
    $rUpgrade = StreamReplica::runtimeUpgrade(1, []);
    $rOut = $rUpgrade(7, ['kind' => 'other', 'source' => null]);
    $this->assertSame('transcode', $rOut['kind']);
    $this->assertStringContainsString('{ffmpeg_cpu}', $rOut['spec']['sources'][0]['cmd']);
    $this->assertStringNotContainsString('/tmp/xcvm-test-', (string) json_encode($rOut), 'no node path leaks');
}

public function testComposerLeavesProxyUntouched(): void {
    $rProxy = ['kind' => 'proxy', 'source' => ['urls' => ['http://x']]];
    $this->assertSame($rProxy, StreamReplica::runtimeUpgrade(1, [])(7, $rProxy));
}

public function testComposerKeepsLoopbackChildAsOther(): void {
    // server snapshot with parent_id > 0 → buildSupervisorSpec returns null → stays other.
    StreamSource::useLoader(fn(string $w, int $i, array $o) =>
        $w === 'stream' ? $this->streamInfo() : ($w === 'server' ? ['parent_id' => 3, 'server_id' => 1, 'on_demand' => 0] : []));
    $rDir = ['kind' => 'other', 'source' => null];
    $rOut = StreamReplica::runtimeUpgrade(1, [])(7, $rDir);
    $this->assertSame('other', $rOut['kind']);
    $this->assertArrayNotHasKey('spec', $rOut);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamReplicaRuntimeUpgradeTest`
Expected: FAIL — `Call to undefined method …::runtimeUpgrade()`.

- [ ] **Step 3: Implement `runtimeUpgrade()`**

Return the closure described in Interfaces; add the two `use` imports.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter StreamReplicaRuntimeUpgradeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Cluster/StreamReplica.php tests/Unit/StreamReplicaRuntimeUpgradeTest.php
git commit -m "feat(cluster): serve-only directive composer over the snapshot loader"
```

---

### Task 4: Wire the composer into the signing path (gate + bracket + page)

**Files:**
- Modify: `src/Domain/Cluster/StreamReplica.php`
- Test: `tests/Unit/ClusterApiTest.php` (DB-backed → CI)

**Interfaces:**
- Consumes: Task 2 `runtimeLoader()`, Task 3 `runtimeUpgrade()`.
- Adds `private static function serveOnly(int $rServerID): bool` — `SELECT \`serve_only\` FROM \`servers\` WHERE \`id\` = ?` via `self::read()`, returns `(bool) (int) (row['serve_only'] ?? 0)`.
- Adds `private static function pageServeOnly(ClusterCrypto $rCrypto, array $rNode, array $rItems, array $rData, ?array $rHave): array` — the serve_only-gated wrapper around `page()`:

```php
$rServerID = (int) $rNode['server_id'];
if (!self::serveOnly($rServerID)) {
    return self::page($rCrypto, $rNode, $rItems, $rData, $rHave, null);
}
StreamSource::useLoader(self::runtimeLoader($rServerID, array_keys($rData)));
try {
    return self::page($rCrypto, $rNode, $rItems, $rData, $rHave, self::runtimeUpgrade($rServerID, []));
} finally {
    StreamSource::useLoader(null);
}
```

- Changes `page()`'s signature to `private static function page(ClusterCrypto $rCrypto, array $rNode, array $rItems, array $rData, ?array $rHave, ?callable $rUpgrade = null): array` and, immediately before the ETag line (`$rEtag = ReplicaBuilder::etag($rData[$rID]);`), inserts:

```php
if ($rUpgrade !== null) {
    $rData[$rID]['runtime'] = $rUpgrade($rID, $rData[$rID]['runtime']);
}
```

- Changes the two `self::page(...)` call sites to `self::pageServeOnly(...)`: in `delta()` (currently `self::page($rCrypto, $rNode, $rRows, $rData, null)`) and in `resync()` (currently `self::page($rCrypto, $rNode, $rItems, self::data($rServerID, $rHeld), $rHashes)`).

`features` is `[]` for now (MAIN does not store a node's live remux capability; `[]` forces ffmpeg, which is correct, never wrong). Wiring real features is a documented follow-up, not this task.

- [ ] **Step 1: Write the failing tests**

In `ClusterApiTest`, extend the streams-section fixture so a server can be serve_only and the snapshot reads do not fail: add a `serve_only int NOT NULL DEFAULT 0` column to the test `servers` table, and ensure `streams_types`, `profiles`, `streams_options`, `streams_arguments` tables exist for the streams-section test (mirror `StreamReplicaRuntimeLoaderTest`'s schema). Add:

```php
public function testServeOnlyZeroEmitsIdenticalRecord(): void {
    // The existing streams fixture with serve_only = 0: the record ETag is
    // unchanged from a run with the gate absent (directive stays other/proxy).
    // Assert kind is not 'transcode' and the record carries no 'spec'.
    $rKeys = /* existing streams-section hello handshake */;
    $rOut = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => new \stdClass()]], 1, $rKeys);
    $rDoc = $this->openRecord($rOut['streams'][0]['sealed'], 'rep');
    $this->assertNotSame('transcode', $rDoc['data']['runtime']['kind']);
    $this->assertArrayNotHasKey('spec', $rDoc['data']['runtime']);
}

public function testServeOnlyNodeStillPagesAndResetsLoader(): void {
    // Flip the served server's servers.serve_only to 1, then a full resync:
    // it must succeed (not 503), still emit the held records, and leave the
    // StreamSource loader reset afterwards.
    $this->rDb->exec('UPDATE `servers` SET `serve_only` = 1 WHERE `id` = 5');
    $rKeys = /* existing streams-section hello handshake */;
    $rOut = $this->served('streams', ['since' => 0, 'resync' => self::EVERY_STREAM + ['hashes' => new \stdClass()]], 1, $rKeys);
    $this->assertNotSame([], $rOut['streams'], 'a serve_only node still gets its records');
    $this->assertNull(StreamSource::streamRow(1, true), 'the snapshot loader was reset after the page');
}
```

(For `testServeOnlyNodeStillPagesAndResetsLoader`: with `serve_only = 1` the gate installs the loader and calls `buildSupervisorSpec` per survivor; in the minimal fixture that returns null, so the directives stay `other` — the test proves the gate + loader bracket integrate without a 503 and reset cleanly. `StreamSource::streamRow(1, true)` with no loader falls through to the DB and returns null for a missing id, proving the loader is no longer installed. Reuse the existing streams-section handshake helper for `$rKeys`; mirror an existing streams test for setup.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterApiTest`
Expected: FAIL — `page()` has no `$rUpgrade` parameter / `pageServeOnly`/`serveOnly` undefined (and the resync still 503s on a serve_only node before the snapshot tables exist).

- [ ] **Step 3: Implement the gate, the bracket, and the `page()` parameter**

Add `serveOnly()` and `pageServeOnly()`, change `page()`'s signature and insert the upgrade call before the ETag, and repoint `delta()`/`resync()` to `pageServeOnly()`, all per Interfaces. Add `use XcVm\Domain\Stream\StreamSource;` if not already added in Task 3.

- [ ] **Step 4: Run the full cluster suite to verify it passes**

Run: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterApiTest`
Expected: PASS — the two new tests and every existing streams-section test (delta/resync mechanics, cap, removals, tickets) unchanged.

- [ ] **Step 5: Gates, static analysis, and commit**

Run: `make gates && make phpstan`
Expected: both clean (confirm `check-core-cluster-refs` passes — the `StreamProcess`/`StreamSource` refs are in `StreamReplica`, not `StreamRecords`).

```bash
git add src/Domain/Cluster/StreamReplica.php tests/Unit/ClusterApiTest.php
git commit -m "feat(cluster): emit portable transcode Spec for serve-only nodes"
```

---

## Not in this plan (follow-ups)

- **Part A — serve_only provisioning** (the single-binary LB install sets `serve_only = 1` and launches `xc_node`). Needs a real LB; it is what makes this emission non-inert and provides its live validation.
- **Phase B2 — version bump on a serve_only flip.** Toggling `serve_only` on a node that already holds streams does not bump `cluster_stream_ver`, so a flip surfaces only via the ~5-min resync. Install is covered (stream assignment bumps). Required before a toggle UI.
- **Real node `features`.** `features` is `[]` here (ffmpeg only). Threading a node's live remux/GPU capability (so `buildSupervisorSpec` can emit a native-remux Spec) is a separate change — MAIN does not store that today.
- **Resync composition cost.** A resync must compose a Spec for every examined held stream (the ETag depends on the Spec), up to `MAX_EXAMINE`, every ~5 min per serve_only node. The frequent delta path is page-bound (≤`MAX_RECORDS`). Caching per-(stream, server) ETags would remove the resync cost; YAGNI until measured.
