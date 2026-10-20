# Stop needing PHP crons on a serve-only LB Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the node-local log-drain crons and `cron:tmp` into the Go cluster agent, and gate the node's crontab so those rows are dropped while the agent owns them — so a serve-only LB stops running them via `console.php cron:*`.

**Architecture:** The agent gains a once-a-minute "node chores" loop that runs each drain and the tmp sweep, each guarded by the same per-cron `CronTrait` lock PHP uses (single consumer in any window). The drains emit the identical `log.*` events on the `p1` event lane that `LogSink::spool` emits — MAIN's `EventIngest` is unchanged. The agent writes a `node_crons` liveness marker each tick; the node's own crontab generation (`ReplicaApply::crontabText`, re-validated each minute by `cron:root_signals`) omits the ported rows only while that marker is fresh, restoring them if the agent dies.

**Tech Stack:** Go (stdlib + in-repo only, `GOWORK=off`), `internal/clusteragent` + `internal/agentcmd`; PHP 8.1 (`src/Core/Cluster`, `tests/Unit`).

**Spec:** `docs/superpowers/specs/2026-10-20-lb-stop-php-crons-design.md`

## Global Constraints

- Go stdlib + in-repo packages only; **zero new external deps**. `GOWORK=off` for every go command.
- **Base:** XC_VM_Fanout `feat/lb-transcode-serve` **with SP1 PR #98 merged** (the tmp sweep reuses SP1's `Agent.FloodDir` / the `FLOOD_TMP_PATH` dir). XC_VM `2.8.0`.
- Faithful to each cron's PHP behaviour and to `LogSink`/`EventIngest`'s event protocol; MAIN ingest unchanged. The drains are single-owner (shared lock); the gate is node-side + liveness-gated.
- **`maxmind` is ported too** (user: do not defer): it joins the ported/dropped set. The dropped-cron set is exactly `streams_logs, lines_logs, activity, errors, tmp, maxmind`.
- **Path fact:** the drains read `LOGS_TMP_PATH = <home>/tmp/logs/`, **not** the agent's existing `-logs` (`<home>/logs/tmp/`, the ffmpeg `{logs}` path). A new `-tmp` root flag (default `/home/xc_vm/tmp/`) supplies `TMP_PATH`; `LOGS_TMP_PATH`/`CRONS_TMP_PATH`/`FLOOD_TMP_PATH`/`SIGNALS_TMP_PATH`/`DIVERGENCE_TMP_PATH`/`MINISTRA_TMP_PATH` all derive as `<tmp>/<sub>/`.
- Conventional Commits, English, **no Claude attribution** (no generated-by line, no Claude co-author). The user merges PRs.
- Whole-repo green: `GOWORK=off go test ./...` + `go vet ./...` (Fanout); `php tests/phpunit.phar -c tests/phpunit.xml.dist` (XC_VM).

## Review Focus

