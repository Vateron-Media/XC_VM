# Разрешения и RBAC

XC_VM системы контроля доступа объединяют:

- **Групповые разрешения** -- разрешенные возможности, назначенные группе администраторов
- **Авторизация на уровне объекта** -- проверка прав собственности для конкретных объектов (пользователей, строк)
- **Авторизация на уровне страницы** -- настройка маршрута/страницы в панелях администратора и реселлера

---

## Модель

```text
user -> member_group_id -> group
         -> is_admin (boolean)
         -> is_reseller (boolean)
         -> advanced[] (array of permission keys)
```

Состояние разрешения загружается в глобальное значение `$rPermissions` во время инициализации сеанса и остается доступным на протяжении всего жизненного цикла запроса.

Ключевые поля в `$rPermissions`:

|Поле|Тип|Описание|
| --- | --- | --- |
| `is_admin` |тип bool|Является ли пользователь администратором|
| `advanced` |массив|Список предоставленных ключевых строк разрешений|
| `all_reports` |массив|Дерево отчетов реселлера (идентификаторы пользователей, которыми управляет этот реселлер)|
| `create_line` |тип bool|Реселлер: может создавать строки|
| `create_sub_resellers` |тип bool|Реселлер: может создавать суб-реселлеров|
| `create_mag` |тип bool|Реселлер: может создавать магнитные устройства|
| `create_enigma` |тип bool|Реселлер: может создавать устройства Enigma|
| `can_view_vod` |тип bool|Реселлер: может просматривать содержимое VOD/streams|
| `reseller_client_connection_logs` |тип bool|Реселлер: может просматривать журналы подключений|

---

## Ключи разрешений

Ключи разрешений объявляются в `src/config/permissions.php` как массив `$rPermissionKeys`. Каждый ключ представляет собой строковый идентификатор, используемый в `Authorization::check('adv', $key)`.

Категории:

|Категория|Примеры|
| --- | --- |
|Создать/Добавить|`add_stream`, `add_movie`, `add_user`, `add_server`, `add_bouquet`, `add_epg`, `add_code`, `add_hmac`, `add_rtmp`|
|Редактировать|`edit_stream`, `edit_movie`, `edit_user`, `edit_server`, `edit_bouquet`, `edit_series`, `edit_reguser`|
|Массовые операции|`mass_edit_streams`, `mass_edit_lines`, `mass_edit_mags`, `mass_edit_enigmas`, `mass_edit_radio`, `mass_edit_users`, `mass_sedits`, `mass_sedits_vod`, `mass_delete`|
|Импорт|`import_streams`, `import_movies`, `import_episodes`|
|Безопасность/блокирование|`block_ips`, `block_isps`, `block_uas`, `block_asns`, `fingerprint`|
|Видимость раздела|`streams`, `movies`, `series`, `episodes`, `radio`, `users`, `servers`, `bouquets`, `epg`, `settings`, `database`|
|Бревна|`connection_logs`, `live_connections`, `client_request_log`, `credits_log`, `login_logs`, `panel_logs`, `reg_userlog`, `restream_logs`|
|Инструменты|`quick_tools`, `stream_tools`, `process_monitor`, `stream_errors`|
|Управление|`mng_regusers`, `mng_groups`, `mng_packages`, `manage_mag`, `manage_e2`, `manage_events`, `manage_tickets`|
|Другой|`categories`, `channel_order`, `player`, `tprofile`, `tprofiles`, `rtmp`, `folder_watch`, `folder_watch_add`, `folder_watch_output`, `folder_watch_settings`, `ticket`, `add_code`, `add_hmac`|

---

## Классы авторизации

### `Authorization`

Файл: `src/Core/Auth/Authorization.php`

Первичный метод:

```php
Authorization::check(string $rType, string|int|null $rID): bool
```

**Предварительные условия:** Немедленно возвращает `false`, если `$rUserInfo`, `$rPermissions` или `$db` не инициализированы.

#### Тип: `user`

