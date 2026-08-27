# Модели управления процессами

`ProcessManager` централизует проверку процессов Linux, завершение работы, проверку PID-файлов и блокировку cron.
Он заменяет разрозненные специальные проверки `posix_kill`, `ps` и `/proc`.

---

## Основные операции

### Проверьте, запущен ли процесс

```php
ProcessManager::isRunning(int $pid, ?string $exe = null): bool
```

- без `$exe`: проверяет наличие `/proc/{pid}`
- с помощью `$exe`: проверяет имя исполняемого файла с помощью `/proc/{pid}/exe`

### Проверьте именованный процесс

```php
ProcessManager::isNamedProcessRunning(
    int $pid,
    string $processName,
    int|string $identifier,
    ?string $exe = null
): bool
```

Соответствует шаблону командной строки `NAME[ID]` (для работников, основанных на названии процесса).

### Проверка потокового процесса

```php
ProcessManager::isStreamRunning(int $pid, int $streamId): bool
```

- `ffmpeg`: проверяет шаблон вывода для конкретного потока в командной строке
- `php`: считается активным для контекста stream worker

---

## Больше проверок и помощников

```php
ProcessManager::isStreamAlive($pid, $streamID): bool     // loose: stream ID appears in the ffmpeg/php cmdline (case-insensitive) — no output check
ProcessManager::isMonitorAlive($pid, $streamID, $exe = null): bool  // the stream's watchdog (MonitorCommand) is alive
ProcessManager::startMonitor($streamID, $restart = 0): bool         // (re)spawn the watchdog for a stream (returns true)
ProcessManager::isNginxRunning(): bool
ProcessManager::getProcessAge($pid): int                 // seconds since the process started (from /proc mtime)
ProcessManager::findProcessPIDs(array $terms, $limit = 0): array     // pids whose cmdline matches ANY of $terms (first match wins)
ProcessManager::isAnyProcessRunning(array $terms): bool
```

`isStreamRunning()` - это проверка **более строгий**: она подтверждает командную строку процесса ffmpeg
ссылается на этот поток **выходные файлы** (`{id}_.m3u8` / `{id}_%d.ts`), т.е. на самом деле это
создающий *этот* поток. `isStreamAlive()` - это более точное, нечувствительное к регистру соответствие подстроки в
идентификатор потока в командной строке — дешевле, но он не проверяет вывод. Используйте `isStreamRunning()`, когда
"создается ли этот поток?" имеет значение, `isStreamAlive()` для краткости "это процесс для этого идентификатора
поблизости?".

---

## Завершение процесса

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

Используйте `SIGTERM` для плавного завершения работы, когда это возможно.

---

## Блокировка Cron

```php
ProcessManager::acquireCronLock(string $pidFile, int $maxAge = 1800): bool
```

Поведение:

- активная блокировка -> завершает текущий запуск
- устаревшая блокировка (старше `$maxAge` секунд) -> удалена и заменена
- в случае успеха -> записывает текущий PID в файл блокировки и возвращает `true`

> **Крайний случай.** `acquireCronLock()` регистрирует ли **нет** обработчик завершения работы — он никогда
> автоматически снимает блокировку при выходе. Блокировка восстанавливается только в том случае, если при последующем запуске обнаруживается, что она старше
> `$maxAge`. Так что следите за тем, чтобы `$maxAge` было удобно выше реального времени выполнения задания (медленный, но живой запуск в прошлом
> `$maxAge` может быть восстановлено ошибочно), и не полагайтесь на то, что блокировка исчезнет в момент выполнения задания
> заканчивает.

---

## `/proc` Проверить кэш

`isRunning()` использует короткий TTL-кэш для `/proc` проверок (1 секунда), чтобы уменьшить количество повторных операций ввода-вывода в узких циклах.

> **Ловушка.** Поскольку результат кэшируется в течение ~1 секунды, процесс, который завершается (или запускается) внутри этого
> окно по—прежнему считывается в своем предыдущем состоянии - жесткий цикл может воздействовать на устаревшее "запущенное"/ "мертвое" окно.
> ответ. Вызовите `ProcessManager::clearCache()`, чтобы удалить кэш, когда вам понадобится новое чтение
> (например, сразу после удаления pid и перед его повторной проверкой).

---

## Соглашение об именовании

Общий формат названия процесса:

- `XC_VM[{id}]` — для каждого потока watchdog (`MonitorCommand`, порожденного `startMonitor()`)
- `Thumbnail[{id}]` — генератор миниатюр для потока `{id}`
- `TVArchive[{id}]` — timeshift/архивный рекордер для потока `{id}`

Рабочие задают эти заголовки с помощью `cli_set_process_title()`; `isNamedProcessRunning()` и
`findProcessPIDs()` соответствует им (смотрите список демонов в
[Инструменты CLI и ссылка на консоль](cli-tools.md)).

---

## Запуск подпроцессов: `Thread` и `Multithread`

`ProcessManager` проверяет и уничтожает **существующий** процессов. Для **запуск** новых процессов из PHP:

- `Thread` (`src/Core/Process/Thread.php`) — тонкая оболочка `proc_open` вокруг одного
фоновая команда (запустите ее, опросите/дождитесь ее, прочитайте ее выходные данные).
- `Multithread` (`src/Core/Process/Multithread.php`) — выполняет несколько команд оболочки.
одновременно и собирает выходные данные каждого из них; используйте его для разветвленной работы (например, для проверки многих
исходники сразу), а не ручной цикл `proc_open`.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Process/ProcessManager.php` |технологические операции|
| `src/Core/Process/Multithread.php` |многопоточные помощники|
| `src/Core/Process/Thread.php` |обертка для нитей|
| `src/bootstrap.php` |Контекст процесса CLI|