- **Both owners draining one file during the cutover window** (agent starts/stops while a PHP cron row is still scheduled for ≤1 min): the shared `CronTrait` lock must make exactly one consume per pass — never a torn/double `.import` rename or a corrupted `.rest` rewrite. → Task 2 (lock) + Task 5 (activity).
- **A crashed/hung/old agent** keeps `state=active`: the node-side gate must key on the `node_crons` marker's *freshness*, not on a stored flag, so a stale marker restores the PHP crons within a minute. → Task 9.
- **A redaction PHP would apply but the Go port misses** (a secret reaching `p1`): the three regexes + `***` mask + "every string column" must match PHP byte-for-byte. → Task 1.
- **`cron:tmp` sweeping a live file**: a fresh Gate-3 `block_<ip>`/counter under `FLOOD_TMP_PATH`, a pinned cron lock, a `ministra_*` file, a KEEP-listed stamp, or an in-flight `.update.tar.gz` must survive; a genuinely stale temp file must go. → Task 7.
- **A drain crash between read and consume**: re-drains (dup tolerated, as PHP's at-least-once), never silently drops a file's contents; the `activity`/`lines` rename-claim must not lose the claimed batch. → Tasks 3–6.

---

### Task 1: PHP-faithful `Redactor` port (`redact` of a log row)

**Files:**
- Create: `internal/clusteragent/logredact.go`
- Test: `internal/clusteragent/logredact_test.go`

**Interfaces:**
- Produces: `func redactLog(s string) string` (ports `Redactor::redact`); `func redactLogRow(row map[string]any) map[string]any` (ports `Redactor::redactRow`: for each value that is a Go `string`, replace with `redactLog(value)`; non-strings and keys untouched).
- Not a use of `internal/redact` (that is a URL-only masker with a different mask — confirmed unsuitable).

Pinned PHP contract (`src/Core/Cluster/Redactor.php`, MASK `***`), three regexes applied in order, empty string short-circuits:
```
1. /\b(password|passwd|pass|token|username|user)=([^&\s"'\\]*)/i   → $1=***
2. #\b([a-z][a-z0-9+.\-]*://)[^/\s:@"']+:[^/\s@"']*@#i             → $1***@
3. #/(live|movie|series|timeshift)/[^/\s?"']+/[^/\s?"']+/#i        → /$1/***/***/
```

- [ ] **Step 1: Write the failing test** (`logredact_test.go`) — assert exact outputs:

```go
func TestRedactLog(t *testing.T) {
	cases := map[string]string{
		"user=alice&password=secret&x=1":            "user=***&password=***&x=1",
		"rtsp://bob:pw@host/live":                    "rtsp://***@host/live",
		"http://h/live/alice/secret/1.ts":            "http://h/live/***/***/1.ts",
		"nothing to mask":                            "nothing to mask",
		"":                                           "",
		`token=ab"cd`:                                `token=***"cd`, // value ends at the quote
	}
	for in, want := range cases {
		if got := redactLog(in); got != want {
			t.Fatalf("redactLog(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRedactLogRowStringsOnly(t *testing.T) {
	row := map[string]any{"q": "pass=x", "n": int64(7)}
	out := redactLogRow(row)
	if out["q"] != "pass=***" || out["n"] != int64(7) {
		t.Fatalf("row = %+v", out)
	}
}
```

- [ ] **Step 2: Run to verify it fails** — `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./internal/clusteragent/ -run 'TestRedactLog' -v` → FAIL (undefined).
- [ ] **Step 3: Implement** `redactLog`/`redactLogRow` in `logredact.go` using `regexp` with the three patterns verbatim (Go `regexp` is RE2 — `\b`, `[^...]`, case-insensitive `(?i)` are supported; verify each pattern compiles and matches the PHP cases; the `$1` backrefs become `$1` in `ReplaceAllString`).
- [ ] **Step 4: Run to verify it passes** — same command → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): port PHP Redactor for node log drains"`

---

### Task 2: `CronTrait`-compatible per-cron lock

**Files:**
- Create: `internal/clusteragent/cronlock.go`
- Test: `internal/clusteragent/cronlock_test.go`

**Interfaces:**
- Produces:
  - `func cronLockPath(cronsDir, phpClass string) string` — `filepath.Join(cronsDir, "lock_"+md5hex(phpClass))` (ports `ProcessManager::cronLockPath`; `phpClass` is the FQCN string, e.g. `XcVm\Cli\CronJobs\StreamsLogsCronJob`).
  - `func acquireCronLock(path string) (release func(), ok bool)` — `ok=false` when a live holder holds it (caller skips this pass); on success writes the lock and returns a `release` that unlinks it.
  - `func cronLockHeld(path string) bool` — ports `ProcessManager::cronLockHolder` (pinned, true=held by a live process).
  - `func procStartTicks(pid int) (int64, bool)` — field 22 of `/proc/<pid>/stat` (ticks since boot, USER_HZ 100); for `self`, `os.Getpid()`.