Проверяет, может ли текущий пользователь получить доступ к целевому пользователю-администратору. Создает список из идентификатора текущего пользователя и их дерева `all_reports`, затем запрашивает таблицу `users`, чтобы проверить, есть ли в этом списке имя целевого пользователя `owner_id` (или целевым пользователем является текущий пользователь).

```php
Authorization::check('user', $userId);
```

#### Тип: `line`

Проверяет, может ли текущий пользователь получить доступ к целевой строке. Тот же подход к дереву отчетов - запрашивает таблицу `lines`, чтобы убедиться, что целевая строка `member_id` находится в дереве отчетов текущего пользователя.

```php
Authorization::check('line', $lineId);
```

#### Тип: `adv`

Проверяет, есть ли у текущего пользователя-администратора определенный ключ расширенных прав доступа.

```php
Authorization::check('adv', 'edit_bouquet');
Authorization::check('adv', 'block_isps');
```

**Важно: ворота `is_admin`.** Перед проверкой массива расширенных разрешений метод требует, чтобы значение `$rPermissions['is_admin']` было равно true. Если пользователь не является администратором, `check('adv', ...)` всегда возвращает значение `false`:

```php
if (!($rType == 'adv' && $rPermissions['is_admin'])) {
    return false;
}
```

Это означает, что проверки `adv` предназначены исключительно для пользователей с правами администратора. Для разрешений реселлеров используется отдельная система (см. ниже).

#### Обход прав суперадминистратора

`member_group_id = 1` - это группа суперадминистраторов. Если массив расширенных разрешений непустой, но пользователь принадлежит к группе 1, проверка для каждого ключа пропускается и метод возвращает значение `true`:

```php
if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
    return in_array($rID, $rPermissions['advanced']);
}
return true;
```

Это означает, что суперадминистраторы проходят все проверки `adv` независимо от того, какие ключи назначены их группе.

#### Помощник реселлера

```php
Authorization::hasResellerPermissions(string $type): bool
```

Возвращает, не является ли значение `$rPermissions[$type]` непустым. Используется для логических флагов, специфичных для реселлера, таких как `create_line`, `create_mag` и т.д.

---

### `PageAuthorization`

Файл: `src/Core/Auth/PageAuthorization.php`

Обеспечивает управление на уровне страницы для панелей администратора и торгового посредника. Вызывается во время отправки запроса, чтобы определить, может ли текущий пользователь получить доступ к данной странице.

```php
PageAuthorization::checkPermissions(?string $page = null): bool
PageAuthorization::checkResellerPermissions(?string $page = null): bool
```

Если `$page` опущено, название страницы выводится из `SCRIPT_FILENAME` (базовое имя без расширения `.php`, в нижнем регистре).

#### Поведение, разрешенное по умолчанию

Оба метода возвращают значение `true` для любой страницы, явно не указанной в их инструкциях switch. Это означает, что страницы без сопоставления доступны всем авторизованным пользователям соответствующего типа (администраторам или торговым посредникам). Доступ ограничен только к страницам с явно заданными параметрами.

---

## Сопоставления разрешений на странице администратора

Метод `checkPermissions()` сопоставляет страницы панели администратора с ключами доступа `adv`. Ниже приведено полное сопоставление, сгруппированное по категориям.

### Шаблон создания или редактирования

Многие страницы сущностей используют условную логику, основанную на параметрах запроса:

- Если указан параметр `id`, проверяется разрешение **редактировать**
- Если параметр `id` отсутствует, проверяется разрешение **добавлять**
- Некоторые страницы (stream, movie) также проверяют наличие параметра `import` и требуют соответствующего разрешения на импорт

Когда ни одно из условий не выполняется, поведение зависит от страницы: некоторые переходят к соответствующему разрешению на перечисление, другие переходят к переключателю по умолчанию (который возвращает `true`).

### Потоки и контент

