# Система реселлеров

Система реселлеров обеспечивает многоуровневое управление партнерскими отношениями с предоставлением кредитных линий.
Реселлеры создают IPTV-линии, устройства MAG и Enigma2 и управляют ими в рамках выделенных им кредитов и разрешений.

---

## Обзор

```text
Admin
  └── assigns credits + group permissions
        └── Reseller
              ├── creates IPTV lines (costs credits)
              ├── creates MAG devices (costs credits)
              ├── creates Enigma2 devices (costs credits)
              └── creates sub-resellers (costs credits)
                    └── sub-reseller has own lines + credits
```

Основная бизнес-логика находится в `src/Domain/User/ResellerAPI.php`. Веб-контроллеры находятся в `src/Public/Controllers/Reseller/`. REST API находится в `src/Public/Controllers/Api/ResellerRestApiController.php`.

---

## Кредитная система

Кредиты являются валютой для всех операций посредника. Каждое действие имеет определенную стоимость, и баланс посредника должен ее покрывать.

### Затраты по кредиту

|Действие|Источник затрат|
| --- | --- |
|Создать линию (официальную)| `package.official_credits` |
|Создать строку (пробная версия)| `package.trial_credits` |
|Создать магнитное устройство|то же, что линия|
|Создание устройства Enigma2|то же, что линия|
|Создать суб-реселлера| `permissions.create_sub_resellers_price` |

### Переопределение ценообразования

Реселлеры могут устанавливать индивидуальные цены для каждого пакета с помощью `override_packages` JSON в своей пользовательской записи:

```php
$rOverride = json_decode($rUserInfo['override_packages'], true);
if (isset($rOverride[$rPackage['id']]['official_credits'])) {
    $rCost = intval($rOverride[$rPackage['id']]['official_credits']);
}
```

### Кредитный перевод

Реселлеры могут переводить кредиты своим непосредственным подчиненным с помощью действия `adjust_credits` API. Оба баланса должны оставаться >= 0.

### Регистрация

Все кредитные операции отражаются в `users_logs`:

|Поле|Описание|
| --- | --- |
| `owner` |идентификатор пользователя торгового посредника|
| `type` |`line`, `mag`, `enigma`, `user`|
| `action` |`new`, `extend`, `edit`, `adjust_credits`|
| `cost` |потраченные кредиты|
| `credits_after` |баланс после операции|
| `package_id` |использованный пакет|
| `date` |отметка времени|

---

## Линейное управление

Строки - это подписки пользователей на IPTV. Типы:

|Тип|Флаги|
| --- | --- |
|Стандартная линия IPTV| `is_mag=0, is_e2=0` |
|МАГНИТНОЕ устройство| `is_mag=1` |
|Устройство Enigma2| `is_e2=1` |

### Процесс создания

1. Проверьте доступность пакета (должен быть указан в разрешениях группы реселлеров).
2. Проверьте `credits >= cost`.
3. Сгенерируйте имя пользователя/пароль, если это разрешено разрешениями.
4. Применить пакет: `exp_date`, `max_connections`, `bouquets`, `allowed_outputs`.
5. Установите ограничения: `allowed_ips` (JSON), `allowed_ua`, `bypass_ua`, `is_isplock`.
6. Вставить в таблицу `lines` через `REPLACE INTO`.
7. Синхронизируйте записи устройства (`mag_devices` или `enigma2_devices`).
8. Передача сигнала о событии на потоковые серверы.
9. Вычтите кредиты и зарегистрируйте транзакцию.

### Назначение букета

В каждом пакете указаны доступные букеты с помощью массива `bouquets` JSON.
Если включено разрешение `allow_change_bouquets`, реселлер может выбрать набор букетов из пакета. В противном случае все букеты из пакета будут назначены автоматически.

---

## Управление устройствами

### МАГНИТНЫЕ устройства

Управляется с помощью `MagService` (`src/Domain/Device/MagService.php`).
Поля блокировки: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

### Устройства Enigma2

Управляется с помощью `EnigmaService` (`src/Domain/Device/EnigmaService.php`).
Поля блокировки: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

Оба типа устройств поддерживают `lock_device` (аппаратная привязка), `is_isplock` (привязка к провайдеру) и `forced_country`.

---

## Иерархия суб-реселлеров

Реселлеры могут создавать суб-реселлеров (если получено разрешение `create_sub_resellers`).:

- Суб-реселлеры связаны через поле `owner_id`.
- Многоуровневый: суб-реселлер может создавать своих собственных суб-реселлеров.
- Каждое создание стоит `create_sub_resellers_price` кредитов.
- Присвоенный `member_group_id` должен находиться в родительском массиве разрешений `subresellers`.

Запросы о праве собственности:

```php
Authorization::check('user', $rID)   // checks reseller hierarchy
Authorization::check('line', $rID)   // checks if reseller's reports own the line
```

Методы:

```php
UserRepository::getResellers($rOwner, $rIncludeSelf)
UserRepository::getDirectReports()
AuthRepository::getGroupPermissions()  // builds all_reports recursively
```

---

## Разрешения

Разрешения берутся из таблицы `users_groups`, загружаемой через `AuthRepository::getPermissions()`.

### Ключевые поля разрешений

