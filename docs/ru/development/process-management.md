# Паттерны управления процессами

`ProcessManager` централизует операции с процессами Linux: проверку активности, завершение и захват cron-блокировок. Заменяет разрозненные вызовы `posix_kill()`, `shell_exec('ps ...')` и `file_exists('/proc/PID')`.

---

## Базовые операции

### Проверка активности процесса

```php
// Проверить, жив ли процесс
ProcessManager::isRunning(int $pid, ?string $exe = null): bool
```

- Без `$exe` — только проверяет наличие `/proc/{pid}`
- С `$exe` — дополнительно проверяет имя исполняемого файла через `/proc/{pid}/exe`

```php
// Примеры
ProcessManager::isRunning(1234);              // процесс жив?
ProcessManager::isRunning(1234, 'ffmpeg');    // это ffmpeg?
ProcessManager::isRunning(1234, PHP_BIN);     // это PHP?
```

### Именованный процесс

Для процессов, которые устанавливают заголовок через `cli_set_process_title()` в формате `NAME[ID]`:

```php
ProcessManager::isNamedProcessRunning(
    int $pid,
    string $processName,  // 'XC_VM', 'Thumbnail', 'TVArchive'
    int|string $identifier, // stream ID
    ?string $exe = null   // ожидаемый исполняемый (по умолч.: PHP_BIN)
): bool
```

```php
// Проверить, запущен ли PHP-процесс "XC_VM[42]" с PID 5678
ProcessManager::isNamedProcessRunning(5678, 'XC_VM', 42);
```

Читает `/proc/{pid}/cmdline` и сравнивает с `"NAME[ID]"`.

### Стриминговый процесс

Специализированная проверка для FFmpeg/PHP стриминговых процессов:

```php
ProcessManager::isStreamRunning(int $pid, int $streamId): bool
```

Логика:
- Если исполняемый файл — `ffmpeg`: проверяет cmdline на наличие `/{streamId}_.m3u8` или `/{streamId}_%d.ts`
- Если исполняемый файл — `php`: возвращает `true` (PHP-стримеры не имеют уникальных аргументов)

### PID-файлы

```php
// Проверить процесс по PID-файлу
ProcessManager::checkPidFile(string $pidFile, string $searchString): bool

// Проверить, содержит ли cmdline процесса заданную строку
ProcessManager::matchesCmdline(int $pid, string $search): bool
```

```php
// Пример cron-задачи
if (ProcessManager::checkPidFile('/tmp/my_cron.pid', 'my_cron_script')) {
    // процесс уже запущен
    exit(0);
}
```

---

## Завершение процессов

```php
ProcessManager::kill(int $pid, int $signal = SIGKILL): bool
```

По умолчанию — `SIGKILL` (немедленное завершение). Для мягкого завершения:

```php
ProcessManager::kill($pid, SIGTERM);  // дать время на завершение
ProcessManager::kill($pid, SIGKILL);  // принудительно
```

---

## Cron-блокировки

Предотвращает параллельный запуск одной и той же cron-задачи.

```php
ProcessManager::acquireCronLock(string $pidFile, int $maxAge = 1800): void
```

- Если PID-файл существует и процесс ещё жив — завершает текущий скрипт (`exit(0)`)
- Если PID-файл устарел (старше `$maxAge` секунд) — удаляет и создаёт новый
- Если блокировка захвачена — регистрирует `register_shutdown_function` для автоматической очистки PID-файла

```php
// Типичное использование в cron-задаче
ProcessManager::acquireCronLock('/tmp/xc_vm/cron_streams.pid', 1800);

// ... дальнейшая работа ...
// PID-файл удаляется автоматически при завершении скрипта
```

---

## Кеш проверок `/proc`

`isRunning()` кеширует результаты проверок `/proc/{pid}` на 1 секунду (`$cacheTtl = 1.0`). Это снижает нагрузку при множественных проверках одного PID в рамках одного запроса.

Для принудительного сброса кеша перед критичной проверкой:

```php
clearstatcache(true);
ProcessManager::isNamedProcessRunning($pid, 'XC_VM', $streamId);
```

---

## Именование процессов

XC_VM использует соглашение `NAME[ID]` для именования PHP-процессов:

| Имя процесса       | Описание                    |
| ------------------ | --------------------------- |
| `XC_VM[{id}]`      | Основной стриминговый процесс |
| `Thumbnail[{id}]`  | Генерация превью             |
| `TVArchive[{id}]`  | TV-архивирование             |

Эти имена устанавливаются через `cli_set_process_title()` в CLI-контексте. Подробнее о CLI-контексте — в [Контексты Bootstrap](bootstrap-contexts.md).

---

## Связанные файлы

| Файл                                   | Назначение                         |
| -------------------------------------- | ---------------------------------- |
| `src/core/Process/ProcessManager.php`  | Основной класс управления процессами |
| `src/core/Process/Multithread.php`     | Многопоточное выполнение           |
| `src/core/Process/Thread.php`          | Обёртка потока                     |
| `src/bootstrap.php`                    | `CONTEXT_CLI` устанавливает имя процесса |
