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

## PID File Utilities

```php
ProcessManager::checkPidFile(string $pidFile, string $searchString): bool
ProcessManager::matchesCmdline(int $pid, string $search): bool
```

---

## Process Termination

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

Use `SIGTERM` for graceful shutdown when possible.

---

## Cron Locking

```php
ProcessManager::acquireCronLock(string $pidFile, int $maxAge = 1800): void
```

Behavior:

- active lock -> exits current run
- stale lock -> removed and replaced
- lock cleanup -> registered via shutdown callback

---

## `/proc` Check Cache

`isRunning()` uses short TTL caching for `/proc` checks (1 second) to reduce repeated I/O in tight loops.

---

## Naming Convention

Common process title format:

- `XC_VM[{id}]`
- `Thumbnail[{id}]`
- `TVArchive[{id}]`

Used together with CLI process title helpers.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Process/ProcessManager.php` | process operations |
| `src/core/Process/Multithread.php` | multi-thread helpers |
| `src/core/Process/Thread.php` | thread wrapper |
| `src/bootstrap.php` | CLI process context |
