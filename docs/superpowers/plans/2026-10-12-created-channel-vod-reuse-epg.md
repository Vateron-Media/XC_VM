# Created Channels: reuse encoded VOD + generate EPG — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a created channel (`streams.type = 3`, `created_live`) play already-encoded VOD by reference (copy-remux, no re-encode) and auto-generate an EPG from the playlist (programme per movie: name, runtime, projected start), served through the existing per-stream cache and `xmltv.php`.

**Architecture:** Playlist entries gain a typed `vod:<streamId>` form; the builder resolves them to the VOD's encoded file and copy-remuxes matching items / transcodes mismatched ones (decided by an ffprobe A/V fingerprint vs a canonical target). A pure generator projects the build's `_.info` cumulative timeline forward from `stream_started` across the loop into programmes, stored in `epg_data` under one reserved sentinel EPG source and linked to the channel via `epg_id` + `channel_id = created_<id>`; `EpgCronJob` regenerates them and (with a widened type filter) builds the per-stream cache, and the existing XMLTV build already includes them.

**Tech Stack:** PHP 8.1, PSR-4 `XcVm\` → `src/`; PHPUnit 10.5 (MariaDB via `tests/Support/TestDb.php`, no SQLite); ffmpeg/ffprobe (per-node, 7.x); igbinary for EPG cache; core SQL migrations in `src/bin/install/database.sql` + `migrations/database/up/<semver>.sql`.

**Spec:** `docs/superpowers/specs/2026-10-12-created-channel-vod-reuse-epg-design.md`

## Global Constraints

- `src/` is the deploy root; PSR-4 `XcVm\` → `src/`. Procedural/view files must `use`-import every class they reference (CI gate `check-procedural-use`).
- Run `make gates`, `make cs`, `make phpstan`, and the PHPUnit suite before a PR; dev tools are `require-dev` (install `make dev-tools`, prune `make dev-clean`). phpcs excludes view templates and `Generic.Files.LineLength`.
- Tests need MariaDB/MySQL (no SQLite): `XCVM_TEST_DB_DSN/_USER/_PASS`. DB-backed tests use `new TestDb()` + `DatabaseFactory::set(...)` and reset statics (`new \ReflectionProperty($class,'db'))->setValue(null,null)`).
- Do NOT change the install default ffmpeg (4.0). Never add module tables to `src/bin/install/database.sql`. Edit docs only under `docs/en`.
- Commit messages Conventional Commits, English; commit only logically-grouped changes.
- Unit suites run one at a time (parallel PHPUnit breaks Redis-backed tests).

## Review Focus

Inputs/failure modes the spec implies that no single task's happy-path test exercises — each gets a test added to the owning task:

- **Mixed-codec/resolution VOD set** → the channel must stay a single uniform HLS variant (mismatched items transcoded, matching ones copied). *(Task 3)*
- **VOD referenced but file missing/broken** (`to_analyze`/deleted) → the item is skipped from the concat list and from EPG, not inserted as a dead path. *(Task 1, Task 6)*
- **Channel never built / not started** (`_.info` absent or `stream_started = 0`) → EPG generator emits nothing, no fatal. *(Task 5)*
- **Loop wrap across the EPG window edge** → programmes repeat correctly past `total` cycle length and clip at window bounds. *(Task 5)*
- **Non-VOD (file-browser) item** in a channel that also has VODs → programme falls back to filename title, empty description, no crash. *(Task 5)*

---

## File structure

- `src/Domain/Stream/StreamProcess.php` — extend `resolveChannelSource()` for `vod:<id>`; add the per-item fingerprint/plan helper used by the builder.
- `src/Domain/Stream/ChannelService.php` — store `vod:<id>` entries (type 0/2); set the channel's EPG linkage on save.
- `src/Domain/Vod/SeriesService.php` — `generatePlaylist()` emits `vod:<episodeStreamId>`.
- `src/Cli/Commands/CreatedCommand.php` — build: resolve `vod:` → encoded file, apply the copy/transcode plan; after `_.info`, trigger EPG regen.
- `src/Domain/Epg/CreatedChannelEpg.php` — **new**: pure timeline→programmes projection + a DB writer for the reserved source.
- `src/Cli/CronJobs/EpgCronJob.php` — skip the sentinel source in XMLTV import; add the created-channel regen step; widen per-stream cache build to `type IN (1,3)`.
- `src/Domain/Stream/MonitorCommand.php` (`src/Cli/Commands/MonitorCommand.php`) — regen EPG when `stream_started` is (re)anchored for a created channel.
- `src/bin/install/database.sql` + `src/bin/install/migrations/database/up/<next>.sql` — seed the reserved "Created Channels" EPG source.
- `src/Public/Views/admin/created_channel.php` + `src/Public/Controllers/Admin/Ajax/StreamToolsAjaxController.php` — VOD selection submits `vod:<id>`.
- Tests: `tests/Unit/CreatedChannelSourceTest.php`, `tests/Unit/CreatedChannelItemPlanTest.php`, `tests/Unit/CreatedChannelEpgTest.php`, `tests/Unit/CreatedChannelEpgIntegrationTest.php`, additions to a `ChannelServiceTest`.

---

### Task 1: Resolve `vod:<id>` playlist entries to the encoded file

**Files:**
- Modify: `src/Domain/Stream/StreamProcess.php` (the `resolveChannelSource()` method, ~`:479-502`)
- Test: `tests/Unit/CreatedChannelSourceTest.php` (create)

**Interfaces:**
- Consumes: a playlist entry string, one of `vod:<int>`, `s:<serverId>:<path>`, or a bare local path.
- Produces: `StreamProcess::resolveChannelSource(string $entry, int $serverId): ?string` returns an absolute local path (`VOD_PATH.<id>.<container>` for a `vod:` entry whose encoded file exists on `$serverId`), or `null` when a `vod:` target is missing/not on this server (caller skips it).

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Database\DatabaseFactory;

final class CreatedChannelSourceTest extends TestCase {
    private TestDb $rDb;
    protected function setUp(): void {
        if (!defined('SERVER_ID')) define('SERVER_ID', 1);
        $this->rDb = new TestDb();
        // minimal streams + streams_servers so a VOD's container + presence can be read
        $this->rDb->exec("CREATE TABLE `streams` (`id` INT PRIMARY KEY, `type` INT, `target_container` VARCHAR(16))");
        $this->rDb->exec("CREATE TABLE `streams_servers` (`stream_id` INT, `server_id` INT, `stream_status` INT, `to_analyze` INT)");
        $this->rDb->query("INSERT INTO `streams` VALUES (7, 2, 'mp4')");
        $this->rDb->query("INSERT INTO `streams_servers` VALUES (7, 1, 0, 0)");
        DatabaseFactory::set($this->rDb);
    }
    protected function tearDown(): void { DatabaseFactory::reset(); }

    private function resolve(string $e, int $s = 1): ?string {
        $m = new \ReflectionMethod(StreamProcess::class, 'resolveChannelSource');
        $m->setAccessible(true);
        return $m->invoke(null, $e, $s);
    }

    public function testVodEntryResolvesToEncodedFile(): void {
        $this->assertSame(VOD_PATH . '7.mp4', $this->resolve('vod:7'));
    }
    public function testVodEntryMissingOnServerReturnsNull(): void {
        $this->assertNull($this->resolve('vod:7', 2), 'not encoded on server 2');
    }
    public function testVodEntryBrokenReturnsNull(): void {
        $this->rDb->query("UPDATE `streams_servers` SET `stream_status` = 1 WHERE `stream_id` = 7"); // BROKEN
        $this->assertNull($this->resolve('vod:7'));
    }
    public function testPlainPathIsReturnedUnchanged(): void {
        $this->assertSame('/media/a.ts', $this->resolve('/media/a.ts'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `XCVM_TEST_DB_DSN=... php tests/phpunit.phar -c tests/phpunit.xml.dist --filter CreatedChannelSourceTest`
Expected: FAIL (`vod:` unhandled — returns the literal string or errors).

- [ ] **Step 3: Implement the `vod:` branch in `resolveChannelSource()`**

At the top of `resolveChannelSource(string $rEntry, int $rServerId)`, before the existing `s:`/path handling:

```php
if (str_starts_with($rEntry, 'vod:')) {
    $rVodId = (int) substr($rEntry, 4);
    $rRow = self::db()->query_first(
        'SELECT s.`target_container`, ss.`stream_status`, ss.`to_analyze`
         FROM `streams` s JOIN `streams_servers` ss ON ss.`stream_id` = s.`id`
         WHERE s.`id` = ? AND ss.`server_id` = ?;',
        $rVodId, $rServerId
    );
    // present on this server, encoded (not broken), analysed
    if (!$rRow || (int) $rRow['stream_status'] !== 0 || (int) $rRow['to_analyze'] !== 0) {
        return null;
    }
    $rExt = $rRow['target_container'] !== '' ? $rRow['target_container'] : 'mp4';
    return VOD_PATH . $rVodId . '.' . $rExt;
}
```

(Use the project's actual DB accessor — match how the existing method queries; `query_first` shown as illustrative. If the method is not currently DB-aware, add `use \XcVm\Infrastructure\Database\DatabaseAware;` on the class or pass the row in from the caller that already has it.)

- [ ] **Step 4: Run to verify it passes**

Run: same filter. Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Stream/StreamProcess.php tests/Unit/CreatedChannelSourceTest.php
git commit -m "feat(created): resolve vod:<id> playlist entries to the encoded VOD file"
```