|Страница|Разрешение|Записи|
| --- | --- | --- |
|`streams`, `stream_view`, `provider`, `providers`, `epg_view`, `created_channels`, `stream_rank`, `archive`| `streams` | |
| `stream` | `edit_stream` | When `id` is present |
| `stream` | `add_stream` |Когда нет `id`|
| `stream` | `import_streams` |Когда присутствует параметр `import` (в дополнение к параметру `add_stream`)|
| `stream_categories` | `categories` | |
| `stream_category` | `add_cat` | |
| `stream_errors` | `stream_errors` | |
|`stream_mass`, `created_channel_mass`| `mass_edit_streams` | |
| `mass_edit_streams` | `edit_stream` | |
| `review` | `import_streams` | |
| `channel_order` | `channel_order` | |
| `created_channel` | `edit_cchannel` | When `id` is present |
| `created_channel` | `create_channel` |Когда нет `id`|

### Фильмы и VOD

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `movies` | `movies` | |
| `movie` | `edit_movie` | When `id` is present |
| `movie` | `add_movie` |Когда нет `id`|
| `movie` | `import_movies` |Когда присутствует параметр `import` (в дополнение к параметру `add_movie`)|
| `movie_mass` | `mass_sedits_vod` | |
| `record` | `add_movie` | |
| `recordings` | `movies` | |

### Сериалы и эпизоды

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `series` | `series` | |
| `serie` | `edit_series` | When `id` is present |
| `serie` | `add_series` |Когда нет `id`|
| `series_order` | `edit_series` | |
| `episodes` | `episodes` | |
| `episode` | `edit_episode` | When `id` is present |
| `episode` | `add_episode` |Когда нет `id`; при отказе переходит на `episodes`|
|`series_mass`, `episodes_mass`| `mass_sedits` | |

### Радио

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `radios` | `radio` | |
| `radio` | `edit_radio` | When `id` is present |
| `radio` | `add_radio` |Когда нет `id`|
| `radio_mass` | `mass_edit_radio` | |

### Линии (Абонентские пользователи)

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `lines` | `users` | |
| `line` | `edit_user` | When `id` is present |
| `line` | `add_user` |Когда нет `id`|
| `line_mass` | `mass_edit_lines` | |
|`line_activity`, `theft_detection`, `line_ips`| `connection_logs` | |
| `live_connections` | `live_connections` | |

### Устройства MAG и Enigma

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `mags` | `manage_mag` | |
| `mag` | `edit_mag` | When `id` is present |
| `mag` | `add_mag` |Когда нет `id`|
| `mag_events` | `manage_events` | |
| `mag_mass` | `mass_edit_mags` | |
| `enigmas` | `manage_e2` | |
| `enigma_mass` | `mass_edit_enigmas` | |

### Пользователи с правами администратора (Зарегистрированные пользователи)

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `users` | `mng_regusers` | |
| `user` | `edit_reguser` | When `id` is present |
| `user` | `add_reguser` |Когда нет `id`|
| `user_mass` | `mass_edit_users` | |
| `user_logs` | `reg_userlog` | |

### Букеты и посылки

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `bouquets` | `bouquets` | |
| `bouquet` | `edit_bouquet` | When `id` is present |
| `bouquet` | `add_bouquet` |Когда нет `id`; при отказе переходит на `edit_bouquet`|
|`bouquet_order`, `bouquet_sort`| `edit_bouquet` | |
|`packages`, `addons`| `mng_packages` | |
| `package` | `edit_package` | When `id` is present |
| `package` | `add_packages` |Когда нет `id`|

### Группы

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `groups` | `mng_groups` | |
| `group` | `edit_group` | When `id` is present |
| `group` | `add_group` |Когда нет `id`; при отказе переходит на `mng_groups`|

### EPG

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `epgs` | `epg` | |
| `epg` | `epg_edit` | When `id` is present |
| `epg` | `add_epg` |Когда нет `id`; при отказе переходит на `epg`|

### Серверы

|Страница|Разрешение|Записи|
| --- | --- | --- |
|`servers`, `server_view`, `server_order`, `proxies`| `servers` | |
|`server`, `proxy`| `edit_server` | When `id` is present |
|`server`, `proxy`| `add_server` |Когда нет `id`|
| `server_install` | `add_server` | |

### Безопасность и блокирование