|Разрешение|Тип|Описание|
| --- | --- | --- |
| `is_reseller` | `bool` |пользователь является реселлером|
| `create_line` | `bool` |может создавать IPTV-линии|
| `create_mag` | `bool` |может создавать магнитные устройства|
| `create_enigma` | `bool` |может создавать устройства Enigma2|
| `create_sub_resellers` | `bool` |может создавать суб-реселлеров|
| `create_sub_resellers_price` | `int` |стоимость кредита на одного суб-реселлера|
| `allow_change_bouquets` | `bool` |можно выбрать подмножество bouquet|
| `allow_change_username` | `bool` |можно установить пользовательское имя пользователя|
| `allow_change_password` | `bool` |можно установить пользовательский пароль|
| `allow_restrictions` | `bool` |можно установить ограничения по IP/UA|
| `can_view_vod` | `bool` |может просматривать содержимое VOD|
| `reseller_client_connection_logs` | `bool` |можно просматривать журналы подключений|
| `minimum_username_length` | `int` |минимальная длина имени пользователя|
| `minimum_password_length` | `int` |минимальная длина пароля|

### Проверки на уровне страницы

`PageAuthorization::checkResellerPermissions()` сопоставляет страницы с разрешениями:

|Страницы|Требуемое разрешение|
| --- | --- |
|`user`, `users`| `create_sub_resellers` |
|`line`, `lines`| `create_line` |
|`mag`, `mags`| `create_mag` |
|`enigma`, `enigmas`| `create_enigma` |
|`epg_view`, `streams`, `movies`| `can_view_vod` |
|`live_connections`, `line_activity`| `reseller_client_connection_logs` |

### Границы

Что реселлеры ** не могут ** делать:

- Линии доступа/пользователи за пределами их иерархии.
- Создавайте или изменяйте пакеты.
- Доступ к настройкам доступен только для администратора.
- Превысить их кредитный баланс.
- Обходите групповые ограничения пакетов.

---

## REST API

Файл: `src/Public/Controllers/Api/ResellerRestApiController.php`

Аутентификация с помощью API-ключа. Действия:

|Действие|Описание|
| --- | --- |
| `user_info` |информация об учетной записи реселлера|
| `packages` |доступные пакеты|
|`get_lines` / `get_mags` / `get_enigmas`|список ресурсов|
|`create_line` / `edit_line` / `delete_line`|грубая линия|
|`enable_line` / `disable_line`|переключение состояния линии|
|`create_mag` / `edit_mag` / `delete_mag`|МАГИЧЕСКАЯ ДРЯНЬ|
|`create_enigma` / `edit_enigma` / `delete_enigma`|Загадочная ДРЯНЬ|
|`convert_mag` / `convert_enigma`|преобразовать тип устройства|
|`get_users` / `get_user`|список/просмотр суб-реселлеров|
|`create_user` / `edit_user` / `delete_user`|грубость субпродюсера|
|`enable_user` / `disable_user`|переключение статуса суб-реселлера|
| `adjust_credits` |перевод кредитов суб-реселлеру|
|`activity_logs` / `live_connections`|данные о подключении|
| `user_logs` |журналы действий суб-реселлеров|

Класс `ResellerAPIWrapper` проверяет ключ API, инициализирует сеанс с помощью `ResellerAPI` и возвращает отфильтрованные ответы в формате JSON.

---

## Сессия и начальная загрузка

### Сессия

Файл: `src/Infrastructure/Bootstrap/reseller_session.php`

- 60-минутный тайм-аут с отслеживанием последней активности.
- Обнаружение изменения IP-адреса (если включена настройка `ip_logout`).
- Ключи сеанса: `reseller` (идентификатор пользователя), `rip`, `rcode`, `rverify`, `rlast_activity`.

### Функции начальной загрузки

Файл: `src/Infrastructure/Bootstrap/reseller_functions.php`

- Загружает базу данных и утилиты.
- Инициализирует `$rUserInfo` и `$rPermissions`.
- Проверяет целостность сеанса (проверка хэша имени пользователя/пароля).
- Устанавливает часовой пояс и языковые настройки.

---

## Маршруты

Файл: `src/Public/routes/reseller.php`

Ключевые маршруты:

```text
GET  /dashboard              → ResellerDashboardController
GET  /edit_profile            → ResellerEditProfileController
POST /post                    → ResellerPostController (form handler)
GET  /api, POST /api          → ResellerApiController
GET  /table, POST /table      → ResellerTableController

GET  /lines                   → ResellerLinesController
GET  /line                    → ResellerLineController
GET  /mags                    → ResellerMagsController
GET  /mag                     → ResellerMagController
GET  /enigmas                 → ResellerEnigmasController
GET  /enigma                  → ResellerEnigmaController

GET  /users                   → ResellerUsersController
GET  /user                    → ResellerUserController
GET  /user_logs               → ResellerUserLogsController
GET  /live_connections        → ResellerLiveConnectionsController
GET  /line_activity           → ResellerLineActivityController
GET  /tickets                 → ResellerTicketsController
```

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Domain/User/ResellerAPI.php` |основная бизнес-логика|
| `src/Public/Controllers/Api/ResellerRestApiController.php` |REST API|
| `src/Public/Controllers/Reseller/*.php` |веб-контроллеры|
| `src/Public/routes/reseller.php` |Маршрутизация URL-адресов|
| `src/Public/Views/reseller/*.php` |просмотр шаблонов|
| `src/Infrastructure/ResellerApiDispatcher.php` |Маршрутизация действий AJAX|
| `src/Infrastructure/ResellerTableRenderer.php` |Рендеринг таблиц данных|
| `src/Infrastructure/Bootstrap/reseller_session.php` |управление сеансами|
| `src/Infrastructure/Bootstrap/reseller_functions.php` |инициализация|
| `src/Core/Auth/Authorization.php` |проверки на право собственности|
| `src/Core/Auth/PageAuthorization.php` |стробирование на уровне страницы|
