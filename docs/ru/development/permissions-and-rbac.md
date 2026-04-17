# Права доступа и RBAC

Система контроля доступа XC_VM построена на трёх компонентах: **права группы** (что разрешено), **авторизация на уровне объектов** (доступ к конкретным записям) и **авторизация на уровне страниц** (разрешения для конкретных разделов панели).

---

## Обзор

```
Пользователь
    │
    └── member_group_id
             │
             ├── is_admin       — суперпользователь (все права)
             ├── is_reseller    — реселлер (ограниченный набор)
             └── advanced[]     — гранулярные права (массив ключей)
```

Все проверки прав основаны на глобальном массиве `$rPermissions`, загружаемом при инициализации сессии администратора.

---

## Ключи прав (`$rPermissionKeys`)

Определены в `src/config/permissions.php`.

### Контент — создание

| Ключ              | Описание                      |
| ----------------- | ----------------------------- |
| `add_stream`      | Добавить стрим                |
| `add_movie`       | Добавить фильм                |
| `add_radio`       | Добавить радио                |
| `add_episode`     | Добавить эпизод               |
| `add_series`      | Добавить сериал               |
| `add_bouquet`     | Создать букет                 |
| `add_cat`         | Добавить категорию            |
| `add_epg`         | Добавить EPG                  |
| `add_group`       | Добавить группу               |
| `add_rtmp`        | Добавить RTMP                 |
| `add_mag`         | Добавить MAG-устройство       |
| `add_e2`          | Добавить Enigma2-устройство   |
| `add_user`        | Создать пользователя          |
| `add_reguser`     | Создать зарегистрированного пользователя |
| `add_server`      | Добавить сервер               |
| `add_packages`    | Создать пакет                 |
| `tprofile`        | Управление профилями транскодирования |

### Контент — редактирование

| Ключ              | Описание                      |
| ----------------- | ----------------------------- |
| `edit_stream`     | Редактировать стрим           |
| `edit_movie`      | Редактировать фильм           |
| `edit_radio`      | Редактировать радио           |
| `edit_episode`    | Редактировать эпизод          |
| `edit_series`     | Редактировать сериал          |
| `edit_bouquet`    | Редактировать букет           |
| `edit_cat`        | Редактировать категорию       |
| `edit_mag`        | Редактировать MAG-устройство  |
| `edit_e2`         | Редактировать Enigma2         |
| `edit_user`       | Редактировать пользователя    |
| `edit_reguser`    | Редактировать зарегистрированного |
| `edit_server`     | Редактировать сервер          |
| `edit_package`    | Редактировать пакет           |
| `edit_group`      | Редактировать группу          |
| `channel_order`   | Изменять порядок каналов      |
| `edit_cchannel`   | Редактировать созданный канал |
| `create_channel`  | Создать канал                 |
| `epg_edit`        | Редактировать EPG             |

### Массовые операции

| Ключ                | Описание                    |
| ------------------- | --------------------------- |
| `mass_delete`       | Массовое удаление           |
| `mass_sedits`       | Массовое редактирование стримов |
| `mass_sedits_vod`   | Массовое редактирование VOD |
| `mass_edit_users`   | Массовое редактирование пользователей |
| `mass_edit_lines`   | Массовое редактирование линий |
| `mass_edit_mags`    | Массовое редактирование MAG |
| `mass_edit_enigmas` | Массовое редактирование Enigma2 |
| `mass_edit_streams` | Массовое редактирование стримов |
| `mass_edit_radio`   | Массовое редактирование радио |
| `mass_edit_reguser` | Массовое редактирование зарегистрированных |

### Импорт

| Ключ              | Описание                    |
| ----------------- | --------------------------- |
| `import_streams`  | Импорт стримов              |
| `import_movies`   | Импорт фильмов              |
| `import_episodes` | Импорт эпизодов             |

### Блокировки и безопасность

| Ключ          | Описание              |
| ------------- | --------------------- |
| `block_ips`   | Блокировка IP         |
| `block_isps`  | Блокировка ISP/ASN    |
| `block_uas`   | Блокировка User-Agent |
| `fingerprint` | Функция fingerprint   |

### Системные разделы (просмотр)

