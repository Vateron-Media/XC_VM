# Права доступа и RBAC

Контроль доступа в XC_VM объединяет:

- **Права группы** — разрешённые возможности, назначаемые админ-группе
- **Авторизация на уровне объектов** — проверки владения для конкретных сущностей (пользователи, линии)
- **Авторизация на уровне страниц** — гейтинг маршрутов/страниц в admin- и reseller-панелях

---

## Модель

```text
user -> member_group_id -> group
         -> is_admin (boolean)
         -> is_reseller (boolean)
         -> advanced[] (массив ключей прав)
```

Состояние прав загружается в глобальную переменную `$rPermissions` при инициализации сессии и остаётся доступным в течение всего жизненного цикла запроса.

Ключевые поля в `$rPermissions`:

| Поле | Тип | Описание |
| --- | --- | --- |
| `is_admin` | bool | Является ли пользователь администратором |
| `advanced` | array | Список выданных строковых ключей прав |
| `all_reports` | array | Дерево подотчётных реселлеру (ID пользователей, которыми он управляет) |
| `create_line` | bool | Реселлер: может создавать линии |
| `create_sub_resellers` | bool | Реселлер: может создавать суб-реселлеров |
| `create_mag` | bool | Реселлер: может создавать устройства MAG |
| `create_enigma` | bool | Реселлер: может создавать устройства Enigma |
| `can_view_vod` | bool | Реселлер: может просматривать контент VOD/потоков |
| `reseller_client_connection_logs` | bool | Реселлер: может просматривать логи подключений |

---

## Ключи прав

Ключи прав объявляются в `src/config/permissions.php` как массив `$rPermissionKeys`. Каждый ключ — это строковый идентификатор, используемый с `Authorization::check('adv', $key)`.

Категории:

| Категория | Примеры |
| --- | --- |
| Создание/добавление | `add_stream`, `add_movie`, `add_user`, `add_server`, `add_bouquet`, `add_epg`, `add_code`, `add_hmac`, `add_rtmp` |
| Редактирование | `edit_stream`, `edit_movie`, `edit_user`, `edit_server`, `edit_bouquet`, `edit_series`, `edit_reguser` |
| Массовые операции | `mass_edit_streams`, `mass_edit_lines`, `mass_edit_mags`, `mass_edit_enigmas`, `mass_edit_radio`, `mass_edit_users`, `mass_sedits`, `mass_sedits_vod`, `mass_delete` |
| Импорт | `import_streams`, `import_movies`, `import_episodes` |
| Безопасность/блокировки | `block_ips`, `block_isps`, `block_uas`, `block_asns`, `fingerprint` |
| Видимость разделов | `streams`, `movies`, `series`, `episodes`, `radio`, `users`, `servers`, `bouquets`, `epg`, `settings`, `database` |
| Логи | `connection_logs`, `live_connections`, `client_request_log`, `credits_log`, `login_logs`, `panel_logs`, `reg_userlog`, `restream_logs` |
| Инструменты | `quick_tools`, `stream_tools`, `process_monitor`, `stream_errors` |
| Управление | `mng_regusers`, `mng_groups`, `mng_packages`, `manage_mag`, `manage_e2`, `manage_events`, `manage_tickets` |
| Прочее | `categories`, `channel_order`, `player`, `tprofile`, `tprofiles`, `rtmp`, `folder_watch`, `folder_watch_add`, `folder_watch_output`, `folder_watch_settings`, `ticket`, `add_code`, `add_hmac` |

---

## Классы авторизации

### `Authorization`

Файл: `src/Core/Auth/Authorization.php`

Основной метод:

```php
Authorization::check(string $type, mixed $id): bool
```

**Предусловия:** Возвращает `false` сразу, если `$rUserInfo`, `$rPermissions` или `$db` не инициализированы.

#### Тип: `user`

Проверяет, может ли текущий пользователь получить доступ к целевому admin-пользователю. Строит список из ID текущего пользователя и его дерева `all_reports`, затем запрашивает таблицу `users`, чтобы убедиться, что `owner_id` целевого пользователя есть в этом списке (или цель — сам текущий пользователь).

