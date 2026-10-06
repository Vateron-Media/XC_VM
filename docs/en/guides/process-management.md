# Process Management Patterns

`ProcessManager` centralizes Linux process checks, termination, PID-file checks, and cron locking.
It replaces scattered ad-hoc `posix_kill`, `ps`, and `/proc` checks.

---

## Core Operations

### Check if process is running

```php
ProcessManager::isRunning(int $pid, ?string $exe = null): bool
```

- without `$exe`: checks `/proc/{pid}` existence
- with `$exe`: validates executable name via `/proc/{pid}/exe`

### Check named process

```php
ProcessManager::isNamedProcessRunning(
    int $pid,
    string $processName,
    int|string $identifier,
    ?string $exe = null
): bool
```

Matches cmdline pattern `NAME[ID]` (for process-title-based workers).

### Check stream process

```php
ProcessManager::isStreamRunning(int $pid, int $streamId): bool
```

- `ffmpeg`: validates stream-specific output pattern in cmdline
- `xc_fanout`: only the native remuxer (`xc_fanout remux … /<id>_.m3u8`) — the daemon process
  itself shares the executable but names no stream playlist
- `php`: considered alive for stream worker context

For "is anything **watching** this stream", use `StreamProcess::isWatched($streamId, $monitorPid)`:
a supervised stream's `monitor_pid` is the xc_fanout daemon's, which `isMonitorAlive()` (an
`XC_VM[<id>]` PHP process) rightly rejects.

---

## More checks & helpers

```php
ProcessManager::isStreamAlive($pid, $streamID): bool     // loose: stream ID appears in the ffmpeg/php cmdline (case-insensitive) — no output check
ProcessManager::isMonitorAlive($pid, $streamID, $exe = null): bool  // the stream's watchdog (MonitorCommand) is alive
ProcessManager::startMonitor($streamID, $restart = 0): bool         // (re)spawn the watchdog for a stream (returns true)
ProcessManager::isNginxRunning(): bool
ProcessManager::getProcessAge($pid): int                 // seconds since the process started (from /proc mtime)
ProcessManager::findProcessPIDs(array $terms, $limit = 0): array     // pids whose cmdline matches ANY of $terms (first match wins)
ProcessManager::isAnyProcessRunning(array $terms): bool
```

`isStreamRunning()` is the **stricter** check: it confirms the ffmpeg process's cmdline
references this stream's **output files** (`{id}_.m3u8` / `{id}_%d.ts`), i.e. it is actually
producing *this* stream. `isStreamAlive()` is a looser, case-insensitive substring match of the
stream ID in the cmdline — cheaper, but it does not verify output. Use `isStreamRunning()` when
"is this stream being produced?" matters, `isStreamAlive()` for a quick "is a process for this ID
around?".

---

## Per-stream CPU and memory

```php
ProcessManager::resourceSample($pid): ?array          // ['ticks' => CPU ticks so far, 'rss' => bytes, 'at' => microtime, 'start' => ticks after boot]
ProcessManager::cpuPercent(array $now, array $prev): ?float   // percent of ONE core between two samples
ProcessManager::cpuPercentSinceStart(array $sample): ?float   // lifetime average, as `ps` shows it
ProcessManager::producerKind($pid): ?string           // 'fanout' (xc_fanout remux) | 'ffmpeg' | 'php'
```