|Страница|Разрешение|Записи|
| --- | --- | --- |
|`isps`, `isp`, `asns`| `block_isps` | |
|`ip`, `ips`| `block_ips` | |
|`useragents`, `useragent`| `block_uas` | |
| `fingerprint` | `fingerprint` | |

### Билеты

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `ticket` | `ticket` | |
|`ticket_view`, `tickets`| `manage_tickets` | |

### Инструменты и настройки

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `settings` | `settings` | |
|`backups`, `cache`, `setup`| `database` | |
|`settings_watch`, `settings_plex`| `folder_watch_settings` | |
|`plex`, `watch`| `folder_watch` | |
|`plex_add`, `watch_add`| `folder_watch_add` | |
| `watch_output` | `folder_watch_output` | |
| `mass_delete` | `mass_delete` | |
| `quick_tools` | `quick_tools` | |
| `stream_tools` | `stream_tools` | |
| `process_monitor` | `process_monitor` | |
| `queue` |`streams` ИЛИ `episodes` ИЛИ `series`|Доступ, если у пользователя есть какой-либо из этих|

### Профили и коды

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `profiles` | `tprofiles` | |
| `profile` | `tprofile` | |
| `player` | `player` | |
|`code`, `codes`| `add_code` | |
|`hmac`, `hmacs`| `add_hmac` | |

### RTMP

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `rtmp_ip` | `add_rtmp` | |
|`rtmp_ips`, `rtmp_monitor`| `rtmp` | |

### Бревна

|Страница|Разрешение|Записи|
| --- | --- | --- |
| `client_logs` | `client_request_log` | |
| `credit_logs` | `credits_log` | |
|`mysql_syslog`, `panel_logs`| `panel_logs` | |
| `login_logs` | `login_logs` | |
| `restream_logs` | `restream_logs` | |

---

## Сопоставления разрешений на странице реселлера

Метод `checkResellerPermissions()` сопоставляет страницы панели реселлера с логическими флагами в `$rPermissions`. В отличие от разрешений администратора, которые используют массив `advanced` через `Authorization::check('adv', ...)`, разрешения реселлера - это простые логические поля, которые проверяются напрямую.

|Страницы|Требуемое разрешение|
| --- | --- |
|`user`, `users`| `create_sub_resellers` |
|`line`, `lines`| `create_line` |
|`mag`, `mags`| `create_mag` |
|`enigma`, `enigmas`| `create_enigma` |
|`epg_view`, `streams`, `created_channels`, `movies`, `episodes`, `radios`| `can_view_vod` |
|`live_connections`, `line_activity`| `reseller_client_connection_logs` |

Любая страница реселлера, не указанная выше, возвращает значение `true` (доступно по умолчанию).

---

## Добавление нового разрешения

1. Добавьте ключ к `src/config/permissions.php`:

```php
$rPermissionKeys = array(
    // ...existing keys...
    'my_new_permission',
);
```

2. Используйте это в коде через `Authorization::check()`:

```php
if (!Authorization::check('adv', 'my_new_permission')) {
    // deny access
}
```

3. Если разрешение должно указывать на страницу, добавьте регистр в `PageAuthorization::checkPermissions()`:

```php
case 'my_new_page':
    return Authorization::check('adv', 'my_new_permission');
```

4. Для создания/редактирования страниц сущностей используйте условный шаблон:

```php
case 'my_entity':
    if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_my_entity')) {
        return true;
    }
    if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_my_entity')) {
        break;
    }
    return true;
```

5. Для получения разрешений торгового посредника добавьте логическое поле в `$rPermissions` и регистр в `checkResellerPermissions()`.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/config/permissions.php` |Реестр ключей разрешений (массив`$rPermissionKeys`)|
| `src/Core/Auth/Authorization.php` |Проверка разрешений на уровне объекта и расширенные проверки разрешений|
| `src/Core/Auth/PageAuthorization.php` |Настройка на уровне страницы для панелей администратора и реселлера|
| `src/Core/Auth/SessionManager.php` |Контекст сеанса; заполняет значения `$rPermissions` и `$rUserInfo`|
| `src/Core/Auth/Authenticator.php` |Аутентификация (вход в систему, проверка учетных данных)|