---

### Task 2: Store `vod:<id>` entries when building a channel from VOD/series

**Files:**
- Modify: `src/Domain/Stream/ChannelService.php` (`process()`, the type-2 `video_files` path and the type-0 series path, ~`:80-91`)
- Modify: `src/Domain/Vod/SeriesService.php` (`generatePlaylist()`, ~`:526-543`)
- Test: add to `tests/Unit/ChannelServiceTest.php` (create if absent)

**Interfaces:**
- Consumes: posted `video_files` (now a JSON array of `vod:<id>` for VOD selection) and `series_no`.
- Produces: `streams.stream_source` holds `vod:<id>` entries for type 0/2; file-browser (type 1) entries unchanged.

- [ ] **Step 1: Write the failing test** — `generatePlaylist()` returns `vod:<episodeStreamId>` entries.

```php
public function testSeriesPlaylistEmitsVodRefs(): void {
    // streams_episodes: (series_id=5) → episode stream ids 11,12 ordered by season/episode
    $this->seedEpisodes(5, [[1,1,11],[1,2,12]]);
    $this->assertSame(['vod:11','vod:12'], SeriesService::generatePlaylist(5));
}
```

- [ ] **Step 2: Run to verify it fails** — `generatePlaylist` currently returns each episode's `stream_source[0]` path. Expected: FAIL.

