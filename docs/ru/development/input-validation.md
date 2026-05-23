# Валидация и санитизация ввода

XC_VM использует двухуровневую защиту входящих данных запроса. Сначала **глобальная санитизация** удаляет опасный контент из всех PHP-суперглобалов во время bootstrap, до того как заработает прикладной код. Затем **валидация на уровне действий** проверяет наличие обязательных полей перед выполнением бизнес-логики.

Оба слоя реализованы в `src/core/Validation/InputValidator.php`.

---

## Поток глобальной санитизации

Санитизация запускается автоматически при bootstrap. Когда вызывается `LegacyInitializer::initCore()` (в `src/core/Init/LegacyInitializer.php`), он выполняет следующие шаги до любого контроллера или сервиса:

```
LegacyInitializer::initCore()
  |
  +-- InputValidator::cleanGlobals($_GET)
  +-- InputValidator::cleanGlobals($_POST)
  +-- InputValidator::cleanGlobals($_SESSION)
  +-- InputValidator::cleanGlobals($_COOKIE)
  |
  +-- $input = InputValidator::parseIncomingRecursively($_GET)
  +-- RequestManager::set(InputValidator::parseIncomingRecursively($_POST, $input))
```

После этой последовательности все сырые суперглобалы санитизированы in-place, а объединённые/очищенные данные GET+POST доступны через `RequestManager`.

Стриминговый контекст (`LegacyInitializer::initStreaming()`) выполняет ту же последовательность санитизации, используя класс `Request`, который предоставляет эквивалентные методы для bootstrap-пути стриминга.

### cleanGlobals(&$rData, $rIteration = 0)

Рекурсивно обходит заданный массив суперглобала и удаляет опасный контент. Применяется к `$_GET`, `$_POST`, `$_SESSION` и `$_COOKIE`.

Удаляет следующее из каждого скалярного значения:

| Угроза | Удаляемый паттерн | Замена |
| --- | --- | --- |
| Null byte injection | `\0` (chr 0) | удаляется |
| Path traversal | `../` | `&#46;&#46;/` (HTML-encoded) |
| RTL override (UI spoofing) | `&#8238;` | удаляется |

Рекурсия ограничена 10 уровнями для предотвращения исчерпания стека при глубоко вложенном вводе.

### parseIncomingRecursively(&$rData, $rInput, $rIteration = 0)

Рекурсивно обходит данные GET и POST, применяя санитизацию ключей и значений к каждому листу. Для массивов рекурсирует до 20 уровней. Для скалярных значений применяет `parseCleanKey()` к ключу и `parseCleanValue()` к значению.

Объединённый результат (сначала GET, затем POST поверх него) сохраняется в `RequestManager` для использования в течение жизненного цикла запроса.

### parseCleanKey($rKey)

Санитизирует ключи массивов для предотвращения инъекций через имена ключей:

1. URL-декодирует и HTML-экранирует ключ (`htmlspecialchars(urldecode(...))`)
2. Удаляет последовательности двойных точек (`..` -> `''`)
3. Удаляет маркеры в стиле `__dunder__` через regex
4. Валидирует по разрешённому набору символов: словесные символы, точки, дефисы, подчёркивания

### parseCleanValue($rValue)

Санитизирует скалярные значения в несколько проходов:

| Шаг | Что делает |
| --- | --- |
| Unescape | `stripslashes()` и восстановление `&#032;` в пробел |
| Нормализация переводов строк | Конвертирует `\r\n`, `\n\r`, `\r` в `\n` |
| Защита HTML-комментариев | `<!--` становится `&#60;&#33;--`, `-->` становится `--&#62;` |
| Нейтрализация script-тегов | `<script` (регистронезависимо) становится `&#60;script` |
| Нормализация сущностей | Исправление двойного-кодирования и некорректных числовых сущностей |
| Trim | Удаление ведущих/завершающих пробелов |

---

## Валидация на уровне действий

### validate()

```php
InputValidator::validate(string $rAction, array $rData): bool
```

Проверяет наличие минимально требуемых полей для заданного действия. Возвращает `true`, если данные приемлемы, и `false`, если обязательные поля отсутствуют или некорректны. Контроллеры должны вызывать её перед передачей данных в слой сервисов/репозиториев.

```php
if (!InputValidator::validate($action, $data)) {
    // отклонить с ошибкой валидации
}
```

### validateOrFail()

