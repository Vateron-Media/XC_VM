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
- `php`: considered alive for stream worker context

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

## Process Termination

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

Use `SIGTERM` for graceful shutdown when possible.

---

## Cron Locking

```php
ProcessManager::acquireCronLock(string $pidFile, int $maxAge = 1800): bool
```

Behavior:

- active lock -> exits the current run
- stale lock (older than `$maxAge` seconds) -> removed and replaced
- on success -> writes the current PID to the lock file and returns `true`

> **Edge case.** `acquireCronLock()` does **not** register a shutdown handler — it never
> auto-removes the lock on exit. A lock is reclaimed only when a later run finds it older than
> `$maxAge`. So keep `$maxAge` comfortably above the job's real runtime (a slow-but-live run past
> `$maxAge` could be wrongly reclaimed), and don't rely on the lock disappearing the moment a job
> finishes.

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