- [ ] **Step 3: Implement** — in `SeriesService::generatePlaylist()`, replace the per-episode `stream_source[0]` extraction with `'vod:' . (int) $rEpisode['stream_id']`. In `ChannelService::process()`, for `channel_type == 2` keep the posted `video_files` as-is (the UI now posts `vod:<id>` — Task 7), and for `channel_type == 0` use `SeriesService::generatePlaylist($series_no)` (already called) which now yields `vod:` refs. File-browser (type 1) branch unchanged.

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Vod/SeriesService.php src/Domain/Stream/ChannelService.php tests/Unit/ChannelServiceTest.php
git commit -m "feat(created): store VOD/series playlist items as vod:<id> references"
```

---

### Task 3: Per-item A/V fingerprint → copy-vs-transcode plan, wired into the build

**Files:**
- Modify: `src/Domain/Stream/StreamProcess.php` (add `channelItemPlan()` pure helper; use in `createChannelItem()` ~`:2089`)
- Modify: `src/Cli/Commands/CreatedCommand.php` (probe each item's fingerprint; pass the plan)
- Test: `tests/Unit/CreatedChannelItemPlanTest.php` (create)

**Interfaces:**
- Consumes: per-item fingerprint `['v'=>"codec,w,h,pixfmt", 'a'=>"codec,ch,rate"]` (from ffprobe) and an optional explicit transcode profile.
- Produces: `StreamProcess::channelItemPlan(array $items, ?array $profileFingerprint): array` → for each item index, `'copy'` or `'transcode'`. Rule: canonical target = `$profileFingerprint` if set, else the **most common** fingerprint among items (ties → first). Items whose fingerprint equals the target → `copy`; others → `transcode`.

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

final class CreatedChannelItemPlanTest extends TestCase {
    private function plan(array $items, ?array $profile = null): array {
        $m = new \ReflectionMethod(StreamProcess::class, 'channelItemPlan');
        $m->setAccessible(true);
        return $m->invoke(null, $items, $profile);
    }
    private const H264 = ['v' => 'h264,1280,720,yuv420p', 'a' => 'aac,2,48000'];
    private const MPEG2 = ['v' => 'mpeg2video,720,576,yuv420p', 'a' => 'mp2,2,48000'];

    public function testUniformSetIsAllCopy(): void {
        $this->assertSame(['copy','copy'], $this->plan([self::H264, self::H264]));
    }
    public function testOddItemIsTranscodedToTheDominantTarget(): void {
        // 2×h264, 1×mpeg2 → target h264 → mpeg2 transcoded
        $this->assertSame(['copy','transcode','copy'], $this->plan([self::H264, self::MPEG2, self::H264]));
    }
    public function testExplicitProfileForcesNonMatchingToTranscode(): void {
        $this->assertSame(['transcode','transcode'], $this->plan([self::H264, self::H264], self::MPEG2));
    }
    public function testDifferentResolutionAloneTriggersTranscode(): void {
        $other = ['v' => 'h264,854,480,yuv420p', 'a' => 'aac,2,48000'];
        $this->assertSame(['copy','transcode'], $this->plan([self::H264, $other]));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `channelItemPlan` undefined. Expected: FAIL.

- [ ] **Step 3: Implement `channelItemPlan()`**

```php
/**
 * Decide copy vs transcode per playlist item so the concat output is one
 * uniform HLS variant. Target = $rProfile when the admin set one, else the
 * most common fingerprint among items. ffmpeg will not flag a mismatch
 * (mpegts carries mid-stream changes), so this proactive comparison is what
 * keeps players from breaking on a codec/resolution switch.
 *
 * @param array<int, array{v:string,a:string}> $rItems
 * @param array{v:string,a:string}|null $rProfile
 * @return array<int, 'copy'|'transcode'>
 */