```php
InputValidator::validateOrFail(string $rAction, array $rData): ?array
```

Удобная обёртка над `validate()`. Возвращает `null`, если данные валидны, или массив с ошибкой, если валидация не прошла:

```php
$error = InputValidator::validateOrFail($action, $data);
if ($error !== null) {
    // $error = ['status' => STATUS_INVALID_INPUT, 'data' => $data]
    return $error;
}
```

### confirmIDs($ids)

```php
InputValidator::confirmIDs(array $ids): array
```

Фильтрует массив, оставляя только положительные целочисленные ID. Любое значение, где `intval($id) <= 0`, отбрасывается. Используется широко по кодовой базе (30+ мест вызова) везде, где пользовательские списки ID должны быть санитизированы перед запросами к базе.

```php
$safeIds = InputValidator::confirmIDs($userSuppliedIds);
// [1, 42, 7] — отрицательные, ноль и нечисловые значения удалены
```

---

## Справочник действий валидации

Метод `validate()` использует `switch` по имени действия. Действия сгруппированы по функциональным областям ниже.

### Управление контентом

#### Потоки и каналы

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processStream` | `stream_display_name` ИЛИ флаг `review` ИЛИ `$_FILES['m3u_file']` | Любое из трёх удовлетворяет валидации |
| `processChannel` | `stream_display_name` ИЛИ флаг `review` ИЛИ `$_FILES['m3u_file']` | Те же правила, что у processStream |
| `processRadio` | `stream_display_name` ИЛИ флаг `review` ИЛИ `$_FILES['m3u_file']` | Те же правила, что у processStream |

#### Movies / VOD

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processMovie` | `stream_display_name` ИЛИ флаг `review` ИЛИ `$_FILES['m3u_file']` | Те же правила, что у processStream |

#### Сериалы и эпизоды

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processSeries` | `title` | Имя сериала обязательно |
| `processEpisode` | `series` (непустое) И `season_num` (numeric) И (флаг `multi` ИЛИ `episode` numeric) | Сложная мульти-путевая валидация |

### Организация

#### Букеты

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processBouquet` | `bouquet_name` | Обязательный скаляр |
| `reorderBouquet` | `stream_order_array` | Должен декодироваться в JSON-массив |
| `sortBouquets` | `bouquet_order_array` | Должен декодироваться в JSON-массив |

#### Категории

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processCategory` | `category_name`, `category_type` | Оба обязательны |
| `orderCategories` | `categories` | Должен декодироваться в JSON-массив |

#### Группы и коды

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processGroup` | `group_name` | Обязательный скаляр |
| `processGroupLegacy` | `group_name` | То же, что processGroup |
| `processCode` | `code` | Обязательный скаляр |
| `processPackage` | `package_name` | Обязательный скаляр |

### EPG

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processEPG` | `epg_name`, `epg_file` | Оба обязательны |

### Устройства и линии

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processMAG` | `mac` | MAC-адрес обязателен |
| `processEnigma` | `mac` | MAC-адрес обязателен |
| `setChannelOrder` | `stream_order_array` | Должен декодироваться в JSON-массив |

### Профили

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processProfile` | `profile_name` | Обязательный скаляр |

### Провайдеры

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processProvider` | `ip`, `port`, `username`, `password`, `name` | Все пять обязательны |
| `processISP` | `isp` | Обязательный скаляр |
| `processUA` | `user_agent` | Обязательный скаляр |

### Безопасность

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `blockIP` | `ip` | IP-адрес обязателен |
| `processRTMPIP` | `ip` | IP-адрес обязателен |

### Управление серверами

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `processServer` | `server_name`, `server_ip` | Оба обязательны |
| `processProxy` | `server_name`, `server_ip` | То же, что processServer |
| `installServer` | `ssh_port`, `root_password` | Оба обязательны |
| `moveStreams` | `content_type`, `source_server`, `replacement_server` | Все три обязательны |
| `replaceDNS` | `old_dns`, `new_dns` | Оба обязательны |
| `orderServers` | `server_order` | Должен декодироваться в JSON-массив |

### Записи и Watch Folders

| Действие | Обязательные поля | Заметки |
| --- | --- | --- |
| `scheduleRecording` | `title`, `source_id` | Оба обязательны |
| `processWatchFolder` | `folder_type`, `selected_path`, `server_id` | Все три обязательны |

### Массовые операции (JSON-массивы)

