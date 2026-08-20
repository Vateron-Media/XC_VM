# Модели управления процессами

`ProcessManager` централизует проверку процессов Linux, завершение работы, проверку PID-файлов и блокировку cron.
Он заменяет разрозненные специальные проверки `posix_kill`, `ps`, и `/proc`.

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

### Проверьте потоковый процесс

```php
ProcessManager::isStreamRunning(int $pid, int $streamId): bool
```

- `ffmpeg`: проверяет шаблон вывода для конкретного потока в командной строке
- `php`: считается активным для контекста stream worker

---

## Утилиты для работы с PID-файлами

```php
ProcessManager::checkPidFile(string $pidFile, string $searchString): bool
ProcessManager::matchesCmdline(int $pid, string $search): bool
```

---

## Завершение процесса

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

Используйте `SIGTERM` для плавного завершения работы, когда это возможно.

---

## Блокировка Cron

```php
ProcessManager::acquireCronLock(string $pidFile, int $maxAge = 1800): void
```

Поведение:

- активная блокировка -> выход из текущего режима
- устаревший замок -> удален и заменен
- очистка блокировки -> регистрация с помощью обратного вызова shutdown

---

## `/proc` Проверить кэш

`isRunning()` использует короткое TTL-кэширование для проверки `/proc` (1 секунда), чтобы уменьшить количество повторных операций ввода-вывода в узких циклах.

---

## Соглашение об именовании

Общий формат названия процесса:

- `XC_VM[{id}]`
- `Thumbnail[{id}]`
- `TVArchive[{id}]`

Используется вместе с помощниками по заголовку процесса CLI.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Process/ProcessManager.php` |технологические операции|
| `src/Core/Process/Multithread.php` |многопоточные помощники|
| `src/Core/Process/Thread.php` |обертка для нитей|
| `src/bootstrap.php` |Контекст процесса CLI|
