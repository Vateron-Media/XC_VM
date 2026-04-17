# Модель обработки ошибок

В XC_VM обработка ошибок разделена на два уровня: **коды ошибок** (что пошло не так) и **обработчики** (как это отображается клиенту).

---

## Обзор архитектуры

```
Код ошибки (строка)
       │
       ▼
generateError($code)
       │
       ├─ debug_show_errors = true  →  стилизованная HTML-страница с кодом и описанием
       └─ production               →  generate404() (имитация nginx 404)
```

---

## Коды ошибок

Все коды хранятся в `src/core/Error/ErrorCodes.php` в массиве `$rErrorCodes`.

Код — это строковый ключ (`'INVALID_CREDENTIALS'`), значение — описание на английском языке.

### Полный список кодов

| Код                        | Описание                                              |
| -------------------------- | ----------------------------------------------------- |
| `API_IP_NOT_ALLOWED`       | IP не имеет доступа к API                             |
| `ARCHIVE_DOESNT_EXIST`     | Файлы архива для данного стрима отсутствуют           |
| `ASN_BLOCKED`              | ASN заблокирован                                      |
| `BANNED`                   | Линия заблокирована                                   |
| `BLOCKED_USER_AGENT`       | User-agent заблокирован                               |
| `CACHE_INCOMPLETE`         | Кеш ещё генерируется                                  |
| `DEVICE_NOT_ALLOWED`       | MAG/Enigma устройства не имеют доступа                |
| `DISABLED`                 | Линия отключена                                       |
| `DOWNLOAD_LIMIT_REACHED`   | Достигнут лимит одновременных загрузок                |
| `E2_DEVICE_LOCK_FAILED`    | Проверка блокировки устройства не пройдена            |
| `E2_DISABLED`              | Устройство отключено                                  |
| `E2_NO_TOKEN`              | Токен не указан                                       |
| `E2_TOKEN_DOESNT_MATCH`    | Токен не совпадает с записями                         |
| `E2_WATCHDOG_TIMEOUT`      | Превышен лимит времени                                |
| `EMPTY_USER_AGENT`         | Пустые user-agent запрещены                           |
| `EPG_DISABLED`             | EPG отключён                                          |
| `EPG_FILE_MISSING`         | Кешированные файлы EPG отсутствуют                    |
| `EXPIRED`                  | Линия истекла                                         |
| `FORCED_COUNTRY_INVALID`   | Страна не соответствует принудительной                |
| `GENERATE_PLAYLIST_FAILED` | Не удалось сгенерировать плейлист                     |
| `HLS_DISABLED`             | HLS отключён                                          |
| `HOSTING_DETECT`           | Обнаружен хостинг-сервер                              |
| `INVALID_API_PASSWORD`     | Неверный пароль API                                   |
| `INVALID_CREDENTIALS`      | Неверный логин или пароль                             |
| `INVALID_HOST`             | Домен не распознан                                    |
| `INVALID_STREAM_ID`        | Стрим с таким ID не существует                        |
| `INVALID_TYPE_TOKEN`       | Токен нельзя использовать для этого типа стрима       |
| `IP_BLOCKED`               | IP заблокирован                                       |
| `IP_MISMATCH`              | Текущий IP не совпадает с IP первого подключения      |
| `ISP_BLOCKED`              | ISP заблокирован                                      |
| `LB_TOKEN_INVALID`         | AES-токен не может быть расшифрован                   |
| `LEGACY_EPG_DISABLED`      | Доступ к legacy epg.php отключён                      |
| `LEGACY_GET_DISABLED`      | Доступ к legacy get.php отключён                      |
| `LEGACY_PANEL_API_DISABLED`| Доступ к legacy panel_api.php отключён               |
| `LINE_CREATE_FAIL`         | Не удалось добавить линию в базу данных               |
| `NO_CREDENTIALS`           | Учётные данные не указаны                             |
| `NO_SERVERS_AVAILABLE`     | Нет доступных серверов для этого стрима               |
| `NO_TIMESTAMP`             | Временная метка архива не указана                     |
| `NO_TOKEN_SPECIFIED`       | AES-токен не указан                                   |
| `NOT_ENIGMA_DEVICE`        | Линия не является Enigma-устройством                  |
| `NOT_IN_ALLOWED_COUNTRY`   | Страна не в списке разрешённых                        |
| `NOT_IN_ALLOWED_IPS`       | IP не в списке разрешённых                            |
| `NOT_IN_ALLOWED_UAS`       | User-agent не в списке разрешённых                    |
| `NOT_IN_BOUQUET`           | Линия не имеет доступа к этому стриму                 |
| `PLAYER_API_DISABLED`      | Player API отключён                                   |
| `PROXY_ACCESS_DENIED`      | Прямой доступ к стриму запрещён при включённом прокси |
| `PROXY_DETECT`             | Обнаружен прокси                                      |
| `PROXY_NO_API_ACCESS`      | Доступ к API через прокси запрещён                    |
| `RESTREAM_DETECT`          | Обнаружено ретрансляция                               |
| `STALKER_CHANNEL_MISMATCH` | ID стрима не совпадает со stalker-токеном             |
| `STALKER_DECRYPT_FAILED`   | Не удалось расшифровать stalker-токен                 |
| `STALKER_INVALID_KEY`      | Неверный stalker-ключ                                 |
| `STALKER_IP_MISMATCH`      | IP не совпадает со stalker-токеном                    |
| `STALKER_KEY_EXPIRED`      | Stalker-токен истёк                                   |
| `STREAM_OFFLINE`           | Стрим сейчас недоступен                               |
| `SUBTITLE_DOESNT_EXIST`    | Файл субтитров не существует                          |
| `THUMBNAIL_DOESNT_EXIST`   | Файл превью не существует                             |
| `THUMBNAILS_NOT_ENABLED`   | Превью не включено для этого стрима                   |
| `TOKEN_ERROR`              | AES-токен содержит неполные данные                    |
| `TOKEN_EXPIRED`            | AES-токен истёк                                       |
| `TS_DISABLED`              | MPEG-TS отключён                                      |
| `USER_ALREADY_CONNECTED`   | Линия уже подключена с другого IP                     |
| `USER_DISALLOW_EXT`        | Расширение не входит в список разрешённых             |
| `VOD_DOESNT_EXIST`         | VOD-файл не существует                                |
| `WAIT_TIME_EXPIRED`        | Таймаут запуска стрима истёк                          |