Pinned PHP lock contract:
- Content: ASCII `"<pid>"` **or** `"<pid> <starttime_ticks>"`, single space, **no newline**, not JSON. The Go lock writes the two-token form `"<pid> <starttime>"`.
- Holder check: read file → `pid = atoi(firstToken)`; if `pid<=0` or process absent (`/proc/<pid>` missing / `kill(pid,0)` ESRCH) → not held. If one token (pid-only legacy): held iff file mtime age `< 1800s`. If two tokens: read `/proc/<pid>/stat`; zombie (`state 'Z'`) → not held; `startTicks == secondToken` → held, else not held; unreadable stat → held (trust by pid).

- [ ] **Step 1: Write the failing test** (`cronlock_test.go`):

```go
func TestCronLockPath(t *testing.T) {
	p := cronLockPath("/t/crons/", `XcVm\Cli\CronJobs\StreamsLogsCronJob`)
	if filepath.Base(p) != "lock_"+md5hex(`XcVm\Cli\CronJobs\StreamsLogsCronJob`) {
		t.Fatalf("path = %s", p)
	}
}

func TestCronLockAcquireExcludes(t *testing.T) {
	dir := t.TempDir()
	p := cronLockPath(dir, "X")
	rel, ok := acquireCronLock(p)
	if !ok { t.Fatal("first acquire must succeed") }
	if _, ok2 := acquireCronLock(p); ok2 { t.Fatal("second acquire while held must fail") }
	rel()
	if _, ok3 := acquireCronLock(p); !ok3 { t.Fatal("acquire after release must succeed") }
}

func TestCronLockStaleDeadPidReclaimed(t *testing.T) {
	dir := t.TempDir()
	p := cronLockPath(dir, "X")
	// a dead pid with a start token -> not held -> reclaimable
	os.WriteFile(p, []byte("2147480000 123"), 0o640)
	if cronLockHeld(p) { t.Fatal("a dead pid must not be held") }
	if _, ok := acquireCronLock(p); !ok { t.Fatal("must reclaim a dead-pid lock") }
}

func TestCronLockContentFormat(t *testing.T) {
	dir := t.TempDir()
	p := cronLockPath(dir, "X")
	rel, _ := acquireCronLock(p); defer rel()
	b, _ := os.ReadFile(p)
	parts := strings.Fields(string(b))
	if len(parts) != 2 || parts[0] != strconv.Itoa(os.Getpid()) || strings.Contains(string(b), "\n") {
		t.Fatalf("lock content = %q (want \"<pid> <start>\", no newline)", b)
	}
}
```

- [ ] **Step 2: Run to verify it fails** — `-run 'TestCronLock'` → FAIL (undefined).
- [ ] **Step 3: Implement** in `cronlock.go`: `md5hex` via `crypto/md5`; `procStartTicks` parses `/proc/<pid>/stat` (the field after the last `)` is field 3 `state`; field 22 `starttime` is index 19 of the post-`)` split — split on the substring after the final `)` to survive a `comm` containing spaces/parens); liveness via `syscall.Kill(pid, 0)` (ESRCH → dead); acquire via `os.OpenFile(O_WRONLY|O_CREATE|O_EXCL)` after a not-held check, falling back to overwrite-if-not-held (match PHP: it writes unconditionally once free). `release` unlinks.
- [ ] **Step 4: Run to verify it passes** — → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): a CronTrait-compatible per-cron lock"`

---

### Task 3: `spoolP1` + the `streams_logs` drain

**Files:**
- Modify: `internal/clusteragent/registry.go` (add `spoolP1`, sibling of `spoolP0`)
- Create: `internal/clusteragent/crondrain.go` (shared drain scaffolding + the stream drain)
- Test: `internal/clusteragent/crondrain_test.go`