Read straight out of `/proc/PID/stat` (fields 14/15 for CPU, 24 for RSS; the page size is derived
from this process's own `statm` vs `status`, since 64K pages are normal on arm64). CPU in `/proc`
is cumulative, so a percentage needs **two** samples: `cpuPercent()` returns `null` when the pair
says nothing — no previous reading, two readings from the same instant, or a counter that went
backwards because the producer restarted under the same stream.

`cron:streams` samples each running stream's producer once per pass and folds the result into that
stream's `progress_info` JSON (`cpu`, `mem`, `producer`). The reading the next pass subtracts from
is kept beside the stream's files, in `<streams>/<id>_.usage` (removed with the rest of `<id>_*`
when the stream stops) — node-local bookkeeping, so it does not depend on surviving a round trip
through a database row other code also rewrites. Where there is no usable previous reading (a
producer's first pass, or a new pid after a restart) the lifetime average stands in, so the column
shows a figure at once. The age for that comes from the process's own start time in
`/proc/PID/stat` against `/proc/uptime`, not from `/proc/PID`'s mtime, which is set when something
first looks at the directory and can be much later than the start.

Only the node running a stream can read its own `/proc`, so the sampling happens there and travels
to the panel in the row the cron already writes; the admin streams list renders it as the
**Resources** column (producer badge, CPU %, RAM).

---

## Process Termination

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

Use `SIGTERM` for graceful shutdown when possible.

### What `cron:streams` kills on its own

At the end of a pass `cron:streams` kills two kinds of process that no stream of this server accounts for:

- **Leftover monitors.** An `XC_VM[<id>]` process whose stream is neither among the streams the pass checked nor on demand is killed with its producer, and the stream's `<id>_*` files are removed. A monitor that is still probing its sources is not a leftover: the process is left alone while this server's row for the stream names it as `monitor_pid` and shows nothing started yet (no `pid`, `stream_status` 0). Nor is a monitor that started its stream while the pass ran: the row names it and shows a `pid` or a status, and the stream is a live stream that is not a direct source, so the next pass checks it.
- **Rogue producers** (the `kill_rogue_ffmpeg` setting). A producer writing an `<id>_.m3u8` that no running stream's pid names is killed, but only if it was already running when the pass started, judged by the start time in `/proc/PID/stat`. A producer started during a pass is judged by the next one, so an orphaned encoder can live for up to two passes. The running producer of a stream for which the pass has just started a monitor (the pid in `<id>_.pid`, else the pid of this server's row) is not a rogue: the new monitor takes it over.

---

## Cron Locking

```php
ProcessManager::acquireCronLock(string $pidFile): bool
ProcessManager::cronLockHolder(string $pidFile, bool $pinned = false)
ProcessManager::exitIfCronLockHeld(string $pidFile)
```

Behavior:

- A held lock makes the run exit with `Running...`.
- The lock file holds `pid starttime` and is held for as long as that very process lives, however long it runs.
- A lock whose process is gone, whose pid now belongs to another process, or whose process has ended and only waits to be collected by its parent, is taken at once.
- Nothing in the lock code ever ends a process. A hung cron holds its lock until it is killed (`sudo kill -9 <pid>`); the next cron minute then takes over.
- A pid-only lock written by 2.6.x is honoured for 30 minutes and swept after 10, as before.
- Once a cron has held its lock for an hour, the next run that finds it writes one Panel Logs line, `has held its cron lock since …`. It is written as the owner of the logs directory when the run is root (`SettingsAudit::asAgentUser`), and to the cron's stderr when root cannot switch to that owner.

`cron:tmp` leaves the `lock_*` file of a running cron in `tmp/crons` whatever its age. The daemons' `daemon_<name>.lock` files are still removed after ten minutes without a change, on purpose: that sweep is what frees a `flock` inherited by a crashed daemon's child.

For developers: a cron sets its lock path for removal only after the lock is taken (`CronTrait` does), and a run that exits at the lock must not unlink it.

---

## `/proc` Check Cache

`isRunning()` uses a short TTL cache for `/proc` checks (1 second) to reduce repeated I/O in tight loops.

> **Pitfall.** Because the result is cached for ~1s, a process that dies (or starts) inside that
> window still reads with its previous state — a tight loop can act on a stale "running"/"dead"
> answer. Call `ProcessManager::clearCache()` to drop the cache when you need a fresh read
> (e.g. right after killing a pid and before re-checking it).

---

## Naming Convention

Common process title format:

- `XC_VM[{id}]` — the per-stream watchdog (`MonitorCommand`, spawned by `startMonitor()`)
- `Thumbnail[{id}]` — the thumbnail generator for stream `{id}`
- `TVArchive[{id}]` — the timeshift/archive recorder for stream `{id}`

Workers set these titles with `cli_set_process_title()`; `isNamedProcessRunning()` and
`findProcessPIDs()` match against them (see the daemon list in
[CLI Tools & Console Reference](cli-tools.md)).

---

## Launching subprocesses: `Thread` and `Multithread`

`ProcessManager` inspects and kills **existing** processes. To **launch** new ones from PHP:

- `Thread` (`src/Core/Process/Thread.php`) — a thin `proc_open` wrapper around a single
  background command (start it, poll/await it, read its output).
- `Multithread` (`src/Core/Process/Multithread.php`) — runs several shell commands
  concurrently and collects each one's output; use it for fan-out work (e.g. probing many
  sources at once) rather than a manual `proc_open` loop.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Process/ProcessManager.php` | process operations |
| `src/Core/Process/Multithread.php` | multi-thread helpers |
| `src/Core/Process/Thread.php` | thread wrapper |
| `src/bootstrap.php` | CLI process context |
