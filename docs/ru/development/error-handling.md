# Модель обработки ошибок

Обработка ошибок в XC_VM имеет три уровня:

- **Коды ошибок** — что пошло не так (централизованный реестр именованных строк ошибок)
- **Обработчики ошибок** — как формируется HTTP-ответ клиенту (`generateError()`, `generate404()`)
- **Подсистема Logger** — runtime-перехват ошибок PHP, необработанных исключений и фатальных сбоев

---

## Обзор потока

```text
Код приложения
  |
  +-- generateError('CODE')        // намеренный ответ с ошибкой
  |     -> debug-режим: стилизованная HTML-страница с кодом + описанием
  |     -> production:  generate404() или явный HTTP-код
  |
  +-- PHP warning / notice / error  // runtime-ошибки
  |     -> Logger::handleError()
  |        -> сопоставляет errno с уровнем (ERROR, WARNING, NOTICE, INFO)
  |        -> пишет base64-encoded JSON в error_log.log
  |        -> опционально отображает на экране
  |
  +-- Необработанное исключение     // unhandled Throwable
  |     -> Logger::handleException()
  |        -> логирует как EXCEPTION с полной цепочкой trace
  |
  +-- Фатальная ошибка при shutdown // E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR
        -> Logger::handleFatal()
           -> логирует как FATAL (stack trace недоступен)
```

---

## Реестр кодов ошибок

Все коды объявляются в `src/core/Error/ErrorCodes.php` как глобальный массив `$rErrorCodes`.

Формат кода:

- Ключ: строка в верхнем регистре (например: `INVALID_CREDENTIALS`)
- Значение: человекочитаемое описание на английском

Используйте только централизованные определения кодов. Не хардкодьте тексты ошибок в обработчиках endpoint'ов.

### Полный список кодов