**Interfaces:**
- Consumes: `redactLogRow` (Task 1); `acquireCronLock`/`cronLockPath` (Task 2); `a.SpoolDir`, `a.Client.State.ServerID`.
- Produces:
  - `func spoolP1(dir string, events []map[string]any) error` — identical to `spoolP0` (registry.go:567) but writes `filepath.Join(dir,"p1")`; `dir` is `a.SpoolDir`.
  - `func logEvents(kind string, cols []string, rows []map[string]any) []map[string]any` — ports `LogSink::spool`'s batching: for each row keep only keys in `cols` (order = `cols`), `redactLogRow` it, size = Σ(`len(string)` or `8`); emit a `{"type":"log."+kind,"d":{"rows":[...]}}` event when the running batch reaches **CHUNK=1000** rows or adding the next row would exceed **SPOOL_BYTES=1048576** value-bytes; flush the tail.
  - **`Agent.TmpRoot string`** (new config field, default populated in Task 8) and the derived-dir helpers used by every drain and the sweep: `logsTmpDir()`, `cronsDir()`, `floodDir()`, `signalsTmpDir()`, `divergenceTmpDir()`, `ministraTmpDir()` — each `filepath.Join(a.TmpRoot, "<sub>")` (`logs`,`crons`,`flood`,`signals`,`divergence`,`ministra`; confirm the sub-names against `ConstantsInitializer`).
  - `func (a *Agent) drainStreamLogs() error` — the `cron:streams_logs` port; reads `logsTmpDir()+"/stream_log.log"`, locks via `cronLockPath(a.cronsDir(), "XcVm\\Cli\\CronJobs\\StreamsLogsCronJob")`.

Pinned: event shape `{"type":"log.<kind>","d":{"rows":[{col:val,…}]}}` on lane **p1**; `t` stamp added by `spoolP1` (UnixMilli). `stream` columns = `["stream_id","server_id","action","source","date"]`, table `streams_logs`. File `<LOGS_TMP_PATH>/stream_log.log`, each line `base64→json`, row `{stream_id:int, server_id:ServerID, action:string, source:string, date:string(=line.time)}`, skip when `!stream_id`. **Consume = read + `unlink` unconditionally** (even on write failure), under the `StreamsLogsCronJob` lock.

- [ ] **Step 1: Write the failing test** (`crondrain_test.go`): write a `stream_log.log` with two base64(json) lines under a temp LOGS dir; run `drainStreamLogs`; assert (a) the file is gone, (b) exactly one `log.stream` NDJSON file exists under `<SpoolDir>/p1/` whose single event's `d.rows` are the two expected rows (server_id = the rig's ServerID, redaction applied to `source`), (c) a 1001-row input produces two events (CHUNK split). Add a `logEvents` unit test for the SPOOL_BYTES boundary (a row whose value-bytes cross 1 MiB starts a new event).
- [ ] **Step 2: Run to verify it fails** → FAIL (undefined).
- [ ] **Step 3: Implement** `spoolP1` (copy `spoolP0`, `"p0"`→`"p1"`), `logEvents`, the `Agent.TmpRoot` field + dir helpers, and `drainStreamLogs` (acquire the lock → read+parse+build rows → `spoolP1(a.SpoolDir, logEvents("stream", streamCols, rows))` → `unlink` → release). The test sets `a.TmpRoot`/`a.SpoolDir`/`a.Client.State.ServerID` directly; Task 8 adds the flag that populates `TmpRoot` in production.
- [ ] **Step 4: Run to verify it passes** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): spoolP1 + the streams_logs drain"`

---

### Task 4: the `lines_logs` drain (rename-claim)

**Files:** Modify `internal/clusteragent/crondrain.go`; Test `internal/clusteragent/crondrain_test.go`.

**Interfaces:**
- Consumes: `logEvents`, `spoolP1`, the lock (Tasks 2–3).
- Produces: `func (a *Agent) drainLinesLogs() error`.

