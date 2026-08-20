# Модель обработки ошибок

XC_VM обработка ошибок состоит из трех уровней:

- **Коды ошибок** -- в чем произошел сбой (централизованный реестр именованных строк ошибок)
- **Обработчики ошибок** -- как генерируется HTTP-ответ клиента (`generateError()`, `generate404()`)
- **Подсистема ведения журнала** - фиксация во время выполнения ошибок PHP, неперехваченных исключений и фатальных сбоев

---

## Обзор потока

```text
Application code
  |
  +-- generateError('CODE')        // deliberate error response
  |     -> debug mode:  styled HTML page with code + description
  |     -> production:  generate404() or explicit HTTP code
  |
  +-- PHP warning / notice / error  // runtime errors
  |     -> Logger::handleError()
  |        -> maps errno to level (ERROR, WARNING, NOTICE, INFO)
  |        -> writes base64-encoded JSON to error_log.log
  |        -> optionally displays on screen
  |
  +-- Uncaught exception            // unhandled Throwable
  |     -> Logger::handleException()
  |        -> logs as EXCEPTION with full chained trace
  |
  +-- Fatal error at shutdown       // E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR
        -> Logger::handleFatal()
           -> logs as FATAL (no stack trace available)
```

---

## Реестр кодов ошибок

Все коды объявлены в `src/Core/Error/ErrorCodes.php` как глобальный массив `$rErrorCodes`.

Формат кода:

- Ключ: строка в верхнем регистре (пример: `INVALID_CREDENTIALS`)
- Значение: понятное для человека описание на английском языке

Используйте только централизованные определения кода. Не следует жестко кодировать текст ошибки в обработчиках конечных точек.

### Полный список кодов