```php
Authorization::check('user', $userId);
```

#### Тип: `line`

Проверяет, может ли текущий пользователь получить доступ к целевой линии. Тот же подход с деревом подотчётных — запрашивает таблицу `lines`, чтобы убедиться, что `member_id` целевой линии есть в дереве подотчётных текущего пользователя.

```php
Authorization::check('line', $lineId);
```

#### Тип: `adv`

Проверяет, есть ли у текущего администратора конкретный продвинутый ключ права.

```php
Authorization::check('adv', 'edit_bouquet');
Authorization::check('adv', 'block_isps');
```

**Важно: гейт `is_admin`.** Перед проверкой массива продвинутых прав метод требует `$rPermissions['is_admin'] = true`. Если пользователь не админ, `check('adv', ...)` всегда возвращает `false`:

```php
if (!($rType == 'adv' && $rPermissions['is_admin'])) {
    return false;
}
```

Это значит, что проверки `adv` предназначены исключительно для администраторов. Права реселлера используют отдельную систему (см. ниже).

#### Обход для супер-админа

`member_group_id = 1` — группа супер-админа. Когда массив продвинутых прав непуст, но пользователь принадлежит группе 1, проверка по конкретному ключу пропускается и метод возвращает `true`:

```php
if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
    return in_array($rID, $rPermissions['advanced']);
}
return true;
```

Это значит, что супер-админы проходят все проверки `adv` независимо от того, какие ключи назначены их группе.

#### Помощник для реселлеров

```php
Authorization::hasResellerPermissions(string $type): bool
```

Возвращает, является ли `$rPermissions[$type]` непустым. Используется для специфичных для реселлера булевых флагов вроде `create_line`, `create_mag` и т.д.

---

### `PageAuthorization`

Файл: `src/Core/Auth/PageAuthorization.php`

Обеспечивает гейтинг на уровне страниц для admin- и reseller-панелей. Вызывается во время диспетчеризации запроса, чтобы определить, может ли текущий пользователь получить доступ к заданной странице.

```php
PageAuthorization::checkPermissions(?string $page = null): bool
PageAuthorization::checkResellerPermissions(?string $page = null): bool
```

Если `$page` опущен, имя страницы выводится из `SCRIPT_FILENAME` (basename без расширения `.php`, в нижнем регистре).

#### Поведение по умолчанию — разрешить

Оба метода возвращают `true` для любой страницы, явно не указанной в их switch-операторах. Это значит, что страницы без сопоставления доступны всем авторизованным пользователям соответствующего типа (admin или reseller). Ограничены только страницы с явными записями.

---

## Сопоставления прав admin-страниц

Метод `checkPermissions()` сопоставляет страницы admin-панели с `adv`-ключами прав. Полное сопоставление приведено ниже, сгруппировано по категориям.

### Паттерн create-vs-edit

Многие страницы сущностей используют условную логику на основе параметров запроса:

- Если присутствует параметр `id`, проверяется право **edit**
- Если параметра `id` нет, проверяется право **add**
- Некоторые страницы (stream, movie) также проверяют параметр `import` и требуют соответствующего права импорта

Если ни одно условие не выполнено, поведение зависит от страницы: некоторые проваливаются на связанное право листинга, другие — на default ветку switch (возвращающую `true`).

### Потоки и контент

| Страница | Право | Заметки |
| --- | --- | --- |
| `streams`, `stream_view`, `provider`, `providers`, `epg_view`, `created_channels`, `stream_rank`, `archive` | `streams` | |
| `stream` | `edit_stream` | Когда есть `id` |
| `stream` | `add_stream` | Когда нет `id` |
| `stream` | `import_streams` | Когда присутствует параметр `import` (в дополнение к `add_stream`) |
| `stream_categories` | `categories` | |
| `stream_category` | `add_cat` | |
| `stream_errors` | `stream_errors` | |
| `stream_mass`, `created_channel_mass` | `mass_edit_streams` | |
| `mass_edit_streams` | `edit_stream` | |
| `review` | `import_streams` | |
| `channel_order` | `channel_order` | |
| `created_channel` | `edit_cchannel` | Когда есть `id` |
| `created_channel` | `create_channel` | Когда нет `id` |