Pinned (`LinesLogsCronJob`): file `<LOGS_TMP_PATH>/client_request.log`; **rename-claim** — process a pre-existing `client_request.log.import` first, then `rename(log, log+".import")` and process it; batch by **IMPORT_BATCH=1000** rows or **IMPORT_BYTES=4194304** raw bytes; `unlink` the claimed file unconditionally at the end. Per line `base64→json`, skip non-objects. Remap the spool keys `["stream_id","user_id","action","query_string","user_agent","user_ip","extra_data","time"]` into the `client` columns `["stream_id","user_id","client_status","query_string","user_agent","ip","extra_data","date"]` (i.e. action→client_status, user_ip→ip, time→date), **every value `(string)`-cast, missing→`""`**. Emit `log.client` via `logEvents`.

- [ ] **Step 1: Write the failing test**: a `client_request.log` with N lines → `drainLinesLogs` → a pre-existing `.import` is drained first; after, both files gone; `log.client` events carry the remapped+stringified rows. Assert the key remap (a line with `user_ip` lands under `ip`).
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `drainLinesLogs` under the `LinesLogsCronJob` lock, mirroring the rename-claim + IMPORT caps.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the lines_logs drain"`

---

### Task 5: the `activity` drain (rename-claim + keep-on-refusal)

**Files:** Modify `crondrain.go`; Test `crondrain_test.go`.

**Interfaces:**
- Produces: `func (a *Agent) drainActivity() error`.

Pinned (`ActivityCronJob`): file `<LOGS_TMP_PATH>/activity`; rename-claim to `activity.import` (if an `.import` already exists and a parse of it is *refused*, return WITHOUT re-claiming — never overwrite it); validate each decoded row — keep only `is-object && server_id && (user_id || hmac_id) && stream_id && user_ip`; batch by IMPORT_BATCH/IMPORT_BYTES; on a refused batch, rewrite the file to the refused batch + remainder via an `activity.rest` temp then rename, and return (file NOT unlinked); on full success `unlink`. Rows spool as `log.activity` with the `activity` columns (intersect in `logEvents`). The `last_activity` update is MAIN-side on ingest — the drain does not replicate it.

- [ ] **Step 1: Write the failing test**: a valid batch drains and unlinks; an invalid row is dropped by the validation; a refused batch leaves the file rewritten to batch+remainder (simulate refusal via a seam) and does NOT unlink; a pre-existing refused `.import` is not overwritten.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `drainActivity` under the `ActivityCronJob` lock with the keep-on-refusal rewrite.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the activity drain"`

---

### Task 6: the `errors` drain (two sources, `stream_logs_save` gate, dedup)

**Files:** Modify `crondrain.go`; Test `crondrain_test.go`.

**Interfaces:**
- Consumes: `a.replicaSettings()` (`stream_logs_save`); `a.node.streams` (STREAMS_PATH — add an accessor if needed).
- Produces: `func (a *Agent) drainErrors() error`.

Pinned (`ErrorsCronJob`), two sources:
1. **Stream errors** — scan `STREAMS_PATH` for files with extension `errors` whose basename (stream id) `> 0`; split content on `\r\n|\r|\n`, trim, skip the ignore-list entries; **`unlink` each `.errors` file unconditionally** (consumed whether or not saving is on); collect a row `{stream_id:int, server_id:ServerID, date:int(now), error:string}` **only when `stream_logs_save` is set**; after the scan, if saving on and rows non-empty → `log.stream_error` (columns `["stream_id","server_id","date","error"]`, table `streams_errors`).
2. **Panel errors** — file `<LOGS_TMP_PATH>/error_log.log`, each line `base64→json`; support legacy `log_*` and current `message`/`extra` keys; skip transient-mysql messages; **dedup by `md5(server_id.type.message.extra.file.line)`** into the `unique` column; build the 10 `panel_logs` columns (null when empty); `log.panel_error`; **`unlink` only on a successful spool** (the one drain whose file survives a failed write).