| Ключ                    | Описание                         |
| ----------------------- | -------------------------------- |
| `bouquets`              | Просмотр букетов                 |
| `categories`            | Просмотр категорий               |
| `streams`               | Просмотр стримов                 |
| `movies`                | Просмотр фильмов                 |
| `radio`                 | Просмотр радио                   |
| `servers`               | Просмотр серверов                |
| `rtmp`                  | Просмотр RTMP                    |
| `epg`                   | Просмотр EPG                     |
| `player`                | Доступ к плееру                  |
| `database`              | Доступ к управлению БД           |
| `settings`              | Доступ к настройкам              |
| `index`                 | Доступ к главной панели          |
| `stream_tools`          | Инструменты стримов              |
| `stream_errors`         | Журнал ошибок стримов            |
| `process_monitor`       | Монитор процессов                |
| `live_connections`      | Активные подключения             |
| `connection_logs`       | Журнал подключений               |
| `login_logs`            | Журнал входов                    |
| `client_request_log`    | Журнал запросов клиентов         |
| `credits_log`           | Журнал кредитов                  |
| `reg_userlog`           | Журнал зарегистрированных        |
| `ticket`                | Тикеты                           |
| `manage_tickets`        | Управление тикетами              |
| `subreseller`           | Суб-реселлеры                    |
| `subresellers`          | Просмотр суб-реселлеров          |
| `manage_events`         | Управление событиями             |
| `manage_mag`            | Управление MAG                   |
| `manage_e2`             | Управление Enigma2               |
| `manage_cchannels`      | Управление созданными каналами   |
| `mng_groups`            | Управление группами              |
| `mng_packages`          | Управление пакетами              |
| `mng_regusers`          | Управление зарегистрированными   |
| `folder_watch`          | Просмотр Watch Folder            |
| `folder_watch_output`   | Вывод Watch Folder               |
| `folder_watch_settings` | Настройки Watch Folder           |

---

## Классы авторизации

### `Authorization` — авторизация на уровне объектов

`src/core/Auth/Authorization.php`

Проверяет, имеет ли текущий пользователь доступ к конкретному объекту (пользователю, линии, расширенной функции).

```php
Authorization::check(string $type, mixed $id): bool
```

| Тип `$type` | Описание                                                        |
| ----------- | --------------------------------------------------------------- |
| `'user'`    | Пользователь принадлежит текущему реселлеру или его суб-реселлерам |
| `'line'`    | Линия принадлежит текущему реселлеру или его суб-реселлерам    |
| `'adv'`     | Расширенная функция (ключ из `permissions.php`) разрешена для группы |

```php
// Примеры
Authorization::check('user', $userId);          // может ли видеть этого пользователя
Authorization::check('line', $lineId);          // может ли видеть эту линию
Authorization::check('adv', 'block_isps');      // есть ли право блокировки ISP
Authorization::check('adv', 'edit_bouquet');    // есть ли право редактирования букетов
```

**Проверка прав реселлера:**

```php
Authorization::hasResellerPermissions(string $type): bool
```

Проверяет `$rPermissions[$type]` для реселлерских разрешений (например, `create_line`, `create_mag`).

---

### `PageAuthorization` — авторизация на уровне страниц

`src/core/Auth/Authorization.php`

Определяет, имеет ли текущий пользователь доступ к конкретной странице панели.

```php
PageAuthorization::checkPermissions(?string $page = null): bool
PageAuthorization::checkResellerPermissions(?string $page = null): bool
```

Если `$page` не передан — имя страницы определяется автоматически по `basename(SCRIPT_FILENAME)`.

**Страницы с гранулярным контролем:**

| Страница          | Требуемое право              |
| ----------------- | ---------------------------- |
| `isps`, `asns`    | `adv: block_isps`            |
| `bouquet`         | `adv: add_bouquet` или `edit_bouquet` |
| `bouquets`        | `adv: bouquets`              |
| `channel_order`   | `adv: channel_order`         |
| `client_logs`     | `adv: client_request_log`    |
| `created_channel` | `adv: create_channel` или `edit_cchannel` |
| и другие…         |                              |

---

## Суперадминистратор

Пользователь с `member_group_id = 1` является суперадминистратором — все расширенные проверки (`adv`) для него возвращают `true` без фактической проверки прав.

```php
// В Authorization::check() для 'adv':
if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
    return in_array($rID, $rPermissions['advanced']);
}
return true; // суперадмин
```

---

## Как добавить новое право

1. Добавьте ключ в массив `$rPermissionKeys` в `src/config/permissions.php`:

```php
'my_new_permission',
```

2. Используйте проверку в коде:

```php
if (!Authorization::check('adv', 'my_new_permission')) {
    // запрещено
}
```

3. При необходимости добавьте проверку в `PageAuthorization::checkPermissions()` для автоматической проверки на уровне страницы.