### Фильмы и VOD

| Страница | Право | Заметки |
| --- | --- | --- |
| `movies` | `movies` | |
| `movie` | `edit_movie` | Когда есть `id` |
| `movie` | `add_movie` | Когда нет `id` |
| `movie` | `import_movies` | Когда присутствует параметр `import` (в дополнение к `add_movie`) |
| `movie_mass` | `mass_sedits_vod` | |
| `record` | `add_movie` | |
| `recordings` | `movies` | |

### Сериалы и эпизоды

| Страница | Право | Заметки |
| --- | --- | --- |
| `series` | `series` | |
| `serie` | `edit_series` | Когда есть `id` |
| `serie` | `add_series` | Когда нет `id` |
| `series_order` | `edit_series` | |
| `episodes` | `episodes` | |
| `episode` | `edit_episode` | Когда есть `id` |
| `episode` | `add_episode` | Когда нет `id`; проваливается на `episodes` при отказе |
| `series_mass`, `episodes_mass` | `mass_sedits` | |

### Радио

| Страница | Право | Заметки |
| --- | --- | --- |
| `radios` | `radio` | |
| `radio` | `edit_radio` | Когда есть `id` |
| `radio` | `add_radio` | Когда нет `id` |
| `radio_mass` | `mass_edit_radio` | |

### Линии (пользователи-абоненты)

| Страница | Право | Заметки |
| --- | --- | --- |
| `lines` | `users` | |
| `line` | `edit_user` | Когда есть `id` |
| `line` | `add_user` | Когда нет `id` |
| `line_mass` | `mass_edit_lines` | |
| `line_activity`, `theft_detection`, `line_ips` | `connection_logs` | |
| `live_connections` | `live_connections` | |

### Устройства MAG и Enigma

| Страница | Право | Заметки |
| --- | --- | --- |
| `mags` | `manage_mag` | |
| `mag` | `edit_mag` | Когда есть `id` |
| `mag` | `add_mag` | Когда нет `id` |
| `mag_events` | `manage_events` | |
| `mag_mass` | `mass_edit_mags` | |
| `enigmas` | `manage_e2` | |
| `enigma_mass` | `mass_edit_enigmas` | |

### Admin-пользователи (зарегистрированные пользователи)

| Страница | Право | Заметки |
| --- | --- | --- |
| `users` | `mng_regusers` | |
| `user` | `edit_reguser` | Когда есть `id` |
| `user` | `add_reguser` | Когда нет `id` |
| `user_mass` | `mass_edit_users` | |
| `user_logs` | `reg_userlog` | |

### Букеты и пакеты

| Страница | Право | Заметки |
| --- | --- | --- |
| `bouquets` | `bouquets` | |
| `bouquet` | `edit_bouquet` | Когда есть `id` |
| `bouquet` | `add_bouquet` | Когда нет `id`; проваливается на `edit_bouquet` при отказе |
| `bouquet_order`, `bouquet_sort` | `edit_bouquet` | |
| `packages`, `addons` | `mng_packages` | |
| `package` | `edit_package` | Когда есть `id` |
| `package` | `add_packages` | Когда нет `id` |

### Группы

| Страница | Право | Заметки |
| --- | --- | --- |
| `groups` | `mng_groups` | |
| `group` | `edit_group` | Когда есть `id` |
| `group` | `add_group` | Когда нет `id`; проваливается на `mng_groups` при отказе |

### EPG

| Страница | Право | Заметки |
| --- | --- | --- |
| `epgs` | `epg` | |
| `epg` | `epg_edit` | Когда есть `id` |
| `epg` | `add_epg` | Когда нет `id`; проваливается на `epg` при отказе |

### Серверы

| Страница | Право | Заметки |
| --- | --- | --- |
| `servers`, `server_view`, `server_order`, `proxies` | `servers` | |
| `server`, `proxy` | `edit_server` | Когда есть `id` |
| `server`, `proxy` | `add_server` | Когда нет `id` |
| `server_install` | `add_server` | |

### Безопасность и блокировки