- [ ] **Step 1: Write the failing test**: with `stream_logs_save` off, `.errors` files are still unlinked but no `log.stream_error` is spooled; with it on, rows are spooled; two identical panel-error lines dedup to one row (same `unique`); the panel file is kept if the spool fails (simulate via a seam) and removed on success.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `drainErrors` under the `ErrorsCronJob` lock.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the errors drain (stream + panel)"`

---

### Task 7: the `tmp` sweep

**Files:** Create `internal/clusteragent/crontmp.go`; Test `internal/clusteragent/crontmp_test.go`.

**Interfaces:**
- Consumes: `a.TmpRoot` + the dir helpers (Task 3); `a.replicaSettings()` (`cache_playlists`); `cronLockHeld` (Task 2).
- Produces: `Agent.PlaylistDir string` (new config field, flag-populated in Task 8; the test sets it directly); `func (a *Agent) sweepTmp() error`; `func tmpMaxAge(name string) int64`; `func keepsCronLock(path string) bool`.

Pinned (`TmpCronJob`): sweep dirs = `[TMP, CRONS, DIVERGENCE, FLOOD, MINISTRA, SIGNALS, LOGS]` (all `<tmp>/<sub>/`, `TMP`=the root). One level, **files only**. Delete a file when `now - mtime >= maxAge(basename)` **and** basename does not contain `ministra_` **and** not a live lock. `maxAge` default **600**, overrides: `fanout_binary_check|xcvm_core_check|ytdlp_check|ffmpeg_check|replica_servers_checked` → `172800`, `.update.tar.gz` → `86400`. Live-lock rule (`keepsCronLock`): basename starts `lock_` **and** `cronLockHeld(path)` (pinned holder). Then prune `PLAYLIST_PATH`: delete files whose age `>= cache_playlists` seconds.

- [ ] **Step 1: Write the failing test**: in a temp tmp-root, a 601s-old plain file is swept; a 599s-old one is kept; a `ministra_x` file of any age is kept; a `fanout_binary_check` < 172800s is kept; a `lock_<md5>` with a live (self-pid) holder is kept, a stale one is swept; a **fresh** `FLOOD/block_1.2.3.4` (mtime now) is kept and a >600s-old one is swept; a playlist file older than `cache_playlists` is pruned.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `sweepTmp`/`tmpMaxAge`/`keepsCronLock` per the predicate.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the tmp sweep"`

---

### Task 8: the chores loop, the `node_crons` marker, and the flags

**Files:**
- Modify: `internal/clusteragent/agent.go` (the chores goroutine + the marker write; a `node.streams` accessor for Task 6)
- Modify: `internal/agentcmd/agentcmd.go` (new `-tmp`, `-playlist` flags → `TmpRoot`/`PlaylistDir`)
- Test: `internal/clusteragent/crondrain_test.go` (marker + loop wiring)

**Interfaces:**
- Consumes: `Agent.TmpRoot` (Task 3), `Agent.PlaylistDir` (Task 7), `drainStreamLogs`/`drainLinesLogs`/`drainActivity`/`drainErrors` (Tasks 3–6), `sweepTmp` (Task 7).
- Produces:
  - `func (a *Agent) RunChores(ctx context.Context)` — a `time.NewTicker(time.Minute)` loop (model on the Registry-save ticker, agent.go:636) that runs the four drains + `sweepTmp`, each error logged not fatal, then calls `touchNodeCrons`. Launched in `Run()` gated on `TmpRoot != ""`, on its own `context.WithCancel(ctx)`.
  - `func (a *Agent) touchNodeCrons()` — writes `<statedir>/node_crons` every tick (random-temp `writeFile{perm:0o640,noSync:true}`; for an unchanged tick, `os.Chtimes` to bump mtime, à la `publish()` agent.go:430). This marker's freshness is the node-side gate's signal (Task 9 reads it).
  - the two flags `fs.String("tmp","/home/xc_vm/tmp/",…)` → `TmpRoot`, `fs.String("playlist",<default>,…)` → `PlaylistDir`, wired into the `&clusteragent.Agent{…}` literal; a `node.streams` accessor.

