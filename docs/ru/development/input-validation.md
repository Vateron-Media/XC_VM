# Стратегия валидации входных данных

Валидация в XC_VM сосредоточена в классе `InputValidator`. Он проверяет наличие обязательных полей перед тем, как API-обработчик начинает работу с данными.

---

## Принцип работы

`InputValidator::validate()` принимает имя действия и массив входных данных, возвращает `bool`. Если метод вернул `false` — запрос отклоняется до вызова бизнес-логики.

```php
if (!InputValidator::validate($rAction, $rData)) {
    // вернуть ошибку клиенту
}
```

---

## Справочник действий и обязательных полей

### Контент — стримы и каналы

| Действие               | Обязательные поля                                  |
| ---------------------- | -------------------------------------------------- |
| `processStream`        | `stream_display_name` (или `review`, или файл M3U) |
| `processChannel`       | `stream_display_name` (или `review`, или файл M3U) |
| `processMovie`         | `stream_display_name` (или `review`, или файл M3U) |
| `processRadio`         | `stream_display_name` (или `review`, или файл M3U) |

### Контент — серии и эпизоды

| Действие          | Обязательные поля                                         |
| ----------------- | --------------------------------------------------------- |
| `processSeries`   | `title`                                                   |
| `processEpisode`  | `series`, числовой `season_num`, и `multi` или `episode`  |

### Организация контента

| Действие            | Обязательные поля                          |
| ------------------- | ------------------------------------------ |
| `processBouquet`    | `bouquet_name`                             |
| `processGroup`      | `group_name`                               |
| `processGroupLegacy`| `group_name`                               |
| `processCategory`   | `category_name`, `category_type`           |
| `processPackage`    | `package_name`                             |
| `reorderBouquet`    | `stream_order_array` (валидный JSON-массив)|
| `setChannelOrder`   | `stream_order_array` (валидный JSON-массив)|
| `sortBouquets`      | `bouquet_order_array` (валидный JSON-массив)|
| `orderCategories`   | `categories` (валидный JSON-массив)        |
| `orderServers`      | `server_order` (валидный JSON-массив)      |

### Массовые операции

| Действие              | Обязательные поля                                    |
| --------------------- | ---------------------------------------------------- |
| `massEditStreams`      | `streams` (валидный JSON-массив)                     |
| `massEditChannels`    | `streams` (валидный JSON-массив)                     |
| `massEditMovies`      | `streams` (валидный JSON-массив)                     |
| `massEditRadios`      | `streams` (валидный JSON-массив)                     |
| `massEditEpisodes`    | `streams` (валидный JSON-массив)                     |
| `massDeleteStreams`    | `streams` (валидный JSON-массив)                     |
| `massDeleteMovies`    | `movies` (валидный JSON-массив)                      |
| `massDeleteLines`     | `lines` (валидный JSON-массив)                       |
| `massDeleteUsers`     | `users` (валидный JSON-массив)                       |
| `massDeleteStations`  | `radios` (валидный JSON-массив)                      |
| `massDeleteMags`      | `mags` (валидный JSON-массив)                        |
| `massDeleteEnigmas`   | `enigmas` (валидный JSON-массив)                     |
| `massDeleteEpisodes`  | `episodes` (валидный JSON-массив)                    |
| `massDeleteSeries`    | `series` (валидный JSON-массив)                      |
| `massEditSeries`      | `series` (валидный JSON-массив)                      |
| `massEditLines`       | `users_selected` (валидный JSON-массив)              |
| `massEditUsers`       | `users_selected` (валидный JSON-массив)              |
| `massEditMags`        | `devices_selected` (валидный JSON-массив)            |
| `massEditEnigmas`     | `devices_selected` (валидный JSON-массив)            |

### Серверы и инфраструктура

| Действие          | Обязательные поля                          |
| ----------------- | ------------------------------------------ |
| `processServer`   | `server_name`, `server_ip`                 |
| `processProxy`    | `server_name`, `server_ip`                 |
| `installServer`   | `ssh_port`, `root_password`                |
| `moveStreams`      | `content_type`, `source_server`, `replacement_server` |
| `replaceDNS`      | `old_dns`, `new_dns`                       |

### Устройства

| Действие        | Обязательные поля |
| --------------- | ----------------- |
| `processMAG`    | `mac`             |
| `processEnigma` | `mac`             |

### Безопасность

| Действие         | Обязательные поля |
| ---------------- | ----------------- |
| `blockIP`        | `ip`              |
| `processRTMPIP`  | `ip`              |
| `processISP`     | `isp`             |
| `processUA`      | `user_agent`      |

### Прочее

| Действие              | Обязательные поля                                |
| --------------------- | ------------------------------------------------ |
| `scheduleRecording`   | `title`, `source_id`                             |
| `processProvider`     | `ip`, `port`, `username`, `password`, `name`     |
| `processCode`         | `code`                                           |
| `processProfile`      | `profile_name`                                   |
| `processEPG`          | `epg_name`, `epg_file`                           |
| `processWatchFolder`  | `folder_type`, `selected_path`, `server_id`      |

### Действия без дополнительной валидации

Следующие действия всегда возвращают `true` — их валидация выполняется на уровне бизнес-логики:

`processUser`, `processLine`, `processHMAC`, `editAdminProfile`

---

## Добавление валидации для нового действия

Добавьте `case` в метод `InputValidator::validate()` в файле `src/core/Validation/InputValidator.php`:

```php
case 'myNewAction':
    return !empty($rData['required_field_one'])
        && !empty($rData['required_field_two']);
```

Правила:
- Проверяйте только **обязательные** поля — необязательные проверяются позже в сервисе
- Для JSON-массивов используйте: `is_array(json_decode($rData['field'] ?? '', true))`
- Для числовых значений: `is_numeric($rData['field'] ?? null)`

---

## Связанные файлы

| Файл                                    | Назначение                    |
| --------------------------------------- | ----------------------------- |
| `src/core/Validation/InputValidator.php`| Основной класс валидации      |
| `src/public/Controllers/`              | Контроллеры, вызывающие validate() |