| Страница | Право | Заметки |
| --- | --- | --- |
| `isps`, `isp`, `asns` | `block_isps` | |
| `ip`, `ips` | `block_ips` | |
| `useragents`, `useragent` | `block_uas` | |
| `fingerprint` | `fingerprint` | |

### Тикеты

| Страница | Право | Заметки |
| --- | --- | --- |
| `ticket` | `ticket` | |
| `ticket_view`, `tickets` | `manage_tickets` | |

### Инструменты и настройки

| Страница | Право | Заметки |
| --- | --- | --- |
| `settings` | `settings` | |
| `backups`, `cache`, `setup` | `database` | |
| `settings_watch`, `settings_plex` | `folder_watch_settings` | |
| `plex`, `watch` | `folder_watch` | |
| `plex_add`, `watch_add` | `folder_watch_add` | |
| `watch_output` | `folder_watch_output` | |
| `mass_delete` | `mass_delete` | |
| `quick_tools` | `quick_tools` | |
| `stream_tools` | `stream_tools` | |
| `process_monitor` | `process_monitor` | |
| `queue` | `streams` ИЛИ `episodes` ИЛИ `series` | Доступ при наличии любого из них |

### Профили и коды

| Страница | Право | Заметки |
| --- | --- | --- |
| `profiles` | `tprofiles` | |
| `profile` | `tprofile` | |
| `player` | `player` | |
| `code`, `codes` | `add_code` | |
| `hmac`, `hmacs` | `add_hmac` | |

### RTMP

| Страница | Право | Заметки |
| --- | --- | --- |
| `rtmp_ip` | `add_rtmp` | |
| `rtmp_ips`, `rtmp_monitor` | `rtmp` | |

### Логи

| Страница | Право | Заметки |
| --- | --- | --- |
| `client_logs` | `client_request_log` | |
| `credit_logs` | `credits_log` | |
| `mysql_syslog`, `panel_logs` | `panel_logs` | |
| `login_logs` | `login_logs` | |
| `restream_logs` | `restream_logs` | |

---

## Сопоставления прав reseller-страниц

Метод `checkResellerPermissions()` сопоставляет страницы reseller-панели с булевыми флагами в `$rPermissions`. В отличие от admin-прав, использующих массив `advanced` через `Authorization::check('adv', ...)`, права реселлера — простые булевы поля, проверяемые напрямую.

| Страницы | Требуемое право |
| --- | --- |
| `user`, `users` | `create_sub_resellers` |
| `line`, `lines` | `create_line` |
| `mag`, `mags` | `create_mag` |
| `enigma`, `enigmas` | `create_enigma` |
| `epg_view`, `streams`, `created_channels`, `movies`, `episodes`, `radios` | `can_view_vod` |
| `live_connections`, `line_activity` | `reseller_client_connection_logs` |

Любая страница reseller, не перечисленная выше, возвращает `true` (доступна по умолчанию).

---

## Добавление нового права

1. Добавьте ключ в `src/config/permissions.php`:

```php
$rPermissionKeys = array(
    // ...существующие ключи...
    'my_new_permission',
);
```

2. Используйте его в коде через `Authorization::check()`:

```php
if (!Authorization::check('adv', 'my_new_permission')) {
    // отказать в доступе
}
```

3. Если право должно гейтить страницу, добавьте case в `PageAuthorization::checkPermissions()`:

```php
case 'my_new_page':
    return Authorization::check('adv', 'my_new_permission');
```

4. Для страниц сущностей create/edit используйте условный паттерн:

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

5. Для прав реселлера добавьте булево поле в `$rPermissions` и case в `checkResellerPermissions()`.

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/config/permissions.php` | Реестр ключей прав (массив `$rPermissionKeys`) |
| `src/Core/Auth/Authorization.php` | Проверки прав на уровне объектов и продвинутых |
| `src/Core/Auth/PageAuthorization.php` | Гейтинг страниц admin- и reseller-панелей |
| `src/Core/Auth/SessionManager.php` | Контекст сессии; заполняет `$rPermissions` и `$rUserInfo` |
| `src/Core/Auth/Authenticator.php` | Аутентификация (вход, проверка учётных данных) |