Gate `RunChores` launch on `TmpRoot != ""`, on its own `context.WithCancel(ctx)`, near the Registry block in `Run()`.

- [ ] **Step 1: Write the failing test**: construct an agent with a temp `TmpRoot`/`SpoolDir`/state dir; call one `RunChores` tick directly (extract the tick body into a helper the test calls) with log files present; assert the files drained to `p1` AND the `node_crons` marker exists with a fresh mtime. A second tick with no input still refreshes the marker mtime.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `RunChores` + its launch in `Run()`, `touchNodeCrons`, the `node.streams` accessor, and the two flags wired into the `&clusteragent.Agent{…}` literal (confirm the `PLAYLIST_PATH` default from `ConstantsInitializer`). The dir helpers + `TmpRoot`/`PlaylistDir` fields already exist (Tasks 3, 7).
- [ ] **Step 4: Run → PASS**, then the whole package: `GOWORK=off go test ./internal/clusteragent/`.
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the node-chores loop and the node_crons liveness marker"`

---

### Task 9: the `maxmind` GeoLite2 update

**Files:** Create `internal/clusteragent/cronmaxmind.go`; Modify `internal/clusteragent/agent.go` (add the `updateMaxmind` call to `RunChores`); Test `internal/clusteragent/cronmaxmind_test.go`.

**Interfaces:**
- Consumes: `a.replicaSettings()` (`maxmind_account_id`, `maxmind_editions` JSON array) + `a.secret("maxmind_license_key")`; `mainHome` (→ `bin/maxmind/`); the lock (`XcVm\Cli\CronJobs\MaxMindCronJob`).
- Produces: `func (a *Agent) updateMaxmind() error`, called each `RunChores` tick (self-gated — see cadence).

Pinned (`MaxMindCronJob` + `MaxMindUpdater`/`GeoLiteReleaseUpdater`):
- **Target** `MAXMIND_DIR = /home/xc_vm/bin/maxmind/` (`filepath.Join(mainHome,"bin/maxmind")`), `version.json` alongside.
- **Cadence / self-heal:** PHP runs weekly (`0 4 * * 2`) and internally gates on `date('N')==='2'` OR `GeoLite2-Country.mmdb` missing. Since `RunChores` ticks each minute, `updateMaxmind` self-gates so it does not hit the API every minute: run only when `bin/maxmind/GeoLite2-Country.mmdb` is **missing** OR older than **6 days** (a faithful "weekly, self-heal if missing"); else no-op. (Builder may instead gate on `date.Weekday()==Tuesday` + a once-per-day marker — settle during implementation; the missing→run self-heal is mandatory.)
- **Creds path** (all three present): per edition in `maxmind_editions`, GET `https://download.maxmind.com/geoip/databases/<edition>/download?suffix=tar.gz` with HTTP Basic auth `account_id:license_key`, `If-Modified-Since` = dest mtime (unless forced); **304 → skip**, non-200 → error; the body is a `.tar.gz` (stdlib `compress/gzip`+`archive/tar`) whose `*.mmdb` entry is extracted to `<MAXMIND_DIR>/<edition>.mmdb` atomically; update `version.json`.
- **No creds → GitHub fallback:** download `GeoLite2-City.mmdb` + `GeoLite2-Country.mmdb` (+ `GeoIP2-ISP.mmdb` when configured) from the release assets, mirroring `GeoLiteReleaseUpdater`; pin the exact asset URLs (`GitHubReleases::assetUrl`) during implementation.
- Outbound HTTPS from the agent uses `net/http` (the agent is not PHP-FPM; cURL-only applies to PHP). The ASN catalog sync is MAIN-only — skip.

