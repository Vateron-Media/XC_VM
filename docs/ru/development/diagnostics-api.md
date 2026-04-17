# Сервис диагностики

`DiagnosticsService` предоставляет утилиты для диагностики состояния системы: анализ SSL-сертификатов, проверка совместимости кодеков, работа с журналами панели и определение внешнего IP.

---

## Методы

### `getCertificateInfo(?string $certificate = null): ?array`

Возвращает информацию об SSL-сертификате.

**Параметры:**

- `$certificate` — путь к файлу сертификата. Если `null` — путь считывается автоматически из `BIN_PATH/nginx/conf/ssl.conf`

**Возвращает:**

```php
[
    'serial'     => string,   // серийный номер
    'expiration' => int,      // UNIX timestamp истечения
    'subject'    => string,   // CN/subject сертификата
    'path'       => string,   // директория сертификата
]
```

Возвращает `null` если:
- Файл сертификата не найден
- `openssl x509` завершился с ошибкой

**Пример:**

```php
$info = DiagnosticsService::getCertificateInfo();
if ($info) {
    $days = ($info['expiration'] - time()) / 86400;
    echo "Сертификат истекает через {$days} дней";
    echo "Subject: " . $info['subject'];
}
```

---

### `checkCompatibility(array|string $data, bool $allowHEVC = false): bool`

Проверяет совместимость кодеков стрима с плеером.

**Параметры:**

- `$data` — вывод FFProbe в виде массива или JSON-строки. Ожидает ключ `codecs` с подключами `audio.codec_name` и `video.codec_name`
- `$allowHEVC` — разрешить HEVC/H.265 и AC3 (по умолчанию отключено)

**Поддерживаемые кодеки:**

| Тип   | Поддерживаемые кодеки (по умолчанию)               | Дополнительно (с `$allowHEVC`) |
| ----- | --------------------------------------------------- | ------------------------------ |
| Видео | `h264`, `vp8`, `vp9`, `ogg`, `av1`                  | `hevc`, `h265`                 |
| Аудио | `aac`, `libfdk_aac`, `opus`, `vorbis`, `pcm_s16le`, `mp2`, `mp3`, `flac` | `ac3` |

**Пример:**

```php
$ffprobeData = [
    'codecs' => [
        'video' => ['codec_name' => 'h264'],
        'audio' => ['codec_name' => 'aac'],
    ]
];

if (DiagnosticsService::checkCompatibility($ffprobeData)) {
    echo 'Кодеки совместимы с плеером';
}
```

---

### `downloadPanelLogs(object $db): array`

Извлекает до 1000 последних записей из таблицы `panel_logs` (исключая тип `epg`), форматирует их и **очищает таблицу**.

**Параметры:**

- `$db` — экземпляр Database (должен иметь методы `->query()` и `->get_rows()`)

**Возвращает:**

```php
[
    'errors'  => [
        [
            'type'       => string,  // тип записи
            'message'    => string,  // текст ошибки (экранирован)
            'file'       => string,  // дополнительный контекст (экранирован)
            'line'       => int,     // номер строки
            'date'       => int,     // UNIX timestamp
            'human_date' => string,  // читаемая дата в UTC
        ],
        // ...
    ],
    'version' => string,  // текущая версия XC_VM
]
```

**Исключения:** выбрасывает `Exception` при ошибке запроса к БД.

> ⚠️ Метод **очищает** таблицу `panel_logs` после чтения. Вызывайте только при намерении скачать и сбросить логи.

---

### `submitPanelLogs(object $db): array`

Отправляет логи на удалённый сервер диагностики и **очищает таблицу**. Аналогичен `downloadPanelLogs()`, но данные передаются через HTTP.

Используется для автоматической отправки отчётов об ошибках в команду поддержки.

---

### `getApiIP(): ?string`

Определяет внешний IP-адрес сервера через запрос к публичному API.

```php
$externalIP = DiagnosticsService::getApiIP();
// Возвращает IP-строку или null при недоступности сети
```

Используется при регистрации сервера и в диагностике сетевых проблем.

---

## Структура `panel_logs`

| Столбец       | Тип      | Описание                         |
| ------------- | -------- | -------------------------------- |
| `type`        | string   | Тип события (`error`, `warning`, и т.д.) |
| `log_message` | string   | Основное сообщение               |
| `log_extra`   | string   | Дополнительный контекст (файл, трейс) |
| `line`        | int      | Номер строки кода                |
| `date`        | int      | UNIX timestamp                   |

Тип `epg` исключается из `downloadPanelLogs()` — EPG-логи имеют высокую частоту и отдельный инструмент просмотра.

---

## Связанные файлы

| Файл                                   | Назначение                         |
| -------------------------------------- | ---------------------------------- |
| `src/core/Diagnostics/DiagnosticsService.php` | Основной класс диагностики  |
| `src/core/Logging/Logger.php`          | Запись в `panel_logs`              |
| `src/core/Config/AppConfig.php`        | Константа `XC_VM_VERSION`          |