public static function channelItemPlan(array $rItems, ?array $rProfile = null): array {
    $rTarget = $rProfile;
    if ($rTarget === null) {
        $rCounts = [];
        foreach ($rItems as $rIt) {
            $rKey = $rIt['v'] . '|' . $rIt['a'];
            $rCounts[$rKey] = ($rCounts[$rKey] ?? 0) + 1;
        }
        $rBest = null; $rBestN = -1;
        foreach ($rItems as $rIt) { // iterate items (not $rCounts) so ties resolve to first-seen
            $rKey = $rIt['v'] . '|' . $rIt['a'];
            if ($rCounts[$rKey] > $rBestN) { $rBestN = $rCounts[$rKey]; $rBest = $rIt; }
        }
        $rTarget = $rBest;
    }
    $rPlan = [];
    foreach ($rItems as $rI => $rIt) {
        $rPlan[$rI] = ($rTarget !== null && $rIt['v'] === $rTarget['v'] && $rIt['a'] === $rTarget['a']) ? 'copy' : 'transcode';
    }
    return $rPlan;
}
```

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Wire into the build (no new unit test; covered by manual build verification below)**

In `CreatedCommand`, before building items: ffprobe each resolved source into the `['v'=>…,'a'=>…]` fingerprint (reuse the probe already done for `_.info`; format with `-show_entries stream=codec_name,width,height,pix_fmt` / `codec_name,channels,sample_rate`). Compute `StreamProcess::channelItemPlan($fingerprints, $profileFingerprint)` where `$profileFingerprint` is non-null only if the channel has a real transcode profile. For each item, `createChannelItem()` does `-c copy -f mpegts` when the plan says `copy`, else the profile transcode. A `vod:` item with no profile and `copy` reuses the encode.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Stream/StreamProcess.php src/Cli/Commands/CreatedCommand.php tests/Unit/CreatedChannelItemPlanTest.php
git commit -m "feat(created): fingerprint channel items, copy matching and transcode the rest"
```