|Код|Описание|
| --- | --- |
| `API_IP_NOT_ALLOWED` |IP-адрес не разрешен для доступа к API.|
| `ARCHIVE_DOESNT_EXIST` |Для этого идентификатора потока отсутствуют архивные файлы.|
| `ASN_BLOCKED` |ASN был заблокирован.|
| `BANNED` |Линия была заблокирована.|
| `BLOCKED_USER_AGENT` |Пользовательский агент был заблокирован.|
| `CACHE_INCOMPLETE` |Генерируется кэш...|
| `DEVICE_NOT_ALLOWED` |Устройствам MAG и Enigma не разрешен доступ к этому файлу.|
| `DISABLED` |Линия была отключена.|
| `DOWNLOAD_LIMIT_REACHED` |Достигнут лимит одновременной загрузки.|
| `E2_DEVICE_LOCK_FAILED` |Проверка блокировки устройства не удалась.|
| `E2_DISABLED` |Устройство было отключено.|
| `E2_NO_TOKEN` |Токен не был указан.|
| `E2_TOKEN_DOESNT_MATCH` |Токен не соответствует записям.|
| `E2_WATCHDOG_TIMEOUT` |Истек лимит времени.|
| `EMPTY_USER_AGENT` |Пустые пользовательские агенты запрещены.|
| `EPG_DISABLED` |EPG был отключен.|
| `EPG_FILE_MISSING` |Кэшированные файлы EPG отсутствуют.|
| `EXPIRED` |Срок действия строки истек.|
| `FORCED_COUNTRY_INVALID` |Страна не совпадает с принудительной страной.|
| `GENERATE_PLAYLIST_FAILED` |Не удалось создать список воспроизведения.|
| `HLS_DISABLED` |HLS был отключен.|
| `HOSTING_DETECT` |Обнаружен хостинг-сервер.|
| `INVALID_API_PASSWORD` |Неверный пароль API.|
| `INVALID_CREDENTIALS` |Имя пользователя или пароль неверны.|
| `INVALID_HOST` |Доменное имя не распознано.|
| `INVALID_STREAM_ID` |Идентификатор потока не существует.|
| `INVALID_TYPE_TOKEN` |Токены не могут быть использованы для этого типа потока.|
| `IP_BLOCKED` |IP-адрес был заблокирован.|
| `IP_MISMATCH` |Текущий IP-адрес не соответствует исходному IP-адресу подключения.|
| `ISP_BLOCKED` |Провайдер был заблокирован.|
| `LB_TOKEN_INVALID` |Токен AES не может быть расшифрован.|
| `LEGACY_EPG_DISABLED` |Устаревший epg.php доступ был отключен.|
| `LEGACY_GET_DISABLED` |Устаревший get.php доступ был отключен.|
| `LEGACY_PANEL_API_DISABLED` |Устаревший panel_api.php доступ был отключен.|
| `LINE_CREATE_FAIL` |Не удалось вставить строку в базу данных.|
| `NO_CREDENTIALS` |Учетные данные не были указаны.|
| `NO_SERVERS_AVAILABLE` |В настоящее время серверы для этого потока не доступны.|
| `NO_TIMESTAMP` |Временная метка архива не указана.|
| `NO_TOKEN_SPECIFIED` |Зашифрованный токен AES не был указан.|
| `NOT_ENIGMA_DEVICE` |Линия - это не загадочное устройство.|
| `NOT_IN_ALLOWED_COUNTRY` |Нет в списке разрешенных стран.|
| `NOT_IN_ALLOWED_IPS` |Его нет в списке разрешенных IP-адресов.|
| `NOT_IN_ALLOWED_UAS` |Отсутствует в списке разрешенных пользовательских агентов.|
| `NOT_IN_BOUQUET` |У Line нет доступа к этому идентификатору потока.|
| `PLAYER_API_DISABLED` |API плеера был отключен.|
| `PROXY_ACCESS_DENIED` |Вы не можете получить прямой доступ к этому потоку, пока включен прокси-сервер.|
| `PROXY_DETECT` |Обнаружен прокси-сервер.|
| `PROXY_NO_API_ACCESS` |Не удается получить доступ к API через прокси.|
| `RESTREAM_DETECT` |Обнаружен повторный поток.|
| `STALKER_CHANNEL_MISMATCH` |Идентификатор потока не совпадает с токеном stalker.|
| `STALKER_DECRYPT_FAILED` |Не удалось расшифровать токен сталкера.|
| `STALKER_INVALID_KEY` |Недействительный ключ сталкера.|
| `STALKER_IP_MISMATCH` |IP-адрес не соответствует токену stalker.|
| `STALKER_KEY_EXPIRED` |Срок действия жетона сталкера истек.|
| `STREAM_OFFLINE` |Трансляция в данный момент отключена.|
| `SUBTITLE_DOESNT_EXIST` |Файл субтитров не существует.|
| `THUMBNAIL_DOESNT_EXIST` |Файл миниатюр не существует.|
| `THUMBNAILS_NOT_ENABLED` |Миниатюра не включена для этого потока.|
| `TOKEN_ERROR` |Токен AES содержит неполные данные.|
| `TOKEN_EXPIRED` |Срок действия токена AES истек.|
| `TS_DISABLED` |MPEG-TS был отключен.|
| `USER_ALREADY_CONNECTED` |Линия уже подключена с другого IP-адреса.|
| `USER_DISALLOW_EXT` |Добавочный номер отсутствует в списке разрешенных.|
| `VOD_DOESNT_EXIST` |VOD файл не существует.|
| `WAIT_TIME_EXPIRED` |Время начала трансляции истекло, запустить не удалось.|

Коды, относящиеся к потоковой передаче данных (`CACHE_INCOMPLETE`, `SUBTITLE_DOESNT_EXIST`, `NO_SERVERS_AVAILABLE`, `PROXY_ACCESS_DENIED`) были перенесены из `stream/init.php` в централизованный реестр.

---

## Обработчики ошибок

Определено в `src/Core/Error/ErrorHandler.php`. Это простые функции (не методы класса), загружаемые в начале начальной загрузки.

### `generateError(string $rError, bool $rKill = true, ?int $rCode = null)`

Выдает ответ об ошибке HTTP. Поведение зависит от параметра `debug_show_errors`.:

```text
if debug_show_errors === true
    render styled HTML page showing error key + description
    if $rKill -> exit()
else (production)
    if $rKill
        if $rCode is set -> http_response_code($rCode) + exit()
        else             -> generate404()
    // if !$rKill, does nothing in production mode
```

Параметры:

|Параметр|Тип|По умолчанию|Значение|
| --- | --- | --- | --- |
| `$rError` | `string` |--|Ключ от `$rErrorCodes`|
| `$rKill` | `bool` | `true` |Завершить работу скрипта после вывода|
| `$rCode` |`инт\|нулевой`| `null` |Явный код ответа HTTP (обходит 404 в рабочей среде)|

Примеры:

```php
generateError('INVALID_CREDENTIALS');              // production: 404 + exit
generateError('API_IP_NOT_ALLOWED', true, 403);    // production: 403 + exit
generateError('STREAM_OFFLINE', false);             // production: no output, no exit
```

### `generate404(bool $rKill = true)`

Возвращает страницу `404 Not Found` в стиле nginx и устанавливает HTTP 404. HTML-код содержит комментарии с дополнениями, чтобы скрыть страницы ошибок, отображаемые в браузере MSIE и Chrome.

```php
generate404();       // 404 + exit
generate404(false);  // 404, continue execution
```

---

## Подсистема регистратора

Определен в `src/Core/Logging/Logger.php`. Класс `final`, который регистрирует три глобальных обработчика PHP, чтобы фиксировать все ошибки во время выполнения и записывать их в файл.

### Инициализация

```php
Logger::init(bool $showErrors, string $logFile): void
```

Регистры:

1. `set_error_handler([Logger::class, 'handleError'])` -- PHP предупреждения, извещения, ошибки
2. `set_exception_handler([Logger::class, 'handleException'])` -- не перехвачено `Throwable`
3. `register_shutdown_function([Logger::class, 'handleFatal'])` -- неустранимые ошибки при завершении работы

Также настраивает `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED)` и устанавливает `display_errors` / `display_startup_errors` на основе `$showErrors`.

### Где вызывается функция Logger::init()

Регистратор инициализируется в двух местах, в зависимости от пути запроса:

|Путь входа|Файл|Как|
| --- | --- | --- |
|Bootstrap (все контексты)| `src/bootstrap.php` |`XC_Bootstrap::loadConstants()` звонков `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`|
|Конечные точки потоковой передачи| `src/Core/Http/RequestGuard.php` |Загружает настройки из файлового кэша, определяет `PHP_ERRORS`, затем вызывает `Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log')`|

В обоих случаях `PHP_ERRORS` отражает настройку `debug_show_errors` (по умолчанию используется `false`, когда настройки недоступны).

### Отображение уровня ошибок

`Logger::handleError()` сопоставляет PHP константы ошибок со строками уровня журнала через `mapErrorLevel()`:

|PHP константа(ы)|Уровень регистрации|
| --- | --- |
|`E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR`| `ERROR` |
|`E_WARNING`, `E_USER_WARNING`| `WARNING` |
|`E_NOTICE`, `E_USER_NOTICE`| `NOTICE` |
|Все остальные значения `errno`| `INFO` |

Обработчик завершения работы (`handleFatal()`) проверяет `error_get_last()` на наличие этих фатальных типов и регистрирует их как `FATAL`:

|PHP постоянные значения при выключении|Уровень регистрации|
| --- | --- |
|`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`| `FATAL` |

Неперехваченные исключения, зарегистрированные с помощью `handleException()`, всегда используют уровень `EXCEPTION`.

Ошибки, подавленные с помощью оператора `@`, игнорируются (обработчик проверяет `error_reporting() & $errno`).

### Формат журнала

Каждая запись в журнале записывается в виде одной строки: `base64_encode(json_encode($data))`, за которой следует новая строка. Это предотвращает искажение строк в многострочных сообщениях.

Декодированная структура JSON:

```json
{
    "type":        "WARNING",
    "log_message": "Undefined variable $foo",
    "file":        "/home/xc_vm/Domain/Stream/StreamService.php",
    "line":        142,
    "log_extra":   "#0 /home/xc_vm/...(line): function()\n#1 ...",
    "time":        1716220800,
    "env":         "fpm-fcgi"
}
```