- [ ] **Step 1: Write the failing test** (`cronmaxmind_test.go`): stand up an `httptest.NewServer` returning a small valid `.tar.gz` containing `GeoLite2-Country.mmdb`; point the updater's base URL + `MAXMIND_DIR` at it/the temp dir via a seam; assert (a) a missing primary → downloads + extracts the `.mmdb`; (b) a present, <6-day-old primary → no-op (no request); (c) a `304` → skip without rewriting. Keep it hermetic (no real MaxMind/GitHub calls).
- [ ] **Step 2: Run to verify it fails** — `GOWORK=off go test ./internal/clusteragent/ -run TestMaxmind -v` → FAIL.
- [ ] **Step 3: Implement** `updateMaxmind` (creds vs GitHub path, conditional GET, tar.gz extract, version.json, self-heal gate) and add `a.updateMaxmind()` to the `RunChores` tick (error logged, not fatal), under the `MaxMindCronJob` lock.
- [ ] **Step 4: Run to verify it passes** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): the maxmind GeoLite2 update"`

---

### Task 10: the node-side crontab filter (XC_VM)

**Files:**
- Modify: `src/Core/Cluster/ReplicaApply.php` (`crontabText`/`cronJobs` — drop the ported rows while the marker is fresh; one-place dropped-set + marker path + freshness constant)
- Test: `tests/Unit/ReplicaApplyCronFilterTest.php`

**Interfaces:**
- Consumes: `AgentPaths` (the `node_crons` marker path, `cluster/node_crons`), the `node_crons` marker the Go agent writes (Task 8).
- Produces: a `const DROPPED_WHEN_AGENT_OWNS = ['streams_logs','lines_logs','activity','errors','tmp','maxmind']` (one place) and a `agentOwnsNodeCrons(): bool` helper (the marker exists and its mtime age `< NODE_CRONS_FRESH_SEC`, a new constant ≈ `30`).

Pinned: `crontabText` builds lines `<time> <PHP_BIN> <MAIN_HOME>console.php cron:<filename> # XC_VM` from `cronJobs()`. The filter drops a job when `in_array($job['filename'], DROPPED_WHEN_AGENT_OWNS, true) && agentOwnsNodeCrons()`. The marker lives under `CONFIG_PATH.'cluster/'` (not swept by `cron:tmp`). `cron:root_signals` already re-validates the installed crontab each minute and regenerates on mismatch, so toggling the marker converges within ≤1 min with no extra wiring. Leave `crontabData` (the central replica builder) unchanged — it has no per-node liveness knowledge.

- [ ] **Step 1: Write the failing test** (`ReplicaApplyCronFilterTest.php`): with a fresh `node_crons` marker, `crontabText` over a job list containing the six ported crons + a non-ported one (`certbot`) omits exactly the six and keeps `certbot`; with the marker absent or its mtime older than `NODE_CRONS_FRESH_SEC`, all rows are present. (Point `AgentPaths`/the marker path at a temp dir via the test's existing config seam, or inject the path.)
- [ ] **Step 2: Run to verify it fails** — `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ReplicaApplyCronFilterTest` → FAIL.
- [ ] **Step 3: Implement** the `DROPPED_WHEN_AGENT_OWNS` const, `agentOwnsNodeCrons()`, and the filter in `crontabText` (skip a dropped job when the agent owns the crons).
- [ ] **Step 4: Run to verify it passes** → PASS; then `make gates` + `make cs` (short-tag/PSR-4 gates).
- [ ] **Step 5: Commit** — `git commit -m "feat(cluster): drop agent-owned node crons from the node crontab"`

---

### Task 11: whole-suite green + vet (both repos)

**Files:** none (verification).

- [ ] **Step 1:** `cd /root/work/XC_VM_Fanout && GOWORK=off go test ./...` → all `ok`.
- [ ] **Step 2:** `GOWORK=off go build ./... && GOWORK=off go vet ./...` → clean.
- [ ] **Step 3:** XC_VM: `php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ReplicaApplyCronFilterTest` green, `make gates` green. (The full XC_VM suite runs once before the PR per the standing rule.)

No commit; fix under the owning task if anything fails.