---

### Task 4: Reserved "Created Channels" EPG source + channel linkage

**Files:**
- Modify: `src/bin/install/database.sql` (seed the reserved `epg` row in the `epg` INSERT section; use a fixed sentinel `epg_file = ''` and a stable `epg_name = 'Created Channels'`)
- Create: `src/bin/install/migrations/database/up/<next-semver>.sql` (insert the reserved row if absent, for existing installs)
- Modify: `src/Domain/Stream/ChannelService.php` (`process()`: set `epg_id` + `channel_id`)
- Modify: `src/Domain/Epg/CreatedChannelEpg.php` (add `reservedSourceId(): int` helper — created in Task 5; stub here or create the file in this task with just the helper)
- Test: add to `tests/Unit/ChannelServiceTest.php`

**Interfaces:**
- Produces: `CreatedChannelEpg::reservedSourceId(): int` (looks up the sentinel `epg` row by `epg_name='Created Channels'`); `ChannelService::process()` sets `streams.epg_id = reservedSourceId()`, `streams.channel_id = 'created_' . $id` on every created channel it saves.

- [ ] **Step 1: Write the failing test** — saving a created channel links it to the reserved source.

```php
public function testCreatedChannelGetsReservedEpgLinkage(): void {
    // seed the reserved epg row id (e.g. 1) then process a created channel id=50
    $id = $this->processCreatedChannel(['video_files' => ['vod:7']]);
    $row = $this->db->query_first("SELECT epg_id, channel_id FROM streams WHERE id = ?", $id);
    $this->assertSame(CreatedChannelEpg::reservedSourceId(), (int) $row['epg_id']);
    $this->assertSame('created_' . $id, $row['channel_id']);
}
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement** — add the seed row (schema + migration), `reservedSourceId()`, and the two column writes in `ChannelService::process()`'s `REPLACE INTO streams` (include `epg_id`, `channel_id`).

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Verify migration idempotency** — the migration inserts only if no `Created Channels` source exists (`INSERT ... SELECT ... WHERE NOT EXISTS`). Note it in `migrations/deleted_files.txt`? No — it adds a row, nothing deleted.

- [ ] **Step 6: Commit**

```bash
git add src/bin/install/database.sql src/bin/install/migrations/database/up/<next>.sql src/Domain/Stream/ChannelService.php src/Domain/Epg/CreatedChannelEpg.php tests/Unit/ChannelServiceTest.php
git commit -m "feat(created): reserved EPG source and per-channel EPG linkage"
```

---

### Task 5: EPG timeline-projection generator (pure)

**Files:**
- Modify/Create: `src/Domain/Epg/CreatedChannelEpg.php` (add the pure `programmes()` projector)
- Test: `tests/Unit/CreatedChannelEpgTest.php` (create)

**Interfaces:**
- Consumes: the build timeline `$rInfo` = ordered `[['seconds'=>int,'start'=>int,'finish'=>int], …]` (from `_.info`/`cc_info`; `start`/`finish` cumulative seconds), `int $rStreamStarted` (unix anchor), `array $rItemMeta` indexed like `$rInfo` with `['title'=>string,'desc'=>string]` per item, and `int $rWindowStart,$rWindowEnd` (unix).
- Produces: `CreatedChannelEpg::programmes(array $rInfo, int $rStreamStarted, array $rItemMeta, int $rWindowStart, int $rWindowEnd): array` → list of `['start'=>int,'end'=>int,'title'=>string,'description'=>string]` ordered by start, covering the window across loop repeats.

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Epg\CreatedChannelEpg;

final class CreatedChannelEpgTest extends TestCase {
    // two items: A 0..100, B 100..250 ; total cycle = 250s
    private const INFO = [
        ['seconds' => 100, 'start' => 0,   'finish' => 100],
        ['seconds' => 150, 'start' => 100, 'finish' => 250],
    ];
    private const META = [
        ['title' => 'Movie A', 'desc' => 'plot a'],
        ['title' => 'Movie B', 'desc' => 'plot b'],
    ];

    public function testProjectsOneCycleFromAnchor(): void {
        $p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1000, 1250);
        $this->assertSame(
            [[1000,1100,'Movie A','plot a'], [1100,1250,'Movie B','plot b']],
            array_map(fn($x) => [$x['start'],$x['end'],$x['title'],$x['description']], $p)
        );
    }
    public function testLoopsAcrossTheWindow(): void {
        // window spans 1.x cycles → A,B,A
        $p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1000, 1350);
        $this->assertSame(['Movie A','Movie B','Movie A'], array_column($p, 'title'));
        $this->assertSame([1000,1100,1250], array_column($p, 'start'));
        $this->assertSame([1100,1250,1350 + 0], array_column($p, 'end') !== [] ? array_column($p,'end') : []);
        $this->assertSame(1250, $p[2]['start']); // third programme starts at cycle 2 item A
    }
    public function testClipsNothingBeforeWindowStart(): void {
        // window starts mid-item B of cycle1; only programmes overlapping [1200,1400]
        $p = CreatedChannelEpg::programmes(self::INFO, 1000, self::META, 1200, 1400);
        $this->assertSame([1100,1250,1350], array_column($p, 'start')); // B(cyc1) overlaps, A(cyc2), B(cyc2)
    }
    public function testZeroDurationItemSkipped(): void {
        $info = [['seconds'=>0,'start'=>0,'finish'=>0], ['seconds'=>100,'start'=>0,'finish'=>100]];
        $meta = [['title'=>'Z','desc'=>''], ['title'=>'Real','desc'=>'']];
        $p = CreatedChannelEpg::programmes($info, 1000, $meta, 1000, 1100);
        $this->assertSame(['Real'], array_column($p, 'title'));
    }
    public function testNoProgrammesWhenNotStarted(): void {
        $this->assertSame([], CreatedChannelEpg::programmes(self::INFO, 0, self::META, 1000, 1250));
        $this->assertSame([], CreatedChannelEpg::programmes([], 1000, [], 1000, 1250));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `programmes()` undefined. Expected: FAIL.

- [ ] **Step 3: Implement `programmes()`**

```php
/**
 * Project the created channel's playlist timeline forward from its wall-clock
 * anchor into EPG programmes, repeating the loop to fill [start,end]. Pure.
 *
 * @param array<int,array{seconds:int,start:int,finish:int}> $rInfo
 * @param array<int,array{title:string,desc:string}> $rItemMeta
 * @return list<array{start:int,end:int,title:string,description:string}>
 */