|Поле|Содержание|
| --- | --- |
| `type` |Уровень регистрации: `ERROR`, `WARNING`, `NOTICE`, `INFO`, `EXCEPTION`, или `FATAL`|
| `log_message` |Текст сообщения об ошибке/исключении|
| `file` |Абсолютный путь к исходному файлу|
| `line` |Номер строки, в которой произошла ошибка|
| `log_extra` |Трассировка стека (форматированная строка). Пусто для неустранимых ошибок.|
| `time` |Временная метка Unix|
| `env` |PHP имя SAPI (`cli`, `fpm-fcgi`, и т.д.)|

### Расположение файла журнала

Путь по умолчанию: `LOGS_TMP_PATH . 'error_log.log'`

Если каталог журнала не существует, Logger создает его с правами доступа `0775`. При запуске от имени root (распространенного в контейнерах) файлу присваивается имя `xc_vm:xc_vm` с режимом `0664`.

### Вывод на экран

Когда `$showErrors` равно `true`, регистратор также отображает ошибки напрямую:

- **CLI:** клеммный выход с цветовой кодировкой (красный - НЕИСПРАВИМОСТЬ/ОШИБКА, желтый - ПРЕДУПРЕЖДЕНИЕ, синий - УВЕДОМЛЕНИЕ)
- **Веб:** встроенный `<div>` с моноширинным шрифтом, красной рамкой и трассировкой стека в блоке `<pre>`

---

## Конвейер ведения журнала: Передача файла в базу данных

Регистратор записывает данные в `error_log.log` на диске. Отдельная подсистема считывает этот файл и сохраняет записи в таблице базы данных `panel_logs`:

1. **Регистратор** записывает строки JSON в кодировке base64 в `error_log.log`
2. **FileLogger** (`src/Core/Logging/FileLogger.php`) предоставляет дополнительный интерфейс ведения журнала, используемый кодом приложения (ошибки PDO, EPG и т.д.), который записывает данные в тот же файл в том же формате
3. Записи заносятся в таблицу `panel_logs`
4. **Диагностический сервис** (`src/Core/Diagnostics/DiagnosticsService.php`) считывает данные из `panel_logs` для:
   - `downloadPanelLogs()` -- извлекает до 1000 последних ошибок, не связанных с EPG, затем обрезает таблицу
   - `submitPanelLogs()` -- отправляет логи на центральный сервер API для анализа
5. Панель администратора отображает эти журналы в разделе **Управление > Журналы > Ошибки панели**

### Фильтрация шума файлового регистратора

`FileLogger::log()` пропускает записи, которые соответствуют:

- Сообщения, содержащие `panel_logs` в дополнительном поле (предотвращает рекурсивное ведение журнала)
- Совпадающие сообщения `timeout exceeded`, `lock wait timeout`, или `duplicate entry` (ошибки с помехами MySQL)

---

## Другие лесорубы

Каталог `src/Core/Logging/` содержит дополнительные специализированные регистраторы:

|Класс|Файл|Цель|
| --- | --- | --- |
| `Logger` | `Logger.php` |Глобальный PHP обработчик ошибок/исключений/фатальных исходов (описанный выше)|
| `FileLogger` | `FileLogger.php` |Ведение журнала на уровне приложения (ошибки PDO, EPG и т.д.) до `error_log.log`|
| `DatabaseLogger` | `DatabaseLogger.php` |Клиентская потоковая передача событий запроса в `client_request.log` (вводится в таблицу `client_logs`)|
| `UpdateLogger` | `UpdateLogger.php` |Операции обновления системы до `MAIN_HOME/update.log` (обычный текст, а не base64)|

Все регистраторы, кроме `UpdateLogger`, реализуют `LoggerInterface` и записывают JSON в кодировке base64.

---

## Типы исключений в кодовой базе

В кодовой базе определено небольшое количество пользовательских классов исключений. Все неперехваченные исключения перехватываются командой `Logger::handleException()`, которая регистрирует всю цепочку исключений (включая `getPrevious()`).