Все массовые операции требуют JSON-encoded массив в указанном поле. Поле должно декодироваться в валидный PHP-массив.

| Действие | JSON-поле |
| --- | --- |
| `massEditEpisodes` | `streams` |
| `massEditMovies` | `streams` |
| `massEditRadios` | `streams` |
| `massEditStreams` | `streams` |
| `massEditChannels` | `streams` |
| `massDeleteStreams` | `streams` |
| `massEditSeries` | `series` |
| `massDeleteSeries` | `series` |
| `massEditLines` | `users_selected` |
| `massEditUsers` | `users_selected` |
| `massEditMags` | `devices_selected` |
| `massEditEnigmas` | `devices_selected` |
| `massDeleteMovies` | `movies` |
| `massDeleteLines` | `lines` |
| `massDeleteUsers` | `users` |
| `massDeleteStations` | `radios` |
| `massDeleteMags` | `mags` |
| `massDeleteEnigmas` | `enigmas` |
| `massDeleteEpisodes` | `episodes` |

---

## Поведение по умолчанию (fallthrough)

Действия, явно не перечисленные в `switch`, проваливаются на `return true`, то есть всегда проходят валидацию. Это намеренно — у этих действий либо нет обязательных полей на уровне гейта, либо они выполняют собственную валидацию глубже в слое бизнес-логики.

**Действия с явным пропуском** (в switch с `return true`):

- `processUser`
- `processLine`
- `processHMAC`
- `editAdminProfile`
- `editSettings`
- `editBackupSettings`
- `editCacheCron`
- `editPlexSettings`
- `editWatchSettings`
- `processPlexSync`
- `processLogin`
- `submitTicket`

**Действия с неявным пропуском** (вообще не в switch, попадают в default `return true`):

Любая строка действия, не совпадающая с `case`, также вернёт `true`. Если новому действию нужен input-гейт, case должен быть добавлен явно.

---

## Используемые паттерны валидации

Метод `validate()` использует небольшой набор паттернов последовательно:

| Паттерн | Назначение | Пример |
| --- | --- | --- |
| `!empty($rData['field'])` | Обязательное скалярное поле (не null, не пустое, не ноль) | `!empty($rData['bouquet_name'])` |
| `is_numeric($rData['field'] ?? null)` | Числовая валидация с null-safe fallback | `is_numeric($rData['season_num'] ?? null)` |
| `is_array(json_decode($rData['field'] ?? '', true))` | JSON-строка, декодируемая в массив | `is_array(json_decode($rData['streams'] ?? '', true))` |
| `isset($rData['field'])` | Проверка наличия поля (значение может быть пустым/falsy) | `isset($rData['review'])` |
| `isset($_FILES['field'])` | Проверка наличия загруженного файла | `isset($_FILES['m3u_file'])` |
| OR-условия | Мульти-путевая валидация (любой путь удовлетворяет) | `!empty($rData['stream_display_name']) \|\| isset($rData['review']) \|\| isset($_FILES['m3u_file'])` |

---

## Добавление валидации для нового действия

Добавьте `case` в `switch` в `src/core/Validation/InputValidator.php`:

```php
case 'myNewAction':
    return !empty($rData['required_field'])
        && is_numeric($rData['numeric_field'] ?? null);
```

Рекомендации:

- Валидируйте только минимально необходимые входные данные на этом слое. Доменные правила (валидация формата, бизнес-ограничения, проверки уникальности) держите в сервисном слое.
- Используйте `!empty()` для обязательных скаляров, `is_numeric()` для числовых полей и `is_array(json_decode(..., true))` для JSON-массивов.
- Для действий, принимающих загрузку файлов как альтернативу полям формы, включите `isset($_FILES['field'])` как OR-условие.
- Если действию не нужна валидация на уровне гейта, добавьте его в блок явного пропуска с `return true`, чтобы будущим мейнтейнерам было понятно, что пропуск намеренный, а не случайный.

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/core/Validation/InputValidator.php` | Вся логика санитизации и валидации |
| `src/core/Init/LegacyInitializer.php` | Bootstrap-вызыватель, запускающий санитизацию через `initCore()` |
| `src/core/Http/RequestManager.php` | Хранит санитизированные, объединённые данные GET+POST |
| `src/public/Controllers/` | Контроллеры, вызывающие `validate()` / `validateOrFail()` перед бизнес-логикой |