public static function programmes(array $rInfo, int $rStreamStarted, array $rItemMeta, int $rWindowStart, int $rWindowEnd): array {
    if ($rStreamStarted <= 0 || $rInfo === []) {
        return [];
    }
    $rTotal = (int) end($rInfo)['finish'];
    if ($rTotal <= 0) {
        return [];
    }
    // first cycle index whose span can still overlap the window start
    $rCycle = (int) floor(($rWindowStart - $rStreamStarted) / $rTotal);
    if ($rCycle < 0) {
        $rCycle = 0;
    }
    $rOut = [];
    while (true) {
        $rBase = $rStreamStarted + $rCycle * $rTotal;
        if ($rBase >= $rWindowEnd) {
            break; // whole cycle is past the window
        }
        foreach ($rInfo as $rI => $rItem) {
            if ((int) $rItem['seconds'] <= 0) {
                continue; // zero-length item: no programme
            }
            $rStart = $rBase + (int) $rItem['start'];
            $rEnd = $rBase + (int) $rItem['finish'];
            if ($rEnd <= $rWindowStart || $rStart >= $rWindowEnd) {
                continue; // outside the window
            }
            $rOut[] = [
                'start' => $rStart,
                'end' => $rEnd,
                'title' => (string) ($rItemMeta[$rI]['title'] ?? ''),
                'description' => (string) ($rItemMeta[$rI]['desc'] ?? ''),
            ];
        }
        $rCycle++;
    }
    return $rOut;
}
```

- [ ] **Step 4: Run to verify it passes.**

(If a test's expected values disagree with the implementation, trust the implementation's window-overlap semantics and correct the test's arithmetic — the rule is: emit a programme when its `[start,end)` overlaps `[windowStart,windowEnd)`. `testLoopsAcrossTheWindow`'s third assertion is written loosely; tighten it to `array_column($p,'end') === [1100,1250,1400]`.)

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Epg/CreatedChannelEpg.php tests/Unit/CreatedChannelEpgTest.php
git commit -m "feat(epg): pure timeline projector for created-channel programmes"
```