|Класс исключений|Базовый класс|Местоположение|
| --- | --- | --- |
| `DropboxException` | `Exception` | `src/Core/Storage/DropboxClient.php` |
| `M3uParser\Exception` | `\Exception` | `src/Core/Parsing/M3uParser/src/Exception.php` |
| `DataBuildingException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Parser/DataBuildingException.php` |
| `DefinitionException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Definition/DefinitionException.php` |
| `DumpingException` | `\RuntimeException` | `src/Core/Parsing/PhpM3u8/src/Dumper/DumpingException.php` |

В большинстве прикладных программ используются общие ошибки `Exception` или используется встроенная система ошибок PHP. Обработчик исключений регистратора принимает любые `Throwable`.

---

## Отладка против производства

### Производство (по умолчанию: `debug_show_errors = false`)

- `generateError()` возвращает общую страницу 404 (или явный HTTP-код), скрывая внутреннюю причину сбоя
- Регистратор по-прежнему записывает все ошибки в `error_log.log` на диск
- `display_errors` и `display_startup_errors` имеют значение `'0'`
- Ошибки видны только через панель администратора (страница ошибок панели) или файлы журналов

### Отладка (`debug_show_errors = true`)

- `generateError()` показывает стилизованную страницу с ключом ошибки и сопоставленным описанием
- Регистратор дополнительно отображает ошибки на экране (вывод CLI с цветовой кодировкой или встроенный HTML).
- `display_errors` и `display_startup_errors` имеют значение `'1'`

Не включайте отображение отладки на рабочих узлах.

---

## Регистрация обработчика ошибок Bootstrap

Инфраструктура обработки ошибок загружается на ранней стадии загрузки:

1. `bootstrap.php` определяет `MAIN_HOME` и регистрирует Composer автозагрузчик
2. `XC_Bootstrap::loadConstants()` загружает (по порядку):
   - `Core/Error/ErrorCodes.php` -- заполняет `$rErrorCodes`
   - `Core/Error/ErrorHandler.php` -- определяет `generateError()` и `generate404()`
   - Путь и конфигурационные файлы
   - `Core/Logging/Logger.php` -- определение класса
3. @вызывается @0@@, регистрирующий три глобальных обработчика
4. С этого момента фиксируются все PHP ошибки, неперехваченные исключения и фатальные сбои

Для конечных точек потоковой передачи, которые обходят полную загрузку, `RequestGuard.php` выполняет шаги 2-3 независимо: загружает настройки из файлового кэша, определяет `PHP_ERRORS` и вызывает `Logger::init()`.

---

## Добавление нового кода ошибки

1. Добавьте новый ключ к `src/Core/Error/ErrorCodes.php`:

```php
'MY_NEW_ERROR' => 'Human-readable description.',
```

2. Используйте это в коде:

```php
generateError('MY_NEW_ERROR');
```

Описания должны быть на английском языке для приведения в соответствие с существующим реестром.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Error/ErrorCodes.php` |Централизованная карта кодов ошибок (`$rErrorCodes`)|
| `src/Core/Error/ErrorHandler.php` |@функции @0@@ и `generate404()`|
| `src/Core/Logging/Logger.php` |Глобальные обработчики ошибок, исключений и фатальных исходов PHP|
| `src/Core/Logging/LoggerInterface.php` |Интерфейс контракта ведения журнала|
| `src/Core/Logging/FileLogger.php` |Ведение журнала файлов на уровне приложения (PDO, EPG и т.д.)|
| `src/Core/Logging/DatabaseLogger.php` |Ведение журнала событий потокового запроса клиента|
| `src/Core/Logging/UpdateLogger.php` |Ведение журнала операций обновления системы|
| `src/Core/Http/RequestGuard.php` |Путь потоковой передачи: защита от наводнений, проверка хоста, запуск регистратора|
| `src/Core/Diagnostics/DiagnosticsService.php` |Считывает таблицу `panel_logs` для отображения администратором и отправки API|
| `src/bootstrap.php` |Включает уровень ошибок и регистратор во всех контекстах начальной загрузки|