| Код | Описание |
| --- | --- |
| `API_IP_NOT_ALLOWED` | IP не разрешён для доступа к API. |
| `ARCHIVE_DOESNT_EXIST` | Архивные файлы отсутствуют для этого stream ID. |
| `ASN_BLOCKED` | ASN заблокирован. |
| `BANNED` | Линия забанена. |
| `BLOCKED_USER_AGENT` | User-agent заблокирован. |
| `CACHE_INCOMPLETE` | Кэш генерируется... |
| `DEVICE_NOT_ALLOWED` | Устройствам MAG и Enigma запрещён доступ. |
| `DISABLED` | Линия отключена. |
| `DOWNLOAD_LIMIT_REACHED` | Достигнут лимит одновременных загрузок. |
| `E2_DEVICE_LOCK_FAILED` | Проверки device lock не пройдены. |
| `E2_DISABLED` | Устройство отключено. |
| `E2_NO_TOKEN` | Токен не указан. |
| `E2_TOKEN_DOESNT_MATCH` | Токен не совпадает с записями. |
| `E2_WATCHDOG_TIMEOUT` | Достигнут лимит времени. |
| `EMPTY_USER_AGENT` | Пустые user-agent запрещены. |
| `EPG_DISABLED` | EPG отключён. |
| `EPG_FILE_MISSING` | Кэшированные файлы EPG отсутствуют. |
| `EXPIRED` | Срок линии истёк. |
| `FORCED_COUNTRY_INVALID` | Страна не совпадает с forced country. |
| `GENERATE_PLAYLIST_FAILED` | Не удалось сгенерировать плейлист. |
| `HLS_DISABLED` | HLS отключён. |
| `HOSTING_DETECT` | Обнаружен hosting-сервер. |
| `INVALID_API_PASSWORD` | Неверный пароль API. |
| `INVALID_CREDENTIALS` | Неверное имя пользователя или пароль. |
| `INVALID_HOST` | Имя домена не распознано. |
| `INVALID_STREAM_ID` | Stream ID не существует. |
| `INVALID_TYPE_TOKEN` | Токены нельзя использовать для этого типа потока. |
| `IP_BLOCKED` | IP заблокирован. |
| `IP_MISMATCH` | Текущий IP не совпадает с IP начального подключения. |
| `ISP_BLOCKED` | ISP заблокирован. |
| `LB_TOKEN_INVALID` | AES-токен не удаётся расшифровать. |
| `LEGACY_EPG_DISABLED` | Доступ к legacy epg.php отключён. |
| `LEGACY_GET_DISABLED` | Доступ к legacy get.php отключён. |
| `LEGACY_PANEL_API_DISABLED` | Доступ к legacy panel_api.php отключён. |
| `LINE_CREATE_FAIL` | Не удалось вставить линию в базу данных. |
| `NO_CREDENTIALS` | Учётные данные не указаны. |
| `NO_SERVERS_AVAILABLE` | Сейчас нет доступных серверов для этого потока. |
| `NO_TIMESTAMP` | Не указана временная метка архива. |
| `NO_TOKEN_SPECIFIED` | Не указан AES-зашифрованный токен. |
| `NOT_ENIGMA_DEVICE` | Линия не является устройством enigma. |
| `NOT_IN_ALLOWED_COUNTRY` | Не в списке разрешённых стран. |
| `NOT_IN_ALLOWED_IPS` | Не в списке разрешённых IP. |
| `NOT_IN_ALLOWED_UAS` | Не в списке разрешённых user-agent. |
| `NOT_IN_BOUQUET` | У линии нет доступа к этому stream ID. |
| `PLAYER_API_DISABLED` | Player API отключён. |
| `PROXY_ACCESS_DENIED` | Невозможно обратиться к потоку напрямую, пока включён proxy. |
| `PROXY_DETECT` | Обнаружен proxy. |
| `PROXY_NO_API_ACCESS` | Нельзя обращаться к API через proxy. |
| `RESTREAM_DETECT` | Обнаружен рестрим. |
| `STALKER_CHANNEL_MISMATCH` | Stream ID не совпадает со stalker-токеном. |
| `STALKER_DECRYPT_FAILED` | Не удалось расшифровать stalker-токен. |
| `STALKER_INVALID_KEY` | Неверный stalker-ключ. |
| `STALKER_IP_MISMATCH` | IP не совпадает со stalker-токеном. |
| `STALKER_KEY_EXPIRED` | Срок stalker-токена истёк. |
| `STREAM_OFFLINE` | Поток сейчас offline. |
| `SUBTITLE_DOESNT_EXIST` | Файл субтитров не существует. |
| `THUMBNAIL_DOESNT_EXIST` | Файл превью не существует. |
| `THUMBNAILS_NOT_ENABLED` | Превью не включены для этого потока. |
| `TOKEN_ERROR` | AES-токен содержит неполные данные. |
| `TOKEN_EXPIRED` | Срок AES-токена истёк. |
| `TS_DISABLED` | MPEG-TS отключён. |
| `USER_ALREADY_CONNECTED` | Линия уже подключена с другого IP. |
| `USER_DISALLOW_EXT` | Расширение не в списке разрешённых. |
| `VOD_DOESNT_EXIST` | VOD-файл не существует. |
| `WAIT_TIME_EXPIRED` | Истёк таймаут запуска потока, не удалось запустить. |

Специфичные для стриминга коды (`CACHE_INCOMPLETE`, `SUBTITLE_DOESNT_EXIST`, `NO_SERVERS_AVAILABLE`, `PROXY_ACCESS_DENIED`) были перенесены из `stream/init.php` в централизованный реестр.

---

## Обработчики ошибок

Определены в `src/core/Error/ErrorHandler.php`. Это обычные функции (а не методы класса), подключаемые рано в bootstrap.

### `generateError(string $rError, bool $rKill = true, ?int $rCode = null)`

Формирует HTTP-ответ с ошибкой. Поведение зависит от настройки `debug_show_errors`:

```text
если debug_show_errors === true
    рендерит стилизованную HTML-страницу с ключом ошибки + описанием
    если $rKill -> exit()
иначе (production)
    если $rKill
        если задан $rCode -> http_response_code($rCode) + exit()
        иначе             -> generate404()
    // если !$rKill, в production-режиме ничего не делает
```

Параметры:

| Параметр | Тип | По умолчанию | Смысл |
| --- | --- | --- | --- |
| `$rError` | `string` | -- | Ключ из `$rErrorCodes` |
| `$rKill` | `bool` | `true` | Завершить скрипт после вывода |
| `$rCode` | `int\|null` | `null` | Явный HTTP-код ответа (обходит 404 в production) |

Примеры:

```php
generateError('INVALID_CREDENTIALS');              // production: 404 + exit
generateError('API_IP_NOT_ALLOWED', true, 403);    // production: 403 + exit
generateError('STREAM_OFFLINE', false);             // production: без вывода, без exit
```

### `generate404(bool $rKill = true)`

Возвращает страницу `404 Not Found` в стиле nginx и устанавливает HTTP 404. HTML включает padding-комментарии для подавления «дружелюбных» страниц ошибок в MSIE и Chrome.

```php
generate404();       // 404 + exit
generate404(false);  // 404, выполнение продолжается
```

---

## Подсистема Logger

Определена в `src/core/Logging/Logger.php`. Класс `final`, регистрирующий три глобальных PHP-обработчика для перехвата всех runtime-ошибок и записи их в файл.

### Инициализация

```php
Logger::init(bool $showErrors, string $logFile): void
```

Регистрирует:

1. `set_error_handler([Logger::class, 'handleError'])` — PHP warning, notice, error
2. `set_exception_handler([Logger::class, 'handleException'])` — необработанные `Throwable`
3. `register_shutdown_function([Logger::class, 'handleFatal'])` — фатальные ошибки при shutdown

Также настраивает `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED)` и устанавливает `display_errors` / `display_startup_errors` на основе `$showErrors`.

### Где вызывается Logger::init()

Logger инициализируется в двух местах в зависимости от пути запроса:

| Путь входа | Файл | Как |
| --- | --- | --- |
| Bootstrap (все контексты) | `src/bootstrap.php` | `XC_Bootstrap::loadConstants()` вызывает `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')` |
| Стриминговые endpoint'ы | `src/core/Http/RequestGuard.php` | Загружает настройки из файлового кэша, определяет `PHP_ERRORS`, затем вызывает `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')` |

В обоих случаях `PHP_ERRORS` отражает настройку `debug_show_errors` (по умолчанию `false`, когда настройки недоступны).

### Сопоставление уровней ошибок

`Logger::handleError()` сопоставляет константы PHP-ошибок строкам уровней через `mapErrorLevel()`:

| Константа(ы) PHP | Уровень лога |
| --- | --- |
| `E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR` | `ERROR` |
| `E_WARNING`, `E_USER_WARNING` | `WARNING` |
| `E_NOTICE`, `E_USER_NOTICE` | `NOTICE` |
| Все другие значения `errno` | `INFO` |

Обработчик shutdown (`handleFatal()`) проверяет `error_get_last()` на эти фатальные типы и логирует их как `FATAL`:

| Константа(ы) PHP при shutdown | Уровень лога |
| --- | --- |
| `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR` | `FATAL` |

Необработанные исключения, логируемые `handleException()`, всегда используют уровень `EXCEPTION`.

Ошибки, подавленные оператором `@`, игнорируются (обработчик проверяет `error_reporting() & $errno`).

### Формат лога

Каждая запись лога пишется одной строкой: `base64_encode(json_encode($data))` с переводом строки. Это предотвращает повреждение строк из многострочных сообщений.

Декодированная JSON-структура:

```json
{
    "type":        "WARNING",
    "log_message": "Undefined variable $foo",
    "file":        "/home/xc_vm/domain/Stream/StreamService.php",
    "line":        142,
    "log_extra":   "#0 /home/xc_vm/...(line): function()\n#1 ...",
    "time":        1716220800,
    "env":         "fpm-fcgi"
}
```

| Поле | Содержимое |
| --- | --- |
| `type` | Уровень лога: `ERROR`, `WARNING`, `NOTICE`, `INFO`, `EXCEPTION` или `FATAL` |
| `log_message` | Текст сообщения об ошибке/исключении |
| `file` | Абсолютный путь к исходному файлу |
| `line` | Номер строки, где произошла ошибка |
| `log_extra` | Stack trace (форматированная строка). Пустой для фатальных ошибок. |
| `time` | Unix timestamp |
| `env` | Имя PHP SAPI (`cli`, `fpm-fcgi` и т.д.) |

### Расположение файла лога

Путь по умолчанию: `LOGS_TMP_PATH . 'error_log.log'`

Если директория лога не существует, Logger создаёт её с правами `0775`. При запуске под root (часто в контейнерах) файл chown'ится в `xc_vm:xc_vm` с режимом `0664`.

### Вывод на экран

Когда `$showErrors = true`, Logger также рендерит ошибки напрямую:

- **CLI:** цветной вывод в терминал (красный для FATAL/ERROR, жёлтый для WARNING, синий для NOTICE)
- **Web:** inline `<div>` с моноширинным шрифтом, красной рамкой и stack trace в блоке `<pre>`

---

## Конвейер логирования: файл → база данных

Logger пишет в `error_log.log` на диск. Отдельная подсистема читает этот файл и сохраняет записи в таблицу `panel_logs`:

1. **Logger** пишет base64-encoded JSON-строки в `error_log.log`
2. **FileLogger** (`src/core/Logging/FileLogger.php`) предоставляет вторичный логирующий интерфейс для кода приложения (ошибки PDO, ошибки EPG и т.д.), который пишет в тот же файл в том же формате
3. Записи импортируются в таблицу `panel_logs`
4. **DiagnosticsService** (`src/core/Diagnostics/DiagnosticsService.php`) читает из `panel_logs` для:
   - `downloadPanelLogs()` — получает до 1000 свежих не-EPG ошибок, затем truncate'ит таблицу
   - `submitPanelLogs()` — отправляет логи на центральный API-сервер для анализа
5. Админ-панель показывает эти логи в **Management > Logs > Panel Errors**

### Шумовая фильтрация FileLogger

`FileLogger::log()` пропускает записи, соответствующие:

- Сообщения, содержащие `panel_logs` в дополнительном поле (предотвращает рекурсивное логирование)
- Сообщения, совпадающие с `timeout exceeded`, `lock wait timeout` или `duplicate entry` (шумные MySQL-ошибки)

---

## Другие логгеры

Директория `src/core/Logging/` содержит дополнительные специализированные логгеры:

| Класс | Файл | Назначение |
| --- | --- | --- |
| `Logger` | `Logger.php` | Глобальный обработчик ошибок/исключений/fatal PHP (описан выше) |
| `FileLogger` | `FileLogger.php` | Логирование уровня приложения (ошибки PDO, EPG и т.д.) в `error_log.log` |
| `DatabaseLogger` | `DatabaseLogger.php` | События клиентских стриминговых запросов в `client_request.log` (импортируются в таблицу `client_logs`) |
| `UpdateLogger` | `UpdateLogger.php` | Операции обновления системы в `MAIN_HOME/update.log` (простой текст, не base64) |

Все логгеры, кроме `UpdateLogger`, реализуют `LoggerInterface` и пишут base64-encoded JSON.

---

## Типы исключений в кодовой базе

Кодовая база определяет небольшое число пользовательских классов исключений. Все необработанные исключения перехватываются `Logger::handleException()`, который логирует полную цепочку исключений (включая `getPrevious()`).

| Класс исключения | Базовый класс | Расположение |
| --- | --- | --- |
| `DropboxException` | `Exception` | `src/core/Storage/DropboxClient.php` |
| `M3uParser\Exception` | `\Exception` | `src/core/Parsing/M3uParser/src/Exception.php` |
| `DataBuildingException` | `\RuntimeException` | `src/core/Parsing/PhpM3u8/src/Parser/DataBuildingException.php` |
| `DefinitionException` | `\RuntimeException` | `src/core/Parsing/PhpM3u8/src/Definition/DefinitionException.php` |
| `DumpingException` | `\RuntimeException` | `src/core/Parsing/PhpM3u8/src/Dumper/DumpingException.php` |

Большая часть кода приложения использует выбросы обобщённого `Exception` или полагается на встроенную систему ошибок PHP. Exception-обработчик Logger принимает любой `Throwable`.

---

## Debug vs Production

### Production (по умолчанию: `debug_show_errors = false`)

- `generateError()` возвращает универсальную страницу 404 (или явный HTTP-код), скрывая внутреннюю причину сбоя
- Logger по-прежнему пишет все ошибки в `error_log.log` на диск
- `display_errors` и `display_startup_errors` устанавливаются в `'0'`
- Ошибки видны только через админ-панель (страница Panel Errors) или файлы логов

### Debug (`debug_show_errors = true`)

- `generateError()` показывает стилизованную страницу с ключом ошибки и сопоставленным описанием
- Logger дополнительно рендерит ошибки на экран (цветной CLI-вывод или inline HTML)
- `display_errors` и `display_startup_errors` устанавливаются в `'1'`

Не включайте debug-отображение на production-нодах.

---

## Регистрация обработчика ошибок в bootstrap

Инфраструктура обработки ошибок загружается рано в последовательности загрузки:

1. Выполняется `autoload.php`, определяющий `MAIN_HOME`
2. `XC_Bootstrap::loadConstants()` загружает (в порядке):
   - `core/Error/ErrorCodes.php` — заполняет `$rErrorCodes`
   - `core/Error/ErrorHandler.php` — определяет `generateError()` и `generate404()`
   - Файлы путей и конфигурации
   - `core/Logging/Logger.php` — определение класса
3. Вызывается `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`, регистрирующий три глобальных обработчика
4. С этого момента все PHP-ошибки, необработанные исключения и фатальные сбои перехватываются

Для стриминговых endpoint'ов, обходящих полный bootstrap, `RequestGuard.php` выполняет шаги 2-3 независимо: загружает настройки из файлового кэша, определяет `PHP_ERRORS` и вызывает `Logger::init()`.

---

## Добавление нового кода ошибки

1. Добавьте новый ключ в `src/core/Error/ErrorCodes.php`:

```php
'MY_NEW_ERROR' => 'Human-readable description.',
```

2. Используйте его в коде:

```php
generateError('MY_NEW_ERROR');
```

Описания должны оставаться на английском для согласованности с существующим реестром.

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/core/Error/ErrorCodes.php` | Централизованная карта кодов ошибок (`$rErrorCodes`) |
| `src/core/Error/ErrorHandler.php` | Функции `generateError()` и `generate404()` |
| `src/core/Logging/Logger.php` | Глобальные обработчики PHP-ошибок, исключений и фатальных |
| `src/core/Logging/LoggerInterface.php` | Интерфейс контракта логирования |
| `src/core/Logging/FileLogger.php` | Файловое логирование уровня приложения (PDO, EPG и т.д.) |
| `src/core/Logging/DatabaseLogger.php` | Логирование событий клиентских стриминговых запросов |
| `src/core/Logging/UpdateLogger.php` | Логирование операций обновления системы |
| `src/core/Http/RequestGuard.php` | Стриминговый путь: защита от флуда, проверка хоста, инициализация Logger |
| `src/core/Diagnostics/DiagnosticsService.php` | Читает таблицу `panel_logs` для отображения в админке и отправки в API |
| `src/bootstrap.php` | Подключает слой ошибок и Logger во всех контекстах bootstrap |
