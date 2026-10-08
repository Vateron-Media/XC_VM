# Разрешения и RBAC

Система контроля доступа XC_VM сочетает в себе:

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

Для сеанса требуется активированная учетная запись (`status = 1`), как и для входа в систему: отключение администратора или торгового посредника завершает сеанс, открытый учетной записью, при следующем запросе, не дожидаясь истечения времени ожидания.

Ключевые поля в `$rPermissions`:

|Поле|Тип|Описание|
| --- | --- | --- |
| `is_admin` |тип bool|Является ли пользователь администратором|
| `advanced` |массив|Список предоставленных ключевых строк разрешений|
| `all_reports` |массив|Дерево отчетов реселлера (идентификаторы пользователей, которыми управляет этот реселлер)|
| `create_line` |тип bool|Реселлер: может создавать строки|
| `create_sub_resellers` |тип bool|Реселлер: может создавать суб-реселлеров|
| `create_mag` |тип bool|Реселлер: может создавать устройства MAG|
| `create_enigma` |тип bool|Реселлер: может создавать устройства Enigma|
| `can_view_vod` |тип bool|Реселлер: может просматривать контент VOD/streams|
| `reseller_client_connection_logs` |тип bool|Реселлер: может просматривать журналы подключений|

---

## Ключи разрешений

Ключи разрешений объявляются в `XcVm\Core\Reference\PermissionReference` (`src/Core/Reference/PermissionReference.php`): `PermissionReference::keys()` возвращает полный список и `PermissionReference::advanced()` связывает каждый ключ с его локализованным названием/описанием для редактора групп. Каждый ключ представляет собой строковый идентификатор, используемый в `Authorization::check('adv', $key)`.

Категории:

|Категория|Примеры|
| --- | --- |
|Создать/Добавить|`add_stream`, `add_movie`, `add_user`, `add_server`, `add_bouquet`, `add_epg`, `add_code`, `add_hmac`, `add_rtmp`|
|Редактировать|`edit_stream`, `edit_movie`, `edit_user`, `edit_server`, `edit_bouquet`, `edit_series`, `edit_reguser`|
|Массовые операции|`mass_edit_streams`, `mass_edit_lines`, `mass_edit_mags`, `mass_edit_enigmas`, `mass_edit_radio`, `mass_edit_users`, `mass_sedits`, `mass_sedits_vod`, `mass_delete`|
|Импорт|`import_streams`, `import_movies`, `import_episodes`|
|Безопасность/блокирование|`block_ips`, `block_isps`, `block_uas`, `block_asns`, `fingerprint`|
|Видимость раздела|`streams`, `movies`, `series`, `episodes`, `radio`, `users`, `servers`, `bouquets`, `epg`, `settings`, `database`|
|Бревна|`connection_logs`, `live_connections`, `client_request_log`, `credits_log`, `login_logs`, `admin_audit`, `panel_logs`, `reg_userlog`, `restream_logs`|
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

#### Полноправный администратор

Полноправный администратор - это член группы 1 или группы администраторов, список прав доступа которых пуст: оба они проходят все проверки `adv`. Группа, в которой вообще нет списка (`allowed_pages` - это значение NULL или пустой текст), считывается как группа с пустым списком, как на панели, так и в таблицах, а также для ключа API администратора. Учетные записи администраторов и группы администраторов управляются только полноправным администратором. Все остальные не могут:

- редактируйте, массово редактируйте, удаляйте, отключайте или включайте учетную запись администратора или корректируйте ее кредиты в панели администратора, панели реселлера, API администратора или API реселлера;
- назначьте пользователю группу администраторов;
- создайте, отредактируйте или удалите группу администраторов или преобразуйте группу в единую (переключатель "Является администратором" в форме "Группа" и действие "Флаг группы").

Реселлер никогда не предоставляет пользователю группу администраторов и не может редактировать, удалять, отключать или включать учетную запись администратора в своем дереве.

`GroupService::reservedGroups()` - это единственное правило: оно возвращает идентификаторы групп, зарезервированные у действующего пользователя, и пустой список для полноправного администратора. Ответы на запрос о зарезервированной учетной записи или группе зависят от того, где он был сделан:

- API администратора и формы для пользователей и групп на панели администратора отвечают `STATUS_INVALID_GROUP` при создании или редактировании пользователя или группы: учетная запись администратора, группа администраторов или присвоенный пользователю статус. При массовом редактировании, когда назначается группа администраторов, ответ остается тем же.
- API администратора отвечает `STATUS_FAILURE` на запрос `delete_user`, `disable_user`, `enable_user`, `adjust_credits` и `delete_group`.
- API реселлера отвечает `STATUS_FAILURE` на запрос `delete_user`, `disable_user`, `enable_user` и `adjust_credits`, и `STATUS_NO_PERMISSIONS` для `edit_user`. Ключ, в группе которого отсутствует флаг, запрашиваемый действием, получает ответ `STATUS_NO_PERMISSIONS` до этого; смотрите [Сопоставления разрешений на странице реселлера](#reseller-page-permission-mappings).
- Действие с строкой для пользователя (удалить, отключить, включить, настроить кредиты) на любой из панелей и действие с групповым флагом отвечают `result: false`.
- Массовое включение, отключение и удаление, массовое редактирование других полей и действие "Удалить группу" оставляют зарезервированные учетные записи и группы без изменений и обеспечивают успешный результат.

Списки пользователей не предлагают никаких действий со строками для зарезервированной учетной записи, а список групп не содержит никаких действий для зарезервированной группы.

#### Ключи API администратора

API-ключ администратора действует с разрешениями, указанными в списке групп его владельца, как это делает владелец на панели. Ключ группы 1 или группы администраторов с пустым списком разрешений сохраняет все. Для ключа группы с ограниченным доступом каждое действие запрашивает разрешение группы, которое панель запрашивает для выполнения той же операции (`AdminApiController::ACTION_PERMISSIONS`): чтение, таблицы и журналы, удаление, включение/выключение и запуск/остановка, а также создание, редактирование и установка. Если в action указано несколько разрешений, достаточно любого из них; `user_info` запрашивает ни одно из них. В случае отказа в выполнении действия отвечает `{"status":"STATUS_NO_PERMISSIONS"}`. Список содержит основные действия: действие или таблица, зарегистрированные модулем, отсутствуют в нем и проверяются собственным обработчиком модуля.

Разрешение, запрашиваемое для каждого действия (`OR`: достаточно любого из ключей):

|Область|Действия|Разрешение|
| --- | --- | --- |
|Собственный аккаунт| `user_info` |Нет: все ключи|
|База данных|`mysql_query`, `reload_cache`| `database` |
|Настройки|`get_settings`, `edit_settings`| `settings` |
|Линии| `get_lines` |`users` ИЛИ `mass_edit_lines`|
|  |`get_line`, `edit_line`, `delete_line`, `disable_line`, `enable_line`, `ban_line`, `unban_line`| `edit_user` |
|  | `create_line` | `add_user` |
|Коды активации| `get_active_codes` |`users` ИЛИ `mass_edit_lines`|
|  |`get_active_code`, `get_active_codes_batches`, `export_active_code_batch`, `check_active_code`| `users` |
|  |`generate_active_codes`, `create_active_code`| `add_user` |
|  | `edit_active_code` | `edit_user` |
|  |`delete_active_code`, `disable_active_code`, `enable_active_code`, `reset_active_code_device`, `mass_active_codes`|`edit_user` ИЛИ `mass_edit_lines`|
|Пользователи| `get_users` |`mng_regusers` ИЛИ `mass_edit_users`|
|  |`get_user`, `edit_user`, `delete_user`, `disable_user`, `enable_user`, `adjust_credits`| `edit_reguser` |
|  | `create_user` | `add_reguser` |
|МАГНИТНЫЕ устройства| `get_mags` |`manage_mag` ИЛИ `mass_edit_mags`|
|  |`get_mag`, `edit_mag`, `delete_mag`, `disable_mag`, `enable_mag`, `ban_mag`, `unban_mag`, `convert_mag`| `edit_mag` |
|  | `create_mag` | `add_mag` |
|Устройства "Энигма"| `get_enigmas` |`manage_e2` ИЛИ `mass_edit_enigmas`|
|  |`get_enigma`, `edit_enigma`, `delete_enigma`, `disable_enigma`, `enable_enigma`, `ban_enigma`, `unban_enigma`, `convert_enigma`| `edit_e2` |
|  | `create_enigma` | `add_e2` |
|Группы| `get_groups` | `mng_groups` |
|  | `get_group` |`mng_groups` ИЛИ `edit_group`|
|  | `create_group` | `add_group` |
|  |`edit_group`, `delete_group`| `edit_group` |
|Пакеты| `get_packages` | `mng_packages` |
|  | `get_package` |`mng_packages` ИЛИ `edit_package`|
|  | `create_package` | `add_packages` |
|  |`edit_package`, `delete_package`| `edit_package` |
|Букеты| `get_bouquets` | `bouquets` |
|  | `get_bouquet` |`bouquets` ИЛИ `edit_bouquet`|
|  | `create_bouquet` | `add_bouquet` |
|  |`edit_bouquet`, `delete_bouquet`| `edit_bouquet` |
|Категории|`get_categories`, `get_category`| `categories` |
|  |`create_category`, `edit_category`| `add_cat` |
|  | `delete_category` | `edit_cat` |
|Потоки| `get_streams` |`streams` ИЛИ `mass_edit_streams`|
|  |`get_stream`, `edit_stream`, `delete_stream`, `start_stream`, `stop_stream`| `edit_stream` |
|  | `create_stream` | `add_stream` |
|Созданные каналы| `get_channels` |`streams` ИЛИ `mass_edit_streams`|
|  |`get_channel`, `edit_channel`| `edit_cchannel` |
|  | `create_channel` | `create_channel` |
|  |`delete_channel`, `start_channel`, `stop_channel`|`edit_cchannel` ИЛИ `edit_stream`|
|Станции| `get_stations` |`radio` ИЛИ `mass_edit_radio`|
|  |`get_station`, `edit_station`| `edit_radio` |
|  | `create_station` | `add_radio` |
|  |`delete_station`, `start_station`, `stop_station`|`edit_radio` ИЛИ `edit_stream`|
|Фильмы| `get_movies` |`movies` ИЛИ `mass_sedits_vod`|
|  |`get_movie`, `edit_movie`, `delete_movie`, `start_movie`, `stop_movie`| `edit_movie` |
|  | `create_movie` | `add_movie` |
|Серии| `get_series_list` |`series` ИЛИ `mass_sedits`|
|  |`get_series`, `edit_series`, `delete_series`| `edit_series` |
|  | `create_series` | `add_series` |
|Эпизоды| `get_episodes` |`episodes` ИЛИ `mass_sedits`|
|  |`get_episode`, `edit_episode`, `delete_episode`, `start_episode`, `stop_episode`| `edit_episode` |
|  | `create_episode` | `add_episode` |
|Поставщики услуг|`get_providers`, `get_provider`, `create_provider`, `edit_provider`, `delete_provider`, `reload_provider`| `streams` |
|  | `get_provider_streams` |`streams` ИЛИ `add_stream`, ИЛИ `edit_stream`, ИЛИ `add_movie`, ИЛИ `edit_movie`|
|ЭПГ| `get_epgs` | `epg` |
|  |`get_epg`, `reload_epg`|`epg` ИЛИ `epg_edit`|
|  | `create_epg` | `add_epg` |
|  |`edit_epg`, `delete_epg`| `epg_edit` |
|Профили перекодирования|`get_transcode_profiles`, `delete_transcode_profile`| `tprofiles` |
|  | `get_transcode_profile` |`tprofiles` ИЛИ `tprofile`|
|  |`create_transcode_profile`, `edit_transcode_profile`| `tprofile` |
|RTMP IP-адреса| `get_rtmp_ips` | `rtmp` |
|  | `get_rtmp_ip` |`rtmp` ИЛИ `add_rtmp`|
|  |`create_rtmp_ip`, `edit_rtmp_ip`, `delete_rtmp_ip`| `add_rtmp` |
|Коды доступа|`get_access_codes`, `get_access_code`, `create_access_code`, `edit_access_code`, `delete_access_code`| `add_code` |
|Ключи HMAC|`get_hmacs`, `get_hmac`, `create_hmac`, `edit_hmac`, `delete_hmac`| `add_hmac` |
|Блокирующие списки|`get_blocked_isps`, `add_blocked_isp`, `delete_blocked_isp`| `block_isps` |
|  |`get_blocked_uas`, `add_blocked_ua`, `delete_blocked_ua`| `block_uas` |
|  |`get_blocked_ips`, `add_blocked_ip`, `delete_blocked_ip`, `flush_blocked_ips`| `block_ips` |
|Серверы| `get_servers` | `servers` |
|  |`get_server`, `get_certificate_info`|`servers` ИЛИ `edit_server`|
|  |`install_server`, `install_proxy`| `add_server` |
|  |`edit_server`, `edit_proxy`, `delete_server`, `reload_nginx`| `edit_server` |
|  | `get_server_stats` |`index` ИЛИ `add_server` ИЛИ `edit_server`|
|  | `get_fpm_status` |`add_server` ИЛИ `edit_server`|
|  | `get_free_space` |`process_monitor` ИЛИ `edit_server`|
|  |`get_pids`, `kill_pid`, `clear_temp`, `clear_streams`| `process_monitor` |
|  | `get_rtmp_stats` | `rtmp` |
|  | `get_directory` |`add_episode` ИЛИ `edit_episode`, ИЛИ `add_movie`, ИЛИ `edit_movie`, ИЛИ `create_channel`, ИЛИ `edit_cchannel`|
|Подключения и журналы| `live_connections` | `live_connections` |
|  |`activity_logs`, `kill_connection`| `connection_logs` |
|  | `credit_logs` | `credits_log` |
|  | `client_logs` | `client_request_log` |
|  | `user_logs` | `reg_userlog` |
|  | `stream_errors` | `stream_errors` |
|  | `system_logs` | `panel_logs` |
|  | `login_logs` | `login_logs` |
|  | `restream_logs` | `restream_logs` |
|  | `mag_events` | `manage_events` |

В названиях действий и ключах разрешения используются разные слова, которые не означают одно и то же. Действие `edit_user` изменяет пользователя панели и запрашивает `edit_reguser`; разрешение `edit_user` используется для строк (`edit_line`). Аналогично, `get_users` запрашивает `mng_regusers`, а `get_lines` - `users`.

API с активным кодом (`/api/active_code`, `/active_code.php`) следует тому же правилу, когда он вызывается с ключом API администратора: каждое из его действий запрашивает разрешение действия API администратора, которое оно обозначает, и отвечает на `STATUS_NO_PERMISSIONS` без него. Он принимает имена Admin API в правом столбце и эти короткие имена:

|Краткое название|Действие администратора API|
| --- | --- |
|`list`, `get_codes`| `get_active_codes` |
|`get`, `details`| `get_active_code` |
|`generate`, `create`| `generate_active_codes` |
|`edit`, `update`| `edit_active_code` |
| `delete` | `delete_active_code` |
| `enable` | `enable_active_code` |
| `disable` | `disable_active_code` |
| `reset_device` | `reset_active_code_device` |
| `mass` | `mass_active_codes` |
| `batches` | `get_active_codes_batches` |
| `export` | `export_active_code_batch` |

Запрос, который отправляет код активации без ключа (устройство, активирующее свой код, или `check`), не запрашивает разрешения.

#### Токены API

Каждый администратор и реселлер могут создавать именованные токены на странице своего профиля (**Редактировать профиль → Токены API**), до 20, вместо единственного ключа API учетной записи (поле **Ключ API** в **Редактировать профиль** отображается только в том случае, если код доступа к API распространяется на группу учетной записи: *Admin API* код для администратора, API реселлера, код для реселлера, созданный на **Коды доступа**). Для создания токена такой код не требуется. Токен, содержащий ключ, например `api_key`, отправляется в API администратора, REST API реселлера, API кода активации и конечные точки таблицы. Он действует с групповыми разрешениями своей учетной записи, как это делает ключ, ограниченный его областью действия (`Core\Auth\ApiTokens::allows()`).:

|Масштаб|Бежит|
| --- | --- |
|Полный|Все действия, разрешенные группой, за исключением `mysql_query`. Администратор может создать полноценный токен, который также выполняет `mysql_query`.|
|Только для чтения|Каждое действие `get_*` записывается в журналы (`activity_logs`, `live_connections`, `credit_logs`, `client_logs`, `user_logs`, `stream_errors`, `system_logs`, `login_logs`, `restream_logs`, `mag_events`), `user_info`, `packages`, `check_active_code` и `export_active_code_batch`.|
|Линии, устройства и коды активации|Действия с линиями, устройствами MAG и Enigma2 и кодами активации с помощью `user_info`, `packages`, `get_packages`, `get_package`, `get_bouquets` и `get_bouquet`.|

Действие, выходящее за пределы области действия, отвечает `{"status":"STATUS_NO_PERMISSIONS"}`; таблица, выходящая за ее пределы, отвечает как недопустимый ключ. Токен также может быть ограничен списком IP-адресов и иметь срок действия; в профиле отображаются первые символы каждого токена, область применения, адреса, срок действия и последнее использование (с точностью до минуты, с указанием адреса). Сам токен (`xct_` и 40 шестнадцатеричных цифр) отображается один раз, когда он создается: панель сохраняет его хэш SHA-256 (`api_tokens`, миграция `076_add_api_tokens.sql`). При отзыве одного из них он сразу же останавливается. При удалении учетной записи удаляются ее токены.

Единый ключ учетной записи продолжает работать, пока включено значение **Настройки → API → Прием устаревших ключей API** (`api_legacy_keys`) по умолчанию. При отключении ни один API не использует устаревший ключ; токены остаются незатронутыми.

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

Если `$page` опущено, имя страницы берется из запроса (`AdminHelpers::getPageName()`: константа `PAGE_NAME`, в противном случае базовое имя скрипта ввода, в нижнем регистре). Правило страницы просматривается по ее имени, обозначенному подчеркиванием, независимо от написания URL: `line/mass`, `line_mass` и `line_mass.php` все они соответствуют правилу для `line_mass`.

#### Поведение, разрешенное по умолчанию

Оба метода возвращают значение `true` для любой страницы, явно не указанной в их инструкциях switch. Это означает, что страницы без сопоставления доступны всем авторизованным пользователям соответствующего типа (администраторам или торговым посредникам). Доступ ограничен только к страницам с явно заданными параметрами. Новая страница администратора или реселлера, которой требуется разрешение, должна получить обращение в `PageAuthorization`.

---

## Сопоставления разрешений на странице администратора

Метод `checkPermissions()` сопоставляет страницы панели администратора с ключами доступа `adv`. Ниже приведено полное сопоставление, сгруппированное по категориям.

### Шаблон создания или редактирования

Многие страницы сущностей используют условную логику, основанную на параметрах запроса:

- Если указан параметр `id`, проверяется разрешение **редактировать**
- Если параметр `id` отсутствует, проверяется разрешение **добавлять**
- Некоторые страницы (stream, movie) также проверяют наличие параметра `import` и требуют соответствующего разрешения на импорт

Когда ни одно из условий не выполняется, поведение зависит от страницы: некоторые переходят к соответствующему разрешению на перечисление, другие переходят к переключателю по умолчанию (который возвращает `true`).

### Таблицы за страницами массового редактирования

A mass-edit page shows the list it edits, so that table is read with the list page's key or the mass-edit page's own: lines with `users` OR `mass_edit_lines`, users with `mng_regusers` OR `mass_edit_users`, MAG devices with `manage_mag` OR `mass_edit_mags`, Enigma devices with `manage_e2` OR `mass_edit_enigmas`. A mass-edit key reads its own list only: `mass_edit_users` does not read the lines table. The provider streams table is read with `streams`, `add_stream`, `edit_stream`, `add_movie` or `edit_movie`.

### Потоки и контент

|Страница|Разрешение|Записи|
| --- | --- | --- |
|`streams`, `stream_view`, `provider`, `providers`, `epg_view`, `created_channels`, `stream_rank`, `archive`| `streams` |При `created_channels` список внутри страницы отображается с помощью `manage_cchannels` или `edit_cchannel`|
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
| `episode` | `add_episode` |Когда нет `id`|
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
|`active_codes`, `active_codes_batch`| `users` |Управляйте активными кодами и пакетным менеджером. Элементы управления, которые изменяют коды (включают, отключают, расширяют, сбрасывают устройство, удаляют), отображаются только с `edit_user` или `mass_edit_lines`|
| `active_code` | `add_user` |Генерировать коды|
| `active_codes_mass` | `mass_edit_lines` |Массовое редактирование активных кодов; та же клавиша также считывает список кодов|
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

### ЭПГ

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
| `modules` | `settings` |Проверено на самой странице; содержит список модулей и все операции с модулями, включая загрузку ZIP-файла и установку в хранилище|
|`backups`, `cache`, `setup`| `database` |Ссылки "Настройки резервного копирования" и "Настройки кэширования" на верхней панели соответствуют одному и тому же ключу|
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

### RTMP - протокол

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
| `admin_actions` | `admin_audit` |Последовательность действий администратора; смотрите ниже|
| `restream_logs` | `restream_logs` | |

#### Отслеживание действий администратора

**Журналы → Система → Действия администратора** перечислены изменения, внесенные администраторами: каждое сохранение формы администратора (`post.php`), каждое действие Ajax в панели администратора, за исключением тех, которые выполняются только для чтения (`AdminAudit::PANEL_READS`: статистика, поиск, поисковые запросы), и каждое действие API администратора и API кода активации, за исключением чтения (`ApiTokens::isRead()`), отклоненных действий включенный в комплект. Каждая запись (`admin_audit`, миграция `077_add_admin_audit.sql`) содержит дату, учетную запись, ее адрес, источник (`panel` или `api`), действие и его результат: **ОК** или **Неудачный**, если ответом является JSON-файл панели (`result`, или статус `STATUS_*`), в противном случае - пустым (экспорт, загрузка). В его деталях хранятся только поля, в которых указано, над чем было выполнено действие (каждый идентификатор: `id`, `ids`, `edit`, `pid` и любое поле, оканчивающееся на `_id`; имена в `AdminAudit::DETAIL_KEYS`; подчиненное действие), не более 20, и никогда пароль, ключ, код или другое значение, отправленное вместе с запросом; при сохранении настроек добавляются названия измененных настроек, а не их значения.

Страница выполняет поиск по учетной записи, действию, адресу и подробным данным и экспортирует их в формате CSV или JSON. Данные сохраняются в резервных копиях, и ничто на панели не удаляет их.

### Действия

Приведенные ниже действия проверяют свой собственный ключ, на какой бы странице ни находилась кнопка:

|Действие|Разрешение|Записи|
| --- | --- | --- |
|Генерировать коды активации| `add_user` | |
|Включать, отключать, продлевать или удалять коды активации и целые пакеты|`edit_user` ИЛИ `mass_edit_lines`|Доступ с помощью любого ключа|
|Откройте подробную информацию о коде, экспортируйте пакет| `users` | |
|Массовые действия на странице Lines| `edit_user` | |
|Массовое удаление серий| `edit_series` | |
|Кнопки кэширования и Redis (восстановить кэш, включить или отключить кэш, включить или отключить обработчик Redis, очистить Redis)| `database` | |
|Экспорт отчета (*Экспорт в формате CSV* / *Экспорт в формате JSON*)| `database` |Кнопки отображаются с помощью одной и той же клавиши|
|Сетка EPG и всплывающее окно программы в представлении EPG, список потоков провайдера, импорт EPG провайдера| `streams` | |
|Очистить журналы|Клавиша страницы журнала, на которой находится кнопка|`client_request_log`, `connection_logs`, `stream_errors`, `credits_log`, `reg_userlog`, `panel_logs`|
|Загрузите журнал работы панели| `panel_logs` |Очищает стол после его сбора|

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

Действия в строке панели реселлера и API реселлера запрашивают одинаковые флаги. Без флага API реселлера отвечает `STATUS_NO_PERMISSIONS`:

|Действия API реселлера|Требуемое разрешение|
| --- | --- |
|`delete_line`, `disable_line`, `enable_line`| `create_line` |
|`delete_mag`, `disable_mag`, `enable_mag`, `convert_mag`| `create_mag` |
|`delete_enigma`, `disable_enigma`, `enable_enigma`, `convert_enigma`| `create_enigma` |
|`disable_user`, `enable_user`, `adjust_credits`| `create_sub_resellers` |
| `delete_user` |`create_sub_resellers` и `delete_users`|

---

## Добавление нового разрешения

1. Добавьте ключ к константе `KEYS` в `src/Core/Reference/PermissionReference.php`:

```php
private const KEYS = array(
    // ...existing keys...
    'my_new_permission',
);
```

Затем добавьте его метки-ключи к языковым файлам, чтобы редактор групп мог отобразить
название/описание: `permission_my_new_permission` и
`permission_my_new_permission_text` в `src/Core/Localization/lang/en.ini`.

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
| `src/Core/Reference/PermissionReference.php` |Реестр разрешений (`keys()`) + локализованные строки для редактора групп (`advanced()`)|
| `src/Core/Auth/Authorization.php` |Проверка разрешений на уровне объекта и расширенные проверки разрешений|
| `src/Core/Auth/PageAuthorization.php` |Настройка на уровне страницы для панелей администратора и реселлера|
| `src/Core/Auth/SessionManager.php` |Контекст сеанса; заполняет значения `$rPermissions` и `$rUserInfo`|
| `src/Core/Auth/Authenticator.php` |Аутентификация (вход в систему, проверка учетных данных)|