---

## Функции обработки

Определены в `src/core/Error/ErrorHandler.php`.

### `generateError(string $code, bool $kill = true, ?int $httpCode = null)`

Отображает ошибку клиенту.

**Логика:**

```
если debug_show_errors = true
    → HTML-страница с кодом и описанием ошибки
иначе
    если $httpCode задан  → http_response_code($httpCode) + exit
    иначе                 → generate404()
```

**Параметры:**

| Параметр   | Тип       | Умолчание | Описание                                             |
| ---------- | --------- | --------- | ---------------------------------------------------- |
| `$rError`  | `string`  | —         | Код ошибки из `$rErrorCodes`                         |
| `$rKill`   | `bool`    | `true`    | Завершить выполнение после вывода                    |
| `$rCode`   | `int\|null` | `null`  | HTTP-код ответа (если `null` — используется 404)     |

**Примеры:**

```php
// Стандартная ошибка — завершить скрипт с 404
generateError('INVALID_CREDENTIALS');

// Вернуть 403 без завершения скрипта
generateError('API_IP_NOT_ALLOWED', false, 403);

// Только показать ошибку, выполнение продолжается
generateError('STREAM_OFFLINE', false);
```

---

### `generate404(bool $kill = true)`

Отдаёт стандартную страницу `404 Not Found`, стилизованную под nginx.

```php
generate404();       // 404 + exit()
generate404(false);  // 404, продолжить выполнение
```

> Используется автоматически внутри `generateError()` в production-режиме.

---

## Режимы отображения

### Production (по умолчанию)

Клиент получает стандартный nginx 404. Реальная причина ошибки скрыта.

```
HTTP/1.1 404 Not Found
<html><head><title>404 Not Found</title></head>
<body><center><h1>404 Not Found</h1></center><hr><center>nginx</center></body>
</html>
```

### Debug-режим

Включается настройкой `debug_show_errors = true` в конфигурации.
Отображает код ошибки и его описание из `$rErrorCodes`.

> ⚠️ Никогда не включайте debug-режим на production-серверах — это раскрывает внутреннюю логику системы клиентам.

---

## Добавление нового кода ошибки

Добавьте запись в массив `$rErrorCodes` в файле `src/core/Error/ErrorCodes.php`:

```php
'MY_NEW_ERROR' => 'Human-readable description of the error.',
```

Затем используйте код там, где нужно:

```php
generateError('MY_NEW_ERROR');
```

Описание должно быть на **английском языке** — это требование существующего формата.

---

## Связанные файлы

| Файл                              | Назначение                              |
| --------------------------------- | --------------------------------------- |
| `src/core/Error/ErrorCodes.php`   | Реестр всех кодов ошибок                |
| `src/core/Error/ErrorHandler.php` | `generateError()`, `generate404()`      |
| `src/bootstrap.php`               | Загружает ErrorHandler через constants  |
| `src/www/constants.php`           | Точка подключения Error-модуля          |