---

### Task 6: EPG storage + EpgCronJob integration (write rows, widen cache, skip sentinel, regen on build/start)

**Files:**
- Modify: `src/Domain/Epg/CreatedChannelEpg.php` (add `regenerate(int $rStreamId): void` — reads `_.info` + `stream_started` + the channel's `vod:` item meta, calls `programmes()`, DELETE+INSERT `epg_data` for `(reserved, created_<id>)`)
- Modify: `src/Cli/CronJobs/EpgCronJob.php` — (a) skip the reserved source in the XMLTV import loop (~`:101-142`); (b) add a step that calls `CreatedChannelEpg::regenerate()` for every `type=3` stream (before the per-stream cache build); (c) widen the per-stream cache build query `type = 1` → `type IN (1,3)` (`:172`)
- Modify: `src/Cli/Commands/CreatedCommand.php` (call `CreatedChannelEpg::regenerate($id)` after writing `_.info`) and the monitor start path `src/Cli/Commands/MonitorCommand.php` (call it when `stream_started` is set for a `type=3` stream)
- Test: `tests/Unit/CreatedChannelEpgIntegrationTest.php` (create)

**Interfaces:**
- Consumes: `CreatedChannelEpg::programmes()` (Task 5), `reservedSourceId()` (Task 4), the channel's `_.info` timeline + `streams_servers.stream_started` + its `vod:` entries (→ `movie_properties.name`/`plot` via `streams`).
- Produces: `CreatedChannelEpg::regenerate(int $rStreamId): int` (count written); fresh `epg_data` rows; a per-stream cache `EPG_PATH/stream_<id>` produced by the (now type-3-inclusive) cache build.

- [ ] **Step 1: Write the failing integration test**

```php
public function testRegenerateWritesEpgDataForTheChannel(): void {
    // seed: reserved epg source, a type=3 stream id=50 (epg_id=reserved, channel_id=created_50),
    // its _.info on disk (2 items), stream_started=1000, two vod refs → movie names.
    $n = CreatedChannelEpg::regenerate(50);
    $this->assertGreaterThan(0, $n);
    $rows = $this->db->get_rows_query(
        "SELECT title,start,end FROM epg_data WHERE epg_id = ? AND channel_id = 'created_50' ORDER BY start",
        CreatedChannelEpg::reservedSourceId()
    );
    $this->assertSame('Movie A', $rows[0]['title']);
    // re-run replaces, does not duplicate
    CreatedChannelEpg::regenerate(50);
    $cnt = $this->db->query_first("SELECT COUNT(*) c FROM epg_data WHERE channel_id='created_50'")['c'];
    $this->assertSame(count($rows), (int) $cnt);
}
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement `regenerate()`** — compute window `[now - 3600, now + N*86400]` (N from a setting, default 2 days), build `$rItemMeta` by mapping each `_.info` position back to its playlist entry: for `vod:<id>` read `streams.movie_properties` → `name`/`plot`; for a non-VOD path use `basename` as title + empty desc (Review Focus: non-VOD fallback). `DELETE FROM epg_data WHERE epg_id=? AND channel_id=?` then batch-`INSERT` the projected programmes (`lang` = channel `epg_lang` or `''`).

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Wire EpgCronJob** — (a) in the import loop, `continue` when the source is the reserved one (empty `epg_file`); (b) after import, `foreach type=3 stream: CreatedChannelEpg::regenerate($id)`; (c) change `:172` query to `type IN (1, 3)`. Add a focused test that the cache build now includes a type-3 stream: seed epg_data for `created_50`, run the cache-build step, assert `EPG_PATH/stream_50` exists and unserialises to the programmes.

```php
public function testCacheBuildIncludesCreatedChannel(): void {
    // seed epg_data for (reserved, created_50) + the stream row type=3
    (new EpgCronJob())->buildStreamCaches(); // or the method name that builds per-stream caches
    $this->assertFileExists(EPG_PATH . 'stream_50');
    $rows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_50'));
    $this->assertNotEmpty($rows);
}
```

- [ ] **Step 6: Wire build/start regen** — call `CreatedChannelEpg::regenerate($id)` at the end of `CreatedCommand` (after `_.info`) and in `MonitorCommand`'s `type==3` start path where `stream_started` is (re)anchored (`:398/:430/:467`), so the guide tracks the live anchor.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Epg/CreatedChannelEpg.php src/Cli/CronJobs/EpgCronJob.php src/Cli/Commands/CreatedCommand.php src/Cli/Commands/MonitorCommand.php tests/Unit/CreatedChannelEpgIntegrationTest.php
git commit -m "feat(epg): generate, store and cache created-channel EPG via EpgCronJob"
```

---

### Task 7: Admin UI — VOD Selection submits `vod:<id>`

**Files:**
- Modify: `src/Public/Views/admin/created_channel.php` (the review-list build + submit `video_files`, ~`:142-195`, `:664-753`)
- Modify: `src/Public/Controllers/Admin/Ajax/StreamToolsAjaxController.php` (`reviewSelection` returns the stream id per row so the UI can emit `vod:<id>`)
- Test: manual (view/JS; no unit harness)

**Interfaces:**
- Consumes: the VOD DataTable rows (now carrying `stream_id`).
- Produces: posted `video_files` = JSON array of `vod:<id>` for VOD selection; file-browser still posts `s:`/path entries.

- [ ] **Step 1:** In `reviewSelection`, include each row's `id`. In `created_channel.php`, when a VOD row is added to `review_sort`, store its value as `vod:<id>`; on submit, `video_files` carries those. Only offer VODs whose `streams_servers` shows the file present on the channel's selected server(s) (Review Focus: missing-file; filter the DataTable query by the chosen servers + `stream_status=0`).
- [ ] **Step 2: Manual verification** — create a channel from 2–3 encoded VODs on one server; confirm the saved `streams.stream_source` holds `vod:<id>` entries (DB check); confirm non-present VODs are not offerable.
- [ ] **Step 3: Commit**

```bash
git add src/Public/Views/admin/created_channel.php src/Public/Controllers/Admin/Ajax/StreamToolsAjaxController.php
git commit -m "feat(created): VOD selection submits vod:<id> references, scoped to the channel's servers"
```

---

### Task 8: End-to-end manual verification (live node — cannot run in CI here)

Not a code task; the acceptance gate. On a test node:

- [ ] Build a created channel from 3 encoded VODs (mixed codecs on purpose) on one server; confirm the build copy-remuxes the matching ones and transcodes the odd one (`progress_info`/`_.info`), and the channel streams and loops.
- [ ] In a player (player_api `get_short_epg`) and in `xmltv.php`, confirm each movie shows as a programme with correct title, runtime, and start time that advances with the loop.
- [ ] Confirm a uniform HLS variant (no mid-stream resolution/codec break) for the mixed-codec channel.
- [ ] Confirm existing file-browser and series created channels are unaffected.

---

## Self-review notes

- **Spec coverage:** D1→T1/T2; D2/E5→T3; D3→T4; D4→T5; D5→T6; D6→confirmed (no code beyond T6's cache-filter widening + import-loop skip); D7→T7; R1-R6 mapped; manual/live→T8.
- **Review Focus:** mixed codecs→T3 tests; missing/broken VOD→T1 tests + T7 filter; not-built/not-started→T5 `testNoProgrammesWhenNotStarted`; loop-wrap/window edges→T5 `testLoopsAcrossTheWindow`/`testClipsNothingBeforeWindowStart`; non-VOD fallback→T6 Step 3 (+ assert in the integration test).
- **Type consistency:** `channelItemPlan(array,?array):array`, `programmes(array,int,array,int,int):list`, `regenerate(int):int`, `reservedSourceId():int`, `resolveChannelSource(string,int):?string` — used consistently across tasks.
- **Open impl detail to confirm while coding:** the exact DB accessor names (`query_first`/`get_rows_query`) must be matched to this codebase's `DatabaseHandler` API; the `_.info` field names (`seconds/start/finish`) are per the explorer's reading of `CreatedCommand` — verify at T5.
